package com.bistbarg.app.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.bistbarg.app.BistbargApp
import com.bistbarg.app.BuildConfig
import com.bistbarg.app.R
import com.bistbarg.app.app
import com.bistbarg.app.ui.components.ErrorBox
import com.bistbarg.app.ui.components.WebPage
import kotlinx.coroutines.CancellationException

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WebScreen(url: String, title: String, onBack: (() -> Unit)?) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                navigationIcon = {
                    if (onBack != null) {
                        IconButton(onClick = onBack) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                        }
                    }
                },
            )
        },
    ) { padding ->
        WebPage(url = url, modifier = Modifier.padding(padding))
    }
}

@Composable
fun AccountScreen() {
    val app = LocalContext.current.app
    WebScreen(
        url = app.api.pageUrl(BuildConfig.ACCOUNT_PATH),
        title = stringResource(R.string.nav_account),
        onBack = null,
    )
}

private sealed interface CheckoutPhase {
    data object Preparing : CheckoutPhase
    data class Failed(val message: String?) : CheckoutPhase
    data class Ready(val url: String, val notAdded: List<String>) : CheckoutPhase
}

/**
 * Moves the in-app cart into the website cart (sharing cookies with the WebView), then shows the
 * shop's own checkout page so every payment gateway and shipping rule keeps working.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CheckoutScreen(onBack: () -> Unit) {
    val app = LocalContext.current.app
    var attempt by remember { mutableIntStateOf(0) }
    var phase by remember { mutableStateOf<CheckoutPhase>(CheckoutPhase.Preparing) }

    LaunchedEffect(attempt) {
        phase = CheckoutPhase.Preparing
        phase = moveCartToWebsite(app)
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.checkout)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        when (val p = phase) {
            CheckoutPhase.Preparing -> Column(
                Modifier.fillMaxSize().padding(padding),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
            ) {
                CircularProgressIndicator()
                Text(stringResource(R.string.preparing_checkout))
            }
            is CheckoutPhase.Failed -> Column(
                Modifier.fillMaxSize().padding(padding),
                verticalArrangement = Arrangement.Center,
            ) {
                ErrorBox(p.message, onRetry = { attempt++ })
            }
            is CheckoutPhase.Ready -> Column(Modifier.fillMaxSize().padding(padding)) {
                if (p.notAdded.isNotEmpty()) {
                    Surface(color = MaterialTheme.colorScheme.errorContainer, modifier = Modifier.fillMaxWidth()) {
                        Text(
                            stringResource(R.string.checkout_partial, p.notAdded.joinToString(", ")),
                            color = MaterialTheme.colorScheme.onErrorContainer,
                            style = MaterialTheme.typography.bodySmall,
                            modifier = Modifier.padding(12.dp),
                        )
                    }
                }
                WebPage(url = p.url, modifier = Modifier.weight(1f))
            }
        }
    }
}

private suspend fun moveCartToWebsite(app: BistbargApp): CheckoutPhase {
    val items = app.cart.items.value
    val moved = mutableSetOf<Long>()
    val notAdded = mutableListOf<String>()
    var checkoutUrl: String? = null
    var error: Exception? = null

    for (item in items) {
        try {
            val result = app.api.addToSiteCart(item.key, item.quantity)
            if (result.added) {
                moved += item.key
                if (checkoutUrl == null) checkoutUrl = result.checkoutUrl
            } else {
                notAdded += item.name
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e
            break
        }
    }

    // Items now live in the website cart; keep only the ones that could not be moved.
    app.cart.removeAll(moved)

    if (error != null && moved.isEmpty()) return CheckoutPhase.Failed(error.message)
    if (error != null) {
        notAdded += items.filter { it.key !in moved && it.name !in notAdded }.map { it.name }
    }
    return CheckoutPhase.Ready(checkoutUrl ?: app.api.pageUrl(BuildConfig.CHECKOUT_PATH), notAdded)
}
