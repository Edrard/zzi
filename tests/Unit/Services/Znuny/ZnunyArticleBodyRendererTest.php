<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyArticleBodyRenderer;
use App\Services\Znuny\ZnunyInlineImageContentId;
use Tests\TestCase;

class ZnunyArticleBodyRendererTest extends TestCase
{
    private ZnunyArticleBodyRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ZnunyArticleBodyRenderer;
    }

    private function getParsedImages(string $html): \DOMNodeList
    {
        $dom = new \DOMDocument;
        $useErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            @$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }

        return $dom->getElementsByTagName('img');
    }

    public function test_mime_type_text_html_is_treated_as_html(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'body' => '<p>HTML content</p>',
        ];

        $result = $this->renderer->render($article);
        $this->assertTrue($result['is_html']);
    }

    public function test_content_type_text_html_with_charset_is_treated_as_html(): void
    {
        $article = [
            'content_type' => 'text/html; charset=utf-8',
            'body' => '<p>HTML content</p>',
        ];

        $result = $this->renderer->render($article);
        $this->assertTrue($result['is_html']);
    }

    public function test_case_insensitive_media_type(): void
    {
        $article = [
            'content_type' => 'TEXT/HTML; charset=utf-8',
            'body' => '<p>HTML content</p>',
        ];

        $result = $this->renderer->render($article);
        $this->assertTrue($result['is_html']);
    }

    public function test_plain_text_with_html_looking_characters_stays_plain_unchanged(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'body' => '<p>Not actually HTML</p>',
        ];

        $result = $this->renderer->render($article);
        $this->assertFalse($result['is_html']);
        $this->assertEquals('<p>Not actually HTML</p>', $result['content']);
    }

    public function test_safe_formatting_survives(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'body' => '<div><p>Safe <strong>formatting</strong></p></div>',
        ];

        $result = $this->renderer->render($article);
        $this->assertTrue($result['is_html']);
        $this->assertStringContainsString('<div><p>Safe <strong>formatting</strong></p></div>', $result['content']);
    }

    public function test_script_event_javascript_content_removed(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'body' => '<p onclick="alert(1)">Hello <script>alert(2)</script></p>',
        ];

        $result = $this->renderer->render($article);
        $this->assertTrue($result['is_html']);
        $this->assertStringNotContainsString('onclick', $result['content']);
        $this->assertStringNotContainsString('<script', $result['content']);
        $this->assertStringContainsString('<p>Hello </p>', $result['content']);
    }

    public function test_valid_cid_becomes_relative_named_route_in_data_attribute(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);

        $token = ZnunyInlineImageContentId::encodeToken('image1@domain.com');
        $expectedUrl = route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $token], false);

        $images = $this->getParsedImages($result['content']);
        $this->assertEquals(1, $images->length);
        $img = $images->item(0);

        $this->assertFalse($img->hasAttribute('src'), 'No real src attribute should exist');
        $this->assertSame($expectedUrl, $img->getAttribute('data-znuny-inline-src'));
        $this->assertSame('lazy', $img->getAttribute('loading'));
        $this->assertStringNotContainsString('image1@domain.com', $result['content']); // Raw ID not in route
    }

    public function test_real_plaintext_single_marker_regression(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'ticket_id' => 59234,
            'article_id' => 355030,
            'body' => '[cid:image001.png@01DD3159.C57C2200]',
        ];

        $result = $this->renderer->render($article);

        $this->assertTrue($result['is_html']);

        $canonicalCid = 'image001.png@01DD3159.C57C2200';
        $token = ZnunyInlineImageContentId::encodeToken($canonicalCid);
        $expectedUrl = route('znuny.inline-image.show', ['ticketId' => 59234, 'articleId' => 355030, 'token' => $token], false);

        $images = $this->getParsedImages($result['content']);
        $this->assertEquals(1, $images->length);
        $img = $images->item(0);

        $this->assertFalse($img->hasAttribute('src'), 'No real src attribute should exist');
        $this->assertSame($expectedUrl, $img->getAttribute('data-znuny-inline-src'));
        $this->assertSame('lazy', $img->getAttribute('loading'));

        $this->assertSame($canonicalCid, ZnunyInlineImageContentId::decodeToken($token));
    }

    public function test_multiple_plaintext_markers(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => "First image: [cid:first@domain.com]\nSecond image: [cid:second@domain.com]",
        ];

        $result = $this->renderer->render($article);

        $this->assertTrue($result['is_html']);

        $token1 = ZnunyInlineImageContentId::encodeToken('first@domain.com');
        $token2 = ZnunyInlineImageContentId::encodeToken('second@domain.com');

        $pos1 = strpos($result['content'], $token1);
        $pos2 = strpos($result['content'], $token2);

        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1);
    }

    public function test_plaintext_safety_with_markers(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<script>alert(1)</script> / <b>text</b> [cid:image1@domain.com]',
        ];

        $result = $this->renderer->render($article);

        $this->assertTrue($result['is_html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; / &lt;b&gt;text&lt;/b&gt;', $result['content']);
        $this->assertStringContainsString('data-znuny-inline-src', $result['content']);
    }

    public function test_no_marker_in_plaintext_remains_unchanged(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => 'This is just plain text without any markers.',
        ];

        $result = $this->renderer->render($article);

        $this->assertFalse($result['is_html']);
        $this->assertSame('This is just plain text without any markers.', $result['content']);
    }

    public function test_invalid_or_missing_ids_in_plaintext_fail_safe(): void
    {
        $article = [
            'mime_type' => 'text/plain',
            'ticket_id' => 0, // Invalid
            'article_id' => 456,
            'body' => '[cid:image1@domain.com]',
        ];

        $result = $this->renderer->render($article);
        $this->assertFalse($result['is_html']);
        $this->assertSame('[cid:image1@domain.com]', $result['content']);
    }

    public function test_multiple_cid_images_preserve_order(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img src="cid:first@domain.com"><img src="cid:second@domain.com">',
        ];

        $result = $this->renderer->render($article);

        $token1 = ZnunyInlineImageContentId::encodeToken('first@domain.com');
        $token2 = ZnunyInlineImageContentId::encodeToken('second@domain.com');

        $pos1 = strpos($result['content'], $token1);
        $pos2 = strpos($result['content'], $token2);

        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos2, $pos1);
    }

    public function test_external_and_data_sources_are_non_fetchable(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img src="http://example.com/a.png"><img src="https://example.com/b.png"><img src="data:image/png;base64,123"><img src="//protocol.relative/c.png"><img src="/root.relative/d.png"><img src="relative/path/e.png">',
        ];

        $result = $this->renderer->render($article);

        $images = $this->getParsedImages($result['content']);
        $this->assertEquals(6, $images->length);
        for ($i = 0; $i < $images->length; $i++) {
            $img = $images->item($i);
            $this->assertFalse($img->hasAttribute('src'), 'No real src attribute should exist');
            $this->assertFalse($img->hasAttribute('data-znuny-inline-src'));
        }
    }

    public function test_malformed_cid_non_fetchable(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img src="cid:">', // Empty ID
        ];

        $result = $this->renderer->render($article);

        $this->assertStringNotContainsString('data-znuny-inline-src', $result['content']);
    }

    public function test_invalid_ticket_id_is_non_fetchable(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 0, // Invalid
            'article_id' => 456,
            'body' => '<img src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);
        $this->assertStringNotContainsString('data-znuny-inline-src', $result['content']);
    }

    public function test_missing_or_invalid_article_id_is_non_fetchable(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'body' => '<img src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);
        $this->assertStringNotContainsString('data-znuny-inline-src', $result['content']);

        $article['article_id'] = 0; // Invalid
        $result = $this->renderer->render($article);
        $this->assertStringNotContainsString('data-znuny-inline-src', $result['content']);
    }

    public function test_srcset_is_stripped_from_images(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img src="cid:image1@domain.com" srcset="https://evil.example/one.png 1x, https://evil.example/two.png 2x">',
        ];

        $result = $this->renderer->render($article);

        $images = $this->getParsedImages($result['content']);
        $this->assertEquals(1, $images->length);
        $img = $images->item(0);

        $this->assertTrue($img->hasAttribute('data-znuny-inline-src'));
        $this->assertFalse($img->hasAttribute('src'));
        $this->assertFalse($img->hasAttribute('srcset'));
        $this->assertStringNotContainsString('evil.example', $result['content']);
    }

    public function test_exact_placeholder_never_survives(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            // A fake placeholder string to ensure the renderer doesn't accidentally replace it or crash
            'body' => '<p>Check out https://placeholder.internal/foo</p><img src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);

        $this->assertStringContainsString('https://placeholder.internal/foo', $result['content']);

        $token = ZnunyInlineImageContentId::encodeToken('image1@domain.com');
        $this->assertStringContainsString($token, $result['content']);
    }

    public function test_user_supplied_data_znuny_inline_src_cannot_override(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img data-znuny-inline-src="hack" src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);

        $token = ZnunyInlineImageContentId::encodeToken('image1@domain.com');

        $this->assertStringNotContainsString('hack', $result['content']); // sanitizer strips data-*
        $this->assertStringContainsString($token, $result['content']);
    }

    public function test_user_supplied_event_attributes_remain_sanitized(): void
    {
        $article = [
            'mime_type' => 'text/html',
            'ticket_id' => 123,
            'article_id' => 456,
            'body' => '<img onerror="alert(1)" src="cid:image1@domain.com">',
        ];

        $result = $this->renderer->render($article);

        $this->assertStringNotContainsString('onerror', $result['content']);
        $this->assertStringContainsString('data-znuny-inline-src', $result['content']);
    }
}
