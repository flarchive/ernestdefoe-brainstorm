import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe-brainstorm', () => {
  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-brainstorm', [
    { key: 'brainstormQuizzes', icon: 'fas fa-graduation-cap', title: () => app.translator.trans('ernestdefoe-brainstorm.admin.widgets.quizzes') },
  ]);
});
