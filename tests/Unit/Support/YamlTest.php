<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Support\Yaml;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

final class YamlTest extends TestCase
{
    public function test_the_dump_parses_back_to_the_same_strings(): void
    {
        $data = [
            'Plain' => 'value',
            'Colon' => 'a: b',
            'Hash' => 'x # y',
            'Dash' => '- item',
            'Bool' => 'yes',
            'Number' => '0',
            'Empty' => '',
            'Quote' => "it's",
            'Url' => 'https://app.example.com:8443/x?y=1#z',
            'Newline' => "first\nsecond",
            'Null' => null,
            'Map' => [],
            'List' => ['POST', 'GET'],
            'X-Znuny-Token' => 'secret',
            'Key with: colon' => 'v',
            'On' => 'keyword key',
            '42' => 'numeric key',
            'Nested' => ['Route' => '/Ticket/:TicketID', 'Deeper' => ['Key' => 'v']],
            'Records' => [['Asynchronous' => '1', 'Event' => 'TicketCreate'], ['Asynchronous' => '0', 'Event' => 'ArticleCreate']],
        ];

        // An empty mapping parses back as an empty array.
        $this->assertSame($data, SymfonyYaml::parse(Yaml::dump($data)));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function textThatIsNotUtf8(): array
    {
        return [
            'a value' => [['Requester' => ['Password' => "secret\xE4"]], 'the value of [Requester.Password]'],
            'a value with a line break' => [['Requester' => ['Password' => "secret\xE4\n"]], 'the value of [Requester.Password]'],
            'a list item' => [['Events' => ['TicketCreate', "Ticket\xE4"]], 'the value of [Events.1]'],
            'a key' => [['Requester' => ["Pass\xE4word" => 'x']], 'a key under [Requester]'],
            'a top-level key' => [["Requester\xE4" => 'x'], 'a top-level key'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    #[DataProvider('textThatIsNotUtf8')]
    public function test_text_that_is_not_valid_utf8_is_refused(array $data, string $what): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot write {$what} as YAML, because it is not valid UTF-8.");

        Yaml::dump($data);
    }

    public function test_other_scalars_are_written_as_the_strings_znuny_expects(): void
    {
        $this->assertSame(
            ['Int' => '30', 'True' => '1', 'False' => '0'],
            SymfonyYaml::parse(Yaml::dump(['Int' => 30, 'True' => true, 'False' => false])),
        );
    }
}
