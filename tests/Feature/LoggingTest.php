<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class LoggingTest extends TestCase
{
    public function test_successful_request_writes_to_znuny_log_channel(): void
    {
        config()->set('znuny.logging.enabled', true);
        config()->set('znuny.logging.log_requests', true);
        config()->set('znuny.logging.channel', 'znuny');

        Log::shouldReceive('channel')->with('znuny')->andReturnSelf();
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->andReturnNull();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '1', 'TicketNumber' => '1', 'Title' => 't',
                'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->find('1');
    }

    public function test_failed_request_logs_error(): void
    {
        config()->set('znuny.logging.enabled', true);
        config()->set('znuny.logging.log_errors', true);
        config()->set('znuny.logging.channel', 'znuny');

        Log::shouldReceive('channel')->with('znuny')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->atLeast()->once();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Error' => ['ErrorCode' => 'TicketGet.NotFound', 'ErrorMessage' => 'gone']]),
        ]);

        try {
            Znuny::tickets()->find('999');
        } catch (\Throwable) {
        }
    }
}
