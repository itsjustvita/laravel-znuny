## What and why

<!-- What does this change, and what was wrong or missing before? -->

## Checks

- [ ] A test fails without this change and passes with it
- [ ] `vendor/bin/phpunit`, `composer analyse` and `vendor/bin/pint --test` pass
- [ ] If it changes how the package talks to Znuny: an integration test, or a note why none is needed
- [ ] CHANGELOG.md has an entry under "Unreleased"
- [ ] If it breaks existing code: UPGRADE.md says how to adapt
- [ ] A new class is listed in `PublicApiTest` and the README's Stability section, or marked `@internal`
