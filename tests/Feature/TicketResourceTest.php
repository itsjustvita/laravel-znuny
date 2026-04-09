<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TicketResourceTest extends TestCase
{
    public function test_find_returns_ticket_dto(): void
    {
        $payload = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/ticket-with-articles.json'),
            true,
        );

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Ticket' => [$payload]]),
        ]);

        $ticket = Znuny::tickets()->find('42');

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('42', $ticket->id);
    }

    public function test_find_with_articles_passes_all_articles_flag(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID'     => '1',
                'TicketNumber' => '1',
                'Title'        => 't',
                'QueueID'      => '1',
                'Queue'        => 'Q',
                'State'        => 'new',
                'Priority'     => '3 normal',
                'Created'      => '2026-04-08 10:00:00',
                'Article'      => [],
            ]]]),
        ]);

        Znuny::tickets()->find('1', withArticles: true, withDynamicFields: true);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketGet')
            && ($r['AllArticles'] ?? null) === 1
            && ($r['DynamicFields'] ?? null) === 1);
    }

    public function test_find_throws_when_not_found(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Error' => [
                'ErrorCode'    => 'TicketGet.NotFound',
                'ErrorMessage' => 'No such ticket',
            ]]),
        ]);

        $this->expectException(TicketNotFoundException::class);
        Znuny::tickets()->find('999');
    }

    public function test_find_url_encodes_ticket_id(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*'    => Http::response(['Ticket' => [[
                'TicketID'     => 'weird/id',
                'TicketNumber' => '1',
                'Title'        => 't',
                'QueueID'      => '1',
                'Queue'        => 'Q',
                'State'        => 'new',
                'Priority'     => '3 normal',
                'Created'      => '2026-04-08 10:00:00',
            ]]]),
        ]);

        Znuny::tickets()->find('weird/id');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketGet/weird%2Fid'));
    }

    public function test_update_sends_ticket_changes(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
        ]);

        Znuny::tickets()->update('42', ['state' => 'closed successful']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketUpdate/42')
            && ($r['Ticket']['State'] ?? null) === 'closed successful');
    }

    public function test_add_article_sends_article_only_update(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042', 'ArticleID' => '900']),
        ]);

        Znuny::tickets()->addArticle('42', [
            'subject'              => 'Internal note',
            'body'                 => 'Customer called back',
            'communicationChannel' => 'Internal',
            'senderType'           => 'agent',
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketUpdate/42')
            && ($r['Article']['Subject'] ?? null) === 'Internal note'
            && ($r['Article']['CommunicationChannel'] ?? null) === 'Internal'
            && ! isset($r['Ticket']));
    }

    public function test_close_sets_state_to_closed_successful(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
        ]);

        Znuny::tickets()->close('42');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'TicketUpdate/42')
            && ($r['Ticket']['State'] ?? null) === 'closed successful');
    }

    public function test_close_accepts_custom_state(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
        ]);

        Znuny::tickets()->close('42', state: 'closed unsuccessful');

        Http::assertSent(fn ($r) => ($r['Ticket']['State'] ?? null) === 'closed unsuccessful');
    }
}
