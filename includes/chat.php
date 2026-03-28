<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Musíš byť prihlásený.</div>';
    return;
}
?>

<div class="row">
    <div class="col-md-4">
        <div class="card card-dark card-outline">
            <div class="card-header">
                <h3 class="card-title">Kolegovia</h3>
            </div>
            <div class="card-body p-2">
                <input type="text" id="chatSearch" class="form-control mb-2" placeholder="Hľadať kolegu...">
                <div id="chatContactsList" class="chat-panel-height" style="overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card card-dark card-outline">
            <div class="card-header bg-dark" id="chatCardHeader">
                <div class="d-flex align-items-center w-100">
                    <img id="chatHeaderPhoto"
                         src="images/profile.jpg"
                         alt="Používateľ"
                         class="img-circle mr-2"
                         style="width:36px; height:36px; object-fit:cover; display:none;">
                    <div class="flex-grow-1">
                        <h3 class="card-title mb-0" id="chatThreadTitle">Vyber kolegu</h3>
                        <div id="chatHeaderMeta" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <div class="card-body p-3 chat-panel-height" id="chatMessages" style="overflow-y: auto; background: #1f2d3d;">
                <div class="text-muted">Zatiaľ nie je otvorená žiadna konverzácia.</div>
            </div>

            <div class="card-footer">
                <form id="chatSendForm" autocomplete="off">
                    <input type="hidden" id="chatThreadId" name="thread_id" value="">
                    <div class="input-group chat-input-group">
                        <input type="text" id="chatMessageInput" name="message_text" class="form-control" placeholder="Napíš správu..." disabled>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-outline-light chat-emoji-toggle" id="chatEmojiToggle" disabled aria-label="Vybrať smajlík" title="Smajlíky">
                                <i class="far fa-smile"></i>
                            </button>
                            <button type="submit" class="btn btn-primary" id="chatSendBtn" disabled>Odoslať</button>
                        </div>
                    </div>
                    <div id="chatEmojiPicker" class="chat-emoji-picker" style="display:none;">
                        <div class="chat-emoji-picker-header">Smajlíky</div>
                        <div class="chat-emoji-grid" id="chatEmojiGrid"></div>
                    </div>
                    <audio id="chatNotificationAudio" preload="auto">
                        <source src="sounds/notification.mp3" type="audio/mpeg">
                    </audio>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.chat-contact {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    margin-bottom: 6px;
    background: rgba(255,255,255,0.03);
    border: 1px solid transparent;
    transition: all 0.15s ease-in-out;
    position: relative;
}

.chat-contact:hover {
    background: rgba(255,255,255,0.08);
}

.chat-contact.active {
    border-color: rgba(255,255,255,0.25);
    background: rgba(0,123,255,0.22);
}

.chat-contact.unread {
    border: 2px solid rgba(255,255,255,0.85); /* svetlý outline */
    box-shadow: 0 0 8px rgba(255,255,255,0.25); /* jemný glow */   
}

.chat-contact.unread .chat-contact-name {
    font-weight: 700;
}

.chat-contact.unread .chat-contact-meta {
    color: rgba(255,255,255,0.96) !important;
    font-weight: 600;
}

.chat-unread-badge {
    min-width: 24px;
    height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: rgba(255,255,255,0.96);
    color: #1f2d3d;
    font-size: 12px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 8px;
    box-shadow: 0 0 10px rgba(0,0,0,0.18);
}

.chat-contact img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
}

.chat-contact-name {
    font-weight: 600;
}

.chat-contact-meta {
    font-size: 12px;
    color: #adb5bd;
    line-height: 1.25;
}

.chat-contact-status {
    margin-left: auto;
    font-size: 16px;
    color: #343a40;
    opacity: 0.9;
}

.chat-message-row {
    display: flex;
    margin-bottom: 12px;
}

.chat-message-row.own {
    justify-content: flex-end;
}

.chat-bubble {
    max-width: 75%;
    padding: 10px 12px;
    border-radius: 12px;
    background: #343a40;
    color: #fff;
    word-break: break-word;
}

.chat-message-row.own .chat-bubble {
    background: #007bff;
}

.chat-meta {
    font-size: 11px;
    opacity: 0.8;
    margin-top: 6px;
}

.chat-panel-height {
    height: 650px;
}

.chat-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 99999;
    min-width: 280px;
    max-width: 380px;
    background: #dc3545;
    color: #fff;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.35);
    opacity: 0;
    transform: translateY(-12px);
    transition: all 0.25s ease;
    font-weight: 600;
}

.chat-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.chat-input-group {
    position: relative;
}

.chat-emoji-toggle {
    border-color: rgba(255,255,255,0.12);
    color: #f8f9fa;
    background: #2b3645;
}

.chat-emoji-toggle:hover,
.chat-emoji-toggle:focus {
    color: #fff;
    background: #364455;
    border-color: rgba(255,255,255,0.22);
}

.chat-emoji-toggle:disabled {
    opacity: 0.55;
}

.chat-emoji-picker {
    position: absolute;
    right: 15px;
    bottom: 72px;
    width: 320px;
    background: #243140;
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 14px;
    box-shadow: 0 16px 36px rgba(0,0,0,0.42);
    z-index: 1055;
    padding: 12px;
}

.chat-emoji-picker-header {
    color: #ced4da;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 10px;
}

.chat-emoji-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 6px;
    max-height: 220px;
    overflow-y: auto;
}

.chat-emoji-btn {
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #fff;
    font-size: 22px;
    line-height: 1;
    padding: 8px 0;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.12s ease;
}

.chat-emoji-btn:hover,
.chat-emoji-btn:focus {
    background: rgba(255,255,255,0.10);
    outline: none;
    transform: scale(1.05);
}

#chatHeaderMeta {
    display: none;
}
/* scroll container */
#chatContactsList {
    scrollbar-width: thin; /* Firefox */
    scrollbar-color: #495057 transparent; /* Firefox */
}

#chatMessages { scrollbar-width: thin; /* Firefox */
    scrollbar-color: #495057 transparent; /* Firefox */ }

/* Chrome / Edge / Safari */
#chatContactsList::-webkit-scrollbar {
    width: 8px;
}

#chatContactsList::-webkit-scrollbar-track {
    background: transparent;
    margin: 6px 0; /* ⬅️ odsadenie hore/dole */
}

#chatContactsList::-webkit-scrollbar-thumb {
    background-color: #495057; /* tmavá sivá */
    border-radius: 10px;
    border: 2px solid transparent; /* ⬅️ vytvorí “gap” */
    background-clip: content-box;
}

#chatContactsList::-webkit-scrollbar-thumb:hover {
    background-color: #6c757d;
}
</style>

<script>
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
    if (isYesterday) return `Včera ${time}`;

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
    if (count === 1) return '1 neprečítaná správa';
    if (count >= 2 && count <= 4) return `${count} neprečítané správy`;
    return `${count} neprečítaných správ`;
}

let currentThreadId = null;
let currentChatUserName = '';
let currentChatUserId = null;
let chatPollInterval = null;

let originalPageTitle = document.title;
let titleBlinkInterval = null;
let knownLastMessageIds = {};
let initializedThreadIds = {};
let userHasInteracted = false;
let notifyAudio = null;
let shouldAutoScrollOnNextRender = false;
let forceScrollOnNextRender = false;
let suppressNextIncomingSoundForThreadId = null;
const chatEmojiList = ['😀','😁','😂','🤣','😊','😉','😍','😘','😎','🤩','🙂','🙃','🤗','🤔','😴','🤤','🤝','👏','👍','👎','🙏','💪','🔥','✨','🎉','❤️','💙','💚','💛','💯','✅','❌','⚠️','🚀','📦','📞','💬','😅','😭','😡','🤯','😇','🤌','👌','🙌','👀','🎯'];

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

function sortContactsForList(contacts) {
    return (contacts || []).slice().sort(function(a, b) {
        const unreadA = parseInt(a.unread_count || 0, 10);
        const unreadB = parseInt(b.unread_count || 0, 10);

        if (unreadA > 0 && unreadB === 0) return -1;
        if (unreadB > 0 && unreadA === 0) return 1;
        if (unreadA !== unreadB) return unreadB - unreadA;

        const timeA = a.last_message_at ? new Date(a.last_message_at).getTime() : 0;
        const timeB = b.last_message_at ? new Date(b.last_message_at).getTime() : 0;
        if (timeA !== timeB) return timeB - timeA;

        return String(a.name || '').localeCompare(String(b.name || ''), 'sk', { sensitivity: 'base' });
    });
}

function applyChatHeaderStatus(statusBg) {
    const header = $('#chatCardHeader');
    const allowedClasses = [
        'bg-success', 'bg-warning', 'bg-danger', 'bg-info', 'bg-secondary',
        'bg-primary', 'bg-dark', 'bg-orange', 'bg-teal', 'bg-indigo', 'bg-pink'
    ];

    header.removeClass(allowedClasses.join(' ')).addClass(statusBg || 'bg-dark');
}

function renderChatHeader(user) {
    if (!user) {
        $('#chatHeaderPhoto').hide();
        $('#chatThreadTitle').text('Vyber kolegu');
        $('#chatHeaderMeta').html('').hide();
        applyChatHeaderStatus('bg-dark');
        return;
    }

    let photo = buildPhotoPath(user.photo);
    let currentPhoto = $('#chatHeaderPhoto').attr('src') || '';

    if (
        (!user.photo || String(user.photo).trim() === '') &&
        currentPhoto &&
        currentPhoto !== 'images/profile.jpg' &&
        !currentPhoto.endsWith('/images/profile.jpg')
    ) {
        photo = currentPhoto;
    }

    $('#chatHeaderPhoto')
        .attr('src', photo)
        .off('error')
        .on('error', function() {
            $(this).attr('src', 'images/profile.jpg');
        })
        .show();

    $('#chatThreadTitle').text(user.name || 'Konverzácia');
    $('#chatHeaderMeta').html('').hide();
    applyChatHeaderStatus(user.status_bg || 'bg-dark');
}

function setActiveContact(userId) {
    $('.chat-contact').removeClass('active');
    $('.chat-contact[data-user-id="' + userId + '"]').addClass('active');
}

function populateHeaderFromContactByThread(threadId) {
    const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');

    if (!contact.length) return false;

    const userId = parseInt(contact.attr('data-user-id') || 0, 10);
    const userName = contact.attr('data-user-name') || 'Konverzácia';
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
        status_bg: contact.attr('data-status-bg') || 'bg-dark'
    });

    setActiveContact(userId);
    return true;
}

function showChatToast(message) {
    const toast = $(`
        <div class="chat-toast">
            ${escapeHtml(message)}
        </div>
    `);

    $('body').append(toast);

    setTimeout(function() {
        toast.addClass('show');
    }, 10);

    setTimeout(function() {
        toast.removeClass('show');
        setTimeout(function() {
            toast.remove();
        }, 250);
    }, 4200);
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

function playNotificationSound() {
    if (!userHasInteracted) return;

    ensureNotificationAudio();
    notifyAudio.currentTime = 0;
    notifyAudio.play().catch(function() {});
}

function startTitleBlink(text) {
    if (titleBlinkInterval) return;

    let toggle = false;
    titleBlinkInterval = setInterval(function() {
        document.title = toggle ? text : originalPageTitle;
        toggle = !toggle;
    }, 1000);
}

function stopTitleBlink() {
    if (titleBlinkInterval) {
        clearInterval(titleBlinkInterval);
        titleBlinkInterval = null;
    }
    document.title = originalPageTitle;
}

function triggerIncomingNotification(senderName, threadId) {
    const safeName = senderName || 'kolegu';

    showChatToast('Nová správa od ' + safeName);
    playNotificationSound();

    if (document.hidden || parseInt(currentThreadId || 0, 10) !== parseInt(threadId || 0, 10)) {
        startTitleBlink('💬 Nová správa od ' + safeName);
    }
}

function updateContactUnreadState(contact, unreadCount) {
    const count = parseInt(unreadCount || 0, 10);
    if (!contact || !contact.length) return;

    contact.find('.chat-unread-badge').remove();
    contact.find('.chat-contact-meta').text(contact.attr('data-default-meta') || '');

    if (count > 0) {
        contact.addClass('unread');
        contact.find('.chat-contact-name').append(`<span class="chat-unread-badge">${count}</span>`);
        contact.find('.chat-contact-meta').text(getUnreadMessageLabel(count));
    } else {
        contact.removeClass('unread');
    }
}

function markContactUnread(threadId, unreadCount) {
    const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');
    updateContactUnreadState(contact, unreadCount);
}

function clearContactUnread(threadId) {
    const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');
    updateContactUnreadState(contact, 0);
}

function renderEmojiPicker() {
    const grid = $('#chatEmojiGrid');
    if (!grid.length || grid.children().length) return;

    let html = '';
    chatEmojiList.forEach(function(emoji) {
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

function loadContacts(query = '') {
    $.ajax({
        url: 'scripts/chat/get_contacts.php',
        method: 'GET',
        dataType: 'json',
        data: { q: query },
        success: function(res) {
            if (!res || res.status !== 'success') return;

            let html = '';
            const contacts = sortContactsForList(res.contacts || []);

            if (!contacts.length) {
                html = '<div class="text-muted p-2">Žiadni kolegovia.</div>';
            } else {
                contacts.forEach(function(user) {
                    let photo = buildPhotoPath(user.photo);
                    let dept = user.department_name ? user.department_name : '';
                    let statusLabel = user.status_label ? user.status_label : 'Unknown';
                    let statusBg = user.status_bg ? user.status_bg : 'bg-secondary';
                    let statusIcon = user.status_icon ? user.status_icon : 'fa-question';
                    let isActive = currentChatUserId && parseInt(currentChatUserId, 10) === parseInt(user.id, 10);
                    let unreadCount = parseInt(user.unread_count || 0, 10);
                    let threadId = parseInt(user.thread_id || 0, 10);
                    let metaText = unreadCount > 0 ? getUnreadMessageLabel(unreadCount) : (dept ? dept : '');

                    html += `
                    <div class="chat-contact ${statusBg} ${isActive ? 'active' : ''} ${unreadCount > 0 ? 'unread' : ''}"
                        data-user-id="${parseInt(user.id, 10)}"
                        data-user-name="${escapeAttr(user.name)}"
                        data-user-photo="${escapeAttr(user.photo || '')}"
                        data-thread-id="${threadId}"
                        data-status-bg="${escapeAttr(statusBg)}"
                        data-status-label="${escapeAttr(statusLabel)}"
                        data-status-icon="${escapeAttr(statusIcon)}"
                        data-department-name="${escapeAttr(dept)}"
                        data-default-meta="${escapeAttr(dept)}"
                        style="color:#fff;">

                        <img src="${escapeAttr(photo)}" alt="" onerror="this.src='images/profile.jpg';">

                        <div class="flex-grow-1">
                            <div class="chat-contact-name">
                                ${escapeHtml(user.name)}
                                ${unreadCount > 0 ? `<span class="chat-unread-badge">${unreadCount}</span>` : ''}
                            </div>

                            <div class="chat-contact-meta text-white">
                                ${escapeHtml(metaText)}
                            </div>
                        </div>

                        <div class="chat-contact-status">
                            <i class="fas ${escapeHtml(statusIcon)}"></i>
                        </div>
                    </div>
                `;
                });
            }

            $('#chatContactsList').html(html);

            if (currentThreadId) {
                const headerTitle = ($('#chatThreadTitle').text() || '').trim();

                if (!currentChatUserId || headerTitle === 'Vyber kolegu') {
                    populateHeaderFromContactByThread(currentThreadId);
                }

                clearContactUnread(currentThreadId);
            }

            if (currentChatUserId) {
                setActiveContact(currentChatUserId);
            }
        },
        error: function(xhr) {
            console.log('loadContacts error:', xhr.responseText);
        }
    });
}

function renderMessages(messages) {
    const chatBox = $('#chatMessages');
    const chatEl = chatBox[0];
    const wasNearBottom = isChatScrolledNearBottom();
    const previousScrollTop = chatEl ? chatEl.scrollTop : 0;

    if (!messages || !messages.length) {
        chatBox.html('<div class="text-muted">Zatiaľ žiadne správy.</div>');
        return;
    }

    let html = '';
    let lastMessageId = 0;

    messages.forEach(function(msg) {
        lastMessageId = msg.id;

        html += `
            <div class="chat-message-row ${msg.is_own ? 'own' : ''}">
                <div class="chat-bubble">
                    <div>${escapeHtml(msg.message_text)}</div>
                    <div class="chat-meta">
                        ${formatDateEU(msg.created_at)}
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
        markThreadRead(currentThreadId, lastMessageId);
        clearContactUnread(currentThreadId);
        stopTitleBlink();
    }
}

function loadMessages(threadId) {
    $.ajax({
        url: 'scripts/chat/get_messages.php',
        method: 'GET',
        dataType: 'json',
        data: { thread_id: threadId },
        success: function(res) {
            if (!res || res.status !== 'success') return;
            renderMessages(res.messages || []);
        },
        error: function(xhr) {
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
        success: function(res) {
            if (!res || res.status !== 'success' || !res.thread) {
                return;
            }

            currentThreadId = parseInt(res.thread.id, 10);
            $('#chatThreadId').val(currentThreadId);
            $('#chatMessageInput').prop('disabled', false);
            $('#chatSendBtn').prop('disabled', false);
            $('#chatEmojiToggle').prop('disabled', false);

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
                if (!currentChatUserName || currentChatUserName === 'Vyber kolegu') {
                    renderChatHeader({
                        name: res.thread.title || 'Konverzácia',
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
        error: function(xhr) {
            console.log('get_thread error:', xhr.responseText);
        }
    });
}

function openDmWithUser(userId, userName, userPhoto = '') {
    currentChatUserId = parseInt(userId, 10);
    currentChatUserName = userName || 'Konverzácia';

    setActiveContact(currentChatUserId);

    const activeContact = $('.chat-contact[data-user-id="' + currentChatUserId + '"]');

    renderChatHeader({
        id: currentChatUserId,
        name: currentChatUserName,
        photo: userPhoto,
        department_name: activeContact.attr('data-department-name') || '',
        status_label: activeContact.attr('data-status-label') || '',
        status_icon: activeContact.attr('data-status-icon') || '',
        status_bg: activeContact.attr('data-status-bg') || 'bg-dark'
    });

    $('#chatMessages').html('<div class="text-muted">Načítavam konverzáciu...</div>');
    $('#chatMessageInput').prop('disabled', false);
    $('#chatSendBtn').prop('disabled', false);
    $('#chatEmojiToggle').prop('disabled', false);

    $.ajax({
        url: 'scripts/chat/start_dm.php',
        method: 'POST',
        dataType: 'json',
        data: { other_user_id: userId },
        success: function(res) {
            if (!res || res.status !== 'success') {
                alert((res && res.message) ? res.message : 'Nepodarilo sa otvoriť chat');
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
        error: function(xhr, status, error) {
            console.log('start_dm AJAX ERROR');
            console.log('status:', status);
            console.log('error:', error);
            console.log('responseText:', xhr.responseText);

            alert('Chyba pri otváraní chatu. Pozri Console.');
        }
    });
}

function sendMessage() {
    const threadId = $('#chatThreadId').val();
    const messageText = $('#chatMessageInput').val().trim();

    if (!threadId || !messageText) return;

    $.ajax({
        url: 'scripts/chat/send_message.php',
        method: 'POST',
        dataType: 'json',
        data: {
            thread_id: threadId,
            message_text: messageText
        },
        success: function(res) {
            if (!res || res.status !== 'success') {
                alert((res && res.message) ? res.message : 'Správu sa nepodarilo odoslať');
                return;
            }

            $('#chatMessageInput').val('');
            toggleEmojiPicker(false);
            forceScrollOnNextRender = true;
            loadMessages(threadId);
            loadContacts($('#chatSearch').val());
        },
        error: function(xhr) {
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
        }
    });
}

function checkForIncomingMessages() {
    $.ajax({
        url: 'scripts/chat/check_updates.php',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (!res || res.status !== 'success' || !Array.isArray(res.threads)) {
                return;
            }

            let shouldReloadCurrentThread = false;
            let currentSearch = $('#chatSearch').val() || '';

            res.threads.forEach(function(thread) {
                let threadId = parseInt(thread.thread_id || 0, 10);
                let lastMessageId = parseInt(thread.last_message_id || 0, 10);
                let unreadCount = parseInt(thread.unread_count || 0, 10);
                let senderName = thread.sender_name || 'kolegu';
                let isCurrentThread = threadId === parseInt(currentThreadId || 0, 10);

                if (!threadId || !lastMessageId) return;

                if (initializedThreadIds[threadId] !== true) {
                    knownLastMessageIds[threadId] = lastMessageId;
                    initializedThreadIds[threadId] = true;
                } else if (lastMessageId > (knownLastMessageIds[threadId] || 0)) {
                    knownLastMessageIds[threadId] = lastMessageId;

                    if (isCurrentThread) {
                        if (suppressNextIncomingSoundForThreadId === threadId) {
                            suppressNextIncomingSoundForThreadId = null;
                        } else {
                            playNotificationSound();
                        }

                        forceScrollOnNextRender = isChatScrolledNearBottom();
                        shouldReloadCurrentThread = true;
                    } else {
                        triggerIncomingNotification(senderName, threadId);
                        markContactUnread(threadId, unreadCount);
                    }
                } else {
                    knownLastMessageIds[threadId] = lastMessageId;
                }

                if (unreadCount > 0 && !isCurrentThread) {
                    markContactUnread(threadId, unreadCount);
                }

                if (isCurrentThread) {
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
        error: function(xhr) {
            console.log('checkForIncomingMessages error:', xhr.responseText);
        }
    });
}

function startPolling() {
    if (chatPollInterval) {
        clearInterval(chatPollInterval);
    }

    chatPollInterval = setInterval(function() {
        checkForIncomingMessages();
    }, 5000);
}

$(document).ready(function() {
    renderEmojiPicker();
    loadContacts();
    checkForIncomingMessages();
    startPolling();

    $(document).on('click keydown mousedown', function() {
        userHasInteracted = true;
    });

    $('#chatSearch').on('keyup', function() {
        loadContacts($(this).val());
    });

    $('#chatEmojiToggle').on('click', function(e) {
        e.preventDefault();
        if ($(this).prop('disabled')) return;
        toggleEmojiPicker();
    });

    $(document).on('click', '.chat-emoji-btn', function(e) {
        e.preventDefault();
        insertEmojiToMessage($(this).data('emoji') || '');
    });

    $(document).on('click', function(e) {
        const $target = $(e.target);
        if (!$target.closest('#chatEmojiPicker').length && !$target.closest('#chatEmojiToggle').length) {
            toggleEmojiPicker(false);
        }
    });

    $(document).on('click', '.chat-contact', function() {
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        const userPhoto = $(this).attr('data-user-photo') || '';

        openDmWithUser(userId, userName, userPhoto);
    });

    $('#chatSendForm').on('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });

    $('#chatMessageInput').on('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    $(window).on('focus', function() {
        if (currentThreadId) {
            stopTitleBlink();
            forceScrollOnNextRender = false;
            loadMessages(currentThreadId);
        }
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && currentThreadId) {
            stopTitleBlink();
            forceScrollOnNextRender = false;
            loadMessages(currentThreadId);
        }
    });

    const preselectedThreadId = getUrlParam('thread_id');
    if (preselectedThreadId) {
        currentThreadId = parseInt(preselectedThreadId, 10);
        $('#chatThreadId').val(currentThreadId);
        $('#chatMessageInput').prop('disabled', false);
        $('#chatSendBtn').prop('disabled', false);
        $('#chatEmojiToggle').prop('disabled', false);

        setTimeout(function() {
            populateHeaderFromContactByThread(currentThreadId);
        }, 300);

        shouldAutoScrollOnNextRender = true;
        loadThreadInfo(currentThreadId);
        loadMessages(currentThreadId);
    } else {
        renderChatHeader(null);
    }
});
</script>
