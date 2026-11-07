# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] - 2026-11-07

Small fixes: a blank timeout no longer waits forever, plus four fixes from the review of 0.3.0.

### Fixed

- A blank timeout setting, such as `ZNUNY_TIMEOUT=` in `.env`, no longer means a timeout of 0, which waits forever. Blank numbers in the connection and `http` settings count as not set, so the defaults apply (30 seconds, 3 seconds to connect).
- `Znuny::fake()` applies a connection's settings as a real connection does. It used to drop null and blank values, so `'search_limit' => null` did not switch the limit off and faked searches with more than 500 tickets threw.
- `znuny:webservice` refuses text that is not valid UTF-8 and names the value, instead of writing an empty or broken value into the definition.
- `znuny:webservice --output` names the URL Znuny will send webhooks to, and the command warns when the webhook host is the machine Znuny runs on, such as `localhost` from a default `APP_URL`.
- The README says that `findByNumber()` against `Znuny::fake()` needs the faked ticket's exact ticket number.

## [0.3.0] - 2026-11-07

Convenience features and an API cleanup. Four breaking changes, each of which fails loudly; see [UPGRADE.md](UPGRADE.md).

### Added

- Search: every builder method can be called on `Znuny::tickets()` directly; named filters `whereTicketNumber()`, `whereTitle()`, `whereType()`, `whereTypeId()`, `whereStateId()`, `wherePriorityId()`, `whereOwnerId()`, `whereResponsibleId()`, `whereCustomerUser()`, `whereLock()`, `whereService()`, `whereSla()`, `whereClosedAfter()`/`Before()`, `wherePendingAfter()`/`Before()`, `whereFullText()`, `includeArchived()`, `onlyArchived()`; `count()`.
- `tickets()->findByNumber()`: an exact lookup by the ticket number customers see, safe for numbers they enter.
- Raw `TicketGet` parameters: `find(..., options: [...])`, `findMany(..., options: [...])` and `withOptions()` for searches.
- Ticket actions: `lock()`, `unlock()`, `assign()`, `move()`, `setPending()`.
- A `pendingTime` in `create()` and `update()` can be a `DateTimeInterface`; it is converted to Znuny's time zone. `Ticket::$pendingUntil`.
- `Attachment::fromUploadedFile()` and `Attachment::fromStorage()`.
- `Znuny::fake()` for application tests: responses per operation, `Znuny::fakeTickets()`, `Znuny::sequence()`, `assertCalled()`, `assertCalledTimes()`, `assertNotCalled()`, `assertNothingCalled()` and `recorded()`.
- `php artisan znuny:webservice`: generates the Znuny web service definition for a connection's routes, optionally with a webhook requester. The integration setup uses it, so CI imports generated definitions into every supported Znuny version.

### Changed

- **Breaking:** the search limit is on by default (500): a search without `limit()` that matches more tickets throws a `ZnunySearchLimitExceededException` instead of returning Znuny's first 500. `'search_limit' => null` restores the old behaviour. A config file published with 0.2.2 keeps the limit off until one line is changed, see UPGRADE.md.
- **Breaking:** dates on `Ticket`, `Article` and `HistoryEntry` are `CarbonImmutable`.
- **Breaking:** `Ticket::$customerUserID` is renamed to `$customerUserId`; the old name works with a deprecation notice until 0.4.0.
- The integration suite runs in CI against Znuny 6.5, 7.0, 7.1, 7.2 and 7.3, and against a Znuny that does not run on UTC.
- PHPDoc array types throughout the package, which passes PHPStan at level 8 now. Applications that use static analysis get more precise types.

### Removed

- **Breaking:** `ZnunyClient::request()`, deprecated since 0.2.0; use `call()`.

## [0.2.2] - 2026-11-06

Robustness fixes. See [UPGRADE.md](UPGRADE.md) for what is worth setting after the update.

### Added

- `search_limit` (`ZNUNY_SEARCH_LIMIT`; per connection or under `http`) and `ZnunySearchLimitExceededException`. Znuny cuts a search without a limit off at 500 tickets and does not say so. With a search limit, `ids()`, `get()`, `paginate()` and `lazy()` without `limit()` return every match up to that number and throw if more tickets match. It is off by default and will be on in 0.3.0; until then a search that returns exactly 500 tickets is logged as a warning.
- `session.per_host` (`ZNUNY_SESSION_PER_HOST`, default `true`), see Fixed.
- `verify_ssl` accepts the path of a CA bundle.
- `customers()->tickets($id, limit: 25)`.
- `ZnunyClient::sessionId()` is public.
- README: troubleshooting section.

### Fixed

- Hosts that share a cache store but reach Znuny from different IP addresses invalidated each other's session with every request, because Znuny binds a session to the address that created it. Each host now keeps its own session.
- `withArticle()` and `addArticle()` failed without a `contentType`, which Znuny requires. Articles are now sent as plain text by default.
- `findMany()` sent one request per ticket when Znuny rejected a batch because of a single missing ticket. It now halves the batch and fetches ticket by ticket only once eight or fewer are left.
- Nested config keys that were added after the config file was published (`http.search_limit`, `session.per_host`) had no effect, and neither had their environment variables. The `http` and `session` blocks are now merged with the package defaults.

### Security

- `verify_ssl` values that are not booleans switched certificate verification off, for example a typo or an empty environment variable. An empty value now leaves verification on, and any other value that is not the path of an existing CA bundle raises a `ZnunyConfigurationException`.

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

[Unreleased]: https://github.com/itsjustvita/laravel-znuny/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/itsjustvita/laravel-znuny/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/itsjustvita/laravel-znuny/releases/tag/v0.1.0
