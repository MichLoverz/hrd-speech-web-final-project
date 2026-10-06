<?php

namespace App\Support;

/**
 * The 1-5 scale, defined once. The manual scoring form and the LLM prompt both
 * read it from here, so the human baseline and the AI draft are scored against
 * the same anchors; otherwise the agreement metric (ADR-004) would compare two
 * different rulers.
 */
final class ScoreScale
{
    public const ANCHORS = [
        1 => 'Sangat kurang — tidak menjawab, atau menunjukkan pemahaman yang keliru.',
        2 => 'Kurang — menyentuh topik tetapi umum atau dangkal, tanpa contoh konkret.',
        3 => 'Cukup — menjawab dengan benar dan ada contoh, tetapi peran, langkah, atau hasilnya kurang jelas.',
        4 => 'Baik — contoh konkret dengan langkah dan alasan yang jelas.',
        5 => 'Sangat baik — contoh konkret, langkah dan alasan jelas, hasil terukur, dan menyebut pertimbangan atau pelajaran.',
    ];

    /** Short label for radio buttons and badges. */
    public static function label(int $score): string
    {
        return explode(' — ', self::ANCHORS[$score])[0];
    }
}
