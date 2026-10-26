<?php

namespace ErnestDefoe\Brainstorm\Api;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use ErnestDefoe\Brainstorm\Saver;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** POST /api/quizzes (create) and PATCH /api/quizzes/{id} (update). */
class SaveQuizController implements RequestHandlerInterface
{
    use Concerns;

    /** New quizzes one member may start in an hour. */
    public const HOURLY = 20;

    public function __construct(private Saver $saver, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $data = (array) Arr::get((array) $request->getParsedBody(), 'data', []);

        if ($request->getMethod() === 'POST') {
            if (! Access::canCreate($actor)) {
                throw new PermissionDeniedException();
            }

            if (! $actor->hasPermission('brainstorm.manage')
                && Quiz::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subHour())->count() >= self::HOURLY) {
                throw new ValidationException(['title' => $this->translator->trans('ernestdefoe-brainstorm.api.slow_down')]);
            }

            $quiz = null;
        } else {
            $quiz = $this->editableQuiz($request, $actor);
        }

        $quiz = $this->saver->save($quiz, $data, $actor);
        $quiz->load('user');

        $out = $this->presenter->quiz($quiz, $actor);
        $out['edit'] = $this->presenter->editable($quiz);

        return new JsonResponse(['data' => $out], $request->getMethod() === 'POST' ? 201 : 200);
    }
}
