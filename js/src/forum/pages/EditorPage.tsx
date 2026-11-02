import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { editorMeta, quizUrl, saveQuiz, showQuiz } from '../../common/api';
import type { Badge, Editable, EditableQuestion, QuestionType, Reveal } from '../../common/api';
import Sortable from '../utils/sortable';
import { hasMediaLibrary, pickImage } from '../utils/mediaLibrary';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.editor.${key}`, params);
const text = (key: string, params: Record<string, unknown> = {}) => extractText(t(key, params));

const TYPES: QuestionType[] = ['single', 'multiple', 'boolean', 'text'];
const REVEALS: Reveal[] = ['score', 'mistakes', 'answers', 'answers_always'];

let keys = 0;
const key = () => 'k' + ++keys;

function blankQuestion(type: QuestionType = 'single'): EditableQuestion {
  return {
    key: key(),
    type,
    body: '',
    imageUrl: null,
    points: 1,
    explanation: '',
    options:
      type === 'boolean'
        ? [{ id: 'true', text: '', correct: true }]
        : type === 'text'
          ? [{ text: '', correct: true }]
          : [
              { text: '', correct: true },
              { text: '', correct: false },
            ],
  };
}

function blankQuiz(): Editable {
  return {
    title: '',
    description: '',
    status: 'draft',
    timeLimit: null,
    passMark: 70,
    maxAttempts: null,
    questionPick: null,
    shuffleQuestions: false,
    shuffleOptions: true,
    partialCredit: false,
    reveal: 'answers',
    leaderboard: true,
    opensAt: null,
    closesAt: null,
    rewardPoints: 0,
    rewardBadgeId: null,
    questions: [blankQuestion()],
  };
}

/** ISO from the server ⇄ the value a datetime-local input takes, in the writer's own time zone. */
function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
const fromLocalInput = (value: string) => (value ? new Date(value).toISOString() : null);

/**
 * /quizzes/new and /quizzes/12/edit. One page for the questions and the
 * settings; nothing is saved until "Save", and leaving with changes asks first.
 */
export default class EditorPage extends Page {
  quiz: Editable | null = null;
  id: number | null = null;
  slug = '';
  meta: { canReward: boolean; points: boolean; badges: boolean; badgeList: Badge[] } | null = null;
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

    return (
      <PageStructure className="BrainstormEditorPage" sidebar={() => <IndexSidebar />} loading={!quiz || !this.meta}>
        {quiz && this.meta ? (
          <div className="BrainstormEditor" oninput={() => (this.dirty = true)} onchange={() => (this.dirty = true)}>
            <div className="BrainstormEditor-head">
              <h2>{t(this.id ? 'edit_title' : 'new_title')}</h2>
              {this.id ? (
                <LinkButton className="Button" icon="fas fa-eye" href={quizUrl({ id: this.id, slug: this.slug })}>
                  {t('view')}
                </LinkButton>
              ) : null}
            </div>

            <div className="BrainstormEditor-layout">
              <div className="BrainstormEditor-main">
                <div className="Form-group">
                  <label for="brainstorm-title">{t('title')}</label>
                  <input
                    id="brainstorm-title"
                    className="FormControl"
                    maxlength={200}
                    value={quiz.title}
                    oninput={(e: InputEvent) => (quiz.title = (e.target as HTMLInputElement).value)}
                  />
                </div>
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
                </div>

                <h3 className="BrainstormEditor-section">{t('questions', { count: quiz.questions.length })}</h3>
                <ol className="BrainstormEditor-questions" data-sortable>
                  {quiz.questions.map((q, i) => this.question(q, i))}
                </ol>
                <div className="BrainstormEditor-add">
                  {TYPES.map((type) => (
                    <Button className="Button" icon="fas fa-plus" onclick={() => this.add(type)}>
                      {t('types.' + type)}
                    </Button>
                  ))}
                </div>
              </div>

              <aside className="BrainstormEditor-side">{this.settings(quiz)}</aside>
            </div>
          </div>
        ) : null}
      </PageStructure>
    );
  }

  question(q: EditableQuestion, i: number): Mithril.Children {
    const id = `brainstorm-q-${q.key}`;

    return (
      <li className="BrainstormEditor-question" key={q.key}>
        <div className="BrainstormEditor-questionHead">
          <span className="BrainstormEditor-handle" aria-label={text('drag', { number: i + 1 })} {...this.sorter.handle(i)}>
            <Icon name="fas fa-grip-vertical" />
          </span>
          <span className="BrainstormEditor-number">{i + 1}</span>
          <span className="BrainstormEditor-summary">
            {q.body
              .split('\n')[0]
              .replace(/[*_`~#>[\]]/g, '')
              .trim() || text('untitled')}
          </span>
          <Select
            className="BrainstormEditor-type"
            aria-label={text('type')}
            value={q.type}
            options={Object.fromEntries(TYPES.map((type) => [type, text('types.' + type)]))}
            onchange={(type: QuestionType) => this.retype(q, type)}
          />
          <Button
            className="Button Button--icon"
            icon="far fa-copy"
            aria-label={text('duplicate')}
            title={text('duplicate')}
            onclick={() => this.duplicate(i)}
          />
          <Button
            className="Button Button--icon"
            icon="fas fa-trash"
            aria-label={text('remove')}
            title={text('remove')}
            disabled={this.quiz!.questions.length < 2}
            onclick={() => this.remove(i)}
          />
        </div>

        <div className="BrainstormEditor-questionBody">
          <label className="sr-only" for={id}>
            {t('question')}
          </label>
          <textarea
            id={id}
            className="FormControl"
            rows={2}
            maxlength={5000}
            placeholder={text('question_placeholder')}
            value={q.body}
            oninput={(e: InputEvent) => (q.body = (e.target as HTMLTextAreaElement).value)}
          />

          <div className="BrainstormEditor-image">
            <input
              className="FormControl"
              type="url"
              maxlength={500}
              placeholder={text('image_placeholder')}
              aria-label={text('image')}
              value={q.imageUrl || ''}
              oninput={(e: InputEvent) => (q.imageUrl = (e.target as HTMLInputElement).value || null)}
            />
            {hasMediaLibrary() ? (
              <Button className="Button" icon="far fa-image" onclick={() => pickImage((url) => ((q.imageUrl = url), (this.dirty = true)))}>
                {t('pick_image')}
              </Button>
            ) : null}
          </div>
          {q.imageUrl && /^(https?:\/\/|\/(?![\/\\]))/i.test(q.imageUrl) ? (
            <img className="BrainstormEditor-preview" src={q.imageUrl} alt="" loading="lazy" referrerpolicy="no-referrer" />
          ) : null}

          {this.answers(q)}

          <div className="BrainstormEditor-row">
            <label className="BrainstormEditor-points">
              {t('points')}
              <input
                className="FormControl"
                type="number"
                min={1}
                max={100}
                value={q.points}
                oninput={(e: InputEvent) => (q.points = Number((e.target as HTMLInputElement).value) || 1)}
              />
            </label>
          </div>

          <label className="BrainstormEditor-explanationLabel" for={id + '-why'}>
            {t('explanation')}
          </label>
          <textarea
            id={id + '-why'}
            className="FormControl"
            rows={2}
            maxlength={5000}
            placeholder={text('explanation_placeholder')}
            value={q.explanation}
            oninput={(e: InputEvent) => (q.explanation = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
      </li>
    );
  }

  answers(q: EditableQuestion): Mithril.Children {
    if (q.type === 'boolean') {
      const isTrue = !!q.options.find((o) => o.id === 'true')?.correct;

      return (
        <div className="BrainstormEditor-boolean" role="radiogroup" aria-label={text('correct_answer')}>
          {[true, false].map((value) => (
            <Button
              className={'Button' + (isTrue === value ? ' Button--primary' : '')}
              role="radio"
              aria-checked={isTrue === value}
              onclick={() => {
                q.options = [{ id: 'true', text: '', correct: value }];
                this.dirty = true;
              }}
            >
              {app.translator.trans(`ernestdefoe-brainstorm.forum.player.${value ? 'true' : 'false'}`)}
            </Button>
          ))}
        </div>
      );
    }

    const typed = q.type === 'text';
    const max = typed ? 20 : 10;

    return (
      <div className="BrainstormEditor-options">
        <p className="helpText">{t(typed ? 'accepted_help' : q.type === 'multiple' ? 'multiple_help' : 'single_help')}</p>
        {q.options.map((o, i) => (
          <div className={'BrainstormEditor-option' + (o.correct && !typed ? ' is-correct' : '')}>
            {typed ? (
              <Icon name="fas fa-check" className="BrainstormEditor-acceptIcon" />
            ) : (
              <input
                type={q.type === 'multiple' ? 'checkbox' : 'radio'}
                name={'correct-' + q.key}
                checked={o.correct}
                aria-label={text('mark_correct')}
                onchange={() => {
                  if (q.type === 'single') q.options.forEach((other) => (other.correct = other === o));
                  else o.correct = !o.correct;
                }}
              />
            )}
            <input
              className="FormControl"
              maxlength={300}
              aria-label={text(typed ? 'accepted' : 'answer', { number: i + 1 })}
              placeholder={text(typed ? 'accepted' : 'answer', { number: i + 1 })}
              value={o.text}
              oninput={(e: InputEvent) => (o.text = (e.target as HTMLInputElement).value)}
            />
            <Button
              className="Button Button--icon Button--link"
              icon="fas fa-xmark"
              aria-label={text('remove_answer')}
              disabled={q.options.length <= (typed ? 1 : 2)}
              onclick={() => {
                q.options.splice(i, 1);
                if (!typed && !q.options.some((x) => x.correct)) q.options[0].correct = true;
                this.dirty = true;
              }}
            />
          </div>
        ))}
        {q.options.length < max ? (
          <Button
            className="Button Button--link"
            icon="fas fa-plus"
            onclick={() => {
              q.options.push({ text: '', correct: typed });
              this.dirty = true;
            }}
          >
            {t(typed ? 'add_accepted' : 'add_answer')}
          </Button>
        ) : null}
      </div>
    );
  }

  settings(quiz: Editable): Mithril.Children {
    const meta = this.meta!;
    const minutes = quiz.timeLimit ? Math.floor(quiz.timeLimit / 60) : '';
    const seconds = quiz.timeLimit ? quiz.timeLimit % 60 : '';
    const setLimit = (m: number, s: number) => (quiz.timeLimit = m * 60 + s > 0 ? m * 60 + s : null);

    return (
      <div className="BrainstormEditor-settings">
        <h3 className="BrainstormEditor-section">{t('settings')}</h3>

        <div className="Form-group">
          <label>{t('time_limit')}</label>
          <div className="BrainstormEditor-time">
            <input
              className="FormControl"
              type="number"
              min={0}
              max={1440}
              aria-label={text('minutes')}
              value={minutes}
              oninput={(e: InputEvent) => setLimit(Number((e.target as HTMLInputElement).value) || 0, Number(seconds) || 0)}
            />
            <span>{t('minutes')}</span>
            <input
              className="FormControl"
              type="number"
              min={0}
              max={59}
              aria-label={text('seconds')}
              value={seconds}
              oninput={(e: InputEvent) => setLimit(Number(minutes) || 0, Number((e.target as HTMLInputElement).value) || 0)}
            />
            <span>{t('seconds')}</span>
          </div>
          <p className="helpText">{t('time_limit_help')}</p>
        </div>

        {this.number(quiz, 'passMark', 0, 100, 'pass_mark', false)}
        {this.number(quiz, 'maxAttempts', 1, 1000, 'max_attempts', true)}
        {this.number(quiz, 'questionPick', 1, 200, 'question_pick', true)}

        <Switch state={quiz.shuffleQuestions} onchange={(v: boolean) => (quiz.shuffleQuestions = v)}>
          {t('shuffle_questions')}
        </Switch>
        <Switch state={quiz.shuffleOptions} onchange={(v: boolean) => (quiz.shuffleOptions = v)}>
          {t('shuffle_options')}
        </Switch>
        <Switch state={quiz.partialCredit} onchange={(v: boolean) => (quiz.partialCredit = v)}>
          {t('partial_credit')}
        </Switch>
        <p className="helpText">{t('partial_credit_help')}</p>
        <Switch state={quiz.leaderboard} onchange={(v: boolean) => (quiz.leaderboard = v)}>
          {t('leaderboard')}
        </Switch>

        <div className="Form-group">
          <label>{t('reveal')}</label>
          <Select
            value={quiz.reveal}
            options={Object.fromEntries(REVEALS.map((r) => [r, text('reveals.' + r)]))}
            onchange={(v: Reveal) => (quiz.reveal = v)}
          />
          <p className="helpText">{t('reveal_help.' + quiz.reveal)}</p>
        </div>

        <div className="Form-group">
          <label for="brainstorm-opens">{t('opens_at')}</label>
          <input
            id="brainstorm-opens"
            className="FormControl"
            type="datetime-local"
            value={toLocalInput(quiz.opensAt)}
            oninput={(e: InputEvent) => (quiz.opensAt = fromLocalInput((e.target as HTMLInputElement).value))}
          />
          <label for="brainstorm-closes">{t('closes_at')}</label>
          <input
            id="brainstorm-closes"
            className="FormControl"
            type="datetime-local"
            value={toLocalInput(quiz.closesAt)}
            oninput={(e: InputEvent) => (quiz.closesAt = fromLocalInput((e.target as HTMLInputElement).value))}
          />
        </div>

        {meta.canReward && (meta.points || meta.badges) ? (
          <fieldset className="BrainstormEditor-rewards">
            <legend>{t('rewards')}</legend>
            <p className="helpText">{t('rewards_help')}</p>
            {meta.points ? this.number(quiz, 'rewardPoints', -100000, 100000, 'reward_points', false) : null}
            {meta.badges ? (
              <div className="Form-group">
                <label>{t('reward_badge')}</label>
                <Select
                  value={String(quiz.rewardBadgeId ?? '')}
                  options={{ '': text('no_badge'), ...Object.fromEntries(meta.badgeList.map((b) => [String(b.id), b.name])) }}
                  onchange={(v: string) => (quiz.rewardBadgeId = v ? Number(v) : null)}
                />
              </div>
            ) : null}
          </fieldset>
        ) : null}

        {this.error ? (
          <p className="BrainstormEditor-error" role="alert">
            {this.error}
          </p>
        ) : null}

        <div className="BrainstormEditor-actions">
          <Button className="Button" loading={this.saving} onclick={() => this.save('draft')}>
            {t(quiz.status === 'published' ? 'unpublish' : 'save_draft')}
          </Button>
          <Button className="Button Button--primary" loading={this.saving} onclick={() => this.save('published')}>
            {t(quiz.status === 'published' ? 'save' : 'publish')}
          </Button>
        </div>
      </div>
    );
  }

  number(
    quiz: Editable,
    field: 'passMark' | 'maxAttempts' | 'questionPick' | 'rewardPoints',
    min: number,
    max: number,
    label: string,
    blankable: boolean
  ): Mithril.Children {
    const id = 'brainstorm-' + field;

    return (
      <div className="Form-group">
        <label for={id}>{t(label)}</label>
        <input
          id={id}
          className="FormControl"
          type="number"
          min={min}
          max={max}
          placeholder={blankable ? text('unlimited') : undefined}
          value={quiz[field] ?? ''}
          oninput={(e: InputEvent) => {
            const raw = (e.target as HTMLInputElement).value;
            (quiz as any)[field] = raw === '' ? (blankable ? null : 0) : Math.max(min, Math.min(max, Math.round(Number(raw)) || 0));
          }}
        />
        <p className="helpText">{t(label + '_help')}</p>
      </div>
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

  /** Changing type keeps what can be kept: the wording, and the answers where they still fit. */
  retype(q: EditableQuestion, type: QuestionType) {
    const fresh = blankQuestion(type);
    if ((type === 'single' || type === 'multiple') && (q.type === 'single' || q.type === 'multiple') && q.options.length >= 2) {
      if (type === 'single') {
        const first = q.options.findIndex((o) => o.correct);
        q.options.forEach((o, i) => (o.correct = i === Math.max(0, first)));
      }
    } else {
      q.options = fresh.options;
    }
    q.type = type;
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
