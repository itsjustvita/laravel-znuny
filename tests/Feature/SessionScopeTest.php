<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * Znuny binds a session to the IP address that created it (SessionCheckRemoteIP), so hosts
 * that share a cache store must not share a session.
 */
final class SessionScopeTest extends TestCase
{
    private function fakeZnuny(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'OWN_SESSION']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
        ]);
    }

    private function sessionKey(string $host): string
    {
        return 'znuny:session:'.substr(hash('sha256', $host), 0, 12).':'.Znuny::client()->identityKey();
    }

    public function test_every_host_keeps_its_own_session(): void
    {
        $this->fakeZnuny();

        // Another host that shares the cache store has already logged in.
        Cache::put($this->sessionKey('another-host'), 'SESSION_OF_ANOTHER_HOST', 600);

        Znuny::call('TicketSearch');

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/TicketSearch') && $r['SessionID'] === 'OWN_SESSION');
        $this->assertSame('OWN_SESSION', Cache::get($this->sessionKey((string) gethostname())));
        $this->assertSame('SESSION_OF_ANOTHER_HOST', Cache::get($this->sessionKey('another-host')));
    }

    public function test_sessions_can_be_shared_between_hosts(): void
    {
        config(['znuny.session.per_host' => false]);
        $this->fakeZnuny();

        Cache::put('znuny:session:'.Znuny::client()->identityKey(), 'SHARED_SESSION', 600);

        Znuny::call('TicketSearch');

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/TicketSearch') && $r['SessionID'] === 'SHARED_SESSION');
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/SessionCreate'));
    }

    public function test_the_session_id_of_a_connection_can_be_read(): void
    {
        $this->fakeZnuny();

        $this->assertSame('OWN_SESSION', Znuny::client()->sessionId());
        $this->assertSame('OWN_SESSION', Znuny::client()->sessionId());

        Http::assertSentCount(1);
    }
}
