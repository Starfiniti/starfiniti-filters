<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

use InvalidArgumentException;

final class PositionCodec
{
    private const VERSION = 1;

    /** @param list<int> $positions */
    public static function encode(array $positions): string
    {
        if (count($positions) > 1024) {
            throw new InvalidArgumentException('Position list exceeds the codec limit.');
        }
        $result = chr(self::VERSION);
        $previous = -1;
        foreach ($positions as $position) {
            if ($position < 0 || $position <= $previous || $position > 1_000_000) {
                throw new InvalidArgumentException('Positions must be bounded and strictly increasing.');
            }
            $delta = $position - $previous;
            do {
                $byte = $delta & 0x7f;
                $delta >>= 7;
                $result .= chr($byte | ($delta > 0 ? 0x80 : 0));
            } while ($delta > 0);
            $previous = $position;
        }
        if (strlen($result) > 2048) {
            throw new InvalidArgumentException('Encoded positions exceed the storage limit.');
        }
        return $result;
    }

    /** @return list<int> */
    public static function decode(string $blob): array
    {
        if ($blob === '' || ord($blob[0]) !== self::VERSION || strlen($blob) > 2048) {
            throw new InvalidArgumentException('Position blob version or size is invalid.');
        }
        $positions = [];
        $previous = -1;
        $value = 0;
        $shift = 0;
        for ($index = 1, $length = strlen($blob); $index < $length; ++$index) {
            $byte = ord($blob[$index]);
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) !== 0) {
                $shift += 7;
                if ($shift > 28) {
                    throw new InvalidArgumentException('Position blob contains an invalid varint.');
                }
                continue;
            }
            $position = $previous + $value;
            if ($position <= $previous || $position > 1_000_000 || count($positions) >= 1024) {
                throw new InvalidArgumentException('Decoded positions violate codec bounds.');
            }
            $positions[] = $position;
            $previous = $position;
            $value = 0;
            $shift = 0;
        }
        if ($shift !== 0) {
            throw new InvalidArgumentException('Position blob ends with an incomplete varint.');
        }
        return $positions;
    }
}
