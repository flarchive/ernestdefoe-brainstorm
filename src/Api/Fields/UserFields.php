<?php

namespace ErnestDefoe\Brainstorm\Api\Fields;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\OptOut;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * The member's own "keep me off quiz leaderboards" choice, on their own user
 * only: read and written by them, invisible to everyone else. So it is looked
 * up for one user per request at most (the actor), never per user in a list.
 */
class UserFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        $own = fn (User $user, Context $context) => $context->getActor()->id === $user->id;

        return [
            Schema\Boolean::make('quizPrivate')
                ->visible($own)
                ->writable($own)
                ->get(fn (User $user) => OptOut::query()->whereKey($user->id)->exists())
                ->save(function (User $user, bool $private) {
                    if ($private) {
                        OptOut::query()->insertOrIgnore(['user_id' => $user->id, 'created_at' => Carbon::now()]);
                    } else {
                        OptOut::query()->whereKey($user->id)->delete();
                    }
                }),
        ];
    }
}
