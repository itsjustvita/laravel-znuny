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
}
