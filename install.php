<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
// A configured secret and an empty user table are required for the first installation.
if (strlen(INSTALL_TOKEN) < 24) {
    http_response_code(403);
    exit('请在 config/local.php 中配置数据库和至少 24 位随机 INSTALL_TOKEN。');
}
$installError = null;
$pdo = null;
try {
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        http_response_code(403);
        exit('站点已初始化，安装入口已锁定。');
    }
} catch (PDOException $exception) {
    if ($exception->getCode() !== '42S02') {
        error_log((string) $exception);
        $installError = '无法连接数据库，请检查配置，并先创建空数据库。';
    }
}
if (is_post() && $pdo && !$installError) {
    verify_csrf();
    if (!hash_equals(INSTALL_TOKEN, post_string('install_token', 255))) {
        http_response_code(403);
        exit('安装密钥错误。');
    }
    $username = post_string('username', 40);
    $email = post_string('email', 190);
    $displayName = post_string('display_name', 80);
    $password = post_password('password');
    if (!preg_match('/^[A-Za-z0-9_]{3,40}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || strlen($password) > 72 || $displayName === '') {
        $installError = '请完整填写管理员信息；用户名 3-40 位字母数字或下划线，密码 8-72 字节。';
    } else {
        $lockName = 'blogger-install-' . substr(hash('sha256', DB_NAME), 0, 32);
        $locked = false;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute([$lockName]);
            $locked = (int) $lock->fetchColumn() === 1;
            if (!$locked) {
                throw new DomainException('另一个安装请求正在执行，请稍后重试。');
            }
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Missing schema');
            }
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                throw new DomainException('安装已由另一个请求完成。');
            }
            db_transaction(function (PDO $pdo) use($username, $email, $password, $displayName) : void {
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, display_name, role) VALUES (?, ?, ?, ?, 'admin')");
                $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $displayName]);
            });
            flash('success', '安装完成，请登录管理员账号');
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $installError = $exception instanceof DomainException ? $exception->getMessage() : '初始化失败，请检查 MySQL 权限和服务器日志。';
        } finally {
            if ($locked) {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            }
        }
        if (!$installError) {
            redirect('login.php');
        }
    }
}
unset($_SESSION['user_id']);
render_header('初始化安装', 'auth');
?>
<div id="main" class="mainWrapper"><div class="auth-box app-panel">
    <h1>初始化站点</h1>
    <?php 
if ($installError) {
    ?><div class="notice error"><?php 
    echo e($installError);
    ?></div><?php 
}
?>
    <form method="post">
        <?php 
echo csrf_field();
?>
        <div class="form-row"><label for="install_token">安装密钥</label><input id="install_token" name="install_token" type="password" autocomplete="off" required></div>
        <div class="form-row"><label for="username">管理员用户名</label><input id="username" name="username" type="text" pattern="[A-Za-z0-9_]{3,40}" required></div>
        <div class="form-row"><label for="display_name">显示昵称</label><input id="display_name" name="display_name" type="text" maxlength="80" required></div>
        <div class="form-row"><label for="email">邮箱</label><input id="email" name="email" type="email" maxlength="190" required></div>
        <div class="form-row"><label for="password">管理员密码</label><input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>
        <button class="button primary" type="submit">初始化并创建管理员</button>
    </form>
</div></div>
<?php 
render_footer();
