<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent;
use ItsJustVita\LaravelZnuny\Events\ZnunyResponseReceived;
use ItsJustVita\LaravelZnuny\Events\ZnunySessionRefreshed;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConnectionException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyRouteNotFoundException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyClient;

final class ZnunyClientTransportTest extends TestCase
{
    private const BASE = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test';

    private function client(array $config = []): ZnunyClient
    {
        return new ZnunyClient(
            connection: 'default',
            config: array_merge([
                'base_url' => self::BASE,
                'username' => 'agent',
                'password' => 'secret',
                'retries' => 0,
            ], $config),
            sessionManager: new SessionManager(new SessionStore(Cache::store('array'), 'znuny:session:'), ttl: 60),
        );
    }

    public function test_stock_routes_create_a_session_and_send_get_requests_with_auth_headers(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'S1']),
            self::BASE.'/Ticket/42*' => Http::response(['Ticket' => [['TicketID' => '42']]]),
        ]);

        $result = $this->client()->call('TicketGet', ['TicketID' => '42', 'DynamicFields' => 1]);

        $this->assertSame('42', $result['Ticket'][0]['TicketID']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/Session'
            && $r['UserLogin'] === 'agent'
            && $r['Password'] === 'secret');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::BASE.'/Ticket/42?DynamicFields=1'
            && $r->header('X-OTRS-Header-SessionID') === ['S1']);
    }

    public function test_credentials_mode_sends_the_login_on_every_request_without_session_create(): void
    {
        Http::fake([self::BASE.'/Ticket*' => Http::response(['TicketID' => ['1']])]);

        $this->client(['auth' => 'credentials', 'routes' => 'generic-ticket-connector-classic'])->call('TicketSearch', ['Limit' => 1]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Ticket?Limit=1'
            && $r->header('X-OTRS-Header-UserLogin') === ['agent']
            && $r->header('X-OTRS-Header-Password') === ['secret']);
    }

    public function test_customer_users_log_in_with_customer_user_login(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'C1']),
            '*' => Http::response(['TicketID' => []]),
        ]);

        $this->client(['user_type' => 'customer'])->call('TicketSearch');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Session'
            && $r['CustomerUserLogin'] === 'agent'
            && ! isset($r['UserLogin']));
    }

    public function test_payload_transport_sends_the_session_in_the_query_string(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'S1']),
            self::BASE.'/Ticket*' => Http::response(['TicketID' => []]),
        ]);

        $this->client(['auth_transport' => 'payload', 'routes' => 'generic-ticket-connector-classic'])->call('TicketSearch', ['States' => ['new', 'open']]);

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Ticket?SessionID=S1&States=new&States=open');
    }

    public function test_an_empty_payload_is_sent_as_a_json_object(): void
    {
        Http::fake(['*' => Http::response(['Queue' => []])]);

        $this->client(['auth' => 'credentials', 'auth_transport' => 'headers'])->call('QueueList');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/QueueList' && $r->body() === '{}');
    }

    public function test_session_expiry_refreshes_once_and_dispatches_an_event(): void
    {
        Event::fake([ZnunySessionRefreshed::class]);

        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            self::BASE.'/Ticket/*' => Http::sequence()
                ->push(['Error' => ['ErrorCode' => 'TicketGet.AuthFail', 'ErrorMessage' => 'TicketGet: Authorization failing!']])
                ->push(['Ticket' => [['TicketID' => '7']]]),
        ]);

        $result = $this->client()->call('TicketGet', ['TicketID' => '7']);

        $this->assertSame('7', $result['Ticket'][0]['TicketID']);
        Event::assertDispatchedTimes(ZnunySessionRefreshed::class, 1);
        Event::assertDispatched(ZnunySessionRefreshed::class, fn (ZnunySessionRefreshed $e) => $e->connection === 'default');
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Ticket/7'
            && $r->header('X-OTRS-Header-SessionID') === ['NEW']);
    }

    public function test_credentials_mode_auth_fail_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['Error' => ['ErrorCode' => 'TicketSearch.AuthFail', 'ErrorMessage' => 'TicketSearch: Authorization failing!']])]);

        try {
            $this->client(['auth' => 'credentials'])->call('TicketSearch');
            $this->fail('Expected ZnunyAuthenticationException');
        } catch (ZnunyAuthenticationException $e) {
            $this->assertNotInstanceOf(ZnunySessionExpiredException::class, $e);
        }

        Http::assertSentCount(1);
    }

    public function test_missing_route_fails_fast_without_http_retries(): void
    {
        Http::fake(['*' => Http::response(
            "HTTP::REST Error while determine Operation for request URI '/QueueList'.",
            500,
            ['Content-Type' => 'text/plain'],
        )]);

        try {
            $this->client(['auth' => 'credentials', 'retries' => 2, 'retry_delay' => 10])->call('QueueList');
            $this->fail('Expected ZnunyRouteNotFoundException');
        } catch (ZnunyRouteNotFoundException $e) {
            $this->assertSame('QueueList', $e->getOperation());
            $this->assertSame(500, $e->getHttpStatus());
        }

        Http::assertSentCount(1);
    }

    public function test_html_success_response_throws_a_server_exception(): void
    {
        Http::fake(['*' => Http::response('<html>proxy</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(ZnunyServerException::class);

        $this->client(['auth' => 'credentials'])->call('TicketSearch');
    }

    public function test_unauthorized_response_without_body_throws_an_authentication_exception(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->expectException(ZnunyAuthenticationException::class);

        $this->client(['auth' => 'credentials'])->call('TicketSearch');
    }

    public function test_configuration_errors_carry_operation_and_connection(): void
    {
        Http::fake(['*' => Http::response(['TicketID' => []])]);

        try {
            $this->client(['auth' => 'credentials'])->call('TicketGet', []);
            $this->fail('Expected ZnunyConfigurationException');
        } catch (ZnunyConfigurationException $e) {
            $this->assertSame('TicketGet', $e->getOperation());
            $this->assertSame('default', $e->getConnection());
        }

        Http::assertNothingSent();
    }

    public function test_events_carry_method_path_status_and_a_redacted_payload(): void
    {
        Event::fake([ZnunyRequestSent::class, ZnunyResponseReceived::class]);
        Http::fake(['*' => Http::response(['TicketID' => []])]);

        $this->client(['auth' => 'credentials', 'routes' => 'generic-ticket-connector-classic'])->call('TicketSearch', ['Title' => 'x']);

        Event::assertDispatched(ZnunyRequestSent::class, fn (ZnunyRequestSent $e) => $e->method === 'GET'
            && $e->path === '/Ticket'
            && $e->payload['Password'] === '[REDACTED]'
            && $e->payload['UserLogin'] === 'agent'
            && $e->payload['Title'] === 'x');
        Event::assertDispatched(ZnunyResponseReceived::class, fn (ZnunyResponseReceived $e) => $e->status === 200);
    }

    public function test_responses_are_logged_when_enabled(): void
    {
        config()->set('znuny.logging.enabled', true);
        config()->set('znuny.logging.log_requests', false);
        config()->set('znuny.logging.log_responses', true);

        Log::shouldReceive('channel')->with('znuny')->andReturnSelf();
        Log::shouldReceive('debug')->once()->withArgs(fn (string $message, array $context) => $message === 'Znuny response'
            && $context['operation'] === 'TicketSearch'
            && $context['status'] === 200
            && isset($context['duration_ms']));

        Http::fake(['*' => Http::response(['TicketID' => ['1']])]);

        $this->client(['auth' => 'credentials'])->call('TicketSearch');
    }

    public function test_ping_in_credentials_mode_runs_a_ticket_search(): void
    {
        Http::fake(['*' => Http::response(['TicketID' => []])]);

        $this->assertTrue($this->client(['auth' => 'credentials'])->ping());

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/Ticket/Search'
            && $r['Limit'] === 1
            && $r['UserLogin'] === 'agent');
    }

    public function test_request_is_a_deprecated_alias_of_call(): void
    {
        Http::fake(['*' => Http::response(['TicketID' => ['1']])]);

        $this->assertSame(['TicketID' => ['1']], $this->client(['auth' => 'credentials'])->request('TicketSearch'));
    }

    public function test_session_ids_are_redacted_in_response_events(): void
    {
        Event::fake([ZnunyResponseReceived::class]);
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'S1']),
            '*' => Http::response(['TicketID' => []]),
        ]);

        $this->client()->call('TicketSearch');

        Event::assertDispatched(ZnunyResponseReceived::class, fn (ZnunyResponseReceived $e) => $e->operation === 'SessionCreate'
            && $e->response === ['SessionID' => '[REDACTED]']);
    }

    public function test_transport_errors_do_not_leak_credentials_from_the_url(): void
    {
        Http::fake(function (Request $request) {
            throw new ConnectionException('cURL error 7: Failed to connect for '.$request->url());
        });

        try {
            $this->client(['auth' => 'credentials', 'auth_transport' => 'payload', 'routes' => 'generic-ticket-connector-classic'])->call('TicketSearch', ['Limit' => 1]);
            $this->fail('Expected ZnunyConnectionException');
        } catch (ZnunyConnectionException $e) {
            $this->assertSame('cURL error 7: Failed to connect for '.self::BASE.'/Ticket', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('secret', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function test_transport_errors_do_not_leak_session_ids_from_the_path(): void
    {
        Http::fake(function (Request $request) {
            throw new ConnectionException('cURL error 28: Operation timed out for '.$request->url());
        });

        try {
            $this->client(['auth' => 'credentials'])->call('SessionGet', ['SessionID' => 'topsecret']);
            $this->fail('Expected ZnunyConnectionException');
        } catch (ZnunyConnectionException $e) {
            $this->assertStringContainsString('/Session/[REDACTED]', $e->getMessage());
            $this->assertStringNotContainsString('topsecret', $e->getMessage());
        }
    }

    public function test_an_empty_reply_points_to_the_web_service_name(): void
    {
        // Znuny closes the connection without an answer for an unknown web service name.
        Http::fake(fn () => throw new ConnectionException('cURL error 52: Empty reply from server'));

        try {
            $this->client(['auth' => 'credentials'])->call('TicketSearch');
            $this->fail('Expected ZnunyConnectionException');
        } catch (ZnunyConnectionException $e) {
            $this->assertStringContainsString('Empty reply from server', $e->getMessage());
            $this->assertStringContainsString('the web service named in base_url does not exist', $e->getMessage());
        }
    }

    public function test_writes_are_not_repeated_after_a_timeout(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });

        try {
            $this->client(['auth' => 'credentials', 'retries' => 2, 'retry_delay' => 10])
                ->call('TicketCreate', ['Ticket' => ['Title' => 'x']]);
            $this->fail('Expected ZnunyConnectionException');
        } catch (ZnunyConnectionException) {
        }

        // Znuny may already have created the ticket; repeating would create a duplicate.
        $this->assertSame(1, $attempts);
    }

    public function test_writes_are_repeated_when_no_connection_could_be_established(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('cURL error 7: Failed to connect to znuny.test port 443');
            }

            return Http::response(['TicketID' => '9', 'TicketNumber' => '1']);
        });

        $result = $this->client(['auth' => 'credentials', 'retries' => 2, 'retry_delay' => 10])
            ->call('TicketCreate', ['Ticket' => ['Title' => 'x']]);

        $this->assertSame('9', $result['TicketID']);
        $this->assertSame(2, $attempts);
    }

    public function test_writes_are_not_repeated_after_a_server_error(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response('{"faultcode":"Server","faultstring":"Operation failed!"}', 500);
        });

        try {
            $this->client(['auth' => 'credentials', 'retries' => 2, 'retry_delay' => 10])
                ->call('TicketUpdate', ['TicketID' => '1', 'Ticket' => ['State' => 'open']]);
            $this->fail('Expected ZnunyServerException');
        } catch (ZnunyServerException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_reads_are_repeated_after_a_timeout(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['TicketID' => ['1']]);
        });

        $result = $this->client(['auth' => 'credentials', 'retries' => 2, 'retry_delay' => 10])
            ->call('TicketSearch', ['Limit' => 1]);

        $this->assertSame(['1'], $result['TicketID']);
        $this->assertSame(3, $attempts);
    }
}
