<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Support;

use ItsJustVita\LaravelZnuny\Support\OutOfOfficeCsv;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class OutOfOfficeCsvTest extends TestCase
{
    /**
     * Read the CSV the way a CSV parser does, quoted line breaks included.
     *
     * @return list<list<string|null>>
     */
    private function parse(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_it_writes_the_columns_znunys_mapping_reads(): void
    {
        $csv = OutOfOfficeCsv::fromEntries([
            ['UserLogin' => 'jane.agent', 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-14', 'OutOfOffice' => 1],
            ['UserEmail' => 'bob@example.com', 'OutOfOffice' => 0],
        ]);

        $this->assertSame(
            "UserLogin,UserSearch,UserEmail,StartDate,EndDate,OutOfOffice\n"
            ."\"jane.agent\",\"\",\"\",\"2026-07-01\",\"2026-07-14\",\"1\"\n"
            ."\"\",\"\",\"bob@example.com\",\"\",\"\",\"0\"\n",
            $csv,
        );
    }

    public function test_values_with_commas_quotes_and_line_breaks_stay_one_field(): void
    {
        $search = "Miller, \"Jo\"\nX";

        $rows = $this->parse(OutOfOfficeCsv::fromEntries([['UserSearch' => $search, 'OutOfOffice' => 1]]));

        $this->assertCount(2, $rows);
        $this->assertSame(['', $search, '', '', '', '1'], $rows[1]);
    }
}
