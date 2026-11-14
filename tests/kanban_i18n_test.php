<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../scripts/kanban/service.php';
function kbLangCheck(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
$_GET = []; $_COOKIE = []; $_SESSION = ['user_id' => 701];
kbLangCheck(kb_resolve_language() === 'sk', 'Slovak default');
$_GET['kb_lang'] = 'en';
kbLangCheck(kb_resolve_language() === 'en', 'Explicit English');
$_COOKIE[kb_language_cookie()] = 'en'; $_GET['kb_lang'] = ['sk'];
kbLangCheck(kb_resolve_language() === 'en', 'Invalid query ignored');
$_GET = []; $_SESSION['user_id'] = 702;
kbLangCheck(kb_resolve_language() === 'sk', 'Cookie scoped to employee');
$_SESSION['kanban_languages'][702] = 'en';
kbLangCheck(kb_resolve_language() === 'en', 'Session fallback');
$_GET['kb_lang'] = 'sk';
kbLangCheck(kb_resolve_language() === 'sk', 'Explicit preference wins');
foreach (['sk', 'en'] as $lang) {
    $GLOBALS['kbLanguage'] = $lang;
    kbLangCheck(kb_t('My kanban') === ($lang === 'sk' ? 'Môj kanban' : 'My kanban'), 'Translated page title');
    kbLangCheck(kb_t('Card “{title}” will be permanently deleted.', ['title' => '{title} <script>']) ===
        ($lang === 'sk' ? 'Karta „{title} <script>“ bude natrvalo odstránená.' : 'Card “{title} <script>” will be permanently deleted.'), 'One-pass interpolation');
    kbLangCheck(kb_templates()['design'][0][0] === ($lang === 'sk' ? 'Zadanie' : 'Brief'), 'New template uses selected language');
    try { kb_text('', 'Title', 200, true); throw new RuntimeException('Expected validation failure'); }
    catch (KanbanError $e) {
        kbLangCheck($e->getMessage() === ($lang === 'sk' ? 'Názov: vyplňte najviac 200 znakov.' : 'Title: enter no more than 200 characters.'), 'Localized validation');
    }
    $values = kb_card_values(['title' => 'Hotové', 'label' => 'Normal', 'description' => 'Done', 'priority' => 'high']);
    kbLangCheck($values[0] === 'Hotové' && $values[1] === 'Done' && $values[2] === 'high' && $values[4] === 'Normal', 'Saved data is not translated');
}
// Every literal translation key used by the module must have a Slovak translation.
$dictionary = kb_i18n_dictionary();
foreach (['includes/kanban.php', 'scripts/kanban/kanban.js', 'scripts/kanban/service.php', 'scripts/kanban/api.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../' . $file);
    preg_match_all('/\b(?:kb_t|t)\(\'([^\']+)\'/', $source, $matches);
    foreach ($matches[1] as $key) kbLangCheck(isset($dictionary[$key]), 'Missing translation: ' . $key);
}
echo "kanban_i18n: preferences, isolation, templates, validation, interpolation and dictionary coverage passed.\n";
