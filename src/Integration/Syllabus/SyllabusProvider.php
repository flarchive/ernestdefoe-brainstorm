<?php

namespace ErnestDefoe\Brainstorm\Integration\Syllabus;

use ErnestDefoe\Brainstorm\Integration\Courses\CourseGate;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered from extend.php only when ernestdefoe-syllabus is enabled. */
class SyllabusProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(CourseGate::class, SyllabusCourses::class);
    }
}
