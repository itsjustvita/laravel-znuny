<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\State;

class StateResource extends Resource
{
    /** @return Collection<int, State> */
    public function all(): Collection
    {
        return $this->remember('states', function () {
            $response = $this->client->request('StateList', []);

            return collect($response['State'] ?? [])
                ->map(fn (array $row) => State::fromZnunyResponse($row))
                ->values();
        });
    }
}
