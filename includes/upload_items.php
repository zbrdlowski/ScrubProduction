<?php

// MESSAGES
if (isset($_SESSION['error'])) {
    echo "<div class='alert alert-danger alert-dismissible'>
            <button type='button' class='close' data-dismiss='alert'>&times;</button>
            <strong>Error:</strong> {$_SESSION['error']}
          </div>";
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    echo "<div class='alert alert-success alert-dismissible'>
            <button type='button' class='close' data-dismiss='alert'>&times;</button>
            <strong>Success:</strong> {$_SESSION['success']}
          </div>";
    unset($_SESSION['success']);
}
if (isset($_SESSION['skipped_details'])) {
    echo "<div class='alert alert-warning'><strong>Skipped Rows:</strong><ul>";
    foreach ($_SESSION['skipped_details'] as $error) {
        echo "<li>" . implode(', ', $error['row']) . " — <em>{$error['reason']}</em></li>";
    }
    echo "</ul>";
    echo '<a href="scripts/upload_errors.log" download class="btn btn-outline-dark mt-2">
            <i class="fas fa-download"></i> Download Log File
          </a>';
    echo "</div>";
    unset($_SESSION['skipped_details']);
}
?>

<div class="container">
  <div class="panel panel-info">
    <div class="panel-heading">
      <h3 class="panel-title"><i class="fa fa-database"></i> Import / Update Items from CSV</h3>
    </div>

    <div class="panel-body">

      <p class="text-muted">This tool will add new items and update existing ones (matched by Part Numbers).</p>

      <form method="POST" action="scripts/upload_items.php" enctype="multipart/form-data" class="form-horizontal">

        <div class="form-group">
          <label class="col-sm-3 control-label">CSV File:</label>
          <div class="col-sm-9">
            <div id="drop-zone" class="well text-center"
                 style="padding: 40px; border: 2px dashed #666; cursor: pointer;">
              <i class="fa fa-cloud-upload fa-4x text-info"></i>
              <p>Drop CSV here<br>or click to select</p>
              <p id="file-feedback" class="text-success" style="margin-top:10px; display:none;"></p>
              <input type="file" name="csv_file" id="csv_file" accept=".csv" required style="display: none;">
            </div>
          </div>
        </div>

        <div class="form-group">
          <div class="col-sm-offset-3 col-sm-9">
            <button type="submit" class="btn btn-primary">
              <i class="fa fa-upload"></i> Import Items
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
  const dropZone = document.getElementById('drop-zone');
  const fileInput = document.getElementById('csv_file');
  const feedback = document.getElementById('file-feedback');

  function showFileFeedback(file) {
    feedback.textContent = `✔️ "${file.name}" is loaded.`;
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
