# Contributing

Thank you for helping. This page says what belongs in the package and how to work on it.

## What belongs in the package

The package has a typed method for an operation if, and only if, Znuny ships that operation. That rule decides most feature requests:

- An operation of Znuny's core, or of an add-on that the Znuny project publishes, can get a method, a data object and tests.
- An operation that only exists in one installation stays in that application. `Znuny::call('YourOperation', [...])` reaches it with the same routing, authentication, error handling, events and logging.
- A workaround for something Znuny does not offer belongs in the application that needs it.

If you are unsure, open an issue before you write code.

## Setup

```bash
composer install
```

## Checks

Every pull request has to pass these; CI runs them on PHP 8.3 to 8.5 and Laravel 12 and 13:

```bash
vendor/bin/phpunit        # unit and feature tests
composer analyse          # PHPStan, level 8, no baseline
vendor/bin/pint           # code style
```

## The integration suite

The package is tested against real Znuny instances, versions 6.5 to 7.3. A change to how the package talks to Znuny needs a test there. `tests/Integration/README.md` explains how to start Znuny in Docker and run the suite; CI runs it on every pull request that touches the code.

Claims about Znuny's behaviour should rest on Znuny's source or on a test against a real instance, and say which.

## How to change things

- **A test first.** Write the test, watch it fail for the right reason, then make it pass. A bug fix starts with a test that reproduces the bug.
- **No silent changes.** If behaviour changes, the CHANGELOG says so. A breaking change goes into a major version and into `UPGRADE.md`, with the old and the new behaviour. The README's "Stability" section says what counts as breaking.
- **New classes are public or internal.** `tests/Unit/PublicApiTest.php` fails for a class that is neither listed there nor marked `@internal`. Prefer `@internal`: a public class is a promise. The same test lists the public members of public classes that are `@internal`. Putting the marker on a member that was covered takes it out of the promise, which needs a major version.
- **Secrets stay out of logs, events and exceptions.** If you add something that carries credentials or session data, add a test that it is redacted. A parameter that carries a password, a session ID, connection settings or a request gets `#[\SensitiveParameter]`, so that it does not show in stack traces; `tests/Feature/SecretsInStackTracesTest.php` checks the traces of the package's failures.
- **English** in code, comments, commit messages and documentation.

## Security

Please do not report a vulnerability in a public issue. See [SECURITY.md](SECURITY.md).
