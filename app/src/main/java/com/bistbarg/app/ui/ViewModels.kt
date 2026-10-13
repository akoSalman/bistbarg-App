package com.bistbarg.app.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.bistbarg.app.data.Category
import com.bistbarg.app.data.Product
import com.bistbarg.app.data.StoreApi
import com.bistbarg.app.data.Term
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ProductListState(
    val products: List<Product> = emptyList(),
    val query: String = "",
    val loading: Boolean = false,
    val refreshing: Boolean = false,
    val error: String? = null,
    val endReached: Boolean = false,
)

class ProductListViewModel(private val api: StoreApi, private val categoryId: Long?) : ViewModel() {
    private val _state = MutableStateFlow(ProductListState())
    val state: StateFlow<ProductListState> = _state.asStateFlow()

    private var page = 0
    private var job: Job? = null

    init {
        reload(pull = false)
    }

    fun setQuery(query: String) {
        _state.update { it.copy(query = query) }
        job?.cancel()
        job = viewModelScope.launch {
            delay(400)
            load(reset = true, pull = false)
        }
    }

    fun refresh() = reload(pull = true)

    fun loadMore() {
        val s = _state.value
        if (s.loading || s.endReached || s.error != null) return
        job = viewModelScope.launch { load(reset = false, pull = false) }
    }

    fun retry() {
        if (_state.value.products.isEmpty()) {
            reload(pull = false)
        } else {
            _state.update { it.copy(error = null) }
            loadMore()
        }
    }

    private fun reload(pull: Boolean) {
        job?.cancel()
        job = viewModelScope.launch { load(reset = true, pull = pull) }
    }

    private suspend fun load(reset: Boolean, pull: Boolean) {
        val next = if (reset) 1 else page + 1
        _state.update { it.copy(loading = true, refreshing = pull, error = null) }
        try {
            val result = api.products(page = next, search = _state.value.query, categoryId = categoryId)
            page = next
            _state.update {
                it.copy(
                    products = if (reset) result.items else (it.products + result.items).distinctBy { p -> p.id },
                    loading = false,
                    refreshing = false,
                    endReached = next >= result.totalPages || result.items.isEmpty(),
                )
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            _state.update { it.copy(loading = false, refreshing = false, error = e.message ?: "") }
        }
    }
}

data class CategoriesState(
    val categories: List<Category> = emptyList(),
    val loading: Boolean = true,
    val error: String? = null,
)

class CategoriesViewModel(private val api: StoreApi) : ViewModel() {
    private val _state = MutableStateFlow(CategoriesState())
    val state: StateFlow<CategoriesState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            try {
                val categories = api.categories()
                    .filter { it.count > 0 }
                    .sortedWith(compareBy({ it.parent != 0L }, { -it.count }))
                _state.update { it.copy(categories = categories, loading = false) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                _state.update { it.copy(loading = false, error = e.message ?: "") }
            }
        }
    }
}

data class ProductDetailState(
    val product: Product? = null,
    val loading: Boolean = true,
    val error: String? = null,
    /** Selected term per variation attribute name. */
    val selection: Map<String, Term> = emptyMap(),
    val variation: Product? = null,
    val variationLoading: Boolean = false,
    val variationMissing: Boolean = false,
    val quantity: Int = 1,
) {
    /** The product (or chosen variation) that would be added to the cart. */
    val purchasable: Product?
        get() {
            val p = product ?: return null
            val target = if (p.isVariable) variation else p.takeIf { it.canBeAddedDirectly }
            return target?.takeIf { it.isPurchasable && it.isInStock }
        }
}

class ProductDetailViewModel(private val api: StoreApi, private val productId: Long) : ViewModel() {
    private val _state = MutableStateFlow(ProductDetailState())
    val state: StateFlow<ProductDetailState> = _state.asStateFlow()

    private var variationJob: Job? = null

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            try {
                val product = api.product(productId)
                _state.update { it.copy(product = product, loading = false) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                _state.update { it.copy(loading = false, error = e.message ?: "") }
            }
        }
    }

    fun select(attributeName: String, term: Term) {
        _state.update {
            it.copy(selection = it.selection + (attributeName to term), variation = null, variationMissing = false, quantity = 1)
        }
        resolveVariation()
    }

    fun setQuantity(quantity: Int) {
        val max = _state.value.purchasable?.addToCart?.maximum ?: 9999
        _state.update { it.copy(quantity = quantity.coerceIn(1, max.coerceAtLeast(1))) }
    }

    private fun resolveVariation() {
        val s = _state.value
        val product = s.product ?: return
        val attributes = product.variationAttributes
        if (attributes.any { it.name !in s.selection }) return

        val match = product.variations.firstOrNull { variation ->
            variation.attributes.all { va ->
                if (va.value.isEmpty()) return@all true // "Any …" attribute
                val chosen = s.selection.entries.firstOrNull { it.key.equals(va.name, ignoreCase = true) }?.value
                    ?: return@all true
                va.value.equals(chosen.slug, ignoreCase = true) || va.value.equals(chosen.name, ignoreCase = true)
            }
        }
        variationJob?.cancel()
        if (match == null) {
            _state.update { it.copy(variationMissing = true, variationLoading = false) }
            return
        }
        variationJob = viewModelScope.launch {
            _state.update { it.copy(variationLoading = true) }
            try {
                val variation = api.product(match.id)
                _state.update { it.copy(variation = variation, variationLoading = false) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                _state.update { it.copy(variationLoading = false, variationMissing = true) }
            }
        }
    }
}
