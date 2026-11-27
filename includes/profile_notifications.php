<?php
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    return;
}
?>
<form id="chatNotificationSettingsForm" style="max-width:640px;">
    <h5>Upozornenia na správy</h5>
    <div class="form-group">
        <label for="chatNotificationMode">Zobrazovanie upozornení</label>
        <select id="chatNotificationMode" name="toast_mode" class="form-control">
            <option value="persistent">Trvalé – do zatvorenia alebo prečítania správy</option>
            <option value="brief">Krátke – skryť po 10 sekundách</option>
            <option value="badge">Iba ikonka – bez vyskakovacích upozornení</option>
        </select>
        <small class="form-text text-muted">Počet neprečítaných konverzácií zostáva na ikonke pri všetkých možnostiach.</small>
    </div>
    <div class="custom-control custom-checkbox mb-3">
        <input type="checkbox" id="chatNotificationSound" name="sound_enabled" class="custom-control-input">
        <label class="custom-control-label" for="chatNotificationSound">Zvuk pri novej správe</label>
    </div>
    <p class="small text-muted">
        Zatvorenie upozornenia sa prenesie do ostatných okien tohto prehliadača.
        Správa zostane neprečítaná, kým ju neotvoríte v chate.
    </p>
    <button type="submit" class="btn btn-primary">Uložiť nastavenia</button>
    <div id="chatNotificationSettingsStatus" class="mt-3" role="status" aria-live="polite"></div>
</form>
<script>
(function () {
    const form = document.getElementById('chatNotificationSettingsForm');
    const service = window.darkScrubChatNotifications;
    const status = document.getElementById('chatNotificationSettingsStatus');
    const button = form.querySelector('button[type="submit"]');
    if (!service) {
        button.disabled = true;
        status.textContent = 'Nastavenia sa nepodarilo načítať. Obnovte stránku.';
        return;
    }
    function populate() {
        const preferences = service.getPreferences();
        form.elements.toast_mode.value = preferences.toast_mode;
        form.elements.sound_enabled.checked = preferences.sound_enabled;
    }
    populate();
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        button.disabled = true;
        status.className = 'mt-3 text-muted';
        status.textContent = 'Ukladám…';
        try {
            await service.savePreferences(form.elements.toast_mode.value, form.elements.sound_enabled.checked);
            populate();
            status.className = 'mt-3 text-success';
            status.textContent = 'Nastavenia upozornení boli uložené.';
        } catch (error) {
            status.className = 'mt-3 text-danger';
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
})();
</script>

<hr style="max-width:640px;margin-left:0;" class="my-4">
<form id="projectNotificationSettingsForm" style="max-width:640px;">
    <h5>Upozornenia pre ownera projektu</h5>
    <div class="form-group">
        <label for="projectNotificationMode">Zobrazovanie projektových upozornení</label>
        <select id="projectNotificationMode" name="toast_mode" class="form-control">
            <option value="persistent">Trvalé – do zatvorenia alebo vybavenia upozornenia</option>
            <option value="brief">Krátke – skryť po 10 sekundách</option>
            <option value="badge">Iba ikonka – bez vyskakovacích upozornení</option>
        </select>
        <small class="form-text text-muted">Platí pre dokončené úlohy a úlohy čakajúce na vaše schválenie v projektoch, za ktoré zodpovedáte.</small>
    </div>
    <p class="small text-muted">Upozornenia aj možnosť schváliť alebo vrátiť úlohu zostávajú v hornej lište pri všetkých možnostiach. Skrytie toastu úlohu neschváli ani neoznačí upozornenie za prečítané. Upozornenia na vaše pridelené subtasks majú naďalej trvalé toasty.</p>
    <button type="submit" class="btn btn-primary">Uložiť nastavenia projektov</button>
    <div id="projectNotificationSettingsStatus" class="mt-3" role="status" aria-live="polite"></div>
</form>
<script src="scripts/projects/notification_settings.js?v=20261127-1" defer></script>
