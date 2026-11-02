<?php

namespace ErnestDefoe\Brainstorm\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/quizzes/{id} — questions, attempts and bests go with it. */
class DeleteQuizController implements RequestHandlerInterface
{
    use Concerns;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $this->editableQuiz($request, $actor)->delete();

        return new EmptyResponse(204);
    }
}
