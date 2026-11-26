<?php

namespace ErnestDefoe\Brainstorm\Access;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Editing a quiz, deleting it and seeing its results: its author while they may write quizzes, or a manager. */
class QuizPolicy extends AbstractPolicy
{
    public function update(User $actor, Quiz $quiz): string
    {
        return Access::canEdit($actor, $quiz) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Quiz $quiz): string
    {
        return $this->update($actor, $quiz);
    }
}
