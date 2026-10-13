package com.bistbarg.app

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.RadialGradient
import android.graphics.RectF
import android.graphics.Shader
import android.graphics.Typeface
import android.os.SystemClock
import android.view.View
import androidx.core.content.res.ResourcesCompat
import android.view.animation.DecelerateInterpolator
import android.view.animation.OvershootInterpolator
import kotlin.math.PI
import kotlin.math.min
import kotlin.math.sin
import kotlin.random.Random

/**
 * Animated welcome screen on white: gold specks drift up, the Bistbarg fan spins into place,
 * the "BIST BARG" letters slide in, a shine sweeps across the logo, a gold line opens
 * and the slogan rises in underneath.
 */
class IntroView(context: Context) : View(context) {

    /** Called once when the whole sequence has played. */
    var onSequenceFinished: (() -> Unit)? = null

    private val density = resources.displayMetrics.density
    private fun dp(v: Float) = v * density

    private val fan: Bitmap = BitmapFactory.decodeResource(resources, R.drawable.intro_fan)
    private val letters: Bitmap = BitmapFactory.decodeResource(resources, R.drawable.intro_text)
    private val slogan = context.getString(R.string.intro_slogan)

    // Layout of the original logo (291 x 186): fan at (0,0) 132x186, letters at (124,70) 167x93.
    private val logoW = 291f
    private val logoH = 186f
    private val fanRect = RectF(0f, 0f, 132f, 186f)
    private val lettersRect = RectF(124f, 70f, 291f, 163f)
    private val fanPivotX = 92f
    private val fanPivotY = 125f

    private val bitmapPaint = Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
    private val bgPaint = Paint()
    private val shinePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        xfermode = PorterDuffXfermode(PorterDuff.Mode.SRC_ATOP)
    }
    private val linePaint = Paint(Paint.ANTI_ALIAS_FLAG)
    private val sloganPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        textAlign = Paint.Align.CENTER
        typeface = Typeface.create(ResourcesCompat.getFont(context, R.font.vazirmatn) ?: Typeface.SANS_SERIF, Typeface.BOLD)
        textSize = dp(19f)
    }
    private val sparkPaint = Paint(Paint.ANTI_ALIAS_FLAG)
    private val glowPaint = Paint(Paint.ANTI_ALIAS_FLAG)

    private val overshoot = OvershootInterpolator(1.4f)
    private val decelerate = DecelerateInterpolator(2f)

    private class Spark(var x: Float, var y: Float, val r: Float, val speed: Float, val phase: Float)
    private val sparks = mutableListOf<Spark>()

    private var startTime = 0L
    private var finished = false
    private val logoRect = RectF()

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        bgPaint.shader = RadialGradient(
            w / 2f, h * 0.42f, maxOf(w, h) * 0.75f,
            intArrayOf(0xFFFFFFFF.toInt(), 0xFFFFFFFF.toInt(), 0xFFF8F3E1.toInt()),
            floatArrayOf(0f, 0.55f, 1f), Shader.TileMode.CLAMP,
        )
        val width = min(w * 0.72f, dp(320f))
        val height = width * logoH / logoW
        val left = (w - width) / 2f
        val top = h * 0.42f - height / 2f
        logoRect.set(left, top, left + width, top + height)

        val gold = intArrayOf(0xFFCFAE34.toInt(), 0xFFA9831C.toInt(), 0xFF7A5C10.toInt())
        sloganPaint.shader = LinearGradient(0f, logoRect.bottom, 0f, logoRect.bottom + dp(80f), gold, null, Shader.TileMode.CLAMP)
        linePaint.shader = LinearGradient(
            w / 2f - dp(80f), 0f, w / 2f + dp(80f), 0f,
            intArrayOf(0x00C7A93A, 0xFFC7A93A.toInt(), 0x00C7A93A), null, Shader.TileMode.CLAMP,
        )
        glowPaint.shader = RadialGradient(
            logoRect.left + fanPivotX / logoW * width, logoRect.top + fanPivotY / logoH * height, width * 0.6f,
            intArrayOf(0x30E5C74A, 0x00E5C74A), null, Shader.TileMode.CLAMP,
        )

        sparks.clear()
        val rnd = Random(20)
        repeat(42) {
            sparks += Spark(
                x = rnd.nextFloat() * w,
                y = rnd.nextFloat() * h,
                r = dp(0.8f + rnd.nextFloat() * 2.2f),
                speed = dp(12f + rnd.nextFloat() * 30f),
                phase = rnd.nextFloat() * 2f * PI.toFloat(),
            )
        }
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        if (startTime == 0L) startTime = SystemClock.uptimeMillis()
    }

    private fun progress(t: Float, start: Float, duration: Float) = ((t - start) / duration).coerceIn(0f, 1f)

    override fun onDraw(canvas: Canvas) {
        val t = (SystemClock.uptimeMillis() - startTime).toFloat()
        canvas.drawRect(0f, 0f, width.toFloat(), height.toFloat(), bgPaint)
        drawSparks(canvas, t)

        val scale = logoRect.width() / logoW
        fun mapX(x: Float) = logoRect.left + x * scale
        fun mapY(y: Float) = logoRect.top + y * scale

        // Soft glow behind the fan once it has landed.
        val glow = progress(t, 700f, 900f)
        if (glow > 0f) {
            glowPaint.alpha = (255 * glow).toInt()
            canvas.drawCircle(mapX(fanPivotX), mapY(fanPivotY), logoRect.width() * 0.6f, glowPaint)
        }

        // Everything logo-related goes into one layer so the shine only lights up the logo.
        val layer = canvas.saveLayer(0f, 0f, width.toFloat(), height.toFloat(), null)

        // 1) Fan spins in.
        val fanLinear = progress(t, 150f, 1100f)
        if (fanLinear > 0f) {
            val p = overshoot.getInterpolation(fanLinear)
            canvas.save()
            canvas.rotate(-200f * (1f - p), mapX(fanPivotX), mapY(fanPivotY))
            val s = 0.25f + 0.75f * p
            canvas.scale(s, s, mapX(fanPivotX), mapY(fanPivotY))
            bitmapPaint.alpha = (255 * progress(t, 150f, 450f)).toInt()
            canvas.drawBitmap(fan, null, RectF(mapX(fanRect.left), mapY(fanRect.top), mapX(fanRect.right), mapY(fanRect.bottom)), bitmapPaint)
            canvas.restore()
        }

        // 2) Letters slide in from the side.
        val textP = decelerate.getInterpolation(progress(t, 750f, 750f))
        if (textP > 0f) {
            val dx = dp(48f) * (1f - textP)
            bitmapPaint.alpha = (255 * textP).toInt()
            canvas.drawBitmap(
                letters, null,
                RectF(mapX(lettersRect.left) + dx, mapY(lettersRect.top), mapX(lettersRect.right) + dx, mapY(lettersRect.bottom)),
                bitmapPaint,
            )
        }

        // 3) Shine sweeps across the logo.
        val shineP = progress(t, 1550f, 900f)
        if (shineP > 0f && shineP < 1f) {
            val band = logoRect.width() * 0.35f
            val x = logoRect.left - band + (logoRect.width() + 2 * band) * shineP
            shinePaint.shader = LinearGradient(
                x - band / 2, logoRect.top, x + band / 2, logoRect.bottom,
                intArrayOf(0x00FFFFFF, 0xB3FFFFFF.toInt(), 0x00FFFFFF), null, Shader.TileMode.CLAMP,
            )
            canvas.drawRect(logoRect.left - band, logoRect.top - band, logoRect.right + band, logoRect.bottom + band, shinePaint)
        }
        canvas.restoreToCount(layer)

        // 4) Gold line opens under the logo.
        val lineP = decelerate.getInterpolation(progress(t, 1700f, 650f))
        val lineY = logoRect.bottom + dp(26f)
        if (lineP > 0f) {
            val half = dp(80f) * lineP
            canvas.drawRect(width / 2f - half, lineY, width / 2f + half, lineY + dp(1.5f), linePaint)
        }

        // 5) Slogan rises in.
        val sloganP = decelerate.getInterpolation(progress(t, 2000f, 750f))
        if (sloganP > 0f) {
            sloganPaint.alpha = (255 * sloganP).toInt()
            val y = lineY + dp(40f) + dp(14f) * (1f - sloganP)
            canvas.drawText(slogan, width / 2f, y, sloganPaint)
        }

        if (!finished && t > 2900f) {
            finished = true
            onSequenceFinished?.invoke()
        }
        if (isAttachedToWindow) postInvalidateOnAnimation()
    }

    private fun drawSparks(canvas: Canvas, t: Float) {
        val seconds = t / 1000f
        val h = height.toFloat()
        val fadeIn = progress(t, 0f, 800f)
        for (s in sparks) {
            var y = (s.y - s.speed * seconds) % h
            if (y < 0) y += h
            val x = s.x + sin(seconds * 0.8f + s.phase) * dp(8f)
            val twinkle = 0.35f + 0.65f * ((sin(seconds * 2.2f + s.phase) + 1f) / 2f)
            sparkPaint.color = 0xFFD2B23E.toInt()
            sparkPaint.alpha = (150 * twinkle * fadeIn).toInt()
            canvas.drawCircle(x, y, s.r, sparkPaint)
        }
    }
}
