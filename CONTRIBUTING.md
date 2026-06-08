# Contributing to Laravel Kronos

Thank you for considering a contribution to Kronos. This document outlines the process and standards for contributing.

---

## Code of Conduct

This project follows the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/) Code of Conduct. By participating, you are expected to uphold this standard.

---

## How to Contribute

### Reporting Bugs

Before opening an issue, please:

1. Search existing [issues](https://github.com/zuqongtech/laravel-kronos/issues) to avoid duplicates.
2. Include your PHP version, Laravel version, and Kronos version.
3. Provide a minimal reproduction — a failing test is ideal.

### Suggesting Features

Open a [GitHub Discussion](https://github.com/zuqongtech/laravel-kronos/discussions) before submitting a feature PR. Aligning on design upfront avoids wasted effort.

---

## Development Setup

```bash
git clone https://github.com/zuqongtech/laravel-kronos.git
cd laravel-kronos
composer install
```

Run the test suite:

```bash
./vendor/bin/pest
```

Run with coverage:

```bash
./vendor/bin/pest --coverage
```

---

## Coding Standards

- **PSR-12** code style enforced via [Laravel Pint](https://laravel.com/docs/pint).
- **PHPStan** level 8 static analysis.
- **PHP 8.2+** minimum — use enums, readonly properties, and named arguments freely.

Run fixers before committing:

```bash
./vendor/bin/pint
./vendor/bin/phpstan analyse
```

---

## Pull Request Guidelines

1. **Branch from `main`** — name your branch `feature/short-description` or `fix/short-description`.
2. **One PR per concern** — keep scope tight.
3. **Tests required** — all new behaviour must be covered by PestPHP tests.
4. **Update CHANGELOG.md** under `[Unreleased]`.
5. **Update documentation** in `README.md` if your change affects the public API.
6. **Squash commits** before requesting review — keep history clean.

---

## Running the Full Quality Suite

```bash
composer test          # pest
composer analyse       # phpstan
composer format        # pint
composer format:check  # pint --test (CI mode)
```

These are defined in `composer.json` under `scripts`.

---

## Commit Message Format

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add webhook trigger support
fix: prevent double dispatch on parallel step advance
docs: document WorkflowContext API
test: add DAGResolver cycle detection test
chore: upgrade symfony/yaml to ^7.1
```

---

## Security Vulnerabilities

Please **do not** open a public issue for security vulnerabilities. Email `security@zuqongtech.com` directly. See [SECURITY.md](SECURITY.md) for the full policy.