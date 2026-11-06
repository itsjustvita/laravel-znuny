# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Robustness fixes. Three of them change behaviour, see [UPGRADE.md](UPGRADE.md).

### Added

- `search_limit` (`ZNUNY_SEARCH_LIMIT`, default 10 000; per connection or under `http`) and `ZnunySearchLimitExceededException`.
- `session.per_host` (`ZNUNY_SESSION_PER_HOST`, default `true`).
- `verify_ssl` accepts the path of a CA bundle.
- `ZnunyClient::sessionId()` is public, for session operations such as `SessionGet` and `SessionDelete`.
- README: troubleshooting section.

### Fixed

- Searches without `limit()` were cut off at 500 tickets without notice, because Znuny applies its `SearchLimit` when the request has no limit. `ids()`, `get()`, `paginate()` and `lazy()` now return every match up to `search_limit` and throw if more tickets match.
- Hosts that share a cache store but reach Znuny from different IP addresses invalidated each other's session with every request, because Znuny binds a session to the address that created it. Each host now keeps its own session.
- `withArticle()` and `addArticle()` failed without a `contentType`, which Znuny requires. Articles are now sent as plain text by default.
- `findMany()` sent one request per ticket when Znuny rejected a batch because of a single missing ticket. It now halves the batch until the ticket is isolated.

### Security

- `verify_ssl` values that were not booleans, such as a CA bundle path, a typo or an empty environment variable, switched certificate verification off. They now raise a `ZnunyConfigurationException`; an empty value leaves verification on.

## [0.2.1] - 2026-11-06

Fixes found by the extended integration suite, which now also covers dynamic fields, the `operation-name` preset, expired sessions, customer users, webhooks end to end, the artisan commands, Znuny's validation errors and a Znuny that does not run on UTC. It runs against Znuny 6.5 and 7.3.

**If your Znuny's `OTRSTimeZone` is not UTC, set `ZNUNY_TIMEZONE` to it when you upgrade.** This also applies if 0.2.0 worked for you because your app and Znuny use the same time zone. See [UPGRADE.md](UPGRADE.md).

### Added

- Option `timezone` (`ZNUNY_TIMEZONE`, default `UTC`): Znuny's `OTRSTimeZone`, by the name Znuny shows. It can be set globally and per connection. `ZnunyWebhookReceived::ticket()` uses it and accepts another time zone as an optional argument.
- `znuny:test-connection` fetches the newest ticket and tickets from about four and eight months ago with `TicketGet` and fails with a hint when the `timezone` setting does not match their dates.
- Search: `whereLastChangedAfter()` and `whereLastChangedBefore()` for the ticket's last change time.

### Fixed

- Date filters sent dates in the app's time zone, but Znuny reads them in its own (UTC by default). In an app ahead of UTC, `whereChangedAfter(now()->subMinutes(5))` found nothing. Dates are now converted to the configured `timezone`.
- `created`, `changed` and `createTime` of `Ticket`, `Article` and `HistoryEntry` were read in the app's time zone and are now read in the configured `timezone`. Empty dates of tickets and articles are `null` instead of the current time.
- `whereChangedBefore()` matched every ticket with any history entry before the date, which includes nearly all older tickets. It now compares the ticket's last change time.

### Changed

- README: which Znuny versions ship the Ready2Adopt example web service and how to set up older ones, the routes Znuny 6.0 and OTRS 6 need, a corrected dot-notation example, and an `addArticle()` example that Znuny accepts (it needs a `contentType`).

## [0.2.0] - 2026-10-11

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

[Unreleased]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/itsjustvita/laravel-znuny/releases/tag/v0.1.0
