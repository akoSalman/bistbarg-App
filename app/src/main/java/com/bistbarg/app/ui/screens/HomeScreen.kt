package com.bistbarg.app.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Clear
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.AssistChip
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.bistbarg.app.R
import com.bistbarg.app.app
import com.bistbarg.app.data.Category
import com.bistbarg.app.data.decodeHtml
import com.bistbarg.app.ui.CategoriesViewModel
import com.bistbarg.app.ui.ProductListViewModel
import com.bistbarg.app.ui.components.ProductGrid

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(onProduct: (Long) -> Unit, onCategory: (Category) -> Unit) {
    val app = LocalContext.current.app
    val vm = viewModel(key = "home") { ProductListViewModel(app.api, categoryId = null) }
    val categoriesVm = viewModel { CategoriesViewModel(app.api) }
    val state by vm.state.collectAsStateWithLifecycle()
    val categories by categoriesVm.state.collectAsStateWithLifecycle()
    val focusManager = LocalFocusManager.current

    Scaffold(
        topBar = {
            TopAppBar(title = { Text(stringResource(R.string.app_name), fontWeight = FontWeight.Bold) })
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            OutlinedTextField(
                value = state.query,
                onValueChange = vm::setQuery,
                placeholder = { Text(stringResource(R.string.search_hint)) },
                leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                trailingIcon = {
                    if (state.query.isNotEmpty()) {
                        IconButton(onClick = { vm.setQuery("") }) {
                            Icon(Icons.Default.Clear, contentDescription = stringResource(R.string.clear))
                        }
                    }
                },
                singleLine = true,
                shape = MaterialTheme.shapes.extraLarge,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { focusManager.clearFocus() }),
                modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp),
            )
            val topLevel = categories.categories.filter { it.parent == 0L }
            if (topLevel.isNotEmpty() && state.query.isEmpty()) {
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(topLevel, key = { it.id }) { category ->
                        AssistChip(onClick = { onCategory(category) }, label = { Text(category.name.decodeHtml()) })
                    }
                }
            }
            ProductGrid(
                state = state,
                onRefresh = vm::refresh,
                onLoadMore = vm::loadMore,
                onRetry = vm::retry,
                onProduct = { onProduct(it.id) },
            )
        }
    }
}
