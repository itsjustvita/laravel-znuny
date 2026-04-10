<?php

namespace ItsJustVita\LaravelZnuny\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController
{
    public function handle(Request $request, string $event): JsonResponse
    {
        return new JsonResponse([
            'status' => 'not_implemented',
            'event' => $event,
            'message' => 'laravel-znuny inbound webhooks are experimental. Signature verification and payload mapping are planned for a future release.',
        ], 501);
    }
}
