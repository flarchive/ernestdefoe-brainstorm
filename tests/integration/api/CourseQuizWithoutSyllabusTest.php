<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Syllabus turned off: a course-only quiz stays hidden from all but its editors (fails closed). */
class CourseQuizWithoutSyllabusTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-brainstorm');
        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'quizzes' => [
                ['id' => 1, 'user_id' => 1, 'title' => 'Chapter quiz', 'slug' => 'chapter-quiz', 'status' => 'published', 'reveal' => 'answers', 'course_id' => 7, 'question_count' => 1],
                ['id' => 2, 'user_id' => 1, 'title' => 'Open quiz', 'slug' => 'open-quiz', 'status' => 'published', 'reveal' => 'answers', 'question_count' => 1],
            ],
        ]);
    }

    #[Test]
    public function a_course_only_quiz_fails_closed(): void
    {
        foreach ([2 => ['Open quiz'], 1 => ['Chapter quiz', 'Open quiz']] as $actor => $expected) {
            $response = $this->send($this->request('GET', '/api/quizzes', ['authenticatedAs' => $actor]));
            $titles = array_map(fn ($r) => $r['attributes']['title'], json_decode((string) $response->getBody(), true)['data']);
            sort($titles);
            $this->assertSame($expected, $titles);
        }

        [$editor] = [json_decode((string) $this->send($this->request('GET', '/api/quizzes/editor', ['authenticatedAs' => 1]))->getBody(), true)];
        $this->assertNull($editor['meta']['courses'], 'no course picker without Syllabus');
    }
}
