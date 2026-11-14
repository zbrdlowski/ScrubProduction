<?php
/** Render the local guide using a small, escaped subset of Markdown. No raw HTML or remote content. */
function kb_render_guide(string $language): string
{
    $file = __DIR__ . '/../../docs/kanban/' . ($language === 'en' ? 'navod.en.md' : 'navod.md');
    $markdown = file_get_contents($file);
    $inline = static function (string $text): string {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    };
    $html = [];
    $paragraph = [];
    $list = null;
    $flush = static function () use (&$html, &$paragraph, $inline): void {
        if ($paragraph) $html[] = '<p>' . $inline(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$html, &$list): void {
        if ($list) $html[] = '</' . $list . '>';
        $list = null;
    };
    foreach (preg_split('/\R/', $markdown) as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); $closeList(); continue; }
        if (preg_match('/^#{1,3}\s+(.+)$/', $line, $match)) {
            $flush(); $closeList();
            $html[] = '<h2>' . $inline($match[1]) . '</h2>';
        } elseif (preg_match('/^(-|[0-9]+\.)\s+(.+)$/', $line, $match)) {
            $flush();
            $wanted = $match[1] === '-' ? 'ul' : 'ol';
            if ($list !== $wanted) { $closeList(); $list = $wanted; $html[] = '<' . $list . '>'; }
            $html[] = '<li>' . $inline($match[2]) . '</li>';
        } else {
            $closeList();
            $paragraph[] = $line;
        }
    }
    $flush(); $closeList();
    return implode("\n", $html);
}
