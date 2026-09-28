package com.ipprobe.app.ui

import android.content.Context
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.google.gson.Gson
import com.ipprobe.app.data.ApiClient
import com.ipprobe.app.data.CreateProbeRequest
import com.ipprobe.app.data.IpProfile
import com.ipprobe.app.data.LogStatsData
import com.ipprobe.app.data.Probe
import com.ipprobe.app.data.StatsData
import com.ipprobe.app.data.UpdateProbeRequest
import com.ipprobe.app.data.VisitLog
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** 默认服务器地址与访问令牌：留空，首次启动由用户在设置中自行填写 */
private const val DEFAULT_SERVER = ""
private const val DEFAULT_TOKEN = ""

data class UiState(
    val server: String = DEFAULT_SERVER,
    val token: String = DEFAULT_TOKEN,
    val saving: Boolean = false,
    val testing: Boolean = false,
    val connected: Boolean = false,
    val statusMsg: String = "打开应用后自动连接服务器",
    // ---- M3: 探针管理 ----
    val probes: List<Probe> = emptyList(),
    val loadingProbes: Boolean = false,
    val creating: Boolean = false,
    val newProbeName: String = "",
    val newProbeRedirect: String = "",
    val listMsg: String = "",
    // ---- M7: 模板/短链/编辑/统计 ----
    val newTemplate: String = "blank",
    val newImageUrl: String = "",
    val newTextContent: String = "",
    val newShorten: String = "self",
    // ---- v1.5 防护选项（新建/编辑） ----
    val newAccessCode: String = "",
    val newExpireDays: String = "",
    val newOnceOnly: Boolean = false,
    val editAccessCode: String = "",
    val editExpireDays: String = "",
    val editOnceOnly: Boolean = false,
    val stats: StatsData? = null,
    val lastCreatedUrl: String? = null,
    val editing: Probe? = null,
    val editName: String = "",
    val editTemplate: String = "blank",
    val editRedirect: String = "",
    val editImageUrl: String = "",
    val editTextContent: String = "",
    val editShorten: String = "self",
    val updating: Boolean = false,
    val toggling: Boolean = false,
    // ---- M4: 访问日志 ----
    val logs: List<VisitLog> = emptyList(),
    val logFilter: String = "",
    val logsLoading: Boolean = false,
    val logsLoadingMore: Boolean = false,
    val clearingLogs: Boolean = false,
    val logPage: Int = 0,
    val logHasMore: Boolean = false,
    val logMsg: String = "",
    // ---- v1.5 日志筛选/排序/详情/统计/画像 ----
    val logDays: Int = 0,
    val logCountry: String = "",
    val logDevice: String = "",
    val logQ: String = "",
    val logSort: String = "time_desc",
    val logDetail: VisitLog? = null,
    val logStats: LogStatsData? = null,
    val logStatsLoading: Boolean = false,
    val ipProfile: IpProfile? = null,
    val ipProfileLoading: Boolean = false,
    // ---- v1.5 多服务器 ----
    val servers: List<String> = listOf(DEFAULT_SERVER),
    val showServerPicker: Boolean = false,
    val newServerUrl: String = "",
)

class MainViewModel(private val ctx: Context) : ViewModel() {

    private val prefs = ctx.getSharedPreferences("ip_probe_prefs", Context.MODE_PRIVATE)

    private val _ui = MutableStateFlow(UiState())
    val ui: StateFlow<UiState> = _ui.asStateFlow()

    init {
        loadServers()
        // 打开应用即自动连接内置服务器并加载数据
        viewModelScope.launch { test() }
    }

    /** 缺协议自动补 https:// */
    private fun fixScheme(s: String): String {
        val t = s.trim()
        return if (t.isNotEmpty() && !t.startsWith("http://") && !t.startsWith("https://")) {
            "https://$t"
        } else t
    }

    private fun api(server: String) = ApiClient.get(fixScheme(server))

    fun test() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) {
            _ui.update { it.copy(statusMsg = "请先填写服务器地址和Token") }
            return
        }
        viewModelScope.launch {
            _ui.update { it.copy(testing = true, statusMsg = "连接中…") }
            try {
                val resp = api(server).ping(token)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update {
                        it.copy(
                            testing = false, connected = true, server = server,
                            token = token, statusMsg = "连接成功 ✓ 云函数在线 (pong=${resp.data.pong})",
                        )
                    }
                    refreshProbes()
                    loadLogs(reset = true)
                    refreshStats()
                } else {
                    _ui.update {
                        it.copy(
                            testing = false, connected = false,
                            statusMsg = "接口返回异常: ${resp.msg ?: "未知错误"}",
                        )
                    }
                }
            } catch (e: Exception) {
                _ui.update {
                    it.copy(testing = false, connected = false, statusMsg = "连接失败: ${e.message}")
                }
            }
        }
    }

    // ---------------- M3: 探针管理 ----------------

    fun refreshProbes() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) {
            _ui.update { it.copy(listMsg = "请先配置服务器地址与Token") }
            return
        }
        viewModelScope.launch {
            _ui.update { it.copy(loadingProbes = true, listMsg = "") }
            try {
                val resp = api(server).listProbes(token)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update { it.copy(loadingProbes = false, probes = resp.data.list) }
                } else {
                    _ui.update {
                        it.copy(loadingProbes = false, listMsg = "加载失败: ${resp.msg ?: "未知错误"}")
                    }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(loadingProbes = false, listMsg = "加载失败: ${e.message}") }
            }
        }
    }

    fun onNewNameChange(v: String) = _ui.update { it.copy(newProbeName = v) }

    fun onNewRedirectChange(v: String) = _ui.update { it.copy(newProbeRedirect = v) }

    fun onNewTemplateChange(v: String) = _ui.update { it.copy(newTemplate = v) }

    fun onNewImageUrlChange(v: String) = _ui.update { it.copy(newImageUrl = v) }

    fun onNewTextChange(v: String) = _ui.update { it.copy(newTextContent = v) }

    fun onNewShortenChange(v: String) = _ui.update { it.copy(newShorten = v) }

    fun onNewAccessCodeChange(v: String) = _ui.update { it.copy(newAccessCode = v) }

    fun onNewExpireDaysChange(v: String) = _ui.update {
        it.copy(newExpireDays = v.filter { c -> c.isDigit() }.take(4))
    }

    fun onNewOnceOnlyChange(v: Boolean) = _ui.update { it.copy(newOnceOnly = v) }

    /** UI 完成复制后清空 */
    fun clearLastCreatedUrl() = _ui.update { it.copy(lastCreatedUrl = null) }

    fun createProbe() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) {
            _ui.update { it.copy(statusMsg = "请先配置服务器地址与Token") }
            return
        }
        viewModelScope.launch {
            _ui.update { it.copy(creating = true) }
            try {
                val resp = api(server).createProbe(
                    token,
                    CreateProbeRequest(
                        name = _ui.value.newProbeName.trim(),
                        redirect = _ui.value.newProbeRedirect.trim(),
                        template = _ui.value.newTemplate,
                        imageUrl = _ui.value.newImageUrl.trim(),
                        textContent = _ui.value.newTextContent.trim(),
                        shorten = _ui.value.newShorten,
                        accessCode = _ui.value.newAccessCode.trim(),
                        expireDays = _ui.value.newExpireDays.toIntOrNull() ?: 0,
                        onceOnly = if (_ui.value.newOnceOnly) 1 else 0,
                    ),
                )
                if (resp.code == 0 && resp.data != null) {
                    val link = resp.data.shortUrl.ifBlank { resp.data.url }
                    _ui.update {
                        it.copy(
                            creating = false, newProbeName = "", newProbeRedirect = "",
                            newImageUrl = "", newTextContent = "",
                            newAccessCode = "", newExpireDays = "", newOnceOnly = false,
                            statusMsg = "已创建探针: ${resp.data.code}",
                            lastCreatedUrl = link,
                        )
                    }
                    refreshProbes()
                    refreshStats()
                } else {
                    _ui.update {
                        it.copy(creating = false, statusMsg = "创建失败: ${resp.msg ?: "未知错误"}")
                    }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(creating = false, statusMsg = "创建失败: ${e.message}") }
            }
        }
    }

    // ---------------- M7: 编辑 / 开关 / 统计 ----------------

    fun startEdit(p: Probe) = _ui.update {
        it.copy(
            editing = p,
            editName = p.name,
            editTemplate = p.template,
            editRedirect = p.redirect,
            editImageUrl = p.imageUrl,
            editTextContent = p.textContent,
            editShorten = p.shortProvider.takeIf { s -> s.isNotBlank() } ?: "self",
            editAccessCode = p.accessCode,
            editExpireDays = if (p.expireDays > 0) p.expireDays.toString() else "",
            editOnceOnly = p.onceOnly == 1,
        )
    }

    fun cancelEdit() = _ui.update { it.copy(editing = null) }

    fun onEditNameChange(v: String) = _ui.update { it.copy(editName = v) }

    fun onEditTemplateChange(v: String) = _ui.update { it.copy(editTemplate = v) }

    fun onEditRedirectChange(v: String) = _ui.update { it.copy(editRedirect = v) }

    fun onEditImageChange(v: String) = _ui.update { it.copy(editImageUrl = v) }

    fun onEditTextChange(v: String) = _ui.update { it.copy(editTextContent = v) }

    fun onEditShortenChange(v: String) = _ui.update { it.copy(editShorten = v) }

    fun onEditAccessCodeChange(v: String) = _ui.update { it.copy(editAccessCode = v) }

    fun onEditExpireDaysChange(v: String) = _ui.update {
        it.copy(editExpireDays = v.filter { c -> c.isDigit() }.take(4))
    }

    fun onEditOnceOnlyChange(v: Boolean) = _ui.update { it.copy(editOnceOnly = v) }

    fun saveEdit() {
        val e = _ui.value.editing ?: return
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        viewModelScope.launch {
            _ui.update { it.copy(updating = true) }
            try {
                // 短链服务选项变化时才重新生成短链（换回自建域名即可防红）
                val shortenChanged = _ui.value.editShorten != (e.shortProvider.takeIf { s -> s.isNotBlank() } ?: "self")
                val resp = api(server).updateProbe(
                    e.code, token,
                    UpdateProbeRequest(
                        name = _ui.value.editName.trim(),
                        redirect = _ui.value.editRedirect.trim(),
                        template = _ui.value.editTemplate,
                        imageUrl = _ui.value.editImageUrl.trim(),
                        textContent = _ui.value.editTextContent.trim(),
                        shorten = if (shortenChanged) _ui.value.editShorten else null,
                        accessCode = _ui.value.editAccessCode.trim(),
                        expireDays = _ui.value.editExpireDays.toIntOrNull() ?: 0,
                        onceOnly = if (_ui.value.editOnceOnly) 1 else 0,
                    ),
                )
                if (resp.code == 0) {
                    _ui.update {
                        it.copy(updating = false, editing = null, statusMsg = "已更新 ${e.code}")
                    }
                    refreshProbes()
                } else {
                    _ui.update {
                        it.copy(updating = false, statusMsg = "更新失败: ${resp.msg ?: "未知错误"}")
                    }
                }
            } catch (ex: Exception) {
                _ui.update { it.copy(updating = false, statusMsg = "更新失败: ${ex.message}") }
            }
        }
    }

    fun toggleProbe(probe: Probe) {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        viewModelScope.launch {
            _ui.update { it.copy(toggling = true) }
            try {
                val resp = api(server).toggleProbe(probe.code, token)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update {
                        it.copy(
                            toggling = false,
                            statusMsg = if (resp.data.enabled == 1) "已启用 ${probe.code}" else "已停用 ${probe.code}",
                        )
                    }
                    refreshProbes()
                } else {
                    _ui.update {
                        it.copy(toggling = false, statusMsg = "操作失败: ${resp.msg ?: "未知错误"}")
                    }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(toggling = false, statusMsg = "操作失败: ${e.message}") }
            }
        }
    }

    fun refreshStats() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) return
        viewModelScope.launch {
            try {
                val resp = api(server).stats(token)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update { it.copy(stats = resp.data) }
                }
            } catch (_: Exception) {
                // 统计失败不影响主流程
            }
        }
    }

    fun deleteProbe(code: String) {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        viewModelScope.launch {
            try {
                val resp = api(server).deleteProbe(code, token)
                if (resp.code == 0) {
                    val filterReset = _ui.value.logFilter == code
                    _ui.update { it.copy(statusMsg = "已删除 $code") }
                    refreshProbes()
                    if (filterReset) {
                        _ui.update { it.copy(logFilter = "") }
                        loadLogs(reset = true)
                    }
                } else {
                    _ui.update { it.copy(statusMsg = "删除失败: ${resp.msg ?: "未知错误"}") }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(statusMsg = "删除失败: ${e.message}") }
            }
        }
    }

    // ---------------- M4: 访问日志 ----------------

    /** 切换日志筛选探针（"" = 全部） */
    fun onLogFilterChange(code: String) {
        if (_ui.value.logFilter == code) return
        _ui.update { it.copy(logFilter = code) }
        loadLogs(reset = true)
    }

    // ---- v1.5 日志筛选增强 ----

    fun onLogDaysChange(d: Int) {
        if (_ui.value.logDays == d) return
        _ui.update { it.copy(logDays = d) }
        loadLogs(reset = true)
    }

    fun onLogCountryChange(v: String) = _ui.update { it.copy(logCountry = v) }

    fun onLogDeviceChange(v: String) {
        if (_ui.value.logDevice == v) return
        _ui.update { it.copy(logDevice = v) }
        loadLogs(reset = true)
    }

    fun onLogQChange(v: String) = _ui.update { it.copy(logQ = v) }

    fun applyLogFilters() = loadLogs(reset = true)

    /** 排序切换：time_desc → time_asc → ip 循环 */
    fun cycleLogSort() {
        _ui.update {
            it.copy(
                logSort = when (it.logSort) {
                    "time_desc" -> "time_asc"
                    "time_asc" -> "ip"
                    else -> "time_desc"
                },
            )
        }
        sortCurrentLogs()
    }

    private fun sortLogs(list: List<VisitLog>): List<VisitLog> = when (_ui.value.logSort) {
        "time_asc" -> list.sortedBy { it.time }
        "ip" -> list.sortedWith(compareBy({ it.ip }, { it.time }))
        else -> list.sortedByDescending { it.time }
    }

    private fun sortCurrentLogs() = _ui.update { it.copy(logs = sortLogs(it.logs)) }

    fun logSortLabel(): String = when (_ui.value.logSort) {
        "time_asc" -> "时间↑"
        "ip" -> "按IP"
        else -> "时间↓"
    }

    // ---- v1.5 日志详情 / 统计 / IP 画像 ----

    fun openLogDetail(log: VisitLog) = _ui.update { it.copy(logDetail = log) }

    fun closeLogDetail() = _ui.update { it.copy(logDetail = null) }

    fun loadLogStats() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) return
        viewModelScope.launch {
            _ui.update { it.copy(logStatsLoading = true) }
            try {
                val resp = api(server).logsStats(token, 30, _ui.value.logFilter)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update { it.copy(logStatsLoading = false, logStats = resp.data) }
                } else {
                    _ui.update { it.copy(logStatsLoading = false, statusMsg = "统计加载失败: ${resp.msg ?: "未知错误"}") }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(logStatsLoading = false, statusMsg = "统计加载失败: ${e.message}") }
            }
        }
    }

    fun loadIpProfile(ip: String) {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) return
        viewModelScope.launch {
            _ui.update { it.copy(ipProfileLoading = true) }
            try {
                val resp = api(server).ipProfile(token, ip)
                if (resp.code == 0 && resp.data != null) {
                    _ui.update { it.copy(ipProfileLoading = false, ipProfile = resp.data) }
                } else {
                    _ui.update { it.copy(ipProfileLoading = false, statusMsg = "画像加载失败: ${resp.msg ?: "未知错误"}") }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(ipProfileLoading = false, statusMsg = "画像加载失败: ${e.message}") }
            }
        }
    }

    fun closeIpProfile() = _ui.update { it.copy(ipProfile = null) }

    fun closeStats() = _ui.update { it.copy(logStats = null) }

    // ---- v1.5 多服务器（SharedPreferences JSON 列表） ----

    private fun loadServers() {
        val raw = prefs.getString("servers", null) ?: return
        val saved = try {
            Gson().fromJson(raw, Array<String>::class.java)?.filter { it.isNotBlank() }?.distinct()
        } catch (e: Exception) {
            null
        }
        _ui.update { it.copy(servers = saved?.takeIf { l -> l.isNotEmpty() } ?: listOf(DEFAULT_SERVER)) }
    }

    private fun saveServers(list: List<String>) {
        prefs.edit().putString("servers", Gson().toJson(list)).apply()
    }

    fun showServerPicker() = _ui.update { it.copy(showServerPicker = true) }

    fun hideServerPicker() = _ui.update { it.copy(showServerPicker = false) }

    fun onNewServerChange(v: String) = _ui.update { it.copy(newServerUrl = v) }

    fun addServer() {
        val url = fixScheme(_ui.value.newServerUrl).trim().trimEnd('/')
        if (url.isEmpty()) return
        val list = (_ui.value.servers + url).distinct()
        _ui.update { it.copy(servers = list, newServerUrl = "") }
        saveServers(list)
    }

    fun removeServer(url: String) {
        val list = _ui.value.servers.filter { it != url }
        _ui.update { it.copy(servers = list) }
        saveServers(list)
    }

    fun switchServer(url: String) {
        val fixed = fixScheme(url)
        _ui.update { it.copy(server = fixed, statusMsg = "已切换服务器，正在连接…") }
        test()
    }

    fun refreshLogs() = loadLogs(reset = true)

    /** 清空当前筛选范围的访问日志（CB） */
    fun clearLogs() {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) {
            _ui.update { it.copy(statusMsg = "请先配置服务器地址与Token") }
            return
        }
        viewModelScope.launch {
            _ui.update { it.copy(clearingLogs = true) }
            try {
                val resp = api(server).clearLogs(token, _ui.value.logFilter)
                if (resp.code == 0) {
                    val n = resp.data?.deleted ?: 0
                    val scope = if (_ui.value.logFilter.isEmpty()) "全部" else "该探针"
                    _ui.update { it.copy(clearingLogs = false, statusMsg = "已清空${scope}日志（$n 条）") }
                    loadLogs(reset = true)
                    refreshStats()
                } else {
                    _ui.update { it.copy(clearingLogs = false, statusMsg = "清空失败: ${resp.msg ?: "未知错误"}") }
                }
            } catch (e: Exception) {
                _ui.update { it.copy(clearingLogs = false, statusMsg = "清空失败: ${e.message}") }
            }
        }
    }

    fun loadMoreLogs() = loadLogs(reset = false)

    /** reset=true 加载第一页；false 加载下一页并追加 */
    fun loadLogs(reset: Boolean) {
        val server = fixScheme(_ui.value.server)
        val token = _ui.value.token.trim()
        if (server.isEmpty() || token.isEmpty()) {
            _ui.update { it.copy(logMsg = "请先配置服务器地址与Token") }
            return
        }
        val page = if (reset) 0 else _ui.value.logPage + 1
        viewModelScope.launch {
            _ui.update {
                it.copy(logsLoading = reset, logsLoadingMore = !reset, logMsg = "")
            }
            try {
                val resp = api(server).logs(
                    token,
                    _ui.value.logFilter,
                    page,
                    LOG_PAGE_SIZE,
                    days = _ui.value.logDays,
                    country = _ui.value.logCountry.trim(),
                    device = _ui.value.logDevice,
                    q = _ui.value.logQ.trim(),
                )
                if (resp.code == 0 && resp.data != null) {
                    _ui.update {
                        val merged = if (reset) resp.data.list else it.logs + resp.data.list
                        it.copy(
                            logs = sortLogs(merged),
                            logPage = page,
                            logHasMore = resp.data.list.size >= LOG_PAGE_SIZE,
                            logsLoading = false,
                            logsLoadingMore = false,
                        )
                    }
                } else {
                    _ui.update {
                        it.copy(
                            logsLoading = false, logsLoadingMore = false,
                            logMsg = "日志加载失败: ${resp.msg ?: "未知错误"}",
                        )
                    }
                }
            } catch (e: Exception) {
                _ui.update {
                    it.copy(
                        logsLoading = false, logsLoadingMore = false,
                        logMsg = "日志加载失败: ${e.message}",
                    )
                }
            }
        }
    }

    /** 探针链接（优先使用短链） */
    fun probeLink(probe: Probe): String {
        if (probe.shortUrl.isNotBlank()) return probe.shortUrl
        val server = fixScheme(_ui.value.server).trimEnd('/')
        return "$server/p/${probe.code}"
    }

    companion object {
        private const val LOG_PAGE_SIZE = 50
    }
}

class MainViewModelFactory(private val ctx: Context) : ViewModelProvider.Factory {
    @Suppress("UNCHECKED_CAST")
    override fun <T : ViewModel> create(modelClass: Class<T>): T =
        MainViewModel(ctx) as T
}