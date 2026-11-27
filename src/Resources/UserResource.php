<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use DateTimeInterface;
use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Support\OutOfOfficeCsv;
use ItsJustVita\LaravelZnuny\Support\ZnunyTime;

/**
 * Agents: Znuny's OutOfOffice operation, which sets the out-of-office time of any agent.
 *
 * Znuny ships it in its own web service, "OutOfOffice", and only answers agents with rw in
 * the group admin. It reports success even when an entry matches no user, or more than one:
 * such an entry is skipped and written to Znuny's log, and nothing in the response says so.
 */
class UserResource extends Resource
{
    private const USER_KEYS = ['login' => 'UserLogin', 'email' => 'UserEmail', 'search' => 'UserSearch'];

    /**
     * Mark an agent as out of office from one day to another. See the class comment for
     * what Znuny does when it does not find the agent.
     *
     * @param  string  $login  the agent's login
     */
    public function setOutOfOffice(string $login, DateTimeInterface $from, DateTimeInterface $until): void
    {
        $this->outOfOffice([['login' => $login, 'from' => $from, 'until' => $until]]);
    }

    /**
     * Mark an agent as back in the office. See the class comment for what Znuny does when it
     * does not find the agent.
     *
     * @param  string  $login  the agent's login
     */
    public function clearOutOfOffice(string $login): void
    {
        $this->outOfOffice([['login' => $login, 'enabled' => false]]);
    }

    /**
     * Set the out-of-office time of several agents in one call. Each entry names its agent
     * by exactly one of "login", "email" or "search" (Znuny's user search); "from" and
     * "until" are days, "enabled" is true unless given. See the class comment for what Znuny
     * does with an entry that matches no agent or several.
     *
     * @param  array<array-key, mixed>  $entries  list<array{login?: string, email?: string, search?: string, from?: DateTimeInterface, until?: DateTimeInterface, enabled?: bool}>
     */
    public function outOfOffice(array $entries): void
    {
        if ($entries === []) {
            throw new InvalidArgumentException('Pass at least one out-of-office entry.');
        }

        $mapped = [];

        foreach (array_values($entries) as $index => $entry) {
            $mapped[] = $this->entry($entry, $index + 1);
        }

        // Znuny's own web service builds the entries from the CSV string and fails without
        // it; a web service without that mapping reads the entries. Both say the same.
        $this->client->call('OutOfOffice', [
            'OutOfOfficeEntries' => $mapped,
            'OutOfOfficeEntriesCSVString' => OutOfOfficeCsv::fromEntries($mapped),
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function entry(mixed $entry, int $position): array
    {
        if (! is_array($entry)) {
            throw new InvalidArgumentException("Out-of-office entry {$position} must be an array.");
        }

        $users = [];

        foreach (self::USER_KEYS as $key => $znunyKey) {
            if (! is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                continue;
            }

            // Znuny's own web service reads the entries from CSV lines and drops lines that start with "#".
            if (preg_match('/[\r\n]/', $entry[$key]) === 1) {
                throw new InvalidArgumentException("The [{$key}] of out-of-office entry {$position} must not contain a line break.");
            }

            $users[$znunyKey] = $entry[$key];
        }

        if (count($users) !== 1) {
            throw new InvalidArgumentException("Out-of-office entry {$position} must name its agent by exactly one of [login], [email] or [search].");
        }

        // Znuny drops an end date that comes without a start date, and says nothing.
        if (isset($entry['from']) !== isset($entry['until'])) {
            throw new InvalidArgumentException("Out-of-office entry {$position} needs both [from] and [until], or neither.");
        }

        if (array_key_exists('enabled', $entry) && ! is_bool($entry['enabled'])) {
            throw new InvalidArgumentException("The [enabled] of out-of-office entry {$position} must be true or false.");
        }

        $mapped = $users;

        // Znuny stores calendar days, so these are the days the caller named, without conversion.
        foreach (['from' => 'StartDate', 'until' => 'EndDate'] as $key => $znunyKey) {
            if (! isset($entry[$key])) {
                continue;
            }

            if (! $entry[$key] instanceof DateTimeInterface) {
                throw new InvalidArgumentException("The [{$key}] of out-of-office entry {$position} must be a date.");
            }

            $mapped[$znunyKey] = ZnunyTime::day($entry[$key]);
        }

        $mapped['OutOfOffice'] = ($entry['enabled'] ?? true) ? 1 : 0;

        return $mapped;
    }
}
