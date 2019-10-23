<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if (!is_post()) {
    redirect('index.php');
}
verify_csrf();
$user = require_login();
$action = post_string('action', 40);
if ($action === 'create_group') {
    $name = post_string('name', 120);
    $description = post_string('description', 5000);
    if (text_length($name) < 2 || $description === '') {
        throw new DomainException('小组名称至少 2 个字符，并且简介不能为空');
    }
    $requestPublic = post_string('request_public', 1) === '1';
    $image = group_image_upload();
    try {
        $id = db_transaction(function (PDO $pdo) use($name, $description, $image, $user, $requestPublic) : int {
            $stmt = $pdo->prepare("INSERT INTO discussion_groups (slug, name, description, image_path, owner_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute(['g-' . bin2hex(random_bytes(12)), $name, $description, $image, $user['id']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'owner', 'approved')")->execute([$id, $user['id']]);
            if ($requestPublic) {
                $pdo->prepare("UPDATE discussion_groups SET visibility_status = 'pending' WHERE id = ?")->execute([$id]);
                $pdo->prepare('INSERT INTO group_public_requests (group_id, requested_by, reason) VALUES (?, ?, ?)')->execute([$id, $user['id'], '创建小组时申请公开']);
            }
            audit('create_group', $user, 'group', $id);
            return $id;
        });
    } catch (Throwable $exception) {
        // The uploaded icon is already on disk; drop it so a failed insert leaves no orphan file.
        group_image_delete($image);
        throw $exception;
    }
    flash('success', $requestPublic ? '小组已创建，公开申请等待站点管理员审核' : '小组已创建，当前为私有小组');
    redirect('group.php', ['id' => $id]);
}
$allowed = ['join_group', 'leave_group', 'request_public', 'update_group', 'make_private', 'delete_group', 'manage_membership', 'remove_member', 'unblock_member', 'member_role', 'transfer_group', 'create_topic', 'edit_topic', 'delete_topic', 'moderate_topic', 'reply_topic', 'edit_reply', 'delete_reply'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    exit('无效操作。');
}
$groupId = post_int('group_id');
$topicId = post_int('topic_id');
$replyId = post_int('reply_id');
if (in_array($action, ['edit_reply', 'delete_reply'], true)) {
    $stmt = db()->prepare('SELECT topic_id FROM replies WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$replyId]);
    $topicId = (int) $stmt->fetchColumn();
}
if (in_array($action, ['reply_topic', 'edit_topic', 'delete_topic', 'moderate_topic', 'edit_reply', 'delete_reply'], true)) {
    $stmt = db()->prepare('SELECT group_id FROM topics WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$topicId]);
    $groupId = (int) $stmt->fetchColumn();
}
// Set inside the update_group branch and unlinked only after the transaction commits.
$replacedImage = null;
// Members whose approved membership was revoked by this request; they are notified after the audit entry.
$blockedMembers = [];
$result = db_transaction(function (PDO $pdo) use($action, $user, $groupId, $topicId, $replyId, &$replacedImage, &$blockedMembers) : array {
    // Group mutations and review actions share this lock to serialize permission/state transitions.
    $stmt = $pdo->prepare('SELECT * FROM discussion_groups WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        throw new DomainException('小组不存在或已删除。');
    }
    $manager = group_is_manager($group, $user);
    $owner = group_is_owner($group, $user);
    $membership = group_membership($groupId, (int) $user['id']);
    $back = ['group.php', ['id' => $groupId], '操作已完成'];
    if ($action === 'join_group') {
        if ($membership && $membership['status'] === 'blocked') {
            throw new DomainException('你已被移出该小组，无法重新加入。');
        }
        if ($membership && in_array($membership['status'], ['pending', 'approved'], true)) {
            $back[2] = $membership['status'] === 'approved' ? '你已是小组成员。' : '加入申请等待组长审核。';
            return $back;
        }
        $status = $group['visibility_status'] === 'public' ? 'approved' : 'pending';
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', ?) ON DUPLICATE KEY UPDATE role = 'member', status = VALUES(status), created_at = NOW()")->execute([$groupId, $user['id'], $status]);
        $back[2] = $status === 'approved' ? '已加入小组。' : '已申请加入，等待组长审核。';
        return $back;
    }
    if ($action === 'leave_group') {
        if ((int) $group['owner_id'] === (int) $user['id']) {
            throw new DomainException('请先转交组长权限，或解散小组。');
        }
        if ($membership && $membership['status'] === 'blocked') {
            throw new DomainException('你已被移出该小组。');
        }
        $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $user['id']]);
        $back[2] = '已退出小组或撤回申请。';
        return $back;
    }
    if (in_array($action, ['request_public', 'update_group', 'make_private', 'delete_group', 'member_role', 'transfer_group'], true) && !$owner) {
        throw new DomainException('只有组长或站点管理员可以执行该操作。');
    }
    if (in_array($action, ['manage_membership', 'remove_member', 'unblock_member'], true) && !$manager) {
        throw new DomainException('无权管理成员。');
    }
    if ($action === 'request_public') {
        if (in_array($group['visibility_status'], ['public', 'pending'], true)) {
            throw new DomainException('小组已经公开或申请正在审核中。');
        }
        $pdo->prepare('INSERT INTO group_public_requests (group_id, requested_by, reason) VALUES (?, ?, ?)')->execute([$groupId, $user['id'], post_string('reason', 500) ?: '申请公开小组']);
        $pdo->prepare("UPDATE discussion_groups SET visibility_status = 'pending' WHERE id = ?")->execute([$groupId]);
        audit_notify($action, $user, 'group', $groupId, ['group_name' => $group['name']]);
        $back[2] = '公开申请已提交。';
        return $back;
    }
    if ($action === 'update_group') {
        $name = post_string('name', 120);
        $description = post_string('description', 5000);
        if (text_length($name) < 2 || $description === '') {
            throw new DomainException('名称至少 2 个字符，简介不能为空。');
        }
        // Upload after the permission check so an unauthorized request never writes a file.
        $uploaded = group_image_upload();
        $stored = (string) ($group['image_path'] ?? '');
        if ($uploaded !== null) {
            $image = $uploaded;
        } elseif (post_string('remove_image', 1) === '1') {
            $image = null;
        } else {
            $image = $stored !== '' ? $stored : null;
        }
        $pdo->prepare('UPDATE discussion_groups SET name = ?, description = ?, image_path = ? WHERE id = ?')->execute([$name, $description, $image, $groupId]);
        if ($image !== $stored && $stored !== '') {
            $replacedImage = $stored;
        }
        audit_notify($action, $user, 'group', $groupId, ['group_name' => $name]);
        return $back;
    }
    if ($action === 'make_private' || $action === 'delete_group') {
        if ($action === 'delete_group' && post_string('confirmation_name', 120) !== $group['name']) {
            throw new DomainException('请准确输入小组名称以确认删除。');
        }
        $sql = $action === 'delete_group' ? "UPDATE discussion_groups SET visibility_status = 'private', deleted_at = NOW() WHERE id = ?" : "UPDATE discussion_groups SET visibility_status = 'private' WHERE id = ?";
        $pdo->prepare($sql)->execute([$groupId]);
        $pdo->prepare("UPDATE group_public_requests SET status = 'cancelled', review_note = '组长撤回公开或删除小组', reviewed_at = NOW() WHERE group_id = ? AND status = 'pending'")->execute([$groupId]);
        audit_notify($action, $user, 'group', $groupId, ['group_name' => $group['name']]);
        return $action === 'delete_group' ? ['index.php', [], '小组已删除，主题和回复同时隐藏，站点管理员可恢复'] : ['group.php', ['id' => $groupId], '小组已改为私有。'];
    }
    if (in_array($action, ['manage_membership', 'remove_member', 'unblock_member', 'member_role', 'transfer_group'], true)) {
        $memberId = post_int('member_id');
        if ($memberId === (int) $group['owner_id']) {
            throw new DomainException('不能通过成员管理修改现任组长。');
        }
        $target = group_membership($groupId, $memberId);
        if (!$target) {
            throw new DomainException('成员不存在。');
        }
        if (!$owner && $target['role'] !== 'member') {
            throw new DomainException('组内管理员不能修改其他管理人员。');
        }
        // Only a transition from approved to blocked locks a member out of the group.
        $wasApproved = $target['status'] === 'approved';
        $context = ['group_id' => $groupId];
        if ($action === 'manage_membership') {
            $status = post_string('status', 20);
            if ($target['status'] !== 'pending' || !in_array($status, ['approved', 'rejected'], true)) {
                throw new DomainException('申请已处理或状态无效。');
            }
            $pdo->prepare('UPDATE group_members SET status = ? WHERE group_id = ? AND user_id = ?')->execute([$status, $groupId, $memberId]);
            $context['status'] = $status;
        } elseif ($action === 'remove_member') {
            if ($target['status'] !== 'approved') {
                throw new DomainException('只能移出已加入的成员。');
            }
            $pdo->prepare("UPDATE group_members SET status = 'blocked', role = 'member' WHERE group_id = ? AND user_id = ?")->execute([$groupId, $memberId]);
        } elseif ($action === 'unblock_member') {
            if ($target['status'] !== 'blocked') {
                throw new DomainException('成员未被移出。');
            }
            $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $memberId]);
            $back[2] = '已解除加入限制，该用户可以重新申请加入。';
        } else {
            if ($target['status'] !== 'approved') {
                throw new DomainException('目标必须是已加入的成员。');
            }
            if ($action === 'member_role') {
                $role = post_string('role', 20);
                if (!in_array($role, ['member', 'moderator'], true)) {
                    throw new DomainException('无效组内角色。');
                }
                $pdo->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')->execute([$role, $groupId, $memberId]);
                $context['role'] = $role;
            } else {
                $active = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
                $active->execute([$memberId]);
                if (!$active->fetch()) {
                    throw new DomainException('不能转交给已停用的账号。');
                }
                $pdo->prepare("UPDATE group_members SET role = 'member' WHERE group_id = ? AND user_id = ?")->execute([$groupId, $group['owner_id']]);
                $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?")->execute([$groupId, $memberId]);
                $pdo->prepare('UPDATE discussion_groups SET owner_id = ? WHERE id = ?')->execute([$memberId, $groupId]);
                // The previous owner also hears that the group was handed over.
                $blockedMembers[] = (int) $group['owner_id'];
                $context['extra_title'] = '「' . $group['name'] . '」的组长已转交给 ' . inbox_user_label($memberId);
                $context['extra_body'] = '小组管理权限已交接，共有成员和讨论不受影响。' . "\n" . url('group.php', ['id' => $groupId]);
            }
        }
        if ($action === 'remove_member' && $wasApproved) {
            // The member keeps no access; tell them why the group disappeared from their list.
            $blockedMembers[] = $memberId;
            $context['extra_title'] = '你已被移出小组「' . $group['name'] . '」';
            $context['extra_body'] = '组长或组内管理员将你移出了该小组，你无法再次申请加入；如有疑问可以联系组长。';
        }
        audit_notify($action, $user, 'group_member', $memberId, $context, $blockedMembers);
        $blockedMembers = [];
        return $back;
    }
    if (!group_access($group, $user)) {
        throw new DomainException('没有访问这个小组的权限。');
    }
    if ($action === 'create_topic') {
        if (!group_can_write($group, $user)) {
            throw new DomainException('加入小组后才能发帖。');
        }
        $title = post_string('title', 180);
        $content = post_string('content', 30000);
        if (text_length($title) < 2 || $content === '') {
            throw new DomainException('标题至少 2 个字符，正文不能为空。');
        }
        $pdo->prepare('INSERT INTO topics (group_id, user_id, title, content) VALUES (?, ?, ?, ?)')->execute([$groupId, $user['id'], $title, $content]);
        $id = (int) $pdo->lastInsertId();
        audit('create_topic', $user, 'topic', $id);
        return ['topic.php', ['id' => $id], '主题已发表。'];
    }
    $stmt = $pdo->prepare('SELECT * FROM topics WHERE id = ? AND group_id = ? AND deleted_at IS NULL FOR UPDATE');
    $stmt->execute([$topicId, $groupId]);
    $topic = $stmt->fetch();
    if (!$topic) {
        throw new DomainException('主题不存在或已删除。');
    }
    $back = ['topic.php', ['id' => $topicId], '操作已完成'];
    if ($action === 'reply_topic') {
        if (!group_can_write($group, $user) || $topic['is_locked']) {
            throw new DomainException('主题已锁定或你还未加入小组，不能回复。');
        }
        $content = post_string('content', 30000);
        if ($content === '') {
            throw new DomainException('回复不能为空。');
        }
        $parentId = post_int('parent_id');
        if ($parentId) {
            $parent = $pdo->prepare('SELECT id FROM replies WHERE id = ? AND topic_id = ? AND deleted_at IS NULL');
            $parent->execute([$parentId, $topicId]);
            if (!$parent->fetch()) {
                throw new DomainException('引用的回复不存在。');
            }
        }
        $pdo->prepare('INSERT INTO replies (topic_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)')->execute([$topicId, $user['id'], $parentId ?: null, $content]);
        $id = (int) $pdo->lastInsertId();
        audit('reply_topic', $user, 'reply', $id);
        // The topic author and the author of a quoted reply both hear about the new reply.
        inbox_notify_reply($id, (int) $user['id']);
        $count = $pdo->prepare('SELECT COUNT(*) FROM replies WHERE topic_id = ? AND deleted_at IS NULL');
        $count->execute([$topicId]);
        return ['topic.php', ['id' => $topicId, 'page' => max(1, (int) ceil((int) $count->fetchColumn() / 20))], '回复已发表'];
    }
    if (in_array($action, ['edit_topic', 'delete_topic', 'moderate_topic'], true)) {
        if (!$manager && (int) $topic['user_id'] !== (int) $user['id']) {
            throw new DomainException('无权编辑这个主题。');
        }
        $pinned = (int) $topic['is_pinned'] === 1;
        $locked = (int) $topic['is_locked'] === 1;
        $context = [];
        if ($action === 'moderate_topic') {
            if (!$manager) {
                throw new DomainException('无权置顶或锁定主题。');
            }
            $pinned = post_string('is_pinned', 1) === '1';
            $locked = post_string('is_locked', 1) === '1';
            $pdo->prepare('UPDATE topics SET is_pinned = ?, is_locked = ? WHERE id = ?')->execute([$pinned ? 1 : 0, $locked ? 1 : 0, $topicId]);
            $context['moderated'] = ($pinned ? '已置顶' : '已取消置顶') . '、' . ($locked ? '已锁定回复' : '已允许回复');
        } elseif ($action === 'delete_topic') {
            $pdo->prepare('UPDATE topics SET deleted_at = NOW() WHERE id = ?')->execute([$topicId]);
            $back = ['group.php', ['id' => $groupId], '主题已删除。'];
        } else {
            if ($topic['is_locked'] && !$manager) {
                throw new DomainException('锁定的主题不能编辑。');
            }
            $title = post_string('title', 180);
            $content = post_string('content', 30000);
            if (text_length($title) < 2 || $content === '') {
                throw new DomainException('标题至少 2 个字符，正文不能为空。');
            }
            $pdo->prepare('UPDATE topics SET title = ?, content = ? WHERE id = ?')->execute([$title, $content, $topicId]);
        }
        // The audience is resolved inside the inbox layer: whoever wrote the topic is told about it.
        audit_notify($action, $user, 'topic', $topicId, $context);
        return $back;
    }
    $stmt = $pdo->prepare('SELECT * FROM replies WHERE id = ? AND topic_id = ? AND deleted_at IS NULL FOR UPDATE');
    $stmt->execute([$replyId, $topicId]);
    $reply = $stmt->fetch();
    if (!$reply || !$manager && (int) $reply['user_id'] !== (int) $user['id']) {
        throw new DomainException('无权修改该回复。');
    }
    if ($action === 'delete_reply') {
        $pdo->prepare('UPDATE replies SET deleted_at = NOW() WHERE id = ?')->execute([$replyId]);
    } else {
        if ($topic['is_locked'] && !$manager) {
            throw new DomainException('主题已锁定，不能编辑回复。');
        }
        $content = post_string('content', 30000);
        if ($content === '') {
            throw new DomainException('回复不能为空。');
        }
        $pdo->prepare('UPDATE replies SET content = ? WHERE id = ?')->execute([$content, $replyId]);
    }
    // Uses audit_notify so that a manager editing or deleting someone else's reply
    // still records the change and puts it in that author's inbox; a self-edit records only the audit entry.
    audit_notify($action, $user, 'reply', $replyId);
    return $back;
});
group_image_delete($replacedImage);
flash('success', $result[2]);
redirect($result[0], $result[1]);
