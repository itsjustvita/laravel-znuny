<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Config;

use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectionConfigTest extends TestCase
{
    private function make(array $overrides = [], array $http = []): ConnectionConfig
    {
        return ConnectionConfig::fromArray('default', array_merge([
            'base_url' => 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/Test/',
            'username' => 'agent',
            'password' => 'secret',
        ], $overrides), $http);
    }

    public function test_minimal_config_uses_documented_defaults(): void
    {
        $config = $this->make();

        $this->assertSame('default', $config->name);
        $this->assertSame('https://znuny.test/otrs/nph-genericinterface.pl/Webservice/Test', $config->baseUrl);
        $this->assertSame('generic-ticket-connector', $config->routes->preset());
        $this->assertSame('session', $config->auth);
        $this->assertSame('agent', $config->userType);
        $this->assertSame('auto', $config->authTransport);
        $this->assertTrue($config->verifySsl);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(3, $config->connectTimeout);
        $this->assertSame(3, $config->retries);
        $this->assertSame(100, $config->retryDelay);
        $this->assertSame(50, $config->batchSize);
        $this->assertTrue($config->usesSession());
        $this->assertSame('UserLogin', $config->loginKey());
    }

    public function test_connection_values_override_global_http_values(): void
    {
        $config = $this->make(['retries' => 1, 'batch_size' => 10], ['retries' => 5, 'retry_delay' => 250, 'batch_size' => 20]);

        $this->assertSame(1, $config->retries);
        $this->assertSame(250, $config->retryDelay);
        $this->assertSame(10, $config->batchSize);
    }

    public function test_enum_values_are_normalised(): void
    {
        $config = $this->make(['auth' => 'Credentials', 'user_type' => 'CUSTOMER', 'auth_transport' => 'Headers']);

        $this->assertFalse($config->usesSession());
        $this->assertSame('CustomerUserLogin', $config->loginKey());
        $this->assertSame('headers', $config->authTransport);
    }

    public function test_verify_ssl_accepts_string_values(): void
    {
        $this->assertFalse($this->make(['verify_ssl' => 'false'])->verifySsl);
        $this->assertFalse($this->make(['verify_ssl' => false])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => '1'])->verifySsl);
    }

    #[DataProvider('missingKeys')]
    public function test_missing_required_keys_throw(string $key): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage("Znuny connection [default] is missing [{$key}].");

        $this->make([$key => null]);
    }

    public static function missingKeys(): array
    {
        return [['base_url'], ['username'], ['password']];
    }

    public function test_invalid_enum_value_throws(): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Znuny connection [default] has invalid [auth] value [oauth]. Allowed: session, credentials.');

        $this->make(['auth' => 'oauth']);
    }

    public function test_published_config_file_defaults(): void
    {
        $config = require __DIR__.'/../../../config/znuny.php';

        $this->assertSame('generic-ticket-connector', $config['connections']['default']['routes']);
        $this->assertSame(50, $config['http']['batch_size']);
        $this->assertArrayNotHasKey('pool_size', $config['http']);
        $this->assertArrayNotHasKey('queues', $config);
        $this->assertArrayNotHasKey('services', $config['cache']['ttl']);
        $this->assertSame('token', $config['webhooks']['auth']['type']);
    }
}
