<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\State;

/**
 * Needs the custom operation StateList in the web service.
 *
 * @deprecated since 0.4.0, removed in 1.0: Znuny does not ship this operation. Use Znuny::call() for custom operations.
 */
class StateResource extends Resource
{
    /** @return Collection<int, State> */
    public function all(): Collection
    {
        return collect($this->rows('states', 'StateList', 'State'))
            ->map(fn (array $row): State => State::fromZnunyResponse($row))
            ->values();
    }
}
