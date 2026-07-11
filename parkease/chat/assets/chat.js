/**
 * ParkChat — Frontend WebSocket Chat Widget
 */

(function () {
    'use strict';

    // ── Config ────────────────────────────────────────────────────
    const CFG     = window.PARKCHAT_CONFIG || {};
    const USER_ID = CFG.userId  || 0;
    const WS_URL  = CFG.wsUrl   || 'ws://localhost:8080';
    const API     = CFG.apiBase || '/chat/api';

    if (!USER_ID) return;

    // ── State ─────────────────────────────────────────────────────
    let ws             = null;
    let wsReady        = false;
    let reconnectTimer = null;
    let reconnectDelay = 2000;
    let heartbeatTimer = null;
    let typingTimer    = null;
    let isTyping       = false;
    let activeConvId   = null;
    let activePage     = 1;
    let activeHasMore  = false;
    let conversations  = [];
    let totalUnread    = 0;

    // In-memory message cache per conversation
    // Stores WS messages received while conversation is not open
    const messageCache = {};

    // ── DOM references ────────────────────────────────────────────
    let $widget, $toggle, $badgeGlobal;
    let $inbox, $convView;
    let $convList, $msgWrap, $typingEl, $inputTA, $sendBtn;
    let $wsStatus, $chatHeaderName, $chatHeaderStatus, $onlineDot;

    // ── Build HTML ────────────────────────────────────────────────
    function inject() {
        const html = `
        <button id="parkChat-toggle" title="Open Chat" aria-label="Open chat">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
            <span class="badge" id="pcGlobalBadge"></span>
        </button>

        <div id="parkChat-widget" role="dialog" aria-label="Chat">

            <div class="pc-header">
                <div class="pc-online-dot" id="pcOnlineDot"></div>
                <div class="pc-header-title">
                    <h3>ParkChat</h3>
                    <span class="pc-status-text" id="pcStatusText">Connecting…</span>
                </div>
                <button class="pc-close-btn" id="pcCloseBtn" title="Close chat">✕</button>
            </div>

            <div class="pc-ws-status connecting hidden" id="pcWsStatus">Connecting…</div>

            <!-- INBOX VIEW -->
            <div class="pc-view" id="pcInboxView">
                <div class="pc-search">
                    <input type="text" id="pcSearch" placeholder="🔍  Search conversations…" autocomplete="off">
                </div>
                <div class="pc-inbox" id="pcConvList">
                    <div class="pc-empty">
                        <i class="fas fa-comments"></i>
                        <p>No conversations yet.<br>Start chatting from a parking space page.</p>
                    </div>
                </div>
            </div>

            <!-- CONVERSATION VIEW -->
            <div class="pc-view hidden" id="pcConvView">
                <div class="pc-chat-header">
                    <button class="pc-back-btn" id="pcBackBtn" title="Back">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="pc-avatar">
                        <div class="pc-avatar-img" id="pcChatAvatar">?</div>
                        <div class="pc-online-dot" id="pcChatOnlineDot"></div>
                    </div>
                    <div class="pc-chat-header-info">
                        <strong id="pcChatName">–</strong>
                        <span class="pc-chat-status" id="pcChatStatus">offline</span>
                    </div>
                </div>

                <div class="pc-messages-wrap" id="pcMsgWrap">
                    <!-- pcLoadMore lives here permanently — never moved or removed -->
                    <div class="pc-load-more hidden" id="pcLoadMore">
                        <button id="pcLoadMoreBtn">Load earlier messages</button>
                    </div>
                </div>

                <div class="pc-typing-indicator" id="pcTypingEl"></div>

                <div class="pc-input-area">
                    <textarea id="pcInput" rows="1" placeholder="Type a message…" maxlength="5000"></textarea>
                    <button class="pc-send-btn" id="pcSendBtn" title="Send" disabled>
                        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </div>
            </div>

        </div>`;

        document.body.insertAdjacentHTML('beforeend', html);

        $widget           = document.getElementById('parkChat-widget');
        $toggle           = document.getElementById('parkChat-toggle');
        $badgeGlobal      = document.getElementById('pcGlobalBadge');
        $inbox            = document.getElementById('pcInboxView');
        $convView         = document.getElementById('pcConvView');
        $convList         = document.getElementById('pcConvList');
        $msgWrap          = document.getElementById('pcMsgWrap');
        $typingEl         = document.getElementById('pcTypingEl');
        $inputTA          = document.getElementById('pcInput');
        $sendBtn          = document.getElementById('pcSendBtn');
        $wsStatus         = document.getElementById('pcWsStatus');
        $chatHeaderName   = document.getElementById('pcChatName');
        $chatHeaderStatus = document.getElementById('pcChatStatus');
        $onlineDot        = document.getElementById('pcChatOnlineDot');
    }

    // ── Event Wiring ──────────────────────────────────────────────
    function wireEvents() {
        $toggle.addEventListener('click', () => toggleWidget());
        document.getElementById('pcCloseBtn').addEventListener('click', () => closeWidget());
        document.getElementById('pcBackBtn').addEventListener('click', showInbox);

        document.getElementById('pcSearch').addEventListener('input', function () {
            filterConversations(this.value.trim().toLowerCase());
        });

        $inputTA.addEventListener('input', onInput);
        $inputTA.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        $sendBtn.addEventListener('click', sendMessage);

        document.getElementById('pcLoadMoreBtn').addEventListener('click', () => {
            if (activeConvId && activeHasMore) {
                loadMessages(activeConvId, activePage + 1, true);
            }
        });
    }

    // ── WebSocket ─────────────────────────────────────────────────
    function connectWS() {
        setWsStatus('connecting');
        ws = new WebSocket(WS_URL);

        ws.onopen = () => {
            wsReady        = true;
            reconnectDelay = 2000;
            setWsStatus('connected');
            wsSend({ type: 'auth', userId: USER_ID });
            heartbeatTimer = setInterval(() => {
                wsSend({ type: 'heartbeat' });
                restHeartbeat();
            }, 30000);
        };

        ws.onmessage = ({ data }) => {
            try { handleWsMessage(JSON.parse(data)); }
            catch (e) { console.warn('[ParkChat] WS parse error', e); }
        };

        ws.onclose = () => {
            wsReady = false;
            clearInterval(heartbeatTimer);
            setWsStatus('disconnected');
            loadConversations();
            reconnectTimer = setTimeout(() => {
                reconnectDelay = Math.min(reconnectDelay * 1.5, 30000);
                connectWS();
            }, reconnectDelay);
        };

        ws.onerror = () => ws.close();
    }

    function wsSend(obj) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(obj));
        }
    }

    function handleWsMessage(msg) {
        switch (msg.type) {
            case 'auth_ok':       loadConversations();   break;
            case 'new_message':   onNewMessage(msg);     break;
            case 'typing':        onTypingEvent(msg);    break;
            case 'messages_read': onMessagesRead(msg);   break;
            case 'presence':      onPresenceUpdate(msg); break;
        }
    }

    // ── WS event handlers ─────────────────────────────────────────

    function onNewMessage(msg) {
        // Decode HTML entities from PHP htmlspecialchars
        const cleanMessage = decodeEntities(msg.message);

        // Cache ALL incoming messages so they survive navigation
        if (!messageCache[msg.conversationId]) {
            messageCache[msg.conversationId] = [];
        }
        // Avoid duplicates in cache
        const alreadyCached = messageCache[msg.conversationId]
            .some(m => m.id === msg.messageId);
        if (!alreadyCached) {
            messageCache[msg.conversationId].push({
                id:         msg.messageId,
                sender_id:  msg.senderId,
                message:    cleanMessage,
                created_at: msg.sentAt,
                is_read:    false,
                is_mine:    msg.senderId === USER_ID,
            });
        }

        if (msg.conversationId === activeConvId) {
            // Remove optimistic temp bubble — prevents double display
            if (msg.senderId === USER_ID) {
                $msgWrap.querySelectorAll('[data-msg-id^="temp_"]')
                    .forEach(el => el.remove());
            }
            appendMessage({
                id:         msg.messageId,
                sender_id:  msg.senderId,
                message:    cleanMessage,
                created_at: msg.sentAt,
                is_read:    false,
                is_mine:    msg.senderId === USER_ID,
            });
            scrollToBottom();
            if (msg.senderId !== USER_ID) markRead(activeConvId);
        } else {
            bumpConvUnread(msg.conversationId, cleanMessage, msg.sentAt);
            updateGlobalBadge(++totalUnread);
        }

        updateConvPreview(msg.conversationId, cleanMessage, msg.sentAt);
    }

    function onTypingEvent(msg) {
        if (msg.conversationId !== activeConvId) return;
        if (msg.isTyping) {
            $typingEl.innerHTML = `
                <div class="pc-typing-dots"><span></span><span></span><span></span></div>
                <span>${safeText(msg.name)} is typing…</span>`;
        } else {
            $typingEl.innerHTML = '';
        }
    }

    function onMessagesRead(msg) {
        if (msg.conversationId !== activeConvId) return;
        document.querySelectorAll('.pc-tick').forEach(el => {
            el.classList.add('read');
            el.textContent = '✓✓';
        });
    }

    function onPresenceUpdate(msg) {
        const conv = conversations.find(c => c.other_id == msg.userId);
        if (conv) conv.is_online = msg.isOnline;

        if (activeConvId) {
            const activeConv = conversations.find(c => c.conversation_id === activeConvId);
            if (activeConv && activeConv.other_id == msg.userId) {
                setActiveOnlineStatus(msg.isOnline);
            }
        }

        const convItem = document.querySelector(
            `[data-conv-id="${getConvIdByUserId(msg.userId)}"]`
        );
        if (convItem) {
            const dot = convItem.querySelector('.pc-online-dot');
            if (dot) dot.classList.toggle('online', msg.isOnline);
        }
    }

    // ── API calls ─────────────────────────────────────────────────

    async function loadConversations() {
        try {
            const res = await fetch(`${API}/get_conversations.php`, {
                method:      'GET',
                credentials: 'include',
                headers:     { 'Accept': 'application/json' }
            });

            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('[ParkChat] API returned non-JSON response');
                return;
            }

            const data = await res.json();
            if (!data.success) return;

            conversations = data.conversations;
            totalUnread   = conversations.reduce(
                (sum, c) => sum + (c.unread_count || 0), 0
            );
            updateGlobalBadge(totalUnread);
            renderInbox(conversations);
        } catch (e) {
            console.warn('[ParkChat] Failed to load conversations', e);
        }
    }

    async function loadMessages(convId, page = 1, prepend = false) {
        try {
            const res = await fetch(
                `${API}/get_messages.php?conversation_id=${convId}&page=${page}`, {
                method:      'GET',
                credentials: 'include',
                headers:     { 'Accept': 'application/json' }
            });

            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('[ParkChat] Messages API returned non-JSON');
                return;
            }

            const data = await res.json();
            if (!data.success) return;

            activePage    = page;
            activeHasMore = data.hasMore;

            // Show/hide the Load Earlier button
            const $loadMore = document.getElementById('pcLoadMore');
            $loadMore.classList.toggle('hidden', !data.hasMore);

            // Decode HTML entities on every message from DB
            const dbMessages = data.messages.map(m => ({
                ...m,
                message: decodeEntities(m.message)
            }));

            if (prepend) {
                // Loading older messages — insert above existing ones
                // Save scroll position so page doesn't jump
                const prevScrollHeight = $msgWrap.scrollHeight;
                dbMessages.forEach(m => prependMessage(m));
                $msgWrap.scrollTop = $msgWrap.scrollHeight - prevScrollHeight;

            } else {
                // Initial load — merge DB messages with any cached WS messages
                const cached = messageCache[convId] || [];
                const dbIds  = new Set(dbMessages.map(m => String(m.id)));

                // Only include cached messages not already in DB response
                const extraFromCache = cached.filter(
                    m => !dbIds.has(String(m.id))
                );

                // Combine and sort chronologically
                const allMessages = [...dbMessages, ...extraFromCache].sort(
                    (a, b) => new Date(a.created_at) - new Date(b.created_at)
                );

                // Clear cache for this conversation — DB is now the source of truth
                delete messageCache[convId];

                // Remove only message bubbles — NOT the pcLoadMore element
                clearMessages();

                // Render all messages
                allMessages.forEach(m => appendMessage(m));
                scrollToBottom();
            }

        } catch (e) {
            console.warn('[ParkChat] Failed to load messages', e);
        }
    }

    async function sendMessage() {
        const text = $inputTA.value.trim();
        if (!text || !activeConvId) return;

        $inputTA.value = '';
        resizeTextarea();
        $sendBtn.disabled = true;
        clearTyping();

        // Optimistic render — tagged temp_ so WS confirm removes it
        appendMessage({
            id:         'temp_' + Date.now(),
            sender_id:  USER_ID,
            message:    text,
            created_at: new Date().toISOString().replace('T', ' ').slice(0, 19),
            is_read:    false,
            is_mine:    true,
        });
        scrollToBottom();

        if (wsReady) {
            wsSend({ type: 'message', conversationId: activeConvId, message: text });
        } else {
            try {
                await fetch(`${API}/send_message.php`, {
                    method:      'POST',
                    credentials: 'include',
                    headers:     { 'Content-Type': 'application/json' },
                    body:        JSON.stringify({
                        conversation_id: activeConvId,
                        message:         text
                    }),
                });
                loadMessages(activeConvId, 1, false);
            } catch (e) {
                console.warn('[ParkChat] REST send failed', e);
            }
        }

        $sendBtn.disabled = false;
        $inputTA.focus();
    }

    async function markRead(convId) {
        if (wsReady) {
            wsSend({ type: 'join_conversation', conversationId: convId });
        }
        fetch(`${API}/mark_read.php`, {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ conversation_id: convId }),
        });

        const conv = conversations.find(c => c.conversation_id === convId);
        if (conv && conv.unread_count > 0) {
            totalUnread -= conv.unread_count;
            conv.unread_count = 0;
            updateGlobalBadge(Math.max(0, totalUnread));
            const badge = document.querySelector(
                `[data-conv-id="${convId}"] .pc-unread-badge`
            );
            if (badge) badge.remove();
        }
    }

    function restHeartbeat() {
        fetch(`${API}/heartbeat.php`, {
            method:      'POST',
            credentials: 'include'
        });
    }

    // ── Rendering ─────────────────────────────────────────────────

    function renderInbox(convs) {
        if (!convs.length) {
            $convList.innerHTML = `
                <div class="pc-empty">
                    <i class="fas fa-comments"></i>
                    <p>No conversations yet.<br>Start chatting from a parking space page.</p>
                </div>`;
            return;
        }

        $convList.innerHTML = convs.map(c => buildConvItem(c)).join('');

        $convList.querySelectorAll('.pc-conv-item').forEach(el => {
            el.addEventListener('click', () =>
                openConversation(parseInt(el.dataset.convId))
            );
        });
    }

    function buildConvItem(c) {
        const initials  = (c.other_first[0] + (c.other_last[0] || '')).toUpperCase();
        const online    = c.is_online ? 'online' : '';
        const time      = c.last_message_at ? formatTime(c.last_message_at) : '';
        const badge     = c.unread_count > 0
            ? `<span class="pc-unread-badge">${c.unread_count}</span>`
            : '';
        const roleBadge = `<span class="pc-role-badge ${c.other_type}">${c.other_type}</span>`;
        const photo     = c.other_photo
            ? `<img src="${escAttr(c.other_photo)}" alt="">`
            : initials;

        const item = document.createElement('div');
        item.className      = 'pc-conv-item';
        item.dataset.convId = c.conversation_id;
        item.innerHTML = `
            <div class="pc-avatar">
                <div class="pc-avatar-img">${photo}</div>
                <div class="pc-online-dot ${online}"></div>
            </div>
            <div class="pc-conv-info">
                <div class="pc-conv-name">
                    <span class="pc-conv-name-text"></span>
                    ${roleBadge}
                    <span class="pc-conv-time">${time}</span>
                </div>
                <div class="pc-conv-preview">
                    <span class="pc-conv-preview-text"></span>
                    ${badge}
                </div>
            </div>`;

        // textContent safely handles apostrophes and all special characters
        item.querySelector('.pc-conv-name-text').textContent =
            c.other_first + ' ' + c.other_last;
        item.querySelector('.pc-conv-preview-text').textContent =
            decodeEntities(c.last_message || 'Start a conversation');

        return item.outerHTML;
    }

    function openConversation(convId) {
        activeConvId = convId;
        activePage   = 1;
    
        const conv = conversations.find(c => c.conversation_id === convId);
        if (!conv) return;
    
        $chatHeaderName.textContent = conv.other_first + ' ' + conv.other_last;
    
        // Check live presence — fetch fresh status instead of
        // relying on cached conversations data which may be stale
        checkPresence(conv.other_id);
    
        const initials = (conv.other_first[0] + (conv.other_last[0] || '')).toUpperCase();
        document.getElementById('pcChatAvatar').innerHTML = conv.other_photo
            ? `<img src="${escAttr(conv.other_photo)}" alt="">`
            : initials;
    
        $inbox.classList.add('hidden');
        $convView.classList.remove('hidden');
    
        const cached = messageCache[convId] || [];
        clearMessages();
        if (cached.length > 0) {
            cached.forEach(m => appendMessage(m));
            scrollToBottom();
        }
    
        loadMessages(convId, 1, false);
        markRead(convId);
        wsSend({ type: 'join_conversation', conversationId: convId });
        $inputTA.focus();
    }

    async function checkPresence(otherUserId) {
        try {
            const res = await fetch(
                `${API}/get_conversations.php`, {
                method:      'GET',
                credentials: 'include',
                headers:     { 'Accept': 'application/json' }
            });
    
            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) return;
    
            const data = await res.json();
            if (!data.success) return;
    
            // Find the conversation with this user and get fresh online status
            const conv = data.conversations.find(c => c.other_id == otherUserId);
            if (conv) {
                setActiveOnlineStatus(conv.is_online);
    
                // Also update our local conversations array
                const localConv = conversations.find(c => c.other_id == otherUserId);
                if (localConv) localConv.is_online = conv.is_online;
    
                // Update inbox dot
                const convItem = document.querySelector(
                    `[data-conv-id="${conv.conversation_id}"] .pc-online-dot`
                );
                if (convItem) convItem.classList.toggle('online', conv.is_online);
            }
        } catch (e) {
            console.warn('[ParkChat] Presence check failed', e);
        }
    }

    function showInbox() {
        activeConvId = null;
        $convView.classList.add('hidden');
        $inbox.classList.remove('hidden');
        $typingEl.innerHTML = '';
        loadConversations();
    }

    // ── Message DOM helpers ───────────────────────────────────────

    // Safely clear ONLY message bubbles — never touches pcLoadMore
    function clearMessages() {
        Array.from($msgWrap.children).forEach(el => {
            if (!el.id || el.id !== 'pcLoadMore') {
                el.remove();
            }
        });
    }

    function appendMessage(msg) {
        // Append BEFORE the end but AFTER pcLoadMore is already at top
        $msgWrap.appendChild(buildMsgEl(msg));
    }

    function prependMessage(msg) {
        // Insert after pcLoadMore (which stays at top) but before all messages
        const $loadMore = document.getElementById('pcLoadMore');
        $loadMore.insertAdjacentElement('afterend', buildMsgEl(msg));
    }

    function buildMsgEl(msg) {
        const isMine = msg.is_mine || msg.sender_id === USER_ID;
        const side   = isMine ? 'mine' : 'theirs';
        const time   = formatTime(msg.created_at);

        const div = document.createElement('div');
        div.className     = `pc-msg ${side}`;
        div.dataset.msgId = String(msg.id);

        // textContent prevents ALL encoding issues including apostrophes
        const bubble = document.createElement('div');
        bubble.className   = 'pc-msg-bubble';
        bubble.textContent = msg.message;

        const meta = document.createElement('div');
        meta.className = 'pc-msg-meta';

        const timeSpan = document.createElement('span');
        timeSpan.textContent = time;
        meta.appendChild(timeSpan);

        if (isMine) {
            const tick = document.createElement('span');
            tick.className   = `pc-tick ${msg.is_read ? 'read' : 'sent'}`;
            tick.textContent = msg.is_read ? '✓✓' : '✓';
            meta.appendChild(tick);
        }

        div.appendChild(bubble);
        div.appendChild(meta);
        return div;
    }

    // ── Input handling ────────────────────────────────────────────

    function onInput() {
        resizeTextarea();
        $sendBtn.disabled = $inputTA.value.trim() === '';
        if (!isTyping) {
            isTyping = true;
            wsSend({ type: 'typing', conversationId: activeConvId, isTyping: true });
        }
        clearTimeout(typingTimer);
        typingTimer = setTimeout(clearTyping, 2500);
    }

    function clearTyping() {
        if (isTyping) {
            isTyping = false;
            wsSend({ type: 'typing', conversationId: activeConvId, isTyping: false });
        }
    }

    function resizeTextarea() {
        $inputTA.style.height = 'auto';
        $inputTA.style.height = Math.min($inputTA.scrollHeight, 120) + 'px';
    }

    // ── UI helpers ────────────────────────────────────────────────

    function toggleWidget() {
        $widget.classList.contains('open') ? closeWidget() : openWidget();
    }

    function openWidget() {
        $widget.classList.add('open');
        if (!wsReady) connectWS();
        else loadConversations();
    }

    function closeWidget() {
        $widget.classList.remove('open');
    }

    function setWsStatus(state) {
        const labels = {
            connected:    '● Connected',
            disconnected: '● Disconnected',
            connecting:   '◌ Connecting…'
        };
        $wsStatus.textContent = labels[state] || '';
        $wsStatus.className   = `pc-ws-status ${state}`;

        document.getElementById('pcStatusText').textContent =
            state === 'connected'  ? 'Online'      :
            state === 'connecting' ? 'Connecting…' : 'Offline';

        if (state === 'connected') {
            setTimeout(() => $wsStatus.classList.add('hidden'), 2000);
        } else {
            $wsStatus.classList.remove('hidden');
        }
    }

    function setActiveOnlineStatus(isOnline) {
        $onlineDot.classList.toggle('online', isOnline);
        $chatHeaderStatus.textContent = isOnline ? '● Online' : '○ Offline';
        $chatHeaderStatus.style.color = isOnline
            ? 'var(--chat-green)'
            : 'var(--chat-text-muted)';
    }

    function updateGlobalBadge(count) {
        $badgeGlobal.style.display = count > 0 ? 'flex' : 'none';
        $badgeGlobal.textContent   = count > 99 ? '99+' : count;
    }

    function bumpConvUnread(convId, preview, time) {
        const conv = conversations.find(c => c.conversation_id === convId);
        if (conv) conv.unread_count = (conv.unread_count || 0) + 1;
        const badge = document.querySelector(
            `[data-conv-id="${convId}"] .pc-unread-badge`
        );
        if (badge) {
            badge.textContent = conv ? conv.unread_count : 1;
        } else {
            const previewEl = document.querySelector(
                `[data-conv-id="${convId}"] .pc-conv-preview`
            );
            if (previewEl) {
                previewEl.insertAdjacentHTML(
                    'beforeend',
                    `<span class="pc-unread-badge">1</span>`
                );
            }
        }
    }

    function updateConvPreview(convId, text, time) {
        const previewEl = document.querySelector(
            `[data-conv-id="${convId}"] .pc-conv-preview-text`
        );
        if (previewEl) previewEl.textContent = text;
        const timeEl = document.querySelector(
            `[data-conv-id="${convId}"] .pc-conv-time`
        );
        if (timeEl) timeEl.textContent = formatTime(time);
    }

    function filterConversations(query) {
        if (!query) { renderInbox(conversations); return; }
        renderInbox(conversations.filter(c =>
            (c.other_first + ' ' + c.other_last).toLowerCase().includes(query)
        ));
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            $msgWrap.scrollTop = $msgWrap.scrollHeight;
        });
    }

    function getConvIdByUserId(userId) {
        const conv = conversations.find(c => c.other_id == userId);
        return conv ? conv.conversation_id : null;
    }

    // ── Utilities ─────────────────────────────────────────────────

    // Decodes HTML entities from PHP htmlspecialchars
    // e.g. &#039; → '   &amp; → &   &quot; → "
    function decodeEntities(str) {
        if (!str) return '';
        const txt = document.createElement('textarea');
        txt.innerHTML = str;
        return txt.value;
    }

    // For HTML attribute values only (src, href)
    function escAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;');
    }

    // For inserting plain text into innerHTML contexts
    function safeText(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function formatTime(datetime) {
        if (!datetime) return '';
        const d    = new Date(datetime);
        const now  = new Date();
        const diff = now - d;
        if (diff < 60000)     return 'Just now';
        if (diff < 3600000)   return Math.floor(diff / 60000) + 'm ago';
        if (diff < 86400000)  return d.toLocaleTimeString([], {
            hour: '2-digit', minute: '2-digit'
        });
        if (diff < 604800000) return [
            'Sun','Mon','Tue','Wed','Thu','Fri','Sat'
        ][d.getDay()];
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    // ── Public API ────────────────────────────────────────────────
    window.ParkChat = {
        openWith: async function (recipientId, parkingId = null) {
            if (!$widget) return;

            try {
                const res = await fetch(`${API}/send_message.php`, {
                    method:      'POST',
                    credentials: 'include',
                    headers:     { 'Content-Type': 'application/json' },
                    body:        JSON.stringify({
                        recipient_id: recipientId,
                        parking_id:   parkingId,
                        message:      '👋 Hi! I have a question about your parking space.',
                    }),
                });

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('[ParkChat] send_message returned non-JSON');
                    return;
                }

                const data = await res.json();
                if (!data.conversationId) return;

                openWidget();

                // Wait for conversations to fully load before opening
                await loadConversations();

                // Small delay ensures DB write is fully committed
                await new Promise(resolve => setTimeout(resolve, 300));

                openConversation(data.conversationId);

            } catch (e) {
                console.warn('[ParkChat] openWith failed', e);
            }
        }
    };

    // ── Boot ──────────────────────────────────────────────────────
    inject();
    wireEvents();
    restHeartbeat();
    connectWS();

})();