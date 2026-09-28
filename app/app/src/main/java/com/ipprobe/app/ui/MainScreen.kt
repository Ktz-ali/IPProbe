package com.ipprobe.app.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.widget.Toast
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.ipprobe.app.data.IpProfile
import com.ipprobe.app.data.LogStatsData
import com.ipprobe.app.data.NameCount
import com.ipprobe.app.data.Probe
import com.ipprobe.app.data.StatsData
import com.ipprobe.app.data.VisitLog
import com.ipprobe.app.ui.theme.BgDeep
import com.ipprobe.app.ui.theme.BgPanel
import com.ipprobe.app.ui.theme.BgPanel2
import com.ipprobe.app.ui.theme.NeoAmber
import com.ipprobe.app.ui.theme.NeoCyan
import com.ipprobe.app.ui.theme.NeoGreen
import com.ipprobe.app.ui.theme.NeoMagenta
import com.ipprobe.app.ui.theme.NeoPurple
import com.ipprobe.app.ui.theme.NeoRed
import com.ipprobe.app.ui.theme.TextDim
import com.ipprobe.app.ui.theme.TextMain
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

fun formatTime(ms: Long): String =
    if (ms <= 0) "暂无" else SimpleDateFormat("MM-dd HH:mm", Locale.getDefault()).format(Date(ms))

fun formatTimeFull(ms: Long): String =
    if (ms <= 0) "暂无" else SimpleDateFormat("MM-dd HH:mm:ss", Locale.getDefault()).format(Date(ms))

/** 访问模板选项 */
private val TEMPLATES = listOf(
    "blank" to "空白页",
    "fake404" to "伪装404",
    "fake503" to "伪装503",
    "image" to "图片页",
    "text" to "文字页",
    "redirect" to "跳转页",
    "weather" to "天气页（推荐：真实天气+定位自然）",
)

/** 短链服务选项（自建=自己域名，防红） */
private val SHORTENERS = listOf(
    "self" to "自建短链",
    "tinyurl" to "TinyURL",
    "clckru" to "clck.ru",
    "auto" to "自动",
)

fun templateLabel(t: String): String = TEMPLATES.firstOrNull { it.first == t }?.second ?: t

fun shortenerLabel(s: String): String = SHORTENERS.firstOrNull { it.first == s }?.second ?: s

/** 生成二维码 Bitmap（zxing core，v1.5 二维码分享） */
fun generateQrBitmap(text: String, size: Int = 512): Bitmap? {
    if (text.isEmpty()) return null
    return try {
        val hints = mapOf(EncodeHintType.MARGIN to 1)
        val matrix = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, hints)
        val pixels = IntArray(size * size)
        for (y in 0 until size) {
            for (x in 0 until size) {
                pixels[y * size + x] = if (matrix[x, y]) 0xFF000000.toInt() else 0xFFFFFFFF.toInt()
            }
        }
        Bitmap.createBitmap(pixels, size, size, Bitmap.Config.ARGB_8888)
    } catch (e: Exception) {
        null
    }
}

/** 连接状态判定（与状态消息文案匹配） */
private fun isOnline(ui: UiState): Boolean =
    ui.statusMsg.contains("成功") || ui.statusMsg.contains("已创建") || ui.statusMsg.contains("已删除")

// =====================================================================
//  科技风基础组件（Neo UI Kit）
// =====================================================================

/** 深色渐变 + 霓虹发光边框卡片 */
@Composable
private fun TechCard(
    modifier: Modifier = Modifier,
    accent: Color = NeoCyan,
    pad: Dp = 14.dp,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        modifier = modifier
            .shadow(
                elevation = 16.dp,
                shape = RoundedCornerShape(14.dp),
                ambientColor = accent.copy(alpha = 0.25f),
                spotColor = accent.copy(alpha = 0.35f),
            )
            .clip(RoundedCornerShape(14.dp))
            .background(
                Brush.verticalGradient(
                    0f to BgPanel.copy(alpha = 0.95f),
                    1f to BgPanel2.copy(alpha = 0.75f),
                )
            )
            .border(1.dp, accent.copy(alpha = 0.5f), RoundedCornerShape(14.dp))
            .padding(pad),
        content = content,
    )
}

/** 区块标题：霓虹竖条 + 加粗标题 */
@Composable
private fun SectionTitle(text: String, accent: Color = NeoCyan) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier
                .size(width = 4.dp, height = 16.dp)
                .clip(RoundedCornerShape(2.dp))
                .background(Brush.verticalGradient(listOf(accent, accent.copy(alpha = 0.2f))))
        )
        Spacer(Modifier.width(8.dp))
        Text(
            text = text,
            style = MaterialTheme.typography.titleMedium,
            color = TextMain,
            fontWeight = FontWeight.Bold,
        )
    }
}

/** 渐变主按钮（青→紫 或 危险红） */
@Composable
private fun NeoButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
    accent: Color = NeoCyan,
    accent2: Color = NeoPurple,
    danger: Boolean = false,
    compact: Boolean = false,
) {
    val bg = if (danger) {
        Brush.linearGradient(listOf(NeoRed, NeoMagenta))
    } else {
        Brush.linearGradient(listOf(accent, accent2))
    }
    val textColor = if (danger) Color.White else Color(0xFF04101A)
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(10.dp))
            .background(if (enabled && !loading) bg else Brush.linearGradient(listOf(TextDim, TextDim.copy(alpha = 0.6f))))
            .clickable(enabled = enabled && !loading, onClick = onClick)
            .padding(horizontal = if (compact) 12.dp else 16.dp, vertical = if (compact) 6.dp else 11.dp),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(Modifier.size(16.dp), color = TextMain, strokeWidth = 2.dp)
        } else {
            Text(
                text = text,
                color = if (enabled) textColor else TextDim.copy(alpha = 0.7f),
                fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.labelLarge,
            )
        }
    }
}

/** 描边小按钮 */
@Composable
private fun NeoMiniBtn(
    text: String,
    onClick: () -> Unit,
    accent: Color = NeoCyan,
    enabled: Boolean = true,
) {
    val c = if (enabled) accent else TextDim.copy(alpha = 0.5f)
    Text(
        text = text,
        color = c,
        style = MaterialTheme.typography.labelMedium,
        modifier = Modifier
            .clip(RoundedCornerShape(8.dp))
            .border(1.dp, c.copy(alpha = 0.55f), RoundedCornerShape(8.dp))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 6.dp),
    )
}

/** 描边全宽按钮（如「加载更多」） */
@Composable
private fun NeoOutlineBtn(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, accent: Color = NeoCyan) {
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(10.dp))
            .border(1.dp, accent.copy(alpha = 0.6f), RoundedCornerShape(10.dp))
            .background(accent.copy(alpha = 0.08f))
            .clickable(onClick = onClick)
            .padding(vertical = 10.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = accent, style = MaterialTheme.typography.labelLarge)
    }
}

/** 霓虹筛选胶囊（替代 FilterChip） */
@Composable
private fun NeoChip(selected: Boolean, label: String, onClick: () -> Unit, accent: Color = NeoCyan) {
    val borderColor = if (selected) accent.copy(alpha = 0.85f) else TextDim.copy(alpha = 0.35f)
    val bg = if (selected) accent.copy(alpha = 0.13f) else BgPanel.copy(alpha = 0.6f)
    Box(
        modifier = Modifier
            .clip(RoundedCornerShape(9.dp))
            .background(bg)
            .border(1.dp, borderColor, RoundedCornerShape(9.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 6.dp),
    ) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = if (selected) accent else TextDim)
    }
}

/** 科技风输入框 */
@Composable
private fun NeoTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    placeholder: String = "",
    singleLine: Boolean = true,
    minLines: Int = 1,
    maxLines: Int = 1,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        modifier = modifier,
        label = { Text(label) },
        placeholder = { Text(placeholder) },
        singleLine = singleLine,
        minLines = minLines,
        maxLines = maxLines,
        shape = RoundedCornerShape(10.dp),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = NeoCyan,
            unfocusedBorderColor = NeoCyan.copy(alpha = 0.25f),
            focusedLabelColor = NeoCyan,
            unfocusedLabelColor = TextDim,
            cursorColor = NeoCyan,
            focusedTextColor = TextMain,
            unfocusedTextColor = TextMain,
            focusedContainerColor = BgPanel.copy(alpha = 0.6f),
            unfocusedContainerColor = BgPanel.copy(alpha = 0.6f),
        ),
    )
}

/** 呼吸灯（状态指示灯） */
@Composable
private fun PulseDot(color: Color, size: Dp = 8.dp) {
    val transition = rememberInfiniteTransition(label = "pulse")
    val alpha by transition.animateFloat(
        initialValue = 0.35f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(animation = tween(900), repeatMode = RepeatMode.Reverse),
        label = "alpha",
    )
    Box(
        Modifier
            .size(size)
            .clip(CircleShape)
            .background(color.copy(alpha = alpha))
    )
}

/** 静态小圆点 */
@Composable
private fun StaticDot(color: Color, size: Dp = 8.dp) {
    Box(
        Modifier
            .size(size)
            .clip(CircleShape)
            .background(color)
    )
}

/** 小标签药丸 */
@Composable
private fun MiniTag(text: String, color: Color) {
    Text(
        text = text,
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(color.copy(alpha = 0.12f))
            .border(1.dp, color.copy(alpha = 0.5f), RoundedCornerShape(6.dp))
            .padding(horizontal = 6.dp, vertical = 2.dp),
    )
}

/** 等宽代码徽章 */
@Composable
private fun CodeBadge(text: String, accent: Color = NeoCyan) {
    Text(
        text = text,
        style = MaterialTheme.typography.labelSmall,
        color = accent,
        fontFamily = FontFamily.Monospace,
        modifier = Modifier
            .clip(RoundedCornerShape(6.dp))
            .background(accent.copy(alpha = 0.10f))
            .border(1.dp, accent.copy(alpha = 0.45f), RoundedCornerShape(6.dp))
            .padding(horizontal = 6.dp, vertical = 2.dp),
    )
}

/** 表单分组标签 */
@Composable
private fun FieldLabel(text: String) {
    Text(text, style = MaterialTheme.typography.labelSmall, color = TextDim)
}

/** 网格 + 霓虹光晕背景 */
@Composable
private fun TechBackground() {
    Canvas(Modifier.fillMaxSize()) {
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(NeoCyan.copy(alpha = 0.10f), Color.Transparent),
                center = Offset(size.width * 0.8f, size.height * 0.02f),
                radius = size.width * 0.95f,
            ),
            radius = size.width * 0.95f,
            center = Offset(size.width * 0.8f, size.height * 0.02f),
        )
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(NeoMagenta.copy(alpha = 0.07f), Color.Transparent),
                center = Offset(size.width * 0.05f, size.height * 0.30f),
                radius = size.width * 0.9f,
            ),
            radius = size.width * 0.9f,
            center = Offset(size.width * 0.05f, size.height * 0.30f),
        )
        val step = 30.dp.toPx()
        val lineColor = NeoCyan.copy(alpha = 0.045f)
        var x = 0f
        while (x < size.width) {
            drawLine(lineColor, Offset(x, 0f), Offset(x, size.height), 1f)
            x += step
        }
        var y = 0f
        while (y < size.height) {
            drawLine(lineColor, Offset(0f, y), Offset(size.width, y), 1f)
            y += step
        }
    }
}

/** 统一科技风对话框 */
@Composable
private fun TechDialog(
    onDismiss: () -> Unit,
    title: String,
    accent: Color = NeoCyan,
    content: @Composable ColumnScope.() -> Unit,
) {
    Dialog(onDismissRequest = onDismiss) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 6.dp)
                .clip(RoundedCornerShape(16.dp))
                .background(Brush.verticalGradient(0f to BgPanel, 1f to BgPanel2))
                .border(1.dp, accent.copy(alpha = 0.55f), RoundedCornerShape(16.dp))
                .padding(18.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SectionTitle(title, accent)
                Spacer(Modifier.weight(1f))
                Text(
                    text = "✕",
                    color = TextDim,
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier
                        .clip(CircleShape)
                        .clickable(onClick = onDismiss)
                        .padding(6.dp),
                )
            }
            Spacer(Modifier.height(12.dp))
            Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                content()
            }
        }
    }
}

// =====================================================================
//  主界面
// =====================================================================

@Composable
fun MainScreen(vm: MainViewModel) {
    val ui by vm.ui.collectAsState()
    val context = LocalContext.current
    var pendingDelete by remember { mutableStateOf<Probe?>(null) }
    var qrProbe by remember { mutableStateOf<Probe?>(null) }

    // 创建成功后自动复制链接（R9）
    LaunchedEffect(ui.lastCreatedUrl) {
        val url = ui.lastCreatedUrl ?: return@LaunchedEffect
        val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText("probe_url", url))
        Toast.makeText(context, "链接已自动复制: $url", Toast.LENGTH_LONG).show()
        vm.clearLastCreatedUrl()
    }

    Scaffold(containerColor = BgDeep) { padding ->
        Box(
            Modifier
                .fillMaxSize()
                .padding(padding)
        ) {
            TechBackground()
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
                contentPadding = PaddingValues(vertical = 16.dp),
            ) {
                // ---- Hero 区：渐变标题 + 在线状态 ----
                item { HeroHeader(ui) }

                // ---- 统计概览（M8） ----
                item { StatsRow(ui.stats) }

                // ---- 服务器设置 ----
                item { SettingsCard(ui, vm) }

                // ---- 新建探针 ----
                item { CreateProbeCard(ui, vm) }

                // ---- 探针列表标题 + 刷新 ----
                item {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        SectionTitle("探针列表", NeoMagenta)
                        NeoMiniBtn(
                            text = if (ui.loadingProbes) "刷新中…" else "刷新",
                            onClick = vm::refreshProbes,
                            accent = NeoMagenta,
                            enabled = !ui.loadingProbes,
                        )
                    }
                    if (ui.listMsg.isNotEmpty()) {
                        Text(
                            text = ui.listMsg,
                            color = NeoRed,
                            style = MaterialTheme.typography.labelSmall,
                        )
                    }
                }

                if (ui.probes.isEmpty() && !ui.loadingProbes) {
                    item {
                        TechCard(Modifier.fillMaxWidth(), NeoCyan.copy(alpha = 0.4f), pad = 12.dp) {
                            Text(
                                text = "还没有探针。三步开始：",
                                style = MaterialTheme.typography.bodyMedium,
                                color = TextMain,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Spacer(Modifier.height(4.dp))
                            Text(
                                text = "① 上方填写名称，点「生成探针链接」\n② 点「二维码」把链接做成码，或「分享」直接发送\n③ 对方打开后，到下方「访问日志」查看 IP 与定位",
                                style = MaterialTheme.typography.bodySmall,
                                color = TextDim,
                            )
                        }
                    }
                }

                items(ui.probes, key = { it.code }) { probe ->
                    ProbeItemCard(
                        probe = probe,
                        onCopy = {
                            val url = vm.probeLink(probe)
                            val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                            cm.setPrimaryClip(ClipData.newPlainText("probe_url", url))
                            Toast.makeText(context, "已复制: $url", Toast.LENGTH_SHORT).show()
                        },
                        onShare = {
                            val url = vm.probeLink(probe)
                            val intent = Intent(Intent.ACTION_SEND).apply {
                                type = "text/plain"
                                putExtra(Intent.EXTRA_TEXT, url)
                            }
                            context.startActivity(Intent.createChooser(intent, "分享探针链接"))
                        },
                        onQr = { qrProbe = probe },
                        onEdit = { vm.startEdit(probe) },
                        onToggle = { vm.toggleProbe(probe) },
                        onDelete = { pendingDelete = probe },
                    )
                }

                // ---- M4: 访问日志 ----
                item {
                    LogSectionHeader(ui, vm, onExport = {
                        if (ui.logs.isEmpty()) {
                            Toast.makeText(context, "暂无日志可导出", Toast.LENGTH_SHORT).show()
                        } else {
                            val csv = buildLogsCsv(ui.logs)
                            val intent = Intent(Intent.ACTION_SEND).apply {
                                type = "text/csv"
                                putExtra(Intent.EXTRA_TEXT, csv)
                                putExtra(Intent.EXTRA_SUBJECT, "ip_probe_logs")
                            }
                            context.startActivity(Intent.createChooser(intent, "导出访问日志（${ui.logs.size} 条）"))
                        }
                    })
                }

                if (ui.logs.isEmpty() && !ui.logsLoading) {
                    item {
                        TechCard(Modifier.fillMaxWidth(), NeoGreen.copy(alpha = 0.35f), pad = 12.dp) {
                            Text(
                                text = ui.logMsg.ifBlank { "暂无访问日志" },
                                style = MaterialTheme.typography.bodyMedium,
                                color = TextMain,
                                fontWeight = FontWeight.SemiBold,
                            )
                            if (ui.logMsg.isBlank()) {
                                Spacer(Modifier.height(4.dp))
                                Text(
                                    text = "把探针链接发给对方并让其打开，访问记录会实时出现在这里；也可以点 IP 查看该访客画像。",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = TextDim,
                                )
                            }
                        }
                    }
                }

                itemsIndexed(ui.logs, key = { index, log -> "${log.time}-$index" }) { _, log ->
                    LogItemCard(
                        log = log,
                        onDetail = { vm.openLogDetail(log) },
                        onProfile = { vm.loadIpProfile(log.ip) },
                    )
                }

                if (ui.logsLoading || ui.logsLoadingMore) {
                    item {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(8.dp),
                            horizontalArrangement = Arrangement.Center,
                        ) {
                            CircularProgressIndicator(
                                modifier = Modifier.height(20.dp),
                                strokeWidth = 2.dp,
                                color = NeoCyan,
                            )
                        }
                    }
                } else if (ui.logHasMore) {
                    item {
                        NeoOutlineBtn(
                            text = "加载更多",
                            onClick = vm::loadMoreLogs,
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
            }
        }
    }

    if (pendingDelete != null) {
        TechDialog(
            onDismiss = { pendingDelete = null },
            title = "删除探针",
            accent = NeoRed,
        ) {
            Text(
                text = "确定删除探针「${pendingDelete!!.name}」(${pendingDelete!!.code})？\n将同时删除其全部访问日志，此操作不可恢复。",
                style = MaterialTheme.typography.bodyMedium,
                color = TextMain,
            )
            Spacer(Modifier.height(16.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                TextButton(onClick = { pendingDelete = null }) { Text("取消", color = TextDim) }
                Spacer(Modifier.width(8.dp))
                NeoButton(
                    text = "删除",
                    onClick = {
                        vm.deleteProbe(pendingDelete!!.code)
                        pendingDelete = null
                    },
                    danger = true,
                    compact = true,
                )
            }
        }
    }

    // 编辑探针对话框
    if (ui.editing != null) {
        EditProbeDialog(ui, vm)
    }

    // v1.5 二维码分享
    if (qrProbe != null) {
        QrDialog(probe = qrProbe!!, url = vm.probeLink(qrProbe!!), onDismiss = { qrProbe = null })
    }

    // v1.5 日志详情
    if (ui.logDetail != null) {
        LogDetailDialog(log = ui.logDetail!!, onDismiss = vm::closeLogDetail)
    }

    // v1.5 统计图表
    if (ui.logStats != null) {
        StatsDialog(stats = ui.logStats!!, loading = ui.logStatsLoading, onDismiss = { vm.closeStats() })
    }

    // v1.5 同 IP 画像
    if (ui.ipProfile != null) {
        IpProfileDialog(profile = ui.ipProfile!!, loading = ui.ipProfileLoading, onDismiss = vm::closeIpProfile)
    }

    // v1.5 多服务器选择
    if (ui.showServerPicker) {
        ServerPickerDialog(ui, vm)
    }
}

/** Hero 头部：渐变标题 + 副标 + 在线状态 */
@Composable
private fun HeroHeader(ui: UiState) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column {
            Text(
                text = "IP探针",
                style = TextStyle(
                    brush = Brush.linearGradient(listOf(NeoCyan, NeoPurple, NeoMagenta)),
                    fontSize = 30.sp,
                    fontWeight = FontWeight.Black,
                ),
            )
            Spacer(Modifier.height(2.dp))
            Text(
                text = "PROBE · TRACE · LOCATE",
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
                letterSpacing = 2.sp,
            )
        }
        Spacer(Modifier.weight(1f))
        Column(horizontalAlignment = Alignment.End) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                PulseDot(if (isOnline(ui)) NeoGreen else NeoRed)
                Spacer(Modifier.width(6.dp))
                Text(
                    text = if (isOnline(ui)) "在线" else "离线",
                    style = MaterialTheme.typography.labelMedium,
                    color = if (isOnline(ui)) NeoGreen else NeoRed,
                )
            }
            Spacer(Modifier.height(2.dp))
            Text(
                text = ui.server.removePrefix("https://").removePrefix("http://"),
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
                fontFamily = FontFamily.Monospace,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun SettingsCard(ui: UiState, vm: MainViewModel) {
    TechCard(modifier = Modifier.fillMaxWidth(), accent = NeoPurple.copy(alpha = 0.55f)) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            SectionTitle("服务器", NeoPurple)
            NeoButton(
                text = "测试连接",
                onClick = vm::test,
                enabled = !ui.testing,
                loading = ui.testing,
                accent = NeoPurple,
                accent2 = NeoCyan,
                compact = true,
            )
        }
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            PulseDot(if (isOnline(ui)) NeoGreen else NeoRed)
            Text(
                text = ui.server,
                style = MaterialTheme.typography.bodyMedium,
                color = NeoCyan,
                fontFamily = FontFamily.Monospace,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Text(
            text = "令牌: ${ui.token.take(6)}…${ui.token.takeLast(4)}（已内置）",
            style = MaterialTheme.typography.labelSmall,
            color = TextDim,
        )
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            NeoMiniBtn("切换服务器(${ui.servers.size})", vm::showServerPicker, NeoMagenta)
        }
        val statusColor = when {
            ui.statusMsg.contains("成功") || ui.statusMsg.contains("已创建") || ui.statusMsg.contains("已删除") -> NeoGreen
            ui.statusMsg.contains("失败") || ui.statusMsg.contains("异常") -> NeoRed
            else -> TextDim
        }
        Text(
            text = ui.statusMsg,
            style = MaterialTheme.typography.bodySmall,
            color = statusColor,
        )
    }
}

@Composable
private fun CreateProbeCard(ui: UiState, vm: MainViewModel) {
    TechCard(modifier = Modifier.fillMaxWidth(), accent = NeoCyan) {
        SectionTitle("新建探针", NeoCyan)
        Spacer(Modifier.height(6.dp))
        NeoTextField(
            value = ui.newProbeName,
            onValueChange = vm::onNewNameChange,
            modifier = Modifier.fillMaxWidth(),
            label = "探针名称",
            placeholder = "例如：测试链接",
        )
        Spacer(Modifier.height(6.dp))
        FieldLabel("访客看到什么页面")
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(TEMPLATES) { (key, label) ->
                NeoChip(
                    selected = ui.newTemplate == key,
                    onClick = { vm.onNewTemplateChange(key) },
                    label = label,
                )
            }
        }
        when (ui.newTemplate) {
            "redirect" -> NeoTextField(
                value = ui.newProbeRedirect,
                onValueChange = vm::onNewRedirectChange,
                modifier = Modifier.fillMaxWidth(),
                label = "跳转目标网址",
                placeholder = "https://example.com",
            )
            "image" -> NeoTextField(
                value = ui.newImageUrl,
                onValueChange = vm::onNewImageUrlChange,
                modifier = Modifier.fillMaxWidth(),
                label = "图片网址（可选）",
                placeholder = "留空使用默认占位图",
            )
            "text" -> NeoTextField(
                value = ui.newTextContent,
                onValueChange = vm::onNewTextChange,
                modifier = Modifier.fillMaxWidth(),
                label = "显示的文字内容",
                placeholder = "例如：这是一条测试消息",
                singleLine = false,
                minLines = 2,
                maxLines = 4,
            )
        }
        Spacer(Modifier.height(6.dp))
        FieldLabel("短链服务（推荐：自建短链，用你自己的域名，微信/QQ 可直接打开不报红）")
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            items(SHORTENERS) { (key, label) ->
                NeoChip(
                    selected = ui.newShorten == key,
                    onClick = { vm.onNewShortenChange(key) },
                    label = label,
                )
            }
        }
        Spacer(Modifier.height(6.dp))
        FieldLabel("防护设置（可选）")
        NeoTextField(
            value = ui.newAccessCode,
            onValueChange = vm::onNewAccessCodeChange,
            modifier = Modifier.fillMaxWidth(),
            label = "访问码（留空=无需验证）",
            placeholder = "访客需输入此码才能访问",
        )
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            NeoTextField(
                value = ui.newExpireDays,
                onValueChange = vm::onNewExpireDaysChange,
                modifier = Modifier.weight(1f),
                label = "有效期（天，0=永久）",
            )
            Text("一次性", style = MaterialTheme.typography.labelMedium, color = TextDim)
            Switch(
                checked = ui.newOnceOnly,
                onCheckedChange = vm::onNewOnceOnlyChange,
            )
        }
        Spacer(Modifier.height(6.dp))
        NeoButton(
            text = if (ui.creating) "生成中…" else "生成探针链接",
            onClick = vm::createProbe,
            modifier = Modifier.fillMaxWidth(),
            enabled = !ui.creating,
            loading = ui.creating,
        )
    }
}

@Composable
private fun ProbeItemCard(
    probe: Probe,
    onCopy: () -> Unit,
    onShare: () -> Unit,
    onQr: () -> Unit,
    onEdit: () -> Unit,
    onToggle: () -> Unit,
    onDelete: () -> Unit,
) {
    val enabled = probe.enabled != 0
    val accent = if (enabled) NeoCyan else TextDim
    TechCard(
        modifier = Modifier.fillMaxWidth(),
        accent = if (enabled) NeoCyan.copy(alpha = 0.55f) else TextDim.copy(alpha = 0.35f),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            StaticDot(if (enabled) NeoGreen else NeoRed)
            Spacer(Modifier.width(8.dp))
            Text(
                text = probe.name.ifBlank { "未命名探针" },
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = if (enabled) TextMain else TextDim,
                modifier = Modifier.weight(1f),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            CodeBadge(if (enabled) probe.code else "已停用", if (enabled) NeoCyan else NeoRed)
        }
        Spacer(Modifier.height(8.dp))
        Row(
            horizontalArrangement = Arrangement.spacedBy(6.dp),
            modifier = Modifier.horizontalScroll(rememberScrollState()),
        ) {
            MiniTag(templateLabel(probe.template), NeoPurple)
            if (probe.shortUrl.isNotBlank()) {
                MiniTag(shortenerLabel(probe.shortProvider), NeoMagenta)
            }
            if (probe.accessCode.isNotBlank()) {
                MiniTag("🔒访问码", NeoAmber)
            }
            if (probe.onceOnly == 1) {
                MiniTag("一次性", NeoAmber)
            }
            if (probe.expireDays > 0) {
                MiniTag("${probe.expireDays}天有效", NeoAmber)
            }
        }
        if (probe.shortUrl.isNotBlank()) {
            Spacer(Modifier.height(6.dp))
            Text(
                text = probe.shortUrl,
                style = MaterialTheme.typography.bodySmall,
                color = if (enabled) NeoCyan else TextDim,
                fontFamily = FontFamily.Monospace,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Spacer(Modifier.height(4.dp))
        Text(
            text = "点击 ${probe.visitCount} 次 · 最近访问 ${formatTime(probe.lastVisit)} · 创建于 ${formatTime(probe.created)}",
            style = MaterialTheme.typography.labelSmall,
            color = TextDim,
        )
        Spacer(Modifier.height(8.dp))
        HorizontalDivider(color = accent.copy(alpha = 0.15f))
        Spacer(Modifier.height(8.dp))
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            NeoMiniBtn("复制", onCopy, NeoCyan)
            Spacer(Modifier.width(6.dp))
            NeoMiniBtn("二维码", onQr, NeoMagenta)
            Spacer(Modifier.width(6.dp))
            NeoMiniBtn("分享", onShare, NeoPurple)
            Spacer(Modifier.weight(1f))
            TextButton(onClick = onToggle) {
                Text(
                    text = if (enabled) "停用" else "启用",
                    color = if (enabled) NeoAmber else NeoGreen,
                    style = MaterialTheme.typography.labelMedium,
                )
            }
            TextButton(onClick = onEdit) {
                Text("编辑", color = NeoCyan, style = MaterialTheme.typography.labelMedium)
            }
            TextButton(onClick = onDelete) {
                Text("删除", color = NeoRed, style = MaterialTheme.typography.labelMedium)
            }
        }
    }
}

@Composable
private fun EditProbeDialog(ui: UiState, vm: MainViewModel) {
    TechDialog(onDismiss = vm::cancelEdit, title = "编辑探针", accent = NeoMagenta) {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            NeoTextField(
                value = ui.editName,
                onValueChange = vm::onEditNameChange,
                modifier = Modifier.fillMaxWidth(),
                label = "探针名称",
            )
            FieldLabel("访问模板")
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items(TEMPLATES) { (key, label) ->
                    NeoChip(
                        selected = ui.editTemplate == key,
                        onClick = { vm.onEditTemplateChange(key) },
                        label = label,
                    )
                }
            }
            when (ui.editTemplate) {
                "redirect" -> NeoTextField(
                    value = ui.editRedirect,
                    onValueChange = vm::onEditRedirectChange,
                    modifier = Modifier.fillMaxWidth(),
                    label = "跳转目标网址",
                )
                "image" -> NeoTextField(
                    value = ui.editImageUrl,
                    onValueChange = vm::onEditImageChange,
                    modifier = Modifier.fillMaxWidth(),
                    label = "图片网址",
                )
                "text" -> NeoTextField(
                    value = ui.editTextContent,
                    onValueChange = vm::onEditTextChange,
                    modifier = Modifier.fillMaxWidth(),
                    label = "文字内容",
                    singleLine = false,
                    minLines = 2,
                    maxLines = 4,
                )
            }
            FieldLabel("短链服务（更换后保存即重新生成；选「自建短链」用你自己域名，防红）")
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                items(SHORTENERS) { (key, label) ->
                    NeoChip(
                        selected = ui.editShorten == key,
                        onClick = { vm.onEditShortenChange(key) },
                        label = label,
                    )
                }
            }
            FieldLabel("防护设置")
            NeoTextField(
                value = ui.editAccessCode,
                onValueChange = vm::onEditAccessCodeChange,
                modifier = Modifier.fillMaxWidth(),
                label = "访问码（留空=无需验证）",
            )
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                NeoTextField(
                    value = ui.editExpireDays,
                    onValueChange = vm::onEditExpireDaysChange,
                    modifier = Modifier.weight(1f),
                    label = "有效期（天，0=永久）",
                )
                Text("一次性", style = MaterialTheme.typography.labelMedium, color = TextDim)
                Switch(
                    checked = ui.editOnceOnly,
                    onCheckedChange = vm::onEditOnceOnlyChange,
                )
            }
            Spacer(Modifier.height(4.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                TextButton(onClick = vm::cancelEdit) { Text("取消", color = TextDim) }
                Spacer(Modifier.width(8.dp))
                NeoButton(
                    text = "保存",
                    onClick = vm::saveEdit,
                    enabled = !ui.updating,
                    loading = ui.updating,
                    accent = NeoMagenta,
                    accent2 = NeoPurple,
                    compact = true,
                )
            }
        }
    }
}

@Composable
private fun LogSectionHeader(ui: UiState, vm: MainViewModel, onExport: () -> Unit) {
    var showClear by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            SectionTitle("访问日志", NeoGreen)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                NeoMiniBtn("导出", onExport, NeoPurple)
                NeoMiniBtn("统计", { vm.loadLogStats() }, NeoMagenta)
                NeoMiniBtn(
                    text = if (ui.clearingLogs) "清空中…" else "清空",
                    onClick = { showClear = true },
                    accent = NeoRed,
                    enabled = !ui.clearingLogs,
                )
                NeoMiniBtn(
                    text = if (ui.logsLoading) "刷新中…" else "刷新",
                    onClick = vm::refreshLogs,
                    accent = NeoCyan,
                    enabled = !ui.logsLoading,
                )
            }
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item {
                NeoChip(
                    selected = ui.logFilter.isEmpty(),
                    onClick = { vm.onLogFilterChange("") },
                    label = "全部",
                )
            }
            items(ui.probes, key = { it.code }) { probe ->
                NeoChip(
                    selected = ui.logFilter == probe.code,
                    onClick = { vm.onLogFilterChange(probe.code) },
                    label = probe.name.ifBlank { probe.code },
                )
            }
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item {
                NeoChip(selected = ui.logDays == 0, onClick = { vm.onLogDaysChange(0) }, label = "全部时间")
            }
            item {
                NeoChip(selected = ui.logDays == 1, onClick = { vm.onLogDaysChange(1) }, label = "今天")
            }
            item {
                NeoChip(selected = ui.logDays == 7, onClick = { vm.onLogDaysChange(7) }, label = "近7天")
            }
            item {
                NeoChip(selected = ui.logDays == 30, onClick = { vm.onLogDaysChange(30) }, label = "近30天")
            }
            item {
                NeoChip(
                    selected = ui.logDevice.isEmpty(),
                    onClick = { vm.onLogDeviceChange("") },
                    label = "全部设备",
                )
            }
            item {
                NeoChip(
                    selected = ui.logDevice == "mobile",
                    onClick = { vm.onLogDeviceChange("mobile") },
                    label = "手机",
                )
            }
            item {
                NeoChip(
                    selected = ui.logDevice == "desktop",
                    onClick = { vm.onLogDeviceChange("desktop") },
                    label = "电脑",
                )
            }
            item {
                NeoChip(
                    selected = ui.logDevice == "other",
                    onClick = { vm.onLogDeviceChange("other") },
                    label = "其他",
                )
            }
            item {
                NeoMiniBtn("排序: ${vm.logSortLabel()}", vm::cycleLogSort, NeoCyan)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            NeoTextField(
                value = ui.logCountry,
                onValueChange = vm::onLogCountryChange,
                modifier = Modifier.weight(1f),
                label = "国家（精确）",
                placeholder = "如：中国",
            )
            NeoTextField(
                value = ui.logQ,
                onValueChange = vm::onLogQChange,
                modifier = Modifier.weight(1f),
                label = "关键词",
                placeholder = "IP/UA/城市…",
            )
            NeoMiniBtn("筛选", vm::applyLogFilters, NeoCyan)
        }
        if (ui.logMsg.isNotEmpty()) {
            Text(
                text = ui.logMsg,
                color = NeoRed,
                style = MaterialTheme.typography.labelSmall,
            )
        }
    }
    if (showClear) {
        TechDialog(
            onDismiss = { showClear = false },
            title = "清空日志",
            accent = NeoRed,
        ) {
            Text(
                text = if (ui.logFilter.isEmpty())
                    "确定清空全部访问日志？此操作不可恢复。"
                else
                    "确定清空该探针的全部访问日志？此操作不可恢复。",
                style = MaterialTheme.typography.bodyMedium,
                color = TextMain,
            )
            Spacer(Modifier.height(16.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                TextButton(onClick = { showClear = false }) { Text("取消", color = TextDim) }
                Spacer(Modifier.width(8.dp))
                NeoButton(
                    text = "确认清空",
                    onClick = {
                        showClear = false
                        vm.clearLogs()
                    },
                    danger = true,
                    compact = true,
                )
            }
        }
    }
}

/** 复制文本到剪贴板 */
private fun copyText(ctx: Context, label: String, text: String) {
    val cm = ctx.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    cm.setPrimaryClip(ClipData.newPlainText("probe_log", text))
    Toast.makeText(ctx, "${label}已复制", Toast.LENGTH_SHORT).show()
}

/** 打开网址（默认浏览器） */
private fun openUrl(ctx: Context, url: String) {
    try {
        ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    } catch (e: Exception) {
        Toast.makeText(ctx, "未找到可打开网页的应用", Toast.LENGTH_SHORT).show()
    }
}

/** 构建日志卡片复制文本（全字段） */
private fun buildLogCopyText(log: VisitLog): String = buildString {
    append("IP: ").append(log.ip)
    if (log.isIpv6 == 1) append("（IPv6）")
    append("\n")
    val region = listOf(log.country, log.province, log.city, log.district)
        .filter { it.isNotBlank() }
        .joinToString(" ")
    append("归属: ").append(if (region.isBlank()) "未知" else region)
    if (log.isp.isNotBlank()) append(" · ").append(log.isp)
    append("\n")
    if (log.lat != null && log.lng != null) {
        append("坐标: ").append("%.6f".format(Locale.US, log.lat)).append(", ")
            .append("%.6f".format(Locale.US, log.lng)).append("\n")
    }
    if (log.gpsLat != null && log.gpsLng != null) {
        append("GPS精确: ").append("%.6f".format(Locale.US, log.gpsLat)).append(", ")
            .append("%.6f".format(Locale.US, log.gpsLng))
        if (log.gpsAcc != null && log.gpsAcc > 0) append("（精度约").append(log.gpsAcc).append("米）")
        append("\n")
        if (log.gpsAddr.isNotBlank()) {
            append("GPS地址: ").append(log.gpsAddr).append("\n")
        }
    }
    val extra = buildString {
        if (log.zip.isNotBlank()) append("邮编: ").append(log.zip)
        if (log.timezone.isNotBlank()) {
            if (isNotEmpty()) append(" · ")
            append("时区: ").append(log.timezone)
        }
        if (log.asInfo.isNotBlank()) {
            if (isNotEmpty()) append(" · ")
            append(log.asInfo)
        }
        if (log.org.isNotBlank()) {
            if (isNotEmpty()) append(" · ")
            append("组织: ").append(log.org)
        }
    }
    if (extra.isNotEmpty()) {
        append(extra)
        append("\n")
    }
    append("设备: ").append(UaParser.device(log.ua)).append(" · ").append(UaParser.browser(log.ua)).append("\n")
    if (log.ua.isNotBlank()) {
        append("UA: ").append(log.ua).append("\n")
    }
    if (log.referer.isNotBlank()) {
        append("来源: ").append(log.referer).append("\n")
    }
    append("探针: ").append(if (log.probe.isBlank()) "-" else log.probe).append("\n")
    append("时间: ").append(formatTimeFull(log.time))
}

@Composable
private fun LogItemCard(log: VisitLog, onDetail: () -> Unit, onProfile: () -> Unit) {
    val context = LocalContext.current
    val hasGps = log.gpsLat != null && log.gpsLng != null
    val accent = if (hasGps) NeoGreen else NeoCyan
    TechCard(
        modifier = Modifier.fillMaxWidth(),
        accent = accent.copy(alpha = 0.5f),
        pad = 12.dp,
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            StaticDot(accent)
            Spacer(Modifier.width(8.dp))
            Text(
                text = log.ip,
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = accent,
                fontFamily = FontFamily.Monospace,
            )
            Spacer(Modifier.width(4.dp))
            if (log.isIpv6 == 1) {
                MiniTag("IPv6", NeoPurple)
            }
            if (log.probe.isNotBlank()) {
                MiniTag(log.probe, NeoMagenta)
            }
            Spacer(Modifier.weight(1f))
            Text(
                text = formatTimeFull(log.time),
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
                fontFamily = FontFamily.Monospace,
            )
        }
        Spacer(Modifier.height(6.dp))
        val region = listOf(log.country, log.province, log.city, log.district)
            .filter { it.isNotBlank() }
            .joinToString(" ")
        val location = buildString {
            append(if (region.isBlank()) "未知归属" else region)
            if (log.isp.isNotBlank()) append(" · ").append(log.isp)
        }
        Text(
            text = location,
            style = MaterialTheme.typography.bodySmall,
            color = NeoCyan,
        )
        // GPS 精确坐标（G2，访客授权上报）
        if (hasGps) {
            Spacer(Modifier.height(2.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = buildString {
                        append("GPS精确: ")
                        append("%.6f".format(Locale.US, log.gpsLat))
                        append(", ")
                        append("%.6f".format(Locale.US, log.gpsLng))
                        if (log.gpsAcc != null && log.gpsAcc > 0) append("（精度约${log.gpsAcc}米）")
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = NeoGreen,
                    fontWeight = FontWeight.SemiBold,
                )
                TextButton(onClick = { openMap(context, log) }) {
                    Text("打开地图", color = NeoGreen, style = MaterialTheme.typography.labelMedium)
                }
            }
        }
        // GPS 逆地理街道地址（v1.5.2，访客授权后由百度/腾讯解析）
        if (log.gpsAddr.isNotBlank()) {
            Spacer(Modifier.height(2.dp))
            Text(
                text = "GPS地址: ${log.gpsAddr}",
                style = MaterialTheme.typography.labelSmall,
                color = NeoGreen,
                fontFamily = FontFamily.Monospace,
            )
        }
        // 坐标 + 打开地图（MB）
        if (log.lat != null && log.lng != null) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "坐标: ${"%.6f".format(Locale.US, log.lat)}, ${"%.6f".format(Locale.US, log.lng)}",
                    style = MaterialTheme.typography.labelSmall,
                    color = NeoPurple,
                    fontFamily = FontFamily.Monospace,
                )
                TextButton(onClick = { openMap(context, log) }) {
                    Text("打开地图", color = NeoCyan, style = MaterialTheme.typography.labelMedium)
                }
            }
        }
        // 时区 / ASN / 邮编（MB）
        val extra = buildString {
            if (log.timezone.isNotBlank()) append("时区: ").append(log.timezone)
            if (log.asInfo.isNotBlank()) {
                if (isNotEmpty()) append(" · ")
                append(log.asInfo)
            }
            if (log.zip.isNotBlank()) {
                if (isNotEmpty()) append(" · 邮编: ")
                append(log.zip)
            }
        }
        if (extra.isNotEmpty()) {
            Text(
                text = extra,
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
            )
        }
        // 设备 / 浏览器识别（M8，纯前端解析）
        Text(
            text = "设备: ${UaParser.device(log.ua)} · ${UaParser.browser(log.ua)}",
            style = MaterialTheme.typography.labelSmall,
            color = NeoMagenta,
        )
        if (log.ua.isNotBlank()) {
            Text(
                text = "UA: ${log.ua}",
                style = MaterialTheme.typography.bodySmall,
                color = TextDim,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
        if (log.referer.isNotBlank()) {
            Text(
                text = "来源: ${log.referer}",
                style = MaterialTheme.typography.bodySmall,
                color = TextDim,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        HorizontalDivider(color = accent.copy(alpha = 0.12f))
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            NeoMiniBtn(
                text = "复制",
                onClick = { copyText(context, "日志", buildLogCopyText(log)) },
                accent = NeoGreen,
            )
            Spacer(Modifier.width(6.dp))
            NeoMiniBtn(
                text = "911查询",
                onClick = { openUrl(context, "https://ip.911cha.com/${log.ip}.html") },
                accent = NeoCyan,
            )
            Spacer(Modifier.weight(1f))
            TextButton(onClick = onProfile) {
                Text("画像", color = NeoPurple, style = MaterialTheme.typography.labelMedium)
            }
            TextButton(onClick = onDetail) {
                Text("详情", color = NeoCyan, style = MaterialTheme.typography.labelMedium)
            }
        }
    }
}

// ---------------- M8: 统计概览 + CSV 导出 ----------------

@Composable
private fun StatsRow(stats: StatsData?) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        StatCell("探针", stats?.probes, Modifier.weight(1f), NeoCyan)
        StatCell("总点击", stats?.clicks, Modifier.weight(1f), NeoMagenta)
        StatCell("今日点击", stats?.today, Modifier.weight(1f), NeoPurple)
    }
}

@Composable
private fun StatCell(label: String, value: Int?, modifier: Modifier = Modifier, accent: Color = NeoCyan) {
    TechCard(modifier = modifier, accent = accent, pad = 10.dp) {
        Column(
            modifier = Modifier.fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            Text(
                text = value?.toString() ?: "-",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = accent,
                fontFamily = FontFamily.Monospace,
            )
            Text(
                text = label,
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
            )
        }
    }
}

/** 用系统地图应用打开坐标（G2：GPS 优先，无 GPS 用 IP 坐标）
 *  标签用 ASCII 避免高德等地图对 geo: URI 中文标签解码失败出现 %E4 乱码；
 *  createChooser 弹地图选择器，由用户自选地图应用 */
private fun openMap(ctx: Context, log: VisitLog) {
    val gpsLat = log.gpsLat
    val gpsLng = log.gpsLng
    val lat = gpsLat ?: log.lat ?: return
    val lng = gpsLng ?: log.lng ?: return
    val label = if (gpsLat != null && gpsLng != null) "GPS" else "IP"
    val uri = Uri.parse("geo:$lat,$lng?q=$lat,$lng($label)")
    try {
        ctx.startActivity(Intent.createChooser(Intent(Intent.ACTION_VIEW, uri), "选择地图应用"))
    } catch (e: Exception) {
        Toast.makeText(ctx, "未找到可用的地图应用", Toast.LENGTH_SHORT).show()
    }
}

// ---------------- v1.5 新对话框与图表 ----------------

@Composable
private fun QrDialog(probe: Probe, url: String, onDismiss: () -> Unit) {
    val bitmap = remember(url) { generateQrBitmap(url) }
    TechDialog(onDismiss = onDismiss, title = "「${probe.name.ifBlank { "未命名探针" }}」二维码", accent = NeoCyan) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (bitmap != null) {
                Box(
                    Modifier
                        .clip(RoundedCornerShape(14.dp))
                        .background(Color.White)
                        .border(2.dp, NeoCyan, RoundedCornerShape(14.dp))
                        .padding(10.dp)
                ) {
                    Image(
                        bitmap = bitmap.asImageBitmap(),
                        contentDescription = "探针二维码",
                        modifier = Modifier.size(210.dp),
                    )
                }
            } else {
                Text("二维码生成失败", color = NeoRed)
            }
            Text(
                text = url,
                style = MaterialTheme.typography.bodySmall,
                color = NeoCyan,
                fontFamily = FontFamily.Monospace,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = "对方扫码即可打开探针页",
                style = MaterialTheme.typography.labelSmall,
                color = TextDim,
            )
        }
    }
}

@Composable
private fun LogDetailDialog(log: VisitLog, onDismiss: () -> Unit) {
    val context = LocalContext.current
    TechDialog(onDismiss = onDismiss, title = "日志详情", accent = NeoCyan) {
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            DetailLine("探针", log.probe.ifBlank { "-" })
            DetailLine("IP", "${log.ip}${if (log.isIpv6 == 1) "（IPv6）" else ""}")
            DetailLine("归属", listOf(log.country, log.province, log.city, log.district).filter { it.isNotBlank() }.joinToString(" ").ifBlank { "未知" })
            DetailLine("运营商", log.isp.ifBlank { "-" })
            DetailLine("IP坐标", if (log.lat != null && log.lng != null) "%.6f, %.6f".format(Locale.US, log.lat, log.lng) else "-")
            if (log.gpsLat != null && log.gpsLng != null) {
                DetailLine(
                    "GPS精确",
                    "%.6f, %.6f".format(Locale.US, log.gpsLat, log.gpsLng) +
                        (if (log.gpsAcc != null && log.gpsAcc > 0) "（精度约${log.gpsAcc}米）" else ""),
                )
                if (log.gpsAddr.isNotBlank()) {
                    DetailLine("GPS地址", log.gpsAddr)
                }
            }
            DetailLine("邮编", log.zip.ifBlank { "-" })
            DetailLine("时区", log.timezone.ifBlank { "-" })
            DetailLine("ASN", log.asInfo.ifBlank { "-" })
            DetailLine("组织", log.org.ifBlank { "-" })
            DetailLine("设备", "${UaParser.device(log.ua)} · ${UaParser.browser(log.ua)}")
            DetailLine("时间", formatTimeFull(log.time))
            if (log.ua.isNotBlank()) {
                DetailLine("UA", log.ua)
            }
            if (log.referer.isNotBlank()) {
                DetailLine("来源", log.referer)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                NeoMiniBtn("打开地图", onClick = { openMap(context, log) }, accent = NeoGreen)
                NeoMiniBtn(
                    "911查询",
                    onClick = { openUrl(context, "https://ip.911cha.com/${log.ip}.html") },
                    accent = NeoCyan,
                )
                NeoMiniBtn(
                    "复制全部",
                    onClick = { copyText(context, "日志详情", buildLogCopyText(log)) },
                    accent = NeoPurple,
                )
            }
        }
    }
}

@Composable
private fun DetailLine(label: String, value: String) {
    Row(verticalAlignment = Alignment.Top) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelSmall,
            color = TextDim,
            modifier = Modifier.width(64.dp),
        )
        Text(
            text = value,
            style = MaterialTheme.typography.bodySmall,
            color = TextMain,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun StatsDialog(stats: LogStatsData, loading: Boolean, onDismiss: () -> Unit) {
    TechDialog(onDismiss = onDismiss, title = "统计图表（近${stats.days}天）", accent = NeoMagenta) {
        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (loading) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                ) {
                    CircularProgressIndicator(modifier = Modifier.height(24.dp), strokeWidth = 2.dp, color = NeoMagenta)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCell("总点击", stats.total, Modifier.weight(1f), NeoCyan)
                StatCell("独立IP", stats.uniq, Modifier.weight(1f), NeoPurple)
            }
            if (stats.trend.isNotEmpty()) {
                SectionTitle("点击趋势", NeoCyan)
                BarChart(values = stats.trend.map { it.count.toFloat() }, labels = stats.trend.map { it.day.takeLast(5) })
            }
            if (stats.countries.isNotEmpty()) {
                SectionTitle("国家分布", NeoGreen)
                HBarChart(items = stats.countries)
            }
            if (stats.devices.isNotEmpty()) {
                SectionTitle("设备分布", NeoPurple)
                HBarChart(items = stats.devices)
            }
            if (stats.trend.isEmpty() && stats.countries.isEmpty()) {
                Text("暂无统计数据", color = TextDim)
            }
        }
    }
}

/** Canvas 竖条柱状图（趋势，霓虹渐变） */
@Composable
private fun BarChart(values: List<Float>, labels: List<String>) {
    if (values.isEmpty()) return
    val maxV = values.maxOrNull() ?: 1f
    Canvas(
        modifier = Modifier
            .fillMaxWidth()
            .height(130.dp),
    ) {
        val barCount = values.size
        val gap = size.width * 0.05f
        val barW = (size.width - gap * (barCount + 1)) / barCount
        // 基线
        drawRect(
            color = NeoCyan.copy(alpha = 0.3f),
            topLeft = Offset(0f, size.height - 2f),
            size = Size(size.width, 2f),
        )
        values.forEachIndexed { i, v ->
            val h = (v / maxV) * (size.height - 26f)
            val left = gap + i * (barW + gap)
            drawRect(
                brush = Brush.verticalGradient(
                    colors = listOf(NeoCyan, NeoPurple.copy(alpha = 0.55f)),
                    startY = size.height - h,
                    endY = size.height,
                ),
                topLeft = Offset(left, size.height - h),
                size = Size(barW, h),
            )
        }
        // 横轴刻度
        val step = if (labels.size > 5) (labels.size / 5f).toInt().coerceAtLeast(1) else 1
        labels.forEachIndexed { i, _ ->
            if (i % step == 0) {
                drawRect(
                    color = TextDim.copy(alpha = 0.8f),
                    topLeft = Offset(gap + i * (barW + gap), size.height - 12f),
                    size = Size(barW, 2f),
                )
            }
        }
    }
}

/** 横向条形图（国家/设备分布，霓虹渐变条） */
@Composable
private fun HBarChart(items: List<NameCount>) {
    if (items.isEmpty()) return
    val maxV = items.maxOfOrNull { it.count } ?: 1
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        items.forEach { item ->
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = item.name,
                    style = MaterialTheme.typography.labelSmall,
                    color = TextMain,
                    modifier = Modifier.width(56.dp),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Box(
                    Modifier
                        .weight(1f)
                        .height(10.dp)
                        .clip(RoundedCornerShape(5.dp))
                        .background(BgDeep)
                ) {
                    Box(
                        Modifier
                            .fillMaxWidth(item.count.toFloat() / maxV)
                            .height(10.dp)
                            .clip(RoundedCornerShape(5.dp))
                            .background(Brush.horizontalGradient(listOf(NeoGreen.copy(alpha = 0.65f), NeoGreen)))
                    )
                }
                Text(
                    text = item.count.toString(),
                    style = MaterialTheme.typography.labelSmall,
                    color = NeoGreen,
                    fontFamily = FontFamily.Monospace,
                    modifier = Modifier.width(28.dp),
                    textAlign = TextAlign.End,
                )
            }
        }
    }
}

@Composable
private fun IpProfileDialog(profile: IpProfile, loading: Boolean, onDismiss: () -> Unit) {
    TechDialog(onDismiss = onDismiss, title = "IP画像: ${profile.ip}", accent = NeoPurple) {
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            if (loading) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                ) {
                    CircularProgressIndicator(modifier = Modifier.height(20.dp), strokeWidth = 2.dp, color = NeoPurple)
                }
            }
            DetailLine("访问次数", profile.total.toString())
            DetailLine("首次访问", formatTimeFull(profile.first))
            DetailLine("最近访问", formatTimeFull(profile.last))
            DetailLine("运营商", profile.isp.ifBlank { "-" })
            DetailLine("访问过的探针", profile.probes.joinToString(", ").ifBlank { "-" })
            DetailLine("出现过的城市", profile.cities.joinToString(", ").ifBlank { "-" })
            DetailLine("是否有GPS", if (profile.hasGps) "有（授权上报过精确坐标）" else "无")
            if (profile.devices.isNotEmpty()) {
                DetailLine(
                    "设备分布",
                    profile.devices.joinToString(", ") { "${it.name}×${it.count}" },
                )
            }
            if (profile.latestUa.isNotBlank()) {
                DetailLine("最新UA", profile.latestUa)
            }
        }
    }
}

@Composable
private fun ServerPickerDialog(ui: UiState, vm: MainViewModel) {
    TechDialog(onDismiss = vm::hideServerPicker, title = "服务器列表", accent = NeoMagenta) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            ui.servers.forEach { url ->
                val isCurrent = url == ui.server
                TechCard(
                    modifier = Modifier.fillMaxWidth(),
                    accent = if (isCurrent) NeoCyan else TextDim.copy(alpha = 0.5f),
                    pad = 10.dp,
                ) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        StaticDot(if (isCurrent) NeoGreen else TextDim.copy(alpha = 0.4f))
                        Spacer(Modifier.width(8.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                text = url,
                                style = MaterialTheme.typography.bodySmall,
                                color = if (isCurrent) NeoCyan else TextMain,
                                fontFamily = FontFamily.Monospace,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                            if (isCurrent) {
                                Text(
                                    text = "当前使用",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = NeoGreen,
                                )
                            }
                        }
                        if (!isCurrent) {
                            TextButton(onClick = { vm.switchServer(url) }) {
                                Text("使用", color = NeoCyan, style = MaterialTheme.typography.labelMedium)
                            }
                        }
                        TextButton(onClick = { vm.removeServer(url) }) {
                            Text("删除", color = NeoRed, style = MaterialTheme.typography.labelMedium)
                        }
                    }
                }
            }
            NeoTextField(
                value = ui.newServerUrl,
                onValueChange = vm::onNewServerChange,
                modifier = Modifier.fillMaxWidth(),
                label = "新增服务器地址",
                placeholder = "https://example.com",
            )
            NeoButton(
                text = "添加服务器",
                onClick = vm::addServer,
                modifier = Modifier.fillMaxWidth(),
                accent = NeoMagenta,
                accent2 = NeoPurple,
            )
        }
    }
}

private fun csvField(s: String): String {
    val t = s.replace("\"", "\"\"")
    return if (t.contains(',') || t.contains('"') || t.contains('\n') || t.contains('\r')) {
        "\"$t\""
    } else t
}

/** 生成访问日志 CSV（带 BOM，Excel 可直接打开） */
private fun buildLogsCsv(logs: List<VisitLog>): String {
    val sb = StringBuilder("\uFEFF")
    sb.append("IP,国家,省份,城市,区县,运营商,纬度,经度,GPS纬度,GPS经度,GPS精度(米),GPS地址,邮编,时区,ASN,设备,浏览器,UA,来源,时间\n")
    logs.forEach { l ->
        sb.append(csvField(l.ip)).append(',')
            .append(csvField(l.country)).append(',')
            .append(csvField(l.province)).append(',')
            .append(csvField(l.city)).append(',')
            .append(csvField(l.district)).append(',')
            .append(csvField(l.isp)).append(',')
            .append(csvField(if (l.lat != null) "%.6f".format(Locale.US, l.lat) else "")).append(',')
            .append(csvField(if (l.lng != null) "%.6f".format(Locale.US, l.lng) else "")).append(',')
            .append(csvField(if (l.gpsLat != null) "%.6f".format(Locale.US, l.gpsLat) else "")).append(',')
            .append(csvField(if (l.gpsLng != null) "%.6f".format(Locale.US, l.gpsLng) else "")).append(',')
            .append(csvField(if (l.gpsAcc != null) l.gpsAcc.toString() else "")).append(',')
            .append(csvField(l.gpsAddr)).append(',')
            .append(csvField(l.zip)).append(',')
            .append(csvField(l.timezone)).append(',')
            .append(csvField(l.asInfo)).append(',')
            .append(csvField(UaParser.device(l.ua))).append(',')
            .append(csvField(UaParser.browser(l.ua))).append(',')
            .append(csvField(l.ua)).append(',')
            .append(csvField(l.referer)).append(',')
            .append(csvField(formatTimeFull(l.time)))
            .append('\n')
    }
    return sb.toString()
}
