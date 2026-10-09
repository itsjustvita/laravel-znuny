<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Http;

use ItsJustVita\LaravelZnuny\Config\ConnectionConfig;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;
use ItsJustVita\LaravelZnuny\Http\RequestBuilder;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RequestBuilderTest extends TestCase
{
    private const BASE = 'https://znuny.test/otrs/nph-genericinterface.pl/Webservice/Test';

    private function builder(array $config = []): RequestBuilder
    {
        return new RequestBuilder(ConnectionConfig::fromArray('default', array_merge([
            'base_url' => self::BASE,
            'username' => 'agent',
            'password' => 'secret',
        ], $config)));
    }

    public function test_get_route_moves_auth_to_headers_and_payload_to_query(): void
    {
        $request = $this->builder()->build('TicketGet', ['TicketID' => '42', 'AllArticles' => 1], ['SessionID' => 'S1']);

        $this->assertSame('GET', $request->method);
        $this->assertSame(self::BASE.'/Ticket/42?AllArticles=1', $request->url);
        $this->assertSame('/Ticket/42', $request->path);
        $this->assertNull($request->body);
        $this->assertSame(['X-OTRS-Header-SessionID' => 'S1'], $request->headers);
    }

    public function test_list_placeholders_are_joined_with_commas(): void
    {
        $request = $this->builder()->build('TicketGet', ['TicketID' => ['1', '2', 3]], ['SessionID' => 'S1']);

        $this->assertSame(self::BASE.'/Ticket/1,2,3', $request->url);
    }

    public function test_placeholder_values_are_url_encoded(): void
    {
        $request = $this->builder(['routes' => 'operation-name'])->build('TicketGet', ['TicketID' => 'weird/id']);

        $this->assertSame(self::BASE.'/TicketGet/weird%2Fid', $request->url);
        $this->assertSame(['TicketID' => 'weird/id'], $request->body);
    }

    public function test_body_route_keeps_auth_and_placeholders_in_the_json_body(): void
    {
        $request = $this->builder()->build('TicketUpdate', ['TicketID' => '42', 'Ticket' => ['State' => 'open']], ['SessionID' => 'S1']);

        $this->assertSame('PATCH', $request->method);
        $this->assertSame(self::BASE.'/Ticket/42', $request->url);
        $this->assertSame(['SessionID' => 'S1', 'TicketID' => '42', 'Ticket' => ['State' => 'open']], $request->body);
        $this->assertSame([], $request->headers);
    }

    public function test_payload_transport_puts_auth_into_the_query_string(): void
    {
        $request = $this->builder(['auth_transport' => 'payload'])
            ->build('TicketSearch', ['States' => ['new', 'open']], ['SessionID' => 'S1']);

        $this->assertSame(self::BASE.'/Ticket?SessionID=S1&States=new&States=open', $request->url);
        $this->assertSame([], $request->headers);
    }

    public function test_headers_transport_moves_credentials_out_of_the_body(): void
    {
        $request = $this->builder(['auth_transport' => 'headers', 'user_type' => 'customer'])
            ->build('TicketCreate', ['Ticket' => ['Title' => 'x']], ['CustomerUserLogin' => 'jane', 'Password' => 'pw']);

        $this->assertSame(['Ticket' => ['Title' => 'x']], $request->body);
        $this->assertSame([
            'X-OTRS-Header-CustomerUserLogin' => 'jane',
            'X-OTRS-Header-Password' => 'pw',
        ], $request->headers);
    }

    public function test_unmapped_operation_uses_the_post_fallback(): void
    {
        $request = $this->builder()->build('FAQSearch', ['Title' => 'VPN*'], ['SessionID' => 'S1']);

        $this->assertSame('POST', $request->method);
        $this->assertSame(self::BASE.'/FAQSearch', $request->url);
        $this->assertSame(['SessionID' => 'S1', 'Title' => 'VPN*'], $request->body);
    }

    public function test_missing_placeholder_value_throws(): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('Route [GET /Ticket/:TicketID] for operation [TicketGet] requires parameter [TicketID].');

        $this->builder()->build('TicketGet', ['AllArticles' => 1], ['SessionID' => 'S1']);
    }

    public function test_nested_parameters_on_get_routes_throw(): void
    {
        $this->expectException(ZnunyConfigurationException::class);

        $this->builder()->build('TicketSearch', ['DynamicField_Order' => ['Equals' => 'X']], ['SessionID' => 'S1']);
    }

    #[DataProvider('dotSegments')]
    public function test_dot_segment_placeholder_values_are_rejected(mixed $value): void
    {
        $this->expectException(ZnunyConfigurationException::class);
        $this->expectExceptionMessage('must not be "." or ".."');

        $this->builder()->build('TicketGet', ['TicketID' => $value], ['SessionID' => 'S1']);
    }

    public static function dotSegments(): array
    {
        return [
            'parent directory' => ['..'],
            'current directory' => ['.'],
            'inside a batch' => [['1', '..']],
        ];
    }

    public function test_values_that_only_contain_dots_are_not_dot_segments(): void
    {
        $request = $this->builder()->build('TicketGet', ['TicketID' => '...'], ['SessionID' => 'S1']);

        $this->assertSame(self::BASE.'/Ticket/...', $request->url);
    }
}
