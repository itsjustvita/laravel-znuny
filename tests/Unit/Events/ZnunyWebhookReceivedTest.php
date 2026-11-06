<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Events;

use ItsJustVita\LaravelZnuny\Events\ZnunyWebhookReceived;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class ZnunyWebhookReceivedTest extends TestCase
{
    private function event(): ZnunyWebhookReceived
    {
        return new ZnunyWebhookReceived(
            event: 'TicketCreate',
            ticketId: '42',
            articleId: null,
            payload: ['Ticket' => ['TicketID' => 42, 'Created' => '2026-07-01 08:00:00']],
        );
    }

    public function test_ticket_dates_are_read_in_utc_by_default(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        try {
            $ticket = $this->event()->ticket();
        } finally {
            date_default_timezone_set('UTC');
        }

        $this->assertSame(strtotime('2026-07-01 08:00:00 UTC'), $ticket?->created?->getTimestamp());
    }

    public function test_ticket_dates_use_the_time_zone_of_the_default_connection(): void
    {
        config(['znuny.default' => 'helpdesk', 'znuny.connections.helpdesk.timezone' => 'Europe/Berlin']);

        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), $this->event()->ticket()?->created?->getTimestamp());
        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), $this->event()->ticket(null)?->created?->getTimestamp());
        $this->assertSame(strtotime('2026-07-01 08:00:00 UTC'), $this->event()->ticket('UTC')?->created?->getTimestamp());
    }

    public function test_ticket_dates_use_the_global_time_zone_when_the_connection_has_none(): void
    {
        config(['znuny.timezone' => 'Europe/Berlin']);

        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), $this->event()->ticket()?->created?->getTimestamp());

        config(['znuny.connections.default.timezone' => '']);

        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), $this->event()->ticket()?->created?->getTimestamp());

        config(['znuny.connections.default.timezone' => 'Asia/Tokyo']);

        $this->assertSame(strtotime('2026-06-30 23:00:00 UTC'), $this->event()->ticket()?->created?->getTimestamp());
    }

    public function test_an_invalid_time_zone_is_reported(): void
    {
        config(['znuny.connections.default.timezone' => 'CEST']);

        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('[timezone]');

        $this->event()->ticket();
    }

    public function test_another_znuny_time_zone_can_be_given(): void
    {
        $this->assertSame(
            strtotime('2026-07-01 06:00:00 UTC'),
            $this->event()->ticket('Europe/Berlin')?->created?->getTimestamp(),
        );
    }
}
