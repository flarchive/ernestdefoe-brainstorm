<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The first time a member completed an assessment, so AssessmentCompleted
 * fires once per member and assessment, ever.
 */
return Migration::createTable('quiz_assessment_results', function (Blueprint $table) {
    $table->unsignedInteger('assessment_id');
    $table->unsignedInteger('user_id');
    $table->decimal('percent', 5, 2);
    $table->dateTime('completed_at');

    $table->primary(['assessment_id', 'user_id']);
    $table->foreign('assessment_id')->references('id')->on('quiz_assessments')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index('user_id');
});
