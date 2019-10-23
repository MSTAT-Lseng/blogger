<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$admin = require_admin();
$tab = is_string($_GET['tab'] ?? '') ? $_GET['tab'] : 'requests';
if (!in_array($tab, ['requests', 'groups', 'users', 'content', 'trash', 'logs'], true)) {
    $tab = 'requests';
}
$page = page_number();
$offset = ($page - 1) * 20;
$rows = $extra = [];
$total = $extraTotal = 0;
$extraPage = page_number('content_page');
$queries = ['requests' => ["SELECT COUNT(*) FROM group_public_requests r INNER JOIN discussion_groups g ON g.id = r.group_id WHERE r.status = 'pending' AND g.deleted_at IS NULL", "SELECT r.*, g.name AS group_name, u.display_name FROM group_public_requests r INNER JOIN discussion_groups g ON g.id = r.group_id INNER JOIN users u ON u.id = r.requested_by WHERE r.status = 'pending' AND g.deleted_at IS NULL ORDER BY r.id ASC"], 'groups' => ['SELECT COUNT(*) FROM discussion_groups WHERE deleted_at IS NULL', 'SELECT g.*, u.display_name AS owner_name FROM discussion_groups g INNER JOIN users u ON u.id = g.owner_id WHERE g.deleted_at IS NULL ORDER BY g.id DESC'], 'users' => ['SELECT COUNT(*) FROM users', 'SELECT id, username, display_name, email, role, status FROM users ORDER BY id DESC'], 'content' => ['SELECT COUNT(*) FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE t.deleted_at IS NULL AND g.deleted_at IS NULL', 'SELECT t.*, g.name AS group_name FROM topics t INNER JOIN discussion_groups g ON g.id = t.group_id WHERE t.deleted_at IS NULL AND g.deleted_at IS NULL ORDER BY t.id DESC'], 'trash' => ['SELECT COUNT(*) FROM discussion_groups WHERE deleted_at IS NOT NULL', 'SELECT id, name, image_path, deleted_at FROM discussion_groups WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'], 'logs' => ['SELECT COUNT(*) FROM audit_logs', 'SELECT a.*, u.display_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC']];
$total = (int) db()->query($queries[$tab][0])->fetchColumn();
$rows = db()->query($queries[$tab][1] . ' LIMIT 20 OFFSET ' . $offset)->fetchAll();
if ($tab === 'trash') {
    $extraTotal = (int) db()->query('SELECT (SELECT COUNT(*) FROM topics WHERE deleted_at IS NOT NULL) + (SELECT COUNT(*) FROM replies WHERE deleted_at IS NOT NULL)')->fetchColumn();
    $extraPage = min($extraPage, max(1, (int) ceil($extraTotal / 20)));
    $extra = db()->query("SELECT 'topic' AS kind, id, title AS summary, deleted_at FROM topics WHERE deleted_at IS NOT NULL UNION ALL SELECT 'reply' AS kind, id, LEFT(content, 80) AS summary, deleted_at FROM replies WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 20 OFFSET " . ($extraPage - 1) * 20)->fetchAll();
}
render_header('管理中心', 'admin');
?>
<div id="main" class="mainWrapper">
    <h1 id="management_center_title">管理中心</h1>
    <ul class="secTab admin-tabs"><?php 
foreach (['requests' => '公开审核', 'groups' => '小组管理', 'users' => '用户管理', 'content' => '内容管理', 'trash' => '回收站', 'logs' => '操作记录'] as $key => $label) {
    ?><li><a href="<?php 
    echo e(url('admin.php', ['tab' => $key]));
    ?>" class="<?php 
    echo $tab === $key ? 'selected' : '';
    ?>"><span><?php 
    echo e($label);
    ?></span></a></li><?php 
}
?></ul><div class="clear"></div>
    <section class="app-panel">
    <?php 
if ($tab === 'requests') {
    ?>
        <h2 id="pub_applicat_pending_review_title">待审核的公开申请</h2>
        <?php 
    if (!$rows) {
        ?><p class="empty">暂无记录</p><?php 
    }
    ?>
        <?php 
    foreach ($rows as $row) {
        ?>
        <div class="request-item pub_applicat_pending_review_table"><h3># <a href="<?php 
        echo e(url('group.php', ['id' => $row['group_id']]));
        ?>"><?php 
        echo e($row['group_name']);
        ?></a></h3>
        <p class="tip">创建者：<?php 
        echo e($row['display_name']);
        ?> <br> 创建时间：<?php 
        echo e(format_time($row['created_at']));
        ?></p>
        备注：<p><?php 
        echo e($row['reason']);
        ?></p>
        <form method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="review_public"><input type="hidden" name="request_id" value="<?php 
        echo (int) $row['id'];
        ?>"><div class="form-row"><label for="note-<?php 
        echo (int) $row['id'];
        ?>" class="review_notes_label">审核说明</label><input id="note-<?php 
        echo (int) $row['id'];
        ?>" name="review_note" maxlength="500"></div><button class="button primary" name="decision" value="approved">通过并公开</button><button class="button danger" name="decision" value="rejected">拒绝</button></form></div>
        <?php 
    }
    ?>
    <?php 
} elseif ($tab === 'groups') {
    ?>
        <h2 id="all_valid_groups_title">全部有效小组</h2><table class="topic_list all_valid_groups_table"><thead><tr><th>小组</th><th>组长</th><th>公开状态</th><th>操作</th></tr></thead><tbody><?php 
    foreach ($rows as $row) {
        ?><tr><td><a href="<?php 
        echo e(url('group.php', ['id' => $row['id']]));
        ?>"><?php 
        echo e($row['name']);
        ?></a></td><td><?php 
        echo e($row['owner_name']);
        ?></td><td><?php 
        echo e(visibility_label($row['visibility_status']));
        ?></td><td><a class="button" href="<?php 
        echo e(url('group_manage.php', ['id' => $row['id']]));
        ?>">管理</a></td></tr><?php 
    }
    if (!$rows) {
        ?><tr><td colspan="4" class="empty">暂无记录</td></tr><?php 
    }
    ?></tbody></table>
    <?php 
} elseif ($tab === 'users') {
    ?>
        <h2 id="user_account_title">用户账号</h2><p class="hint">
            注意事项： <br>
            1. 停用或更改角色会使用户已有会话失效；<br>
            2. 不能在此修改当前管理员； <br>
            3. UID 为 1 的账号是站长，其他管理员不能将其降级、停用或重置密码； <br>
            4. 临时密码需通过站外渠道交给用户</p>
        <?php 
    if (!$rows) {
        ?><p class="empty">暂无记录</p><?php 
    }
    ?>
        <?php 
    foreach ($rows as $row) {
        $ownerRow = is_site_owner($row);
        $selfRow = (int) $row['id'] === (int) $admin['id'];
        // The site owner row only offers recovery actions to other administrators: never demote, disable, or reset.
        $canStatus = !$selfRow && ($ownerRow ? $row['status'] !== 'active' : true);
        $canRole = !$selfRow && ($ownerRow ? $row['role'] !== 'admin' : true);
        $canPassword = !$selfRow && !$ownerRow;
        ?><div class="request-item"><h3 class="user_account_name"><?php 
        echo e($row['display_name']);
        ?> <small class="grey">@<?php 
        echo e($row['username']);
        ?></small></h3>
            <p class="tip">
                邮箱：<?php 
        echo e($row['email']);
        ?> <br>
                级别：<?php 
        echo $ownerRow ? '站长（UID 1）' : ($row['role'] === 'admin' ? '站点管理员' : '用户');
        ?> <br>
                状态：<?php 
        echo $row['status'] === 'active' ? '正常' : '停用';
        ?>
            </p>
        <?php 
        if ($ownerRow && !$selfRow) {
            ?><p class="hint">站长账号受保护，其他管理员不能将其调为用户权限、停用或重置密码。<?php 
            echo $row['role'] !== 'admin' ? '该账号当前没有管理员权限，可通过“设为站点管理员”恢复。' : '';
            ?></p><?php 
        }
        ?>
        <?php 
        if ($canStatus || $canRole || $canPassword) {
            ?>
        <div class="height_6px"></div>
        <?php 
            if ($canStatus) {
                ?><form class="inline-form" method="post" action="<?php 
                echo e(url('admin_actions.php'));
                ?>"><?php 
                echo csrf_field();
                ?><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?php 
                echo (int) $row['id'];
                ?>"><button class="button" name="value" value="<?php 
                echo $row['status'] === 'active' ? 'disabled' : 'active';
                ?>"><?php 
                echo $row['status'] === 'active' ? '停用账号' : '启用账号';
                ?></button></form><?php 
            }
            ?>
        <?php 
            if ($canRole) {
                ?><form class="inline-form" method="post" action="<?php 
                echo e(url('admin_actions.php'));
                ?>"><?php 
                echo csrf_field();
                ?><input type="hidden" name="action" value="user_role"><input type="hidden" name="id" value="<?php 
                echo (int) $row['id'];
                ?>"><button class="button" name="value" value="<?php 
                echo $row['role'] === 'admin' ? 'user' : 'admin';
                ?>" data-confirm="确认更改站点角色？"><?php 
                echo $row['role'] === 'admin' ? '改为普通用户' : '设为站点管理员';
                ?></button></form><?php 
            }
            ?>
        <?php 
            if ($canPassword) {
                ?><details class="reset_password"><summary>重置密码</summary><form method="post" action="<?php 
                echo e(url('admin_actions.php'));
                ?>"><?php 
                echo csrf_field();
                ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?php 
                echo (int) $row['id'];
                ?>"><div class="form-row"><label for="pw-<?php 
                echo (int) $row['id'];
                ?>" class="temp_pwd_tag">临时密码</label><input id="pw-<?php 
                echo (int) $row['id'];
                ?>" name="new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></div><button class="button danger" data-confirm="重置此用户密码？">保存临时密码</button></form></details><?php 
            }
            ?>
        <?php 
        }
        ?></div><?php 
    }
    ?>
    <?php 
} elseif ($tab === 'content') {
    ?>
        <h2 id="topic_reply_manage_title">主题与回复管理</h2><table class="topic_list topic_manage_list"><thead><tr><th>主题</th><th>小组</th><th>操作</th></tr></thead><tbody><?php 
    foreach ($rows as $row) {
        ?><tr><td><a href="<?php 
        echo e(url('topic.php', ['id' => $row['id']]));
        ?>"><?php 
        echo e($row['title']);
        ?></a></td><td><?php 
        echo e($row['group_name']);
        ?></td><td><a class="button" href="<?php 
        echo e(url('topic.php', ['id' => $row['id']]));
        ?>">查看</a><form method="post" class="inline-form" action="<?php 
        echo e(url('actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="<?php 
        echo (int) $row['id'];
        ?>"><button class="button danger" data-confirm="删除此主题？">删除</button></form></td></tr><?php 
    }
    if (!$rows) {
        ?><tr><td colspan="3" class="empty">暂无记录</td></tr><?php 
    }
    ?></tbody></table>
    <?php 
} elseif ($tab === 'trash') {
    ?>
        <h2 id="deleted_group_title">已删除的小组</h2>
        <p class="hint" id="deleted_group_hint">
            注意事项：<br>
            1. 已删除的小组可以恢复为私有小组，也可以彻底删除； <br>
            2. 独立删除的主题和回复仍保留其删除状态； <br>
            3. 恢复内容前须先恢复小组与主题； <br>
            4. 彻底删除会从数据库中永久移除内容及其下属数据，无法恢复
        </p>
        <?php 
    if (!$rows) {
        ?><p class="empty">暂无记录</p><?php 
    }
    ?>
        <?php 
    foreach ($rows as $row) {
        ?><div class="request-item"># <?php 
        echo e($row['name']);
        ?> <form class="inline-form" method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="restore_group"><input type="hidden" name="id" value="<?php 
        echo (int) $row['id'];
        ?>"><button class="button recovery_group">恢复小组</button></form> <form class="inline-form" method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="purge_group"><input type="hidden" name="id" value="<?php 
        echo (int) $row['id'];
        ?>"><button class="button danger recovery_group" data-confirm="<?php 
        echo e('彻底删除小组“' . $row['name'] . '”及其全部主题和回复？此操作不可恢复。');
        ?>">彻底删除</button></form></div><?php 
    }
    ?>
        <?php 
    paginate('admin.php', ['tab' => 'trash', 'content_page' => $extraPage], $page, $total);
    ?>
        <h2 id="deleted_theme_reply_title">已删除的主题和回复</h2>
        <?php 
    if (!$extra) {
        ?><p class="empty">暂无记录</p><?php 
    }
    ?>
        <?php 
    foreach ($extra as $row) {
        $kindLabel = $row['kind'] === 'topic' ? '主题' : '回复';
        ?><div class="request-item"># <?php 
        echo e($row['summary']);
        ?> <form class="inline-form" method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="<?php 
        echo $row['kind'] === 'topic' ? 'restore_topic' : 'restore_reply';
        ?>"><input type="hidden" name="id" value="<?php 
        echo (int) $row['id'];
        ?>"><button class="button recovery_group">恢复<?php 
        echo $kindLabel;
        ?></button></form> <form class="inline-form" method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="<?php 
        echo $row['kind'] === 'topic' ? 'purge_topic' : 'purge_reply';
        ?>"><input type="hidden" name="id" value="<?php 
        echo (int) $row['id'];
        ?>"><button class="button danger recovery_group" data-confirm="彻底删除此<?php 
        echo $kindLabel;
        ?>？<?php 
        echo $row['kind'] === 'topic' ? '其下全部回复也会被永久删除，' : '';
        ?>此操作不可恢复。">彻底删除</button></form></div><?php 
    }
    ?>
        <?php 
    paginate('admin.php', ['tab' => 'trash', 'page' => $page], $extraPage, $extraTotal, 20, 'content_page');
    ?>
    <?php 
} else {
    ?>
        <h2 id="manage_operate_logs_title">管理操作记录</h2><table class="topic_list"><thead><tr><th>操作者</th><th>操作</th><th>目标</th><th>时间</th></tr></thead><tbody><?php 
    foreach ($rows as $row) {
        ?><tr><td><?php 
        echo e($row['display_name'] ?: $row['actor_id']);
        ?></td><td><?php 
        echo e($row['action']);
        ?></td><td><?php 
        echo e($row['target_type']);
        ?> #<?php 
        echo (int) $row['target_id'];
        ?></td><td><?php 
        echo e(format_time($row['created_at']));
        ?></td></tr><?php 
    }
    if (!$rows) {
        ?><tr><td colspan="4" class="empty">暂无记录</td></tr><?php 
    }
    ?></tbody></table>
        <form method="post" action="<?php 
    echo e(url('admin_actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="clean_auth_attempts"><button class="button">清理过期登录记录</button></form>
        <?php 
    if (is_site_owner($admin)) {
        ?>
        <div class="height_6px"></div>
        <form method="post" action="<?php 
        echo e(url('admin_actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="reset_logs"><button class="button danger" data-confirm="重置将清空全部管理操作记录（仅保留本次重置记录），确认继续？">重置操作记录</button></form>
        <?php 
    }
    ?>
    <?php 
}
?>
    
    <?php 
if ($tab !== 'trash') {
    paginate('admin.php', ['tab' => $tab, 'content_page' => $extraPage], $page, $total);
}
?>
    </section>
</div>
<?php 
render_footer();
