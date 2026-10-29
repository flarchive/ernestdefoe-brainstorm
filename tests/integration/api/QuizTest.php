<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class QuizTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-brainstorm');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1],
            ],
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
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options);
        $response = $this->send($actor ? $request : $request->withAttribute('bypassCsrfToken', true));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** A published quiz with one question of every type, written by the admin. */
    private function quiz(array $settings = []): array
    {
        [$status, $body, $raw] = $this->json('POST', '/api/quizzes', 1, ['data' => $settings + [
            'title' => 'Capitals',
            'description' => 'How well do you **know** the map?',
            'status' => 'published',
            'passMark' => 75,
            'reveal' => 'answers',
            'questions' => [
                ['type' => 'single', 'body' => 'Capital of France?', 'explanation' => 'SECRET-EXPLANATION', 'options' => [
                    ['text' => 'Paris', 'correct' => true], ['text' => 'Lyon'], ['text' => 'Nice'],
                ]],
                ['type' => 'multiple', 'body' => 'Which are in Europe?', 'options' => [
                    ['text' => 'Spain', 'correct' => true], ['text' => 'Italy', 'correct' => true], ['text' => 'Peru'],
                ]],
                ['type' => 'boolean', 'body' => 'Berlin is in Germany.', 'options' => [['id' => 'true', 'correct' => true]]],
                ['type' => 'text', 'body' => 'Capital of Iceland?', 'options' => [['text' => 'Reykjavík'], ['text' => 'Reykjavik city']]],
            ],
        ]]);
        $this->assertSame(201, $status, $raw);

        return $body['data'];
    }

    /** The right answers, read from the editor's copy, keyed by question id. */
    private function key(array $quiz): array
    {
        $answers = [];
        foreach ($quiz['edit']['questions'] as $q) {
            $right = array_values(array_map(fn ($o) => $o['id'], array_filter($q['options'], fn ($o) => $o['correct'])));
            $answers[(string) $q['id']] = match ($q['type']) {
                'multiple' => $right,
                'text' => '  reykjavik! ',
                default => $right[0],
            };
        }

        return $answers;
    }

    private function start(int $quizId, int $actor = 2): array
    {
        [$status, $body, $raw] = $this->json('POST', "/api/quizzes/$quizId/attempts", $actor);
        $this->assertSame(200, $status, $raw);

        return $body['data'];
    }

    private function submit(int $attemptId, array $answers, int $actor = 2): array
    {
        [$status, $body, $raw] = $this->json('POST', "/api/quiz-attempts/$attemptId/submit", $actor, ['data' => ['answers' => $answers]]);
        $this->assertSame(200, $status, $raw);

        return $body['data'];
    }

    #[Test]
    public function the_answer_key_never_reaches_a_player_before_marking(): void
    {
        $quiz = $this->quiz(['reveal' => 'answers', 'maxAttempts' => 3]);

        [, , $list] = $this->json('GET', '/api/quizzes', 2);
        [, , $show] = $this->json('GET', '/api/quizzes/'.$quiz['id'].'?edit=1', 2);
        [, , $guest] = $this->json('GET', '/api/quizzes/'.$quiz['id'].'?edit=1');
        $paper = $this->start($quiz['id']);
        [, , $resumed] = $this->json('GET', '/api/quiz-attempts/'.$paper['id'], 2);

        foreach ([$list, $show, $guest, json_encode($paper), $resumed] as $raw) {
            $this->assertStringNotContainsString('"correct"', $raw);
            $this->assertStringNotContainsString('"edit"', $raw);
            $this->assertStringNotContainsString('SECRET-EXPLANATION', $raw);
            $this->assertStringNotContainsString('Reykjav', $raw, 'a typed question\'s accepted answers');
        }

        $this->assertCount(4, $paper['questions']);
        $this->assertSame('text', $paper['questions'][3]['type']);
        $this->assertSame([], $paper['questions'][3]['options']);

        // A wrong try with attempts left shows marks, not the key.
        $result = $this->submit($paper['id'], []);
        $this->assertFalse($result['passed']);
        $this->assertFalse($result['answersShown']);
        $this->assertStringNotContainsString('"correct"', json_encode($result));
        $this->assertStringNotContainsString('SECRET-EXPLANATION', json_encode($result));
    }

    #[Test]
    public function a_full_marks_attempt_passes_and_shows_the_key(): void
    {
        $quiz = $this->quiz();
        $paper = $this->start($quiz['id']);

        $result = $this->submit($paper['id'], $this->key($quiz));

        $this->assertSame(100.0, (float) $result['percent']);
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['answersShown']);
        $this->assertStringContainsString('SECRET-EXPLANATION', json_encode($result));
        $this->assertSame(1, (int) $this->database()->table('quizzes')->where('id', $quiz['id'])->value('pass_count'));
    }

    #[Test]
    public function marking_follows_each_question_type(): void
    {
        $strict = $this->quiz(['partialCredit' => false]);
        $key = $this->key($strict);
        $ids = array_keys($key);

        // Multiple choice, strict: one right pick of two earns nothing.
        $wrong = $key;
        $wrong[$ids[1]] = [$key[$ids[1]][0]];
        $result = $this->submit($this->start($strict['id'])['id'], $wrong);
        $this->assertSame(3.0, (float) $result['score']);
        $this->assertSame(75.0, (float) $result['percent']);
        $this->assertTrue($result['passed'], 'exactly the pass mark passes');

        // Partial credit: one of two right picks is half; a wrong pick takes it back.
        $partial = $this->quiz(['partialCredit' => true]);
        $pkey = $this->key($partial);
        $pids = array_keys($pkey);
        $half = $pkey;
        $half[$pids[1]] = [$pkey[$pids[1]][0]];
        $this->assertSame(3.5, (float) $this->submit($this->start($partial['id'])['id'], $half)['score']);

        $all = $partial['edit']['questions'][1]['options'];
        $everything = $pkey;
        $everything[$pids[1]] = array_map(fn ($o) => $o['id'], $all);
        $this->assertSame(3.5, (float) $this->submit($this->start($partial['id'], 3)['id'], $everything, 3)['score'], 'ticking every box is not a strategy');

        // A typed answer is matched without case, accents, spacing or the punctuation around it.
        $typed = $pkey;
        $typed[$pids[3]] = 'REYKJAVIK CITY.';
        $this->assertSame(4.0, (float) $this->submit($this->start($partial['id'], 4)['id'], $typed, 4)['score']);
    }

    #[Test]
    public function answers_naming_other_questions_or_options_are_ignored(): void
    {
        $quiz = $this->quiz();
        $paper = $this->start($quiz['id']);

        $result = $this->submit($paper['id'], ['999' => 'x', (string) $paper['questions'][0]['id'] => 'not-an-option', (string) $paper['questions'][1]['id'] => 'not-a-list']);

        $this->assertSame(0.0, (float) $result['score']);
    }

    #[Test]
    public function the_attempt_limit_is_kept(): void
    {
        $quiz = $this->quiz(['maxAttempts' => 1]);
        $paper = $this->start($quiz['id']);

        // Starting again while one is open hands back the same attempt.
        $this->assertSame($paper['id'], $this->start($quiz['id'])['id']);

        $this->submit($paper['id'], []);

        [$status] = $this->json('POST', '/api/quizzes/'.$quiz['id'].'/attempts', 2);
        $this->assertSame(422, $status);
        $this->assertSame(1, $this->database()->table('quiz_attempts')->where('user_id', 2)->count());
    }

    #[Test]
    public function an_attempt_is_marked_once(): void
    {
        $quiz = $this->quiz();
        $paper = $this->start($quiz['id']);
        $key = $this->key($quiz);

        $first = $this->submit($paper['id'], []);
        $second = $this->submit($paper['id'], $key);

        $this->assertSame((float) $first['score'], (float) $second['score']);
        $this->assertSame(0.0, (float) $second['score']);
        $this->assertSame(1, (int) $this->database()->table('quizzes')->where('id', $quiz['id'])->value('attempt_count'));
        $this->assertSame(1, (int) $this->database()->table('quiz_bests')->where('quiz_id', $quiz['id'])->value('attempts'));
    }

    #[Test]
    public function the_server_clock_decides_and_late_answers_are_not_taken(): void
    {
        $quiz = $this->quiz(['timeLimit' => 60]);
        $key = $this->key($quiz);
        $ids = array_keys($key);

        Carbon::setTestNow(Carbon::now());
        $paper = $this->start($quiz['id']);
        $this->assertSame(60, $paper['secondsLeft']);

        // Saved in time: the first answer.
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        [$status] = $this->json('PATCH', '/api/quiz-attempts/'.$paper['id'], 2, ['data' => ['answers' => [$ids[0] => $key[$ids[0]]]]]);
        $this->assertSame(200, $status);

        // Two minutes on, everything is right — and too late.
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $this->json('PATCH', '/api/quiz-attempts/'.$paper['id'], 2, ['data' => ['answers' => $key]]);
        $result = $this->submit($paper['id'], $key);

        $this->assertTrue($result['expired']);
        $this->assertSame(1.0, (float) $result['score'], 'only what was saved before the deadline');
        $this->assertSame(60, $result['duration']);
    }

    #[Test]
    public function an_abandoned_timed_attempt_is_marked_when_next_seen(): void
    {
        $quiz = $this->quiz(['timeLimit' => 60, 'maxAttempts' => 2]);

        Carbon::setTestNow(Carbon::now());
        $paper = $this->start($quiz['id']);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        [, $show] = $this->json('GET', '/api/quizzes/'.$quiz['id'], 2);

        $this->assertNull($show['data']['me']['openAttemptId']);
        $this->assertSame(1, $show['data']['me']['attemptsLeft']);
        $this->assertNotNull($this->database()->table('quiz_attempts')->where('id', $paper['id'])->value('submitted_at'));
    }

    #[Test]
    public function nobody_else_can_see_save_or_submit_an_attempt(): void
    {
        $quiz = $this->quiz();
        $paper = $this->start($quiz['id']);

        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/submit']] as [$method, $suffix]) {
            [$status] = $this->json($method, '/api/quiz-attempts/'.$paper['id'].$suffix, 3, $method === 'GET' ? null : ['data' => ['answers' => []]]);
            $this->assertSame(404, $status, "$method by another member");
        }

        [$status] = $this->json('GET', '/api/quiz-attempts/'.$paper['id']);
        $this->assertSame(401, $status);

        $this->assertNull($this->database()->table('quiz_attempts')->where('id', $paper['id'])->value('submitted_at'));
    }

    #[Test]
    public function guests_may_look_but_not_play(): void
    {
        $quiz = $this->quiz();

        [$status, $body] = $this->json('GET', '/api/quizzes/'.$quiz['id']);
        $this->assertSame(200, $status);
        $this->assertFalse($body['data']['canTake']);

        [$status] = $this->json('POST', '/api/quizzes/'.$quiz['id'].'/attempts');
        $this->assertSame(401, $status);
    }

    #[Test]
    public function drafts_and_editing_belong_to_their_author_and_managers(): void
    {
        $draft = $this->quiz(['status' => 'draft']);

        [$status] = $this->json('GET', '/api/quizzes/'.$draft['id'], 2);
        $this->assertSame(404, $status, 'a member cannot see a draft');

        [, $list] = $this->json('GET', '/api/quizzes?filter[ids]='.$draft['id'], 2);
        $this->assertSame([], $list['data']);

        [$status] = $this->json('POST', '/api/quizzes', 2, ['data' => ['title' => 'Mine']]);
        $this->assertSame(403, $status, 'members do not write quizzes by default');

        $published = $this->quiz();
        [$status] = $this->json('PATCH', '/api/quizzes/'.$published['id'], 2, ['data' => ['title' => 'Hijacked']]);
        $this->assertSame(403, $status);
        [$status] = $this->json('DELETE', '/api/quizzes/'.$published['id'], 2);
        $this->assertSame(403, $status);
        [$status] = $this->json('GET', '/api/quizzes/'.$published['id'].'/results', 2);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function an_author_without_the_reward_permission_cannot_attach_rewards(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => Group::MEMBER_ID, 'permission' => 'brainstorm.create']]]);

        [$status, $body, $raw] = $this->json('POST', '/api/quizzes', 2, ['data' => [
            'title' => 'Points for me',
            'rewardPoints' => 5000,
            'rewardBadgeId' => 1,
            'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'options' => [['id' => 'true', 'correct' => true]]]],
        ]]);
        $this->assertSame(201, $status, $raw);
        $this->assertSame(0, $body['data']['edit']['rewardPoints']);
        $this->assertNull($body['data']['edit']['rewardBadgeId']);

        // Their own draft is theirs to see and edit; another member's is not.
        [$status] = $this->json('PATCH', '/api/quizzes/'.$body['data']['id'], 2, ['data' => ['title' => 'Renamed', 'rewardPoints' => 10]]);
        $this->assertSame(200, $status);
        $this->assertSame(0, (int) $this->database()->table('quizzes')->where('id', $body['data']['id'])->value('reward_points'));
        [$status] = $this->json('GET', '/api/quizzes/'.$body['data']['id'], 3);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function a_reveal_of_score_only_hides_the_marking(): void
    {
        $quiz = $this->quiz(['reveal' => 'score']);
        $result = $this->submit($this->start($quiz['id'])['id'], $this->key($quiz));

        $this->assertTrue($result['passed']);
        $this->assertNull($result['review']);
    }

    #[Test]
    public function the_key_shows_once_attempts_run_out(): void
    {
        $quiz = $this->quiz(['reveal' => 'answers', 'maxAttempts' => 1]);
        $result = $this->submit($this->start($quiz['id'])['id'], []);

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['answersShown']);
        $this->assertSame(0, $result['attemptsLeft']);
    }

    #[Test]
    public function a_picked_quiz_deals_that_many_questions(): void
    {
        $quiz = $this->quiz(['questionPick' => 2, 'shuffleQuestions' => true]);
        $paper = $this->start($quiz['id']);

        $this->assertCount(2, $paper['questions']);
        $this->assertSame(2, $quiz['questionCount']);
    }

    #[Test]
    public function leaderboards_leave_out_members_who_opted_out(): void
    {
        $quiz = $this->quiz();
        foreach ([2, 3, 4] as $user) {
            $this->submit($this->start($quiz['id'], $user)['id'], $this->key($quiz), $user);
        }

        [$status] = $this->json('PATCH', '/api/users/3', 3, ['data' => ['type' => 'users', 'id' => '3', 'attributes' => ['quizPrivate' => true]]]);
        $this->assertSame(200, $status);

        // Another member cannot change carol's choice back.
        $this->json('PATCH', '/api/users/3', 2, ['data' => ['type' => 'users', 'id' => '3', 'attributes' => ['quizPrivate' => false]]]);
        $this->assertTrue((bool) $this->database()->table('users')->where('id', 3)->value('quiz_private'));

        foreach (['/api/quizzes/'.$quiz['id'].'/leaderboard', '/api/quiz-leaderboard'] as $path) {
            [, $seen] = $this->json('GET', $path, 2);
            $names = array_map(fn ($row) => $row['user']['username'], $seen['data']);
            $this->assertNotContains('carol', $names, $path);
            $this->assertContains('dave', $names, $path);

            [, $guest] = $this->json('GET', $path);
            $this->assertNotContains('carol', array_map(fn ($row) => $row['user']['username'], $guest['data']), $path);

            // Carol still sees where she stands.
            [, $own] = $this->json('GET', $path, 3);
            $this->assertNotNull($own['meta']['me'], $path);
        }

        [, $users] = $this->json('GET', '/api/users/3', 2);
        $this->assertArrayNotHasKey('quizPrivate', $users['data']['attributes']);
    }

    #[Test]
    public function managers_see_results_and_can_reset_a_member(): void
    {
        $quiz = $this->quiz(['maxAttempts' => 1]);
        $this->submit($this->start($quiz['id'])['id'], $this->key($quiz));

        [$status, $body] = $this->json('GET', '/api/quizzes/'.$quiz['id'].'/results', 1);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertEquals(100, $body['meta']['questions'][0]['correct']);

        [$status] = $this->json('DELETE', '/api/quizzes/'.$quiz['id'].'/results/2', 1);
        $this->assertSame(204, $status);

        $this->start($quiz['id']);
        $this->assertSame(0, (int) $this->database()->table('quizzes')->where('id', $quiz['id'])->value('pass_count'));
    }

    #[Test]
    public function a_quiz_closed_by_date_takes_no_new_attempts(): void
    {
        $quiz = $this->quiz(['closesAt' => Carbon::now()->subDay()->toIso8601String(), 'opensAt' => Carbon::now()->subDays(2)->toIso8601String()]);

        [$status] = $this->json('POST', '/api/quizzes/'.$quiz['id'].'/attempts', 2);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function an_author_previews_a_draft_as_practice(): void
    {
        $draft = $this->quiz(['status' => 'draft', 'maxAttempts' => 1]);

        foreach ([1, 2] as $try) {
            $result = $this->submit($this->start($draft['id'], 1)['id'], $this->key($draft), 1);
            $this->assertTrue($result['practice'], "try $try");
            $this->assertTrue($result['passed']);
        }

        $this->assertSame(0, $this->database()->table('quiz_bests')->count(), 'practice is off the leaderboards');
        $this->assertSame(0, (int) $this->database()->table('quizzes')->where('id', $draft['id'])->value('attempt_count'));

        [$status] = $this->json('POST', '/api/quizzes/'.$draft['id'].'/attempts', 2);
        $this->assertSame(404, $status, 'members cannot start a draft');
    }

    #[Test]
    public function a_writers_own_run_follows_the_reveal_setting(): void
    {
        // luceos, Discord 2026-10-29: answers set to show only once passed,
        // yet his own failed test run showed every correct answer.
        $quiz = $this->quiz(['reveal' => 'answers']);

        $failed = $this->submit($this->start($quiz['id'], 1)['id'], [], 1);
        $this->assertTrue($failed['practice']);
        $this->assertFalse($failed['answersShown'], 'a failed run keeps the key hidden, as for members');

        $passed = $this->submit($this->start($quiz['id'], 1)['id'], $this->key($quiz), 1);
        $this->assertTrue($passed['answersShown'], 'a passed run reveals it, as for members');
    }

    #[Test]
    public function with_the_setting_on_a_writers_attempt_on_an_open_quiz_counts(): void
    {
        $this->setting('ernestdefoe-brainstorm.editors_count', '1');
        $quiz = $this->quiz(['maxAttempts' => 1]);
        $draft = $this->quiz(['status' => 'draft']);
        $later = $this->quiz(['opensAt' => Carbon::now()->addDay()->toIso8601String()]);

        $result = $this->submit($this->start($quiz['id'], 1)['id'], $this->key($quiz), 1);
        $this->assertFalse($result['practice']);
        $this->assertSame(1, $this->database()->table('quiz_bests')->where('user_id', 1)->count(), 'on the leaderboard');
        $this->assertSame(1, (int) $this->database()->table('quizzes')->where('id', $quiz['id'])->value('attempt_count'));

        [$status] = $this->json('POST', '/api/quizzes/'.$quiz['id'].'/attempts', 1);
        $this->assertSame(422, $status, 'and the attempt limit applies');

        // A draft, or a quiz not open yet, is still only a preview.
        foreach ([$draft, $later] as $preview) {
            $this->assertTrue($this->submit($this->start($preview['id'], 1)['id'], $this->key($preview), 1)['practice']);
        }
    }

    #[Test]
    public function the_quiz_page_carries_its_title_for_link_previews_but_never_a_drafts(): void
    {
        $quiz = $this->quiz();
        $draft = $this->quiz(['status' => 'draft', 'title' => 'Secret upcoming quiz']);

        $html = (string) $this->send($this->request('GET', '/quizzes/'.$quiz['id'].'-capitals'))->getBody();
        $this->assertStringContainsString('<meta property="og:title" content="Capitals">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="How well do you', $html);

        $html = (string) $this->send($this->request('GET', '/quizzes/'.$draft['id'], ['authenticatedAs' => 2]))->getBody();
        $this->assertStringNotContainsString('Secret upcoming quiz', $html);
    }

    #[Test]
    public function an_earlier_result_hides_the_key_while_the_last_attempt_is_open(): void
    {
        $quiz = $this->quiz(['reveal' => 'answers', 'maxAttempts' => 2]);
        $first = $this->submit($this->start($quiz['id'])['id'], []);
        $this->assertFalse($first['answersShown']);

        // The last attempt is open: the first one's result must not carry the key.
        $this->start($quiz['id']);
        [, $again, $raw] = $this->json('GET', '/api/quiz-attempts/'.$first['id'], 2);
        $this->assertFalse($again['data']['answersShown']);
        $this->assertStringNotContainsString('"correct"', $raw);
        $this->assertStringNotContainsString('SECRET-EXPLANATION', $raw);
    }

    #[Test]
    public function an_attempt_cannot_outlive_the_quiz_closing(): void
    {
        Carbon::setTestNow(Carbon::now());
        $quiz = $this->quiz(['closesAt' => Carbon::now()->addMinute()->toIso8601String()]);
        $paper = $this->start($quiz['id']);
        $this->assertLessThanOrEqual(60, $paper['secondsLeft']);

        Carbon::setTestNow(Carbon::now()->addDays(2));
        $result = $this->submit($paper['id'], $this->key($quiz));
        $this->assertTrue($result['expired']);
        $this->assertSame(0.0, (float) $result['score']);
    }

    #[Test]
    public function an_update_only_changes_what_it_sends(): void
    {
        $quiz = $this->quiz(['timeLimit' => 120, 'maxAttempts' => 3, 'status' => 'draft']);

        [$status] = $this->json('PATCH', '/api/quizzes/'.$quiz['id'], 1, ['data' => ['status' => 'published']]);
        $this->assertSame(200, $status);

        $row = $this->database()->table('quizzes')->where('id', $quiz['id'])->first();
        $this->assertSame('published', $row->status);
        $this->assertSame(120, (int) $row->time_limit);
        $this->assertSame(3, (int) $row->max_attempts);
        $this->assertSame(4, (int) $row->question_count);
        $this->assertSame(4, $this->database()->table('quiz_questions')->where('quiz_id', $quiz['id'])->count());
        $this->assertNotNull($row->description);
    }

    #[Test]
    public function an_author_cannot_change_how_a_rewarded_quiz_is_marked(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => Group::MEMBER_ID, 'permission' => 'brainstorm.create']]]);
        [, $made] = $this->json('POST', '/api/quizzes', 2, ['data' => [
            'title' => 'Mine', 'status' => 'published', 'passMark' => 80,
            'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'options' => [['id' => 'true', 'correct' => true]]]],
        ]]);
        $id = $made['data']['id'];

        // An admin attaches points.
        [$status] = $this->json('PATCH', "/api/quizzes/$id", 1, ['data' => ['rewardPoints' => 50]]);
        $this->assertSame(200, $status);

        [$status] = $this->json('PATCH', "/api/quizzes/$id", 2, ['data' => ['passMark' => 0]]);
        $this->assertSame(422, $status, 'pass mark');
        [$status] = $this->json('PATCH', "/api/quizzes/$id", 2, ['data' => ['questions' => [['type' => 'boolean', 'body' => 'Yes?', 'options' => [['id' => 'true', 'correct' => false]]]]]]);
        $this->assertSame(422, $status, 'answer key');
        $this->assertSame(80, (int) $this->database()->table('quizzes')->where('id', $id)->value('pass_mark'));

        // The wording is still theirs to fix.
        [$status] = $this->json('PATCH', "/api/quizzes/$id", 2, ['data' => ['title' => 'Mine, retitled', 'questions' => [['type' => 'boolean', 'body' => 'Yes, really?', 'options' => [['id' => 'true', 'correct' => true]]]]]]);
        $this->assertSame(200, $status);
    }

    #[Test]
    public function a_member_made_an_editor_mid_attempt_is_practising(): void
    {
        $quiz = $this->quiz();
        $paper = $this->start($quiz['id'], 3);

        $this->database()->table('group_user')->insert(['user_id' => 3, 'group_id' => Group::MODERATOR_ID]);
        $result = $this->submit($paper['id'], $this->key($quiz), 3);

        $this->assertTrue($result['practice']);
        $this->assertSame(0, $this->database()->table('quiz_bests')->where('user_id', 3)->count());
    }

    #[Test]
    public function a_retake_after_seeing_the_key_does_not_climb_the_board(): void
    {
        $quiz = $this->quiz(['reveal' => 'answers_always']);
        $key = $this->key($quiz);
        $ids = array_keys($key);

        $half = [$ids[0] => $key[$ids[0]], $ids[1] => $key[$ids[1]]];
        $this->submit($this->start($quiz['id'])['id'], $half);
        $this->submit($this->start($quiz['id'])['id'], $key);

        $best = $this->database()->table('quiz_bests')->where('user_id', 2)->first();
        $this->assertSame(50.0, (float) $best->percent, 'the first, unseen attempt is what ranks');
        $this->assertSame(2, (int) $best->attempts);
    }

    #[Test]
    public function an_image_may_be_a_path_on_this_forum(): void
    {
        [$status] = $this->json('POST', '/api/quizzes', 1, ['data' => [
            'title' => 'Local image',
            'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'imageUrl' => '/assets/files/2026-10-20/cat.png', 'options' => [['id' => 'true', 'correct' => true]]]],
        ]]);
        $this->assertSame(201, $status);

        foreach (['//evil.example/x.png', '/\\evil.example/x.png'] as $url) {
            [$status] = $this->json('POST', '/api/quizzes', 1, ['data' => [
                'title' => 'Images',
                'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'imageUrl' => $url, 'options' => [['id' => 'true', 'correct' => true]]]],
            ]]);
            $this->assertSame(422, $status, $url);
        }
    }

    #[Test]
    public function an_image_has_to_be_a_web_address(): void
    {
        foreach (['javascript:alert(1)', "java\tscript:alert(1)", '//evil.example/x.png', "https://ok.example/\x01"] as $url) {
            [$status] = $this->json('POST', '/api/quizzes', 1, ['data' => [
                'title' => 'Images',
                'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'imageUrl' => $url, 'options' => [['id' => 'true', 'correct' => true]]]],
            ]]);
            $this->assertSame(422, $status, $url);
        }
    }
}
