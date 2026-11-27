<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use DateTimeInterface;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\TimeAccountingEntry;
use ItsJustVita\LaravelZnuny\Support\ZnunyTime;

/**
 * Znuny's TimeAccountingGet operation. Znuny ships it in its own web service,
 * "TimeAccountingREST", and answers only users with rw in the group timeaccounting_webservice.
 */
class TimeAccountingResource extends Resource
{
    /**
     * The time units an agent booked between two points in time, oldest first.
     *
     * @param  string  $login  the agent's login
     * @return Collection<int, TimeAccountingEntry>
     */
    public function entries(string $login, DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        $timezone = $this->client->settings()->timezone;

        $rows = $this->client->call('TimeAccountingGet', [
            'TimeAccountingUserLogin' => $login,
            'TimeAccountingStart' => ZnunyTime::dateTime($from, $timezone),
            'TimeAccountingEnd' => ZnunyTime::dateTime($until, $timezone),
        ])['TimeAccountingResult'] ?? [];

        // Znuny leaves the key out when nothing was booked.
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): TimeAccountingEntry => TimeAccountingEntry::fromZnunyResponse($row, $timezone))
            ->values();
    }
}
