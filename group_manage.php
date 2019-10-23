<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = db()->prepare('SELECT * FROM discussion_groups WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$group = $stmt->fetch();
if (!$group || !group_is_manager($group, $user)) {
    http_response_code(403);
    exit('无权管理此小组。');
}
$owner = group_is_owner($group, $user);
$page = page_number();
$stmt = db()->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ?");
$stmt->execute([$id]);
$total = (int) $stmt->fetchColumn();
$stmt = db()->prepare("SELECT m.*, u.username, u.display_name FROM group_members m INNER JOIN users u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY FIELD(m.status, 'pending','approved','rejected','blocked'), m.created_at DESC LIMIT 20 OFFSET " . ($page - 1) * 20);
$stmt->execute([$id]);
$members = $stmt->fetchAll();
$stmt = db()->prepare('SELECT status, reason, review_note, created_at FROM group_public_requests WHERE group_id = ? ORDER BY id DESC LIMIT 10');
$stmt->execute([$id]);
$history = $stmt->fetchAll();
render_header('小组管理', 'groups');
?>
<div id="main" class="mainWrapper"><div class="columns clearit"><div id="columnA" class="column">
    <h1 id="group_manage_title">管理「<?php 
echo e($group['name']);
?>」</h1>
    <?php 
if ($owner) {
    ?>
    <section class="app-panel"><h2 id="group_profile_title">小组资料</h2>
        <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>" enctype="multipart/form-data">
            <?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="update_group"><input type="hidden" name="group_id" value="<?php 
    echo (int) $id;
    ?>">
            <div class="form-row"><label for="name">名称</label><input id="name" name="name" value="<?php 
    echo e($group['name']);
    ?>" minlength="2" maxlength="120" required></div>
            <div class="form-row"><label for="description">简介</label><textarea id="description" name="description" maxlength="5000" required><?php 
    echo e($group['description']);
    ?></textarea></div>
            <div class="form-row"><label for="image_file">小组图标</label><?php 
    render_group_image_field((string) ($group['image_path'] ?? ''));
    if ((string) ($group['image_path'] ?? '') !== '') {
        ?><label class="image-upload-remove"><input type="checkbox" name="remove_image" value="1"> 改用默认图标</label><?php 
    }
    ?></div>
            <button class="button primary">保存资料</button>
        </form>
    </section>
    <section class="app-panel"><h2 id="public_status_title">公开状态 <span class="tag"><?php 
    echo e(visibility_label($group['visibility_status']));
    ?></span></h2>
        <p class="hint">私有小组不在首页展示，通过把小组链接分享给朋友，他们可以申请加入。</p>
        <?php 
    if (in_array($group['visibility_status'], ['private', 'rejected'], true)) {
        ?>
        <form method="post" action="<?php 
        echo e(url('actions.php'));
        ?>">
            <?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="request_public"><input type="hidden" name="group_id" value="<?php 
        echo (int) $id;
        ?>">
            <div class="form-row"><label for="reason">公开申请理由</label><textarea id="reason" name="reason" rows="3" maxlength="500" required></textarea></div>
            <button class="button primary">提交公开申请</button>
        </form>
        <?php 
    } else {
        ?>
        <form method="post" action="<?php 
        echo e(url('actions.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="make_private"><input type="hidden" name="group_id" value="<?php 
        echo (int) $id;
        ?>"><br><button class="button">撤回公开申请</button></form>
        <?php 
    }
    ?>
        <?php 
    foreach ($history as $request) {
        ?>
            <p class="hint">
                <br>
                上次提交时间：<?php 
        echo e(format_time($request['created_at']));
        ?> <br>
                审核结果：<?php 
        echo e(['pending' => '待审核', 'approved' => '通过', 'rejected' => '拒绝', 'cancelled' => '撤回'][$request['status']] ?? $request['status']);
        ?> <br>
                <font color="black">批注：<?php 
        echo e($request['reason']);
        ?> <?php 
        if ($request['review_note']) {
            ?>（管理员：<?php 
            echo e($request['review_note']);
            ?>）<?php 
        }
        ?></font></p><?php 
    }
    ?>
    </section>
    <?php 
}
?>
    <section class="app-panel"><h2>成员与加入申请</h2><ul class="members-list">
        <?php 
foreach ($members as $member) {
    ?>
        <li><a href="<?php 
    echo e(url('user.php', ['id' => $member['user_id']]));
    ?>"><?php 
    echo e($member['display_name']);
    ?></a> <span class="hint">@<?php 
    echo e($member['username']);
    ?> 在<?php 
    echo e(['pending' => '待审核', 'approved' => '已加入', 'rejected' => '已拒绝', 'blocked' => '已移出'][$member['status']] ?? '');
    ?>状态。角色：<?php 
    echo e(['owner' => '组长', 'moderator' => '管理员', 'member' => '成员'][$member['role']] ?? '');
    ?></span>
            <?php 
    if ((int) $member['user_id'] !== (int) $group['owner_id'] && ($owner || $member['role'] === 'member')) {
        ?>
            <div class="member-controls">
                <?php 
        if ($member['status'] === 'pending') {
            ?>
                <form method="post" action="<?php 
            echo e(url('actions.php'));
            ?>" class="inline-form"><?php 
            echo csrf_field();
            ?><input type="hidden" name="action" value="manage_membership"><input type="hidden" name="group_id" value="<?php 
            echo (int) $id;
            ?>"><input type="hidden" name="member_id" value="<?php 
            echo (int) $member['user_id'];
            ?>"><button class="button" name="status" value="approved">通过</button><button class="button" name="status" value="rejected">拒绝</button></form>
                <?php 
        } elseif ($member['status'] === 'blocked') {
            ?>
                <form method="post" action="<?php 
            echo e(url('actions.php'));
            ?>" class="inline-form"><?php 
            echo csrf_field();
            ?><input type="hidden" name="action" value="unblock_member"><input type="hidden" name="group_id" value="<?php 
            echo (int) $id;
            ?>"><input type="hidden" name="member_id" value="<?php 
            echo (int) $member['user_id'];
            ?>"><button class="button">解除加入限制</button></form>
                <?php 
        } elseif ($member['status'] === 'approved') {
            ?>
                <form method="post" action="<?php 
            echo e(url('actions.php'));
            ?>" class="inline-form"><?php 
            echo csrf_field();
            ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="group_id" value="<?php 
            echo (int) $id;
            ?>"><input type="hidden" name="member_id" value="<?php 
            echo (int) $member['user_id'];
            ?>"><button class="button danger" data-confirm="移出此成员，并阻止重新加入？">移出</button></form>
                <?php 
            if ($owner) {
                ?>
                <form method="post" action="<?php 
                echo e(url('actions.php'));
                ?>" class="inline-form"><?php 
                echo csrf_field();
                ?><input type="hidden" name="action" value="member_role"><input type="hidden" name="group_id" value="<?php 
                echo (int) $id;
                ?>"><input type="hidden" name="member_id" value="<?php 
                echo (int) $member['user_id'];
                ?>"><button class="button" name="role" value="<?php 
                echo $member['role'] === 'moderator' ? 'member' : 'moderator';
                ?>"><?php 
                echo $member['role'] === 'moderator' ? '撤销管理员' : '设为管理员';
                ?></button></form>
                <form method="post" action="<?php 
                echo e(url('actions.php'));
                ?>" class="inline-form"><?php 
                echo csrf_field();
                ?><input type="hidden" name="action" value="transfer_group"><input type="hidden" name="group_id" value="<?php 
                echo (int) $id;
                ?>"><input type="hidden" name="member_id" value="<?php 
                echo (int) $member['user_id'];
                ?>"><button class="button" data-confirm="确认把组长权限交给此成员？">转交组长</button></form>
                <?php 
            }
            ?>
                <?php 
        }
        ?>
            </div><?php 
    }
    ?>
        </li><?php 
}
?>
    </ul><?php 
paginate('group_manage.php', ['id' => $id], $page, $total);
?></section>
    <?php 
if ($owner) {
    ?>
    <section class="app-panel danger-zone"><h2 id="delete_group_title">删除小组</h2><p class="hint">删除后小组、主题与回复都无法访问，只有站点管理员可以恢复。 <br>
        为了防止误操作，请输入名称进行确认。</p>
        <form method="post" action="<?php 
    echo e(url('actions.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="<?php 
    echo (int) $id;
    ?>"><div class="form-row"><label for="confirmation_name">确认小组名称</label><input id="confirmation_name" name="confirmation_name" required maxlength="120"></div><button class="button danger">删除小组</button></form>
    </section><?php 
}
?>
</div><div id="columnB" class="column"><div class="menu_inner"><a class="l" href="<?php 
echo e(url('group.php', ['id' => $id]));
?>">/ 返回小组</a></div></div></div></div>
<?php 
render_footer();
