<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Routing;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Routing\Route;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RouteTest extends TestCase
{
    public function test_it_parses_method_and_path(): void
    {
        $route = Route::parse('GET /Ticket/:TicketID');

        $this->assertSame('GET', $route->method);
        $this->assertSame('/Ticket/:TicketID', $route->path);
    }

    public function test_method_defaults_to_post_and_is_case_insensitive(): void
    {
        $this->assertSame('POST', Route::parse('/TicketSearch')->method);
        $this->assertSame('PATCH', Route::parse('patch /Ticket/:TicketID')->method);
    }

    public function test_it_adds_a_missing_leading_slash(): void
    {
        $this->assertSame('/Ticket', Route::parse('POST Ticket')->path);
    }

    public function test_it_lists_placeholders(): void
    {
        $this->assertSame(['TicketID', 'ArticleID'], Route::parse('GET /Ticket/:TicketID/Article/:ArticleID')->parameters());
        $this->assertSame([], Route::parse('POST /Session')->parameters());
    }

    public function test_only_get_routes_send_no_body(): void
    {
        $this->assertFalse(Route::parse('GET /Ticket')->sendsBody());
        $this->assertTrue(Route::parse('PATCH /Ticket/:TicketID')->sendsBody());
        $this->assertTrue(Route::parse('DELETE /Session/:SessionID')->sendsBody());
    }

    public function test_it_renders_as_string(): void
    {
        $this->assertSame('GET /Ticket/:TicketID', (string) Route::parse('get /Ticket/:TicketID'));
    }

    #[DataProvider('invalidDefinitions')]
    public function test_it_rejects_invalid_definitions(string $definition): void
    {
        $this->expectException(ZnunyConfigurationException::class);

        Route::parse($definition);
    }

    public static function invalidDefinitions(): array
    {
        return [
            'empty' => [''],
            'unknown method' => ['FETCH /Ticket'],
            'too many parts' => ['GET /Ticket extra'],
        ];
    }
}
