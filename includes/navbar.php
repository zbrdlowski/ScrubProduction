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
      <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=orders&exclude_status=PENDING%2CSHIPPED" class="nav-link">Open Orders</a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="?page=projects" class="nav-link">Projects</a>
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
  <style>
    .chat-toast-container {
      position: fixed;
      top: 1rem;
      right: 1rem;
      z-index: 1080;
      width: 360px;
      max-width: calc(100vw - 2rem);
      
    }

    .chat-toast-container .toast {
      margin-bottom: .75rem;
    }

    .chat-toast-body .btn {
      border-radius: 999px;
    }

    .chat-toast-body img {
      width: 40px;
      height: 40px;
      object-fit: cover;
    }
  </style>
  <script>
let chatNotifInitialized = false;
let seenChatNotificationKeys = new Set();

function escapeHtmlNav(text) {
    const div = document.createElement('div');
    div.innerText = text || '';
    return div.innerHTML;
}

function getChatMessageLabel(count) {
    if (count === 1) {
        return 'nová správa';
    }

    if (count >= 2 && count <= 4) {
        return 'nové správy';
    }

    return 'nových správ';
}

function getChatNotificationKey(item) {
    return [
        item.thread_id || 0,
        item.created_at || '',
        item.message_text || '',
        item.name || ''
    ].join('|');
}

function isSameOpenChatThread(threadId) {
    const params = new URLSearchParams(window.location.search);
    const page = params.get('page') || '';
    const currentThreadId = params.get('thread_id') || '';

    return page === 'chat' && String(currentThreadId) === String(threadId) && document.visibilityState === 'visible';
}

function showChatToast(item) {
    if (isSameOpenChatThread(item.thread_id)) {
        return;
    }

    let container = $('#chatToastContainer');

    if (!container.length) {
        $('body').append('<div id="chatToastContainer" class="chat-toast-container"></div>');
        container = $('#chatToastContainer');
    }

    const photo = item.photo ? ('images/' + item.photo) : 'images/profile.jpg';
    const senderName = item.name || 'Nová správa';
    let text = item.message_text || 'Poslal(a) ti novú správu';

    if (text.length > 90) {
        text = text.substring(0, 90) + '...';
    }

    const toastId = 'chatToast_' + Date.now() + '_' + Math.floor(Math.random() * 100000);

    const toastHtml = `
        <div id="${toastId}" class="toast bg-maroon fade" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="false">
            <div class="toast-header bg-maroon text-white">
                <strong class="mr-auto">Nová správa</strong>
                <button type="button" class="ml-2 mb-1 close text-white" aria-label="Zavrieť">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                <div class="chat-toast-body d-flex align-items-start">
                    <img src="${escapeHtmlNav(photo)}" alt="Avatar" class="img-circle mr-2" onerror="this.src='images/profile.jpg';">
                    <div class="flex-grow-1 pr-2">
                        <div class="font-weight-bold mb-1">${escapeHtmlNav(senderName)}</div>
                        <div class="mb-2">${escapeHtmlNav(text)}</div>
                        <a href="?page=chat&thread_id=${encodeURIComponent(item.thread_id)}" class="btn btn-xs btn-light">Otvoriť chat</a>
                    </div>
                </div>
            </div>
        </div>
    `;

    container.append(toastHtml);

    const toast = $('#' + toastId);
    toast.toast({
        autohide: false,
        animation: true
    });

    toast.find('.close').on('click', function() {
        toast.toast('hide');
    });

    toast.on('hidden.bs.toast', function() {
        toast.remove();
    });

    toast.toast('show');
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

            const threads = Array.isArray(res.threads) ? res.threads : [];
            const count = parseInt(res.count || 0, 10);
            const currentKeys = new Set();

            if (count > 0) {
                $('#chatUnreadBadge').text(count).show();
                $('#chatNotifHeader').text(count + ' ' + getChatMessageLabel(count));
            } else {
                $('#chatUnreadBadge').hide();
                $('#chatNotifHeader').text('Žiadne nové správy');
            }

            let html = '';

            if (!threads.length) {
                html = '<span class="dropdown-item text-muted">Žiadne nové správy</span>';
            } else {
                threads.forEach(function(item) {
                    const key = getChatNotificationKey(item);
                    currentKeys.add(key);

                    if (chatNotifInitialized && !seenChatNotificationKeys.has(key)) {
                        showChatToast(item);
                    }

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
            seenChatNotificationKeys = currentKeys;
            chatNotifInitialized = true;
        }
    });
}

$(document).ready(function() {
    loadChatNotifications();
    setInterval(loadChatNotifications, 10000);
});
</script>
  <?php include 'includes/profile_modal.php'; ?>