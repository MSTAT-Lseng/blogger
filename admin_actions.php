<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if (!is_post()) {
    redirect('admin.php');
}
$admin = require_admin();
verify_csrf();
$action = post_string('action', 40);
$id = post_int('id');
if ($action === 'review_public') {
    $requestId = post_int('request_id');
    $stmt = db()->prepare('SELECT group_id FROM group_public_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $groupId = (int) $stmt->fetchColumn();
    db_transaction(function (PDO $pdo) use($admin, $groupId, $requestId) : void {
        $stmt = $pdo->prepare('SELECT * FROM discussion_groups WHERE id = ? FOR UPDATE');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT * FROM group_public_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$group || !$request || $group['deleted_at'] || $request['status'] !== 'pending' || $group['visibility_status'] !== 'pending') {
            throw new DomainException('申请已处理、撤回或小组已删除。');
        }
        $decision = post_string('decision', 20);
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainException('无效审核决定。');
        }
        $note = post_string('review_note', 500);
        $pdo->prepare('UPDATE group_public_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?')->execute([$decision, $admin['id'], $note, $requestId]);
        $pdo->prepare('UPDATE discussion_groups SET visibility_status = ? WHERE id = ?')->execute([$decision === 'approved' ? 'public' : 'rejected', $groupId]);
        if ($decision === 'approved') {
            // Everyone waiting for approval joins the now-public group.
            $pdo->prepare("UPDATE group_members SET status = 'approved' WHERE group_id = ? AND status = 'pending'")->execute([$groupId]);
        }
        audit('review_public_' . $decision, $admin, 'group', $groupId);
        // Everyone who asked to join this group this way learns the outcome in their inbox.
        $pending = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'approved'");
        $pending->execute([$groupId]);
        $requesterIds = array_merge([(int) $request['requested_by']], array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN)));
        $pendingInbox = [];
        foreach (array_unique($requesterIds) as $recipient) {
            if ($recipient < 1 || $recipient === (int) $admin['id']) {
                continue;
            }
            $pendingInbox[] = ['user_id' => $recipient, 'actor_id' => (int) $admin['id'], 'kind' => 'review', 'title' => $decision === 'approved' ? '小组「' . $group['name'] . '」的公开申请已通过' : '小组「' . $group['name'] . '」的公开申请未通过', 'body' => ($decision === 'approved' ? '小组已对外公开，你的加入状态同时转为已加入。' : '小组保持私有，你可以联系组长了解原因。') . ($note !== '' ? ' 管理员说明：' . $note : '') . "\n" . url('group.php', ['id' => $groupId]), 'target_type' => 'group', 'target_id' => $groupId];
        }
        inbox_notify($pendingInbox);
    });
    flash('success', '公开申请已处理');
    redirect('admin.php');
}
if ($action === 'user_status' || $action === 'user_role' || $action === 'reset_password') {
    $unchanged = false;
    db_transaction(function (PDO $pdo) use($admin, $id, $action, &$unchanged) : void {
        // Lock all admins first so concurrent demotions cannot remove the last administrator.
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE")->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new DomainException('用户不存在。');
        }
        if ($id === (int) $admin['id']) {
            throw new DomainException('不能在管理中心停用、降级或重置当前登录账号。');
        }
        $value = $action === 'reset_password' ? '' : post_string('value', 20);
        // The site owner (UID 1) may never be demoted, disabled, or password-reset by another administrator.
        if (is_site_owner($target) && !is_site_owner($admin)) {
            $blocked = $action === 'reset_password' || $action === 'user_role' && $value !== 'admin' || $action === 'user_status' && $value !== 'active';
            if ($blocked) {
                throw new DomainException('站长（UID 1）账号受保护，其他管理员不能将其调为用户权限、停用或重置密码。');
            }
            // Only a real recovery changes anything; a repeat request must not invalidate the owner's sessions.
            if ($target['role'] === 'admin' && $target['status'] === 'active') {
                $unchanged = true;
                return;
            }
        }
        if ($action === 'reset_password') {
            $password = post_password('new_password');
            if (strlen($password) < 8 || strlen($password) > 72) {
                throw new DomainException('临时密码长度须为8~72字符');
            }
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        } else {
            if ($action === 'user_role') {
                if (!in_array($value, ['admin', 'user'], true)) {
                    throw new DomainException('角色无效。');
                }
                $removesAdmin = $value !== 'admin';
                $sql = 'UPDATE users SET role = ?, session_version = session_version + 1 WHERE id = ?';
            } else {
                if (!in_array($value, ['active', 'disabled'], true)) {
                    throw new DomainException('状态无效。');
                }
                $removesAdmin = $value !== 'active';
                $sql = 'UPDATE users SET status = ?, session_version = session_version + 1 WHERE id = ?';
            }
            if ($target['role'] === 'admin' && $target['status'] === 'active' && $removesAdmin && count($admins) <= 1) {
                throw new DomainException('必须保留至少一个有效管理员。');
            }
            $pdo->prepare($sql)->execute([$value, $id]);
        }
        audit_notify($action, $admin, 'user', $id, ['value' => $value]);
    });
    flash('success', $unchanged ? '站长账号已处于该状态，未做任何变更' : '用户账号已更新，其现有会话已失效');
    redirect('admin.php', ['tab' => 'users']);
}
if (in_array($action, ['restore_group', 'restore_topic', 'restore_reply'], true)) {
    db_transaction(function (PDO $pdo) use($id, $action, $admin) : void {
        if ($action === 'restore_group') {
            $stmt = $pdo->prepare("UPDATE discussion_groups SET deleted_at = NULL, visibility_status = 'private' WHERE id = ? AND deleted_at IS NOT NULL");
            $type = 'group';
        } elseif ($action === 'restore_topic') {
            $stmt = $pdo->prepare('UPDATE topics t INNER JOIN discussion_groups g ON g.id = t.group_id SET t.deleted_at = NULL WHERE t.id = ? AND t.deleted_at IS NOT NULL AND g.deleted_at IS NULL');
            $type = 'topic';
        } else {
            $stmt = $pdo->prepare('UPDATE replies r INNER JOIN topics t ON t.id = r.topic_id INNER JOIN discussion_groups g ON g.id = t.group_id SET r.deleted_at = NULL WHERE r.id = ? AND r.deleted_at IS NOT NULL AND t.deleted_at IS NULL AND g.deleted_at IS NULL');
            $type = 'reply';
        }
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('内容已恢复、不存在，或需要先恢复所属小组与主题。');
        }
        audit_notify($action, $admin, $type, $id);
    });
    flash('success', $action === 'restore_group' ? '小组已恢复为私有' : '内容已恢复');
    redirect('admin.php', ['tab' => 'trash']);
}
if (in_array($action, ['purge_group', 'purge_topic', 'purge_reply'], true)) {
    // Permanent deletion: only already soft-deleted rows qualify.
    $purgedImage = null;
    db_transaction(function (PDO $pdo) use($id, $action, $admin, &$purgedImage) : void {
        if ($action === 'purge_group') {
            $stmt = $pdo->prepare('SELECT id, image_path FROM discussion_groups WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
            $stmt->execute([$id]);
            $group = $stmt->fetch();
            if (!$group) {
                throw new DomainException('小组不存在或未处于已删除状态。');
            }
            // Members, public requests, topics, and their replies go away through foreign-key cascades.
            $pdo->prepare('DELETE FROM discussion_groups WHERE id = ?')->execute([$id]);
            $purgedImage = isset($group['image_path']) && $group['image_path'] !== null ? (string) $group['image_path'] : null;
            $type = 'group';
        } elseif ($action === 'purge_topic') {
            $stmt = $pdo->prepare('SELECT id FROM topics WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                throw new DomainException('主题不存在或未处于已删除状态。');
            }
            // Replies of the topic are removed by the foreign-key cascade.
            $pdo->prepare('DELETE FROM topics WHERE id = ?')->execute([$id]);
            $type = 'topic';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM replies WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                throw new DomainException('回复不存在或未处于已删除状态。');
            }
            // Replies quoting this one keep no dangling parent reference.
            $pdo->prepare('UPDATE replies SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM replies WHERE id = ?')->execute([$id]);
            $type = 'reply';
        }
        audit($action, $admin, $type, $id);
    });
    group_image_delete($purgedImage);
    flash('success', $action === 'purge_group' ? '小组及其全部主题和回复已彻底删除' : '内容已彻底删除');
    redirect('admin.php', ['tab' => 'trash']);
}
if ($action === 'clean_auth_attempts') {
    db()->exec('DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    flash('success', '过期登录记录已清理');
    redirect('admin.php', ['tab' => 'logs']);
}
if ($action === 'reset_logs') {
    // Only the site owner may wipe the audit trail.
    if (!is_site_owner($admin)) {
        http_response_code(403);
        exit('只有站长（UID 1）可以重置操作记录。');
    }
    db_transaction(function (PDO $pdo) use($admin) : void {
        $pdo->exec('DELETE FROM audit_logs');
        // One entry records the reset itself so the trail never restarts completely blind.
        audit('reset_logs', $admin, 'audit_logs', 0);
    });
    flash('success', '操作记录已重置');
    redirect('admin.php', ['tab' => 'logs']);
}
http_response_code(400);
exit('无效操作。');
