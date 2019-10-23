<?php
declare (strict_types=1);
function render_header(string $title, string $active = 'groups', bool $wide = false) : void
{
    $user = current_user();
    $error = flash('error');
    $success = flash('success');
    // A badge is decoration: a mailbox that cannot be read must not break the page.
    $unreadCount = $user ? inbox_unread_badge((int) $user['id']) : 0;
    $requestedQuery = $_GET['q'] ?? '';
    $headerQuery = is_string($requestedQuery) ? trim(utf8_substr($requestedQuery, 0, 100)) : '';
    ?>
<!doctype html>
<html lang="zh-CN" data-theme="light" data-theme-color="pink">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php 
    echo e($title);
    ?> - <?php 
    echo e(APP_NAME);
    ?></title>
    <script defer src="<?php 
    echo e(url('assets/app.js'));
    ?>"></script>
    <script defer src="<?php 
    echo e(url('assets/markdown.js'));
    ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?php 
    echo e(url('assets/bangumi.min.css'));
    ?>">
    <link rel="stylesheet" type="text/css" href="<?php 
    echo e(url('assets/app.css'));
    ?>">
</head>
<body class="bangumi" data-preview-url="<?php 
    echo e(url('preview.php'));
    ?>">
<div id="wrapperNeue" class="wrapperNeue<?php 
    echo $wide ? ' mainXL' : '';
    ?>">
<div id="headerNeue2">
    <div class="headerNeueInner clearit">
        <div class="bg musume_1"></div>
        <a href="<?php 
    echo e(url('index.php'));
    ?>" class="logo">Bangumi 番组计划</a>
        <div id="navNeue2"><div id="menuNeue">
            <ul id="navMenuNeue" class="clearit">
                <li><a href="<?php 
    echo e(url('index.php'));
    ?>" class="top chl <?php 
    echo $active === 'groups' ? 'focus' : '';
    ?>"><span>小组</span></a></li>
                <?php 
    if ($user) {
        ?>
                    <li><a href="<?php 
        echo e(url('create_group.php'));
        ?>" class="top chl <?php 
        echo $active === 'create' ? 'focus' : '';
        ?>"><span>创建小组</span></a></li>
                    <?php 
        if ($user['role'] === 'admin') {
            ?>
                        <li><a href="<?php 
            echo e(url('admin.php'));
            ?>" class="top chl <?php 
            echo $active === 'admin' ? 'focus' : '';
            ?>"><span>管理中心</span></a></li>
                    <?php 
        }
        ?>
                <?php 
    }
    ?>
            </ul>
        </div></div>
        <form method="get" action="<?php 
    echo e(url('index.php'));
    ?>" class="header-search" role="search">
            <input type="hidden" name="view" value="all">
            <label class="sr-only" for="header-search-query">搜索小组和话题</label>
            <span class="header-search-field">
                <input id="header-search-query" type="search" name="q" value="<?php 
    echo e($headerQuery);
    ?>" maxlength="100" placeholder="搜索小组、话题">
                <button type="submit">搜索</button>
            </span>
        </form>
        <div class="header-account">
            <?php 
    if ($user) {
        ?>
                <a href="<?php 
        echo e(url('inbox.php'));
        ?>" class="header-inbox-link">收件箱<?php 
        if ($unreadCount > 0) {
            ?><span class="inbox-badge"><?php 
            echo $unreadCount > 99 ? '99+' : (int) $unreadCount;
            ?></span><?php 
        }
        ?></a>
                <a href="<?php 
        echo e(url('user.php', ['id' => $user['id']]));
        ?>"><?php 
        echo e($user['display_name'] ?: $user['username']);
        ?></a>
                <a href="<?php 
        echo e(url('settings.php'));
        ?>">设置</a>
                <form method="post" action="<?php 
        echo e(url('auth.php'));
        ?>" class="inline-form">
                    <?php 
        echo csrf_field();
        ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="text-button">登出</button>
                </form>
            <?php 
    } else {
        ?>
                <a href="<?php 
        echo e(url('login.php'));
        ?>">登录</a>
                <a href="<?php 
        echo e(url('register.php'));
        ?>">注册</a>
            <?php 
    }
    ?>
        </div>
    </div>
</div>
<?php 
    if ($error || $success) {
        ?>
<div class="notice-wrap mainWrapper">
    <?php 
        if ($error) {
            ?><div class="notice error"><?php 
            echo e($error);
            ?></div><?php 
        }
        ?>
    <?php 
        if ($success) {
            ?><div class="notice success"><?php 
            echo e($success);
            ?></div><?php 
        }
        ?>
</div>
<?php 
    }
}
function render_footer() : void
{
    $footerUser = current_user();
    $footerUnread = $footerUser ? inbox_unread_badge((int) $footerUser['id']) : 0;
    ?>
<div id="footer">
    <ul id="footerLinks" class="clearit">
        <li><dl><dt>小组</dt><dd><a href="<?php 
    echo e(url('index.php'));
    ?>">随便看看</a></dd><dd><a href="<?php 
    echo e(url('index.php', ['view' => 'all']));
    ?>">所有小组</a></dd></dl></li>
        <li><dl><dt>参与讨论</dt><dd><a href="<?php 
    echo e(url('index.php', ['view' => 'mine']));
    ?>">参加的小组</a></dd><dd><a href="<?php 
    echo e(url('create_group.php'));
    ?>">创建小组</a></dd></dl></li>
        <li><dl><dt>账号</dt><?php 
    if ($footerUser) {
        ?><dd><a href="<?php 
        echo e(url('inbox.php'));
        ?>">收件箱<?php 
        echo $footerUnread > 0 ? '（' . (int) $footerUnread . '）' : '';
        ?></a></dd><?php 
    }
    ?><dd><a href="<?php 
    echo e(url('settings.php'));
    ?>">账号设置</a></dd><dd><a href="<?php 
    echo e(url('user.php'));
    ?>">个人主页</a></dd></dl></li>
    </ul>
    <div class="copyright"><small class="grey">© <?php 
    echo date('Y');
    ?> <?php 
    echo e(APP_NAME);
    ?>, some rights reserved.</small></div>
</div>
</div>
<div id="dock"><div class="content"><ul class="clearit"><li class="first"><a href="<?php 
    echo e(url('index.php'));
    ?>">小组首页</a></li><li><a href="<?php 
    echo e(url('index.php', ['view' => 'mine']));
    ?>">参加的小组</a> <a href="<?php 
    echo e(url('settings.php'));
    ?>">设置</a></li></ul></div></div>
</body>
</html>
<?php 
}
function render_image_upload_field(string $inputId, string $previewId, string $preview, int $maxBytes, string $hint) : void
{
    $accepted = implode(',', array_keys(UPLOAD_IMAGE_TYPES));
    ?>
    <div class="image-upload">
        <img id="<?php 
    echo e($previewId);
    ?>" class="image-upload-preview" src="<?php 
    echo e(url($preview));
    ?>" width="80" height="80" alt="图片预览">
        <div class="image-upload-meta">
            <input type="file" id="<?php 
    echo e($inputId);
    ?>" name="<?php 
    echo e($inputId);
    ?>" accept="<?php 
    echo e($accepted);
    ?>" data-image-preview="<?php 
    echo e($previewId);
    ?>" data-max-bytes="<?php 
    echo (int) $maxBytes;
    ?>" data-allowed="<?php 
    echo e($accepted);
    ?>" aria-describedby="<?php 
    echo e($inputId);
    ?>_hint">
            <p id="<?php 
    echo e($inputId);
    ?>_hint" class="hint"><?php 
    echo e($hint);
    ?></p>
        </div>
    </div>
    <?php 
}
function render_group_image_field(string $current = '') : void
{
    render_image_upload_field('image_file', 'group_image_preview', stored_image($current) ?? GROUP_IMAGE_DEFAULT, GROUP_IMAGE_MAX_BYTES, '支持 JPG、PNG、GIF、WebP，单张不超过 ' . (int) (GROUP_IMAGE_MAX_BYTES / 1048576) . ' MB。不选择文件时使用默认图标。');
}
function render_user_avatar_field(string $current = '') : void
{
    render_image_upload_field('avatar_file', 'user_avatar_preview', stored_image($current) ?? USER_AVATAR_DEFAULT, USER_AVATAR_MAX_BYTES, '支持 JPG、PNG、GIF、WebP，单张不超过 ' . (int) (USER_AVATAR_MAX_BYTES / 1048576) . ' MB。不上传时使用默认头像。');
}
