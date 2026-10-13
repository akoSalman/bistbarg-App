package com.bistbarg.app.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.bistbarg.app.R
import com.bistbarg.app.app
import com.bistbarg.app.data.CartItem
import com.bistbarg.app.data.PriceFormatter
import com.bistbarg.app.ui.components.MessageBox
import com.bistbarg.app.ui.components.QuantityStepper

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CartScreen(onProduct: (Long) -> Unit, onCheckout: () -> Unit, onShop: () -> Unit) {
    val cart = LocalContext.current.app.cart
    val items by cart.items.collectAsStateWithLifecycle()

    Scaffold(
        topBar = { TopAppBar(title = { Text(stringResource(R.string.nav_cart)) }) },
        bottomBar = {
            if (items.isNotEmpty()) {
                val total = items.fold(java.math.BigDecimal.ZERO) { acc, item -> acc + item.lineTotal }
                Surface(tonalElevation = 3.dp) {
                    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(R.string.total), style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                            Text(
                                PriceFormatter.format(total, items.first().prices),
                                style = MaterialTheme.typography.titleLarge,
                                fontWeight = FontWeight.Bold,
                                color = MaterialTheme.colorScheme.primary,
                            )
                        }
                        Text(
                            stringResource(R.string.checkout_note),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Button(onClick = onCheckout, modifier = Modifier.fillMaxWidth()) {
                            Text(stringResource(R.string.checkout))
                        }
                    }
                }
            }
        },
    ) { padding ->
        if (items.isEmpty()) {
            MessageBox(stringResource(R.string.cart_empty), Modifier.padding(padding)) {
                Button(onClick = onShop) { Text(stringResource(R.string.start_shopping)) }
            }
        } else {
            LazyColumn(
                contentPadding = PaddingValues(vertical = 8.dp),
                modifier = Modifier.fillMaxSize().padding(padding),
            ) {
                items(items, key = { it.key }) { item ->
                    CartRow(
                        item = item,
                        onClick = { onProduct(item.productId) },
                        onQuantity = { cart.setQuantity(item.key, it) },
                        onRemove = { cart.remove(item.key) },
                    )
                    HorizontalDivider()
                }
            }
        }
    }
}

@Composable
private fun CartRow(item: CartItem, onClick: () -> Unit, onQuantity: (Int) -> Unit, onRemove: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        AsyncImage(
            model = item.imageUrl.ifEmpty { null },
            contentDescription = item.name,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .size(80.dp)
                .clip(MaterialTheme.shapes.medium)
                .background(MaterialTheme.colorScheme.surfaceVariant),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(item.name, style = MaterialTheme.typography.titleSmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (item.variationLabel.isNotEmpty()) {
                Text(item.variationLabel, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Text(
                PriceFormatter.format(item.lineTotal, item.prices),
                style = MaterialTheme.typography.titleSmall,
                color = MaterialTheme.colorScheme.primary,
            )
            QuantityStepper(quantity = item.quantity, onChange = onQuantity, max = item.maxQuantity)
        }
        IconButton(onClick = onRemove) {
            Icon(Icons.Default.Delete, contentDescription = stringResource(R.string.remove))
        }
    }
}
