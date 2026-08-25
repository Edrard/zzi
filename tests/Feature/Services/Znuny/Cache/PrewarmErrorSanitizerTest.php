<?php

namespace Tests\Feature\Services\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmErrorSanitizer;
use Tests\TestCase;

class PrewarmErrorSanitizerTest extends TestCase
{
    public function test_sanitizes_stack_trace()
    {
        $sanitizer = new PrewarmErrorSanitizer();
        $this->assertEquals(
            'Error occurred',
            $sanitizer->sanitize("Error occurred\nStack trace:\n1. index.php")
        );
    }

    public function test_redacts_bearer_token()
    {
        $sanitizer = new PrewarmErrorSanitizer();
        $this->assertEquals(
            'Token: Bearer ***',
            $sanitizer->sanitize('Token: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...')
        );
    }

    public function test_redacts_quoted_secrets()
    {
        $sanitizer = new PrewarmErrorSanitizer();
        $this->assertEquals(
            '{"password": "***"}',
            $sanitizer->sanitize('{"password": "secret-password"}')
        );
    }

    public function test_redacts_unquoted_secrets()
    {
        $sanitizer = new PrewarmErrorSanitizer();
        $this->assertEquals(
            'api_key=***',
            $sanitizer->sanitize('api_key=12345abcdef')
        );
    }

    public function test_caps_at_500_characters()
    {
        $sanitizer = new PrewarmErrorSanitizer();
        $longText = str_repeat('A', 600);
        $result = $sanitizer->sanitize($longText);
        $this->assertEquals(500, strlen($result));
        $this->assertEquals(str_repeat('A', 500), $result);
    }
}
