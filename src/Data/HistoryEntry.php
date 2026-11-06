<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\Carbon;
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
        public ?Carbon $createTime,
        public ?string $createBy,
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
            createTime: self::optionalDate($data['CreateTime'] ?? null, $timezone),
            createBy: self::optionalString($data['CreateBy'] ?? null),
            articleId: $articleId === '0' ? null : $articleId,
            attributes: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'HistoryType' => $this->historyType,
            'Name' => $this->name,
            'CreateTime' => $this->createTime?->format('Y-m-d H:i:s'),
            'CreateBy' => $this->createBy,
            'ArticleID' => $this->articleId,
        ];
    }
}
