<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Event\AssessmentCompleted;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

class AssessmentTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SpeaksJsonApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-brainstorm');
        $this->prepareDatabase([
            User::class => [$this->normalUser(), ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1]],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    private function json(string $method, string $path, ?int $actor = null, ?array $body = null): array
    {
        [$method, $path, $body] = self::toJsonApi($method, $path, $body);
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $request = $this->request($method, (string) strtok($path, '?'), $options);
        if (str_contains($path, '?')) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($actor ? $request : $request->withAttribute('bypassCsrfToken', true));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), self::flatten(json_decode($raw, true)), $raw];
    }

    /** A published one-question true/false quiz by the admin. */
    private function quiz(string $title, array $settings = []): int
    {
        [$status, $body, $raw] = $this->json('POST', '/api/quizzes', 1, ['data' => $settings + [
            'title' => $title,
            'status' => 'published',
            'passMark' => 50,
            'questions' => [
                ['type' => 'boolean', 'body' => 'Yes?', 'options' => [['id' => 'true', 'correct' => true]]],
                ['type' => 'boolean', 'body' => 'No?', 'options' => [['id' => 'true', 'correct' => false]]],
            ],
        ]]);
        $this->assertSame(201, $status, $raw);

        return $body['data']['id'];
    }

    /** Play a quiz as $actor, getting $right of its two questions right. */
    private function play(int $quizId, int $actor, int $right): void
    {
        [$status, $paper, $raw] = $this->json('POST', "/api/quizzes/$quizId/attempts", $actor);
        $this->assertSame(201, $status, $raw);
        $answers = [];
        foreach ($paper['data']['questions'] as $n => $q) {
            $truth = $q['bodyHtml'] === '<p>Yes?</p>' || str_contains((string) $q['bodyHtml'], 'Yes?');
            $answers[(string) $q['id']] = ($n < $right) === $truth ? 'true' : 'false';
        }
        [$status] = $this->json('POST', '/api/quiz-attempts/'.$paper['data']['id'].'/submit', $actor, ['data' => ['answers' => $answers]]);
        $this->assertSame(200, $status);
    }

    private function assessment(array $data, int $actor = 1): array
    {
        [$status, $body, $raw] = $this->json('POST', '/api/quiz-assessments', $actor, ['data' => $data + ['title' => 'JavaScript', 'status' => 'published']]);
        $this->assertSame(201, $status, $raw);

        return $body['data'];
    }

    #[Test]
    public function an_assessment_averages_the_finished_quizzes_and_completes_once(): void
    {
        $one = $this->quiz('Chapter 1');
        $two = $this->quiz('Chapter 2');
        $a = $this->assessment(['passMark' => 70, 'items' => [['quizId' => $one], ['quizId' => $two]]]);
        $this->assertSame(['quizId' => $one, 'weight' => 1, 'required' => true], $a['items'][0]);

        $fired = [];
        $this->app()->getContainer()->make(Dispatcher::class)->listen(AssessmentCompleted::class, function (AssessmentCompleted $e) use (&$fired) {
            $fired[] = [$e->user->id, $e->percent];
        });

        $this->play($one, 2, 2);
        [, $show] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 2);
        $this->assertSame(['done' => 1, 'required' => 2, 'total' => 2, 'percent' => 100, 'complete' => false], $show['data']['me']);
        $this->assertSame(100, (int) $show['data']['quizzes'][0]['best']['percent']);
        $this->assertNull($show['data']['quizzes'][1]['best']);

        // Half marks on the second: (100 + 50) / 2 = 75 ≥ 70.
        $this->play($two, 2, 1);
        [, $show] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 2);
        $this->assertEquals(75, $show['data']['me']['percent']);
        $this->assertTrue($show['data']['me']['complete']);
        $this->assertSame(1, $this->database()->table('quiz_assessment_results')->where('user_id', 2)->count());
        $this->assertSame([[2, 75.0]], $fired, 'AssessmentCompleted fires once, with the result');

        // Another attempt changes nothing that was recorded.
        $this->play($two, 2, 2);
        $this->assertSame(1, count($fired));
        $this->assertArrayNotHasKey('items', $show['data'], 'only editors get the editor\'s copy');
    }

    #[Test]
    public function a_weighted_assessment_counts_each_quiz_by_its_weight_and_optional_ones_dont_hold_it_up(): void
    {
        $one = $this->quiz('Chapter 1');
        $two = $this->quiz('Chapter 2');
        $bonus = $this->quiz('Bonus');
        $a = $this->assessment(['passMark' => 60, 'weighting' => 'weighted', 'items' => [
            ['quizId' => $one, 'weight' => 3], ['quizId' => $two, 'weight' => 1], ['quizId' => $bonus, 'required' => false],
        ]]);

        $this->play($one, 2, 2);
        $this->play($two, 2, 0);
        [, $show] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 2);

        // (100 × 3 + 0 × 1) / 4 = 75; the bonus quiz isn't required.
        $this->assertEquals(75, $show['data']['me']['percent']);
        $this->assertSame(2, $show['data']['me']['required']);
        $this->assertTrue($show['data']['me']['complete']);
    }

    #[Test]
    public function practice_runs_never_count_and_below_the_pass_mark_is_not_complete(): void
    {
        $one = $this->quiz('Chapter 1');
        $a = $this->assessment(['passMark' => 80, 'items' => [['quizId' => $one]]]);

        $this->play($one, 1, 2); // the admin wrote it: practice
        [, $show] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 1);
        $this->assertSame(0, $show['data']['me']['done']);

        $this->play($one, 2, 1);
        [, $show] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 2);
        $this->assertEquals(50, $show['data']['me']['percent']);
        $this->assertFalse($show['data']['me']['complete']);
        $this->assertSame(0, $this->database()->table('quiz_assessment_results')->count());
    }

    #[Test]
    public function a_quiz_the_reader_cant_see_is_only_listed_as_locked(): void
    {
        $open = $this->quiz('Chapter 1');
        $draft = $this->quiz('Secret chapter', ['status' => 'draft']);
        $a = $this->assessment(['items' => [['quizId' => $open], ['quizId' => $draft]]]);

        [, $show, $raw] = $this->json('GET', '/api/quiz-assessments/'.$a['id'], 2);
        $this->assertFalse($show['data']['quizzes'][0]['locked']);
        $this->assertTrue($show['data']['quizzes'][1]['locked']);
        $this->assertStringNotContainsString('Secret chapter', $raw);
    }

    #[Test]
    public function writing_assessments_needs_the_quiz_writing_permission_and_drafts_stay_private(): void
    {
        $one = $this->quiz('Chapter 1');

        [$status] = $this->json('POST', '/api/quiz-assessments', 2, ['data' => ['title' => 'Mine', 'items' => [['quizId' => $one]]]]);
        $this->assertSame(403, $status);
        [$status] = $this->json('POST', '/api/quiz-assessments', null, ['data' => ['type' => 'quiz-assessments', 'attributes' => ['title' => 'Guest']]]);
        $this->assertContains($status, [401, 403]);

        $draft = $this->assessment(['status' => 'draft', 'items' => [['quizId' => $one]]]);
        [$status] = $this->json('GET', '/api/quiz-assessments/'.$draft['id'], 2);
        $this->assertSame(404, $status);
        [$status, $list, $raw] = $this->json('GET', '/api/quiz-assessments', 2);
        $this->assertSame(200, $status, $raw);
        $this->assertSame([], $list['data']);

        [$status] = $this->json('PATCH', '/api/quiz-assessments/'.$draft['id'], 2, ['data' => ['title' => 'Hijacked']]);
        $this->assertSame(404, $status);
        [$status] = $this->json('DELETE', '/api/quiz-assessments/'.$draft['id'], 1);
        $this->assertSame(204, $status);
    }

    #[Test]
    public function an_assessment_is_checked_before_it_is_saved(): void
    {
        $one = $this->quiz('Chapter 1');

        foreach ([
            ['title' => ''],
            ['title' => 'Empty', 'status' => 'published', 'items' => []],
            ['title' => 'Unknown quiz', 'items' => [['quizId' => 99999]]],
        ] as $data) {
            [$status, , $raw] = $this->json('POST', '/api/quiz-assessments', 1, ['data' => $data]);
            $this->assertSame(422, $status, $raw);
        }

        $a = $this->assessment(['passMark' => 250, 'weighting' => 'nonsense', 'items' => [['quizId' => $one, 'weight' => 500], ['quizId' => $one]]]);
        $this->assertSame(100, $a['passMark']);
        $this->assertSame('equal', $a['weighting']);
        $this->assertSame([['quizId' => $one, 'weight' => 100, 'required' => true]], $a['items']);
    }

    #[Test]
    public function a_page_of_assessments_costs_the_same_queries_for_one_or_many(): void
    {
        $quizzes = [$this->quiz('A'), $this->quiz('B'), $this->quiz('C')];
        $this->assessment(['items' => [['quizId' => $quizzes[0]]]]);

        $count = function (): int {
            $queries = 0;
            $this->database()->listen(function () use (&$queries) {
                $queries++;
            });
            $request = $this->request('GET', '/api/quiz-assessments', ['authenticatedAs' => 2]);
            $this->send($request);
            $queries = 0;
            $this->send($request);

            return $queries;
        };

        $one = $count();
        for ($i = 0; $i < 5; $i++) {
            $this->assessment(['title' => "More $i", 'items' => array_map(fn ($id) => ['quizId' => $id], $quizzes)]);
        }
        $this->assertSame($one, $count());
    }
}
