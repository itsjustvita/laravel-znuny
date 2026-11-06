<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Config;

use DateTimeImmutable;
use DateTimeZone;
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
        $this->assertSame(10000, $config->searchLimit);
        $this->assertSame('UTC', $config->timezone);
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

    public function test_the_search_limit_comes_from_the_connection_then_the_http_config(): void
    {
        $this->assertSame(200, $this->make(['search_limit' => '200'], ['search_limit' => 5000])->searchLimit);
        $this->assertSame(5000, $this->make([], ['search_limit' => 5000])->searchLimit);
        $this->assertSame(1, $this->make(['search_limit' => 0])->searchLimit);
    }

    public function test_the_search_limit_can_be_switched_off(): void
    {
        // null: send no limit and accept whatever Znuny's own SearchLimit returns.
        $this->assertNull($this->make(['search_limit' => null], ['search_limit' => 5000])->searchLimit);
        $this->assertNull($this->make([], ['search_limit' => null])->searchLimit);
        $this->assertNull($this->make(['search_limit' => ''])->searchLimit);
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
        $this->assertFalse($this->make(['verify_ssl' => '0'])->verifySsl);
        $this->assertFalse($this->make(['verify_ssl' => 'off'])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => '1'])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => 'true'])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => true])->verifySsl);
    }

    public function test_a_blank_verify_ssl_value_keeps_verification_on(): void
    {
        // An empty environment variable must not switch certificate verification off.
        $this->assertTrue($this->make(['verify_ssl' => ''])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => ' '])->verifySsl);
        $this->assertTrue($this->make(['verify_ssl' => null])->verifySsl);
    }

    public function test_verify_ssl_accepts_the_path_of_a_ca_bundle(): void
    {
        $bundle = (string) tempnam(sys_get_temp_dir(), 'znuny-ca-');

        try {
            $this->assertSame($bundle, $this->make(['verify_ssl' => $bundle])->verifySsl);
            $this->assertSame(sys_get_temp_dir(), $this->make(['verify_ssl' => sys_get_temp_dir()])->verifySsl);
        } finally {
            unlink($bundle);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidVerifySslValues(): array
    {
        return [
            'typo' => ['treu'],
            'missing file' => ['/no/such/ca-bundle.pem'],
            'number' => [2],
            'list' => [['a']],
        ];
    }

    #[DataProvider('invalidVerifySslValues')]
    public function test_other_verify_ssl_values_are_rejected_instead_of_disabling_verification(mixed $value): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Znuny connection [default] has invalid [verify_ssl] value');

        $this->make(['verify_ssl' => $value]);
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

    public function test_the_time_zone_must_be_valid(): void
    {
        $this->assertSame('Europe/Berlin', $this->make(['timezone' => 'Europe/Berlin'])->timezone);

        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Znuny connection [default] has invalid [timezone] value [Mars/Olympus].');

        $this->make(['timezone' => 'Mars/Olympus']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function timeZonesWithoutDaylightSavingRules(): array
    {
        return [
            'abbreviation' => ['CEST'],
            'offset' => ['+02:00'],
            'GMT offset' => ['GMT+2'],
        ];
    }

    #[DataProvider('timeZonesWithoutDaylightSavingRules')]
    public function test_abbreviations_and_offsets_are_not_accepted_as_time_zone(string $timezone): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage("Znuny connection [default] has invalid [timezone] value [{$timezone}]. Use the IANA name of Znuny's OTRSTimeZone");

        $this->make(['timezone' => $timezone]);
    }

    public function test_the_global_time_zone_is_used_unless_the_connection_sets_one(): void
    {
        $config = ['base_url' => 'https://znuny.test/ws', 'username' => 'agent', 'password' => 'secret'];

        $this->assertSame('Europe/Berlin', ConnectionConfig::fromArray('default', $config, [], 'Europe/Berlin')->timezone);
        $this->assertSame('Asia/Tokyo', ConnectionConfig::fromArray('default', $config + ['timezone' => 'Asia/Tokyo'], [], 'Europe/Berlin')->timezone);
        $this->assertSame('UTC', ConnectionConfig::fromArray('default', $config)->timezone);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}> name, zone used, winter and summer offset in hours
     */
    public static function legacyZoneNames(): array
    {
        return [
            'CET' => ['CET', 'Europe/Brussels', 1, 2],
            'MET' => ['MET', 'Europe/Brussels', 1, 2],
            'EET' => ['EET', 'Europe/Athens', 2, 3],
            'WET' => ['WET', 'Europe/Lisbon', 0, 1],
            'EST' => ['EST', 'America/Panama', -5, -5],
            'MST' => ['MST', 'America/Phoenix', -7, -7],
            'HST' => ['HST', 'Pacific/Honolulu', -10, -10],
        ];
    }

    /**
     * Znuny offers these names for OTRSTimeZone, but PHP reads them as fixed offsets without daylight saving time.
     */
    #[DataProvider('legacyZoneNames')]
    public function test_legacy_zone_names_znuny_offers_are_mapped_to_their_region(string $name, string $zone, int $winter, int $summer): void
    {
        $timezone = new DateTimeZone($this->make(['timezone' => $name])->timezone);

        $this->assertSame($zone, $timezone->getName());
        $this->assertSame($winter * 3600, $timezone->getOffset(new DateTimeImmutable('2026-01-15 12:00:00 UTC')));
        $this->assertSame($summer * 3600, $timezone->getOffset(new DateTimeImmutable('2026-07-15 12:00:00 UTC')));
    }

    public function test_a_blank_connection_time_zone_falls_back_to_the_global_one(): void
    {
        $config = ['base_url' => 'https://znuny.test/ws', 'username' => 'agent', 'password' => 'secret'];

        $this->assertSame('Europe/Berlin', ConnectionConfig::fromArray('default', $config + ['timezone' => ''], [], 'Europe/Berlin')->timezone);
        $this->assertSame('Europe/Berlin', ConnectionConfig::fromArray('default', $config + ['timezone' => ' '], [], 'Europe/Berlin')->timezone);
        $this->assertSame('Europe/Berlin', ConnectionConfig::fromArray('default', $config + ['timezone' => null], [], 'Europe/Berlin')->timezone);
    }

    public function test_iana_time_zone_names_are_accepted(): void
    {
        $this->assertSame('America/Argentina/Buenos_Aires', $this->make(['timezone' => ' America/Argentina/Buenos_Aires '])->timezone);
        $this->assertSame('Etc/GMT-2', $this->make(['timezone' => 'Etc/GMT-2'])->timezone);
        $this->assertSame('UTC', $this->make(['timezone' => ''])->timezone);
        $this->assertSame('UTC', $this->make(['timezone' => null])->timezone);
    }

    public function test_published_config_file_defaults(): void
    {
        $config = require __DIR__.'/../../../config/znuny.php';

        $this->assertSame('generic-ticket-connector', $config['connections']['default']['routes']);
        $this->assertSame('UTC', $config['timezone']);
        $this->assertArrayNotHasKey('timezone', $config['connections']['default']);
        $this->assertSame(50, $config['http']['batch_size']);
        $this->assertSame(10000, $config['http']['search_limit']);
        $this->assertArrayNotHasKey('pool_size', $config['http']);
        $this->assertArrayNotHasKey('queues', $config);
        $this->assertArrayNotHasKey('services', $config['cache']['ttl']);
        $this->assertSame('token', $config['webhooks']['auth']['type']);
    }

    public function test_identity_keys_depend_on_target_and_credentials(): void
    {
        $key = $this->make()->identityKey('app-key');

        $this->assertSame($key, $this->make()->identityKey('app-key'));
        $this->assertStringStartsWith('default:', $key);
        $this->assertStringNotContainsString('secret', $key);
        $this->assertNotSame($key, $this->make(['base_url' => 'https://other.test/Webservice/Test'])->identityKey('app-key'));
        $this->assertNotSame($key, $this->make(['username' => 'someone'])->identityKey('app-key'));
        $this->assertNotSame($key, $this->make(['password' => 'other'])->identityKey('app-key'));
        $this->assertNotSame($key, $this->make(['user_type' => 'customer'])->identityKey('app-key'));
        $this->assertNotSame($key, $this->make()->identityKey('another-app-key'));
    }
}
