<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestFailed;
use ItsJustVita\LaravelZnuny\Events\ZnunyRequestSent;
use ItsJustVita\LaravelZnuny\Events\ZnunyResponseReceived;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketCreated;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class EventDispatchTest extends TestCase
{
    public function test_successful_request_fires_sent_and_received_events(): void
    {
        Event::fake();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID' => '1', 'TicketNumber' => '1', 'Title' => 't',
                'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->find('1');

        Event::assertDispatched(ZnunyRequestSent::class);
        Event::assertDispatched(ZnunyResponseReceived::class);
    }

    public function test_failed_request_fires_failed_event(): void
    {
        Event::fake();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Error' => ['ErrorCode' => 'TicketGet.NotFound', 'ErrorMessage' => 'gone']]),
        ]);

        try {
            Znuny::tickets()->find('999');
        } catch (\Throwable) {}

        Event::assertDispatched(ZnunyRequestFailed::class);
    }

    public function test_successful_create_fires_ticket_created_event(): void
    {
        Event::fake();

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID' => '42', 'TicketNumber' => '00042', 'Title' => 't',
                'QueueID' => '1', 'Queue' => 'Q', 'State' => 'new',
                'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->create([
            'title' => 't', 'queue' => 'Q', 'state' => 'new',
            'priority' => '3 normal', 'customerUser' => 'c@example.com',
        ])
        ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
        ->save();

        Event::assertDispatched(ZnunyTicketCreated::class);
    }
}
