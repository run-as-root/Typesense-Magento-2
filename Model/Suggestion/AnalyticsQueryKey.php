<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Suggestion;

/**
 * The query text and document id Typesense 29's popular-queries analytics store a search under
 * (Tokenizer::normalize_ascii_no_spaces, QueryAnalytics::serialize_as_docs). Seeded documents must use the same,
 * or the counts Typesense adds later would land in a duplicate document instead of the seeded one.
 */
class AnalyticsQueryKey
{
    // wyhash v5's default secret (_wyp) as signed 64-bit integers: the hex literals would overflow to float in PHP.
    private const SECRET = [
        -6884282663029611473,
        -1800455987208640293,
        -8161530843051276573,
        6384245875588680899,
        2129725606500045391,
        8264716193205171313,
    ];

    // Typesense reserves the maximum hash for use as a delimiter and stores max - 1 instead.
    private const RESERVED_HASH = -1;
    private const RESERVED_HASH_REPLACEMENT = '18446744073709551614';

    public function normalize(string $query): string
    {
        // strtolower is ASCII-only since PHP 8.2, just like Typesense's normalization.
        return strtolower(trim($query, " \t\n\r\v\f"));
    }

    /**
     * Typesense's StringUtils::hash_wy() (wyhash v5, seed 0) of the normalized query, as an unsigned decimal.
     */
    public function documentId(string $normalizedQuery): string
    {
        $length = strlen($normalizedQuery);
        $hash = $this->mum($this->hash($normalizedQuery, $length) ^ $length, self::SECRET[5]);

        return $hash === self::RESERVED_HASH ? self::RESERVED_HASH_REPLACEMENT : sprintf('%u', $hash);
    }

    private function hash(string $bytes, int $length): int
    {
        $seed = self::SECRET[4];
        $offset = 0;
        $remaining = $length;

        if ($remaining > 64) {
            $see1 = $seed;
            $see2 = $seed;
            $see3 = $seed;
            for (; $remaining > 64; $remaining -= 64, $offset += 64) {
                $seed = $this->pair($bytes, $offset, $offset + 8, 0, $seed);
                $see1 = $this->pair($bytes, $offset + 16, $offset + 24, 1, $see1);
                $see2 = $this->pair($bytes, $offset + 32, $offset + 40, 2, $see2);
                $see3 = $this->pair($bytes, $offset + 48, $offset + 56, 3, $see3);
            }
            $seed ^= $see1 ^ $see2 ^ $see3;
        }

        return $this->hashTail($bytes, $offset, $remaining, $seed);
    }

    private function hashTail(string $bytes, int $offset, int $length, int $seed): int
    {
        $end = $offset + $length;
        if ($length > 32) {
            return $this->pair($bytes, $offset, $offset + 8, 0, $seed)
                ^ $this->pair($bytes, $offset + 16, $offset + 24, 1, $seed)
                ^ $this->pair($bytes, $end - 32, $end - 24, 2, $seed)
                ^ $this->pair($bytes, $end - 16, $end - 8, 3, $seed);
        }
        if ($length > 16) {
            return $this->pair($bytes, $offset, $offset + 8, 0, $seed)
                ^ $this->pair($bytes, $end - 16, $end - 8, 1, $seed);
        }
        if ($length >= 8) {
            return $this->pair($bytes, $offset, $end - 8, 0, $seed);
        }

        if ($length >= 4) {
            return $this->mix($this->read4($bytes, $offset) ^ self::SECRET[0], $this->read4($bytes, $offset + $length - 4) ^ $seed);
        }

        return $this->mix(($length > 0 ? $this->read3($bytes, $offset, $length) : 0) ^ self::SECRET[0], $seed);
    }

    private function pair(string $bytes, int $first, int $second, int $secret, int $seed): int
    {
        return $this->mix($this->read8($bytes, $first) ^ self::SECRET[$secret], $this->read8($bytes, $second) ^ $seed);
    }

    private function mix(int $a, int $b): int
    {
        return $a ^ $b ^ $this->mum($a, $b);
    }

    /**
     * High and low half of the 128-bit product, xored. Computed on 16-bit limbs so no partial sum overflows PHP's
     * signed 64-bit integers.
     */
    private function mum(int $a, int $b): int
    {
        $x = [$a & 0xFFFF, ($a >> 16) & 0xFFFF, ($a >> 32) & 0xFFFF, ($a >> 48) & 0xFFFF];
        $y = [$b & 0xFFFF, ($b >> 16) & 0xFFFF, ($b >> 32) & 0xFFFF, ($b >> 48) & 0xFFFF];
        $limbs = array_fill(0, 8, 0);
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $limbs[$i + $j] += $x[$i] * $y[$j];
            }
        }

        $carry = 0;
        for ($k = 0; $k < 8; $k++) {
            $value = $limbs[$k] + $carry;
            $limbs[$k] = $value & 0xFFFF;
            $carry = $value >> 16;
        }

        $low = $limbs[0] | ($limbs[1] << 16) | ($limbs[2] << 32) | ($limbs[3] << 48);
        $high = $limbs[4] | ($limbs[5] << 16) | ($limbs[6] << 32) | ($limbs[7] << 48);

        return $high ^ $low;
    }

    private function read8(string $bytes, int $offset): int
    {
        return unpack('P', $bytes, $offset)[1];
    }

    private function read4(string $bytes, int $offset): int
    {
        return unpack('V', $bytes, $offset)[1];
    }

    private function read3(string $bytes, int $offset, int $length): int
    {
        return (ord($bytes[$offset]) << 16) | (ord($bytes[$offset + ($length >> 1)]) << 8) | ord($bytes[$offset + $length - 1]);
    }
}
