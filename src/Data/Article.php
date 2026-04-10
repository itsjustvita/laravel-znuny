<?php

namespace ItsJustVita\LaravelZnuny\Data;

use Carbon\Carbon;

final readonly class Article
{
    public function __construct(
        public string $id,
        public string $subject,
        public string $body,
        public string $contentType,
        public ?string $communicationChannel,
        public ?string $senderType,
        public ?string $from,
        public ?Carbon $created,
    ) {}

    public static function fromZnunyResponse(array $data): self
    {
        return new self(
            id: (string) ($data['ArticleID'] ?? ''),
            subject: (string) ($data['Subject'] ?? ''),
            body: (string) ($data['Body'] ?? ''),
            contentType: (string) ($data['ContentType'] ?? ''),
            communicationChannel: $data['CommunicationChannel'] ?? null,
            senderType: $data['SenderType'] ?? null,
            from: $data['From'] ?? null,
            created: isset($data['CreateTime']) ? Carbon::parse($data['CreateTime']) : null,
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
            'CreateTime' => $this->created?->format('Y-m-d H:i:s'),
        ];
    }
}
