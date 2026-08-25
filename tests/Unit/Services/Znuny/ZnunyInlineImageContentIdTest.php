<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyInlineImageContentId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ZnunyInlineImageContentIdTest extends TestCase
{
    #[DataProvider('provideValidContentIds')]
    public function test_normalizes_valid_content_ids(string $input, string $expected): void
    {
        $normalized = ZnunyInlineImageContentId::normalize($input);
        $this->assertEquals($expected, $normalized);
    }

    public static function provideValidContentIds(): array
    {
        return [
            'bare' => ['image1@domain.com', 'image1@domain.com'],
            'cid prefix' => ['cid:image1@domain.com', 'image1@domain.com'],
            'cid prefix case insensitive' => ['CID:image1@domain.com', 'image1@domain.com'],
            'wrapper' => ['<image1@domain.com>', 'image1@domain.com'],
            'cid and wrapper' => ['cid:<image1@domain.com>', 'image1@domain.com'],
            'whitespace normalization' => ['  cid: <image1@domain.com>  ', 'image1@domain.com'],
            'preserves case' => ['cid:Image1@Domain.COM', 'Image1@Domain.COM'],
            'exactly 512 bytes' => [str_repeat('a', 512), str_repeat('a', 512)],
        ];
    }

    #[DataProvider('provideInvalidContentIds')]
    public function test_rejects_invalid_content_ids(string $input, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        ZnunyInlineImageContentId::normalize($input);
    }

    public static function provideInvalidContentIds(): array
    {
        return [
            'empty' => ['', 'empty after normalization'],
            'empty after trim' => ['  ', 'empty after normalization'],
            'empty cid' => ['cid:', 'empty after normalization'],
            'empty wrapper' => ['<>', 'empty after normalization'],
            'too long' => [str_repeat('a', 513), 'exceeds maximum canonical length'],
            'control chars' => ["image\x00@domain.com", 'invalid control characters'],
            'newlines' => ["image\n@domain.com", 'invalid control characters'],
        ];
    }

    public function test_encode_and_decode_round_trip(): void
    {
        $cid = 'image1@domain.com';
        $token = ZnunyInlineImageContentId::encodeToken($cid);

        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('=', $token);

        $decoded = ZnunyInlineImageContentId::decodeToken($token);
        $this->assertEquals($cid, $decoded);
    }

    public function test_encode_and_decode_round_trip_with_base64url_substitutions(): void
    {
        // "???" encodes to Pz8/ in normal base64, so it exercises the / -> _ substitution
        // ">" encodes to Pg== in normal base64, so it exercises = -> (empty)
        $cid = '???>>';
        $token = ZnunyInlineImageContentId::encodeToken($cid);

        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('=', $token);

        $decoded = ZnunyInlineImageContentId::decodeToken($token);
        $this->assertEquals($cid, $decoded);
    }

    public function test_rejects_empty_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ContentID cannot be empty after normalization');

        ZnunyInlineImageContentId::decodeToken('');
    }

    public function test_rejects_token_yielding_over_maximum_canonical_bound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ContentID exceeds maximum canonical length.');

        $oversizedCanonical = str_repeat('a', 513);
        $token = rtrim(strtr(base64_encode($oversizedCanonical), '+/', '-_'), '=');
        ZnunyInlineImageContentId::decodeToken($token);
    }

    public function test_rejects_token_over_expected_input_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Token exceeds maximum expected length.');

        ZnunyInlineImageContentId::decodeToken(str_repeat('a', 1025));
    }

    public function test_rejects_invalid_token_alphabet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        ZnunyInlineImageContentId::decodeToken('invalid+token/');
    }

    public function test_rejects_strict_malformed_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strictly decode token');

        // '-' is valid in base64url, but a single character cannot be valid base64
        ZnunyInlineImageContentId::decodeToken('-');
    }

    public function test_rejects_non_canonical_alternate_token(): void
    {
        // 'cid:image1@domain.com'
        $tokenWithCid = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode('cid:image1@domain.com'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not canonical');

        // This will decode to 'cid:image1@domain.com', then normalize to 'image1@domain.com'
        // which encodes back to a DIFFERENT token.
        ZnunyInlineImageContentId::decodeToken($tokenWithCid);
    }
}
