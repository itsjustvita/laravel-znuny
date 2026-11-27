<?php

declare(strict_types=1);

namespace ItsJustVita\LaravelZnuny\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelZnuny\Events\ZnunyTicketUpdated;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use Znuny;

/**
 * This file uses strict types, as many applications do: an integer is not turned into a
 * string on the way into a method, so the methods have to accept one.
 */
final class IntegerTicketIdsTest extends TestCase
{
    public function test_ticket_ids_can_be_integers(): void
    {
        Http::fake([
            '*SessionCreate' => Http::response(['SessionID' => 'S1']),
            '*TicketGet/42' => Http::response(['Ticket' => [['TicketID' => 42, 'Title' => 'Forty-two']]]),
            '*TicketUpdate/42' => Http::response(['TicketID' => 42, 'ArticleID' => 7]),
            '*TicketHistoryGet' => Http::response(['TicketHistory' => [['TicketID' => 42, 'History' => []]]]),
        ]);

        $updated = [];
        Event::listen(function (ZnunyTicketUpdated $event) use (&$updated): void {
            $updated[] = $event->ticketId;
        });

        // Applications keep ticket IDs as integers; the ID is the same ticket either way.
        $this->assertSame('42', Znuny::tickets()->find(42)->id);
        Znuny::tickets()->update(42, ['state' => 'open']);
        Znuny::tickets()->close(42);
        Znuny::tickets()->lock(42);
        Znuny::tickets()->unlock(42);
        Znuny::tickets()->assign(42, 'jane.agent');
        Znuny::tickets()->move(42, 'Support');
        Znuny::tickets()->setPending(42, now()->addDay());
        $this->assertSame('7', Znuny::tickets()->addArticle(42, ['subject' => 's', 'body' => 'b']));
        $this->assertCount(0, Znuny::tickets()->history(42));

        $this->assertSame(array_fill(0, 7, '42'), $updated);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketHistoryGet') && $r['TicketID'] === '42');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/TicketUpdate/42') && $r['TicketID'] === '42');
    }
}
