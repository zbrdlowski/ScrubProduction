(function () {
  'use strict';
  function init() {
    const form = document.getElementById('projectNotificationSettingsForm');
    if (!form || form.dataset.initialized) return;
    form.dataset.initialized = '1';
    const service = window.darkScrubProjectNotificationService;
    const status = document.getElementById('projectNotificationSettingsStatus');
    const button = form.querySelector('button[type="submit"]');
    if (!service) {
      button.disabled = true;
      status.textContent = 'Nastavenia projektov sa nepodarilo načítať. Obnovte stránku.';
      return;
    }
    let dirty = false;
    function populate() { form.elements.toast_mode.value = service.getPreferences().toast_mode; }
    populate();
    form.addEventListener('change', () => { dirty = true; });
    document.addEventListener('darkscrub:project-preferences', () => { if (!dirty) populate(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      button.disabled = true;
      status.className = 'mt-3 text-muted';
      status.textContent = 'Ukladám…';
      try {
        await service.savePreferences(form.elements.toast_mode.value);
        dirty = false;
        populate();
        status.className = 'mt-3 text-success';
        status.textContent = 'Nastavenia projektových upozornení boli uložené.';
      } catch (error) {
        status.className = 'mt-3 text-danger';
        status.textContent = error.message;
      } finally { button.disabled = false; }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
