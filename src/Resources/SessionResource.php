<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\Data\SessionUser;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyServerException;

/**
 * The session of the connection: Znuny's SessionGet and SessionRemove operations.
 */
class SessionResource extends Resource
{
    /**
     * The user of the connection, that is the account the application logs in with.
     */
    public function user(): SessionUser
    {
        if ($this->client->settings()->usesSession()) {
            // The route's session ID comes from the authentication, also after a new login.
            return $this->userFrom($this->client->call('SessionGet'));
        }

        // A connection that sends its credentials with every request has no session; get one.
        // The cached one may be gone in Znuny; then the next one is asked.
        $dropped = fn (ZnunyException $e): bool => $e->getErrorCode() === 'SessionGet.SessionInvalid';

        try {
            return $this->client->expecting($dropped, fn (): SessionUser => $this->read());
        } catch (ZnunyException $e) {
            if (! $dropped($e)) {
                throw $e;
            }

            $this->client->forgetSession();

            return $this->read();
        }
    }

    /**
     * End the connection's session in Znuny. The next call logs in again.
     */
    public function logout(): void
    {
        $this->client->logout();
    }

    private function read(): SessionUser
    {
        return $this->userFrom($this->client->call('SessionGet', ['SessionID' => $this->client->sessionId()]));
    }

    /**
     * @param  array<array-key, mixed>  $response
     */
    private function userFrom(array $response): SessionUser
    {
        if (! is_array($response['SessionData'] ?? null)) {
            throw (new ZnunyServerException('Znuny answered SessionGet without SessionData.'))
                ->setOperation('SessionGet')
                ->setConnection($this->client->connection())
                ->setResponseBody($response);
        }

        return SessionUser::fromZnunyResponse($response);
    }
}
