# Extraction architecture

## Original data path

The monolith renders a per-website script in `Pixel`, receives events in `PixelTrack`, stores the normal tracking graph in visitors → sessions → page events → child actions, stores goals as conversions, heatmap snapshots as compressed rrweb events, and replay metadata in MySQL while replay bodies live in an internal cache filesystem. Reporting controllers then query these tables directly.

The behavior worth retaining is:

- stable visitor identity in local storage and a 30-minute session window;
- landing/pageview distinction, bounce updates, referrer and UTM attribution;
- click, scroll, form-submit and viewport-resize actions;
- pageview and custom goals;
- one rrweb full snapshot per heatmap/device and click/scroll aggregation;
- chunked, masked rrweb session recording;
- DNT detection with an explicit integration-level override;
- query-string tracking as an opt-in.

## Removed SaaS concerns

`user_id`, `website_id`, website selection, plans, monthly usage counters, teams, domains, billing, per-plan retention and feature gates are not present. A deployment owns one project and one allowed host.

## Package boundary

The tracker owns browser identity, lifecycle observation, batching and privacy masking. It emits a versioned event envelope and has no knowledge of PHP.

The PHP package owns portable validation, MySQL/PDO persistence and simple read models. The Laravel package is the high-volume implementation: queue-backed ingestion, PostgreSQL partitions and JSONB/BRIN/GIN indexes, scheduled rollups, object storage and authenticated reporting endpoints.

The host application owns routing, authentication, authorization, rate limiting at the edge, trusted-proxy configuration and admin-page composition.

GeoIP and user-agent parsing are optional host enrichments exposed through `RequestContext`; the package does not redistribute a GeoLite database or force a parser dependency. Country, city, OS and browser dimensions become available as soon as the host supplies them.

## Storage changes

Site/user columns and duplicated goal-id arrays are removed. PostgreSQL stores compact UUID facts in monthly range partitions and derives daily multidimensional rollups. Replay chunks and heatmap DOM snapshots are gzip-compressed in a Laravel Filesystem disk, while PostgreSQL stores searchable metadata. The disk can be local, S3 or any compatible driver.

## Security and privacy defaults

- Collector requests are JSON and bounded by a configurable byte limit.
- Event names, UUIDs, URLs, dimensions, counts and nested payload shapes are validated.
- The URL host and optional `Origin` header must match configuration.
- Duplicate page events and replay chunks are idempotent.
- rrweb masks all inputs; password fields and elements marked with `.pa-block` are blocked.
- Action events contain element metadata, coordinates and a bounded selector, never input values.
- IP storage is disabled by default; when enabled, the host must pass a validated client IP.
- Admin read APIs are deliberately separate from public collection and must be authenticated by the host.

## Collection and analysis coverage

The generic event contract covers interactions, navigation, engagement, performance, errors, network diagnostics, ecommerce and application-defined events. Autocapture includes clicks and rage clicks, scroll depth, pointer samples, safe form metadata, media, selection/clipboard activity, DOM mutations, SPA navigation, fetch/XHR timing, resource errors, Core Web Vitals and long tasks. Attribution persists first and last touch UTM/referrer/ad click IDs. Reports expose real-time activity, journeys, funnels, revenue, goals, technology, geography, replays and heatmaps.

## Scaling boundary

The framework-neutral MySQL package is appropriate at moderate volume. Laravel/PostgreSQL is the preferred high-volume path. If aggregate workloads outgrow PostgreSQL, the versioned browser contract and queue boundary allow raw facts to be mirrored into ClickHouse without changing the tracker.
