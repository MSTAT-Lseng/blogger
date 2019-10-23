<?php
declare (strict_types=1);
// The inbox stores one row per recipient per event. Rows are never removed on read:
// read_at marks them as read and deleted_at hides them from the owner's own inbox.
const INBOX_PAGE_SIZE = 20;
const INBOX_BODY_LIMIT = 800;
const INBOX_NOTIFICATION_KINDS = ['reply' => '回复', 'quote' => '引用', 'topic' => '主题', 'group' => '小组', 'member' => '成员', 'review' => '审核', 'account' => '账号', 'system' => '系统'];
/**
 * Creates the notifications table on demand so an existing site gains the inbox
 * without a manual migration. install.php still ships the same definition in
 * database/schema.sql; this only fills the gap for already-installed databases.
 */
function inbox_ensure_schema(bool $force = false) : bool
{
    static $ready = false;
    if ($ready && !$force) {
        return true;
    }
    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if ($stmt->fetchColumn() !== false) {
            $ready = true;
            return true;
        }
        // CREATE TABLE IF NOT EXISTS is safe to repeat; the guard above keeps it to a single attempt.
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    kind VARCHAR(30) NOT NULL DEFAULT 'system',
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL DEFAULT '',
    target_type VARCHAR(30) NOT NULL DEFAULT '',
    target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, deleted_at, read_at, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
        $ready = true;
        return true;
    } catch (Throwable $exception) {
        error_log('[inbox] notification table unavailable: ' . (string) $exception);
        return false;
    }
}
function inbox_kind_label(string $kind) : string
{
    return INBOX_NOTIFICATION_KINDS[$kind] ?? '系统';
}
// Every notification body is stored as a single plain-text line so rendering stays trivial.
function inbox_plain_text(string $value) : string
{
    $text = trim(preg_replace('/\\s+/u', ' ', markdown_plain($value)) ?? '');
    if ($text === '') {
        return '';
    }
    if (text_length($text) > INBOX_BODY_LIMIT) {
        $text = utf8_substr($text, 0, INBOX_BODY_LIMIT) . '…';
    }
    return $text;
}
// Markdown constructs such as "## 标题" or "> 引用" read poorly inside a notification body.
function inbox_body_line(string $value) : string
{
    $text = ltrim(inbox_plain_text($value), "#>*-+ \t");
    return trim($text);
}
function inbox_display_name(array $user) : string
{
    $name = trim((string) ($user['display_name'] ?? ''));
    return $name !== '' ? $name : (string) ($user['username'] ?? '');
}
function inbox_user_label(int $userId) : string
{
    if ($userId < 1) {
        return '站点管理员';
    }
    $stmt = db()->prepare('SELECT username, display_name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ? inbox_display_name($user) : '已注销用户';
}
function inbox_target_url(string $type, int $id) : ?string
{
    if ($id < 1) {
        return null;
    }
    $routes = ['topic' => 'topic.php', 'group' => 'group.php', 'user' => 'user.php'];
    if (!isset($routes[$type])) {
        return null;
    }
    return url($routes[$type], ['id' => $id]);
}
function inbox_store(int $userId, int $actorId, string $kind, string $title, string $body, string $targetType, int $targetId) : void
{
    if ($userId < 1) {
        return;
    }
    if (!isset(INBOX_NOTIFICATION_KINDS[$kind])) {
        $kind = 'system';
    }
    if ($targetType !== '' && inbox_target_url($targetType, $targetId) === null) {
        $targetType = '';
        $targetId = 0;
    }
    $insert = static function () use($userId, $actorId, $kind, $title, $body, $targetType, $targetId) : void {
        $stmt = db()->prepare('INSERT INTO notifications (user_id, actor_id, kind, title, body, target_type, target_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $actorId > 0 ? $actorId : null, $kind, utf8_substr(trim($title) !== '' ? trim($title) : '新通知', 0, 180), inbox_plain_text($body), $targetType, $targetId]);
    };
    if (!inbox_ensure_schema()) {
        // The table may have appeared since the previous attempt, so check once more.
        inbox_ensure_schema(true);
    }
    try {
        $insert();
    } catch (PDOException $exception) {
        if (!inbox_ensure_schema(true)) {
            throw $exception;
        }
        $insert();
    }
}
// A single event can address several people; each recipient receives an individually worded row.
function inbox_notify(array $notifications) : array
{
    $recipients = [];
    foreach ($notifications as $notification) {
        if (!is_array($notification)) {
            continue;
        }
        $userId = (int) ($notification['user_id'] ?? 0);
        if ($userId < 1) {
            continue;
        }
        $actorId = (int) ($notification['actor_id'] ?? 0);
        // Nobody is notified about their own action, even when they are also the target.
        if ($actorId > 0 && $actorId === $userId) {
            continue;
        }
        inbox_store($userId, $actorId, (string) ($notification['kind'] ?? 'system'), (string) ($notification['title'] ?? '新通知'), (string) ($notification['body'] ?? ''), (string) ($notification['target_type'] ?? ''), (int) ($notification['target_id'] ?? 0));
        $recipients[$userId] = $userId;
    }
    return array_values($recipients);
}
function inbox_group_member_ids(int $groupId, array $exclude = []) : array
{
    $stmt = db()->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND status = 'approved'");
    $stmt->execute([$groupId]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
        $ids[(int) $memberId] = (int) $memberId;
    }
    foreach ($exclude as $userId) {
        unset($ids[(int) $userId]);
    }
    return array_values($ids);
}
// One notification per approved member, e.g. when the group they belong to disappears.
function inbox_notify_group_members(int $groupId, int $actorId, string $kind, string $title, string $body, string $targetType = '', int $targetId = 0, array $exclude = []) : array
{
    if ($groupId < 1) {
        return [];
    }
    $notifications = [];
    foreach (inbox_group_member_ids($groupId, $exclude) as $memberId) {
        $notifications[] = ['user_id' => $memberId, 'actor_id' => $actorId, 'kind' => $kind, 'title' => $title, 'body' => $body, 'target_type' => $targetType, 'target_id' => $targetId];
    }
    return inbox_notify($notifications);
}
// A new reply notifies the topic author and, for a quoted reply, the author of the quoted reply.
function inbox_notify_reply(int $replyId, int $actorId) : array
{
    $stmt = db()->prepare('SELECT r.topic_id, r.parent_id, r.content, t.id AS topic_key, t.title AS topic_title, t.user_id AS topic_author, g.id AS group_key, g.name AS group_name, p.user_id AS parent_author FROM replies r INNER JOIN topics t ON t.id = r.topic_id INNER JOIN discussion_groups g ON g.id = t.group_id LEFT JOIN replies p ON p.id = r.parent_id WHERE r.id = ?');
    $stmt->execute([$replyId]);
    $reply = $stmt->fetch();
    if (!$reply) {
        return [];
    }
    $actorLabel = inbox_user_label($actorId);
    $excerpt = inbox_body_line((string) $reply['content']);
    $topicUrl = inbox_target_url('topic', (int) $reply['topic_key']);
    $topicLink = $topicUrl === null ? '' : "\n" . $topicUrl;
    $notifications = [['user_id' => (int) $reply['topic_author'], 'actor_id' => $actorId, 'kind' => 'reply', 'title' => $actorLabel . ' 回复了你的主题「' . excerpt($reply['topic_title'], 60) . '」', 'body' => ($excerpt !== '' ? '回复内容：' . $excerpt : '') . $topicLink, 'target_type' => 'topic', 'target_id' => (int) $reply['topic_key']]];
    $parentAuthor = (int) ($reply['parent_author'] ?? 0);
    if ($parentAuthor > 0 && $parentAuthor !== (int) $reply['topic_author']) {
        $notifications[] = ['user_id' => $parentAuthor, 'actor_id' => $actorId, 'kind' => 'quote', 'title' => $actorLabel . ' 在「' . excerpt($reply['group_name'], 40) . '」引用了你的回复', 'body' => ($excerpt !== '' ? '引用内容：' . $excerpt : '') . $topicLink, 'target_type' => 'topic', 'target_id' => (int) $reply['topic_key']];
    }
    return inbox_notify($notifications);
}
// Maps one audit entry to the inbox rows the affected users should receive.
// Write-only actions never notify the person who performed them.
function inbox_dispatch_audit(string $action, int $actorId, string $targetType, int $targetId, array $context = []) : array
{
    if ($actorId < 1 || $targetId < 1) {
        return [];
    }
    // Recorded for the audit trail only: no user is waiting for this event.
    $silent = ['create_group', 'create_topic', 'request_public', 'reset_logs', 'clean_auth_attempts', 'review_public_approved', 'review_public_rejected', 'purge_group', 'purge_topic', 'purge_reply', 'remove_member', 'unblock_member'];
    if (in_array($action, $silent, true)) {
        return [];
    }
    $actorLabel = inbox_user_label($actorId);
    $notifications = [];
    if ($targetType === 'user') {
        // The caller passes the new value so the notice can say what actually changed.
        $value = (string) ($context['value'] ?? '');
        $titles = ['user_role' => $value === 'admin' ? $actorLabel . ' 将你设为站点管理员' : $actorLabel . ' 将你调整为普通用户', 'user_status' => $value === 'active' ? $actorLabel . ' 重新启用了你的账号' : $actorLabel . ' 停用了你的账号', 'reset_password' => $actorLabel . ' 重置了你的登录密码'];
        $bodies = ['user_role' => '你的站点权限发生了变化，已有登录会话会立即失效，请重新登录确认。', 'user_status' => $value === 'active' ? '账号已恢复正常，可以重新登录并使用站点功能。' : '账号已被停用，你将无法登录；如有疑问请联系站点管理员。', 'reset_password' => '请使用管理员提供的临时密码登录，并在登录后尽快修改密码。'];
        if (isset($titles[$action])) {
            $notifications[] = ['user_id' => $targetId, 'actor_id' => $actorId, 'kind' => 'account', 'title' => $titles[$action], 'body' => $bodies[$action] . "\n" . url('settings.php'), 'target_type' => 'user', 'target_id' => $targetId];
        }
        return inbox_notify($notifications);
    }
    if ($targetType === 'reply') {
        $stmt = db()->prepare('SELECT r.user_id, r.content, r.topic_id, t.title AS topic_title FROM replies r INNER JOIN topics t ON t.id = r.topic_id WHERE r.id = ?');
        $stmt->execute([$targetId]);
        $reply = $stmt->fetch();
        if (!$reply) {
            return [];
        }
        $excerpt = inbox_body_line((string) $reply['content']);
        $topicUrl = inbox_target_url('topic', (int) $reply['topic_id']);
        $titles = ['edit_reply' => '你的回复被 ' . $actorLabel . ' 编辑', 'delete_reply' => '你在主题「' . excerpt($reply['topic_title'], 60) . '」中的回复已被删除', 'restore_reply' => '你在主题「' . excerpt($reply['topic_title'], 60) . '」中的回复已恢复'];
        if (isset($titles[$action])) {
            $notifications[] = ['user_id' => (int) $reply['user_id'], 'actor_id' => $actorId, 'kind' => $action === 'edit_reply' ? 'reply' : 'system', 'title' => $titles[$action], 'body' => ($excerpt !== '' ? '回复内容：' . $excerpt : '') . ($topicUrl === null ? '' : "\n" . $topicUrl), 'target_type' => 'topic', 'target_id' => (int) $reply['topic_id']];
        }
        return inbox_notify($notifications);
    }
    if ($targetType === 'topic') {
        $stmt = db()->prepare('SELECT user_id, title FROM topics WHERE id = ?');
        $stmt->execute([$targetId]);
        $topic = $stmt->fetch();
        if (!$topic) {
            return [];
        }
        $topicTitle = excerpt($topic['title'], 60);
        $topicUrl = inbox_target_url('topic', $targetId);
        $titles = ['edit_topic' => '你的主题「' . $topicTitle . '」被 ' . $actorLabel . ' 编辑', 'delete_topic' => '你的主题「' . $topicTitle . '」已被删除', 'moderate_topic' => $actorLabel . ' 调整了你的主题「' . $topicTitle . '」的置顶或锁定状态', 'restore_topic' => '你的主题「' . $topicTitle . '」已恢复'];
        if (isset($titles[$action])) {
            $bodies = ['edit_topic' => '主题标题或正文已被修改，打开主题即可查看最新内容。', 'delete_topic' => '主题及其回复已对其他用户隐藏；站点管理员可以在回收站中恢复。', 'moderate_topic' => '置顶或锁定状态已变化，锁定后暂时无法新增回复。', 'restore_topic' => '主题已从回收站恢复，可以继续参与讨论。'];
            $notifications[] = ['user_id' => (int) $topic['user_id'], 'actor_id' => $actorId, 'kind' => 'topic', 'title' => $titles[$action], 'body' => $bodies[$action] . ($topicUrl === null ? '' : "\n" . $topicUrl), 'target_type' => 'topic', 'target_id' => $targetId];
        }
        return inbox_notify($notifications);
    }
    if ($targetType === 'group_member') {
        $notifications[] = inbox_member_notification($action, $targetId, (int) ($context['group_id'] ?? 0), $actorId, $actorLabel, $context);
        return inbox_notify(array_filter($notifications));
    }
    if ($targetType === 'group') {
        // A private group disappears for its members; they cannot open it any more.
        if ($action === 'delete_group') {
            return inbox_notify_group_members($targetId, $actorId, 'group', '「' . excerpt((string) ($context['group_name'] ?? ''), 60) . '」已被组长删除', '小组已从站点隐藏，成员和讨论都不再可见；如有疑问请联系组长。', 'group', $targetId, [$actorId]);
        }
        return [];
    }
    return [];
}
function inbox_member_notification(string $action, int $memberId, int $groupId, int $actorId, string $actorLabel, array $context) : ?array
{
    if ($groupId < 1 || $memberId < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT name, owner_id FROM discussion_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        return null;
    }
    $groupName = excerpt($group['name'], 60);
    $groupUrl = inbox_target_url('group', $groupId);
    $line = $groupUrl === null ? '' : "\n" . $groupUrl;
    if ($action === 'manage_membership') {
        $status = (string) ($context['status'] ?? '');
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return null;
        }
        return ['user_id' => $memberId, 'actor_id' => $actorId, 'kind' => 'member', 'title' => $status === 'approved' ? $actorLabel . ' 通过了你加入「' . $groupName . '」的申请' : '你加入「' . $groupName . '」的申请未被通过', 'body' => ($status === 'approved' ? '你现在可以阅读小组讨论、发表主题和回复。' : '你暂时无法加入该小组，如有疑问可以联系组长。') . $line, 'target_type' => 'group', 'target_id' => $groupId];
    }
    if ($action === 'member_role') {
        $role = (string) ($context['role'] ?? '');
        if (!in_array($role, ['member', 'moderator'], true)) {
            return null;
        }
        return ['user_id' => $memberId, 'actor_id' => $actorId, 'kind' => 'member', 'title' => $role === 'moderator' ? $actorLabel . ' 将你设为「' . $groupName . '」的组内管理员' : $actorLabel . ' 取消了你在「' . $groupName . '」的组内管理员身份', 'body' => $role === 'moderator' ? '你现在可以管理该小组的成员和讨论内容。' . $line : '你仍然是该小组的普通成员，可以继续参与讨论。' . $line, 'target_type' => 'group', 'target_id' => $groupId];
    }
    if ($action === 'transfer_group') {
        if ($memberId === (int) $group['owner_id']) {
            return ['user_id' => $memberId, 'actor_id' => $actorId, 'kind' => 'member', 'title' => $actorLabel . ' 将「' . $groupName . '」的组长权限转交给你', 'body' => '你现在可以管理小组资料、成员和公开申请。' . $line, 'target_type' => 'group', 'target_id' => $groupId];
        }
        return ['user_id' => $memberId, 'actor_id' => $actorId, 'kind' => 'member', 'title' => '「' . $groupName . '」的组长已变更为 ' . inbox_user_label((int) $group['owner_id']), 'body' => '小组管理权限已交接，你仍然是该小组的成员。' . $line, 'target_type' => 'group', 'target_id' => $groupId];
    }
    return null;
}
// Runs one inbox statement and, when the table is genuinely missing, creates it once and retries.
function inbox_guarded_query(string $sql, array $params = []) : PDOStatement
{
    inbox_ensure_schema();
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $exception) {
        if (!inbox_ensure_schema(true)) {
            throw $exception;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
function inbox_unread_count(int $userId) : int
{
    if ($userId < 1) {
        return 0;
    }
    return (int) inbox_guarded_query('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL AND deleted_at IS NULL', [$userId])->fetchColumn();
}
/** Unread count for decoration such as the header badge; never breaks the surrounding page. */
function inbox_unread_badge(int $userId) : int
{
    try {
        return inbox_unread_count($userId);
    } catch (Throwable $exception) {
        return 0;
    }
}
function inbox_total_count(int $userId, ?bool $unreadOnly = null) : int
{
    if ($userId < 1) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND deleted_at IS NULL';
    if ($unreadOnly === true) {
        $sql .= ' AND read_at IS NULL';
    } elseif ($unreadOnly === false) {
        $sql .= ' AND read_at IS NOT NULL';
    }
    return (int) inbox_guarded_query($sql, [$userId])->fetchColumn();
}
/** @return array<int, array<string, mixed>> */
function inbox_items(int $userId, int $limit, int $offset, ?bool $unreadOnly = null) : array
{
    if ($userId < 1) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $sql = "SELECT n.*, u.username AS actor_username, u.display_name AS actor_name, u.avatar_path AS actor_avatar FROM notifications n LEFT JOIN users u ON u.id = n.actor_id WHERE n.user_id = ? AND n.deleted_at IS NULL";
    if ($unreadOnly === true) {
        $sql .= ' AND n.read_at IS NULL';
    } elseif ($unreadOnly === false) {
        $sql .= ' AND n.read_at IS NOT NULL';
    }
    $sql .= ' ORDER BY n.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    return inbox_guarded_query($sql, [$userId])->fetchAll();
}
function inbox_mark_read(int $itemId, int $userId) : bool
{
    if ($itemId < 1) {
        return false;
    }
    return inbox_guarded_query('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL AND deleted_at IS NULL', [$itemId, $userId])->rowCount() > 0;
}
function inbox_mark_all_read(int $userId) : int
{
    if ($userId < 1) {
        return 0;
    }
    return inbox_guarded_query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND deleted_at IS NULL', [$userId])->rowCount();
}
function inbox_delete_item(int $itemId, int $userId) : bool
{
    if ($itemId < 1) {
        return false;
    }
    return inbox_guarded_query('UPDATE notifications SET deleted_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$itemId, $userId])->rowCount() > 0;
}
function inbox_delete_read(int $userId) : int
{
    if ($userId < 1) {
        return 0;
    }
    return inbox_guarded_query('UPDATE notifications SET deleted_at = NOW() WHERE user_id = ? AND read_at IS NOT NULL AND deleted_at IS NULL', [$userId])->rowCount();
}
// Audit trail plus inbox fan-out: call sites record the action and the affected people in one step.
function audit_notify(string $action, array $user, string $type, int $id, array $context = [], array $extraRecipients = []) : array
{
    audit($action, $user, $type, $id);
    $recipients = inbox_dispatch_audit($action, (int) ($user['id'] ?? 0), $type, $id, $context);
    $extraRecipients = array_diff(array_map('intval', $extraRecipients), $recipients, [(int) ($user['id'] ?? 0)]);
    if (!$extraRecipients) {
        return $recipients;
    }
    $notifications = [];
    foreach ($extraRecipients as $recipient) {
        if ($recipient < 1) {
            continue;
        }
        $notifications[] = ['user_id' => $recipient, 'actor_id' => (int) ($user['id'] ?? 0), 'kind' => 'member', 'title' => (string) ($context['extra_title'] ?? '小组通知'), 'body' => (string) ($context['extra_body'] ?? ''), 'target_type' => 'group', 'target_id' => (int) ($context['group_id'] ?? 0)];
    }
    return array_merge($recipients, inbox_notify($notifications));
}
