<?php
declare (strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/inbox.php';
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $exception) : void {
    $validation = $exception instanceof DomainException;
    $requestId = strtoupper(bin2hex(random_bytes(5)));
    if (!$validation) {
        error_log('[request ' . $requestId . '] ' . (string) $exception);
    }
    http_response_code($validation ? 422 : 503);
    if ($validation) {
        $message = $exception->getMessage();
        echo '<!doctype html><html lang="zh-CN"><meta charset="UTF-8"><title>请求提示</title><body><h1>请求提示</h1><p>' . e($message) . '</p><a href="' . e(url('index.php')) . '">返回首页</a></body></html>';
        return;
    }
    $details = exception_details($exception);
    $suggestions = exception_suggestions($exception);
    $time = date('Y-m-d H:i:s T');
    $environment = exception_debug_enabled() ? '已开启本地诊断' : '生产保护模式';
    $safeMessage = exception_safe_message($exception);
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>暂时无法处理请求 - <?php 
    echo e(APP_NAME);
    ?></title>
    <link rel="stylesheet" type="text/css" href="<?php 
    echo e(url('assets/bangumi.min.css'));
    ?>">
    <link rel="stylesheet" type="text/css" href="<?php 
    echo e(url('assets/app.css'));
    ?>">
</head>
<body class="bangumi app-error-page">
<main class="app-error-shell" aria-labelledby="error-title">
    <section class="app-error-card">
        <div class="app-error-icon" aria-hidden="true">!</div>
        <p class="app-error-eyebrow">SERVICE TEMPORARILY UNAVAILABLE</p>
        <h1 id="error-title">暂时无法处理请求</h1>
        <p class="app-error-lead">服务遇到了暂时性故障。请稍后重试；如果问题持续存在，可以将下面的诊断编号提供给管理员。</p>
        <div class="app-error-actions">
            <a class="button primary" href="<?php 
    echo e(url('index.php'));
    ?>">返回首页</a>
            <button class="button" type="button" onclick="window.location.reload()">重新加载</button>
        </div>
        <details class="app-error-details" open>
            <summary>查看具体错误细节</summary>
            <dl class="app-error-meta">
                <div><dt>错误类型</dt><dd><?php 
    echo e($details['type']);
    ?></dd></div>
                <div><dt>错误代码</dt><dd><code><?php 
    echo e($details['code']);
    ?></code></dd></div>
                <div><dt>请求编号</dt><dd><code><?php 
    echo e($requestId);
    ?></code></dd></div>
                <div><dt>发生时间</dt><dd><?php 
    echo e($time);
    ?></dd></div>
                <div><dt>运行模式</dt><dd><?php 
    echo e($environment);
    ?></dd></div>
            </dl>
            <p class="app-error-message"><strong>诊断摘要：</strong><?php 
    echo e($safeMessage);
    ?></p>
            <?php 
    if ($suggestions) {
        ?>
                <div class="app-error-checklist">
                    <strong>建议检查</strong>
                    <ul>
                        <?php 
        foreach ($suggestions as $suggestion) {
            ?><li><?php 
            echo e($suggestion);
            ?></li><?php 
        }
        ?>
                    </ul>
                </div>
            <?php 
    }
    ?>
            <?php 
    if (exception_debug_enabled()) {
        ?>
                <p class="app-error-debug"><strong>本地调试信息：</strong><?php 
        echo e($exception->getMessage());
        ?></p>
            <?php 
    } else {
        ?>
                <p class="app-error-note">为保护数据库凭据和服务器路径，完整异常堆栈已写入服务器日志。</p>
            <?php 
    }
    ?>
        </details>
    </section>
</main>
</body>
</html>
<?php 
});
function exception_debug_enabled() : bool
{
    return config_value('APP_DEBUG', '') === '1' && in_array(strtolower(config_value('APP_ENV', 'production')), ['local', 'development', 'dev'], true);
}
function pdo_error_identifiers(PDOException $exception) : array
{
    $info = $exception->errorInfo ?? [];
    $identifiers = [$exception->getCode(), $info[0] ?? null, $info[1] ?? null];
    return array_values(array_unique(array_filter(array_map('strval', $identifiers), static function (string $value) : bool {
        return $value !== '';
    })));
}
function exception_details(Throwable $exception) : array
{
    if ($exception instanceof PDOException) {
        $info = $exception->errorInfo ?? [];
        $state = is_string($info[0] ?? null) ? $info[0] : '';
        $driverCode = is_scalar($info[1] ?? null) ? (string) $info[1] : '';
        $code = $state !== '' ? $state . ($driverCode !== '' ? ' / ' . $driverCode : '') : implode(' / ', pdo_error_identifiers($exception));
        return ['type' => '数据库连接或查询错误', 'code' => $code !== '' ? $code : 'PDO_EXCEPTION'];
    }
    return ['type' => '应用程序错误', 'code' => (string) $exception->getCode() ?: 'APPLICATION_ERROR'];
}
function exception_safe_message(Throwable $exception) : string
{
    if ($exception instanceof PDOException) {
        $identifiers = pdo_error_identifiers($exception);
        if (array_intersect($identifiers, ['1045', '28000'])) {
            return '数据库拒绝了当前连接，通常与数据库用户名、密码或授权主机不匹配有关。';
        }
        if (in_array('1049', $identifiers, true)) {
            return '数据库名称不存在，请确认配置中的 DB_NAME 与实际创建的数据库一致。';
        }
        if (array_intersect($identifiers, ['2002', '2003'])) {
            return '无法连接到 MySQL 服务，请确认主机、端口和数据库服务状态。';
        }
        if (in_array('42S02', $identifiers, true)) {
            return '数据库表不存在或结构未初始化，请导入 database/schema.sql。';
        }
        return '数据库暂时不可用，请检查连接参数、服务状态和数据库结构。';
    }
    return '应用程序遇到未预期的错误，请根据请求编号查看服务器日志。';
}
function exception_suggestions(Throwable $exception) : array
{
    if (!$exception instanceof PDOException) {
        return ['确认最近的代码或配置变更没有产生 PHP 致命错误。', '将请求编号提供给管理员，以便从服务器日志定位完整堆栈。'];
    }
    return ['确认 config/local.php 已创建，并核对 DB_HOST、DB_PORT、DB_NAME、DB_USER 和 DB_PASS。', '确认 MySQL 服务正在运行，且配置用户拥有目标数据库的访问权限。', '首次部署时执行 database/schema.sql，确保 users、discussion_groups 等表已创建。'];
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
