<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if (!is_post()) {
    redirect('login.php');
}
verify_csrf();
$action = post_string('action', 30);
if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    flash('success', '已登出');
    redirect('index.php');
}
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ipCheck = db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
$ipCheck->execute([$ipHash]);
if ((int) $ipCheck->fetchColumn() >= 30) {
    throw new DomainException('尝试次数过多，请 15 分钟后再试。');
}
if ($action === 'login') {
    $identity = post_string('identity', 190);
    $password = post_password('password');
    $identityHash = hash('sha256', utf8_lower($identity));
    $check = db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE identity_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $check->execute([$identityHash]);
    if ((int) $check->fetchColumn() >= 8) {
        throw new DomainException('该账号尝试次数过多，请 15 分钟后再试。');
    }
    $stmt = db()->prepare('SELECT id, password_hash, session_version, status FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        db()->prepare('INSERT INTO auth_attempts (identity_hash, ip_hash) VALUES (?, ?)')->execute([$identityHash, $ipHash]);
        flash('error', '账号或密码不正确，或账号已被停用');
        redirect('login.php');
    }
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    flash('success', '登录成功');
    if (isset($_SESSION['return_to']) && is_array($_SESSION['return_to'])) {
        $return = $_SESSION['return_to'];
        unset($_SESSION['return_to']);
        if (in_array($return[0], ['group.php', 'topic.php'], true)) {
            redirect($return[0], $return[1]);
        }
    }
    redirect('index.php');
}
if ($action === 'register') {
    if ((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() < 1) {
        throw new DomainException('请先由站点管理员完成初始化。');
    }
    $username = post_string('username', 40);
    $email = post_string('email', 190);
    $displayName = post_string('display_name', 80);
    $password = post_password('password');
    $confirmation = post_password('password_confirmation');
    if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $username)) {
        flash('error', '用户名只能包含字母、数字和下划线，最低3位、最高40位');
        redirect('register.php');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '请输入有效邮箱');
        redirect('register.php');
    }
    if ($displayName === '' || strlen($password) < 8 || strlen($password) > 72 || $password !== $confirmation) {
        flash('error', '请填写昵称，密码长度须为8~72字符且两次输入一致');
        redirect('register.php');
    }
    try {
        $avatar = user_avatar_upload();
    } catch (DomainException $exception) {
        flash('error', $exception->getMessage());
        redirect('register.php');
    }
    try {
        $userId = db_transaction(function (PDO $pdo) use($username, $email, $password, $displayName, $ipHash, $avatar) : int {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, display_name, avatar_path) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $displayName, $avatar]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO auth_attempts (identity_hash, ip_hash) VALUES (?, ?)')->execute([hash('sha256', 'register:' . $username), $ipHash]);
            return $id;
        });
    } catch (PDOException $exception) {
        // The avatar is already on disk; drop it so a failed insert leaves no orphan file.
        user_avatar_delete($avatar);
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        flash('error', '用户名或邮箱已被使用');
        redirect('register.php');
    }
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = 1;
    flash('success', '注册成功，欢迎加入');
    redirect('index.php');
}
http_response_code(400);
exit('无效操作。');
