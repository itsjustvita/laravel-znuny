<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\CarbonImmutable;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class HistoryEntry
{
    use HasAttributes;

    /**
     * @param  array<string, mixed>  $attributes  raw Znuny fields
     */
    public function __construct(
        public string $historyType,
        public string $name,
        public ?CarbonImmutable $created,
        public ?string $createdBy,
        public ?string $articleId,
        public array $attributes = [],
    ) {}

    /**
     * @param  string  $timezone  Znuny's OTRSTimeZone, in which it returns dates
     * @param  array<string, mixed>  $data
     */
    public static function fromZnunyResponse(array $data, string $timezone = 'UTC'): self
    {
        $articleId = self::optionalString($data['ArticleID'] ?? null);

        return new self(
            historyType: (string) ($data['HistoryType'] ?? ''),
            name: (string) ($data['Name'] ?? ''),
            created: self::optionalDate($data['CreateTime'] ?? null, $timezone),
            createdBy: self::optionalString($data['CreateBy'] ?? null),
            articleId: $articleId === '0' ? null : $articleId,
            attributes: $data,
        );
    }

    /**
     * $createTime and $createBy were renamed in 1.0, to match the other data objects.
     *
     * @return array<string, string>
     */
    protected static function renamedProperties(): array
    {
        return ['createTime' => 'created', 'createBy' => 'createdBy'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'HistoryType' => $this->historyType,
            'Name' => $this->name,
            'CreateTime' => $this->created?->format('Y-m-d H:i:s'),
            'CreateBy' => $this->createdBy,
            'ArticleID' => $this->articleId,
        ];
    }
}
