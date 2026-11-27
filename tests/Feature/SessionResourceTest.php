<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyAuthenticationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * Against the routes of Znuny's GenericTicketConnectorREST: POST /Session,
 * GET /Session/:SessionID and DELETE /Session/:SessionID.
 */
final class SessionResourceTest extends TestCase
{
    private const BASE = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test';

    private const INVALID = ['Error' => ['ErrorCode' => 'SessionGet.SessionInvalid', 'ErrorMessage' => 'SessionGet: SessionID is Invalid!']];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('znuny.connections.default.routes', 'generic-ticket-connector');
    }

    /**
     * @return array{SessionData: list<array{Key: string, Value: string}>}
     */
    private function sessionOf(string $login): array
    {
        return ['SessionData' => [
            ['Key' => 'UserID', 'Value' => '5'],
            ['Key' => 'UserLogin', 'Value' => $login],
            ['Key' => 'UserType', 'Value' => 'User'],
        ]];
    }

    /**
     * @return list<string> "METHOD path" of every request sent, in order
     */
    private function sent(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): string => $pair[0]->method().' '.str_replace(self::BASE, '', $pair[0]->url()))
            ->all();
    }

    public function test_user_returns_the_user_of_the_connection(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'S1']),
            self::BASE.'/Session/S1' => Http::response($this->sessionOf('api-agent')),
        ]);

        $user = Znuny::session()->user();

        $this->assertSame('api-agent', $user->login);
        $this->assertSame('5', $user->id);
        $this->assertSame(['POST /Session', 'GET /Session/S1'], $this->sent());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->header('X-OTRS-Header-SessionID') === ['S1']);
    }

    public function test_a_dropped_session_is_replaced_and_read_again(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            self::BASE.'/Session/OLD' => Http::response(self::INVALID),
            self::BASE.'/Session/NEW' => Http::response($this->sessionOf('api-agent')),
        ]);

        $this->assertSame('api-agent', Znuny::session()->user()->login);

        // The repeat describes the new session, not the one Znuny dropped.
        $this->assertSame(['POST /Session', 'GET /Session/OLD', 'POST /Session', 'GET /Session/NEW'], $this->sent());
    }

    public function test_user_logs_in_once_in_credentials_mode(): void
    {
        config(['znuny.connections.default.auth' => 'credentials']);

        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'S1']),
            self::BASE.'/Session/S1' => Http::response($this->sessionOf('testuser')),
        ]);

        $this->assertSame('testuser', Znuny::session()->user()->login);
        $this->assertSame('testuser', Znuny::session()->user()->login);

        $this->assertSame(['POST /Session', 'GET /Session/S1', 'GET /Session/S1'], $this->sent());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->header('X-OTRS-Header-UserLogin') === ['testuser']);
    }

    public function test_a_dropped_session_is_replaced_in_credentials_mode_too(): void
    {
        config(['znuny.connections.default.auth' => 'credentials']);

        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            self::BASE.'/Session/OLD' => Http::response(self::INVALID),
            self::BASE.'/Session/NEW' => Http::response($this->sessionOf('testuser')),
        ]);

        $this->assertSame('testuser', Znuny::session()->user()->login);
        $this->assertSame(['POST /Session', 'GET /Session/OLD', 'POST /Session', 'GET /Session/NEW'], $this->sent());
    }

    public function test_logout_removes_the_session_in_znuny_and_forgets_it(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'S1'])->push(['SessionID' => 'S2']),
            self::BASE.'/Session/S1' => Http::response(['Success' => 1]),
            self::BASE.'/Session/S2' => Http::response($this->sessionOf('api-agent')),
        ]);

        Znuny::client()->sessionId();
        Znuny::session()->logout();
        Znuny::session()->user();

        $this->assertSame(['POST /Session', 'DELETE /Session/S1', 'POST /Session', 'GET /Session/S2'], $this->sent());

        // Znuny up to 6.5.11 and 7.1 wants the credentials for it, newer versions the session ID.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && $r->data() === ['UserLogin' => 'testuser', 'Password' => 'testpass', 'SessionID' => 'S1']);
    }

    public function test_logout_without_a_session_sends_nothing(): void
    {
        Http::fake();

        Znuny::session()->logout();

        Http::assertNothingSent();
    }

    public function test_logout_of_a_session_znuny_no_longer_knows_is_quiet(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'S1'])->push(['SessionID' => 'S2']),
            self::BASE.'/Session/S1' => Http::response(['Error' => ['ErrorCode' => 'SessionRemove.Fail', 'ErrorMessage' => "SessionRemove: Could not remove session with ID 'S1'."]]),
        ]);

        Znuny::client()->sessionId();
        Znuny::session()->logout();

        $this->assertSame('S2', Znuny::client()->sessionId());
    }

    public function test_a_session_id_in_znunys_error_message_is_not_published(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['SessionID' => 'SESSIONabc123def456']),
            self::BASE.'/Session/*' => Http::response(['Error' => [
                'ErrorCode' => 'SessionRemove.Fail',
                'ErrorMessage' => "SessionRemove: Could not remove session with ID 'SESSIONabc123def456'.",
            ]]),
        ]);

        $published = [];
        Event::listen(function (MessageLogged $message) use (&$published): void {
            $published[] = [$message->message, $message->context];
        });
        Event::listen(function (ZnunyRequestFailed $event) use (&$published): void {
            $published[] = [$event->exception->getMessage(), $event->exception->context(), $event->payload];
        });

        Znuny::client()->sessionId();
        Znuny::session()->logout();

        $this->assertNotSame([], $published);
        $this->assertStringNotContainsString('SESSIONabc123def456', (string) json_encode($published));
        $this->assertStringContainsString('Could not remove session with ID', (string) json_encode($published));
    }

    public function test_a_password_in_znunys_error_message_is_not_published(): void
    {
        Http::fake([
            self::BASE.'/Session' => Http::response(['Error' => [
                'ErrorCode' => 'SessionCreate.AuthFail',
                'ErrorMessage' => 'SessionCreate: Authorization failing for testuser with testpass!',
            ]]),
        ]);

        try {
            Znuny::client()->sessionId();
            $this->fail('The login must fail.');
        } catch (ZnunyAuthenticationException $e) {
            $this->assertSame('[SessionCreate.AuthFail] SessionCreate: Authorization failing for testuser with [REDACTED]!', $e->getMessage());
            $this->assertStringNotContainsString('testpass', (string) json_encode($e->context()));
        }
    }

    public function test_a_failed_logout_still_forgets_the_session(): void
    {
        config(['znuny.connections.default.retries' => 0]);

        Http::fake([
            self::BASE.'/Session' => Http::sequence()->push(['SessionID' => 'S1'])->push(['SessionID' => 'S2']),
            self::BASE.'/Session/S1' => Http::response('Service Unavailable', 503),
        ]);

        Znuny::client()->sessionId();

        try {
            Znuny::session()->logout();
            $this->fail('A failed logout must be reported.');
        } catch (ZnunyServerException) {
        }

        $this->assertSame('S2', Znuny::client()->sessionId());
    }
}
