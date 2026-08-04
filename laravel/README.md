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
PERSONAL_ANALYTICS_STORE_IP=false
```

```bash
php artisan queue:work redis --queue=analytics
php artisan schedule:work
```

Install `@localzet/personal-analytics-tracker` in the frontend and point `endpoint` to `/api/analytics/collect`. The public collector accepts bounded JSON batches and returns `202`; queue jobs project visitor/session state and bulk-insert event facts.

The authenticated dashboard is available at `/admin/analytics` through the host application's `web` and `auth` middleware. Views and configuration are publishable. API reports include dashboard, realtime, journeys, replays and heatmaps.

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
