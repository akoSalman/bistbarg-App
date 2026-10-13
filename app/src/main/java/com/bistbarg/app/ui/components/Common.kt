package com.bistbarg.app.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import com.bistbarg.app.R
import com.bistbarg.app.data.PriceFormatter
import com.bistbarg.app.data.Product

@Composable
fun LoadingBox(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        CircularProgressIndicator()
    }
}

@Composable
fun ErrorBox(message: String?, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    Column(
        modifier.fillMaxWidth().padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
    ) {
        Text(
            stringResource(R.string.error_generic),
            style = MaterialTheme.typography.bodyLarge,
            textAlign = TextAlign.Center,
        )
        if (!message.isNullOrBlank()) {
            Text(
                message,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
        }
        Button(onClick = onRetry) { Text(stringResource(R.string.retry)) }
    }
}

@Composable
fun MessageBox(text: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null) {
    Column(
        modifier.fillMaxSize().padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
    ) {
        Text(text, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        action?.invoke()
    }
}

@Composable
fun ProductPrice(product: Product, large: Boolean = false) {
    val price = remember(product) { PriceFormatter.productPrice(product) }
    val regular = remember(product) {
        val p = product.prices
        if (product.onSale && p.priceRange == null && p.regularPrice != p.price) PriceFormatter.format(p.regularPrice, p) else null
    }
    val style = if (large) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.titleSmall
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(price, style = style, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
        if (regular != null) {
            Text(
                regular,
                style = if (large) MaterialTheme.typography.bodyLarge else MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textDecoration = TextDecoration.LineThrough,
            )
        }
    }
}

@Composable
fun QuantityStepper(quantity: Int, onChange: (Int) -> Unit, max: Int = 9999, min: Int = 1) {
    val decrease = stringResource(R.string.decrease)
    val increase = stringResource(R.string.increase)
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        FilledTonalIconButton(
            onClick = { onChange(quantity - 1) },
            enabled = quantity > min,
            modifier = Modifier.size(36.dp).semantics { contentDescription = decrease },
        ) { Text("−", style = MaterialTheme.typography.titleMedium) }
        Text(
            quantity.toString(),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.padding(horizontal = 8.dp),
        )
        FilledTonalIconButton(
            onClick = { onChange(quantity + 1) },
            enabled = quantity < max,
            modifier = Modifier.size(36.dp).semantics { contentDescription = increase },
        ) { Text("+", style = MaterialTheme.typography.titleMedium) }
    }
}
