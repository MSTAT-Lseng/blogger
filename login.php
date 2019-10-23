<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
if (current_user()) {
    redirect('index.php');
}
render_header('登录', 'auth');
?>
<div id="main" class="mainWrapper">
    <div class="auth-box app-panel">
        <h1>登录</h1>
        <form method="post" action="<?php 
echo e(url('auth.php'));
?>">
            <?php 
echo csrf_field();
?>
            <input type="hidden" name="action" value="login">
            <div class="form-row"><label for="identity">用户名或邮箱</label><input id="identity" name="identity" type="text" required autofocus></div>
            <div class="form-row"><label for="password">密码</label><input id="password" name="password" type="password" required></div>
            <button class="button primary" type="submit">登录</button>
            <a class="button" href="<?php 
echo e(url('register.php'));
?>">注册新账号</a>
        </form>
    </div>
</div>
<?php 
render_footer();
