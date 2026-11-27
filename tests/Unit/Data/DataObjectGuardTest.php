<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use Error;
use ItsJustVita\LaravelZnuny\Data\Article;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Data\DynamicField;
use ItsJustVita\LaravelZnuny\Data\HistoryEntry;
use ItsJustVita\LaravelZnuny\Data\SessionUser;
use ItsJustVita\LaravelZnuny\Data\Ticket;
use ItsJustVita\LaravelZnuny\Data\TimeAccountingEntry;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A typo in a property name must not read as null: every data object fails on a property
 * it does not have, while isset() and ?? stay quiet.
 */
final class DataObjectGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: object}>
     */
    public static function objects(): array
    {
        return [
            'Ticket' => [Ticket::fromZnunyResponse(['TicketID' => '1'])],
            'Article' => [Article::fromZnunyResponse(['ArticleID' => '1'])],
            'Attachment' => [Attachment::fromContent('a.txt', 'A')],
            'DynamicField' => [DynamicField::fromZnunyResponse(['Name' => 'OrderId', 'Value' => 'x'])],
            'HistoryEntry' => [HistoryEntry::fromZnunyResponse(['HistoryType' => 'AddNote'])],
            'SessionUser' => [SessionUser::fromZnunyResponse(['SessionData' => []])],
            'TimeAccountingEntry' => [TimeAccountingEntry::fromZnunyResponse(['TicketNumber' => '1'])],
        ];
    }

    #[DataProvider('objects')]
    public function test_reading_an_undefined_property_fails(object $object): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Undefined property: '.$object::class.'::$noSuchProperty');

        $object->noSuchProperty;
    }

    #[DataProvider('objects')]
    public function test_asking_for_an_undefined_property_is_quiet(object $object): void
    {
        $this->assertFalse(isset($object->noSuchProperty));
        $this->assertSame('fallback', $object->noSuchProperty ?? 'fallback');
        $this->assertSame('fallback', data_get($object, 'noSuchProperty', 'fallback'));
        $this->assertSame([null], collect([$object])->pluck('noSuchProperty')->all());
    }

    /**
     * @return array<string, array{0: object, 1: string, 2: string}>
     */
    public static function renamed(): array
    {
        $entry = HistoryEntry::fromZnunyResponse(['HistoryType' => 'AddNote', 'CreateTime' => '2026-10-09 10:00:00', 'CreateBy' => '1']);

        return [
            'the history entry\'s time' => [$entry, 'createTime', 'created'],
            'the history entry\'s author' => [$entry, 'createBy', 'createdBy'],
            'the ticket\'s customer user' => [Ticket::fromZnunyResponse(['TicketID' => '1', 'CustomerUserID' => 'jane']), 'customerUserID', 'customerUserId'],
        ];
    }

    #[DataProvider('renamed')]
    public function test_a_renamed_property_names_its_successor(object $object, string $old, string $new): void
    {
        // Code written for an earlier release: quiet where it asked, loud where it read.
        $this->assertSame('fallback', $object->{$old} ?? 'fallback');
        $this->assertFalse(isset($object->{$old}));
        $this->assertSame([null], collect([$object])->pluck($old)->all());
        $this->assertNotNull($object->{$new});

        try {
            $object->{$old};
            $this->fail('Reading a renamed property must fail.');
        } catch (Error $e) {
            $this->assertSame('Undefined property: '.$object::class.'::$'.$old.' (renamed to $'.$new.')', $e->getMessage());
        }
    }
}
