<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\TimeAccountingEntry;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class TimeAccountingEntryTest extends TestCase
{
    private const ROW = [
        'TicketNumber' => '2026070110000017',
        'TicketTitle' => 'Printer on fire',
        'TicketCustomerID' => 'acme',
        'Queue' => 'Raw',
        'TimeUnit' => '15.50',
        'Created' => '2026-07-01 10:00:00',
    ];

    public function test_it_builds_from_a_row_of_time_accounting_get(): void
    {
        $entry = TimeAccountingEntry::fromZnunyResponse(self::ROW, 'Europe/Berlin');

        $this->assertSame('2026070110000017', $entry->ticketNumber);
        $this->assertSame('Printer on fire', $entry->ticketTitle);
        $this->assertSame('acme', $entry->customerId);
        $this->assertSame('Raw', $entry->queue);
        $this->assertSame(15.5, $entry->timeUnit);
        $this->assertSame('2026-07-01 08:00:00', $entry->created?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('15.50', $entry->attribute('TimeUnit'));
    }

    public function test_missing_values(): void
    {
        $entry = TimeAccountingEntry::fromZnunyResponse(['TicketNumber' => 1, 'TimeUnit' => null, 'TicketCustomerID' => '']);

        $this->assertSame('1', $entry->ticketNumber);
        $this->assertSame('', $entry->ticketTitle);
        $this->assertSame(0.0, $entry->timeUnit);
        $this->assertNull($entry->customerId);
        $this->assertNull($entry->queue);
        $this->assertNull($entry->created);
    }

    public function test_to_array_uses_znunys_keys(): void
    {
        $this->assertSame(
            ['TicketNumber' => '2026070110000017', 'TicketTitle' => 'Printer on fire', 'TicketCustomerID' => 'acme', 'Queue' => 'Raw', 'TimeUnit' => 15.5, 'Created' => '2026-07-01 10:00:00'],
            TimeAccountingEntry::fromZnunyResponse(self::ROW, 'Europe/Berlin')->toArray(),
        );
    }
}
