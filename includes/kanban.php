<?php
require_once __DIR__ . '/../scripts/kanban/i18n.php';
require_once __DIR__ . '/../scripts/kanban/help.php';
$GLOBALS['kbLanguage'] = kb_resolve_language();
if ((int) ($_SESSION['user_id'] ?? 0) <= 0) {
    http_response_code(403);
    echo '<div class="alert alert-warning">' . htmlspecialchars(kb_t('Please sign in to the application.'), ENT_QUOTES, 'UTF-8') . '</div>';
    return;
}
// The entire personal board is available to every signed-in employee, regardless of role.
// Do not reuse cached markup from an older deployment or a different signed-in account.
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$_SESSION['kanban_languages'][(int) $_SESSION['user_id']] = kb_language();
if (empty($_SESSION['kanban_csrf'])) $_SESSION['kanban_csrf'] = bin2hex(random_bytes(32));
$kbEscape = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
// Content hashes refresh browser assets after upload even when deployment preserves file timestamps.
$kbCssVersion = substr(hash_file('sha256', __DIR__ . '/../assets/css/kanban.css'), 0, 12);
$kbJsVersion = substr(hash_file('sha256', __DIR__ . '/../scripts/kanban/kanban.js'), 0, 12);
?>
<link rel="stylesheet" href="assets/css/kanban.css?v=<?= $kbEscape($kbCssVersion) ?>">
<section id="kb-app" class="kb-app" lang="<?= kb_language() ?>" data-csrf="<?= $kbEscape($_SESSION['kanban_csrf']) ?>" aria-labelledby="kb-title">
  <div class="kb-language-bar">
    <button type="button" id="kb-templates-open" class="btn btn-outline-light btn-sm" hidden><i class="fas fa-th-large" aria-hidden="true"></i> <?= $kbEscape(kb_t('Templates')) ?></button>
    <button type="button" id="kb-guide-open" class="btn btn-outline-light btn-sm" aria-haspopup="dialog"><i class="far fa-question-circle" aria-hidden="true"></i> <?= $kbEscape(kb_t('Help')) ?></button>
    <div class="btn-group btn-group-sm" role="group" aria-label="<?= $kbEscape(kb_t('Language')) ?>">
    <?php foreach (['sk' => 'SK', 'en' => 'EN'] as $language => $languageLabel): ?>
      <a class="btn <?= kb_language() === $language ? 'btn-info' : 'btn-outline-light' ?>" href="index.php?<?= $kbEscape(http_build_query(['page' => 'kanban', 'kb_lang' => $language])) ?>" data-kb-language="<?= $language ?>" lang="<?= $language ?>" hreflang="<?= $language ?>" aria-pressed="<?= kb_language() === $language ? 'true' : 'false' ?>" title="<?= $language === 'sk' ? 'Slovenčina' : 'English' ?>"><?= $languageLabel ?></a>
    <?php endforeach; ?>
  </div></div>
  <header class="kb-heading">
    <div>
      <div class="kb-eyebrow"><i class="fas fa-lock" aria-hidden="true"></i> <?= $kbEscape(kb_t('PRIVATE BOARD')) ?></div>
      <h1 id="kb-title"><?= $kbEscape(kb_t('My kanban')) ?></h1>
      <p class="kb-muted"><?= $kbEscape(kb_t('Your work, your order. Drag each card where it belongs.')) ?></p>
    </div>
    <div class="kb-heading-actions">
      <span id="kb-save-status" class="kb-muted" role="status" aria-live="polite"></span>
      <button type="button" id="kb-refresh" class="kb-btn kb-icon" title="<?= $kbEscape(kb_t('Refresh board')) ?>" aria-label="<?= $kbEscape(kb_t('Refresh board')) ?>"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
    </div>
  </header>
  <div id="kb-message" class="kb-message" role="alert" hidden></div>
  <div id="kb-toolbar" class="kb-toolbar" hidden>
    <label class="kb-search"><i class="fas fa-search" aria-hidden="true"></i><span class="sr-only"><?= $kbEscape(kb_t('Search all cards')) ?></span><input id="kb-search" type="search" maxlength="100" placeholder="<?= $kbEscape(kb_t('Search older cards too…')) ?>" autocomplete="off"></label>
    <label class="kb-limit"><?= $kbEscape(kb_t('Done: latest')) ?> <input id="kb-done-limit" class="kb-input" type="number" min="1" max="100" value="20" aria-label="<?= $kbEscape(kb_t('Number of completed cards to display')) ?>"></label>
    <button id="kb-add-column" type="button" class="kb-btn"><i class="fas fa-columns" aria-hidden="true"></i> <?= $kbEscape(kb_t('Add column')) ?></button>
    <button id="kb-add-card" type="button" class="kb-btn kb-primary"><i class="fas fa-plus" aria-hidden="true"></i> <?= $kbEscape(kb_t('New card')) ?></button>
  </div>
  <p id="kb-filter-note" class="kb-muted" hidden><?= $kbEscape(kb_t('Results include older completed cards. Clear the search to drag and drop.')) ?></p>
  <div id="kb-onboarding" class="kb-onboarding" hidden>
    <span class="kb-onboarding-icon"><i class="fas fa-columns" aria-hidden="true"></i></span>
    <h2><?= $kbEscape(kb_t('Create your own workspace')) ?></h2>
    <p class="kb-muted"><?= $kbEscape(kb_t('Start with a template. You can change column names, colours and order at any time.')) ?></p>
    <p id="kb-template-notice" class="kb-muted" hidden><?= $kbEscape(kb_t('Applying a template replaces your columns. Your cards are kept: active cards move to the first column, completed cards to the last.')) ?></p>
    <div class="kb-templates">
      <button type="button" class="kb-template" data-template="general"><strong><?= $kbEscape(kb_t('General')) ?></strong><span><?= $kbEscape(kb_t('Backlog → To do → In progress → Done')) ?></span></button>
      <button type="button" class="kb-template" data-template="design"><strong><?= $kbEscape(kb_t('Design')) ?></strong><span><?= $kbEscape(kb_t('Brief → In progress → For approval → Revisions → Done')) ?></span></button>
      <button type="button" class="kb-template" data-template="support"><strong><?= $kbEscape(kb_t('Customer service')) ?></strong><span><?= $kbEscape(kb_t('New → Handling → Waiting for customer → Resolved')) ?></span></button>
    </div>
    <button type="button" id="kb-template-back" class="kb-btn" hidden><?= $kbEscape(kb_t('Back to board')) ?></button>
  </div>
  <div id="kb-board" class="kb-board" aria-label="<?= $kbEscape(kb_t('Kanban columns')) ?>" aria-busy="true"></div>
  <p id="kb-help" class="kb-muted kb-help" hidden><i class="fas fa-grip-vertical" aria-hidden="true"></i> <?= $kbEscape(kb_t('Drag a card or a column handle. Click a card title to open its details. Completed cards are sorted newest first.')) ?></p>

  <dialog id="kb-guide-dialog" class="kb-dialog kb-guide-dialog" aria-labelledby="kb-guide-title">
    <header class="kb-dialog-header"><h2 id="kb-guide-title"><?= $kbEscape(kb_t('Kanban guide')) ?></h2><button type="button" class="kb-btn kb-icon" data-close aria-label="<?= $kbEscape(kb_t('Close')) ?>">×</button></header>
    <div class="kb-dialog-body kb-guide-content"><?= kb_render_guide(kb_language()) ?></div>
    <footer class="kb-dialog-footer"><button type="button" class="kb-btn" data-close><?= $kbEscape(kb_t('Close')) ?></button></footer>
  </dialog>
  <dialog id="kb-template-dialog" class="kb-dialog kb-dialog-small" aria-labelledby="kb-template-title">
    <form id="kb-template-form">
      <header class="kb-dialog-header"><h2 id="kb-template-title"><?= $kbEscape(kb_t('Apply template?')) ?></h2></header>
      <div class="kb-dialog-body">
        <div class="kb-form-error" role="alert" hidden></div>
        <p id="kb-template-summary"></p>
        <p><?= $kbEscape(kb_t('Existing column names, colours and positions will be replaced. All cards, including older completed cards, are kept. Completion dates and your display limit stay unchanged.')) ?></p>
      </div>
      <footer class="kb-dialog-footer"><button type="button" class="kb-btn" data-close><?= $kbEscape(kb_t('Cancel')) ?></button><button type="submit" class="kb-btn kb-primary"><?= $kbEscape(kb_t('Apply template')) ?></button></footer>
    </form>
  </dialog>

  <dialog id="kb-card-dialog" class="kb-dialog" aria-labelledby="kb-card-dialog-title">
    <form id="kb-card-form">
      <header class="kb-dialog-header"><h2 id="kb-card-dialog-title"><?= $kbEscape(kb_t('New card')) ?></h2><button type="button" class="kb-btn kb-icon" data-close aria-label="<?= $kbEscape(kb_t('Close')) ?>">×</button></header>
      <div class="kb-dialog-body">
        <div class="kb-form-error" role="alert" hidden></div>
        <input name="id" type="hidden" value="0">
        <label><?= $kbEscape(kb_t('Card title')) ?><input class="kb-input" name="title" maxlength="200" required placeholder="<?= $kbEscape(kb_t('What needs to be done?')) ?>"></label>
        <label><?= $kbEscape(kb_t('Description')) ?><textarea class="kb-input" name="description" maxlength="10000" rows="5" placeholder="<?= $kbEscape(kb_t('Brief, notes, details…')) ?>"></textarea></label>
        <div class="kb-form-grid">
          <label><?= $kbEscape(kb_t('Column')) ?><select class="kb-input" name="column_id"></select></label>
          <label><?= $kbEscape(kb_t('Priority')) ?><select class="kb-input" name="priority"><option value="low"><?= $kbEscape(kb_t('Low')) ?></option><option value="normal" selected><?= $kbEscape(kb_t('Normal')) ?></option><option value="high"><?= $kbEscape(kb_t('High')) ?></option><option value="urgent"><?= $kbEscape(kb_t('Urgent')) ?></option></select></label>
          <label><?= $kbEscape(kb_t('Due date')) ?><input class="kb-input" name="due_date" type="date" min="1000-01-01" max="9999-12-31"></label>
          <label><?= $kbEscape(kb_t('Label')) ?><input class="kb-input" name="label" maxlength="60" placeholder="<?= $kbEscape(kb_t('e.g. Design')) ?>"></label>
        </div>
        <label><?= $kbEscape(kb_t('Customer / reference')) ?><input class="kb-input" name="reference" maxlength="160" placeholder="<?= $kbEscape(kb_t('e.g. Client ABC · Order #1258')) ?>"></label>
        <label><?= $kbEscape(kb_t('Related record link')) ?><input class="kb-input" name="reference_url" type="url" maxlength="1000" placeholder="https://…"></label>
        <p id="kb-card-dates" class="kb-muted"></p>
      </div>
      <footer class="kb-dialog-footer">
        <button id="kb-delete-card" type="button" class="kb-btn kb-danger" hidden><?= $kbEscape(kb_t('Delete card')) ?></button>
        <span class="kb-spacer"></span><button type="button" class="kb-btn" data-close><?= $kbEscape(kb_t('Cancel')) ?></button><button type="submit" class="kb-btn kb-primary"><?= $kbEscape(kb_t('Save card')) ?></button>
      </footer>
    </form>
  </dialog>

  <dialog id="kb-column-dialog" class="kb-dialog kb-dialog-small" aria-labelledby="kb-column-dialog-title">
    <form id="kb-column-form">
      <header class="kb-dialog-header"><h2 id="kb-column-dialog-title"><?= $kbEscape(kb_t('New column')) ?></h2><button type="button" class="kb-btn kb-icon" data-close aria-label="<?= $kbEscape(kb_t('Close')) ?>">×</button></header>
      <div class="kb-dialog-body">
        <div class="kb-form-error" role="alert" hidden></div>
        <input name="id" type="hidden" value="0">
        <label><?= $kbEscape(kb_t('Column name')) ?><input class="kb-input" name="title" maxlength="80" required></label>
        <label><?= $kbEscape(kb_t('Colour')) ?><input class="kb-input kb-color" name="color" type="color" value="#60a5fa"></label>
        <label class="kb-checkbox"><input name="is_done" type="checkbox"> <?= $kbEscape(kb_t('Cards in this column are completed')) ?></label>
        <p class="kb-muted"><?= $kbEscape(kb_t('Completed cards are sorted by completion time. Changing this setting also applies to existing cards in the column.')) ?></p>
        <div id="kb-column-order" class="kb-order-controls" hidden><span><?= $kbEscape(kb_t('Column order')) ?></span><button type="button" class="kb-btn" id="kb-column-left" aria-label="<?= $kbEscape(kb_t('Move column left')) ?>"><?= $kbEscape(kb_t('← Left')) ?></button><button type="button" class="kb-btn" id="kb-column-right" aria-label="<?= $kbEscape(kb_t('Move column right')) ?>"><?= $kbEscape(kb_t('Right →')) ?></button></div>
      </div>
      <footer class="kb-dialog-footer"><button id="kb-delete-column" type="button" class="kb-btn kb-danger" hidden><?= $kbEscape(kb_t('Delete')) ?></button><span class="kb-spacer"></span><button type="button" class="kb-btn" data-close><?= $kbEscape(kb_t('Cancel')) ?></button><button type="submit" class="kb-btn kb-primary"><?= $kbEscape(kb_t('Save')) ?></button></footer>
    </form>
  </dialog>
  <dialog id="kb-confirm-dialog" class="kb-dialog kb-dialog-small" aria-labelledby="kb-confirm-title">
    <form id="kb-confirm-form">
      <header class="kb-dialog-header"><h2 id="kb-confirm-title"><?= $kbEscape(kb_t('Delete?')) ?></h2></header>
      <div class="kb-dialog-body"><p id="kb-confirm-text"></p></div>
      <footer class="kb-dialog-footer"><button type="button" class="kb-btn" data-close><?= $kbEscape(kb_t('Cancel')) ?></button><button type="submit" class="kb-btn kb-danger"><?= $kbEscape(kb_t('Delete')) ?></button></footer>
    </form>
  </dialog>
  <noscript><p class="kb-message"><?= $kbEscape(kb_t('Enable JavaScript to use the kanban board.')) ?></p></noscript>
</section>
<script>window.kanbanConfig = <?= json_encode(['language' => kb_language(), 'languageCookie' => kb_language_cookie(), 'translations' => kb_language() === 'sk' ? kb_i18n_dictionary() : new stdClass()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="scripts/kanban/vendor/Sortable.min.js"></script>
<script defer src="scripts/kanban/kanban.js?v=<?= $kbEscape($kbJsVersion) ?>"></script>
