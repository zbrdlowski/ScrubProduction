<head>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
</head>
<?php
    include 'includes/session.php';
    include 'includes/header.php';
    include '../sviatky.php';
     $eno = $_GET['eno']; // meno
     $Year =   $_GET['year'];
     $Month =  $_GET['month'];
     $SumarPraca = 0;
     $SumarDovca = 0;
     $SumarLekar = 0;
     $SumarPredpis = 0;
     $SumarWeekend = 0;
     $SumarSviatok = 0;
     $number = cal_days_in_month(CAL_GREGORIAN, $Month, $Year); // 31
     $VelkaNedela = date("d-m", easter_date($Year));
     $VelkyPiatok = date("d-m", easter_date($Year)-172800);
     $VelkyPondelok = date("d-m", easter_date($Year)+86400);
     
     //$DayToCalculate = $ThisYear.'-'.$PreviousMonth;
     $DayToTest = $Year.'-'.$Month;


     echo '<table border="0">';
     $sql = "SELECT * FROM employees WHERE id = '$eno'";
     $query = $conn->query($sql);
     while($row = $query->fetch_assoc()){
        // nacitame schedule pre konkretneho zamestnanca
      $ScheduleSQL = "SELECT SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) AS schedulediff, time_in, time_out FROM schedules WHERE id = ".$row['schedule_id']."";
      $ScheduleQuery = $conn->query($ScheduleSQL);
      while($Schedulerow = $ScheduleQuery->fetch_assoc()){
        // $ScheduleDiff -> pracovny cas zo schedules v sekundach
        $ScheduleDiff = $Schedulerow['schedulediff']-1800; // minus obed
        $PracovnaDoba = $Schedulerow['time_in'].' - '.$Schedulerow['time_out']; // kvoli výpisu v tabulke s userom
      } 
        SWITCH($row['gender']){
            case 'Male': $pohlavie = 'Narodený'; break;
            case 'Female': $pohlavie = 'Narodená'; break;
          }
       
        print '<tr ><td style="width:150px; padding:2px;">Meno:</td><td>'.$row['firstname'].' '.$row['lastname'].'</td>';      
        print'</tr>';

        print '<tr ><td style="width:150px; padding:2px;">Výkaz za obdobie:</td><td>'.$Month.' / '.$Year.'</td>';      
        print'</tr>';
        print "</table>";     
    }
     echo "<br />";
    echo '<table border="1" width="90%">';


     echo '<tr bgcolor="silver">';
     echo '<th align="center"><center>Deň</center></th>';
     echo '<th align="center"><center>Názov dňa</center></th>';
     echo '<th align="center"><center>Predpis</center></th>';
     echo '<th align="center"><center>Práca</center></th>';
     echo '<th align="center"><center>Dovolenka</center></th>';
     echo '<th align="center"><center>Lekár</center></th>';
    //echo '<th align="center"><center>Súčet</center></th>';
     //echo '<th align="center"><center>Saldokonto</center></th>';          
     echo '</tr>';


     for($i=01; $i <= $number; $i++){


        SWITCH(date('l', strtotime($DayToTest.'-'.$i))){
            case 'Monday' : $SlovakDay = 'Pondelok'; break;
            case 'Tuesday' : $SlovakDay = 'Utorok'; break;
            case 'Wednesday' : $SlovakDay = 'Streda'; break;
            case 'Thursday' : $SlovakDay = 'Štvrtok'; break;
            case 'Friday' : $SlovakDay = 'Piatok'; break;
            case 'Saturday' : $SlovakDay = 'Sobota'; break;
            case 'Sunday' : $SlovakDay = 'Nedeľa'; break;
        }
       
        if(strlen($i) == 1){
            $i_display = '0'.$i;
        }else{
            $i_display = $i;
        }


        // vypocet casov za jednotlive movementy
        include('calendar_row.php');
       
        
          // skusam velku noc
          if ($i_display."-".$Month == $VelkyPiatok || $i_display."-".$Month == $VelkyPondelok){
          $volnyDen = TRUE;
          }
          elseif(date('N', strtotime($DayToTest.'-'.$i)) > '5'){
            $volnyDen = TRUE;             
          }
          elseif(in_array($i_display."-".$Month, $sviatky)) {
            $volnyDen = TRUE;            
          }
          else{
            $volnyDen = FALSE;            
          }
    
        echo '<tr>';
               
        echo '<td align="center" width="15%" style="padding:2px;">' . $i_display . '</td>';
        echo '<td align="center" width="15%" style="padding:2px;">' . $SlovakDay . '</td>';

        
        // Predpísaný pracovaný čas
        echo '<td align="center" width="15%" style="padding:2px;">';
        if ($volnyDen == TRUE){echo '--';} 
        else{echo gmdate('H:i:s', $ScheduleDiff); $SumarPredpis = $SumarPredpis + $ScheduleDiff;}
        
        echo '</td>';

        // Odpracovaný čas
        echo '<td align="center" width="15%" style="padding:2px;">';
        if($CasovyFond != 0){echo gmdate('H:i:s', $CasovyFond);} else {echo '--';}  
        echo '</td>';
       
        echo '<td align="center" width="15%" style="padding:2px;">';
        // čas strávený na obede
        if($DovcaFond != 0){echo gmdate('H:i:s', $DovcaFond);} else {echo '--';}  
        //echo $ObedFond;
        echo '</td>';


        echo '<td align="center" width="15%" style="padding:2px;">';
        // čas strávený na obede
        if($MarodFond != 0){echo gmdate('H:i:s', $MarodFond);} else {echo '--';}  
        echo '</td>';

        /*
        echo'<td align="center" width="15%" style="padding:2px;">';
        if(date('H:i:s', $lumpsum-3600) != '00:00:00'){
            echo date('H:i:s', $lumpsum);
        }else{
            echo '<br />';
        }
        echo '</td>';  
        
        

        echo"<td align='center' width='15%' style='padding:2px;'>";
        if(!empty($CasovyFond)){
           
        if($CasovyFond > $ScheduleDiff){
            echo ''.date('\+ H:i:s', (($CasovyFond-$ScheduleDiff))).'';
        }else{
            echo ''.date('\- H:i:s', (($CasovyFond-$ScheduleDiff)*-1)).'';
           // echo '-'.$lumpsum;
            }
            $CasovyFond = 0;
        }else{
            echo '<br />';
        }
        echo "</td>"; 
        */
        
        echo '</tr>';
         
     }

    $odpracovane = ($SumarPraca + $SumarDovca + $SumarLekar);
    $SumarNadcas = $odpracovane - $SumarPredpis;
    $DniDovca = ($SumarDovca / 28800);
     switch ($DniDovca) {
      case '1':
        $KolkoDni = 'Deň';
        break;
        case '2':
          $KolkoDni = 'Dni';
          break;
          case '3':
            $KolkoDni = 'Dni';
            break;
            case '4':
              $KolkoDni = 'Dni';
              break;

      default:
        $KolkoDni = 'Dní';
        break;
     }
    $hpredpis = intval($SumarPredpis / 3600); 
    $SumarPredpis = $SumarPredpis - ($hpredpis * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mpredpis = intval($SumarPredpis / 60);
    if(strlen($mpredpis) == 1){$mpredpis = '0'.$mpredpis;}
    // Remaining value is seconds
    $spredpis = $SumarPredpis - ($mpredpis * 60);
    if(strlen($spredpis) == 1){$spredpis = '0'.$spredpis;}
    // Printing the result
    

    $hpraca = intval($SumarPraca / 3600); 
    $SumarPraca = $SumarPraca - ($hpraca * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mpraca = intval($SumarPraca / 60);
    if(strlen($mpraca) == 1){$mpraca = '0'.$mpraca;}
    // Remaining value is seconds
    $spraca = $SumarPraca - ($mpraca * 60);
    if(strlen($spraca) == 1){$spraca = '0'.$spraca;}
    // Printing the result


    $hdovca = intval($SumarDovca / 3600); 
    $SumarDovca = $SumarDovca - ($hdovca * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mdovca = intval($SumarDovca / 60);
    if(strlen($mdovca) == 1){$mdovca = '0'.$mdovca;}
    // Remaining value is seconds
    $sdovca = $SumarDovca - ($mdovca * 60);
    if(strlen($sdovca) == 1){$sdovca = '0'.$sdovca;}
    // Printing the result
    

    $hlekar = intval($SumarLekar / 3600); 
    $SumarLekar = $SumarLekar - ($hlekar * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mlekar = intval($SumarLekar / 60);
    if(strlen($mlekar) == 1){$mlekar = '0'.$mlekar;}
    // Remaining value is seconds
    $slekar = $SumarLekar - ($mlekar * 60);
    if(strlen($slekar) == 1){$slekar = '0'.$slekar;}
    // Printing the result

    $hnadcas = intval($SumarNadcas / 3600); 
    $SumarNadcas = $SumarNadcas - ($hnadcas * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mnadcas = intval($SumarNadcas / 60);
    if(strlen($mnadcas) == 1){$mnadcas = '0'.$mnadcas;}
    // Remaining value is seconds
    $snadcas = $SumarNadcas - ($mnadcas * 60);
    if(strlen($snadcas) == 1){$snadcas = '0'.$snadcas;}
    // Printing the result

    $hweekend = intval($SumarWeekend / 3600); 
    $SumarWeekend = $SumarWeekend - ($hweekend * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $mweekend = intval($SumarWeekend / 60);
    if(strlen($mweekend) == 1){$mweekend = '0'.$mweekend;}
    // Remaining value is seconds
    $sweekend = $SumarWeekend - ($mweekend * 60);
    if(strlen($sweekend) == 1){$sweekend = '0'.$sweekend;}
    // Printing the result

    $hsviatky = intval($SumarSviatok / 3600); 
    $SumarSviatok = $SumarSviatok - ($hsviatky * 3600);     
    // Minutes is obtained by dividing
    // remaining total time with 60
    $msviatky = intval($SumarSviatok / 60);
    if(strlen($msviatky) == 1){$msviatky = '0'.$msviatky;}
    // Remaining value is seconds
    $ssviatky = $SumarSviatok - ($msviatky * 60);
    if(strlen($ssviatky) == 1){$ssviatky = '0'.$ssviatky;}
    // Printing the result


     print '<tr>';
     print '<td colspan="2"><center>Súhrn</center></td>';
     print '<td><center>'; echo ("$hpredpis:$mpredpis:$spredpis"); print'</center></td>';
     print '<td><center>'; echo ("$hpraca:$mpraca:$spraca"); print'</center></td>';
     print '<td><center>'; echo ("$hdovca:$mdovca:$sdovca"); print'</center></td>';
     print '<td><center>'; echo ("$hlekar:$mlekar:$slekar"); print'</center></td>';
     print '</tr>';
     echo '</table>';

     echo '<br />';

     echo '<table border="1" width="90%">';

     echo '<tr>';

     echo '<td style="width:100px; padding:5px;">';
     echo 'Nadčas:';
     echo '</td>';
     echo '<td style="padding:5px;">';
     echo ("$hnadcas:$mnadcas:$snadcas");
     echo '</td>';

     echo '<td style="width:100px; padding:5px;">';
     echo 'Lekár';
     echo '</td>';
     echo '<td style="padding:5px;">';
     echo ("$hlekar:$mlekar:$slekar");
     echo '</td>';

     echo '</tr>';

     echo '<tr>';

     echo '<td style="width:100px; padding:5px;">';
     echo 'Sviatky:';
     echo '</td>';
     echo '<td style="padding:5px;">';
     echo ("$hsviatky:$msviatky:$ssviatky");
     echo '</td>';

     echo '<td style="width:100px; padding:5px;">';
     echo 'Dovolenky';
     echo '</td>';
     echo '<td style="padding:5px;">';
     echo  ' ' . $DniDovca . ' ' . $KolkoDni . ' ( ' . ("$hdovca:$mdovca:$sdovca") . ' Hodín )';
     echo '</td>';

     echo '</tr>';

     echo '<tr>';

     echo '<td style="width:100px; padding:5px;">';
     echo 'Weekendy:';
     echo '</td>';
     echo '<td style="padding:5px;">';
     //echo $SumarWeekend;
     echo ("$hweekend:$mweekend:$sweekend");
     echo '</td>';

     echo '<td style="width:100px; padding:5px;">';
     echo '&nbsp;';
     echo '</td>';
     echo '<td style="padding:5px;">';
     echo '&nbsp;';
     echo '</td>';

     echo '</tr>';

     echo '</table>';
     ?>
     <script type="text/javascript">
	function PrintPage() {
		window.print();
	}
window.addEventListener('DOMContentLoaded', (event) => {
   		PrintPage()
		setTimeout(function(){ window.close() },750)
	});
</script>