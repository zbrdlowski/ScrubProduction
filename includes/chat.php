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
            <div class="card-header">
                <div class="d-flex align-items-center w-100">
                    <img id="chatHeaderPhoto"
                         src="images/profile.jpg"
                         alt="Používateľ"
                         class="img-circle mr-2"
                         style="width:36px; height:36px; object-fit:cover; display:none;">
                    <div class="flex-grow-1">
                        <h3 class="card-title mb-0" id="chatThreadTitle">Vyber kolegu</h3>
                        <div id="chatHeaderMeta" class="text-muted small mt-1"></div>
                    </div>
                </div>
            </div>

            <div class="card-body p-3 chat-panel-height" id="chatMessages" style="overflow-y: auto; background: #1f2d3d;">
                <div class="text-muted">Zatiaľ nie je otvorená žiadna konverzácia.</div>
            </div>

            <div class="card-footer">
                <form id="chatSendForm" autocomplete="off">
                    <input type="hidden" id="chatThreadId" name="thread_id" value="">
                    <div class="input-group">
                        <input type="text" id="chatMessageInput" name="message_text" class="form-control" placeholder="Napíš správu..." disabled>
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-primary" id="chatSendBtn" disabled>Odoslať</button>
                        </div>
                    </div>
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
    border-color: rgba(220,53,69,0.55);
    background: rgba(220,53,69,0.18);
    animation: pulseUnread 1.2s infinite;
}

@keyframes pulseUnread {
    0% { box-shadow: 0 0 0 0 rgba(220,53,69,0.60); }
    70% { box-shadow: 0 0 0 10px rgba(220,53,69,0); }
    100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); }
}

.chat-unread-badge {
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 8px;
    box-shadow: 0 0 8px rgba(220,53,69,0.45);
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
.chat-contact {
    display: flex;
    align-items: center;
    gap: 10px;
}

.chat-contact-status {
    margin-left: auto;
    font-size: 16px;
    color: #343a40; /* tmavá ikonka */
    opacity: 0.9;
}
</style>

<script>
function getUrlParam(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name);
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = text || '';
    return div.innerHTML;
}

function escapeAttr(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
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

function renderChatHeader(user) {
    if (!user) {
        $('#chatHeaderPhoto').hide();
        $('#chatThreadTitle').text('Vyber kolegu');
        $('#chatHeaderMeta').html('');
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

    let statusLabel = user.status_label || '';
    let statusIcon = user.status_icon || '';
    let dept = user.department_name || '';

    let metaHtml = '';

    if (statusLabel) {
        metaHtml += `${statusIcon ? `<i class="fas ${escapeHtml(statusIcon)} mr-1"></i>` : ''}${escapeHtml(statusLabel)}`;
    }

    if (dept) {
        metaHtml += `${metaHtml ? ' • ' : ''}${escapeHtml(dept)}`;
    }

    $('#chatHeaderMeta').html(metaHtml);
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
        department_name: '',
        status_label: '',
        status_icon: ''
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
        notifyAudio = new Audio('sounds/notification.mp3');
        notifyAudio.preload = 'auto';
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

function markContactUnread(threadId, unreadCount) {
    const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');

    if (!contact.length) return;

    contact.addClass('unread');

    contact.find('.chat-unread-badge').remove();

    if (unreadCount > 0) {
        contact.find('.chat-contact-name').append(`<span class="chat-unread-badge">${parseInt(unreadCount, 10)}</span>`);
    }
}

function clearContactUnread(threadId) {
    const contact = $('.chat-contact[data-thread-id="' + threadId + '"]');

    if (!contact.length) return;

    contact.removeClass('unread');
    contact.find('.chat-unread-badge').remove();
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

            if (!res.contacts || !res.contacts.length) {
                html = '<div class="text-muted p-2">Žiadni kolegovia.</div>';
            } else {
                res.contacts.forEach(function(user) {
                    let photo = buildPhotoPath(user.photo);
                    let dept = user.department_name ? user.department_name : '';
                    let statusLabel = user.status_label ? user.status_label : 'Unknown';
                    let statusBg = user.status_bg ? user.status_bg : 'bg-secondary';
                    let statusIcon = user.status_icon ? user.status_icon : 'fa-question';
                    let isActive = currentChatUserId && parseInt(currentChatUserId, 10) === parseInt(user.id, 10);
                    let unreadCount = parseInt(user.unread_count || 0, 10);
                    let threadId = parseInt(user.thread_id || 0, 10);

                    html += `
                    <div class="chat-contact ${statusBg} ${isActive ? 'active' : ''} ${unreadCount > 0 ? 'unread' : ''}"
                        data-user-id="${parseInt(user.id, 10)}"
                        data-user-name="${escapeAttr(user.name)}"
                        data-user-photo="${escapeAttr(user.photo || '')}"
                        data-thread-id="${threadId}"
                        style="color:#fff;">

                        <img src="${escapeAttr(photo)}" alt="" onerror="this.src='images/profile.jpg';">

                        <div class="flex-grow-1">
                            <div class="chat-contact-name">
                                ${escapeHtml(user.name)}
                                ${unreadCount > 0 ? `<span class="chat-unread-badge">${unreadCount}</span>` : ''}
                            </div>

                            <div class="chat-contact-meta text-white">
                                ${dept ? escapeHtml(dept) : ''}
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
    if (!messages || !messages.length) {
        $('#chatMessages').html('<div class="text-muted">Zatiaľ žiadne správy.</div>');
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
                        ${escapeHtml(msg.sender_name)} • ${escapeHtml(msg.created_at)}
                    </div>
                </div>
            </div>
        `;
    });

    $('#chatMessages').html(html);
    $('#chatMessages').scrollTop($('#chatMessages')[0].scrollHeight);

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
                // Ak backend nevie určiť other_user, NEPREPISUJ už správne nastavený header placeholderom
                if (!currentChatUserName || currentChatUserName === 'Vyber kolegu') {
                    renderChatHeader({
                        name: res.thread.title || 'Konverzácia',
                        photo: '',
                        department_name: '',
                        status_label: '',
                        status_icon: ''
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

    renderChatHeader({
        id: currentChatUserId,
        name: currentChatUserName,
        photo: userPhoto,
        department_name: '',
        status_label: '',
        status_icon: ''
    });

    $('#chatMessages').html('<div class="text-muted">Načítavam konverzáciu...</div>');
    $('#chatMessageInput').prop('disabled', false);
    $('#chatSendBtn').prop('disabled', false);

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

            res.threads.forEach(function(thread) {
                let threadId = parseInt(thread.thread_id || 0, 10);
                let lastMessageId = parseInt(thread.last_message_id || 0, 10);
                let unreadCount = parseInt(thread.unread_count || 0, 10);
                let senderName = thread.sender_name || 'kolegu';

                if (!threadId || !lastMessageId) return;

                if (initializedThreadIds[threadId] !== true) {
                    knownLastMessageIds[threadId] = lastMessageId;
                    initializedThreadIds[threadId] = true;
                } else if (lastMessageId > (knownLastMessageIds[threadId] || 0)) {
                    knownLastMessageIds[threadId] = lastMessageId;

                    if (threadId !== parseInt(currentThreadId || 0, 10)) {
                        triggerIncomingNotification(senderName, threadId);
                        markContactUnread(threadId, unreadCount);
                    } else {
                        loadMessages(threadId);
                    }
                } else {
                    knownLastMessageIds[threadId] = lastMessageId;
                }

                if (unreadCount > 0 && threadId !== parseInt(currentThreadId || 0, 10)) {
                    markContactUnread(threadId, unreadCount);
                }

                if (threadId === parseInt(currentThreadId || 0, 10)) {
                    clearContactUnread(threadId);
                }
            });

            loadContacts($('#chatSearch').val());
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
        if (currentThreadId) {
            loadMessages(currentThreadId);
        }
        //checkForIncomingMessages();
    }, 5000);
}

$(document).ready(function() {
    loadContacts();
        // checkForIncomingMessages();
        startPolling();

    $(document).on('click keydown mousedown', function() {
        userHasInteracted = true;
    });

    $('#chatSearch').on('keyup', function() {
        loadContacts($(this).val());
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
            loadMessages(currentThreadId);
        }
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && currentThreadId) {
            stopTitleBlink();
            loadMessages(currentThreadId);
        }
    });

    const preselectedThreadId = getUrlParam('thread_id');
if (preselectedThreadId) {
    currentThreadId = parseInt(preselectedThreadId, 10);
    $('#chatThreadId').val(currentThreadId);
    $('#chatMessageInput').prop('disabled', false);
    $('#chatSendBtn').prop('disabled', false);

    // skús header doplniť hneď po načítaní kontaktov
    setTimeout(function() {
        populateHeaderFromContactByThread(currentThreadId);
    }, 300);

    loadThreadInfo(currentThreadId);
    loadMessages(currentThreadId);
} else {
    renderChatHeader(null);
}
});
</script>