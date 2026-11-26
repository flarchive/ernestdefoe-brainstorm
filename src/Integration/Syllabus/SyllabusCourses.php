<?php

namespace ErnestDefoe\Brainstorm\Integration\Syllabus;

use ErnestDefoe\Brainstorm\Integration\Courses\CourseGate;
use Ernestdefoe\Syllabus\Courses;
use Ernestdefoe\Syllabus\Enrolments;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Course-only quizzes through Syllabus's public contract (1.5.0): a member
 * enrolled in the course sees its quizzes, and so do the instructors of a
 * published course and Syllabus's course managers. Bound by {@see SyllabusProvider}.
 */
class SyllabusCourses implements CourseGate
{
    /**
     * How many of an instructor's courses count, for what they see and what
     * they may pick alike: Syllabus's own cap (its published courses, by title).
     */
    public const TAUGHT = Courses::ATTACHABLE_LIMIT;

    public function __construct(private Enrolments $enrolments, private Courses $courses)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function scope(Builder $query, User $actor, string $table): void
    {
        if ($actor->hasPermission('syllabus.manage')) {
            return;
        }

        $taught = $actor->hasPermission('syllabus.create') ? array_column($this->courses->attachableBy($actor, self::TAUGHT), 'id') : [];

        $query->where(fn (Builder $q) => $q
            ->whereNull("$table.course_id")
            ->orWhereIn("$table.course_id", $this->enrolments->courseIdsFor($actor))
            ->when($taught, fn (Builder $q) => $q->orWhereIn("$table.course_id", $taught)));
    }

    public function attachable(User $actor): array
    {
        return $this->courses->attachableBy($actor, self::TAUGHT);
    }
}
