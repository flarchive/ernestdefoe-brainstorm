<?php

namespace ErnestDefoe\Brainstorm\Api;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempt;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Presenter;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /api/quizzes/{id}/attempts — resume the actor's open attempt or start
 * a new one, and hand back its paper.
 */
class StartAttemptController implements RequestHandlerInterface
{
    use Concerns;

    /** Attempts one member may start across all quizzes in ten minutes. */
    public const BURST = 30;

    public function __construct(private Attempts $attempts, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $quiz = $this->visibleQuiz($request, $actor);

        if (! Access::canTake($actor, $quiz)) {
            throw new PermissionDeniedException();
        }

        // Unlimited-attempt quizzes can't become a way to fill the table.
        if (Attempt::query()->where('user_id', $actor->id)->where('started_at', '>', Carbon::now()->subMinutes(10))->count() >= self::BURST
            && ! $this->attempts->open($quiz, $actor)) {
            throw new ValidationException(['attempt' => $this->translator->trans('ernestdefoe-brainstorm.api.slow_down')]);
        }

        $attempt = $this->attempts->start($quiz, $actor, Access::canEdit($actor, $quiz));

        return new JsonResponse(['data' => $this->presenter->paper($attempt)]);
    }
}
