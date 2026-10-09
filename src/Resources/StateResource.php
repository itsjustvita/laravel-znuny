<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\State;

/**
 * Needs the custom operation StateList in the web service.
 */
class StateResource extends Resource
{
    /** @return Collection<int, State> */
    public function all(): Collection
    {
        return $this->remember('states', function () {
            $response = $this->client->call('StateList');

            return collect($response['State'] ?? [])
                ->map(fn (array $row) => State::fromZnunyResponse($row))
                ->values();
        });
    }
}
