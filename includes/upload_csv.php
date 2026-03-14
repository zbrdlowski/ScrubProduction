<?php

// Handle feedback messages
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
    echo "<div class='alert alert-danger'><strong>Preskočené riadky:</strong><ul>";
    foreach ($_SESSION['skipped_details'] as $error) {
        echo "<li>" . implode(', ', $error['row']) . " — <em>{$error['reason']}</em></li>";
    }
    echo "</ul>";

    // Add download button
    echo '<a href="scripts/upload_errors.log" download class="btn btn-outline-dark mt-2">
            <i class="fas fa-download"></i> Stiahnuť log súbor
          </a>';

    echo "</div>";
    unset($_SESSION['skipped_details']);
}
?>

<!-- CSV Upload Form -->
<div class="container">
  <div class="panel panel-info">
    <div class="panel-heading">
      <h3 class="panel-title"><i class="fa fa-upload"></i> Upload inventory CSV to Import Stock</h3>
    </div>
    <div class="panel-body">
      <form method="POST" action="scripts/upload_csv.php" enctype="multipart/form-data" class="form-horizontal">
        <div class="form-group">
          <label class="col-sm-3 control-label">Upload CSV:</label>
          <div class="col-sm-9">
            <div id="drop-zone" class="well text-center" style="padding: 40px; border: 2px dashed #ccc; cursor: pointer;">
              <i class="fa fa-cloud-upload fa-4x text-info"></i>
              <p>Pleskni sem CSV<br>alebo klikni a vyber</p>
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
</div>
<script>
  const dropZone = document.getElementById('drop-zone');
  const fileInput = document.getElementById('csv_file');
  const feedback = document.getElementById('file-feedback');

  function showFileFeedback(file) {
    feedback.textContent = `✔️ "${file.name}" je ready. Davaj Bomby.`;
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

