package com.bistbarg.app.data

import androidx.core.text.HtmlCompat
import java.math.BigDecimal
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/** Decodes HTML entities and strips tags, e.g. "Tom &amp; Jerry" -> "Tom & Jerry". */
fun String.decodeHtml(): String =
    if ('&' !in this && '<' !in this) this
    else HtmlCompat.fromHtml(this, HtmlCompat.FROM_HTML_MODE_LEGACY).toString().trim()

object PriceFormatter {
    /** Formats an amount given in the currency's minor unit (as the Store API returns it). */
    fun format(amountMinor: String, prices: Prices): String =
        format(amountMinor.toBigDecimalOrNull() ?: BigDecimal.ZERO, prices)

    fun format(amountMinor: BigDecimal, prices: Prices): String {
        val minorUnit = prices.currencyMinorUnit.coerceAtLeast(0)
        val symbols = DecimalFormatSymbols(Locale.US).apply {
            decimalSeparator = prices.decimalSeparator.firstOrNull() ?: '.'
            prices.thousandSeparator.firstOrNull()?.let { groupingSeparator = it }
        }
        val pattern = if (minorUnit > 0) "#,##0." + "0".repeat(minorUnit) else "#,##0"
        val formatter = DecimalFormat(pattern, symbols).apply {
            isGroupingUsed = prices.thousandSeparator.isNotEmpty()
        }
        val number = formatter.format(amountMinor.movePointLeft(minorUnit))
        val prefix = prices.prefix.decodeHtml()
        val suffix = prices.suffix.decodeHtml()
        return buildString {
            append(prefix)
            append(number)
            if (suffix.isNotEmpty()) {
                if (!suffix.first().isWhitespace() && suffix.first().isLetter()) append(' ')
                append(suffix)
            }
        }
    }

    fun productPrice(product: Product): String {
        val p = product.prices
        val range = p.priceRange
        return if (range != null && range.minAmount != range.maxAmount) {
            "${format(range.minAmount, p)} – ${format(range.maxAmount, p)}"
        } else {
            format(p.price, p)
        }
    }
}
