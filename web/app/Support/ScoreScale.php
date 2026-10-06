<?php

namespace App\Support;

/**
 * The 0-10 scale in steps of 0.5, defined once. The manual scoring form and the
 * LLM prompt both read it from here, so the human baseline and the AI draft are
 * scored against the same reference points; otherwise the agreement metric
 * (ADR-004) would compare two different rulers.
 *
 * The anchors are reference points, not categories: there are no labels such as
 * "baik", and a rater may pick any step in between (6, 8.5). Steps of 0.5 rather
 * than free decimals because nobody can tell a 7.3 answer from a 7.4 one
 * consistently (ADR-010).
 */
final class ScoreScale
{
    public const MIN = 0.0;

    public const MAX = 10.0;

    public const STEP = 0.5;

    /** Keyed by the score as a string ("2.5"): PHP array keys cannot be floats. */
    public const ANCHORS = [
        '0' => 'Tidak menjawab, atau jawabannya keliru.',
        '2.5' => 'Menyentuh topik tetapi umum, tanpa contoh konkret.',
        '5' => 'Benar dan ada contoh, tetapi langkah atau hasilnya kurang jelas.',
        '7.5' => 'Contoh konkret dengan langkah dan alasan yang jelas.',
        '10' => 'Contoh konkret, langkah dan alasan jelas, hasil terukur, dan menyebut pertimbangan atau pelajaran.',
    ];

    /** @return list<float> 0, 0.5, ..., 10 */
    public static function values(): array
    {
        return array_map(fn ($i) => $i * self::STEP, range(0, (int) (self::MAX / self::STEP)));
    }

    /** Nearest valid step, or null when outside the scale or not a number. */
    public static function snap(mixed $value): ?float
    {
        if (! is_numeric($value) || $value < self::MIN || $value > self::MAX) {
            return null;
        }

        return round($value / self::STEP) * self::STEP;
    }

    /** Indonesian notation without trailing zeros: 7.5 -> "7,5", 8.0 -> "8", null -> "–". */
    public static function format(?float $value): string
    {
        if ($value === null) {
            return '–';
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }
}
