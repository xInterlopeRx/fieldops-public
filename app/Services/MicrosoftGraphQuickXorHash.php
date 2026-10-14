<?php

namespace App\Services;

class MicrosoftGraphQuickXorHash
{
    private const HASH_SIZE_BYTES = 20;

    private const HASH_SIZE_BITS = self::HASH_SIZE_BYTES * 8;

    private const SHIFT_PER_BYTE = 11;

    /** Return Microsoft's base64-encoded QuickXorHash for a file's exact bytes. */
    public function hash(string $content): string
    {
        $hash = array_fill(0, self::HASH_SIZE_BYTES, 0);
        $length = strlen($content);

        for ($index = 0; $index < $length; $index++) {
            $bitOffset = ($index * self::SHIFT_PER_BYTE) % self::HASH_SIZE_BITS;
            $byteOffset = intdiv($bitOffset, 8);
            $shift = $bitOffset % 8;
            $shiftedByte = ord($content[$index]) << $shift;

            $hash[$byteOffset] ^= $shiftedByte & 0xFF;
            $hash[($byteOffset + 1) % self::HASH_SIZE_BYTES] ^= $shiftedByte >> 8;
        }

        // QuickXorHash incorporates the file length in the last eight bytes.
        for ($index = 0; $index < 8; $index++) {
            $hash[12 + $index] ^= ($length >> ($index * 8)) & 0xFF;
        }

        return base64_encode(pack('C*', ...$hash));
    }
}
