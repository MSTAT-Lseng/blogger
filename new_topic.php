<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$groupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare('SELECT * FROM discussion_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$groupId]);
$group = $stmt->fetch();
if (!$group || !group_can_write($group, $user)) {
    http_response_code(403);
    exit('只有小组成员可以发帖。');
}
render_header('发表新主题', 'groups');
?>
<div id="main" class="mainWrapper"><div class="columns columns-center"><div class="column column-main"><div class="app-panel"><h1>在「<?php 
echo e($group['name']);
?>」发表新主题</h1><form method="post" action="<?php 
echo e(url('actions.php'));
?>"><?php 
echo csrf_field();
?><input type="hidden" name="action" value="create_topic"><input type="hidden" name="group_id" value="<?php 
echo (int) $groupId;
?>"><div class="form-row"><label for="title">标题</label><input id="title" class="new_topic_title_input" name="title" type="text" minlength="2" maxlength="180" required autofocus></div><div class="form-row"><label for="content">正文</label><textarea id="content" name="content" maxlength="30000" required data-markdown></textarea><span class="hint"><?php 
echo markdown_editor_hint();
?></span></div><button class="button primary" type="submit">发表主题</button><a class="button" href="<?php 
echo e(url('group.php', ['id' => $groupId]));
?>">取消</a></form></div></div></div></div>
<?php 
render_footer();
