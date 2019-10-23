<?php
declare (strict_types=1);
/**
 * Dependency-free Markdown subset renderer for group topics and replies.
 *
 * The renderer escapes its input before generating any markup, so a post can never
 * inject raw HTML: the only tags that reach the browser are the ones this file emits.
 * Supported syntax: headings, emphasis, strikethrough, inline code, fenced code
 * blocks, links, images, autolinks, ordered/unordered lists, blockquotes, tables
 * and horizontal rules. A single newline inside a paragraph renders as <br>, which
 * keeps the plain-text line breaks older posts relied on.
 */
const MARKDOWN_INLINE_DEPTH_LIMIT = 4;
const MARKDOWN_BLOCK_DEPTH_LIMIT = 12;
const MARKDOWN_MAX_URL_LENGTH = 2048;
function markdown_to_html(string $text) : string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // \x02 and \x03 are internal placeholder markers for inline spans and table cells.
    $text = str_replace(["\x02", "\x03"], '', $text);
    if (trim($text) === '') {
        return '';
    }
    return markdown_blocks(explode("\n", $text));
}
/** Markdown rendered down to plain text, for excerpts and quotes. */
function markdown_plain(string $text) : string
{
    $plain = html_entity_decode(strip_tags(markdown_to_html($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\\s+/u', ' ', $plain) ?: '');
}
/** Syntax reminder rendered under every Markdown editor. */
function markdown_editor_hint() : string
{
    return '支持 Markdown 格式。';
}
function markdown_blocks(array $lines, int $depth = 0) : string
{
    // Bound nesting so a pathological draft cannot recurse until memory runs out.
    if ($depth > MARKDOWN_BLOCK_DEPTH_LIMIT) {
        $flattened = array_values(array_filter(array_map('trim', $lines), static function (string $line) : bool {
            return $line !== '';
        }));
        return $flattened ? '<p>' . implode("<br>\n", array_map('markdown_inline', $flattened)) . "</p>\n" : '';
    }
    $html = '';
    $total = count($lines);
    $index = 0;
    while ($index < $total) {
        if (trim($lines[$index]) === '') {
            $index++;
            continue;
        }
        $fence = markdown_fence_open($lines[$index]);
        if ($fence !== null) {
            $index++;
            $code = [];
            while ($index < $total && !markdown_fence_close($lines[$index], $fence)) {
                $code[] = $lines[$index];
                $index++;
            }
            if ($index < $total) {
                $index++;
            }
            $html .= markdown_code_block(implode("\n", $code), $fence['info']);
            continue;
        }
        if (preg_match('/^ {0,3}(#{1,6})[ \\t]+(.*)$/', $lines[$index], $match)) {
            $level = strlen($match[1]);
            $title = trim((string) preg_replace('/[ \\t]+#+[ \\t]*$/', '', $match[2]));
            if ($title !== '') {
                $html .= '<h' . $level . '>' . markdown_inline($title) . '</h' . $level . ">\n";
                $index++;
                continue;
            }
        }
        if (markdown_is_rule($lines[$index])) {
            $html .= "<hr>\n";
            $index++;
            continue;
        }
        if (preg_match('/^ {0,3}>/', $lines[$index])) {
            $quoted = [];
            while ($index < $total) {
                if (preg_match('/^ {0,3}>[ \\t]?(.*)$/', $lines[$index], $match)) {
                    $quoted[] = $match[1];
                    $index++;
                    continue;
                }
                if (trim($lines[$index]) === '') {
                    break;
                }
                // Lazy continuation: an unmarked line still belongs to the quote.
                $quoted[] = $lines[$index];
                $index++;
            }
            $html .= "<blockquote>\n" . markdown_blocks($quoted, $depth + 1) . "</blockquote>\n";
            continue;
        }
        if (markdown_list_item($lines[$index]) !== null) {
            $html .= markdown_list($lines, $index, $depth);
            continue;
        }
        $table = markdown_table_block($lines, $index);
        if ($table !== null) {
            $html .= $table;
            continue;
        }
        $paragraph = [];
        while ($index < $total) {
            if (trim($lines[$index]) === '') {
                break;
            }
            if ($paragraph && markdown_starts_block($lines, $index)) {
                break;
            }
            $paragraph[] = trim($lines[$index]);
            $index++;
        }
        if ($paragraph) {
            $html .= '<p>' . implode("<br>\n", array_map('markdown_inline', $paragraph)) . "</p>\n";
        }
    }
    return $html;
}
function markdown_fence_open(string $line) : ?array
{
    if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \\t]*([^\\s`~]*)[ \\t]*$/', $line, $match)) {
        return ['char' => $match[1][0], 'length' => strlen($match[1]), 'info' => $match[2]];
    }
    return null;
}
function markdown_fence_close(string $line, array $fence) : bool
{
    $pattern = '/^ {0,3}' . preg_quote(str_repeat($fence['char'], $fence['length']), '/') . preg_quote($fence['char'], '/') . '*[ \\t]*$/';
    return (bool) preg_match($pattern, $line);
}
function markdown_code_block(string $code, string $info) : string
{
    $language = preg_match('/^[A-Za-z0-9+#._-]{1,32}$/', $info) === 1 ? ' class="language-' . e(strtolower($info)) . '"' : '';
    return '<pre><code' . $language . '>' . e($code) . "</code></pre>\n";
}
function markdown_is_rule(string $line) : bool
{
    // Written without nested quantifiers: a long run of one marker character must
    // stay linear instead of tripping the PCRE backtrack limit on 30 KB of input.
    $stripped = str_replace(["\t", ' '], '', $line);
    if (strlen($stripped) < 3 || strspn($line, " \t") > 3) {
        return false;
    }
    $marker = $stripped[0];
    if ($marker !== '*' && $marker !== '-' && $marker !== '_') {
        return false;
    }
    return $stripped === str_repeat($marker, strlen($stripped));
}
function markdown_indent(string $line) : int
{
    $expanded = str_replace("\t", '    ', $line);
    return strlen($expanded) - strlen(ltrim($expanded, ' '));
}
function markdown_list_item(string $line) : ?array
{
    if (!preg_match('/^([ \\t]*)([-*+]|\\d{1,9}[.)])([ \\t]+)(.*)$/', $line, $match)) {
        return null;
    }
    $ordered = ctype_digit($match[2][0]);
    return ['indent' => markdown_indent($match[1]), 'ordered' => $ordered, 'number' => $ordered ? (int) $match[2] : 0, 'content' => rtrim($match[4])];
}
function markdown_list(array $lines, int &$index, int $depth = 0) : string
{
    $total = count($lines);
    $first = markdown_list_item($lines[$index]);
    $ordered = $first['ordered'];
    $indent = $first['indent'];
    $start = $first['number'];
    $items = [];
    while ($index < $total) {
        $item = markdown_list_item($lines[$index]);
        if ($item === null || $item['ordered'] !== $ordered || $item['indent'] !== $indent) {
            break;
        }
        $content = [$item['content']];
        $index++;
        while ($index < $total) {
            if (trim($lines[$index]) === '') {
                $next = $index;
                while ($next < $total && trim($lines[$next]) === '') {
                    $next++;
                }
                // Blank lines stay inside the item only when indented content follows.
                if ($next < $total && markdown_indent($lines[$next]) >= $indent + 2) {
                    $content[] = '';
                    $index++;
                    continue;
                }
                break;
            }
            if (markdown_indent($lines[$index]) < $indent + 2) {
                break;
            }
            $content[] = substr(str_replace("\t", '    ', $lines[$index]), $indent + 2);
            $index++;
        }
        while ($content && end($content) === '') {
            array_pop($content);
        }
        $items[] = $content;
    }
    $html = $ordered ? '<ol' . ($start > 1 ? ' start="' . $start . '"' : '') . ">\n" : "<ul>\n";
    foreach ($items as $content) {
        $body = trim(markdown_blocks($content, $depth + 1));
        // A tight item (no blank lines) drops the paragraph wrappers around its blocks.
        if (!in_array('', $content, true)) {
            $body = str_replace(['<p>', '</p>'], '', $body);
        }
        $html .= '<li>' . $body . "</li>\n";
    }
    return $html . ($ordered ? "</ol>\n" : "</ul>\n");
}
function markdown_table_row(string $line) : array
{
    if (strpos($line, '|') === false) {
        return [];
    }
    $line = str_replace('\\|', "\x03", trim($line));
    $cells = explode('|', trim($line, '|'));
    return array_map(static function (string $cell) : string {
        return trim(str_replace("\x03", '|', $cell));
    }, $cells);
}
function markdown_table_divider(string $line, int $columns) : ?array
{
    $cells = markdown_table_row($line);
    if (!$cells || count($cells) !== $columns) {
        return null;
    }
    foreach ($cells as $cell) {
        if (!preg_match('/^:?-+:?$/', $cell)) {
            return null;
        }
    }
    return $cells;
}
function markdown_table_block(array $lines, int &$index) : ?string
{
    $total = count($lines);
    if ($index + 1 >= $total || strpos($lines[$index], '|') === false) {
        return null;
    }
    $header = markdown_table_row($lines[$index]);
    $divider = markdown_table_divider($lines[$index + 1], count($header));
    if (!$header || $divider === null || trim(implode('', $header)) === '') {
        return null;
    }
    $index += 2;
    $rows = [];
    while ($index < $total) {
        if (trim($lines[$index]) === '' || strpos($lines[$index], '|') === false) {
            break;
        }
        $cells = markdown_table_row($lines[$index]);
        if (!$cells) {
            break;
        }
        $rows[] = $cells;
        $index++;
    }
    $html = "<table>\n<thead>\n<tr>";
    foreach ($header as $position => $cell) {
        $html .= '<th' . markdown_table_align($divider[$position] ?? '') . '>' . markdown_inline($cell) . '</th>';
    }
    $html .= "</tr>\n</thead>\n";
    if ($rows) {
        $html .= "<tbody>\n";
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($header) as $position) {
                $html .= '<td' . markdown_table_align($divider[$position] ?? '') . '>' . markdown_inline($row[$position] ?? '') . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody>\n";
    }
    return $html . "</table>\n";
}
function markdown_table_align(string $cell) : string
{
    $left = strpos($cell, ':') === 0;
    $right = substr($cell, -1) === ':';
    if ($left && $right) {
        return ' style="text-align:center"';
    }
    if ($right) {
        return ' style="text-align:right"';
    }
    return $left ? ' style="text-align:left"' : '';
}
function markdown_starts_block(array $lines, int $index) : bool
{
    $line = $lines[$index];
    if (markdown_fence_open($line) !== null || markdown_is_rule($line)) {
        return true;
    }
    if (preg_match('/^ {0,3}(?:#{1,6}[ \\t]+|>)/', $line)) {
        return true;
    }
    if (markdown_list_item($line) !== null) {
        return true;
    }
    if (strpos($line, '|') !== false && $index + 1 < count($lines)) {
        $header = markdown_table_row($line);
        return $header && markdown_table_divider($lines[$index + 1], count($header)) !== null;
    }
    return false;
}
function markdown_inline(string $text) : string
{
    return markdown_inline_escaped(e($text), 0);
}
/** A PCRE pass returns null when it gives up; keep the input instead of dropping it. */
function markdown_pass(string $pattern, callable $callback, string $text) : string
{
    $result = preg_replace_callback($pattern, $callback, $text);
    return is_string($result) ? $result : $text;
}
function markdown_replace(string $pattern, string $replacement, string $text) : string
{
    $result = preg_replace($pattern, $replacement, $text);
    return is_string($result) ? $result : $text;
}
/** Inline rendering for text that is already HTML-escaped. */
function markdown_inline_escaped(string $text, int $depth) : string
{
    $tokens = [];
    $store = static function (string $html) use(&$tokens) : string {
        $tokens[] = $html;
        return "\x02" . (count($tokens) - 1) . "\x02";
    };
    // Backslash escapes win over every other inline rule.
    $text = markdown_pass('/\\\\([\\\\`*_{}\\[\\]()#+\\-.!>~|])/', static function (array $match) use($store) : string {
        return $store(e($match[1]));
    }, $text);
    // Inline code spans are extracted first so their content stays literal.
    $text = markdown_pass('/`([^`]+)`/', static function (array $match) use($store) : string {
        return $store('<code>' . $match[1] . '</code>');
    }, $text);
    // Links and images always contain "]("; without it the passes can be skipped,
    // which keeps a wall of lone brackets from costing a quadratic scan.
    if ($depth < MARKDOWN_INLINE_DEPTH_LIMIT && strpos($text, '](') !== false) {
        $text = markdown_pass('~!\\[([^\\]]*)\\]\\(\\s*([^\\s)]+)(?:\\s+(&quot;|&#039;)(.*?)\\3)?\\s*\\)~s', static function (array $match) use($store) : string {
            $url = markdown_url($match[2], true);
            if ($url === null) {
                return $match[0];
            }
            $title = ($match[4] ?? '') !== '' ? ' title="' . $match[4] . '"' : '';
            return $store('<img src="' . $url . '" alt="' . $match[1] . '"' . $title . ' loading="lazy" referrerpolicy="no-referrer">');
        }, $text);
        $text = markdown_pass('~\\[([^\\]]*)\\]\\(\\s*([^\\s)]+)(?:\\s+(&quot;|&#039;)(.*?)\\3)?\\s*\\)~s', static function (array $match) use($store, $depth) : string {
            $url = markdown_url($match[2], false);
            if ($url === null) {
                return $match[0];
            }
            $label = markdown_inline_escaped($match[1], $depth + 1);
            if (trim(strip_tags($label)) === '') {
                $label = $url;
            }
            $title = ($match[4] ?? '') !== '' ? ' title="' . $match[4] . '"' : '';
            $external = preg_match('~^(?:https?:)?//~i', $match[2]) === 1;
            return $store('<a href="' . $url . '"' . $title . ($external ? ' target="_blank" rel="nofollow noopener noreferrer"' : ' rel="nofollow"') . '>' . $label . '</a>');
        }, $text);
    }
    // <https://example.com> and bare http(s) URLs become links.
    if (strpos($text, '&lt;') !== false) {
        $text = markdown_pass('~&lt;((?:https?|mailto):[^\\s]+?)&gt;~i', static function (array $match) use($store) : string {
            $url = markdown_url($match[1], false);
            if ($url === null) {
                return $match[0];
            }
            return $store('<a href="' . $url . '" target="_blank" rel="nofollow noopener noreferrer">' . $url . '</a>');
        }, $text);
    }
    if (stripos($text, 'http') !== false) {
        $text = markdown_pass('~(?<![\\w\\x02])(https?://[^\\s<>\\x02]+)~i', static function (array $match) use($store) : string {
            [$raw, $trailing] = markdown_trim_url($match[1]);
            $url = markdown_url($raw, false);
            if ($url === null) {
                return $match[0];
            }
            return $store('<a href="' . $url . '" target="_blank" rel="nofollow noopener noreferrer">' . $url . '</a>') . $trailing;
        }, $text);
    }
    $text = markdown_replace('/~~(?=\\S)(.+?)(?<=\\S)~~/s', '<del>$1</del>', $text);
    $text = markdown_replace('/\\*\\*(?=\\S)(.+?)(?<=\\S)\\*\\*/s', '<strong>$1</strong>', $text);
    $text = markdown_replace('/__(?=\\S)(.+?)(?<=\\S)__/s', '<strong>$1</strong>', $text);
    $text = markdown_replace('/\\*(?=\\S)(.+?)(?<=\\S)\\*/s', '<em>$1</em>', $text);
    $text = markdown_replace('/(?<![0-9A-Za-z])_(?=\\S)(.+?)(?<=\\S)_(?![0-9A-Za-z])/s', '<em>$1</em>', $text);
    if ($tokens) {
        // One linear pass: a per-token str_replace over 30 KB of text scales badly.
        $text = markdown_pass('/\\x02(\\d+)\\x02/', static function (array $match) use($tokens) : string {
            return $tokens[(int) $match[1]] ?? '';
        }, $text);
    }
    return $text;
}
/**
 * Validate a link or image target and return it in its escaped output form.
 * Entities are decoded before the scheme check so that an entity-obfuscated
 * "javascript:" target is rejected instead of reaching the href attribute.
 */
function markdown_url(string $url, bool $image) : ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > MARKDOWN_MAX_URL_LENGTH) {
        return null;
    }
    $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (trim($decoded) === '' || preg_match('/[\\x00-\\x20\\x7f]/', $decoded) === 1) {
        return null;
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9+.\\-]*:/', $decoded) === 1) {
        $allowed = $image ? '/^https?:\\/\\//i' : '/^(?:https?:\\/\\/|mailto:)/i';
        if (preg_match($allowed, $decoded) !== 1) {
            return null;
        }
    }
    return e($decoded);
}
/** Split trailing sentence punctuation off an autolinked URL. */
function markdown_trim_url(string $url) : array
{
    $trailing = '';
    while ($url !== '') {
        $last = substr($url, -1);
        if (strpos('.,;:!?、。，；：！？', $last) !== false) {
            $trailing = $last . $trailing;
            $url = substr($url, 0, -1);
            continue;
        }
        if ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
            $trailing = $last . $trailing;
            $url = substr($url, 0, -1);
            continue;
        }
        break;
    }
    return [$url, $trailing];
}
