<?php
declare (strict_types=1);
function e($value) : string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function utf8_valid(string $value) : bool
{
    return preg_match('//u', $value) === 1;
}
function utf8_length(string $value) : int
{
    if ($value === '') {
        return 0;
    }
    $matches = [];
    return preg_match_all('/./us', $value, $matches) ?: 0;
}
function utf8_substr(string $value, int $start, int $length) : string
{
    if ($length <= 0 || $value === '') {
        return '';
    }
    $matches = [];
    preg_match_all('/./us', $value, $matches);
    return implode('', array_slice($matches[0] ?? [], $start, $length));
}
function utf8_lower(string $value) : string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}
function url(string $path = '', array $query = []) : string
{
    $base = APP_URL;
    if ($base === '') {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
    }
    $target = $base . '/' . ltrim($path, '/');
    if ($query) {
        $target .= (strpos($target, '?') === false ? '?' : '&') . http_build_query($query);
    }
    return $target === '/' ? $base ?: '/' : $target;
}
function redirect(string $path, array $query = []) : void
{
    header('Location: ' . url($path, $query));
    exit;
}
function flash(string $key, ?string $message = null) : ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}
function csrf_token() : string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function verify_csrf() : void
{
    $sent = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : '';
    if (!$sent || !hash_equals((string) ($_SESSION['_csrf'] ?? ''), $sent)) {
        http_response_code(419);
        exit('请求已过期，请返回页面后重试。');
    }
}
function csrf_field() : string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function post_string(string $key, int $maxLength = 5000) : string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !utf8_valid($value)) {
        throw new DomainException('输入格式无效。');
    }
    $value = trim($value);
    if (utf8_length($value) > $maxLength) {
        throw new DomainException('输入超过允许长度（' . $maxLength . ' 个字符）。');
    }
    return $value;
}
function text_length(string $value) : int
{
    return utf8_length($value);
}
function post_password(string $key) : string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || strpos($value, "\x00") !== false || strlen($value) > 72) {
        throw new DomainException('密码须为不超过72字符的有效文本');
    }
    // Passwords are byte strings: leading and trailing spaces must be preserved.
    return $value;
}
function post_int(string $key) : int
{
    $value = $_POST[$key] ?? null;
    return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : 0;
}
function current_user() : ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare("SELECT id, username, email, display_name, bio, avatar_path, role, session_version, created_at FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch() ?: null;
    if ($user && (int) $user['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
        $user = null;
    }
    if (!$user) {
        unset($_SESSION['user_id']);
    }
    return $user;
}
function require_login() : array
{
    $user = current_user();
    if (!$user) {
        flash('error', '请先登录');
        redirect('login.php');
    }
    return $user;
}
function require_admin() : array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('无权访问。');
    }
    return $user;
}
function is_admin() : bool
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}
// UID 1 is the account created by install.php; it is the site owner (站长) and outranks every other administrator.
const SITE_OWNER_ID = 1;
function is_site_owner(?array $user) : bool
{
    return $user !== null && (int) ($user['id'] ?? 0) === SITE_OWNER_ID;
}
function is_post() : bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
function format_time(string $value) : string
{
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i', $time) : $value;
}
function excerpt(string $value, int $length = 100) : string
{
    $value = trim(preg_replace('/\\s+/u', ' ', strip_tags($value)) ?: '');
    if (text_length($value) <= $length) {
        return $value;
    }
    return utf8_substr($value, 0, $length) . '…';
}
function group_is_manager(array $group, ?array $user = null) : bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'admin' || (int) $group['owner_id'] === (int) $user['id']) {
        return true;
    }
    $membership = group_membership((int) $group['id'], (int) $user['id']);
    return $membership && $membership['status'] === 'approved' && $membership['role'] === 'moderator';
}
function group_membership(int $groupId, int $userId) : ?array
{
    $stmt = db()->prepare('SELECT status, role FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    return $stmt->fetch() ?: null;
}
function group_is_owner(array $group, array $user) : bool
{
    return $user['role'] === 'admin' || (int) $group['owner_id'] === (int) $user['id'];
}
function group_can_write(array $group, array $user) : bool
{
    if (group_is_manager($group, $user)) {
        return true;
    }
    $membership = group_membership((int) $group['id'], (int) $user['id']);
    return $membership && $membership['status'] === 'approved';
}
function group_access(array $group, ?array $user = null) : bool
{
    $user = $user ?? current_user();
    if ((string) $group['visibility_status'] === 'public' || group_is_manager($group, $user)) {
        return true;
    }
    if (!$user) {
        return false;
    }
    $membership = group_membership((int) $group['id'], (int) $user['id']);
    return $membership && $membership['status'] === 'approved';
}
// Uploaded pictures (group icons, user avatars) share one validation and storage pipeline.
const UPLOAD_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const GROUP_IMAGE_DIRECTORY = 'assets/uploads/group';
const GROUP_IMAGE_DEFAULT = 'assets/images/no_icon.jpg';
const GROUP_IMAGE_MAX_BYTES = 2097152;
const USER_AVATAR_DIRECTORY = 'assets/uploads/avatar';
const USER_AVATAR_DEFAULT = 'assets/images/user_avatar.jpg';
const USER_AVATAR_MAX_BYTES = 2097152;
function upload_root() : string
{
    return dirname(__DIR__);
}
function group_image(array $group) : string
{
    return !empty($group['image_path']) ? (string) $group['image_path'] : GROUP_IMAGE_DEFAULT;
}
function stored_image(string $path) : ?string
{
    try {
        return image_path($path);
    } catch (DomainException $exception) {
        // A stored path whose file disappeared falls back to the default picture.
        return null;
    }
}
function user_avatar(?string $path) : string
{
    return stored_image((string) $path) ?? USER_AVATAR_DEFAULT;
}
function image_upload(string $field, string $directory, string $prefix, int $maxBytes) : ?string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file)) {
        return null;
    }
    $error = is_scalar($file['error'] ?? null) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = '图片不能超过 ' . (int) ($maxBytes / 1048576) . ' MB。';
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException($limit);
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException('图片上传失败（错误码 ' . $error . '），请重新选择文件。');
    }
    $temporary = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        throw new DomainException('上传的图片无效，请重新选择文件。');
    }
    $size = is_scalar($file['size'] ?? null) ? (int) $file['size'] : 0;
    if ($size < 1) {
        throw new DomainException('图片文件为空，请重新选择文件。');
    }
    if ($size > $maxBytes) {
        throw new DomainException($limit);
    }
    // The stored extension comes from the detected image type, never from the uploaded filename.
    $info = @getimagesize($temporary);
    $mime = is_array($info) && isset($info['mime']) ? strtolower((string) $info['mime']) : '';
    if ($mime === '' || !isset(UPLOAD_IMAGE_TYPES[$mime])) {
        throw new DomainException('只支持 JPG、PNG、GIF 或 WebP 图片。');
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = strtolower((string) finfo_file($finfo, $temporary));
            finfo_close($finfo);
            if ($detected !== '' && strpos($detected, 'image/') !== 0) {
                throw new DomainException('只支持 JPG、PNG、GIF 或 WebP 图片。');
            }
        }
    }
    $target = upload_root() . '/' . $directory;
    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
        throw new DomainException('无法保存图片，请联系站点管理员检查上传目录权限。');
    }
    $relative = $directory . '/' . $prefix . '-' . bin2hex(random_bytes(12)) . '.' . UPLOAD_IMAGE_TYPES[$mime];
    if (!move_uploaded_file($temporary, upload_root() . '/' . $relative)) {
        throw new DomainException('图片保存失败，请重试。');
    }
    @chmod(upload_root() . '/' . $relative, 0644);
    return image_path($relative);
}
function image_delete(?string $path, string $directory, string $prefix) : void
{
    if ($path === null || !preg_match('~^' . preg_quote($directory, '~') . '/' . preg_quote($prefix, '~') . '-[0-9a-f]{24}\\.(jpg|png|gif|webp)$~', $path)) {
        return;
    }
    $file = upload_root() . '/' . $path;
    if (is_file($file)) {
        @unlink($file);
    }
}
function group_image_upload(string $field = 'image_file') : ?string
{
    return image_upload($field, GROUP_IMAGE_DIRECTORY, 'group', GROUP_IMAGE_MAX_BYTES);
}
function group_image_delete(?string $path) : void
{
    image_delete($path, GROUP_IMAGE_DIRECTORY, 'group');
}
function user_avatar_upload(string $field = 'avatar_file') : ?string
{
    return image_upload($field, USER_AVATAR_DIRECTORY, 'avatar', USER_AVATAR_MAX_BYTES);
}
function user_avatar_delete(?string $path) : void
{
    image_delete($path, USER_AVATAR_DIRECTORY, 'avatar');
}
function image_path(string $path) : ?string
{
    if ($path === '') {
        return null;
    }
    $shipped = '~^(assets/images|assets/picker)/[\\p{L}\\p{N}_-]+\\.(jpg|jpeg|png|gif|webp)$~u';
    $groupIcon = '~^' . preg_quote(GROUP_IMAGE_DIRECTORY, '~') . '/group-[0-9a-f]{24}\\.(jpg|png|gif|webp)$~';
    $avatar = '~^' . preg_quote(USER_AVATAR_DIRECTORY, '~') . '/avatar-[0-9a-f]{24}\\.(jpg|png|gif|webp)$~';
    if (!preg_match($shipped, $path) && !preg_match($groupIcon, $path) && !preg_match($avatar, $path) || !is_file(upload_root() . '/' . $path)) {
        throw new DomainException('图片必须是站点自带的图片或已成功上传的图片文件。');
    }
    return $path;
}
function avatar_image(?string $path, string $displayName, int $size = 48) : string
{
    $image = stored_image((string) $path) ?? USER_AVATAR_DEFAULT;
    return '<img class="profileAvatar avatarNeue avatarSize' . $size . ' ll" src="' . e(url($image)) . '" width="' . $size . '" height="' . $size . '" alt="' . e($displayName) . '">';
}
function avatar_link(int $userId, string $displayName, int $size = 48, ?string $avatar = null) : string
{
    $size = $size >= 48 ? 48 : 40;
    return '<a class="avatar" href="' . e(url('user.php', ['id' => $userId])) . '" title="' . e($displayName) . '">' . avatar_image($avatar, $displayName, $size) . '</a>';
}
function visibility_label(string $status) : string
{
    return ['public' => '公开', 'pending' => '公开审核中', 'private' => '私有', 'rejected' => '公开未通过'][$status] ?? '私有';
}
function page_number(string $key = 'page') : int
{
    $value = $_GET[$key] ?? null;
    $page = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    return max(1, min(100000, $page ?: 1));
}
function paginate(string $path, array $query, int $page, int $total, int $size = 20, string $pageKey = 'page') : void
{
    $pages = max(1, (int) ceil($total / $size));
    echo '<div class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . e(url($path, array_merge($query, [$pageKey => $page - 1]))) . '">上一页</a> ';
    }
    echo '<span class="grey">当前第 ' . $page . ' / ' . $pages . ' 页，共 ' . $total . ' 个条目</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . e(url($path, array_merge($query, [$pageKey => $page + 1]))) . '">下一页</a>';
    }
    echo '</div>';
}
function audit(string $action, array $user, string $type, int $id) : void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, target_type, target_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], $action, $type, $id]);
}
