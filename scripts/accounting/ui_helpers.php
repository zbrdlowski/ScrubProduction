<?php
declare(strict_types=1);

function accountingUiH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function accountingUiInfo(string $text): string
{
    return '<i class="fas fa-info-circle accounting-info ml-1" tabindex="0" role="img" aria-label="Informácia: '
        . accountingUiH($text) . '" data-accounting-tooltip="' . accountingUiH($text) . '"></i>';
}

function accountingUiHelpButton(string $document): string
{
    return '<a class="btn btn-outline-info" href="?page=accounting_help&amp;doc=' . rawurlencode($document) . '"'
        . ' data-toggle="modal" data-target="#accountingHelpModal">'
        . '<i class="fas fa-question-circle mr-1"></i> Pomocník</a>';
}

function accountingUiHelpDocuments(): array
{
    return [
        'payouts' => ['title' => 'eBay payouty', 'file' => 'accounting-payouts.md'],
        'paypal' => ['title' => 'PayPal', 'file' => 'accounting-paypal.md'],
        'omega' => ['title' => 'OMEGA faktúry', 'file' => 'accounting-omega.md'],
        'omega_export' => ['title' => 'OMEGA TXT export', 'file' => 'accounting-omega-export.md'],
    ];
}

function accountingUiHelpModal(string $document): string
{
    $documents = accountingUiHelpDocuments();
    if (!isset($documents[$document])) return '';
    $config = $documents[$document];
    $path = __DIR__ . '/../../docs/' . $config['file'];
    $markdown = is_file($path) ? (string) file_get_contents($path) : '# Návod sa nenašiel';

    return '<div class="modal fade accounting-help-modal" id="accountingHelpModal" tabindex="-1" role="dialog" aria-labelledby="accountingHelpModalTitle" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="accountingHelpModalTitle"><i class="fas fa-question-circle text-info mr-2"></i>Pomocník – '
        . accountingUiH($config['title']) . '</h5>'
        . '<button type="button" class="close" data-dismiss="modal" aria-label="Zavrieť"><span aria-hidden="true">&times;</span></button></div>'
        . '<div class="modal-body accounting-help-content">' . accountingUiRenderMarkdown($markdown) . '</div>'
        . '<div class="modal-footer"><a class="btn btn-outline-info mr-auto" href="?page=accounting_help&amp;doc=' . rawurlencode($document) . '">'
        . '<i class="fas fa-external-link-alt mr-1"></i> Otvoriť na celej stránke</a>'
        . '<button type="button" class="btn btn-secondary" data-dismiss="modal">Zavrieť</button></div>'
        . '</div></div></div>';
}

function accountingUiAssets(): string
{
    return <<<'HTML'
<style>
  .accounting-info { color:#39c9b5; cursor:help; font-size:.82em; vertical-align:.08em; }
  .accounting-info:focus { outline:2px solid #39c9b5; outline-offset:2px; border-radius:50%; }
  .accounting-tooltip-popover { position:fixed; z-index:2100; width:320px; max-width:calc(100vw - 24px); padding:.5rem .65rem; border-radius:.3rem; background:#111820; color:#fff; box-shadow:0 3px 12px rgba(0,0,0,.4); font-size:.82rem; line-height:1.35; text-align:left; pointer-events:none; transform:translate(-50%, -100%); }
  .accounting-help-modal .modal-content { background:#f8f9fa; color:#263238; }
  .accounting-help-modal .modal-header, .accounting-help-modal .modal-footer { border-color:#d7dee3; }
  .accounting-help-content { padding:1.75rem 2.25rem; }
  .accounting-help-content h1 { font-size:1.75rem; border-bottom:2px solid #20c997; padding-bottom:.65rem; margin-bottom:1.2rem; }
  .accounting-help-content h2 { font-size:1.3rem; margin-top:1.8rem; color:#146c5b; }
  .accounting-help-content h3 { font-size:1.1rem; margin-top:1.35rem; }
  .accounting-help-content p, .accounting-help-content li { line-height:1.65; }
  .accounting-help-content code { color:#9c2f5b; background:#e9ecef; padding:.12rem .3rem; border-radius:.2rem; }
  .accounting-help-content pre { background:#17212b; color:#f1f5f8; padding:1rem; border-radius:.3rem; overflow:auto; }
  .accounting-help-content pre code { color:inherit; background:none; padding:0; }
  @media(max-width:575.98px){.accounting-help-content{padding:1.15rem}}
</style>
<script>
(function () {
  var tooltip = null;
  function hideAccountingTooltip() {
    if (tooltip) tooltip.remove();
    tooltip = null;
  }
  function showAccountingTooltip(icon) {
    hideAccountingTooltip();
    var text = icon.getAttribute('data-accounting-tooltip');
    if (!text) return;
    tooltip = document.createElement('div');
    tooltip.className = 'accounting-tooltip-popover';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = text;
    document.body.appendChild(tooltip);
    var rect = icon.getBoundingClientRect();
    var halfWidth = Math.min(160, Math.max(0, (window.innerWidth - 24) / 2));
    var left = Math.max(12 + halfWidth, Math.min(window.innerWidth - 12 - halfWidth, rect.left + rect.width / 2));
    var top = rect.top - 8;
    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
    if (tooltip.getBoundingClientRect().top < 8) {
      tooltip.style.top = (rect.bottom + 8) + 'px';
      tooltip.style.transform = 'translate(-50%, 0)';
    }
  }
  document.querySelectorAll('.accounting-info[data-accounting-tooltip]').forEach(function (icon) {
    icon.addEventListener('mouseenter', function () { showAccountingTooltip(icon); });
    icon.addEventListener('mouseleave', hideAccountingTooltip);
    icon.addEventListener('focus', function () { showAccountingTooltip(icon); });
    icon.addEventListener('blur', hideAccountingTooltip);
  });
  window.addEventListener('scroll', hideAccountingTooltip, true);
  window.addEventListener('resize', hideAccountingTooltip);
}());
</script>
HTML;
}

function accountingUiInlineMarkdown(string $text): string
{
    $escaped = accountingUiH($text);
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    return $escaped;
}

function accountingUiRenderMarkdown(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $listType = null;
    $inCode = false;
    $code = [];
    $count = count($lines);

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if (!$paragraph) return;
        $html[] = '<p>' . accountingUiInlineMarkdown(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$listType, &$html): void {
        if ($listType !== null) $html[] = '</' . $listType . '>';
        $listType = null;
    };

    for ($i = 0; $i < $count; $i++) {
        $line = rtrim($lines[$i]);
        if (preg_match('/^```/', $line)) {
            $flushParagraph();
            $closeList();
            if ($inCode) {
                $html[] = '<pre><code>' . accountingUiH(implode("\n", $code)) . '</code></pre>';
                $code = [];
                $inCode = false;
            } else {
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $code[] = $line;
            continue;
        }
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $match)) {
            $flushParagraph();
            $closeList();
            $level = strlen($match[1]);
            $html[] = '<h' . $level . '>' . accountingUiInlineMarkdown($match[2]) . '</h' . $level . '>';
            continue;
        }
        if ($i + 1 < $count && strpos($line, '|') !== false && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1])) {
            $flushParagraph();
            $closeList();
            $headers = array_values(array_filter(array_map('trim', explode('|', trim($line, '| '))), 'strlen'));
            $html[] = '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>';
            foreach ($headers as $cell) $html[] = '<th>' . accountingUiInlineMarkdown($cell) . '</th>';
            $html[] = '</tr></thead><tbody>';
            $i += 2;
            while ($i < $count && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                $cells = array_map('trim', explode('|', trim($lines[$i], '| ')));
                $html[] = '<tr>';
                foreach ($cells as $cell) $html[] = '<td>' . accountingUiInlineMarkdown($cell) . '</td>';
                $html[] = '</tr>';
                $i++;
            }
            $i--;
            $html[] = '</tbody></table></div>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $line, $match) || preg_match('/^\d+\.\s+(.+)$/', $line, $match)) {
            $flushParagraph();
            $wanted = preg_match('/^\d+\./', $line) ? 'ol' : 'ul';
            if ($listType !== $wanted) {
                $closeList();
                $listType = $wanted;
                $html[] = '<' . $listType . '>';
            }
            $html[] = '<li>' . accountingUiInlineMarkdown($match[1]) . '</li>';
            continue;
        }
        $paragraph[] = trim($line);
    }
    $flushParagraph();
    $closeList();
    if ($inCode) $html[] = '<pre><code>' . accountingUiH(implode("\n", $code)) . '</code></pre>';
    return implode("\n", $html);
}
