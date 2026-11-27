<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunySessionExpiredException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\ZnunyServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Znuny;

/**
 * An empty variable in .env, such as ZNUNY_SESSION_TTL=, arrives as a blank string. It
 * counts as not set, so the default applies.
 */
final class BlankSettingsTest extends TestCase
{
    private function fakeZnuny(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
        ]);
    }

    private function requestsTo(string $operation): int
    {
        return collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/'.$operation))->count();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function blank(): array
    {
        return ['an empty string' => [''], 'spaces' => ['  '], 'null' => [null]];
    }

    #[DataProvider('blank')]
    public function test_a_blank_session_ttl_keeps_the_session_cached(mixed $ttl): void
    {
        config(['znuny.session.ttl' => $ttl]);
        $this->fakeZnuny();

        Znuny::call('TicketSearch');
        Znuny::call('TicketSearch');
        Znuny::call('TicketSearch');

        // A time to live of 0 would store nothing, so every request would log in again.
        $this->assertSame(1, $this->requestsTo('SessionCreate'));
    }

    public function test_a_session_ttl_that_is_no_number_is_rejected(): void
    {
        config(['znuny.session.ttl' => 'six hours']);
        $this->fakeZnuny();

        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('The Znuny configuration has invalid [session.ttl] value [six hours]. Use a whole number.');

        Znuny::call('TicketSearch');
    }

    #[DataProvider('blank')]
    public function test_a_blank_default_connection_is_the_connection_named_default(mixed $value): void
    {
        config(['znuny.default' => $value]);

        $this->assertSame('default', Znuny::connectionName());
        $this->artisan('znuny:routes')->expectsOutputToContain('Connection [default]')->assertExitCode(0);
    }

    #[DataProvider('blank')]
    public function test_blank_connection_choices_take_their_defaults(mixed $value): void
    {
        config(['znuny.connections.default.auth' => $value, 'znuny.connections.default.user_type' => $value, 'znuny.connections.default.auth_transport' => $value]);

        $settings = Znuny::client()->settings();

        $this->assertSame(['session', 'agent', 'auto'], [$settings->auth, $settings->userType, $settings->authTransport]);
    }

    /**
     * @return list<string> the messages the package logged
     */
    private function logged(\Closure $callback): array
    {
        $messages = [];
        Event::listen(function (MessageLogged $message) use (&$messages): void {
            $messages[] = $message->message;
        });

        $callback();

        return $messages;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function logSwitches(): array
    {
        return ['logging.enabled' => ['enabled'], 'logging.log_requests' => ['log_requests']];
    }

    #[DataProvider('logSwitches')]
    public function test_a_blank_log_switch_keeps_logging_on(string $switch): void
    {
        foreach (['', '  ', null] as $value) {
            config(["znuny.logging.{$switch}" => $value]);
            $this->fakeZnuny();

            $this->assertContains('Znuny request', $this->logged(fn () => Znuny::call('TicketSearch')));
        }
    }

    #[DataProvider('logSwitches')]
    public function test_a_log_switch_is_switched_off_with_any_boolean_like_value(string $switch): void
    {
        foreach ([false, 'false', 'off', 'no', '0', 0] as $value) {
            config(["znuny.logging.{$switch}" => $value]);
            $this->fakeZnuny();

            $this->assertNotContains('Znuny request', $this->logged(fn () => Znuny::call('TicketSearch')), var_export($value, true));
        }
    }

    #[DataProvider('blank')]
    public function test_a_blank_log_channel_is_the_channel_znuny(mixed $value): void
    {
        config(['znuny.logging.channel' => $value]);
        $this->fakeZnuny();

        Log::shouldReceive('channel')->with('znuny')->atLeast()->once()->andReturnSelf();
        Log::shouldReceive('info')->atLeast()->once();

        Znuny::call('TicketSearch');
    }

    private function fakeExpiredSession(): void
    {
        Http::fake([
            '*SessionCreate' => Http::sequence()->push(['SessionID' => 'OLD'])->push(['SessionID' => 'NEW']),
            '*TicketSearch' => Http::sequence()
                ->push(['Error' => ['ErrorCode' => 'TicketSearch.AuthFail', 'ErrorMessage' => 'TicketSearch: Authorization failing!']])
                ->push(['TicketID' => ['7']]),
        ]);
    }

    #[DataProvider('blank')]
    public function test_a_blank_retry_switch_keeps_replacing_expired_sessions(mixed $value): void
    {
        config(['znuny.session.auto_retry_on_expired' => $value]);
        $this->fakeExpiredSession();

        $this->assertSame(['TicketID' => ['7']], Znuny::call('TicketSearch'));
    }

    public function test_the_retry_switch_is_switched_off_with_the_word_false(): void
    {
        config(['znuny.session.auto_retry_on_expired' => 'false']);
        $this->fakeExpiredSession();

        $this->expectException(ZnunySessionExpiredException::class);

        Znuny::call('TicketSearch');
    }

    #[DataProvider('blank')]
    public function test_a_blank_session_prefix_is_the_default_prefix(mixed $value): void
    {
        config(['znuny.session.cache_prefix' => $value, 'znuny.session.per_host' => false]);
        $this->fakeZnuny();

        Znuny::call('TicketSearch');

        $this->assertSame('S1', Cache::get('znuny:session:'.Znuny::client()->identityKey()));
    }

    #[DataProvider('blank')]
    public function test_blank_webhook_settings_take_their_defaults(mixed $value): void
    {
        // Off by default: a blank switch does not open the endpoint.
        config(['znuny.webhooks.enabled' => $value]);
        (new ZnunyServiceProvider($this->app))->boot();
        Route::getRoutes()->refreshNameLookups();
        $this->assertFalse(Route::has('znuny.webhook'));

        config(['znuny.webhooks.enabled' => 'true', 'znuny.webhooks.route_prefix' => $value]);
        (new ZnunyServiceProvider($this->app))->boot();
        Route::getRoutes()->refreshNameLookups();
        $this->assertSame('znuny/webhooks/{event?}', Route::getRoutes()->getByName('znuny.webhook')?->uri());

        // The token is read from the default header.
        config(['znuny.webhooks.auth.token' => 'secret-token', 'znuny.webhooks.auth.header' => $value]);
        $this->postJson('/znuny/webhooks', [], ['X-Znuny-Token' => 'secret-token'])->assertOk();
    }

    public function test_a_blank_webhook_auth_type_still_rejects(): void
    {
        config(['znuny.webhooks.enabled' => true, 'znuny.webhooks.auth.token' => 'secret-token']);
        (new ZnunyServiceProvider($this->app))->boot();

        // This one fails closed: a blank type is no type, not the default one.
        foreach (['', '  '] as $type) {
            config(['znuny.webhooks.auth.type' => $type]);
            $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Bearer secret-token'])->assertUnauthorized();
        }

        // A type that is not set at all is the token type, which needs the token.
        config(['znuny.webhooks.auth.type' => null]);
        $this->postJson('/znuny/webhooks', [], ['Authorization' => 'Bearer secret-token'])->assertOk();
        $this->postJson('/znuny/webhooks', [])->assertUnauthorized();
    }
}
