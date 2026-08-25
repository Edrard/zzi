<?php

namespace App\Services\Znuny;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

class ZnunyArticleBodyRenderer
{
    public function render(array $article): array
    {
        if (! empty($article['html_body_available']) && isset($article['html_body'])) {
            $ticketId = $article['ticket_id'] ?? null;
            $articleId = $article['article_id'] ?? null;
            $content = $this->processHtmlBody($article['html_body'], $ticketId, $articleId);

            return [
                'is_html' => true,
                'content' => $content,
            ];
        }

        $body = $article['body'] ?? '';

        if (! $this->isHtmlArticle($article)) {
            $ticketId = $article['ticket_id'] ?? null;
            $articleId = $article['article_id'] ?? null;

            return $this->processPlaintextBody($body, $ticketId, $articleId);
        }

        if (trim($body) === '') {
            return [
                'is_html' => true,
                'content' => '',
            ];
        }

        $ticketId = $article['ticket_id'] ?? null;
        $articleId = $article['article_id'] ?? null;

        $content = $this->processHtmlBody($body, $ticketId, $articleId);

        return [
            'is_html' => true,
            'content' => $content,
        ];
    }

    private function isHtmlArticle(array $article): bool
    {
        $mimeType = $this->extractMediaType($article['mime_type'] ?? '');
        if ($mimeType === 'text/html') {
            return true;
        }

        $contentType = $this->extractMediaType($article['content_type'] ?? '');
        if ($contentType === 'text/html') {
            return true;
        }

        return false;
    }

    private function extractMediaType(string $header): string
    {
        $parts = explode(';', $header);

        return strtolower(trim($parts[0]));
    }

    private function processPlaintextBody(string $body, int|string|null $ticketId, int|string|null $articleId): array
    {
        if (trim($body) === '') {
            return [
                'is_html' => false,
                'content' => $body,
            ];
        }

        if (! $this->isValidId($ticketId) || ! $this->isValidId($articleId)) {
            return [
                'is_html' => false,
                'content' => $body,
            ];
        }

        $pattern = '/\[cid:([^\]\r\n]{1,512})\]/i';

        $hasMatches = preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);
        if (! $hasMatches) {
            return [
                'is_html' => false,
                'content' => $body,
            ];
        }

        $html = '';
        $lastPos = 0;
        $convertedAny = false;

        foreach ($matches[0] as $index => $match) {
            $fullMatch = $match[0];
            $pos = $match[1];
            $cid = $matches[1][$index][0];

            $url = $this->buildLaravelRouteUrl($cid, $ticketId, $articleId);

            if ($url !== null) {
                $textBefore = substr($body, $lastPos, $pos - $lastPos);
                if ($textBefore !== '') {
                    $html .= '<span class="whitespace-pre-wrap">'.e($textBefore).'</span>';
                }

                $html .= '<img data-znuny-inline-src="'.e($url).'" loading="lazy">';
                $convertedAny = true;
                $lastPos = $pos + strlen($fullMatch);
            }
        }

        if (! $convertedAny) {
            return [
                'is_html' => false,
                'content' => $body,
            ];
        }

        $textAfter = substr($body, $lastPos);
        if ($textAfter !== '') {
            $html .= '<span class="whitespace-pre-wrap">'.e($textAfter).'</span>';
        }

        return [
            'is_html' => true,
            'content' => $html,
        ];
    }

    private function processHtmlBody(string $html, int|string|null $ticketId, int|string|null $articleId): string
    {
        $dom = $this->parseHtml($html);
        if (! $dom) {
            return '';
        }

        $placeholders = [];
        $images = $dom->getElementsByTagName('img');

        // Step 1: Pre-sanitization CID rewrite and external image removal
        // Iterating backwards when modifying DOMNodeList
        for ($i = $images->length - 1; $i >= 0; $i--) {
            /** @var DOMElement $img */
            $img = $images->item($i);
            $img->removeAttribute('srcset');
            $src = trim($img->getAttribute('src'));

            if (stripos($src, 'cid:') === 0) {
                $finalUrl = $this->buildLaravelRouteUrl($src, $ticketId, $articleId);

                if ($finalUrl) {
                    $placeholder = 'https://placeholder.internal/'.Str::uuid();
                    $placeholders[$placeholder] = $finalUrl;
                    $img->setAttribute('src', $placeholder);
                } else {
                    $img->removeAttribute('src');
                }
            } else {
                // Non-CID or malformed
                $img->removeAttribute('src');
            }
        }

        $sources = $dom->getElementsByTagName('source');
        for ($i = $sources->length - 1; $i >= 0; $i--) {
            /** @var DOMElement $source */
            $source = $sources->item($i);
            $source->removeAttribute('srcset');
            $source->removeAttribute('src');
        }

        $intermediateHtml = $this->serializeHtml($dom);

        // Step 2: Sanitize
        $sanitizedHtml = Str::sanitizeHtml($intermediateHtml);

        // Step 3: Post-sanitization cleanup and exact mapping
        $sanitizedDom = $this->parseHtml($sanitizedHtml);
        if (! $sanitizedDom) {
            return '';
        }

        $sanitizedImages = $sanitizedDom->getElementsByTagName('img');
        for ($i = $sanitizedImages->length - 1; $i >= 0; $i--) {
            /** @var DOMElement $img */
            $img = $sanitizedImages->item($i);
            $img->removeAttribute('srcset');
            $src = trim($img->getAttribute('src'));

            if ($src !== '' && array_key_exists($src, $placeholders)) {
                $img->removeAttribute('src');
                $img->setAttribute('data-znuny-inline-src', $placeholders[$src]);
                $img->setAttribute('loading', 'lazy');
            } else {
                $img->removeAttribute('src');
            }
        }

        $sanitizedSources = $sanitizedDom->getElementsByTagName('source');
        for ($i = $sanitizedSources->length - 1; $i >= 0; $i--) {
            /** @var DOMElement $source */
            $source = $sanitizedSources->item($i);
            $source->removeAttribute('srcset');
            $source->removeAttribute('src');
        }

        $finalHtml = $this->serializeHtml($sanitizedDom);

        foreach ($placeholders as $placeholder => $url) {
            if (str_contains($finalHtml, $placeholder)) {
                throw new \RuntimeException('Placeholder leaked into final sanitized HTML');
            }
        }

        return $finalHtml;
    }

    private function buildLaravelRouteUrl(string $cidSrc, int|string|null $ticketId, int|string|null $articleId): ?string
    {
        if (! $this->isValidId($ticketId) || ! $this->isValidId($articleId)) {
            return null;
        }

        try {
            $normalizedCid = ZnunyInlineImageContentId::normalize($cidSrc);
            $token = ZnunyInlineImageContentId::encodeToken($normalizedCid);

            return route('znuny.inline-image.show', [
                'ticketId' => $ticketId,
                'articleId' => $articleId,
                'token' => $token,
            ], false); // relative URL
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isValidId(int|string|null $id): bool
    {
        if ($id === null) {
            return false;
        }

        return (bool) preg_match('/^[1-9]\d*$/', (string) $id);
    }

    private function parseHtml(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $useErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $fragment = '<?xml encoding="UTF-8"><body>'.$html.'</body>';
            $success = $dom->loadHTML($fragment, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            if (! $success) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }

        return $dom;
    }

    private function serializeHtml(DOMDocument $dom): string
    {
        $bodyNodes = $dom->getElementsByTagName('body');
        if ($bodyNodes->length === 0) {
            return '';
        }

        $body = $bodyNodes->item(0);
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
