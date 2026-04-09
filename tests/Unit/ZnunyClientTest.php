<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Exceptions\ExceptionMapper;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyClient;

final class ZnunyClientTest extends TestCase
{
    private function makeClient(array $config = []): ZnunyClient
    {
        // Default: no HTTP-layer retry in tests unless explicitly enabled.
        config()->set('znuny.http.retries', 0);

        $config = array_merge([
            'base_url' => 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test',
            'username' => 'testuser',
            'password' => 'testpass',
            'verify_ssl' => false,
            'timeout' => 5,
        ], $config);

        return new ZnunyClient(
            connection: 'default',
            config: $config,
            sessionManager: new SessionManager(
                new SessionStore(Cache::store('array'), 'znuny:session:'),
                ttl: 60,
            ),
            exceptionMapper: new ExceptionMapper(),
        );
    }

    public function test_it_creates_a_session_on_first_request(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_ABC'])
            ->push(['Ticket' => [['TicketID' => '42', 'Title' => 'Hello']]]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();
        $result = $client->request('TicketGet', ['TicketID' => '42']);

        $this->assertSame('42', $result['Ticket'][0]['TicketID']);
        Http::assertSentCount(2);
    }

    public function test_it_reuses_cached_session_across_requests(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_ABC'])
            ->push(['Ticket' => [['TicketID' => '1']]])
            ->push(['Ticket' => [['TicketID' => '2']]]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();
        $client->request('TicketGet', ['TicketID' => '1']);
        $client->request('TicketGet', ['TicketID' => '2']);

        // SessionCreate (1) + 2 TicketGet calls = 3 total.
        Http::assertSentCount(3);
    }

    public function test_it_maps_error_responses_to_typed_exceptions(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_ABC'])
            ->push(['Error' => ['ErrorCode' => 'TicketGet.NotFound', 'ErrorMessage' => 'Ticket not found']]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();

        $this->expectException(TicketNotFoundException::class);
        $client->request('TicketGet', ['TicketID' => '9999']);
    }

    public function test_it_maps_session_create_auth_fail_to_authentication_exception(): void
    {
        Http::fake([
            '*' => Http::response(['Error' => ['ErrorCode' => 'SessionCreate.AuthFail', 'ErrorMessage' => 'bad creds']], 200),
        ]);

        $client = $this->makeClient();

        $this->expectException(ZnunyAuthenticationException::class);
        $client->request('TicketGet', ['TicketID' => '1']);
    }

    public function test_it_retries_transient_5xx_at_the_http_layer(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'S1'])                                // SessionCreate succeeds
            ->pushStatus(503)                                             // TicketGet 503 (retry fires)
            ->push(['Ticket' => [['TicketID' => '42']]]);                 // TicketGet retry succeeds

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();

        // makeClient() defaults retries to 0; enable for this test only.
        config()->set('znuny.http.retries', 2);
        config()->set('znuny.http.retry_delay', 10); // keep test fast

        $result = $client->request('TicketGet', ['TicketID' => '42']);

        $this->assertSame('42', $result['Ticket'][0]['TicketID']);
        Http::assertSentCount(3); // SessionCreate + TicketGet(503) + TicketGet(200)
    }

    public function test_per_connection_retry_config_overrides_global(): void
    {
        config()->set('znuny.http.retries', 5);
        config()->set('znuny.http.retry_delay', 1000);

        $sequence = Http::sequence()
            ->push(['SessionID' => 'S1'])
            ->pushStatus(503)
            ->push(['Ticket' => [['TicketID' => '1']]]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient([
            'retries' => 1,       // per-connection override: only 1 retry
            'retry_delay' => 10,  // per-connection override: 10ms
        ]);

        $result = $client->request('TicketGet', ['TicketID' => '1']);

        $this->assertSame('1', $result['Ticket'][0]['TicketID']);
        Http::assertSentCount(3); // SessionCreate + TicketGet(503) + TicketGet(200) — succeeded on first retry
    }

    public function test_it_maps_transport_errors_to_connection_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $client = $this->makeClient();

        try {
            $client->request('TicketGet', ['TicketID' => '1']);
            $this->fail('Expected ZnunyConnectionException');
        } catch (\ItsJustVita\LaravelZnuny\Exceptions\ZnunyConnectionException $e) {
            $this->assertSame('SessionCreate', $e->getOperation()); // the first call is SessionCreate
            $this->assertSame('default', $e->getConnection());
            $this->assertArrayHasKey('UserLogin', $e->getRequestPayload());
            $this->assertSame('Connection refused', $e->getMessage());
        }
    }

    public function test_it_retries_once_on_session_expired_and_succeeds(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_OLD'])
            ->push(['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID expired']])
            ->push(['SessionID' => 'SESS_NEW'])
            ->push(['Ticket' => [['TicketID' => '42']]]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();
        $result = $client->request('TicketGet', ['TicketID' => '42']);

        $this->assertSame(['TicketID' => '42'], $result['Ticket'][0]);
        Http::assertSentCount(4);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'TicketGet')
                && ($request['SessionID'] ?? null) === 'SESS_NEW'
                && ($request['TicketID'] ?? null) === '42';
        });
    }

    public function test_it_does_not_retry_twice_on_session_expired(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_A'])
            ->push(['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID expired']])
            ->push(['SessionID' => 'SESS_B'])
            ->push(['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID expired']]);

        Http::fake(['*' => $sequence]);

        $client = $this->makeClient();

        $this->expectException(\ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException::class);
        $client->request('TicketGet', ['TicketID' => '42']);
    }

    public function test_it_honors_disabled_auto_retry(): void
    {
        $sequence = Http::sequence()
            ->push(['SessionID' => 'SESS_A'])
            ->push(['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'SessionID expired']]);

        Http::fake(['*' => $sequence]);

        config()->set('znuny.session.auto_retry_on_expired', false);

        $client = $this->makeClient();

        $this->expectException(\ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException::class);
        $client->request('TicketGet', ['TicketID' => '42']);
    }
}
