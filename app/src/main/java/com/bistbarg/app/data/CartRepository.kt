package com.bistbarg.app.data

import android.content.Context
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.math.BigDecimal

@Serializable
data class CartItem(
    val productId: Long,
    val variationId: Long? = null,
    val name: String,
    val variationLabel: String = "",
    val imageUrl: String = "",
    val unitPrice: String,
    val prices: Prices,
    val quantity: Int,
    val maxQuantity: Int = 9999,
) {
    /** The id WooCommerce needs to add this line to its cart. */
    val key: Long get() = variationId ?: productId
    val lineTotal: BigDecimal get() = (unitPrice.toBigDecimalOrNull() ?: BigDecimal.ZERO) * quantity.toBigDecimal()
}

/** The in-app cart, persisted in SharedPreferences. */
class CartRepository(context: Context) {
    private val prefs = context.getSharedPreferences("cart", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true }

    private val _items = MutableStateFlow(load())
    val items: StateFlow<List<CartItem>> = _items.asStateFlow()

    fun add(item: CartItem) = mutate { list ->
        if (list.any { it.key == item.key }) {
            list.map {
                if (it.key == item.key) it.copy(quantity = (it.quantity + item.quantity).coerceAtMost(it.maxQuantity)) else it
            }
        } else {
            list + item
        }
    }

    fun setQuantity(key: Long, quantity: Int) = mutate { list ->
        if (quantity <= 0) list.filterNot { it.key == key }
        else list.map { if (it.key == key) it.copy(quantity = quantity.coerceAtMost(it.maxQuantity)) else it }
    }

    fun remove(key: Long) = setQuantity(key, 0)

    fun removeAll(keys: Set<Long>) = mutate { list -> list.filterNot { it.key in keys } }

    private fun mutate(transform: (List<CartItem>) -> List<CartItem>) {
        _items.update(transform)
        prefs.edit().putString(KEY, json.encodeToString(_items.value)).apply()
    }

    private fun load(): List<CartItem> {
        val stored = prefs.getString(KEY, null) ?: return emptyList()
        return runCatching { json.decodeFromString<List<CartItem>>(stored) }.getOrDefault(emptyList())
    }

    private companion object {
        const val KEY = "items"
    }
}
