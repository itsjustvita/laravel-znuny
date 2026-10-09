<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use ItsJustVita\LaravelZnuny\Support\Redactor;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

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
}
