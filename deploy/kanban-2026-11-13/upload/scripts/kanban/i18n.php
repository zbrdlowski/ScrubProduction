<?php
declare(strict_types=1);

/** Translate interface strings only. Saved card/column values remain user data. */
function kb_i18n_dictionary(): array
{
    static $dictionary;
    return $dictionary ??= json_decode((string) file_get_contents(__DIR__ . '/locales/sk.json'), true, 512, JSON_THROW_ON_ERROR);
}

function kb_language_cookie(): string
{
    return 'kanban_lang_' . (int) ($_SESSION['user_id'] ?? 0);
}

function kb_resolve_language(): string
{
    $user = (int) ($_SESSION['user_id'] ?? 0);
    foreach ([$_GET['kb_lang'] ?? null, $_COOKIE[kb_language_cookie()] ?? null,
        $_SESSION['kanban_languages'][$user] ?? null, 'sk'] as $candidate) {
        if (is_string($candidate) && in_array($candidate, ['sk', 'en'], true)) return $candidate;
    }
    return 'sk';
}

function kb_language(): string
{
    return $GLOBALS['kbLanguage'] ?? kb_resolve_language();
}

function kb_t(string $key, array $values = [], ?string $language = null): string
{
    $text = ($language ?? kb_language()) === 'sk' ? (kb_i18n_dictionary()[$key] ?? $key) : $key;
    $replacements = [];
    foreach ($values as $name => $value) $replacements['{' . $name . '}'] = (string) $value;
    return strtr($text, $replacements);
}
