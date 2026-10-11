<?php

namespace ItsJustVita\LaravelZnuny\Data;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Concerns\HasAttributes;

final readonly class Attachment
{
    use HasAttributes;

    /**
     * @param  string  $content  decoded file contents
     * @param  array<string, mixed>  $attributes  raw Znuny fields without the base64 content
     */
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public int $filesize,
        public ?string $contentId = null,
        public ?string $disposition = null,
        public array $attributes = [],
    ) {}

    public static function fromContent(string $filename, string $content, string $contentType = 'application/octet-stream'): self
    {
        return new self(
            filename: $filename,
            contentType: $contentType,
            content: $content,
            filesize: strlen($content),
        );
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Attachment file [{$path}] does not exist or is not readable.");
        }

        $detected = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return self::fromContent(
            filename: $filename ?? basename($path),
            content: (string) file_get_contents($path),
            contentType: $contentType ?? ($detected ?: 'application/octet-stream'),
        );
    }

    public static function fromZnunyResponse(array $data): self
    {
        $raw = (string) ($data['Content'] ?? '');
        $decoded = base64_decode($raw, true);
        $content = $decoded === false ? $raw : $decoded;

        return new self(
            filename: (string) ($data['Filename'] ?? ''),
            contentType: (string) ($data['ContentType'] ?? 'application/octet-stream'),
            content: $content,
            filesize: (int) ($data['FilesizeRaw'] ?? strlen($content)),
            contentId: self::optionalString($data['ContentID'] ?? null),
            disposition: self::optionalString($data['Disposition'] ?? null),
            attributes: array_diff_key($data, ['Content' => true]),
        );
    }

    /**
     * The structure TicketCreate/TicketUpdate expect in their "Attachment" list.
     *
     * @return array{Filename: string, ContentType: string, Content: string}
     */
    public function toZnunyPayload(): array
    {
        return [
            'Filename' => $this->filename,
            'ContentType' => $this->contentType,
            'Content' => base64_encode($this->content),
        ];
    }

    public function toArray(): array
    {
        return [
            'Filename' => $this->filename,
            'ContentType' => $this->contentType,
            'Filesize' => $this->filesize,
            'ContentID' => $this->contentId,
            'Disposition' => $this->disposition,
            'Content' => base64_encode($this->content),
        ];
    }
}
