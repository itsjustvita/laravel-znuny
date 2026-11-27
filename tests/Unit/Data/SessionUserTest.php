<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use ItsJustVita\LaravelZnuny\Data\SessionUser;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class SessionUserTest extends TestCase
{
    /**
     * A SessionGet response for the given session values.
     *
     * @param  array<string, mixed>  $data
     * @return array{SessionData: list<array{Key: string, Value: mixed}>}
     */
    private function response(array $data): array
    {
        return ['SessionData' => array_map(
            fn (string $key, mixed $value): array => ['Key' => $key, 'Value' => $value],
            array_keys($data),
            array_values($data),
        )];
    }

    public function test_it_reads_an_agent_from_the_session_data(): void
    {
        $user = SessionUser::fromZnunyResponse($this->response([
            'UserID' => 5,
            'UserLogin' => 'api-agent',
            'UserFirstname' => 'API',
            'UserLastname' => 'Agent',
            'UserFullname' => 'API Agent',
            'UserEmail' => 'api-agent@example.com',
            'UserType' => 'User',
            'UserTitle' => null,
            'OutOfOffice' => '0',
        ]));

        $this->assertSame('5', $user->id);
        $this->assertSame('api-agent', $user->login);
        $this->assertSame('API', $user->firstName);
        $this->assertSame('Agent', $user->lastName);
        $this->assertSame('API Agent', $user->fullName);
        $this->assertSame('api-agent@example.com', $user->email);
        $this->assertSame('agent', $user->type);
        $this->assertSame('0', $user->attribute('OutOfOffice'));
        $this->assertNull($user->attribute('UserTitle', 'none'));
    }

    public function test_it_reads_a_customer_user(): void
    {
        $user = SessionUser::fromZnunyResponse($this->response([
            'UserID' => 'jane',
            'UserLogin' => 'jane',
            'UserCustomerID' => 'acme',
            'UserType' => 'Customer',
        ]));

        $this->assertSame('jane', $user->id);
        $this->assertSame('customer', $user->type);
        $this->assertSame('acme', $user->attribute('UserCustomerID'));
        $this->assertNull($user->firstName);
        $this->assertNull($user->email);
    }

    public function test_values_znuny_marks_as_serialized_are_decoded(): void
    {
        $user = SessionUser::fromZnunyResponse(['SessionData' => [
            ['Key' => 'Config', 'Value' => '{"CacheTTL":86400,"Map":[["UserLogin","Username"]]}', 'Serialized' => 1],
            ['Key' => 'Broken', 'Value' => '{not json', 'Serialized' => 1],
            ['Key' => 'Plain', 'Value' => '{"looks":"like json"}'],
        ]]);

        $this->assertSame(86400, $user->attribute('Config.CacheTTL'));
        $this->assertSame('{not json', $user->attribute('Broken'));
        $this->assertSame('{"looks":"like json"}', $user->attribute('Plain'));
    }

    public function test_values_that_name_a_secret_are_left_out(): void
    {
        $user = SessionUser::fromZnunyResponse($this->response([
            'UserLogin' => 'jane',
            'UserPassword' => 'f78beb624063dd9b62883ed26db719502a8fb578',
            'UserGoogleAuthenticatorSecretKey' => 'ABC',
            'UserToken' => 't',
            'UserLastPwChangeTime' => '1795766307',
        ]));

        $this->assertSame(['UserLogin' => 'jane', 'UserLastPwChangeTime' => '1795766307'], $user->attributes);
    }

    public function test_missing_values_and_malformed_rows(): void
    {
        $user = SessionUser::fromZnunyResponse(['SessionData' => [
            ['Value' => 'no key'],
            'not a row',
            ['Key' => ['nested'], 'Value' => 'x'],
            ['Key' => 'UserEmail', 'Value' => ''],
        ]]);

        $this->assertSame('', $user->id);
        $this->assertSame('', $user->login);
        $this->assertNull($user->email);
        $this->assertSame('agent', $user->type);
        $this->assertSame(['UserEmail' => ''], $user->attributes);

        $this->assertSame([], SessionUser::fromZnunyResponse([])->attributes);
    }

    public function test_to_array_uses_znunys_keys(): void
    {
        $data = [
            'UserID' => '5',
            'UserLogin' => 'api-agent',
            'UserFirstname' => 'API',
            'UserLastname' => 'Agent',
            'UserFullname' => 'API Agent',
            'UserEmail' => 'api-agent@example.com',
            'UserType' => 'User',
        ];

        $this->assertSame($data, SessionUser::fromZnunyResponse($this->response($data))->toArray());
    }
}
