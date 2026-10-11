# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0]

Works out of the box with the `GenericTicketConnectorREST` example web service that Znuny ships. See [UPGRADE.md](UPGRADE.md).

### Added

- Configurable REST routes per connection: presets `generic-ticket-connector` (default, the example web service Znuny ships), `generic-ticket-connector-classic` (the older development example) and `operation-name` (0.1 behaviour), `extends` overrides and a `POST /<Operation>` fallback.
- `Znuny::call()` for any operation, `Znuny::build()` for runtime connections, `$options` for `usingCredentials()`.
- Authentication modes `session` and `credentials`, agent or customer users, credentials via `X-OTRS-Header-*` headers.
- `tickets()->findMany()` with batched `TicketGet`, `tickets()->history()`, attachments for new tickets, articles and reads, dynamic fields in `update()`, `addArticle()` returns the article ID.
- Search: `whereChangedAfter()`, `whereChangedBefore()`, `whereDynamicField()`, `withAttachments()`, `DateTimeInterface` arguments, `asc`/`desc`.
- `attributes` with all raw Znuny fields on every DTO; more ticket and article fields; `Attachment` and `HistoryEntry` DTOs.
- Webhook endpoint with token or Basic authentication, `ZnunyWebhookReceived` event and `znuny.webhook` middleware.
- `znuny:routes` command; `znuny:test-connection` also checks routing with a `TicketSearch`.
- `ZnunyConfigurationException`, `ZnunyRouteNotFoundException`, `ZnunyAccessDeniedException` and `getHttpStatus()` on all exceptions.
- Dynamic field types `float` and `array`.
- CI for PHP 8.3–8.5 with Laravel 12 and 13, `LICENSE.md`, opt-in integration suite against Znuny in Docker.

### Changed

- **Breaking:** the default routes follow the `GenericTicketConnectorREST` example web service that Znuny ships.
- Errors are mapped from Znuny's error codes; HTTP errors without a Znuny error body throw instead of returning an empty array.
- Secrets are redacted the same way in events, logs and exceptions; `logging.log_responses` is implemented.
- `ZnunySessionRefreshed` is dispatched and carries only the connection name.

### Removed

- **Breaking:** `QueueResource::resolve()` and the `queues` config block.
- `http.pool_size`, `cache.ttl.services`, the webhook stub and `WebhookEventMapper`.

### Security

- Cached sessions and lookups are keyed by target and credentials, so a runtime connection that reuses a connection name can no longer pick up (and send elsewhere) another connection's session ID or serve another system's queues.
- Route placeholders reject `.` and `..`, which servers would normalise into another route.
- Session IDs and credentials are kept out of `ZnunyResponseReceived`, exception response bodies, transport error messages and logged paths.

### Fixed

- Without a `znuny` log channel, Laravel wrote an emergency log entry on every request; the package now registers the channel as a daily `storage/logs/znuny.log` file unless the application defines it.
- HTTP retries repeated writes after timeouts and server errors, which could create duplicate tickets when Znuny answered slowly; writes are now only repeated when no connection could be established.
- `get()`, `paginate()` and `lazy()` sent one `TicketGet` per ticket; they use batches again.
- The dynamic field validator rejected the `float` type that the generator supports.
- Missing `illuminate/pagination`, `illuminate/routing` and `illuminate/log` dependencies.

## [0.1.2] - 2026-04-24

### Fixed

- Session-expired detection for `AuthFail` errors and the `TicketGet` route with ticket ID.

## [0.1.1] - 2026-04-13

### Fixed

- `znuny:test-connection` only calls `SessionCreate`; unique cache keys for runtime credentials; `CustomerUserID` read/write mapping; configurable article and dynamic field loading in the search builder; facade annotations.

## [0.1.0] - 2026-04-10

- Initial release.

[Unreleased]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/itsjustvita/laravel-znuny/releases/tag/v0.1.0
