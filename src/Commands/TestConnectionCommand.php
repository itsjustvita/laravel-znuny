<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Support\TimeZoneCheck;
use ItsJustVita\LaravelZnuny\ZnunyClient;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class TestConnectionCommand extends Command
{
    /**
     * Minimum ages in minutes of the tickets whose dates are checked: the newest ticket and
     * one each from about four and eight months ago, so that a zone with other daylight
     * saving rules is noticed whatever the season.
     */
    private const SAMPLE_AGES = [0, 175_200, 350_400];

    protected $signature = 'znuny:test-connection {connection? : Connection name (defaults to the default connection)}';

    protected $description = 'Check credentials, routing, permissions and the time zone of a Znuny connection';

    public function handle(ZnunyManager $manager): int
    {
        $name = (string) ($this->argument('connection') ?? config('znuny.default', 'default'));

        try {
            $client = $manager->connection($name)->client();
            $settings = $client->settings();

            $this->components->twoColumnDetail('Base URL', $settings->baseUrl);
            $this->components->twoColumnDetail('Routes', $settings->routes->preset() ?? 'custom');
            $this->components->twoColumnDetail('Auth', "{$settings->auth}, {$settings->userType}, transport {$settings->authTransport}");

            if ($settings->usesSession()) {
                $client->ping();
                $this->components->twoColumnDetail('SessionCreate', '<fg=green;options=bold>OK</>');
            }

            $tickets = $this->sampleTickets($client);
            [$mismatch, $unverified] = $this->checkTimeZone($client, $tickets, $settings->timezone);
        } catch (ZnunyException $e) {
            $this->error("Znuny connection [{$name}] FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($mismatch !== null) {
            $this->error("Znuny connection [{$name}] FAILED: {$mismatch}");
            $this->line(
                "Set 'timezone' (ZNUNY_TIMEZONE) to Znuny's OTRSTimeZone, which you find in its system configuration. "
                .'If it is already set to that, the clocks of this server and Znuny differ.'
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Time zone', $unverified === null
            ? "{$settings->timezone} <fg=green;options=bold>OK</>"
            : "{$settings->timezone}, not verified ({$unverified})");

        $this->info("Znuny connection [{$name}] OK");

        return self::SUCCESS;
    }

    /**
     * Search and fetch the tickets for the time zone check, which also checks the routes
     * and permissions for TicketSearch and TicketGet.
     *
     * @return array<string, array<array-key, mixed>> TicketGet rows by ticket ID, newest first
     */
    private function sampleTickets(ZnunyClient $client): array
    {
        $tickets = [];

        foreach (self::SAMPLE_AGES as $minutes) {
            $search = ['Limit' => 1] + ($minutes > 0 ? ['TicketCreateTimeOlderMinutes' => $minutes] : []);
            $ticketIds = (array) ($client->call('TicketSearch', $search)['TicketID'] ?? []);

            if ($minutes === 0) {
                $this->components->twoColumnDetail('TicketSearch', '<fg=green;options=bold>OK</>');
            }

            if ($ticketIds === []) {
                break;
            }

            $ticketId = (string) reset($ticketIds);

            if (isset($tickets[$ticketId])) {
                continue;
            }

            $tickets[$ticketId] = $this->ticket($client, $ticketId);

            if (count($tickets) === 1) {
                $this->components->twoColumnDetail('TicketGet', '<fg=green;options=bold>OK</>');
            }
        }

        return $tickets;
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $tickets  TicketGet rows by ticket ID, newest first
     * @return array{0: string|null, 1: string|null} the mismatch found, and why nothing could be verified
     */
    private function checkTimeZone(ZnunyClient $client, array $tickets, string $timezone): array
    {
        $verified = 0;
        $unverified = $tickets === [] ? 'needs a ticket this user can read' : 'TicketGet returned no Created and Age';

        foreach ($tickets as $ticketId => $ticket) {
            $check = TimeZoneCheck::fromTicket($ticket, $timezone, now()->getTimestamp());

            if ($check === null) {
                continue;
            }

            if ($check->matches()) {
                $verified++;

                continue;
            }

            if ($ticketId === array_key_first($tickets) && $this->liesInZnunysFuture($client, (string) $ticketId, $ticket)) {
                $unverified = "the newest ticket's creation time lies in Znuny's future";

                continue;
            }

            return [sprintf(
                'Znuny returns dates in %s, but the time zone [%s] of this connection is %s (ticket %s, created %s).',
                TimeZoneCheck::label($check->znunyOffset),
                $timezone,
                TimeZoneCheck::label($check->configuredOffset),
                $ticketId,
                $ticket['Created'],
            ), null];
        }

        return [null, $verified > 0 ? null : $unverified];
    }

    /**
     * "Age" is the absolute distance between "Created" and now. It shrinks when "Created" lies
     * in Znuny's future, which happens in the hour that repeats when daylight saving time ends
     * and after OTRSTimeZone was changed. Such a ticket says nothing about the time zone.
     *
     * @param  array<array-key, mixed>  $ticket
     */
    private function liesInZnunysFuture(ZnunyClient $client, string $ticketId, array $ticket): bool
    {
        Sleep::for(2)->seconds();

        $age = $this->ticket($client, $ticketId)['Age'] ?? null;

        return is_numeric($age) && (int) $age < (int) $ticket['Age'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function ticket(ZnunyClient $client, string $ticketId): array
    {
        $ticket = $client->call('TicketGet', ['TicketID' => $ticketId])['Ticket'][0] ?? null;

        return is_array($ticket) ? $ticket : [];
    }
}
