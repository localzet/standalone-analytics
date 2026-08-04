# Localzet Laravel Personal Analytics

Single-project analytics integration for Laravel 13 and PostgreSQL. It deliberately contains no tenants, sites, billing or user management.

## Install

```bash
composer require localzet/laravel-personal-analytics
php artisan vendor:publish --tag=personal-analytics-config
php artisan migrate
```

Configure the collector and run a dedicated queue worker plus Laravel scheduler:

```dotenv
PERSONAL_ANALYTICS_TOKEN=replace-with-a-long-random-public-token
PERSONAL_ANALYTICS_HOST=app.example.com
PERSONAL_ANALYTICS_ORIGINS=https://app.example.com
PERSONAL_ANALYTICS_DB_CONNECTION=pgsql
PERSONAL_ANALYTICS_QUEUE_CONNECTION=redis
PERSONAL_ANALYTICS_QUEUE=analytics
PERSONAL_ANALYTICS_STORAGE_DISK=s3
PERSONAL_ANALYTICS_MAX_PAYLOAD_BYTES=8388608
PERSONAL_ANALYTICS_MAX_DECODED_PAYLOAD_BYTES=33554432
PERSONAL_ANALYTICS_STORE_IP=false
```

```bash
php artisan queue:work redis --queue=analytics
php artisan schedule:work
```

Install `@localzet/personal-analytics-tracker` in the frontend and point `endpoint` to `/api/analytics/collect`. The public collector accepts bounded JSON batches and returns `202`; queue jobs project visitor/session state and bulk-insert event facts.

The full analytics console is available at `/admin/analytics`. It includes overview charts, realtime activity, raw event drill-down, sessions, visitors, attribution, client errors, performance telemetry, playable rrweb replays, heatmaps, goals, funnels, ecommerce and pipeline health.

By default, the console is protected by the host application's `web` and `auth` middleware. For a standalone token-protected console, use a separate random secret:

```dotenv
PERSONAL_ANALYTICS_ADMIN_AUTH_MODE=token
PERSONAL_ANALYTICS_ADMIN_TOKEN=replace-with-a-separate-256-bit-secret
PERSONAL_ANALYTICS_ADMIN_MIDDLEWARE=web
```

Never reuse `PERSONAL_ANALYTICS_TOKEN` for admin access. It is a public ingestion identifier embedded in frontend JavaScript and cannot protect analytics data. Token mode sends the separate admin secret in the `Authorization: Bearer` header and retains it only in browser `sessionStorage`.

The browser tracker and Laravel package should be upgraded together. Tracker 0.2 compresses large replay and heatmap payloads before transport; Laravel 0.2 safely decompresses them with independent compressed and decoded size limits.

## Storage model

- PostgreSQL monthly partitions for raw events, with BRIN/B-tree/GIN indexes;
- visitor/session projections and daily multidimensional rollups;
- goals, conversions and configurable funnels;
- gzip replay chunks and heatmap DOM snapshots on any Laravel Filesystem disk;
- retention and future-partition maintenance via scheduled commands.

The collector never stores form/request bodies. Input values are masked by rrweb, sensitive property keys are redacted on both client and server, and IP persistence is disabled by default.

## License

Copyright (C) 2025-2026 [Zorin Projects S.P.](https://zorin.space). Created and maintained by [Ivan Zorin](mailto:creator@localzet.com).

Licensed under [`AGPL-3.0-or-later`](LICENSE). Preserve the included [`NOTICE`](NOTICE) when redistributing the package.
