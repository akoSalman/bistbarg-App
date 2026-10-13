package com.bistbarg.app.data

import android.webkit.CookieManager
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl

/** Shares cookies between OkHttp and the WebView so both see the same WooCommerce session. */
class WebViewCookieJar : CookieJar {
    private val cookieManager: CookieManager by lazy {
        CookieManager.getInstance().apply { setAcceptCookie(true) }
    }

    override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        val urlString = url.toString()
        cookies.forEach { cookieManager.setCookie(urlString, it.toString()) }
        cookieManager.flush()
    }

    override fun loadForRequest(url: HttpUrl): List<Cookie> {
        val header = cookieManager.getCookie(url.toString()) ?: return emptyList()
        return header.split(';').mapNotNull { Cookie.parse(url, it.trim()) }
    }
}
