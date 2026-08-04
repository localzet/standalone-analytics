# Localzet Personal Analytics for PHP

Framework-neutral, single-project analytics collector and reporting library for PHP 8.2+ and MySQL 8.

```bash
composer require localzet/personal-analytics
```

Apply [`database/mysql.sql`](database/mysql.sql), create a PDO connection and construct the analytics facade:

```php
use Localzet\PersonalAnalytics\Analytics;
use Localzet\PersonalAnalytics\Config;

$analytics = new Analytics($pdo, new Config(
    projectToken: $_ENV['ANALYTICS_TOKEN'],
    allowedHost: 'app.example.com',
    allowedOrigins: ['https://app.example.com'],
));
```

Route collector JSON to `$analytics->collector->collectJson()`. Protect reporting endpoints with the host application's authentication and authorization. A complete minimal adapter is available in [`../examples/plain-php`](../examples/plain-php).

The package stores lifecycle events, safe interactions, UTM attribution, goals, heatmaps and compressed replay chunks. Sensitive values must never be passed as custom properties; the collector also redacts common sensitive keys.

## License

Copyright (C) 2025-2026 [Zorin Projects S.P.](https://zorin.space). Created and maintained by [Ivan Zorin](mailto:creator@localzet.com).

Licensed under [`AGPL-3.0-or-later`](LICENSE). Preserve the included [`NOTICE`](NOTICE) when redistributing the package.
