<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use ItsJustVita\LaravelZnuny\Data\SessionUser;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;

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
            return SessionUser::fromZnunyResponse($this->client->call('SessionGet'));
        }

        // A connection that sends its credentials with every request has no session; get one.
        try {
            return $this->read();
        } catch (ZnunyException $e) {
            if ($e->getErrorCode() !== 'SessionGet.SessionInvalid') {
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
        return SessionUser::fromZnunyResponse(
            $this->client->call('SessionGet', ['SessionID' => $this->client->sessionId()]),
        );
    }
}
