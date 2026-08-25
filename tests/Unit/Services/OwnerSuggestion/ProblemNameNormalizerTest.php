<?php

namespace Tests\Unit\Services\OwnerSuggestion;

use App\Services\OwnerSuggestion\ProblemNameNormalizer;
use PHPUnit\Framework\TestCase;

class ProblemNameNormalizerTest extends TestCase
{
    private ProblemNameNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ProblemNameNormalizer;
    }

    public function test_null_and_empty_return_empty_string()
    {
        $this->assertSame('', $this->normalizer->normalize(null));
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize('   '));
    }

    public function test_lowercases_and_trims()
    {
        $this->assertSame('some error here', $this->normalizer->normalize('  Some Error HERE  '));
    }

    public function test_normalizes_repeated_spaces()
    {
        $this->assertSame('word1 word2 word3', $this->normalizer->normalize('word1    word2  word3'));
    }

    public function test_replaces_percentages()
    {
        $this->assertSame('disk space <percent>', $this->normalizer->normalize('disk space 20%'));
        $this->assertSame('disk space <percent>', $this->normalizer->normalize('disk space 20.5%'));
        $this->assertSame('disk space <percent>', $this->normalizer->normalize('disk space 20 %'));
    }

    public function test_replaces_ipv4()
    {
        $this->assertSame('host <ip> is down', $this->normalizer->normalize('host 192.0.2.10 is down'));
    }

    public function test_replaces_mac()
    {
        $this->assertSame('device <mac> failed', $this->normalizer->normalize('device 00:1A:2B:3C:4D:5E failed'));
    }

    public function test_replaces_uuid()
    {
        $this->assertSame('volume <id> missing', $this->normalizer->normalize('volume 123e4567-e89b-12d3-a456-426614174000 missing'));
    }

    public function test_replaces_hex_hash()
    {
        $this->assertSame('commit <id> failed', $this->normalizer->normalize('commit a1b2c3d4e5f6a7b8a1b2c3d4e5f6a7b8 failed'));
    }

    public function test_replaces_paths()
    {
        $this->assertSame('file <path> not found', $this->normalizer->normalize('file /var/log/syslog not found'));
        $this->assertSame('file <path> not found', $this->normalizer->normalize('file C:\Windows\Temp\error.log not found'));
    }

    public function test_replaces_standalone_numbers()
    {
        $this->assertSame('service <num> stopped', $this->normalizer->normalize('service 123 stopped'));
        $this->assertSame('service <num> stopped', $this->normalizer->normalize('service 123.45 stopped'));
    }

    public function test_disk_space_examples()
    {
        $result1 = $this->normalizer->normalize('Free disk space is less than 20% on volume /var');
        $result2 = $this->normalizer->normalize('Free disk space is less than 10% on volume /home');

        $this->assertSame('free disk space is less than <percent> on volume <path>', $result1);
        $this->assertSame('free disk space is less than <percent> on volume <path>', $result2);
    }

    public function test_cpu_vs_memory_remain_different()
    {
        $cpu = $this->normalizer->normalize('CPU utilization high');
        $mem = $this->normalizer->normalize('Memory utilization high');

        $this->assertNotSame($cpu, $mem);
        $this->assertSame('cpu utilization high', $cpu);
        $this->assertSame('memory utilization high', $mem);
    }

    public function test_icmp_ping_unavailable_remains_meaningful()
    {
        $this->assertSame('host <ip> is unavailable by icmp ping', $this->normalizer->normalize('Host 198.51.100.5 is unavailable by ICMP ping'));
    }

    public function test_removes_meaningless_punctuation()
    {
        $this->assertSame('interface eth0 link down', $this->normalizer->normalize('Interface eth0: Link down'));
    }
}
