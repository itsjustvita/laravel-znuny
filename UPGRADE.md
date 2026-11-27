# Upgrade guide

## 0.4.x → 1.0

1.0 removes what 0.4.0 deprecated, corrects a few names before the API is frozen, and applies one rule to blank settings. From 1.0 on the package follows semantic versioning; the README's "Stability" section says what that covers. Nothing has to change in Znuny.

### The lookups are gone

`Znuny::queues()`, `states()`, `priorities()`, `types()`, `customers()`, `flush()`, `flushAll()`, the command `znuny:cache-clear` and the `cache` block of the config are removed, with their classes (`Data\Queue`, `State`, `Priority`, `Type`, `Customer`, the five resources and `Caching\CacheManager`). They needed operations Znuny does not ship. A `cache` block that is still in your published config file is ignored; you can delete it, and `ZNUNY_CACHE_ENABLED` with it.

| Removed | Use instead |
|---|---|
| `Znuny::queues()->all()` | `Znuny::call('QueueList')['Queue']` (the response of your operation, as an array) |
| `Znuny::queues()->find($id)` | `Znuny::call('QueueGet', ['QueueID' => $id])` |
| `Znuny::states()->all()`, `priorities()->all()`, `types()->all()` | `Znuny::call('StateList')`, `call('PriorityList')`, `call('TypeList')` |
| `Znuny::customers()->find($id)` | `Znuny::call('CustomerUserGet', ['CustomerUserID' => $id])` |
| `Znuny::customers()->tickets($id)` | `Znuny::tickets()->whereCustomerId($id)->get()` |
| the lookup cache, `flush()`, `znuny:cache-clear` | Laravel's cache around your own `call()`, e.g. `Cache::remember('queues', 3600, fn () => Znuny::call('QueueList'))` |

Two things change for those custom operations:

- **Exceptions.** `QueueNotFoundException` and `CustomerNotFoundException` are gone. A not-found error of a custom operation raises their parent, `ZnunyNotFoundException`. `TicketNotFoundException` stays.
- **Retries.** `QueueList`, `QueueGet`, `StateList`, `PriorityList`, `TypeList` and `CustomerUserGet` were repeated after timeouts and server errors, because the package knew they only read. It no longer knows your operations: a custom operation is repeated on a `GET` route only. Map a reading operation to one, or repeat it yourself. The `operation-name` preset no longer contains `POST /QueueGet/:QueueID`; add the route to your connection if you use it.

### `HistoryEntry` says `created` and `createdBy`

`$entry->createTime` is now `$entry->created`, and `$entry->createBy` is `$entry->createdBy`, as on the other data objects. The keys of `toArray()` (`CreateTime`, `CreateBy`) are unchanged.

### Data objects fail on undefined properties

Reading a property that a data object does not have throws an `Error` instead of returning null with a warning, on `Ticket` (as before), `Article`, `Attachment`, `DynamicField`, `HistoryEntry`, `SessionUser` and `TimeAccountingEntry`. For a renamed property the message names the new one. `isset()` and `??` on an undefined property are false and do not throw.

A renamed property fails wherever it is read, so that code written for an earlier release does not go on with null: `$entry->createTime ?? null`, `data_get($entry, 'createTime')` and the collection helpers that read by name, such as `pluck('createBy')`, `sortBy('createTime')`, `where()` and `groupBy()`, throw the same `Error`. That also holds for `$ticket->customerUserID ?? $default`, which gave the default in 0.4. Search your code for `createTime`, `createBy` and `customerUserID`, as property names and as strings.

### A blank setting counts as not set

A setting that is null or a blank string now takes its default everywhere. Three of them behaved differently:

| Setting | 0.4 | 1.0 |
|---|---|---|
| `ZNUNY_SEARCH_LIMIT=` (empty) | the search limit was off | the default of 500. Write `ZNUNY_SEARCH_LIMIT=null` to keep it off; searches that match more than 500 tickets otherwise throw a `ZnunySearchLimitExceededException` |
| `ZNUNY_LOGGING_ENABLED=` (empty) | logging was off | logging is on. Write `false` to turn it off |
| `ZNUNY_AUTH=`, `ZNUNY_USER_TYPE=`, `ZNUNY_AUTH_TRANSPORT=` (empty) | a `ZnunyConfigurationException` | the defaults `session`, `agent`, `auto` |

Switches (`logging.enabled`, the `log_*` settings, `session.auto_retry_on_expired`, `webhooks.enabled`) read `"false"`, `"off"`, `"no"` and `0` as off; the strings `"off"` and `"no"` used to count as on. A blank `webhooks.auth.type` still rejects every webhook.

Two consequences, if your config file reads these switches from the environment:

- A switch that is blank takes its default, and that default is on for `logging.log_requests`, `logging.log_errors` and `session.auto_retry_on_expired`. Blank used to be off. With `log_requests` on, the package logs each request with its payload (secrets redacted).
- A switch only reads `true`/`false`, `1`/`0`, `"on"`/`"off"` and `"yes"`/`"no"`. Any other text takes the default: `ZNUNY_WEBHOOKS_ENABLED=enabled` used to register the webhook route and no longer does.

### Handled failures are no longer reported as errors

`ZnunyRequestFailed` and an error entry in the log now mean that a failure reached your application. They no longer appear when the package deals with the failure itself: a batch of tickets Znuny rejected and the package split (`findMany()`, `get()`, `paginate()`, `lazy()`), a new ticket that could not be read back after `save()` (the warning stays), a session that was already gone at `logout()`, and a dropped session during `session()->user()` in credentials mode. They are logged as information. If you counted these events or alerted on these entries, expect fewer.

### Stricter checks of what you pass in

- `options`: `cert` and `ssl_key` must name existing files, checked when the connection is built instead of by the first request; `headers` must map names to values; `Content-Type` cannot be configured.
- `users()->updateOutOfOffice()` (see below): `login`, `email` and `search` are trimmed; an entry whose `from` is after its `until` is refused.
- `session()->user()` throws a `ZnunyServerException` when Znuny's answer has no session data, instead of returning a user without login.

### Smaller renames and changed types

| 0.4 | 1.0 |
|---|---|
| `Znuny::users()->outOfOffice([...])` | `Znuny::users()->updateOutOfOffice([...])`. The method writes; its old name read like a getter |
| `lazy()` returns a `Generator` | `lazy()` returns a `LazyCollection`. `foreach` works as before, and so do `filter()`, `map()`, `take()` and going through it twice. Code that called `current()`, `next()` or `valid()` on the result, or declared its type as `Generator`, uses `->getIterator()` |
| `Znuny::client()->settings()->password`, `->options` | no longer public properties, so that logging or dumping the settings does not write secrets out. The package's own accessors `password()` and `options()` are internal |

### Mocking in your tests

`Znuny::fake()`, `Http::fake()`, `Znuny::shouldReceive()` and mocks of the resources work as before, and `Znuny::partialMock()` works now. One thing no longer does: a real `TicketResource` or `SessionResource` on top of a fully mocked `ZnunyClient`, because the resources call methods of the client that are internal. Use `Znuny::fake()`, or mock the resource.

### Other things you may notice

- Ticket IDs can be passed as integers to every method of `tickets()`.
- An option in `options` that is null or blank counts as not set, and in a connection it removes the global option of that name.
- `Znuny::fake()` ignores `options` and `verify_ssl`, so certificate files that only exist in production do not stop a test.
- A call to Znuny made by one of your event listeners is reported as a failure of its own (`ZnunyRequestFailed`, error entry), even while the package is handling another failure.
- `composer.json` no longer sets `minimum-stability: dev`. This does not affect your application.
- Classes that are not part of the public API are marked `@internal`. If your code extends or constructs one of them, the README's "Stability" section says what is safe to rely on.

## 0.3.x → 0.4.0

0.4.0 gives every operation Znuny ships a typed method (see the CHANGELOG) and deprecates what does not rest on stock Znuny. Four changes can break existing code. Nothing has to change in Znuny or in your config file.

### `$ticket->customerUserID` is gone

The property was renamed to `$ticket->customerUserId` in 0.3.0 and has triggered a deprecation notice since. Reading the old name now throws an `Error` that names the new one; `isset()` and `??` on it are false. The input key `customerUserID` of `create()` and `update()` keeps working, as does the key `CustomerUserID` of `toArray()`.

### `paginate()` follows the page of the request

`paginate()` without a page used to return page 1. It now returns the page of the current request (`?page=`), as Eloquent's `paginate()` does. Pass `page: 1` where page 1 is meant whatever the request says. Calls that pass a page are unchanged.

### Two refusals raise `ZnunyAccessDeniedException`

Only relevant if you already call these operations through `Znuny::call()`:

- `TimeAccountingGet.NoPermission` (the user is not in the group `timeaccounting_webservice`) was a `GenericZnunyException`.
- `OutOfOffice.AuthFail` with "User needs to be in group admin" or "User needs to be an agent" was a `ZnunySessionExpiredException` in session mode, after a pointless new login, and a `ZnunyAuthenticationException` in credentials mode.

### Dates in dynamic fields are sent in Znuny's format

A `DateTimeInterface` as a dynamic field value, on create, update or in `whereDynamicField()`, is sent as `Y-m-d H:i:s` in Znuny's time zone. Before, it was sent as an ISO 8601 string, which Znuny rejects, so working code is not affected. If a Znuny field is of type "Date" (without time), declare it in `config/znuny.php` as `'YourField' => ['type' => 'date']`; otherwise Znuny rejects the time of day.

### Deprecated: the lookups

`Znuny::queues()`, `states()`, `priorities()`, `types()`, `customers()`, `flush()`, `flushAll()`, the command `znuny:cache-clear` and the `cache` block of the config are deprecated and will be removed in 1.0. They need operations Znuny does not ship, in a response format only this package defines. They keep working and trigger `E_USER_DEPRECATED`, which Laravel writes to the deprecations log channel.

The section "0.4.x → 1.0" above lists what replaces each of them.

### Other things you may notice

- Session data in logs and events: values whose key names a secret are redacted, also inside values Znuny returns as JSON.
- `php artisan znuny:webservice` has a new option `--also`. Without it the generated file is the same as before, unless the connection's routes already map `TimeAccountingGet` or `OutOfOffice`: those two were left out as unknown operations and are now generated.
- Znuny's error messages sometimes repeat the session ID or the password that was sent. Both are replaced with `[REDACTED]` in exception messages, logs and events.

## 0.2.x → 0.3.0

0.3.0 adds a lot (see the CHANGELOG) and makes four breaking changes. Each of them fails loudly rather than changing results silently. Nothing has to change in Znuny.

### Searches fail instead of returning a partial result (high impact)

The search limit introduced as an option in 0.2.2 is on by default, with the value 500. Znuny itself cuts a search without a limit off at 500 tickets, so no search loads more than before; the difference is that a search without `limit()` that matches more than 500 tickets now throws a `ZnunySearchLimitExceededException` instead of returning the first 500.

What to do where it happens:

- Narrow the search down, or call `limit(500)` if the first tickets are enough.
- Raise the limit with `ZNUNY_SEARCH_LIMIT` or the connection's `search_limit`, and use `lazy()` or `paginate()` instead of `get()` for large results: `get()` holds every ticket with its articles in memory.
- `'search_limit' => null` (or `ZNUNY_SEARCH_LIMIT=null`) restores the behaviour of 0.2.
- **If you published `config/znuny.php` with 0.2.2**, it contains `'search_limit' => env('ZNUNY_SEARCH_LIMIT'),`. Without the variable that is `null`, which keeps the limit off. Change the line to `'search_limit' => env('ZNUNY_SEARCH_LIMIT', 500),` to get the new default. Config files published with 0.2.1 or earlier, or not published at all, get it automatically.
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
