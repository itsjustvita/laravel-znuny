<?php

namespace ItsJustVita\LaravelZnuny\Testing;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use ItsJustVita\LaravelZnuny\Caching\CacheManager;
use ItsJustVita\LaravelZnuny\ZnunyClient;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The manager behind Znuny::fake(): every connection answers from the fake responses and
 * records its calls, so tests can assert what the application asked Znuny for.
 */
class FakeZnunyManager extends ZnunyManager
{
    /** Used for connections that are not configured in the test environment. */
    private const FAKE_CONNECTION = [
        'base_url' => 'https://znuny.fake/otrs/nph-genericinterface.pl/Webservice/fake',
        'username' => 'fake',
        'password' => 'fake',
    ];

    protected FakeZnunyState $state;

    /**
     * @param  array<string, mixed>  $responses  by operation name; "*" answers every other operation
     */
    public function __construct(Application $app, array $responses = [])
    {
        parent::__construct($app);

        $this->state = new FakeZnunyState($responses);
    }

    public function client(): ZnunyClient
    {
        $connection = $this->connectionName();

        return $this->clients[$connection] ??= new FakeZnunyClient($connection, $this->fakeConfig($connection), $this->state);
    }

    /**
     * Assert that an operation was called, optionally with data the callback accepts.
     *
     * @param  (Closure(array<string, mixed>, string): bool)|null  $callback  receives the payload and the connection name
     */
    public function assertCalled(string $operation, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($operation, $callback),
            "Expected Znuny operation [{$operation}] to be called".($callback !== null ? ' with matching data.' : '.'),
        );
    }

    public function assertCalledTimes(string $operation, int $times): void
    {
        $count = count($this->recorded($operation));

        PHPUnit::assertSame($times, $count, "Expected Znuny operation [{$operation}] to be called {$times} times, but it was called {$count} times.");
    }

    /**
     * @param  (Closure(array<string, mixed>, string): bool)|null  $callback  receives the payload and the connection name
     */
    public function assertNotCalled(string $operation, ?Closure $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->matching($operation, $callback),
            "Unexpected call of Znuny operation [{$operation}]".($callback !== null ? ' with matching data.' : '.'),
        );
    }

    public function assertNothingCalled(): void
    {
        $operations = array_column($this->recorded(), 'operation');

        PHPUnit::assertEmpty($operations, 'Expected no Znuny calls, but these were made: '.implode(', ', $operations).'.');
    }

    /**
     * @return list<array{connection: string, operation: string, payload: array<string, mixed>}>
     */
    public function recorded(?string $operation = null): array
    {
        return $this->state->calls($operation);
    }

    /**
     * Lookups are not cached, so every call reaches the fake responses.
     */
    protected function buildCacheManager(): CacheManager
    {
        return new CacheManager(new Repository(new ArrayStore), 'znuny:fake:', [], false);
    }

    /**
     * The connection's configuration as far as it exists, so its time zone, search limit and
     * batch size apply, completed with placeholders for what tests usually leave out.
     *
     * @return array<string, mixed>
     */
    protected function fakeConfig(string $connection): array
    {
        $config = $this->runtimeConfig ?? (array) $this->config("znuny.connections.{$connection}", []);

        return array_merge(self::FAKE_CONNECTION, array_filter($config, fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  (Closure(array<string, mixed>, string): bool)|null  $callback
     * @return list<array{connection: string, operation: string, payload: array<string, mixed>}>
     */
    private function matching(string $operation, ?Closure $callback): array
    {
        return array_values(array_filter(
            $this->recorded($operation),
            fn (array $call): bool => $callback === null || $callback($call['payload'], $call['connection']),
        ));
    }
}
