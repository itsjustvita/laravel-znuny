<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\HistoryEntry;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class HistoryEntryTest extends TestCase
{
    public function test_it_builds_from_a_znuny_history_line(): void
    {
        $entry = HistoryEntry::fromZnunyResponse([
            'HistoryType' => 'AddNote',
            'Name' => 'Added note (Note)',
            'CreateTime' => '2026-10-09 10:00:00',
            'CreateBy' => '1',
            'ArticleID' => '12',
            'StateID' => '4',
        ]);

        $this->assertSame('AddNote', $entry->historyType);
        $this->assertSame('Added note (Note)', $entry->name);
        $this->assertSame('2026-10-09 10:00:00', $entry->createTime?->format('Y-m-d H:i:s'));
        $this->assertSame('1', $entry->createBy);
        $this->assertSame('12', $entry->articleId);
        $this->assertSame('4', $entry->attribute('StateID'));
    }

    public function test_the_create_time_is_read_in_the_znuny_time_zone(): void
    {
        date_default_timezone_set('Asia/Tokyo');

        try {
            $utc = HistoryEntry::fromZnunyResponse(['HistoryType' => 'NewTicket', 'CreateTime' => '2026-07-01 08:00:00']);
            $berlin = HistoryEntry::fromZnunyResponse(['HistoryType' => 'NewTicket', 'CreateTime' => '2026-07-01 08:00:00'], 'Europe/Berlin');
        } finally {
            date_default_timezone_set('UTC');
        }

        $this->assertSame(strtotime('2026-07-01 08:00:00 UTC'), $utc->createTime->getTimestamp());
        $this->assertSame(strtotime('2026-07-01 06:00:00 UTC'), $berlin->createTime->getTimestamp());
        $this->assertSame('2026-07-01 08:00:00', $berlin->toArray()['CreateTime']);
    }

    public function test_article_id_zero_means_no_article(): void
    {
        $this->assertNull(HistoryEntry::fromZnunyResponse(['HistoryType' => 'NewTicket', 'ArticleID' => '0'])->articleId);
    }
}
