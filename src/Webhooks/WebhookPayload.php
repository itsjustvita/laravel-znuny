<?php

namespace ItsJustVita\LaravelZnuny\Webhooks;

/**
 * Reads event name and IDs from webhook payloads. Tolerant because Znuny admins can
 * reshape the payload with mappings.
 */
final class WebhookPayload
{
    public static function event(array $payload, ?string $routeEvent): string
    {
        if ($routeEvent !== null && $routeEvent !== '') {
            return $routeEvent;
        }

        $event = $payload['Event'] ?? null;

        if (is_array($event) && is_string($event['Event'] ?? null) && $event['Event'] !== '') {
            return $event['Event'];
        }

        return is_string($event) && $event !== '' ? $event : 'unknown';
    }

    public static function ticketId(array $payload): ?string
    {
        return self::id($payload['Event']['TicketID'] ?? null) ?? self::id($payload['Ticket']['TicketID'] ?? null);
    }

    public static function articleId(array $payload): ?string
    {
        return self::id($payload['Event']['ArticleID'] ?? null) ?? self::id($payload['Article']['ArticleID'] ?? null);
    }

    private static function id(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
