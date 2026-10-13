# Bistbarg Android App

Native Android app (Kotlin + Jetpack Compose) for the WooCommerce shop at **https://bistbarg.com**.

## Features

- **Native shop.** Product grid with infinite scroll, pull-to-refresh and search.
- **Categories.** Category chips on the home screen and a full categories tab.
- **Product page.** Image gallery, sale prices, stock status, variation picker (size, colour, …), quantity and sharing.
- **Native cart.** Saved on the device, with a badge on the Cart tab.
- **Checkout.** Tapping *Checkout* moves the cart into the website cart and opens your real WooCommerce checkout inside the app. All your payment gateways, shipping methods, coupons and plugins keep working.
- **Account.** The *My Account* page (login, orders, addresses) opens in the app.
- **System support.** Light and dark mode, RTL layouts, splash screen and adaptive icon.

### No API keys needed

The app uses the public, read-only [WooCommerce Store API](https://developer.woocommerce.com/docs/apis/store-api/) (`/wp-json/wc/store/v1/`), which is built into WooCommerce. No secrets are stored in the app or in this repository.

## Getting the APK

Every push to `main` builds the app with GitHub Actions:

1. Open the **Actions** tab and click the latest **Build Android APK** run.
2. Download the **bistbarg-debug-apk** artifact (a zip containing `app-debug.apk`).
3. Copy it to your phone and open it. You may need to allow *Install unknown apps*.

To publish a downloadable release, push a tag such as `v1.0.0`. The APK will be attached to a GitHub Release.

## Configuration

Values live in `app/build.gradle.kts` and can be overridden with `-P` flags:

| Property        | Default                | Purpose                                  |
|-----------------|------------------------|------------------------------------------|
| `STORE_URL`     | `https://bistbarg.com` | Your shop's address                      |
| `CHECKOUT_PATH` | `/checkout/`           | Fallback checkout page path              |
| `ACCOUNT_PATH`  | `/my-account/`         | My Account page path                     |

The checkout URL is normally detected automatically from WooCommerce. If your account or checkout page uses a different (for example, translated) slug, change the defaults.

App name: `app/src/main/res/values/strings.xml`. Colours: `ui/theme/Theme.kt` and `res/values/colors.xml`. To translate the app, add `res/values-fa/strings.xml` (or `values-ckb`, `values-ar`, …).

## Signed release and Google Play

Debug APKs are fine for testing. For the Play Store you need a signed build:

1. Create a keystore once and keep it safe. If you lose it, you can't update the app:
   ```
   keytool -genkeypair -v -keystore release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias bistbarg
   ```
2. In the repo, go to **Settings → Secrets and variables → Actions** and add:
   - `ANDROID_KEYSTORE_BASE64`: the output of `base64 -w0 release.jks`
   - `ANDROID_KEYSTORE_PASSWORD`
   - `ANDROID_KEY_ALIAS` (for example, `bistbarg`)
   - `ANDROID_KEY_PASSWORD`
3. The next build also produces a signed `app-release.apk` and an `app-release.aab`. Upload the `.aab` to Google Play.

## Building locally

Requires JDK 17 and the Android SDK (or Android Studio):

```
./gradlew assembleDebug
```

## Project layout

```
app/src/main/java/com/bistbarg/app/
├── BistbargApp.kt            # HTTP client, shared cookies, repositories
├── MainActivity.kt           # Navigation + bottom tabs
├── data/                     # Store API client, models, cart, price formatting
└── ui/
    ├── ViewModels.kt
    ├── components/           # Product grid, WebView, shared widgets
    ├── screens/              # Home, Categories, Product, Cart, Checkout, Account
    └── theme/
```
