<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare("SELECT g.*, u.display_name AS owner_name, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'approved') AS member_count FROM discussion_groups g INNER JOIN users u ON u.id = g.owner_id WHERE g.id = ? AND g.deleted_at IS NULL");
$stmt->execute([$groupId]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    exit('小组不存在或已删除。');
}
$user = current_user();
$membership = $user ? group_membership($groupId, (int) $user['id']) : null;
$canRead = group_access($group, $user);
$canWrite = $user && group_can_write($group, $user);
$manager = $user && group_is_manager($group, $user);
$tab = ($_GET['tab'] ?? '') === 'members' ? 'members' : 'overview';
$page = page_number();
$topics = $members = [];
$total = 0;
if ($canRead) {
    if ($tab === 'members') {
        $stmt = db()->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND status = 'approved'");
        $stmt->execute([$groupId]);
        $total = (int) $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT m.role, m.created_at, u.id, u.display_name, u.username FROM group_members m INNER JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND m.status = 'approved' ORDER BY m.created_at ASC, m.user_id ASC LIMIT 20 OFFSET " . ($page - 1) * 20);
        $stmt->execute([$groupId]);
        $members = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM topics WHERE group_id = ? AND deleted_at IS NULL');
        $stmt->execute([$groupId]);
        $total = (int) $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT t.*, u.display_name, (SELECT COUNT(*) FROM replies r WHERE r.topic_id = t.id AND r.deleted_at IS NULL) AS reply_count, GREATEST(t.created_at, COALESCE((SELECT MAX(r.created_at) FROM replies r WHERE r.topic_id = t.id AND r.deleted_at IS NULL), t.created_at)) AS last_activity FROM topics t INNER JOIN users u ON u.id = t.user_id WHERE t.group_id = ? AND t.deleted_at IS NULL ORDER BY t.is_pinned DESC, last_activity DESC, t.id DESC LIMIT 20 OFFSET " . ($page - 1) * 20);
        $stmt->execute([$groupId]);
        $topics = $stmt->fetchAll();
    }
}
$recent = [];
if ($canRead) {
    $stmt = db()->prepare("SELECT u.id, u.display_name FROM group_members m INNER JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND m.status = 'approved' ORDER BY m.created_at DESC LIMIT 8");
    $stmt->execute([$groupId]);
    $recent = $stmt->fetchAll();
}
render_header($group['name'], 'groups');
?>
<div id="main" class="mainWrapper"><div class="columns clearit">
    <div id="columnA" class="column">
        <div class="clearit">
            <ul class="secTab rr"><li><a href="<?php 
echo e(url('group.php', ['id' => $groupId]));
?>" class="<?php 
echo $tab === 'overview' ? 'selected' : '';
?>"><span>讨论</span></a></li><li><a href="<?php 
echo e(url('group.php', ['id' => $groupId, 'tab' => 'members']));
?>" class="<?php 
echo $tab === 'members' ? 'selected' : '';
?>"><span>成员</span></a></li><?php 
if ($manager) {
    ?><li><a href="<?php 
    echo e(url('group_manage.php', ['id' => $groupId]));
    ?>"><span>管理</span></a></li><?php 
}
?></ul>
            <h1 class="SecondaryNavTitle"><?php 
echo e($group['name']);
?></h1>
        </div><hr class="board">
        <?php 
if ($canRead) {
    ?>
        <div class="grp_box group-intro clearit">
            <img src="<?php 
    echo e(url(group_image($group)));
    ?>" alt="" class="port ll">
            <div class="line_detail"><span class="tip"><?php 
    echo nl2br(e($group['description']));
    ?></span><p class="tip">组长：<a href="<?php 
    echo e(url('user.php', ['id' => $group['owner_id']]));
    ?>"><?php 
    echo e($group['owner_name']);
    ?></a>，<?php 
    echo (int) $group['member_count'];
    ?> 位成员 <span class="tag <?php 
    echo e($group['visibility_status']);
    ?>"><?php 
    echo e(visibility_label($group['visibility_status']));
    ?></span></p></div>
        </div>
        <?php 
} else {
    ?>
        <div class="app-panel"><h2>这是一个私有小组</h2><p class="tip">加入申请通过前，小组介绍、讨论和成员列表仅对成员可见。你可以申请加入。</p></div>
        <?php 
}
?>
        <div class="group-actions clearit">
            <?php 
if (!$user) {
    ?>
                <?php 
    $_SESSION['return_to'] = ['group.php', ['id' => $groupId]];
    ?>
                <a class="chiiBtn" href="<?php 
    echo e(url('login.php'));
    ?>"><span>登录后加入这个小组</span></a>
            <?php 
} elseif (!$membership || $membership['status'] === 'rejected') {
    ?>
                <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="join_group"><input type="hidden" name="group_id" value="<?php 
    echo (int) $groupId;
    ?>"><button class="button primary" type="submit"><?php 
    echo $group['visibility_status'] === 'public' ? '加入这个小组' : '申请加入这个小组';
    ?></button></form>
            <?php 
} elseif ($membership['status'] === 'pending') {
    ?>
                <span class="tip">加入申请审核中</span><form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="leave_group"><input type="hidden" name="group_id" value="<?php 
    echo (int) $groupId;
    ?>"><button class="text-button" type="submit">撤回申请</button></form>
            <?php 
} elseif ($membership['status'] === 'blocked') {
    ?><span class="tip">你已被移出该小组，请联系组长。</span>
            <?php 
} elseif ((int) $group['owner_id'] !== (int) $user['id']) {
    ?>
                <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="leave_group"><input type="hidden" name="group_id" value="<?php 
    echo (int) $groupId;
    ?>"><button class="button" type="submit">退出小组</button></form>
            <?php 
}
?>
            <?php 
if ($canWrite) {
    ?><a class="chiiBtn rr" href="<?php 
    echo e(url('new_topic.php', ['group_id' => $groupId]));
    ?>"><span>发表新主题</span></a><?php 
}
?>
        </div>
        <?php 
if ($canRead && $tab === 'members') {
    ?>
        <h2 class="title">/ 小组成员</h2><ul class="members-list">
            <?php 
    foreach ($members as $member) {
        ?><li><a href="<?php 
        echo e(url('user.php', ['id' => $member['id']]));
        ?>"><?php 
        echo e($member['display_name']);
        ?></a><small class="grey"> (<?php 
        echo e($member['username']);
        ?>) · <?php 
        echo e(['owner' => '组长', 'moderator' => '组内管理员', 'member' => '成员'][$member['role']] ?? '成员');
        ?> · <?php 
        echo e(format_time($member['created_at']));
        ?> 加入</small></li><?php 
    }
    ?>
        </ul><?php 
    paginate('group.php', ['id' => $groupId, 'tab' => 'members'], $page, $total);
    ?>
        <?php 
} elseif ($canRead) {
    ?>
        <h2 class="title">小组最新讨论</h2>
        <table class="topic_list home-topic-list"><thead><tr><th width="60%">话题</th><th width="15%">作者</th><th width="25%">最后回复</th></tr></thead><tbody>
            <?php 
    foreach ($topics as $index => $topic) {
        ?>
            <tr class="<?php 
        echo $index % 2 ? 'even' : 'odd';
        ?>"><td><?php 
        if ($topic['is_pinned']) {
            ?><span class="tag">置顶</span><?php 
        }
        if ($topic['is_locked']) {
            ?><span class="tag">锁定</span><?php 
        }
        ?><a class="l" href="<?php 
        echo e(url('topic.php', ['id' => $topic['id']]));
        ?>"><?php 
        echo e($topic['title']);
        ?></a> <small class="grey">(+<?php 
        echo (int) $topic['reply_count'];
        ?>)</small></td><td><a href="<?php 
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
        ?><tr><td colspan="3" class="empty">暂无讨论，发表第一个主题吧。</td></tr><?php 
    }
    ?>
        </tbody></table>
        <?php 
    paginate('group.php', ['id' => $groupId], $page, $total);
    ?>
        <?php 
    if ($canWrite) {
        ?><div class="app-panel"><h2 class="reply_title">发表新主题</h2><form method="post" action="<?php 
        echo e(url('actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="create_topic"><input type="hidden" name="group_id" value="<?php 
        echo (int) $groupId;
        ?>"><div class="form-row"><label for="title">标题</label><input id="title" name="title" required minlength="2" maxlength="180"></div><div class="form-row"><label for="content">正文</label><textarea id="content" name="content" class="group_new_topic_editor" rows="6" maxlength="30000" required data-markdown></textarea><span class="hint"><?php 
        echo markdown_editor_hint();
        ?></span></div><button class="inputBtn" type="submit">加上去</button><span class="width_6px"></span><span class="hint"> Ctrl+Enter 快捷提交</span></form></div><?php 
    }
    ?>
        <?php 
}
?>
    </div>
    <div id="columnB" class="column">
        <?php 
if ($canRead) {
    ?><div class="SidePanel"><h2>最近加入</h2><ul class="side-list"><?php 
    foreach ($recent as $member) {
        ?><li><a href="<?php 
        echo e(url('user.php', ['id' => $member['id']]));
        ?>"><?php 
        echo e($member['display_name']);
        ?></a></li><?php 
    }
    ?></ul></div><?php 
}
?>
        <div class="menu_inner"><a class="l" href="<?php 
echo e(url('index.php'));
?>">/ 返回小组首页</a><br><a class="l" href="<?php 
echo e(url('index.php', ['view' => 'mine']));
?>">/ 参加的小组</a><?php 
if ($manager) {
    ?><br><a class="l" href="<?php 
    echo e(url('group_manage.php', ['id' => $groupId]));
    ?>">/ 小组管理</a><?php 
}
?></div>
    </div>
</div></div>
<?php 
render_footer();
