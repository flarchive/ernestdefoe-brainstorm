<?php

namespace ErnestDefoe\Brainstorm\Content;

use ErnestDefoe\Brainstorm\Quiz;
use ErnestDefoe\Brainstorm\RichText;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A quiz page's title and description in the HTML itself, for link previews,
 * search engines and readers without JavaScript. One query, on this page
 * only, through the same visibility as the API: a draft or a hidden quiz
 * gives nothing away.
 */
class QuizDocument
{
    public function __construct(private RichText $rich, private UrlGenerator $url)
    {
    }

    public function __invoke(Document $document, Request $request): Document
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        if ($id < 1 || $id > 2147483647) {
            return $document;
        }
        $quiz = Quiz::query()->visibleTo(RequestUtil::getActor($request))->find($id);

        if (! $quiz) {
            return $document;
        }

        $description = Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $this->rich->html($quiz->description, $quiz)))), 200);
        $url = $this->url->to('forum')->route('brainstorm.quiz', ['id' => $quiz->id.'-'.$quiz->slug]);

        $document->title = $quiz->title;
        $document->canonicalUrl = $url;
        if ($description !== '') {
            $document->meta['description'] = $description;
        }
        $document->head[] = '<meta property="og:title" content="'.e($quiz->title).'">';
        $document->head[] = '<meta property="og:type" content="website">';
        $document->head[] = '<meta property="og:url" content="'.e($url).'">';
        if ($description !== '') {
            $document->head[] = '<meta property="og:description" content="'.e($description).'">';
        }
        $document->content = '<h1>'.e($quiz->title).'</h1>'.($description !== '' ? '<p>'.e($description).'</p>' : '');

        return $document;
    }
}
