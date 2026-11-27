# laravel-znuny

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![Tests](https://img.shields.io/github/actions/workflow/status/itsjustvita/laravel-znuny/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/itsjustvita/laravel-znuny/actions/workflows/tests.yml)
[![Znuny 6.5 to 7.3](https://img.shields.io/github/actions/workflow/status/itsjustvita/laravel-znuny/integration.yml?branch=main&label=znuny%206.5%20to%207.3&style=flat-square)](https://github.com/itsjustvita/laravel-znuny/actions/workflows/integration.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![PHP Version](https://img.shields.io/packagist/php-v/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![License](https://img.shields.io/packagist/l/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)

> Laravel SDK for the Znuny / OTRS Community Edition Generic Interface REST API.

Works out of the box with the **GenericTicketConnectorREST** example web service that Znuny ships, and with any custom web service through configurable routes. Every operation Znuny ships has a typed method. You get DTOs, a fluent ticket search with batching and pagination, attachments, ticket history, session handling with automatic re-login, time accounting, out-of-office settings, inbound webhooks, events, logging, and a generic `call()` for every other operation.

## Contents

- [What this package covers](#what-this-package-covers)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setting up the web service in Znuny](#setting-up-the-web-service-in-znuny)
- [Configuration](#configuration)
- [Tickets](#tickets)
- [Searching tickets](#searching-tickets)
- [Attachments](#attachments)
- [Session](#session)
- [Time accounting](#time-accounting)
- [Out of office](#out-of-office)
- [Calling any operation](#calling-any-operation)
- [Dynamic fields](#dynamic-fields)
- [Webhooks (Znuny to Laravel)](#webhooks-znuny-to-laravel)
- [Events](#events)
- [Logging](#logging)
- [Exceptions](#exceptions)
- [Artisan commands](#artisan-commands)
- [Troubleshooting](#troubleshooting)
- [Testing your application](#testing-your-application)
- [Stability](#stability)
- [Upgrading](#upgrading)

## What this package covers

The package has a typed method for an operation if, and only if, Znuny ships that operation. Znuny 6.5 to 7.3 ship ten, and CI runs each of them against a real Znuny of every supported version:

| Znuny operation | Method | Web service Znuny ships it in | Needs |
|---|---|---|---|
| `SessionCreate`, `SessionGet`, `SessionRemove` | the automatic login, `session()->user()`, `session()->logout()` | `GenericTicketConnectorREST` | |
| `TicketCreate`, `TicketGet`, `TicketSearch`, `TicketUpdate`, `TicketHistoryGet` | `tickets()` | `GenericTicketConnectorREST` | permissions on the queues |
| `TimeAccountingGet` | `timeAccounting()->entries()` | `TimeAccountingREST` | `rw` in the group `timeaccounting_webservice` |
| `OutOfOffice` | `users()->setOutOfOffice()`, `clearOutOfOffice()`, `updateOutOfOffice()` | `OutOfOffice` | an agent with `rw` in the group `admin` |

Everything else, such as operations of Znuny add-ons or your own, goes through [`Znuny::call()`](#calling-any-operation) with the same routing, authentication, error handling, events and logging.

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13
- Znuny 6.5 or 7.x with a Generic Interface web service that has a REST provider. Every change is tested against real Znuny 6.5, 7.0, 7.1, 7.2 and 7.3 instances. Znuny 6.0 and OTRS 6 are not tested, but known to work with `'auth_transport' => 'payload'` (they do not read authentication headers) and, for their example web service, `'routes' => 'generic-ticket-connector-classic'`.

These are the versions CI tests. See [Stability](#stability) for what happens when one of them is no longer supported by its maker.

## Installation

```bash
composer require itsjustvita/laravel-znuny
php artisan vendor:publish --tag=znuny-config
```

```dotenv
ZNUNY_BASE_URL=https://znuny.example.com/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST
ZNUNY_USERNAME=api-agent
ZNUNY_PASSWORD=secret
```

```bash
php artisan znuny:test-connection
```

The command checks the login, the routes and the [time zone](#time-zone) setting.

## Setting up the web service in Znuny

1. Get the web service definition:
   - Znuny 6.5.11+ and 7.1.3+ list `GenericTicketConnectorREST` under **Admin → Web Services → Add Web Service → Ready2Adopt Web Services**. Import it there; the default route preset matches it.
   - For every other version, for other routes and for webhooks, generate the definition from your connection:

     ```bash
     php artisan znuny:webservice --output=znuny-webservice.yml
     ```

     and import it under **Admin → Web Services → Add Web Service → Import web service**, or on the Znuny server with `bin/otrs.Console.pl Admin::WebService::Add --name GenericTicketConnectorREST --source-path znuny-webservice.yml`. CI imports generated definitions into Znuny 6.5, 7.0, 7.1, 7.2 and 7.3. Add `--also=TimeAccountingGet --also=OutOfOffice` to get those two operations into the same web service; see [Time accounting](#time-accounting) and [Out of office](#out-of-office).
2. Keep the name `GenericTicketConnectorREST` or choose your own. The base URL is `https://<host>/otrs/nph-genericinterface.pl/Webservice/<name>`; use your installation's script alias if it is not `/otrs/`.
3. Create an agent for the API and give it permissions on the queues it needs.

Web services that were set up from the older example in `development/webservices/` (they search with `GET /Ticket`) need `'routes' => 'generic-ticket-connector-classic'`. `php artisan znuny:test-connection` tells you whether the routes match.

## Configuration

### Connections

```php
// config/znuny.php
'connections' => [
    'default' => [
        'base_url'        => env('ZNUNY_BASE_URL'),
        'username'        => env('ZNUNY_USERNAME'),
        'password'        => env('ZNUNY_PASSWORD'),
        'routes'          => env('ZNUNY_ROUTES', 'generic-ticket-connector'),
        'auth'            => env('ZNUNY_AUTH', 'session'),
        'user_type'       => env('ZNUNY_USER_TYPE', 'agent'),
        'auth_transport'  => env('ZNUNY_AUTH_TRANSPORT', 'auto'),
        'verify_ssl'      => env('ZNUNY_VERIFY_SSL', true),
        'timeout'         => env('ZNUNY_TIMEOUT', 30),
        'connect_timeout' => env('ZNUNY_CONNECT_TIMEOUT', 3),
    ],
],
```

`verify_ssl` is `true`, `false` or the absolute path of a CA bundle; any other value is rejected, so a typo cannot switch certificate verification off.

A connection can also set `retries`, `retry_delay` (milliseconds), `batch_size` and `search_limit`; they default to the values under `http`. Retries repeat reads after timeouts and server errors. Writes such as `TicketCreate` and `TicketUpdate` are only repeated when no connection could be established, so a slow Znuny never receives the same ticket twice.

`options` adds Guzzle request options to every request of a connection, for a proxy, a client certificate or a reverse proxy in front of Znuny:

```php
'default' => [
    // ...
    'options' => [
        'proxy'   => 'http://proxy.internal:3128',
        'cert'    => '/etc/ssl/client.pem',
        'headers' => ['X-Forwarded-Auth' => env('ZNUNY_PROXY_TOKEN')],
    ],
],
```

Accepted are `proxy`, `cert`, `ssl_key`, `headers`, `auth`, `force_ip_resolve` and `version`; `http.options` sets them for all connections, and a connection's own options replace those key by key. An option that is null or blank is not set, so `'cert' => env('ZNUNY_CLIENT_CERT')` is no certificate where the variable is missing; in a connection it removes the global option of that name. Any other option is rejected: certificate verification and the timeouts are only changed through `verify_ssl`, `timeout` and `connect_timeout`. The authentication headers (`X-OTRS-Header-UserLogin`, `-CustomerUserLogin`, `-Password`, `-SessionID`) and `Content-Type` cannot be configured: the package sets them, and Znuny reads the authentication headers over the request body. `cert` and `ssl_key` must name files that exist; that is checked when the connection is built.

**A setting that is null or blank counts as not set and takes its default.** An empty variable in `.env`, such as `ZNUNY_TIMEOUT=`, therefore never means 0 or "off". Switches read `false`, `"false"`, `"off"`, `"no"` and `0` as off. Two settings differ: `search_limit` is switched off with `null` (`ZNUNY_SEARCH_LIMIT=null`), while a blank value is the default of 500; and a blank `webhooks.auth.type` rejects every webhook, so that the endpoint cannot open by accident.

### Routes

Znuny maps REST routes to operations per web service. Tell the package which routes yours uses:

| `routes` | Meaning |
|---|---|
| `generic-ticket-connector` (default) | The example web service Znuny ships (`var/webservices/examples/`): `POST /Session`, `GET`/`DELETE /Session/:SessionID`, `POST /Ticket`, `GET /Ticket/:TicketID`, `POST /Ticket/Search`, `PATCH /Ticket/:TicketID`, `GET /Ticket/History/:TicketID` |
| `generic-ticket-connector-classic` | The older example (`development/webservices/`) that many OTRS and Znuny installations were set up from: like the default, but `GET /Ticket` (search), `GET /TicketHistory/:TicketID` and `GET /TicketList` |
| `operation-name` | Routes named after the operations: `POST /<Operation>`, plus `POST /TicketGet/:TicketID` and `POST /TicketUpdate/:TicketID` |
| array | Your own mapping in Znuny's notation `"METHOD /Path/:Param"`, optionally starting from a preset with `extends` |

```php
'routes' => [
    'extends'   => 'generic-ticket-connector',
    'QueueList' => 'GET /Queue',          // custom operations of your web service
    'FAQSearch' => 'POST /FAQ/Search',
],
```

Operations without a mapping are sent to `POST /<Operation>`. `php artisan znuny:routes` prints the effective routes of a connection.

For `GET` routes the parameters go into the query string the way Znuny parses it (`States=new&States=open`). Nested parameters such as dynamic field search conditions cannot be expressed in a query string. This affects `TicketSearch` with the classic preset; to search by dynamic fields there, add a `POST` route for `TicketSearch` to the web service and map it, e.g. `'TicketSearch' => 'POST /Ticket/Search'`.

### Authentication

| Key | Values | Default |
|---|---|---|
| `auth` | `session`: log in once via `SessionCreate` and reuse the cached `SessionID`; an expired session is replaced and the request retried once. `credentials`: send login and password with every request. | `session` |
| `user_type` | `agent` (`UserLogin`) or `customer` (`CustomerUserLogin`) | `agent` |
| `auth_transport` | `auto`: `X-OTRS-Header-*` headers for `GET` routes, request data otherwise. `payload`: always in the request data. `headers`: always as headers. | `auto` |

Headers keep session IDs and passwords out of URLs and access logs. They need Znuny 6.5 or newer; on older versions use `payload`.

Znuny binds a session to the IP address that created it and deletes it when another address uses it. The package therefore keeps one session per host name, so servers and workers that share a cache store do not invalidate each other's session.

- If a single host reaches Znuny from changing addresses, use `'auth' => 'credentials'` or switch off `SessionCheckRemoteIP` in Znuny.
- Hosts with short-lived names, such as autoscaled containers or job pods, log in once each. If they all reach Znuny from the same address, `ZNUNY_SESSION_PER_HOST=false` lets them share one session.

### Time zone

```php
'timezone' => env('ZNUNY_TIMEZONE', 'UTC'),
```

Znuny reads search dates and returns dates in its `OTRSTimeZone` (Admin → System Configuration), whatever the time zone of your app is. It is `UTC` unless an administrator changed it. If your Znuny uses another time zone, set `ZNUNY_TIMEZONE` to the name Znuny shows, for example `Europe/Berlin`; otherwise dates and date filters are off by Znuny's UTC offset. Offsets and abbreviations that Znuny does not offer, such as `+02:00` or `CEST`, are rejected because they ignore daylight saving time; the legacy names `CET`, `EET`, `MET`, `WET`, `EST`, `MST` and `HST` are replaced by their region, for example `CET` by `Europe/Brussels`. A connection can override the value with its own `timezone` key.

`php artisan znuny:test-connection` checks the setting against the dates Znuny returns. It compares the UTC offsets for the newest ticket and, if they exist, for tickets from about four and eight months ago, and fails with a hint if they differ.

### Multiple and runtime connections

```php
use ItsJustVita\LaravelZnuny\Facades\Znuny;

Znuny::connection('tenant-a')->tickets()->find('12345');

// Connections that only exist at runtime, e.g. stored per tenant:
Znuny::build([
    'name'     => 'tenant-42',            // optional; shown in logs and events
    'base_url' => $tenant->znuny_url,
    'username' => $tenant->znuny_user,
    'password' => $tenant->znuny_password,
    'routes'   => 'operation-name',
])->tickets()->find('12345');

// Shorthand:
Znuny::usingCredentials($url, $username, $password, options: ['routes' => 'operation-name']);
```

## Tickets

```php
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Facades\Znuny;

$ticket  = Znuny::tickets()->find('12345');
$ticket  = Znuny::tickets()->find('12345', withArticles: true, withDynamicFields: true, withAttachments: true);
$tickets = Znuny::tickets()->findMany(['12345', '12346']);   // batched; keeps the order

$ticket = Znuny::tickets()->create([
        'title'        => 'Printer on fire',
        'queue'        => 'Support',
        'state'        => 'new',
        'priority'     => '3 normal',
        'customerUser' => 'jane@example.com',
    ])
    ->withArticle([
        'subject'              => 'Printer on fire',
        'body'                 => 'Please send help.',
        'contentType'          => 'text/plain; charset=utf8',
        'communicationChannel' => 'Email',
        'senderType'           => 'customer',
    ])
    ->withDynamicFields(['OrderId' => 'ORD-2026-001'])
    ->withAttachments([Attachment::fromPath(storage_path('app/photo.jpg'))])
    ->save();                                      // returns the created Ticket

Znuny::tickets()->update('12345', ['state' => 'open'], dynamicFields: ['OrderId' => 'ORD-2']);

$articleId = Znuny::tickets()->addArticle('12345', [
    'subject'              => 'Internal note',
    'body'                 => 'Customer called back.',
    'communicationChannel' => 'Internal',
    'senderType'           => 'agent',
    'isVisibleForCustomer' => false,
]);

Znuny::tickets()->close('12345');                  // state "closed successful"
Znuny::tickets()->lock('12345');                   // and unlock()
Znuny::tickets()->assign('12345', 'agent-login');  // an int is an agent ID
Znuny::tickets()->move('12345', 'Second Level');   // an int is a queue ID
Znuny::tickets()->setPending('12345', now()->addDays(3));                         // "pending reminder"
Znuny::tickets()->setPending('12345', now()->addWeek(), 'pending auto close+');

$ticket  = Znuny::tickets()->findByNumber('2026110610000017');   // the number customers see
$history = Znuny::tickets()->history('12345');     // Collection<HistoryEntry>
```

A ticket ID can be a string, as Znuny returns it, or an integer; tickets and events carry it as a string. For the second argument of `assign()` and `move()` the type decides: a string is a login or a queue name, an integer is an ID. The IDs on a ticket are strings, so cast one you pass on: `move($id, (int) $other->queueId)`. A `pendingTime` in `create()` and `update()` can be any `DateTimeInterface`; it is converted to Znuny's [time zone](#time-zone) and read to the minute. `$ticket->pendingUntil` is the pending time while the ticket is in a pending state, `null` otherwise. `findByNumber()` matches the exact number only: wildcards never match, so a number a customer enters returns that ticket or none. Whether the ticket belongs to that customer is still for your application to check.

`save()` reads the new ticket back from Znuny. If that fails, for example because the user may create tickets in a queue but not read them, the ticket still exists: `save()` returns it with its ID, its number and the attributes you sent (without articles and dates) and logs a warning, so that your application does not create it again.

Input keys are camelCase (`queueId`, `customerUser`, `isVisibleForCustomer`, …). Any other key is passed to Znuny unchanged, so `'HistoryComment' => '…'` works too. Articles are sent as `text/plain; charset=utf8` unless you pass a `contentType` (or `mimeType` and `charset`).

`Ticket`, `Article` and the other DTOs expose typed properties plus every raw field Znuny returned. Dates are `CarbonImmutable`.

```php
$ticket->owner;                                    // typed property
$ticket->attribute('EscalationTime');              // raw field
```

Raw `TicketGet` parameters can be passed to `find()` and `findMany()`, for example `find('12345', options: ['Extended' => 1, 'ArticleLimit' => 5])`.

`attribute()` supports dot notation for nested data. `TicketGet` returns flat ticket fields, but webhook payloads include, for example, the customer user's data: `$event->ticket()->attribute('CustomerUser.UserEmail')`.

> Znuny checks permissions before existence: `find()` on a ticket that does not exist usually throws `ZnunyAccessDeniedException`. `findMany()` skips tickets that are missing or not accessible.

## Searching tickets

```php
$tickets = Znuny::tickets()
    ->whereState(['new', 'open'])
    ->whereQueue('Support')
    ->whereCreatedAfter(now()->subDays(30))
    ->whereChangedBefore(now()->subDay())
    ->orderBy('Age', 'desc')
    ->limit(50)
    ->get();                                       // Collection<Ticket>

$page  = Znuny::tickets()->whereCustomerUser('jane')->paginate(perPage: 25);   // the page of the request, or page: 2
$count = Znuny::tickets()->whereLock('lock')->whereOwnerId(3)->count();
$ids   = Znuny::tickets()->whereFullText('printer')->ids();

foreach (Znuny::tickets()->whereQueue('Support')->lazy(chunkSize: 100) as $ticket) {
    // processes tickets chunk by chunk
}

// lazy() is a LazyCollection: only the chunks that are needed are fetched
$urgent = Znuny::tickets()->whereQueue('Support')->lazy()->first(fn ($ticket) => $ticket->priority === '5 very high');

// Any TicketSearch parameter:
Znuny::tickets()->where('CreatedUserIDs', [3, 4])->ids();

// Dynamic field conditions (the default preset searches via POST; for the classic preset see Routes):
Znuny::tickets()->whereDynamicField('OrderId', 'ORD-*', 'Like')->ids();

// Several conditions on one field are combined with AND, e.g. for a range:
Znuny::tickets()
    ->whereDynamicField('DueDate', '2026-01-01 00:00:00', 'GreaterThanEquals')
    ->whereDynamicField('DueDate', '2026-02-01 00:00:00', 'SmallerThan')
    ->ids();
```

| Filters | Znuny parameter |
|---|---|
| `whereTicketNumber()`, `whereTitle()` (`*` is a wildcard) | `TicketNumber`, `Title` |
| `whereState()`, `whereStateId()`, `whereStateType()` | `States`, `StateIDs`, `StateType` |
| `whereQueue()`, `whereQueueId()` | `Queues`, `QueueIDs` |
| `wherePriority()`, `wherePriorityId()`, `whereType()`, `whereTypeId()` | `Priorities`, `PriorityIDs`, `Types`, `TypeIDs` |
| `whereOwnerId()`, `whereResponsibleId()`, `whereLock()` | `OwnerIDs`, `ResponsibleIDs`, `Locks` |
| `whereCustomerId()`, `whereCustomerUser()` | `CustomerID`, `CustomerUserLogin` |
| `whereService()`, `whereSla()` | `Services`, `SLAs` |
| `whereFullText()` | `Fulltext` (subject, body, From, To, Cc of the articles) |
| `whereDynamicField()` | `DynamicField_<Name>` |
| `includeArchived()`, `onlyArchived()` | `SearchInArchive` |

Filters with a plural Znuny parameter take a value or a list. All of them, and every other search method, can be called on `Znuny::tickets()` directly or on `Znuny::tickets()->query()`.

Date filters accept any `DateTimeInterface` and convert it to Znuny's [time zone](#time-zone); the dates of the returned tickets, articles and history entries are read in it as well.

| Filter | Compares |
|---|---|
| `whereCreatedAfter()`, `whereCreatedBefore()` | the creation time (`$ticket->created`) |
| `whereLastChangedAfter()`, `whereLastChangedBefore()` | the time of the last change (`$ticket->changed`) |
| `whereChangedAfter()` | the ticket history: any entry after the date |
| `whereChangedBefore()` | same as `whereLastChangedBefore()` |
| `whereClosedAfter()`, `whereClosedBefore()` | the close time |
| `wherePendingAfter()`, `wherePendingBefore()` | the pending time |

`get()`, `paginate()` and `lazy()` load articles and dynamic fields by default; use `withoutArticles()`, `withoutDynamicFields()` or `withAttachments()` to change that, and `withOptions(['ArticleLimit' => 5])` for other `TicketGet` parameters. `paginate()` fetches all matching IDs first and then loads only the tickets of the requested page. Without a page it uses the page of the current request (`?page=`), as Eloquent does, and its links point to the current URL.

### Incomplete results

Znuny cuts a search without a limit off at its `SearchLimit`, 500 tickets by default, and does not say so. The package therefore sends its own `search_limit` (500 by default): a search without `limit()` returns every matching ticket up to that number and throws a `ZnunySearchLimitExceededException` if more match. Then narrow the search down, call `limit()` to accept only the first tickets, or raise the limit:

```dotenv
ZNUNY_SEARCH_LIMIT=5000
```

A connection can have its own `search_limit`. The package's limit replaces Znuny's `SearchLimit`, so use at least that value if an administrator raised it. `get()` holds every ticket with its articles in memory; for more than a few hundred tickets use `lazy()` or `paginate()`.

`ZNUNY_SEARCH_LIMIT=null` switches the check off, as in 0.2. Then a search that returns exactly 500 tickets is logged as a warning, because Znuny probably cut it off.

## Attachments

```php
use ItsJustVita\LaravelZnuny\Data\Attachment;

Attachment::fromPath('/path/to/file.pdf');                 // filename and MIME type detected
Attachment::fromContent('report.csv', $csv, 'text/csv');
Attachment::fromUploadedFile($request->file('photo'));    // MIME type detected from the content
Attachment::fromStorage('tickets/photo.jpg', disk: 's3');

Znuny::tickets()->addArticle('12345', $article, attachments: [
    Attachment::fromContent('log.txt', $log, 'text/plain'),
]);

$ticket = Znuny::tickets()->find('12345', withAttachments: true);

foreach ($ticket->articles as $article) {
    foreach ($article->attachments as $attachment) {
        Storage::put($attachment->filename, $attachment->content);   // decoded bytes
    }
}
```

## Session

```php
$user = Znuny::session()->user();

$user->id;          // "5"; for a customer user the login
$user->login;       // "api-agent"
$user->firstName;   $user->lastName;   $user->fullName;   $user->email;
$user->type;        // "agent" or "customer"
$user->attribute('UserLastLoginTimestamp');   // any other value of the session, e.g. preferences

Znuny::session()->logout();                  // ends the session in Znuny; the next call logs in again
```

`user()` is the account of the connection, that is the user your application logs in with. It is not the person who uses your application. It works with `auth => credentials` too; the package then logs in once to have a session to ask about.

Znuny's session data contains secrets: a customer user's password hash, a two-factor key, and the password of an LDAP directory or external customer database inside the customer backend's configuration. `SessionUser` leaves out every value whose key names a secret, and the package redacts them in its logs and events. `Znuny::call('SessionGet', …)` returns what Znuny sends.

`logout()` needs the `SessionRemove` operation, which the web service set up from the older example in `development/webservices/` does not have.

## Time accounting

```php
$entries = Znuny::timeAccounting()->entries('agent-login', now()->startOfMonth(), now());   // Collection<TimeAccountingEntry>

$entries->sum('timeUnit');
$entries->first()->ticketNumber;   // also ticketTitle, queue, customerId, timeUnit, created
```

The entries are the time units an agent booked on tickets between two points in time, oldest first; `queue` is the queue the ticket was in at that time. Znuny answers only users with `rw` in the group `timeaccounting_webservice`, otherwise you get a `ZnunyAccessDeniedException`. For a login Znuny does not know it answers like for an agent who booked nothing: the result is empty.

Znuny ships this operation in a web service of its own, **TimeAccountingREST** (Ready2Adopt Web Services), not in `GenericTicketConnectorREST`. Use one of these:

```php
// 1. A second connection to Znuny's web service. It has no session route, so it sends the credentials.
'time-accounting' => [
    'base_url' => 'https://znuny.example.com/otrs/nph-genericinterface.pl/Webservice/TimeAccountingREST',
    'username' => env('ZNUNY_USERNAME'),
    'password' => env('ZNUNY_PASSWORD'),
    'auth'     => 'credentials',
],

Znuny::connection('time-accounting')->timeAccounting()->entries(...);
```

```bash
# 2. The operation in your own web service; then the default connection has it.
php artisan znuny:webservice --also=TimeAccountingGet --output=znuny-webservice.yml
```

## Out of office

```php
Znuny::users()->setOutOfOffice('agent-login', now()->addDay(), now()->addWeeks(2));
Znuny::users()->clearOutOfOffice('agent-login');

// Several agents at once, also by e-mail address or Znuny's user search:
Znuny::users()->updateOutOfOffice([
    ['email' => 'jane@example.com', 'from' => $from, 'until' => $until],
    ['search' => 'Miller', 'enabled' => false],
]);
```

`from` and `until` are calendar days: the day the date names in its own time zone is sent, without conversion. Give both or neither.

- **Znuny reports success even when nothing happened.** An entry that matches no agent, or more than one, is skipped and written to Znuny's log; the response is the same, and no operation reads the setting back for another agent. The methods therefore return nothing. An agent's own session shows the setting once they have logged in again: `Znuny::session()->user()->attribute('OutOfOffice')`.
- The caller must be an agent with `rw` in the group `admin`, otherwise you get a `ZnunyAccessDeniedException`.
- Znuny ships the operation in a web service of its own, **OutOfOffice**. As for [time accounting](#time-accounting), use a second connection with `'auth' => 'credentials'` and the `base_url` of that web service, or `php artisan znuny:webservice --also=OutOfOffice`. The package's request works with Znuny's web service, which reads the entries from a CSV text, and with a generated one, which reads them directly.

## Calling any operation

Every other operation — Znuny add-ons such as FAQ or ITSM, or your own — goes through the same routing, authentication, error handling, events and logging:

```php
$result = Znuny::call('FAQSearch', ['Title' => 'VPN*']);
$result = Znuny::connection('tenant-a')->call('ConfigItemSearch', ['ConfigItem' => ['Number' => '1000*']]);
```

`call()` returns Znuny's decoded response as an array. The package cannot know whether an operation of yours only reads, so it repeats it after a timeout or server error only on a `GET` route; map a reading operation to one (`'QueueList' => 'GET /Queues'`) to have it repeated.

Until 1.0 the package had lookup helpers for queues, states, priorities, types and customer users, which need operations Znuny does not ship. [UPGRADE.md](UPGRADE.md) shows the `call()` that replaces each.

## Dynamic fields

```php
// 1. Plain array, always works
->withDynamicFields(['OrderId' => 'ORD-001', 'Segment' => 'business'])

// 2. Validation in config/znuny.php
'dynamic_fields' => [
    'Segment' => ['type' => 'string', 'allowed' => ['business', 'private']],
    'Amount'  => ['type' => 'float', 'min' => 0],
    'Tags'    => ['type' => 'array', 'allowed' => ['vip', 'urgent']],
    'Rating'  => ['type' => 'integer', 'min' => 1, 'max' => 5],
    'Flag'    => ['type' => 'boolean'],
    'DueAt'   => ['type' => 'datetime'],   // a Znuny field of type "Date / Time"
    'DueOn'   => ['type' => 'date'],       // a Znuny field of type "Date"
],
// Invalid values throw InvalidDynamicFieldException before anything is sent (create and update).

// 3. Typed helper generated from that config
php artisan znuny:generate-fields
```

Dates go in as `DateTimeInterface` and are sent the way Znuny reads them, on create, update and in search conditions:

```php
->withDynamicFields(['DueAt' => now()->addDays(3)])
Znuny::tickets()->whereDynamicField('DueAt', now(), 'SmallerThan')->get();   // overdue

$ticket->dynamicField('DueAt')?->date();   // CarbonImmutable, or null without a value
$ticket->dynamicField('OrderId')?->value;  // any field by name
```

A date is sent as date and time in Znuny's [time zone](#time-zone), which is what a Znuny field of type "Date / Time" takes. A field of type "Date" takes a day only, and the package cannot see a field's type: declare such a field as `'type' => 'date'`, and it is sent as the day the date names. `date()` reads both back in Znuny's time zone. An empty string clears a date field: `update($id, dynamicFields: ['DueAt' => ''])`.

## Webhooks (Znuny to Laravel)

The package can receive Znuny's outgoing events and dispatch them as Laravel events.

1. Enable the endpoint and set a secret:

   ```dotenv
   ZNUNY_WEBHOOKS_ENABLED=true
   ZNUNY_WEBHOOK_TOKEN=a-long-random-string
   ```

   This registers `POST /znuny/webhooks/{event?}` (see `webhooks.route_prefix`) with the `api` middleware group and the `znuny.webhook` authentication middleware.

2. In Znuny create a web service with a **requester**. The easiest way is to generate it together with the provider routes and import it (see [Setting up the web service](#setting-up-the-web-service-in-znuny)):

   ```bash
   php artisan znuny:webservice --webhooks=TicketCreate,ArticleCreate --webhook-url=https://your-app.example.com --output=znuny-webservice.yml
   ```

   The file contains `CHANGE-ME` where the secret belongs; `--with-token` writes it instead. Webhooks are delivered by the Znuny daemon; `--sync` sends them during the triggering request. To set the requester up by hand:
   - network transport `HTTP::REST` with the endpoint `https://your-app.example.com/znuny/webhooks`;
   - an invoker of type `Ticket::Generic`, triggered by the events you need (for example `TicketCreate`, `ArticleCreate`, `TicketStateUpdate`);
   - a controller mapping for the invoker, e.g. `POST` to `/TicketCreate` (the last path segment becomes the event name);
   - the additional header `X-Znuny-Token: <your token>` (or `Authorization: Bearer <your token>`). Alternatively use Znuny's BasicAuth together with `ZNUNY_WEBHOOK_AUTH=basic`, `ZNUNY_WEBHOOK_USERNAME` and `ZNUNY_WEBHOOK_PASSWORD`.

3. Listen to the event:

   ```php
   use ItsJustVita\LaravelZnuny\Events\ZnunyWebhookReceived;

   Event::listen(function (ZnunyWebhookReceived $event) {
       if ($event->is('TicketCreate')) {
           $ticket = $event->ticket();   // built from the payload; null if no ticket data was sent
                                         // dates use the configured time zone; for another Znuny: ticket('Europe/Berlin')
           // $event->ticketId, $event->articleId, $event->payload, $event->parameters
       }
   });
   ```

The endpoint answers `200 {}`; Znuny applies data returned by the endpoint to the ticket, so nothing else is returned. Requests without valid credentials get `401`, and if no secret is configured every request is rejected.

For several Znuny systems, for example one per tenant, register your own route with the middleware:

```php
use ItsJustVita\LaravelZnuny\Webhooks\WebhookController;

Route::post('tenants/{tenant}/znuny', WebhookController::class)->middleware('znuny.webhook');
// $event->parameters === ['tenant' => 'acme']
```

## Events

| Event | Fired when |
|---|---|
| `ZnunyRequestSent` | before every HTTP call (`connection`, `operation`, `method`, `path`, redacted `payload`) |
| `ZnunyResponseReceived` | after a successful response (`responseTimeMs`, `status`, `response`) |
| `ZnunyRequestFailed` | when a request fails and the failure reaches your application (`exception`) |
| `ZnunySessionRefreshed` | after an expired session was replaced |
| `ZnunyTicketCreated` | after `create()->save()` |
| `ZnunyTicketUpdated` | after `update()` (`changes`, `dynamicFields`) |
| `ZnunyArticleAdded` | after `addArticle()` (`article`, `articleId`) |
| `ZnunyWebhookReceived` | when Znuny calls the webhook endpoint |

Passwords, session IDs and tokens are replaced with `[REDACTED]` in event payloads, logs and exceptions.

`ZnunyRequestFailed` and an error entry in the log mean that a request failed and the failure reached your application. A failure the package deals with itself is logged as information and fires no event: an expired session that is replaced, a batch of tickets that Znuny rejected and the package split, a new ticket that could not be read back after `save()` (which logs a warning), a session that was already gone at `logout()`, and a dropped session during `session()->user()` in credentials mode. A call that one of your listeners makes is your application's: its failure is reported even while the package is handling another.

## Logging

The package logs to the channel named in `znuny.logging.channel` (`ZNUNY_LOG_CHANNEL`, default `znuny`). If your application does not define that channel, the package registers it as a daily file, `storage/logs/znuny.log`. To log elsewhere, define the channel yourself or point `ZNUNY_LOG_CHANNEL` at an existing one, e.g. `stack`:

```php
// config/logging.php
'channels' => [
    'znuny' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/znuny.log'),
        'level'  => 'debug',
        'days'   => 14,
    ],
],
```

`znuny.logging.log_requests` (info), `log_responses` (debug, with status and duration; attachment contents are shortened) and `log_errors` (error) control what is written. Set `ZNUNY_LOGGING_ENABLED=false` to turn logging off.

## Exceptions

```
ZnunyException (abstract, extends RuntimeException)
├── ZnunyConfigurationException          // invalid config, missing route parameter, nested GET parameter
│   └── ZnunyRouteNotFoundException      // the web service has no route for the request
├── ZnunyConnectionException             // network, timeout, TLS
├── ZnunyAuthenticationException         // wrong credentials, HTTP 401/403
│   └── ZnunySessionExpiredException     // handled by the automatic re-login
├── ZnunyAccessDeniedException           // *.AccessDenied, *.NoPermission, OutOfOffice outside the group admin
├── ZnunyValidationException             // *.MissingParameter, *.InvalidParameter, ...
│   ├── InvalidQueueException
│   ├── InvalidStateException
│   ├── InvalidPriorityException
│   └── InvalidDynamicFieldException
├── ZnunyNotFoundException
│   └── TicketNotFoundException
├── ZnunySearchLimitExceededException    // a search without limit() matches more than search_limit tickets
├── ZnunyRateLimitException
├── ZnunyServerException                 // HTTP 5xx, unexpected responses
└── GenericZnunyException                // everything else
```

Every exception carries `getOperation()`, `getErrorCode()`, `getConnection()`, `getHttpStatus()`, the redacted `getRequestPayload()` and `getResponseBody()`.

## Artisan commands

| Command | Purpose |
|---|---|
| `znuny:test-connection [connection]` | Shows the connection settings, logs in, runs a `TicketSearch` and a `TicketGet` to check routing and permissions, and compares the time zone setting with the dates Znuny returns |
| `znuny:routes [connection]` | Prints the effective route map |
| `znuny:generate-fields [--output=path]` | Generates a typed dynamic field helper from the config |
| `znuny:webservice [connection] [--routes=preset] [--also=operation] [--webhooks=events] [--webhook-url=url] [--with-token] [--sync] [--output=path]` | Generates the Znuny web service definition for the connection's routes, optionally with `TimeAccountingGet`, `OutOfOffice` and a webhook requester |

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Dates are off by one or more hours, or date filters miss tickets | The `timezone` setting does not match Znuny's `OTRSTimeZone`. Set `ZNUNY_TIMEZONE`; `php artisan znuny:test-connection` tells you whether it is right. See [Time zone](#time-zone). |
| `ZnunyRouteNotFoundException` | The routes of the connection do not match the web service. `php artisan znuny:routes` prints the routes the package uses; pick the matching preset or map the routes. See [Routes](#routes). |
| `ZnunyConnectionException` with "Empty reply from server" | The web service named in `base_url` does not exist in Znuny. |
| `ZnunyAccessDeniedException` for a ticket that does not exist | Znuny checks permissions before existence, so a missing ticket is reported as access denied. |
| A search returns exactly 500 tickets although `search_limit` is off | Znuny cut the result off. Remove `ZNUNY_SEARCH_LIMIT=null` to get an exception instead. See [Incomplete results](#incomplete-results). |
| `ZnunySearchLimitExceededException` after upgrading to 1.0 | An empty `ZNUNY_SEARCH_LIMIT=` used to switch the check off and is the default of 500 now. Write `ZNUNY_SEARCH_LIMIT=null` to keep it off. |
| `ZnunySearchLimitExceededException` | A search without `limit()` matches more than `search_limit` tickets. Narrow it down, call `limit()`, or raise `ZNUNY_SEARCH_LIMIT`. See [Incomplete results](#incomplete-results). |
| `ZnunyRouteNotFoundException` for `POST /Session` when calling time accounting or out of office | Znuny's `TimeAccountingREST` and `OutOfOffice` web services have no session route. Give that connection `'auth' => 'credentials'`. See [Time accounting](#time-accounting). |
| `setOutOfOffice()` succeeds but the agent is not out of office | Znuny skips an entry that matches no agent or several and still reports success. Check the login; Znuny's log names the entry. See [Out of office](#out-of-office). |
| `ZnunySessionRefreshed` fires on almost every request | Requests reach Znuny from changing IP addresses, and Znuny deletes a session that another address uses. See [Authentication](#authentication). |

## Testing your application

`Znuny::fake()` answers Znuny operations from fixed responses, so your tests need no Znuny and no knowledge of its REST routes. Resources, search builders and DTOs run as in production.

```php
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAccessDeniedException;
use ItsJustVita\LaravelZnuny\Facades\Znuny;

Znuny::fake([
    'TicketSearch' => ['TicketID' => ['42']],
    'TicketGet'    => Znuny::fakeTickets(['TicketID' => '42', 'Title' => 'Printer on fire']),
    'TicketCreate' => fn (array $payload) => ['TicketID' => '43', 'TicketNumber' => '2026110643'],
    'TicketUpdate' => new ZnunyAccessDeniedException('TicketUpdate: no permission'),
    'TicketHistoryGet' => Znuny::sequence(['TicketHistory' => []], ['TicketHistory' => []]),
]);

// ... run your code ...

Znuny::assertCalled('TicketCreate', fn (array $payload) => $payload['Ticket']['Queue'] === 'Support');
Znuny::assertCalledTimes('TicketGet', 1);
Znuny::assertNotCalled('TicketUpdate');
```

- `fakeTickets()` builds a `TicketGet` response from complete default tickets; pass only the fields that matter.
- `fakeSessionUser()` builds the `SessionGet` response for `Znuny::session()->user()`: `Znuny::fake(['SessionGet' => Znuny::fakeSessionUser(['UserLogin' => 'jane.agent'])])`. `TimeAccountingGet` answers `['TimeAccountingResult' => [['TicketNumber' => '…', 'TicketTitle' => '…', 'Queue' => '…', 'TimeUnit' => '15', 'Created' => '2026-07-01 10:00:00']]]`, `OutOfOffice` and `SessionDelete` (for `logout()`) answer `['Success' => 1]`.
- `findByNumber()` returns a ticket only if its number is exactly the one asked for, so give the faked ticket that number: `Znuny::fakeTickets(['TicketID' => '42', 'TicketNumber' => '2026110610000042'])`.
- A response can be an array, a closure that receives the payload and the connection name, an exception to throw, or a `Znuny::sequence()` that answers one call after the other. `'*'` answers every operation without its own response.
- An operation without a response throws a `StrayZnunyCallException`, so a forgotten fake shows up in the test.
- The fake replaces `ZnunyManager` in the container as well, needs no configured connection and keeps the configured time zone, search limit and batch size. `Znuny::recorded()` returns all calls with their connection and payload.

Laravel's facade mocking and Mockery work as well, because the manager, the client, the resources and the builders are not final: `Znuny::shouldReceive('tickets')->andReturn(Mockery::mock(TicketResource::class))`, `Znuny::partialMock()`. Mock the class your code talks to. A real resource on top of a mocked `ZnunyClient` does not work: the resources call methods of the client that are internal.

The package uses Laravel's HTTP client, so `Http::fake()` works too, if you want to test at the HTTP level:

```php
Http::fake([
    '*/Session'    => Http::response(['SessionID' => 'test']),
    '*/Ticket/42*' => Http::response(['Ticket' => [['TicketID' => '42', 'Title' => 'Hello', 'State' => 'open']]]),
]);

$ticket = Znuny::tickets()->find('42');
```

## Stability

The package follows [semantic versioning](https://semver.org). From 1.0 on, a release that breaks one of the following has a new major version.

**Covered.** For a class named here, every public member is covered unless it is marked `@internal`; the list names what people use most, it does not limit the cover:

- the `Znuny` facade and the public methods of `ZnunyManager`;
- the resources `tickets()`, `session()`, `timeAccounting()` and `users()`, and the builders they return: public methods and documented behaviour;
- of `ZnunyClient`: `call()`, `ping()`, `sessionId()`, `logout()`, `connection()`, `settings()` and `routes()`, with the properties, constants and reading methods of the `ConnectionConfig`, `RouteMap` and `Route` they return (the password and the request options of a connection are internal);
- the data objects `Ticket`, `Article`, `Attachment`, `DynamicField`, `HistoryEntry`, `SessionUser` and `TimeAccountingEntry`: public properties and methods, and construction with named arguments;
- the events: class names, public properties and methods, and construction with named arguments;
- the exceptions: class names, their hierarchy, construction, getters, setters and `context()`;
- the config keys, their defaults and the environment variables;
- the artisan commands: names, arguments, options and exit codes;
- webhooks: the route name and middleware alias `znuny.webhook`, `WebhookController` as a route action, and `ZnunyWebhookReceived`;
- `ZnunyServiceProvider` and its `registerWebhookRoutes()`;
- testing: `Znuny::fake()`, `fakeTickets()`, `fakeSessionUser()`, `sequence()`, the assertions and `recorded()` of the `FakeZnunyManager` that `fake()` returns, `StrayZnunyCallException`, and `FakeResponseSequence` as what `sequence()` returns, without its methods;
- the names of the route presets and the constants of `RoutePresets`.

**Not covered:**

- everything marked `@internal`;
- protected and private members, and subclasses of the package's classes;
- the positional order of constructor parameters;
- the wording of log entries, exception messages and command output, and the exact content of a generated web service file;
- what `Znuny::call()` returns: that is Znuny's response.

**Policy:**

- A deprecation is announced at least one minor release before the removal, with `@deprecated`, a deprecation notice and an entry in [UPGRADE.md](UPGRADE.md).
- Support for a version of PHP, Laravel or Znuny that its maker no longer supports can end in a minor release; the CHANGELOG names it, and Composer keeps your application on the last release that fits it.
- Fixes go into the latest 1.x release. There is no long-term support branch.
- CI tests PHP 8.3 to 8.5, Laravel 12 and 13, and Znuny 6.5, 7.0, 7.1, 7.2 and 7.3.

## Upgrading

See [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md).

## Contributing and security

[CONTRIBUTING.md](CONTRIBUTING.md) says what belongs in the package and how to work on it. Please report a vulnerability privately, as [SECURITY.md](SECURITY.md) describes.

## License

MIT, see [LICENSE.md](LICENSE.md).
