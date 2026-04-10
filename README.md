# laravel-znuny

> Modern Laravel SDK for the Znuny / OTRS Community Edition Generic Interface REST API.

Replaces hand-rolled `OTRSService` implementations with a typed, fluent, Laravel-native package: facade-centric API, multi-connection support, cache-backed sessions with auto-retry, typed DTOs, fluent ticket search with pagination, per-resource caching for lookup data, and hybrid dynamic fields.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A reachable Znuny instance with a configured `GenericInterface` webservice exposing `TicketCreate`, `TicketGet`, `TicketUpdate`, `TicketSearch`, `SessionCreate`, `QueueList`, `QueueGet`, `StateList`, `PriorityList`, `TypeList`, `CustomerUserGet`

## Installation

```bash
composer require itsjustvita/laravel-znuny
php artisan vendor:publish --tag=znuny-config
```

Add the required environment variables:

```
ZNUNY_BASE_URL=https://agent.ticket.example.com/otrs/nph-genericinterface.pl/Webservice/td-webservice
ZNUNY_USERNAME=apiuser
ZNUNY_PASSWORD=apipass
ZNUNY_VERIFY_SSL=true
```

## Usage

### Tickets

```php
use Znuny;

// Find
$ticket = Znuny::tickets()->find('12345');
$ticket = Znuny::tickets()->find('12345', withArticles: true, withDynamicFields: true);

// Create
$ticket = Znuny::tickets()->create([
    'title'        => 'Connection issue',
    'queue'        => 'Support',
    'state'        => 'new',
    'priority'     => '3 normal',
    'customerUser' => 'customer@example.com',
    'customerId'   => '12345',
])
->withArticle([
    'subject'     => 'Initial message',
    'body'        => 'Customer reports...',
    'contentType' => 'text/plain; charset=utf8',
])
->withDynamicFields([
    'OrderId'  => 'ORD-2026-001',
    'Severity' => 'high',
])
->save();

// Update
Znuny::tickets()->update('12345', ['state' => 'open']);

// Add an article
Znuny::tickets()->addArticle('12345', [
    'subject'              => 'Internal note',
    'body'                 => 'Customer called back',
    'communicationChannel' => 'Internal',
    'senderType'           => 'agent',
]);

// Close
Znuny::tickets()->close('12345');
```

### Search (fluent builder)

```php
$tickets = Znuny::tickets()
    ->where('CustomerID', '12345')
    ->whereState('open')
    ->whereQueue('Support')
    ->whereCreatedAfter(now()->subDays(30))
    ->orderByDesc('Created')
    ->limit(50)
    ->get();                              // Collection<Ticket>

$paginator = Znuny::tickets()
    ->where('CustomerID', '12345')
    ->paginate(perPage: 25);              // LengthAwarePaginator<Ticket>

$ids = Znuny::tickets()->where('State', 'open')->ids();          // Collection<string>

foreach (Znuny::tickets()->where('State', 'open')->lazy() as $ticket) {
    // process each ticket, chunked fetch under the hood
}
```

> **Note:** `paginate(perPage: 25)` first fetches *all* matching TicketIDs via `TicketSearch`, then batch-fetches the current page. The ID list is cheap but not free -- if you have 10k+ matching tickets, prefer `lazy()` for background jobs.

### Queues / states / priorities / types

```php
$queues = Znuny::queues()->all();                                  // cached 1h
$queue  = Znuny::queues()->find('803');
$queue  = Znuny::queues()->findByName('Support');

// Replaces the old OtrsQueueService:
$queueId = Znuny::queues()->resolve('relocation', businessCustomer: true);

Znuny::states()->all();
Znuny::priorities()->all();
Znuny::types()->all();
```

### Customers

```php
$customer = Znuny::customers()->find('12345');
$tickets  = Znuny::customers()->tickets('12345');
```

### Multi-connection

Config file:

```php
'connections' => [
    'default'  => ['base_url' => env('ZNUNY_BASE_URL'), ...],
    'tenant-a' => ['base_url' => env('TENANT_A_BASE_URL'), ...],
],
```

```php
Znuny::connection('tenant-a')->tickets()->find('12345');
```

Runtime credentials (for dynamic tenants):

```php
Znuny::usingCredentials(
    baseUrl: 'https://otrs.tenant-x.com/...',
    username: 'apiuser',
    password: 'secret',
)->tickets()->find('12345');
```

### Dynamic fields (three ways)

```php
// 1. Raw array -- always works, no setup.
Znuny::tickets()->create([...])->withDynamicFields([
    'CustomerSegment' => 'business',
    'OrderId'         => 'ORD-001',
])->save();

// 2. Config-based validation (config/znuny.php)
'dynamic_fields' => [
    'CustomerSegment' => ['type' => 'string', 'allowed' => ['business', 'private']],
    'OrderId'         => ['type' => 'string'],
    'Priority'        => ['type' => 'integer', 'min' => 1, 'max' => 5],
],
// Invalid values throw InvalidDynamicFieldException.

// 3. Generated typed helper
php artisan znuny:generate-fields

use App\Znuny\DynamicFields;

Znuny::tickets()->create([...])->withDynamicFields(
    DynamicFields::make()
        ->customerSegment('business')
        ->orderId('ORD-001')
        ->priority(3)
        ->toArray(),
)->save();
```

### Events

| Event                         | Fired when                          |
|-------------------------------|-------------------------------------|
| `ZnunyRequestSent`            | before an HTTP call                 |
| `ZnunyResponseReceived`       | after a successful response         |
| `ZnunyRequestFailed`          | on any exception                    |
| `ZnunyTicketCreated`          | after `TicketCreate` succeeds       |
| `ZnunyTicketUpdated`          | after `TicketUpdate` (ticket data)  |
| `ZnunyArticleAdded`           | after `TicketUpdate` (article only) |
| `ZnunySessionRefreshed`       | when a cached session was recreated |

### Logging

Add a dedicated channel to `config/logging.php`:

```php
'channels' => [
    'znuny' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/znuny.log'),
        'level'  => 'debug',
        'days'   => 7,
    ],
],
```

Then set `ZNUNY_LOG_CHANNEL=znuny` and `ZNUNY_LOGGING_ENABLED=true`.

### Exceptions

```
ZnunyException (abstract)
├── ZnunyConnectionException        // network / timeout / SSL
├── ZnunyAuthenticationException
│   └── ZnunySessionExpiredException // handled by auto-retry
├── ZnunyValidationException
│   ├── InvalidQueueException
│   ├── InvalidStateException
│   ├── InvalidPriorityException
│   └── InvalidDynamicFieldException
├── ZnunyNotFoundException
│   ├── TicketNotFoundException
│   ├── CustomerNotFoundException
│   └── QueueNotFoundException
├── ZnunyRateLimitException
└── ZnunyServerException
```

Every exception carries `operation`, `errorCode`, `connection`, sanitized `requestPayload`, and raw `responseBody`.

### Artisan commands

| Command                          | Purpose                                                 |
|----------------------------------|---------------------------------------------------------|
| `znuny:test-connection [conn]`   | Verify credentials by calling `SessionCreate` + `QueueList` |
| `znuny:cache-clear [conn] [--resource=... \| --all]` | Flush lookup caches |
| `znuny:generate-fields`          | Generate a typed `DynamicFields` helper from config     |

### Webhooks (experimental)

Inbound webhooks (Znuny -> Laravel) are a planned feature. The current release ships a disabled-by-default route that returns HTTP 501. Signature verification and payload mapping will arrive in a later release.

## Migration from handwritten OTRSService

See [docs/MIGRATION.md](docs/MIGRATION.md).

## License

MIT.
