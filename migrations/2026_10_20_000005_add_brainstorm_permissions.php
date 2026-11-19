<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Anyone may look at quizzes, members may take them, moderators write and
// manage them. Attaching badges and points stays with admins (who hold every
// permission): a reward is the forum's to give, not a quiz author's.
return Migration::addPermissions([
    'brainstorm.view' => Group::GUEST_ID,
    'brainstorm.take' => Group::MEMBER_ID,
    'brainstorm.create' => Group::MODERATOR_ID,
    'brainstorm.manage' => Group::MODERATOR_ID,
]);
