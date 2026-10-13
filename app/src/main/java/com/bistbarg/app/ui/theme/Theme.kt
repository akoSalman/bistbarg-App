package com.bistbarg.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Brand = Color(0xFF0B6E4F)
private val BrandLight = Color(0xFF6FD9AE)
private val Accent = Color(0xFFE76F51)

private val LightColors = lightColorScheme(
    primary = Brand,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFC9F0DD),
    onPrimaryContainer = Color(0xFF002115),
    secondary = Accent,
    onSecondary = Color.White,
    tertiary = Accent,
)

private val DarkColors = darkColorScheme(
    primary = BrandLight,
    onPrimary = Color(0xFF003825),
    primaryContainer = Color(0xFF005238),
    onPrimaryContainer = Color(0xFFC9F0DD),
    secondary = Color(0xFFFFB59F),
    tertiary = Color(0xFFFFB59F),
)

@Composable
fun BistbargTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (isSystemInDarkTheme()) DarkColors else LightColors,
        content = content,
    )
}
