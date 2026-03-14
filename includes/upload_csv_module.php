<!--
Optional Parameters
action: Path to your upload handler script

label: Custom title for the panel
-->

<?php
// upload_csv_module.php
// Include this file wherever you want the upload form to appear

function renderCsvUploadForm($action = 'scripts/upload_csv.php', $label = 'Upload inventory CSV to Import Stock') {
?>
<div class="panel panel-info">
  <div class="panel-heading">
    <h3 class="panel-title"><i class="fa fa-upload"></i> <?= htmlspecialchars($label) ?></h3>
  </div>
  <div class="panel-body">
    <form method="POST" action="<?= htmlspecialchars($action) ?>" enctype="multipart/form-data" class="form-horizontal">
      <div class="form-group">
        <label class="col-sm-3 control-label">Upload CSV:</label>
        <div class="col-sm-9">
          <div id="drop-zone" class="well text-center" style="padding: 40px; border: 2px dashed #ccc; cursor: pointer;">
            <i class="fa fa-cloud-upload fa-4x text-info"></i>
            <p>Drag & drop your CSV file here<br>or click to browse</p>
            <p id="file-feedback" class="text-success" style="margin-top:10px; display:none;"></p>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" required style="display: none;">
          </div>
        </div>
      </div>
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
          <button type="submit" name="upload_csv" class="btn btn-primary">
            <i class="fa fa-cloud-upload"></i> Upload & Import
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
}
?>


<?php
include 'upload_csv_module.php';
renderCsvUploadForm(); // Optional: pass custom action or label
?>

<script>
  const dropZone = document.getElementById('drop-zone');
  const fileInput = document.getElementById('csv_file');
  const feedback = document.getElementById('file-feedback');

  function showFileFeedback(file) {
    feedback.textContent = `✔️ "${file.name}" is ready to upload.`;
    feedback.style.display = 'block';
  }

  dropZone.addEventListener('click', () => fileInput.click());

  dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('bg-info');
  });

  dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('bg-info');
  });

  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('bg-info');
    fileInput.files = e.dataTransfer.files;
    if (fileInput.files.length > 0) {
      showFileFeedback(fileInput.files[0]);
    }
  });

  fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) {
      showFileFeedback(fileInput.files[0]);
    }
  });
</script>

