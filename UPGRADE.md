# Upgrade guide

## 0.2.x → 0.3.0

0.3.0 adds a lot (see the CHANGELOG) and makes four breaking changes. Each of them fails loudly rather than changing results silently. Nothing has to change in Znuny.

### Searches fail instead of returning a partial result (high impact)

The search limit introduced as an option in 0.2.2 is on by default, with the value 500. Znuny itself cuts a search without a limit off at 500 tickets, so no search loads more than before; the difference is that a search without `limit()` that matches more than 500 tickets now throws a `ZnunySearchLimitExceededException` instead of returning the first 500.

What to do where it happens:

- Narrow the search down, or call `limit(500)` if the first tickets are enough.
- Raise the limit with `ZNUNY_SEARCH_LIMIT` or the connection's `search_limit`, and use `lazy()` or `paginate()` instead of `get()` for large results: `get()` holds every ticket with its articles in memory.
- `'search_limit' => null` (or `ZNUNY_SEARCH_LIMIT=null`) restores the behaviour of 0.2.
- Every `TicketSearch` without `limit()` carries `Limit: 501` now. Adjust `Http::fake()` expectations in your tests if they compare the whole request.

### Dates are immutable

`$ticket->created`, `$ticket->changed`, `$article->created` and `$entry->createTime` are `Carbon\CarbonImmutable` instead of `Carbon\Carbon`.

- Type hints on `Carbon` fail with a `TypeError`; use `CarbonInterface` or `CarbonImmutable`.
- Methods such as `addDay()` or `setTimezone()` return a new instance and leave the DTO unchanged. Code that relied on mutating the DTO's date must use the returned value.

### `$ticket->customerUserID` is now `$ticket->customerUserId`

The property follows the naming of all other properties. The old name still works until 0.4.0 and triggers a deprecation notice (`E_USER_DEPRECATED`, which Laravel writes to the deprecations log channel). Code that constructs `Ticket` objects with the named argument `customerUserID:` must use `customerUserId:`. The input key `customerUserID` of `create()` and `update()` keeps working, as does the key `CustomerUserID` of `toArray()`.

### `ZnunyClient::request()` is removed

It was a deprecated alias of `call()` since 0.2.0. Use `call()`.

## 0.2.1 → 0.2.2

Nothing has to be changed. Two things are worth doing, and two behaviours changed.

### Worth doing: set a search limit

Znuny cuts a `TicketSearch` without a limit off at its `SearchLimit`, 500 tickets by default, and does not say so. `ids()`, `get()`, `paginate()` and `lazy()` without `limit()` therefore return at most that many tickets.

```dotenv
ZNUNY_SEARCH_LIMIT=500
```

With a search limit, such a search returns every matching ticket up to that number and throws a `ZnunySearchLimitExceededException` if more match. See "Incomplete results" in the README before you raise it far beyond 500: `get()` loads every ticket with its articles into memory.

- 0.2.2 leaves the limit off, so nothing changes until you set it. It only logs a warning when a search returns exactly 500 tickets. 0.3.0 will switch the limit on.
- The package's limit replaces Znuny's `SearchLimit`. If an administrator raised that setting, use at least the same value.
- With a limit set, every `TicketSearch` without `limit()` carries a `Limit` parameter. Adjust `Http::fake()` expectations in your tests if they compare the whole request.

### Worth doing: check `ZNUNY_VERIFY_SSL`

A value that is neither a boolean nor the path of an existing CA bundle now raises a `ZnunyConfigurationException`. Until 0.2.1 such a value switched certificate verification off, and so did an empty `ZNUNY_VERIFY_SSL=`. An empty value now leaves verification on; set `ZNUNY_VERIFY_SSL=false` if you really want it off.

### Changed: one session per host

Znuny binds a session to the IP address that created it. The package now caches one session per host name, so hosts that share a cache store no longer invalidate each other's session. After the update every host logs in once.

Hosts with short-lived names, such as autoscaled containers or job pods, log in once each. If they all reach Znuny from the same address, `ZNUNY_SESSION_PER_HOST=false` shares one session between all hosts as before.

### Changed: articles default to plain text

Articles without `contentType` (or `mimeType` and `charset`) are sent as `text/plain; charset=utf8`. Znuny rejected them before, so no working code changes.

### Published config files

`ZNUNY_SEARCH_LIMIT` and `ZNUNY_SESSION_PER_HOST` also work with a config file that was published before 0.2.2; you do not have to add the new keys `http.search_limit` and `session.per_host` to it.

## 0.2.0 → 0.2.1

### Time zones (check before you upgrade)

**If your Znuny's `OTRSTimeZone` is not UTC, set `ZNUNY_TIMEZONE` to it when you upgrade**, for example `ZNUNY_TIMEZONE=Europe/Berlin`. Without it, dates and date filters are off by Znuny's UTC offset. This also applies if 0.2.0 worked for you because your app and Znuny use the same time zone.

Znuny reads search dates and returns dates in its `OTRSTimeZone`. 0.2.0 used the time zone of your app instead; 0.2.1 uses the new `timezone` option, which defaults to `UTC` like Znuny does.

| Znuny's `OTRSTimeZone` | Time zone of your app | 0.2.0 | 0.2.1 |
|---|---|---|---|
| UTC | UTC | correct | correct |
| UTC | not UTC | wrong | correct |
| not UTC | same as Znuny | correct | correct once `ZNUNY_TIMEZONE` is set |
| not UTC | different | wrong | correct once `ZNUNY_TIMEZONE` is set |

- You find `OTRSTimeZone` in Znuny under Admin → System Configuration; use the name it shows there. `php artisan znuny:test-connection` now checks your setting against the dates of the newest ticket and of tickets from about four and eight months ago, and fails with a hint if the UTC offsets differ.
- `ZNUNY_TIMEZONE` also works with a config file that was published before 0.2.1. To set the time zone per connection, add a `timezone` key to the connection.
- `$ticket->created`, `$ticket->changed`, `$article->created` and `$entry->createTime` are Carbon instances in Znuny's time zone. Use `->copy()->setTimezone(config('app.timezone'))` to display them in your app's time zone.
- `$event->ticket()` reads the dates of webhook payloads in the configured time zone. If a webhook comes from a Znuny with another time zone, pass it: `$event->ticket('Europe/Berlin')`.

### `whereChangedBefore()`

`whereChangedBefore($date)` now returns tickets whose last change (`$ticket->changed`) is before `$date`. In 0.2.0 it also returned tickets that were changed after `$date`, as long as they had any older history entry. To get the old behaviour, use `->where('TicketChangeTimeOlderDate', ...)` with a date in Znuny's time zone.

`whereChangedAfter()` still matches tickets with any history entry after the date. For a window on the last change, combine the new `whereLastChangedAfter()` and `whereLastChangedBefore()`.

### `znuny:test-connection`

The command now also fetches up to three tickets with `TicketGet` (the newest, and one each from about four and eight months ago) and checks the time zone against their dates. It exits with an error when the web service has no `TicketGet` route or when the time zone setting does not match Znuny's dates.

## 0.1.x → 0.2.0

0.2.0 makes the package work with any Znuny installation. Most changes are additions, but a few affect existing installations.

### Default routes (high impact)

0.1 sent every request as `POST <base_url>/<Operation>`, with the ID appended for `TicketGet`, `TicketUpdate` and `QueueGet`. 0.2 uses the routes of the `GenericTicketConnectorREST` example web service that Znuny ships (`var/webservices/examples/`) by default. Web services set up from the older example in `development/webservices/` (search via `GET /Ticket`) need `'routes' => 'generic-ticket-connector-classic'`.

If your web service uses routes named after the operations, keep the old behaviour per connection:

```php
// config/znuny.php
'connections' => [
    'default' => [
        // ...
        'routes' => 'operation-name',
    ],
],
```

or set `ZNUNY_ROUTES=operation-name`. Runtime connections need it as well:

```php
Znuny::usingCredentials($url, $username, $password, options: ['routes' => 'operation-name']);
Znuny::build(['base_url' => $url, 'username' => $username, 'password' => $password, 'routes' => 'operation-name']);
```

If only some routes differ, extend a preset:

```php
'routes' => [
    'extends'   => 'operation-name',
    'TicketGet' => 'POST /TicketGet',
],
```

Check the result with `php artisan znuny:routes` and `php artisan znuny:test-connection`.

### Authentication on GET routes

`auth_transport` defaults to `auto`: for `GET` routes the session ID or credentials are sent as `X-OTRS-Header-*` headers, which Znuny supports since 6.5. With `operation-name` routes every request is a `POST`, so nothing changes. On Znuny 6.0 or OTRS 6 with `GET` routes set `'auth_transport' => 'payload'`.

### Queue resolution removed

`Znuny::queues()->resolve()` and the `queues` config block (`queues.default`, `queues.resolution`) are gone because they encoded one organisation's business rules. Keep such a mapping in your application, for example:

```php
// config/services.php
'znuny_queues' => [
    'default'    => env('ZNUNY_DEFAULT_QUEUE_ID'),
    'relocation' => ['business' => 901, 'private' => 902],
],

// application code
$queueId = config("services.znuny_queues.relocation.{$segment}") ?? config('services.znuny_queues.default');
```

### Removed and renamed config keys

- `http.pool_size` → replaced by `http.batch_size` (maximum ticket IDs per `TicketGet` request, default 50).
- `cache.ttl.services` → removed (it was never used).
- `queues` → removed (see above).
- New: `connections.*.routes`, `auth`, `user_type`, `auth_transport`, `webhooks.auth`.

Compare your published `config/znuny.php` with the package version (`php artisan vendor:publish --tag=znuny-config --force` overwrites yours).

### `ZnunyClient::request()`

`request()` is now a deprecated alias of `call()` and no longer accepts path suffixes:

```php
// before
$client->request('TicketGet/123', ['TicketID' => '123']);
// after
Znuny::call('TicketGet', ['TicketID' => '123']);
```

### Exceptions

- `*.AccessDenied` errors throw `ZnunyAccessDeniedException` (before: `GenericZnunyException`). Znuny usually reports tickets that do not exist this way.
- HTTP errors without a Znuny error body now throw (`ZnunyRouteNotFoundException`, `ZnunyAuthenticationException`, `ZnunyServerException`, …) instead of returning an empty array. A `2xx` response that is not JSON throws `ZnunyServerException`.
- HTTP 4xx responses other than 401, 403, 404 and 429 throw `GenericZnunyException` (before: the response body was returned, or an empty array).
- `TicketCreate` without a `TicketID` in the response throws `ZnunyServerException` (before: `InvalidArgumentException`).
- Configuration problems throw `ZnunyConfigurationException`, which still extends `RuntimeException`.

### Events

- `ZnunySessionRefreshed` now only carries `connection`. It was never dispatched in 0.1.
- Event payloads contain `[REDACTED]` for passwords and session IDs instead of omitting the keys. `ZnunyResponseReceived::$response` is redacted too, so the `SessionCreate` response no longer exposes the session ID.
- New optional properties: `ZnunyRequestSent::$method` and `$path`, `ZnunyResponseReceived::$status`, `ZnunyTicketUpdated::$dynamicFields`, `ZnunyArticleAdded::$articleId`.

### Session and lookup cache

Cached sessions and lookups (queues, states, …) are keyed by the connection name plus a hash of base URL, user type, login and password. Data cached by 0.1 is therefore not reused; every connection logs in once more after the upgrade and reloads its lookups.

### DTO serialization

`Ticket::toArray()` and `Article::toArray()` contain additional keys (`StateID`, `Owner`, `SLA`, … and `To`, `Cc`, `Bcc`, `IsVisibleForCustomer`, `Attachment`). If you return `toArray()` from an API, check your consumers.

### Webhooks

The `501` stub is replaced by a working endpoint. It stays disabled until `ZNUNY_WEBHOOKS_ENABLED=true` and rejects every request until `ZNUNY_WEBHOOK_TOKEN` (or Basic auth credentials) is set.
