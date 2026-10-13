package com.bistbarg.app.ui.screens

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.fromHtml
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import com.bistbarg.app.R
import com.bistbarg.app.app
import com.bistbarg.app.data.CartItem
import com.bistbarg.app.data.Product
import com.bistbarg.app.data.decodeHtml
import com.bistbarg.app.ui.ProductDetailState
import com.bistbarg.app.ui.ProductDetailViewModel
import com.bistbarg.app.ui.components.ErrorBox
import com.bistbarg.app.ui.components.LoadingBox
import com.bistbarg.app.ui.components.ProductPrice
import com.bistbarg.app.ui.components.QuantityStepper
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProductDetailScreen(
    productId: Long,
    onBack: () -> Unit,
    onOpenCart: () -> Unit,
    onOpenWeb: (url: String, title: String) -> Unit,
) {
    val context = LocalContext.current
    val app = context.app
    val vm = viewModel(key = "product-$productId") { ProductDetailViewModel(app.api, productId) }
    val state by vm.state.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    val addedText = stringResource(R.string.added_to_cart)
    val viewCartText = stringResource(R.string.view_cart)

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = {},
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                    }
                },
                actions = {
                    val permalink = state.product?.permalink
                    if (!permalink.isNullOrEmpty()) {
                        IconButton(onClick = {
                            val send = Intent(Intent.ACTION_SEND).apply {
                                type = "text/plain"
                                putExtra(Intent.EXTRA_TEXT, permalink)
                            }
                            context.startActivity(Intent.createChooser(send, null))
                        }) {
                            Icon(Icons.Default.Share, contentDescription = stringResource(R.string.share))
                        }
                    }
                },
            )
        },
        bottomBar = {
            val product = state.product
            if (product != null) {
                BuyBar(
                    state = state,
                    onQuantity = vm::setQuantity,
                    onAdd = {
                        state.toCartItem()?.let { app.cart.add(it) }
                        scope.launch {
                            snackbar.currentSnackbarData?.dismiss()
                            val result = snackbar.showSnackbar(addedText, viewCartText, duration = SnackbarDuration.Short)
                            if (result == SnackbarResult.ActionPerformed) onOpenCart()
                        }
                    },
                    onOpenWebsite = { onOpenWeb(product.permalink, product.name.decodeHtml()) },
                )
            }
        },
    ) { padding ->
        val product = state.product
        when {
            product != null -> ProductContent(
                state = state,
                product = product,
                onSelect = vm::select,
                onOpenWebsite = { onOpenWeb(product.permalink, product.name.decodeHtml()) },
                modifier = Modifier.padding(padding),
            )
            state.loading -> LoadingBox(Modifier.padding(padding))
            else -> Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                ErrorBox(state.error, vm::load)
            }
        }
    }
}

private fun ProductDetailState.toCartItem(): CartItem? {
    val parent = product ?: return null
    val target = purchasable ?: return null
    val image = (target.images.firstOrNull() ?: parent.images.firstOrNull())?.thumbnailOrSrc.orEmpty()
    return CartItem(
        productId = parent.id,
        variationId = if (parent.isVariable) target.id else null,
        name = parent.name.decodeHtml(),
        variationLabel = if (parent.isVariable) selection.values.joinToString(", ") { it.name.decodeHtml() } else "",
        imageUrl = image,
        unitPrice = target.prices.price,
        prices = target.prices,
        quantity = quantity,
        maxQuantity = target.addToCart.maximum.coerceAtLeast(1),
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ProductContent(
    state: ProductDetailState,
    product: Product,
    onSelect: (String, com.bistbarg.app.data.Term) -> Unit,
    onOpenWebsite: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val name = remember(product.id) { product.name.decodeHtml() }
    val shortDescription = remember(product.id) { AnnotatedString.fromHtml(product.shortDescription.trim()) }
    val description = remember(product.id) { AnnotatedString.fromHtml(product.description.trim()) }
    val shown = state.variation ?: product

    LazyColumn(modifier.fillMaxSize()) {
        item {
            val images = (state.variation?.images.orEmpty() + product.images).distinctBy { it.src }
            ImagePager(images.map { it.src }, name)
        }
        item {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.SemiBold)
                ProductPrice(shown, large = true)
                Text(
                    stringResource(if (shown.isInStock) R.string.in_stock else R.string.out_of_stock),
                    style = MaterialTheme.typography.labelLarge,
                    color = if (shown.isInStock) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                )
                if (shortDescription.isNotBlank()) {
                    Text(shortDescription, style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
        if (product.isVariable) {
            product.variationAttributes.forEach { attribute ->
                item(key = "attr-${attribute.name}") {
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
                        Text(attribute.name.decodeHtml(), style = MaterialTheme.typography.titleSmall)
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            attribute.terms.forEach { term ->
                                FilterChip(
                                    selected = state.selection[attribute.name] == term,
                                    onClick = { onSelect(attribute.name, term) },
                                    label = { Text(term.name.decodeHtml()) },
                                )
                            }
                        }
                    }
                }
            }
            item {
                Box(Modifier.padding(horizontal = 16.dp)) {
                    when {
                        state.variationLoading -> CircularProgressIndicator(Modifier.size(24.dp))
                        state.variationMissing -> Text(
                            stringResource(R.string.option_unavailable),
                            color = MaterialTheme.colorScheme.error,
                        )
                    }
                }
            }
        }
        if (description.isNotBlank()) {
            item {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    HorizontalDivider()
                    Text(stringResource(R.string.description), style = MaterialTheme.typography.titleMedium)
                    Text(description, style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
        if (product.permalink.isNotEmpty()) {
            item {
                OutlinedButton(onClick = onOpenWebsite, modifier = Modifier.padding(16.dp)) {
                    Text(stringResource(R.string.view_on_website))
                }
            }
        }
    }
}

@Composable
private fun ImagePager(images: List<String>, contentDescription: String) {
    if (images.isEmpty()) {
        Box(
            Modifier
                .fillMaxWidth()
                .aspectRatio(1f)
                .background(MaterialTheme.colorScheme.surfaceVariant),
        )
        return
    }
    val pagerState = rememberPagerState { images.size }
    Box {
        HorizontalPager(state = pagerState) { page ->
            AsyncImage(
                model = images[page],
                contentDescription = contentDescription,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .fillMaxWidth()
                    .aspectRatio(1f)
                    .background(MaterialTheme.colorScheme.surfaceVariant),
            )
        }
        if (images.size > 1) {
            Row(
                Modifier.align(Alignment.BottomCenter).padding(12.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                repeat(images.size) { index ->
                    val selected = pagerState.currentPage == index
                    Box(
                        Modifier
                            .size(if (selected) 10.dp else 8.dp)
                            .clip(CircleShape)
                            .background(
                                if (selected) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.surface.copy(alpha = 0.8f),
                            ),
                    )
                }
            }
        }
    }
}

@Composable
private fun BuyBar(
    state: ProductDetailState,
    onQuantity: (Int) -> Unit,
    onAdd: () -> Unit,
    onOpenWebsite: () -> Unit,
) {
    val product = state.product ?: return
    Surface(tonalElevation = 3.dp, shadowElevation = 8.dp) {
        Row(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (!product.isVariable && !product.canBeAddedDirectly) {
                // Grouped / external products are bought on the website.
                Button(onClick = onOpenWebsite, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.view_on_website))
                }
                return@Row
            }
            val target = state.purchasable
            QuantityStepper(
                quantity = state.quantity,
                onChange = onQuantity,
                max = target?.addToCart?.maximum ?: 1,
            )
            Button(onClick = onAdd, enabled = target != null, modifier = Modifier.weight(1f)) {
                Text(
                    stringResource(
                        when {
                            product.isVariable && state.variation == null -> R.string.choose_options
                            target == null -> R.string.out_of_stock
                            else -> R.string.add_to_cart
                        },
                    ),
                )
            }
        }
    }
}
