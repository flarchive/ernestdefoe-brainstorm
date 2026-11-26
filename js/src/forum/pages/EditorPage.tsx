import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import { editorMeta, quizUrl, saveQuiz, showQuiz } from '../../common/api';
import type { Editable, EditableQuestion, QuestionType } from '../../common/api';
import Sortable from '../utils/sortable';
import { TYPES, blankQuestion, blankQuiz, key, retype, t, text } from '../editor/model';
import QuestionEditor from '../editor/QuestionEditor';
import QuizSettings from '../editor/QuizSettings';
import type { EditorMeta } from '../editor/QuizSettings';

/**
 * /quizzes/new and /quizzes/12/edit. One page for the questions and the
 * settings; nothing is saved until "Save", and leaving with changes asks first.
 */
export default class EditorPage extends Page {
  quiz: Editable | null = null;
  id: number | null = null;
  slug = '';
  meta: EditorMeta | null = null;
  saving = false;
  dirty = false;
  error: string | null = null;
  sorter = new Sortable<EditableQuestion>(
    () => this.quiz!.questions,
    () => {
      this.dirty = true;
    },
    text('sortable')
  );

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const id = parseInt(m.route.param('id'), 10);
    app.setTitle(text(id ? 'edit_title' : 'new_title'));

    editorMeta().then(
      ({ data }) => {
        this.meta = data;
        m.redraw();
      },
      () => m.route.set(app.route('brainstorm.index'))
    );

    if (id) {
      showQuiz(id, true).then(
        ({ data }) => {
          if (!data.edit) return m.route.set(quizUrl(data));
          this.id = data.id;
          this.slug = data.slug;
          this.quiz = data.edit;
          this.quiz.questions.forEach((q) => (q.key = key()));
          m.redraw();
        },
        () => m.route.set(app.route('brainstorm.index'))
      );
    } else {
      this.quiz = blankQuiz();
    }
  }

  /** Leaving the page (closing, reloading) with unsaved changes asks first. */
  unload = (e: BeforeUnloadEvent) => {
    if (this.dirty) e.preventDefault();
  };

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    window.addEventListener('beforeunload', this.unload);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    window.removeEventListener('beforeunload', this.unload);
  }

  view(): Mithril.Children {
    const quiz = this.quiz;
    const changed = () => (this.dirty = true);

    return (
      <PageStructure className="BrainstormEditorPage" sidebar={() => <IndexSidebar />} loading={!quiz || !this.meta}>
        {quiz && this.meta ? (
          <div className="BrainstormEditor" oninput={changed} onchange={changed}>
            <div className="BrainstormEditor-head">
              <h2>{t(this.id ? 'edit_title' : 'new_title')}</h2>
              {this.id ? (
                <LinkButton className="Button" icon="fas fa-eye" href={quizUrl({ id: this.id, slug: this.slug })}>
                  {t('view')}
                </LinkButton>
              ) : null}
            </div>

            <div className="BrainstormEditor-layout">
              <div className="BrainstormEditor-main">{this.main(quiz)}</div>
              <aside className="BrainstormEditor-side">
                <QuizSettings
                  quiz={quiz}
                  meta={this.meta}
                  saving={this.saving}
                  error={this.error}
                  save={(status: 'draft' | 'published') => this.save(status)}
                />
              </aside>
            </div>
          </div>
        ) : null}
      </PageStructure>
    );
  }

  /** The title, the description and the questions. */
  main(quiz: Editable): Mithril.Children {
    return [
      <div className="Form-group">
        <label for="brainstorm-title">{t('title')}</label>
        <input
          id="brainstorm-title"
          className="FormControl"
          maxlength={200}
          value={quiz.title}
          oninput={(e: InputEvent) => (quiz.title = (e.target as HTMLInputElement).value)}
        />
      </div>,
      <div className="Form-group">
        <label for="brainstorm-description">{t('description')}</label>
        <textarea
          id="brainstorm-description"
          className="FormControl"
          rows={3}
          value={quiz.description}
          oninput={(e: InputEvent) => (quiz.description = (e.target as HTMLTextAreaElement).value)}
        />
        <p className="helpText">{t('formatting_help')}</p>
      </div>,
      <h3 className="BrainstormEditor-section">{t('questions', { count: quiz.questions.length })}</h3>,
      this.questions(quiz),
      <div className="BrainstormEditor-add">
        {TYPES.map((type) => (
          <Button className="Button" icon="fas fa-plus" onclick={() => this.add(type)}>
            {t('types.' + type)}
          </Button>
        ))}
      </div>,
    ];
  }

  /** The questions, in order: drag one by its handle to move it. */
  questions(quiz: Editable): Mithril.Children {
    const changed = () => (this.dirty = true);

    return (
      <ol className="BrainstormEditor-questions" data-sortable>
        {quiz.questions.map((q, i) => (
          <QuestionEditor
            key={q.key}
            question={q}
            index={i}
            count={quiz.questions.length}
            sorter={this.sorter}
            changed={changed}
            retype={(type: QuestionType) => {
              retype(q, type);
              changed();
            }}
            duplicate={() => this.duplicate(i)}
            remove={() => this.remove(i)}
          />
        ))}
      </ol>
    );
  }

  add(type: QuestionType) {
    this.quiz!.questions.push(blankQuestion(type));
    this.dirty = true;
    // Straight into the new question.
    setTimeout(() => (document.querySelector('.BrainstormEditor-question:last-child textarea') as HTMLTextAreaElement | null)?.focus(), 0);
  }

  duplicate(i: number) {
    const copy: EditableQuestion = JSON.parse(JSON.stringify(this.quiz!.questions[i]));
    delete copy.id;
    copy.key = key();
    if (copy.type !== 'boolean') copy.options.forEach((o) => delete o.id);
    this.quiz!.questions.splice(i + 1, 0, copy);
    this.dirty = true;
  }

  remove(i: number) {
    const q = this.quiz!.questions[i];
    if ((q.body || q.options.some((o) => o.text)) && !confirm(text('remove_confirm', { number: i + 1 }))) return;
    this.quiz!.questions.splice(i, 1);
    this.dirty = true;
  }

  save(status: 'draft' | 'published') {
    const quiz = this.quiz!;
    this.saving = true;
    this.error = null;

    saveQuiz({ ...quiz, status }, this.id ?? undefined).then(
      ({ data }) => {
        this.saving = false;
        this.dirty = false;
        app.alerts.show({ type: 'success' }, t(status === 'published' ? 'published' : 'saved'));

        if (!this.id) {
          m.route.set(app.route('brainstorm.edit', { id: data.id }));
          return;
        }

        this.slug = data.slug;
        this.quiz = data.edit!;
        this.quiz.questions.forEach((q) => (q.key = key()));
        m.redraw();
      },
      (error: any) => {
        this.saving = false;
        this.error = error?.response?.errors?.[0]?.detail || text('save_failed');
        m.redraw();
      }
    );
  }
}
