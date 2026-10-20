<?php

namespace ErnestDefoe\Brainstorm\Api;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempt;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET   /api/quiz-attempts/{id}        — an open attempt's paper, or a marked one's result
 * PATCH /api/quiz-attempts/{id}        — save answers so far
 * POST  /api/quiz-attempts/{id}/submit — mark it
 *
 * Only the member who took it may see, save or submit it; people who may
 * edit the quiz may also look at a marked one.
 */
class AttemptController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Attempts $attempts, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $attempt = Attempt::query()->find($this->routeId($request));
        $quiz = $attempt ? Quiz::query()->visibleTo($actor)->find($attempt->quiz_id) : null;

        if (! $attempt || ! $quiz) {
            throw new ModelNotFoundException();
        }

        $attempt->setRelation('quiz', $quiz);
        $own = $attempt->user_id === $actor->id;
        $method = $request->getMethod();

        if (! $own && ($method !== 'GET' || ! Access::canEdit($actor, $quiz))) {
            // Somebody else's attempt is as missing as one that never was.
            throw new ModelNotFoundException();
        }

        if ($method !== 'GET' && ! $actor->hasPermission('brainstorm.take')) {
            throw new PermissionDeniedException();
        }

        $answers = Arr::get((array) $request->getParsedBody(), 'data.answers');

        if ($method === 'PATCH') {
            $this->attempts->save($attempt, $answers);

            // An autosave only needs to know it landed and how long is left.
            return new JsonResponse(['data' => [
                'id' => $attempt->id,
                'finished' => $attempt->isFinished(),
                'secondsLeft' => $attempt->deadline_at ? max(0, $attempt->deadline_at->getTimestamp() - Carbon::now()->getTimestamp()) : null,
            ]]);
        }

        if ($method === 'POST' || $attempt->isOverdue()) {
            $attempt->setRelation('user', $own ? $actor : User::query()->findOrFail($attempt->user_id));
            $attempt = $this->attempts->submit($attempt, $method === 'POST' ? $answers : null);
        }

        if (! $attempt->isFinished()) {
            return new JsonResponse(['data' => $own ? $this->presenter->paper($attempt) : ['id' => $attempt->id, 'finished' => false]]);
        }

        return new JsonResponse(['data' => $this->presenter->result($attempt, $actor)]);
    }
}
