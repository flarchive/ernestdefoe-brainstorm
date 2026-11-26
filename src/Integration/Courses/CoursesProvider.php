<?php

namespace ErnestDefoe\Brainstorm\Integration\Courses;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * "No courses" unless Syllabus's provider, registered from extend.php inside
 * Extend\Conditional, bound its gate; bindIf leaves that in place whichever
 * provider runs first.
 */
class CoursesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bindIf(CourseGate::class, NoCourses::class);
    }
}
