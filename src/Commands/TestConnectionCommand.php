<?php

namespace ItsJustVita\LaravelZnuny\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelZnuny\Exceptions\ZnunyException;
use ItsJustVita\LaravelZnuny\Support\TimeZoneCheck;
use ItsJustVita\LaravelZnuny\ZnunyManager;

class TestConnectionCommand extends Command
{
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

            $ticketIds = (array) ($client->call('TicketSearch', ['Limit' => 1])['TicketID'] ?? []);
            $this->components->twoColumnDetail('TicketSearch', '<fg=green;options=bold>OK</>');

            $check = null;

            if ($ticketIds !== []) {
                $ticket = $client->call('TicketGet', ['TicketID' => (string) reset($ticketIds)])['Ticket'][0] ?? null;
                $this->components->twoColumnDetail('TicketGet', '<fg=green;options=bold>OK</>');

                $check = TimeZoneCheck::fromTicket(is_array($ticket) ? $ticket : [], $settings->timezone, now()->getTimestamp());
            }
        } catch (ZnunyException $e) {
            $this->error("Znuny connection [{$name}] FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($check === null) {
            $this->components->twoColumnDetail('Time zone', "{$settings->timezone}, not verified (needs a ticket this user can read)");
        } elseif (! $check->matches()) {
            $this->error(sprintf(
                'Znuny connection [%s] FAILED: Znuny returns dates in %s, but the time zone [%s] of this connection is %s.',
                $name,
                TimeZoneCheck::label($check->znunyOffset),
                $settings->timezone,
                TimeZoneCheck::label($check->configuredOffset),
            ));
            $this->line(
                "Set 'timezone' (ZNUNY_TIMEZONE) to Znuny's OTRSTimeZone, which you find in its system configuration. "
                .'If it is already set to that, the clocks of this server and Znuny differ.'
            );

            return self::FAILURE;
        } else {
            $this->components->twoColumnDetail('Time zone', "{$settings->timezone} <fg=green;options=bold>OK</>");
        }

        $this->info("Znuny connection [{$name}] OK");

        return self::SUCCESS;
    }
}
