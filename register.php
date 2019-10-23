<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
if (current_user()) {
    redirect('index.php');
}
render_header('注册', 'auth');
?>
<div id="main" class="mainWrapper">
    <div class="auth-box app-panel">
        <h1>注册账号</h1>
        <form method="post" action="<?php 
echo e(url('auth.php'));
?>" enctype="multipart/form-data">
            <?php 
echo csrf_field();
?>
            <input type="hidden" name="action" value="register">
            <div class="form-row"><label for="username">用户名</label><input id="username" name="username" type="text" required pattern="[A-Za-z0-9_]{3,40}"><span class="hint">3-40 位字母、数字或下划线。</span></div>
            <div class="form-row"><label for="display_name">显示昵称</label><input id="display_name" name="display_name" type="text" required maxlength="80"></div>
            <div class="form-row"><label for="avatar_file">头像</label><?php 
render_user_avatar_field();
?></div>
            <div class="form-row"><label for="email">邮箱</label><input id="email" name="email" type="email" required maxlength="190"></div>
            <div class="form-row"><label for="password">密码</label><input id="password" name="password" type="password" required minlength="8"></div>
            <div class="form-row"><label for="password_confirmation">确认密码</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="8"></div>
            <button class="button primary" type="submit">创建账号</button>
            <a class="button" href="<?php 
echo e(url('login.php'));
?>">返回登录</a>
        </form>
    </div>
</div>
<?php 
render_footer();
