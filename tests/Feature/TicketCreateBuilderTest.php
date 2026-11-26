<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketCreated;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_pending_date_on_create_uses_the_connection_time_zone(): void
    {
        config(['znuny.timezone' => 'Europe/Berlin']);
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '99', 'TicketNumber' => '1']),
            '*TicketGet*' => Http::response(['Ticket' => [['TicketID' => '99', 'Title' => 't']]]),
        ]);

        Znuny::tickets()->create(['title' => 't', 'queue' => 'Q', 'state' => 'pending reminder', 'priority' => '3 normal',
            'customerUser' => 'c@example.com', 'pendingTime' => new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('UTC'))])
            ->withArticle(['subject' => 's', 'body' => 'b'])
            ->save();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'TicketCreate')
            && $request['Ticket']['PendingTime'] === ['Year' => 2026, 'Month' => 7, 'Day' => 1, 'Hour' => 10, 'Minute' => 0]);
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

    /**
     * @return array<string, array{0: \Closure(): mixed}>
     */
    public static function failingReads(): array
    {
        return [
            'the user may create but not read the ticket' => [fn () => Http::response(['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'User does not have access to the ticket!']])],
            'Znuny is down after the create' => [fn () => Http::response('Service Unavailable', 503)],
        ];
    }

    /**
     * Failing would make the caller create the ticket a second time.
     *
     * @param  \Closure(): mixed  $read
     */
    #[DataProvider('failingReads')]
    public function test_a_created_ticket_that_cannot_be_read_back_is_returned_from_the_create_response(\Closure $read): void
    {
        config(['znuny.connections.default.retries' => 0]);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketCreate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '2026112610000042', 'ArticleID' => '7']),
            '*TicketGet*' => $read,
        ]);

        $events = [];
        $warnings = [];
        Event::listen(function (ZnunyTicketCreated $event) use (&$events): void {
            $events[] = $event;
        });
        Event::listen(function (MessageLogged $message) use (&$warnings): void {
            if ($message->level === 'warning') {
                $warnings[] = $message->message;
            }
        });

        $ticket = Znuny::tickets()->create(['title' => 'Printer on fire', 'queueId' => 5, 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'jane'])
            ->withArticle(['subject' => 's', 'body' => 'b'])
            ->save();

        $this->assertSame('42', $ticket->id);
        $this->assertSame('2026112610000042', $ticket->ticketNumber);
        $this->assertSame('Printer on fire', $ticket->title);
        $this->assertSame('5', $ticket->queueId);
        $this->assertSame('new', $ticket->state);
        $this->assertSame('jane', $ticket->customerUser);
        $this->assertNull($ticket->created);
        $this->assertCount(0, $ticket->articles);

        $this->assertCount(1, $events);
        $this->assertSame($ticket, $events[0]->ticket);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('ticket 42', $warnings[0]);
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'TicketCreate')));
    }

    public function test_ticket_data_in_the_create_response_is_used_when_the_ticket_cannot_be_read_back(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            // The operation's IncludeTicketData option adds the stored ticket to the response.
            '*TicketCreate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '2026112610000042', 'ArticleID' => '7', 'Ticket' => [
                'TicketID' => '42', 'TicketNumber' => '2026112610000042', 'Title' => 'As Znuny stored it', 'QueueID' => '5', 'Queue' => 'Support',
                'State' => 'open', 'Priority' => '3 normal', 'Created' => '2026-11-26 19:00:00',
                'Article' => [['ArticleID' => '7', 'Subject' => 's', 'Body' => 'b']],
                'DynamicField' => [['Name' => 'OrderId', 'Value' => 'ORD-1']],
            ]]),
            '*TicketGet*' => Http::response(['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'User does not have access to the ticket!']]),
        ]);

        $ticket = Znuny::tickets()->create(['title' => 'As sent', 'queue' => 'Support', 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'jane'])
            ->withArticle(['subject' => 's', 'body' => 'b'])
            ->save();

        $this->assertSame('As Znuny stored it', $ticket->title);
        $this->assertSame('open', $ticket->state);
        $this->assertSame('2026-11-26 19:00:00', $ticket->created?->format('Y-m-d H:i:s'));
        $this->assertSame(['7'], $ticket->articles->pluck('id')->all());
        $this->assertSame(['OrderId'], $ticket->dynamicFields->pluck('name')->all());
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
