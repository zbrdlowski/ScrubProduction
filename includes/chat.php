<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">You must be logged in.</div>';
    return;
}
?>

<link rel="stylesheet" href="dist/css/chat.css?v=20261123-1">
<div id="chatPageRoot" class="chat-page">
    <aside class="chat-contacts" aria-label="Colleagues">
        <div class="chat-contacts-header" id="chatContactsHeader">
            <div class="chat-contacts-heading">
                <h2>Colleagues</h2>
                <a href="index.php?page=profile&amp;tab=notifications" class="chat-icon-button" title="Notification settings" aria-label="Notification settings">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                </a>
            </div>
            <div class="chat-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="chatSearch" class="form-control" placeholder="Search colleagues…" aria-label="Search colleagues">
            </div>
            <?php if ((int) ($_SESSION['permission'] ?? 0) >= 500) { ?>
                <div class="custom-control custom-switch chat-broadcast-switch">
                    <input type="checkbox" class="custom-control-input" id="chatBroadcastModeToggle">
                    <label class="custom-control-label" for="chatBroadcastModeToggle">Broadcast announcement</label>
                </div>
            <?php } ?>
        </div>
        <div id="chatContactsList" class="chat-contacts-list"></div>
        <div class="chat-contacts-footer">
            <div class="chat-status-legend" aria-label="Attendance status">
                <span><i data-status="online"></i>At work</span>
                <span><i data-status="offline"></i>Out of office</span>
                <span><i data-status="lunch"></i>Lunch</span>
                <span><i data-status="break"></i>Break</span>
            </div>
            <button type="button" id="chatComposeBroadcast" class="btn btn-primary">Write announcement <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i></button>
        </div>
    </aside>

    <section class="chat-conversation" aria-label="Conversation">
        <header class="chat-header" id="chatCardHeader">
            <button type="button" id="chatShowContacts" class="chat-icon-button" title="Back to colleagues" aria-label="Back to colleagues">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </button>
            <span id="chatHeaderAvatar" class="chat-avatar" data-status="unknown" style="display:none;">
                <img id="chatHeaderPhoto" src="images/profile.jpg" alt="">
                <i id="chatHeaderAnnouncementIcon" class="fas fa-bullhorn" aria-hidden="true" style="display:none;"></i>
            </span>
            <div class="chat-header-details">
                <h2 id="chatThreadTitle">Select a colleague</h2>
                <div id="chatHeaderMeta">Your conversations, all in one place.</div>
            </div>
        </header>

        <div id="chatMessages" class="chat-messages" role="region" aria-label="Messages" tabindex="0">
            <div class="chat-empty-state"><i class="far fa-comments" aria-hidden="true"></i><strong>Start a conversation</strong><span>Select a colleague to view your messages.</span></div>
        </div>

        <div class="chat-composer">
            <form id="chatSendForm" autocomplete="off">
                <input type="hidden" id="chatThreadId" name="thread_id" value="">
                <div id="chatAttachmentPreview" class="chat-attachment-preview" style="display:none;">
                    <i class="fas fa-file-alt" aria-hidden="true"></i>
                    <div class="chat-attachment-details">
                        <div class="font-weight-bold" id="chatAttachmentName"></div>
                        <div class="small text-muted" id="chatAttachmentSize"></div>
                    </div>
                    <button type="button" class="chat-icon-button" id="chatAttachmentRemove" aria-label="Remove attachment" title="Remove attachment"><i class="fas fa-times" aria-hidden="true"></i></button>
                </div>
                <div class="chat-input-group">
                    <button type="button" class="chat-icon-button" id="chatAttachToggle" disabled aria-label="Attach file" title="Attach file"><i class="fas fa-paperclip" aria-hidden="true"></i></button>
                    <input type="text" id="chatMessageInput" name="message_text" class="form-control" placeholder="Type a message…" aria-label="Message" disabled>
                    <button type="button" class="chat-icon-button chat-emoji-toggle" id="chatEmojiToggle" disabled aria-label="Pick an emoji" title="Emoji"><i class="far fa-smile" aria-hidden="true"></i></button>
                    <button type="submit" class="btn btn-primary" id="chatSendBtn" disabled aria-label="Send message" title="Send message"><i class="far fa-paper-plane" aria-hidden="true"></i><span>Send</span></button>
                </div>
                <div id="chatAnnouncementHtmlHint" class="small text-muted mt-2" style="display:none;">
                    HTML formatting is supported, e.g. &lt;h1&gt;Heading&lt;/h1&gt;, &lt;br /&gt;, &lt;strong&gt;bold&lt;/strong&gt;, lists and links.
                </div>
                <input type="file" id="chatAttachmentInput" style="display:none;">
                <div id="chatEmojiPicker" class="chat-emoji-picker" style="display:none;">
                    <div class="chat-emoji-picker-header">Emoji</div>
                    <div class="chat-emoji-grid" id="chatEmojiGrid"></div>
                </div>
                <audio id="chatNotificationAudio" preload="auto"><source src="sounds/notification.mp3" type="audio/mpeg"></audio>
            </form>
        </div>
        <div id="chatAttachmentDropzone" class="chat-attachment-dropzone is-disabled" role="button" tabindex="-1" aria-disabled="true">
            <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
            <strong>Drop a file to attach it</strong>
            <span>Your message will be sent when you press Send.</span>
        </div>
    </section>
</div>
<script>
    (function ($) {
        if (!$) {
            return;
        }

        const chatEventNamespace = '.darkScrubChat';
        const chatRuntime = window.darkScrubChatRuntime || {};

        if (chatRuntime.chatPollInterval) {
            clearInterval(chatRuntime.chatPollInterval);
        }

        if (chatRuntime.titleBlinkInterval) {
            clearInterval(chatRuntime.titleBlinkInterval);
        }

        if (chatRuntime.visibilityHandler) {
            document.removeEventListener('visibilitychange', chatRuntime.visibilityHandler);
        }

        if (chatRuntime.viewportHandler && window.visualViewport) {
            window.visualViewport.removeEventListener('resize', chatRuntime.viewportHandler);
        }

        if (chatRuntime.originalPageTitle) {
            document.title = chatRuntime.originalPageTitle;
        }

        $(document).off(chatEventNamespace);
        $(window).off(chatEventNamespace);

        window.darkScrubChatRuntime = chatRuntime;

    function formatDateEU(datetimeStr) {
        if (!datetimeStr) return '';

        const d = new Date(datetimeStr);
        if (isNaN(d)) return datetimeStr;

        const now = new Date();

        const isToday =
            d.getDate() === now.getDate() &&
            d.getMonth() === now.getMonth() &&
            d.getFullYear() === now.getFullYear();

        const yesterday = new Date();
        yesterday.setDate(now.getDate() - 1);

        const isYesterday =
            d.getDate() === yesterday.getDate() &&
            d.getMonth() === yesterday.getMonth() &&
            d.getFullYear() === yesterday.getFullYear();

        const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

        if (isToday) return time;
        if (isYesterday) return `Yesterday ${time}`;

        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();

        return `${day}.${month}.${year} ${time}`;
    }

    function getUrlParam(name) {
        const params = new URLSearchParams(window.location.search);
        return params.get(name);
    }

    function getUnreadMessageLabel(count) {
        count = parseInt(count || 0, 10);
        if (count === 1) return '1 unread message';
        return `${count} unread messages`;
    }
    const canSendAnnouncements = <?php echo ((int) ($_SESSION['permission'] ?? 0) >= 500 ? 'true' : 'false'); ?>;
    const currentUserId = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
    const chatNotifications = window.darkScrubChatNotifications || null;
    let broadcastModeEnabled = false;
    let selectedAnnouncementRecipients = new Set();
    let contactsCache = [];
    let lastToastSignature = chatRuntime.lastToastSignature || '';
    let lastToastAt = chatRuntime.lastToastAt || 0;
    let currentThreadId = null;
    let currentChatUserName = '';
    let currentChatUserId = null;
    let chatPollInterval = null;

    let originalPageTitle = chatRuntime.originalPageTitle || document.title;
    let titleBlinkInterval = null;
    let knownLastMessageIds = chatRuntime.knownLastMessageIds || {};
    let initializedThreadIds = chatRuntime.initializedThreadIds || {};
    let notifiedMessageIds = chatRuntime.notifiedMessageIds || {};
    let userHasInteracted = chatRuntime.userHasInteracted === true;
    let notifyAudio = null;
    let shouldAutoScrollOnNextRender = false;
    let forceScrollOnNextRender = false;
    let suppressNextIncomingSoundForThreadId = null;
    let isCheckingUpdates = false;
    let pendingCheckUpdates = false;
    let notificationPermissionRequested = chatRuntime.notificationPermissionRequested === true;
    const chatEmojiList = ['😀', '😁', '😂', '🤣', '😊', '😉', '😍', '😘', '😎', '🤩', '🙂', '🙃', '🤗', '🤔', '😴', '🤤', '🤝', '👏', '👍', '👎', '🙏', '💪', '🔥', '✨', '🎉', '❤️', '💙', '💚', '💛', '💯', '✅', '❌', '⚠️', '🚀', '📦', '📞', '💬', '😅', '😭', '😡', '🤯', '😇', '🤌', '👌', '🙌', '👀', '🎯'];

    chatRuntime.originalPageTitle = originalPageTitle;
    chatRuntime.knownLastMessageIds = knownLastMessageIds;
    chatRuntime.initializedThreadIds = initializedThreadIds;
    chatRuntime.notifiedMessageIds = notifiedMessageIds;

    function isConversationVisible() {
        return !window.matchMedia('(max-width: 767.98px)').matches || $('#chatPageRoot').hasClass('conversation-open');
    }

    function showConversation() {
        $('#chatPageRoot').addClass('conversation-open');
        requestAnimationFrame(scrollChatToBottom);
    }

    function syncChatHeight() {
        const root = document.getElementById('chatPageRoot');
        if (!root) return;
        const viewport = window.visualViewport;
        const bottom = viewport ? viewport.height + viewport.offsetTop : window.innerHeight;
        const footer = document.querySelector('.main-footer');
        const footerHeight = footer && getComputedStyle(footer).position === 'fixed' ? footer.getBoundingClientRect().height : 0;
        root.style.setProperty('--chat-height', Math.max(240, Math.floor(bottom - root.getBoundingClientRect().top - footerHeight - 12)) + 'px');
    }

    function chatEmptyState(title, description, icon = 'fa-comments') {
        return `<div class="chat-empty-state"><i class="far ${escapeAttr(icon)}" aria-hidden="true"></i><strong>${escapeHtml(title)}</strong><span>${escapeHtml(description)}</span></div>`;
    }

    function contactStatus(user) {
        const states = { 'bg-success': 'online', 'bg-danger': 'offline', 'bg-warning': 'break', 'bg-info': 'lunch' };
        return states[user.status_bg] || 'unknown';
    }

    function contactIsInactive(user) {
        return user.is_active === false || user.is_active === 0 || String(user.active || '').toLowerCase() === 'inactive';
    }

    function contactTime(datetime) {
        if (!datetime) return '';
        const date = new Date(String(datetime).replace(' ', 'T'));
        if (isNaN(date)) return '';
        const today = new Date();
        if (date.toDateString() === today.toDateString()) {
            return date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
        }
        today.setDate(today.getDate() - 1);
        if (date.toDateString() === today.toDateString()) return 'Yesterday';
        return date.toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric' });
    }

    function isChatScrolledNearBottom() {
        const el = $('#chatMessages')[0];
        if (!el) return true;
        const threshold = 80;
        return (el.scrollHeight - el.scrollTop - el.clientHeight) <= threshold;
    }

    function scrollChatToBottom() {
        const el = $('#chatMessages')[0];
        if (!el) return;
        el.scrollTop = el.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text || '';
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function buildPhotoPath(photoValue) {
        let photo = 'images/profile.jpg';

        if (photoValue && String(photoValue).trim() !== '') {
            if (String(photoValue).includes('/')) {
                photo = photoValue;
            } else {
                photo = 'images/' + photoValue;
            }
        }

        return photo;
    }

    function setAttachmentDropzoneEnabled(enabled) {
        const isEnabled = !!enabled;
        const dropzone = $('#chatAttachmentDropzone');

        if (!dropzone.length) return;

        dropzone
            .toggleClass('is-disabled', !isEnabled)
            .attr('aria-disabled', isEnabled ? 'false' : 'true')
            .attr('tabindex', isEnabled ? '0' : '-1');

        if (!isEnabled) {
            dropzone.removeClass('drag-over');
        }
    }

    function setComposerEnabled(enabled) {
        const isEnabled = !!enabled;
        const canAttach = isEnabled && !broadcastModeEnabled;

        $('#chatMessageInput').prop('disabled', !isEnabled);
        $('#chatSendBtn').prop('disabled', !isEnabled);
        $('#chatEmojiToggle').prop('disabled', !isEnabled);

        if ($('#chatAttachToggle').length) {
            $('#chatAttachToggle').prop('disabled', !canAttach);
        }

        setAttachmentDropzoneEnabled(canAttach);
    }

    function getSelectedDepartmentValues() {
        return ($('#chatAnnouncementDepartments').val() || []).filter(Boolean);
    }

    function refreshBroadcastSummary() {
        const count = selectedAnnouncementRecipients.size;
        $('#chatBroadcastSummary').text(
            count > 0 ? ('Selected recipients: ' + count) : 'No recipients selected'
        );
        $('#chatComposeBroadcast').prop('disabled', count === 0);
    }

    function collectDepartmentsFromContacts(contacts) {
        const seen = new Set();
        const departments = [];

        (contacts || []).forEach(function (item) {
            if ((item.item_type || 'user') !== 'user') return;
            const dept = String(item.department_name || '').trim();
            if (!dept || seen.has(dept)) return;
            seen.add(dept);
            departments.push(dept);
        });

        departments.sort(function (a, b) {
            return a.localeCompare(b, 'sk', { sensitivity: 'base' });
        });

        return departments;
    }

    function renderAnnouncementToolbar(contacts) {
        if (!canSendAnnouncements) return;

        let toolbar = $('#chatBroadcastToolbar');

        if (!toolbar.length) {
            const toolbarHtml = `
            <div id="chatBroadcastToolbar" class="chat-broadcast-toolbar">          
                <div id="chatBroadcastControls" style="display:none;">
                    <label for="chatAnnouncementDepartments" class="small mb-1">Departments</label>
                    <select id="chatAnnouncementDepartments" class="form-control form-control-sm" multiple></select>
                    <div class="d-flex mt-2">
                    <button type="button" class="btn flex-fill" id="chatBroadcastSelectAll">
                        Select all
                    </button>
                    <button type="button" class="btn flex-fill" id="chatBroadcastClearAll">
                        Clear selection
                    </button>
                </div>
                    <div id="chatBroadcastSummary" class="chat-broadcast-summary">No recipients selected</div>
                </div>
            </div>
        `;
            $('#chatContactsHeader').append(toolbarHtml);
            toolbar = $('#chatBroadcastToolbar');
        }

        const departments = collectDepartmentsFromContacts(contacts);
        const select = $('#chatAnnouncementDepartments');
        const previousValues = new Set(getSelectedDepartmentValues());

        select.html(
            departments.map(function (dept) {
                const selected = previousValues.has(dept) ? 'selected' : '';
                return `<option value="${escapeAttr(dept)}" ${selected}>${escapeHtml(dept)}</option>`;
            }).join('')
        );

        $('#chatBroadcastModeToggle').prop('checked', broadcastModeEnabled);
        $('#chatBroadcastControls').toggle(broadcastModeEnabled);
        refreshBroadcastSummary();
    }

    function applyDepartmentSelection() {
        const selectedDepts = new Set(getSelectedDepartmentValues());
        selectedAnnouncementRecipients = new Set();

        contactsCache.forEach(function (item) {
            if ((item.item_type || 'user') !== 'user') return;
            const dept = String(item.department_name || '').trim();
            if (selectedDepts.has(dept)) {
                selectedAnnouncementRecipients.add(parseInt(item.id, 10));
            }
        });

        refreshBroadcastSummary();
        loadContacts($('#chatSearch').val());
    }

    function toggleBroadcastMode(enabled) {
        if (!canSendAnnouncements) return;

        broadcastModeEnabled = !!enabled;
        $('#chatPageRoot').toggleClass('broadcast-mode', broadcastModeEnabled).removeClass('conversation-open');
        $('#chatBroadcastControls').toggle(broadcastModeEnabled);
        $('#chatAnnouncementHtmlHint').toggle(broadcastModeEnabled);

        selectedAnnouncementRecipients = new Set();

        if (broadcastModeEnabled) {
            currentThreadId = null;
            currentChatUserId = null;
            currentChatUserName = 'Broadcast messages';
            $('#chatThreadId').val('');
            $('#chatMessages').html(chatEmptyState('Broadcast announcement', 'Select recipients from your colleagues, then write your announcement.'));
            renderChatHeader({
                name: 'Broadcast messages',
                photo: '',
                status_bg: 'bg-maroon',
                hide_photo: true,
                thread_type: 'announcement'
            });
            setComposerEnabled(true);
            resetAttachmentPreview();
        } else {
            $('#chatAnnouncementDepartments').val([]);
            $('#chatMessageInput').val('');
            $('#chatMessages').html(chatEmptyState('Start a conversation', 'Select a colleague to view your messages.'));
            resetAttachmentPreview();
            setComposerEnabled(false);
            renderChatHeader(null);
        }

        refreshBroadcastSummary();
        loadContacts($('#chatSearch').val());
    }

    function toggleAnnouncementRecipient(userId) {
        const parsedId = parseInt(userId, 10);
        if (!parsedId || parsedId <= 0) return;

        if (selectedAnnouncementRecipients.has(parsedId)) {
            selectedAnnouncementRecipients.delete(parsedId);
        } else {
            selectedAnnouncementRecipients.add(parsedId);
        }

        refreshBroadcastSummary();
        loadContacts($('#chatSearch').val());
    }

    function sendAnnouncement() {
        const messageText = ($('#chatMessageInput').val() || '').trim();
        const recipientIds = Array.from(selectedAnnouncementRecipients);

        if (recipientIds.length === 0) {
            alert('Select at least one recipient.');
            return;
        }

        if (!messageText) {
            alert('Type the broadcast message.');
            return;
        }

        if (!confirm('Are you sure you want to send a broadcast message to ' + recipientIds.length + ' users?')) {
            return;
        }

        $.ajax({
            url: 'scripts/chat/send_announcement.php',
            method: 'POST',
            dataType: 'json',
            data: {
                message_text: messageText,
                'recipient_ids[]': recipientIds
            },
            success: function (res) {
                if (!res || res.status !== 'success') {
                    alert((res && res.message) ? res.message : 'Failed to send the broadcast message');
                    return;
                }

                $('#chatMessageInput').val('');
                $('#chatAnnouncementDepartments').val([]);
                selectedAnnouncementRecipients = new Set();
                refreshBroadcastSummary();
                toggleBroadcastMode(false);
                loadContacts($('#chatSearch').val());
            },
            error: function (xhr) {
                console.log('sendAnnouncement error:', xhr.responseText);
                alert('Error while sending the broadcast message: ' + (xhr.responseText || 'Unknown error'));
            }
        });
    }

    function openAnnouncementThread(threadId) {
        const parsedThreadId = parseInt(threadId, 10);
        if (!parsedThreadId) return;
        showConversation();

        currentThreadId = parsedThreadId;
        currentChatUserId = -parsedThreadId;
        currentChatUserName = 'Broadcast messages';

        $('#chatThreadId').val(parsedThreadId);
        renderChatHeader({
            name: 'Broadcast messages',
            photo: '',
            status_bg: 'bg-maroon',
            hide_photo: true,
            thread_type: 'announcement'
        });

        $('#chatMessages').html('<div class="text-muted">Loading broadcast message...</div>');
        setComposerEnabled(false);
        loadThreadInfo(parsedThreadId);
        loadMessages(parsedThreadId);

        const newUrl = `${window.location.pathname}?page=chat&thread_id=${parsedThreadId}`;
        window.history.replaceState({}, '', newUrl);
    }

    function sortContactsForList(contacts) {
        return (contacts || []).slice().sort(function (a, b) {
            const typeA = a.thread_type || 'dm';
            const typeB = b.thread_type || 'dm';

            if (typeA === 'announcement' && typeB !== 'announcement') return -1;
            if (typeB === 'announcement' && typeA !== 'announcement') return 1;

            const firstA = String(a.firstname || '').trim() || String(a.name || '').trim().split(/\s+/)[0] || '';
            const firstB = String(b.firstname || '').trim() || String(b.name || '').trim().split(/\s+/)[0] || '';
            const firstCompare = firstA.localeCompare(firstB, 'sk', { sensitivity: 'base' });

            if (firstCompare !== 0) return firstCompare;

            const lastCompare = String(a.lastname || '').localeCompare(String(b.lastname || ''), 'sk', { sensitivity: 'base' });
            if (lastCompare !== 0) return lastCompare;

            return String(a.name || '').localeCompare(String(b.name || ''), 'sk', { sensitivity: 'base' });
        });
    }

    function renderChatHeader(user) {
        const avatar = $('#chatHeaderAvatar');
        if (!user) {
            avatar.hide();
            $('#chatThreadTitle').text('Select a colleague');
            $('#chatHeaderMeta').text('Your conversations, all in one place.');
            return;
        }
        const announcement = user.hide_photo === true || user.thread_type === 'announcement' || user.status_bg === 'bg-maroon';
        const inactive = contactIsInactive(user);
        avatar.attr('data-status', contactStatus(user)).toggleClass('is-inactive', inactive).show();
        $('#chatHeaderPhoto').toggle(!announcement);
        $('#chatHeaderAnnouncementIcon').toggle(announcement);
        if (!announcement) {
            $('#chatHeaderPhoto').attr('src', buildPhotoPath(user.photo)).off('error').one('error', function () {
                $(this).attr('src', 'images/profile.jpg');
            });
        }
        $('#chatThreadTitle').text(user.name || 'Conversation');
        const status = user.status_label === 'At home' ? 'Out of office' : (user.status_label || 'Status unknown');
        $('#chatHeaderMeta').text(announcement
            ? (broadcastModeEnabled ? 'Choose recipients and send an announcement' : 'Broadcast announcement · Read only')
            : [status, user.department_name, inactive ? 'Inactive account' : ''].filter(Boolean).join(' · '));
        avatar.attr('title', announcement ? 'Announcement' : status);
    }

    function setActiveContact(userId) {
        $('.chat-contact').removeClass('active').attr('aria-current', 'false');
        $('.chat-contact[data-user-id="' + userId + '"]').addClass('active').attr('aria-current', 'true');
    }

    function populateHeaderFromContactByThread(threadId) {
        const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');

        if (!contact.length) return false;

        const userId = parseInt(contact.attr('data-user-id') || 0, 10);
        const userName = contact.attr('data-user-name') || 'Conversation';
        const userPhoto = contact.attr('data-user-photo') || '';

        currentChatUserId = userId;
        currentChatUserName = userName;

        renderChatHeader({
            id: userId,
            name: userName,
            photo: userPhoto,
            department_name: contact.attr('data-department-name') || '',
            status_label: contact.attr('data-status-label') || '',
            status_icon: contact.attr('data-status-icon') || '',
            status_bg: contact.attr('data-status-bg') || 'bg-dark',
            is_active: contact.attr('data-is-active') !== 'false',
            thread_type: contact.attr('data-thread-type') || 'dm'
        });

        setActiveContact(userId);
        return true;
    }

    function normalizeToastText(value, fallback = '') {
        if (value === null || value === undefined) return fallback;
        if (typeof value === 'string') return value;
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);

        if (typeof value === 'object') {
            if (typeof value.name === 'string' && value.name.trim() !== '') return value.name;
            if (typeof value.sender_name === 'string' && value.sender_name.trim() !== '') return value.sender_name;
            if (typeof value.message === 'string' && value.message.trim() !== '') return value.message;
        }

        return fallback;
    }

    function pruneNotifiedMessageIds(nowTs) {
        const keys = Object.keys(notifiedMessageIds);

        if (keys.length <= 80) {
            return;
        }

        keys.forEach(function (key) {
            if ((nowTs - notifiedMessageIds[key]) > 60 * 60 * 1000) {
                delete notifiedMessageIds[key];
            }
        });
    }

    function rememberNotifiedMessage(threadId, messageId, nowTs) {
        if (!threadId || !messageId) {
            return true;
        }

        const key = threadId + ':' + messageId;
        if (notifiedMessageIds[key]) {
            return false;
        }

        notifiedMessageIds[key] = nowTs;
        chatRuntime.notifiedMessageIds = notifiedMessageIds;
        pruneNotifiedMessageIds(nowTs);
        return true;
    }

    function showChatToast(payload) {
        const data = (payload && typeof payload === 'object') ? payload : { message: payload };
        const threadId = parseInt(data.thread_id || 0, 10);
        const messageId = parseInt(data.last_message_id || data.message_id || 0, 10);
        if (chatNotifications && !chatNotifications.canShow(threadId, messageId)) return false;
        const senderName = normalizeToastText(data.sender_name || data.name, 'New message');
        const contact = threadId ? $('.chat-contact[data-thread-id="' + threadId + '"]') : $();
        const threadType = contact.attr('data-thread-type') || data.thread_type || 'dm';
        const rawPreview = chatMessagePreview(data, threadType);
        const preview = rawPreview && rawPreview.trim() !== '' ? rawPreview.trim() : 'You have a new message';
        const shortPreview = preview.length > 140 ? preview.substring(0, 140) + '...' : preview;

        const signature = [threadId, messageId, senderName, shortPreview].join('|');
        const nowTs = Date.now();
        if (!rememberNotifiedMessage(threadId, messageId, nowTs)) {
            return false;
        }

        if (signature === lastToastSignature && (nowTs - lastToastAt) < 4000) {
            return false;
        }
        lastToastSignature = signature;
        lastToastAt = nowTs;
        chatRuntime.lastToastSignature = lastToastSignature;
        chatRuntime.lastToastAt = lastToastAt;

        const titleText = threadType === 'announcement' ? 'New announcement' : 'New message';

        let avatar = 'images/profile.jpg';
        if (threadType === 'announcement') {
            if (data.sender_photo && String(data.sender_photo).trim() !== '') {
                avatar = buildPhotoPath(data.sender_photo);
            } else if (data.sender_id) {
                const senderContact = $('.chat-contact[data-user-id="' + parseInt(data.sender_id, 10) + '"]');
                const senderPhoto = senderContact.attr('data-user-photo') || '';
                if (senderPhoto) {
                    avatar = buildPhotoPath(senderPhoto);
                }
            }
        } else if (contact.length) {
            const contactPhoto = contact.attr('data-user-photo') || '';
            if (contactPhoto) {
                avatar = buildPhotoPath(contactPhoto);
            }
        }

        let container = $('#chatToastContainer');
        if (!container.length) {
            $('body').append('<div id="chatToastContainer" class="chat-toast-container"></div>');
            container = $('#chatToastContainer');
        }

        const toastId = 'chatToast_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
        const threadUrl = '?page=chat&thread_id=' + encodeURIComponent(threadId || '');

        const toast = $(`
        <div class="chat-toast" id="${toastId}">
            <div class="chat-toast-header">
                <div class="chat-toast-title">
                    <i class="fas ${threadType === 'announcement' ? 'fa-bullhorn' : 'fa-comments'}"></i>
                    <span>${escapeHtml(titleText)}</span>
                </div>
                <button type="button" class="chat-toast-close" aria-label="Close">&times;</button>
            </div>
            <div class="chat-toast-body">
                <div class="chat-toast-main">
                    <img src="${escapeAttr(avatar)}" alt="" class="chat-toast-avatar" onerror="this.src='images/profile.jpg';">
                    <div class="chat-toast-content">
                        <div class="chat-toast-sender">${escapeHtml(senderName)}</div>
                        <div class="chat-toast-preview">${escapeHtml(shortPreview)}</div>
                        <div class="chat-toast-actions">
                            <a href="${escapeAttr(threadUrl)}" class="chat-toast-link">
                                <i class="fas fa-external-link-alt"></i>
                                Open
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `);

        container.append(toast);

        setTimeout(function () {
            toast.addClass('show');
        }, 10);

        const closeToast = function () {
            toast.removeClass('show');
            setTimeout(function () { toast.remove(); }, 250);
        };
        toast.find('.chat-toast-close, .chat-toast-link').on('click', function () {
            if (chatNotifications) chatNotifications.dismiss(threadId, messageId);
            else closeToast();
        });
        if (chatNotifications) chatNotifications.registerToast(threadId, messageId, closeToast);

        return true;
    }

    function ensureNotificationAudio() {
        if (!notifyAudio) {
            notifyAudio = document.getElementById('chatNotificationAudio');

            if (!notifyAudio) {
                notifyAudio = new Audio('sounds/notification.mp3');
                notifyAudio.preload = 'auto';
            }
        }
    }

    function playNotificationSound(threadId, messageId) {
        if (chatNotifications) {
            chatNotifications.playSound(threadId, messageId);
            return;
        }
        if (!userHasInteracted) return;
        ensureNotificationAudio();
        notifyAudio.currentTime = 0;
        notifyAudio.play().catch(function () { });
    }

    function supportsBrowserNotifications() {
        return 'Notification' in window && (
            window.isSecureContext ||
            Notification.permission === 'granted' ||
            /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/i.test(window.location.origin)
        );
    }

    function requestBrowserNotificationPermission() {
        if ((chatNotifications && chatNotifications.getPreferences().toast_mode === 'badge')
            || !supportsBrowserNotifications() || notificationPermissionRequested || Notification.permission !== 'default') {
            return;
        }

        notificationPermissionRequested = true;
        chatRuntime.notificationPermissionRequested = true;

        try {
            const permissionRequest = Notification.requestPermission();
            if (permissionRequest && typeof permissionRequest.catch === 'function') {
                permissionRequest.catch(function () { });
            }
        } catch (e) {
            Notification.requestPermission(function () { });
        }
    }

    function showBrowserNotification(thread, titleText, bodyText, iconPath, threadUrl) {
        if (!supportsBrowserNotifications() || Notification.permission !== 'granted') {
            return;
        }

        const threadId = parseInt((thread && thread.thread_id) || 0, 10);
        const messageId = parseInt((thread && (thread.last_message_id || thread.message_id)) || 0, 10);
        if (chatNotifications) {
            chatNotifications.showBrowserNotification(threadId, messageId, titleText, bodyText, iconPath, threadUrl);
            return;
        }
        const tag = threadId && messageId ? ('chat-' + threadId + '-' + messageId) : ('chat-' + Date.now());

        try {
            const notification = new Notification(titleText, {
                body: bodyText,
                icon: iconPath || 'images/profile.jpg',
                tag: tag,
                requireInteraction: true
            });

            notification.onclick = function () {
                window.focus();
                if (threadUrl) {
                    window.location.href = threadUrl;
                }
                notification.close();
            };

        } catch (e) {
            // Browser notifications are best-effort only.
        }
    }

    function startTitleBlink(text) {
        if (titleBlinkInterval) return;

        let toggle = false;
        titleBlinkInterval = setInterval(function () {
            document.title = toggle ? text : originalPageTitle;
            toggle = !toggle;
        }, 1000);
        chatRuntime.titleBlinkInterval = titleBlinkInterval;
    }

    function stopTitleBlink() {
        if (titleBlinkInterval) {
            clearInterval(titleBlinkInterval);
            titleBlinkInterval = null;
        }
        chatRuntime.titleBlinkInterval = null;
        document.title = originalPageTitle;
    }

    function triggerIncomingNotification(thread) {
        const data = thread || {};
        const safeName = normalizeToastText(data.sender_name, 'a colleague');
        const threadId = parseInt(data.thread_id || 0, 10);
        const contact = threadId ? $('.chat-contact[data-thread-id="' + threadId + '"]') : $();
        const threadType = contact.attr('data-thread-type') || data.thread_type || 'dm';
        const titleText = threadType === 'announcement' ? 'New announcement' : ('New message from ' + safeName);
        const rawPreview = chatMessagePreview(data, threadType);
        const preview = rawPreview && rawPreview.trim() !== '' ? rawPreview.trim() : 'You have a new message';
        const shortPreview = preview.length > 140 ? preview.substring(0, 140) + '...' : preview;
        const iconPath = data.sender_photo ? buildPhotoPath(data.sender_photo) : 'images/profile.jpg';
        const threadUrl = '?page=chat&thread_id=' + encodeURIComponent(threadId || '');

        const messageId = parseInt(data.last_message_id || data.message_id || 0, 10);
        if (chatNotifications && chatNotifications.handled(threadId, messageId)) return;
        if (chatNotifications && chatNotifications.getPreferences().toast_mode === 'badge') {
            playNotificationSound(threadId, messageId);
            return;
        }
        if (!showChatToast(data)) return;

        playNotificationSound(threadId, messageId);
        showBrowserNotification(data, titleText, shortPreview, iconPath, threadUrl);

        if (document.hidden || parseInt(currentThreadId || 0, 10) !== threadId) {
            startTitleBlink('💬 New message from ' + safeName);
        }
    }

    function updateContactUnreadState(contact, unreadCount) {
        const count = parseInt(unreadCount || 0, 10);
        if (!contact || !contact.length) return;

        contact.find('.chat-unread-badge').remove();
        contact.find('.chat-contact-meta').text(contact.attr('data-default-meta') || '');

        if (count > 0) {
            contact.addClass('unread');
            contact.find('.chat-contact-bottom').append(`<span class="chat-unread-badge" aria-label="${getUnreadMessageLabel(count)}">${count}</span>`);
        } else {
            contact.removeClass('unread');
        }
    }

    function markContactUnread(threadId, unreadCount) {
        const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');
        updateContactUnreadState(contact, unreadCount);
    }

    function clearContactUnread(threadId) {
        if (!isConversationVisible() || document.hidden || !document.hasFocus()) return;
        const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');
        updateContactUnreadState(contact, 0);
    }

    function renderEmojiPicker() {
        const grid = $('#chatEmojiGrid');
        if (!grid.length || grid.children().length) return;

        let html = '';
        chatEmojiList.forEach(function (emoji) {
            html += `<button type="button" class="chat-emoji-btn" data-emoji="${emoji}" title="${emoji}">${emoji}</button>`;
        });

        grid.html(html);
    }

    function toggleEmojiPicker(forceOpen = null) {
        const picker = $('#chatEmojiPicker');
        if (!picker.length) return;

        const shouldOpen = forceOpen === null ? !picker.is(':visible') : !!forceOpen;
        if (shouldOpen) {
            renderEmojiPicker();
            picker.stop(true, true).fadeIn(120);
        } else {
            picker.stop(true, true).fadeOut(120);
        }
    }

    function insertEmojiToMessage(emoji) {
        const input = document.getElementById('chatMessageInput');
        if (!input || input.disabled) return;

        const value = input.value || '';
        const start = typeof input.selectionStart === 'number' ? input.selectionStart : value.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : value.length;
        const newValue = value.slice(0, start) + emoji + value.slice(end);

        input.value = newValue;
        input.focus();

        const caretPos = start + emoji.length;
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(caretPos, caretPos);
        }

        $(input).trigger('input');
    }

    let selectedAttachmentFile = null;

    function formatFileSize(bytes) {
        const size = parseInt(bytes || 0, 10);
        if (size < 1024) return size + ' B';
        if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB';
        return (size / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function resetAttachmentPreview() {
        selectedAttachmentFile = null;
        $('#chatAttachmentInput').val('');
        $('#chatAttachmentName').text('');
        $('#chatAttachmentSize').text('');
        $('#chatAttachmentPreview').hide();
    }

    function setAttachmentPreview(file) {
        selectedAttachmentFile = file || null;

        if (!selectedAttachmentFile) {
            resetAttachmentPreview();
            return;
        }

        $('#chatAttachmentName').text(selectedAttachmentFile.name || 'Attachment');
        $('#chatAttachmentSize').text(formatFileSize(selectedAttachmentFile.size || 0));
        $('#chatAttachmentPreview').show();
    }

    function handleAttachmentFiles(files) {
        const file = files && files.length ? files[0] : null;

        if (!file || $('#chatAttachmentDropzone').hasClass('is-disabled')) {
            return;
        }

        setAttachmentPreview(file);
        toggleEmojiPicker(false);
    }

    function sendAttachmentMessage() {
        const threadId = $('#chatThreadId').val();
        const messageText = $('#chatMessageInput').val().trim();

        if (!threadId || !selectedAttachmentFile) {
            return;
        }

        const formData = new FormData();
        formData.append('thread_id', threadId);
        formData.append('message_text', messageText);
        formData.append('attachment', selectedAttachmentFile);

        $.ajax({
            url: 'scripts/chat/upload_attachment.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (!res || res.status !== 'success') {
                    alert((res && res.message) ? res.message : 'Failed to send the attachment');
                    return;
                }

                $('#chatMessageInput').val('');
                resetAttachmentPreview();
                toggleEmojiPicker(false);
                forceScrollOnNextRender = true;
                loadMessages(threadId);
                loadContacts($('#chatSearch').val());
            },
            error: function (xhr) {
                console.log('sendAttachmentMessage error:', xhr.responseText);
                alert('Error while sending the attachment');
            }
        });
    }

    function loadContacts(query = '') {
        $.ajax({
            url: 'scripts/chat/get_contacts.php', method: 'GET', dataType: 'json', data: { q: query },
            success: function (res) {
                if (!res || res.status !== 'success') return;
                // Ignore an older search response arriving after a newer query.
                if (query !== ($('#chatSearch').val() || '')) return;
                contactsCache = res.contacts || [];
                renderAnnouncementToolbar(contactsCache);
                const contacts = sortContactsForList(contactsCache);
                const html = contacts.map(function (user) {
                    const threadType = user.thread_type || 'dm';
                    const announcement = threadType === 'announcement';
                    const checked = selectedAnnouncementRecipients.has(parseInt(user.id, 10));
                    const inactive = contactIsInactive(user);
                    const active = parseInt(currentChatUserId, 10) === parseInt(user.id, 10);
                    const unread = parseInt(user.unread_count || 0, 10);
                    const dept = user.department_name || '';
                    const status = user.status_label === 'At home' ? 'Out of office' : (user.status_label || 'Status unknown');
                    const preview = chatMessagePreview(user, threadType).replace(/\s+/g, ' ').trim()
                        || (user.last_message_type === 'attachment' ? 'Attachment' : '')
                        || (announcement ? 'Announcements' : dept || status);
                    const avatar = announcement ? '<i class="fas fa-bullhorn" aria-hidden="true"></i>'
                        : `<img src="${escapeAttr(buildPhotoPath(user.photo))}" alt="" onerror="this.onerror=null;this.src='images/profile.jpg';">`;
                    return `
                    <div class="chat-contact ${active ? 'active' : ''} ${unread > 0 ? 'unread' : ''} ${inactive ? 'is-inactive' : ''}"
                        role="button" tabindex="0" aria-current="${active ? 'true' : 'false'}"
                        aria-label="${escapeAttr(user.name + '. ' + status + (inactive ? '. Inactive account' : ''))}"
                        data-user-id="${parseInt(user.id, 10)}" data-user-name="${escapeAttr(user.name)}"
                        data-user-photo="${escapeAttr(user.photo || '')}" data-thread-id="${parseInt(user.thread_id || 0, 10)}"
                        data-thread-type="${escapeAttr(threadType)}" data-status-bg="${escapeAttr(user.status_bg || 'bg-secondary')}"
                        data-status-label="${escapeAttr(status)}" data-department-name="${escapeAttr(dept)}"
                        data-is-active="${!inactive}" data-default-meta="${escapeAttr(preview)}">
                        <span class="chat-recipient-check">${!announcement ? `<input type="checkbox" class="chat-recipient-checkbox" tabindex="-1" aria-label="Select ${escapeAttr(user.name)}" ${checked ? 'checked' : ''}>` : ''}</span>
                        <span class="chat-avatar ${inactive ? 'is-inactive' : ''}" data-status="${contactStatus(user)}" title="${escapeAttr(status + (inactive ? ' · Inactive account' : ''))}">${avatar}</span>
                        <div class="chat-contact-details">
                            <div class="chat-contact-top"><span class="chat-contact-name">${escapeHtml(user.name)}</span><time class="chat-contact-time" title="${escapeAttr(formatDateEU(user.last_message_at))}">${escapeHtml(contactTime(user.last_message_at))}</time></div>
                            <div class="chat-contact-bottom"><span class="chat-contact-meta">${escapeHtml(preview)}</span>${unread > 0 ? `<span class="chat-unread-badge" aria-label="${getUnreadMessageLabel(unread)}">${unread}</span>` : ''}</div>
                        </div>
                    </div>`;
                }).join('');
                const list = $('#chatContactsList');
                const scrollTop = list.scrollTop();
                const focusedContact = list.find('.chat-contact').filter(function () { return this.contains(document.activeElement); }).attr('data-user-id');
                list.html(html || '<div class="text-muted p-3">No colleagues found.</div>');
                if (focusedContact) list.find('.chat-contact[data-user-id="' + focusedContact + '"]').trigger('focus');
                list.scrollTop(scrollTop);
                if (currentThreadId && !broadcastModeEnabled) populateHeaderFromContactByThread(currentThreadId);
                if (currentChatUserId) setActiveContact(currentChatUserId);
            },
            error: function (xhr) { console.log('loadContacts error:', xhr.responseText); }
        });
    }

    function renderMessages(messages) {
        const chatBox = $('#chatMessages');
        const chatEl = chatBox[0];
        const wasNearBottom = isChatScrolledNearBottom();
        const previousScrollTop = chatEl ? chatEl.scrollTop : 0;

        if (!messages || !messages.length) {
            chatBox.html(chatEmptyState('No messages yet', 'Send a message to start the conversation.'));
            return;
        }

        let html = '';
        let lastMessageId = 0;

        messages.forEach(function (msg) {
            lastMessageId = msg.id;

            let attachmentHtml = '';

            if (msg.attachment && msg.attachment.id) {
                attachmentHtml = `
            <div class="chat-attachment-card">
                <div class="font-weight-bold mb-1">
                    <i class="fas fa-paperclip mr-1"></i>
                    ${escapeHtml(msg.attachment.original_name || 'Attachment')}
                </div>
                <div class="small mb-2">
                    ${escapeHtml(msg.attachment.extension || '')} · ${escapeHtml(msg.attachment.file_size_human || '')}
                </div>
                <a class="chat-attachment-link" href="${escapeAttr(msg.attachment.download_url || '#')}" target="_blank">
                    <i class="fas fa-download mr-1"></i>Download
                </a>
            </div>
        `;
            }

            const senderLabelHtml = msg.message_type === 'announcement'
                ? `<div class="chat-announcement-sender">${escapeHtml(msg.sender_name || 'Administrator')}</div>`
                : '';
            const messageHtml = msg.message_type === 'announcement'
                ? formatAnnouncementHtml(msg.message_text)
                : linkify(msg.message_text);

            html += `
        <div class="chat-message-row ${msg.is_own ? 'own' : ''}">
            <div class="chat-bubble">
                ${senderLabelHtml}
                ${msg.message_text ? `<div>${messageHtml}</div>` : ''}
                ${attachmentHtml}
                <div class="chat-meta">
                    ${escapeHtml(formatDateEU(msg.created_at))}
                </div>
            </div>
        </div>
    `;
        });

        chatBox.html(html);

        if (forceScrollOnNextRender || shouldAutoScrollOnNextRender || wasNearBottom) {
            scrollChatToBottom();
        } else if (chatEl) {
            chatEl.scrollTop = previousScrollTop;
        }

        forceScrollOnNextRender = false;
        shouldAutoScrollOnNextRender = false;

        if (lastMessageId > 0 && currentThreadId) {
            knownLastMessageIds[currentThreadId] = lastMessageId;
            initializedThreadIds[currentThreadId] = true;
            if (!document.hidden && document.hasFocus() && isConversationVisible()) {
                markThreadRead(currentThreadId, lastMessageId);
                clearContactUnread(currentThreadId);
                stopTitleBlink();
            }
        }
    }

    function loadMessages(threadId) {
        $.ajax({
            url: 'scripts/chat/get_messages.php',
            method: 'GET',
            dataType: 'json',
            data: { thread_id: threadId },
            success: function (res) {
                if (!res || res.status !== 'success') return;
                renderMessages(res.messages || []);
            },
            error: function (xhr) {
                console.log('loadMessages error:', xhr.responseText);
            }
        });
    }

    function loadThreadInfo(threadId) {
        $.ajax({
            url: 'scripts/chat/get_thread.php',
            method: 'GET',
            dataType: 'json',
            data: { thread_id: threadId },
            success: function (res) {
                if (!res || res.status !== 'success' || !res.thread) {
                    return;
                }

                currentThreadId = parseInt(res.thread.id, 10);
                const threadType = (res.thread.thread_type || 'dm');
                if (threadType === 'announcement') {
                    currentChatUserId = -currentThreadId;
                    currentChatUserName = res.thread.title || 'Broadcast messages';
                    $('#chatThreadId').val(currentThreadId);
                    renderChatHeader({
                        name: currentChatUserName,
                        photo: '',
                        status_bg: 'bg-maroon'
                    });
                    setComposerEnabled(false);
                    clearContactUnread(currentThreadId);
                    stopTitleBlink();
                    return;
                }

                setComposerEnabled(true);

                $('#chatThreadId').val(currentThreadId);
                $('#chatMessageInput').prop('disabled', false);
                $('#chatSendBtn').prop('disabled', false);
                $('#chatEmojiToggle').prop('disabled', false);
                $('#chatAttachToggle').prop('disabled', false);

                if (res.thread.other_user) {
                    currentChatUserId = parseInt(res.thread.other_user.id, 10);
                    currentChatUserName = res.thread.other_user.name || currentChatUserName || '';

                    let fallbackPhoto = '';

                    const activeContact = $('.chat-contact[data-user-id="' + currentChatUserId + '"]');
                    if (activeContact.length) {
                        fallbackPhoto = activeContact.attr('data-user-photo') || '';
                    }

                    const currentHeaderSrc = $('#chatHeaderPhoto').attr('src') || '';
                    let safePhoto = '';

                    if (res.thread.other_user.photo && String(res.thread.other_user.photo).trim() !== '') {
                        safePhoto = res.thread.other_user.photo;
                    } else if (fallbackPhoto && String(fallbackPhoto).trim() !== '') {
                        safePhoto = fallbackPhoto;
                    } else if (
                        currentHeaderSrc &&
                        currentHeaderSrc !== 'images/profile.jpg' &&
                        !currentHeaderSrc.endsWith('/images/profile.jpg')
                    ) {
                        safePhoto = currentHeaderSrc;
                    }

                    const headerUser = {
                        ...res.thread.other_user,
                        photo: safePhoto
                    };

                    renderChatHeader(headerUser);
                    setActiveContact(currentChatUserId);
                } else {
                    if (!currentChatUserName || currentChatUserName === 'Select a colleague') {
                        renderChatHeader({
                            name: res.thread.title || 'Conversation',
                            photo: '',
                            department_name: '',
                            status_label: '',
                            status_icon: '',
                            status_bg: 'bg-dark'
                        });
                    }
                }

                clearContactUnread(currentThreadId);
                stopTitleBlink();
            },
            error: function (xhr) {
                console.log('get_thread error:', xhr.responseText);
            }
        });

    }

    function openDmWithUser(userId, userName, userPhoto = '') {
        showConversation();
        currentChatUserId = parseInt(userId, 10);
        currentChatUserName = userName || 'Conversation';

        setActiveContact(currentChatUserId);

        const activeContact = $('.chat-contact[data-user-id="' + currentChatUserId + '"]');

        renderChatHeader({
            id: currentChatUserId,
            name: currentChatUserName,
            photo: userPhoto,
            department_name: activeContact.attr('data-department-name') || '',
            status_label: activeContact.attr('data-status-label') || '',
            status_icon: activeContact.attr('data-status-icon') || '',
            status_bg: activeContact.attr('data-status-bg') || 'bg-dark',
            is_active: activeContact.attr('data-is-active') !== 'false'
        });

        $('#chatMessages').html('<div class="text-muted">Loading conversation...</div>');
        $('#chatMessageInput').prop('disabled', false);
        $('#chatSendBtn').prop('disabled', false);
        $('#chatEmojiToggle').prop('disabled', false);

        $.ajax({
            url: 'scripts/chat/start_dm.php',
            method: 'POST',
            dataType: 'json',
            data: { other_user_id: userId },
            success: function (res) {
                if (!res || res.status !== 'success') {
                    alert((res && res.message) ? res.message : 'Failed to open the chat');
                    return;
                }

                currentThreadId = parseInt(res.thread_id, 10);
                $('#chatThreadId').val(currentThreadId);

                clearContactUnread(currentThreadId);
                stopTitleBlink();
                shouldAutoScrollOnNextRender = true;
                suppressNextIncomingSoundForThreadId = currentThreadId;

                loadThreadInfo(currentThreadId);
                loadMessages(currentThreadId);
                loadContacts($('#chatSearch').val());

                const newUrl = `${window.location.pathname}?page=chat&thread_id=${currentThreadId}`;
                window.history.replaceState({}, '', newUrl);
            },
            error: function (xhr, status, error) {
                console.log('start_dm AJAX ERROR');
                console.log('status:', status);
                console.log('error:', error);
                console.log('responseText:', xhr.responseText);

                alert('Error while opening the chat. Check the console.');
            }
        });
    }

    function sendMessage() {
        if (broadcastModeEnabled) {
            sendAnnouncement();
            return;
        }
        const threadId = $('#chatThreadId').val();
        const messageText = $('#chatMessageInput').val().trim();

        if (selectedAttachmentFile) {
            sendAttachmentMessage();
            return;
        }

        if (!threadId || !messageText) return;

        $.ajax({
            url: 'scripts/chat/send_message.php',
            method: 'POST',
            dataType: 'json',
            data: {
                thread_id: threadId,
                message_text: messageText
            },
            success: function (res) {
                if (!res || res.status !== 'success') {
                    alert((res && res.message) ? res.message : 'Failed to send the message');
                    return;
                }

                $('#chatMessageInput').val('');
                toggleEmojiPicker(false);
                forceScrollOnNextRender = true;
                loadMessages(threadId);
                loadContacts($('#chatSearch').val());
            },
            error: function (xhr) {
                console.log('sendMessage error:', xhr.responseText);
            }
        });
    }

    function markThreadRead(threadId, lastMessageId) {
        $.ajax({
            url: 'scripts/chat/mark_read.php',
            method: 'POST',
            dataType: 'json',
            data: {
                thread_id: threadId,
                last_message_id: lastMessageId
            },
            success: function (res) {
                if (res && res.status === 'success' && chatNotifications) {
                    chatNotifications.markRead(threadId, res.last_read_message_id || lastMessageId);
                }
            }
        });
    }

    function checkForIncomingMessages() {
        if (isCheckingUpdates) {
            pendingCheckUpdates = true;
            return;
        }

        isCheckingUpdates = true;

        $.ajax({
            url: 'scripts/chat/check_updates.php',
            method: 'GET',
            dataType: 'json',
            success: function (res) {
                if (!res || res.status !== 'success' || !Array.isArray(res.threads)) {
                    return;
                }

                if (chatNotifications) chatNotifications.syncServer(res);
                let shouldReloadCurrentThread = false;
                let currentSearch = $('#chatSearch').val() || '';

                res.threads.forEach(function (thread) {
                    let threadId = parseInt(thread.thread_id || 0, 10);
                    let lastMessageId = parseInt(thread.last_message_id || 0, 10);
                    let unreadCount = parseInt(thread.unread_count || 0, 10);
                    let lastSenderId = parseInt(thread.last_sender_id || 0, 10);
                    let isCurrentThread = threadId === parseInt(currentThreadId || 0, 10) && isConversationVisible();
                    let isOwnLastMessage = lastSenderId > 0 && lastSenderId === currentUserId;

                    if (!threadId || !lastMessageId) return;

                    if (initializedThreadIds[threadId] !== true) {
                        knownLastMessageIds[threadId] = lastMessageId;
                        initializedThreadIds[threadId] = true;
                    } else if (lastMessageId > (knownLastMessageIds[threadId] || 0)) {
                        knownLastMessageIds[threadId] = lastMessageId;

                        if (isCurrentThread) {
                            if (suppressNextIncomingSoundForThreadId === threadId) {
                                suppressNextIncomingSoundForThreadId = null;
                            } else if (!isOwnLastMessage) {
                                if (document.hidden || !document.hasFocus()) {
                                    triggerIncomingNotification(thread);
                                } else {
                                    playNotificationSound(threadId, lastMessageId);
                                }
                            }

                            forceScrollOnNextRender = isChatScrolledNearBottom();
                            shouldReloadCurrentThread = true;
                        } else if (!isOwnLastMessage) {
                            triggerIncomingNotification(thread);
                            markContactUnread(threadId, unreadCount);
                        }
                    } else {
                        knownLastMessageIds[threadId] = lastMessageId;
                    }

                    if (unreadCount > 0 && !isCurrentThread) {
                        markContactUnread(threadId, unreadCount);
                    }

                    if (isCurrentThread && !document.hidden && document.hasFocus() && isConversationVisible()) {
                        clearContactUnread(threadId);
                    }
                });

                loadContacts(currentSearch);

                if (currentThreadId) {
                    loadThreadInfo(currentThreadId);
                }

                if (shouldReloadCurrentThread && currentThreadId) {
                    loadMessages(currentThreadId);
                }
            },
            error: function (xhr) {
                console.log('checkForIncomingMessages error:', xhr.responseText);
            },
            complete: function () {
                isCheckingUpdates = false;

                if (pendingCheckUpdates) {
                    pendingCheckUpdates = false;
                    checkForIncomingMessages();
                }
            }
        });
    }

    function startPolling() {
        if (chatPollInterval) {
            clearInterval(chatPollInterval);
        }

        chatPollInterval = setInterval(function () {
            checkForIncomingMessages();
        }, 5000);
        chatRuntime.chatPollInterval = chatPollInterval;
    }

    $(document).ready(function () {
        $('body').addClass('chat-layout-page');
        $('#chatPageRoot').closest('.card').addClass('chat-page-card');
        syncChatHeight();
        $(window).on('resize.darkScrubChat load.darkScrubChat', syncChatHeight);
        chatRuntime.viewportHandler = syncChatHeight;
        if (window.visualViewport) window.visualViewport.addEventListener('resize', syncChatHeight);
        $('#chatShowContacts').off(chatEventNamespace).on('click.darkScrubChat', function () {
            $('#chatPageRoot').removeClass('conversation-open');
            toggleEmojiPicker(false);
            $('#chatSearch').trigger('focus');
        });
        $('#chatComposeBroadcast').off(chatEventNamespace).on('click.darkScrubChat', showConversation);
        $('.chat-conversation').off(chatEventNamespace)
            .on('dragenter.darkScrubChat dragover.darkScrubChat', function (e) {
                const transfer = e.originalEvent && e.originalEvent.dataTransfer;
                if (!transfer || !Array.from(transfer.types || []).includes('Files')) return;
                e.preventDefault();
                if (!$('#chatAttachToggle').prop('disabled')) $(this).addClass('is-dragging');
            })
            .on('dragleave.darkScrubChat', function (e) {
                if (!this.contains(e.relatedTarget)) $(this).removeClass('is-dragging');
            })
            .on('drop.darkScrubChat', function (e) {
                e.preventDefault();
                $(this).removeClass('is-dragging');
                const transfer = e.originalEvent && e.originalEvent.dataTransfer;
                handleAttachmentFiles(transfer ? transfer.files : null);
            });
        $(document).on('dragend.darkScrubChat', function () { $('.chat-conversation').removeClass('is-dragging'); });
        renderEmojiPicker();
        loadContacts();
        checkForIncomingMessages();
        startPolling();

        $('#chatSearch, #chatEmojiToggle, #chatSendForm, #chatMessageInput, #chatAttachToggle, #chatAttachmentInput, #chatAttachmentDropzone, #chatAttachmentRemove')
            .off(chatEventNamespace);

        $(document).on('click.darkScrubChat keydown.darkScrubChat mousedown.darkScrubChat', function () {
            userHasInteracted = true;
            chatRuntime.userHasInteracted = true;
            requestBrowserNotificationPermission();
        });

        $('#chatSearch').on('input.darkScrubChat', function () {
            loadContacts($(this).val());
        });

        $('#chatEmojiToggle').on('click.darkScrubChat', function (e) {
            e.preventDefault();
            if ($(this).prop('disabled')) return;
            toggleEmojiPicker();
        });

        $(document).on('click.darkScrubChat', '.chat-emoji-btn', function (e) {
            e.preventDefault();
            insertEmojiToMessage($(this).data('emoji') || '');
        });

        $(document).on('click.darkScrubChat', function (e) {
            const $target = $(e.target);
            if (!$target.closest('#chatEmojiPicker').length && !$target.closest('#chatEmojiToggle').length) {
                toggleEmojiPicker(false);
            }
        });

        $(document).on('keydown.darkScrubChat', '.chat-contact', function (e) {
            if (e.target !== this || !['Enter', ' '].includes(e.key)) return;
            e.preventDefault();
            $(this).trigger('click');
        });
        $(document).on('click.darkScrubChat', '.chat-contact', function (e) {
            const threadType = $(this).attr('data-thread-type') || 'dm';
            const userId = $(this).data('user-id');
            const userName = $(this).data('user-name');
            const userPhoto = $(this).attr('data-user-photo') || '';
            const threadId = $(this).attr('data-thread-id') || '';

            if (broadcastModeEnabled && threadType !== 'announcement') {
                e.preventDefault();
                toggleAnnouncementRecipient(userId);
                return;
            }

            if (threadType === 'announcement') {
                openAnnouncementThread(threadId);
                return;
            }

            openDmWithUser(userId, userName, userPhoto);
        });

        $('#chatSendForm').on('submit.darkScrubChat', function (e) {
            e.preventDefault();
            sendMessage();
        });

        $('#chatMessageInput').on('keypress.darkScrubChat', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        $(document).on('darkscrub:chat-read.darkScrubChat', function () {
            loadContacts($('#chatSearch').val());
        });

        $(window).on('focus.darkScrubChat', function () {
            if (currentThreadId) {
                stopTitleBlink();
                forceScrollOnNextRender = false;
                loadMessages(currentThreadId);
            }
        });

        chatRuntime.visibilityHandler = function () {
            if (!document.hidden && currentThreadId) {
                stopTitleBlink();
                forceScrollOnNextRender = false;
                loadMessages(currentThreadId);
            }
        };
        document.addEventListener('visibilitychange', chatRuntime.visibilityHandler);

        const preselectedThreadId = getUrlParam('thread_id');
        if (preselectedThreadId) {
            showConversation();
            currentThreadId = parseInt(preselectedThreadId, 10);
            $('#chatThreadId').val(currentThreadId);
            setComposerEnabled(true);

            setTimeout(function () {
                populateHeaderFromContactByThread(currentThreadId);
            }, 300);

            shouldAutoScrollOnNextRender = true;
            loadThreadInfo(currentThreadId);
            loadMessages(currentThreadId);
        } else {
            renderChatHeader(null);
            setComposerEnabled(false);
            renderChatHeader(null);
        }

        $('#chatAttachToggle').on('click.darkScrubChat', function (e) {
            e.preventDefault();
            if ($(this).prop('disabled')) return;
            $('#chatAttachmentInput').trigger('click');
        });

        $('#chatAttachmentInput').on('change.darkScrubChat', function () {
            handleAttachmentFiles(this.files);
        });

        $('#chatAttachmentDropzone')
            .on('click.darkScrubChat', function (e) {
                e.preventDefault();
                if ($(this).hasClass('is-disabled')) return;
                $('#chatAttachmentInput').trigger('click');
            })
            .on('keydown.darkScrubChat', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                if ($(this).hasClass('is-disabled')) return;
                $('#chatAttachmentInput').trigger('click');
            })
            .on('dragenter.darkScrubChat dragover.darkScrubChat', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if ($(this).hasClass('is-disabled')) return;
                $(this).addClass('drag-over');
            })
            .on('dragleave.darkScrubChat dragend.darkScrubChat', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            })
            .on('drop.darkScrubChat', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
                $('.chat-conversation').removeClass('is-dragging');

                const dataTransfer = e.originalEvent && e.originalEvent.dataTransfer;
                handleAttachmentFiles(dataTransfer ? dataTransfer.files : null);
            });

        $('#chatAttachmentRemove').on('click.darkScrubChat', function (e) {
            e.preventDefault();
            resetAttachmentPreview();
        });
        $(document).on('change.darkScrubChat', '#chatBroadcastModeToggle', function () {
            toggleBroadcastMode($(this).is(':checked'));
        });

        $(document).on('change.darkScrubChat', '#chatAnnouncementDepartments', function () {
            applyDepartmentSelection();
        });

        $(document).on('click.darkScrubChat', '#chatBroadcastSelectAll', function (e) {
            e.preventDefault();
            selectedAnnouncementRecipients = new Set(
                contactsCache
                    .filter(item => (item.item_type || 'user') === 'user')
                    .map(item => parseInt(item.id, 10))
                    .filter(id => id > 0)
            );
            refreshBroadcastSummary();
            loadContacts($('#chatSearch').val());
        });

        $(document).on('click.darkScrubChat', '#chatBroadcastClearAll', function (e) {
            e.preventDefault();
            $('#chatAnnouncementDepartments').val([]);
            selectedAnnouncementRecipients = new Set();
            refreshBroadcastSummary();
            loadContacts($('#chatSearch').val());
        });
    });
    function formatAnnouncementHtml(text) {
        // Parse in an inert template, then rebuild only formatting elements.
        // Never insert the original HTML or copy arbitrary attributes into the page.
        const source = document.createElement('template');
        source.innerHTML = String(text || '');
        const output = document.createElement('div');
        const allowedTags = new Set([
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'div', 'span',
            'strong', 'b', 'em', 'i', 'u', 's', 'del', 'small', 'sub', 'sup',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a'
        ]);

        function appendSafeNodes(parent, target, autoLink = true) {
            Array.from(parent.childNodes).forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    if (autoLink && node.textContent.trim() !== '') {
                        const linkedText = document.createElement('template');
                        linkedText.innerHTML = linkify(node.textContent);
                        target.appendChild(linkedText.content);
                    } else {
                        target.appendChild(document.createTextNode(node.textContent));
                    }
                    return;
                }

                if (node.nodeType !== Node.ELEMENT_NODE) return;
                const tag = node.localName.toLowerCase();
                if (!allowedTags.has(tag)) return;

                const element = document.createElement(tag);
                if (tag === 'a') {
                    const href = (node.getAttribute('href') || '').trim();
                    try {
                        const url = new URL(href, window.location.href);
                        if (href && ['http:', 'https:', 'mailto:'].includes(url.protocol)) {
                            element.setAttribute('href', url.href);
                            element.setAttribute('target', '_blank');
                            element.setAttribute('rel', 'noopener noreferrer');
                            element.className = 'chat-web-link';
                        }
                    } catch (error) {
                        // Invalid links retain their label without a clickable destination.
                    }
                }
                appendSafeNodes(node, element, autoLink && !['a', 'pre', 'code'].includes(tag));
                target.appendChild(element);
            });
        }

        appendSafeNodes(source.content, output);
        return output.innerHTML;
    }

    function chatMessagePreview(data, threadType) {
        const text = normalizeToastText(data.last_message_text || data.message_text || data.preview || data.message, '');
        if (threadType !== 'announcement') return text;

        const content = document.createElement('template');
        content.innerHTML = formatAnnouncementHtml(text);
        content.content.querySelectorAll('br, hr').forEach(node => node.replaceWith(' '));
        content.content.querySelectorAll('h1, h2, h3, h4, h5, h6, p, div, li, blockquote, pre')
            .forEach(node => { node.prepend(' '); node.append(' '); });
        return content.content.textContent.replace(/\s+/g, ' ').trim();
    }

    function linkifyHttp(text) {
        const source = String(text || '');
        const pattern = /https?:\/\/[^\s<>"']+/gi;
        let html = '';
        let lastIndex = 0;
        let match;

        while ((match = pattern.exec(source)) !== null) {
            const url = match[0];
            html += escapeHtml(source.slice(lastIndex, match.index));
            html += `<a href="${escapeAttr(url)}" class="chat-web-link" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
            lastIndex = pattern.lastIndex;
        }

        return html + escapeHtml(source.slice(lastIndex));
    }

    function linkify(text) {
        if (!text) return '';

        const source = String(text);
        const pathPattern = /Y:[\\\/][^\r\n]+/gi;
        let html = '';
        let lastIndex = 0;
        let match;

        while ((match = pathPattern.exec(source)) !== null) {
            html += linkifyHttp(source.slice(lastIndex, match.index));

            // The path reaches the end of the line, so it may contain spaces.
            const matchedText = match[0];
            const path = matchedText.trimEnd();
            const trailingWhitespace = matchedText.slice(path.length);
            const fileUrl = encodeURI('file:///' + path.replace(/\\/g, '/'));
            const encodedPath = encodeURIComponent(path);

            html += `
                <span class="chat-file-wrapper">
                    <a href="${escapeHtml(fileUrl)}" class="chat-file-link">
                        <i class="fas fa-folder-open mr-1"></i>${escapeHtml(path)}
                    </a>
                    <button type="button" class="chat-copy-path" data-path="${encodedPath}" title="Copy full path">
                        <i class="fas fa-copy"></i>
                    </button>
                </span>${escapeHtml(trailingWhitespace)}
            `;

            lastIndex = pathPattern.lastIndex;
        }

        html += linkifyHttp(source.slice(lastIndex));
        return html;
    }
    $(document).on('click.darkScrubChat', '.chat-file-link', function(e) {
        // optional: warning
        console.log('Opening file path:', this.href);
    });

    $(document).on('click.darkScrubChat', '.chat-copy-path', function(e) {
        e.preventDefault();

        const encodedPath = $(this).attr('data-path');
        const path = encodedPath ? decodeURIComponent(encodedPath) : '';

        if (!path) return;

        navigator.clipboard.writeText(path).then(() => {
            const btn = $(this);
            const original = btn.html();

            btn.html('<i class="fas fa-check"></i>');
            btn.css('color', '#28a745');

            setTimeout(() => {
                btn.html(original);
                btn.css('color', '');
            }, 1200);
        }).catch(() => {
            alert('Failed to copy the path');
        });
    });
    })(window.jQuery);
</script>
