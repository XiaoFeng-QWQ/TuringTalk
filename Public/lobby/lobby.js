/**
 * 公共聊天室 - 客户端
 */
(function () {
    'use strict';

    const WS_URL = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.host + '/ws/lobby';
    const RECONNECT_DELAY = 2000;
    const HEARTBEAT_INTERVAL = 20000;
    const PONG_GRACE = 15000;

    // ==================== DOM ====================
    const $header = document.querySelector('header');
    const $main = document.getElementById('lobby-main');
    const $lobbyChatHeader = document.getElementsByClassName('lobby-chat-header')[0];
    const $hasIdentity  = document.getElementById('lobby-has-identity');
    const $noIdentity   = document.getElementById('lobby-no-identity');
    const $fillName     = document.getElementById('lobby-fill-name');
    const $messages     = document.getElementById('lobby-messages');
    const $chatInput    = document.getElementById('lobby-chat-input');
    const $btnSend      = document.getElementById('lobby-btn-send');
    const $btnSticker   = document.getElementById('lobby-btn-sticker');
    const $stickerPicker = document.getElementById('lobby-sticker-picker');
    const $stickerPickerBody = document.getElementById('lobby-sticker-picker-body');
    const $btnCloseStickerPicker = document.getElementById('lobby-btn-close-sticker-picker');
    const $stickerLightbox = document.getElementById('lobby-sticker-lightbox');
    const $stickerLightboxImg = document.getElementById('lobby-sticker-lightbox-img');
    const $stickerLightboxClose = document.getElementById('lobby-sticker-lightbox-close');
    const $usersList    = document.getElementById('lobby-users-list');
    const $usersCount   = document.getElementById('lobby-users-count');
    const $usersPanel   = document.getElementById('lobby-users-panel');
    const $btnToggleUsers = document.getElementById('btn-toggle-users');
    const $replyPreview    = document.getElementById('lobby-reply-preview');
    const $replyPreviewText = document.getElementById('lobby-reply-preview-text');
    const $replyPreviewCancel = document.getElementById('lobby-reply-preview-cancel');
    const $connStatus = document.getElementById('lobby-connection-status');
    // 身份状态 DOM
    const $recoverNickname = document.getElementById('lobby-recover-nickname');
    const $recoverInput  = document.getElementById('lobby-recover-input');
    const $btnRecover    = document.getElementById('lobby-btn-recover');
    const $recoverMsg    = document.getElementById('lobby-recover-msg');
    const $btnGoHome     = document.getElementById('lobby-btn-go-home');
    const $btnNewName    = document.getElementById('lobby-btn-new-name');
    const $nicknameInput = document.getElementById('lobby-nickname-input');
    const $btnJoin       = document.getElementById('lobby-btn-join');

    // ==================== 状态 ====================
    let ws = null;
    let heartbeatTimer = null;
    let pongTimer = null;
    let reconnectTimer = null;
    let reconnecting = false;
    let intentionalClose = false;
    let banned = false;
    let myNickname = '';
    let replyTarget = null;      // { id, name, text }
    let stickyScroll = false;
    let onlinePlayers = [];      // [{ fd, nickname }] — 在线玩家列表
    let onlinePlayerCount = 0;   // 缓存在线人数，用于右上角状态栏显示

    // ==================== WebSocket ====================
    function connect() {
        if (ws && ws.readyState === WebSocket.OPEN) return;

        try {
            ws = new WebSocket(WS_URL);
        } catch (e) {
            scheduleReconnect();
            return;
        }

        ws.onopen = function () {
            console.log('[Lobby] WS connected');
            reconnecting = false;
            setConnStatus('connected', '已连接');
            // 指纹
            send({ type: 'lobby_set_fp', fingerprint: getFingerprint() });
            // 身份验证
            sendJoin();
            // 历史消息
            send({ type: 'lobby_history' });
            startHeartbeat();
        };

        ws.onmessage = function (e) {
            try {
                const data = JSON.parse(e.data);
                dispatch(data);
            } catch (err) {
                console.warn('[Lobby] Invalid message', e.data);
            }
        };

        ws.onclose = function () {
            console.log('[Lobby] WS closed');
            setConnStatus('', '连接断开');
            stopHeartbeat();
            if (!intentionalClose && !banned) scheduleReconnect();
            intentionalClose = false;
        };

        ws.onerror = function () {
            console.log('[Lobby] WS error');
        };
    }

    function send(data) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(data));
        }
    }

    // ==================== 身份检测与面板切换 ====================
    function showIdentityState() {
        var nickname = getUserNickname();
        var code = getUserRecoveryCode();

        if (nickname && code) {
            // 有身份：建立连接，显示聊天界面
            myNickname = nickname;
            $hasIdentity.style.display = 'flex';
            $noIdentity.style.display = 'none';
            $fillName.style.display = 'none';
            connect();
        } else {
            // 无身份：不建立连接
            $hasIdentity.style.display = 'none';
            $noIdentity.style.display = 'flex';
            $fillName.style.display = 'none';
        }
    }

    function sendJoin() {
        send({
            type: 'lobby_join',
            nickname: myNickname,
            recovery_code: getUserRecoveryCode() || ''
        });
    }

    function handleJoined(data) {
        // 保存服务端返回的恢复码
        if (data.recovery_code) {
            setUserRecoveryCode(data.recovery_code);
        }
        // 使用服务端返回的昵称
        if (data.nickname && data.nickname !== myNickname) {
            myNickname = data.nickname;
            setUserNickname(myNickname);
        }
        if (!getUserNickname()) {
            setUserNickname(myNickname);
        }
        // 切换到聊天界面
        $hasIdentity.style.display = 'flex';
        $noIdentity.style.display = 'none';
        $fillName.style.display = 'none';
    }

    // 返回首页
    $btnGoHome.addEventListener('click', function () {
        location.href = '/';
    });

    // 恢复码恢复
    function doRecover() {
        var code = $recoverInput.value.trim();
        if (!code) { showRecoverMsg('请输入恢复码'); return; }
        var nickname = $recoverNickname.value.trim();
        if (!nickname) { showRecoverMsg('请先填写昵称'); return; }
        $recoverMsg.style.display = 'none';
        $btnRecover.disabled = true;
        $btnRecover.textContent = '恢复中...';
        fetch('/api/player-stats?code=' + encodeURIComponent(code) + '&nickname=' + encodeURIComponent(nickname))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                $btnRecover.disabled = false;
                $btnRecover.textContent = '恢复';
                if (data.error) { showRecoverMsg(data.error); return; }
                setUserNickname(nickname);
                setUserRecoveryCode(data.code);
                $recoverInput.value = '';
                $recoverNickname.value = '';
                showIdentityState();
            })
            .catch(function () {
                $btnRecover.disabled = false;
                $btnRecover.textContent = '恢复';
                showRecoverMsg('网络错误，请稍后重试');
            });
    }
    function showRecoverMsg(msg) {
        $recoverMsg.textContent = msg;
        $recoverMsg.style.display = 'block';
    }
    $btnRecover.addEventListener('click', doRecover);
    $recoverNickname.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') $recoverInput.focus();
    });
    $recoverInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doRecover();
    });

    // 直接填写昵称
    $btnNewName.addEventListener('click', function () {
        $hasIdentity.style.display = 'none';
        $noIdentity.style.display = 'none';
        $fillName.style.display = 'flex';
        $nicknameInput.focus();
    });

    // 昵称提交 → 进入聊天室
    function doJoinChat() {
        var nickname = $nicknameInput.value.trim();
        if (!nickname || nickname.length < 1 || nickname.length > 12) {
            showTopToast('昵称 1~12 字符', true);
            return;
        }
        myNickname = nickname;
        setUserNickname(myNickname);
        // 立即显示聊天界面，不等待服务端
        $hasIdentity.style.display = 'flex';
        $noIdentity.style.display = 'none';
        $fillName.style.display = 'none';
        connect();
    }
    $btnJoin.addEventListener('click', doJoinChat);
    $nicknameInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doJoinChat();
    });

    // ==================== 心跳 ====================
    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(function () {
            send({ type: 'ping' });
            if (pongTimer) clearTimeout(pongTimer);
            pongTimer = setTimeout(function () {
                console.log('[Lobby] Pong timeout');
                if (ws) ws.close();
            }, PONG_GRACE);
        }, HEARTBEAT_INTERVAL);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
        if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
    }

    // ==================== 重连 ====================
    function scheduleReconnect() {
        if (reconnecting) return;
        reconnecting = true;

        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            connect();
        }, RECONNECT_DELAY);
    }

    // ==================== 消息分发 ====================
    function dispatch(data) {
        switch (data.type) {
            case 'pong':
                if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
                break;

            case 'lobby_history':
                renderHistory(data.messages);
                break;

            case 'lobby_chat':
                appendMessage(data);
                break;

            case 'lobby_joined':
                handleJoined(data);
                break;

            case 'lobby_system':
                appendSystem(data.text);
                break;

            case 'lobby_online_count':
                updateOnlineCount(data);
                break;

            case 'lobby_message_deleted':
                removeMessage(data.message_id);
                break;

            case 'lobby_revoke':
                revokeMessageUI(data.message_id, data.sender_name);
                break;

            case 'lobby_report_ok':
                showTopToast(data.message || '举报已提交', false);
                break;

            case 'lobby_mentioned':
                showTopToast(data.sender_name + ' 在聊天中@了你', false);
                highlightMentionedMessage(data.message_id);
                break;

            case 'error':
                showBanned(data.message || '您已被管理员封禁');
                break;

            case 'lobby_error':
                showTopToast(data.text || data.message || '操作失败', true);
                break;

            case 'stickers_list':
                if (data.stickers) {
                    const map = {};
                    data.stickers.forEach(function (s) { map[s.id] = { name: s.name, url: s.url }; });
                    stickerMap = map;
                    saveStickerCache(map);
                }
                break;

            case 'broadcast':
                showDanmaku(data.text, '全服公告', data.duration || 0);
                break;

            case 'room_announce':
                showDanmaku(data.text, '管理警告');
                break;
        }
    }

    // ==================== 封禁处理 ====================
    function showBanned(message) {
        banned = true;
        intentionalClose = true;
        stopHeartbeat();
        if (ws) {
            try { ws.close(); } catch (e) {}
            ws = null;
        }

        var container = document.querySelector('.lobby-container');
        if (!container) return;

        container.innerHTML =
            '<div class="lobby-identity-card">' +
                '<svg class="icon" viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--danger);margin-bottom:8px;">' +
                    '<circle cx="12" cy="12" r="10"/>' +
                    '<line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>' +
                '</svg>' +
                '<h2>无法访问聊天室</h2>' +
                '<p>' + escapeHtml(message) + '</p>' +
                '<a href="/" class="doodle-btn btn-full" style="text-decoration:none;">返回首页</a>' +
            '</div>';
    }

    // ==================== 连接状态 ====================
    var lastConnCls = '';
    var lastConnText = '';

    function setConnStatus(cls, text) {
        if (!$connStatus) return;
        lastConnCls = cls;
        lastConnText = text;
        renderConnStatus();
    }

    function renderConnStatus() {
        if (!$connStatus) return;
        $connStatus.className = 'lobby-connection-status ' + lastConnCls;
        var countHtml = onlinePlayerCount > 0 ? ' <span class="status-count">· ' + onlinePlayerCount + ' 在线</span>' : '';
        $connStatus.innerHTML = '<span class="status-dot"></span> ' + escapeHtml(lastConnText) + countHtml;
    }

    // ==================== 消息渲染 ====================

    function getAvatarChar(name) {
        if (!name) return '?';
        return name.charAt(0);
    }

    function getAvatarColor(name) {
        if (!name) return 'var(--note-green)';
        var colors = ['var(--note-green)', 'var(--note-blue)', 'var(--note-yellow)', 'var(--note-pink)', '#d1f2d3', '#d3e2ed', '#fdf5c9', '#fde2e4'];
        var hash = 0;
        for (var i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        return colors[Math.abs(hash) % colors.length];
    }

    function makeBubble(data, isMine) {
        var senderName = data.sender_name || '';
        var isSticker = data.content && /^\[sticker:/.test(data.content);

        var wrapper = document.createElement('div');
        wrapper.className = 'lobby-msg-row';
        if (isMine) wrapper.classList.add('mine');
        // 被 @ 提及的消息高亮
        if (data.mentions && Array.isArray(data.mentions) && data.mentions.indexOf(myNickname) >= 0) {
            wrapper.classList.add('mentioned');
        }

        // 头像
        var avatar = document.createElement('div');
        avatar.className = 'lobby-avatar';
        avatar.textContent = getAvatarChar(senderName);
        avatar.style.background = isMine ? 'var(--note-blue)' : getAvatarColor(senderName);

        // 右侧内容区：名字时间 + 气泡
        var content = document.createElement('div');
        content.className = 'lobby-msg-content';

        // 名字 + 时间
        var meta = document.createElement('div');
        meta.className = 'lobby-msg-meta';

        var nameSpan = document.createElement('span');
        nameSpan.className = 'lobby-msg-sender';
        nameSpan.textContent = senderName;

        var timeSpan = document.createElement('span');
        timeSpan.className = 'lobby-msg-time';
        timeSpan.textContent = data.time || '';

        meta.appendChild(nameSpan);
        meta.appendChild(timeSpan);
        content.appendChild(meta);

        // 气泡
        var bubble = document.createElement('div');
        bubble.className = 'lobby-msg' + (isMine ? ' mine' : '');
        bubble.dataset.msgId = data.id;
        bubble.dataset.createdAt = data.created_at || '';
        bubble.dataset.senderName = senderName;
        bubble.dataset.msgContent = data.content || '';

        // 已撤回的消息
        if (data.revoked) {
            bubble.classList.add('revoked');
            bubble.innerHTML = '<div class="lobby-msg-text revoked-text">消息已撤回</div>';
            content.appendChild(bubble);
            wrapper.appendChild(avatar);
            wrapper.appendChild(content);
            return wrapper;
        }

        if (isSticker) {
            var stickerId = parseStickerId(data.content);
            var stickerUrl = stickerMap[stickerId] ? stickerMap[stickerId].url : '';
            bubble.innerHTML = (stickerUrl ? '<img class="sticker-img" src="' + escapeHtmlAttr(stickerUrl) + '" alt="表情">' : '');
            if (stickerUrl) {
                var img = bubble.querySelector('.sticker-img');
                img.addEventListener('click', function () {
                    showStickerLightbox(stickerUrl);
                });
            }
        } else {
            var replyHtml = '';
            if (data.reply_to && data.reply_to.id) {
                replyHtml = '<div class="lobby-msg-reply" data-reply-id="' + data.reply_to.id + '">' +
                    '<span class="reply-name">' + escapeHtml(data.reply_to.name) + '</span>: ' +
                    escapeHtml(data.reply_to.text) +
                    '</div>';
            }

            bubble.innerHTML =
                replyHtml +
                '<div class="lobby-msg-text">' + escapeHtml(data.content) + '</div>';

            var replyDiv = bubble.querySelector('.lobby-msg-reply');
            if (replyDiv) {
                replyDiv.addEventListener('click', function () {
                    var targetId = this.dataset.replyId;
                    var target = document.querySelector('[data-msg-id="' + targetId + '"]');
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.animation = 'none';
                        target.offsetHeight;
                        target.style.animation = 'lobby-highlight 2s ease';
                    }
                });
            }
        }

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        return wrapper;
    }

    function renderHistory(messages) {
        $messages.innerHTML = '';
        if (!messages || messages.length === 0) {
            appendSystem('欢迎来到公共聊天室', true);
            return;
        }
        appendSystem('── 以下是最近消息 ──', false);
        messages.forEach(function (m) {
            $messages.appendChild(makeBubble(m, m.sender_name === myNickname));
        });
        scrollToBottom();
    }

    function appendMessage(data) {
        $messages.appendChild(makeBubble(data, data.sender_name === myNickname));
        scrollToBottom();
    }

    function highlightMentionedMessage(messageId) {
        var el = document.querySelector('[data-msg-id="' + messageId + '"]');
        if (!el) return;
        var row = el.closest('.lobby-msg-row');
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('mentioned');
        row.style.animation = 'none';
        row.offsetHeight;
        row.style.animation = 'lobby-highlight 2s ease';
    }

    function appendSystem(text, withIcon) {
        var div = document.createElement('div');
        div.className = 'lobby-msg system';
        if (withIcon) {
            div.innerHTML = '<svg class="sys-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> ' + escapeHtml(text);
        } else {
            div.textContent = text;
        }
        $messages.appendChild(div);
        scrollToBottom();
    }

    function removeMessage(messageId) {
        const el = $messages.querySelector('[data-msg-id="' + messageId + '"]');
        if (el) {
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }
    }

    function revokeMessageUI(messageId, senderName) {
        // 显示系统消息
        appendSystem((senderName || '有人') + ' 撤回了一条消息', false);
        // 从 DOM 移除原消息
        removeMessage(messageId);
    }

    // ==================== 右键菜单 ====================

    var $contextMenu = null;

    function createContextMenu() {
        if ($contextMenu) return;
        $contextMenu = document.createElement('div');
        $contextMenu.id = 'lobby-msg-context-menu';
        $contextMenu.className = 'lobby-msg-context-menu';
        $contextMenu.style.display = 'none';
        document.body.appendChild($contextMenu);
    }

    function showMsgContextMenu(e, data, bubble) {
        createContextMenu();

        var isMine = data.sender_name === myNickname;
        var items = [];

        // 回复
        items.push({
            label: '回复',
            class: '',
            action: function () {
                replyTarget = {
                    id: data.id,
                    name: data.sender_name,
                    text: data.content || ''
                };
                showReplyPreview();
            }
        });

        // 复制文本
        if (data.content) {
            items.push({
                label: '复制文本',
                class: '',
                action: function () {
                    copyToClipboard(data.content);
                }
            });
        }

        // 分割线
        items.push({ separator: true });

        if (isMine) {
            // 检查3分钟限制
            var canRevoke = true;
            if (data.created_at) {
                var elapsed = (Date.now() - new Date(data.created_at).getTime()) / 1000;
                if (elapsed > 180) canRevoke = false;
            }
            if (canRevoke) {
                items.push({
                    label: '撤回',
                    class: 'danger',
                    action: function () {
                        send({ type: 'lobby_revoke', message_id: data.id });
                    }
                });
            }
        } else {
            items.push({
                label: '举报',
                class: 'danger',
                action: function () {
                    showReportDialog(data.id, data.sender_name, data.content || '');
                }
            });
        }

        // 构建菜单 HTML
        var html = '';
        for (var i = 0; i < items.length; i++) {
            if (items[i].separator) {
                html += '<div class="ctx-menu-sep"></div>';
            } else {
                html += '<div class="ctx-menu-item' + (items[i].class ? ' ' + items[i].class : '') + '" data-idx="' + i + '">' + items[i].label + '</div>';
            }
        }
        $contextMenu.innerHTML = html;

        // 绑定点击
        $contextMenu.addEventListener('click', function (e) {
            e.stopPropagation();
        });
        var menuItems = $contextMenu.querySelectorAll('.ctx-menu-item');
        menuItems.forEach(function (item) {
            item.addEventListener('click', function (e) {
                e.stopPropagation();
                var idx = parseInt(this.dataset.idx);
                if (items[idx]) items[idx].action();
                hideContextMenu();
            });
        });

        // 定位
        $contextMenu.style.display = 'block';
        var menuW = $contextMenu.offsetWidth;
        var menuH = $contextMenu.offsetHeight;
        var left = e.clientX;
        var top = e.clientY;
        if (left + menuW > window.innerWidth) left = window.innerWidth - menuW - 5;
        if (top + menuH > window.innerHeight) top = window.innerHeight - menuH - 5;
        if (left < 5) left = 5;
        if (top < 5) top = 5;
        $contextMenu.style.left = left + 'px';
        $contextMenu.style.top = top + 'px';
    }

    function hideContextMenu() {
        if ($contextMenu) $contextMenu.style.display = 'none';
    }

    function copyToClipboard(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }

    document.addEventListener('click', function (e) {
        if ($contextMenu && !$contextMenu.contains(e.target)) {
            hideContextMenu();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hideContextMenu();
    });

    // 委托：消息右键菜单
    $messages.addEventListener('contextmenu', function (e) {
        var bubble = e.target.closest('.lobby-msg');
        if (!bubble || !bubble.dataset.msgId) return;
        e.preventDefault();
        var msgData = {
            id: parseInt(bubble.dataset.msgId),
            sender_name: bubble.dataset.senderName || '',
            content: bubble.dataset.msgContent || '',
            created_at: bubble.dataset.createdAt || '',
        };
        showMsgContextMenu(e, msgData, bubble);
    });

    function scrollToBottom() {
        if (stickyScroll) return;
        $messages.scrollTop = $messages.scrollHeight;
    }

    // ==================== 发送消息 ====================
    function sendMessage() {
        const content = $chatInput.value.trim();
        if (!content) return;

        const data = {
            type: 'lobby_chat',
            nickname: myNickname,
            content: content
        };

        if (replyTarget) {
            data.reply_to_id = replyTarget.id;
            data.reply_to_name = replyTarget.name;
            data.reply_to_text = replyTarget.text;
            replyTarget = null;
            hideReplyPreview();
        }

        send(data);
        $chatInput.value = '';
        hideMentionDropdown();
    }

    $btnSend.addEventListener('click', sendMessage);
    $chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            if ($mentionDropdown && $mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                selectMentionedUser();
                return;
            }
            sendMessage();
        }
        if (e.key === 'Escape') {
            hideMentionDropdown();
        }
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            if ($mentionDropdown && $mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                navigateMentionDropdown(e.key === 'ArrowDown' ? 1 : -1);
            }
        }
        if (e.key === 'Tab') {
            if ($mentionDropdown && $mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                selectMentionedUser();
            }
        }
    });

    // ==================== @ 提及自动补全 ====================
    let $mentionDropdown = null;
    let mentionStartPos = -1;
    let selectedMentionIndex = 0;

    function ensureMentionDropdown() {
        if ($mentionDropdown) return;
        $mentionDropdown = document.createElement('div');
        $mentionDropdown.className = 'lobby-mention-dropdown';
        $mentionDropdown.style.display = 'none';
        $chatInput.parentNode.appendChild($mentionDropdown);
    }

    $chatInput.addEventListener('keyup', function () {
        var value = $chatInput.value;
        var cursorPos = $chatInput.selectionStart;
        var textBeforeCursor = value.substring(0, cursorPos);
        var atMatch = /@(\S*)$/.exec(textBeforeCursor);

        if (atMatch) {
            ensureMentionDropdown();
            var query = atMatch[1].toLowerCase();
            var filtered = onlinePlayers.filter(function (p) {
                return p.nickname && p.nickname !== myNickname && p.nickname.toLowerCase().indexOf(query) >= 0;
            });

            if (filtered.length > 0) {
                selectedMentionIndex = 0;
                renderMentionDropdown(filtered, textBeforeCursor.length - atMatch[0].length);
                return;
            }
        }
        hideMentionDropdown();
    });

    function renderMentionDropdown(players, startPos) {
        mentionStartPos = startPos;
        $mentionDropdown.innerHTML = '';
        players.forEach(function (p, i) {
            var item = document.createElement('div');
            item.className = 'lobby-mention-item' + (i === 0 ? ' active' : '');
            item.textContent = p.nickname;
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                insertMention(p.nickname);
            });
            $mentionDropdown.appendChild(item);
        });
        // 定位到输入框上方
        var inputRect = $chatInput.getBoundingClientRect();
        $mentionDropdown.style.left = inputRect.left + 'px';
        $mentionDropdown.style.bottom = (window.innerHeight - inputRect.top + 8) + 'px';
        $mentionDropdown.style.display = 'block';
    }

    function navigateMentionDropdown(direction) {
        var items = $mentionDropdown.querySelectorAll('.lobby-mention-item');
        if (items.length === 0) return;
        items[selectedMentionIndex].classList.remove('active');
        selectedMentionIndex = (selectedMentionIndex + direction + items.length) % items.length;
        items[selectedMentionIndex].classList.add('active');
        items[selectedMentionIndex].scrollIntoView({ block: 'nearest' });
    }

    function selectMentionedUser() {
        var items = $mentionDropdown.querySelectorAll('.lobby-mention-item');
        if (items.length === 0 || selectedMentionIndex < 0) return;
        var name = items[selectedMentionIndex].textContent;
        insertMention(name);
    }

    function insertMention(nickname) {
        var value = $chatInput.value;
        var before = value.substring(0, mentionStartPos);
        var after = value.substring($chatInput.selectionStart);
        $chatInput.value = before + '@' + nickname + ' ' + after;
        var newCursor = mentionStartPos + nickname.length + 2;
        $chatInput.setSelectionRange(newCursor, newCursor);
        $chatInput.focus();
        hideMentionDropdown();
    }

    function hideMentionDropdown() {
        if ($mentionDropdown) {
            $mentionDropdown.style.display = 'none';
        }
        mentionStartPos = -1;
        selectedMentionIndex = 0;
    }

    // ==================== 引用 ====================
    function showReplyPreview() {
        if (!replyTarget) return;
        var previewText = replyTarget.text || '';
        if (/^\[sticker:/.test(previewText)) {
            previewText = '[表情]';
        } else if (previewText.length > 50) {
            previewText = previewText.substring(0, 50) + '...';
        }
        $replyPreviewText.textContent = replyTarget.name + ': ' + previewText;
        $replyPreview.classList.add('show');
        $chatInput.focus();
    }

    function hideReplyPreview() {
        $replyPreview.classList.remove('show');
        $replyPreviewText.textContent = '';
    }

    $replyPreviewCancel.addEventListener('click', function () {
        replyTarget = null;
        hideReplyPreview();
    });

    // ==================== 举报 ====================
    function showReportDialog(messageId, targetName, messageContent) {
        const overlay = document.createElement('div');
        overlay.className = 'lobby-report-overlay';

        overlay.innerHTML =
            '<div class="lobby-report-card">' +
                '<h3>举报消息</h3>' +
                '<p style="font-size:13px;color:var(--text-secondary);margin-bottom:10px;">举报来自 <strong>' + escapeHtml(targetName) + '</strong> 的消息</p>' +
                '<p style="font-size:12px;color:var(--text-subtle);background:var(--surface-violet-subtle, #f3f0ff);padding:6px 10px;border-radius:6px;margin-bottom:10px;max-height:60px;overflow:hidden;">' + escapeHtml(messageContent || '（空消息）') + '</p>' +
                '<select id="lobby-report-reason">' +
                    '<option value="">请选择举报原因</option>' +
                    '<option value="垃圾广告">垃圾广告</option>' +
                    '<option value="人身攻击">人身攻击</option>' +
                    '<option value="涉黄内容">涉黄内容</option>' +
                    '<option value="骚扰信息">骚扰信息</option>' +
                    '<option value="其他违规">其他违规</option>' +
                '</select>' +
                '<div class="btn-group">' +
                    '<button id="lobby-report-cancel" class="doodle-btn" style="font-size:14px;">取消</button>' +
                    '<button id="lobby-report-submit" class="doodle-btn danger" style="font-size:14px;">提交举报</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        const $reason = overlay.querySelector('#lobby-report-reason');
        overlay.querySelector('#lobby-report-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

        overlay.querySelector('#lobby-report-submit').addEventListener('click', function () {
            const reason = $reason.value;
            if (!reason) { showTopToast('请选择举报原因', true); return; }
            send({
                type: 'lobby_report',
                message_id: messageId,
                reason: reason
            });
            overlay.remove();
        });
    }

    // ==================== 在线玩家列表 ====================
    function updateOnlineCount(data) {
        var players = data.players || [];
        onlinePlayers = players;
        onlinePlayerCount = players.length;

        if ($usersCount) $usersCount.textContent = onlinePlayerCount;
        if ($usersList) {
            $usersList.innerHTML = '';
            players.forEach(function (p) {
                var item = document.createElement('div');
                item.className = 'lobby-user-item';
                if (p.nickname && p.nickname === myNickname) {
                    item.classList.add('you');
                }
                var avatar = document.createElement('span');
                avatar.className = 'user-avatar';
                avatar.textContent = getAvatarChar(p.nickname || '?');
                avatar.style.background = getAvatarColor(p.nickname || '');
                item.appendChild(avatar);
                item.appendChild(document.createTextNode(p.nickname || '匿名'));
                $usersList.appendChild(item);
            });
        }

        // 刷新右上角连接状态栏中的在线人数
        renderConnStatus();
    }

    // ==================== 表情 ====================
    let stickerMap = loadStickerCache();

    function renderStickerPicker() {
        const fresh = loadStickerCache();
        if (Object.keys(fresh).length > 0) stickerMap = fresh;

        renderSharedStickerPicker($stickerPickerBody, stickerMap, function (id) {
            send({ type: 'lobby_chat', nickname: myNickname, content: '[sticker:' + id + ']' });
            $stickerPicker.style.display = 'none';
        });
    }

    bindStickerPickerTabs('lobby-sticker-picker', renderStickerPicker);

    function showStickerLightbox(url) {
        $stickerLightboxImg.src = url;
        $stickerLightbox.style.display = 'flex';
    }

    $btnSticker.addEventListener('click', function () {
        if ($stickerPicker.style.display === 'none' || !$stickerPicker.style.display) {
            renderStickerPicker();
            $stickerPicker.style.visibility = 'hidden';
            $stickerPicker.style.display = 'flex';
            const btnRect = $btnSticker.getBoundingClientRect();
            const pickerWidth = $stickerPicker.offsetWidth || 260;
            const pickerHeight = $stickerPicker.offsetHeight;
            let left = btnRect.right - pickerWidth;
            if (left + pickerWidth > window.innerWidth) left = window.innerWidth - pickerWidth - 10;
            if (left < 10) left = 10;
            $stickerPicker.style.left = left + 'px';
            $stickerPicker.style.top = (btnRect.top - pickerHeight - 16) + 'px';
            $stickerPicker.style.visibility = 'visible';
        } else {
            $stickerPicker.style.display = 'none';
        }
    });

    $btnCloseStickerPicker.addEventListener('click', function () {
        $stickerPicker.style.display = 'none';
    });

    document.addEventListener('click', function (e) {
        if ($stickerPicker.style.display === 'flex' &&
            !$stickerPicker.contains(e.target) &&
            e.target !== $btnSticker &&
            !$btnSticker.contains(e.target)) {
            $stickerPicker.style.display = 'none';
        }
    });

    $stickerLightboxClose.addEventListener('click', function () {
        $stickerLightbox.style.display = 'none';
    });
    $stickerLightbox.addEventListener('click', function (e) {
        if (e.target === $stickerLightbox || e.target.className === 'sticker-lightbox-bg') {
            $stickerLightbox.style.display = 'none';
        }
    });

    // ==================== 用户列表切换 ====================
    $btnToggleUsers.addEventListener('click', function () {
        if (!$usersPanel) return;
        if ($usersPanel.style.display === 'none') {
            $usersPanel.style.display = 'flex';
        } else {
            $usersPanel.style.display = 'none';
        }
    });

    // ==================== 消息滚动 ====================
    $messages.addEventListener('scroll', function () {
        stickyScroll = $messages.scrollTop + $messages.clientHeight < $messages.scrollHeight - 40;
    });

    // ==================== 工具 ====================
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeHtmlAttr(text) {
        return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function parseStickerId(text) {
        const m = text.match(/^\[sticker:([^\]]+)\]/);
        return m ? m[1] : null;
    }

    // ==================== 初始化 ====================
    showIdentityState();

    // 如果处于iframe环境
    if (window.self !== window.top) {
        $header.style.display = 'none';
        $lobbyChatHeader.style.display = 'none';
        $main.style.height = '100vh';
    }
})();
