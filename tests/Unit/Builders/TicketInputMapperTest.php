<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Builders;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Builders\TicketInputMapper;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class TicketInputMapperTest extends TestCase
{
    public function test_ticket_keys_are_mapped_and_unknown_keys_pass_through(): void
    {
        $this->assertSame([
            'Title' => 't',
            'OwnerID' => 5,
            'Responsible' => 'lead',
            'SLAID' => '2',
            'CustomerUserID' => 'jane',
            'PendingTime' => ['Diff' => 60],
            'Custom' => 'x',
        ], TicketInputMapper::mapTicket([
            'title' => 't',
            'ownerId' => 5,
            'responsible' => 'lead',
            'slaId' => '2',
            'customerUserId' => 'jane',
            'pendingTime' => ['Diff' => 60],
            'Custom' => 'x',
        ]));
    }

    public function test_article_keys_are_mapped_and_booleans_become_integers(): void
    {
        $this->assertSame([
            'Subject' => 's',
            'To' => 'a@example.com',
            'Cc' => 'b@example.com',
            'IsVisibleForCustomer' => 1,
            'NoAgentNotify' => 0,
            'HistoryType' => 'AddNote',
            'HistoryComment' => 'via API',
            'ContentType' => 'text/html; charset=utf8',
        ], TicketInputMapper::mapArticle([
            'subject' => 's',
            'to' => 'a@example.com',
            'cc' => 'b@example.com',
            'isVisibleForCustomer' => true,
            'noAgentNotify' => false,
            'historyType' => 'AddNote',
            'historyComment' => 'via API',
            'contentType' => 'text/html; charset=utf8',
        ]));
    }

    public function test_articles_are_plain_text_unless_a_content_type_is_given(): void
    {
        // Znuny rejects articles without ContentType (or MimeType and Charset).
        $this->assertSame(
            ['Subject' => 's', 'Body' => 'b', 'ContentType' => 'text/plain; charset=utf8'],
            TicketInputMapper::mapArticle(['subject' => 's', 'body' => 'b']),
        );
    }

    public function test_a_given_content_type_or_mime_type_is_kept(): void
    {
        $this->assertSame(
            ['ContentType' => 'text/html; charset=utf8'],
            TicketInputMapper::mapArticle(['ContentType' => 'text/html; charset=utf8']),
        );
        $this->assertSame(
            ['MimeType' => 'text/html', 'Charset' => 'utf8'],
            TicketInputMapper::mapArticle(['mimeType' => 'text/html', 'charset' => 'utf8']),
        );
    }

    public function test_a_pending_date_becomes_znunys_date_parts_in_the_znuny_time_zone(): void
    {
        $until = new DateTimeImmutable('2026-07-01 12:30:45', new DateTimeZone('UTC'));

        $this->assertSame(
            ['State' => 'pending reminder', 'PendingTime' => ['Year' => 2026, 'Month' => 7, 'Day' => 1, 'Hour' => 14, 'Minute' => 30]],
            TicketInputMapper::mapTicket(['state' => 'pending reminder', 'pendingTime' => $until], 'Europe/Berlin'),
        );
        $this->assertSame(
            ['PendingTime' => ['Year' => 2026, 'Month' => 7, 'Day' => 1, 'Hour' => 12, 'Minute' => 30]],
            TicketInputMapper::mapTicket(['PendingTime' => $until]),
        );
    }

    public function test_a_raw_pending_time_passes_through(): void
    {
        $this->assertSame(
            ['PendingTime' => ['Diff' => 60]],
            TicketInputMapper::mapTicket(['pendingTime' => ['Diff' => 60]], 'Europe/Berlin'),
        );
    }

    public function test_dynamic_fields_become_name_value_pairs(): void
    {
        $this->assertSame(
            [['Name' => 'Flag', 'Value' => 1], ['Name' => 'Tags', 'Value' => ['a', 'b']]],
            TicketInputMapper::mapDynamicFields(['Flag' => true, 'Tags' => ['a', 'b']]),
        );
    }

    public function test_attachments_are_encoded(): void
    {
        $this->assertSame(
            [['Filename' => 'a.txt', 'ContentType' => 'text/plain', 'Content' => base64_encode('x')]],
            TicketInputMapper::mapAttachments([Attachment::fromContent('a.txt', 'x', 'text/plain')]),
        );
    }

    public function test_non_attachment_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TicketInputMapper::mapAttachments([['Filename' => 'a.txt']]);
    }
}
