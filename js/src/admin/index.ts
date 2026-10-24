import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

/*
 * Brainstorm's admin is its permissions and one setting; quizzes themselves
 * are written and managed on the forum, where they are played.
 */
app.initializers.add('ernestdefoe-brainstorm', () => {
  const t = (key: string) => app.translator.trans(`ernestdefoe-brainstorm.admin.${key}`);

  app.registry
    .for('ernestdefoe-brainstorm')
    .registerSetting({
      setting: 'ernestdefoe-brainstorm.show_nav',
      type: 'boolean',
      label: t('settings.show_nav'),
    })
    .registerPermission({ icon: 'fas fa-graduation-cap', label: t('permissions.view'), permission: 'brainstorm.view', allowGuest: true }, 'view')
    .registerPermission({ icon: 'fas fa-play', label: t('permissions.take'), permission: 'brainstorm.take' }, 'reply')
    .registerPermission({ icon: 'fas fa-pen-to-square', label: t('permissions.create'), permission: 'brainstorm.create' }, 'start')
    .registerPermission({ icon: 'fas fa-graduation-cap', label: t('permissions.manage'), permission: 'brainstorm.manage' }, 'moderate')
    .registerPermission({ icon: 'fas fa-award', label: t('permissions.reward'), permission: 'brainstorm.reward' }, 'moderate');

  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-brainstorm', [
    { key: 'brainstormQuizzes', icon: 'fas fa-graduation-cap', title: () => t('widgets.quizzes') },
  ]);
});
