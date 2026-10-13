<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Events\ZnunyArticleAdded;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketUpdated;
use ItsJustVita\LaravelZnuny\Exceptions\InvalidDynamicFieldException;
use ItsJustVita\LaravelZnuny\Exceptions\TicketNotFoundException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

final class TicketResourceTest extends TestCase
{
    public function test_find_returns_ticket_dto(): void
    {
        $payload = json_decode(
            file_get_contents(__DIR__.'/../fixtures/ticket-with-articles.json'),
            true,
        );

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [$payload]]),
        ]);

        $ticket = Znuny::tickets()->find('42');

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('42', $ticket->id);
    }

    public function test_find_with_articles_passes_all_articles_flag(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => '1',
                'TicketNumber' => '1',
                'Title' => 't',
                'QueueID' => '1',
                'Queue' => 'Q',
                'State' => 'new',
                'Priority' => '3 normal',
                'Created' => '2026-04-08 10:00:00',
                'Article' => [],
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
            '*TicketGet*' => Http::response(['Error' => [
                'ErrorCode' => 'TicketGet.NotFound',
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
            '*TicketGet*' => Http::response(['Ticket' => [[
                'TicketID' => 'weird/id',
                'TicketNumber' => '1',
                'Title' => 't',
                'QueueID' => '1',
                'Queue' => 'Q',
                'State' => 'new',
                'Priority' => '3 normal',
                'Created' => '2026-04-08 10:00:00',
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
            'subject' => 'Internal note',
            'body' => 'Customer called back',
            'communicationChannel' => 'Internal',
            'senderType' => 'agent',
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

    private function ticket(string $id): array
    {
        return [
            'TicketID' => $id,
            'TicketNumber' => '1000'.$id,
            'Title' => 'Ticket '.$id,
            'QueueID' => '1',
            'Queue' => 'Q',
            'State' => 'new',
            'Priority' => '3 normal',
            'Created' => '2026-04-08 10:00:00',
        ];
    }

    public function test_find_with_attachments_requests_articles_and_attachments(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [$this->ticket('1')]]),
        ]);

        Znuny::tickets()->find('1', withAttachments: true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketGet/1')
            && ($r['AllArticles'] ?? null) === 1
            && ($r['Attachments'] ?? null) === 1
            && ! isset($r['DynamicFields']));
    }

    public function test_find_many_uses_one_batch_and_keeps_the_requested_order(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [$this->ticket('1'), $this->ticket('2'), $this->ticket('3')]]),
        ]);

        $tickets = Znuny::tickets()->findMany(['3', '1', '2', '3']);

        $this->assertSame(['3', '1', '2'], $tickets->pluck('id')->all());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketGet/3,1,2')
            && ($r['TicketID'] ?? null) === ['3', '1', '2']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'TicketGet')));
    }

    public function test_find_many_splits_requests_by_batch_size(): void
    {
        config()->set('znuny.http.batch_size', 2);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet/1,2' => Http::response(['Ticket' => [$this->ticket('1'), $this->ticket('2')]]),
            '*TicketGet/3' => Http::response(['Ticket' => [$this->ticket('3')]]),
        ]);

        $this->assertSame(['1', '2', '3'], Znuny::tickets()->findMany(['1', '2', '3'])->pluck('id')->all());
    }

    public function test_find_many_skips_tickets_that_znuny_denies(): void
    {
        $denied = ['Error' => ['ErrorCode' => 'TicketGet.AccessDenied', 'ErrorMessage' => 'TicketGet: User does not have access to the ticket!']];

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet/1,404' => Http::response($denied),
            '*TicketGet/1' => Http::response(['Ticket' => [$this->ticket('1')]]),
            '*TicketGet/404' => Http::response($denied),
        ]);

        $this->assertSame(['1'], Znuny::tickets()->findMany(['1', '404'])->pluck('id')->all());
    }

    public function test_find_many_without_ids_sends_nothing(): void
    {
        Http::fake();

        $this->assertTrue(Znuny::tickets()->findMany([])->isEmpty());

        Http::assertNothingSent();
    }

    public function test_update_can_set_dynamic_fields_only(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
        ]);

        Znuny::tickets()->update('42', dynamicFields: ['OrderId' => 'ORD-2', 'Flag' => true]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketUpdate/42')
            && ! isset($r['Ticket'])
            && $r['DynamicField'] === [['Name' => 'OrderId', 'Value' => 'ORD-2'], ['Name' => 'Flag', 'Value' => 1]]);
    }

    public function test_update_validates_dynamic_fields_before_sending(): void
    {
        config()->set('znuny.dynamic_fields', ['Segment' => ['type' => 'string', 'allowed' => ['business']]]);
        Http::fake();

        try {
            Znuny::tickets()->update('42', dynamicFields: ['Segment' => 'government']);
            $this->fail('Expected InvalidDynamicFieldException');
        } catch (InvalidDynamicFieldException) {
            Http::assertNothingSent();
        }
    }

    public function test_update_without_changes_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Znuny::tickets()->update('42');
    }

    public function test_update_event_carries_the_dynamic_fields(): void
    {
        Event::fake([ZnunyTicketUpdated::class]);
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042']),
        ]);

        Znuny::tickets()->update('42', ['state' => 'open'], ['OrderId' => 'ORD-2']);

        Event::assertDispatched(ZnunyTicketUpdated::class, fn (ZnunyTicketUpdated $e) => $e->changes === ['state' => 'open']
            && $e->dynamicFields === ['OrderId' => 'ORD-2']);
    }

    public function test_add_article_returns_the_article_id_and_sends_attachments(): void
    {
        Event::fake([ZnunyArticleAdded::class]);
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketUpdate*' => Http::response(['TicketID' => '42', 'TicketNumber' => '00042', 'ArticleID' => '900']),
        ]);

        $articleId = Znuny::tickets()->addArticle('42', ['subject' => 's', 'body' => 'b'], [
            Attachment::fromContent('log.txt', 'boom', 'text/plain'),
        ]);

        $this->assertSame('900', $articleId);
        Http::assertSent(fn (Request $r) => ($r['Attachment'][0]['Filename'] ?? null) === 'log.txt'
            && ($r['Attachment'][0]['Content'] ?? null) === base64_encode('boom'));
        Event::assertDispatched(ZnunyArticleAdded::class, fn (ZnunyArticleAdded $e) => $e->articleId === '900');
    }

    public function test_dates_are_read_in_the_time_zone_of_the_connection(): void
    {
        config(['znuny.connections.default.timezone' => 'Europe/Berlin']);

        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet*' => Http::response(['Ticket' => [['TicketID' => '42', 'Created' => '2026-07-01 08:00:00']]]),
            '*TicketHistoryGet' => Http::response(['TicketHistory' => [['TicketID' => '42', 'History' => [
                ['HistoryType' => 'NewTicket', 'Name' => 'New Ticket [42] created.', 'CreateTime' => '2026-07-01 08:00:00'],
            ]]]]),
        ]);

        $expected = strtotime('2026-07-01 06:00:00 UTC');

        $this->assertSame($expected, Znuny::tickets()->find('42')->created->getTimestamp());
        $this->assertSame($expected, Znuny::tickets()->findMany(['42'])->first()->created->getTimestamp());
        $this->assertSame($expected, Znuny::tickets()->history('42')->first()->createTime->getTimestamp());
    }

    public function test_history_returns_entries(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketHistoryGet' => Http::response(['TicketHistory' => [['TicketID' => '42', 'History' => [
                ['HistoryType' => 'NewTicket', 'Name' => 'New Ticket [42] created.', 'CreateTime' => '2026-10-09 10:00:00', 'ArticleID' => '0'],
                ['HistoryType' => 'AddNote', 'Name' => 'Added note', 'CreateTime' => '2026-10-09 10:05:00', 'ArticleID' => '7'],
            ]]]]),
        ]);

        $history = Znuny::tickets()->history('42');

        $this->assertSame(['NewTicket', 'AddNote'], $history->pluck('historyType')->all());
        $this->assertSame('7', $history->last()->articleId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketHistoryGet') && $r['TicketID'] === '42');
    }
}
