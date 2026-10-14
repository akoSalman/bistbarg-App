plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Shop settings. Override on the command line, e.g. ./gradlew assembleDebug -PSTORE_URL=https://example.com
fun prop(name: String, default: String) = (project.findProperty(name) as String?) ?: default

val storeUrl = prop("STORE_URL", "https://bistbarg.com")
val accountPath = prop("ACCOUNT_PATH", "/my-account/")

val keystorePath: String? = System.getenv("ANDROID_KEYSTORE_PATH")

android {
    namespace = "com.bistbarg.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.bistbarg.app"
        minSdk = 26
        targetSdk = 35
        versionCode = prop("VERSION_CODE", "1").toInt()
        versionName = prop("VERSION_NAME", "1.0.0")

        buildConfigField("String", "STORE_URL", "\"$storeUrl\"")
        buildConfigField("String", "ACCOUNT_PATH", "\"$accountPath\"")
    }

    signingConfigs {
        if (keystorePath != null) {
            create("release") {
                storeFile = file(keystorePath)
                storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("ANDROID_KEY_ALIAS")
                keyPassword = System.getenv("ANDROID_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (keystorePath != null) signingConfig = signingConfigs.getByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        buildConfig = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.core:core-splashscreen:1.0.1")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
}
