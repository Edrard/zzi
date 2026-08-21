<?php

namespace App\Services\Znuny;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class ZnunyInlineImageService
{
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    private const MAX_DECODED_SIZE = 26214400; // 25 MiB

    public function __construct(private readonly ZnunyClient $client) {}

    public function getInlineImage(int|string $ticketId, int|string $articleId, string $contentId): ?array
    {
        $canonicalContentId = ZnunyInlineImageContentId::normalize($contentId);

        $cacheKey = $this->buildCacheKey($ticketId, $articleId, $canonicalContentId);
        $store = Cache::store(config('znuny.inline_image_cache_store', 'redis'));

        $cached = $store->get($cacheKey);

        if (is_array($cached)) {
            $normalizedCached = $this->normalizeCachePayload($cached, $canonicalContentId);
            if ($normalizedCached !== null) {
                return $normalizedCached;
            }
        }

        $result = $this->client->getInlineAttachment($ticketId, $articleId, $canonicalContentId);

        if (empty($result['found']) || $result['found'] !== true) {
            return null; // Local MISS
        }

        return $this->validateAndCache($result, $canonicalContentId, $cacheKey, $store);
    }

    private function normalizeCachePayload(array $payload, string $requestedContentId): ?array
    {
        if (! isset($payload['content_type']) || ! is_string($payload['content_type']) ||
            ! isset($payload['content_id']) || ! is_string($payload['content_id']) ||
            ! isset($payload['content']) || ! is_string($payload['content'])) {
            return null;
        }

        try {
            $cachedContentId = ZnunyInlineImageContentId::normalize($payload['content_id']);
        } catch (\Throwable $e) {
            return null;
        }

        if ($cachedContentId !== $requestedContentId) {
            return null;
        }

        $normalizedMime = $this->normalizeMimeType($payload['content_type']);
        if (! in_array($normalizedMime, self::ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        if ($payload['content'] === '') {
            return null;
        }

        if (strlen($payload['content']) > self::MAX_DECODED_SIZE) {
            return null;
        }

        return [
            'content_type' => $normalizedMime,
            'content_id' => $cachedContentId,
            'content' => $payload['content'],
        ];
    }

    private function normalizeMimeType(string $contentType): string
    {
        $rawContentType = strtolower(trim(explode(';', $contentType)[0]));
        if ($rawContentType === 'image/jpg') {
            return 'image/jpeg';
        }

        return $rawContentType;
    }

    private function validateAndCache(array $result, string $requestedContentId, string $cacheKey, Repository $store): ?array
    {
        $returnedContentId = ZnunyInlineImageContentId::normalize($result['content_id']);
        if ($returnedContentId !== $requestedContentId) {
            return null; // Mismatch in canonical ContentID
        }

        $normalizedMime = $this->normalizeMimeType($result['content_type']);
        if (! in_array($normalizedMime, self::ALLOWED_MIME_TYPES, true)) {
            return null; // Rejected MIME
        }

        $decodedBinary = base64_decode($result['content_base64'], true);
        if ($decodedBinary === false || $decodedBinary === '') {
            return null; // Base64 decode failed or empty
        }

        $actualSize = strlen($decodedBinary);
        if ($actualSize > self::MAX_DECODED_SIZE) {
            return null; // Exceeds 25 MiB limit
        }

        if (isset($result['filesize_raw']) && $result['filesize_raw'] !== $actualSize) {
            return null; // Filesize mismatch
        }

        $cachePayload = [
            'content_type' => $normalizedMime,
            'content_id' => $returnedContentId,
            'content' => $decodedBinary,
        ];

        $store->put($cacheKey, $cachePayload, $this->getTtl());

        return $cachePayload;
    }

    private function buildCacheKey(int|string $ticketId, int|string $articleId, string $canonicalContentId): string
    {
        $identity = "{$ticketId}|{$articleId}|{$canonicalContentId}";

        return 'znuny:inline-image:v1:'.hash('sha256', $identity);
    }

    private function getTtl(): int
    {
        return 3600; // 1 hour for Stage 3
    }
}
