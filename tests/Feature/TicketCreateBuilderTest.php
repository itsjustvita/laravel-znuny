<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TicketCreateBuilderTest extends TestCase
{
    public function test_save_creates_ticket_and_returns_dto(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '99', 'TicketNumber' => '202604080099']),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '99',
                'TicketNumber' => '202604080099',
                'Title' => 'Connection issue',
                'QueueID' => '5',
                'Queue' => 'Support',
                'State' => 'new',
                'Priority' => '3 normal',
                'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        $ticket = Znuny::tickets()->create([
            'title' => 'Connection issue',
            'queue' => 'Support',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => 'customer@example.com',
            'customerId' => '12345',
        ])
            ->withArticle([
                'subject' => 'Initial',
                'body' => 'Customer reports',
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
            'title' => 'no article',
            'queue' => 'Support',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => 'c@example.com',
        ])->save();
    }

    public function test_invalid_dynamic_field_throws(): void
    {
        config()->set('znuny.dynamic_fields', [
            'Segment' => ['type' => 'string', 'allowed' => ['business', 'private']],
        ]);

        Http::fake(['*SessionCreate' => Http::response(['SessionID' => 'S1'])]);

        $this->expectException(InvalidDynamicFieldException::class);

        Znuny::tickets()->create([
            'title' => 't', 'queue' => 'Support', 'state' => 'new',
            'priority' => '3 normal', 'customerUser' => 'c@example.com',
        ])
            ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
            ->withDynamicFields(['Segment' => 'government'])
            ->save();
    }

    public function test_queue_id_is_accepted_instead_of_queue_name(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '1', 'TicketNumber' => '1']),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '1',
                'TicketNumber' => '1',
                'Title' => 't',
                'QueueID' => '5',
                'Queue' => 'Support',
                'State' => 'new',
                'Priority' => '3 normal',
                'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->create([
            'title' => 't',
            'queueId' => '5',
            'state' => 'new',
            'priority' => '3 normal',
            'customerUser' => 'c@example.com',
        ])
            ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
            ->save();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'TicketCreate')) {
                return false;
            }

            return ($request['Ticket']['QueueID'] ?? null) === '5'
                && ! isset($request['Ticket']['Queue']);
        });
    }

    public function test_save_sends_attachments(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '99', 'TicketNumber' => '1']),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '99', 'TicketNumber' => '1', 'Title' => 't', 'QueueID' => '1',
                'Queue' => 'Q', 'State' => 'new', 'Priority' => '3 normal', 'Created' => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->create(['title' => 't', 'queue' => 'Q', 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'c@example.com'])
            ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
            ->withAttachments([Attachment::fromContent('a.txt', 'A', 'text/plain')])
            ->save();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'TicketCreate')
            && $request['Attachment'] === [['Filename' => 'a.txt', 'ContentType' => 'text/plain', 'Content' => base64_encode('A')]]);
    }

    public function test_missing_ticket_id_in_the_response_is_a_server_error(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketNumber' => '1']),
        ]);

        $this->expectException(ZnunyServerException::class);

        Znuny::tickets()->create(['title' => 't', 'queue' => 'Q', 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'c@example.com'])
            ->withArticle(['subject' => 's', 'body' => 'b', 'contentType' => 'text/plain'])
            ->save();
    }
}
