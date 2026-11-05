<?php
declare(strict_types=1);
/** English source strings are stable keys; application data is never translated. */
function pb_i18n_dictionary(): array
{
    static $dictionary;
    return $dictionary ??= json_decode((string) file_get_contents(__DIR__ . '/locales/sk.json'), true, 512, JSON_THROW_ON_ERROR);
}
function pb_language_cookie(): string
{
    return 'product_builder_lang_' . (int) ($_SESSION['user_id'] ?? 0);
}
function pb_resolve_language(): string
{
    $user = (int) ($_SESSION['user_id'] ?? 0);
    foreach ([$_GET['pb_lang'] ?? null, $_COOKIE[pb_language_cookie()] ?? null, $_SESSION['product_builder_languages'][$user] ?? null, 'sk'] as $candidate) {
        if (is_string($candidate) && in_array($candidate, ['sk','en'], true)) return $candidate;
    }
    return 'sk';
}
function pb_language(): string
{
    return $GLOBALS['pbLanguage'] ?? 'en';
}
function pb_t(string $key, array $values = [], ?string $language = null): string
{
    $text = ($language ?? pb_language()) === 'sk' ? (pb_i18n_dictionary()[$key] ?? $key) : $key;
    $replace = [];
    foreach ($values as $name => $value) $replace['{' . $name . '}'] = (string) $value;
    return strtr($text, $replace);
}
/** Translate only known API error formats, never successful payloads or saved values. */
function pb_translate_error(string $message): string
{
    if (pb_language() !== 'sk') return $message;
    if (isset(pb_i18n_dictionary()[$message])) return pb_t($message);
    if (preg_match('/^CSV row (\d+): (.*)$/s', $message, $m)) return pb_t('CSV row {row}: {message}', ['row'=>$m[1], 'message'=>pb_translate_error($m[2])]);
    if (preg_match('/^Missing component of type ([GFPST])\.$/', $message, $m)) return pb_t('Missing component of type {type}.', ['type'=>$m[1]]);
    if (preg_match('/^maximum (\d+) characters\.$/', $message, $m)) return pb_t('maximum {max} characters.', ['max'=>$m[1]]);
    if (preg_match('/^enter a non-negative number with up to (\d+) digits before and (\d+) digits after the decimal point\.$/', $message, $m)) return pb_t('enter a non-negative number with up to {digits} digits before and {scale} digits after the decimal point.', ['digits'=>$m[1], 'scale'=>$m[2]]);
    $parts = explode(': ', $message, 2);
    if (count($parts) === 2) {
        $label = $parts[0];
        if (preg_match('/^Component name ([GFPST])$/', $label, $m)) $label = pb_t('Component name {type}', ['type'=>$m[1]]);
        elseif (str_ends_with($label, ' currency')) $label = pb_t('{label} currency', ['label'=>pb_t(substr($label, 0, -9))]);
        else $label = pb_t($label);
        return $label . ': ' . pb_translate_error($parts[1]);
    }
    return $message;
}
