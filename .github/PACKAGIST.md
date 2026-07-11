# Publishing to Packagist

This package is distributed as `siren/sdk` on
[Packagist](https://packagist.org/packages/siren/sdk). Publishing is **not** a
CI step — Packagist works by a one-time repository submission plus an
auto-update webhook. Do not add a workflow that tries to push to Packagist
directly.

## One-time setup

1. **Submit the repository.** Sign in to [packagist.org](https://packagist.org)
   with the Novatorius account, go to **Submit**, and enter the repository URL:

   ```
   https://github.com/Novatorius/siren-php
   ```

   Packagist reads `composer.json` and creates the `siren/sdk` package.

2. **Enable auto-updates.** Packagist normally offers to configure the GitHub
   integration for you during submission. If it does not, wire it manually:

   - **Preferred (GitHub App):** Install the
     [Packagist GitHub App](https://github.com/apps/packagist) on the
     `Novatorius/siren-php` repository. This keeps the package updated on every
     push and tag.
   - **Alternative (webhook):** In the repository, go to
     **Settings → Webhooks → Add webhook** and add
     `https://packagist.org/api/github?username=<packagist-username>` with your
     Packagist API token, content type `application/json`, for push events.

## Cutting a release

Versions are derived from git tags — there is deliberately **no** `version`
field in `composer.json`. To publish a new version:

```bash
git tag 0.2.0        # no leading "v"
git push origin 0.2.0
```

The webhook / GitHub App notifies Packagist, which picks up the new tag and
publishes it automatically. Update `CHANGELOG.md` before tagging.
