
    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-3">

            <!-- Profile Image -->
            <div class="card card-primary card-outline">
              <div class="card-body box-profile">
                <div class="text-center">
                  <img class="profile-user-img img-fluid img-circle"
                       src="<?php echo (!empty($_SESSION['user_photo'])) ? 'images/'.$_SESSION['user_photo'] : 'images/profile.jpg'; ?>"
                       alt="User profile picture">
                </div>
                
                <h3 class="profile-username text-center"><? echo $_SESSION['name']; ?></h3>

                <p class="text-muted text-center"><? echo $_SESSION['dpt_name']; ?></p>

                <ul class="list-group">
                  <li class="list-group-item">
                    <b>RTP</b> <a class="float-right">1,322</a>
                  </li>
                  <li class="list-group-item">
                    <b>SO</b> <a class="float-right">543</a>
                  </li>
                  <li class="list-group-item">
                    <b>Custom Jobs</b> <a class="float-right">13,287</a>
                  </li>
                </ul>
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->

            <!-- About Me Box -->
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">About Me</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body"> 
                <? $usersql = "SELECT *, employees.id as empid FROM employees LEFT JOIN position ON position.id=employees.position_id LEFT JOIN schedules ON schedules.id=employees.schedule_id WHERE employees.id = '".$_SESSION['user_id']."'";  
                $query = $conn->query($usersql);   
                while($row = $query->fetch_array()){
                  $user_address = $row['address'];
                  $user_phone = $row['contact_info'];
                  $user_schedule = $row['time_in'].' - '.$row['time_out'];

                }        
                ?>
                <strong><i class="fas fa-map-marker-alt mr-1"></i> Adress</strong>
                <p class="text-muted"><? echo $user_address; ?></p>
                <hr>
                <strong><i class="fas fa-phone-alt mr-1"></i> Phone</strong>
                <p class="text-muted"><? echo $user_phone; ?></p>
                <hr>
                <strong><i class="fas fa-laptop mr-1"></i> Working Schedule</strong>
                <p class="text-muted"><? echo $user_schedule; ?></p>                              
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
          <div class="col-md-9">
            <div class="card">
              <div class="card-header p-2">
                <ul class="nav nav-pills">                  
                  <li class="nav-item"><a class="nav-link active" href="#activity" data-toggle="tab">Activity</a></li>
                  <?
                  if(@$_GET['order_nr'] != '') {
                  print '<li class="nav-item"><a class="nav-link" href="#timeline" data-toggle="tab">Timeline</a></li>';
                  }
                  ?>
                  
                </ul>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <div class="tab-content">
                  <!-- class="active tab-pane"  určuje čo bude prioritne zobrazené-->
                  <div class="active tab-pane" id="activity">
                    <!-- Post -->
                    <div class="post">
                      
                      <!-- /.user-block -->
                      <?
                      
                      switch ($_SESSION['dpt']) {
                        case '2':
                          $column = 'assign_g';
                          break;
                          case '6':
                            $column = 'assign_p';
                            break;
                            case '8':
                              $column = 'assign_s';
                              break;
                        
                        default:
                        $column = 'assign_g';
                          break;
                      }


                       $ordersql = "SELECT * FROM orders_$append WHERE ".$column." = '".$_SESSION['user_id']."' ORDER BY orders_$append.date ASC";  
                      $orderquery = $conn->query($ordersql);
                      print  '<table id="example3" class="table table-striped table-valign-middle"><thead>';
                  print  '<tr>';
                  print  '<th>Date</th>';
                  print  '<th>Order No.</th>';                   
                  print  '<th>Status</th>';
                  print  '<th>Customer</th>';          
                  print  '<th>Country</th>';         
                  print  '<th>Order Type</th>';
                  print  '<th>Description</th>';
                  print  '</tr>' ;
                  print  '</thead>';
                  print  '<tbody>';
                      while($orderrow = $orderquery->fetch_array()){

                    print  ' <tr>';
                    print  '<td>' . $orderrow['date'] . '</td>';
                    //print  '<td><a href="index.php?page=modeldata&scrubcocode=YUVS" title="sem klikni"><img src="dist/img/default-150x150.png" alt="Product 1" class="img-circle img-size-32 mr-2"></a>' . $orderrow['order_nr'] . '</td>';
                    print  '<td><a href="?page=profile&order_nr=' . $orderrow['order_nr'] . '"><button type="button" class="btn btn-block bg-gradient-info btn-sm">' . $orderrow['order_nr'] . '</button></a></td>';                    
                    print  '<td>' . $orderrow['status'] . '</td>';
                    print  '<td>' . $orderrow['customer'] . '</td>';
                    print  '<td>' . $orderrow['country'] . '</td>';
                    print  '<td>' . $orderrow['gfp'] . '</td>';
                    print  '<td>' . $orderrow['product_name'] . '</td>';
                    print '</div>';
                    print  ' </tr>';
                   if( $orderrow['order_nr'] == $_GET['order_nr']){
                      print  ' <tr>';
                      print  ' <td colspan="7">';
                      print '<div class="col-sm-12">';
  
                  print'</div>';
                      print  ' </td>';
                      print  ' </tr>';
                    }       
                      //print '<p>' . $orderrow['date'] . ' ' . $orderrow['gfp'] . ' ' . $orderrow['order_nr'] . ' ' . $orderrow['customer'] . ' ' . $orderrow['product_name'] . '</p>';
                      }                      
                      print'</tbody>';
                      print'</table>';
                     ?> 
                    </div>
                    <!-- /.post -->

                    <!-- Post -->
                    
                    <!-- /.post -->

                    <!-- Post -->
                    
                    <!-- /.post -->
                  </div>
                  <!-- /.tab-pane -->
                   <!-- class="active tab-pane"  určuje čo bude prioritne zobrazené-->
                  <div class="tab-pane" id="timeline">
                    <!-- The timeline -->
                    <div class="timeline timeline-inverse">
                      <!-- timeline time label -->
                      <div class="time-label">
                        <span class="bg-danger">
                          10 Feb. 2014
                        </span>
                      </div>
                      <!-- /.timeline-label -->
                     
                      <!-- timeline item -->
                      <div>
                        <i class="fas fa-envelope bg-primary"></i>

                        <div class="timeline-item">
                          <span class="time"><i class="far fa-clock"></i> 12:05</span>

                          <h3 class="timeline-header"><a href="#">Support Team</a> sent you an email</h3>

                          <div class="timeline-body">
                            Etsy doostang zoodles disqus groupon greplin oooj voxy zoodles,
                            weebly ning heekya handango imeem plugg dopplr jibjab, movity
                            jajah plickers sifteo edmodo ifttt zimbra. Babblely odeo kaboodle
                            quora plaxo ideeli hulu weebly balihoo...
                          </div>
                          <div class="timeline-footer">
                            <a href="#" class="btn btn-primary btn-sm">Read more</a>
                            <a href="#" class="btn btn-danger btn-sm">Delete</a>
                          </div>
                        </div>
                      </div>
                      <!-- END timeline item -->

                      <!-- timeline item -->
                      <div>
                        <i class="fas fa-user bg-info"></i>

                        <div class="timeline-item">
                          <span class="time"><i class="far fa-clock"></i> 5 mins ago</span>

                          <h3 class="timeline-header border-0"><a href="#">Sarah Young</a> accepted your friend request
                          </h3>
                        </div>
                      </div>
                      <!-- END timeline item -->
                      <!-- timeline item -->
                      <div>
                        <i class="fas fa-comments bg-warning"></i>

                        <div class="timeline-item">
                          <span class="time"><i class="far fa-clock"></i> 27 mins ago</span>

                          <h3 class="timeline-header"><a href="#">Jay White</a> commented on your post</h3>

                          <div class="timeline-body">
                            Take me to your leader!
                            Switzerland is small and neutral!
                            We are more like Germany, ambitious and misunderstood!
                          </div>
                          <div class="timeline-footer">
                            <a href="#" class="btn btn-warning btn-flat btn-sm">View comment</a>
                          </div>
                        </div>
                      </div>
                      <!-- END timeline item -->
                      <!-- timeline time label -->
                      <div class="time-label">
                        <span class="bg-success">
                          3 Jan. 2014
                        </span>
                      </div>
                      <!-- /.timeline-label -->
                      <!-- timeline item -->
                      <div>
                        <i class="fas fa-camera bg-purple"></i>

                        <div class="timeline-item">
                          <span class="time"><i class="far fa-clock"></i> 2 days ago</span>

                          <h3 class="timeline-header"><a href="#">Mina Lee</a> uploaded new photos</h3>

                          <div class="timeline-body">
                            <a href="#"><img src="images/stuf/150x112.png" alt="https://image-placeholder.com/images/actual-size/150x112.png"></a>
                            <a href="#"><img src="images/stuf/150x112.png" alt="https://image-placeholder.com/images/actual-size/150x112.png"></a>
                            <a href="#"><img src="images/stuf/150x112.png" alt="https://image-placeholder.com/images/actual-size/150x112.png"></a>
                            <a href="#"><img src="images/stuf/150x112.png" alt="https://image-placeholder.com/images/actual-size/150x112.png"></a>
                          </div>
                        </div>
                      </div>
                      <!-- END timeline item -->
                      <div>
                        <i class="far fa-clock bg-gray"></i>
                      </div>
                    </div>
                  </div>
                  <!-- /.tab-pane -->

                  
                  <!-- /.tab-pane -->
                </div>
                <!-- /.tab-content -->
              </div><!-- /.card-body -->
            </div>
            
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div><? include 'includes/profile_orders.php'; ?>
        <!-- /.row -->
      </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
   
