<?php
  session_start();
$eno = $_GET['eno'];
$Year = $_GET['year'];
$Month = $_GET['month'];
$DateString = $Year.'-'.$Month;
$GetMonthName = date( 'F', strtotime($DateString));
$number = cal_days_in_month(CAL_GREGORIAN, $Month, $Year);

include('../includes/conn.php');

// Includneme XLSX generator chnyžnytsu 
require_once ('PhpXlsxGenerator.php'); 
 
// názov súboru pri stiahnutí 

$sql = "SELECT firstname, lastname FROM employees WHERE id = '$eno'"; // načítame zamestnanca
     $query = $conn->query($sql);
     while($row = $query->fetch_assoc()){
$fileName = $row['firstname'] . "-" . $row['lastname'] . "_" . $DateString . ".xlsx"; 
$excelData[] = array($row['firstname'],$row['lastname'],$GetMonthName,$Year);
     }
 
// Zadefinujeme názvy stĺpcov

$excelData[] = array('Dátum','Príchod','Odchod','Činnosť','Súčet');

// načítame údaje z databázy
$query = $conn->query("SELECT id, date, time_in, time_out, movement FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date LIKE '".$DateString."-%'"); 
if($query->num_rows > 0){ 
    while($row = $query->fetch_assoc()){ 
        switch ($row['movement']) {
            case '1': $movement = 'Práca'; break;
            case '2': $movement = 'Odchod domov'; break;
            case '3': $movement = 'Cigareta'; break;
            case '4': $movement = 'Obed'; break;
            case '5': $movement = 'Dovolenka'; break; 
            case '6': $movement = 'Maródka'; break;                      
            }
            $date = $row['date']; 
            $sucet = strtotime($row['time_out'])-strtotime($row['time_in']);
            $sucet = gmdate('H:i:s',$sucet);
            $lineData =  array($date, $row['time_in'], $row['time_out'], $movement, $sucet);  
            $excelData[] = $lineData; 
    } 
} 
 
// Exportneme dáta do excelu a dáme stiahnuť ako xlsx 
$xlsx = CodexWorld\PhpXlsxGenerator::fromArray( $excelData ); 
$xlsx->downloadAs($fileName); 
 
exit(); 
 
?>

