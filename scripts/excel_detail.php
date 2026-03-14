<?php
include '../sviatky.php';
  session_start();
$eno = $_GET['eno'];
$Year = $_GET['year'];
$Month = $_GET['month'];
$DateString = $Year.'-'.$Month;
$number = cal_days_in_month(CAL_GREGORIAN, $Month, $Year);
$SumPredpis = '=SUM(D3:D'.($number + 2 ).')';
$SumPraca = '=SUM(E3:E'.($number + 2 ).')';
$SumDovolenky = '=SUM(H3:H'.($number + 2 ).')';
$SumMarodka = '=SUM(I3:I'.($number + 2 ).')';
$SumNadcas = "=(E".($number + 3 )."+H".($number + 3 )."+I".($number + 3 ).")-D".($number + 3 );
$Kumulativ = '0';
$KumulativSviatok = '0';
$KumulativSobota = '0';
$KumulativNedela = '0';
$PracaNormal = '0';
$PracaSobota = '0';
$PracaNedela = '0';
$PracaSviatok = '0';
$sviatok = FALSE;
$Sobota = FALSE;
$Nedela = FALSE;
$VelkyPiatok = date("d-m", easter_date($Year)-172800);
$VelkyPondelok = date("d-m", easter_date($Year)+86400);
include('../includes/conn.php');

$sql = "SELECT firstname, lastname, schedule_id FROM employees WHERE id = '$eno'"; // načítame zamestnanca
     $query = $conn->query($sql);
     while($row = $query->fetch_assoc()){
$fileName = $row['firstname'] . "-" . $row['lastname'] . "_" . $DateString . "_sumary.xlsx";
$chto =  $row['firstname'] . "-" . $row['lastname'];
$excelData[] = array($row['firstname'],$row['lastname'],$mesiace[$Month],$Year);
$ScheduleSQL = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS schedulediff, time_in, time_out FROM schedules WHERE id = ".$row['schedule_id']."";      
      $ScheduleQuery = $conn->query($ScheduleSQL);
      while($Schedulerow = $ScheduleQuery->fetch_assoc()){
        // $ScheduleDiff -> pracovny cas zo schedules v sekundach
        $ScheduleDiff = $Schedulerow['schedulediff']-1800; // minus obed
        $PracovnaDoba = $Schedulerow['time_in'].' - '.$Schedulerow['time_out']; // kvoli výpisu v tabulke s userom
        $StartPraca = $Schedulerow['time_in'];
        $EndPraca = $Schedulerow['time_out'];        
      }
     }
 
// Includneme XLSX generator chnyžnytsu
require_once ('PhpXlsxGenerator.php');


// Zadefinujeme názvy stĺpcov
$excelData[] = array('Dátum','Deň','Prac. Doba','Predpis','Práca','Obed','Prestávky','Dovolenky','Maródka','Nadčas');

for($i=01; $i <= $number; $i++){

    if(strlen($i) == 1){
      $Display_i = '0'.$i;
    }else{
      $Display_i = $i;
    }
/*
    if(in_array($Display_i."-".$Month, $sviatky)) {
        $sviatok = TRUE;        
      }else{$sviatok = FALSE;}
    if(date('N', strtotime($DateString.'-'.$i)) == '6'){
        $Sobota = TRUE;        
      }else{$Sobota = FALSE;}
    if(date('N', strtotime($DateString.'-'.$i)) == '7'){
        $Nedela = TRUE;        
        }else{$Nedela = FALSE;}
        */
    $CasovyFond = '00:00:00';
    $ObedFond = '00:00:00';
    $CigaFond = '00:00:00';
    $DovcaFond = '00:00:00';
    $MarodFond = '00:00:00';
    $Nadcas = '00:00:00';

    SWITCH(date('l', strtotime($DateString.'-'.$Display_i))){
        case 'Monday' : $SlovakDay = 'Pondelok'; break;
        case 'Tuesday' : $SlovakDay = 'Utorok'; break;
        case 'Wednesday' : $SlovakDay = 'Streda'; break;
        case 'Thursday' : $SlovakDay = 'Štvrtok'; break;
        case 'Friday' : $SlovakDay = 'Piatok'; break;
        case 'Saturday' : $SlovakDay = 'Sobota'; break;
        case 'Sunday' : $SlovakDay = 'Nedeľa'; break;
    }

 // Odpracovaný čas
 $ZratajCas =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS praca_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '1'") or die(mysqli_error());                      
 if($ZratajCas->num_rows > 0){
     while($row = $ZratajCas->fetch_array()){
         $edit = TRUE;
         if(date('H:i:s', $row['praca_sucet']-3600) != '00:00:00') {
             // store raw values and mark day type flags; defer adding to cumulative sums until after lunch rounding
             $lumpsum = $lumpsum + $row['praca_sucet'];
             $CasovyFond = $row['praca_sucet'];
             $RawCasovyFond = $row['praca_sucet'];
             $isSviatokDay = in_array($Display_i."-".$Month, $sviatky);
             $isSobotaDay = (date('N', strtotime($DateString.'-'.$i)) == '6');
             $isNedelaDay = (date('N', strtotime($DateString.'-'.$i)) == '7');
             if($CasovyFond > 21600){$obed = TRUE;}else{$obed = FALSE;}
             $PracaNormal = $PracaNormal + 1;
             $Nadcas = $CasovyFond - $ScheduleDiff;
         }
     }
 }else{
     $CasovyFond = '00:00:00';
 }
 
 // čas strávený na obede
 $ZratajObed =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS obed_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '4'") or die(mysqli_error());                      
 if($ZratajObed->num_rows > 0){
     while($ObedRow = $ZratajObed->fetch_array()){        
         if(date('H:i:s', $ObedRow['obed_sucet']-3600) != '00:00:00') {
             $lumpsum = $lumpsum - $ObedRow['obed_sucet'];
             $ObedFond = $ObedRow['obed_sucet'];
             $RawObedValue = $ObedRow['obed_sucet'];
            }
        }                            
    }else{$ObedFond = '00:00:00';}


 // čas strávený hulením
 $ZratajCiga =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS ciga_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '3'") or die(mysqli_error());                      
 if($ZratajCiga->num_rows > 0){
     while($CigaRow = $ZratajCiga->fetch_array()){        
         if(date('H:i:s', $CigaRow['ciga_sucet']-3600) != '00:00:00') {
             $lumpsum = $lumpsum - $CigaRow['ciga_sucet'];
             $CigaFond = $CigaRow['ciga_sucet'];        
            }
        }                            
     }else{$CigaFond = '00:00:00';}

 // Odpúzdriť v prípade potreby

     // Ak má obed menej ako pol hodinu, má pol hodinu. Sorry Patrik ...
     if($obed == TRUE){
        if($ObedFond == '00:00:00'){            
            if($CasovyFond != '00:00:00'){
                $ObedFond = 1800; 
                //$CasovyFond = ($CasovyFond - $ObedFond);
            } $obed = FALSE;
        }

 if($RawObedValue < 1800){ // zistíme, či má obed menej ako 1800 sekúnd (=> 30 minút)
    $odpocet = (1800-$RawObedValue); // vytvoríme premennú s hodnotou času čo chýba do 30 minút
    if($CasovyFond > 21600){ // ak má odpracovaných viac ako 6 hodím v danom dni, MUSÍ mať 30 min pauzu na obed bez prerušenia (§ 91 odst. ZP)
    $CasovyFond = ($CasovyFond - $odpocet); // odrátame z celkového odpracovaného času hodnotu čo chýbala do celého obeda
    $ObedFond = 1800; // obed zaokrúhlime na pol hodinu
        }    
    }
}

// dovolenky

$ZratajDovca =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS dovolenka FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '5'") or die(mysqli_error());                      
if($ZratajDovca->num_rows > 0){
    while($DovcaRow = $ZratajDovca->fetch_array()){        
        if(date('H:i:s', $DovcaRow['dovolenka']-3600) != '00:00:00') {
            $lumpsum = $lumpsum - $DovcaRow['dovolenka'];
            $DovcaFond = $DovcaRow['dovolenka'];
            if($DovcaFond >= 21600){
                $DovcaFond = $DovcaFond -1800;
            }
            //$DovcaFond = GMDATE('H:i:s',$DovcaFond);        
           }
       }                            
    }else{$DovcaFond = '00:00:00';}

// Marodka

$ZratajMarod =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS marodka FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '6'") or die(mysqli_error());                      
if($ZratajMarod->num_rows > 0){
    while($MarodRow = $ZratajMarod->fetch_array()){        
       if(date('H:i:s', $MarodRow['marodka']-3600) != '00:00:00') {
            $lumpsum = $lumpsum - $MarodRow['marodka'];
            $MarodFond = $MarodRow['marodka'];
            if($MarodFond >= 21600){
                $MarodFond = $MarodFond -1800;
            }
            //$MarodFond = GMDATE('H:i:s',$MarodFond);        
          }
       }                            
    }else{$MarodFond = '00:00:00';}
          // Add adjusted work time to cumulative sums (after lunch rounding)
    if((int)$CasovyFond > 0){
        $Kumulativ = $Kumulativ + $CasovyFond;
        if(isset($isSviatokDay) && $isSviatokDay){ $PracaSviatok = $PracaSviatok + $CasovyFond; }
        if(isset($isSobotaDay) && $isSobotaDay){ $PracaSobota = $PracaSobota + $CasovyFond; }
        if(isset($isNedelaDay) && $isNedelaDay){ $PracaNedela = $PracaNedela + $CasovyFond; }
    }

          // Ak Sviatok  
    if(in_array($Display_i."-".$Month, $sviatky)){
        $lineData =  array($i.'.'.$Month.'.'.$Year, $SlovakDay,'','', gmdate('H:i:s',((int)$CasovyFond)), gmdate('H:i:s',((int)$ObedFond)), gmdate('H:i:s',((int)$CigaFond)), gmdate('H:i:s',((int)$DovcaFond)), gmdate('H:i:s',((int)$MarodFond)));
    }
            // Samostatne Velka noc - pohyblivý sviatok
    elseif ($Display_i."-".$Month == $VelkyPiatok || $Display_i."-".$Month == $VelkyPondelok){
        $lineData =  array($i.'.'.$Month.'.'.$Year, $SlovakDay,'','', gmdate('H:i:s',((int)$CasovyFond)), gmdate('H:i:s',((int)$ObedFond)), gmdate('H:i:s',((int)$CigaFond)), gmdate('H:i:s',((int)$DovcaFond)), gmdate('H:i:s',((int)$MarodFond)));
    }
            // Soboty
    elseif(date('N', strtotime($DateString.'-'.$i)) == '6'){
        $lineData =  array($i.'.'.$Month.'.'.$Year, $SlovakDay,'','', gmdate('H:i:s',((int)$CasovyFond)), gmdate('H:i:s',((int)$ObedFond)), gmdate('H:i:s',((int)$CigaFond)), gmdate('H:i:s',((int)$DovcaFond)), gmdate('H:i:s',((int)$MarodFond)));
    }
            // Nedele
    elseif(date('N', strtotime($DateString.'-'.$i)) == '7'){
        $lineData =  array($i.'.'.$Month.'.'.$Year, $SlovakDay,'','', gmdate('H:i:s',((int)$CasovyFond)), gmdate('H:i:s',((int)$ObedFond)), gmdate('H:i:s',((int)$CigaFond)), gmdate('H:i:s',((int)$DovcaFond)), gmdate('H:i:s',((int)$MarodFond)));
    }
            // Normálny výpis
    else{
        $lineData =  array($i.'.'.$Month.'.'.$Year, $SlovakDay,$PracovnaDoba,gmdate('H:i:s',((int)$ScheduleDiff)), gmdate('H:i:s',((int)$CasovyFond)), gmdate('H:i:s',((int)$ObedFond)), gmdate('H:i:s',((int)$CigaFond)), gmdate('H:i:s',((int)$DovcaFond)), gmdate('H:i:s',((int)$MarodFond)));
    }

             
            $excelData[] = $lineData;  
            
        // reset premennych na nadcas
        $Kumulativ = '0';
        $KumulativSviatok = '0';
        $KumulativSobota = '0';
        $KumulativNedela = '0';
            
        }  
        $excelData[] = array('','','',$SumPredpis,$SumPraca,'','',$SumDovolenky,$SumMarodka,$SumNadcas);
        $excelData[] = '\n';
        $excelData[] = '\n';
        $excelData[] = array('Spolu Dní:',$PracaNormal);         
        $excelData[] = array('Z toho Sviatky:',gmdate('H:i:s',$PracaSviatok));    
        $excelData[] = array('Z toho Soboty:',gmdate('H:i:s',$PracaSobota));    
        $excelData[] = array('z toho Nedele:',gmdate('H:i:s',$PracaNedela));
          //  $excelData[] = array('','',$SumWork,$SumObed); // totok akosik nefunguje korektne

// Exportneme dáta do excelu a dáme stiahnuť ako xlsx 
$xlsx = CodexWorld\PhpXlsxGenerator::fromArray( $excelData ); 
$xlsx->downloadAs($fileName); 
 
exit(); 
 
?>

