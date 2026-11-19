import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import type { EditableOption, EditableQuestion, QuestionType } from '../../common/api';
import type Sortable from '../utils/sortable';
import { hasMediaLibrary, pickImage } from '../utils/mediaLibrary';
import { TYPES, t, text } from './model';

export interface QuestionEditorAttrs {
  question: EditableQuestion;
  index: number;
  count: number;
  sorter: Sortable<EditableQuestion>;
  /** Something changed that no input or change event announces (a button). */
  changed: () => void;
  retype: (type: QuestionType) => void;
  duplicate: () => void;
  remove: () => void;
}

/** One question in the quiz editor: its wording, image, answers, points and explanation. */
export default class QuestionEditor extends Component<QuestionEditorAttrs> {
  view(): Mithril.Children {
    const q = this.attrs.question;
    const id = `brainstorm-q-${q.key}`;

    return (
      <li className="BrainstormEditor-question">
        {this.head()}
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
          {this.image()}
          {q.type === 'boolean' ? this.trueFalse() : this.options()}
          {this.footer(id)}
        </div>
      </li>
    );
  }

  head(): Mithril.Children {
    const { question: q, index: i } = this.attrs;
    const summary = q.body
      .split('\n')[0]
      .replace(/[*_`~#>[\]]/g, '')
      .trim();

    return (
      <div className="BrainstormEditor-questionHead">
        <span className="BrainstormEditor-handle" aria-label={text('drag', { number: i + 1 })} {...this.attrs.sorter.handle(i)}>
          <Icon name="fas fa-grip-vertical" />
        </span>
        <span className="BrainstormEditor-number">{i + 1}</span>
        <span className="BrainstormEditor-summary">{summary || text('untitled')}</span>
        <Select
          className="BrainstormEditor-type"
          aria-label={text('type')}
          value={q.type}
          options={Object.fromEntries(TYPES.map((type) => [type, text('types.' + type)]))}
          onchange={(type: QuestionType) => this.attrs.retype(type)}
        />
        <Button
          className="Button Button--icon"
          icon="far fa-copy"
          aria-label={text('duplicate')}
          title={text('duplicate')}
          onclick={this.attrs.duplicate}
        />
        <Button
          className="Button Button--icon"
          icon="fas fa-trash"
          aria-label={text('remove')}
          title={text('remove')}
          disabled={this.attrs.count < 2}
          onclick={this.attrs.remove}
        />
      </div>
    );
  }

  image(): Mithril.Children {
    const q = this.attrs.question;
    const pick = () =>
      pickImage((url) => {
        q.imageUrl = url;
        this.attrs.changed();
      });

    return [
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
          <Button className="Button" icon="far fa-image" onclick={pick}>
            {t('pick_image')}
          </Button>
        ) : null}
      </div>,
      q.imageUrl && /^(https?:\/\/|\/(?![\/\\]))/i.test(q.imageUrl) ? (
        <img className="BrainstormEditor-preview" src={q.imageUrl} alt="" loading="lazy" referrerpolicy="no-referrer" />
      ) : null,
    ];
  }

  trueFalse(): Mithril.Children {
    const q = this.attrs.question;
    const isTrue = !!q.options.find((o) => o.id === 'true')?.correct;
    const choose = (value: boolean) => {
      q.options = [{ id: 'true', text: '', correct: value }];
      this.attrs.changed();
    };

    return (
      <div className="BrainstormEditor-boolean" role="radiogroup" aria-label={text('correct_answer')}>
        {[true, false].map((value) => (
          <Button
            className={'Button' + (isTrue === value ? ' Button--primary' : '')}
            role="radio"
            aria-checked={isTrue === value}
            onclick={() => choose(value)}
          >
            {app.translator.trans(`ernestdefoe-brainstorm.forum.player.${value ? 'true' : 'false'}`)}
          </Button>
        ))}
      </div>
    );
  }

  options(): Mithril.Children {
    const q = this.attrs.question;
    const typed = q.type === 'text';
    const add = () => {
      q.options.push({ text: '', correct: typed });
      this.attrs.changed();
    };

    return (
      <div className="BrainstormEditor-options">
        <p className="helpText">{t(typed ? 'accepted_help' : q.type === 'multiple' ? 'multiple_help' : 'single_help')}</p>
        {q.options.map((o, i) => this.option(o, i))}
        {q.options.length < (typed ? 20 : 10) ? (
          <Button className="Button Button--link" icon="fas fa-plus" onclick={add}>
            {t(typed ? 'add_accepted' : 'add_answer')}
          </Button>
        ) : null}
      </div>
    );
  }

  option(o: EditableOption, i: number): Mithril.Children {
    const q = this.attrs.question;
    const typed = q.type === 'text';
    const mark = () => {
      if (q.type === 'single') q.options.forEach((other) => (other.correct = other === o));
      else o.correct = !o.correct;
    };
    const drop = () => {
      q.options.splice(i, 1);
      if (!typed && !q.options.some((x) => x.correct)) q.options[0].correct = true;
      this.attrs.changed();
    };

    return (
      <div className={'BrainstormEditor-option' + (o.correct && !typed ? ' is-correct' : '')}>
        {typed ? (
          <Icon name="fas fa-check" className="BrainstormEditor-acceptIcon" />
        ) : (
          <input
            type={q.type === 'multiple' ? 'checkbox' : 'radio'}
            name={'correct-' + q.key}
            checked={o.correct}
            aria-label={text('mark_correct')}
            onchange={mark}
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
          onclick={drop}
        />
      </div>
    );
  }

  footer(id: string): Mithril.Children {
    const q = this.attrs.question;

    return [
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
      </div>,
      <label className="BrainstormEditor-explanationLabel" for={id + '-why'}>
        {t('explanation')}
      </label>,
      <textarea
        id={id + '-why'}
        className="FormControl"
        rows={2}
        maxlength={5000}
        placeholder={text('explanation_placeholder')}
        value={q.explanation}
        oninput={(e: InputEvent) => (q.explanation = (e.target as HTMLTextAreaElement).value)}
      />,
    ];
  }
}
