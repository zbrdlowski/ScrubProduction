<?php
$uploadErrorsPath = 'scripts/upload_errors.log';
$fifoLogsDir = 'logs/receiving/fifo';
?>

<div class="row">

  <!-- ================= LEFT COLUMN: UPLOAD ERRORS ================= -->
  <div class="col-md-6">
    <div class="box box-danger">
      <div class="box-header with-border">
        <h3 class="box-title">
          <i class="fa fa-exclamation-circle"></i> Upload Errors Log
        </h3>
      </div>
      <div class="box-body">
        <?php
        if (file_exists($uploadErrorsPath)) {
            $lines = file($uploadErrorsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);

            if (count($lines) > 0) {
                echo "<ul class='list-group'>";
                foreach ($lines as $line) {
                    echo "<li class='list-group-item'>
                            <i class='fa fa-warning text-warning'></i> 
                            " . htmlspecialchars($line) . "
                          </li>";
                }
                echo "</ul>";
            } else {
                echo "<p class='text-success'><i class='fa fa-check-circle'></i> No errors logged.</p>";
            }
        } else {
            echo "<p class='text-info'><i class='fa fa-info-circle'></i> No log file found.</p>";
        }
        ?>
      </div>
    </div>
  </div>

  <!-- ================= RIGHT COLUMN: FIFO LOGS ================= -->
  <div class="col-md-6">
    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">
          <i class="fa fa-history"></i> FIFO Receiving Logs (Last 50)
        </h3>
      </div>
      <div class="box-body">
        <?php
        if (is_dir($fifoLogsDir)) {
            $files = array_diff(scandir($fifoLogsDir, SCANDIR_SORT_DESCENDING), ['.', '..']);
            $files = array_slice($files, 0, 50);

            if (count($files) > 0) {
                echo "<ul class='list-group'>";
                foreach ($files as $file) {
                    $filePath = $fifoLogsDir . '/' . $file;
                    if (is_file($filePath)) {
                        $fileSize = filesize($filePath);
                        $fileDate = filemtime($filePath);
                        $formattedDate = date('Y-m-d H:i:s', $fileDate);
                        $formattedSize = $fileSize > 1024 
                            ? round($fileSize / 1024, 2) . ' KB' 
                            : $fileSize . ' B';

                        echo "<li class='list-group-item'>
                                <a href='scripts/download_log.php?file=" . urlencode($file) . "' 
                                   class='text-primary' 
                                   title='Download log file'>
                                    <i class='fa fa-download'></i> {$file}
                                </a>
                                <br/>
                                <small class='text-muted'>
                                    <i class='fa fa-calendar'></i> {$formattedDate} | 
                                    <i class='fa fa-file-o'></i> {$formattedSize}
                                </small>
                              </li>";
                    }
                }
                echo "</ul>";
            } else {
                echo "<p class='text-info'><i class='fa fa-info-circle'></i> No FIFO logs found.</p>";
            }
        } else {
            echo "<p class='text-danger'><i class='fa fa-exclamation-triangle'></i> FIFO logs directory not found.</p>";
        }
        ?>
      </div>
    </div>
  </div>

</div>

