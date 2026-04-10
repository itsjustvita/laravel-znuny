<?php

namespace ItsJustVita\LaravelZnuny\Webhooks;

/**
 * Experimental — will map Znuny webhook payloads to package events in a future release.
 */
class WebhookEventMapper
{
    public function map(string $event, array $payload): ?object
    {
        return null;
    }
}
