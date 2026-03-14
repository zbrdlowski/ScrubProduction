
<?php
$currentPage = $_GET['page'] ?? '';

function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}

function isOpen(array $pages) {
    global $currentPage;
    return in_array($currentPage, $pages) ? 'menu-open active' : '';
}
?>
<aside class="main-sidebar sidebar-dark-primary elevation-4">

  <!-- Brand -->
  <a href="index.php" class="brand-link">
    <img src="dist/img/ScrubLogo.png" class="brand-image img-circle elevation-3" alt="">
    <span class="brand-text">SCRUB prod</span>
  </a>

  <div class="sidebar">

    <!-- User panel -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <img src="<?= (!empty($_SESSION['user_photo'])) ? 'images/'.$_SESSION['user_photo'] : 'images/profile.jpg' ?>" class="img-circle elevation-2">
      </div>
      <div class="info">
        <a href="?page=profile" class="d-block"><?= $_SESSION['name'] ?></a>
        <small><?= $_SESSION['dpt_name'] ?></small>
      </div>
    </div>

    <!-- MENU -->
    <ul class="sidebar-menu" data-widget="tree">

      <!-- TABLES -->
      <li class="treeview <?= isOpen(['modeldata','product_chart']) ?>">
        <a href="#">
          <i class="fa fa-table"></i> <span>Tables</span>
          <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu">
          <li class="<?= isActive('modeldata') ?>">
            <a href="?page=modeldata"><i class="fa fa-caret-right"></i> Compatibility Chart</a>
          </li>
          <li class="<?= isActive('product_chart') ?>">
            <a href="?page=product_chart"><i class="fa fa-caret-right"></i> Scrub Products Chart</a>
          </li>
        </ul>
      </li>

      <!-- ORDERS -->
      <li class="treeview <?= isOpen(['system','orders','orders_g','orders_p','orders_s','orders_f']) ?>">
        <a href="#">
          <i class="fa fa-globe"></i> <span>Orders</span>
          <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu">

          <li class="<?= isActive('system') ?>"><a href="?page=system"><i class="fa fa-caret-right"></i> Unassigned Orders</a></li>
          <li class="<?= isActive('orders') ?>"><a href="?page=orders"><i class="fa fa-caret-right"></i> Open Orders</a></li>
          <li class="<?= isActive('orders_g') ?>"><a href="?page=orders&type=g"><i class="fa fa-caret-right"></i> Graphics</a></li>
          <li class="<?= isActive('orders_p') ?>"><a href="?page=orders&type=p"><i class="fa fa-caret-right"></i> Plastics</a></li>
          <li class="<?= isActive('orders_s') ?>"><a href="?page=orders&type=s"><i class="fa fa-caret-right"></i> Seat Covers</a></li>
          <li class="<?= isActive('orders_f') ?>"><a href="?page=orders&type=f"><i class="fa fa-caret-right"></i> Fitting</a></li>

        </ul>
      </li>

      <!-- FINISHED ORDERS -->
      <li class="treeview <?= isOpen(['delivered']) ?>">
        <a href="#">
          <i class="fa fa-globe-africa"></i> <span>Finished Orders</span>
          <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu">
          <li><a href="?page=delivered&status=nok"><i class="fa fa-truck"></i> Shipped</a></li>
          <li><a href="?page=delivered&status=ok"><i class="fa fa-box-open"></i> Delivered</a></li>
        </ul>
      </li>

      <!-- STOCK MANAGEMENT -->
      <li class="treeview <?= isOpen([
        'plastics_dashboard','stock_movements','inventory_report',
        'items','add_item','upload_csv','shelves','display_stock','scan_form',
        'reset_location','relocate_item','kit_diss','plastics_orders_active',
        'receive_supply','plastics_orders_sent','plastics_orders_all','order_prepare'
      ]) ?>">
        <a href="#">
          <i class="fa fa-globe-africa" style="color:#ffc107;"></i>
          <span>Stock Management</span>
          <i class="fa fa-angle-left pull-right"></i>
        </a>

        <ul class="treeview-menu">

          <!-- Dashboards -->
          <li class="treeview <?= isOpen(['plastics_dashboard','stock_movements','inventory_report']) ?>">
            <a href="#"><i class="fa fa-chart-bar"></i> Dashboards & Reports<i class="fa fa-angle-left pull-right"></i></a>
            <ul class="treeview-menu">
              <li class="<?= isActive('plastics_dashboard') ?>"><a href="?page=plastics_dashboard"><i class="fa fa-caret-right"></i> Dashboard</a></li>
              <li class="<?= isActive('stock_movements') ?>"><a href="?page=stock_movements"><i class="fa fa-caret-right"></i> Inventory Movements</a></li>
              <li class="<?= isActive('inventory_report') ?>"><a href="?page=inventory_report"><i class="fa fa-caret-right"></i> Inventory Report</a></li>
            </ul>
          </li>

          <!-- Inventory -->
          <li class="treeview <?= isOpen(['items','add_item','upload_csv','shelves','display_stock','scan_form']) ?>">
            <a href="#"><i class="fa fa-boxes"></i> Inventory & Items<i class="fa fa-angle-left pull-right"></i></a>
            <ul class="treeview-menu">
              <li class="<?= isActive('items') ?>"><a href="?page=items"><i class="fa fa-tag"></i> All Items</a></li>
              <li class="<?= isActive('add_item') ?>"><a href="?page=add_item"><i class="fa fa-plus"></i> Create Item</a></li>
              <li class="<?= isActive('upload_csv') ?>"><a href="?page=upload_csv"><i class="fa fa-file-upload"></i> CSV Upload</a></li>
              <li class="<?= isActive('shelves') ?>"><a href="?page=shelves"><i class="fa fa-border-all"></i> Shelves</a></li>
              <li class="<?= isActive('display_stock') ?>"><a href="?page=display_stock"><i class="fa fa-stream"></i> Shelves / PN Report</a></li>
              <li class="<?= isActive('scan_form') ?>"><a href="?page=scan_form"><i class="fa fa-print"></i> Scan Form</a></li>
            </ul>
          </li>

          <!-- Stock Operations -->
          <li class="treeview <?= isOpen(['reset_location','relocate_item']) ?>">
            <a href="#"><i class="fa fa-exchange-alt"></i> Stock Operations<i class="fa fa-angle-left pull-right"></i></a>
            <ul class="treeview-menu">
              <li class="<?= isActive('reset_location') ?>"><a href="?page=reset_location"><i class="fa fa-sync-alt"></i> Location Reset</a></li>
              <li class="<?= isActive('relocate_item') ?>"><a href="?page=relocate_item"><i class="fa fa-arrows-alt-h"></i> Relocate Item</a></li>
            </ul>
          </li>

          <!-- Orders Section -->
          <li class="treeview <?= isOpen([
            'kit_diss','plastics_orders_active','receive_supply',
            'plastics_orders_sent','plastics_orders_all','order_prepare'
          ]) ?>">
            <a href="#"><i class="fa fa-list"></i> Orders Section<i class="fa fa-angle-left pull-right"></i></a>
            <ul class="treeview-menu">
              <li><a href="?page=kit_diss"><i class="fa fa-puzzle-piece"></i> Disassembled Kits</a></li>
              <li><a href="?page=plastics_orders_active"><i class="fa fa-hourglass-half"></i> Waiting Orders</a></li>
              <li><a href="?page=receive_supply"><i class="fa fa-inbox"></i> Receive Supply</a></li>
              <li><a href="?page=plastics_orders_sent"><i class="fa fa-truck"></i> Sent Orders</a></li>
              <li><a href="?page=plastics_orders_all"><i class="fa fa-clipboard-list"></i> All Orders</a></li>
              <li><a href="?page=order_prepare"><i class="fa fa-clock"></i> Order Queue</a></li>
            </ul>
          </li>

        </ul>
      </li>

      <!-- ADMIN -->
      <?php if ($_SESSION['permission'] >= 300): ?>
      <li class="treeview <?= isOpen(['employee','controlls']) ?>">
        <a href="#"><i class="fa fa-user-secret"></i> <span>Admin</span><i class="fa fa-angle-left pull-right"></i></a>
        <ul class="treeview-menu">
          <li class="<?= isActive('employee') ?>"><a href="?page=employee"><i class="fa fa-users"></i> Employees</a></li>
          <li class="<?= isActive('controlls') ?>"><a href="?page=controlls"><i class="fa fa-th"></i> Controls</a></li>
        </ul>
      </li>
      <?php endif; ?>

    </ul>

  </div>
</aside>
