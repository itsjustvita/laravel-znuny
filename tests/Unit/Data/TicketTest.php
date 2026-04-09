<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Article;
use ItsJustVita\LaravelZnuny\Data\DynamicField;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class TicketTest extends TestCase
{
    public function test_it_builds_from_fixture(): void
    {
        $payload = json_decode(file_get_contents(__DIR__ . '/../../fixtures/ticket-with-articles.json'), true);

        $ticket = Ticket::fromZnunyResponse($payload);

        $this->assertSame('42', $ticket->id);
        $this->assertSame('202604080001', $ticket->ticketNumber);
        $this->assertSame('Connection issue', $ticket->title);
        $this->assertSame('803', $ticket->queueId);
        $this->assertSame('Support', $ticket->queue);
        $this->assertSame('open', $ticket->state);
        $this->assertSame('3 normal', $ticket->priority);
        $this->assertSame('Incident', $ticket->type);
        $this->assertSame('12345', $ticket->customerId);
        $this->assertSame('customer@example.com', $ticket->customerUser);

        $this->assertInstanceOf(Collection::class, $ticket->articles);
        $this->assertCount(1, $ticket->articles);
        $this->assertInstanceOf(Article::class, $ticket->articles->first());

        $this->assertInstanceOf(Collection::class, $ticket->dynamicFields);
        $this->assertCount(2, $ticket->dynamicFields);
        $this->assertInstanceOf(DynamicField::class, $ticket->dynamicFields->first());
        $this->assertSame('OrderId', $ticket->dynamicFields->first()->name);

        $this->assertInstanceOf(\Carbon\Carbon::class, $ticket->created);
        $this->assertSame('2026-04-08 10:00:00', $ticket->created->format('Y-m-d H:i:s'));
    }

    public function test_it_handles_minimal_payload(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID'     => '1',
            'TicketNumber' => '00001',
            'Title'        => 'minimal',
            'QueueID'      => '1',
            'Queue'        => 'Misc',
            'State'        => 'new',
            'Priority'     => '3 normal',
            'Created'      => '2026-04-08 10:00:00',
        ]);

        $this->assertCount(0, $ticket->articles);
        $this->assertCount(0, $ticket->dynamicFields);
        $this->assertNull($ticket->type);
    }

    public function test_it_handles_missing_created(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID'     => '1',
            'TicketNumber' => '00001',
            'Title'        => 'no timestamps',
            'QueueID'      => '1',
            'Queue'        => 'Misc',
            'State'        => 'new',
            'Priority'     => '3 normal',
        ]);

        $this->assertNull($ticket->created);
        $this->assertNull($ticket->changed);
    }
}
