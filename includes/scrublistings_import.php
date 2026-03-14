<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$canEdit = (isset($_SESSION['permission']) && (int)$_SESSION['permission'] >= 300);
?>

<?php if (!$canEdit): ?>
  <div></div>
<?php else: ?>

<div class="card card-primary card-outline shadow-sm">
  <div class="card-header bg-gradient-primary">
    <h3 class="card-title text-white mb-0">
      <i class="fas fa-file-upload mr-1"></i> Import Scrub Listings (CSV)
    </h3>
  </div>

  <div class="card-body">

    <div class="form-group">
      <label class="text-muted small mb-1">Mode</label>
      <select id="importMode" class="form-control form-control-sm" style="max-width: 260px;">
        <option value="merge" selected>Merge (add/update, keep existing items)</option>
        <option value="replace">Replace items (per listing)</option>
      </select>
    </div>

    <div id="dropzone"
         class="border border-secondary rounded p-4 text-center"
         style="border-style: dashed !important; cursor: pointer;">
      <div class="text-muted">
        <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i>
        <div><b>Drag & Drop CSV</b> sem, alebo klikni pre výber súboru.</div>
        <div class="small mt-2">
          Áno Patrik, Header MUSÍ !!! byť: <code>listing_code,listing_name,model_code,price,barcode,sort_order</code>
        </div>
      </div>
      <input id="fileInput" type="file" accept=".csv,text/csv" hidden>
    </div>

    <div class="mt-3">
      <button id="btnUpload" class="btn btn-primary btn-sm" disabled>
        <i class="fas fa-upload"></i> Upload & Import
      </button>
      <span id="fileName" class="ml-2 text-muted small"></span>
    </div>

    <div id="result" class="mt-3"></div>
  </div>
</div>

<script>
  const IMPORT_ENDPOINT = 'scripts/scrublistings/import_csv.php';

  const dz = document.getElementById('dropzone');
  const fi = document.getElementById('fileInput');
  const btn = document.getElementById('btnUpload');
  const fileNameEl = document.getElementById('fileName');
  const resultEl = document.getElementById('result');
  const modeEl = document.getElementById('importMode');

  let selectedFile = null;

  function setFile(f) {
    selectedFile = f;
    if (f) {
      btn.disabled = false;
      fileNameEl.textContent = `${f.name} (${Math.round(f.size/1024)} KB)`;
    } else {
      btn.disabled = true;
      fileNameEl.textContent = '';
    }
  }

  dz.addEventListener('click', () => fi.click());

  dz.addEventListener('dragover', (e) => {
    e.preventDefault();
    dz.classList.add('bg-dark');
  });

  dz.addEventListener('dragleave', () => dz.classList.remove('bg-dark'));

  dz.addEventListener('drop', (e) => {
    e.preventDefault();
    dz.classList.remove('bg-dark');
    const f = e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) setFile(f);
  });

  fi.addEventListener('change', () => {
    const f = fi.files && fi.files[0];
    if (f) setFile(f);
  });

  btn.addEventListener('click', async () => {
    if (!selectedFile) return;

    resultEl.innerHTML = '<div class="text-muted">Importujem…</div>';

    const fd = new FormData();
    fd.append('csv_file', selectedFile);
    fd.append('mode', modeEl.value);

    const resp = await fetch(IMPORT_ENDPOINT, { method: 'POST', body: fd });
    let json = null;
    try { json = await resp.json(); } catch (e) {}

    if (!resp.ok || !json || json.ok !== true) {
      const msg = (json && json.error) ? json.error : `Request failed (${resp.status})`;
      resultEl.innerHTML = `<div class="alert alert-danger">${msg}</div>`;
      return;
    }

    let html = `
      <div class="alert alert-success">
        <b>Hotovo!</b>
        Listings upserted: <b>${json.listings_upserted}</b>,
        Items inserted: <b>${json.items_inserted}</b>,
        Items skipped (duplicates/empty): <b>${json.items_skipped}</b>
      </div>
    `;

    if (json.errors && json.errors.length) {
      html += `<div class="alert alert-warning"><b>Warnings/Errors (${json.errors.length})</b><br>`;
      html += '<ul class="mb-0">';
      json.errors.slice(0, 50).forEach(er => { html += `<li>${er}</li>`; });
      if (json.errors.length > 50) html += `<li>…and more</li>`;
      html += '</ul></div>';
    }

    resultEl.innerHTML = html;

    // optional: refresh listing page
    // location.reload();
  });
</script>

<?php endif; ?>