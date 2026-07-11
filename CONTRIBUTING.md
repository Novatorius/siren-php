# Contributing

Thanks for your interest in improving the Siren SDK for PHP. Contributions of
all kinds are welcome — bug reports, fixes, docs, and features.

## Getting started

```bash
git clone https://github.com/Novatorius/siren-php.git
cd siren-php
composer install
composer test
```

The test suite mocks the HTTP layer, so it runs offline with no API key or
network access.

## Requirements

- PHP 8.1+
- Composer

Keep code compatible with PHP 8.1 — even if you develop on a newer runtime,
avoid syntax and functions introduced after 8.1.

## Pull requests

1. Fork the repository and create a topic branch from `main`.
2. Make your change, and add or update tests to cover it.
3. Run `composer test` and make sure everything is green.
4. Keep the public surface consistent with the existing style; new behaviour
   should be documented in the `README.md` where user-facing.
5. Add an entry under `[Unreleased]` in `CHANGELOG.md`.
6. Open a pull request with a clear description of the change and its
   motivation. Fill out the pull request template.

CI runs the test suite against PHP 8.1, 8.2, and 8.3 on every push and pull
request. PRs must be green before they can be merged.

## Reporting bugs and requesting features

Use the [issue tracker](https://github.com/Novatorius/siren-php/issues) and the
provided templates. For security issues, do **not** open a public issue — see
[SECURITY.md](./SECURITY.md).

## Releases and Packagist

This package is distributed on [Packagist](https://packagist.org/packages/siren/sdk).
Versions are derived from git tags — there is no `version` field in
`composer.json`. Maintainers cut a release by tagging (e.g. `0.2.0`) and pushing
the tag; Packagist auto-updates via its GitHub webhook. See
[.github/PACKAGIST.md](./.github/PACKAGIST.md) for the one-time publishing setup.

## Code of Conduct

By participating in this project you agree to abide by the
[Code of Conduct](./CODE_OF_CONDUCT.md).
