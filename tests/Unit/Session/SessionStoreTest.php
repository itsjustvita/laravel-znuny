<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Session;

use Illuminate\Support\Facades\Cache;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class SessionStoreTest extends TestCase
{
    public function test_it_puts_and_retrieves_value(): void
    {
        $store = new SessionStore(Cache::store('array'), 'znuny:session:');

        $store->put('default', 'SESSION_ABC', 60);

        $this->assertSame('SESSION_ABC', $store->get('default'));
    }

    public function test_it_forgets_value(): void
    {
        $store = new SessionStore(Cache::store('array'), 'znuny:session:');
        $store->put('default', 'SESSION_ABC', 60);

        $store->forget('default');

        $this->assertNull($store->get('default'));
    }

    public function test_it_scopes_keys_per_connection(): void
    {
        $store = new SessionStore(Cache::store('array'), 'znuny:session:');

        $store->put('tenant-a', 'SESS_A', 60);
        $store->put('tenant-b', 'SESS_B', 60);

        $this->assertSame('SESS_A', $store->get('tenant-a'));
        $this->assertSame('SESS_B', $store->get('tenant-b'));
    }
}
