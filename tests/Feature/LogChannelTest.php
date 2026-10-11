<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Znuny;

final class LogChannelTest extends TestCase
{
    public function test_a_default_log_channel_is_registered_when_the_app_defines_none(): void
    {
        $this->assertSame('daily', config('logging.channels.znuny.driver'));
        $this->assertSame(storage_path('logs/znuny.log'), config('logging.channels.znuny.path'));
    }

    public function test_requests_are_logged_without_emergency_entries(): void
    {
        $levels = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$levels): void {
            $levels[] = $message->level;
        });

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketSearch' => Http::response(['TicketID' => []]),
        ]);

        Znuny::tickets()->query()->ids();

        $this->assertContains('info', $levels);
        $this->assertNotContains('emergency', $levels);
    }

    protected function useOwnChannel($app): void
    {
        $app['config']->set('logging.channels.znuny', ['driver' => 'single', 'path' => storage_path('logs/own.log')]);
    }

    #[DefineEnvironment('useOwnChannel')]
    public function test_a_channel_defined_by_the_application_is_left_alone(): void
    {
        $this->assertSame('single', config('logging.channels.znuny.driver'));
    }
}
