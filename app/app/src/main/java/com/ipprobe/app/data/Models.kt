package com.ipprobe.app.data

import com.google.gson.annotations.SerializedName

/** 云函数统一响应壳 */
data class ApiResponse<T>(
    val code: Int,
    val msg: String? = null,
    val data: T? = null,
)

/** 探针 */
data class Probe(
    val code: String = "",
    val name: String = "",
    val redirect: String = "",
    val template: String = "blank",
    @SerializedName("image_url") val imageUrl: String = "",
    @SerializedName("text_content") val textContent: String = "",
    @SerializedName("short_url") val shortUrl: String = "",
    @SerializedName("short_provider") val shortProvider: String = "",
    @SerializedName("access_code") val accessCode: String = "",
    @SerializedName("expire_days") val expireDays: Int = 0,
    @SerializedName("once_only") val onceOnly: Int = 0,
    val enabled: Int = 1,
    val created: Long = 0,
    @SerializedName("visit_count") val visitCount: Int = 0,
    @SerializedName("last_visit") val lastVisit: Long = 0,
)

data class ProbeList(val list: List<Probe> = emptyList())

data class CreateProbeRequest(
    val name: String = "",
    val redirect: String = "",
    val template: String = "blank",
    @SerializedName("image_url") val imageUrl: String = "",
    @SerializedName("text_content") val textContent: String = "",
    val shorten: String = "self",
    @SerializedName("access_code") val accessCode: String = "",
    @SerializedName("expire_days") val expireDays: Int = 0,
    @SerializedName("once_only") val onceOnly: Int = 0,
)

data class CreateProbeResult(
    val code: String = "",
    val url: String = "",
    @SerializedName("short_url") val shortUrl: String = "",
    val provider: String = "",
)

data class UpdateProbeRequest(
    val name: String = "",
    val redirect: String = "",
    val template: String = "blank",
    @SerializedName("image_url") val imageUrl: String = "",
    @SerializedName("text_content") val textContent: String = "",
    /** 非空时重新生成短链（self/tinyurl/clckru），用于更换爆红短链 */
    val shorten: String? = null,
    @SerializedName("access_code") val accessCode: String = "",
    @SerializedName("expire_days") val expireDays: Int = 0,
    @SerializedName("once_only") val onceOnly: Int = 0,
)

data class StatsData(val probes: Int = 0, val clicks: Int = 0, val today: Int = 0)

data class ToggleResult(val enabled: Int = 1)

/** 单条访问日志 */
data class VisitLog(
    val probe: String = "",
    val ip: String = "",
    val country: String = "",
    val province: String = "",
    val city: String = "",
    val district: String = "",
    val isp: String = "",
    val lat: Double? = null,
    val lng: Double? = null,
    val zip: String = "",
    val timezone: String = "",
    val org: String = "",
    @SerializedName("as_info") val asInfo: String = "",
    @SerializedName("is_ipv6") val isIpv6: Int = 0,
    @SerializedName("gps_lat") val gpsLat: Double? = null,
    @SerializedName("gps_lng") val gpsLng: Double? = null,
    @SerializedName("gps_acc") val gpsAcc: Int? = null,
    @SerializedName("gps_addr") val gpsAddr: String = "",
    val ua: String = "",
    val referer: String = "",
    val time: Long = 0,
)

data class LogList(
    val list: List<VisitLog> = emptyList(),
    val page: Int = 0,
    val size: Int = 0,
)

data class PingData(val pong: Int = 0, val ts: Long = 0)

data class DeleteResult(val deleted: String = "")
data class ClearResult(val deleted: Int = 0)

// ---------------- v1.5 统计图表 / IP 画像 ----------------

data class TrendPoint(val day: String = "", val count: Int = 0)

data class NameCount(val name: String = "", val count: Int = 0)

data class LogStatsData(
    val days: Int = 30,
    val total: Int = 0,
    val uniq: Int = 0,
    val trend: List<TrendPoint> = emptyList(),
    val countries: List<NameCount> = emptyList(),
    val devices: List<NameCount> = emptyList(),
)

data class IpProfile(
    val ip: String = "",
    val total: Int = 0,
    val first: Long = 0,
    val last: Long = 0,
    val probes: List<String> = emptyList(),
    val cities: List<String> = emptyList(),
    @SerializedName("has_gps") val hasGps: Boolean = false,
    val isp: String = "",
    @SerializedName("latest_ua") val latestUa: String = "",
    val devices: List<NameCount> = emptyList(),
)