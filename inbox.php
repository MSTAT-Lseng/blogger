<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$userId = (int) $user['id'];
$allowedTabs = ['all' => null, 'unread' => true, 'read' => false];
$requestedTab = $_GET['tab'] ?? '';
$tab = is_string($requestedTab) && isset($allowedTabs[$requestedTab]) ? $requestedTab : 'all';
if (is_post()) {
    verify_csrf();
    $action = post_string('action', 30);
    $requestedRedirect = post_string('tab', 20);
    $redirectTab = isset($allowedTabs[$requestedRedirect]) ? $requestedRedirect : 'all';
    if ($action === 'inbox_read') {
        $target = post_string('target', 400);
        $marked = inbox_mark_read(post_int('notification_id'), $userId);
        // Following a notification succeeds even when it was already read elsewhere.
        if ($target !== '' && strpos($target, '/') === 0 && strpos($target, '//') !== 0) {
            header('Location: ' . $target);
            exit;
        }
        flash($marked ? 'success' : 'error', $marked ? '已标记为已读' : '没有需要更新的通知');
        redirect('inbox.php', ['tab' => $redirectTab]);
    }
    if ($action === 'inbox_delete') {
        $deleted = inbox_delete_item(post_int('notification_id'), $userId);
        flash($deleted ? 'success' : 'error', $deleted ? '通知已删除' : '通知不存在或已删除');
        redirect('inbox.php', ['tab' => $redirectTab]);
    }
    if ($action === 'inbox_read_all') {
        $count = inbox_mark_all_read($userId);
        flash('success', $count > 0 ? '已将 ' . $count . ' 条通知标记为已读' : '没有未读通知');
        redirect('inbox.php', ['tab' => $redirectTab]);
    }
    if ($action === 'inbox_delete_read') {
        $count = inbox_delete_read($userId);
        flash('success', $count > 0 ? '已删除 ' . $count . ' 条已读通知' : '没有可删除的已读通知');
        redirect('inbox.php', ['tab' => $redirectTab]);
    }
    http_response_code(400);
    exit('无效操作。');
}
$unreadTotal = inbox_unread_count($userId);
$allTotal = inbox_total_count($userId);
$readTotal = max(0, $allTotal - $unreadTotal);
$parsed = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = max(1, min(100000, is_int($parsed) && $parsed > 0 ? $parsed : 1));
$filter = $allowedTabs[$tab];
$total = $tab === 'unread' ? $unreadTotal : ($tab === 'read' ? $readTotal : $allTotal);
$pages = max(1, (int) ceil($total / INBOX_PAGE_SIZE));
$page = min($page, $pages);
$items = inbox_items($userId, INBOX_PAGE_SIZE, ($page - 1) * INBOX_PAGE_SIZE, $filter);
render_header('收件箱', 'inbox');
?>
<div id="main" class="mainWrapper">
    <div class="app-panel inbox-panel">
        <h1 id="inbox_title">收件箱</h1>
        <p class="hint" id="inbox_hint">收到的回复、引用与管理员操作都会出现在这里。</p>
        <ul class="secTab inbox-tabs">
            <li><a href="<?php 
echo e(url('inbox.php', ['tab' => 'all']));
?>" class="<?php 
echo $tab === 'all' ? 'selected' : '';
?>"><span>全部 <?php 
echo (int) $allTotal;
?></span></a></li>
            <li><a href="<?php 
echo e(url('inbox.php', ['tab' => 'unread']));
?>" class="<?php 
echo $tab === 'unread' ? 'selected' : '';
?>"><span>未读 <?php 
echo (int) $unreadTotal;
?></span></a></li>
            <li><a href="<?php 
echo e(url('inbox.php', ['tab' => 'read']));
?>" class="<?php 
echo $tab === 'read' ? 'selected' : '';
?>"><span>已读 <?php 
echo (int) $readTotal;
?></span></a></li>
        </ul>
        <div class="clear"></div>
        <div class="inbox-bulk">
            <?php 
if ($unreadTotal > 0) {
    ?>
            <form class="inline-form" method="post" action="<?php 
    echo e(url('inbox.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="inbox_read_all"><input type="hidden" name="tab" value="<?php 
    echo e($tab);
    ?>"><button class="button">全部标为已读</button></form>
            <?php 
}
?>
            <?php 
if ($readTotal > 0) {
    ?>
            <form class="inline-form" method="post" action="<?php 
    echo e(url('inbox.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="inbox_delete_read"><input type="hidden" name="tab" value="<?php 
    echo e($tab);
    ?>"><button class="button danger" data-confirm="删除全部已读通知？删除后不可恢复。">删除已读通知</button></form>
            <?php 
}
?>
        </div>
        <ul class="inbox-list">
        <?php 
if (!$items) {
    ?>
            <li class="empty"><?php 
    echo $tab === 'unread' ? '没有未读通知。' : ($tab === 'read' ? '还没有已读通知。' : '收件箱是空的。');
    ?></li>
        <?php 
}
?>
        <?php 
foreach ($items as $item) {
    $isUnread = $item['read_at'] === null;
    $kindLabel = inbox_kind_label((string) $item['kind']);
    $actorName = trim((string) ($item['actor_name'] ?? '')) !== '' ? (string) $item['actor_name'] : (string) ($item['actor_username'] ?? '');
    $targetUrl = inbox_target_url((string) $item['target_type'], (int) $item['target_id']);
    $isSelf = (int) ($item['actor_id'] ?? 0) === $userId;
    $selfUrl = url('user.php', ['id' => $userId]);
    $actorUrl = $isSelf || (int) ($item['actor_id'] ?? 0) < 1 ? $selfUrl : url('user.php', ['id' => (int) $item['actor_id']]);
    ?>
            <li class="inbox-item<?php 
    echo $isUnread ? ' unread' : '';
    ?>">
                <div class="inbox-avatar"><?php 
    echo avatar_image($item['actor_avatar'] ?? null, $actorName !== '' ? $actorName : '系统', 40);
    ?></div>
                <div class="inbox-main">
                    <p class="inbox-title">
                        <?php 
    if ($isUnread) {
        ?>
                        <form class="inline-form" method="post" action="<?php 
        echo e(url('inbox.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="inbox_read"><input type="hidden" name="notification_id" value="<?php 
        echo (int) $item['id'];
        ?>"><input type="hidden" name="tab" value="<?php 
        echo e($tab);
        ?>"><input type="hidden" name="target" value="<?php 
        echo e((string) ($targetUrl ?? ''));
        ?>"><button type="submit" class="inbox-title-link"><?php 
        echo e($item['title']);
        ?></button></form>
                        <?php 
    } elseif ($targetUrl !== null) {
        ?>
                        <a href="<?php 
        echo e($targetUrl);
        ?>"><?php 
        echo e($item['title']);
        ?></a>
                        <?php 
    } else {
        ?>
                        <span><?php 
        echo e($item['title']);
        ?></span>
                        <?php 
    }
    ?>
                        <?php 
    if ($isUnread) {
        ?><span class="inbox-dot" aria-label="未读">未读</span><?php 
    }
    ?>
                    </p>
                    <?php 
    if ((string) $item['body'] !== '') {
        ?><div class="inbox-body"><?php 
        echo nl2br(e($item['body']));
        ?></div><?php 
    }
    ?>
                    <p class="inbox-meta">
                        <span class="tag"><?php 
    echo e($kindLabel);
    ?></span>
                        <a href="<?php 
    echo e($actorUrl);
    ?>"><?php 
    echo e($actorName !== '' ? $actorName : '站点管理员');
    ?></a>
                        · <?php 
    echo e(format_time($item['created_at']));
    ?>
                        <?php 
    if (!$isUnread) {
        ?> · 已读 <?php 
        echo e(format_time((string) $item['read_at']));
    }
    ?>
                    </p>
                    <div class="inbox-actions">
                        <?php 
    if ($targetUrl !== null) {
        ?><a class="button" href="<?php 
        echo e($targetUrl);
        ?>">查看详情</a><?php 
    }
    ?>
                        <?php 
    if ($isUnread) {
        ?>
                        <form class="inline-form" method="post" action="<?php 
        echo e(url('inbox.php'));
        ?>"><?php 
        echo csrf_field();
        ?><input type="hidden" name="action" value="inbox_read"><input type="hidden" name="notification_id" value="<?php 
        echo (int) $item['id'];
        ?>"><input type="hidden" name="tab" value="<?php 
        echo e($tab);
        ?>"><input type="hidden" name="target" value=""><button class="button">标为已读</button></form>
                        <?php 
    }
    ?>
                        <form class="inline-form" method="post" action="<?php 
    echo e(url('inbox.php'));
    ?>"><?php 
    echo csrf_field();
    ?><input type="hidden" name="action" value="inbox_delete"><input type="hidden" name="notification_id" value="<?php 
    echo (int) $item['id'];
    ?>"><input type="hidden" name="tab" value="<?php 
    echo e($tab);
    ?>"><button class="button danger" data-confirm="删除这条通知？删除后不可恢复。">删除</button></form>
                    </div>
                </div>
            </li>
        <?php 
}
?>
        </ul>
        <?php 
paginate('inbox.php', ['tab' => $tab], $page, $total, INBOX_PAGE_SIZE);
?>
    </div>
</div>
<?php 
render_footer();
