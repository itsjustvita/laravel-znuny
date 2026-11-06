<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class Article
{
    use HasAttributes;

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @param  array<string, mixed>  $attributes  raw Znuny fields without attachments
     */
    public function __construct(
        public string $id,
        public string $subject,
        public string $body,
        public string $contentType,
        public ?string $communicationChannel,
        public ?string $senderType,
        public ?string $from,
        public ?Carbon $created,
        public ?string $to = null,
        public ?string $cc = null,
        public ?string $bcc = null,
        public ?bool $isVisibleForCustomer = null,
        public Collection $attachments = new Collection,
        public array $attributes = [],
    ) {}

    /**
     * @param  string  $timezone  Znuny's OTRSTimeZone, in which it returns dates
     */
    public static function fromZnunyResponse(array $data, string $timezone = 'UTC'): self
    {
        $attachments = collect(is_array($data['Attachment'] ?? null) ? $data['Attachment'] : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): Attachment => Attachment::fromZnunyResponse($row))
            ->values();

        return new self(
            id: (string) ($data['ArticleID'] ?? ''),
            subject: (string) ($data['Subject'] ?? ''),
            body: (string) ($data['Body'] ?? ''),
            contentType: (string) ($data['ContentType'] ?? ''),
            communicationChannel: $data['CommunicationChannel'] ?? null,
            senderType: $data['SenderType'] ?? null,
            from: $data['From'] ?? null,
            created: self::optionalDate($data['CreateTime'] ?? null, $timezone),
            to: self::optionalString($data['To'] ?? null),
            cc: self::optionalString($data['Cc'] ?? null),
            bcc: self::optionalString($data['Bcc'] ?? null),
            isVisibleForCustomer: isset($data['IsVisibleForCustomer']) ? (bool) (int) $data['IsVisibleForCustomer'] : null,
            attachments: $attachments,
            attributes: array_diff_key($data, ['Attachment' => true]),
        );
    }

    public function toArray(): array
    {
        return [
            'ArticleID' => $this->id,
            'Subject' => $this->subject,
            'Body' => $this->body,
            'ContentType' => $this->contentType,
            'CommunicationChannel' => $this->communicationChannel,
            'SenderType' => $this->senderType,
            'From' => $this->from,
            'To' => $this->to,
            'Cc' => $this->cc,
            'Bcc' => $this->bcc,
            'IsVisibleForCustomer' => $this->isVisibleForCustomer === null ? null : (int) $this->isVisibleForCustomer,
            'CreateTime' => $this->created?->format('Y-m-d H:i:s'),
            'Attachment' => $this->attachments->map(fn (Attachment $attachment): array => $attachment->toArray())->all(),
        ];
    }
}
