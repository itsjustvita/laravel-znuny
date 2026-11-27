<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Znuny;

/**
 * The lookups need operations Znuny does not ship. They work until 1.0 and say so.
 */
final class DeprecatedLookupsTest extends TestCase
{
    /**
     * @return list<string> the deprecation notices the callback caused
     */
    private function deprecationsOf(Closure $callback): array
    {
        $deprecations = [];

        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }

    private function fakeZnuny(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*QueueList' => Http::response(['Queue' => [['QueueID' => '1', 'Name' => 'Support']]]),
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}> method and what the notice names as replacement
     */
    public static function lookups(): array
    {
        return [
            'queues' => ['queues', "Use Znuny::call('QueueList') instead."],
            'states' => ['states', "Use Znuny::call('StateList') instead."],
            'priorities' => ['priorities', "Use Znuny::call('PriorityList') instead."],
            'types' => ['types', "Use Znuny::call('TypeList') instead."],
            'customers' => ['customers', "Use Znuny::call('CustomerUserGet') instead, and Znuny::tickets()->whereCustomerId() for a customer's tickets."],
        ];
    }

    #[DataProvider('lookups')]
    public function test_a_lookup_says_that_it_is_deprecated_and_what_replaces_it(string $method, string $replacement): void
    {
        $deprecations = $this->deprecationsOf(fn () => Znuny::{$method}());

        $this->assertSame(
            ["Znuny::{$method}() is deprecated and will be removed in 1.0: it needs operations Znuny does not ship. {$replacement}"],
            $deprecations,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cacheMethods(): array
    {
        return ['flush' => ['flush'], 'flushAll' => ['flushAll']];
    }

    #[DataProvider('cacheMethods')]
    public function test_clearing_the_lookup_cache_is_deprecated_with_the_lookups(string $method): void
    {
        $deprecations = $this->deprecationsOf(fn () => Znuny::{$method}());

        $this->assertSame(
            ["Znuny::{$method}() is deprecated and will be removed in 1.0: it clears the cache of the lookups, which need operations Znuny does not ship."],
            $deprecations,
        );
    }

    public function test_deprecated_lookups_still_work(): void
    {
        $this->fakeZnuny();

        $names = [];
        $deprecations = $this->deprecationsOf(function () use (&$names): void {
            $names = Znuny::queues()->all()->pluck('name')->all();
        });

        $this->assertSame(['Support'], $names);
        $this->assertCount(1, $deprecations);
    }

    public function test_they_are_deprecated_against_the_fake_too(): void
    {
        Znuny::fake(['QueueList' => ['Queue' => []]]);

        $this->assertCount(1, $this->deprecationsOf(fn () => Znuny::queues()->all()));
    }

    public function test_the_cache_clear_command_says_that_it_is_deprecated(): void
    {
        $exitCode = null;
        $this->deprecationsOf(function () use (&$exitCode): void {
            $exitCode = Artisan::call('znuny:cache-clear');
        });

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('deprecated', $output);
        $this->assertStringContainsString('1.0', $output);
    }
}
