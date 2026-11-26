<?php

namespace ErnestDefoe\Brainstorm\Access;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Assessment\Assessment;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Editing and deleting an assessment: its author while they may write quizzes, or a manager. */
class AssessmentPolicy extends AbstractPolicy
{
    public function update(User $actor, Assessment $assessment): string
    {
        return Access::canEditAssessment($actor, $assessment) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Assessment $assessment): string
    {
        return $this->update($actor, $assessment);
    }
}
