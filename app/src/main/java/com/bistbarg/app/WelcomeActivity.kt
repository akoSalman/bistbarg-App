package com.bistbarg.app

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.animation.DecelerateInterpolator
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.ComponentActivity

/** Shown once, on the first launch: invites the visitor to become a member. */
class WelcomeActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_welcome)

        val benefits = findViewById<LinearLayout>(R.id.benefits)
        listOf(R.string.welcome_benefit_orders, R.string.welcome_benefit_fast, R.string.welcome_benefit_offers)
            .forEach { text ->
                val row = LayoutInflater.from(this).inflate(R.layout.item_benefit, benefits, false)
                row.findViewById<TextView>(R.id.text).setText(text)
                benefits.addView(row)
            }

        findViewById<View>(R.id.yes).setOnClickListener {
            // The sign-up screen reports straight back to MainActivity.
            startActivity(Intent(this, RegisterActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_FORWARD_RESULT))
            finish()
        }
        findViewById<View>(R.id.no).setOnClickListener { finish() }
        findViewById<View>(R.id.login).setOnClickListener {
            setResult(RESULT_OK, Intent().putExtra(MainActivity.EXTRA_ACTION, MainActivity.ACTION_LOGIN))
            finish()
        }

        if (savedInstanceState == null) animateIn(findViewById(R.id.content))
    }

    /** Children slide up and fade in one after another. */
    private fun animateIn(container: ViewGroup) {
        val items = buildList {
            for (i in 0 until container.childCount) {
                val child = container.getChildAt(i)
                if (child.id == R.id.benefits && child is ViewGroup) {
                    for (j in 0 until child.childCount) add(child.getChildAt(j))
                } else {
                    add(child)
                }
            }
        }
        val shift = 24 * resources.displayMetrics.density
        items.forEachIndexed { index, view ->
            view.alpha = 0f
            view.translationY = shift
            view.animate()
                .alpha(1f)
                .translationY(0f)
                .setStartDelay(120L + index * 70L)
                .setDuration(500)
                .setInterpolator(DecelerateInterpolator(2f))
                .start()
        }
    }

    @Suppress("DEPRECATION")
    override fun finish() {
        super.finish()
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out)
    }
}
