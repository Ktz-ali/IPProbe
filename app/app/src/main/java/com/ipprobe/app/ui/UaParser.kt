package com.ipprobe.app.ui

/**
 * 纯前端 UA 解析：识别设备类型与浏览器（不依赖后端）
 */
object UaParser {

    /** 设备类型：手机 / 平板 / 电脑 / 其他 */
    fun device(ua: String): String {
        val u = ua.lowercase()
        return when {
            u.contains("ipad") || u.contains("tablet") -> "平板"
            u.contains("iphone") -> "手机"
            u.contains("android") && u.contains("mobile") -> "手机"
            u.contains("android") -> "平板"
            u.contains("windows") || u.contains("macintosh") || u.contains("linux") -> "电脑"
            u.contains("mobile") -> "手机"
            else -> "其他"
        }
    }

    /** 浏览器/客户端名称 */
    fun browser(ua: String): String {
        val u = ua.lowercase()
        return when {
            u.contains("micromessenger") || u.contains("wechat") -> "微信"
            u.contains("mqqbrowser") || u.contains("qq/") -> "QQ浏览器"
            u.contains("quark") -> "夸克"
            u.contains("ucbrowser") || u.contains("ucweb") -> "UC"
            u.contains("edg/") || u.contains("edge") -> "Edge"
            u.contains("firefox") || u.contains("fxios") -> "Firefox"
            u.contains("crios") -> "Chrome"
            u.contains("safari") && u.contains("version") -> "Safari"
            u.contains("chrome") -> "Chrome"
            u.contains("okhttp") || u.contains("dalvik") -> "App内嵌"
            u.contains("curl") || u.contains("wget") || u.contains("python") || u.contains("bot") -> "脚本/机器人"
            else -> ua.take(24)
        }
    }
}