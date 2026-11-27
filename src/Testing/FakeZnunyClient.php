<?php

namespace ItsJustVita\LaravelZnuny\Testing;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use ItsJustVita\LaravelZnuny\Session\SessionManager;
use ItsJustVita\LaravelZnuny\Session\SessionStore;
use ItsJustVita\LaravelZnuny\ZnunyClient;
use SensitiveParameter;

/**
 * Answers operations from Znuny::fake() instead of sending HTTP requests. Routing, logins
 * and request events do not happen; everything above call() runs as usual.
 *
 * @internal Not covered by the package's stability promise.
 */
class FakeZnunyClient extends ZnunyClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        string $connection,
        #[SensitiveParameter] array $config,
        private readonly FakeZnunyState $state,
    ) {
        parent::__construct(
            $connection,
            $config,
            new SessionManager(new SessionStore(new Repository(new ArrayStore), 'znuny:fake:'), 60),
        );
    }

    public function call(string $operation, array $payload = []): array
    {
        return $this->state->respond($this->connection, $operation, $payload);
    }

    public function ping(): bool
    {
        return true;
    }

    public function sessionId(): string
    {
        return 'fake-session';
    }

    public function cachedSessionId(): ?string
    {
        return $this->sessionId();
    }

    public function forgetSession(): void {}

    /**
     * Answered from the fake responses like every other operation.
     */
    public function logout(): void
    {
        $this->state->respond($this->connection, 'SessionDelete', ['SessionID' => $this->sessionId()]);
    }
}
