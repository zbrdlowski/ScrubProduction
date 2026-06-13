<div class="card card-dark">
  <div class="card-header">
    <h3 class="card-title">Import objednávok DARKSCRUB_IMPORT.csv</h3>
  </div>

  <div class="card-body">
    <div class="alert alert-info">
      Nahraj jednotný CSV export z Google Sheets tabu <b>DARKSCRUB_IMPORT</b>.
      Import funguje ako add/update podľa <code>source + external_order_id</code>.
    </div>

    <input id="sourceSelect" type="hidden" value="DARKSCRUB" />

    <div id="dropzone"
         style="border:2px dashed rgba(255,255,255,.3); border-radius:12px; padding:28px; text-align:center; cursor:pointer;">
      <div style="font-size:40px; opacity:.7;">
        <i class="fas fa-file-csv"></i>
      </div>
      <div style="font-size:18px; margin-top:10px;">
        Pretiahni DARKSCRUB_IMPORT.csv sem
      </div>
      <div class="text-muted" style="margin-top:6px;">
        alebo klikni pre výber súboru
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
        <span class="ml-2">Importujem…</span>
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
  const result = document.getElementById('result');
  const spinner = document.getElementById('spinner');
  let file = null;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }

  function setFile(f) {
    file = f;
    selectedFile.textContent = file ? `${file.name} (${Math.round(file.size/1024)} KB)` : '';
    btnUpload.disabled = !file;
  }

  dropzone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', e => e.target.files && e.target.files[0] && setFile(e.target.files[0]));

  function highlight(on) {
    dropzone.style.borderColor = on ? 'rgba(0,123,255,.9)' : 'rgba(255,255,255,.3)';
    dropzone.style.background = on ? 'rgba(0,123,255,.08)' : 'transparent';
  }

  ['dragenter','dragover'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); highlight(true); }));
  ['dragleave','drop'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); highlight(false); }));
  dropzone.addEventListener('drop', e => e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0] && setFile(e.dataTransfer.files[0]));

  btnUpload.addEventListener('click', async () => {
    if (!file) return;
    result.innerHTML = '';
    spinner.style.display = 'inline-flex';
    btnUpload.disabled = true;

    const form = new FormData();
    form.append('source', sourceSelect.value);
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
            Súbor: ${escapeHtml(data.filename)}<br>
            Objednávky: ${data.orders}<br>
            Nové: ${data.created}<br>
            Aktualizované: ${data.updated}<br>
            Výrobné položky: ${data.items}<br>
            Preskočené shipping/payment položky: ${data.skipped_shipping_items}<br>
            Poznámka: ${escapeHtml(data.note || '-')}
          </div>`;
      }
    } catch (err) {
      const details = [
        err && err.name ? `${err.name}: ${err.message || ''}`.trim() : String(err || 'Unknown error'),
        'Skús hard refresh a pozri v DevTools > Network odpoveď pre scripts/upload_import_orders.php.'
      ];
      result.innerHTML = `<div class="alert alert-danger"><b>Chyba uploadu</b><br>${escapeHtml(details.join(' '))}</div>`;
    } finally {
      spinner.style.display = 'none';
      btnUpload.disabled = !file;
    }
  });
})();
</script>
