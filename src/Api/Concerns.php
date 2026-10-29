<?php

namespace ErnestDefoe\Brainstorm\Api;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** The two lookups every controller starts with. */
trait Concerns
{
    /** The id in the route, or 0 (not found) for one too big for an id column. */
    protected function routeId(ServerRequestInterface $request, string $name = 'id'): int
    {
        $id = (string) Arr::get($request->getAttributes(), 'routeParameters.'.$name);

        return strlen($id) <= 10 && (int) $id <= 2147483647 ? (int) $id : 0;
    }

    /** A quiz the actor may see, or a 404 — the same answer whether it is missing or hidden. */
    protected function visibleQuiz(ServerRequestInterface $request, User $actor): Quiz
    {
        return Quiz::query()->visibleTo($actor)->with('user')->findOrFail($this->routeId($request));
    }

    protected function editableQuiz(ServerRequestInterface $request, User $actor): Quiz
    {
        $quiz = $this->visibleQuiz($request, $actor);

        if (! Access::canEdit($actor, $quiz)) {
            throw new PermissionDeniedException();
        }

        return $quiz;
    }
}
