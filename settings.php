<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
$user = require_login();
if (is_post()) {
    verify_csrf();
    $name = post_string('display_name', 80);
    $bio = post_string('bio', 500);
    $email = post_string('email', 190);
    $password = post_password('current_password');
    $newPassword = post_password('new_password');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('请填写昵称和有效邮箱。');
    }
    if ($newPassword !== '' && (strlen($newPassword) < 8 || $newPassword !== post_password('password_confirmation'))) {
        throw new DomainException('新密码 8-72 字节，且两次输入一致。');
    }
    $uploadedAvatar = user_avatar_upload();
    $replacedAvatar = null;
    try {
        $version = db_transaction(function (PDO $pdo) use($user, $name, $bio, $email, $password, $newPassword, $uploadedAvatar, &$replacedAvatar) : int {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$user['id']]);
            $stored = $stmt->fetch();
            if (!$stored || $stored['status'] !== 'active' || (int) $stored['session_version'] !== (int) $user['session_version']) {
                throw new DomainException('账号状态已变更，请重新登录。');
            }
            if ($stored['email'] !== $email || $newPassword !== '') {
                if (!password_verify($password, $stored['password_hash'])) {
                    throw new DomainException('修改邮箱或密码需要正确的当前密码。');
                }
            }
            $storedAvatar = (string) ($stored['avatar_path'] ?? '');
            if ($uploadedAvatar !== null) {
                $avatar = $uploadedAvatar;
            } elseif (post_string('remove_avatar', 1) === '1') {
                $avatar = null;
            } else {
                $avatar = $storedAvatar !== '' ? $storedAvatar : null;
            }
            if ($avatar !== $storedAvatar && $storedAvatar !== '') {
                $replacedAvatar = $storedAvatar;
            }
            $version = (int) $stored['session_version'] + ($newPassword !== '' ? 1 : 0);
            $hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_BCRYPT) : $stored['password_hash'];
            $pdo->prepare('UPDATE users SET display_name = ?, bio = ?, email = ?, avatar_path = ?, password_hash = ?, session_version = ? WHERE id = ?')->execute([$name, $bio, $email, $avatar, $hash, $version, $user['id']]);
            return $version;
        });
    } catch (Throwable $exception) {
        // The uploaded avatar is already on disk; drop it when the save does not commit.
        user_avatar_delete($uploadedAvatar);
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            throw new DomainException('邮箱已被其他账号使用。');
        }
        throw $exception;
    }
    // The replaced avatar is unlinked only after the transaction commits.
    user_avatar_delete($replacedAvatar);
    $_SESSION['session_version'] = $version;
    session_regenerate_id(true);
    flash('success', '账号资料已保存，如果有密码变更会使其他会话失效');
    redirect('settings.php');
}
render_header('账号设置', 'account');
?>
<div id="main" class="mainWrapper"><div class="auth-box app-panel"><h1>账号设置</h1><p class="tip">用户名：<?php 
echo e($user['username']);
?></p>
    <form method="post" enctype="multipart/form-data"><?php 
echo csrf_field();
?>
        <div class="form-row"><label for="display_name">昵称</label><input id="display_name" name="display_name" value="<?php 
echo e($user['display_name']);
?>" required maxlength="80"></div>
        <div class="form-row"><label for="avatar_file">头像</label><?php 
render_user_avatar_field((string) ($user['avatar_path'] ?? ''));
if ((string) ($user['avatar_path'] ?? '') !== '') {
    ?><label class="image-upload-remove"><input type="checkbox" name="remove_avatar" value="1"> 改用默认头像</label><?php 
}
?></div>
        <div class="form-row"><label for="bio">签名</label><textarea id="bio" name="bio" maxlength="500"><?php 
echo e($user['bio']);
?></textarea></div>
        <div class="form-row"><label for="email">邮箱</label><input id="email" name="email" type="email" value="<?php 
echo e($user['email']);
?>" required maxlength="190"></div>
        <div class="form-row"><label for="current_password">当前密码（改邮箱或密码时填写）</label><input id="current_password" name="current_password" type="password" autocomplete="current-password"></div>
        <div class="form-row"><label for="new_password">新密码（留空保留原密码）</label><input id="new_password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password"></div>
        <div class="form-row"><label for="password_confirmation">确认新密码</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password"></div>
        <button class="button primary">保存设置</button>
    </form>
</div></div>
<?php 
render_footer();
