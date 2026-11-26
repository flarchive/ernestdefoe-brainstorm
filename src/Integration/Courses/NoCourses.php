<?php

namespace ErnestDefoe\Brainstorm\Integration\Courses;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Syllabus isn't enabled: no courses to pick, and course-only quizzes stay hidden. */
class NoCourses implements CourseGate
{
    public function available(): bool
    {
        return false;
    }

    public function scope(Builder $query, User $actor, string $table): void
    {
        $query->whereNull("$table.course_id");
    }

    public function attachable(User $actor): array
    {
        return [];
    }
}
