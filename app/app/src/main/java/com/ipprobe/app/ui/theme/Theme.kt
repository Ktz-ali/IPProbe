package com.ipprobe.app.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val TechColors = darkColorScheme(
    primary = NeoCyan,
    onPrimary = Color(0xFF04101A),
    secondary = NeoMagenta,
    onSecondary = Color(0xFF2B0A24),
    tertiary = NeoPurple,
    onTertiary = Color(0xFF160B33),
    background = BgDeep,
    onBackground = TextMain,
    surface = BgPanel,
    onSurface = TextMain,
    surfaceVariant = BgPanel2,
    onSurfaceVariant = TextDim,
    error = NeoRed,
    onError = Color(0xFF1A050A),
    outline = NeoCyan.copy(alpha = 0.35f),
)

/** 炫酷科技风主题（全局深色霓虹，不跟随系统） */
@Composable
fun IpProbeTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = TechColors,
        typography = Typography,
        content = content,
    )
}