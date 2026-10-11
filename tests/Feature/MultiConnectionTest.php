<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyManager;
use Znuny;

final class MultiConnectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('znuny.connections.tenant-a', [
            'base_url' => 'https://tenant-a.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'userA',
            'password' => 'passA',
            'verify_ssl' => false,
            'timeout' => 5,
        ]);

        $app['config']->set('znuny.connections.tenant-b', [
            'base_url' => 'https://tenant-b.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'userB',
            'password' => 'passB',
            'verify_ssl' => false,
            'timeout' => 5,
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

        $this->assertStringStartsWith('ephemeral_', $m->connectionName());
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

    public function test_build_creates_a_runtime_connection_with_its_own_routes(): void
    {
        Http::fake([
            'https://runtime.test/*/SessionCreate' => Http::response(['SessionID' => 'R1']),
            'https://runtime.test/*/TicketSearch' => Http::response(['TicketID' => ['1']]),
        ]);

        $result = Znuny::build([
            'base_url' => 'https://runtime.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'rt',
            'password' => 'pw',
            'routes' => 'operation-name',
        ])->call('TicketSearch', ['Limit' => 1]);

        $this->assertSame(['TicketID' => ['1']], $result);
    }

    public function test_build_uses_the_given_name(): void
    {
        $manager = Znuny::build(['name' => 'tenant-42', 'base_url' => 'https://x.test', 'username' => 'u', 'password' => 'p']);

        $this->assertSame('tenant-42', $manager->connectionName());
        $this->assertSame('tenant-42', $manager->client()->connection());
    }

    public function test_runtime_connection_names_differ_per_user_type(): void
    {
        $base = ['base_url' => 'https://x.test', 'username' => 'u', 'password' => 'p'];

        $this->assertNotSame(
            Znuny::build($base)->connectionName(),
            Znuny::build($base + ['user_type' => 'customer'])->connectionName(),
        );
    }

    public function test_using_credentials_accepts_options(): void
    {
        Http::fake([
            '*/SessionCreate' => Http::response(['SessionID' => 'E1']),
            '*/TicketGet/5' => Http::response(['Ticket' => [[
                'TicketID' => '5', 'TicketNumber' => '5', 'Title' => 't',
                'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::usingCredentials(
            baseUrl: 'https://eph.test/otrs/nph-genericinterface.pl/Webservice/test',
            username: 'eph',
            password: 'pw',
            options: ['routes' => 'operation-name'],
        )->tickets()->find('5');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://eph.test/otrs/nph-genericinterface.pl/Webservice/test/TicketGet/5');
    }

    public function test_call_on_the_facade_uses_the_default_connection(): void
    {
        Http::fake([
            '*/SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*/FAQSearch' => Http::response(['ID' => ['3']]),
        ]);

        $this->assertSame(['ID' => ['3']], Znuny::call('FAQSearch', ['Title' => 'VPN*']));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'znuny.test') && ($r['Title'] ?? null) === 'VPN*');
    }

    public function test_incomplete_connection_config_throws_configuration_exception(): void
    {
        config()->set('znuny.connections.broken', ['base_url' => 'https://x.test']);

        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Znuny connection [broken] is missing [username].');

        Znuny::connection('broken')->client();
    }

    public function test_a_runtime_connection_never_reuses_the_session_of_a_connection_with_the_same_name(): void
    {
        Http::fake([
            'https://znuny.test/*/SessionCreate' => Http::response(['SessionID' => 'DEFAULT_SESSION']),
            'https://znuny.test/*/TicketSearch' => Http::response(['TicketID' => []]),
            'https://elsewhere.test/*/SessionCreate' => Http::response(['SessionID' => 'OTHER_SESSION']),
            'https://elsewhere.test/*/TicketSearch' => Http::response(['TicketID' => []]),
        ]);

        Znuny::call('TicketSearch');
        Znuny::build([
            'name' => 'default',
            'base_url' => 'https://elsewhere.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'someone',
            'password' => 'else',
            'routes' => 'operation-name',
        ])->call('TicketSearch');

        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://elsewhere.test')
            && ($r['SessionID'] ?? null) === 'DEFAULT_SESSION');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://elsewhere.test/otrs/nph-genericinterface.pl/Webservice/test/SessionCreate');
    }

    public function test_runtime_connections_with_different_passwords_do_not_share_a_session(): void
    {
        Http::fake([
            '*/SessionCreate' => Http::sequence()->push(['SessionID' => 'FIRST'])->push(['SessionID' => 'SECOND']),
            '*/TicketSearch' => Http::response(['TicketID' => []]),
        ]);

        $config = [
            'base_url' => 'https://shared.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'agent',
            'routes' => 'operation-name',
        ];

        Znuny::build($config + ['password' => 'right'])->call('TicketSearch');
        Znuny::build($config + ['password' => 'wrong'])->call('TicketSearch');

        $this->assertCount(2, collect(Http::recorded())->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/SessionCreate')));
    }
}
