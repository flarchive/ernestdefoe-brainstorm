<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * The first time a member completed an assessment.
 *
 * @property int $assessment_id
 * @property int $user_id
 * @property float $percent
 * @property Carbon $completed_at
 */
class Completion extends AbstractModel
{
    protected $table = 'quiz_assessment_results';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $casts = [
        'assessment_id' => 'integer',
        'user_id' => 'integer',
        'percent' => 'float',
        'completed_at' => 'datetime',
    ];
}
