
<?   
    echo '<table class="table table-bordered">';
           
        
        echo '<tr bgcolor="silver"><td rowspan="5" width="1%"><img src="../images/profile.jpg" height="140px"></td></tr>';
        print '<tr bgcolor="silver"><td width="2%">Meno:</td><td>&nbsp;</td><td>&nbsp;</td>';      
        print'</tr>';

        print '<tr bgcolor="silver"><td width="2%">Bydlisko:</td><td>&nbsp;</td>';
        
        print '<td>';
        print '<div class="form-group">';
        print '<label for="movement" class="col-sm-3 control-label">Vyber Zamestnanca</label>';
        print '<div class="col-sm-9">';
        print '<select class="form-control" id="eno" name="eno" onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
        $sql2 = "SELECT id, firstname, lastname FROM employees";
        print '<option value="calendar.php?eno=&year='.$Year.'&month='.$Month.'">Vyber zamestnanca</option>';
		$query2 = $conn->query($sql2);
		while($row2 = $query2->fetch_array()){
		echo '<option value="calendar.php?eno='.$row2['id'].'&year='.$Year.'&month='.$Month.'">'.$row2['firstname'].' '.$row2['lastname'].'</option>';	
		}						
        print '</select>';
        print '</div>';
        print '</label>';
        print '</div>';
        print '</td>';
        
        print '</tr>';
        print '<tr bgcolor="silver"><td width="2%">Narodený:</td><td>&nbsp;</td>';
        
        print '<td>';
        print '<div class="form-group">';
        print '<label for="month" class="col-sm-3 control-label">Vyber Mesiac</label>';
        print '<div class="col-sm-9">';
        print '<select class="form-control" id="month" name="month"  onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=01"'; if($Month == '01'){print 'selected';} print '>Január</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=02"'; if($Month == '02'){print 'selected';} print '>Február</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=03"'; if($Month == '03'){print 'selected';} print '>Marec</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=04"'; if($Month == '04'){print 'selected';} print '>Apríl</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=05"'; if($Month == '05'){print 'selected';} print '>Máj</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=06"'; if($Month == '06'){print 'selected';} print '>Jún</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=07"'; if($Month == '07'){print 'selected';} print '>Júl</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=08"'; if($Month == '08'){print 'selected';} print '>August</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=09"'; if($Month == '09'){print 'selected';} print '>September</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=10"'; if($Month == '10'){print 'selected';} print '>Október</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=11"'; if($Month == '11'){print 'selected';} print '>November</option>';
        echo '<option value="calendar.php?eno='.$eno.'&year='.$Year.'&month=12"'; if($Month == '12'){print 'selected';} print '>December</option>';						
        print '</select>';
        print '</div>';
        print '</label>';
        print '</div>';
        print '</td></tr>';
        print '<tr bgcolor="silver"><td width="2%">Telefón:</td><td>';
        Print '&nbsp;';
        print '<td>';
        print '<div class="form-group">';
        print '<label for="year" class="col-sm-3 control-label">Vyber Rok</label>';
        print '<div class="col-sm-9">';
        print '<select class="form-control" id="year" name="year" required>';
        for($i=2024; $i <= 2035; $i++){
            print '<option value="'.$i.'">'.$i.'</option>';
        }        			
        print '</select>';
        print '</div>';
        print '</label>';
        print '</div>';
        print'</td></tr>';
        
       // print '<tr><td>'.$row['gender'].'</td></tr>';

      // konec tabulky s userom
     ?>