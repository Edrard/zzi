<?php

namespace App\Services\Znuny;

use DOMDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class ZnunyInlineImagePayloadService
{
    public function __construct(
        protected string $disk = 'local',
    ) {}

    public function processHtml(string $html, string $expectedDirectory): array
    {
        $attachments = [];

        if (empty(trim($html))) {
            return [
                'html' => $html,
                'attachments' => [],
            ];
        }

        // Use DOMDocument to safely parse and manipulate HTML
        $dom = new DOMDocument;
        $dom->encoding = 'UTF-8';

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }

        $images = $dom->getElementsByTagName('img');
        $placeholders = [];

        foreach ($images as $img) {
            $dataId = $img->getAttribute('data-id');

            if (empty($dataId)) {
                $src = $img->getAttribute('src');
                if (str_starts_with($src, 'data:') || str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '/')) {
                    throw new \InvalidArgumentException('Unsupported image source: external, data URIs, or local URLs are not allowed without a valid draft upload.');
                }

                throw new \InvalidArgumentException('Image without a valid authorization token detected.');
            }

            // Reject traversal attempts
            if (str_contains($dataId, '..') || str_starts_with($dataId, '/') || str_contains($dataId, '\\')) {
                throw new \InvalidArgumentException('Invalid file path detected.');
            }

            // Must be within the expected draft directory
            if (! str_starts_with($dataId, $expectedDirectory.'/')) {
                throw new \InvalidArgumentException("Image path {$dataId} is not within the authorized draft directory.");
            }

            if (! Storage::disk($this->disk)->exists($dataId)) {
                throw new \InvalidArgumentException("Image file {$dataId} does not exist.");
            }

            $content = Storage::disk($this->disk)->get($dataId);

            // Validate MIME type
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($content);

            $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            if (! in_array($mimeType, $allowedMimes, true)) {
                throw new \InvalidArgumentException("Unsupported MIME type: {$mimeType}.");
            }

            // Additional check
            if (! @getimagesizefromstring($content)) {
                throw new \InvalidArgumentException("Invalid image data in file {$dataId}.");
            }

            $extension = (new MimeTypes)->getExtensions($mimeType)[0] ?? 'bin';
            $filename = 'image_'.Str::random(8).'.'.$extension;
            $uuid = Str::uuid()->toString();
            $contentId = "znuny-inline-{$uuid}@work.vamark.com";

            $placeholderUrl = "https://znuny-inline.invalid/{$uuid}";
            $placeholders[$placeholderUrl] = $contentId;

            // Add attachment
            $attachments[] = [
                'Content' => base64_encode($content),
                'ContentType' => $mimeType.'; name="'.$filename.'"',
                'Filename' => $filename,
                'Disposition' => 'inline',
                'ContentID' => $contentId,
            ];

            // Rewrite src and remove data-id
            $img->setAttribute('src', $placeholderUrl);
            $img->removeAttribute('data-id');
        }

        // Extract body inner HTML
        $bodyNodes = $dom->getElementsByTagName('body')->item(0)->childNodes;
        $processedHtml = '';
        foreach ($bodyNodes as $node) {
            $processedHtml .= $dom->saveHTML($node);
        }

        $sanitizedHtml = Str::sanitizeHtml($processedHtml);

        $finalHtml = $sanitizedHtml;
        if (! empty($placeholders)) {
            $finalHtml = str_replace(
                array_keys($placeholders),
                array_map(fn ($cid) => "cid:{$cid}", array_values($placeholders)),
                $sanitizedHtml
            );
        }

        if (str_contains($finalHtml, 'https://znuny-inline.invalid/')) {
            throw new \RuntimeException('Placeholder URL leaked into final HTML.');
        }

        return [
            'html' => $finalHtml,
            'attachments' => $attachments,
        ];
    }
}
