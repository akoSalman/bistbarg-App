package com.bistbarg.app

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Build
import android.webkit.CookieManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * Fetches notifications from the "Bistbarg App Notifications" WordPress plugin and shows them.
 *
 * No Firebase or socket server is needed: the app asks the site for anything new every 30 s while
 * it is open, and Android runs [NotificationWorker] about every 15 minutes while it is closed.
 * The site's login cookies are sent along, so personal notifications (e.g. order updates) reach the
 * right customer.
 */
object Notifications {
    private const val CHANNEL_ID = "bistbarg_news"
    private const val PREFS = "notifications"
    private const val WORK_NAME = "bistbarg-notifications"
    const val EXTRA_URL = "open_url"

    fun schedule(context: Context) {
        val request = PeriodicWorkRequestBuilder<NotificationWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(context)
            .enqueueUniquePeriodicWork(WORK_NAME, ExistingPeriodicWorkPolicy.KEEP, request)
    }

    /** Checks the site for new notifications and shows them. Safe to call from any background thread. */
    @Synchronized
    fun sync(context: Context) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val deviceId = prefs.getString("device_id", null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString("device_id", it).apply()
        }
        val since = prefs.getLong("cursor", 0L)
        val base = BuildConfig.STORE_URL.trimEnd('/')
        val url = "$base/wp-admin/admin-ajax.php?action=bistbarg_notifications" +
            "&device=${enc(deviceId)}&since=$since&v=${enc(BuildConfig.VERSION_NAME)}"

        val response = runCatching { httpGet(url, cookiesFor(base)) }.getOrNull() ?: return
        val json = runCatching { JSONObject(response) }.getOrNull() ?: return
        if (!json.has("cursor")) return

        val items = json.optJSONArray("notifications")
        if (items != null) {
            for (i in 0 until items.length()) {
                val n = items.getJSONObject(i)
                show(
                    context,
                    id = n.optInt("id"),
                    title = n.optString("title"),
                    body = n.optString("body"),
                    link = n.optString("url"),
                    imageUrl = n.optString("image"),
                )
            }
        }
        prefs.edit().putLong("cursor", json.optLong("cursor", since)).apply()
    }

    private fun show(context: Context, id: Int, title: String, body: String, link: String, imageUrl: String) {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return
        createChannel(context)

        val open = Intent(context, MainActivity::class.java)
            .setAction(Intent.ACTION_VIEW)
            .putExtra(EXTRA_URL, link)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        val pending = PendingIntent.getActivity(
            context, id, open, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(ContextCompat.getColor(context, R.color.gold_dark))
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setPriority(NotificationCompat.PRIORITY_HIGH)

        if (imageUrl.isNotBlank()) {
            downloadImage(imageUrl)?.let { bitmap ->
                builder.setLargeIcon(bitmap)
                builder.setStyle(
                    NotificationCompat.BigPictureStyle()
                        .bigPicture(bitmap)
                        .bigLargeIcon(null as Bitmap?)
                        .setSummaryText(body),
                )
            }
        }

        runCatching { NotificationManagerCompat.from(context).notify(id, builder.build()) }
    }

    private fun createChannel(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        if (manager.getNotificationChannel(CHANNEL_ID) != null) return
        val channel = NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.notification_channel),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply { description = context.getString(R.string.notification_channel_description) }
        manager.createNotificationChannel(channel)
    }

    private fun cookiesFor(url: String): String? =
        runCatching { CookieManager.getInstance().getCookie(url) }.getOrNull()

    private fun httpGet(url: String, cookies: String?): String {
        val conn = URL(url).openConnection() as HttpURLConnection
        try {
            conn.connectTimeout = 15_000
            conn.readTimeout = 20_000
            conn.setRequestProperty("Accept", "application/json")
            conn.setRequestProperty("User-Agent", "BistbargApp/${BuildConfig.VERSION_NAME} (Android)")
            if (!cookies.isNullOrEmpty()) conn.setRequestProperty("Cookie", cookies)
            if (conn.responseCode !in 200..299) error("HTTP ${conn.responseCode}")
            return conn.inputStream.bufferedReader().use { it.readText() }
        } finally {
            conn.disconnect()
        }
    }

    private fun downloadImage(url: String): Bitmap? = runCatching {
        val conn = URL(url).openConnection() as HttpURLConnection
        try {
            conn.connectTimeout = 15_000
            conn.readTimeout = 20_000
            val bytes = conn.inputStream.use { it.readBytes() }
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
            var sample = 1
            while (bounds.outWidth / sample > 1080) sample *= 2
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
        } finally {
            conn.disconnect()
        }
    }.getOrNull()

    private fun enc(s: String) = URLEncoder.encode(s, "UTF-8")

    /** Resolves where a tapped notification should go; null means "not a page of our site". */
    fun isSiteUrl(link: String): Boolean {
        val host = Uri.parse(link).host?.lowercase()?.removePrefix("www.") ?: return false
        val own = Uri.parse(BuildConfig.STORE_URL).host?.lowercase()?.removePrefix("www.") ?: return false
        return host == own || host.endsWith(".$own")
    }
}

class NotificationWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        withContext(Dispatchers.IO) { Notifications.sync(applicationContext) }
        return Result.success()
    }
}
