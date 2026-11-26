import Extend from 'flarum/common/extenders';

/* The pages are their own chunks, fetched the first time one is opened. */
export default [
  new Extend.Routes()
    .add('brainstorm.index', '/quizzes', () => import('./pages/QuizzesPage'))
    .add('brainstorm.new', '/quizzes/new', () => import('./pages/EditorPage'))
    .add('brainstorm.leaderboard', '/quizzes/leaderboard', () => import('./pages/LeaderboardPage'))
    // "new" before :id, or the page for an assessment called "new" would take it.
    .add('brainstorm.assessment.new', '/quizzes/assessments/new', () => import('./pages/AssessmentEditorPage'))
    .add('brainstorm.assessment', '/quizzes/assessments/:id', () => import('./pages/AssessmentPage'))
    .add('brainstorm.assessment.edit', '/quizzes/assessments/:id/edit', () => import('./pages/AssessmentEditorPage'))
    .add('brainstorm.quiz', '/quizzes/:id', () => import('./pages/QuizPage'))
    .add('brainstorm.edit', '/quizzes/:id/edit', () => import('./pages/EditorPage')),
];
