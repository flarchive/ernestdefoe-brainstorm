<?php

namespace ErnestDefoe\Brainstorm;

/**
 * Marks one answer. Pure: no queries, no state, so every path that scores —
 * a submission, the clock running out, the tests — marks the same way.
 */
class Scorer
{
    /** The share of the question's points the answer earns, 0 to 1. */
    public static function fraction(Question $question, mixed $answer, bool $partialCredit): float
    {
        $correct = $question->correctIds();

        switch ($question->type) {
            case 'single':
            case 'boolean':
                return is_string($answer) && in_array($answer, $correct, true) ? 1.0 : 0.0;

            case 'multiple':
                if (! is_array($answer) || ! $correct) {
                    return 0.0;
                }
                $chosen = array_values(array_unique(array_filter($answer, 'is_string')));
                $hits = count(array_intersect($chosen, $correct));
                $misses = count($chosen) - $hits;

                if (! $partialCredit) {
                    return $hits === count($correct) && $misses === 0 ? 1.0 : 0.0;
                }

                // Each right pick earns its share, each wrong pick takes one
                // back, so ticking every box is never a strategy.
                return max(0.0, ($hits - $misses) / count($correct));

            case 'text':
                if (! is_string($answer)) {
                    return 0.0;
                }
                $given = self::normalise($answer);
                if ($given === '') {
                    return 0.0;
                }
                foreach ($question->options ?: [] as $option) {
                    if (self::normalise((string) ($option['text'] ?? '')) === $given) {
                        return 1.0;
                    }
                }

                return 0.0;
        }

        return 0.0;
    }

    /**
     * How a typed answer is compared: case, accents, spacing and the
     * punctuation around it don't count. "  Café! " matches "cafe".
     */
    public static function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                // Drop the accents the decomposition split off Latin letters
                // only: in other scripts a mark can be what tells two words apart.
                $text = (string) preg_replace('/(?<=\p{Latin})\p{Mn}+/u', '', $decomposed);
            }
        }

        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B.,!?;:'\"()[]");
    }
}
