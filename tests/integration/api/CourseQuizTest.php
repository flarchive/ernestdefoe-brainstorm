<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Course-only quizzes through Syllabus: enrolled members, the course's
 * instructors, course managers and the quiz's editors see it; nobody else,
 * anywhere quizzes show.
 */
class CourseQuizTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use SpeaksJsonApi;

    private bool $withSyllabus = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...($this->withSyllabus ? ['ernestdefoe-syllabus', 'ernestdefoe-brainstorm'] : ['ernestdefoe-brainstorm']));
        $now = Carbon::now()->toDateTimeString();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'dave', 'email' => 'dave@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [['group_id' => 3, 'permission' => 'syllabus.create']],
            'syllabus_courses' => [
                ['id' => 1, 'user_id' => 1, 'title' => 'JavaScript', 'slug' => 'javascript', 'status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
            'syllabus_instructors' => [['course_id' => 1, 'user_id' => 4]],
            'syllabus_enrolments' => [['course_id' => 1, 'user_id' => 2, 'created_at' => $now]],
            'quizzes' => [
                ['id' => 1, 'user_id' => 1, 'title' => 'Chapter quiz', 'slug' => 'chapter-quiz', 'status' => 'published', 'reveal' => 'answers', 'course_id' => 1, 'question_count' => 1, 'pass_mark' => 50, 'published_at' => $now],
                ['id' => 2, 'user_id' => 1, 'title' => 'Open quiz', 'slug' => 'open-quiz', 'status' => 'published', 'reveal' => 'answers', 'question_count' => 1, 'pass_mark' => 50, 'published_at' => $now],
            ],
            'quiz_questions' => [
                ['id' => 1, 'quiz_id' => 1, 'position' => 0, 'type' => 'boolean', 'body' => '<t>Yes?</t>', 'points' => 1, 'options' => json_encode([['id' => 'true', 'text' => '', 'correct' => true], ['id' => 'false', 'text' => '', 'correct' => false]])],
                ['id' => 2, 'quiz_id' => 2, 'position' => 0, 'type' => 'boolean', 'body' => '<t>Yes?</t>', 'points' => 1, 'options' => json_encode([['id' => 'true', 'text' => '', 'correct' => true], ['id' => 'false', 'text' => '', 'correct' => false]])],
            ],
        ]);
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

    private function titles(?int $actor): array
    {
        [$status, $body, $raw] = $this->json('GET', '/api/quizzes', $actor);
        $this->assertSame(200, $status, $raw);

        $titles = array_column($body['data'], 'title');
        sort($titles);

        return $titles;
    }

    #[Test]
    public function only_the_course_people_see_a_course_only_quiz(): void
    {
        $this->assertSame(['Chapter quiz', 'Open quiz'], $this->titles(2), 'enrolled');
        $this->assertSame(['Chapter quiz', 'Open quiz'], $this->titles(4), "the course's instructor");
        $this->assertSame(['Chapter quiz', 'Open quiz'], $this->titles(1), 'admin');
        $this->assertSame(['Open quiz'], $this->titles(3), 'not enrolled');
        $this->assertSame(['Open quiz'], $this->titles(null), 'guest');

        $this->assertSame(404, $this->json('GET', '/api/quizzes/1', 3)[0]);
        $this->assertSame(404, $this->json('POST', '/api/quizzes/1/attempts', 3)[0]);
        [$status, $quiz] = $this->json('GET', '/api/quizzes/1', 2);
        $this->assertSame(200, $status);
        $this->assertTrue($quiz['data']['courseOnly']);
        $this->assertSame(201, $this->json('POST', '/api/quizzes/1/attempts', 2)[0]);
    }

    #[Test]
    public function the_overall_leaderboard_counts_a_course_quiz_only_for_those_who_see_it(): void
    {
        [, $paper] = $this->json('POST', '/api/quizzes/1/attempts', 2);
        $this->json('POST', '/api/quiz-attempts/'.$paper['data']['id'].'/submit', 2, ['data' => ['answers' => ['1' => 'true']]]);

        $names = fn (?int $actor) => array_map(fn ($r) => $r['user']['username'], $this->json('GET', '/api/quiz-leaderboard', $actor)[1]['data']);
        $this->assertSame(['normal'], $names(4));
        $this->assertSame([], $names(3), 'carol may not see the quiz, so it counts for nobody on her board');
    }

    #[Test]
    public function an_author_may_limit_a_quiz_only_to_a_course_they_teach(): void
    {
        [$status, $body] = $this->json('GET', '/api/quizzes/editor', 1);
        $this->assertSame(200, $status);
        $this->assertSame([['id' => 1, 'title' => 'JavaScript']], $body['meta']['courses']);

        [$status, , $raw] = $this->json('PATCH', '/api/quizzes/2', 1, ['data' => ['courseId' => 999]]);
        $this->assertSame(422, $status, $raw);
        [$status] = $this->json('PATCH', '/api/quizzes/2', 1, ['data' => ['courseId' => 1]]);
        $this->assertSame(200, $status);
        $this->assertSame(1, (int) $this->database()->table('quizzes')->where('id', 2)->value('course_id'));
        $this->assertSame([], $this->titles(3));
    }
}
