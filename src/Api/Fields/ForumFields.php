<?php

namespace ErnestDefoe\Brainstorm\Api\Fields;

use ErnestDefoe\Brainstorm\Access;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** What the forum's frontend needs on every page. Both are permissions the actor's groups were already loaded for: no query. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canViewQuizzes')
                ->get(fn (object $forum, Context $context) => $context->getActor()->hasPermission('brainstorm.view')),
            Schema\Boolean::make('canCreateQuizzes')
                ->get(fn (object $forum, Context $context) => Access::canCreate($context->getActor())),
        ];
    }
}
