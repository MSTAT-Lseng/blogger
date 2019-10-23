<?php
declare (strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_login();
render_header('创建小组', 'create');
?>
<div id="main" class="mainWrapper">
    <div class="columns columns-center">
        <div class="column column-main">
            <div class="app-panel">
                <h1>创建一个新小组</h1>
                <form method="post" action="<?php 
echo e(url('actions.php'));
?>" enctype="multipart/form-data">
                    <?php 
echo csrf_field();
?>
                    <input type="hidden" name="action" value="create_group">
                    <div class="form-row"><label for="name">小组名称</label><input id="name" name="name" type="text" minlength="2" maxlength="120" required></div>
                    <div class="form-row"><label for="description">小组简介</label><textarea id="description" name="description" maxlength="5000" required></textarea></div>
                    <div class="form-row"><label for="image_file">小组图标</label><?php 
render_group_image_field();
?></div>
                    <div class="form-row"><label><input type="checkbox" name="request_public" value="1"> <span id="request_public_label">申请公开</span></label></div>
                    <button class="button primary" type="submit">创建小组</button>
                    <a class="button" href="<?php 
echo e(url('index.php'));
?>">取消</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php 
render_footer();
