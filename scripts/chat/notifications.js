(function () {
    'use strict';
    const config = window.darkScrubChatNotificationConfig;
    if (!config || !config.userId || window.darkScrubChatNotifications) return;

    const prefix = 'darkscrub:chat:' + config.userId + ':';
    const positions = new Map();
    const tracked = new Map();
    let preferences = { toast_mode: 'persistent', sound_enabled: true, revision: -1 };
    let channel = null;
    let interacted = false;
    let audio = null;
    try {
        if ('BroadcastChannel' in window) channel = new BroadcastChannel(prefix + 'events');
    } catch (error) { /* Storage events still synchronize other windows. */ }

    function readStorage(key) {
        try { return localStorage.getItem(prefix + key); } catch (error) { return null; }
    }
    function writeStorage(key, value) {
        try { localStorage.setItem(prefix + key, value); } catch (error) { /* Private/restricted storage. */ }
    }
    function post(event) {
        if (channel) channel.postMessage(event);
    }
    function position(kind, threadId) {
        const key = kind + ':' + threadId;
        return Math.max(positions.get(key) || 0, Number(readStorage(key)) || 0);
    }
    function handled(threadId, messageId) {
        return messageId > 0 && Math.max(position('dismiss', threadId), position('read', threadId)) >= messageId;
    }
    function removeEntry(key) {
        const entry = tracked.get(key);
        if (!entry) return;
        tracked.delete(key);
        clearTimeout(entry.timer);
        entry.close();
    }
    function removeThrough(threadId, messageId) {
        tracked.forEach((entry, key) => {
            if (entry.threadId === threadId && entry.messageId <= messageId) removeEntry(key);
        });
    }
    function setPosition(kind, threadId, messageId, broadcast = true) {
        threadId = Number(threadId);
        messageId = Number(messageId);
        if (!['read', 'dismiss'].includes(kind) || !Number.isSafeInteger(threadId) || threadId <= 0
            || !Number.isSafeInteger(messageId) || messageId <= 0) return;
        const key = kind + ':' + threadId;
        const previous = positions.get(key) || 0;
        const next = Math.max(position(kind, threadId), messageId);
        positions.set(key, next);
        writeStorage(key, String(next));
        removeThrough(threadId, next);
        if (next > previous) {
            if (broadcast) post({ kind, threadId, messageId: next });
            if (kind === 'read') document.dispatchEvent(new CustomEvent('darkscrub:chat-read', {
                detail: { threadId, messageId: next }
            }));
        }
    }
    function startTimer(entry) {
        clearTimeout(entry.timer);
        if (preferences.toast_mode === 'brief') {
            entry.timer = setTimeout(() => setPosition('dismiss', entry.threadId, entry.messageId), 10000);
        }
    }
    function register(kind, threadId, messageId, close) {
        threadId = Number(threadId);
        messageId = Number(messageId);
        if (!canShow(threadId, messageId)) { close(); return; }
        const key = kind + ':' + threadId + ':' + messageId;
        if (tracked.has(key)) { close(); return; }
        const entry = { threadId, messageId, close, timer: null };
        tracked.set(key, entry);
        startTimer(entry);
    }
    function canShow(threadId, messageId) {
        return preferences.toast_mode !== 'badge' && !handled(Number(threadId), Number(messageId));
    }
    function applyPreferences(next, broadcast = false) {
        if (!next || !['persistent', 'brief', 'badge'].includes(next.toast_mode)
            || typeof next.sound_enabled !== 'boolean' || !Number.isSafeInteger(next.revision)
            || next.revision < preferences.revision) return;
        if (JSON.stringify(next) === JSON.stringify(preferences)) return;
        preferences = { toast_mode: next.toast_mode, sound_enabled: next.sound_enabled, revision: next.revision };
        writeStorage('preferences', JSON.stringify(preferences));
        tracked.forEach((entry, key) => {
            if (preferences.toast_mode === 'badge') removeEntry(key);
            else startTimer(entry);
        });
        if (broadcast) post({ kind: 'preferences', preferences });
        document.dispatchEvent(new CustomEvent('darkscrub:chat-preferences', { detail: { ...preferences } }));
    }
    function receive(event) {
        if (!event || typeof event !== 'object') return;
        if (event.kind === 'preferences') applyPreferences(event.preferences);
        else setPosition(event.kind, event.threadId, event.messageId, false);
    }
    if (channel) channel.onmessage = event => receive(event.data);
    window.addEventListener('storage', event => {
        if (!event.key || !event.key.startsWith(prefix)) return;
        const key = event.key.slice(prefix.length);
        if (key === 'preferences') {
            try { applyPreferences(JSON.parse(event.newValue)); } catch (error) { /* Ignore invalid cached data. */ }
        } else {
            const match = /^(dismiss|read):(\d+)$/.exec(key);
            if (match) setPosition(match[1], Number(match[2]), Number(event.newValue), false);
        }
    });

    // One sound/native notification per message across this user's browser windows.
    async function claim(kind, threadId, messageId, action) {
        const key = kind + ':' + threadId;
        const run = async () => {
            if (handled(threadId, messageId) || position(kind, threadId) >= messageId) return;
            if (await action() !== false) {
                positions.set(key, messageId);
                writeStorage(key, String(messageId));
            }
        };
        if (navigator.locks && navigator.locks.request) {
            await navigator.locks.request(prefix + key, run);
        } else {
            await run();
        }
    }
    async function playSound(threadId, messageId, play) {
        if (!preferences.sound_enabled || !interacted) return;
        try {
            await claim('sound', Number(threadId), Number(messageId), async () => {
                if (!preferences.sound_enabled) return false;
                if (play) return play();
                if (!audio) audio = new Audio('sounds/notification.mp3');
                audio.currentTime = 0;
                try { await audio.play(); return true; } catch (error) { return false; }
            });
        } catch (error) { /* Browser sound support is best effort. */ }
    }
    async function showBrowserNotification(threadId, messageId, title, body, icon, url) {
        threadId = Number(threadId);
        messageId = Number(messageId);
        if (!canShow(threadId, messageId) || !('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            await claim('browser', threadId, messageId, () => {
                if (!canShow(threadId, messageId)) return false;
                const notification = new Notification(title, {
                    body, icon, tag: prefix + threadId + ':' + messageId,
                    requireInteraction: preferences.toast_mode === 'persistent',
                    silent: true
                });
                register('browser', threadId, messageId, () => notification.close());
                notification.onclick = () => {
                    setPosition('dismiss', threadId, messageId);
                    window.focus();
                    window.location.href = url;
                };
                return true;
            });
        } catch (error) { /* Native notifications are best effort. */ }
    }
    async function savePreferences(mode, sound) {
        const body = new URLSearchParams({
            toast_mode: mode, sound_enabled: sound ? '1' : '0', csrf_token: config.csrfToken
        });
        const response = await fetch('scripts/chat/notification_preferences.php', {
            method: 'POST', credentials: 'same-origin', body
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Nastavenia sa nepodarilo uložiť.');
        }
        applyPreferences(result.preferences, true);
        return { ...preferences };
    }
    function syncServer(response) {
        if (!response || response.status !== 'success') return;
        if (response.notification_preferences) applyPreferences(response.notification_preferences);
        (response.read_positions || []).forEach(item => setPosition('read', item.thread_id, item.last_read_message_id));
    }
    function preview(text, type) {
        text = String(text || '');
        if (type !== 'announcement') return text;
        const template = document.createElement('template');
        template.innerHTML = text;
        template.content.querySelectorAll('script,style,iframe,object,embed,svg,math,template').forEach(node => node.remove());
        template.content.querySelectorAll('br,hr').forEach(node => node.replaceWith(' '));
        template.content.querySelectorAll('h1,h2,h3,h4,h5,h6,p,div,li,blockquote,pre')
            .forEach(node => { node.prepend(' '); node.append(' '); });
        return template.content.textContent.replace(/\s+/g, ' ').trim();
    }

    let cachedPreferences = null;
    try { cachedPreferences = JSON.parse(readStorage('preferences')); } catch (error) { /* No saved settings yet. */ }
    applyPreferences(config.preferences);
    applyPreferences(cachedPreferences);
    ['pointerdown', 'keydown', 'click'].forEach(type => {
        document.addEventListener(type, () => { interacted = true; }, { once: true, capture: true });
    });
    window.darkScrubChatNotifications = {
        getPreferences: () => ({ ...preferences }),
        applyPreferences, savePreferences, syncServer, preview, canShow, handled,
        dismiss: (threadId, messageId) => setPosition('dismiss', threadId, messageId),
        markRead: (threadId, messageId) => setPosition('read', threadId, messageId),
        registerToast: (threadId, messageId, close) => register('toast', threadId, messageId, close),
        playSound, showBrowserNotification
    };
})();
