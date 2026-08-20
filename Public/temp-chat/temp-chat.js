/* ==================== 临时聊天（布局/样式完全复用聊天室 lobby） ==================== */
(function () {
    'use strict';

    // ==================== 工具 ====================
    function escapeHtml(text) {
        let div = document.createElement('div');
        div.textContent = String(text == null ? '' : text);
        return div.innerHTML;
    }

    function escapeHtmlAttr(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function getParam(name) {
        return new URLSearchParams(location.search).get(name) || '';
    }

    function getAvatarChar(name) {
        if (!name) return '?';
        return name.charAt(0);
    }

    // 照搬聊天室实现（含 let(-- 兼容写法，无效值回退到 CSS 默认背景）
    function getAvatarColor(name) {
        if (!name) return 'let(--note-green)';
        let colors = ['let(--note-green)', 'let(--note-blue)', 'let(--note-yellow)', 'let(--note-pink)', '#d1f2d3', '#d3e2ed', '#fdf5c9', '#fde2e4'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        return colors[Math.abs(hash) % colors.length];
    }

    // ==================== DOM 引用（复用聊天室结构） ====================
    const $messages = document.getElementById('lobby-messages');
    const $chatInput = document.getElementById('lobby-chat-input');
    const $btnSend = document.getElementById('lobby-btn-send');
    const $btnSticker = document.getElementById('lobby-btn-sticker');
    const $stickerPicker = document.getElementById('lobby-sticker-picker');
    const $stickerBody = document.getElementById('lobby-sticker-picker-body');
    const $btnCloseSticker = document.getElementById('lobby-btn-close-sticker-picker');
    const $btnScrollBottom = document.getElementById('lobby-btn-scroll-bottom');
    const $lobbyMain = document.getElementById('lobby-main');
    const $matchPanel = document.getElementById('lobby-match-panel');
    const $hasIdentity = document.getElementById('lobby-has-identity');
    const $loading = document.getElementById('lobby-loading');
    const $peerBar = document.getElementById('temp-peer-bar');
    const $peerName = document.getElementById('temp-peer-name');
    const $peerAvatar = document.getElementById('temp-peer-avatar');
    const $exitBtn = document.getElementById('temp-exit-btn');
    const $reportBtn = document.getElementById('temp-report-btn');
    const $btnBack = document.getElementById('btn-back');
    const $lightbox = document.getElementById('lobby-sticker-lightbox');
    const $lightboxImg = document.getElementById('lobby-sticker-lightbox-img');
    const $lightboxClose = document.getElementById('lobby-sticker-lightbox-close');
    const $searchInput = document.getElementById('temp-search-input');
    const $searchBtn = document.getElementById('temp-search-btn');
    const $searchResults = document.getElementById('temp-search-results');
    const $inviteToast = document.getElementById('temp-invite-toast');
    const $inviteText = document.getElementById('temp-invite-text');
    const $inviteYes = document.getElementById('temp-invite-yes');
    const $inviteNo = document.getElementById('temp-invite-no');

    // ==================== 状态 ====================
    const state = {
        ws: null,
        phase: 'invite',        // invite | room
        nickname: '',
        playerId: '',
        roomId: '',
        peerName: '',
        peerPlayerId: '',
        historyOffset: 0,       // 已加载历史条数（temp_history 分页游标）
        historyHasMore: false,  // 是否还有更早消息可加载
        historyLoading: false,  // 分页加载锁
        reconnectTimer: null,
        intentionalClose: false,
        stickerMap: {},
        pendingInviteId: getParam('invite') || '',
        declineInviteId: getParam('decline') || '',
        rejoinRoomId: getParam('room') || '',
        rejoinNickname: getParam('nick') || '',
    };

    // ==================== WS 连接 ====================
    const WS_URL = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.host + '/ws/tempchat';

    function connect() {
        if (state.ws) { try { state.ws.close(); } catch (e) { } }
        let ws;
        try { ws = new WebSocket(WS_URL); } catch (e) { return; }
        state.ws = ws;

        ws.onopen = function () {
            state.intentionalClose = false;
            // 必须登录账号：无 token 拒绝进入
            let loginToken = '';
            try { loginToken = getUserToken() || ''; } catch (e) { }
            if (!loginToken) {
                showToast('临时聊天需要登录账号');
                state.intentionalClose = true;
                try { ws.close(); } catch (e) { }
                setTimeout(function () {
                    if (window.confirm('临时聊天需要登录账号，是否前往登录？')) {
                        window.location.href = '/';
                    }
                }, 600);
                return;
            }
            // 项目不支持游客：优先复用玩家真实昵称，拿不到则置空由后端拒绝
            let nick = state.rejoinNickname || getStoredNick() || (function(){ try { return getUserNickname() || ''; } catch (e) { return ''; } })();
            state.nickname = nick;
            let joinMsg = { type: 'temp_join', nickname: nick, player_token: loginToken };
            ws.send(JSON.stringify(joinMsg));

            // 房间内断线自动重连：temp_rejoin 恢复房间
            if (state.roomId) {
                setTimeout(function () {
                    if (ws.readyState !== WebSocket.OPEN) return;
                    let m = { type: 'temp_rejoin', room_id: state.roomId, nickname: state.nickname };
                    try { if (getUserToken()) m.player_token = getUserToken(); } catch (e) { }
                    ws.send(JSON.stringify(m));
                }, 400);
            }
            if (state.pendingInviteId) {
                setTimeout(function () {
                    ws.send(JSON.stringify({ type: 'temp_invite_resp', invite_id: state.pendingInviteId, accept: true }));
                }, 300);
            }
            if (state.declineInviteId) {
                setTimeout(function () {
                    let m = { type: 'temp_invite_resp', invite_id: state.declineInviteId, accept: false };
                    try { if (getUserToken()) m.player_token = getUserToken(); } catch (e) { }
                    ws.send(JSON.stringify(m));
                }, 300);
            }
            if (state.rejoinRoomId) {
                setTimeout(function () {
                    let m = { type: 'temp_rejoin', room_id: state.rejoinRoomId, nickname: state.rejoinNickname };
                    try { if (getUserToken()) m.player_token = getUserToken(); } catch (e) { }
                    ws.send(JSON.stringify(m));
                }, 400);
            }
        };

        ws.onmessage = function (ev) {
            let data;
            try { data = JSON.parse(ev.data); } catch (e) { return; }
            handleMessage(data);
        };

        ws.onclose = function () {
            if (state.intentionalClose) return;
            if (state.phase === 'room' && !state.reconnectTimer) {
                state.reconnectTimer = setTimeout(function () {
                    state.reconnectTimer = null;
                    if (!state.intentionalClose) connect();
                }, 1500);
            }
        };

        ws.onerror = function () { };
    }

    // ==================== 消息分发 ====================
    function handleMessage(data) {
        switch (data.type) {
            case 'temp_joined':
                state.roomId = '';
                state.phase = 'lobby';
                break;
            case 'temp_error':
                showToast(data.text || '操作失败');
                if (String(data.text || '').indexOf('登录') >= 0) {
                    state.intentionalClose = true;
                    try { state.ws && state.ws.close(); } catch (e) { }
                }
                break;
            case 'temp_search_result':
                renderSearchResults(data.users || []);
                break;
            case 'temp_invite_sent':
                if (!data.ok) showToast(data.error || '邀请失败');
                else showToast('邀请已发送，等待对方回应…');
                break;
            case 'temp_invite_result':
                showToast(data.ok ? '对方接受了邀请' : (data.error || '邀请失败'));
                break;
            case 'temp_invite':
                showInviteToast(data);
                break;
            case 'temp_invite_expired':
                hideInviteToast();
                if (state.phase !== 'room') showToast(data.text || '邀请已过期');
                break;
            case 'temp_invite_dismissed':
                hideInviteToast();
                break;
            case 'temp_room_created':
                enterRoom(data);
                break;
            case 'temp_chat':
                appendChatMessage(data);
                break;
            case 'temp_history':
                if (state.phase !== 'room') break; // 已离开房间：丢弃迟到的分页响应
                state.historyLoading = false;
                state.historyHasMore = !!data.has_more;
                state.historyOffset = data.offset || 0;
                prependHistory(data.messages || []);
                break;
            case 'temp_system':
                appendSystem(data.text || '');
                break;
            case 'temp_closed':
                leaveRoom(data.reason || '房间已关闭', true);
                break;
            case 'temp_report_result':
                if (data.ok) { showToast(data.message || '举报已提交'); closeReportModal(); }
                else { showToast(data.error || '举报失败'); }
                break;
            case 'stickers_list':
                // 复用 shared.js 的表情处理（写缓存 + 收藏清理）
                if (window.handleStickersList) {
                    state.stickerMap = handleStickersList(data);
                } else {
                    state.stickerMap = data.stickers || {};
                }
                stickerLoaded = true;
                renderStickerPicker();
                break;
            case 'stickers_unchanged':
                break;
        }
    }

    // ==================== 邀请页：搜索 ====================
    function doSearch() {
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN) { showToast('连接未就绪'); return; }
        state.ws.send(JSON.stringify({ type: 'temp_search', keyword: $searchInput.value.trim() }));
    }

    function renderSearchResults(users) {
        if (!users.length) {
            $searchResults.innerHTML = '<div class="temp-search-empty">没有找到在线用户</div>';
            return;
        }
        let html = '';
        for (let i = 0; i < users.length; i++) {
            let u = users[i];
            let statusText = u.status === 'online' ? '空闲' : '对局中';
            let statusCls = u.status === 'online' ? 'online' : 'ingame';
            let disabled = u.status !== 'online' ? ' data-disabled="1"' : '';
            html += '<div class="temp-user-item">' +
                '<span class="temp-user-avatar" data-pid="' + escapeHtmlAttr(u.player_id) + '" data-nick="' + escapeHtmlAttr(u.nickname) + '">' + escapeHtml(getAvatarChar(u.nickname)) + '</span>' +
                '<span class="temp-user-name">' + escapeHtml(u.nickname) + '</span>' +
                '<span class="temp-user-status ' + statusCls + '">' + statusText + '</span>' +
                '<button class="doodle-btn temp-invite-btn" data-pid="' + escapeHtmlAttr(u.player_id) + '"' + disabled + '>邀请</button>' +
                '</div>';
        }
        $searchResults.innerHTML = html;
        // 搜索结果头像：优先 OAuth 头像，失败降级为首字符色块
        let avatars = $searchResults.querySelectorAll('.temp-user-avatar');
        for (let i = 0; i < avatars.length; i++) {
            (function (av) {
                let pid = av.getAttribute('data-pid') || '';
                let nick = av.getAttribute('data-nick') || '';
                if (pid) renderAvatar(av, pid, nick);
            })(avatars[i]);
        }
        let btns = $searchResults.querySelectorAll('.temp-invite-btn');
        for (let i = 0; i < btns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    if (btn.getAttribute('data-disabled')) { showToast('对方正在对局中，无法邀请'); return; }
                    state.ws.send(JSON.stringify({ type: 'temp_invite', target_player_id: btn.getAttribute('data-pid') }));
                    showToast('邀请已发送，等待对方回应…');
                });
            })(btns[i]);
        }
    }

    // ==================== 收到邀请 ====================
    function showInviteToast(data) {
        $inviteText.textContent = data.from_name + ' 邀请你进入临时聊天';
        $inviteToast.style.display = 'flex';
        $inviteToast.dataset.inviteId = data.invite_id || '';
        clearTimeout($inviteToast._t);
        $inviteToast._t = setTimeout(function () {
            if ($inviteToast.style.display !== 'none') {
                $inviteToast.style.display = 'none';
                if (state.phase !== 'room') showToast('邀请已过期');
            }
        }, (data.timeout || 60) * 1000);
    }

    function hideInviteToast() {
        $inviteToast.style.display = 'none';
        clearTimeout($inviteToast._t);
    }

    function respondInvite(accept) {
        let inviteId = $inviteToast.dataset.inviteId || '';
        if (!inviteId || !state.ws) return;
        state.ws.send(JSON.stringify({ type: 'temp_invite_resp', invite_id: inviteId, accept: accept }));
        hideInviteToast();
        if (accept) showToast('正在进入房间…');
    }

    // ==================== 房间 ====================
    function enterRoom(data) {
        state.phase = 'room';
        state.roomId = data.room_id || '';
        state.peerName = data.peer_name || '对方';
        state.peerPlayerId = data.peer_player_id || '';
        $matchPanel.style.display = 'none';
        $lobbyMain.style.removeProperty('display');
        $hasIdentity.style.display = 'flex';
        $loading.style.display = 'none';
        $peerBar.style.display = 'flex';
        $reportBtn.style.display = 'inline-flex';
        $btnBack.style.display = 'none'; // 房间内隐藏返回按钮（由退出按钮代替）
        $peerName.textContent = state.peerName;
        if ($peerAvatar) {
            $peerAvatar.textContent = '';
            $peerAvatar.style.backgroundImage = 'none';
            if (state.peerPlayerId) {
                renderAvatar($peerAvatar, state.peerPlayerId, state.peerName);
            } else {
                $peerAvatar.textContent = getAvatarChar(state.peerName);
                $peerAvatar.style.background = getAvatarColor(state.peerName);
            }
        }
        $messages.innerHTML = '';
        state.historyLoading = false;
        if (data.rejoined && Array.isArray(data.history)) {
            for (let i = 0; i < data.history.length; i++) appendChatMessage(data.history[i], true);
            // 满一页（50 条）说明可能还有更早消息，可向上滚动加载
            state.historyOffset = data.history.length;
            state.historyHasMore = data.history.length >= 50;
            appendSystem('你已重新连接');
        } else {
            state.historyOffset = 0;
            state.historyHasMore = false;
            appendSystem('已进入临时房间，和 ' + state.peerName + ' 私密畅聊吧');
        }
        $chatInput.focus();
    }

    function leaveRoom(reason, closed) {
        state.phase = 'invite';
        state.roomId = '';
        $hasIdentity.style.display = 'none';
        $peerBar.style.display = 'none';
        $reportBtn.style.display = 'none';
        $btnBack.style.display = ''; // 恢复返回按钮
        $matchPanel.style.display = '';
        $lobbyMain.style.display = 'none';
        $messages.innerHTML = '';
        if (closed) showToast(reason || '房间已关闭');
    }

    // ==================== 聊天渲染（模仿聊天室 DOM 结构） ====================
    function appendSystem(text) {
        let div = document.createElement('div');
        div.className = 'lobby-msg system';
        div.textContent = text;
        $messages.appendChild(div);
        scrollToBottom();
    }

    function appendChatMessage(data, replay) {
        let node = buildChatNode(data);
        $messages.appendChild(node);
        // 实时消息仅在接近底部时自动滚动（向上翻看历史时不打断）
        if (!replay && isNearBottom()) scrollToBottom();
    }

    /** 构建单条消息节点（新消息追加与历史消息插入顶部共用） */
    function buildChatNode(data) {
        let senderName = data.sender_name || '游客';
        let isMine = senderName === state.nickname;

        let wrapper = document.createElement('div');
        wrapper.className = 'lobby-msg-row';
        if (isMine) wrapper.classList.add('mine');

        let avatar = document.createElement('div');
        avatar.className = 'lobby-avatar';
        // 有 OAuth 头像则显示图片，否则降级为首字符
        if (data.player_id) {
            renderAvatar(avatar, data.player_id, senderName);
        } else if (data.avatar) {
            // 兼容旧历史消息（只存了 avatar URL、没有 player_id）
            let img = new Image();
            img.onload = function () {
                avatar.textContent = '';
                avatar.style.backgroundImage = 'url(' + data.avatar + ')';
                avatar.style.backgroundSize = 'cover';
                avatar.style.backgroundPosition = 'center';
            };
            img.onerror = function () {
                avatar.style.backgroundImage = 'none';
                avatar.textContent = getAvatarChar(senderName);
                avatar.style.background = isMine ? 'let(--note-blue)' : getAvatarColor(senderName);
            };
            img.src = data.avatar;
        } else {
            avatar.textContent = getAvatarChar(senderName);
            avatar.style.background = isMine ? 'let(--note-blue)' : getAvatarColor(senderName);
        }

        let content = document.createElement('div');
        content.className = 'lobby-msg-content';

        let meta = document.createElement('div');
        meta.className = 'lobby-msg-meta';
        let nameSpan = document.createElement('span');
        nameSpan.className = 'lobby-msg-sender';
        nameSpan.textContent = senderName;
        let timeSpan = document.createElement('span');
        timeSpan.className = 'lobby-msg-time';
        timeSpan.textContent = data.time || '';
        meta.appendChild(nameSpan);
        meta.appendChild(timeSpan);
        content.appendChild(meta);

        let bubble = document.createElement('div');
        bubble.className = 'lobby-msg' + (isMine ? ' mine' : '');

        // 表情消息
        if (data.sticker_id || data.sticker_url) {
            let url = resolveStickerUrl(data.sticker_id, data.sticker_url, state.stickerMap);
            if (url) {
                let img = document.createElement('img');
                img.className = 'sticker-img';
                img.src = url;
                img.alt = '表情';
                img.addEventListener('click', function () { showStickerLightbox(url); });
                bubble.appendChild(img);
            } else {
                bubble.innerHTML = '<span class="temp-sticker-placeholder">[表情]</span>';
            }
        } else {
            // 纯文本 + 视频链接解析（禁 MD / 音乐 / 彩蛋）
            let textDiv = document.createElement('div');
            textDiv.className = 'lobby-msg-text';
            textDiv.innerHTML = parseVideoLinks(escapeHtml(data.content || ''));
            bubble.appendChild(textDiv);
        }

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        // 长按/右键消息弹出功能菜单（复制/举报）
        attachMsgLongPress(wrapper, data);
        return wrapper;
    }

    /** 历史分页插入顶部（保持滚动视口位置不跳动） */
    function prependHistory(messages) {
        if (!messages || !messages.length) return;
        const prevHeight = $messages.scrollHeight;
        const prevTop = $messages.scrollTop;
        const frag = document.createDocumentFragment();
        for (let i = 0; i < messages.length; i++) frag.appendChild(buildChatNode(messages[i]));
        $messages.insertBefore(frag, $messages.firstChild);
        $messages.scrollTop = prevTop + ($messages.scrollHeight - prevHeight);
    }

    /** 是否接近底部（120px 内视为在底部） */
    function isNearBottom() {
        return $messages.scrollHeight - $messages.scrollTop - $messages.clientHeight < 120;
    }

    /** 请求更早历史（滚动到顶触发，防抖/去重） */
    function requestHistory() {
        if (state.historyLoading || !state.historyHasMore || !state.roomId) return;
        state.historyLoading = true;
        state.ws.send(JSON.stringify({ type: 'temp_history', offset: state.historyOffset }));
    }

    // ==================== 消息长按功能菜单 ====================

    function attachMsgLongPress(wrapper, data) {
        let timer = null;
        let started = false;

        function onStart(e) {
            started = false;
            timer = setTimeout(function () {
                started = true;
                showMsgMenu(wrapper, data);
            }, 500);
        }
        function onEnd() {
            clearTimeout(timer);
            timer = null;
        }
        wrapper.addEventListener('mousedown', onStart);
        wrapper.addEventListener('touchstart', onStart, { passive: true });
        wrapper.addEventListener('mouseup', onEnd);
        wrapper.addEventListener('mouseleave', onEnd);
        wrapper.addEventListener('touchend', onEnd);
        wrapper.addEventListener('touchcancel', onEnd);
        // 桌面右键
        wrapper.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            showMsgMenu(wrapper, data);
        });
        // 阻止长按选中文本
        wrapper.addEventListener('selectstart', function (e) { if (timer) e.preventDefault(); });
    }

    function showMsgMenu(wrapper, data) {
        let menu = document.getElementById('temp-msg-menu');
        if (menu) menu.parentNode && menu.parentNode.removeChild(menu);

        let isMine = (data.sender_name || '') === state.nickname;
        let text = typeof data.content === 'string' ? data.content : '';
        let items = '';
        if (text) {
            items += '<div class="temp-msg-menu-item" data-act="copy"><svg class="temp-msg-menu-icon" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>复制文本</div>';
        }
        if (!isMine) {
            items += '<div class="temp-msg-menu-item" data-act="report"><svg class="temp-msg-menu-icon" viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>举报对方</div>';
        }
        if (!items) {
            items = '<div class="temp-msg-menu-item" data-act="none">无可用操作</div>';
        }

        let overlay = document.createElement('div');
        overlay.id = 'temp-msg-menu-overlay';
        overlay.className = 'temp-msg-overlay';
        menu = document.createElement('div');
        menu.id = 'temp-msg-menu';
        menu.className = 'temp-msg-menu';
        menu.innerHTML = items;
        document.body.appendChild(overlay);
        document.body.appendChild(menu);

        // 定位：气泡附近（屏幕中央偏上）
        let rect = wrapper.getBoundingClientRect();
        let mw = menu.offsetWidth || 160;
        let left = Math.max(8, Math.min(window.innerWidth - mw - 8, rect.left + rect.width / 2 - mw / 2));
        let top = Math.max(8, rect.top - menu.offsetHeight - 8);
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeMsgMenu();
        });
        menu.addEventListener('click', function (e) {
            let item = e.target.closest('.temp-msg-menu-item');
            if (!item) return;
            let act = item.getAttribute('data-act');
            closeMsgMenu();
            if (act === 'copy') {
                let txt = typeof data.content === 'string' ? data.content : '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(txt).then(() => showToast('已复制'));
                } else {
                    let ta = document.createElement('textarea');
                    ta.value = txt;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showToast('已复制');
                }
            } else if (act === 'report') {
                openReportModal();
            }
        });
    }

    function closeMsgMenu() {
        let overlay = document.getElementById('temp-msg-menu-overlay');
        if (overlay) overlay.parentNode && overlay.parentNode.removeChild(overlay);
        let menu = document.getElementById('temp-msg-menu');
        if (menu) menu.parentNode && menu.parentNode.removeChild(menu);
    }

    function scrollToBottom() {
        $messages.scrollTop = $messages.scrollHeight;
        // 表情等图片异步加载会撑高消息内容：给未加载完成的图片注册 load/error，
        // 仅在仍接近底部时再次滚动，避免停在半空（对齐聊天室行为）；用户上翻历史时不打断
        $messages.querySelectorAll('img:not([data-scroll-tracked])').forEach(function (img) {
            if (img.complete) return;
            img.dataset.scrollTracked = '1';
            img.addEventListener('load', function () { if (isNearBottom()) scrollToBottom(); }, { once: true });
            img.addEventListener('error', function () { if (isNearBottom()) scrollToBottom(); }, { once: true });
        });
    }

    // ==================== 视频解析（B站/抖音，对齐聊天室） ====================
    const BILI_SPINNER_SVG = '<svg class="bili-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="31.4 31.4" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite"/></circle></svg>';

    function parseVideoLinks(text) {
        let regex = /https?:\/\/(?:www\.)?bilibili\.com\/video\/[^\s<>"'，。！？、；：》\)\]]+|https?:\/\/b23\.tv\/[^\s<>"'，。！？、；：》\)\]]+|https?:\/\/v\.douyin\.com\/[^\s<>"'，。！？、；：》\)\]]+|BV[0-9A-Za-z]{10}/gi;
        return text.replace(regex, function (match) {
            let cleanUrl = match.replace(/\?.*$/, '');
            if (/^BV[0-9A-Za-z]{10}$/i.test(cleanUrl)) {
                cleanUrl = 'https://www.bilibili.com/video/' + cleanUrl;
            }
            return '<div class="bili-embed" data-bili-url="' + encodeURIComponent(cleanUrl) + '">' +
                '<div class="bili-loading">' + BILI_SPINNER_SVG + '解析中...</div>' +
                '</div>';
        });
    }

    let biliObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            let el = entry.target;
            biliObserver.unobserve(el);
            let url = decodeURIComponent(el.getAttribute('data-bili-url') || '');
            if (!url) return;
            let apiUrl = 'https://api.xiaofengqwq.com/api/v1/tools/video-parse?url=' + encodeURIComponent(url);
            fetchBiliWithRetry(el, apiUrl, 0, function (json) {
                if (json && json.code === 200 && json.data && json.data.video_url) {
                    let data = json.data;
                    el.innerHTML =
                        '<video class="bili-video" src="' + escapeHtmlAttr(data.video_url) + '" controls></video>' +
                        '<div class="bili-title"><a href="' + escapeHtmlAttr(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(data.title || '') + '</a></div>';
                    if (data.cover) {
                        el.querySelector('.bili-video').setAttribute('poster', 'https://api-proxy_image.xfcode.top/proxy_image.php?url=' + encodeURIComponent(data.cover));
                    }
                    try { new Plyr(el.querySelector('.bili-video'), { controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'fullscreen'] }); } catch (e) { }
                } else {
                    el.innerHTML = '<div class="bili-title"><a href="' + escapeHtmlAttr(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtmlAttr(url) + '</a></div>';
                }
            });
        });
    }, { rootMargin: '200px' });

    function fetchBiliWithRetry(el, url, retry, cb) {
        fetch(url).then(function (r) { return r.json(); }).then(function (json) {
            cb(json);
        }).catch(function () {
            if (retry < 2) setTimeout(function () { fetchBiliWithRetry(el, url, retry + 1, cb); }, 800);
            else cb(null);
        });
    }

    // ==================== 表情包（照搬聊天室：加载指示器 + shared.js 面板 + 定位） ====================
    let stickerLoaded = false;      // 是否已收到过服务端表情响应（收到后即使为空也显示空态，不再转圈）
    let stickerLoadTimer = null;    // 加载超时兜底（防 WS 无响应时无限转圈）

    function loadStickers() {
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return;
        let msg = { type: 'get_stickers' };
        try { if (getUserToken()) msg.player_token = getUserToken(); } catch (e) { }
        state.ws.send(JSON.stringify(msg));
    }

    // 表情面板渲染（对齐聊天室：未加载完成显示加载指示器，2.5s 超时兜底）
    function renderStickerPicker() {
        let fresh = null;
        if (window.loadStickerCache) fresh = loadStickerCache();
        if (fresh && Object.keys(fresh).length > 0) state.stickerMap = fresh;

        if (!stickerLoaded && Object.keys(state.stickerMap).length === 0) {
            $stickerBody.innerHTML = '<div class="sticker-loading">' + BILI_SPINNER_SVG + '<span>加载中…</span></div>';
            if (!stickerLoadTimer) {
                stickerLoadTimer = setTimeout(function () {
                    stickerLoadTimer = null;
                    stickerLoaded = true;
                    renderStickerPicker();
                }, 2500);
            }
            return;
        }

        if (window.renderSharedStickerPicker) {
            renderSharedStickerPicker($stickerBody, state.stickerMap, function (id) {
                sendSticker(id);
                $stickerPicker.style.display = 'none';
            });
        } else {
            renderStickerPickerFallback();
        }
    }

    // 降级渲染（shared.js 不可用时）
    function renderStickerPickerFallback() {
        let ids = Object.keys(state.stickerMap || {});
        if (!ids.length) { $stickerBody.innerHTML = '<div class="temp-sticker-empty">暂无可用表情</div>'; return; }
        let html = '';
        for (let i = 0; i < ids.length; i++) {
            html += '<img src="' + escapeHtmlAttr(state.stickerMap[ids[i]].url) + '" data-id="' + escapeHtmlAttr(ids[i]) + '" alt="表情" loading="lazy">';
        }
        $stickerBody.innerHTML = html;
        let imgs = $stickerBody.querySelectorAll('img');
        for (let i = 0; i < imgs.length; i++) {
            (function (img) {
                img.addEventListener('click', function () {
                    sendSticker(img.getAttribute('data-id'));
                    $stickerPicker.style.display = 'none';
                });
            })(imgs[i]);
        }
    }

    // 表情面板定位（对齐聊天室：锚定表情按钮上方）
    function repositionStickerPicker() {
        if ($stickerPicker.style.display !== 'flex') return;
        const btnRect = $btnSticker.getBoundingClientRect();
        const pickerWidth = $stickerPicker.offsetWidth || 260;
        const pickerHeight = $stickerPicker.offsetHeight;
        let left = btnRect.right - pickerWidth;
        if (left + pickerWidth > window.innerWidth) left = window.innerWidth - pickerWidth - 10;
        if (left < 10) left = 10;
        $stickerPicker.style.left = left + 'px';
        $stickerPicker.style.top = (btnRect.top - pickerHeight - 16) + 'px';
    }

    function sendSticker(id) {
        if (!state.ws || !state.roomId || !id) return;
        state.ws.send(JSON.stringify({ type: 'temp_chat', content: '', sticker_id: id, sticker_url: '' }));
    }

    function showStickerLightbox(url) {
        $lightboxImg.src = url;
        $lightbox.style.display = 'flex';
    }

    // ==================== 发送 / 举报 / 退出 ====================
    function sendChat() {
        let content = $chatInput.value.trim();
        if (!content || !state.ws || state.ws.readyState !== WebSocket.OPEN) return;
        state.ws.send(JSON.stringify({ type: 'temp_chat', content: content }));
        $chatInput.value = '';
        $chatInput.style.height = 'auto';
        $chatInput.focus();
    }

    function exitRoom() {
        if (!state.roomId) return;
        state.intentionalClose = true;
        state.ws.send(JSON.stringify({ type: 'temp_exit' }));
        leaveRoom('已退出房间');
        showToast('已退出房间');
        // 清理后重新连接（回邀请页）
        setTimeout(function () { state.intentionalClose = false; if (state.ws) { try { state.ws.close(); } catch (e) { } } state.ws = null; connect(); }, 200);
    }

    function openReportModal() {
        if (!state.roomId) return;
        let overlay = document.createElement('div');
        overlay.className = 'temp-report-overlay';
        overlay.id = 'temp-report-overlay';
        overlay.innerHTML =
            '<div class="temp-report-box">' +
            '<div class="temp-report-title">举报 ' + escapeHtml(state.peerName) + '</div>' +
            '<textarea class="temp-report-reason" id="temp-report-reason" maxlength="500" placeholder="请填写举报原因（辱骂、骚扰、广告等）"></textarea>' +
            '<div class="temp-report-ops">' +
            '<button class="doodle-btn" id="temp-report-cancel">取消</button>' +
            '<button class="doodle-btn temp-send-btn" id="temp-report-submit">提交举报</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeReportModal(); });
        document.getElementById('temp-report-cancel').addEventListener('click', closeReportModal);
        document.getElementById('temp-report-submit').addEventListener('click', function () {
            let reason = document.getElementById('temp-report-reason').value.trim();
            if (!reason) { showToast('请填写举报原因'); return; }
            state.ws.send(JSON.stringify({ type: 'temp_report', reason: reason }));
        });
        document.getElementById('temp-report-reason').focus();
    }

    function closeReportModal() {
        let el = document.getElementById('temp-report-overlay');
        if (el) el.parentNode.removeChild(el);
    }

    // ==================== Toast ====================
    function showToast(text) {
        let el = document.createElement('div');
        el.className = 'temp-toast';
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s';
            el.style.opacity = '0';
            setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 320);
        }, 2400);
    }

    function getStoredNick() {
        try { return localStorage.getItem('tempchat_nick') || ''; } catch (e) { return ''; }
    }

    // ==================== 事件绑定 ====================
    function bindEvents() {
        $searchBtn.addEventListener('click', doSearch);
        $searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') doSearch(); });

        $inviteYes.addEventListener('click', function () { respondInvite(true); });
        $inviteNo.addEventListener('click', function () { respondInvite(false); });

        $btnSend.addEventListener('click', sendChat);
        $chatInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
        });
        // 输入框自动增高（对齐聊天室行为）
        $chatInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        $exitBtn.addEventListener('click', exitRoom);
        $reportBtn.addEventListener('click', openReportModal);
        $btnBack.addEventListener('click', function () { location.href = '/'; });

        $btnSticker.addEventListener('click', function () {
            if ($stickerPicker.style.display === 'none' || !$stickerPicker.style.display) {
                loadStickers();
                renderStickerPicker();
                $stickerPicker.style.visibility = 'hidden';
                $stickerPicker.style.display = 'flex';
                repositionStickerPicker();
                $stickerPicker.style.visibility = 'visible';
            } else {
                $stickerPicker.style.display = 'none';
            }
        });
        $btnCloseSticker.addEventListener('click', function () { $stickerPicker.style.display = 'none'; });

        // 点外部关闭表情面板（对齐聊天室）
        document.addEventListener('click', function (e) {
            if ($stickerPicker.style.display === 'flex' &&
                !$stickerPicker.contains(e.target) &&
                e.target !== $btnSticker &&
                !$btnSticker.contains(e.target)) {
                $stickerPicker.style.display = 'none';
            }
        });

        $btnScrollBottom.addEventListener('click', scrollToBottom);

        // 向上滚动到顶 → 加载更早历史（分页）
        $messages.addEventListener('scroll', function () {
            if ($messages.scrollTop <= 24) requestHistory();
        });

        $lightboxClose.addEventListener('click', function () { $lightbox.style.display = 'none'; });
        // 点击遮罩/背景/大图任意处关闭
        $lightbox.addEventListener('click', function (e) {
            if (e.target === $lightbox || e.target.className === 'sticker-lightbox-bg' || e.target === $lightboxImg) {
                $lightbox.style.display = 'none';
            }
        });

        // 表情面板 tab（shared.js 提供 bindStickerPickerTabs）
        if (window.bindStickerPickerTabs) {
            bindStickerPickerTabs('lobby-sticker-picker', function () { renderStickerPicker(); }, function () { repositionStickerPicker(); });
        }

        window.addEventListener('beforeunload', function () {
            state.intentionalClose = true;
            if (state.ws) { try { state.ws.close(); } catch (e) { } }
        });
    }

    // ==================== 初始化 ====================
    function init() {
        // 初始只显示邀请页，房间区域隐藏
        $lobbyMain.style.display = 'none';
        $matchPanel.style.display = '';
        $hasIdentity.style.display = 'none';
        bindEvents();
        connect();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
