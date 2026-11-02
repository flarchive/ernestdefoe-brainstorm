<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🚨 `options` carries the answer key (`correct`). Never serialise a Question
 * as a whole; the presenter builds what a player sees field by field.
 *
 * @property int $id
 * @property int $quiz_id
 * @property int $position
 * @property string $type
 * @property string $body
 * @property string|null $image_url
 * @property list<array{id: string, text: string, correct: bool}> $options
 * @property string|null $explanation
 * @property int $points
 * @property Quiz $quiz
 */
class Question extends AbstractModel
{
    public const TYPES = ['single', 'multiple', 'boolean', 'text'];

    protected $table = 'quiz_questions';

    public $timestamps = false;

    protected $casts = [
        'quiz_id' => 'integer',
        'position' => 'integer',
        'options' => 'array',
        'points' => 'integer',
    ];

    protected $hidden = ['options'];

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return list<string> ids of the options marked correct */
    public function correctIds(): array
    {
        $ids = [];
        foreach ($this->options ?: [] as $option) {
            if (! empty($option['correct'])) {
                $ids[] = (string) $option['id'];
            }
        }

        return $ids;
    }
}
