<?
 $lumpsum = 0;
 $CasovyFond = 0;
 $edit = FALSE;
 $CasovyFond = 0;
 $ObedFond = 0;
 $CigaFond = 0;
 $RawCasovyFond = 0;
 $RawObedValue = 0;
 $DovcaFond = 0;
 $MarodFond = 0;



 // Odpracovaný čas
 $ZratajCas =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS praca_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '1'") or die(mysqli_error());                      
 if($ZratajCas->num_rows > 0){
     while($row = $ZratajCas->fetch_array()){
         $edit = TRUE;
         if(date('H:i:s', $row['praca_sucet']-3600) != '00:00:00') {            
             $CasovyFond = $row['praca_sucet']; 
            // mark holiday/weekend; add to sums after lunch rounding
            $isSviatokDay = in_array($i_display."-".$Month, $sviatky);
            $isWeekendDay = (date('N', strtotime($DayToTest.'-'.$i)) > 5);
         }  
     }
 }
 
 // čas strávený na obede
 $ZratajObed =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS obed_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '4'") or die(mysqli_error());                      
 if($ZratajObed->num_rows > 0){
     while($ObedRow = $ZratajObed->fetch_array()){        
         if(date('H:i:s', $ObedRow['obed_sucet']-3600) != '00:00:00') {            
             $ObedFond = $ObedRow['obed_sucet'];
             $RawObedValue = $ObedRow['obed_sucet'];
            }
        }                            
    }




 // čas strávený hulením
 $ZratajCiga =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS ciga_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '3'") or die(mysqli_error());                      
 if($ZratajCiga->num_rows > 0){
     while($CigaRow = $ZratajCiga->fetch_array()){        
         if(date('H:i:s', $CigaRow['ciga_sucet']-3600) != '00:00:00') {            
             $CigaFond = $CigaRow['ciga_sucet'];        
            }
        }                            
     }

// Dovolenky
$ZratajDovca =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS dovca_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '5'") or die(mysqli_error());                      
if($ZratajDovca->num_rows > 0){
    while($DovcaRow = $ZratajDovca->fetch_array()){        
        if(date('H:i:s', $DovcaRow['dovca_sucet']-3600) != '00:00:00') {           
            $DovcaFond = $DovcaRow['dovca_sucet'];
            if($DovcaFond > $ScheduleDiff){$DovcaFond = $ScheduleDiff;}
                   
           }
       }                            
    }

// Lekar
$ZratajMarod =  $conn->query("SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS marod_sucet FROM `".$attdn_table."` WHERE employee_id = '$eno' AND date = '".$Year."-".$Month."-".$i."' AND movement = '6'") or die(mysqli_error());                      
if($ZratajMarod->num_rows > 0){
    while($MarodRow = $ZratajMarod->fetch_array()){        
        if(date('H:i:s', $MarodRow['marod_sucet']-3600) != '00:00:00') {           
            $MarodFond = $MarodRow['marod_sucet']; 
            if($MarodFond > $ScheduleDiff){$MarodFond = $ScheduleDiff;}
                     
           }
       }                            
    }


  // odpúzdriť v prípade potreby
 // Ak má obed menej ako pol hodinu, má pol hodinu. Sorry Patrik ...
 if($RawObedValue < 1800){ // zistíme, či má obed menej ako 1800 sekúnd (=> 30 minút)
    $odpocet = (1800-$RawObedValue); // vytvoríme premennú s hodnotou času čo chýba do 30 minút
    if($CasovyFond > 21600){ // ak má odpracovaných viac ako 6 hodím v danom dni, MUSÍ mať 30 min pauzu na obed bez prerušenia (§ 91 odst. ZP)
    $CasovyFond = ($CasovyFond - $odpocet); // odrátame z celkového odpracovaného času hodnotu čo chýbala do celého obeda
    $ObedFond = 1800; // obed zaokrúhlime na pol hodinu
    }    
 }
    
    // Add daily totals (weekend/holiday added here so lunch rounding is applied)
    $SumarPraca = $SumarPraca + $CasovyFond;
    $SumarLekar = $SumarLekar + $MarodFond;
    $SumarDovca = $SumarDovca + $DovcaFond;
    if (isset($isSviatokDay) && $isSviatokDay) { $SumarSviatok += $CasovyFond; }
    if (isset($isWeekendDay) && $isWeekendDay) { $SumarWeekend += $CasovyFond; }
?>
