<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = current_user();
$requestedView = $_GET['view'] ?? 'discover';
$view = is_string($requestedView) ? $requestedView : 'discover';
if (!in_array($view, ['discover', 'all', 'mine'], true)) {
    $view = 'discover';
}
if ($view === 'mine') {
    $user = require_login();
}
$query = $_GET['q'] ?? '';
$q = is_string($query) ? trim(utf8_substr($query, 0, 100)) : '';
$page = page_number();
$condition = "g.deleted_at IS NULL AND g.visibility_status = 'public'";
$params = [];
if ($view === 'mine') {
    $condition = "g.deleted_at IS NULL AND (g.owner_id = ? OR EXISTS (SELECT 1 FROM group_members my WHERE my.group_id = g.id AND my.user_id = ? AND my.status IN ('approved','pending')))";
    $params = [$user['id'], $user['id']];
}
if ($q !== '') {
    $condition .= ' AND g.name LIKE ?';
    $params[] = '%' . $q . '%';
}
$count = db()->prepare('SELECT COUNT(*) FROM discussion_groups g WHERE ' . $condition);
$count->execute($params);
$totalGroups = (int) $count->fetchColumn();
$groupLimit = $view === 'discover' && $q === '' ? 6 : 20;
$page = min($page, max(1, (int) ceil($totalGroups / $groupLimit)));
$offset = $view === 'discover' && $q === '' ? 0 : ($page - 1) * $groupLimit;
$groupSql = "SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'approved') AS member_count\r\n    FROM discussion_groups g WHERE " . $condition . ' ORDER BY member_count DESC, g.created_at DESC LIMIT ' . $groupLimit . ' OFFSET ' . $offset;
$stmt = db()->prepare($groupSql);
$stmt->execute($params);
$groups = $stmt->fetchAll();
$topicCondition = "g.deleted_at IS NULL AND g.visibility_status = 'public' AND t.deleted_at IS NULL";
$topicParams = [];
if ($q !== '') {
    $topicCondition .= ' AND (t.title LIKE ? OR g.name LIKE ?)';
    $topicParams = ['%' . $q . '%', '%' . $q . '%'];
}
$topicCount = db()->prepare('SELECT COUNT(*) FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE ' . $topicCondition);
$topicCount->execute($topicParams);
$totalTopics = (int) $topicCount->fetchColumn();
$topicPage = min(page_number('topic_page'), max(1, (int) ceil($totalTopics / 20)));
$stmt = db()->prepare("SELECT t.id, t.title, t.user_id, u.display_name, g.id AS group_id, g.name AS group_name,\r\n    (SELECT COUNT(*) FROM replies r WHERE r.topic_id = t.id AND r.deleted_at IS NULL) AS reply_count,\r\n    GREATEST(t.created_at, COALESCE((SELECT MAX(r.created_at) FROM replies r WHERE r.topic_id = t.id AND r.deleted_at IS NULL), t.created_at)) AS last_activity\r\n    FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id INNER JOIN users u ON u.id = t.user_id\r\n    WHERE " . $topicCondition . ' ORDER BY last_activity DESC, t.id DESC LIMIT 20 OFFSET ' . ($topicPage - 1) * 20);
$stmt->execute($topicParams);
$topics = $stmt->fetchAll();
$newGroups = db()->query("SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'approved') AS member_count FROM discussion_groups g WHERE g.visibility_status = 'public' AND g.deleted_at IS NULL ORDER BY g.created_at DESC LIMIT 10")->fetchAll();
render_header('小组', 'groups');
?>
<div id="main" class="mainWrapper">
    <div id="header" class="clearit"><h1>小组</h1></div>
    <div class="directory-bar clearit">
        <ul class="secTab">
            <?php 
foreach (['discover' => '随便看看', 'all' => '所有小组', 'mine' => '参加的小组'] as $key => $label) {
    ?>
            <li><a href="<?php 
    echo e(url('index.php', ['view' => $key]));
    ?>" class="<?php 
    echo $view === $key ? 'selected' : '';
    ?>"><span><?php 
    echo e($label);
    ?></span></a></li>
            <?php 
}
?>
        </ul>
    </div>
    <div class="columns clearit">
        <div id="columnA" class="column">
            <h2 class="title"><?php 
echo $view === 'mine' ? '参加的小组与加入申请' : ($view === 'all' || $q !== '' ? '所有公开小组' : '热门小组');
?></h2>
            <?php 
if (!$groups) {
    ?><div class="empty">暂无符合条件的小组，<a class="l" href="<?php 
    echo e(url('create_group.php'));
    ?>">创建一个新小组</a>。</div><?php 
}
?>
            <ul class="groupsLarge">
                <?php 
foreach ($groups as $group) {
    ?>
                <li><a href="<?php 
    echo e(url('group.php', ['id' => $group['id']]));
    ?>" title="<?php 
    echo e($group['name']);
    ?>"><span class="pictureFrameGroup"><span class="image"><img src="<?php 
    echo e(url(group_image($group)));
    ?>" alt=""></span><span class="overlay"></span></span><?php 
    echo e($group['name']);
    ?></a><br><small class="feed"><?php 
    echo (int) $group['member_count'];
    ?> 位成员</small></li>
                <?php 
}
?>
            </ul>
            <div class="clear"></div>
            <?php 
if ($view !== 'discover' || $q !== '') {
    paginate('index.php', ['view' => $view, 'q' => $q, 'topic_page' => $topicPage], $page, $totalGroups);
}
?>
            <?php 
if ($view !== 'mine') {
    ?>
            <h2 class="home_title">所有公开小组的最新话题</h2>
            <table class="topic_list home-topic-list"><thead><tr><th width="53%">话题</th><th width="20%">小组</th><th width="12%">作者</th><th width="15%">最后回复</th></tr></thead><tbody>
            <?php 
    foreach ($topics as $index => $topic) {
        ?>
                <tr class="<?php 
        echo $index % 2 ? 'even' : 'odd';
        ?>"><td><a href="<?php 
        echo e(url('topic.php', ['id' => $topic['id']]));
        ?>" class="l"><?php 
        echo e($topic['title']);
        ?></a> <small class="grey">(+<?php 
        echo (int) $topic['reply_count'];
        ?>)</small></td><td><a href="<?php 
        echo e(url('group.php', ['id' => $topic['group_id']]));
        ?>"><?php 
        echo e($topic['group_name']);
        ?></a></td><td><a href="<?php 
        echo e(url('user.php', ['id' => $topic['user_id']]));
        ?>"><?php 
        echo e($topic['display_name']);
        ?></a></td><td><small class="grey"><?php 
        echo e(format_time($topic['last_activity']));
        ?></small></td></tr>
            <?php 
    }
    ?>
            <?php 
    if (!$topics) {
        ?><tr><td colspan="4" class="empty">暂无话题</td></tr><?php 
    }
    ?>
            </tbody></table>
            <?php 
    paginate('index.php', ['view' => $view, 'q' => $q, 'page' => $page], $topicPage, $totalTopics, 20, 'topic_page');
    ?>
            <?php 
}
?>
        </div>
        <div id="columnB" class="column">
            <div class="SidePanel png_bg">
                <h2>新成立的小组 | <a href="<?php 
echo e(url('index.php', ['view' => 'all']));
?>">所有小组</a></h2>
                <ul class="groupsSmall">
                    <?php 
foreach ($newGroups as $group) {
    ?>
                    <li><a href="<?php 
    echo e(url('group.php', ['id' => $group['id']]));
    ?>" class="avatar"><span class="groupImage"><img src="<?php 
    echo e(url(group_image($group)));
    ?>" class="avatar groupImage" width="32" alt=""></span></a><div class="inner"><a href="<?php 
    echo e(url('group.php', ['id' => $group['id']]));
    ?>"><?php 
    echo e($group['name']);
    ?></a><br><small class="feed"><?php 
    echo (int) $group['member_count'];
    ?> 位成员</small></div></li>
                    <?php 
}
?>
                    <?php 
if (!$newGroups) {
    ?><li class="empty">暂无公开小组</li><?php 
}
?>
                </ul><div class="clear"></div>
            </div>
            <div class="menu_inner"><a class="l" href="<?php 
echo e(url('index.php', ['view' => 'all']));
?>">/ 所有小组</a><br><a class="l" href="<?php 
echo e(url('index.php', ['view' => 'mine']));
?>">/ 参加的小组</a><br><a class="l" href="<?php 
echo e(url('create_group.php'));
?>">/ 创建一个新小组</a></div>
            <div class="SidePanel"><h2>小组的公开状态</h2><p class="tip">新建的小组默认不公开，组长可分享小组链接邀请成员，或申请公开，经站点管理员审核后在这里展示。</p></div>
        </div>
    </div>
</div>
<?php 
render_footer();
