<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A quiz only members enrolled in this Syllabus course may see and take.
 * No foreign key: Syllabus is optional, and a quiz whose course is gone stays
 * hidden from everyone but its editors and course managers.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('course_id')->nullable();
            $table->index('course_id');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropColumn('course_id');
        });
    },
];
