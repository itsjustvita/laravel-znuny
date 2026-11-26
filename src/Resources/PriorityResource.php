<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Priority;

/**
 * Needs the custom operation PriorityList in the web service.
 */
class PriorityResource extends Resource
{
    /** @return Collection<int, Priority> */
    public function all(): Collection
    {
        return collect($this->rows('priorities', 'PriorityList', 'Priority'))
            ->map(fn (array $row): Priority => Priority::fromZnunyResponse($row))
            ->values();
    }
}
