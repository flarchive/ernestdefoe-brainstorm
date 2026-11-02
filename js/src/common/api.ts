import app from 'flarum/common/app';

export type QuestionType = 'single' | 'multiple' | 'boolean' | 'text';
export type Reveal = 'score' | 'mistakes' | 'answers' | 'answers_always';

export interface Person {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  slug: string;
}

export interface Badge {
  id?: number;
  name: string;
  icon: string;
  color: string;
  background: string;
}

export interface EditableOption {
  id?: string;
  text: string;
  correct: boolean;
}

export interface EditableQuestion {
  id?: number;
  type: QuestionType;
  body: string;
  imageUrl: string | null;
  points: number;
  explanation: string;
  options: EditableOption[];
  /** Client-only key, so Mithril keeps each row's DOM as rows move. */
  key?: string;
}

export interface Editable {
  id?: number;
  title: string;
  description: string;
  status: 'draft' | 'published';
  timeLimit: number | null;
  passMark: number;
  maxAttempts: number | null;
  questionPick: number | null;
  shuffleQuestions: boolean;
  shuffleOptions: boolean;
  partialCredit: boolean;
  reveal: Reveal;
  leaderboard: boolean;
  opensAt: string | null;
  closesAt: string | null;
  rewardPoints: number;
  rewardBadgeId: number | null;
  questions: EditableQuestion[];
}

export interface Quiz {
  id: number;
  slug: string;
  title: string;
  descriptionHtml: string | null;
  status: 'draft' | 'published';
  timeLimit: number | null;
  passMark: number;
  maxAttempts: number | null;
  questionCount: number;
  reveal: Reveal;
  leaderboard: boolean;
  opensAt: string | null;
  closesAt: string | null;
  isOpen: boolean;
  rewardPoints: number;
  rewardBadge: Badge | null;
  attemptCount: number;
  passCount: number;
  publishedAt: string | null;
  author: Person | null;
  canEdit: boolean;
  canTake: boolean;
  me: {
    attemptsUsed: number;
    attemptsLeft: number | null;
    openAttemptId: number | null;
    best: { attemptId: number; percent: number; duration: number; passed: boolean } | null;
  } | null;
  edit?: Editable;
}

export interface PaperQuestion {
  id: number;
  type: QuestionType;
  bodyHtml: string | null;
  imageUrl: string | null;
  points: number;
  options: { id: string; text: string }[];
}

export type Answer = string | string[];

export interface Paper {
  id: number;
  quizId: number;
  finished: false;
  practice: boolean;
  deadlineAt: string | null;
  secondsLeft: number | null;
  questions: PaperQuestion[];
  answers: Record<string, Answer>;
}

export interface ReviewQuestion {
  id: number;
  type: QuestionType;
  bodyHtml: string | null;
  imageUrl: string | null;
  points: number;
  earned: number;
  options: { id: string; text: string; chosen: boolean; correct?: boolean }[];
  typed: string | null;
  accepted?: string[] | null;
  explanationHtml?: string | null;
}

export interface Result {
  id: number;
  quizId: number;
  finished: true;
  score: number;
  maxScore: number;
  percent: number;
  passed: boolean;
  passMark: number;
  duration: number;
  expired: boolean;
  practice: boolean;
  attemptsUsed: number;
  attemptsLeft: number | null;
  answersShown: boolean;
  answersLater: boolean;
  review: ReviewQuestion[] | null;
}

export interface Row {
  rank: number;
  user: Person;
  percent?: number;
  duration?: number;
  attempts?: number;
  passed?: number | boolean;
  average?: number;
}

export interface Standing {
  rank: number;
  percent?: number;
  duration?: number;
  passed?: number | boolean;
  average?: number;
}

const api = (path: string) => app.forum.attribute<string>('apiUrl') + path;

function call<T>(method: string, path: string, body?: object, params?: Record<string, unknown>): Promise<T> {
  return app.request<T>({ method, url: api(path), body: body ? { data: body } : undefined, params });
}

export const listQuizzes = (params: Record<string, unknown>) =>
  call<{ data: Quiz[]; meta: { canCreate: boolean; next: number | null } }>('GET', '/quizzes', undefined, params);
export const showQuiz = (id: number, edit = false) => call<{ data: Quiz }>('GET', `/quizzes/${id}`, undefined, edit ? { edit: 1 } : undefined);
export const saveQuiz = (data: Editable, id?: number) => call<{ data: Quiz }>(id ? 'PATCH' : 'POST', id ? `/quizzes/${id}` : '/quizzes', data);
export const deleteQuiz = (id: number) => call<void>('DELETE', `/quizzes/${id}`);
export const editorMeta = () => call<{ data: { canReward: boolean; points: boolean; badges: boolean; badgeList: Badge[] } }>('GET', '/quiz-editor');
export const startAttempt = (quizId: number) => call<{ data: Paper }>('POST', `/quizzes/${quizId}/attempts`);
export const showAttempt = (id: number) => call<{ data: Paper | Result }>('GET', `/quiz-attempts/${id}`);
export const saveAnswers = (id: number, answers: Record<string, Answer>) =>
  call<{ data: { finished: boolean; secondsLeft: number | null } }>('PATCH', `/quiz-attempts/${id}`, { answers });
export const submitAttempt = (id: number, answers: Record<string, Answer>) =>
  call<{ data: Result }>('POST', `/quiz-attempts/${id}/submit`, { answers });
export const leaderboard = (quizId?: number, limit?: number) =>
  call<{ data: Row[]; meta: { me: Standing | null; off?: boolean } }>(
    'GET',
    quizId ? `/quizzes/${quizId}/leaderboard` : '/quiz-leaderboard',
    undefined,
    limit ? { limit } : undefined
  );
export const results = (quizId: number, offset = 0) =>
  call<{
    data: (Result & { user: Person | null; submittedAt: string })[];
    meta: { next: number | null; questions: { id: number; text: string; asked: number; correct: number }[] | null };
  }>('GET', `/quizzes/${quizId}/results`, undefined, offset ? { offset } : undefined);
export const resetMember = (quizId: number, userId: number) => call<void>('DELETE', `/quizzes/${quizId}/results/${userId}`);

/** "/quizzes/12-capitals" */
export function quizUrl(quiz: { id: number; slug: string }): string {
  return app.route('brainstorm.quiz', { id: quiz.id + (quiz.slug ? '-' + quiz.slug : '') });
}
