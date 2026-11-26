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
        // Like a new Laravel 13 application: the store serializes its values and reads no
        // objects back (cache.serializable_classes), so nothing the package caches may be an object.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => true]);
        $app['config']->set('cache.serializable_classes', false);

        // Most feature tests describe v0.1-style operation-name routes (POST /<Operation>).
        // The stock GenericTicketConnectorREST routes are covered by GenericTicketConnectorTest
        // and ZnunyClientTransportTest.
        $app['config']->set('znuny.connections.default', [
            'base_url' => 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'testuser',
            'password' => 'testpass',
            'routes' => 'operation-name',
            'verify_ssl' => false,
            'timeout' => 5,
        ]);
    }
}
