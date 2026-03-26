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
      <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=chat" class="nav-link">Scrub Chat</a>
      </li>
    </ul>
    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
          <li class="nav-item dropdown">
        <a class="nav-link" data-toggle="dropdown" href="#" id="chatNotifToggle">
          <i class="far fa-comments"></i>
          <span class="badge badge-danger navbar-badge" id="chatUnreadBadge" style="display:none;">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right" id="chatNotifMenu">
          <span class="dropdown-item dropdown-header" id="chatNotifHeader">0 nových správ</span>
          <div class="dropdown-divider"></div>

          <div id="chatNotifList">
            <span class="dropdown-item text-muted">Žiadne nové správy</span>
          </div>

          <div class="dropdown-divider"></div>
          <a href="?page=chat" class="dropdown-item dropdown-footer">Otvoriť chat</a>
        </div>
      </li>
      
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
  <script>
function escapeHtmlNav(text) {
    const div = document.createElement('div');
    div.innerText = text || '';
    return div.innerHTML;
}

function loadChatNotifications() {
    $.ajax({
        url: 'scripts/chat/get_unread_threads.php',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.status !== 'success') {
                return;
            }

            const count = parseInt(res.count || 0, 10);

            if (count > 0) {
                $('#chatUnreadBadge').text(count).show();
                $('#chatNotifHeader').text(count + ' nových správ');
            } else {
                $('#chatUnreadBadge').hide();
                $('#chatNotifHeader').text('0 nových správ');
            }

            let html = '';

            if (!res.threads || !res.threads.length) {
                html = '<span class="dropdown-item text-muted">Žiadne nové správy</span>';
            } else {
                res.threads.forEach(function(item) {
                    let photo = item.photo ? ('images/' + item.photo) : 'images/profile.jpg';
                    let text = item.message_text || '';

                    if (text.length > 55) {
                        text = text.substring(0, 55) + '...';
                    }

                    html += `
                        <a href="?page=chat&thread_id=${item.thread_id}" class="dropdown-item">
                            <div class="media">
                                <img src="${photo}" alt="User Avatar" class="img-size-50 mr-3 img-circle">
                                <div class="media-body">
                                    <h3 class="dropdown-item-title">
                                        ${escapeHtmlNav(item.name)}
                                    </h3>
                                    <p class="text-sm">${escapeHtmlNav(text)}</p>
                                    <p class="text-sm text-muted">${escapeHtmlNav(item.created_at)}</p>
                                </div>
                            </div>
                        </a>
                        <div class="dropdown-divider"></div>
                    `;
                });
            }

            $('#chatNotifList').html(html);
        }
    });
}

$(document).ready(function() {
    loadChatNotifications();
    setInterval(loadChatNotifications, 10000);
});
</script>
  <?php include 'includes/profile_modal.php'; ?>