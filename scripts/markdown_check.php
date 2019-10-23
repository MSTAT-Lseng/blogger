<?php
declare (strict_types=1);
// Source-only smoke test for the Markdown renderer: php scripts/markdown_check.php
// It never starts PHP's web server, connects to MySQL, or makes HTTP requests.
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/markdown.php';
$failures = 0;
function check(string $name, string $expected, string $actual) : void
{
    global $failures;
    if ($expected === $actual) {
        return;
    }
    $failures++;
    echo "FAIL: {$name}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
}
function contains(string $name, string $needle, string $haystack) : void
{
    global $failures;
    if (strpos($haystack, $needle) !== false) {
        return;
    }
    $failures++;
    echo "FAIL: {$name}\n  missing: " . var_export($needle, true) . "\n  in:      " . var_export($haystack, true) . "\n";
}
function lacks(string $name, string $needle, string $haystack) : void
{
    global $failures;
    if (strpos($haystack, $needle) === false) {
        return;
    }
    $failures++;
    echo "FAIL: {$name}\n  unexpected: " . var_export($needle, true) . "\n  in:         " . var_export($haystack, true) . "\n";
}
$html = markdown_to_html("第一行\n第二行");
check('plain line break', "<p>第一行<br>\n第二行</p>\n", $html);
$html = markdown_to_html('**粗体** 和 *斜体* 和 ~~删除~~ 和 `code`');
check('inline emphasis', "<p><strong>粗体</strong> 和 <em>斜体</em> 和 <del>删除</del> 和 <code>code</code></p>\n", $html);
$html = markdown_to_html("# 标题一\n\n## 标题二");
check('headings', "<h1>标题一</h1>\n<h2>标题二</h2>\n", $html);
$html = markdown_to_html("- 苹果\n- 香蕉\n  - 嵌套\n- 橙子");
check('nested list', "<ul>\n<li>苹果</li>\n<li>香蕉\n<ul>\n<li>嵌套</li>\n</ul></li>\n<li>橙子</li>\n</ul>\n", $html);
$html = markdown_to_html("1. 一\n2. 二");
check('ordered list', "<ol>\n<li>一</li>\n<li>二</li>\n</ol>\n", $html);
$html = markdown_to_html("> 引用内容\n> 第二行");
contains('blockquote', '<blockquote>', $html);
contains('blockquote body', '引用内容', $html);
$html = markdown_to_html("```php\n<?php echo 'x';\n```");
check('fenced code', "<pre><code class=\"language-php\">&lt;?php echo &#039;x&#039;;</code></pre>\n", $html);
$html = markdown_to_html('| A | B |' . "\n" . '| --- | ---: |' . "\n" . '| 1 | 2 |');
contains('table header', '<th>A</th>', $html);
contains('table alignment', '<th style="text-align:right">B</th>', $html);
contains('table body', '<td>1</td>', $html);
$html = markdown_to_html("上面\n\n---\n\n下面");
contains('horizontal rule', '<hr>', $html);
$html = markdown_to_html('[番组计划](https://bgm.tv) 和 https://bgm.tv/a.');
contains('markdown link', '<a href="https://bgm.tv"', $html);
contains('autolink trims punctuation', '<a href="https://bgm.tv/a"', $html);
$html = markdown_to_html('[x](/group.php?id=3)');
contains('relative link', '<a href="/group.php?id=3"', $html);
$html = markdown_to_html('![图](https://bgm.tv/a.png)');
contains('image', '<img src="https://bgm.tv/a.png" alt="图"', $html);
// Escaping guarantees: user text must never become executable markup.
$drafts = ['<script>alert(1)</script>' => ['&lt;script&gt;alert(1)&lt;/script&gt;', '<script>'], '[x](javascript:alert(1))' => ['[x](javascript:alert(1))', 'href="javascript'], '![x](javascript:alert(1))' => ['![x](javascript:alert(1))', '<img'], '[x](&#106;avascript:alert(1))' => ['href="&amp;#106;avascript', 'href="&#106;'], '[x](javascript&colon;alert(1))' => ['href="javascript&amp;colon;', 'href="javascript:'], '[x](data:text/html;base64,PHNjcmlwdD4=)' => ['[x](data:text/html;base64,PHNjcmlwdD4=)', '<a href="data:'], '[x](vbscript:msgbox(1))' => ['[x](vbscript:msgbox(1))', 'href="vbscript'], '[x](https://a.com/"><script>alert(1)</script>)' => ['&lt;script&gt;alert(1', '<script>'], '![x](file:///etc/passwd)' => ['![x](file:///etc/passwd)', '<img'], '[x](java' . "\n" . 'script:alert(1))' => ['java<br>', 'href="javascript'], '<img src=x onerror=alert(1)>' => ['&lt;img src=x onerror=alert(1)&gt;', '<img src=x'], '`<script>alert(1)</script>`' => ['<code>&lt;script&gt;alert(1)&lt;/script&gt;</code>', '<script>'], "```\n<b>raw</b>\n```" => ['<pre><code>&lt;b&gt;raw&lt;/b&gt;</code></pre>', '<b>']];
foreach ($drafts as $draft => $expectations) {
    $rendered = markdown_to_html($draft);
    contains('escape: ' . $draft, $expectations[0], $rendered);
    lacks('no raw tag for: ' . $draft, $expectations[1], $rendered);
}
check('backslash escape', "<p>*不是斜体*</p>\n", markdown_to_html('\\*不是斜体\\*'));
check('placeholder marker stripped', "<p>xy 1</p>\n", markdown_to_html("x\x02y 1 \x02"));
check('plain text', '标题 粗体 和 链接', markdown_plain("# 标题\n\n**粗体** 和 [链接](https://a.com)"));
check('empty input', '', markdown_to_html("  \n "));
// Robustness: a 30 KB draft must render in bounded time and memory. The old
// nested-quantifier rule test tripped the PCRE backtrack limit and turned a run
// of "* " into a recursively nested list until memory ran out.
check('long marker run is a rule', "<hr>\n", markdown_to_html(str_repeat('* ', 5000)));
check('lone brackets stay text', '<p>' . str_repeat('[', 2000) . "</p>\n", markdown_to_html(str_repeat('[', 2000)));
$deep = markdown_to_html(str_repeat('> ', 500) . '正文');
contains('deep quote keeps text', '正文', $deep);
contains('deep quote stays quoted', '<blockquote>', $deep);
contains('long code span run survives', '<code>a</code>', markdown_to_html(str_repeat('`a', 5000)));
if ($failures > 0) {
    echo "Markdown check: {$failures} failure(s)\n";
    exit(1);
}
echo "Markdown check: OK\n";
