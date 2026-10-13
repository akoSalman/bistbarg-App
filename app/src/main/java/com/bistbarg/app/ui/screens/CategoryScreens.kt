package com.bistbarg.app.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import com.bistbarg.app.R
import com.bistbarg.app.app
import com.bistbarg.app.data.Category
import com.bistbarg.app.data.decodeHtml
import com.bistbarg.app.ui.CategoriesViewModel
import com.bistbarg.app.ui.ProductListViewModel
import com.bistbarg.app.ui.components.ErrorBox
import com.bistbarg.app.ui.components.LoadingBox
import com.bistbarg.app.ui.components.MessageBox
import com.bistbarg.app.ui.components.ProductGrid

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CategoriesScreen(onCategory: (Category) -> Unit) {
    val app = LocalContext.current.app
    val vm = viewModel { CategoriesViewModel(app.api) }
    val state by vm.state.collectAsStateWithLifecycle()

    Scaffold(topBar = { TopAppBar(title = { Text(stringResource(R.string.nav_categories)) }) }) { padding ->
        when {
            state.loading -> LoadingBox(Modifier.padding(padding))
            state.error != null -> ErrorBox(state.error, vm::load, Modifier.padding(padding))
            state.categories.isEmpty() -> MessageBox(stringResource(R.string.no_categories), Modifier.padding(padding))
            else -> LazyVerticalGrid(
                columns = GridCells.Adaptive(150.dp),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
                modifier = Modifier.fillMaxSize().padding(padding),
            ) {
                items(state.categories, key = { it.id }) { category ->
                    CategoryCard(category, onClick = { onCategory(category) })
                }
            }
        }
    }
}

@Composable
private fun CategoryCard(category: Category, onClick: () -> Unit) {
    val name = category.name.decodeHtml()
    Card(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        AsyncImage(
            model = category.image?.thumbnailOrSrc,
            contentDescription = name,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .fillMaxWidth()
                .aspectRatio(1.4f)
                .background(MaterialTheme.colorScheme.primaryContainer),
        )
        Column(Modifier.padding(10.dp)) {
            Text(name, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                stringResource(R.string.products_count, category.count),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CategoryProductsScreen(categoryId: Long, title: String, onBack: () -> Unit, onProduct: (Long) -> Unit) {
    val app = LocalContext.current.app
    val vm = viewModel(key = "category-$categoryId") { ProductListViewModel(app.api, categoryId) }
    val state by vm.state.collectAsStateWithLifecycle()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(title.decodeHtml(), maxLines = 1, overflow = TextOverflow.Ellipsis) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        ProductGrid(
            state = state,
            onRefresh = vm::refresh,
            onLoadMore = vm::loadMore,
            onRetry = vm::retry,
            onProduct = { onProduct(it.id) },
            modifier = Modifier.padding(padding),
        )
    }
}
