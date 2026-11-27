<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use ItsJustVita\LaravelZnuny\Support\Setting;
use ItsJustVita\LaravelZnuny\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function strings(): array
    {
        return [
            'an empty string' => ['', 'znuny'],
            'spaces' => ['  ', 'znuny'],
            'null' => [null, 'znuny'],
            'a list' => [['audit'], 'znuny'],
            'a value' => ['audit', 'audit'],
            'a value with spaces around it' => [' audit ', 'audit'],
            'a number' => [5, '5'],
        ];
    }

    #[DataProvider('strings')]
    public function test_a_blank_string_setting_takes_its_default(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Setting::string('znuny', $value));
    }
}
