package com.bistbarg.app.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Subset of the WooCommerce Store API (wc/store/v1) product schema used by the app. */
@Serializable
data class Product(
    val id: Long,
    val name: String = "",
    val slug: String = "",
    val permalink: String = "",
    val type: String = "simple",
    val description: String = "",
    @SerialName("short_description") val shortDescription: String = "",
    @SerialName("on_sale") val onSale: Boolean = false,
    val prices: Prices = Prices(),
    val images: List<ProductImage> = emptyList(),
    val categories: List<CategoryRef> = emptyList(),
    val attributes: List<Attribute> = emptyList(),
    val variations: List<VariationRef> = emptyList(),
    @SerialName("is_purchasable") val isPurchasable: Boolean = true,
    @SerialName("is_in_stock") val isInStock: Boolean = true,
    @SerialName("add_to_cart") val addToCart: AddToCart = AddToCart(),
) {
    val isVariable get() = type == "variable"
    val canBeAddedDirectly get() = type == "simple" || type == "variation"
    val variationAttributes get() = attributes.filter { it.hasVariations }
}

@Serializable
data class Prices(
    val price: String = "0",
    @SerialName("regular_price") val regularPrice: String = "0",
    @SerialName("sale_price") val salePrice: String = "0",
    @SerialName("price_range") val priceRange: PriceRange? = null,
    @SerialName("currency_code") val currencyCode: String = "",
    @SerialName("currency_symbol") val currencySymbol: String = "",
    @SerialName("currency_minor_unit") val currencyMinorUnit: Int = 2,
    @SerialName("currency_decimal_separator") val decimalSeparator: String = ".",
    @SerialName("currency_thousand_separator") val thousandSeparator: String = ",",
    @SerialName("currency_prefix") val prefix: String = "",
    @SerialName("currency_suffix") val suffix: String = "",
)

@Serializable
data class PriceRange(
    @SerialName("min_amount") val minAmount: String = "0",
    @SerialName("max_amount") val maxAmount: String = "0",
)

@Serializable
data class ProductImage(
    val id: Long = 0,
    val src: String = "",
    val thumbnail: String = "",
    val alt: String = "",
) {
    val thumbnailOrSrc get() = thumbnail.ifEmpty { src }
}

@Serializable
data class CategoryRef(val id: Long, val name: String = "", val slug: String = "")

@Serializable
data class Attribute(
    val id: Long = 0,
    val name: String = "",
    val taxonomy: String? = null,
    @SerialName("has_variations") val hasVariations: Boolean = false,
    val terms: List<Term> = emptyList(),
)

@Serializable
data class Term(val id: Long = 0, val name: String = "", val slug: String = "")

@Serializable
data class VariationRef(val id: Long, val attributes: List<VariationAttribute> = emptyList())

@Serializable
data class VariationAttribute(val name: String = "", val value: String = "")

@Serializable
data class AddToCart(val text: String = "", val minimum: Int = 1, val maximum: Int = 9999)

@Serializable
data class Category(
    val id: Long,
    val name: String = "",
    val slug: String = "",
    val parent: Long = 0,
    val count: Int = 0,
    val image: ProductImage? = null,
)
