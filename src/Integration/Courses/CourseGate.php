<?php

namespace ErnestDefoe\Brainstorm\Integration\Courses;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Course-only quizzes: which of them a member may see, and which courses an
 * author may limit a quiz to. Syllabus's gate is bound only while Syllabus
 * is enabled; otherwise {@see NoCourses} keeps every course-only quiz from
 * everyone but its editors (it fails closed).
 */
interface CourseGate
{
    public function available(): bool;

    /**
     * Keep, of a quiz query, the quizzes open to everyone and the course-only
     * ones the actor may see. A quiz's own editors are let in by the caller.
     *
     * @param Builder<\ErnestDefoe\Brainstorm\Quiz> $query
     */
    public function scope(Builder $query, User $actor, string $table): void;

    /**
     * The courses this author may limit a quiz to.
     *
     * @return list<array{id: int, title: string}>
     */
    public function attachable(User $actor): array;
}
