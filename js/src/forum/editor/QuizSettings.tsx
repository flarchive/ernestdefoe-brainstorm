import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import type { Badge, Editable, Reveal } from '../../common/api';
import { REVEALS, fromLocalInput, t, text, toLocalInput } from './model';

export interface EditorMeta {
  canReward: boolean;
  points: boolean;
  badges: boolean;
  badgeList: Badge[];
  /** The Syllabus courses this author may limit a quiz to; null while Syllabus isn't enabled. */
  courses: { id: number; title: string }[] | null;
}

export interface QuizSettingsAttrs {
  quiz: Editable;
  meta: EditorMeta;
  saving: boolean;
  error: string | null;
  save: (status: 'draft' | 'published') => void;
}

type NumberField = 'passMark' | 'maxAttempts' | 'questionPick' | 'rewardPoints';

/** The quiz editor's side column: timing, marking, who sees what, when it is open, rewards, and saving. */
export default class QuizSettings extends Component<QuizSettingsAttrs> {
  view(): Mithril.Children {
    const quiz = this.attrs.quiz;

    return (
      <div className="BrainstormEditor-settings">
        <h3 className="BrainstormEditor-section">{t('settings')}</h3>
        {this.timeLimit()}
        {this.number('passMark', 0, 100, 'pass_mark', false)}
        {this.number('maxAttempts', 1, 1000, 'max_attempts', true)}
        {this.number('questionPick', 1, 200, 'question_pick', true)}
        {this.switches()}
        <div className="Form-group">
          <label>{t('reveal')}</label>
          <Select
            value={quiz.reveal}
            options={Object.fromEntries(REVEALS.map((r) => [r, text('reveals.' + r)]))}
            onchange={(v: Reveal) => (quiz.reveal = v)}
          />
          <p className="helpText">{t('reveal_help.' + quiz.reveal)}</p>
        </div>
        {this.dates()}
        {this.course()}
        {this.rewards()}
        {this.actions()}
      </div>
    );
  }

  timeLimit(): Mithril.Children {
    const quiz = this.attrs.quiz;
    const minutes = quiz.timeLimit ? Math.floor(quiz.timeLimit / 60) : '';
    const seconds = quiz.timeLimit ? quiz.timeLimit % 60 : '';
    const setLimit = (m: number, s: number) => (quiz.timeLimit = m * 60 + s > 0 ? m * 60 + s : null);
    const value = (e: InputEvent) => Number((e.target as HTMLInputElement).value) || 0;

    return (
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
            oninput={(e: InputEvent) => setLimit(value(e), Number(seconds) || 0)}
          />
          <span>{t('minutes')}</span>
          <input
            className="FormControl"
            type="number"
            min={0}
            max={59}
            aria-label={text('seconds')}
            value={seconds}
            oninput={(e: InputEvent) => setLimit(Number(minutes) || 0, value(e))}
          />
          <span>{t('seconds')}</span>
        </div>
        <p className="helpText">{t('time_limit_help')}</p>
      </div>
    );
  }

  switches(): Mithril.Children {
    const quiz = this.attrs.quiz;

    return [
      <Switch state={quiz.shuffleQuestions} onchange={(v: boolean) => (quiz.shuffleQuestions = v)}>
        {t('shuffle_questions')}
      </Switch>,
      <Switch state={quiz.shuffleOptions} onchange={(v: boolean) => (quiz.shuffleOptions = v)}>
        {t('shuffle_options')}
      </Switch>,
      <Switch state={quiz.partialCredit} onchange={(v: boolean) => (quiz.partialCredit = v)}>
        {t('partial_credit')}
      </Switch>,
      <p className="helpText">{t('partial_credit_help')}</p>,
      <Switch state={quiz.leaderboard} onchange={(v: boolean) => (quiz.leaderboard = v)}>
        {t('leaderboard')}
      </Switch>,
    ];
  }

  dates(): Mithril.Children {
    const quiz = this.attrs.quiz;
    const value = (e: InputEvent) => fromLocalInput((e.target as HTMLInputElement).value);

    return (
      <div className="Form-group">
        <label for="brainstorm-opens">{t('opens_at')}</label>
        <input
          id="brainstorm-opens"
          className="FormControl"
          type="datetime-local"
          value={toLocalInput(quiz.opensAt)}
          oninput={(e: InputEvent) => (quiz.opensAt = value(e))}
        />
        <label for="brainstorm-closes">{t('closes_at')}</label>
        <input
          id="brainstorm-closes"
          className="FormControl"
          type="datetime-local"
          value={toLocalInput(quiz.closesAt)}
          oninput={(e: InputEvent) => (quiz.closesAt = value(e))}
        />
      </div>
    );
  }

  /** Only members enrolled in a Syllabus course may see and take the quiz. */
  course(): Mithril.Children {
    const { quiz, meta } = this.attrs;
    if (!meta.courses) return null;

    // Keyed as strings ("c12"): numeric keys would be re-sorted ahead of "Everyone".
    const options: Record<string, string> = { '': text('course_everyone') };
    meta.courses.forEach((c) => (options['c' + c.id] = c.title));
    if (quiz.courseId && !options['c' + quiz.courseId]) options['c' + quiz.courseId] = text('course_other');

    return (
      <div className="Form-group">
        <label>{t('course')}</label>
        <Select
          value={quiz.courseId ? 'c' + quiz.courseId : ''}
          options={options}
          onchange={(v: string) => (quiz.courseId = v ? Number(v.slice(1)) : null)}
        />
        <p className="helpText">{t('course_help')}</p>
      </div>
    );
  }

  rewards(): Mithril.Children {
    const { quiz, meta } = this.attrs;
    if (!meta.canReward || !(meta.points || meta.badges)) return null;

    return (
      <fieldset className="BrainstormEditor-rewards">
        <legend>{t('rewards')}</legend>
        <p className="helpText">{t('rewards_help')}</p>
        {meta.points ? this.number('rewardPoints', -100000, 100000, 'reward_points', false) : null}
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
    );
  }

  actions(): Mithril.Children {
    const { quiz, saving, error, save } = this.attrs;
    const published = quiz.status === 'published';

    return [
      error ? (
        <p className="BrainstormEditor-error" role="alert">
          {error}
        </p>
      ) : null,
      <div className="BrainstormEditor-actions">
        <Button className="Button" loading={saving} onclick={() => save('draft')}>
          {t(published ? 'unpublish' : 'save_draft')}
        </Button>
        <Button className="Button Button--primary" loading={saving} onclick={() => save('published')}>
          {t(published ? 'save' : 'publish')}
        </Button>
      </div>,
    ];
  }

  number(field: NumberField, min: number, max: number, label: string, blankable: boolean): Mithril.Children {
    const quiz = this.attrs.quiz;
    const id = 'brainstorm-' + field;
    const set = (raw: string) => {
      (quiz as Record<NumberField, number | null>)[field] =
        raw === '' ? (blankable ? null : 0) : Math.max(min, Math.min(max, Math.round(Number(raw)) || 0));
    };

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
          oninput={(e: InputEvent) => set((e.target as HTMLInputElement).value)}
        />
        <p className="helpText">{t(label + '_help')}</p>
      </div>
    );
  }
}
