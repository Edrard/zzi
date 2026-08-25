<?php

namespace App\Services\Znuny;

use InvalidArgumentException;

class ZnunyInlineImageContentId
{
    private const MAX_LENGTH = 512;

    public static function normalize(string $contentId): string
    {
        $normalized = trim($contentId);

        if (stripos($normalized, 'cid:') === 0) {
            $normalized = substr($normalized, 4);
            $normalized = trim($normalized);
        }

        if (str_starts_with($normalized, '<') && str_ends_with($normalized, '>')) {
            $normalized = substr($normalized, 1, -1);
            $normalized = trim($normalized);
        }

        if ($normalized === '') {
            throw new InvalidArgumentException('ContentID cannot be empty after normalization.');
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('ContentID exceeds maximum canonical length.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $normalized)) {
            throw new InvalidArgumentException('ContentID contains invalid control characters.');
        }

        return $normalized;
    }

    public static function encodeToken(string $contentId): string
    {
        $canonical = self::normalize($contentId);
        $encoded = base64_encode($canonical);
        $encoded = str_replace(['+', '/', '='], ['-', '_', ''], $encoded);

        return $encoded;
    }

    public static function decodeToken(string $token): string
    {
        if (strlen($token) > (self::MAX_LENGTH * 2)) {
            throw new InvalidArgumentException('Token exceeds maximum expected length.');
        }

        if (preg_match('/[^a-zA-Z0-9\-_]/', $token)) {
            throw new InvalidArgumentException('Token contains invalid characters.');
        }

        $base64 = str_replace(['-', '_'], ['+', '/'], $token);

        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Failed to strictly decode token.');
        }

        $canonical = self::normalize($decoded);

        if (! hash_equals(self::encodeToken($canonical), $token)) {
            throw new InvalidArgumentException('Token is not canonical.');
        }

        return $canonical;
    }
}
