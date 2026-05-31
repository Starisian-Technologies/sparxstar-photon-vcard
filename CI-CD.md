# CI/CD and Release Operations

## Workflows

- `.github/workflows/lint.yml`
  - Runs on push and pull request.
  - Executes PHPCS, PHPStan, ESLint, and Stylelint.
  - Must pass before merge.

- `.github/workflows/build-release.yml`
  - Runs only on semver tags matching `v*.*.*`.
  - Updates version markers, builds minified assets, creates plugin ZIP, computes SHA256, and publishes a GitHub Release.

## Required local validation before PR

Run:

```bash
npm test
```

This must pass before requesting review.

## Release procedure

1. Ensure `main` is green.
2. Create and push a semver tag: `vX.Y.Z`.
3. Verify release workflow success.
4. Verify generated archive and SHA256 checksum in the GitHub Release.
5. Deploy to staging first, then production.

## Rollback procedure

If release validation fails in staging or production:

1. Revert plugin deployment to prior known-good release ZIP.
2. Clear relevant caches (edge/proxy/object cache) to remove mixed-asset state.
3. Confirm card rendering, triggers, and vCard generation on a smoke test page.
4. Create a regression issue with the failing release version and impacted area.

## Deployment safety requirements

- Never deploy from an untagged commit.
- Never bypass required CI checks.
- Never include secrets in workflows, logs, or release artifacts.
- Treat security disclosures via `SECURITY.md`, not public issue details.
