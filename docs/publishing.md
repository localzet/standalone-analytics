# Publishing and integration

Composer packages must be published from repositories or release branches whose root is the corresponding package directory. Use dedicated mirrors or `git subtree split --prefix=<package>`. npm can publish directly from `tracker/`.

## PHP / Packagist

1. Mirror `php/` to a repository root or create a split release branch.
2. Run `composer validate --strict`, `composer audit` and `composer test`.
3. Tag a SemVer release.
4. Submit that repository to Packagist as `localzet/personal-analytics`.

The consuming application installs it with:

```bash
composer require localzet/personal-analytics
```

The package is framework-agnostic and intended for small integrations.

## Laravel 13 / Packagist

Publish `laravel/` as `localzet/laravel-personal-analytics`, then install both server and browser packages:

```bash
composer require localzet/laravel-personal-analytics
npm install @localzet/personal-analytics-tracker
```

The Laravel release pipeline should run `composer validate --strict`, PHP lint/static analysis and package integration checks against Laravel 13 with PostgreSQL. Configure a real queue worker and scheduler in production; replay/heatmap storage may use an S3-compatible Laravel disk.

## JavaScript / npm

1. Enter `tracker/` or publish it from a dedicated split repository.
2. Run `npm ci`, `npm audit`, `npm run typecheck`, `npm test` and `npm run build`.
3. Review `npm pack --dry-run`.
4. Authenticate to npm and run `npm publish --access public`.

The consuming application installs it with:

```bash
npm install @localzet/personal-analytics-tracker
```

Do not use CDN `current` URLs in production. Pin exact rrweb versions in any standalone admin UI, as the example does.

## Versioning contract

The browser envelope is the integration boundary. Lifecycle/media payloads use schema version 1 and batched telemetry uses schema version 2. Before a breaking change, increment the relevant version and keep collectors backward-compatible during a deprecation window. Server and tracker versions do not otherwise need to be released in lockstep.
