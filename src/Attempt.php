<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quiz_id
 * @property int $user_id
 * @property Carbon $started_at
 * @property Carbon|null $deadline_at
 * @property Carbon|null $submitted_at
 * @property list<array{q: int, o: list<string>}> $layout
 * @property array<string, mixed>|null $answers
 * @property float|null $score
 * @property float|null $max_score
 * @property float|null $percent
 * @property bool|null $passed
 * @property int|null $duration
 * @property bool $expired
 * @property bool $practice
 * @property array<string, float>|null $marks
 * @property Quiz $quiz
 * @property User $user
 */
class Attempt extends AbstractModel
{
    /** Seconds after the deadline a submission is still taken, for the trip to the server. */
    public const GRACE = 10;

    protected $table = 'quiz_attempts';

    public $timestamps = false;

    protected $casts = [
        'quiz_id' => 'integer',
        'user_id' => 'integer',
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'layout' => 'array',
        'answers' => 'array',
        'score' => 'float',
        'max_score' => 'float',
        'percent' => 'float',
        'passed' => 'boolean',
        'duration' => 'integer',
        'expired' => 'boolean',
        'practice' => 'boolean',
        'marks' => 'array',
    ];

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return $this->submitted_at !== null;
    }

    /** The clock ran out, grace and all: nothing more is taken from the player. */
    public function isOverdue(): bool
    {
        return ! $this->isFinished()
            && $this->deadline_at !== null
            && $this->deadline_at->copy()->addSeconds(self::GRACE)->lt(Carbon::now());
    }

    /** @return list<int> */
    public function questionIds(): array
    {
        return array_map(fn ($slot) => (int) $slot['q'], $this->layout ?: []);
    }
}
