package com.bistbarg.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.FormBody
import okhttp3.Headers
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException

class StoreApiException(message: String, val code: Int = 0) : IOException(message)

data class PageResult<T>(val items: List<T>, val totalPages: Int)

data class SiteCartResult(val added: Boolean, val cartUrl: String?, val checkoutUrl: String?)

/**
 * Talks to the public WooCommerce Store API (no API keys needed) and to the
 * classic `wc-ajax` endpoints for moving items into the website cart.
 */
class StoreApi(storeUrl: String, private val client: OkHttpClient) {

    val baseUrl: HttpUrl = storeUrl.trimEnd('/').toHttpUrl()

    // Sites without pretty permalinks don't serve /wp-json/; fall back to ?rest_route=.
    @Volatile private var useRestRoute = false

    private val json = Json {
        ignoreUnknownKeys = true
        coerceInputValues = true
        explicitNulls = false
        isLenient = true
    }

    fun pageUrl(path: String): String = baseUrl.resolve(path)?.toString() ?: baseUrl.toString()

    suspend fun products(
        page: Int,
        perPage: Int = 20,
        search: String? = null,
        categoryId: Long? = null,
    ): PageResult<Product> {
        val params = buildMap {
            put("page", page.toString())
            put("per_page", perPage.toString())
            if (!search.isNullOrBlank()) {
                put("search", search.trim())
            } else {
                put("orderby", "date")
                put("order", "desc")
            }
            if (categoryId != null) put("category", categoryId.toString())
        }
        val response = get("products", params)
        val totalPages = response.headers["X-WP-TotalPages"]?.toIntOrNull() ?: 1
        return PageResult(json.decodeFromString(response.body), totalPages)
    }

    suspend fun product(id: Long): Product = json.decodeFromString(get("products/$id").body)

    suspend fun categories(): List<Category> =
        json.decodeFromString(get("products/categories").body)

    /**
     * Adds a product (or a variation, by its own id) to the website cart. Cookies are shared
     * with the WebView, so the checkout page afterwards shows these items.
     */
    suspend fun addToSiteCart(productId: Long, quantity: Int): SiteCartResult = withContext(Dispatchers.IO) {
        val url = baseUrl.newBuilder().addQueryParameter("wc-ajax", "add_to_cart").build()
        val form = FormBody.Builder()
            .add("product_id", productId.toString())
            .add("quantity", quantity.toString())
            .build()
        val request = Request.Builder().url(url).post(form).header("Accept", "application/json").build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw StoreApiException("HTTP ${response.code}", response.code)
            val body = response.body?.string().orEmpty()
            val obj = runCatching { json.parseToJsonElement(body).jsonObject }.getOrNull()
                ?: return@use SiteCartResult(false, null, null)
            if (obj["error"]?.jsonPrimitive?.booleanOrNull == true) {
                return@use SiteCartResult(false, null, null)
            }
            val fragments = obj["fragments"]?.let { f ->
                runCatching { f.jsonObject.values.joinToString("\n") { it.jsonPrimitive.contentOrNull.orEmpty() } }
                    .getOrNull()
            }.orEmpty()
            val (cartUrl, checkoutUrl) = parseMiniCartLinks(fragments)
            SiteCartResult(true, cartUrl, checkoutUrl)
        }
    }

    private class RawResponse(val code: Int, val body: String, val headers: Headers, val isJson: Boolean)

    private class ApiResponse(val body: String, val headers: Headers)

    private suspend fun get(path: String, params: Map<String, String> = emptyMap()): ApiResponse =
        withContext(Dispatchers.IO) {
            if (!useRestRoute) {
                val raw = execute(buildUrl(path, params, restRoute = false))
                if (raw.code != 404 || raw.isJson) return@withContext raw.unwrap()
                useRestRoute = true
            }
            execute(buildUrl(path, params, restRoute = true)).unwrap()
        }

    private fun buildUrl(path: String, params: Map<String, String>, restRoute: Boolean): HttpUrl {
        val builder = baseUrl.newBuilder()
        if (restRoute) {
            builder.addQueryParameter("rest_route", "/wc/store/v1/$path")
        } else {
            builder.addPathSegments("wp-json/wc/store/v1/$path")
        }
        params.forEach { (k, v) -> builder.addQueryParameter(k, v) }
        return builder.build()
    }

    private fun execute(url: HttpUrl): RawResponse {
        val request = Request.Builder().url(url).header("Accept", "application/json").build()
        return client.newCall(request).execute().use { r ->
            RawResponse(
                code = r.code,
                body = r.body?.string().orEmpty(),
                headers = r.headers,
                isJson = r.header("Content-Type")?.contains("json") == true,
            )
        }
    }

    private fun RawResponse.unwrap(): ApiResponse {
        if (code !in 200..299) {
            val message = runCatching {
                json.parseToJsonElement(body).jsonObject["message"]?.jsonPrimitive?.contentOrNull
            }.getOrNull()
            throw StoreApiException(message?.decodeHtml() ?: "HTTP $code", code)
        }
        return ApiResponse(body, headers)
    }

    companion object {
        private val anchorTag = Regex("<a\\s[^>]*>", RegexOption.IGNORE_CASE)
        private val hrefAttr = Regex("href\\s*=\\s*[\"']([^\"']+)[\"']", RegexOption.IGNORE_CASE)
        private val classAttr = Regex("class\\s*=\\s*[\"']([^\"']+)[\"']", RegexOption.IGNORE_CASE)

        /** Finds the "View cart" / "Checkout" buttons in WooCommerce's mini-cart fragment. */
        fun parseMiniCartLinks(html: String): Pair<String?, String?> {
            var cart: String? = null
            var checkout: String? = null
            for (match in anchorTag.findAll(html)) {
                val tag = match.value
                val classes = classAttr.find(tag)?.groupValues?.get(1) ?: continue
                if ("wc-forward" !in classes) continue
                val href = hrefAttr.find(tag)?.groupValues?.get(1)?.replace("&amp;", "&") ?: continue
                if (!href.startsWith("http")) continue
                if ("checkout" in classes) {
                    if (checkout == null) checkout = href
                } else if (cart == null) {
                    cart = href
                }
            }
            return cart to checkout
        }
    }
}
