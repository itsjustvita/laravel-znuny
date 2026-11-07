<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_an_uploaded_file_becomes_an_attachment(): void
    {
        $attachment = Attachment::fromUploadedFile(UploadedFile::fake()->createWithContent('report.csv', "a,b\n1,2\n"));

        $this->assertSame('report.csv', $attachment->filename);
        $this->assertSame("a,b\n1,2\n", $attachment->content);
        $this->assertStringStartsWith('text/', $attachment->contentType);
        $this->assertSame('renamed.csv', Attachment::fromUploadedFile(UploadedFile::fake()->createWithContent('report.csv', 'a'), 'renamed.csv')->filename);
    }

    public function test_an_upload_that_cannot_be_read_is_reported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report.csv');

        Attachment::fromUploadedFile(new UploadedFile('/no/such/upload', 'report.csv', 'text/csv', UPLOAD_ERR_NO_FILE, true));
    }

    public function test_a_file_from_storage_becomes_an_attachment(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('tickets/photo.txt', 'hello');

        $attachment = Attachment::fromStorage('tickets/photo.txt', 'documents');

        $this->assertSame(['photo.txt', 'hello', 'text/plain'], [$attachment->filename, $attachment->content, $attachment->contentType]);
        $this->assertSame('note.txt', Attachment::fromStorage('tickets/photo.txt', 'documents', 'note.txt')->filename);
    }

    public function test_the_default_disk_is_used_without_a_disk_name(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::put('report.txt', 'from the default disk');

        $this->assertSame('from the default disk', Attachment::fromStorage('report.txt')->content);
    }

    public function test_a_missing_file_in_storage_is_reported(): void
    {
        Storage::fake('documents');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment file [tickets/nope.pdf] does not exist on disk [documents].');

        Attachment::fromStorage('tickets/nope.pdf', 'documents');
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
