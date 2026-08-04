# Localzet Personal Analytics

[![License: AGPL v3 or later](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](php/composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20.svg)](laravel/composer.json)
[![Node.js](https://img.shields.io/badge/Node.js-20%2B-339933.svg)](tracker/package.json)

Standalone, single-project analytics extracted from the Localzet Analytics tracking model. The folder is intentionally a small integration kit rather than another SaaS application.

It contains:

- `laravel/` — production-oriented Laravel 13 adapter with PostgreSQL partitions, queues, rollups, S3-compatible replay/heatmap storage and a complete protected analytics console;
- `php/` — framework-agnostic MySQL/PDO ingestion, goals and reports for small non-Laravel integrations;
- `tracker/` — TypeScript browser SDK with autocapture, attribution, product/ecommerce events, diagnostics, heatmaps and rrweb recording;
- `examples/plain-php/` — minimal collector/reporting endpoints and an admin page;
- `docs/architecture.md` — extraction decisions and a mapping from the original monolith.

Release preparation for Packagist and npm is documented in [`docs/publishing.md`](docs/publishing.md).

## Packages

The three packages are independently publishable:

```bash
cd php
composer install

cd ../laravel
composer install

cd ../tracker
npm install
npm run build
```

Apply `php/database/mysql.sql`, construct `Localzet\PersonalAnalytics\Analytics`, and route JSON requests to `Collector::collect()`. See `examples/plain-php` for a complete adapter.

Browser integration:

```ts
import { createAnalytics } from '@localzet/personal-analytics-tracker';

const analytics = createAnalytics({
  endpoint: 'https://admin.example.test/analytics/collect.php',
  projectToken: 'replace-with-a-random-public-token',
  ignoreDnt: true,
  autocapture: true,
  trackPerformance: true,
  trackErrors: true,
  trackNetwork: true,
  recordReplays: true,
  replaySampleRate: 0.25,
  goals: [{ key: 'checkout', type: 'pageview', path: '/checkout/success' }],
  heatmaps: [{ key: 'home', path: '/' }],
});

analytics.start();
analytics.goal('signup');
analytics.track('pricing_calculator_used', { plan: 'business' });
analytics.ecommerce('purchase', { orderId: 'A-42', revenue: 1990, currency: 'RUB' });
```

`ignoreDnt` is explicit. Without it, the tracker does not start when DNT is enabled. Input values are masked in rrweb; request bodies, form values, credentials and payment data are never collected. Sensitive keys are redacted before transport and again on the server.

## Scope

This kit has exactly one analytics project per installation. It has no users, websites, teams, plans, billing or quotas. The console can use the host application's Laravel authentication or its own independent admin token. The public project token identifies traffic but is never accepted as an admin secret; ingestion is additionally restricted by the configured host/origin.

## License

Copyright (C) 2025-2026 [Zorin Projects S.P.](https://zorin.space). Created and maintained by [Ivan Zorin](mailto:creator@localzet.com).

Licensed under [`AGPL-3.0-or-later`](LICENSE). When redistributing the project or substantial portions of it, keep the copyright, license and attribution notices, including the link to the [original repository](https://github.com/localzet/standalone-analytics). See [`NOTICE`](NOTICE).

rrweb and other third-party dependencies are distributed under their respective licenses.
