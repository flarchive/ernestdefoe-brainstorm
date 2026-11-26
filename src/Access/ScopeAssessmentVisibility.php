<?php

namespace ErnestDefoe\Brainstorm\Access;

/** The same rule as quizzes: published ones for anyone who may view quizzes, plus the drafts this actor may edit. */
class ScopeAssessmentVisibility extends ScopePublishedOrOwn
{
}
