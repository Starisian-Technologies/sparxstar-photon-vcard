# Contributing to SPARXSTAR Photon VCard

Thank you for your interest in contributing to **SPARXSTAR Photon VCard**.

This is a **proprietary, commercially licensed** project owned and maintained by [Starisian Technologies](https://starisian.com). Contributions are managed internally. If you are an external party with a bug report, security disclosure, or feature request, please follow the guidance below.

---

## Reporting Bugs

Open a [GitHub Issue](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/issues/new/choose) using the **Bug Report** template (`bug_report.yml`) and include:

- Steps to reproduce
- Expected vs. actual behaviour
- PHP version, WordPress version, and active theme/plugins
- Browser and device (if a front-end issue)
- Error log excerpts if available

Do **not** include credentials, user data, or production URLs in public issues.

For commercial support and non-security operational assistance, see [SUPPORT.md](SUPPORT.md).

---

## Reporting Security Vulnerabilities

**Do not open a public issue for security vulnerabilities.**

The repository issue-creation page does not provide a public security form. Instead, the `.github/ISSUE_TEMPLATE/config.yml` configuration disables blank issues and provides a **contact link** that routes directly to [SECURITY.md](SECURITY.md), where the coordinated disclosure process is described.

Please follow that process for all security disclosures. Do not attempt to work around the issue template controls.

---

## Requesting Features

Open a [GitHub Issue](https://github.com/Starisian-Technologies/sparxstar-photon-vcard/issues/new/choose) using the **Feature Request** template (`feature_request.yml`).

Describe:

- The problem you are trying to solve
- Your proposed solution (if any)
- Any constraints or compatibility concerns

Feature requests are reviewed against the project roadmap by the Starisian Technologies core team.

---

## Internal Contributor Workflow

> The following section applies to Starisian Technologies engineers and authorised contributors only.

### Environment Setup

See [DEVELOPMENT.md](DEVELOPMENT.md) for the complete local setup guide.
See [CI-CD.md](CI-CD.md) for CI gates, release flow, and rollback expectations.

### Branch Naming

| Type | Pattern | Example |
|------|---------|---------|
| Feature | `feature/<slug>` | `feature/social-matrix` |
| Bug fix | `fix/<slug>` | `fix/shake-threshold` |
| Chore / docs | `chore/<slug>` | `chore/update-readme` |
| Release prep | `release/v<semver>` | `release/v0.6.0` |

Work in a feature branch; never commit directly to `main`.

### Commit Messages

Use the [Conventional Commits](https://www.conventionalcommits.org/) format:

```
<type>(<scope>): <short summary>
```

Types: `feat`, `fix`, `docs`, `chore`, `refactor`, `perf`, `test`, `ci`, `style`.

Examples:
```
feat(pwa): add offline precache for card assets
fix(sensors): lower face-down threshold from 155° to 145°
docs(readme): fix author attribution typo
```

### Pull Requests

1. Branch from `main`.
2. Keep PRs focused — one logical change per PR.
3. Ensure all lint and static-analysis checks pass locally before opening the PR:
   ```bash
   npm test
   ```
4. Fill in the PR template completely.
5. Request review from at least one core team member.
6. Do not merge without an approved review.

### Code Standards

All code must satisfy:

- **PHP**: PHPCS (WordPress VIP + PSR-12), PHPStan Level 5+
- **JavaScript**: ESLint (project config in `eslint.config.js`)
- **CSS**: Stylelint (project config in `.stylelintrc.json`)

See [DEVELOPMENT.md](DEVELOPMENT.md) for lint and fix commands.

### Testing

There is currently no automated PHP unit-test suite for this plugin; the test command (`npm test`) runs linters and static analysis. Manual regression testing against a local WordPress installation is required for functional changes.

When a formal test suite is introduced, all new PHP code must include PHPUnit tests and all new JavaScript must include Jest tests.

### Documentation

- All public PHP classes, methods, constants, filters, and actions must have PHPDoc blocks.
- All exported JavaScript symbols must have JSDoc comments.
- Architectural changes must be reflected in [ARCHITECTURE.md](ARCHITECTURE.md).
- User-facing feature changes must be recorded in [CHANGELOG.md](CHANGELOG.md).

---

## Code of Conduct

All contributors are expected to follow the project [Code of Conduct](CODE_OF_CONDUCT.md).

---

## License

By contributing to this project you agree that your contributions will be licensed under the [Starisian Technologies Proprietary License](LICENSE.md). You retain no ownership over merged contributions.
