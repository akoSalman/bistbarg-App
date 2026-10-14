package com.bistbarg.app

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.CookieManager
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/**
 * Live connection to the Bistbarg socket server while the app is open.
 *
 * WordPress hands out a signed token (it knows the logged-in customer from the site cookies);
 * the socket server then pings this phone whenever a notification is meant for it, and the
 * notification is fetched and shown straight away. Reconnects automatically with backoff.
 */
class SocketClient(private val context: Context) {

    private val main = Handler(Looper.getMainLooper())
    private val io = Executors.newSingleThreadExecutor()
    private val client = OkHttpClient.Builder()
        .pingInterval(25, TimeUnit.SECONDS)
        .readTimeout(0, TimeUnit.MILLISECONDS)
        .build()

    private var socket: WebSocket? = null
    private var running = false
    private var attempt = 0
    private var generation = 0

    /** True while the live connection is up (polling can then slow down). */
    @Volatile var isConnected = false
        private set

    fun start() {
        if (running) return
        running = true
        attempt = 0
        connect()
    }

    fun stop() {
        running = false
        generation++
        main.removeCallbacksAndMessages(null)
        socket?.close(1000, "app closed")
        socket = null
        isConnected = false
    }

    /** Reconnect with a fresh token, e.g. after the customer logged in or out. */
    fun restart() {
        stop()
        start()
    }

    private fun connect() {
        val myGeneration = ++generation
        io.execute {
            val endpoint = fetchEndpoint()
            main.post {
                if (!running || myGeneration != generation) return@post
                if (endpoint == null) {
                    scheduleReconnect()
                    return@post
                }
                val (url, token) = endpoint
                val request = Request.Builder()
                    .url(url + (if ('?' in url) "&" else "?") + "token=" + URLEncoder.encode(token, "UTF-8"))
                    .header("User-Agent", "BistbargApp/${BuildConfig.VERSION_NAME} (Android)")
                    .build()
                socket = client.newWebSocket(request, Listener(myGeneration))
            }
        }
    }

    private inner class Listener(private val gen: Int) : WebSocketListener() {
        override fun onOpen(webSocket: WebSocket, response: Response) {
            main.post {
                if (gen != generation) return@post
                isConnected = true
                attempt = 0
            }
            // Catch up on anything sent while we were offline.
            io.execute { Notifications.sync(context) }
        }

        override fun onMessage(webSocket: WebSocket, text: String) {
            val type = runCatching { JSONObject(text).optString("type") }.getOrNull()
            if (type == "notification") io.execute { Notifications.sync(context) }
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) = dropped()

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) = dropped()

        private fun dropped() {
            main.post {
                if (gen != generation) return@post
                isConnected = false
                socket = null
                scheduleReconnect()
            }
        }
    }

    private fun scheduleReconnect() {
        if (!running) return
        // 2 s, 4 s, 8 s … up to 60 s.
        val delay = (2000L shl attempt.coerceAtMost(5)).coerceAtMost(60_000L)
        attempt++
        main.postDelayed({ if (running) connect() }, delay)
    }

    /** Asks WordPress for the socket address and a signed token. Null if disabled or unreachable. */
    private fun fetchEndpoint(): Pair<String, String>? = runCatching {
        val base = BuildConfig.STORE_URL.trimEnd('/')
        val device = URLEncoder.encode(Notifications.deviceId(context), "UTF-8")
        val conn = URL("$base/wp-admin/admin-ajax.php?action=bistbarg_socket&device=$device")
            .openConnection() as HttpURLConnection
        try {
            conn.connectTimeout = 15_000
            conn.readTimeout = 15_000
            conn.setRequestProperty("Accept", "application/json")
            runCatching { CookieManager.getInstance().getCookie(base) }.getOrNull()
                ?.let { conn.setRequestProperty("Cookie", it) }
            if (conn.responseCode != 200) return@runCatching null
            val json = JSONObject(conn.inputStream.bufferedReader().use { it.readText() })
            if (!json.optBoolean("enabled")) return@runCatching null
            val url = json.optString("url")
            val token = json.optString("token")
            if (url.isBlank() || token.isBlank()) null else url to token
        } finally {
            conn.disconnect()
        }
    }.getOrNull()
}
