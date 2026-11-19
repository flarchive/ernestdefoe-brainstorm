<?php

namespace ErnestDefoe\Brainstorm\Access;

use ErnestDefoe\Brainstorm\Attempt;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Saving and handing in an attempt: only the member taking it, while they may take quizzes. */
class AttemptPolicy extends AbstractPolicy
{
    public function update(User $actor, Attempt $attempt): string
    {
        return $attempt->user_id === $actor->id && $actor->hasPermission('brainstorm.take') ? $this->allow() : $this->deny();
    }
}
