import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type { Editable, EditableQuestion, QuestionType, Reveal } from '../../common/api';

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.editor.${key}`, params);
export const text = (key: string, params: Record<string, unknown> = {}) => extractText(t(key, params));

export const TYPES: QuestionType[] = ['single', 'multiple', 'boolean', 'text'];
export const REVEALS: Reveal[] = ['score', 'mistakes', 'answers', 'answers_always'];

let keys = 0;
/** A client-only key, so Mithril keeps each question's DOM as questions move. */
export const key = () => 'k' + ++keys;

export function blankQuestion(type: QuestionType = 'single'): EditableQuestion {
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

export function blankQuiz(): Editable {
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
    courseId: null,
    questions: [blankQuestion()],
  };
}

/** ISO from the server ⇄ the value a datetime-local input takes, in the writer's own time zone. */
export function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
export const fromLocalInput = (value: string) => (value ? new Date(value).toISOString() : null);

/** Changing type keeps what can be kept: the wording, and the answers where they still fit. */
export function retype(q: EditableQuestion, type: QuestionType): void {
  const choice = (x: QuestionType) => x === 'single' || x === 'multiple';

  if (choice(type) && choice(q.type) && q.options.length >= 2) {
    if (type === 'single') {
      const first = q.options.findIndex((o) => o.correct);
      q.options.forEach((o, i) => (o.correct = i === Math.max(0, first)));
    }
  } else {
    q.options = blankQuestion(type).options;
  }
  q.type = type;
}
