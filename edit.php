<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$type = ($_GET['type'] ?? '') === 'reply' ? 'reply' : 'topic';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($type === 'reply') {
    $stmt = db()->prepare('SELECT r.*, t.id AS topic_id, t.is_locked, t.group_id, g.owner_id, g.visibility_status FROM replies r INNER JOIN topics t ON t.id = r.topic_id INNER JOIN discussion_groups g ON g.id = t.group_id WHERE r.id = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL AND g.deleted_at IS NULL');
} else {
    $stmt = db()->prepare('SELECT t.*, t.id AS topic_id, g.owner_id, g.visibility_status FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE t.id = ? AND t.deleted_at IS NULL AND g.deleted_at IS NULL');
}
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('内容不存在。');
}
$group = ['id' => $record['group_id'], 'owner_id' => $record['owner_id'], 'visibility_status' => $record['visibility_status']];
$manager = group_is_manager($group, $user);
if (!group_access($group, $user) || !$manager && (int) $record['user_id'] !== (int) $user['id'] || $record['is_locked'] && !$manager) {
    http_response_code(403);
    exit('无权编辑，或主题已锁定。');
}
render_header('编辑内容');
?>
<div id="main" class="mainWrapper"><div class="app-panel"><h1>编辑<?php 
echo $type === 'topic' ? '主题' : '回复';
?></h1>
    <form method="post" action="<?php 
echo e(url('actions.php'));
?>"><?php 
echo csrf_field();
?><input type="hidden" name="action" value="<?php 
echo $type === 'topic' ? 'edit_topic' : 'edit_reply';
?>"><input type="hidden" name="<?php 
echo $type === 'topic' ? 'topic_id' : 'reply_id';
?>" value="<?php 
echo (int) $id;
?>">
        <?php 
if ($type === 'topic') {
    ?><div class="form-row"><label for="title">标题</label><input id="title" name="title" value="<?php 
    echo e($record['title']);
    ?>" required minlength="2" maxlength="180"></div><?php 
}
?>
        <div class="form-row"><label for="content">内容</label><textarea id="content" name="content" required maxlength="30000" data-markdown><?php 
echo e($record['content']);
?></textarea><span class="hint"><?php 
echo markdown_editor_hint();
?></span></div>
        <button class="button primary">保存</button><a class="button" href="<?php 
echo e(url('topic.php', ['id' => $record['topic_id']]));
?>">取消</a>
    </form>
</div></div>
<?php 
render_footer();
