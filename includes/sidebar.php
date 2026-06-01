<style>
  .main-sidebar {
  z-index: 2000 !important;
}

.main-sidebar .sidebar,
.main-sidebar .nav-sidebar,
.main-sidebar .nav-treeview {
  position: relative;
  z-index: 2010 !important;
}
</style>
<?php
$currentPage = $_GET['page'] ?? '';
function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
function isMenuOpen($pages = []) {
    global $currentPage;
    return in_array($currentPage, $pages) ? 'menu-open' : '';
}
?>
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="<?echo basename($_SERVER['PHP_SELF']);?>?page=orders_dashboard" class="brand-link">
      <img src="dist/img/ScrubLogo.png" alt="Scrub Logo" class="brand-image img-circle elevation-3" style="opacity: .8">      
    </a>

    <!-- Sidebar -->
    <div id="sidebarMenu" class="sidebar">
      
      <!-- Sidebar user (optional) -->
      <div class="user-panel mt-3 pb-3 mb-3 d-flex">
        <div class="image">
          <img src="<?php echo (!empty($_SESSION['user_photo'])) ? 'images/'.$_SESSION['user_photo'] : 'images/profile.jpg'; ?>" class="img-circle elevation-2" alt="User Image">
        </div>
        <div class="info">
          <a href="?page=profile" class="d-block"><? echo $_SESSION['name']; ?></a>
          <? echo $_SESSION['dpt_name']; ?>          
        </div>        
      </div>

      <!-- SidebarSearch Form 
      <div class="form-inline">
        <div class="input-group" data-widget="sidebar-search">
          <input class="form-control form-control-sidebar" type="search" placeholder="Search" aria-label="Search">
          <div class="input-group-append">
            <button class="btn btn-sidebar">
              <i class="fas fa-search fa-fw"></i>
            </button>
          </div>
        </div>
      </div>
      -->
      <?
      switch (basename($_SERVER['PHP_SELF'])) {
        case 'index.php':
            $path = 'index.php';
            break;
            case 'index_1.php':
                $path = 'tab';
                break;       
       
      }
      
      ?>
      <!-- Sidebar Menu -->
      <nav class="mt-2 nav-compact auto-collapse">

       <ul class="nav nav-pills nav-sidebar flex-column nav-treeview" id="menu-open-orders" data-widget="treeview" role="menu" data-accordion="false">
          <!-- Add icons to the links using the .nav-icon class
            <a href="#" class="nav-link active">
               with font-awesome or any other icon font library -->   
               <li class="nav-item <?= isMenuOpen([
           'kit_diss',
            'plastics_orders_active',
            'receive_supply',
            'plastics_orders_sent',
            'plastics_orders_all',
            'order_prepare',
            'import_orders'
          ]) ? 'menu-open' : '' ?>">
            <?
              if($_SESSION['permission'] > 300){
            ?> 
             <li class="nav-item <?= isMenuOpen([
            'employee',
            'controlls',
            'calendar',
            'import_orders'
          ]) ? 'menu-open' : '' ?>">    
          <?
           echo ' <a href="#" class="nav-link"style="background-color:#2a3036;">';
             echo '<i class="nav-icon fas fa-user-secret" ></i>';
              echo '<p>';
                echo 'Admin';
                echo '<i class="right fas fa-angle-left"></i>';
              echo '</p>';
           echo ' </a>';
            echo '<ul class="nav nav-treeview">';
          echo '<li class="nav-item">';
            echo '<a href="'.basename($_SERVER['PHP_SELF']).'?page=employee" class="nav-link  '.isActive('employee').'">';
              echo '<i class="nav-icon fas fa-users"></i>';
              echo '<p>';
                echo 'Employees';                
              echo '</p>';
            echo '</a>';
          echo '</li>';
          echo '<li class="nav-item">';
            echo '<a href="'.basename($_SERVER['PHP_SELF']).'?page=calendar" class="nav-link  '.isActive('calendar').'">';
              echo '<i class="nav-icon fas fa-calendar"></i>';
              echo '<p>';
                echo 'Attendance';                
              echo '</p>';
            echo '</a>';
          echo '</li>';
          echo '<li class="nav-item active">';
            echo '<a href="'.basename($_SERVER['PHP_SELF']).'?page=controlls" class="nav-link  '.isActive('controlls').'">';
            echo '<i class="nav-icon fas fa-th"></i>';
              //echo '<i class="nav-icon fas fa-cogs"></i>';
              echo '<p>';
                echo 'Controlls';                
              echo '</p>';
            echo '</a>';
          echo '</li>'; 
          echo '<li class="nav-item active">';
            echo '<a href="'.basename($_SERVER['PHP_SELF']).'?page=import_orders" class="nav-link  '.isActive('import_orders').'">';
            echo '<i class="fas fa-file-upload nav-icon"></i>';
              //echo '<i class="nav-icon fas fa-cogs"></i>';
              echo '<p>';
                echo 'Import Orders';                
              echo '</p>';
            echo '</a>';
          echo '</li>'; 
          echo ' </ul>';  
          echo '</li>'; 
          //echo ' </ul>';      
        }
        ?>   
<li class="nav-item">
  <a href="<?= basename($_SERVER['PHP_SELF']) ?>?page=holidays"
     class="nav-link <?= isActive('holidays') ?>">
    <i class="nav-icon far fa-calendar-check" style="color:#17a2b8;"></i>
    <p>Holidays</p>
  </a>
</li>
<li class="nav-item <?= isMenuOpen([
      'modeldata',
      'product_chart'      
    ]) ? 'menu-open' : '' ?>">
    <!-- <li class="nav-item menu-open"> zabezpečí rozbalené menu po refreshi -->
    
            <a href="#" class="nav-link" style="background-color:#2a3036;">
              <i class="nav-icon fas fa-table"></i>
              <p>
                Tables
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>            
      <ul class="nav nav-treeview">
              <li class="nav-item"><a href="<?echo basename($_SERVER['PHP_SELF']);?>?page=modeldata" class="nav-link <?= isActive('modeldata') ?>"><i class="fa fa-caret-right nav-icon"></i><p>Compatibility Chart</p></a></li>
              <li class="nav-item"><a href="<?echo basename($_SERVER['PHP_SELF']);?>?page=product_chart" class="nav-link <?= isActive('product_chart') ?>"><i class="fa fa-caret-right nav-icon"></i><p>Scrub Products Chart</p></a></li>
      </ul>
              </li>
              <li class="nav-item <?= isMenuOpen([
              'fetch_data',
              'system',
              'orders',
              'orders_g',
              'orders_p',
              'orders_s',
              'orders_f'
            ]) ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link" style="background-color:#2a3036;"><i class="nav-icon fas fa-globe-africa"  style="color:#ffc107;"></i><p>Scrub Orders<i class="right fas fa-angle-left"></i></p></a> 
      <ul class="nav nav-treeview">
              <li class="nav-item"><a href="index.php?page=orders_dashboard" class="nav-link <?= ($_GET['page'] ?? '') === 'orders_dashboard' ? 'active' : '' ?>"><i class="nav-icon fas fa-chart-line"></i>DASHBOARD</a></li>
              <li class="nav-item"><? if($path == 'tab'){echo '<a href=pages/orders.php" class="nav-link">';}else{echo '<a href="'.$path.'?page=orders&exclude_status=PENDING%2CSHIPPED" class="nav-link '. isActive('orders') .'">';}?><i class="far fa fa-caret-right nav-icon"></i><p>Open Orders</p></a></li>
      
<li class="nav-item"><a href="#" class="nav-link"style="background-color:#2a3036;"><i class="nav-icon fas fa-globe-africa" style="color:#28a745;"></i><p>Finished Orders<i class="right fas fa-angle-left"></i></p></a>
       <ul class="nav nav-treeview">
              <li class="nav-item"><a href="<?echo basename($_SERVER['PHP_SELF']);?>?page=delivered&status=nok" class="nav-link"><i class="far fa fa-truck nav-icon"></i><p>Shipped</p></a></li>
              <li class="nav-item">
                <a href="<?echo basename($_SERVER['PHP_SELF']);?>?page=delivered&status=ok" class="nav-link"><i class="far fas fa-box-open nav-icon"></i><p>Delivered</p></a></li>
        </ul>
      </li>
    </li>
 </ul>
 
<?php if ((isset($_SESSION['permission']) && intval($_SESSION['permission']) >= 500) || (isset($_SESSION['dpt']) && intval($_SESSION['dpt']) == 6)) { ?>
<li class="nav-item menu-open"><a href="#" class="nav-link" style="background-color:#2a3036;"><i class="nav-icon fas fa-globe-africa"  style="color:#ffc107;"></i><p>Stock Management<i class="right fas fa-angle-left"></i></p></a>  
  <ul class="nav nav-treeview">
    <!-- 📊 DASHBOARDS & REPORTS -->
<li class="nav-item <?= isMenuOpen([
      'plastics_dashboard',
      'stock_movements',      
      'historical_movements',
      'inventory_report',
      'display_stock'
    ]) ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link" style="background-color:#2a3036;"><i class="fas fa-chart-bar nav-icon" style="color:#ffc107;"></i><p>Dashboards & Reports <i class="right fas fa-angle-left"></i></p></a>
        <ul class="nav nav-treeview">
    <li class="nav-item">
        <a href="?page=plastics_dashboard" class="nav-link <?= isActive('plastics_dashboard') ?>">
            <i class="fa fa-calculator nav-icon"></i><p>Dashboard</p>
        </a>
    </li>

    <li class="nav-item">
        <a href="?page=stock_movements" class="nav-link <?= isActive('stock_movements') ?>">
            <i class="fas fa-exchange-alt nav-icon"></i><p>Inventory Movements</p>
        </a>
    </li>

    <li class="nav-item">
        <a href="?page=historical_movements" class="nav-link <?= isActive('historical_movements') ?>">
            <i class="fas fa-archive nav-icon"></i><p>Archived Movements</p>
        </a>
    </li>

    <li class="nav-item">
        <a href="?page=inventory_report" class="nav-link <?= isActive('inventory_report') ?>">
            <i class="fas fa-clipboard-list nav-icon"></i><p>Inventory Report</p>
        </a>
    </li>
        <li class="nav-item">
      <a href="?page=display_stock" class="nav-link <?= isActive('display_stock') ?>">
        <i class="fas fa-stream nav-icon"></i>
        <p>Shelves / PN Report</p>
      </a>
    </li>   

</ul>
</li>
<!-- 📦 INVENTORY & ITEMS -->
<li class="nav-item <?= isMenuOpen([
      'items',
      'add_item',      
      'shelves',
      'upload_items'
            
    ]) ? 'menu-open' : '' ?>">
  <a href="#" class="nav-link" style="background-color:#2a3036;">
    <i class="fas fa-boxes nav-icon" style="color:#17a2b8;"></i>
    <p>Inventory & Items <i class="right fas fa-angle-left"></i></p>
  </a>
  <ul class="nav nav-treeview">

    <li class="nav-item">
      <a href="?page=items" class="nav-link <?= isActive('items') ?>">
        <i class="fas fa-tag nav-icon"></i>
        <p>All Items (KP Gen)</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=add_item" class="nav-link <?= isActive('add_item') ?>">
        <i class="fas fa-plus nav-icon"></i>
        <p>Create New Item</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=upload_items" class="nav-link <?= isActive('upload_items') ?>">
        <i class="fas fa-file-upload nav-icon"></i>
        <p>Add / Update Item</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=shelves" class="nav-link <?= isActive('shelves') ?>">
        <i class="fas fa-border-all nav-icon"></i>
        <p>Shelves List</p>
      </a>
    </li>


  </ul>
</li>
<!-- 🔄 STOCK OPERATIONS -->
<li class="nav-item <?= isMenuOpen([
      'reset_location',
      'relocate_item',
      'bulk_scan_in',
      'bulk_scan_in_2',
      'scan_form',
      'scan_form_out',
      'upload_csv',
      'search_item'
    ]) ? 'menu-open' : '' ?>">
  <a href="#" class="nav-link" style="background-color:#2a3036;">
    <i class="fas fa-exchange-alt nav-icon" style="color:#28a745;"></i>
    <p>Stock Operations <i class="right fas fa-angle-left"></i></p>
  </a>
  <ul class="nav nav-treeview">

  <li class="nav-item">
      <a href="?page=search_item" class="nav-link <?= isActive('search_item') ?>">
        <i class="fas fa-search nav-icon"></i>
        <p>Quick Search</p>
      </a>
    </li>

   

    <li class="nav-item">
      <a href="?page=scan_form_out" class="nav-link <?= isActive('scan_form_out') ?>">
        <i class="fas fa-print nav-icon"  style="color:#d9534f;"></i>
        <p>Scan - OUT</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=bulk_scan_in" class="nav-link <?= isActive('bulk_scan_in') ?>">
        <i class="fas fa-print nav-icon"  style="color:#28a745;"></i>
        <p>Scan IN</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=reset_location" class="nav-link <?= isActive('reset_location') ?>">
        <i class="fas fa-sync-alt nav-icon"></i>
        <p>Location Reset</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=relocate_item" class="nav-link <?= isActive('relocate_item') ?>">
        <i class="fas fa-arrows-alt-h nav-icon"></i>
        <p>Relocate Item</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=bulk_scan_in_2" class="nav-link <?= isActive('bulk_scan_in_2') ?>">
        <i class="fas fa-print nav-icon" style="color:#17a2b8;"></i>
        <p>A010 Scan IN Form</p>
      </a>
    </li>

    <li class="nav-item">
      <a href="?page=upload_csv" class="nav-link <?= isActive('upload_csv') ?>">
        <i class="fas fa-file-upload nav-icon"></i>
        <p>CSV Upload</p>
      </a>
    </li>

  </ul>
</li>
<!-- 📝 ORDERS SECTION (kept untouched) -->
<li class="nav-item <?= isMenuOpen([
      'kit_diss',
      'plastics_orders_active',
      'receive_supply',
      'plastics_orders_sent',
      'plastics_orders_all',
      'order_prepare',
      'intake_print'
    ]) ? 'menu-open' : '' ?>">
  <a href="#" class="nav-link" style="background-color:#2a3036;">
    <i class="fas fa-list nav-icon" style="color:#17a2b8;"></i>
    <p>Orders Section <i class="fas fa-angle-left right"></i></p>
  </a>
  <ul class="nav nav-treeview">
    <li class="nav-item"><a href="?page=order_prepare" class="nav-link <?= isActive('order_prepare') ?>"><i class="fas fa-clock nav-icon"></i><p> Prepare Order</p></a></li>
   
    <li class="nav-item"><a href="?page=plastics_orders_active" class="nav-link <?= isActive('plastics_orders_active') ?>"><i class="fas fa-hourglass-half nav-icon"></i><p>Send Order</p></a></li>
    <li class="nav-item"><a href="?page=receive_supply" class="nav-link <?= isActive('receive_supply') ?>"><i class="fas fa-inbox nav-icon"></i><p>Receive Order</p></a></li>
    <li class="nav-item"><a href="?page=plastics_orders_sent" class="nav-link <?= isActive('plastics_orders_sent') ?>"><i class="fas fa-truck nav-icon"></i><p>Sent Orders</p></a></li>
    <li class="nav-item"><a href="?page=plastics_orders_all" class="nav-link <?= isActive('plastics_orders_all') ?>"><i class="fas fa-clipboard-list nav-icon"></i><p>All Orders</p></a></li>
    <li class="nav-item"><a href="?page=kit_diss" class="nav-link <?= isActive('kit_diss') ?>"><i class="fas fa-puzzle-piece nav-icon"></i><p>Kit Diss</p></a></li>
    <li class="nav-item"><a href="?page=intake_print" class="nav-link <?= isActive('intake_print') ?>"><i class="fas fa-print nav-icon"></i><p>Intake Print</p></a></li>
  </ul>
</li>
<!-- 🛠 MAINTENANCE -->
<li class="nav-item <?= isMenuOpen([
      'backup',
      'logs',
      'cleanup'
    ]) ? 'menu-open' : '' ?>">
  <a href="#" class="nav-link" style="background-color:#2a3036;">
    <i class="fas fa-cogs nav-icon" style="color:#6f42c1;"></i>
    <p>Maintenance <i class="fas fa-angle-left right"></i></p>
  </a>
  <ul class="nav nav-treeview">
    <li class="nav-item has-treeview">
      <a href="#" class="nav-link">
        <i class="fa fa-wrench nav-icon"></i>
        <p>System Tools <i class="fas fa-angle-left right"></i></p>
      </a>
      <ul class="nav nav-treeview">
        <li class="nav-item">
          <a href="?page=backup" class="nav-link <?= isActive('backup') ?>">
            <i class="fa fa-database nav-icon"></i>
            <p>Backup</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="?page=logs" class="nav-link <?= isActive('logs') ?>">
            <i class="fa fa-file nav-icon"></i>
            <p>Logs</p>
          </a>
        </li>
      </ul>
    </li>
    <li class="nav-item">
      <a href="?page=cleanup" class="nav-link <?= isActive('cleanup') ?>">
        <i class="fa fa-trash nav-icon"></i>
        <p>Cleanup</p>
      </a>
    </li>
  </ul>
</li>
<li class="nav-item">
  <a href="<?= basename($_SERVER['PHP_SELF']) ?>?page=projects"
     class="nav-link <?= isActive('projects') ?>">
    <i class="nav-icon fas fa-project-diagram" style="color:#ffc107;"></i>
    <p>Projects</p>
  </a>
</li>
  </ul>
<?php } ?>
      
       </li>                
      </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>
