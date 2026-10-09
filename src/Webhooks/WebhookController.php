<?php

namespace ItsJustVita\LaravelZnuny\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ItsJustVita\LaravelZnuny\Events\ZnunyWebhookReceived;
use stdClass;

class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return new JsonResponse(['Error' => 'The request body must be a JSON object.'], 400);
        }

        $parameters = $request->route()?->originalParameters() ?? [];
        $routeEvent = $parameters['event'] ?? null;

        event(new ZnunyWebhookReceived(
            event: WebhookPayload::event($payload, is_string($routeEvent) ? $routeEvent : null),
            ticketId: WebhookPayload::ticketId($payload),
            articleId: WebhookPayload::articleId($payload),
            payload: $payload,
            parameters: $parameters,
        ));

        // Znuny's Ticket::Generic invoker applies returned data to the ticket, so return nothing.
        return new JsonResponse(new stdClass);
    }
}
