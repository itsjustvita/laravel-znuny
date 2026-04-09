<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use Znuny;

final class MultiConnectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('znuny.connections.tenant-a', [
            'base_url'   => 'https://tenant-a.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username'   => 'userA',
            'password'   => 'passA',
            'verify_ssl' => false,
            'timeout'    => 5,
        ]);

        $app['config']->set('znuny.connections.tenant-b', [
            'base_url'   => 'https://tenant-b.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username'   => 'userB',
            'password'   => 'passB',
            'verify_ssl' => false,
            'timeout'    => 5,
        ]);
    }

    public function test_default_connection_is_resolved(): void
    {
        $manager = Znuny::getFacadeRoot();
        $this->assertInstanceOf(ZnunyManager::class, $manager);
        $this->assertSame('default', $manager->connectionName());
    }

    public function test_named_connections_do_not_mutate_singleton(): void
    {
        $a = Znuny::connection('tenant-a');
        $b = Znuny::connection('tenant-b');

        $this->assertSame('tenant-a', $a->connectionName());
        $this->assertSame('tenant-b', $b->connectionName());
        $this->assertSame('default', Znuny::getFacadeRoot()->connectionName(), 'Original manager must still report default');
    }

    public function test_runtime_credentials_produce_ephemeral_manager(): void
    {
        $m = Znuny::usingCredentials(
            baseUrl: 'https://ephemeral.test/otrs/nph-genericinterface.pl/Webservice/test',
            username: 'eph',
            password: 'eph',
        );

        $this->assertSame('ephemeral', $m->connectionName());
    }

    public function test_unknown_connection_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Znuny connection [nonexistent] is not configured');

        Znuny::connection('nonexistent')->client();
    }

    public function test_each_connection_uses_its_own_credentials(): void
    {
        Http::fake([
            '*tenant-a.test*' => Http::sequence()
                ->push(['SessionID' => 'A_SESS'])
                ->push(['Ticket' => [[
                    'TicketID' => '1', 'TicketNumber' => '1', 'Title' => 'A',
                    'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                    'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
                ]]]),
            '*tenant-b.test*' => Http::sequence()
                ->push(['SessionID' => 'B_SESS'])
                ->push(['Ticket' => [[
                    'TicketID' => '2', 'TicketNumber' => '2', 'Title' => 'B',
                    'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                    'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
                ]]]),
        ]);

        Znuny::connection('tenant-a')->tickets()->find('1');
        Znuny::connection('tenant-b')->tickets()->find('2');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'tenant-a.test') && ($r['UserLogin'] ?? null) === 'userA');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tenant-b.test') && ($r['UserLogin'] ?? null) === 'userB');
    }
}
