# @localzet/personal-analytics-tracker

Browser half of Localzet Personal Analytics.

```bash
npm install @localzet/personal-analytics-tracker
```

```ts
import { createAnalytics } from '@localzet/personal-analytics-tracker';

const analytics = createAnalytics({
  endpoint: '/analytics/collect.php',
  projectToken: 'public-project-token',
  ignoreDnt: true,
  trackActions: true,
  recordReplays: true,
});

await analytics.start();
await analytics.goal('paid-order');
analytics.track('pricing_calculator_used', { plan: 'business' });
analytics.identify(String(user.id), { segment: 'customer' });
analytics.ecommerce('purchase', { orderId: 'A-42', revenue: 1990, currency: 'RUB' });
```

The recorder masks all inputs by default. Add `.pa-block` to elements that must be completely excluded from replay recording. Call `analytics.optOut()` to persistently stop tracking and remove analytics identities; call `optIn()` to enable it again.

Autocapture covers clicks, rage clicks, scroll depth, forms, field interaction metadata, selection/clipboard actions, media, pointer samples, DOM mutations, fetch/XHR timing, JavaScript/resource errors, engagement and Core Web Vitals. Request/field bodies and sensitive values are never recorded.

## License

Copyright (C) 2025-2026 [Zorin Projects S.P.](https://localzet.com). Created and maintained by [Ivan Zorin](mailto:creator@localzet.com).

Licensed under [`AGPL-3.0-or-later`](LICENSE). Preserve the included [`NOTICE`](NOTICE) when redistributing the package. rrweb and other dependencies retain their respective licenses.
