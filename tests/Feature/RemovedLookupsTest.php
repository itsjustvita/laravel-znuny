<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Znuny;

/**
 * 1.0 has a typed method for an operation if, and only if, Znuny ships it. The lookups of
 * 0.x needed operations Znuny does not ship; such operations go through Znuny::call().
 */
final class RemovedLookupsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function removedMethods(): array
    {
        return array_combine(
            $methods = ['queues', 'states', 'priorities', 'types', 'customers', 'flush', 'flushAll'],
            array_map(fn (string $method): array => [$method], $methods),
        );
    }

    #[DataProvider('removedMethods')]
    public function test_the_lookup_methods_are_gone(string $method): void
    {
        $this->assertFalse(method_exists(ZnunyManager::class, $method));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function removedClasses(): array
    {
        $classes = [
            'Resources\QueueResource', 'Resources\StateResource', 'Resources\PriorityResource', 'Resources\TypeResource', 'Resources\CustomerResource',
            'Data\Queue', 'Data\State', 'Data\Priority', 'Data\Type', 'Data\Customer',
            'Caching\CacheManager', 'Commands\CacheClearCommand',
            'Exceptions\QueueNotFoundException', 'Exceptions\CustomerNotFoundException',
        ];

        return array_combine($classes, array_map(fn (string $class): array => ['ItsJustVita\\LaravelZnuny\\'.$class], $classes));
    }

    #[DataProvider('removedClasses')]
    public function test_their_classes_are_gone(string $class): void
    {
        $this->assertFalse(class_exists($class));
    }

    public function test_the_cache_command_and_the_cache_settings_are_gone(): void
    {
        $this->assertArrayNotHasKey('znuny:cache-clear', Artisan::all());
        $this->assertNull(config('znuny.cache'));
    }

    public function test_a_config_file_with_the_old_cache_block_is_ignored(): void
    {
        // What a config file published by 0.4 or earlier still contains.
        config(['znuny.cache' => [
            'enabled' => true,
            'store' => null,
            'prefix' => 'znuny:lookup:',
            'ttl' => ['queues' => 3600, 'priorities' => 3600, 'states' => 3600, 'types' => 3600, 'customers' => 600],
        ]]);

        (new ZnunyServiceProvider($this->app))->register();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [['TicketID' => '1', 'Title' => 'Still works']]]),
        ]);

        $this->assertSame('Still works', Znuny::tickets()->find('1')->title);
    }

    public function test_a_custom_operation_is_reached_through_call(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/QueueList' => Http::response(['Queue' => [['QueueID' => '1', 'Name' => 'Support']]]),
        ]);

        $this->assertSame([['QueueID' => '1', 'Name' => 'Support']], Znuny::call('QueueList')['Queue']);
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function notFound(): array
    {
        return [
            'a custom queue operation' => ['QueueGet', ZnunyNotFoundException::class],
            'a custom customer operation' => ['CustomerUserGet', ZnunyNotFoundException::class],
            'a ticket operation' => ['TicketGet', TicketNotFoundException::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('notFound')]
    public function test_not_found_errors_of_custom_operations_raise_the_general_exception(string $operation, string $class): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*' => Http::response(['Error' => ['ErrorCode' => $operation.'.NotFound', 'ErrorMessage' => 'Not found']]),
        ]);

        try {
            Znuny::call($operation, ['TicketID' => '1', 'QueueID' => '1']);
            $this->fail('Znuny answered an error.');
        } catch (ZnunyException $e) {
            $this->assertSame($class, $e::class);
        }
    }

    public function test_a_custom_read_is_repeated_on_a_get_route_only(): void
    {
        config(['znuny.connections.default.retries' => 2, 'znuny.connections.default.retry_delay' => 10]);

        $count = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'Queue'))->count();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*' => Http::response('Bad Gateway', 502),
        ]);

        // The package cannot know that a custom operation only reads, so a POST is sent once.
        try {
            Znuny::call('QueueList');
            $this->fail('Znuny answered an error.');
        } catch (ZnunyServerException) {
        }

        $this->assertSame(1, $count());

        // On a GET route every operation is repeated.
        config(['znuny.connections.custom' => ['routes' => ['extends' => 'operation-name', 'QueueList' => 'GET /Queues']] + config('znuny.connections.default')]);

        try {
            Znuny::connection('custom')->call('QueueList');
            $this->fail('Znuny answered an error.');
        } catch (ZnunyServerException) {
        }

        $this->assertSame(1 + 3, $count());
    }
}
