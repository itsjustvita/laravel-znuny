<?php

namespace ItsJustVita\LaravelZnuny\Facades;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use ItsJustVita\LaravelZnuny\Testing\FakeResponseSequence;
use ItsJustVita\LaravelZnuny\Testing\FakeZnunyManager;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use RuntimeException;

/**
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager connection(?string $name = null)
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager build(array<string, mixed> $config)
 * @method static \ItsJustVita\LaravelZnuny\ZnunyManager usingCredentials(string $baseUrl, string $username, string $password, bool $verifySSL = true, int $timeout = 30, array<string, mixed> $options = [])
 * @method static string connectionName()
 * @method static \ItsJustVita\LaravelZnuny\ZnunyClient client()
 * @method static array<array-key, mixed> call(string $operation, array<string, mixed> $payload = [])
 * @method static \ItsJustVita\LaravelZnuny\Resources\TicketResource tickets()
 * @method static \ItsJustVita\LaravelZnuny\Resources\QueueResource queues()
 * @method static \ItsJustVita\LaravelZnuny\Resources\StateResource states()
 * @method static \ItsJustVita\LaravelZnuny\Resources\PriorityResource priorities()
 * @method static \ItsJustVita\LaravelZnuny\Resources\TypeResource types()
 * @method static \ItsJustVita\LaravelZnuny\Resources\CustomerResource customers()
 * @method static bool ping()
 * @method static void flush(?string $resource = null)
 * @method static void flushAll()
 * @method static void assertCalled(string $operation, \Closure|null $callback = null)
 * @method static void assertCalledTimes(string $operation, int $times)
 * @method static void assertNotCalled(string $operation, \Closure|null $callback = null)
 * @method static void assertNothingCalled()
 * @method static list<array{connection: string, operation: string, payload: array<string, mixed>}> recorded(string|null $operation = null)
 *
 * @see ZnunyManager
 * @see FakeZnunyManager
 */
class Znuny extends Facade
{
    /**
     * Answer Znuny operations from fixed responses instead of sending requests.
     *
     * @param  array<string, mixed>  $responses  by operation name: an array, a Closure(array $payload, string $connection),
     *                                           a Throwable to throw, or Znuny::sequence(...); "*" answers every other operation
     */
    public static function fake(array $responses = []): FakeZnunyManager
    {
        $app = static::getFacadeApplication();

        if (! $app instanceof Application) {
            throw new RuntimeException('Znuny::fake() needs a Laravel application.');
        }

        $fake = new FakeZnunyManager($app, $responses);

        static::swap($fake);
        $app->instance(ZnunyManager::class, $fake);

        return $fake;
    }

    /**
     * A TicketGet response. Each ticket is merged over complete defaults; TicketID
     * defaults to "1", "2", ... by position.
     *
     * @param  array<string, mixed>  ...$tickets
     * @return array{Ticket: list<array<string, mixed>>}
     */
    public static function fakeTickets(array ...$tickets): array
    {
        $rows = [];

        foreach (array_values($tickets) as $position => $ticket) {
            $id = (string) ($ticket['TicketID'] ?? $position + 1);

            $rows[] = array_merge([
                'TicketID' => $id,
                'TicketNumber' => '2026'.str_pad($id, 12, '0', STR_PAD_LEFT),
                'Title' => "Fake ticket {$id}",
                'QueueID' => '2',
                'Queue' => 'Raw',
                'StateID' => '1',
                'State' => 'new',
                'StateType' => 'new',
                'PriorityID' => '3',
                'Priority' => '3 normal',
                'TypeID' => '1',
                'Type' => 'Unclassified',
                'LockID' => '1',
                'Lock' => 'unlock',
                'OwnerID' => '1',
                'Owner' => 'root@localhost',
                'ResponsibleID' => '1',
                'Responsible' => 'root@localhost',
                'Created' => '2026-01-01 08:00:00',
                'Changed' => '2026-01-01 08:00:00',
            ], $ticket);
        }

        return ['Ticket' => $rows];
    }

    /**
     * Responses that are used one per call; an exhausted sequence throws.
     */
    public static function sequence(mixed ...$responses): FakeResponseSequence
    {
        return new FakeResponseSequence(...$responses);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'znuny';
    }
}
