<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$topicId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare("SELECT t.*, g.name AS group_name, g.image_path, g.owner_id, g.visibility_status, u.display_name AS author_name, u.bio AS author_bio, u.avatar_path AS author_avatar FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id INNER JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.deleted_at IS NULL AND g.deleted_at IS NULL");
$stmt->execute([$topicId]);
$topic = $stmt->fetch();
if (!$topic) {
    http_response_code(404);
    exit('主题不存在或已删除');
}
$user = current_user();
$group = ['id' => $topic['group_id'], 'owner_id' => $topic['owner_id'], 'visibility_status' => $topic['visibility_status']];
if (!group_access($group, $user)) {
    http_response_code(403);
    exit('只有已加入的成员可以阅读私有小组主题');
}
$manager = $user && group_is_manager($group, $user);
$canWrite = $user && group_can_write($group, $user) && !$topic['is_locked'];
$page = page_number();
$sort = ($_GET['sort'] ?? '') === 'desc' ? 'desc' : 'asc';
$authorOnly = ($_GET['author'] ?? '') === '1';
$where = 'r.topic_id = ? AND r.deleted_at IS NULL';
$params = [$topicId];
if ($authorOnly) {
    $where .= ' AND r.user_id = ?';
    $params[] = $topic['user_id'];
}
$stmt = db()->prepare('SELECT COUNT(*) FROM replies r WHERE ' . $where);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$stmt = db()->prepare("SELECT r.*, u.display_name, u.bio, u.avatar_path, p.content AS parent_content, pu.display_name AS parent_author FROM replies r INNER JOIN users u ON u.id = r.user_id LEFT JOIN replies p ON p.id = r.parent_id AND p.topic_id = r.topic_id AND p.deleted_at IS NULL LEFT JOIN users pu ON pu.id = p.user_id WHERE " . $where . ' ORDER BY r.id ' . strtoupper($sort) . ' LIMIT 20 OFFSET ' . ($page - 1) * 20);
$stmt->execute($params);
$replies = $stmt->fetchAll();
$quote = null;
$quoteId = filter_input(INPUT_GET, 'reply_to', FILTER_VALIDATE_INT) ?: 0;
if ($quoteId && $canWrite) {
    $stmt = db()->prepare('SELECT r.id, u.display_name FROM replies r INNER JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.topic_id = ? AND r.deleted_at IS NULL');
    $stmt->execute([$quoteId, $topicId]);
    $quote = $stmt->fetch();
}
render_header($topic['title'], 'groups', true);
?>
<div id="main" class="mainWrapper"><div class="columns columns-center">
    <div id="columnInSubjectA" class="column column-main">
        <div id="pageHeader"><h1><span><a href="<?php 
echo e(url('group.php', ['id' => $topic['group_id']]));
?>"><img src="<?php 
echo e(url(group_image($topic)));
?>" class="avatar ll" width="32" alt=""><?php 
echo e($topic['group_name']);
?></a> » 讨论</span><br><?php 
echo e($topic['title']);
if ($topic['is_locked']) {
    ?><span class="tag">已锁定</span><?php 
}
?></h1></div><hr class="board">
        <div class="postTopic light_odd clearit discussion-post">
            <div class="post_actions re_info"><small>#1 · <?php 
echo e(format_time($topic['created_at']));
?></small>
                <?php 
if ($manager || $user && (int) $topic['user_id'] === (int) $user['id']) {
    ?>
                <a href="<?php 
    echo e(url('edit.php', ['type' => 'topic', 'id' => $topicId]));
    ?>">编辑</a>
                <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>" class="inline-form"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="<?php 
    echo (int) $topicId;
    ?>"><button class="text-button" data-confirm="删除此主题和所有回复？">删除</button></form>
                <?php 
}
?>
            </div>
            <?php 
echo avatar_link((int) $topic['user_id'], $topic['author_name'], 48, $topic['author_avatar'] ?? null);
?>
            <div class="inner"><strong><a class="l" href="<?php 
echo e(url('user.php', ['id' => $topic['user_id']]));
?>"><?php 
echo e($topic['author_name']);
?></a></strong> <span class="sign tip_j"><?php 
echo e($topic['author_bio']);
?></span><div class="topic_content markdown-body"><?php 
echo markdown_to_html($topic['content']);
?></div><div class="topic_actions"><a href="#reply_wrapper"><?php 
echo (int) $total;
?> 回复</a></div></div>
        </div>
        <div class="reply-sort"><a href="<?php 
echo e(url('topic.php', ['id' => $topicId, 'sort' => 'asc']));
?>">顺序</a> / <a href="<?php 
echo e(url('topic.php', ['id' => $topicId, 'sort' => 'desc']));
?>">倒序</a> / <a href="<?php 
echo e(url('topic.php', ['id' => $topicId, 'author' => '1']));
?>">只看楼主</a></div>
        <div id="comment_list" class="comment-list commentList borderNeue">
            <?php 
foreach ($replies as $index => $reply) {
    ?>
            <div class="row row_reply <?php 
    echo $index % 2 ? 'light_odd' : 'light_even';
    ?> clearit discussion-post" id="reply-<?php 
    echo (int) $reply['id'];
    ?>">
                <div class="post_actions re_info"><small>#<?php 
    echo (int) ($sort === 'asc' ? ($page - 1) * 20 + $index + 2 : $total - ($page - 1) * 20 - $index + 1);
    ?> · <?php 
    echo e(format_time($reply['created_at']));
    ?></small>
                    <?php 
    if ($canWrite) {
        ?><a href="<?php 
        echo e(url('topic.php', ['id' => $topicId, 'reply_to' => $reply['id'], 'page' => $page]));
        ?>#reply_wrapper">回复</a><?php 
    }
    ?>
                    <?php 
    if ($manager || $user && (int) $reply['user_id'] === (int) $user['id']) {
        ?>
                    <a href="<?php 
        echo e(url('edit.php', ['type' => 'reply', 'id' => $reply['id']]));
        ?>">编辑</a>
                    <form method="post" action="<?php 
        echo e(url('actions.php'));
        ?>" class="inline-form"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="delete_reply"><input type="hidden" name="reply_id" value="<?php 
        echo (int) $reply['id'];
        ?>"><button class="text-button" data-confirm="确认删除这条回复？">删除</button></form>
                    <?php 
    }
    ?>
                </div>
                <?php 
    echo avatar_link((int) $reply['user_id'], $reply['display_name'], 40, $reply['avatar_path'] ?? null);
    ?>
                <div class="inner"><strong><a class="l" href="<?php 
    echo e(url('user.php', ['id' => $reply['user_id']]));
    ?>"><?php 
    echo e($reply['display_name']);
    ?></a></strong> <span class="sign tip_j"><?php 
    echo e($reply['bio']);
    ?></span>
                    <?php 
    if ($reply['parent_id']) {
        ?><blockquote class="reply-quote"><?php 
        echo $reply['parent_author'] ? '回复 ' . e($reply['parent_author']) . '：' . e(excerpt(markdown_plain((string) $reply['parent_content']), 180)) : '引用的回复已删除';
        ?></blockquote><?php 
    }
    ?>
                    <div class="reply_content markdown-body"><?php 
    echo markdown_to_html($reply['content']);
    ?></div>
                </div>
            </div>
            <?php 
}
?>
            <?php 
if (!$replies) {
    ?><div class="empty">还没有回复。</div><?php 
}
?>
        </div>
        <?php 
paginate('topic.php', ['id' => $topicId, 'sort' => $sort, 'author' => $authorOnly ? '1' : '0'], $page, $total);
?>
        <div id="reply_wrapper" class="light_odd app-panel">
            <?php 
if ($canWrite) {
    ?>
            <h2 class="reply_title"><?php 
    echo e($user['display_name']);
    ?> / 添加新回复</h2>
            <?php 
    if ($quote) {
        ?><p class="tip">回复 <?php 
        echo e($quote['display_name']);
        ?> <a href="<?php 
        echo e(url('topic.php', ['id' => $topicId]));
        ?>#reply_wrapper">取消引用</a><br><br></p><?php 
    }
    ?>
            <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>">
                <?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="reply_topic"><input type="hidden" name="topic_id" value="<?php 
    echo (int) $topicId;
    ?>"><input type="hidden" name="parent_id" value="<?php 
    echo (int) ($quote['id'] ?? 0);
    ?>">
                <div class="form-row"><label class="sr-only" for="content">回复内容</label><textarea id="content" name="content" rows="6" maxlength="30000" required data-markdown></textarea><span class="hint"><?php 
    echo markdown_editor_hint();
    ?></span></div>
                <button class="inputBtn">加上去</button><span class="hint" id="reply_editor_hint">Ctrl+Enter 快捷提交</span>
            </form>
            <?php 
} elseif ($topic['is_locked']) {
    ?><p class="tip">主题已锁定，暂停新回复。</p>
            <?php 
} else {
    ?><p class="tip">登录并加入小组后可以回复。<a href="<?php 
    echo e(url('group.php', ['id' => $topic['group_id']]));
    ?>">前往小组</a></p><?php 
}
?>
        </div>
    </div>
    <div id="columnInSubjectB" class="column column-side-md">
        <div class="menu_inner"><a class="l" href="<?php 
echo e(url('group.php', ['id' => $topic['group_id']]));
?>">/ 返回<?php 
echo e($topic['group_name']);
?>小组</a><?php 
if ($user && group_can_write($group, $user)) {
    ?><br><a class="l" href="<?php 
    echo e(url('new_topic.php', ['group_id' => $topic['group_id']]));
    ?>">/ 发表新主题</a><?php 
}
?></div>
        <?php 
if ($manager) {
    ?>
        <div class="SidePanel"><h2>主题管理</h2><form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="moderate_topic"><input type="hidden" name="topic_id" value="<?php 
    echo (int) $topicId;
    ?>"><p><label><input type="checkbox" name="is_pinned" value="1" <?php 
    echo $topic['is_pinned'] ? 'checked' : '';
    ?>> 置顶</label></p><p><label><input type="checkbox" name="is_locked" value="1" <?php 
    echo $topic['is_locked'] ? 'checked' : '';
    ?>> 锁定回复</label></p><button class="inputBtn">保存</button></form></div>
        <?php 
}
?>
    </div>
</div></div>
<?php 
render_footer();
