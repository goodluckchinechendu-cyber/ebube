# EbubeConnect

Sales agent platform for **ebubeconnect.com**.

## Folder layout

| Path | Purpose |
|------|---------|
| `app/` | Flutter source (Android + Web now; iOS folder ready for later) |
| `backend/` | PHP API (renamed from `api/`) |
| `agent_legacy/` | Old compiled SMobile Accesslink web build (reference only) |

## Run locally

### Backend
Serve this repo with XAMPP. API base:

`http://localhost/Ebube/backend`

### Flutter web
```bash
cd app
flutter run -d chrome
```

### Flutter Android (emulator)
```bash
cd app
flutter run -d android
```

Physical Android device (use your machine LAN IP):
```bash
flutter run -d android --dart-define=API_BASE=http://192.168.x.x/Ebube/backend
```

Production API:
```bash
flutter run --dart-define=API_BASE=https://ebubeconnect.com/backend
```

## Deploy (Railway)

Builds Flutter web into `app/build/web` (served as `/agent/`) and uploads the Docker image to Railway:

```bash
./scripts/deploy.sh
```

Optional: `API_BASE=https://ebubeconnect.com/backend RAILWAY_SERVICE=web ./scripts/deploy.sh`

## Branding

- App name: **EbubeConnect**
- Brand slug header: `ebubeconnect` (`X-SMobile-Brand-Slug`)
- Temporary logo: letters **EC** (replace later with your image)
- Mail brand entry added in `backend/config.mail.brands.php`
- Copy `backend/config.mail.ebubeconnect.example.php` → `backend/config.mail.php` on the live server and set SMTP password

## SMobile VTU (airtime / data)

EbubeConnect proxies purchases through `backend/` so the API key stays on the server.

1. Create a key in SMobile app → Developer Settings  
2. Put it in `backend/config.smobile_vtu.php` as `$smobileVtuApiKey`  
3. App calls:
   - `vtu_balance.php`
   - `vtu_plans.php?network=MTN`
   - `vtu_purchase.php` (`type`: airtime | data)
   - `vtu_transaction.php?reference=SM-…`

Upstream base: `https://smobileagent.com/api`
