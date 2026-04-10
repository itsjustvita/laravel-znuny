# Migration from handwritten `OTRSService`

This guide maps the three existing in-house implementations (`connect-api`, `teledata-kundenportal`, `tuenet-kundenportal`) onto `itsjustvita/laravel-znuny`.

## At a glance

| Before                                                      | After                                                     |
|-------------------------------------------------------------|-----------------------------------------------------------|
| `app(OTRSService::class)`                                    | `Znuny::tickets()` / `Znuny::queues()` / ...              |
| `$otrs->createTicket($ticket, $article, $dynamicFields)`     | `Znuny::tickets()->create($ticket)->withArticle($article)->withDynamicFields($dynamicFields)->save()` |
| `$otrs->getTicket($id, $withArticles, $withDynamic)`         | `Znuny::tickets()->find($id, withArticles: $withArticles, withDynamicFields: $withDynamic)` |
| `$otrs->updateTicket($id, ['State' => 'closed successful'])` | `Znuny::tickets()->update($id, ['state' => 'closed successful'])` |
| `$otrs->closeTicket($id)`                                    | `Znuny::tickets()->close($id)`                            |
| `$otrs->addArticle($id, $subject, $body, $contentType)`      | `Znuny::tickets()->addArticle($id, [...])`                |
| `$otrs->searchTickets(['CustomerID' => 'x'])`                | `Znuny::tickets()->where('CustomerID', 'x')->ids()`       |
| `$otrs->getTicketsByCustomer($id)` (has N+1)                 | `Znuny::tickets()->where('CustomerID', $id)->get()` (batched) |
| `app(OtrsQueueService::class)->resolveQueue('relocation', $isBusiness)` | `Znuny::queues()->resolve('relocation', businessCustomer: $isBusiness)` |
| `['Title' => ...]`                                           | `['title' => ...]` (camelCase keys)                       |
| `isset($response['Error'])` manual error handling            | typed `ZnunyException` subclasses                         |

## Step 1: Install and publish config

```bash
composer require itsjustvita/laravel-znuny
php artisan vendor:publish --tag=znuny-config
```

Copy env vars:

```
ZNUNY_BASE_URL={{ your OTRS_API_BASE_URL }}
ZNUNY_USERNAME={{ your OTRS_API_USERNAME }}
ZNUNY_PASSWORD={{ your OTRS_API_PASSWORD }}
ZNUNY_VERIFY_SSL={{ your OTRS_API_VERIFY_SSL }}
```

## Step 2: Translate queue resolution

If you use `OtrsQueueService`, move the queue map into `config/znuny.php`:

```php
'queues' => [
    'default' => env('ZNUNY_DEFAULT_QUEUE_ID', '803'),
    'resolution' => [
        'business' => [
            'data_changes'      => env('ZNUNY_QUEUE_DATA_CHANGES_BUSINESS'),
            'relocation'        => env('ZNUNY_QUEUE_RELOCATION_BUSINESS'),
            'cancellation'      => env('ZNUNY_QUEUE_CANCELLATION_BUSINESS'),
            'tariff_change'     => env('ZNUNY_QUEUE_TARIFF_CHANGE_BUSINESS'),
            'contract_transfer' => env('ZNUNY_QUEUE_CONTRACT_TRANSFER_BUSINESS'),
        ],
        'private' => [
            'data_changes'      => env('ZNUNY_QUEUE_DATA_CHANGES_PRIVATE'),
            'relocation'        => env('ZNUNY_QUEUE_RELOCATION_PRIVATE'),
            // ...
        ],
    ],
],
```

Replace:

```php
$queueId = app(OtrsQueueService::class)->resolveQueue('relocation', $customer->isBusiness());
```

with:

```php
$queueId = Znuny::queues()->resolve('relocation', businessCustomer: $customer->isBusiness());
```

## Step 3: Rewrite call sites

### createTicket

Before:

```php
$otrs = app(OTRSService::class);
$result = $otrs->createTicket([
    'Title'        => 'Connection issue',
    'QueueID'      => $queueId,
    'State'        => 'new',
    'PriorityID'   => '3',
    'CustomerUser' => $email,
    'CustomerID'   => $customerId,
], [
    'Subject'     => 'Initial',
    'Body'        => 'Reports...',
    'ContentType' => 'text/plain; charset=utf8',
], [
    ['Name' => 'OrderId', 'Value' => 'ORD-001'],
]);
```

After:

```php
$ticket = Znuny::tickets()->create([
    'title'        => 'Connection issue',
    'queueId'      => $queueId,
    'state'        => 'new',
    'priorityId'   => '3',
    'customerUser' => $email,
    'customerId'   => $customerId,
])
->withArticle([
    'subject'     => 'Initial',
    'body'        => 'Reports...',
    'contentType' => 'text/plain; charset=utf8',
])
->withDynamicFields(['OrderId' => 'ORD-001'])
->save();
```

Note: `save()` returns a `Ticket` DTO (fully hydrated). The old API returned `['ticketId' => ..., 'ticketNumber' => ...]`. If you only need the ID, use `$ticket->id`.

### getTicket

Before:

```php
$ticket = $otrs->getTicket($id, withArticles: true);
```

After:

```php
$ticket = Znuny::tickets()->find($id, withArticles: true);
```

Both return a `Ticket` DTO, but the old DTO used `int $id` while the new one uses `string $id` -- cast explicitly if you need integers.

### searchTickets / getTicketsByCustomer

Before:

```php
$tickets = $otrs->getTicketsByCustomer('12345'); // N+1 problem
```

After:

```php
$tickets = Znuny::tickets()
    ->where('CustomerID', '12345')
    ->orderByDesc('Created')
    ->get();  // batched fetch, no N+1
```

For large result sets, prefer `lazy()`:

```php
foreach (Znuny::tickets()->where('CustomerID', '12345')->lazy() as $ticket) {
    // ...
}
```

## Step 4: Replace error handling

Before:

```php
try {
    $otrs->createTicket(...);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'AuthFail')) {
        // guess it was auth
    }
    Log::error('OTRS error', ['message' => $e->getMessage()]);
}
```

After:

```php
use ItsJustVita\LaravelZnuny\Exceptions\InvalidQueueException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConnectionException;

try {
    Znuny::tickets()->create(...)->...;
} catch (InvalidQueueException $e) {
    // bad queue
} catch (ZnunyAuthenticationException $e) {
    // credentials
} catch (ZnunyConnectionException $e) {
    // network
} catch (TicketNotFoundException $e) {
    // 404
}
```

## Step 5: Delete the old files

Once all call sites are migrated:

```
rm app/Services/OTRSService.php
rm app/Services/Contracts/OTRSServiceInterface.php
rm app/Services/OtrsQueueService.php            # if present
rm app/Data/Ticket.php                           # replaced by the package DTO
```

Update the service container binding: remove the bindings in `AppServiceProvider` or wherever `OTRSService::class` was resolved.

## Step 6: Smoke-test

```bash
php artisan znuny:test-connection
```

Should print `Znuny connection [default] OK`. If not, double-check env vars and SSL settings.

## Behavioral differences to be aware of

1. **Session handling**: the old service created a new session per request instance. The new package caches sessions across requests via the cache store. Expired-session errors are auto-retried once -- if your previous code caught `AuthFail` to retry manually, you can drop that wrapper.
2. **Queue resolution**: the old `OtrsQueueService` used a tenant-provided string for `ticket_type`. The new `resolve()` uses a configurable key per customer segment. Keys are user-defined strings -- add new types directly in `config/znuny.php`.
3. **DTO ID types**: IDs are `string` in the new DTOs. Existing code that does `$ticket->id + 1` or compares against `int` will need explicit casts.
4. **`updateTicket`**: the old service accepted raw Znuny keys (`['State' => 'open']`). The new one uses camelCase (`['state' => 'open']`) and maps internally.
5. **Pagination**: the old service had no pagination. `paginate()` prefetches all matching IDs; for huge result sets, prefer `lazy()`.
