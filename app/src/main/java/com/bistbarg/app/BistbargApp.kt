package com.bistbarg.app

import android.app.Application
import android.content.Context
import android.webkit.WebSettings
import com.bistbarg.app.data.CartRepository
import com.bistbarg.app.data.StoreApi
import com.bistbarg.app.data.WebViewCookieJar
import okhttp3.Cache
import okhttp3.OkHttpClient
import java.io.File
import java.util.concurrent.TimeUnit

class BistbargApp : Application() {
    lateinit var api: StoreApi
        private set
    lateinit var cart: CartRepository
        private set

    // Use the WebView's user agent so security plugins/CDNs treat API calls like the browser.
    private val userAgent: String? by lazy { runCatching { WebSettings.getDefaultUserAgent(this) }.getOrNull() }

    override fun onCreate() {
        super.onCreate()
        val client = OkHttpClient.Builder()
            .cookieJar(WebViewCookieJar())
            .cache(Cache(File(cacheDir, "http"), 20L * 1024 * 1024))
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val request = chain.request().newBuilder().apply {
                    userAgent?.let { header("User-Agent", it) }
                }.build()
                chain.proceed(request)
            }
            .build()
        api = StoreApi(BuildConfig.STORE_URL, client)
        cart = CartRepository(this)
    }
}

val Context.app: BistbargApp get() = applicationContext as BistbargApp
