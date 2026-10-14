=== ورود و ثبت‌نام با موبایل بیست‌برگ (کاوه‌نگار) ===
Stable tag: 2.0.0
Requires PHP: 7.4

ثبت‌نام و ورود مشتریان با موبایل و کد پیامکی، سپس تکمیل نام، نام خانوادگی، استان و شهر.
فرم پیش‌فرض ورود/ثبت‌نام ووکامرس غیرفعال می‌شود.

== نصب ==
1. افزونه‌ها ← افزودن ← بارگذاری افزونه ← bistbarg-otp.zip ← نصب و فعال‌سازی.
2. در پنل کاوه‌نگار یک الگوی تأیید (VerifyLookup) با %token% بسازید؛ مثلاً «کد ورود شما: %token%».
3. تنظیمات ← «ورود با موبایل»: نام الگو را بگذارید. (کلید API خالی = استفاده از افزونهٔ پیامک فعلی.)
4. گزینهٔ «غیرفعال کردن فرم پیش‌فرض ووکامرس» روشن باشد تا صفحهٔ حساب کاربری همین فرم را نشان دهد.
   یا کد کوتاه [bistbarg_otp_login] را در هر صفحه‌ای بگذارید.

== API (برای اپلیکیشن) ==
POST /wp-json/bistbarg/v1/otp/request  { phone }
POST /wp-json/bistbarg/v1/otp/verify   { phone, code }  → { needs_profile }
POST /wp-json/bistbarg/v1/otp/profile  { first_name, last_name, province, city, address }  (نیازمند ورود)
GET  /wp-json/bistbarg/v1/locations    → { استان: [شهرها] }
