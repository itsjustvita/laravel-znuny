<?php

namespace ItsJustVita\LaravelZnuny\Tests\Unit\Data;

use Carbon\Carbon;
use ItsJustVita\LaravelZnuny\Data\Article;
use ItsJustVita\LaravelZnuny\Tests\TestCase;

final class ArticleTest extends TestCase
{
    public function test_it_builds_from_znuny_response(): void
    {
        $article = Article::fromZnunyResponse([
            'ArticleID'             => '500',
            'Subject'               => 'Initial',
            'Body'                  => 'Hello',
            'ContentType'           => 'text/plain; charset=utf8',
            'CommunicationChannel'  => 'Internal',
            'SenderType'            => 'agent',
            'From'                  => 'agent@example.com',
            'CreateTime'            => '2026-04-08 10:00:00',
        ]);

        $this->assertSame('500', $article->id);
        $this->assertSame('Initial', $article->subject);
        $this->assertSame('Hello', $article->body);
        $this->assertSame('text/plain; charset=utf8', $article->contentType);
        $this->assertSame('Internal', $article->communicationChannel);
        $this->assertSame('agent', $article->senderType);
        $this->assertInstanceOf(Carbon::class, $article->created);
    }

    public function test_it_serializes_to_array(): void
    {
        $article = new Article(
            id: '500',
            subject: 'Initial',
            body: 'Hello',
            contentType: 'text/plain; charset=utf8',
            communicationChannel: 'Internal',
            senderType: 'agent',
            from: 'agent@example.com',
            created: Carbon::parse('2026-04-08 10:00:00'),
        );

        $this->assertSame([
            'ArticleID'            => '500',
            'Subject'              => 'Initial',
            'Body'                 => 'Hello',
            'ContentType'          => 'text/plain; charset=utf8',
            'CommunicationChannel' => 'Internal',
            'SenderType'           => 'agent',
            'From'                 => 'agent@example.com',
            'CreateTime'           => '2026-04-08 10:00:00',
        ], $article->toArray());
    }
}
