# laravel-znuny

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![Tests](https://img.shields.io/github/actions/workflow/status/itsjustvita/laravel-znuny/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/itsjustvita/laravel-znuny/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![PHP Version](https://img.shields.io/packagist/php-v/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)
[![License](https://img.shields.io/packagist/l/itsjustvita/laravel-znuny.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-znuny)

> Laravel SDK for the Znuny / OTRS Community Edition Generic Interface REST API.

Works out of the box with the **GenericTicketConnectorREST** example web service that Znuny ships, and with any custom web service through configurable routes. You get typed DTOs, a fluent ticket search with batching and pagination, attachments, ticket history, session handling with automatic re-login, inbound webhooks, events, logging, and a generic `call()` for every other operation.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Setting up the web service in Znuny](#setting-up-the-web-service-in-znuny)
- [Configuration](#configuration)
- [Tickets](#tickets)
- [Searching tickets](#searching-tickets)
- [Attachments](#attachments)
- [Calling any operation](#calling-any-operation)
- [Lookups (custom operations)](#lookups-custom-operations)
- [Dynamic fields](#dynamic-fields)
- [Webhooks (Znuny to Laravel)](#webhooks-znuny-to-laravel)
- [Events](#events)
- [Logging](#logging)
- [Exceptions](#exceptions)
- [Artisan commands](#artisan-commands)
- [Testing your application](#testing-your-application)
- [Upgrading](#upgrading)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Znuny 6.5 or 7.x with a Generic Interface web service that has a REST provider. Znuny 6.0 and OTRS 6 work too; set `'auth_transport' => 'payload'` there.

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

## Setting up the web service in Znuny

1. In Znuny open **Admin → Web Services → Add Web Service** and import the example web service `GenericTicketConnectorREST` (Znuny ships it in `var/webservices/examples/`).
2. Keep the name `GenericTicketConnectorREST` or choose your own. The base URL is `https://<host>/otrs/nph-genericinterface.pl/Webservice/<name>`; use your installation's script alias if it is not `/otrs/`.
3. Create an agent for the API and give it permissions on the queues it needs.

The default route preset matches this web service, so nothing else needs to be configured. If your web service was set up from the older example in `development/webservices/` (it searches with `GET /Ticket`), set `'routes' => 'generic-ticket-connector-classic'`. `php artisan znuny:test-connection` tells you whether the routes match.

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

A connection can also set `retries`, `retry_delay` (milliseconds) and `batch_size`; they default to the values under `http`.

### Routes

Znuny maps REST routes to operations per web service. Tell the package which routes yours uses:

| `routes` | Meaning |
|---|---|
| `generic-ticket-connector` (default) | The example web service Znuny ships (`var/webservices/examples/`): `POST /Session`, `GET`/`DELETE /Session/:SessionID`, `POST /Ticket`, `GET /Ticket/:TicketID`, `POST /Ticket/Search`, `PATCH /Ticket/:TicketID`, `GET /Ticket/History/:TicketID` |
| `generic-ticket-connector-classic` | The older example (`development/webservices/`) that many OTRS and Znuny installations were set up from: like the default, but `GET /Ticket` (search), `GET /TicketHistory/:TicketID` and `GET /TicketList` |
| `operation-name` | Routes named after the operations: `POST /<Operation>`, plus `POST /TicketGet/:TicketID`, `POST /TicketUpdate/:TicketID` and `POST /QueueGet/:QueueID` |
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

### Multiple and runtime connections

```php
use ItsJustVita\LaravelZnuny\Facades\Znuny;

Znuny::connection('tenant-a')->tickets()->find('12345');

// Connections that only exist at runtime, e.g. stored per tenant:
Znuny::build([
    'name'     => 'tenant-42',            // optional; used for logs, events and the session cache
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
$history = Znuny::tickets()->history('12345');     // Collection<HistoryEntry>
```

Input keys are camelCase (`queueId`, `customerUser`, `isVisibleForCustomer`, …). Any other key is passed to Znuny unchanged, so `'HistoryComment' => '…'` works too.

`Ticket`, `Article` and the other DTOs expose typed properties plus every raw field Znuny returned:

```php
$ticket->owner;                                    // typed property
$ticket->attribute('EscalationTime');              // raw field
$ticket->attribute('CustomerUser.UserEmail');      // dot notation for nested data
```

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

$page = Znuny::tickets()->where('CustomerID', '12345')->paginate(perPage: 25, page: 1);
$ids  = Znuny::tickets()->query()->whereStateType('open')->ids();

foreach (Znuny::tickets()->whereQueue('Support')->lazy(chunkSize: 100) as $ticket) {
    // processes tickets chunk by chunk
}

// Any TicketSearch parameter:
Znuny::tickets()->where('OwnerIDs', [3, 4])->ids();

// Dynamic field conditions (the default preset searches via POST; for the classic preset see Routes):
Znuny::tickets()->query()->whereDynamicField('OrderId', 'ORD-*', 'Like')->ids();
```

`get()`, `paginate()` and `lazy()` load articles and dynamic fields by default; use `withoutArticles()`, `withoutDynamicFields()` or `withAttachments()` to change that. `paginate()` fetches all matching IDs first and then loads only the tickets of the requested page.

## Attachments

```php
use ItsJustVita\LaravelZnuny\Data\Attachment;

Attachment::fromPath('/path/to/file.pdf');                 // filename and MIME type detected
Attachment::fromContent('report.csv', $csv, 'text/csv');

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

## Calling any operation

Every other operation — Znuny add-ons such as FAQ or ITSM, or your own — goes through the same routing, authentication, error handling, events and logging:

```php
$result = Znuny::call('FAQSearch', ['Title' => 'VPN*']);
$result = Znuny::connection('tenant-a')->call('ConfigItemSearch', ['ConfigItem' => ['Number' => '1000*']]);
```

`call()` returns Znuny's decoded response as an array.

## Lookups (custom operations)

Znuny has no operations for listing queues, states, priorities, types or customer users. If your web service exposes such custom operations, these helpers use them and cache the results (see `cache.ttl`):

| Method | Operation | Expected response |
|---|---|---|
| `Znuny::queues()->all()`, `findByName()` | `QueueList` | `{"Queue": [{"QueueID", "Name", "GroupID", "ValidID", "Comment"}]}` |
| `Znuny::queues()->find($id)` | `QueueGet` (`QueueID`) | `{"Queue": [{...}]}` |
| `Znuny::states()->all()` | `StateList` | `{"State": [{"StateID", "Name", "TypeName", "ValidID"}]}` |
| `Znuny::priorities()->all()` | `PriorityList` | `{"Priority": [{"PriorityID", "Name", "ValidID"}]}` |
| `Znuny::types()->all()` | `TypeList` | `{"Type": [{"TypeID", "Name", "ValidID"}]}` |
| `Znuny::customers()->find($id)` | `CustomerUserGet` (`CustomerUserID`) | `{"CustomerUser": [{"CustomerID", "UserLogin", "UserEmail", ...}]}` |

If the web service does not expose an operation you get a `ZnunyRouteNotFoundException`. Map the operations in `routes` if they use other paths.

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
],
// Invalid values throw InvalidDynamicFieldException before anything is sent (create and update).

// 3. Typed helper generated from that config
php artisan znuny:generate-fields
```

## Webhooks (Znuny to Laravel)

The package can receive Znuny's outgoing events and dispatch them as Laravel events.

1. Enable the endpoint and set a secret:

   ```dotenv
   ZNUNY_WEBHOOKS_ENABLED=true
   ZNUNY_WEBHOOK_TOKEN=a-long-random-string
   ```

   This registers `POST /znuny/webhooks/{event?}` (see `webhooks.route_prefix`) with the `api` middleware group and the `znuny.webhook` authentication middleware.

2. In Znuny create a web service (or extend one) with a **requester**:
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
| `ZnunyRequestFailed` | when a call fails (`exception`) |
| `ZnunySessionRefreshed` | after an expired session was replaced |
| `ZnunyTicketCreated` | after `create()->save()` |
| `ZnunyTicketUpdated` | after `update()` (`changes`, `dynamicFields`) |
| `ZnunyArticleAdded` | after `addArticle()` (`article`, `articleId`) |
| `ZnunyWebhookReceived` | when Znuny calls the webhook endpoint |

Passwords, session IDs and tokens are replaced with `[REDACTED]` in event payloads, logs and exceptions.

## Logging

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
├── ZnunyAccessDeniedException           // *.AccessDenied
├── ZnunyValidationException             // *.MissingParameter, *.InvalidParameter, ...
│   ├── InvalidQueueException
│   ├── InvalidStateException
│   ├── InvalidPriorityException
│   └── InvalidDynamicFieldException
├── ZnunyNotFoundException
│   ├── TicketNotFoundException
│   ├── CustomerNotFoundException
│   └── QueueNotFoundException
├── ZnunyRateLimitException
├── ZnunyServerException                 // HTTP 5xx, unexpected responses
└── GenericZnunyException                // everything else
```

Every exception carries `getOperation()`, `getErrorCode()`, `getConnection()`, `getHttpStatus()`, the redacted `getRequestPayload()` and `getResponseBody()`.

## Artisan commands

| Command | Purpose |
|---|---|
| `znuny:test-connection [connection]` | Shows the connection settings, logs in and runs a `TicketSearch` to check routing and permissions |
| `znuny:routes [connection]` | Prints the effective route map |
| `znuny:cache-clear [connection] [--resource=queues] [--all]` | Clears cached lookups |
| `znuny:generate-fields [--output=path]` | Generates a typed dynamic field helper from the config |

## Testing your application

The package uses Laravel's HTTP client, so `Http::fake()` works:

```php
Http::fake([
    '*/Session'    => Http::response(['SessionID' => 'test']),
    '*/Ticket/42*' => Http::response(['Ticket' => [['TicketID' => '42', 'Title' => 'Hello', 'State' => 'open']]]),
]);

$ticket = Znuny::tickets()->find('42');
```

## Upgrading

See [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE.md](LICENSE.md).
