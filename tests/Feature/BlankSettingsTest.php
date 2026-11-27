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
}
