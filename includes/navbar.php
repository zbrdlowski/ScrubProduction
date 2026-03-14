<nav class="main-header navbar navbar-expand navbar-dark">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=inventory_report" class="nav-link">Plastics Inventory</a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=general_items" class="nav-link">All Plastics Items</a>
      </li>
       <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=historical_movements" class="nav-link">Plastics Order Archive</a>
      </li>
      </li>
       <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=shoptet_order_download" class="nav-link">Web Order Download</a>
      </li>
    </ul>
    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">


      
      <li class="nav-item">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>

      <!--<li class="nav-item">-->
        <!--<a class="nav-link" data-widget="control-sidebar" data-slide="true" href="#" role="button">-->
          <!--<i class="fas fa-th-large"></i>-->
        <!--</a>-->
      <!--</li>-->
      <li class="dropdown user user-menu" style="padding-top:5px;">
            <a href="#" style="color:silver;" class="dropdown-toggle" data-toggle="dropdown">
              <img src="<?php echo (!empty($_SESSION['user_photo'])) ? 'images/'.$_SESSION['user_photo'] : 'images/profile.jpg'; ?>" class="user-image" alt="User Image">
              <span class="hidden-xs"><?php echo $_SESSION['name']; ?></span>
            </a>
            <ul class="dropdown-menu">
              <!-- User image -->
              <li class="user-header">
                <img src="<?php echo (!empty($_SESSION['user_photo'])) ? 'images/'.$_SESSION['user_photo'] : 'images/profile.jpg'; ?>" class="img-circle" alt="User Image">

                <p>
                  <?php echo $_SESSION['name']; ?>
                  
                </p>
              </li>
              <li class="user-footer"> 
              
                <a href="#profile" data-toggle="modal" class="btn btn-default btn-flat" id="admin_profile">Upraviť</a>               
                
                <a href="logout.php" class="btn btn-default btn-flat float-right">Odhlásiť</a>
                
              </li>
            </ul>
          </li>
    </ul>
  </nav>
  <?php include 'includes/profile_modal.php'; ?>