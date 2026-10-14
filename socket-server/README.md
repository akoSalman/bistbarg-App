# سرور سوکت اعلان‌های بیست‌برگ

این برنامهٔ کوچک Node.js روی سرور سایت اجرا می‌شود. با هر گوشیِ دارای اپ یک اتصال زنده (WebSocket) نگه می‌دارد تا اعلان‌ها **فوری** برسند.

```
وردپرس (افزونهٔ bistbarg-push) ──POST /publish──▶ سرور سوکت ──WebSocket──▶ گوشی‌ها
                                                                          │
گوشی محتوای اعلان را با حساب کاربری خودش از وردپرس می‌گیرد ◀────────────┘
```

## نصب (سرور لینوکس، با دسترسی root)

۱. **Node.js** نسخهٔ ۱۸ یا بالاتر را نصب کنید (اگر نصب نیست):
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

۲. فایل‌ها را در سرور کپی کنید و وابستگی را نصب کنید:
```bash
sudo mkdir -p /opt/bistbarg-socket
sudo cp server.js package.json package-lock.json /opt/bistbarg-socket/
cd /opt/bistbarg-socket && sudo npm ci --omit=dev
```

۳. **رمز سوکت** را از وردپرس بردارید:
پیشخوان ← اعلان‌های اپ ← تنظیمات ← بخش «سرور سوکت» ← «رمز سوکت».

۴. سرویس را نصب کنید. در فایل سرویس، `BISTBARG_SECRET` را برابر رمز مرحلهٔ ۳ بگذارید:
```bash
sudo cp bistbarg-socket.service /etc/systemd/system/
sudo nano /etc/systemd/system/bistbarg-socket.service
sudo systemctl daemon-reload
sudo systemctl enable --now bistbarg-socket
sudo systemctl status bistbarg-socket      # باید active (running) باشد
```

۵. وب‌سرور را تنظیم کنید تا آدرس `wss://bistbarg.com/bistbarg-socket/` به سرور سوکت برسد:
- **nginx**: محتوای `nginx.conf.example` را داخل بلوک `server` سایت (همان که `listen 443` دارد) بگذارید. بعد `sudo nginx -t && sudo systemctl reload nginx` را اجرا کنید.
- **Apache**: از `apache.conf.example` استفاده کنید.
- **کنترل‌پنل‌ها (DirectAdmin / cPanel / aaPanel / CyberPanel)**: همین تنظیم را در بخش «Custom nginx/Apache config» یا «Reverse proxy» سایت وارد کنید.

۶. در وردپرس به «اعلان‌های اپ ← تنظیمات» بروید و «سرور سوکت» را روشن کنید. وضعیت باید «متصل» نشان داده شود.

## دستورهای مفید
```bash
sudo journalctl -u bistbarg-socket -f      # دیدن گزارش زنده (هر اعلان و تعداد گوشی‌های گیرنده)
sudo systemctl restart bistbarg-socket     # راه‌اندازی دوباره
```

## امنیت
- سرور فقط روی `127.0.0.1` گوش می‌دهد. از بیرون فقط از طریق nginx/Apache با HTTPS به آن می‌رسند.
- هر گوشی با یک توکن امضاشده توسط وردپرس وصل می‌شود. این توکن ۲۴ ساعت اعتبار دارد.
- `/publish` و `/health` فقط با رمز سوکت کار می‌کنند.
- متن اعلان از طریق سوکت فرستاده نمی‌شود. گوشی فقط «اعلان جدید داری» را می‌گیرد و محتوا را با ورود خودش از سایت می‌خواند.

## تست
```bash
npm install && npm test
```
