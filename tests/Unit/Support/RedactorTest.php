<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use ItsJustVita\LaravelZnuny\Support\Redactor;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedactorTest extends TestCase
{
    public function test_it_redacts_sensitive_keys_case_insensitively_and_recursively(): void
    {
        $result = Redactor::redact([
            'UserLogin' => 'agent',
            'Password' => 'secret',
            'sessionID' => 'abc',
            'Ticket' => ['Title' => 'ok', 'Auth' => ['TOKEN' => 'xyz']],
            'X-OTRS-Header-Password' => 'secret',
        ]);

        $this->assertSame([
            'UserLogin' => 'agent',
            'Password' => '[REDACTED]',
            'sessionID' => '[REDACTED]',
            'Ticket' => ['Title' => 'ok', 'Auth' => ['TOKEN' => '[REDACTED]']],
            'X-OTRS-Header-Password' => '[REDACTED]',
        ], $result);
    }

    public function test_values_of_key_value_pairs_that_name_a_secret_are_redacted(): void
    {
        // The shape of SessionGet: Znuny returns session data as a list of pairs.
        $result = Redactor::redact(['SessionData' => [
            ['Key' => 'UserGoogleAuthenticatorSecretKey', 'Value' => 'ABC'],
            ['Key' => 'UserToken', 'Value' => 't'],
            ['Key' => 'UserPassword', 'Value' => 'f78beb62'],
            ['Key' => 'UserLogin', 'Value' => 'api-agent'],
            ['Key' => 'Config', 'Value' => '{"a":1}', 'Serialized' => 1],
            ['Key' => 'UserPw'],
        ]]);

        $this->assertSame(['SessionData' => [
            ['Key' => 'UserGoogleAuthenticatorSecretKey', 'Value' => '[REDACTED]'],
            ['Key' => 'UserToken', 'Value' => '[REDACTED]'],
            ['Key' => 'UserPassword', 'Value' => '[REDACTED]'],
            ['Key' => 'UserLogin', 'Value' => 'api-agent'],
            ['Key' => 'Config', 'Value' => '{"a":1}', 'Serialized' => 1],
            ['Key' => 'UserPw'],
        ]], $result);
    }

    public function test_secrets_inside_serialized_values_of_pairs_are_redacted(): void
    {
        $config = ['CacheTTL' => 86400, 'Params' => ['Host' => 'ldap.example.com', 'UserPw' => 'bind-secret', 'Password' => 'db-secret']];

        $result = Redactor::redact(['SessionData' => [
            ['Key' => 'Config', 'Value' => json_encode($config), 'Serialized' => 1],
            ['Key' => 'Broken', 'Value' => '{"UserPw":"bind-secret"', 'Serialized' => 1],
            ['Key' => 'Decoded', 'Value' => ['Params' => ['UserPw' => 'bind-secret', 'Host' => 'h']]],
        ]]);

        $this->assertSame(
            ['CacheTTL' => 86400, 'Params' => ['Host' => 'ldap.example.com', 'UserPw' => '[REDACTED]', 'Password' => '[REDACTED]']],
            json_decode($result['SessionData'][0]['Value'], true),
        );
        // A value that claims to be JSON and is not cannot be inspected.
        $this->assertSame('[REDACTED]', $result['SessionData'][1]['Value']);
        $this->assertSame(['Params' => ['UserPw' => '[REDACTED]', 'Host' => 'h']], $result['SessionData'][2]['Value']);
        $this->assertStringNotContainsString('secret', (string) json_encode($result));
    }

    public function test_pairs_are_redacted_by_the_same_names_as_keys(): void
    {
        $result = Redactor::redact(['SessionData' => [
            ['Key' => 'SessionID', 'Value' => 'abc'],
            ['Key' => 'ApiKey', 'Value' => 'abc'],
            ['Key' => 'Authorization', 'Value' => 'Bearer abc'],
            ['Key' => 'UserOTP', 'Value' => '123456'],
        ]]);

        $this->assertSame(['[REDACTED]', '[REDACTED]', '[REDACTED]', '[REDACTED]'], array_column($result['SessionData'], 'Value'));
    }

    public function test_secrets_can_be_dropped_at_any_depth(): void
    {
        $this->assertSame(
            ['Host' => 'h', 'Nested' => ['List' => [['Name' => 'a']]]],
            Redactor::withoutSecrets(['Host' => 'h', 'UserPw' => 'x', 'Nested' => ['Token' => 't', 'List' => [['Name' => 'a', 'ApiSecret' => 's']]]]),
        );
    }

    public function test_a_map_that_merely_has_a_key_named_key_is_left_alone(): void
    {
        $data = ['Key' => 'UserPassword', 'Label' => 'Password'];

        $this->assertSame($data, Redactor::redact($data));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function names(): array
    {
        return [
            'UserPassword' => ['UserPassword', true],
            'UserPw' => ['UserPw', true],
            'NEWPW' => ['NEWPW', true],
            'UserGoogleAuthenticatorSecretKey' => ['UserGoogleAuthenticatorSecretKey', true],
            'UserToken' => ['UserToken', true],
            'a timestamp about the password' => ['UserLastPwChangeTime', false],
            'UserLogin' => ['UserLogin', false],
            'UserShowTickets' => ['UserShowTickets', false],
        ];
    }

    #[DataProvider('names')]
    public function test_it_knows_names_of_secrets(string $name, bool $secret): void
    {
        $this->assertSame($secret, Redactor::isSecretName($name));
    }

    public function test_it_redacts_whole_values_of_sensitive_keys_even_when_they_are_arrays(): void
    {
        $this->assertSame(['Authorization' => '[REDACTED]'], Redactor::redact(['Authorization' => ['Bearer', 'x']]));
    }

    public function test_for_log_shortens_large_content_values(): void
    {
        $result = Redactor::forLog([
            'Attachment' => [['Filename' => 'a.txt', 'Content' => str_repeat('A', 200)]],
            'Password' => 'x',
        ]);

        $this->assertSame('[200 chars]', $result['Attachment'][0]['Content']);
        $this->assertSame('a.txt', $result['Attachment'][0]['Filename']);
        $this->assertSame('[REDACTED]', $result['Password']);
    }

    public function test_for_log_keeps_short_content_values(): void
    {
        $this->assertSame(['Content' => 'short'], Redactor::forLog(['Content' => 'short']));
    }

    public function test_scrub_redacts_credentials_in_urls_and_messages(): void
    {
        $this->assertSame(
            'Failed for https://znuny.test/Ticket?UserLogin=agent&Password=[REDACTED]&SessionID=[REDACTED]&Limit=1',
            Redactor::scrub('Failed for https://znuny.test/Ticket?UserLogin=agent&Password=s3cr%26t&SessionID=abc&Limit=1'),
        );
    }
}
