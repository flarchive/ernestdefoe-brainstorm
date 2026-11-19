<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A member's best finished attempt at a quiz: one row per member per quiz,
 * kept up to date on every counted submission. Leaderboards, "your best" on
 * a card, the badge metrics and Onramp read this instead of ranking attempts.
 *
 * Keyed by (quiz_id, user_id), so it is written through queries on that key.
 *
 * @property int $quiz_id
 * @property int $user_id
 * @property int $attempt_id
 * @property float $score
 * @property float $percent
 * @property int $duration
 * @property bool $passed
 * @property int $attempts
 * @property Carbon|null $first_passed_at
 * @property Carbon $achieved_at
 */
class Best extends AbstractModel
{
    protected $table = 'quiz_bests';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $casts = [
        'quiz_id' => 'integer',
        'user_id' => 'integer',
        'attempt_id' => 'integer',
        'score' => 'float',
        'percent' => 'float',
        'duration' => 'integer',
        'passed' => 'boolean',
        'attempts' => 'integer',
        'first_passed_at' => 'datetime',
        'achieved_at' => 'datetime',
    ];
}
