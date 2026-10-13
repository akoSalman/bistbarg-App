package com.bistbarg.app

import android.net.Uri
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.bistbarg.app.ui.screens.AccountScreen
import com.bistbarg.app.ui.screens.CartScreen
import com.bistbarg.app.ui.screens.CategoriesScreen
import com.bistbarg.app.ui.screens.CategoryProductsScreen
import com.bistbarg.app.ui.screens.CheckoutScreen
import com.bistbarg.app.ui.screens.HomeScreen
import com.bistbarg.app.ui.screens.ProductDetailScreen
import com.bistbarg.app.ui.screens.WebScreen
import com.bistbarg.app.ui.theme.BistbargTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            BistbargTheme {
                ShopApp()
            }
        }
    }
}

private object Routes {
    const val HOME = "home"
    const val CATEGORIES = "categories"
    const val CART = "cart"
    const val ACCOUNT = "account"
    const val CHECKOUT = "checkout"
    const val PRODUCT = "product/{id}"
    const val CATEGORY = "category/{id}?name={name}"
    const val WEB = "web?url={url}&title={title}"

    fun product(id: Long) = "product/$id"
    fun category(id: Long, name: String) = "category/$id?name=${Uri.encode(name)}"
    fun web(url: String, title: String) = "web?url=${Uri.encode(url)}&title=${Uri.encode(title)}"
}

private data class Tab(val route: String, val label: Int, val icon: ImageVector)

private val tabs = listOf(
    Tab(Routes.HOME, R.string.nav_home, Icons.Default.Home),
    Tab(Routes.CATEGORIES, R.string.nav_categories, Icons.AutoMirrored.Filled.List),
    Tab(Routes.CART, R.string.nav_cart, Icons.Default.ShoppingCart),
    Tab(Routes.ACCOUNT, R.string.nav_account, Icons.Default.Person),
)

@Composable
private fun ShopApp() {
    val nav = rememberNavController()
    val backStack by nav.currentBackStackEntryAsState()
    val currentRoute = backStack?.destination?.route
    val cartItems by LocalContext.current.app.cart.items.collectAsStateWithLifecycle()
    val cartCount = cartItems.sumOf { it.quantity }

    Scaffold(
        contentWindowInsets = WindowInsets(0),
        bottomBar = {
            if (tabs.any { it.route == currentRoute }) {
                NavigationBar {
                    tabs.forEach { tab ->
                        NavigationBarItem(
                            selected = currentRoute == tab.route,
                            onClick = { nav.switchTab(tab.route) },
                            icon = {
                                if (tab.route == Routes.CART && cartCount > 0) {
                                    BadgedBox(badge = { Badge { Text(cartCount.toString()) } }) {
                                        Icon(tab.icon, contentDescription = null)
                                    }
                                } else {
                                    Icon(tab.icon, contentDescription = null)
                                }
                            },
                            label = { Text(stringResource(tab.label)) },
                        )
                    }
                }
            }
        },
    ) { innerPadding ->
        NavHost(
            navController = nav,
            startDestination = Routes.HOME,
            modifier = Modifier.padding(innerPadding).consumeWindowInsets(innerPadding),
        ) {
            composable(Routes.HOME) {
                HomeScreen(
                    onProduct = { nav.navigate(Routes.product(it)) },
                    onCategory = { nav.navigate(Routes.category(it.id, it.name)) },
                )
            }
            composable(Routes.CATEGORIES) {
                CategoriesScreen(onCategory = { nav.navigate(Routes.category(it.id, it.name)) })
            }
            composable(Routes.CART) {
                CartScreen(
                    onProduct = { nav.navigate(Routes.product(it)) },
                    onCheckout = { nav.navigate(Routes.CHECKOUT) },
                    onShop = { nav.switchTab(Routes.HOME) },
                )
            }
            composable(Routes.ACCOUNT) { AccountScreen() }
            composable(Routes.CHECKOUT) { CheckoutScreen(onBack = { nav.popBackStack() }) }
            composable(
                Routes.PRODUCT,
                arguments = listOf(navArgument("id") { type = NavType.LongType }),
            ) { entry ->
                ProductDetailScreen(
                    productId = entry.arguments?.getLong("id") ?: 0L,
                    onBack = { nav.popBackStack() },
                    onOpenCart = { nav.switchTab(Routes.CART) },
                    onOpenWeb = { url, title -> nav.navigate(Routes.web(url, title)) },
                )
            }
            composable(
                Routes.CATEGORY,
                arguments = listOf(
                    navArgument("id") { type = NavType.LongType },
                    navArgument("name") { type = NavType.StringType; defaultValue = "" },
                ),
            ) { entry ->
                CategoryProductsScreen(
                    categoryId = entry.arguments?.getLong("id") ?: 0L,
                    title = entry.arguments?.getString("name").orEmpty(),
                    onBack = { nav.popBackStack() },
                    onProduct = { nav.navigate(Routes.product(it)) },
                )
            }
            composable(
                Routes.WEB,
                arguments = listOf(
                    navArgument("url") { type = NavType.StringType; defaultValue = "" },
                    navArgument("title") { type = NavType.StringType; defaultValue = "" },
                ),
            ) { entry ->
                WebScreen(
                    url = entry.arguments?.getString("url").orEmpty(),
                    title = entry.arguments?.getString("title").orEmpty(),
                    onBack = { nav.popBackStack() },
                )
            }
        }
    }
}

private fun NavHostController.switchTab(route: String) {
    navigate(route) {
        popUpTo(graph.findStartDestination().id) { saveState = true }
        launchSingleTop = true
        restoreState = true
    }
}
