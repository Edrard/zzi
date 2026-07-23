<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyTicketCreationMarkerBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ZnunyTicketCreationMarkerBuilderTest extends TestCase
{
    private ZnunyTicketCreationMarkerBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ZnunyTicketCreationMarkerBuilder;
    }

    public function test_builds_exact_zabbix_marker(): void
    {
        $marker = $this->builder->buildZabbixMarker(184527);
        $this->assertEquals('[ZBX:184527]', $marker);
    }

    public function test_builds_exact_scheduled_marker(): void
    {
        $marker = $this->builder->buildScheduledMarker(3821);
        $this->assertEquals('[SHE:3821]', $marker);
    }

    public function test_supports_positive_numeric_string(): void
    {
        $marker = $this->builder->buildZabbixMarker('99999');
        $this->assertEquals('[ZBX:99999]', $marker);
    }

    public function test_normalizes_leading_zeros(): void
    {
        $marker = $this->builder->buildZabbixMarker('000123');
        $this->assertEquals('[ZBX:123]', $marker);
    }

    public function test_supports_very_large_positive_numeric_string_without_overflow(): void
    {
        $largeId = '99999999999999999999999999999999999999';
        $marker = $this->builder->buildZabbixMarker($largeId);
        $this->assertEquals('[ZBX:99999999999999999999999999999999999999]', $marker);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_rejects_invalid_id(mixed $invalidId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->buildZabbixMarker($invalidId);
    }

    public static function invalidIdProvider(): array
    {
        return [
            'null' => [null],
            'boolean true' => [true],
            'boolean false' => [false],
            'float' => [12.34],
            'integral float' => [12.0],
            'array' => [[]],
            'object' => [(object) []],
            'empty string' => [''],
            'whitespace-only string' => ['   '],
            'leading whitespace' => [' 123'],
            'trailing whitespace' => ['123 '],
            'signed string positive' => ['+123'],
            'signed string negative' => ['-123'],
            'decimal string' => ['12.34'],
            'scientific notation' => ['1e3'],
            'hexadecimal-like string' => ['0x1A'],
            'zero integer' => [0],
            'zero string' => ['0'],
            'zero-only string' => ['0000'],
            'alphanumeric' => ['123a'],
            'letters' => ['abc'],
        ];
    }

    public function test_blank_subject_rejection(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);

        $this->expectException(InvalidArgumentException::class);
        $this->builder->appendMarker('   ', $marker);
    }

    public function test_empty_marker_rejection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->hasMarker('Alert', '');
    }

    public function test_exact_marker_at_subject_end(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'Alert [ZBX:123]';

        $this->assertTrue($this->builder->hasMarker($subject, $marker));
    }

    public function test_trailing_subject_whitespace_is_ignored(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'Alert [ZBX:123]   ';

        $this->assertTrue($this->builder->hasMarker($subject, $marker));
    }

    public function test_marker_in_the_middle_is_rejected(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'Alert [ZBX:123] old';

        $this->assertFalse($this->builder->hasMarker($subject, $marker));
    }

    public function test_marker_embedded_in_other_text_is_rejected(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'prefix[ZBX:123]suffix';

        $this->assertFalse($this->builder->hasMarker($subject, $marker));
    }

    public function test_no_match_for_different_marker_id(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'Alert [ZBX:1234]';

        $this->assertFalse($this->builder->hasMarker($subject, $marker));
    }

    public function test_preserves_leading_whitespace_and_removes_trailing_whitespace(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = '  Alert: High CPU Usage   ';

        $result = $this->builder->appendMarker($subject, $marker);

        $this->assertEquals('  Alert: High CPU Usage [ZBX:123]', $result);
    }

    public function test_duplicate_marker_prevention(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject = 'Alert: High CPU Usage [ZBX:123]';

        $result = $this->builder->appendMarker($subject, $marker);

        $this->assertEquals('Alert: High CPU Usage [ZBX:123]', $result);
    }

    public function test_detects_and_normalizes_multiple_ascii_spaces_before_marker(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);
        $subject1 = 'Alert  [ZBX:123]';
        $subject2 = 'Alert     [ZBX:123]';

        $this->assertTrue($this->builder->hasMarker($subject1, $marker));
        $this->assertTrue($this->builder->hasMarker($subject2, $marker));

        $this->assertEquals('Alert [ZBX:123]', $this->builder->appendMarker($subject1, $marker));
        $this->assertEquals('Alert [ZBX:123]', $this->builder->appendMarker($subject2, $marker));
    }

    public function test_rejects_invalid_separators_and_marker_only_subject(): void
    {
        $marker = $this->builder->buildZabbixMarker(123);

        // no space before the marker is rejected
        $this->assertFalse($this->builder->hasMarker('Alert[ZBX:123]', $marker));

        // a tab before the marker is rejected
        $this->assertFalse($this->builder->hasMarker("Alert\t[ZBX:123]", $marker));

        // a marker-only subject
        $this->assertFalse($this->builder->hasMarker('[ZBX:123]', $marker));
    }
}
