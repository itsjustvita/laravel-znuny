<?php

namespace ItsJustVita\LaravelZnuny\Support;

/**
 * The CSV text that the inbound mapping of Znuny's "OutOfOffice" web service turns into
 * out-of-office entries (Kernel::GenericInterface::Mapping::OutOfOffice).
 *
 * @internal
 */
final class OutOfOfficeCsv
{
    /** The columns the mapping reads, in the header row. */
    private const COLUMNS = ['UserLogin', 'UserSearch', 'UserEmail', 'StartDate', 'EndDate', 'OutOfOffice'];

    /**
     * @param  list<array<string, mixed>>  $entries  Znuny's entries, as sent in "OutOfOfficeEntries"
     */
    public static function fromEntries(array $entries): string
    {
        $lines = [implode(',', self::COLUMNS)];

        foreach ($entries as $entry) {
            $lines[] = implode(',', array_map(
                // Every value is quoted, and a quote inside is doubled, so commas and line breaks stay inside their field.
                fn (string $column): string => '"'.str_replace('"', '""', (string) ($entry[$column] ?? '')).'"',
                self::COLUMNS,
            ));
        }

        return implode("\n", $lines)."\n";
    }
}
