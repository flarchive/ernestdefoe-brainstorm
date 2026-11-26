<?php

namespace ErnestDefoe\Brainstorm\Access;

use ErnestDefoe\Brainstorm\Integration\Courses\CourseGate;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Published quizzes for anyone who may view quizzes, plus the drafts this
 * actor may edit. A published course-only quiz also needs the course gate's
 * yes (an enrolled member, the instructors of its published course, course managers); its
 * own editors always see it.
 */
class ScopeQuizVisibility extends ScopePublishedOrOwn
{
    public function __construct(private CourseGate $courses)
    {
    }

    /** @param Builder<\ErnestDefoe\Brainstorm\Quiz> $query */
    protected function published(Builder $query, User $actor, string $table): void
    {
        $this->courses->scope($query, $actor, $table);
    }
}
