<?php

namespace App\Library;

/**
 * The ReplayGain tags of a file: how many decibels to apply so that tracks (or whole albums) play equally loud, and the
 * peak sample (1.0 = full scale), so that a positive gain doesn't clip. Any of them may be missing.
 */
final readonly class ReplayGain
{
    /** Gains outside this range (in dB) are taken for broken tags. */
    public const float MAX_GAIN_DB = 60.0;

    /** Peaks above this are taken for broken tags (they can exceed 1.0 a little, after lossy encoding). */
    public const float MAX_PEAK = 10.0;

    public function __construct(
        public ?float $trackGain = null,
        public ?float $trackPeak = null,
        public ?float $albumGain = null,
        public ?float $albumPeak = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->trackGain && null === $this->trackPeak && null === $this->albumGain && null === $this->albumPeak;
    }

    /**
     * A gain in dB from a tag: a number, or text like "-6.48 dB".
     */
    public static function gain(mixed $value): ?float
    {
        $gain = self::number($value);

        return null !== $gain && abs($gain) <= self::MAX_GAIN_DB ? $gain : null;
    }

    /**
     * A peak from a tag: a positive number, like "0.988159".
     */
    public static function peak(mixed $value): ?float
    {
        $peak = self::number($value);

        return null !== $peak && $peak > 0 && $peak <= self::MAX_PEAK ? $peak : null;
    }

    private static function number(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return \is_string($value) && 1 === preg_match('/^\s*([+-]?\d+(?:[.,]\d+)?)/', $value, $matches) ? (float) str_replace(',', '.', $matches[1]) : null;
    }
}
