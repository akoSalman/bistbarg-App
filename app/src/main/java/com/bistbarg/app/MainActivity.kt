package com.bistbarg.app

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.os.SystemClock
import android.view.View
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.ProgressBar
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.addCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.graphics.ColorUtils
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout

/**
 * Shows the Bistbarg website full screen, so the app looks exactly like the site.
 */
class MainActivity : ComponentActivity() {

    private lateinit var root: View
    private lateinit var webView: WebView
    private lateinit var swipe: SwipeRefreshLayout
    private lateinit var progress: ProgressBar
    private lateinit var errorView: View

    private var firstPageShown = false
    private var mainFrameFailed = false
    private var lastBackPress = 0L
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val filePicker = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data))
        fileCallback = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        val splash = installSplashScreen()
        super.onCreate(savedInstanceState)
        val startedAt = SystemClock.uptimeMillis()
        // Keep the splash until the site has painted (max 4 s) so users never see a blank page.
        splash.setKeepOnScreenCondition { !firstPageShown && SystemClock.uptimeMillis() - startedAt < 4000 }

        WindowCompat.setDecorFitsSystemWindows(window, false)
        setContentView(R.layout.activity_main)
        root = findViewById(R.id.root)
        webView = findViewById(R.id.web)
        swipe = findViewById(R.id.swipe)
        progress = findViewById(R.id.progress)
        errorView = findViewById(R.id.error)

        ViewCompat.setOnApplyWindowInsetsListener(root) { v, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime())
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            WindowInsetsCompat.CONSUMED
        }

        setupWebView()
        swipe.setColorSchemeResources(R.color.brand)
        swipe.setOnChildScrollUpCallback { _, _ -> webView.scrollY > 0 }
        swipe.setOnRefreshListener { webView.reload() }
        findViewById<Button>(R.id.retry).setOnClickListener {
            errorView.visibility = View.GONE
            webView.reload()
        }

        onBackPressedDispatcher.addCallback(this) {
            when {
                webView.canGoBack() -> webView.goBack()
                SystemClock.uptimeMillis() - lastBackPress < 2000 -> finish()
                else -> {
                    lastBackPress = SystemClock.uptimeMillis()
                    Toast.makeText(this@MainActivity, R.string.press_back_again, Toast.LENGTH_SHORT).show()
                }
            }
        }

        if (savedInstanceState == null || webView.restoreState(savedInstanceState) == null) {
            webView.loadUrl(siteUrlFrom(intent) ?: BuildConfig.STORE_URL)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        siteUrlFrom(intent)?.let { webView.loadUrl(it) }
    }

    private fun siteUrlFrom(intent: Intent?): String? =
        intent?.data?.takeIf { it.scheme == "https" && isOwnHost(it.host) }?.toString()

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, true) // needed by some payment gateways
        }
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            loadWithOverviewMode = true
            useWideViewPort = true
            textZoom = 100 // ignore the phone's font size so the layout matches the site
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString BistbargApp/${BuildConfig.VERSION_NAME}"
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) {
                progress.progress = newProgress
                progress.visibility = if (newProgress < 100) View.VISIBLE else View.GONE
                // Reveal the page as soon as most of it has rendered.
                if (newProgress >= 70) firstPageShown = true
            }

            override fun onShowFileChooser(
                view: WebView,
                callback: ValueCallback<Array<Uri>>,
                params: FileChooserParams,
            ): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                return try {
                    filePicker.launch(params.createIntent())
                    true
                } catch (_: ActivityNotFoundException) {
                    fileCallback = null
                    false
                }
            }
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                val scheme = uri.scheme?.lowercase()
                if (scheme == "http" || scheme == "https") {
                    // Social / messaging links open in their own apps; everything else
                    // (the shop, payment gateways, bank pages) stays inside the app.
                    if (isExternalHost(uri.host)) {
                        openExternally(uri)
                        return true
                    }
                    return false
                }
                openExternally(uri)
                return true
            }

            override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                mainFrameFailed = false
            }

            override fun onPageFinished(view: WebView, url: String) {
                firstPageShown = true
                swipe.isRefreshing = false
                if (!mainFrameFailed) errorView.visibility = View.GONE
                applySiteColors()
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) {
                    mainFrameFailed = true
                    firstPageShown = true
                    swipe.isRefreshing = false
                    errorView.visibility = View.VISIBLE
                }
            }
        }

        webView.setDownloadListener { url, _, _, _, _ -> openExternally(Uri.parse(url)) }
    }

    /** Colours the status/navigation bar area like the site (its theme-color or header colour). */
    private fun applySiteColors() {
        val script = """
            (function() {
              var m = document.querySelector('meta[name="theme-color"]');
              if (m && m.content) return m.content;
              var els = [document.querySelector('header'), document.body];
              for (var i = 0; i < els.length; i++) {
                if (!els[i]) continue;
                var c = getComputedStyle(els[i]).backgroundColor;
                if (c && c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)') return c;
              }
              return '';
            })()
        """.trimIndent()
        webView.evaluateJavascript(script) { result ->
            val color = parseCssColor(result?.trim('"').orEmpty()) ?: return@evaluateJavascript
            root.setBackgroundColor(color)
            val light = ColorUtils.calculateLuminance(color) > 0.5
            WindowInsetsControllerCompat(window, root).apply {
                isAppearanceLightStatusBars = light
                isAppearanceLightNavigationBars = light
            }
        }
    }

    private fun parseCssColor(value: String): Int? {
        val v = value.trim()
        if (v.startsWith("#")) {
            val hex = if (v.length == 4) "#" + v.drop(1).map { "$it$it" }.joinToString("") else v
            return runCatching { Color.parseColor(hex) }.getOrNull()
        }
        val nums = Regex("[\\d.]+").findAll(v).map { it.value.toFloat() }.toList()
        if (v.startsWith("rgb") && nums.size >= 3) {
            if (nums.size >= 4 && nums[3] == 0f) return null
            return Color.rgb(nums[0].toInt(), nums[1].toInt(), nums[2].toInt())
        }
        return null
    }

    private fun isOwnHost(host: String?): Boolean {
        val own = Uri.parse(BuildConfig.STORE_URL).host?.removePrefix("www.") ?: return false
        val h = host?.lowercase()?.removePrefix("www.") ?: return false
        return h == own || h.endsWith(".$own")
    }

    private fun isExternalHost(host: String?): Boolean {
        val h = host?.lowercase()?.removePrefix("www.") ?: return false
        if (isOwnHost(h)) return false
        return EXTERNAL_HOSTS.any { h == it || h.endsWith(".$it") }
    }

    private fun openExternally(uri: Uri) {
        val intent = if (uri.scheme == "intent") {
            runCatching { Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME) }.getOrNull() ?: return
        } else {
            Intent(Intent.ACTION_VIEW, uri)
        }
        try {
            startActivity(intent)
        } catch (_: ActivityNotFoundException) {
            intent.getStringExtra("browser_fallback_url")?.let { webView.loadUrl(it) }
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onResume() {
        super.onResume()
        webView.onResume()
    }

    override fun onPause() {
        webView.onPause()
        CookieManager.getInstance().flush()
        super.onPause()
    }

    override fun onDestroy() {
        webView.destroy()
        super.onDestroy()
    }

    private companion object {
        val EXTERNAL_HOSTS = listOf(
            "instagram.com", "t.me", "telegram.me", "wa.me", "whatsapp.com", "api.whatsapp.com",
            "facebook.com", "twitter.com", "x.com", "youtube.com", "youtu.be", "linkedin.com",
            "eitaa.com", "rubika.ir", "ble.ir", "splus.ir", "aparat.com",
            "maps.google.com", "goo.gl", "maps.app.goo.gl", "play.google.com",
        )
    }
}
