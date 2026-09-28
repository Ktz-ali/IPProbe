package com.ipprobe.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import com.ipprobe.app.ui.MainScreen
import com.ipprobe.app.ui.MainViewModel
import com.ipprobe.app.ui.MainViewModelFactory
import com.ipprobe.app.ui.theme.IpProbeTheme

class MainActivity : ComponentActivity() {

    private val vm: MainViewModel by viewModels {
        MainViewModelFactory(applicationContext)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            IpProbeTheme {
                MainScreen(vm)
            }
        }
    }
}