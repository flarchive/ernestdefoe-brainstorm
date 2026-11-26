<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quiz in an assessment. Keyed by (assessment_id, quiz_id), so written
 * through queries on that key.
 *
 * @property int $assessment_id
 * @property int $quiz_id
 * @property int $weight
 * @property bool $required
 * @property int $position
 * @property Quiz $quiz
 */
class Item extends AbstractModel
{
    protected $table = 'quiz_assessment_items';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $casts = [
        'assessment_id' => 'integer',
        'quiz_id' => 'integer',
        'weight' => 'integer',
        'required' => 'boolean',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
