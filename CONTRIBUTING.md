# Contributing

Contributions are welcome through GitHub issues and pull requests.

## Development

- Keep changes focused and use Conventional Commits.
- Follow PSR-12 for PHP and the existing strict TypeScript configuration.
- Never collect passwords, authentication tokens, payment details, request bodies or raw form values.
- Preserve public APIs unless a breaking change is explicitly planned.
- Update documentation and changelog entries for user-visible changes.

Before submitting a pull request, run the relevant checks:

```bash
cd php
composer validate --strict
composer test

cd ../laravel
composer validate --strict

cd ../tracker
npm ci
npm run typecheck
npm test
npm run build
npm audit
```

By contributing, you agree that your contribution is licensed under `AGPL-3.0-or-later` and that existing copyright and attribution notices remain intact.
