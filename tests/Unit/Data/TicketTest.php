<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Article;
use ItsJustVita\LaravelZnuny\Data\DynamicField;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class TicketTest extends TestCase
{
    public function test_it_builds_from_fixture(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/../../fixtures/ticket-with-articles.json'), true);

        $ticket = Ticket::fromZnunyResponse($payload);

        $this->assertSame('42', $ticket->id);
        $this->assertSame('202604080001', $ticket->ticketNumber);
        $this->assertSame('Connection issue', $ticket->title);
        $this->assertSame('5', $ticket->queueId);
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

        $this->assertInstanceOf(Carbon::class, $ticket->created);
        $this->assertSame('2026-04-08 10:00:00', $ticket->created->format('Y-m-d H:i:s'));
    }

    public function test_it_handles_minimal_payload(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID' => '1',
            'TicketNumber' => '00001',
            'Title' => 'minimal',
            'QueueID' => '1',
            'Queue' => 'Misc',
            'State' => 'new',
            'Priority' => '3 normal',
            'Created' => '2026-04-08 10:00:00',
        ]);

        $this->assertCount(0, $ticket->articles);
        $this->assertCount(0, $ticket->dynamicFields);
        $this->assertNull($ticket->type);
    }

    public function test_it_handles_missing_created(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID' => '1',
            'TicketNumber' => '00001',
            'Title' => 'no timestamps',
            'QueueID' => '1',
            'Queue' => 'Misc',
            'State' => 'new',
            'Priority' => '3 normal',
        ]);

        $this->assertNull($ticket->created);
        $this->assertNull($ticket->changed);
    }

    public function test_it_exposes_additional_fields_and_raw_attributes(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID' => '7', 'TicketNumber' => '2026', 'Title' => 't', 'QueueID' => '2', 'Queue' => 'Raw',
            'State' => 'open', 'StateID' => '4', 'StateType' => 'open',
            'Priority' => '3 normal', 'PriorityID' => '3', 'Type' => 'Incident', 'TypeID' => '2',
            'Lock' => 'unlock', 'Owner' => 'agent', 'OwnerID' => '5', 'Responsible' => 'lead', 'ResponsibleID' => '6',
            'Service' => '', 'ServiceID' => '', 'SLA' => 'Gold', 'SLAID' => '1', 'EscalationTime' => '0',
        ]);

        $this->assertSame('4', $ticket->stateId);
        $this->assertSame('open', $ticket->stateType);
        $this->assertSame('3', $ticket->priorityId);
        $this->assertSame('2', $ticket->typeId);
        $this->assertSame('unlock', $ticket->lock);
        $this->assertSame('agent', $ticket->owner);
        $this->assertSame('5', $ticket->ownerId);
        $this->assertSame('lead', $ticket->responsible);
        $this->assertSame('6', $ticket->responsibleId);
        $this->assertNull($ticket->service);
        $this->assertNull($ticket->serviceId);
        $this->assertSame('Gold', $ticket->sla);
        $this->assertSame('1', $ticket->slaId);
        $this->assertSame('0', $ticket->attribute('EscalationTime'));
        $this->assertSame('fallback', $ticket->attribute('Missing', 'fallback'));
        $this->assertSame('4', $ticket->toArray()['StateID']);
    }

    public function test_it_parses_ticket_deep_get_payloads(): void
    {
        $ticket = Ticket::fromZnunyResponse([
            'TicketID' => '9', 'TicketNumber' => '1', 'Title' => 't', 'QueueID' => '1', 'Queue' => 'Raw',
            'State' => 'new', 'Priority' => '3 normal', 'CustomerUserID' => 'jane',
            'CustomerUser' => ['UserLogin' => 'jane', 'UserEmail' => 'jane@example.com'],
            'DynamicField_OrderId' => 'ORD-1',
            'Article' => ['ArticleID' => '2', 'Subject' => 'requested'],
            'Articles' => [
                ['ArticleID' => '1', 'Subject' => 'first'],
                ['ArticleID' => '2', 'Subject' => 'requested'],
            ],
        ]);

        $this->assertSame('jane', $ticket->customerUser);
        $this->assertSame(['1', '2'], $ticket->articles->pluck('id')->all());
        $this->assertSame('OrderId', $ticket->dynamicFields->first()->name);
        $this->assertSame('ORD-1', $ticket->dynamicFields->first()->value);
        $this->assertSame('jane@example.com', $ticket->attribute('CustomerUser.UserEmail'));
        $this->assertArrayNotHasKey('Articles', $ticket->attributes);
    }

    public function test_a_single_article_hash_is_accepted(): void
    {
        $ticket = Ticket::fromZnunyResponse(['TicketID' => '1', 'Article' => ['ArticleID' => '5', 'Subject' => 's']]);

        $this->assertSame(['5'], $ticket->articles->pluck('id')->all());
    }
}
