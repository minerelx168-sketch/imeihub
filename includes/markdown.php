<?php
declare(strict_types=1);

/**
 * A tiny Markdown-to-HTML renderer for article bodies. Handles only the
 * subset we use: h2/h3 headings, paragraphs, unordered lists, tables,
 * inline code, bold, and links. Output is safe: input is escaped first
 * and only whitelisted markup is unescaped back.
 */

if (!function_exists('md_render')) {
function md_render(string $src): string
{
    $src = str_replace("\r\n", "\n", $src);
    $lines = explode("\n", $src);
    $out = [];
    $i = 0; $n = count($lines);

    $flushPara = function (array &$buf) use (&$out) {
        if (!$buf) return;
        $out[] = '<p>' . md_inline(implode(' ', $buf)) . '</p>';
        $buf = [];
    };

    $para = [];
    while ($i < $n) {
        $line = $lines[$i];

        // Blank line ends paragraph.
        if (trim($line) === '') {
            $flushPara($para);
            $i++;
            continue;
        }

        // Headings.
        if (preg_match('/^###\s+(.*)$/', $line, $m)) {
            $flushPara($para);
            $out[] = '<h3>' . md_inline($m[1]) . '</h3>';
            $i++; continue;
        }
        if (preg_match('/^##\s+(.*)$/', $line, $m)) {
            $flushPara($para);
            $out[] = '<h2>' . md_inline($m[1]) . '</h2>';
            $i++; continue;
        }

        // Unordered list.
        if (preg_match('/^[-*]\s+(.*)$/', $line)) {
            $flushPara($para);
            $items = [];
            while ($i < $n && preg_match('/^[-*]\s+(.*)$/', $lines[$i], $m)) {
                $items[] = '<li>' . md_inline($m[1]) . '</li>';
                $i++;
            }
            $out[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        // Table.
        if (str_contains($line, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?[-: \|]+\|?\s*$/', $lines[$i + 1])) {
            $flushPara($para);
            $head = array_map('trim', array_filter(explode('|', trim($line, " |")), 'strlen'));
            $i += 2;
            $rows = [];
            while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                $rows[] = array_map('trim', array_filter(explode('|', trim($lines[$i], " |")), 'strlen'));
                $i++;
            }
            $html = '<table><thead><tr>';
            foreach ($head as $h) $html .= '<th>' . md_inline($h) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach ($r as $c) $html .= '<td>' . md_inline($c) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $out[] = $html;
            continue;
        }

        // Otherwise: paragraph buffer.
        $para[] = trim($line);
        $i++;
    }
    $flushPara($para);

    return implode("\n", $out);
}
}

if (!function_exists('md_inline')) {
function md_inline(string $s): string
{
    // Escape first.
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    // Decode the few entities the source already wrote in (so &mdash; etc render).
    $s = str_replace(
        ['&amp;mdash;', '&amp;ndash;', '&amp;rarr;', '&amp;larr;', '&amp;hellip;'],
        ['&mdash;', '&ndash;', '&rarr;', '&larr;', '&hellip;'],
        $s
    );
    // Inline code: `code`
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    // Bold: **text**
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    // Italics: *text* or _text_ (avoid eating list markers — these run on inlines only)
    $s = preg_replace('/(?<!\w)_([^_]+)_(?!\w)/', '<em>$1</em>', $s);
    // Links: [label](url)
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        function ($m) {
            $href = filter_var(html_entity_decode($m[2]), FILTER_VALIDATE_URL) ? $m[2] : '#';
            return '<a href="' . $href . '">' . $m[1] . '</a>';
        },
        $s
    );
    return $s;
}
}
