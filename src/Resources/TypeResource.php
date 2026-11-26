<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Type;

/**
 * Needs the custom operation TypeList in the web service.
 */
class TypeResource extends Resource
{
    /** @return Collection<int, Type> */
    public function all(): Collection
    {
        return collect($this->rows('types', 'TypeList', 'Type'))
            ->map(fn (array $row): Type => Type::fromZnunyResponse($row))
            ->values();
    }
}
