import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { listQuizzes } from '../../common/api';
import type { Quiz } from '../../common/api';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.composer.${key}`, params);

interface Attrs extends IInternalModalAttrs {
  composer: any;
}

/**
 * Pick a quiz to put in the post. What goes in is the quiz's link on a line of
 * its own, which every editor keeps as a link — Markdown, BBCode, Scribe,
 * FoF Rich Text — and which the post then shows as the quiz itself. A forum
 * without Brainstorm, or a reader who may not see the quiz, sees the link.
 */
export default class QuizPickerModal extends Modal<Attrs> {
  quizzes: Quiz[] = [];
  loading = true;
  query = '';
  timer = 0;
  /** Only the newest search's answer is shown. */
  asked = 0;

  className() {
    return 'BrainstormPicker Modal--small';
  }

  title() {
    return t('title');
  }

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    this.loading = true;
    const filter: Record<string, string> = {};
    if (this.query.trim()) filter.q = this.query.trim();

    const ask = ++this.asked;
    listQuizzes({ filter }).then(
      ({ data }) => {
        if (ask !== this.asked) return;
        this.quizzes = data;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <input
          className="FormControl"
          type="search"
          placeholder={extractText(t('search'))}
          aria-label={extractText(t('search'))}
          value={this.query}
          oninput={(e: InputEvent) => {
            this.query = (e.target as HTMLInputElement).value;
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.load(), 300);
          }}
        />
        {this.loading ? (
          <LoadingIndicator display="block" />
        ) : this.quizzes.length ? (
          <ul className="BrainstormPicker-list">
            {this.quizzes.map((quiz) => (
              <li>
                <Button className="Button Button--flat BrainstormPicker-item" icon="fas fa-graduation-cap" onclick={() => this.insert(quiz)}>
                  <span className="BrainstormPicker-title">{quiz.title}</span>
                  <span className="BrainstormPicker-meta">{t('meta', { count: quiz.questionCount })}</span>
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="BrainstormEmpty">{t('none')}</p>
        )}
        {app.forum.attribute('canCreateQuizzes') ? (
          <LinkButton className="Button Button--link" icon="fas fa-plus" href={app.route('brainstorm.new')} onclick={() => this.hide()}>
            {t('create')}
          </LinkButton>
        ) : null}
      </div>
    );
  }

  insert(quiz: Quiz) {
    const url = app.forum.attribute<string>('baseUrl') + '/quizzes/' + quiz.id + (quiz.slug ? '-' + quiz.slug : '');
    // Scribe turns a Markdown link into a real one on insert; every other
    // editor autolinks a bare address.
    const scribe = 'ernestdefoe-scribe' in ((window as any).flarum?.extensions || {});
    this.attrs.composer.editor?.insertAtCursor(scribe ? `\n\n[${url}](${url})\n\n` : `\n\n${url}\n\n`, false);
    this.hide();
  }
}
