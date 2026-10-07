# Ngingu — Backend

Backend for **Ngingu**, a fish-disease prediction app for Indonesian aquaculture farmers.
Implements [SRS.md](../SRS.md) v1.0.4 and the HTTP contract in [API.md](../API.md) v1.0.2.

- **Laravel 13** (PHP ^8.3) JSON API under `/api/*` — consumed by the **Flutter** mobile app (mobile-only frontend).
- **Blade admin panel** at `/admin` (server-rendered, default guard) — FR-09.
- **ML inference** (Python FastAPI) reached through a driver abstraction — `fake` for dev/test, `http` for production (API.md §10).
- No WebSocket/Pusher anywhere — scans are consumed via **long polling** (`GET /api/scans/{id}?wait=`, API.md §4.3).

## Feature map (SRS)

| Area | Highlights |
| --- | --- |
| Auth (FR-01..03) | register/login/logout (Sanctum tokens), Google OAuth, forgot/reset password; admin web login + email-OTP password reset |
| Fish scan (FR-06) | multi-image upload, queue job → ML driver, long-polling result fetch, confidence + `low` flag, email + in-app notification |
| Encyclopedia (FR-04) | diseases with symptoms/treatments/medicines/preventions, search, fish-type filter |
| Forum (FR-05) | posts, comments, replies, likes, mentions, reports, moderation |
| Dashboard & outbreak (FR-07) | stats, `outbreaks:aggregate` daily job, read-only `GET /api/outbreaks` feed |
| Notifications (FR-08) | 7 types (`scan_result`, `forum_reply`, `mention`, `outbreak`, `tips`, `security`, `weekly`), per-type in-app/email settings, weekly digest cron, signed one-click unsubscribe (API.md §8.8) |
| Admin (FR-09) | users, diseases, tips, help/FAQ, app-info/APK, ML datasets, audit logs — roles `superadmin`/`admin`/`user` |

Response envelope everywhere: `{success, message, data[, meta|errors]}` (API.md §2).

## Requirements

- PHP 8.3+ with `pdo_mysql`/`pdo_sqlite`, `mbstring`, `openssl`, `zip`, `gd`
- Composer 2
- MySQL 8 (production) — tests run on in-memory SQLite
- Redis (queue/cache in production; tests use `array`)
- Node 20+ (only for building admin assets: Vite + Tailwind 4)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # DatabaseSeeder → HelpSeeder + DiseaseSeeder (+ UserSeeder bila bukan production)
npm install && npm run build    # admin panel assets
php artisan serve               # API + admin panel
php artisan queue:listen        # scan jobs (ProcessScanJob)
```

Or run the bundled pipeline: `composer setup` (install, env, key, migrate, npm build).

> Local `.env` should keep `ML_DRIVER=fake` (no Python needed) and may use
> `DB_CONNECTION=sqlite` + `QUEUE_CONNECTION=sync` for quick development.
> phpunit always overrides to SQLite `:memory:` + `array` mail/queue/cache.

### Seeded accounts

`php artisan db:seed` creates two kinds of data:

| Seeder | Content |
| --- | --- |
| `HelpSeeder` | FAQ + help contacts |
| `DiseaseSeeder` | 11 diseases — the exact output classes of the ViT model, in class-index order (FR-10.11), incl. fish types, treatments & preventions |
| `UserSeeder` | demo logins — **only when `APP_ENV` is not `production`** |

Demo accounts (password `password`): `superadmin@ngingu.id`, `admin@ngingu.id`, `budi@ngingu.id`, `sari@ngingu.id`, `joko@ngingu.id`.

No account is created in production. To promote your own account instead:

```bash
php artisan tinker
>>> App\Models\User::where('email', 'you@example.com')->update(['role' => 'superadmin', 'is_active' => 1])
```

Then open `/admin` — access requires role `admin` or `superadmin` (default guard).

### Keeping `diseases` in sync with the model (FR-10.11)

`ml-service/label_map.json` is the source of truth for the model's class order
(index `0..10`). `DiseaseSeeder` seeds the matching rows, then you push the
resulting ids back into the label map:

```bash
php artisan ml:sync-label-map           # write disease_id into ml-service/label_map.json
php artisan ml:sync-label-map --check   # verify only; exit 1 when out of sync (CI)
```

The path defaults to `<repo>/ml-service/label_map.json` and can be overridden
with `ML_LABEL_MAP`. `GET /diseases` on the ML service exposes these ids
(API.md §10.3).

## ML service (driver)

| Env | Meaning |
| --- | --- |
| `ML_DRIVER=fake` | built-in fake predictor (randomised, no Python service) — development & tests only |
| `ML_DRIVER=http` | POSTs to the Python FastAPI service at `ML_BASE_URL` (API.md §10) |
| `ML_BASE_URL=http://127.0.0.1:8009` | ML service address — **8009**, because `run_dev.ps1` already owns 8000. See `ml-service/README.md` for running it. |

### Test a prediction request from the admin panel

Admin → **Model ML & Dataset** has a *Test Permintaan ML* panel: pick a photo (optional
`fish_type`, optional GradCAM) and it performs the exact multipart `POST /predict` the
app would make, then shows four layers — the request, the raw JSON from Python, the
normalized result the app uses (`MlPredictionNormalizer`), and whether the returned
slug resolves to a row in `diseases` (FR-10.11). Failures are displayed, never thrown,
so a stopped service or a `422 IMAGE_QUALITY_LOW` is visible in the panel.
*Cek Status Service* runs `GET /health` alone.

With `ML_DRIVER=fake` a banner reminds you the photo never leaves the backend.
Routes: `POST /admin/models/test`, `GET /admin/models/status` (both admin-only).
| `ML_TIMEOUT=30` | hard ML call limit in seconds |
| `SCAN_WAIT_DEFAULT=120` / `SCAN_WAIT_MAX=300` | long-polling wait ceilings (API.md §4.3) |
| `LOW_CONFIDENCE_THRESHOLD=60` | predictions below this return `confidence_level: "low"` |

### Server timeouts for 300 s long polling (required in production)

- `php-fpm`: `max_execution_time = 0` (or ≥ 310), `request_terminate_timeout ≥ 310s`
- nginx: `fastcgi_read_timeout` / `proxy_read_timeout ≥ 310s`
- Flutter Dio: `connectTimeout`/`receiveTimeout ≥ 320000` ms

## Scheduled tasks (Asia/Jakarta)

Enable the scheduler with `* * * * * php artisan schedule:run` in cron. Registered commands (`php artisan schedule:list`):

| When | Command | Purpose |
| --- | --- | --- |
| Daily 07:00 | `php artisan outbreaks:aggregate` | roll up scan counts into outbreak areas |
| Monday 07:00 | `php artisan digest:weekly` | weekly digest email/in-app (`weekly` type, API.md §8.1) |

## Tests & code style

```bash
php artisan test              # 190 tests (or: php vendor\phpunit\phpunit\phpunit)
vendor\bin\pint               # code style (Laravel Pint) — run with --dirty for changed files
php artisan migrate:fresh     # reset the local database
```

Conventions enforced by the suite: JSON envelope on every endpoint, rate limiters
(`login` 5/min, `register` 3/hr, `scan` 10/hr, `forum-post` 10/hr), guest-first ordering
before `actingAs()` in a test, `assertSame(0/1, ...)` for the non-cast `is_active` column.

## Documentation

- [API.md](../API.md) — full HTTP contract (v1.0.2), including §8 notifications, §10 ML service
- [SRS.md](../SRS.md) — software requirements specification (v1.0.4)

## Security

`composer audit` reports **no known advisories** (the `league/commonmark` high-severity
advisories were resolved by upgrading to ≥ 2.10.3). Report vulnerabilities privately to the
maintainers, not through the public issue tracker.
