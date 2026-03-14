<?php
session_start();
include('../includes/conn.php');

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != 0) {
    $_SESSION['error'] = "No CSV file uploaded.";
    header('Location: ../index.php?page=upload_items');
    exit;
}

$file = $_FILES['csv_file']['tmp_name'];
$handle = fopen($file, "r");

if (!$handle) {
    $_SESSION['error'] = "Cannot read uploaded file.";
    header('Location: ../index.php?page=upload_items');
    exit;
}

$inserted = 0;
$updated = 0;
$skipped = [];

$logFile = fopen("upload_errors.log", "w");

$pdo->beginTransaction();

// SKIP HEADER ROW
$headerRow = fgetcsv($handle, 2000, ",");

while (($row = fgetcsv($handle, 2000, ",")) !== false) {

    if (count($row) < 2) continue;

    // Correct barcode (column 0)
    $barcode = trim($row[0]);

    if ($barcode == "") {
        $skipped[] = ['row' => $row, 'reason' => "No barcode"];
        fwrite($logFile, "Skipped: No barcode\n");
        continue;
    }

    // CSV → DB
    $data = [
        'barcode'       => $row[0]  ?? null,
        'brand'         => $row[1]  ?? null,
        'scrubcode'     => $row[2]  ?? null,
        'name'          => $row[3]  ?? null,
        'description'   => $row[4]  ?? null,
        'color'         => $row[5]  ?? null,   
        'optimum'       => $row[6]  ?? null,
        'moq'           => $row[7]  ?? null,
        'main_supplier' => $row[8]  ?? null,        
        'ufo_pn'        => $row[9] ?? null,
        'ufo_barcode'   => $row[10] ?? null,
        'rt_pn'         => $row[11] ?? null,
        'rt_barcode'    => $row[12] ?? null,
        'ps_pn'         => $row[13] ?? null,
        'ps_barcode'    => $row[14] ?? null,
        'ac_pn'         => $row[15] ?? null,
        'ac_barcode'    => $row[16] ?? null,
        'other_pn'      => $row[17] ?? null,
        'other_barcode' => $row[18] ?? null
    ];

    // Check existence
    $stmt = $pdo->prepare("SELECT id FROM items WHERE barcode = :barcode LIMIT 1");
    $stmt->execute(['barcode' => $barcode]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        // UPDATE
        $sql = "UPDATE items SET
            brand         = :brand,
            scrubcode     = :scrubcode,
            name          = :name,
            description   = :description,
            color         = :color, 
            optimum       = :optimum,
            moq           = :moq,
            main_supplier = :main_supplier,           
            ufo_pn        = :ufo_pn,
            ufo_barcode   = :ufo_barcode,
            rt_pn         = :rt_pn,
            rt_barcode    = :rt_barcode,
            ps_pn         = :ps_pn,
            ps_barcode    = :ps_barcode,
            ac_pn         = :ac_pn,
            ac_barcode    = :ac_barcode,
            other_pn      = :other_pn,
            other_barcode = :other_barcode
            WHERE barcode = :barcode";

        $pdo->prepare($sql)->execute($data);
        $updated++;

    } else {
        // INSERT
        $sql = "INSERT INTO items
            (brand, barcode, scrubcode, name, description, color, optimum, moq, main_supplier,
              ufo_pn, ufo_barcode, rt_pn, rt_barcode, ps_pn, ps_barcode, ac_pn, ac_barcode, other_pn, other_barcode)
            VALUES
            (:brand, :barcode, :scrubcode, :name, :description, :color, :optimum, :moq, :main_supplier,
              :ufo_pn, :ufo_barcode, :rt_pn, :rt_barcode, :ps_pn, :ps_barcode, :ac_pn, :ac_barcode, :other_pn, :other_barcode)";
        
        $pdo->prepare($sql)->execute($data);
        $inserted++;
    }
}

$pdo->commit();
fclose($logFile);
fclose($handle);

// FEEDBACK
$msg = [];
if ($inserted) $msg[] = "$inserted inserted";
if ($updated)  $msg[] = "$updated updated";

if ($skipped) {
    $_SESSION['skipped_details'] = $skipped;
}

$_SESSION['success'] = "Done: " . implode(", ", $msg);
header('Location: ../index.php?page=upload_items');
exit;
?>