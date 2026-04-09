<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Session;

use Illuminate\Support\Facades\Cache;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class SessionManagerTest extends TestCase
{
    public function test_it_creates_and_caches_session_lazily(): void
    {
        $calls = 0;
        $manager = new SessionManager(
            store: new SessionStore(Cache::store('array'), 'znuny:session:'),
            ttl: 60,
        );

        $creator = function (string $connection) use (&$calls): string {
            $calls++;

            return 'NEW_SESSION_'.$connection;
        };

        $first = $manager->sessionId('default', $creator);
        $second = $manager->sessionId('default', $creator);

        $this->assertSame('NEW_SESSION_default', $first);
        $this->assertSame('NEW_SESSION_default', $second);
        $this->assertSame(1, $calls);
    }

    public function test_it_invalidates_and_recreates_session(): void
    {
        $calls = 0;
        $manager = new SessionManager(
            store: new SessionStore(Cache::store('array'), 'znuny:session:'),
            ttl: 60,
        );

        $creator = function () use (&$calls): string {
            $calls++;

            return 'SESSION_'.$calls;
        };

        $this->assertSame('SESSION_1', $manager->sessionId('default', $creator));
        $manager->invalidate('default');
        $this->assertSame('SESSION_2', $manager->sessionId('default', $creator));
    }

    public function test_it_scopes_sessions_per_connection(): void
    {
        $manager = new SessionManager(
            store: new SessionStore(Cache::store('array'), 'znuny:session:'),
            ttl: 60,
        );

        $creator = fn (string $connection) => 'SESS_'.$connection;

        $this->assertSame('SESS_tenant-a', $manager->sessionId('tenant-a', $creator));
        $this->assertSame('SESS_tenant-b', $manager->sessionId('tenant-b', $creator));
    }
}
