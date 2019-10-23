<?php
declare (strict_types=1);
// Renders an editor draft with the same Markdown pipeline the topic page uses, so
// the preview can never disagree with the published post. Login + CSRF protected.
require_once __DIR__ . '/app/bootstrap.php';
if (!is_post()) {
    header('Allow: POST');
    http_response_code(405);
    exit('请使用 POST 请求。');
}
verify_csrf();
require_login();
header('Content-Type: text/html; charset=UTF-8');
try {
    $content = post_string('content', 30000);
} catch (DomainException $exception) {
    echo '<p class="grey">' . e($exception->getMessage()) . '</p>';
    exit;
}
if ($content === '') {
    echo '<p class="grey">还没有内容。</p>';
    exit;
}
echo markdown_to_html($content);
