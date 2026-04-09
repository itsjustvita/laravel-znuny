<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TicketCreateBuilderTest extends TestCase
{
    public function test_save_creates_ticket_and_returns_dto(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '99', 'TicketNumber' => '202604080099']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID'     => '99',
                'TicketNumber' => '202604080099',
                'Title'        => 'Connection issue',
                'QueueID'      => '803',
                'Queue'        => 'Support',
                'State'        => 'new',
                'Priority'     => '3 normal',
                'Created'      => '2026-04-08 10:00:00',
            ]]]),
        ]);

        $ticket = Znuny::tickets()->create([
            'title'        => 'Connection issue',
            'queue'        => 'Support',
            'state'        => 'new',
            'priority'     => '3 normal',
            'customerUser' => 'customer@example.com',
            'customerId'   => '12345',
        ])
        ->withArticle([
            'subject'     => 'Initial',
            'body'        => 'Customer reports',
            'contentType' => 'text/plain; charset=utf8',
        ])
        ->withDynamicFields([
            'OrderId' => 'ORD-2026-001',
        ])
        ->save();

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('99', $ticket->id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'TicketCreate')) {
                return false;
            }
            return $request['Ticket']['Title'] === 'Connection issue'
                && $request['Ticket']['Queue'] === 'Support'
                && $request['Article']['Subject'] === 'Initial'
                && $request['DynamicField'][0]['Name'] === 'OrderId'
                && $request['DynamicField'][0]['Value'] === 'ORD-2026-001';
        });
    }

    public function test_create_fails_without_article(): void
    {
        Http::fake(['*SessionCreate' => Http::response(['SessionID' => 'S1'])]);

        $this->expectException(\InvalidArgumentException::class);
        Znuny::tickets()->create([
            'title'        => 'no article',
            'queue'        => 'Support',
            'state'        => 'new',
            'priority'     => '3 normal',
            'customerUser' => 'c@example.com',
        ])->save();
    }

    public function test_queue_id_is_accepted_instead_of_queue_name(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '1', 'TicketNumber' => '1']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID'     => '1',
                'TicketNumber' => '1',
                'Title'        => 't',
                'QueueID'      => '803',
                'Queue'        => 'Support',
                'State'        => 'new',
                'Priority'     => '3 normal',
                'Created'      => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->create([
            'title'        => 't',
            'queueId'      => '803',
            'state'        => 'new',
            'priority'     => '3 normal',
            'customerUser' => 'c@example.com',
        ])
        ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
        ->save();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'TicketCreate')) {
                return false;
            }
            return ($request['Ticket']['QueueID'] ?? null) === '803'
                && ! isset($request['Ticket']['Queue']);
        });
    }
}
