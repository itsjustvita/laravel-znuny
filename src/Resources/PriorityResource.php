<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Priority;

class PriorityResource extends Resource
{
    /** @return Collection<int, Priority> */
    public function all(): Collection
    {
        $response = $this->client->request('PriorityList', []);

        return collect($response['Priority'] ?? [])
            ->map(fn (array $row) => Priority::fromZnunyResponse($row))
            ->values();
    }
}
