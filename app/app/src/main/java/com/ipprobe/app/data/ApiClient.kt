package com.ipprobe.app.data

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {

    @Volatile
    private var api: ProbeApi? = null

    @Volatile
    private var baseUrl: String = ""

    fun get(base: String): ProbeApi {
        val normalized = normalizeBase(base)
        val cached = api
        if (cached != null && baseUrl == normalized) return cached

        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }
        val client = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .addInterceptor(logging)
            .build()
        val retrofit = Retrofit.Builder()
            .baseUrl(normalized)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
        api = retrofit.create(ProbeApi::class.java)
        baseUrl = normalized
        return api!!
    }

    /** 规范化：去空格、确保以 / 结尾（如 https://xxx/release/） */
    fun normalizeBase(base: String): String {
        val b = base.trim()
        return if (b.endsWith("/")) b else "$b/"
    }
}