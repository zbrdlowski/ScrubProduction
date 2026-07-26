<div class="card card-dark">
  <div class="card-header">
    <h3 class="card-title">Import objedn&aacute;vok DARKSCRUB_IMPORT.csv</h3>
  </div>

  <div class="card-body">
    <div class="alert alert-info">
      Nahraj jednotn&yacute; CSV export z Google Sheets tabu <b>DARKSCRUB_IMPORT</b>.
      Import funguje ako add/update pod&#318;a <code>source + external_order_id</code>.
    </div>

    <input id="sourceSelect" type="hidden" value="DARKSCRUB" />

    <div class="mb-3" style="border:1px solid rgba(255,255,255,.2); border-radius:10px; padding:14px 16px; background:rgba(255,255,255,.03);">
      <label for="modeSelect" style="font-weight:600; margin-bottom:8px; display:block;">
        <i class="fas fa-toggle-on" style="opacity:.8;"></i>
        Ak objedn&aacute;vka u&#382; existuje (rovnak&yacute; source + external_order_id):
      </label>
      <select id="modeSelect" class="form-control" style="max-width:420px; font-weight:600;">
        <option value="skip" selected>Presko&#269;i&#357; existuj&uacute;ce (odpor&uacute;&#269;an&eacute;)</option>
        <option value="update">Prep&iacute;sa&#357; existuj&uacute;ce (re-import)</option>
      </select>
      <div id="modeHint" class="text-muted" style="margin-top:8px; font-size:13px;">
        Existuj&uacute;ca objedn&aacute;vka ostane bez zmeny &ndash; vhodn&eacute; pre denn&yacute; eBay export, kde chce&scaron; len prid&aacute;va&#357; nov&eacute; objedn&aacute;vky.
      </div>
    </div>

    <div id="dropzone"
         style="border:2px dashed rgba(255,255,255,.3); border-radius:12px; padding:28px; text-align:center; cursor:pointer;">
      <div style="font-size:40px; opacity:.7;">
        <i class="fas fa-file-csv"></i>
      </div>
      <div style="font-size:18px; margin-top:10px;">
        Pretiahni DARKSCRUB_IMPORT.csv sem
      </div>
      <div class="text-muted" style="margin-top:6px;">
        alebo klikni pre v&yacute;ber s&uacute;boru
      </div>
      <input id="fileInput" type="file" accept=".csv,text/csv" style="display:none;" />
    </div>

    <div class="mt-3 d-flex align-items-center">
      <button id="btnUpload" class="btn btn-primary" disabled>
        <i class="fas fa-upload"></i> Upload & Import
      </button>
      <div id="selectedFile" class="ml-3 text-muted"></div>
      <div id="spinner" class="ml-3" style="display:none;">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        <span class="ml-2">Importujem...</span>
      </div>
    </div>

    <div id="result" class="mt-4"></div>
  </div>
</div>

<script>
(function() {
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const btnUpload = document.getElementById('btnUpload');
  const selectedFile = document.getElementById('selectedFile');
  const sourceSelect = document.getElementById('sourceSelect');
  const modeSelect = document.getElementById('modeSelect');
  const modeHint = document.getElementById('modeHint');
  const result = document.getElementById('result');
  const spinner = document.getElementById('spinner');
  let file = null;

  const modeHints = {
    skip: 'Existuj&uacute;ca objedn&aacute;vka ostane bez zmeny &ndash; vhodn&eacute; pre denn&yacute; eBay export, kde chce&scaron; len prid&aacute;va&#357; nov&eacute; objedn&aacute;vky.',
    update: 'Pozor: existuj&uacute;ca objedn&aacute;vka aj jej polo&#382;ky sa prep&iacute;&scaron;u aktu&aacute;lnymi hodnotami z CSV (re-import).'
  };
  modeSelect.addEventListener('change', () => {
    modeHint.innerHTML = modeHints[modeSelect.value] || '';
  });

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }

  function setFile(f) {
    file = f;
    selectedFile.textContent = file ? `${file.name} (${Math.round(file.size / 1024)} KB)` : '';
    btnUpload.disabled = !file;
  }

  dropzone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', e => e.target.files && e.target.files[0] && setFile(e.target.files[0]));

  function highlight(on) {
    dropzone.style.borderColor = on ? 'rgba(0,123,255,.9)' : 'rgba(255,255,255,.3)';
    dropzone.style.background = on ? 'rgba(0,123,255,.08)' : 'transparent';
  }

  ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); highlight(true); }));
  ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); highlight(false); }));
  dropzone.addEventListener('drop', e => e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0] && setFile(e.dataTransfer.files[0]));

  btnUpload.addEventListener('click', async () => {
    if (!file) return;
    result.innerHTML = '';
    spinner.style.display = 'inline-flex';
    btnUpload.disabled = true;

    const form = new FormData();
    form.append('source', sourceSelect.value);
    form.append('mode', modeSelect.value);
    form.append('file', file);

    try {
      const res = await fetch('scripts/upload_import_orders.php', { method: 'POST', body: form, credentials: 'same-origin' });
      const txt = await res.text();
      let data;
      try { data = JSON.parse(txt); } catch (e) { data = null; }

      if (!res.ok || !data || !data.ok) {
        const rawMsg = data && data.error ? data.error : txt;
        const msg = rawMsg && String(rawMsg).trim() !== ''
          ? rawMsg
          : `HTTP ${res.status} ${res.statusText || ''}`.trim();
        result.innerHTML = `<div class="alert alert-danger"><b>Import zlyhal</b><br>${escapeHtml(msg)}</div>`;
      } else {
        result.innerHTML = `
          <div class="alert alert-success">
            <b>Import OK</b><br>
            S&uacute;bor: ${escapeHtml(data.filename)}<br>
            M&oacute;d: ${data.mode === 'update' ? 'Prep&iacute;sa&#357; existuj&uacute;ce' : 'Presko&#269;i&#357; existuj&uacute;ce'}<br>
            Objedn&aacute;vky: ${data.orders}<br>
            Nov&eacute;: ${data.created}<br>
            Aktualizovan&eacute;: ${data.updated}<br>
            V&yacute;robn&eacute; polo&#382;ky: ${data.items}<br>
            Presko&#269;en&eacute; shipping/payment polo&#382;ky: ${data.skipped_shipping_items}<br>
            Presko&#269;en&eacute; existuj&uacute;ce objedn&aacute;vky: ${data.skipped_existing_orders || 0}<br>
            ${(data.skipped_existing_order_refs && data.skipped_existing_order_refs.length)
              ? `Existuj&uacute;ce (nezmenen&eacute;) ordery: ${escapeHtml(data.skipped_existing_order_refs.join(', '))}<br>`
              : ''}
            Presko&#269;en&eacute; zamknut&eacute; objedn&aacute;vky: ${data.skipped_locked_orders || 0}<br>
            ${(data.skipped_locked_order_refs && data.skipped_locked_order_refs.length)
              ? `Zamknut&eacute; ordery: ${escapeHtml(data.skipped_locked_order_refs.join(', '))}<br>`
              : ''}
            Pozn&aacute;mka: ${escapeHtml(data.note || '-')}
          </div>`;
      }
    } catch (err) {
      const details = [
        err && err.name ? `${err.name}: ${err.message || ''}`.trim() : String(err || 'Unknown error'),
        'Skus hard refresh a pozri v DevTools > Network odpoved pre scripts/upload_import_orders.php.'
      ];
      result.innerHTML = `<div class="alert alert-danger"><b>Chyba uploadu</b><br>${escapeHtml(details.join(' '))}</div>`;
    } finally {
      spinner.style.display = 'none';
      btnUpload.disabled = !file;
    }
  });
})();
</script>