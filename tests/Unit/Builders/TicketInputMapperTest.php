<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Builders;

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
        ], TicketInputMapper::mapArticle([
            'subject' => 's',
            'to' => 'a@example.com',
            'cc' => 'b@example.com',
            'isVisibleForCustomer' => true,
            'noAgentNotify' => false,
            'historyType' => 'AddNote',
            'historyComment' => 'via API',
        ]));
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
