<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$viewer = current_user();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: $viewer['id'] ?? 0;
if ($id < 1 && !$viewer) {
    redirect('login.php');
}
$stmt = db()->prepare('SELECT id, username, display_name, bio, avatar_path, status, created_at FROM users WHERE id = ?');
$stmt->execute([$id]);
$profile = $stmt->fetch();
if (!$profile) {
    http_response_code(404);
    exit('用户不存在。');
}
$where = "t.user_id = ? AND t.deleted_at IS NULL AND g.deleted_at IS NULL";
$params = [$id];
if (!$viewer || $viewer['role'] !== 'admin') {
    $where .= " AND (g.visibility_status = 'public'";
    if ($viewer) {
        $where .= " OR g.owner_id = ? OR EXISTS (SELECT 1 FROM group_members m WHERE m.group_id = g.id AND m.user_id = ? AND m.status = 'approved')";
        $params[] = $viewer['id'];
        $params[] = $viewer['id'];
    }
    $where .= ')';
}
$stmt = db()->prepare('SELECT COUNT(*) FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE ' . $where);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$page = page_number();
$stmt = db()->prepare('SELECT t.id, t.title, t.created_at, g.name AS group_name FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE ' . $where . ' ORDER BY t.id DESC LIMIT 20 OFFSET ' . ($page - 1) * 20);
$stmt->execute($params);
$topics = $stmt->fetchAll();
render_header($profile['display_name'], 'account');
?>
<div id="main" class="mainWrapper"><div class="columns clearit"><div id="columnA" class="column">
    <div class="app-panel user-profile clearit"><div class="user-avatar"><?php 
echo avatar_image($profile['avatar_path'] ?? null, $profile['display_name'], 55);
?></div>
    <div id="profile_info_area">
    <h1 id="user_name_title"><?php 
echo e($profile['display_name']);
?></h1><p class="tip">@<?php 
echo e($profile['username']);
?> · <?php 
echo e(format_time($profile['created_at']));
?> 加入<?php 
if ($profile['status'] !== 'active') {
    ?> · 账号已停用<?php 
}
?></p>
        <p id="profile_bio"><?php 
echo nl2br(e($profile['bio']));
?></p>
        <?php 
if ($viewer && (int) $viewer['id'] === (int) $id) {
    ?><a class="button" id="modify_profile_password_btn" href="<?php 
    echo e(url('settings.php'));
    ?>">账户设置</a><?php 
}
?></div></div>
    <br><h2 class="title">发表的话题</h2><ul class="side-list"><?php 
foreach ($topics as $topic) {
    ?><li><a class="l" href="<?php 
    echo e(url('topic.php', ['id' => $topic['id']]));
    ?>"><?php 
    echo e($topic['title']);
    ?></a> <small class="grey"><?php 
    echo e($topic['group_name']);
    ?> · <?php 
    echo e(format_time($topic['created_at']));
    ?></small></li><?php 
}
if (!$topics) {
    ?><li class="empty">暂无可见话题。</li><?php 
}
?></ul><?php 
paginate('user.php', ['id' => $id], $page, $total);
?>
</div><div id="columnB" class="column"><div class="menu_inner"><a href="<?php 
echo e(url('index.php'));
?>" class="l">/ 返回小组首页</a><?php 
if ($viewer && (int) $viewer['id'] === (int) $id) {
    ?><br><a href="<?php 
    echo e(url('inbox.php'));
    ?>" class="l">/ 我的收件箱（<?php 
    echo (int) inbox_unread_badge((int) $viewer['id']);
    ?>）</a><?php 
}
?></div></div></div>
<?php 
render_footer();
