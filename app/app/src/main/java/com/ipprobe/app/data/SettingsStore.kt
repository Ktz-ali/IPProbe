package com.ipprobe.app.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore(name = "settings")

/** 服务器地址 + 访问令牌 */
data class AppSettings(val server: String = "", val token: String = "")

class SettingsStore(private val context: Context) {

    private val keyServer = stringPreferencesKey("server")
    private val keyToken = stringPreferencesKey("token")

    val settings: Flow<AppSettings> = context.dataStore.data.map { p ->
        AppSettings(server = p[keyServer] ?: "", token = p[keyToken] ?: "")
    }

    suspend fun save(server: String, token: String) {
        context.dataStore.edit { p ->
            p[keyServer] = server
            p[keyToken] = token
        }
    }

    suspend fun current(): AppSettings = settings.first()
}