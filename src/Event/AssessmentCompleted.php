<?php

namespace ErnestDefoe\Brainstorm\Event;

use ErnestDefoe\Brainstorm\Assessment\Assessment;
use Flarum\User\User;

/**
 * A member completed an assessment: every required quiz finished and the
 * average reached its pass mark. Once per member and assessment, ever.
 */
class AssessmentCompleted
{
    public function __construct(public Assessment $assessment, public User $user, public float $percent)
    {
    }
}
