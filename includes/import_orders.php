<div class="card card-dark">
  <div class="card-header">
    <h3 class="card-title">Import objednávok (CSV)</h3>
  </div>

  <div class="card-body">

    <div class="form-group">
      <label for="sourceSelect">Zdrojová platforma</label>
      <select id="sourceSelect" class="form-control">
        <option value="EBAY">eBay</option>
        <option value="SHOPTET">Shoptet</option>
        <option value="MX_LOCKER">MX Locker</option>
      </select>
      <small class="text-muted">
        Vyber platformu a potom pretiahni CSV alebo klikni a vyber súbor.
      </small>
    </div>

    <div id="dropzone"
         style="border:2px dashed rgba(255,255,255,.3); border-radius:12px; padding:28px; text-align:center; cursor:pointer;">
      <div style="font-size:40px; opacity:.7;">
        <i class="fas fa-file-csv"></i>
      </div>
      <div style="font-size:18px; margin-top:10px;">
        Pretiahni CSV sem
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

  function setFile(f) {
    file = f;
    if (!file) {
      selectedFile.textContent = '';
      btnUpload.disabled = true;
      return;
    }
    selectedFile.textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)';
    btnUpload.disabled = false;
  }

  dropzone.addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', (e) => {
    if (e.target.files && e.target.files[0]) setFile(e.target.files[0]);
  });

  function highlight(on) {
    dropzone.style.borderColor = on ? 'rgba(0,123,255,.9)' : 'rgba(255,255,255,.3)';
    dropzone.style.background = on ? 'rgba(0,123,255,.08)' : 'transparent';
  }

  ['dragenter','dragover'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); highlight(true); });
  });
  ['dragleave','drop'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); highlight(false); });
  });

  dropzone.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    if (dt && dt.files && dt.files[0]) setFile(dt.files[0]);
  });

  btnUpload.addEventListener('click', async () => {
    if (!file) return;

    result.innerHTML = '';
    spinner.style.display = 'inline-flex';
    btnUpload.disabled = true;

    const form = new FormData();
    form.append('source', sourceSelect.value);
    form.append('file', file);

    try {
      const res = await fetch('scripts/upload_import_orders.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
      });

      const txt = await res.text();
console.log(txt);
result.innerHTML = `<pre style="white-space:pre-wrap">${txt.replace(/</g,'&lt;')}</pre>`;
return;

      if (!res.ok || !data.ok) {
        const msg = data && data.error ? data.error : 'Import failed';
        result.innerHTML = `<div class="alert alert-danger">${msg}</div>`;
      } else {
        result.innerHTML = `
          <div class="alert alert-success">
            <b>Import OK</b><br/>
            Zdroj: ${data.source}<br/>
            Súbor: ${data.filename}<br/>
            Objednávky: ${data.orders}<br/>
            Položky: ${data.items}<br/>
            Poznámka: ${data.note || '-'}
          </div>
        `;
      }
    } catch (err) {
      result.innerHTML = `<div class="alert alert-danger">Chyba: ${err}</div>`;
    } finally {
      spinner.style.display = 'none';
      btnUpload.disabled = !file;
    }
  });
})();
</script>