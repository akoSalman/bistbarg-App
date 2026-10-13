package com.bistbarg.app

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Bitmap
import android.os.Bundle
import android.text.InputType
import android.util.Patterns
import android.view.View
import android.view.ViewGroup
import android.view.animation.OvershootInterpolator
import android.webkit.CookieManager
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.core.content.res.ResourcesCompat
import com.google.android.material.button.MaterialButton
import com.google.android.material.checkbox.MaterialCheckBox
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
import org.json.JSONArray
import org.json.JSONObject

/**
 * Native sign-up screen.
 *
 * The site's own WooCommerce "My account" page is loaded in a hidden WebView. Its registration
 * form is read and rebuilt here with native fields; on submit the values are put back into the
 * real form and it is submitted, so the site does all validation and the shopper ends up logged in
 * (the WebView shares its cookies with the main screen).
 *
 * If the site uses something that cannot be rebuilt natively (captcha, SMS sign-up plugin,
 * unusual fields), the site's page is shown instead, without its header and footer.
 */
class RegisterActivity : ComponentActivity() {

    private enum class State { LOADING, FORM, SUBMITTING, WEB, SUCCESS, OFFLINE }

    private data class Field(val name: String, val type: String, val label: String, val required: Boolean)

    private lateinit var webView: WebView
    private lateinit var formScroll: View
    private lateinit var fieldsBox: LinearLayout
    private lateinit var formError: TextView
    private lateinit var submit: MaterialButton
    private lateinit var loading: View
    private lateinit var offline: View
    private lateinit var success: View

    private var state = State.LOADING
    private var fields = emptyList<Field>()
    private val inputs = mutableMapOf<String, View>()
    private var navigatedSinceSubmit = false

    private val accountUrl get() = BuildConfig.STORE_URL.trimEnd('/') + BuildConfig.ACCOUNT_PATH

    private val submitTimeout = Runnable {
        if (state == State.SUBMITTING && !navigatedSinceSubmit) {
            // The form may have been sent with JavaScript; reload to see whether we are logged in.
            webView.reload()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_register)
        webView = findViewById(R.id.web)
        formScroll = findViewById(R.id.form_scroll)
        fieldsBox = findViewById(R.id.fields)
        formError = findViewById(R.id.form_error)
        submit = findViewById(R.id.submit)
        loading = findViewById(R.id.loading)
        offline = findViewById(R.id.offline)
        success = findViewById(R.id.success)

        findViewById<View>(R.id.back).setOnClickListener { finish() }
        findViewById<View>(R.id.login).setOnClickListener {
            setResult(RESULT_OK, Intent().putExtra(MainActivity.EXTRA_ACTION, MainActivity.ACTION_LOGIN))
            finish()
        }
        findViewById<View>(R.id.retry).setOnClickListener { loadPage() }
        submit.setOnClickListener { onSubmit() }

        setupWebView()
        loadPage()
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true)
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            textZoom = 100
            userAgentString = "$userAgentString BistbargApp/${BuildConfig.VERSION_NAME}"
        }
        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                navigatedSinceSubmit = true
            }

            override fun onPageFinished(view: WebView, url: String) {
                if (state == State.WEB) hideSiteChrome()
                if (state != State.OFFLINE) inspectPage()
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) show(State.OFFLINE)
            }
        }
    }

    private fun loadPage() {
        show(State.LOADING)
        webView.loadUrl(accountUrl)
    }

    private fun show(newState: State) {
        state = newState
        loading.visibility = if (newState == State.LOADING || newState == State.SUBMITTING && fields.isEmpty()) View.VISIBLE else View.GONE
        formScroll.visibility = if (newState == State.FORM || newState == State.SUBMITTING && fields.isNotEmpty()) View.VISIBLE else View.GONE
        offline.visibility = if (newState == State.OFFLINE) View.VISIBLE else View.GONE
        success.visibility = if (newState == State.SUCCESS) View.VISIBLE else View.GONE
        webView.visibility = if (newState == State.WEB) View.VISIBLE else View.INVISIBLE
        submit.isEnabled = newState == State.FORM
        submit.setText(if (newState == State.SUBMITTING) R.string.register_submitting else R.string.register_submit)
    }

    /** Reads the page: logged in? registration form? errors? */
    private fun inspectPage() {
        webView.evaluateJavascript(INSPECT_JS) { raw ->
            val page = runCatching { JSONObject(raw) }.getOrNull() ?: return@evaluateJavascript
            if (page.optBoolean("logged")) {
                onLoggedIn()
                return@evaluateJavascript
            }
            when (state) {
                State.LOADING -> {
                    val parsed = parseFields(page)
                    if (parsed == null) {
                        show(State.WEB)
                        hideSiteChrome()
                    } else {
                        fields = parsed
                        buildForm()
                        show(State.FORM)
                    }
                }
                State.SUBMITTING -> {
                    if (!navigatedSinceSubmit) return@evaluateJavascript
                    val parsed = parseFields(page)
                    if (parsed == null) {
                        show(State.WEB)
                        hideSiteChrome()
                    } else {
                        if (parsed.map { it.name } != fields.map { it.name }) {
                            val values = collectValues()
                            fields = parsed
                            buildForm(values)
                        }
                        showError(page.optJSONArray("errors").joinLines().ifEmpty { getString(R.string.register_failed) })
                        show(State.FORM)
                    }
                }
                else -> Unit
            }
        }
    }

    /** Returns the form's visible fields, or null if the form can't be shown natively. */
    private fun parseFields(page: JSONObject): List<Field>? {
        if (!page.optBoolean("form") || page.optBoolean("captcha")) return null
        val arr = page.optJSONArray("fields") ?: return null
        val result = mutableListOf<Field>()
        for (i in 0 until arr.length()) {
            val f = arr.getJSONObject(i)
            val type = f.optString("type")
            if (type !in SUPPORTED_TYPES) return null
            val name = f.optString("name")
            if (result.any { it.name == name }) return null
            result += Field(name, type, f.optString("label"), f.optBoolean("required"))
        }
        return result.takeIf { it.isNotEmpty() }
    }

    private fun buildForm(previous: Map<String, String> = emptyMap()) {
        fieldsBox.removeAllViews()
        inputs.clear()
        val font = ResourcesCompat.getFont(this, R.font.vazirmatn)
        val gap = (14 * resources.displayMetrics.density).toInt()

        for (field in fields) {
            val label = persianLabel(field)
            val view: View = if (field.type == "checkbox") {
                MaterialCheckBox(this).apply {
                    text = label
                    typeface = font
                    isChecked = previous[field.name] == "1"
                }
            } else {
                TextInputLayout(this, null, com.google.android.material.R.attr.textInputOutlinedStyle).apply {
                    hint = if (field.required) "$label *" else label
                    val r = 14 * resources.displayMetrics.density
                    setBoxCornerRadii(r, r, r, r)
                    typeface = font
                    if (field.type == "password") endIconMode = TextInputLayout.END_ICON_PASSWORD_TOGGLE
                    val edit = TextInputEditText(context).apply {
                        typeface = font
                        inputType = inputTypeFor(field.type)
                        if (field.type != "textarea") maxLines = 1
                        if (field.type in LTR_TYPES) textDirection = View.TEXT_DIRECTION_LTR
                        setText(previous[field.name].orEmpty())
                    }
                    addView(edit)
                }
            }
            fieldsBox.addView(
                view,
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                    .apply { topMargin = gap },
            )
            inputs[field.name] = view
        }
    }

    private fun persianLabel(field: Field): String {
        val known = when (field.name) {
            "email", "billing_email" -> R.string.field_email
            "username" -> R.string.field_username
            "password" -> R.string.field_password
            "billing_phone", "phone", "mobile" -> R.string.field_phone
            "first_name", "billing_first_name" -> R.string.field_first_name
            "last_name", "billing_last_name" -> R.string.field_last_name
            else -> null
        }
        return known?.let { getString(it) } ?: field.label.ifBlank { field.name }
    }

    private fun inputTypeFor(type: String) = when (type) {
        "email" -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
        "password" -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        "tel" -> InputType.TYPE_CLASS_PHONE
        "number" -> InputType.TYPE_CLASS_NUMBER
        "url" -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
        "textarea" -> InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_MULTI_LINE
        else -> InputType.TYPE_CLASS_TEXT
    }

    private fun collectValues(): Map<String, String> = inputs.mapValues { (_, view) ->
        when (view) {
            is MaterialCheckBox -> if (view.isChecked) "1" else ""
            is TextInputLayout -> view.editText?.text?.toString()?.trim().orEmpty()
            else -> ""
        }
    }

    private fun onSubmit() {
        formError.visibility = View.GONE
        val values = collectValues()
        var firstInvalid: View? = null
        for (field in fields) {
            val view = inputs[field.name] ?: continue
            val value = values[field.name].orEmpty()
            val error = when {
                field.required && value.isEmpty() -> getString(R.string.field_required)
                field.type == "email" && value.isNotEmpty() && !Patterns.EMAIL_ADDRESS.matcher(value).matches() ->
                    getString(R.string.field_invalid_email)
                else -> null
            }
            when (view) {
                is TextInputLayout -> view.error = error
                is MaterialCheckBox -> view.error = error
            }
            if (error != null && firstInvalid == null) firstInvalid = view
        }
        if (firstInvalid != null) {
            firstInvalid.requestFocus()
            return
        }

        show(State.SUBMITTING)
        val json = JSONObject(values).toString()
        webView.evaluateJavascript("($SUBMIT_JS)($json)") { raw ->
            val result = raw?.trim('"').orEmpty()
            when {
                result == "ok" -> {
                    navigatedSinceSubmit = false
                    webView.removeCallbacks(submitTimeout)
                    webView.postDelayed(submitTimeout, 6000)
                }
                result.startsWith("blocked:") -> {
                    // e.g. WooCommerce's password strength meter keeps the button disabled.
                    showError(result.removePrefix("blocked:").ifBlank { getString(R.string.register_failed) })
                    show(State.FORM)
                }
                else -> {
                    show(State.WEB)
                    hideSiteChrome()
                }
            }
        }
    }

    private fun showError(message: String) {
        formError.text = message
        formError.visibility = View.VISIBLE
        formScroll.post { formScroll.scrollTo(0, 0) }
    }

    private fun onLoggedIn() {
        webView.removeCallbacks(submitTimeout)
        if (state == State.LOADING) {
            Toast.makeText(this, R.string.register_already_logged_in, Toast.LENGTH_SHORT).show()
            finishWith(MainActivity.ACTION_REGISTERED)
            return
        }
        if (state == State.SUCCESS) return
        CookieManager.getInstance().flush()
        show(State.SUCCESS)
        findViewById<View>(R.id.success_icon).apply {
            scaleX = 0f
            scaleY = 0f
            animate().scaleX(1f).scaleY(1f).setDuration(600).setInterpolator(OvershootInterpolator(2.5f)).start()
        }
        success.postDelayed({ finishWith(MainActivity.ACTION_REGISTERED) }, 1800)
    }

    private fun finishWith(action: String) {
        setResult(RESULT_OK, Intent().putExtra(MainActivity.EXTRA_ACTION, action))
        finish()
    }

    /** Fallback mode: show only the account/registration part of the site's page. */
    private fun hideSiteChrome() {
        webView.evaluateJavascript(HIDE_CHROME_JS, null)
    }

    private fun JSONArray?.joinLines(): String {
        if (this == null) return ""
        return (0 until length()).map { optString(it) }.filter { it.isNotBlank() }.distinct().joinToString("\n")
    }

    override fun onDestroy() {
        webView.removeCallbacks(submitTimeout)
        webView.destroy()
        super.onDestroy()
    }

    @Suppress("DEPRECATION")
    override fun finish() {
        super.finish()
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
    }

    private companion object {
        val SUPPORTED_TYPES = setOf("text", "email", "password", "tel", "number", "url", "checkbox", "textarea")
        val LTR_TYPES = setOf("email", "password", "tel", "number", "url")

        const val FORM_SELECTOR = "form.woocommerce-form-register, form.register"

        val INSPECT_JS = """
            (function() {
              var logged = !!(document.body && document.body.classList.contains('logged-in'));
              var errEls = document.querySelectorAll('.woocommerce-error li');
              if (!errEls.length) errEls = document.querySelectorAll('.woocommerce-error');
              var errs = Array.prototype.map.call(errEls, function(e) { return (e.innerText || '').trim(); });
              var form = document.querySelector('$FORM_SELECTOR');
              if (!form) return { logged: logged, form: false, errors: errs };
              var captcha = !!form.querySelector('.g-recaptcha, .h-captcha, .cf-turnstile, iframe, [data-sitekey]');
              var fields = [];
              Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function(el) {
                var t = (el.getAttribute('type') || el.tagName).toLowerCase();
                if (el.tagName === 'SELECT') t = 'select';
                if (el.tagName === 'TEXTAREA') t = 'textarea';
                if (!el.name || t === 'hidden' || t === 'submit' || t === 'button') return;
                var lab = el.id ? form.querySelector('label[for="' + el.id + '"]') : null;
                if (!lab) lab = el.closest('label');
                var text = lab ? lab.innerText : (el.placeholder || '');
                fields.push({
                  name: el.name,
                  type: t,
                  label: text.replace(/[*]/g, '').trim(),
                  required: el.required || !!(lab && lab.querySelector('.required'))
                });
              });
              return { logged: logged, form: true, captcha: captcha, fields: fields, errors: errs };
            })()
        """.trimIndent()

        val SUBMIT_JS = """
            function(values) {
              var form = document.querySelector('$FORM_SELECTOR');
              if (!form) return 'noform';
              Object.keys(values).forEach(function(name) {
                var el = form.querySelector('[name="' + name + '"]');
                if (!el) return;
                if (el.type === 'checkbox') { el.checked = values[name] === '1'; }
                else { el.value = values[name]; }
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('keyup', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
              });
              var btn = form.querySelector('[name="register"], [type="submit"]');
              if (btn && btn.disabled) {
                var hint = form.querySelector('.woocommerce-password-strength, .woocommerce-password-hint');
                return 'blocked:' + (hint ? hint.innerText.trim() : '');
              }
              if (btn) btn.click(); else form.submit();
              return 'ok';
            }
        """.trimIndent()

        val HIDE_CHROME_JS = """
            (function() {
              var css = 'header, footer, #masthead, #colophon, .site-header, .site-footer,' +
                '.elementor-location-header, .elementor-location-footer, #wpadminbar,' +
                '.woocommerce-breadcrumb, .breadcrumbs { display: none !important; }' +
                'body { padding-top: 8px !important; }';
              if (document.getElementById('bistbarg-app-css')) return;
              var s = document.createElement('style');
              s.id = 'bistbarg-app-css';
              s.textContent = css;
              document.head.appendChild(s);
            })()
        """.trimIndent()
    }
}
