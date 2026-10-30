# ERICA-S-BABY-CARE

**Diapers & Wipes 360 — ERICA'S BABY CARE**, Hansua–Techiman.

## The app (Release 1, works today)

`index.html` is the complete working shop app in one file: POS, stock with expiry, wholesale credit, customers with consent, MoMo verification, cash shifts, dashboard, flyers, settings and a tamper-evident audit log. It works offline and keeps its data in the browser of the device it is used on.

### Put it online with GitHub Pages

1. On GitHub open **Settings → Pages** and set **Source** to **GitHub Actions**.
2. Push to `main` (or run the **deploy-app** workflow from the **Actions** tab). The workflow `.github/workflows/pages.yml` publishes only `index.html`.
3. The app is then at **https://psobeng.github.io/ERICA-S-BABY-CARE/**.

Each device keeps its own data: download a backup daily from **Settings & audit → Backup & data**. Sign in first time as Erica with PIN `1234`, then change every PIN in **Staff & PINs** and clear the sample data before real use.

## The full system (Laravel + React)


Production codebase for Release 1 of the PRD v3.1 (shop core: POS, stock with expiry, wholesale credit, consent-aware customers, manual MoMo verification, cash shifts, audit, offline sync).

```
apps/api   Laravel 13 API (PHP 8.3+, PostgreSQL 16, Redis)   — business rules, database protections
apps/web   React + TypeScript PWA (Vite)                      — POS with signed offline queue
docs/      ADRs and runbooks · SECURITY.md · .github/ (CI, CODEOWNERS, Dependabot)
```

## What is verified already

| Check | Result |
|---|---|
| Domain rules (money, Ghana phones, tier pricing, FEFO, WAC, receivables, hash chain) — `php apps/api/tests/Standalone/run.php` | 36/36 pass |
| PostgreSQL triggers and constraints from the migrations, on a real PostgreSQL 16 | 15/15 behave as designed (11 blocked, 4 allowed) |
| Web: typecheck, 11 Vitest tests, production build | pass · 104 KB gzipped JS |
| Offline signature made by WebCrypto verifies in PHP; edited payload rejected | pass |
| Laravel feature tests (`tests/Feature/R1AcceptanceTest.php`, 16 cases) | **run them after `composer install`** — Packagist was not reachable from the build workspace |

## Run it locally

```bash
# API
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
# create the PostgreSQL database and users named in .env, then:
php artisan migrate --seed          # seed = clearly marked SAMPLE data; replace before go-live
php artisan test                    # R1 acceptance tests
php artisan serve                   # http://localhost:8000

# Web
cd ../web
npm ci
VITE_API_BASE=http://localhost:8000/api/v1 npm run dev
```

Sample staff (seed only): owner@example.test / change-me-1234 (PIN 1234), manager… (2222), cashier… (3333). Change them immediately.

## Before go-live

Follow the R1 gate in PRD B17 and the GitHub settings table in `SECURITY.md` (branch rules, signed commits, CODEOWNERS, hardware keys, protected `production` environment). Replace sample data with the owner's verified catalogue, costs, prices and expiry dates. Register with the Data Protection Commission.

## Not in this release (by design, per the PRD roadmap)

R2: flyer templates rendered on the server, WhatsApp Cloud API + SMS adapters, campaign builder, expenses/net profit. R3: gateway payments (Hubtel / Paystack / MTN MoMo API) with webhooks and reconciliation. The schema and outbox events are already in place for them.
