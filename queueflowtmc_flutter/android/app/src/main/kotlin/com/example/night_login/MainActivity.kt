package com.example.night_login

import android.content.Intent

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(
            flutterEngine.dartExecutor.binaryMessenger,
            "qflow/receipt",
        ).setMethodCallHandler { call, result ->
            when (call.method) {
                "shareText" -> {
                    val text = call.argument<String>("text") ?: ""
                    val subject = call.argument<String>("subject") ?: "QueueFlow TMC"

                    val share = Intent(Intent.ACTION_SEND).apply {
                        type = "text/plain"
                        putExtra(Intent.EXTRA_SUBJECT, subject)
                        putExtra(Intent.EXTRA_TEXT, text)
                    }

                    startActivity(Intent.createChooser(share, "Share receipt"))
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }
}