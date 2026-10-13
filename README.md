# Bistbarg Android App

Android app for the shop at **https://bistbarg.com**. The app shows the website itself full screen, so it looks and works **exactly like the site**. Any change you make on the site (theme, products, menus, plugins) shows up in the app immediately, with no app update needed.

## Features

- Opens bistbarg.com full screen and matches the status bar colour to the site.
- Splash screen while the first page loads, and a thin loading bar.
- Pull down to refresh.
- Android back button goes back through pages; press it twice to exit.
- Friendly "no internet" screen with a retry button.
- Login, cart, checkout and every payment gateway work exactly as on the site.
- Links to Instagram, Telegram, WhatsApp, Eitaa, Rubika and others, plus phone (`tel:`) and email links, open in their own apps.
- File uploads (e.g. forms) and downloads work.
- bistbarg.com links opened on the phone can open in the app.
- The app adds `BistbargApp/<version>` to its user agent, so the site can detect the app (for example to hide a "download our app" banner).

> The earlier native version (product, cart and category screens built inside the app) is still in the git history if you want it back.

## Getting the APK

**Download the latest APK:** https://github.com/akoSalman/bistbarg-App/releases/latest/download/bistbarg.apk

Open that link on your Android phone, then open the downloaded file. You may need to allow *Install unknown apps*.

Every push to `main` builds the app with GitHub Actions and publishes it on the [Releases page](https://github.com/akoSalman/bistbarg-App/releases). Pushing a tag such as `v1.0.0` creates a release with that version name.

## Configuration

- Site address: `STORE_URL` in `app/build.gradle.kts`
- App name: `app/src/main/res/values/strings.xml`
- Icon and splash colour: `app/src/main/res/values/colors.xml` and `res/drawable/ic_launcher_foreground.xml`
- Apps that external links open in: `EXTERNAL_HOSTS` in `MainActivity.kt`

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
app/src/main/
├── java/com/bistbarg/app/MainActivity.kt   # WebView, back button, external links, uploads
├── res/layout/activity_main.xml            # WebView + pull-to-refresh + offline screen
└── AndroidManifest.xml
```
