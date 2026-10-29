<?php

namespace ErnestDefoe\Brainstorm\Api;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Presenter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/quizzes/{id} — one quiz and the actor's standing at it.
 * `?edit=1` adds the editor's copy (answer key included) for those who may
 * edit it, and nobody else.
 */
class ShowQuizController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Attempts $attempts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $quiz = $this->visibleQuiz($request, $actor);

        if (! $actor->isGuest()) {
            // A timed attempt left running is marked before it is shown.
            $this->attempts->expireOverdue($quiz, $actor);
        }

        $data = $this->presenter->quiz($quiz, $actor);

        if (! empty($request->getQueryParams()['edit']) && Access::canEdit($actor, $quiz)) {
            $data['edit'] = $this->presenter->editable($quiz);
        }

        return new JsonResponse(['data' => $data]);
    }
}
