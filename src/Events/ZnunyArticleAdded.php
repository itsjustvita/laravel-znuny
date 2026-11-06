<?php

namespace ItsJustVita\LaravelZnuny\Events;

final readonly class ZnunyArticleAdded
{
    /**
     * @param  array<string, mixed>  $article
     */
    public function __construct(
        public string $connection,
        public string $ticketId,
        public array $article,
        public ?string $articleId = null,
    ) {}
}
