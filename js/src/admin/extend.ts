import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

/*
 * Brainstorm's admin is its permissions and two settings; quizzes themselves
 * are written and managed on the forum, where they are played.
 */
const t = (key: string) => app.translator.trans(`ernestdefoe-brainstorm.admin.${key}`);

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'ernestdefoe-brainstorm.show_nav',
      type: 'boolean',
      label: t('settings.show_nav'),
      help: t('settings.show_nav_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-brainstorm.editors_count',
      type: 'boolean',
      label: t('settings.editors_count'),
      help: t('settings.editors_count_help'),
    }))
    .permission(() => ({ icon: 'fas fa-graduation-cap', label: t('permissions.view'), permission: 'brainstorm.view', allowGuest: true }), 'view')
    .permission(() => ({ icon: 'fas fa-play', label: t('permissions.take'), permission: 'brainstorm.take' }), 'reply')
    .permission(() => ({ icon: 'fas fa-pen-to-square', label: t('permissions.create'), permission: 'brainstorm.create' }), 'start')
    .permission(() => ({ icon: 'fas fa-graduation-cap', label: t('permissions.manage'), permission: 'brainstorm.manage' }), 'moderate')
    .permission(() => ({ icon: 'fas fa-award', label: t('permissions.reward'), permission: 'brainstorm.reward' }), 'moderate'),
];
