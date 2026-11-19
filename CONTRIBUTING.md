# Contributing

Thank you for helping. Small, focused pull requests are the easiest to review.

## Setup

```sh
git clone https://github.com/IndianOpinion/read-aloud.git
cd read-aloud
composer install
```

PHP 8.1 or later is required. Write code that runs on PHP 8.1: no readonly
classes, no DNF types, no constants in traits.

## Tests

```sh
composer test        # PHPUnit; WordPress is replaced by a stub layer in tests/stubs
composer analyse     # PHPStan level 8
composer lint        # PHP_CodeSniffer, PSR-12
composer check       # all three
```

The ledger also has integration tests against MySQL. They are skipped unless
`READ_ALOUD_TEST_MYSQL` is set, for example
`READ_ALOUD_TEST_MYSQL=127.0.0.1:3306:root:secret:test vendor/bin/phpunit --testsuite integration`.

Tests never use the network. Every behaviour change needs a test; name the
PRD case it covers in the test's docblock.

## Style

- `declare(strict_types=1);` in every PHP file.
- Typed exceptions, never silent failure, never `@`.
- Escape every output, check a capability and a nonce on every admin action.
- Plain words and British spelling in docs. No em dashes.

## Pull requests

- Branch from `main`, keep one topic per pull request.
- Update `CHANGELOG.md` under Unreleased.
- CI must be green: lint, phpcs, phpstan and phpunit on PHP 8.1, 8.2 and 8.3.
- A DCO-style sign-off is not required.
