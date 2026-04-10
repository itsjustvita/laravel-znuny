<?php

namespace ItsJustVita\LaravelZnuny\Tests;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Facades\Znuny;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [ZnunyServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Znuny' => Znuny::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('znuny.connections.default', [
            'base_url' => 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'testuser',
            'password' => 'testpass',
            'verify_ssl' => false,
            'timeout' => 5,
        ]);
    }
}
