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
}

.chat-contact:hover {
    background: rgba(255,255,255,0.08);
}

.chat-contact.active {
    border-color: rgba(255,255,255,0.25);
    background: rgba(0,123,255,0.22);
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

function renderChatHeader(user) {
    if (!user) {
        $('#chatHeaderPhoto').hide();
        $('#chatThreadTitle').text('Vyber kolegu');
        $('#chatHeaderMeta').html('');
        return;
    }

let photo = 'images/profile.jpg';

if (user.photo && user.photo.trim() !== '') {
    if (user.photo.includes('/')) {
        photo = user.photo;
    } else {
        photo = 'images/' + user.photo;
    }
}

    $('#chatHeaderPhoto')
        .attr('src', photo)
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
                    let photo = 'images/profile.jpg';

                    if (user.photo && user.photo.trim() !== '') {
                        if (user.photo.includes('/')) {
                            photo = user.photo;
                        } else {
                            photo = 'images/' + user.photo;
                        }
                    }
                    let dept = user.department_name ? user.department_name : '';
                    let statusLabel = user.status_label ? user.status_label : 'Unknown';
                    let statusBg = user.status_bg ? user.status_bg : 'bg-secondary';
                    let statusIcon = user.status_icon ? user.status_icon : 'fa-question';
                    let isActive = currentChatUserId && parseInt(currentChatUserId, 10) === parseInt(user.id, 10);

                    html += `
                        <div class="chat-contact ${statusBg} ${isActive ? 'active' : ''}"
                            data-user-id="${parseInt(user.id, 10)}"
                            data-user-name="${escapeAttr(user.name)}"
                            data-user-photo="${escapeAttr(user.photo || '')}"
                            style="color:#fff;">
                            <img src="${escapeAttr(photo)}" alt="">
                            <div class="flex-grow-1">
                                <div class="chat-contact-name">${escapeHtml(user.name)}</div>
                                <div class="chat-contact-meta text-white">
                                    <i class="fas ${escapeHtml(statusIcon)} mr-1"></i>
                                    ${escapeHtml(statusLabel)}
                                    ${dept ? ' • ' + escapeHtml(dept) : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            $('#chatContactsList').html(html);

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
        markThreadRead(currentThreadId, lastMessageId);
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
                renderChatHeader(res.thread.other_user);
                setActiveContact(currentChatUserId);
            } else {
                renderChatHeader({
                    name: res.thread.title || currentChatUserName || 'Konverzácia',
                    photo: '',
                    department_name: '',
                    status_label: '',
                    status_icon: ''
                });
            }
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

            loadThreadInfo(currentThreadId);
            loadMessages(currentThreadId);

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

function startPolling() {
    if (chatPollInterval) {
        clearInterval(chatPollInterval);
    }

    chatPollInterval = setInterval(function() {
        if (currentThreadId) {
            loadMessages(currentThreadId);
        }
    }, 5000);
}

$(document).ready(function() {
    loadContacts();
    startPolling();

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

    const preselectedThreadId = getUrlParam('thread_id');
    if (preselectedThreadId) {
        currentThreadId = parseInt(preselectedThreadId, 10);
        $('#chatThreadId').val(currentThreadId);
        $('#chatMessageInput').prop('disabled', false);
        $('#chatSendBtn').prop('disabled', false);
        loadThreadInfo(currentThreadId);
        loadMessages(currentThreadId);
    } else {
        renderChatHeader(null);
    }
});
</script>