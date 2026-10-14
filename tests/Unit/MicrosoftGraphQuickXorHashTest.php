<?php

namespace Tests\Unit;

use App\Services\MicrosoftGraphQuickXorHash;
use PHPUnit\Framework\TestCase;

class MicrosoftGraphQuickXorHashTest extends TestCase
{
    public function test_it_calculates_the_base64_quickxor_hash_for_known_inputs(): void
    {
        $hashes = new MicrosoftGraphQuickXorHash;

        $this->assertSame('AAAAAAAAAAAAAAAAAAAAAAAAAAA=', $hashes->hash(''));
        $this->assertSame('YQAAAAAAAAAAAAAAAQAAAAAAAAA=', $hashes->hash('a'));
        $this->assertSame('UihCEIjQhCK4oA1kCQAAAAAAAAA=', $hashes->hash('README.md'));
    }
}
