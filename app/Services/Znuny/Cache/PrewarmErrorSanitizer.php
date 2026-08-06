<?php

namespace App\Services\Znuny\Cache;

class PrewarmErrorSanitizer
{
    /**
     * Sanitize an error message by removing stack traces and redacting secrets.
     */
    public function sanitize(string $error): string
    {
        // Remove stack trace case-insensitively
        $stackTracePos = stripos($error, 'Stack trace:');
        if ($stackTracePos !== false) {
            $error = substr($error, 0, $stackTracePos);
        }

        // Redact secrets, keys, session IDs, Bearer tokens
        $keys = 'password|token|secret|api_key|apikey|access_token|refresh_token|client_secret|authorization|SessionID|session_id';

        // Bare Bearer token or Authorization: Bearer
        $error = preg_replace('/(Bearer\s+)([^\s&"\'\r\n]+)/i', '$1***', $error);

        // Redact quoted values (JSON, header, query with quotes)
        $error = preg_replace(
            '/(["\']?(?:' . $keys . ')["\']?\s*[=:]\s*)(["\'])(.*?)\2/i',
            '$1$2***$2',
            $error
        );

        // Redact unquoted values
        $error = preg_replace(
            '/(["\']?(?:' . $keys . ')["\']?\s*[=:]\s*)(?!["\']|Bearer\b)([^\s&,\r\n]+)/i',
            '$1***',
            $error
        );

        return substr(trim($error), 0, 500);
    }
}
