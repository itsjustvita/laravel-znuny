<?php

namespace ItsJustVita\LaravelZnuny\Resources;

use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Type;

/**
 * Needs the custom operation TypeList in the web service.
 *
 * @deprecated since 0.4.0, removed in 1.0: Znuny does not ship this operation. Use Znuny::call() for custom operations.
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
