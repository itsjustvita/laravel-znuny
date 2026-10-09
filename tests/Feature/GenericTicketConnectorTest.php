<?php

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * End-to-end behaviour against the routes of Znuny's stock GenericTicketConnectorREST web service.
 */
final class GenericTicketConnectorTest extends TestCase
{
    private const BASE = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('znuny.connections.default.routes', 'generic-ticket-connector');
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses  "METHOD /path" => JSON body
     */
    private function fakeZnuny(array $responses): void
    {
        Http::fake(function (Request $request) use ($responses) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/otrs/nph-genericinterface.pl/Webservice/test'));
            $key = $request->method().' '.$path;

            if ($key === 'POST /Session') {
                return Http::response(['SessionID' => 'S1']);
            }

            if (! array_key_exists($key, $responses)) {
                return Http::response("HTTP::REST Error while determine Operation for request URI '{$path}'.", 500);
            }

            return Http::response($responses[$key]);
        });
    }

    private function ticket(string $id): array
    {
        return [
            'TicketID' => $id, 'TicketNumber' => '2026'.$id, 'Title' => 'Ticket '.$id, 'QueueID' => '2',
            'Queue' => 'Raw', 'State' => 'new', 'Priority' => '3 normal', 'Created' => '2026-10-09 10:00:00',
        ];
    }

    public function test_find_uses_get_with_query_options_and_header_auth(): void
    {
        $this->fakeZnuny(['GET /Ticket/42' => ['Ticket' => [$this->ticket('42')]]]);

        $ticket = Znuny::tickets()->find('42', withArticles: true, withDynamicFields: true);

        $this->assertSame('42', $ticket->id);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::BASE.'/Ticket/42?AllArticles=1&DynamicFields=1'
            && $r->header('X-OTRS-Header-SessionID') === ['S1']);
    }

    public function test_search_and_get_use_repeated_query_keys_and_one_batched_ticket_get(): void
    {
        $this->fakeZnuny([
            'GET /Ticket' => ['TicketID' => ['3', '1']],
            'GET /Ticket/3,1' => ['Ticket' => [$this->ticket('1'), $this->ticket('3')]],
        ]);

        $tickets = Znuny::tickets()
            ->whereState(['new', 'open'])
            ->limit(10)
            ->withoutArticles()
            ->withoutDynamicFields()
            ->get();

        $this->assertSame(['3', '1'], $tickets->pluck('id')->all());
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Ticket?States=new&States=open&Limit=10');
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/Ticket/3,1');
    }

    public function test_create_posts_to_ticket_and_refetches_the_ticket(): void
    {
        $this->fakeZnuny([
            'POST /Ticket' => ['TicketID' => '99', 'TicketNumber' => '202699'],
            'GET /Ticket/99' => ['Ticket' => [$this->ticket('99')]],
        ]);

        $ticket = Znuny::tickets()->create([
            'title' => 'Printer', 'queue' => 'Raw', 'state' => 'new', 'priority' => '3 normal', 'customerUser' => 'jane@example.com',
        ])
            ->withArticle(['subject' => 'Help', 'body' => 'It burns', 'contentType' => 'text/plain; charset=utf8'])
            ->withAttachments([Attachment::fromContent('log.txt', 'boom', 'text/plain')])
            ->save();

        $this->assertSame('99', $ticket->id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/Ticket'
            && $r['SessionID'] === 'S1'
            && $r['Ticket']['Title'] === 'Printer'
            && $r['Attachment'][0]['Content'] === base64_encode('boom'));
    }

    public function test_update_uses_patch(): void
    {
        $this->fakeZnuny(['PATCH /Ticket/42' => ['TicketID' => '42', 'TicketNumber' => '1']]);

        Znuny::tickets()->update('42', ['state' => 'open'], ['OrderId' => 'ORD-1']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['Ticket']['State'] === 'open'
            && $r['DynamicField'] === [['Name' => 'OrderId', 'Value' => 'ORD-1']]);
    }

    public function test_history_uses_the_ticket_history_route(): void
    {
        $this->fakeZnuny(['GET /TicketHistory/42' => ['TicketHistory' => [['TicketID' => '42', 'History' => [
            ['HistoryType' => 'NewTicket', 'Name' => 'New ticket', 'CreateTime' => '2026-10-09 10:00:00', 'CreateBy' => '1', 'ArticleID' => '0'],
        ]]]]]);

        $history = Znuny::tickets()->history('42');

        $this->assertSame('NewTicket', $history->first()->historyType);
        $this->assertNull($history->first()->articleId);
    }

    public function test_dynamic_field_search_on_get_route_fails_before_sending(): void
    {
        $this->fakeZnuny([]);

        try {
            Znuny::tickets()->query()->whereDynamicField('OrderId', 'ORD-1')->ids();
            $this->fail('Expected ZnunyConfigurationException');
        } catch (ZnunyConfigurationException $e) {
            $this->assertStringContainsString("routes['TicketSearch'] = 'POST /TicketSearch'", $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/Ticket'));
    }

    public function test_dynamic_field_search_works_with_a_post_search_route(): void
    {
        config()->set('znuny.connections.default.routes', [
            'extends' => 'generic-ticket-connector',
            'TicketSearch' => 'POST /TicketSearch',
        ]);

        $this->fakeZnuny(['POST /TicketSearch' => ['TicketID' => ['5']]]);

        $ids = Znuny::tickets()->query()->whereDynamicField('OrderId', 'ORD-1')->ids();

        $this->assertSame(['5'], $ids->all());
        Http::assertSent(fn (Request $r) => ($r['DynamicField_OrderId'] ?? null) === ['Equals' => 'ORD-1']);
    }
}
