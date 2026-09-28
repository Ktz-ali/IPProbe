package com.ipprobe.app.data

import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** 云函数接口（路径为相对路径，与 BASE_URL 拼接） */
interface ProbeApi {

    @GET("api/ping")
    suspend fun ping(@Query("token") token: String): ApiResponse<PingData>

    @POST("api/probe")
    suspend fun createProbe(
        @Query("token") token: String,
        @Body body: CreateProbeRequest,
    ): ApiResponse<CreateProbeResult>

    @GET("api/probe/list")
    suspend fun listProbes(@Query("token") token: String): ApiResponse<ProbeList>

    @GET("api/stats")
    suspend fun stats(@Query("token") token: String): ApiResponse<StatsData>

    @POST("api/probe/{code}")
    suspend fun updateProbe(
        @Path("code") code: String,
        @Query("token") token: String,
        @Body body: UpdateProbeRequest,
    ): ApiResponse<DeleteResult>

    @POST("api/probe/{code}/toggle")
    suspend fun toggleProbe(
        @Path("code") code: String,
        @Query("token") token: String,
    ): ApiResponse<ToggleResult>

    @DELETE("api/probe/{code}")
    suspend fun deleteProbe(
        @Path("code") code: String,
        @Query("token") token: String,
    ): ApiResponse<DeleteResult>

    @GET("api/logs")
    suspend fun logs(
        @Query("token") token: String,
        @Query("probe") probe: String,
        @Query("page") page: Int = 0,
        @Query("size") size: Int = 50,
        @Query("days") days: Int = 0,
        @Query("country") country: String = "",
        @Query("device") device: String = "",
        @Query("q") q: String = "",
    ): ApiResponse<LogList>
    @DELETE("api/logs")
    suspend fun clearLogs(
        @Query("token") token: String,
        @Query("probe") probe: String = "",
    ): ApiResponse<ClearResult>
    @GET("api/logs/stats")
    suspend fun logsStats(
        @Query("token") token: String,
        @Query("days") days: Int = 30,
        @Query("probe") probe: String = "",
    ): ApiResponse<LogStatsData>
    @GET("api/ip_profile")
    suspend fun ipProfile(
        @Query("token") token: String,
        @Query("ip") ip: String,
    ): ApiResponse<IpProfile>
}