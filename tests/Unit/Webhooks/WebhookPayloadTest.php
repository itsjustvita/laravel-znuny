<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Webhooks;

use ItsJustVita\LaravelZnuny\Tests\TestCase;
use ItsJustVita\LaravelZnuny\Webhooks\WebhookPayload;

final class WebhookPayloadTest extends TestCase
{
    public function test_event_name_resolution_order(): void
    {
        $this->assertSame('FromRoute', WebhookPayload::event(['Event' => ['Event' => 'TicketCreate']], 'FromRoute'));
        $this->assertSame('TicketCreate', WebhookPayload::event(['Event' => ['Event' => 'TicketCreate']], null));
        $this->assertSame('Custom', WebhookPayload::event(['Event' => 'Custom'], ''));
        $this->assertSame('unknown', WebhookPayload::event([], null));
    }

    public function test_ids_come_from_the_event_or_the_ticket(): void
    {
        $this->assertSame('5', WebhookPayload::ticketId(['Event' => ['TicketID' => 5], 'Ticket' => ['TicketID' => '6']]));
        $this->assertSame('6', WebhookPayload::ticketId(['Ticket' => ['TicketID' => '6']]));
        $this->assertNull(WebhookPayload::ticketId(['Event' => 'TicketCreate']));
        $this->assertSame('9', WebhookPayload::articleId(['Event' => ['ArticleID' => '9']]));
        $this->assertNull(WebhookPayload::articleId([]));
    }
}
