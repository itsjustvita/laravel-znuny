<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use InvalidArgumentException;
use ItsJustVita\LaravelZnuny\Data\Attachment;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class AttachmentTest extends TestCase
{
    public function test_from_content_builds_the_znuny_payload(): void
    {
        $attachment = Attachment::fromContent('note.txt', 'hello', 'text/plain');

        $this->assertSame(5, $attachment->filesize);
        $this->assertSame([
            'Filename' => 'note.txt',
            'ContentType' => 'text/plain',
            'Content' => base64_encode('hello'),
        ], $attachment->toZnunyPayload());
    }

    public function test_from_path_reads_the_file_and_detects_the_mime_type(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'znuny');
        file_put_contents($path, "plain text\n");

        try {
            $attachment = Attachment::fromPath($path, 'readme.txt');
        } finally {
            unlink($path);
        }

        $this->assertSame('readme.txt', $attachment->filename);
        $this->assertSame("plain text\n", $attachment->content);
        $this->assertSame('text/plain', $attachment->contentType);
    }

    public function test_from_path_rejects_missing_files(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Attachment::fromPath('/nonexistent/file.pdf');
    }

    public function test_from_znuny_response_decodes_base64_content(): void
    {
        $attachment = Attachment::fromZnunyResponse([
            'Filename' => 'a.txt',
            'ContentType' => 'text/plain; charset=utf-8',
            'Content' => base64_encode('abc'),
            'FilesizeRaw' => '3',
            'Filesize' => '3 Bytes',
            'ContentID' => '',
            'Disposition' => 'attachment',
            'FileID' => '2',
        ]);

        $this->assertSame('abc', $attachment->content);
        $this->assertSame(3, $attachment->filesize);
        $this->assertNull($attachment->contentId);
        $this->assertSame('attachment', $attachment->disposition);
        $this->assertSame('2', $attachment->attribute('FileID'));
        $this->assertArrayNotHasKey('Content', $attachment->attributes);
    }
}
