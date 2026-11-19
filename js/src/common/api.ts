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

/*
 * Quizzes and attempts are JSON:API resources. The pages work with plain
 * objects, so documents are unwrapped here, in one place: a resource becomes
 * its id and attributes, and a quiz's included writer becomes its `author`.
 */
interface ResourceObject {
  type: string;
  id: string;
  attributes: Record<string, any>;
  relationships?: Record<string, { data: { type: string; id: string } | null }>;
}

interface Document {
  data?: ResourceObject | ResourceObject[];
  included?: ResourceObject[];
  meta?: Record<string, any>;
  links?: { next?: string };
}

function person(user: ResourceObject | undefined): Person | null {
  if (!user) return null;
  const a = user.attributes;

  return { id: Number(user.id), username: a.username, displayName: a.displayName, avatarUrl: a.avatarUrl ?? null, slug: a.slug };
}

function flatten<T>(resource: ResourceObject, included: ResourceObject[] = []): T {
  const author = resource.relationships?.user?.data;
  const out: Record<string, any> = { id: Number(resource.id), ...resource.attributes };
  if (resource.type === 'quizzes') out.author = person(included.find((r) => r.type === 'users' && author && r.id === author.id));

  return out as T;
}

function request(method: string, path: string, body?: object, params?: Record<string, unknown>): Promise<Document> {
  return app.request<Document>({ method, url: api(path), body, params });
}

const one = <T>(doc: Document) => ({ data: flatten<T>(doc.data as ResourceObject, doc.included) });
const resource = (type: string, attributes: object, id?: number) => ({ data: { type, attributes, ...(id ? { id: String(id) } : {}) } });

/** The JSON:API way to sort: "popular" is the most-played first, newest first among equals. */
const SORTS: Record<string, string> = { popular: '-attemptCount,-publishedAt' };

export function listQuizzes(params: { filter?: Record<string, string>; sort?: string; page?: { offset?: number } }) {
  const offset = params.page?.offset || 0;
  const page = { offset, total: 0, ...(params.filter?.ids ? { limit: 50 } : {}) };
  const sort = params.sort ? SORTS[params.sort] : undefined;

  return request('GET', '/quizzes', undefined, { filter: params.filter, page, ...(sort ? { sort } : {}) }).then((doc) => {
    const data = (doc.data as ResourceObject[]).map((r) => flatten<Quiz>(r, doc.included));

    return { data, meta: { canCreate: !!app.forum.attribute('canCreateQuizzes'), next: doc.links?.next ? offset + data.length : null } };
  });
}

export const showQuiz = (id: number, edit = false) =>
  request('GET', `/quizzes/${id}`, undefined, edit ? { withEdit: 1 } : undefined).then((doc) => one<Quiz>(doc));

export function saveQuiz(data: Editable, id?: number) {
  const { id: _id, ...attributes } = data;

  return request(id ? 'PATCH' : 'POST', id ? `/quizzes/${id}` : '/quizzes', resource('quizzes', attributes, id)).then((doc) => one<Quiz>(doc));
}

export const deleteQuiz = (id: number) => request('DELETE', `/quizzes/${id}`).then(() => undefined);

export const editorMeta = () =>
  request('GET', '/quizzes/editor').then((doc) => ({
    data: doc.meta as { canReward: boolean; points: boolean; badges: boolean; badgeList: Badge[] },
  }));

export const startAttempt = (quizId: number) =>
  request('POST', '/quiz-attempts', resource('quiz-attempts', { quizId })).then((doc) => one<Paper>(doc));

export const showAttempt = (id: number) => request('GET', `/quiz-attempts/${id}`).then((doc) => one<Paper | Result>(doc));

export const saveAnswers = (id: number, answers: Record<string, Answer>) =>
  request('PATCH', `/quiz-attempts/${id}`, resource('quiz-attempts', { answers }, id)).then((doc) =>
    one<{ finished: boolean; secondsLeft: number | null }>(doc)
  );

export const submitAttempt = (id: number, answers: Record<string, Answer>) =>
  request('PATCH', `/quiz-attempts/${id}`, resource('quiz-attempts', { answers, submit: true }, id)).then((doc) => one<Result>(doc));

export const leaderboard = (quizId?: number, limit?: number) =>
  request('GET', quizId ? `/quizzes/${quizId}/leaderboard` : '/quizzes/leaderboard', undefined, limit ? { page: { limit } } : undefined).then(
    (doc) => {
      const { rows, ...meta } = doc.meta as { rows: Row[]; me: Standing | null; off?: boolean };

      return { data: rows, meta };
    }
  );

export const results = (quizId: number, offset = 0) =>
  request('GET', `/quizzes/${quizId}/results`, undefined, offset ? { page: { offset } } : undefined).then((doc) => {
    const { rows, ...meta } = doc.meta as {
      rows: (Result & { user: Person | null; submittedAt: string })[];
      next: number | null;
      questions: { id: number; text: string; asked: number; correct: number }[] | null;
    };

    return { data: rows, meta };
  });

export const resetMember = (quizId: number, userId: number) => request('DELETE', `/quizzes/${quizId}/results/${userId}`).then(() => undefined);

/**
 * The answers not yet saved, sent as the page goes away. A keepalive request
 * outlives the tab being closed, which an ordinary one does not, and
 * app.request has no keepalive option: so this one is a plain fetch, with the
 * session's CSRF token, to the same endpoint as {@link saveAnswers}.
 */
export function saveAnswersOnLeave(id: number, answers: Record<string, Answer>): void {
  fetch(api(`/quiz-attempts/${id}`), {
    method: 'PATCH',
    keepalive: true,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken || '' },
    body: JSON.stringify(resource('quiz-attempts', { answers }, id)),
  }).catch(() => {
    // Nothing to tell: the page is already going away.
  });
}

/** "/quizzes/12-capitals" */
export function quizUrl(quiz: { id: number; slug: string }): string {
  return app.route('brainstorm.quiz', { id: quiz.id + (quiz.slug ? '-' + quiz.slug : '') });
}
