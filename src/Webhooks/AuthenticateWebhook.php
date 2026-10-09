<?php

namespace ItsJustVita\LaravelZnuny\Webhooks;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from a Znuny requester (middleware alias "znuny.webhook").
 * Fails closed: without a configured secret every request is rejected.
 */
class AuthenticateWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('znuny.webhooks.auth', []);
        $type = strtolower((string) ($config['type'] ?? 'token'));

        $authorized = match ($type) {
            'none' => true,
            'token' => $this->validToken($request, $config),
            'basic' => $this->validBasic($request, $config),
            default => $this->reject("unknown auth type [{$type}]"),
        };

        if (! $authorized) {
            return new JsonResponse(['Error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    private function validToken(Request $request, array $config): bool
    {
        $expected = (string) ($config['token'] ?? '');

        if ($expected === '') {
            return $this->reject('ZNUNY_WEBHOOK_TOKEN is not set');
        }

        $candidates = [
            (string) $request->bearerToken(),
            (string) $request->header((string) ($config['header'] ?? 'X-Znuny-Token'), ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function validBasic(Request $request, array $config): bool
    {
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->reject('ZNUNY_WEBHOOK_USERNAME / ZNUNY_WEBHOOK_PASSWORD are not set');
        }

        return hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());
    }

    private function reject(string $reason): bool
    {
        Log::warning("Znuny webhook rejected: {$reason}.");

        return false;
    }
}
