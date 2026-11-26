<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Znuny;

/**
 * An empty variable in .env, such as ZNUNY_SESSION_TTL=, arrives as a blank string. It
 * counts as not set, so the default applies.
 */
final class BlankSettingsTest extends TestCase
{
    private function fakeZnuny(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
            '*QueueList' => Http::response(['Queue' => [['QueueID' => '1', 'Name' => 'Support']]]),
        ]);
    }

    private function requestsTo(string $operation): int
    {
        return collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/'.$operation))->count();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function blank(): array
    {
        return ['an empty string' => [''], 'spaces' => ['  '], 'null' => [null]];
    }

    #[DataProvider('blank')]
    public function test_a_blank_session_ttl_keeps_the_session_cached(mixed $ttl): void
    {
        config(['znuny.session.ttl' => $ttl]);
        $this->fakeZnuny();

        Znuny::call('TicketSearch');
        Znuny::call('TicketSearch');
        Znuny::call('TicketSearch');

        // A time to live of 0 would store nothing, so every request would log in again.
        $this->assertSame(1, $this->requestsTo('SessionCreate'));
    }

    public function test_a_session_ttl_that_is_no_number_is_rejected(): void
    {
        config(['znuny.session.ttl' => 'six hours']);
        $this->fakeZnuny();

        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('The Znuny configuration has invalid [session.ttl] value [six hours]. Use a whole number.');

        Znuny::call('TicketSearch');
    }

    #[DataProvider('blank')]
    public function test_a_blank_cache_switch_keeps_the_lookup_cache_on(mixed $enabled): void
    {
        config(['znuny.cache.enabled' => $enabled]);
        $this->fakeZnuny();

        Znuny::queues()->all();
        Znuny::queues()->all();

        $this->assertSame(1, $this->requestsTo('QueueList'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function switchedOff(): array
    {
        return ['false' => [false], '"false"' => ['false'], '"off"' => ['off'], '"no"' => ['no'], '"0"' => ['0'], '0' => [0]];
    }

    #[DataProvider('switchedOff')]
    public function test_the_lookup_cache_can_be_switched_off_with_any_boolean_like_value(mixed $enabled): void
    {
        config(['znuny.cache.enabled' => $enabled]);
        $this->fakeZnuny();

        Znuny::queues()->all();
        Znuny::queues()->all();

        $this->assertSame(2, $this->requestsTo('QueueList'));
    }
}
