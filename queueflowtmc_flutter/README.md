# QUEUEFLOW TMC — Flutter App Connection Guide

QueueFlow TMC Flutter application. By default it talks to the Laravel
backend through the **public HTTPS ngrok tunnel**: the phone works on
**any Wi-Fi or mobile data** — it does NOT need to share Wi-Fi with the
laptop. The laptop must stay on Wi-Fi running both the Laravel server
and the ngrok tunnel (ngrok is a tunnel, not hosting).

## The one rule

Every request uses a single base URL owned by `lib/core/api/api_config.dart`.
Nothing else in `lib/` holds an address. There are three ways to set it,
in priority order:

1. **In the app (recommended):** About > Server connection > Change.
   Enter the server address (ending with `/api`), tap Test. The choice is
   remembered on the phone across restarts — no rebuild needed.
2. **At build time:** `flutter run --dart-define=API_BASE_URL=<url>/api`
   or the same flag on `flutter build apk --release`.
3. **Compiled-in default:** the public ngrok tunnel
   (`https://spelling-ocelot-opposing.ngrok-free.dev/api`).
   Free ngrok URLs change on every tunnel restart — when it changes, use
   option 1 above (no rebuild) or update the one `defaultValue` line in
   `api_config.dart` (`.\tool\update_api_url.ps1 -Url https://<new-host>/api`).
   A reserved ngrok domain keeps one stable URL instead.

## Tunnel setup (works on any Wi-Fi + mobile data)

1. Start Laravel on the server computer:
   `php artisan serve --host=0.0.0.0 --port=8000`
   (or double-click `start-queueflow-ngrok.bat` in the Laravel project,
   which also starts the tunnel).
2. Start the tunnel: `ngrok http 8000`
   (add `--url=https://<reserved-domain>` for a stable URL).
3. Make sure the app points at the public tunnel URL
   (`https://<host>/api` via the in-app setting or the default).
4. Install the APK. **USB cable is NOT required after installation.**

Phone on mobile data with Wi-Fi off works: requests go over the public
internet through the tunnel to the laptop. Same-Wi-Fi LAN
(`http://<lan-ip>:8000/api`, cleartext allowed only for private hosts)
remains available as a fallback via option 1 above.

## Network behavior

- **Offline detection:** distinguishes no internet, unreachable server,
  authentication failure, and server errors — each with its own message.
- **Retry:** Retry buttons and the status banner re-probe the real
  `/api/v1/health` endpoint; automatic retries back off (5s → 30s ceiling).
- **Network switching:** Wi-Fi ⇄ mobile-data changes are detected and
  re-probed automatically; queue data refreshes after reconnecting.
- **No duplicate tickets:** a create call whose reply was lost is flagged
  uncertain and reconciled against the server before reporting failure.
- **Timeouts:** 15s connect / 20s receive / 35s per request.

## Troubleshooting

If you see "Server Offline":
- Check About > Server connection shows the tunnel URL
  (`https://<host>/api`) and tap Test — a stale tunnel URL after an
  ngrok restart is the most common cause.
- Check the Laravel server is running on the PC
  (`php artisan serve --host=0.0.0.0 --port=8000`) and `ngrok http 8000`
  is running.
- On mobile data with a `http://<lan-ip>` address the app says so
  explicitly — switch back to the public tunnel URL.

## Notification sounds (Android + iPhone)

Queue alerts (number called, turn approaching, ticket issued/completed/
cancelled, new announcements) arrive as system notifications with sound:

- **Android** (TECNO, Infinix, Samsung, Xiaomi/Redmi, OPPO, vivo, realme,
  Pixel and others): a bundled `queueflow_chime` plays through the
  `QueueFlow TMC Notifications` channel (Android 8+ binds sound to the
  channel; the app migrates the channel automatically when its sound
  changes). Respects volume, silent mode and Do Not Disturb.
- **iPhone**: the iOS system default notification sound plays (an iOS-valid
  choice; no custom bundle sound is shipped yet). Permission for
  alert + badge + sound is requested once after first launch.

Settings > On this device: a sound on/off toggle (persisted, default on)
and **Play test sound**. Tapping a notification opens its ticket or
announcement screen — including taps that launch a closed app. The same
event never sounds twice (persisted per-account seen-set + recency
window), even across data refreshes.

Detection needs the app alive (foreground, or background while the OS
keeps it): events are found by polling the Laravel API. **Terminated-app
delivery is NOT implemented** — it needs remote push, which requires
manual setup outside this repository:

1. Create a Firebase project, add the Android app (`com.example.night_login`
   or your renamed applicationId) and the iOS app (your bundle ID);
   download `google-services.json` into `android/app/` and
   `GoogleService-Info.plist` into `ios/Runner/`.
2. Add the `firebase_messaging` Flutter package and a background handler.
3. iOS only, all manual in Apple's portals (needs a paid Apple Developer
   account): APNs key in Firebase console, Push Notifications capability +
   entitlements in Xcode, signed release builds from macOS/Xcode.
4. Laravel: store per-user device tokens (new table + registration
   endpoint) and send FCM payloads with `sound: default` (Android channel
   sound still governs) and `aps.sound: default` for iOS. Never ship
   Firebase server keys in the app.

iPhone push has **not** been tested on a real iPhone (no macOS/Xcode here);
the iOS local-notification path is code-complete and analyzer-clean but
unproven on-device.

## Build

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release --dart-define=API_BASE_URL=http://10.34.142.48:8000/api
```
