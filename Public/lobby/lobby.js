/**
 * 公共聊天室 - 客户端
 */
(function () {
    'use strict';

    const WS_URL = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') + window.location.host + '/ws/lobby';
    const RECONNECT_DELAY = 2000;
    const HEARTBEAT_INTERVAL = 20000;
    const PONG_GRACE = 15000;

    // 全局捕获图片加载错误：任何 <img> 加载失败统一替换为提示文本
    document.addEventListener('error', function (e) {
        const t = e.target;
        if (!t || t.tagName !== 'IMG' || !t.parentNode) return;
        // 排除 lightbox 大图与点歌封面：这些 img 由 JS 动态设置 src，
        // 加载失败不应被替换，否则会破坏元素引用导致后续无法更新
        if (t.id === 'lobby-sticker-lightbox-img' || t.id === 'lobby-song-info-cover') return;
        const span = document.createElement('span');
        span.className = 'md-img-error';
        span.innerHTML = '<svg class="md-img-error-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>我图图呢？！';
        t.parentNode.replaceChild(span, t);
    }, true);

    // ==================== DOM ====================
    const $header = document.querySelector('header');
    const $main = document.getElementById('lobby-main');
    const $lobbyChatHeader = $header;
    const $lyrics = document.getElementById('lobby-lyrics');
    const $hasIdentity = document.getElementById('lobby-has-identity');
    const $noIdentity = document.getElementById('lobby-no-identity');
    const $messages = document.getElementById('lobby-messages');
    const BILI_SPINNER_SVG = '<svg class="bili-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="31.4 31.4" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite"/></circle></svg>';
    const $chatInput = document.getElementById('lobby-chat-input');
    const $btnSend = document.getElementById('lobby-btn-send');
    const $btnSticker = document.getElementById('lobby-btn-sticker');
    const $stickerPicker = document.getElementById('lobby-sticker-picker');
    const $stickerPickerBody = document.getElementById('lobby-sticker-picker-body');
    const $btnCloseStickerPicker = document.getElementById('lobby-btn-close-sticker-picker');
    const $stickerLightbox = document.getElementById('lobby-sticker-lightbox');
    const $stickerLightboxImg = document.getElementById('lobby-sticker-lightbox-img');
    const $stickerLightboxClose = document.getElementById('lobby-sticker-lightbox-close');
    const $usersList = document.getElementById('lobby-users-list');
    const $usersCount = document.getElementById('lobby-users-count');
    const $usersPanel = document.getElementById('lobby-users-panel');
    const $btnToggleUsers = document.getElementById('btn-toggle-users');
    const $overlay = document.getElementById('lobby-overlay');
    const $btnBack = document.getElementById('btn-back');
    const $replyPreview = document.getElementById('lobby-reply-preview');
    const $replyPreviewText = document.getElementById('lobby-reply-preview-text');
    const $replyPreviewCancel = document.getElementById('lobby-reply-preview-cancel');
    const $btnNotify = document.getElementById('lobby-btn-notify');
    // 身份状态 DOM
    const $btnGoHome = document.getElementById('lobby-btn-go-home');
    // 点歌系统
    const $btnSong = document.getElementById('lobby-btn-song');
    const $songPanel = document.getElementById('lobby-song-panel');
    const $songPlaylist = document.getElementById('lobby-song-playlist');
    const $songPlayingInfo = document.getElementById('lobby-song-playing-info');
    const $songSearchInput = document.getElementById('lobby-song-search-input');
    const $songSearchBtn = document.getElementById('lobby-song-search-btn');
    const $songSearchClear = document.getElementById('lobby-song-search-clear');
    const $songSearchResults = document.getElementById('lobby-song-search-results');
    const $songListenToggle = document.getElementById('lobby-song-listen-toggle');
    const $songSyncToggle = document.getElementById('lobby-song-sync-toggle');
    const $songSyncLabel = document.getElementById('lobby-song-sync-label');
    const $songInfo = document.getElementById('lobby-song-info');
    const $songInfoCover = document.getElementById('lobby-song-info-cover');
    const $songInfoName = document.getElementById('lobby-song-info-name');
    const $songInfoArtist = document.getElementById('lobby-song-info-artist');
    const $songInfoProgressBar = document.getElementById('lobby-song-info-progress-bar');
    const $songInfoTime = document.getElementById('lobby-song-info-time');
    const $songInfoAdder = document.getElementById('lobby-song-info-adder');
    const $songInfoNext = document.getElementById('lobby-song-info-next');

    // ==================== 状态 ====================
    let ws = null;
    let heartbeatTimer = null;
    let pongTimer = null;
    let reconnectTimer = null;
    let reconnecting = false;
    let intentionalClose = false;
    let banned = false;
    let myNickname = '';
    let lastSentStickerId = '';   // 本地渲染去重，防止服务端广播回传导致重复
    let replyTarget = null;      // { id, name, text }
    let isLobbyAdmin = false;    // 管理员状态（lobby_admin_verify 验证通过后为 true）
    let stickyScroll = false;
    let onlinePlayers = [];      // [{ fd, nickname }] — 在线玩家列表
    let onlinePlayerCount = 0;   // 缓存在线人数，用于右上角状态栏显示

    // ==================== 点歌状态 ====================
    let songPlaying = null;       // { id, name, artist, picurl, url, duration, adder, start_time }
    let lyricsLines = [];          // [{time: seconds, text: "..."}]——LRC 解析结果
    let songList = [];            // [{ id, name, artist, duration, votes, adder, remove_votes }]  播放队列
    let songPool = [];            // [{ id, name, artist, votes, voter_count }]      投票池
    let removeVotedSongs = new Set();  // 自己已投移除票的歌曲 ID（String）
    let songAudioA = new Audio();
    let songAudioB = new Audio();
    let songCurAudio = null;      // 当前正在播放的 Audio 实例
    let preloadedSongId = null;   // 已预加载下一首的歌曲 ID
    let preloadedLrc = [];        // 已预加载下一首的歌词行
    let songProgressTimer = null; // 进度条更新定时器
    let songSyncTimer = null;     // 定期同步检查定时器（10s）
    let lastSongServerTime = 0;   // 上次收到服务器歌曲广播的时间戳
    let audioUnlocked = false;    // 浏览器自动播放策略是否已解锁
    let songListen = getUserdata().song_listen ?? false;  // 是否参与听歌
    let songSyncMode = getUserdata().song_sync_mode ?? true;  // true=同步模式，false=个人模式

    // ==================== 浏览器通知 ====================
    let notifyEnabled = getUserdata().lobby_notify ?? false;

    function updateNotifyUI() {
        if (!$btnNotify) return;
        if (notifyEnabled) {
            $btnNotify.classList.add('enabled');
            $btnNotify.title = '通知已开启';
        } else {
            $btnNotify.classList.remove('enabled');
            $btnNotify.title = '通知已关闭';
        }
    }

    function requestNotifyPermission() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') return;
        if (Notification.permission === 'denied') {
            showTopToast('通知权限已被浏览器拒绝，请在浏览器设置中开启', true);
            return;
        }
        Notification.requestPermission().then((perm) => {
            if (perm === 'granted') {
                notifyEnabled = true;
                (ud => { ud.lobby_notify = true; saveUserdata(ud); })(getUserdata());
                updateNotifyUI();
                showTopToast('通知已开启 — 有人@你或切后台时会提醒', false);
            } else {
                notifyEnabled = false;
                (ud => { ud.lobby_notify = false; saveUserdata(ud); })(getUserdata());
                updateNotifyUI();
                showTopToast('通知权限未授权', true);
            }
        });
    }

    function sendNotification(title, body) {
        if (!notifyEnabled) return;
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        try {
            new Notification(title, {
                body: body,
                icon: '/favicon.svg'
            });
        } catch (e) {
            // 忽略通知失败
        }
    }

    $btnNotify.addEventListener('click', function () {
        if (!notifyEnabled) {
            if (!('Notification' in window)) {
                showTopToast('当前浏览器不支持通知功能', true);
                return;
            }
            if (Notification.permission === 'granted') {
                notifyEnabled = true;
                (ud => { ud.lobby_notify = true; saveUserdata(ud); })(getUserdata());
                updateNotifyUI();
                showTopToast('通知已开启', false);
            } else if (Notification.permission === 'denied') {
                showTopToast('通知权限已被拒绝，请在浏览器设置中开启', true);
            } else {
                requestNotifyPermission();
            }
        } else {
            notifyEnabled = false;
            (ud => { ud.lobby_notify = false; saveUserdata(ud); })(getUserdata());
            updateNotifyUI();
            showTopToast('通知已关闭', true);
        }
    });

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
        let nickname = getUserNickname();
        let token = getUserToken();

        if (nickname && token) {
            myNickname = nickname;
            document.getElementById('lobby-match-panel').style.display = 'none';
            document.getElementById('lobby-main').style.removeProperty('display');
            $hasIdentity.style.display = 'flex';
            $noIdentity.style.display = 'none';
            connect();
        } else {
            document.getElementById('lobby-match-panel').style.display = '';
            document.getElementById('lobby-main').style.display = 'none';
            $hasIdentity.style.display = 'none';
            $noIdentity.style.display = 'flex';
        }
    }

    function sendJoin() {
        send({
            type: 'lobby_join',
            nickname: myNickname,
            player_token: getUserToken() || ''
        });
    }

    // 读取 Cookie（管理员 token 等）
    function getLobbyCookie(name) {
        let m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function handleJoined(data) {
        if (data.token && !getUserToken()) {
            setUserToken(data.token);
        }
        if (data.nickname && data.nickname !== myNickname) {
            myNickname = data.nickname;
            setUserNickname(myNickname);
        }
        if (!getUserNickname()) {
            setUserNickname(myNickname);
        }
        $hasIdentity.style.display = 'flex';
        $noIdentity.style.display = 'none';
        send({ type: 'lobby_song_current' });
        send({ type: 'lobby_song_list' });
        // 管理员验证：存在管理 token 时注册为聊天室管理员（可使用 \ban 等指令）
        let adminTok = getLobbyCookie('turing_admin_token');
        if (adminTok) {
            send({ type: 'lobby_admin_verify', token: adminTok });
        }
    }

    // 返回首页
    $btnGoHome.addEventListener('click', function () {
        leaveLobbyGracefully('/');
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
                if (!isMineMessage(data) && document.hidden) {
                    let preview = data.content || '';
                    if (preview.length > 60) preview = preview.substring(0, 60) + '...';
                    sendNotification(data.sender_name, preview);
                }
                break;

            case 'sticker':
                // 服务端回传确认：若本地已渲染过该表情，更新消息ID（供撤回/回复）并跳过重复追加
                let stickerSid = data.sticker_id || data.id || '';
                if (lastSentStickerId && lastSentStickerId === stickerSid) {
                    let localBubble = document.querySelector('[data-local-sticker="' + lastSentStickerId + '"]');
                    if (localBubble) localBubble.dataset.msgId = data.id;
                    lastSentStickerId = '';
                    break;
                }
                appendStickerMessage(data);
                break;

            case 'lobby_joined':
                handleJoined(data);
                break;

            case 'lobby_admin_verified':
                isLobbyAdmin = !!data.is_admin;
                if (data.is_admin) {
                    showTopToast('管理员模式已激活', false);
                    // 刷新在线列表与歌单（显示管理操作按钮）
                    if ($usersList) renderUsersList();
                    renderSongPanel();
                }
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
                sendNotification('有人@了你', data.sender_name + ' 在公共聊天室提到了你');
                break;

            case 'lobby_nudged':
                handleNudged(data.sender_name);
                break;

            case 'system':
                if (data.text && (data.text.includes('已有活跃连接') || data.text.includes('已在其他地方登录'))) {
                    showTopToast(data.text, true);
                    intentionalClose = true;
                    stopHeartbeat();
                    if (ws) { try { ws.close(); } catch (e) { } ws = null; }
                }
                break;

            case 'error':
                showBanned(data.message || '您已被管理员封禁');
                break;

            case 'lobby_error':
                showTopToast(data.text || data.message || '操作失败', true);
                break;

            case 'lobby_btn_click_result':
                handleBtnClickResult(data);
                break;

            case 'stickers_list':
                stickerMap = handleStickersList(data);
                // 表情选择器打开时自动刷新显示（修复首次加载需多次点击的问题）
                if ($stickerPicker && $stickerPicker.style.display === 'flex') {
                    renderStickerPicker();
                }
                break;

            case 'stickers_unchanged':
                stickerMap = loadStickerCache();
                break;

            case 'broadcast':
                showDanmaku(data.text, '全服公告', data.duration || 0);
                break;

            case 'room_announce':
                showDanmaku(data.text, '管理警告');
                break;

            case 'lobby_song_current':
                // 收到当前播放歌曲（用户加入时自动获取）
                if (data.song) {
                    handleForcePlay({ song: data.song, start_time: data.song.start_time || Date.now() / 1000 });
                }
                break;

            case 'lobby_song_search_result':
                renderSongSearchResults(data.songs || []);
                break;

            case 'lobby_song_list':
                songList = data.playlist || [];
                songPool = data.pool || [];
                if (data.playing) {
                    handleForcePlay({ song: data.playing, start_time: data.playing.start_time || Date.now() / 1000 });
                } else {
                    songPlaying = null;
                    stopSongPlayback();
                    updateConnStatusSong();
                }
                renderSongPanel();
                break;

            case 'lobby_song_requested':
                showTopToast('已点歌: ' + (data.song ? data.song.name : ''), false);
                renderSongPanel();
                break;

            case 'list_update':
                songList = data.playlist || [];
                songPool = data.pool || [];
                if (data.playing) {
                    handleForcePlay({ song: data.playing, start_time: data.playing.start_time || Date.now() / 1000 });
                } else {
                    songPlaying = null;
                    stopSongPlayback();
                    updateConnStatusSong();
                }
                renderSongPanel();
                break;

            case 'lobby_vote_update':
                handleVoteUpdate(data);
                break;

            case 'lobby_remove_vote_update':
                handleRemoveVoteUpdate(data);
                break;

            case 'waiting_vote':
                songPlaying = null;
                stopSongPlayback();
                updateConnStatusSong();
                renderSongPanel();
                break;
        }
    }

    // ==================== 封禁处理 ====================
    function showBanned(message) {
        banned = true;
        intentionalClose = true;
        stopHeartbeat();
        if (ws) {
            try { ws.close(); } catch (e) { }
            ws = null;
        }

        let container = document.querySelector('.lobby-container');
        if (!container) return;

        container.innerHTML =
            '<div class="lobby-identity-card">' +
            '<svg class="icon" viewBox="0 0 24 24" style="width:48px;height:48px;stroke:let(--danger);margin-bottom:8px;">' +
            '<circle cx="12" cy="12" r="10"/>' +
            '<line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>' +
            '</svg>' +
            '<h2>无法访问聊天室</h2>' +
            '<p>' + escapeHtml(message) + '</p>' +
            '<a href="/" class="doodle-btn btn-full" style="text-decoration:none;">返回首页</a>' +
            '</div>';
    }

    // ==================== 消息渲染 ====================

    function getAvatarChar(name) {
        if (!name) return '?';
        return name.charAt(0);
    }

    function getAvatarColor(name) {
        if (!name) return 'let(--note-green)';
        let colors = ['let(--note-green)', 'let(--note-blue)', 'let(--note-yellow)', 'let(--note-pink)', '#d1f2d3', '#d3e2ed', '#fdf5c9', '#fde2e4'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        return colors[Math.abs(hash) % colors.length];
    }

    function insertMention(name) {
        if (!name || !$chatInput) return;
        let at = '@' + name + ' ';
        if (document.activeElement === $chatInput) {
            let start = $chatInput.selectionStart;
            let end = $chatInput.selectionEnd;
            let val = $chatInput.value;
            $chatInput.value = val.substring(0, start) + at + val.substring(end);
            $chatInput.selectionStart = $chatInput.selectionEnd = start + at.length;
        } else {
            $chatInput.value += at;
        }
        $chatInput.focus();
    }

    // ==================== 拍一拍 ====================

    function nudgeUser(targetNickname) {
        if (!targetNickname || targetNickname === myNickname) return;
        // 从在线列表中查找 fd
        let target = null;
        for (let i = 0; i < onlinePlayers.length; i++) {
            if (onlinePlayers[i].nickname === targetNickname) {
                target = onlinePlayers[i];
                break;
            }
        }
        if (!target) {
            showTopToast('该玩家已离线', true);
            return;
        }
        send({
            type: 'lobby_nudge',
            target_fd: target.fd,
            target_nickname: targetNickname
        });
        showTopToast('你拍了拍 ' + targetNickname, false);
    }

    /**
     * 为头像元素绑定拍一拍交互（双击 / 双拍）
     */
    function addAvatarNudgeHandler(el, targetNickname) {
        if (!el || !targetNickname) return;

        // 桌面端：双击
        el.addEventListener('dblclick', function (e) {
            if (scrollGuard) return;
            e.preventDefault();
            e.stopPropagation();
            nudgeUser(targetNickname);
        });

        // 移动端：双拍检测（两次 tap 间隔 ≤ 300ms）
        let lastTap = 0;
        el.addEventListener('touchend', function (e) {
            if (scrollGuard) { lastTap = 0; return; }
            let now = Date.now();
            if (now - lastTap < 300) {
                e.preventDefault();
                nudgeUser(targetNickname);
                lastTap = 0;
            } else {
                lastTap = now;
            }
        });
    }

    /**
     * 收到拍一拍通知：抖一抖聊天头部 + toast
     */
    function handleNudged(senderName) {
        showTopToast(senderName + ' 拍了拍你', false);
        if ($lobbyChatHeader) {
            $lobbyChatHeader.classList.add('nudged');
            setTimeout(function () {
                $lobbyChatHeader.classList.remove('nudged');
            }, 600);
        }
    }

    function isMineMessage(data) {
        return data.sender_name === myNickname;
    }

    /**
     * 渲染战绩分享卡片（JSON 格式，兼容历史 XML）
     * JSON: {type:'record', title, player, fields:{wins,losses,games,rate}, footer}
     */
    function renderRecordCard(cardText) {
        let title = '战绩', player = '', footer = '', fields = { wins: '0', losses: '0', games: '0', rate: '0' };
        let text = String(cardText || '').trim();
        // 优先 JSON 解析
        if (text.charAt(0) === '{') {
            try {
                let card = JSON.parse(text);
                title = card.title || '战绩';
                player = card.player || '';
                footer = card.footer || '';
                let f = card.fields || {};
                fields = {
                    wins: String(f.wins != null ? f.wins : '0'),
                    losses: String(f.losses != null ? f.losses : '0'),
                    games: String(f.games != null ? f.games : '0'),
                    rate: String(f.rate != null ? f.rate : '0')
                };
            } catch (e) {
                return null;
            }
        } else {
            // 兼容历史 XML 卡片
            try {
                let doc = new DOMParser().parseFromString(text, 'text/xml');
                let cardEl = doc.querySelector('card');
                if (!cardEl) return null;
                title = cardEl.querySelector('title') ? cardEl.querySelector('title').textContent : '战绩';
                player = cardEl.querySelector('player') ? cardEl.querySelector('player').textContent : '';
                footer = cardEl.querySelector('footer') ? cardEl.querySelector('footer').textContent : '';
                let fs = cardEl.querySelectorAll('field');
                for (let i = 0; i < fs.length; i++) {
                    let n = fs[i].getAttribute('name');
                    if (n) fields[n] = fs[i].textContent;
                }
            } catch (e) {
                return null;
            }
        }
        return '<div class="record-card" style="padding:0">' +
            '<div class="rc-header">' + escapeHtml(title) + '</div>' +
            '<div class="rc-body">' +
            '<div class="rc-item"><b>' + escapeHtml(fields['wins'] || '0') + '</b><span>胜</span></div>' +
            '<div class="rc-item"><b>' + escapeHtml(fields['losses'] || '0') + '</b><span>负</span></div>' +
            '<div class="rc-item"><b>' + escapeHtml(fields['games'] || '0') + '</b><span>总场</span></div>' +
            '<div class="rc-item"><b>' + escapeHtml(fields['rate'] || '0') + '%</b><span>胜率</span></div>' +
            '</div>' +
            (footer ? '<div class="rc-footer">' + escapeHtml(footer) + '</div>' : '') +
            '</div>';
    }

    /**
     * 渲染五子棋对局邀请卡片
     * JSON: {type:'gomoku_invite', title, player, room, footer}
     */
    function renderGomokuInviteCard(cardText) {
        let card = null;
        try { card = JSON.parse(String(cardText || '')); } catch (e) { return null; }
        if (!card || card.type !== 'gomoku_invite') return null;
        let roomCode = String(card.room || '').toUpperCase();
        return '<div class="gomoku-invite-card" style="padding:0">' +
            '<div class="gi-banner"><svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#fff;stroke-width:1.6;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>五子棋对局邀请</div>' +
            '<div class="gi-title">' + escapeHtml(card.title || '五子棋对局') + '</div>' +
            '<div class="gi-room">房间凭证 <b>' + escapeHtml(roomCode) + '</b></div>' +
            '<button class="doodle-btn gi-join-btn" data-room="' + escapeHtmlAttr(roomCode) + '">加入对局</button>' +
            '</div>';
    }

    function makeBubble(data, isMine) {
        let senderName = data.sender_name || '';

        let wrapper = document.createElement('div');
        wrapper.className = 'lobby-msg-row';
        if (isMine) wrapper.classList.add('mine');
        // 被 @ 提及的消息高亮
        if (data.mentions && Array.isArray(data.mentions) && data.mentions.indexOf(myNickname) >= 0) {
            wrapper.classList.add('mentioned');
        }

        // 头像
        let avatar = document.createElement('div');
        avatar.className = 'lobby-avatar';
        avatar.textContent = getAvatarChar(senderName);
        avatar.style.background = isMine ? 'let(--note-blue)' : getAvatarColor(senderName);

        // 长按头像 → @昵称
        (function (av, name) {
            let timer = null;
            let started = false;

            function onStart(e) {
                if (scrollGuard) return;
                started = false;
                timer = setTimeout(function () {
                    started = true;
                    av.classList.add('longpress');
                    insertMention(name);
                }, 500);
            }

            function onEnd() {
                clearTimeout(timer);
                timer = null;
                av.classList.remove('longpress');
            }

            av.addEventListener('mousedown', onStart);
            av.addEventListener('touchstart', onStart, { passive: true });
            av.addEventListener('mouseup', onEnd);
            av.addEventListener('mouseleave', onEnd);
            av.addEventListener('touchend', onEnd);
            av.addEventListener('touchcancel', onEnd);
            // 阻止长按选中文本
            av.addEventListener('selectstart', function (e) { if (timer) e.preventDefault(); });
            // 阻止长按弹出菜单
            av.addEventListener('contextmenu', function (e) { if (started) e.preventDefault(); });
        })(avatar, senderName);

        // 拍一拍：双击头像
        addAvatarNudgeHandler(avatar, senderName);

        // 右侧内容区：名字时间 + 气泡
        let content = document.createElement('div');
        content.className = 'lobby-msg-content';

        // 名字 + 时间
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

        // 气泡
        let bubble = document.createElement('div');
        bubble.className = 'lobby-msg' + (isMine ? ' mine' : '');
        bubble.dataset.msgId = data.id;
        bubble.dataset.createdAt = data.created_at || '';
        bubble.dataset.senderName = senderName;
        bubble.dataset.msgContent = (data.type === 'sticker' && data.sticker_id)
            ? '[sticker:' + data.sticker_id + ']'
            : (data.content || '');

        // 已撤回的消息
        if (data.revoked) {
            bubble.classList.add('revoked');
            bubble.innerHTML = '<div class="lobby-msg-text revoked-text">消息已撤回</div>';
            content.appendChild(bubble);
            wrapper.appendChild(avatar);
            wrapper.appendChild(content);
            return wrapper;
        }

        let replyHtml = '';
        if (data.reply_to && data.reply_to.id) {
            // 引用块同样走 mdFormat（支持内嵌 md 组件渲染），有 DOMPurify 消毒
            let replyTextHtml = mdFormat(data.reply_to.text || '');
            // 回复的是表情消息：引用块显示表情包图片
            let replyStickerMatch = String(data.reply_to.text || '').match(/^\[sticker:(.+?)\]$/);
            if (replyStickerMatch) {
                let replyStickerUrl = resolveStickerUrl(replyStickerMatch[1], '', stickerMap);
                replyTextHtml = replyStickerUrl
                    ? '<img class="reply-sticker-img" src="' + escapeHtmlAttr(replyStickerUrl) + '" alt="表情">'
                    : '[表情]';
            }
            replyHtml = '<div class="lobby-msg-reply" data-reply-id="' + data.reply_to.id + '">' +
                '<span class="reply-name">' + escapeHtml(data.reply_to.name) + '</span>: ' +
                replyTextHtml +
                '</div>';
        }

        // 表情消息：渲染为图片
        if (data.type === 'sticker' && data.sticker_id) {
            let stickerUrl = resolveStickerUrl(data.sticker_id, data.sticker_url, stickerMap);
            bubble.innerHTML = stickerUrl
                ? '<img class="sticker-img" src="' + escapeHtmlAttr(stickerUrl) + '" alt="表情" title="' + escapeHtmlAttr(data.sticker_name || '') + '">'
                : '<span style="color:#999;font-style:italic;">[表情不存在: ' + escapeHtml(data.sticker_id) + ']</span>';
            if (stickerUrl) {
                (function (url) {
                    let img = bubble.querySelector('.sticker-img');
                    if (img) {
                        img.addEventListener('click', function () {
                            showStickerLightbox(url);
                        });
                    }
                })(stickerUrl);
            }
        } else if (data.msg_type === 'card.share.record' || data.type === 'card.share.record') {
            // 战绩分享卡片：直接渲染，不套气泡层
            let cardHtml = renderRecordCard(data.content);
            let cardEl = document.createElement('div');
            cardEl.className = 'lobby-card-wrapper';
            cardEl.innerHTML = replyHtml + (cardHtml || '<div class="lobby-msg-text">' + escapeHtml(data.content) + '</div>');
            content.appendChild(cardEl);
            wrapper.appendChild(avatar);
            wrapper.appendChild(content);
            return wrapper;
        } else if (data.msg_type === 'card.invite.gomoku' || data.type === 'card.invite.gomoku') {
            // 五子棋对局邀请卡片：直接渲染，不套气泡层
            let inviteHtml = renderGomokuInviteCard(data.content);
            let cardEl = document.createElement('div');
            cardEl.className = 'lobby-card-wrapper';
            cardEl.innerHTML = replyHtml + (inviteHtml || '<div class="lobby-msg-text">' + escapeHtml(data.content) + '</div>');
            content.appendChild(cardEl);
            wrapper.appendChild(avatar);
            wrapper.appendChild(content);
            return wrapper;
        } else {
            if (isAsciiArt(data.content)) {
                // 字符画：空格渲染为固定 0.5em 宽的占位（中文 1em = 2 空格），任何字体下严格对齐
                let artLines = escapeHtml(data.content).split('\n').map((line) => {
                    return '<div class="aa-line">' + line.replace(/ /g, '<span class="aa-space"></span>') + '</div>';
                }).join('');
                bubble.innerHTML = replyHtml + '<div class="lobby-msg-text ascii-art">' + artLines + '</div>';
            } else {
                bubble.innerHTML =
                    replyHtml +
                    '<div class="lobby-msg-text">' + mdFormat(data.content) + '</div>';
            }
        }

        let replyDiv = bubble.querySelector('.lobby-msg-reply');
        if (replyDiv) {
            replyDiv.addEventListener('click', function () {
                let targetId = this.dataset.replyId;
                let target = document.querySelector('[data-msg-id="' + targetId + '"]');
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    target.style.animation = 'none';
                    target.offsetHeight;
                    target.style.animation = 'lobby-highlight 2s ease';
                }
            });
        }

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        // 初始化 md 组件（倒计时/进度条/条件显示/全局变量）
        initMdComponents(content);
        return wrapper;
    }

    function appendStickerMessage(data) {
        let senderName = data.sender_name || data.sender || '';
        let stickerId = data.sticker_id || data.id || '';
        let stickerUrl = data.sticker_url || data.url || '';
        let stickerName = data.sticker_name || data.name || '';
        let stickerUrl2 = resolveStickerUrl(stickerId, stickerUrl, stickerMap);
        let isMine = senderName === myNickname;

        let wrapper = document.createElement('div');
        wrapper.className = 'lobby-msg-row';
        if (isMine) wrapper.classList.add('mine');

        let avatar = document.createElement('div');
        avatar.className = 'lobby-avatar';
        avatar.textContent = getAvatarChar(senderName);
        avatar.style.background = isMine ? 'let(--note-blue)' : getAvatarColor(senderName);

        // 拍一拍：双击头像
        addAvatarNudgeHandler(avatar, senderName);

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
        // 供右键菜单使用：消息ID、发送者、时间、内容标记
        // 本地即时渲染的消息没有服务端消息ID，先标记 local-sticker，等广播回传后更新 msgId
        if (data.id && /^[0-9]+$/.test(String(data.id))) {
            bubble.dataset.msgId = data.id;
        } else {
            bubble.dataset.localSticker = stickerId;
        }
        bubble.dataset.senderName = senderName;
        bubble.dataset.createdAt = data.created_at || '';
        bubble.dataset.msgContent = '[sticker:' + stickerId + ']';

        let renderStickerImg = function (url) {
            bubble.innerHTML = '<img class="sticker-img" src="' + escapeHtmlAttr(url) + '" alt="表情" title="' + escapeHtmlAttr(stickerName || '') + '">';
            bubble.querySelector('.sticker-img').addEventListener('click', function () {
                showStickerLightbox(url);
            });
        };
        if (stickerUrl2) {
            renderStickerImg(stickerUrl2);
        } else {
            bubble.innerHTML = '<span style="color:#999;font-style:italic;">[表情不存在: ' + escapeHtml(stickerId) + ']</span>';
        }

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        $messages.appendChild(wrapper);
        scrollToBottom();
        updatePlusOneChain();
    }

    function renderHistory(messages) {
        $messages.innerHTML = '';
        if (!messages || messages.length === 0) {
            appendSystem('欢迎来到公共聊天室', true);
            return;
        }
        appendSystem('── 以下是最近消息 ──', false);
        messages.forEach((m) => {
            let bubble = makeBubble(m, isMineMessage(m));
            $messages.appendChild(bubble);
            resolveBilibiliEmbeds(bubble);
        });
        scrollToBottom();
    }

    function appendMessage(data) {
        let bubble = makeBubble(data, isMineMessage(data));
        $messages.appendChild(bubble);
        resolveBilibiliEmbeds(bubble);
        scrollToBottom();
        updatePlusOneChain();
    }

    // ==================== 消息 +1 跟队形 ====================

    function updatePlusOneChain() {
        // 清除所有已有 +1 徽章
        document.querySelectorAll('.lobby-msg-plusone').forEach((el) => { el.remove(); });
        // 解包旧的 bubble-wrap，还原 DOM 结构
        document.querySelectorAll('.lobby-msg-bubble-wrap').forEach((wrap) => {
            let parent = wrap.parentNode;
            while (wrap.firstChild) {
                parent.insertBefore(wrap.firstChild, wrap);
            }
            parent.removeChild(wrap);
        });

        let rows = $messages.querySelectorAll('.lobby-msg-row');
        if (rows.length < 2) return;

        // 从底部向上扫描，找出连续相同内容的消息链
        let chainEnd = rows.length - 1;
        let chainContent = '';

        // 从最后一条有效文本消息开始
        for (let i = rows.length - 1; i >= 0; i--) {
            let row = rows[i];
            let bubble = row.querySelector('.lobby-msg');
            if (!bubble || !bubble.dataset.msgId || bubble.classList.contains('revoked')) continue;
            let textEl = bubble.querySelector('.lobby-msg-text');
            if (!textEl) continue;
            let content = textEl.textContent.trim();
            if (!content) continue;

            chainContent = content;
            chainEnd = i;
            break;
        }

        if (!chainContent) return;

        // 向上扩展链，找到所有连续相同内容的行
        let chainStart = chainEnd;
        for (let j = chainEnd - 1; j >= 0; j--) {
            let row = rows[j];
            let bubble = row.querySelector('.lobby-msg');
            if (!bubble || !bubble.dataset.msgId || bubble.classList.contains('revoked')) break;
            let textEl = bubble.querySelector('.lobby-msg-text');
            if (!textEl) break;
            if (textEl.textContent.trim() === chainContent) {
                chainStart = j;
            } else {
                break;
            }
        }

        let chainLen = chainEnd - chainStart + 1;
        if (chainLen < 2) return;

        // 只在最后一条消息（链尾）显示 +1 徽章
        let row = rows[chainEnd];
        let bubble = row.querySelector('.lobby-msg');
        if (bubble && bubble.dataset.msgId && !bubble.classList.contains('revoked')) {
            let isMine = row.classList.contains('mine');

            // 用横排容器包裹气泡，+1 徽章放在左或右
            let wrap = document.createElement('div');
            wrap.className = 'lobby-msg-bubble-wrap';
            bubble.parentNode.insertBefore(wrap, bubble);
            wrap.appendChild(bubble);

            let badge = document.createElement('span');
            badge.className = 'lobby-msg-plusone';
            badge.textContent = '+1';
            badge.title = '跟队形';
            badge.addEventListener('click', function (e) {
                e.stopPropagation();
                doPlusOne(chainContent);
            });

            if (isMine) {
                // 自己的消息：+1 在气泡左边
                wrap.insertBefore(badge, bubble);
            } else {
                // 对方的消息：+1 在气泡右边
                wrap.appendChild(badge);
            }
        }
    }

    function doPlusOne(content) {
        if (!content) return;
        // 打断机制：发送前先清除链上的 +1 徽章（避免点多次）
        document.querySelectorAll('.lobby-msg-plusone').forEach((el) => { el.remove(); });
        send({
            type: 'lobby_chat',
            nickname: myNickname,
            content: content
        });
    }

    function highlightMentionedMessage(messageId) {
        let el = document.querySelector('[data-msg-id="' + messageId + '"]');
        if (!el) return;
        let row = el.closest('.lobby-msg-row');
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('mentioned');
        row.style.animation = 'none';
        row.offsetHeight;
        row.style.animation = 'lobby-highlight 2s ease';
    }

    // 消息列表数量上限：防止无限增长导致内存占用/卡顿（保留最近 300 条）
    function trimMessages() {
        if (!$messages) return;
        while ($messages.children.length > 300) {
            $messages.removeChild($messages.firstChild);
        }
    }

    function appendSystem(text, withIcon) {        let div = document.createElement('div');
        div.className = 'lobby-msg system';
        if (withIcon) {
            div.innerHTML = '<svg class="sys-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> ' + escapeHtml(text);
        } else {
            div.textContent = text;
        }
        $messages.appendChild(div);
        scrollToBottom();
        trimMessages();
    }

    function removeMessage(messageId) {
        const el = $messages.querySelector('[data-msg-id="' + messageId + '"]');
        if (!el) return;
        const row = el.closest('.lobby-msg-row');
        if (!row) return;
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(function () {
            row.remove();
            updatePlusOneChain();
        }, 300);
    }

    function revokeMessageUI(messageId, senderName) {
        // 先在源消息位置插入系统消息，再移除原消息
        let el = $messages.querySelector('[data-msg-id="' + messageId + '"]');
        let row = el ? el.closest('.lobby-msg-row') : null;

        let div = document.createElement('div');
        div.className = 'lobby-msg system';
        div.textContent = (senderName || '有人') + ' 撤回了一条消息';

        if (row && row.parentNode) {
            row.parentNode.insertBefore(div, row);
        } else {
            $messages.appendChild(div);
            scrollToBottom();
        }

        // 从 DOM 移除原消息
        removeMessage(messageId);

        // 更新所有引用该消息的回复预览
        document.querySelectorAll('.lobby-msg-reply[data-reply-id="' + messageId + '"]').forEach((reply) => {
            reply.innerHTML = '<span class="reply-name">' + escapeHtml(senderName || '有人') + '</span>: <i>消息已撤回</i>';
            reply.classList.add('revoked');
        });
    }

    // ==================== 右键菜单 ====================

    let $contextMenu = null;

    function createContextMenu() {
        if ($contextMenu) return;
        $contextMenu = document.createElement('div');
        $contextMenu.id = 'lobby-msg-context-menu';
        $contextMenu.className = 'lobby-msg-context-menu';
        $contextMenu.style.display = 'none';
        document.body.appendChild($contextMenu);
    }

    // 提取消息"表面文本"：把 md 按钮 [!文字](...) 替换成 文字（去掉语法，显示用户看到的样子）
    function extractSurfaceText(content) {
        let text = String(content || '');
        if (text.indexOf('[!') === -1) return text;
        let result = '';
        let re = /\[!([^\]]+)\]\(/g;
        let lastIndex = 0;
        let m;
        while ((m = re.exec(text))) {
            let start = re.lastIndex;
            let depth = 1;
            let i = start;
            for (; i < text.length; i++) {
                if (text[i] === '(') depth++;
                else if (text[i] === ')') { depth--; if (depth === 0) break; }
            }
            result += text.slice(lastIndex, m.index) + m[1];
            lastIndex = i + 1;
            re.lastIndex = lastIndex;
        }
        result += text.slice(lastIndex);
        return result;
    }

    function showMsgContextMenu(e, data, bubble) {
        createContextMenu();

        let isMine = isMineMessage(data);
        let items = [];

        // 回复
        items.push({
            label: '回复',
            class: '',
            action: function () {
                replyTarget = {
                    id: data.id,
                    name: data.sender_name,
                    text: data.content || extractSurfaceText(data.content)
                };
                showReplyPreview();
            }
        });

        // 复制选中（仅当用户已选中文字时显示）
        let selection = window.getSelection().toString().trim();
        if (selection) {
            items.push({
                label: '复制选中',
                class: '',
                action: function () {
                    copyToClipboard(selection);
                }
            });
        }

        // 复制全部
        if (data.content) {
            items.push({
                label: '复制全部',
                class: '',
                action: function () {
                    copyToClipboard(extractSurfaceText(data.content));
                }
            });
        }

        // 分割线
        items.push({ separator: true });

        // 管理员：可删除任意消息（替代原 \delete 指令）
        if (isLobbyAdmin) {
            items.push({
                label: '删除',
                class: 'danger',
                action: function () {
                    send({ type: 'lobby_delete', message_id: data.id });
                }
            });
        }

        if (isMine) {
            // 撤回按钮：始终显示，直接发送（后端已验证发送者身份）
            items.push({
                label: '撤回',
                class: 'danger',
                action: function () {
                    send({ type: 'lobby_revoke', message_id: data.id });
                }
            });
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
        let html = '';
        for (let i = 0; i < items.length; i++) {
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
        let menuItems = $contextMenu.querySelectorAll('.ctx-menu-item');
        menuItems.forEach((item) => {
            item.addEventListener('click', function (e) {
                e.stopPropagation();
                let idx = parseInt(this.dataset.idx);
                if (items[idx]) items[idx].action();
                hideContextMenu();
            });
        });

        // 定位
        $contextMenu.style.display = 'block';
        let menuW = $contextMenu.offsetWidth;
        let menuH = $contextMenu.offsetHeight;
        let left = e.clientX;
        let top = e.clientY;
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
        let ta = document.createElement('textarea');
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

    // 委托：五子棋邀请卡片 → 加入对局跳转
    $messages.addEventListener('click', function (e) {
        let btn = e.target.closest('.gi-join-btn');
        if (btn) {
            e.preventDefault();
            let room = btn.getAttribute('data-room');
            if (room) {
                // 通知已打开的五子棋标签页（如果有的话）
                try {
                    const ch = new BroadcastChannel('gomoku_invite');
                    ch.postMessage({ room: room });
                    ch.close();
                } catch (_) {}
                // 使用固定窗口名，确保只有一个五子棋标签页
                window.open('/gomoku?room=' + encodeURIComponent(room), 'gomoku_tab');
            }
        }
    });

    // 委托：消息右键菜单
    $messages.addEventListener('contextmenu', function (e) {
        let bubble = e.target.closest('.lobby-msg');
        if (!bubble || !bubble.dataset.msgId) return;
        e.preventDefault();
        let msgData = {
            id: /^\d+$/.test(bubble.dataset.msgId) ? parseInt(bubble.dataset.msgId, 10) : bubble.dataset.msgId,
            sender_name: bubble.dataset.senderName || '',
            content: bubble.dataset.msgContent || '',
            created_at: bubble.dataset.createdAt || '',
        };
        showMsgContextMenu(e, msgData, bubble);
    });

    // 委托：MD 按钮动作（复制 / 快捷发送 / 弹窗 / 网页内嵌等）——document 级，弹窗内嵌套按钮也生效
    // ==================== 按钮点击次数限制 ====================

    let pendingClickBtns = {};   // key => btn 元素（全局计数待服务端确认）

    // 生成按钮唯一标识：消息 ID + 按钮身份 hash
    function getBtnClickKey(btn) {
        let msgEl = btn.closest('.lobby-msg, .md-modal-body');
        let msgId = msgEl ? (msgEl.dataset.msgId || '') : '';
        let identity = btn.getAttribute('href') || '';
        let datas = ['send', 'copy', 'embed', 'modalContent', 'modalTitle', 'confirmMsg', 'confirmAction', 'detailsTitle', 'detailsContent', 'rand', 'randMode', 'randTitle'];
        for (let i = 0; i < datas.length; i++) {
            let v = btn.dataset[datas[i]];
            if (v) identity += '|' + v;
        }
        let hash = 0;
        for (let j = 0; j < identity.length; j++) {
            hash = ((hash << 5) - hash + identity.charCodeAt(j)) | 0;
        }
        return (msgId ? msgId + '_' : '') + Math.abs(hash).toString(36);
    }

    // 获取当前用户在该规则下的点击次数上限（null 表示无限制）
    function getClickLimitForUser(rule, userName) {
        if (!rule) return null;
        if (rule.mode === 'per-user') {
            return rule.perUserLimit > 0 ? rule.perUserLimit : null;
        }
        if (rule.mode === 'mixed' && rule.extra && rule.extra[userName] !== undefined) {
            return rule.extra[userName] > 0 ? rule.extra[userName] : null;
        }
        return rule.globalLimit > 0 ? rule.globalLimit : null;
    }

    // 每人模式：localStorage 已用次数
    function getLocalClickUsed(key, userName) {
        try {
            let map = JSON.parse(localStorage.getItem('lobby_btn_clicks') || '{}');
            return map[key + '|' + userName] || 0;
        } catch (e) { return 0; }
    }

    function recordLocalClick(key, userName) {
        try {
            let map = JSON.parse(localStorage.getItem('lobby_btn_clicks') || '{}');
            map[key + '|' + userName] = (map[key + '|' + userName] || 0) + 1;
            localStorage.setItem('lobby_btn_clicks', JSON.stringify(map));
        } catch (e) { }
    }

    // 执行按钮动作（音效 + 各动作类型）
    function executeBtn(btn) {
        if (btn.dataset.sound) playButtonSound(btn.dataset.sound);
        let msgEl4 = btn.closest('.lobby-msg, .md-modal-body');
        if (btn.dataset.copy !== undefined) {
            // copy 渲染时未 encodeURIComponent（仅 escapeHtmlAttr），直接值引用替换
            let copyText = resolveMdPlaceholders(btn.dataset.copy, msgEl4);
            copyToClipboard(copyText);
            showTopToast('已复制: ' + (copyText.length > 20 ? copyText.slice(0, 20) + '...' : copyText), false);
        } else if (btn.dataset.send !== undefined) {
            // send 渲染时未 encodeURIComponent（仅 escapeHtmlAttr），直接值引用替换
            $chatInput.value = resolveMdPlaceholders(btn.dataset.send, msgEl4);
            $chatInput.style.height = 'auto';
            sendMessage();
        } else if (btn.dataset.modalContent !== undefined || btn.dataset.modalTitle !== undefined) {
            openMdModal(btn);
        } else if (btn.dataset.embed !== undefined) {
            openEmbedModal(btn.dataset.embed, btn);
        } else if (btn.dataset.confirmMsg !== undefined) {
            openConfirmModal(btn);
        } else if (btn.dataset.detailsTitle !== undefined) {
            toggleDetails(btn);
        } else if (btn.dataset.rand !== undefined) {
            // 随机：默认随机发送；data-rand-mode=modal 随机弹窗
            let rMode = btn.dataset.randMode || 'send';
            let rList = decodeURIComponent(btn.dataset.rand).split('|').map((s) => { return s.trim(); }).filter(Boolean);
            if (rList.length) {
                if (rMode === 'modal') {
                    openRandModal(btn);
                } else {
                    let rPick = rList[Math.floor(Math.random() * rList.length)];
                    $chatInput.value = rPick;
                    $chatInput.style.height = 'auto';
                    sendMessage();
                }
            }
        } else if (btn.dataset.ok !== undefined) {
            // 确认按钮：绑定输入框（有 ok 值则校验）；绑定 switch/变量则视为确认执行 right
            let msgEl = btn.closest('.lobby-msg, .md-modal-body');
            let state = getMsgUIState(msgEl);
            let bindId = btn.dataset.ok;
            let inputValue = getMdValue(bindId, msgEl, state);
            let inp = msgEl ? msgEl.querySelector('.md-input[data-input-id="' + bindId + '"]') : null;
            let okVal = inp ? (inp.getAttribute('data-ok') || '') : '';
            let action;
            if (inp && okVal !== '') {
                // 输入框且有期望值：校验（ok=a/b/c 表示任一答案匹配），正确执行 right，错误执行 wrong
                let okPass = false;
                if (okVal.indexOf('/') >= 0) {
                    let oks = okVal.split('/').map(function (s) { return s.trim(); }).filter(Boolean);
                    okPass = oks.indexOf(String(inputValue)) >= 0;
                } else {
                    okPass = (String(inputValue) === String(okVal));
                }
                action = okPass ? btn.dataset.right : btn.dataset.wrong;
            } else {
                // 无校验条件：视为确认执行 right
                action = btn.dataset.right;
            }
            if (action) executeMdAction(decodeURIComponent(action), msgEl);
        } else if (btn.dataset.cancel !== undefined) {
            let msgEl = btn.closest('.lobby-msg, .md-modal-body');
            if (btn.dataset.cancel) executeMdAction(decodeURIComponent(btn.dataset.cancel), msgEl);
        } else if (btn.dataset.close !== undefined) {
            // 关闭按钮：data-close 存的是组件 id（可空=全部），拼成 close: 操作执行
            let msgEl = btn.closest('.lobby-msg, .md-modal-body');
            executeMdAction('close:' + (btn.dataset.close || ''), msgEl);
        } else if (btn.dataset.switchId !== undefined) {
            let msgEl = btn.closest('.lobby-msg, .md-modal-body');
            switchMdValue(btn, msgEl);
        } else {
            // 普通跳转按钮（无动作 data-*，但有 href）：用于全局点击次数异步确认后打开链接
            let href = btn.getAttribute('href');
            if (href && href !== '#') {
                if (isExternalUrl(href)) showExternalLinkWarning(href);
                else window.open(href, '_blank', 'noopener');
            }
        }
    }

    // 检查点击次数，返回 true 放行 / false 拦截
    function checkBtnClick(btn, rule) {
        let userName = myNickname || '';
        let limit = getClickLimitForUser(rule, userName);
        if (limit === null) return true;
        let key = getBtnClickKey(btn);
        if (rule.mode === 'per-user') {
            let used = getLocalClickUsed(key, userName);
            if (used >= limit) {
                btn.classList.add('md-btn-disabled');
                btn.dataset.clickDisabled = '1';
                showTopToast('点击次数已用完', true);
                return false;
            }
            recordLocalClick(key, userName);
            return true;
        }
        // global / mixed：先查服务端，allowed 才执行动作（防止超限一击执行、刷新后重复点击）
        pendingClickBtns[key] = btn;
        send({ type: 'lobby_btn_click', key: key, userName: userName, rule: rule });
        return false;
    }

    // 服务端返回全局计数结果：allowed 则执行按钮动作，超限则禁用
    function handleBtnClickResult(data) {
        let key = data.key || '';
        let btn = pendingClickBtns[key];
        if (btn) delete pendingClickBtns[key];
        if (!data.allowed) {
            if (btn) {
                btn.classList.add('md-btn-disabled');
                btn.dataset.clickDisabled = '1';
            }
            showTopToast('点击次数已用完', true);
        } else if (btn) {
            // 服务端确认允许：执行按钮动作（普通跳转按钮会在此打开链接）
            executeBtn(btn);
        }
    }

    // ==================== 交互式 MD（输入框 / 获取内容 / 确认 / 取消 / 关闭 / 可改变内容） ====================

    // 解析 | 分隔的参数：第一个是裸值，其余为 键=值（括号感知，嵌套组件内的 | 不参与分割）
    function parseNewMdParams(content) {
        let parts = splitTopLevelByPipe(String(content || ''));
        let result = { value: parts[0] || '' };
        for (let i = 1; i < parts.length; i++) {
            let p = parts[i];
            let eq = p.indexOf('=');
            if (eq > 0) {
                result[p.slice(0, eq).trim()] = p.slice(eq + 1);
            }
        }
        return result;
    }

    // 解析 switch 的值列表：值1|值2|...|id=xxx|c=1|cc=颜色1/颜色2/...
    function parseSwitchParams(content) {
        let parts = String(content || '').split('|');
        let values = [];
        let colors = [];
        let id = '';
        let color = false;
        for (let i = 0; i < parts.length; i++) {
            let p = parts[i];
            if (p.indexOf('id=') === 0) { id = p.slice(3); }
            else if (p.indexOf('cc=') === 0) { colors = p.slice(3).split('/'); }
            else if (p.indexOf('c=') === 0) { color = (p.slice(2) === '1'); }
            else { values.push(p); }
        }
        return { values: values, colors: colors, id: id, color: color };
    }

    // 简单 XOR + hex 编码加密（渲染时加密内容，避免 F12 直接看到明文；不依赖 btoa 更兼容）
    function mdEncrypt(text, key) {
        let k = String(key || 'md');
        let out = '';
        for (let i = 0; i < text.length; i++) {
            let c = text.charCodeAt(i) ^ k.charCodeAt(i % k.length);
            out += ('000' + c.toString(16)).slice(-4);
        }
        return out;
    }

    function mdDecrypt(data, key) {
        try {
            let k = String(key || 'md');
            let out = '';
            for (let i = 0; i + 4 <= String(data).length; i += 4) {
                let c = parseInt(String(data).slice(i, i + 4), 16) ^ k.charCodeAt((i / 4) % k.length);
                out += String.fromCharCode(c);
            }
            return out;
        } catch (e) { return ''; }
    }

    // 解析表格参数：col=N|单元格...（第一行 N 个为表头）
    function parseTableParams(content) {
        let parts = String(content || '').split('|');
        let cols = 2;
        let cells = [];
        for (let i = 0; i < parts.length; i++) {
            let p = parts[i].trim();
            if (p.indexOf('col=') === 0) { cols = parseInt(p.slice(4), 10) || 2; }
            else { cells.push(p); }
        }
        return { cols: cols, cells: cells };
    }

    // ==================== 画板 ====================

    // 解析画板图形：类型:参数:颜色;类型:参数:颜色;...
    function parseBoardShapes(shapesStr) {
        let result = [];
        let parts = String(shapesStr || '').split(';');
        for (let i = 0; i < parts.length; i++) {
            let p = parts[i].trim();
            if (!p) continue;
            let segs = p.split(':');
            let type = (segs[0] || '').trim().toLowerCase();
            let params = (segs[1] || '').trim();
            let color = (segs[2] || '#000000').trim();
            // text 图形兼容两种写法：
            //   text:x,y,字号,内容（逗号写法，第 3 段为颜色可选）
            //   text:x,y,字号:内容（冒号写法，文档推荐，第 3 段非颜色时视为文本内容）
            if (type === 'text' && segs.length === 3) {
                let third = segs[2].trim();
                if (!/^#?[0-9a-fA-F]{3,8}$/.test(third)) {
                    params = params + ',' + third;
                    color = '#000000';
                }
            }
            if (!type || !params) continue;
            result.push({ type: type, params: params, color: color });
        }
        return result;
    }

    // 渲染单个画板图形（SVG）
    function renderBoardShape(shape) {
        let t = shape.type;
        let p = String(shape.params).split(',');
        let c = escapeHtmlAttr(shape.color || '#000000');
        let num = function (v, d) { let n = parseFloat(v); return isNaN(n) ? d : n; };
        if (t === 'line') {
            // line:x1,y1,x2,y2
            return '<line x1="' + num(p[0], 0) + '" y1="' + num(p[1], 0) + '" x2="' + num(p[2], 10) + '" y2="' + num(p[3], 10) + '" stroke="' + c + '" stroke-width="0.12"/>';
        }
        if (t === 'rect') {
            return '<rect x="' + num(p[0], 0) + '" y="' + num(p[1], 0) + '" width="' + Math.max(0.1, num(p[2], 1)) + '" height="' + Math.max(0.1, num(p[3], 1)) + '" fill="' + c + '"/>';
        }
        if (t === 'circle') {
            return '<circle cx="' + num(p[0], 5) + '" cy="' + num(p[1], 5) + '" r="' + Math.max(0.1, num(p[2], 1)) + '" fill="' + c + '"/>';
        }
        if (t === 'dot') {
            return '<circle cx="' + num(p[0], 5) + '" cy="' + num(p[1], 5) + '" r="0.15" fill="' + c + '"/>';
        }
        if (t === 'triangle') {
            // triangle:x1,y1,x2,y2,x3,y3
            return '<polygon points="' + num(p[0], 0) + ',' + num(p[1], 0) + ' ' + num(p[2], 10) + ',' + num(p[3], 0) + ' ' + num(p[4], 5) + ',' + num(p[5], 10) + '" fill="' + c + '"/>';
        }
        if (t === 'diamond') {
            // diamond:cx,cy,r
            let cx = num(p[0], 10), cy = num(p[1], 10), r = Math.max(0.1, num(p[2], 3));
            return '<polygon points="' + cx + ',' + (cy - r) + ' ' + (cx + r) + ',' + cy + ' ' + cx + ',' + (cy + r) + ' ' + (cx - r) + ',' + cy + '" fill="' + c + '"/>';
        }
        if (t === 'star') {
            // star:cx,cy,r（五角星）
            let cx = num(p[0], 10), cy = num(p[1], 10), r = Math.max(0.1, num(p[2], 4));
            let pts = [];
            for (let i = 0; i < 10; i++) {
                let ang = -Math.PI / 2 + i * Math.PI / 5;
                let rad = (i % 2 === 0) ? r : r * 0.4;
                pts.push((cx + rad * Math.cos(ang)).toFixed(2) + ',' + (cy + rad * Math.sin(ang)).toFixed(2));
            }
            return '<polygon points="' + pts.join(' ') + '" fill="' + c + '"/>';
        }
        if (t === 'heart') {
            // heart:cx,cy,s（简化心形 path）
            let cx = num(p[0], 10), cy = num(p[1], 10), s = Math.max(0.1, num(p[2], 3));
            return '<path d="M ' + cx + ',' + (cy + s) + ' C ' + (cx - s) + ',' + cy + ' ' + (cx - s) + ',' + (cy - s) + ' ' + cx + ',' + (cy - s * 0.4) + ' C ' + (cx + s) + ',' + (cy - s) + ' ' + (cx + s) + ',' + cy + ' ' + cx + ',' + (cy + s) + ' Z" fill="' + c + '"/>';
        }
        if (t === 'frame') {
            // frame:x,y,w,h
            return '<rect x="' + num(p[0], 0) + '" y="' + num(p[1], 0) + '" width="' + num(p[2], 20) + '" height="' + num(p[3], 20) + '" fill="none" stroke="' + c + '" stroke-width="0.12"/>';
        }
        if (t === 'text') {
            // text:x,y,字号:文本（文本在参数第 4 个逗号后）
            let content = p.slice(3).join(',');
            return '<text x="' + num(p[0], 10) + '" y="' + num(p[1], 10) + '" font-size="' + Math.max(0.3, num(p[2], 1)) + '" fill="' + c + '" text-anchor="middle">' + escapeHtml(content) + '</text>';
        }
        return '';
    }

    // 渲染画板 SVG 到容器
    function renderBoard(msgEl, boardEl) {
        if (!boardEl) return;
        let size = Math.max(1, Math.min(20, parseInt(boardEl.getAttribute('data-board-size'), 10) || 20));
        let shapesRaw = boardEl.getAttribute('data-board-shapes') || '';
        let textRaw = boardEl.getAttribute('data-board-text') || '';
        let bg = boardEl.getAttribute('data-board-bg') || '';
        let showGrid = boardEl.getAttribute('data-board-grid') !== '0';
        // 值引用实时替换（%a%）
        shapesRaw = resolveMdPlaceholders(shapesRaw, msgEl);
        textRaw = resolveMdPlaceholders(textRaw, msgEl);
        let svg = '<svg class="md-board-svg" viewBox="0 0 ' + size + ' ' + size + '" preserveAspectRatio="xMidYMid meet">';
        if (bg) svg += '<rect x="0" y="0" width="' + size + '" height="' + size + '" fill="' + escapeHtmlAttr(bg) + '"/>';
        // 网格线（grid=0 关闭）
        if (showGrid) {
            svg += '<g stroke="#e2e8f0" stroke-width="0.04">';
            for (let i = 1; i < size; i++) {
                svg += '<line x1="' + i + '" y1="0" x2="' + i + '" y2="' + size + '"/>';
                svg += '<line x1="0" y1="' + i + '" x2="' + size + '" y2="' + i + '"/>';
            }
            svg += '</g>';
        }
        // 图形
        let shapes = parseBoardShapes(shapesRaw);
        for (let i = 0; i < shapes.length; i++) {
            svg += renderBoardShape(shapes[i]);
        }
        // 文本（text=内容 居中默认；tx/ty/ts/tc 可覆盖位置/字号/颜色，支持 %值% 引用）
        if (textRaw) {
            let txx = boardEl.getAttribute('data-board-tx');
            let tyy = boardEl.getAttribute('data-board-ty');
            let tss = boardEl.getAttribute('data-board-ts');
            let tcc = boardEl.getAttribute('data-board-tc') || '';
            let px = size / 2, py = size / 2, ps = Math.max(0.5, size / 8), pc = '#000';
            if (txx !== null) { let v = parseFloat(resolveMdPlaceholders(txx, msgEl)); if (!isNaN(v)) px = v; }
            if (tyy !== null) { let v = parseFloat(resolveMdPlaceholders(tyy, msgEl)); if (!isNaN(v)) py = v; }
            if (tss !== null) { let v = parseFloat(resolveMdPlaceholders(tss, msgEl)); if (!isNaN(v)) ps = Math.max(0.3, v); }
            if (tcc) { let v = resolveMdPlaceholders(tcc, msgEl).trim(); if (v) pc = v; }
            svg += '<text x="' + px + '" y="' + py + '" text-anchor="middle" dominant-baseline="middle" font-size="' + ps + '" fill="' + escapeHtmlAttr(pc) + '">' + escapeHtml(textRaw) + '</text>';
        }
        svg += '</svg>';
        boardEl.innerHTML = svg;
    }

    // 画板弹窗（modal=1 时点击按钮显示）
    function openBoardModal(boardId) {
        let src = document.querySelector('.md-board[data-board-id="' + boardId + '"]');
        if (!src) return;
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        overlay.innerHTML =
            '<div class="md-modal">' +
            '<div class="md-modal-header"><span class="md-modal-title">画板</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button></div>' +
            '<div class="md-modal-body" style="display:flex;justify-content:center;padding:12px;"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        let clone = src.cloneNode(true);
        clone.style.display = '';
        clone.style.width = 'min(80vw, 400px)';
        overlay.querySelector('.md-modal-body').appendChild(clone);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    }

    // 消息级 UI 状态（输入框内容、可改变内容、全局变量、倒计时、进度条）——WeakMap 随消息元素销毁自动回收
    let msgUIStates = new WeakMap();
    function getMsgUIState(msgEl) {
        if (!msgEl) msgEl = document.body;
        // 归一化到消息容器（.lobby-msg-content / .md-modal-body）：初始化与交互共用同一状态
        let root = msgEl.closest ? (msgEl.closest('.lobby-msg-content, .md-modal-body') || msgEl) : msgEl;
        let s = msgUIStates.get(root);
        if (!s) { s = { inputs: {}, switches: {}, vars: {}, timers: {}, bars: {}, votes: {}, ats: {} }; msgUIStates.set(root, s); }
        return s;
    }

    // 刷新当前消息内所有 get: 占位的内容显示（支持输入框 / switch / 变量值）
    function refreshMsgGets(msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        let gets = msgEl.querySelectorAll('.md-get');
        for (let i = 0; i < gets.length; i++) {
            let id = gets[i].getAttribute('data-get-id');
            gets[i].textContent = getMdValue(id, msgEl, state);
        }
    }

    // 可改变内容：切换到下一个值（支持颜色模式 c:1 + 独立颜色列表 cc:，内容和颜色同时切换）
    function switchMdValue(btn, msgEl) {
        let state = getMsgUIState(msgEl);
        let id = btn.getAttribute('data-switch-id') || '';
        let values = [];
        try { values = JSON.parse(btn.getAttribute('data-switch-vals') || '[]'); } catch (e) { values = []; }
        if (!values.length) return;
        let colors = [];
        try { colors = JSON.parse(btn.getAttribute('data-switch-colors') || '[]'); } catch (e) { colors = []; }
        let idx = (state.switches[id] || 0);
        idx = (idx + 1) % values.length;
        state.switches[id] = idx;
        let val = values[idx];
        btn.textContent = val;
        // 颜色模式：优先用 cc: 颜色列表（与值对应），否则用值本身作为颜色
        let colorVal = colors.length ? (colors[idx] || '') : val;
        if (btn.getAttribute('data-switch-color') === '1') {
            let c = String(colorVal || '').trim();
            if (/^#?[0-9a-fA-F]{3,8}$/.test(c)) {
                if (c.charAt(0) !== '#') c = '#' + c;
                btn.style.backgroundColor = c;
            }
        }
        // 刷新绑定 colorof 该 switch 的组件
        if (msgEl) refreshColorOf(msgEl, id, colorVal);
        if (msgEl) refreshShowIfs(msgEl);
        // 刷新画板（%值% 引用 switch/变量/输入框，实时重绘）
        if (msgEl) {
            let boards = msgEl.querySelectorAll('.md-board');
            for (let bi = 0; bi < boards.length; bi++) renderBoard(msgEl, boards[bi]);
        }
        // onchange 联动
        let oc = btn.getAttribute('data-onchange');
        if (oc) executeMdAction(decodeURIComponent(oc), msgEl);
    }

    // 刷新当前消息内绑定 colorof=switchId 的组件颜色
    function refreshColorOf(msgEl, switchId, val) {
        let els = msgEl.querySelectorAll('[data-colorof="' + switchId + '"]');
        let c = String(val || '').trim();
        let isColor = /^#?[0-9a-fA-F]{3,8}$/.test(c);
        if (isColor && c.charAt(0) !== '#') c = '#' + c;
        for (let i = 0; i < els.length; i++) {
            if (isColor) els[i].style.backgroundColor = c;
            else els[i].style.backgroundColor = '';
        }
    }

    // 执行交互式 MD 操作：send:/copy:/reset:/switch:/close:
    // 替换操作内容里的 {id} 占位符：取当前消息内 switch 的当前值 或 输入框的内容
    // 获取当前消息内 id 对应组件的值（输入框 / 全局变量 / switch）
    function getMdValue(id, msgEl, state) {
        if (!state) return '';
        if (state.inputs[id] !== undefined) return state.inputs[id];
        if (state.vars[id] !== undefined) return state.vars[id];
        if (msgEl) {
            let swBtn = msgEl.querySelector('.md-btn-switch[data-switch-id="' + id + '"], .md-hide-switch[data-switch-id="' + id + '"]');
            if (swBtn) {
                let values = [];
                try { values = JSON.parse(swBtn.getAttribute('data-switch-vals') || '[]'); } catch (e) { values = []; }
                return values[state.switches[id] || 0] !== undefined ? values[state.switches[id] || 0] : '';
            }
        }
        return '';
    }

    // 替换操作内容里的 {id} / %id% 占位符：取当前消息内 switch / 输入框 / 全局变量的值
    // 支持默认值：%id|默认值% 或 {id|默认值}（引用为空时返回默认值）
    function resolveMdPlaceholders(action, msgEl) {
        if (!action) return action;
        // 解码 HTML 实体（动作内容可能残留 &lt; &gt; &amp;）
        if (action.indexOf('&') >= 0) {
            action = action.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
        }
        if (action.indexOf('{') === -1 && action.indexOf('%') === -1) return action;
        let state = msgEl ? getMsgUIState(msgEl) : null;
        return action.replace(/\{([^}]+)\}|%([^%]+)%/g, function (m, id1, id2) {
            let key = id1 || id2;
            let def = '';
            let bar = key.indexOf('|');
            if (bar > 0) { def = key.slice(bar + 1); key = key.slice(0, bar); }
            let v = getMdValue(key, msgEl, state);
            return v !== '' ? v : def;
        });
    }

    // 数学表达式求值（仅允许数字和 + - * / ( ) 空格，安全过滤）
    function evalMdMath(expr) {
        let s = String(expr).trim();
        if (!s) return s;
        if (!/^[\d+\-*/().\s]+$/.test(s)) return s; // 含非数学字符 → 按普通字符串
        try {
            let v = Function('"use strict";return (' + s + ')')();
            if (typeof v === 'number' && isFinite(v)) return String(Math.round(v * 1000000) / 1000000);
            return s;
        } catch (e) { return s; }
    }

    // 设置全局变量并刷新消息内所有 var / 条件显示
    function setMdVar(name, value, msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        state.vars[name] = String(value);
        let els = msgEl.querySelectorAll('.md-var[data-var-id="' + name + '"]');
        for (let i = 0; i < els.length; i++) els[i].textContent = state.vars[name];
        refreshShowIfs(msgEl);
    }

    // 更新进度条（id, 目标值）
    function updateMdBar(id, value, msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        let barEl = msgEl.querySelector('.md-bar[data-bar-id="' + id + '"]');
        if (!barEl) return;
        let max = parseInt(barEl.getAttribute('data-bar-max'), 10) || 100;
        let v = Math.max(0, Math.min(max, parseInt(value, 10) || 0));
        state.bars[id] = v;
        let fill = barEl.querySelector('.md-bar-fill');
        if (fill) fill.style.width = (max > 0 ? (v / max) * 100 : 0) + '%';
        let text = barEl.querySelector('.md-bar-text');
        if (text) text.textContent = v + '/' + max;
        refreshShowIfs(msgEl);
    }

    // 启动倒计时
    function startMdTimer(id, msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        if (state.timers[id]) { clearInterval(state.timers[id]); delete state.timers[id]; }
        let timerEl = msgEl.querySelector('.md-timer[data-timer-id="' + id + '"]');
        if (!timerEl) return;
        let total = parseInt(timerEl.getAttribute('data-timer-total'), 10) || 0;
        let left = total;
        timerEl.textContent = left;
        state.timers[id] = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(state.timers[id]);
                delete state.timers[id];
                timerEl.textContent = '0';
                timerEl.classList.add('md-timer-done');
                // 解锁倒计时锁定的按钮组
                let lockTarget = timerEl.getAttribute('data-timer-lock');
                if (lockTarget) {
                    let locked = msgEl.querySelectorAll('[data-timer-lock-group="' + lockTarget + '"]');
                    for (let i = 0; i < locked.length; i++) delete locked[i].dataset.timerLocked;
                }
                // 执行结束操作
                let endAct = timerEl.getAttribute('data-timer-end');
                if (endAct) executeMdAction(decodeURIComponent(endAct), msgEl);
                return;
            }
            timerEl.textContent = left;
            // 联动进度条
            let barId = timerEl.getAttribute('data-timer-bar');
            if (barId) updateMdBar(barId, left, msgEl);
        }, 1000);
    }

    // 刷新条件显示（if: id=期望值 / id 非空 / 比较运算符 == != > >= < <=）
    function refreshShowIfs(msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        let els = msgEl.querySelectorAll('.md-if');
        for (let i = 0; i < els.length; i++) {
            let cond = els[i].getAttribute('data-if-cond') || '';
            let ok = false;
            let cid, expect, op = '=';
            let hasOp = false; // 是否带运算符/等号（无则按"非空即显示"）
            // 优先识别比较运算符（== != >= <= > <），再退化到 键=值
            let cm = cond.match(/^(.+?)\s*(==|!=|>=|<=|>|<)\s*(.+)$/);
            if (cm) {
                cid = cm[1].trim();
                op = cm[2];
                expect = cm[3];
                hasOp = true;
            } else {
                let eq = cond.indexOf('=');
                if (eq > 0) {
                    cid = cond.slice(0, eq).trim();
                    expect = cond.slice(eq + 1);
                    hasOp = true;
                } else {
                    cid = cond.trim();
                }
            }
            let actual;
            if (state.inputs[cid] !== undefined) actual = state.inputs[cid];
            else if (state.vars[cid] !== undefined) actual = state.vars[cid];
            else {
                let swBtn = msgEl.querySelector('.md-btn-switch[data-switch-id="' + cid + '"], .md-hide-switch[data-switch-id="' + cid + '"]');
                if (swBtn) {
                    let vals = [];
                    try { vals = JSON.parse(swBtn.getAttribute('data-switch-vals') || '[]'); } catch (e2) { vals = []; }
                    actual = vals[state.switches[cid] || 0];
                }
            }
            if (!hasOp) ok = !!(actual); // 无运算符：非空即显示
            else if (op === '=' || op === '==') ok = (String(actual) === String(expect));
            else if (op === '!=') ok = (String(actual) !== String(expect));
            else {
                // 数值比较：双方必须是数字，否则不满足
                let av = parseFloat(actual), ev = parseFloat(expect);
                if (isNaN(av) || isNaN(ev)) ok = false;
                else if (op === '>') ok = av > ev;
                else if (op === '>=') ok = av >= ev;
                else if (op === '<') ok = av < ev;
                else if (op === '<=') ok = av <= ev;
            }
            els[i].style.display = ok ? '' : 'none';
        }
    }

    // ==================== 投票 / 骰子 / 图集 / 定时到点 ====================

    // 投票：点击选项（单选默认；max>1 多选；再次点击取消；localStorage 防重复，刷新不重置）
    function handleVoteClick(optEl) {
        let voteEl = optEl.closest('.md-vote');
        if (!voteEl) return;
        let vId = voteEl.getAttribute('data-vote-id') || '';
        let vMax = parseInt(voteEl.getAttribute('data-vote-max'), 10) || 1;
        let idx = parseInt(optEl.getAttribute('data-vote-opt'), 10);
        let key = 'lobby_vote_' + vId;
        let picked = [];
        try { picked = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { picked = []; }
        if (picked.indexOf(idx) >= 0) {
            picked = picked.filter(function (x) { return x !== idx; });
        } else {
            if (picked.length >= vMax) {
                if (vMax === 1) picked = []; // 单选：切换选择
                else { showTopToast('最多选择 ' + vMax + ' 项', true); return; }
            }
            picked.push(idx);
        }
        try { localStorage.setItem(key, JSON.stringify(picked)); } catch (e) { }
        renderVote(voteEl);
    }

    // 重渲染投票：显示已选状态 + 本地计数/百分比条
    function renderVote(voteEl) {
        let vId = voteEl.getAttribute('data-vote-id') || '';
        let key = 'lobby_vote_' + vId;
        let picked = [];
        try { picked = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { picked = []; }
        let opts = voteEl.querySelectorAll('.md-vote-opt');
        let counts = {};
        for (let i = 0; i < picked.length; i++) counts[picked[i]] = (counts[picked[i]] || 0) + 1;
        let total = picked.length;
        for (let i = 0; i < opts.length; i++) {
            let on = picked.indexOf(i) >= 0;
            let c = counts[i] || 0;
            let pct = total > 0 ? Math.round(c / total * 100) : 0;
            opts[i].setAttribute('data-vote-picked', on ? '1' : '0');
            let bar = opts[i].querySelector('.md-vote-bar i');
            if (bar) bar.style.width = pct + '%';
            let num = opts[i].querySelector('.md-vote-num');
            if (num) num.textContent = on ? ('✓ ' + c + ' 票 ' + pct + '%') : (c + ' 票 ' + pct + '%');
        }
    }

    // 掷骰子：解析 XdY±Z（支持 dY 省略 X、纯数字），生成随机结果，展示并存入 %id% 变量
    function rollDice(btn) {
        let expr = btn.getAttribute('data-dice') || '1d6';
        let dId = btn.getAttribute('data-dice-id') || '';
        let m = String(expr).match(/^(\d*)d(\d+)([+-]\d+)?$/i);
        let rolls = [], total = 0, mod = 0;
        if (m) {
            let diceCount = m[1] ? parseInt(m[1], 10) : 1;
            let sides = parseInt(m[2], 10) || 6;
            if (m[3]) mod = parseInt(m[3], 10) || 0;
            if (diceCount < 1) diceCount = 1;
            if (diceCount > 100) diceCount = 100;
            for (let i = 0; i < diceCount; i++) {
                let r = Math.floor(Math.random() * sides) + 1;
                rolls.push(r);
                total += r;
            }
            total += mod;
        }
        let resEl = btn.parentElement ? btn.parentElement.querySelector('.md-dice-result') : null;
        if (resEl) {
            let txt = '🎲 ' + expr + ' → ';
            if (rolls.length > 1) txt += rolls.join(' + ');
            else if (rolls.length === 1) txt += rolls[0];
            if (mod) txt += (mod > 0 ? ' + ' + mod : ' - ' + Math.abs(mod));
            txt += ' = ' + total;
            resEl.textContent = txt;
            resEl.classList.add('md-dice-done');
        }
        if (dId) {
            let msgEl = btn.closest('.lobby-msg, .md-modal-body');
            if (msgEl) setMdVar(dId, String(total), msgEl);
        }
    }

    // 图集轮播弹窗：左右切换 + 指示点 + 自动播放 + 键盘左右键
    let galleryTimer = null;
    function openGalleryModal(btn) {
        if (!canOpenModal()) return;
        let imgs = [];
        try { imgs = JSON.parse(btn.getAttribute('data-gallery') || '[]'); } catch (e) { imgs = []; }
        if (!imgs.length) return;
        let title = '';
        try { title = decodeURIComponent(btn.getAttribute('data-gallery-title') || ''); } catch (e) { title = ''; }
        let autoplay = parseInt(btn.getAttribute('data-gallery-autoplay'), 10) || 0;
        let cur = 0;
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        let html = '<div class="md-modal md-gallery-modal">' +
            '<div class="md-modal-header"><span class="md-modal-title">' + escapeHtml(title || '图片预览') + ' <span class="md-gallery-page"></span></span>' +
            '<button class="md-modal-close" title="关闭">&times;</button></div>' +
            '<div class="md-gallery-body"><div class="md-gallery-stage">' +
            imgs.map(function (u, i) {
                return '<div class="md-gallery-slide' + (i === 0 ? ' md-gallery-active' : '') + '"><img src="' + escapeHtmlAttr(u) + '" loading="lazy" referrerpolicy="no-referrer"></div>';
            }).join('') +
            '</div>' +
            (imgs.length > 1 ? '<button class="md-gallery-prev" title="上一张">‹</button><button class="md-gallery-next" title="下一张">›</button>' : '') +
            '</div>' +
            (imgs.length > 1 ? '<div class="md-gallery-dots">' + imgs.map(function (u, i) {
                return '<span class="md-gallery-dot' + (i === 0 ? ' md-gallery-dot-active' : '') + '" data-gdot="' + i + '"></span>';
            }).join('') + '</div>' : '') +
            '</div>';
        overlay.innerHTML = html;
        document.body.appendChild(overlay);
        let slides = overlay.querySelectorAll('.md-gallery-slide');
        let dots = overlay.querySelectorAll('.md-gallery-dot');
        let pageEl = overlay.querySelector('.md-gallery-page');
        let show = function (i) {
            cur = (i + slides.length) % slides.length;
            for (let s = 0; s < slides.length; s++) slides[s].classList.toggle('md-gallery-active', s === cur);
            for (let d = 0; d < dots.length; d++) dots[d].classList.toggle('md-gallery-dot-active', d === cur);
            if (pageEl) pageEl.textContent = (cur + 1) + '/' + slides.length;
        };
        let stopAuto = function () { if (galleryTimer) { clearInterval(galleryTimer); galleryTimer = null; } };
        let startAuto = function () {
            stopAuto();
            if (autoplay > 0 && slides.length > 1) {
                galleryTimer = setInterval(function () { show(cur + 1); }, autoplay * 1000);
            }
        };
        show(0);
        startAuto();
        let keyFn = function (ev) {
            if (ev.key === 'ArrowLeft') { stopAuto(); show(cur - 1); startAuto(); }
            else if (ev.key === 'ArrowRight') { stopAuto(); show(cur + 1); startAuto(); }
            else if (ev.key === 'Escape') { overlay.remove(); }
        };
        document.addEventListener('keydown', keyFn);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        let prev = overlay.querySelector('.md-gallery-prev');
        let next = overlay.querySelector('.md-gallery-next');
        if (prev) prev.addEventListener('click', function () { stopAuto(); show(cur - 1); startAuto(); });
        if (next) next.addEventListener('click', function () { stopAuto(); show(cur + 1); startAuto(); });
        for (let di = 0; di < dots.length; di++) {
            dots[di].addEventListener('click', function () { stopAuto(); show(di); startAuto(); });
        }
        // 移除时清理定时器与键盘监听
        let origRemove = overlay.remove.bind(overlay);
        overlay.remove = function () { stopAuto(); document.removeEventListener('keydown', keyFn); origRemove(); };
    }

    // 定时到点：每秒检查 HH:MM[:SS]，到点执行 end 动作；repeat=1 每天重复
    function startMdAt(msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        let ats = msgEl.querySelectorAll('.md-at');
        for (let i = 0; i < ats.length; i++) {
            (function (el) {
                let id = el.getAttribute('data-at-id') || '';
                if (id && state.ats[id]) return;
                if (id) state.ats[id] = true;
                let iv = setInterval(function () {
                    if (!el.isConnected) { clearInterval(iv); if (id) delete state.ats[id]; return; }
                    let timeStr = el.getAttribute('data-at-time') || '00:00';
                    let segs = timeStr.split(':').map(function (x) { return parseInt(x, 10) || 0; });
                    let hh = segs[0] || 0, mm = segs[1] || 0, ss = segs[2] || 0;
                    let now = new Date();
                    let target = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hh, mm, ss);
                    let diff = target - now;
                    if (diff < 0) diff += 24 * 3600 * 1000; // 已过今日则等明天
                    let pad = function (n) { return (n < 10 ? '0' : '') + n; };
                    if (el.getAttribute('data-at-done') === '1') {
                        if (el.getAttribute('data-at-repeat') !== '1') {
                            clearInterval(iv);
                            if (id) delete state.ats[id];
                        }
                        return;
                    }
                    if (diff <= 1000) {
                        // 到点触发
                        el.setAttribute('data-at-done', '1');
                        el.textContent = '⏰ ' + timeStr + ' · 已触发';
                        el.classList.add('md-at-done');
                        let endAct = el.getAttribute('data-at-end');
                        if (endAct) executeMdAction(decodeURIComponent(endAct), msgEl);
                        if (el.getAttribute('data-at-repeat') === '1') {
                            el.removeAttribute('data-at-done');
                            el.classList.remove('md-at-done');
                        }
                    } else {
                        let h = Math.floor(diff / 3600000), m2 = Math.floor(diff % 3600000 / 60000), s2 = Math.floor(diff % 60000 / 1000);
                        el.textContent = '⏰ ' + timeStr + ' · ' + pad(h) + ':' + pad(m2) + ':' + pad(s2);
                    }
                }, 1000);
            })(ats[i]);
        }
    }

    // 初始化消息内 md 组件：启动倒计时、初始化进度条、评估条件显示、初始化全局变量
    function initMdComponents(msgEl) {
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        // 初始化全局变量（var:）
        let vars = msgEl.querySelectorAll('.md-var');
        for (let i = 0; i < vars.length; i++) {
            let id = vars[i].getAttribute('data-var-id');
            if (id && state.vars[id] === undefined) state.vars[id] = vars[i].textContent;
        }
        // 注册定义值（def:，隐藏）
        let defs = msgEl.querySelectorAll('.md-def');
        for (let d = 0; d < defs.length; d++) {
            let nm = defs[d].getAttribute('data-def-name');
            let vl = defs[d].getAttribute('data-def-value');
            if (nm && state.vars[nm] === undefined) state.vars[nm] = vl;
        }
        // 启动倒计时
        let timers = msgEl.querySelectorAll('.md-timer');
        for (let j = 0; j < timers.length; j++) {
            let id = timers[j].getAttribute('data-timer-id');
            if (id && !state.timers[id]) startMdTimer(id, msgEl);
        }
        // 启动定时到点（at:）
        startMdAt(msgEl);
        // 初始化投票显示（恢复本地已选状态）
        let votes = msgEl.querySelectorAll('.md-vote');
        for (let vi = 0; vi < votes.length; vi++) renderVote(votes[vi]);
        // 刷新静态值引用（table 单元格 / text 内容 / if 内容：渲染时无法解析的 %值%，此时 def/var 已注册）
        let refCells = msgEl.querySelectorAll('.md-table th, .md-table td, .md-textbox-body, .md-if');
        for (let rc = 0; rc < refCells.length; rc++) {
            refCells[rc].textContent = resolveMdPlaceholders(refCells[rc].textContent, msgEl);
        }
        // rand 列表支持 %值% 引用（data-rand 为 encodeURIComponent 存储）
        let randBtns = msgEl.querySelectorAll('.md-btn-rand');
        for (let ri = 0; ri < randBtns.length; ri++) {
            let dv = randBtns[ri].getAttribute('data-rand');
            if (dv && (dv.indexOf('%') >= 0 || dv.indexOf('{') >= 0)) {
                try {
                    randBtns[ri].setAttribute('data-rand', encodeURIComponent(resolveMdPlaceholders(decodeURIComponent(dv), msgEl)));
                } catch (e) { }
            }
        }
        // switch 值列表支持 %值% 引用
        let swBtns2 = msgEl.querySelectorAll('.md-btn-switch[data-switch-vals]');
        for (let si2 = 0; si2 < swBtns2.length; si2++) {
            let b3 = swBtns2[si2];
            let sv2 = b3.getAttribute('data-switch-vals');
            if (sv2 && (sv2.indexOf('%') >= 0 || sv2.indexOf('{') >= 0)) {
                let replaced = resolveMdPlaceholders(sv2, msgEl);
                b3.setAttribute('data-switch-vals', replaced);
                try {
                    let vals2 = JSON.parse(replaced);
                    if (vals2.length) b3.textContent = vals2[0];
                } catch (e) { }
            }
        }
        // 初始化进度条
        let bars = msgEl.querySelectorAll('.md-bar');
        for (let k = 0; k < bars.length; k++) {
            let id = bars[k].getAttribute('data-bar-id');
            let initVal = parseInt(bars[k].getAttribute('data-bar-init'), 10) || 0;
            if (id) updateMdBar(id, initVal, msgEl);
        }
        // 渲染画板（解析 %值% 并生成 SVG）
        let boards = msgEl.querySelectorAll('.md-board');
        for (let bb = 0; bb < boards.length; bb++) {
            renderBoard(msgEl, boards[bb]);
        }
        // 倒计时锁定：为锁组内按钮加锁定标记
        let lockGroups = {};
        let tms = msgEl.querySelectorAll('.md-timer[data-timer-lock]');
        for (let li = 0; li < tms.length; li++) {
            let grp = tms[li].getAttribute('data-timer-lock');
            if (grp) lockGroups[grp] = true;
        }
        for (let g in lockGroups) {
            let locked = msgEl.querySelectorAll('[data-timer-lock-group="' + g + '"]');
            for (let m = 0; m < locked.length; m++) locked[m].dataset.timerLocked = '1';
        }
        // 评估条件显示
        refreshShowIfs(msgEl);
    }

    // ==================== for 循环（仅按钮触发，最高 300 次） ====================

    // 循环条件判断：变量 运算符 数字
    function evalForCond(cond, varName, val) {
        let m = String(cond || '').match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(<=|>=|==|!=|<|>)\s*(-?\d+)$/);
        if (!m || m[1] !== varName) return false;
        let op = m[2];
        let num = parseInt(m[3], 10);
        switch (op) {
            case '<': return val < num;
            case '<=': return val <= num;
            case '>': return val > num;
            case '>=': return val >= num;
            case '==': return val === num;
            case '!=': return val !== num;
        }
        return false;
    }

    // 循环步进：变量 运算符 数字
    function applyForStep(step, varName, val) {
        let m = String(step || '').match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*([+\-*/])\s*(\d+)$/);
        if (!m || m[1] !== varName) return val;
        let num = parseInt(m[3], 10);
        switch (m[2]) {
            case '+': return val + num;
            case '-': return val - num;
            case '*': return val * num;
            case '/': return num === 0 ? val : val / num;
        }
        return val;
    }

    // 执行 for 循环：header = 变量=起始;条件;步进，body = 循环体动作
    // 安全限制：最多 300 次（兜底）；禁止嵌套；死循环方向检测；仅按钮触发（渲染不自动执行）
    function executeMdFor(header, body, msgEl) {
        let segs = String(header || '').split(';');
        if (segs.length < 3) return;
        let initM = segs[0].match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(-?\d+)$/);
        if (!initM) return;
        let varName = initM[1];
        let val = parseInt(initM[2], 10);
        let cond = segs[1].trim();
        let step = segs[2].trim();
        // 禁止嵌套 for（循环体内不允许再出现 for:）
        if (body.indexOf('for:') >= 0) return;
        // ==== 死循环防范 ====
        // 1) 条件必须是有界格式且使用循环变量：变量 op 数字
        let condM = String(cond).match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(<=|>=|==|!=|<|>)\s*(-?\d+)$/);
        if (!condM || condM[1] !== varName) return;
        // 2) 步进必须是有界格式且使用循环变量：变量 op 数字
        let stepM = String(step).match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*([+\-*/])\s*(\d+)$/);
        if (!stepM || stepM[1] !== varName) return;
        let stepOp = stepM[2];
        let stepNum = parseInt(stepM[3], 10);
        // 3) 步进值为 0 / 乘 1 / 除 1：变量不前进 → 必死循环，拒绝
        if (stepNum === 0) return;
        if (stepOp === '*' && stepNum === 1) return;
        if (stepOp === '/' && stepNum === 1) return;
        // 4) 方向检测：上界条件（< <=）必须递增步进（+ *）；下界条件（> >=）必须递减步进（- /）
        //    方向矛盾（如 i<5 配 i-1）会使变量远离边界 → 必死循环，拒绝
        let condOp = condM[2];
        let increasing = (stepOp === '+' || stepOp === '*');
        let decreasing = (stepOp === '-' || stepOp === '/');
        if ((condOp === '<' || condOp === '<=') && decreasing) return;
        if ((condOp === '>' || condOp === '>=') && increasing) return;
        // 5) 兜底：最高 300 次（== / != 等不可预测条件也由此截断）
        const MAX_LOOPS = 300;
        let count = 0;
        let re = new RegExp('%' + varName + '%', 'g');
        let re2 = new RegExp('\\{' + varName + '\\}', 'g');
        while (count < MAX_LOOPS && evalForCond(cond, varName, val)) {
            // 循环体内替换 %变量% / {变量}
            let bodyAction = body.replace(re, String(val)).replace(re2, String(val));
            executeMdAction(bodyAction, msgEl);
            val = applyForStep(step, varName, val);
            count++;
        }
    }

    function executeMdAction(action, msgEl) {
        if (!action) return;
        // 解码 HTML 实体（escapeHtml 转义后残留的 &lt; &gt; &amp; 等，恢复为原始字符）
        if (action.indexOf('&') >= 0) {
            action = action.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
        }
        if (action.indexOf('send:') === 0) {
            $chatInput.value = resolveMdPlaceholders(action.slice(5), msgEl);
            $chatInput.style.height = 'auto';
            sendMessage();
        } else if (action.indexOf('copy:') === 0) {
            copyToClipboard(resolveMdPlaceholders(action.slice(5), msgEl));
            showTopToast('已复制', false);
        } else if (action.indexOf('reset:') === 0) {
            let id = action.slice(6).trim();
            let state = getMsgUIState(msgEl);
            if (state.inputs) state.inputs[id] = '';
            let inp = msgEl ? msgEl.querySelector('.md-input[data-input-id="' + id + '"]') : null;
            if (inp) inp.value = '';
            refreshMsgGets(msgEl);
        } else if (action.indexOf('switch:') === 0) {
            let id = action.slice(7).trim();
            let btn = msgEl ? msgEl.querySelector('.md-btn-switch[data-switch-id="' + id + '"]') : null;
            if (btn) switchMdValue(btn, msgEl);
        } else if (action.indexOf('close:') === 0) {
            let id = action.slice(6).trim();
            if (!msgEl) return;
            if (id) {
                let el = msgEl.querySelector('[data-ui-id="' + id + '"]');
                if (el) el.style.display = 'none';
            } else {
                let els = msgEl.querySelectorAll('.md-input-box, .md-get, .md-btn-ok, .md-btn-cancel, .md-btn-close, .md-btn-switch');
                for (let i = 0; i < els.length; i++) els[i].style.display = 'none';
            }
        } else if (action.indexOf('set:') === 0) {
            // 设置全局变量：set:foo=值（支持 {占位符} / %值% / 数学表达式）
            let eq = action.indexOf('=', 4);
            if (eq > 4) {
                let name = action.slice(4, eq).trim();
                let val = resolveMdPlaceholders(action.slice(eq + 1), msgEl);
                val = evalMdMath(val);
                setMdVar(name, val, msgEl);
            }
        } else if (action.indexOf('incr:') === 0) {
            // 自增：incr:foo 或 incr:foo=5
            let rest = action.slice(5).trim();
            let ieq = rest.indexOf('=');
            let id = ieq > 0 ? rest.slice(0, ieq).trim() : rest;
            let delta = ieq > 0 ? (parseInt(rest.slice(ieq + 1), 10) || 0) : 1;
            let state = getMsgUIState(msgEl);
            setMdVar(id, (parseInt(state.vars[id], 10) || 0) + delta, msgEl);
        } else if (action.indexOf('decr:') === 0) {
            // 自减：decr:foo 或 decr:foo=5
            let rest = action.slice(5).trim();
            let deq = rest.indexOf('=');
            let id = deq > 0 ? rest.slice(0, deq).trim() : rest;
            let delta = deq > 0 ? (parseInt(rest.slice(deq + 1), 10) || 0) : 1;
            let state = getMsgUIState(msgEl);
            setMdVar(id, (parseInt(state.vars[id], 10) || 0) - delta, msgEl);
        } else if (action.indexOf('bar.add:') === 0) {
            // 进度条增加：bar.add:id=值
            let rest = action.slice(8);
            let eq = rest.indexOf('=');
            if (eq > 0) {
                let id = rest.slice(0, eq).trim();
                let delta = parseInt(rest.slice(eq + 1), 10) || 0;
                let state = getMsgUIState(msgEl);
                updateMdBar(id, (state.bars[id] || 0) + delta, msgEl);
            }
        } else if (action.indexOf('bar.sub:') === 0) {
            // 进度条减少：bar.sub:id=值
            let rest = action.slice(8);
            let eq = rest.indexOf('=');
            if (eq > 0) {
                let id = rest.slice(0, eq).trim();
                let delta = parseInt(rest.slice(eq + 1), 10) || 0;
                let state = getMsgUIState(msgEl);
                updateMdBar(id, (state.bars[id] || 0) - delta, msgEl);
            }
        } else if (action.indexOf('bar.set:') === 0) {
            // 进度条设置：bar.set:id=值
            let rest = action.slice(8);
            let eq = rest.indexOf('=');
            if (eq > 0) {
                let id = rest.slice(0, eq).trim();
                let val = parseInt(resolveMdPlaceholders(rest.slice(eq + 1), msgEl), 10) || 0;
                updateMdBar(id, val, msgEl);
            }
        } else if (action.indexOf('timer.start:') === 0) {
            // 启动倒计时：timer.start:id
            let id = action.slice(11).trim();
            startMdTimer(id, msgEl);
        } else if (action.indexOf('timer.stop:') === 0) {
            // 停止倒计时：timer.stop:id
            let id = action.slice(10).trim();
            let state = getMsgUIState(msgEl);
            if (state.timers[id]) { clearInterval(state.timers[id]); delete state.timers[id]; }
        } else if (action.indexOf('show:') === 0) {
            // 显示组件：show:画板id（或 data-ui-id 组件）
            let id = action.slice(5).trim();
            if (msgEl) {
                let el = msgEl.querySelector('.md-board[data-board-id="' + id + '"], [data-ui-id="' + id + '"]');
                if (el) { el.style.display = ''; renderBoard(msgEl, el); }
            }
        } else if (action.indexOf('hide:') === 0) {
            // 隐藏组件：hide:画板id（或 data-ui-id 组件）
            let id = action.slice(5).trim();
            if (msgEl) {
                let el = msgEl.querySelector('.md-board[data-board-id="' + id + '"], [data-ui-id="' + id + '"]');
                if (el) el.style.display = 'none';
            }
        } else if (action.indexOf('for:') === 0) {
            // for 循环：for:变量=起始;条件;步进;循环体（最高 300 次，禁止嵌套，仅按钮触发）
            let rest = action.slice(4);
            let segs = rest.split(';');
            if (segs.length >= 4) {
                let header = segs[0] + ';' + segs[1] + ';' + segs[2];
                let body = segs.slice(3).join(';');
                executeMdFor(header, body, msgEl);
            }
        }
    }

    document.addEventListener('click', function (e) {
        // 投票选项点击（vote 组件）
        let voteOpt = e.target.closest('.md-vote-opt');
        if (voteOpt) {
            e.preventDefault();
            handleVoteClick(voteOpt);
            return;
        }
        let btn = e.target.closest('.md-btn, .md-hide');
        if (!btn) return;
        // 权限禁用按钮：点了没效果（不播放音效）
        if (btn.dataset.disabled !== undefined) {
            e.preventDefault();
            showTopToast('你没有权限使用此按钮', true);
            return;
        }
        // 倒计时锁定中：不可操作
        if (btn.dataset.timerLocked !== undefined) {
            e.preventDefault();
            showTopToast('倒计时结束后才可操作', true);
            return;
        }
        // 点击次数已用完：拦截
        if (btn.dataset.clickDisabled !== undefined) {
            e.preventDefault();
            showTopToast('点击次数已用完', true);
            return;
        }
        // 加密内容：点击解密弹窗
        if (btn.dataset.cipher !== undefined) {
            e.preventDefault();
            showMdDecryptModal(mdDecrypt(btn.dataset.cipher, btn.dataset.cipherKey || 'md'));
            return;
        }
        // 画板弹窗：点击显示内置画板
        if (btn.dataset.boardModal !== undefined) {
            e.preventDefault();
            openBoardModal(btn.dataset.boardModal);
            return;
        }
        // 骰子：点击掷骰
        if (btn.dataset.dice !== undefined) {
            e.preventDefault();
            rollDice(btn);
            return;
        }
        // 图集：点击打开轮播弹窗
        if (btn.dataset.gallery !== undefined) {
            e.preventDefault();
            openGalleryModal(btn);
            return;
        }
        // 点击次数检查
        if (btn.dataset.click) {
            let rule;
            try { rule = JSON.parse(btn.dataset.click); } catch (e2) { rule = null; }
            if (rule && !checkBtnClick(btn, rule)) {
                e.preventDefault();
                return;
            }
        }
        // 只有动作按钮阻止默认并执行；普通跳转按钮（无 data-*）放行，让浏览器正常打开链接
        let isAction = btn.dataset.copy !== undefined || btn.dataset.send !== undefined ||
            btn.dataset.modalContent !== undefined || btn.dataset.modalTitle !== undefined ||
            btn.dataset.embed !== undefined || btn.dataset.confirmMsg !== undefined ||
            btn.dataset.detailsTitle !== undefined || btn.dataset.rand !== undefined ||
            btn.dataset.ok !== undefined || btn.dataset.cancel !== undefined ||
            btn.dataset.close !== undefined || btn.dataset.switchId !== undefined;
        if (isAction) {
            e.preventDefault();
            executeBtn(btn);
        }
    });

    // 解密内容弹窗
    function showMdDecryptModal(text) {
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        overlay.innerHTML =
            '<div class="md-modal">' +
            '<div class="md-modal-header"><span class="md-modal-title">解密内容</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button></div>' +
            '<div class="md-modal-body" style="white-space:pre-wrap;word-break:break-word;">' + escapeHtml(text) + '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    }

    // 输入框 input 事件（委托）：更新当前消息状态，并刷新 get: / 条件显示 / onchange 联动
    document.addEventListener('input', function (e) {
        let inp = e.target.closest('.md-input');
        if (!inp) return;
        let msgEl = inp.closest('.lobby-msg, .md-modal-body');
        if (!msgEl) return;
        let state = getMsgUIState(msgEl);
        state.inputs[inp.getAttribute('data-input-id')] = inp.value;
        refreshMsgGets(msgEl);
        refreshShowIfs(msgEl);
        // onchange 联动
        let oc = inp.getAttribute('data-onchange');
        if (oc) executeMdAction(decodeURIComponent(oc), msgEl);
    });

    // 判断是否为站外链接（http/https 且域名不同于当前站点）
    function isExternalUrl(href) {
        if (!/^https?:\/\//i.test(href)) return false;
        try {
            return new URL(href, window.location.href).hostname !== window.location.hostname;
        } catch (e) {
            return false;
        }
    }

    // 站外链接警告弹窗：确认后才新标签页打开
    function showExternalLinkWarning(url) {
        if (!canOpenModal()) return;
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        overlay.innerHTML =
            '<div class="md-modal md-confirm">' +
            '<div class="md-modal-header"><span class="md-modal-title">站外链接提醒</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button></div>' +
            '<div class="md-modal-body">你即将访问站外链接，请确认链接安全：<br><span class="external-link-url">' + escapeHtml(url) + '</span></div>' +
            '<div class="md-confirm-actions">' +
            '<button class="doodle-btn md-confirm-cancel">取消</button>' +
            '<button class="doodle-btn md-confirm-ok">继续访问</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.querySelector('.md-confirm-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('.md-confirm-ok').addEventListener('click', function () {
            overlay.remove();
            window.open(url, '_blank', 'noopener');
        });
    }

    // 全局拦截站外链接：点击先弹警告，确认后新标签页打开
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented) return;
        let a = e.target.closest('a[href]');
        if (!a) return;
        let href = a.getAttribute('href') || '';
        if (!isExternalUrl(href)) return;
        e.preventDefault();
        showExternalLinkWarning(href);
    });

    // 确认弹窗：点击后先确认，确认后执行动作
    function openConfirmModal(btn) {
        let msg = decodeURIComponent(btn.dataset.confirmMsg || '确定执行吗？');
        let action = decodeURIComponent(btn.dataset.confirmAction || '');
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        overlay.innerHTML =
            '<div class="md-modal md-confirm">' +
            '<div class="md-modal-header"><span class="md-modal-title">确认操作</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button></div>' +
            '<div class="md-modal-body">' + escapeHtml(msg) + '</div>' +
            '<div class="md-confirm-actions">' +
            '<button class="doodle-btn md-confirm-cancel">取消</button>' +
            '<button class="doodle-btn danger md-confirm-ok">确认</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        applyModalAnim(overlay, btn);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.querySelector('.md-confirm-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('.md-confirm-ok').addEventListener('click', function () {
            overlay.remove();
            executeBtnAction(action);
        });
    }

    // 执行按钮动作（确认按钮的后续动作）：send:/copy:/https://embed:/modal:
    function executeBtnAction(action) {
        if (!action) return;
        if (action.indexOf('send:') === 0) {
            $chatInput.value = action.slice(5);
            $chatInput.style.height = 'auto';
            sendMessage();
        } else if (action.indexOf('copy:') === 0) {
            copyToClipboard(action.slice(5));
            showTopToast('已复制', false);
        } else if (action.indexOf('embed:') === 0) {
            let eu = action.slice(6);
            if (/^https?:\/\//.test(eu)) openEmbedModal(eu);
        } else if (action.indexOf('modal:') === 0) {
            let mRaw = action.slice(6);
            let mSep = mRaw.indexOf('|');
            let mT = mSep >= 0 ? mRaw.slice(0, mSep) : '提示';
            let mC = mSep >= 0 ? mRaw.slice(mSep + 1) : mRaw;
            let tmpBtn = document.createElement('a');
            tmpBtn.dataset.modalTitle = encodeURIComponent(mT);
            tmpBtn.dataset.modalContent = encodeURIComponent(mC);
            openMdModal(tmpBtn);
        } else if (/^https?:\/\//.test(action)) {
            window.open(action, '_blank', 'noopener');
        }
    }

    // 折叠/详情：点击展开收起，内容直接插入按钮下方
    function toggleDetails(btn) {
        // 查找或创建按钮的兄弟容器
        let nextEl = btn.nextElementSibling;
        if (nextEl && nextEl.classList.contains('md-details-panel')) {
            nextEl.remove();
            return;
        }
        let title = decodeURIComponent(btn.dataset.detailsTitle || '详情');
        let content = decodeURIComponent(btn.dataset.detailsContent || '');
        let panel = document.createElement('div');
        panel.className = 'md-details-panel';
        panel.innerHTML = '<div class="md-details-title">' + escapeHtml(title) + '</div>' +
            '<div class="md-details-body">' + mdFormat(content) + '</div>';
        btn.insertAdjacentElement('afterend', panel);
        // 初始化折叠内容里的 md 组件（画板/倒计时/进度条等）
        initMdComponents(panel);
    }

    // 随机弹窗：从多个内容中随机显示一个（标题用 t 参数）
    function openRandModal(btn) {
        if (!canOpenModal()) return;
        let raw = decodeURIComponent(btn.dataset.rand || '');
        let parts = raw.split('|').map((s) => { return s.trim(); }).filter(Boolean);
        if (!parts.length) return;
        let mTitle = btn.dataset.randTitle ? decodeURIComponent(btn.dataset.randTitle) : '随机';
        let pick = parts[Math.floor(Math.random() * parts.length)] || '';
        let tmpBtn = document.createElement('a');
        tmpBtn.dataset.modalTitle = encodeURIComponent(mTitle);
        tmpBtn.dataset.modalContent = encodeURIComponent(pick);
        if (btn && btn.dataset.anim) tmpBtn.dataset.anim = btn.dataset.anim;
        openMdModal(tmpBtn);
    }

    // ==================== 按钮音效 ====================
    let sfxAudio = null;
    let sfxToastEl = null;
    let sfxTimeoutId = null;

    // 合法音频扩展名白名单
    const VALID_AUDIO_EXT = /\.(mp3|wav|ogg|aac|m4a|flac|opus|webm|weba|wma|mid|midi)(\?.*)?$/i;
    // 合法图片扩展名白名单
    const VALID_IMG_EXT = /\.(png|jpe?g|gif|webp|bmp|svg|ico)(\?.*)?$/i;
    // 弹窗图片最大尺寸（像素），防止超大图片撑爆布局
    const IMG_MAX_DIMENSION = 5000;
    // 最大播放时长（秒），防止加载超大非音频文件
    const SFX_MAX_DURATION = 30;

    // 验证是否为合法的音频 URL
    function isValidAudioUrl(url) {
        if (!url || typeof url !== 'string') return false;
        if (!/^https?:\/\//i.test(url)) return false;
        if (!VALID_AUDIO_EXT.test(url)) return false;
        if (/^(data|javascript|file|vbscript):/i.test(url)) return false;
        return true;
    }

    // 验证是否为合法的图片 URL（用于弹窗内图片）
    function isValidImageUrl(url) {
        if (!url || typeof url !== 'string') return false;
        if (!/^https?:\/\//i.test(url)) return false;
        if (!VALID_IMG_EXT.test(url)) return false;
        if (/^(data|javascript|file|vbscript):/i.test(url)) return false;
        return true;
    }

    // 播放按钮音效：静音"一起听歌"→ 播放一次 → 结束恢复；顶部显示停止按钮
    function playButtonSound(soundUrl) {
        if (!soundUrl) return;
        // 安全校验：拒绝非法链接
        if (!isValidAudioUrl(soundUrl)) {
            showTopToast('音效链接不合法，仅支持 mp3/wav/ogg/aac 等音频格式', true);
            return;
        }
        if (!sfxAudio) sfxAudio = new Audio();
        // 播放音效时静音一起听歌
        if (songCurAudio) { try { songCurAudio.muted = true; } catch (e) { } }
        let done = function () {
            if (songCurAudio) { try { songCurAudio.muted = false; } catch (e) { } }
            clearSfxTimeout();
            hideSfxToast();
        };
        // 超时保护：超过最大时长自动停止
        clearSfxTimeout();
        sfxTimeoutId = setTimeout(function () {
            stopSfx();
            showTopToast('音效播放超时已自动停止', true);
        }, SFX_MAX_DURATION * 1000);
        try {
            sfxAudio.onended = done;
            sfxAudio.onerror = function () {
                clearSfxTimeout();
                done();
            };
            sfxAudio.src = soundUrl;
            sfxAudio.currentTime = 0;
            let p = sfxAudio.play();
            if (p && p.catch) {
                p.catch(() => {
                    clearSfxTimeout();
                    done();
                });
            }
            showSfxToast();
        } catch (e) {
            clearSfxTimeout();
            done();
        }
    }

    function clearSfxTimeout() {
        if (sfxTimeoutId) {
            clearTimeout(sfxTimeoutId);
            sfxTimeoutId = null;
        }
    }

    function showSfxToast() {
        hideSfxToast();
        sfxToastEl = document.createElement('div');
        sfxToastEl.className = 'sfx-toast';
        sfxToastEl.innerHTML = '<span class="sfx-label"><svg class="icon" viewBox="0 0 24 24" style="width:14px;height:14px;vertical-align:-2px;"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg> 播放音效中</span>' +
            '<button class="sfx-stop" title="停止播放音效">停止播放</button>';
        sfxToastEl.querySelector('.sfx-stop').addEventListener('click', function () {
            stopSfx();
        });
        document.body.appendChild(sfxToastEl);
    }

    function stopSfx() {
        clearSfxTimeout();
        if (sfxAudio) {
            try { sfxAudio.pause(); sfxAudio.src = ''; } catch (e) { }
        }
        if (songCurAudio) { try { songCurAudio.muted = false; } catch (e) { } }
        hideSfxToast();
    }

    function hideSfxToast() {
        if (sfxToastEl) {
            sfxToastEl.remove();
            sfxToastEl = null;
        }
    }

    // ==================== MD 弹窗 ====================

    // 打开弹窗前检查数量上限（最多同时 10 个）
    function canOpenModal() {
        let count = document.querySelectorAll('.md-modal-overlay').length;
        if (count >= 10) {
            showTopToast('弹窗数量已达上限（最多10个），请先关闭部分弹窗', true);
            return false;
        }
        return true;
    }

    // 应用弹窗动画时长（秒）
    function applyModalAnim(overlay, btn) {
        if (!overlay || !btn) return;
        let sec = parseFloat(btn.dataset.anim || '');
        if (sec > 0) {
            let modal = overlay.querySelector('.md-modal');
            if (modal) modal.style.animationDuration = sec + 's';
        }
    }

    // MD 网页内嵌弹窗：iframe 加载指定网址
    function openEmbedModal(url, btn) {
        if (!canOpenModal()) return;
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay embed-overlay';
        overlay.innerHTML =
            '<div class="md-modal md-modal-embed">' +
            '<div class="md-modal-header">' +
            '<span class="md-modal-title">' + escapeHtml(url) + '</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button>' +
            '</div>' +
            '<div class="md-modal-body embed-body"><iframe src="' + escapeHtmlAttr(url) + '" loading="lazy" sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe></div>' +
            '</div>';
        document.body.appendChild(overlay);
        applyModalAnim(overlay, btn || null);
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.remove();
        });
    }

    // MD 弹窗：标题 + 内容（内容支持 MD 渲染、嵌套按钮、内置图片）
    function openMdModal(btn) {
        if (!canOpenModal()) return;
        let title = decodeURIComponent(btn.dataset.modalTitle || '提示');
        let content = decodeURIComponent(btn.dataset.modalContent || '');
        let overlay = document.createElement('div');
        overlay.className = 'md-modal-overlay';
        overlay.innerHTML =
            '<div class="md-modal">' +
            '<div class="md-modal-header">' +
            '<span class="md-modal-title">' + escapeHtml(title) + '</span>' +
            '<button class="md-modal-close" title="关闭">&times;</button>' +
            '</div>' +
            '<div class="md-modal-body"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        applyModalAnim(overlay, btn);
        let body = overlay.querySelector('.md-modal-body');
        // 弹窗内容：允许图片（消息内禁止），图片做安全处理
        body.innerHTML = mdFormat(content, { allowImg: true });
        // 初始化弹窗内 md 组件（画板/倒计时/进度条/条件显示等）
        initMdComponents(body);
        let imgs = body.querySelectorAll('img');
        for (let ii = 0; ii < imgs.length; ii++) {
            (function (img) {
                let url = img.getAttribute('src') || '';
                let alt = img.getAttribute('alt') || '';
                let showPlaceholder = function (msg) {
                    let errEl = document.createElement('span');
                    errEl.className = 'md-img-error';
                    errEl.textContent = msg;
                    if (img.parentNode) img.parentNode.replaceChild(errEl, img);
                };
                // 1. URL 格式校验：仅允许 http/https 链接
                if (!/^https?:\/\/.+/i.test(url)) {
                    showPlaceholder('该链接不是一个合法的链接');
                    return;
                }
                // 2. 扩展名校验：仅允许常见图片格式
                if (!VALID_IMG_EXT.test(url)) {
                    showPlaceholder('链接不是支持的图片格式（png/jpg/gif/webp/bmp/svg）');
                    return;
                }
                // 3. 预检：先不直接加载，用 Image 验证是合法可加载的图片后才真正显示
                //    （非法/非图片链接不会触发加载，防止误加载流量）
                img.removeAttribute('src');
                img.loading = 'lazy';
                img.referrerPolicy = 'no-referrer';
                let probe = new Image();
                probe.referrerPolicy = 'no-referrer';
                probe.onload = function () {
                    // 尺寸检查：拒绝超大图片（防止撑爆布局或恶意消耗内存）
                    if (probe.naturalWidth > IMG_MAX_DIMENSION || probe.naturalHeight > IMG_MAX_DIMENSION) {
                        showPlaceholder('图片尺寸过大（最大 ' + IMG_MAX_DIMENSION + 'px）');
                        return;
                    }
                    img.src = url; // 验证成功，正式加载（走浏览器缓存）
                    img.addEventListener('error', function () {
                        let errEl = document.createElement('span');
                        errEl.className = 'md-img-error';
                        errEl.textContent = '该链接不是一个合法的链接';
                        if (img.parentNode) img.parentNode.replaceChild(errEl, img);
                    });
                };
                probe.onerror = function () {
                    showPlaceholder('该链接不是一个合法的链接');
                };
                probe.src = url;
            })(imgs[ii]);
        }
        overlay.querySelector('.md-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.remove();
        });
    }

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
        $chatInput.style.height = 'auto';
        hideMentionDropdown();
    }

    $btnSend.addEventListener('click', sendMessage);
    $chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            // @提及下拉打开时，回车选择提及（不换行）
            if ($mentionDropdown && $mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                selectMentionedUser();
                return;
            }
            // 桌面端：Enter 发送，Ctrl+Enter 换行；手机端保持原行为（Enter 换行）
            const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);
            if (!isMobile) {
                if (e.ctrlKey) {
                    // Ctrl+Enter 换行：插入换行符
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    this.value = this.value.slice(0, start) + '\n' + this.value.slice(end);
                    this.selectionStart = this.selectionEnd = start + 1;
                    this.dispatchEvent(new Event('input', { bubbles: true }));
                    return;
                }
                e.preventDefault();
                sendMessage();
                return;
            }
            // 手机端：默认换行
            return;
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

    // textarea 自动撑高
    $chatInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
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
        let value = $chatInput.value;
        let cursorPos = $chatInput.selectionStart;
        let textBeforeCursor = value.substring(0, cursorPos);
        let atMatch = /@(\S*)$/.exec(textBeforeCursor);

        if (atMatch) {
            ensureMentionDropdown();
            let query = atMatch[1].toLowerCase();
            let filtered = onlinePlayers.filter((p) => {
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
        players.forEach((p, i) => {
            let item = document.createElement('div');
            item.className = 'lobby-mention-item' + (i === 0 ? ' active' : '');
            item.textContent = p.nickname;
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                insertMention(p.nickname);
            });
            $mentionDropdown.appendChild(item);
        });
        // 定位到输入框上方
        let inputRect = $chatInput.getBoundingClientRect();
        $mentionDropdown.style.left = inputRect.left + 'px';
        $mentionDropdown.style.bottom = (window.innerHeight - inputRect.top + 8) + 'px';
        $mentionDropdown.style.display = 'block';
    }

    function navigateMentionDropdown(direction) {
        let items = $mentionDropdown.querySelectorAll('.lobby-mention-item');
        if (items.length === 0) return;
        items[selectedMentionIndex].classList.remove('active');
        selectedMentionIndex = (selectedMentionIndex + direction + items.length) % items.length;
        items[selectedMentionIndex].classList.add('active');
        items[selectedMentionIndex].scrollIntoView({ block: 'nearest' });
    }

    function selectMentionedUser() {
        let items = $mentionDropdown.querySelectorAll('.lobby-mention-item');
        if (items.length === 0 || selectedMentionIndex < 0) return;
        let name = items[selectedMentionIndex].textContent;
        insertMention(name);
    }

    function insertMention(nickname) {
        let value = $chatInput.value;
        let before = value.substring(0, mentionStartPos);
        let after = value.substring($chatInput.selectionStart);
        $chatInput.value = before + '@' + nickname + ' ' + after;
        let newCursor = mentionStartPos + nickname.length + 2;
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
        let previewText = replyTarget.text || '';
        // 回复表情消息：预览显示表情包图片
        let stickerMatch = String(previewText).match(/^\[sticker:(.+?)\]$/);
        if (stickerMatch) {
            let sUrl = resolveStickerUrl(stickerMatch[1], '', stickerMap);
            if (sUrl) {
                $replyPreviewText.innerHTML = escapeHtml(replyTarget.name) +
                    ': <img class="reply-sticker-preview" src="' + escapeHtmlAttr(sUrl) + '" alt="表情">';
                $replyPreview.classList.add('show');
                $chatInput.focus();
                return;
            }
        }
        if (previewText.length > 50) {
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
            '<p style="font-size:13px;color:let(--text-secondary);margin-bottom:10px;">举报来自 <strong>' + escapeHtml(targetName) + '</strong> 的消息</p>' +
            '<p style="font-size:12px;color:let(--text-subtle);background:let(--surface-violet-subtle, #f3f0ff);padding:6px 10px;border-radius:6px;margin-bottom:10px;max-height:60px;overflow:hidden;">' + escapeHtml(messageContent || '（空消息）') + '</p>' +
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
        let players = data.players || [];
        // 按 fd 去重（兜底：防止后端清理延迟导致的重复条目）
        let seen = {};
        let deduped = [];
        for (let i = 0; i < players.length; i++) {
            let p = players[i];
            if (seen.hasOwnProperty(p.fd)) continue;
            seen[p.fd] = true;
            deduped.push(p);
        }
        onlinePlayers = deduped;
        onlinePlayerCount = deduped.length;

        if ($usersCount) $usersCount.textContent = onlinePlayerCount;
        // 在线人数变化 → 移除投票阈值变化 → 只更新移除投票计数显示，不重建列表
        updateRemoveVoteDisplay();
        renderUsersList();
        // 右上角连接状态栏已移除，无需刷新
    }

    // 渲染在线玩家列表（管理员额外显示封禁/禁言按钮）
    function renderUsersList() {
        if (!$usersList) return;
        $usersList.innerHTML = '';
        onlinePlayers.forEach((p) => {
            let item = document.createElement('div');
            item.className = 'lobby-user-item';
            item.dataset.fd = p.fd;
            let isMe = p.nickname && p.nickname === myNickname;
            if (isMe) item.classList.add('you');

            let avatar = document.createElement('span');
            avatar.className = 'user-avatar';
            avatar.textContent = getAvatarChar(p.nickname || '?');
            avatar.style.background = getAvatarColor(p.nickname || '');
            item.appendChild(avatar);
            item.appendChild(document.createTextNode(p.nickname || '匿名'));

            // 管理员操作：除自己外显示 禁言/解禁 + 封禁 按钮
            if (isLobbyAdmin && !isMe) {
                let actions = document.createElement('span');
                actions.className = 'user-admin-actions';

                let muteBtn = document.createElement('button');
                muteBtn.className = 'user-admin-btn' + (p.muted ? ' unmute' : '');
                muteBtn.textContent = p.muted ? '解禁' : '禁言';
                muteBtn.title = p.muted ? '解除禁言' : '禁言';
                muteBtn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    if (p.muted) {
                        send({ type: 'lobby_unmute', target_fd: p.fd });
                    } else {
                        showMuteDialog(p);
                    }
                });
                actions.appendChild(muteBtn);

                let banBtn = document.createElement('button');
                banBtn.className = 'user-admin-btn ban';
                banBtn.textContent = '封禁';
                banBtn.title = '封禁';
                banBtn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    showBanDialog(p);
                });
                actions.appendChild(banBtn);

                // 孤立 / 解除孤立
                let isoBtn = document.createElement('button');
                isoBtn.className = 'user-admin-btn' + (p.isolated ? ' iso-active' : '');
                isoBtn.textContent = p.isolated ? '解除孤立' : '孤立';
                isoBtn.title = p.isolated ? '解除孤立' : '孤立（其消息不再广播）';
                isoBtn.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    if (p.isolated) {
                        send({ type: 'lobby_unisolate', target_fd: p.fd });
                    } else {
                        showIsolateDialog(p);
                    }
                });
                actions.appendChild(isoBtn);

                item.appendChild(actions);
            }
            // 拍一拍：双击用户列表头像
            addAvatarNudgeHandler(item, p.nickname);
            $usersList.appendChild(item);
        });
    }

    // ==================== 管理员弹窗：封禁 / 禁言 ====================
    function showBanDialog(player) {
        let overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.innerHTML =
            '<div class="admin-dialog">' +
            '<h3>封禁玩家</h3>' +
            '<p class="admin-dialog-target">' + escapeHtml(player.nickname || '') + '</p>' +
            '<textarea id="admin-ban-reason" placeholder="请输入封禁理由（必填）" maxlength="200" rows="3"></textarea>' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="admin-ban-cancel">取消</button>' +
            '<button class="doodle-btn danger" id="admin-ban-confirm">确认封禁</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        let reasonInput = overlay.querySelector('#admin-ban-reason');
        reasonInput.focus();
        overlay.querySelector('#admin-ban-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('#admin-ban-confirm').addEventListener('click', function () {
            let reason = reasonInput.value.trim();
            if (!reason) { showTopToast('请输入封禁理由', true); return; }
            send({ type: 'lobby_ban', target_fd: player.fd, reason: reason });
            overlay.remove();
        });
    }

    function showIsolateDialog(player) {
        let overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.innerHTML =
            '<div class="admin-dialog">' +
            '<h3>孤立玩家</h3>' +
            '<p class="admin-dialog-target">' + escapeHtml(player.nickname || '') + '</p>' +
            '<p style="font-size:11px;color:let(--text-subtle);margin-bottom:8px;">孤立期间其消息不再广播（仅本人可见），且不提醒其他玩家</p>' +
            '<input type="number" id="admin-isolate-minutes" placeholder="孤立分钟数" min="1" max="1440" value="10">' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="admin-isolate-cancel">取消</button>' +
            '<button class="doodle-btn danger" id="admin-isolate-confirm">确认孤立</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        let minutesInput = overlay.querySelector('#admin-isolate-minutes');
        minutesInput.focus();
        overlay.querySelector('#admin-isolate-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('#admin-isolate-confirm').addEventListener('click', function () {
            let minutes = parseInt(minutesInput.value, 10);
            if (!minutes || minutes < 1) { showTopToast('请输入有效的孤立分钟数', true); return; }
            send({ type: 'lobby_isolate', target_fd: player.fd, minutes: minutes });
            overlay.remove();
        });
    }

    function showMuteDialog(player) {        let overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.innerHTML =
            '<div class="admin-dialog">' +
            '<h3>禁言玩家</h3>' +
            '<p class="admin-dialog-target">' + escapeHtml(player.nickname || '') + '</p>' +
            '<input type="number" id="admin-mute-minutes" placeholder="禁言分钟数" min="1" max="1440" value="10">' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="admin-mute-cancel">取消</button>' +
            '<button class="doodle-btn danger" id="admin-mute-confirm">确认禁言</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        let minutesInput = overlay.querySelector('#admin-mute-minutes');
        minutesInput.focus();
        overlay.querySelector('#admin-mute-cancel').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('#admin-mute-confirm').addEventListener('click', function () {
            let minutes = parseInt(minutesInput.value, 10);
            if (!minutes || minutes < 1) { showTopToast('请输入有效的禁言分钟数', true); return; }
            send({ type: 'lobby_mute', target_fd: player.fd, minutes: minutes });
            overlay.remove();
        });
    }

    // ==================== 表情 ====================
    let stickerMap = loadStickerCache();

    function renderStickerPicker() {
        const fresh = loadStickerCache();
        if (Object.keys(fresh).length > 0) stickerMap = fresh;

        renderSharedStickerPicker($stickerPickerBody, stickerMap, function (id, st) {
            send({ type: 'lobby_sticker', id: id });
            // 立即本地渲染，不等服务端广播回传（防止表情被吞）
            appendStickerMessage({
                id: id, url: st ? st.url : '', name: st ? st.name : '',
                sender: myNickname
            });
            // 记录已渲染的表情，防止服务端广播回报时重复追加
            lastSentStickerId = id;
            $stickerPicker.style.display = 'none';
        });
    }

    bindStickerPickerTabs('lobby-sticker-picker', renderStickerPicker, repositionStickerPicker);

    function requestStickers() {
        send({ type: 'get_stickers', version: getStickerCacheVersion(), player_token: getUserToken() });
    }

    function showStickerLightbox(url) {
        $stickerLightboxImg.src = url;
        $stickerLightbox.style.display = 'flex';
    }

    $btnSticker.addEventListener('click', function () {
        if ($stickerPicker.style.display === 'none' || !$stickerPicker.style.display) {
            requestStickers();
            renderStickerPicker();
            $stickerPicker.style.visibility = 'hidden';
            $stickerPicker.style.display = 'flex';
            repositionStickerPicker();
            $stickerPicker.style.visibility = 'visible';
        } else {
            $stickerPicker.style.display = 'none';
        }
    });

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
    $btnToggleUsers.addEventListener('click', function (e) {
        if (e) e.stopPropagation();
        if (!$usersPanel) return;
        if ($usersPanel.style.display === 'none') {
            $usersPanel.style.display = 'flex';
            // 关闭点歌面板
            if ($songPanel && $songPanel.style.display !== 'none') {
                closeSidebar($songPanel);
            }
            if ($overlay) $overlay.style.display = 'block';
        } else {
            closeSidebar($usersPanel);
        }
    });

    // ==================== 点击遮罩关闭弹窗 ====================
    if ($overlay) {
        $overlay.addEventListener('click', function () {
            closeSidebar($usersPanel);
            closeSidebar($songPanel);
        });
    }

    // ==================== 消息滚动 ====================
    let scrollGuard = false;
    let scrollGuardTimer = null;
    $messages.addEventListener('scroll', function () {
        stickyScroll = $messages.scrollTop + $messages.clientHeight < $messages.scrollHeight - 40;
        scrollGuard = true;
        if (scrollGuardTimer) clearTimeout(scrollGuardTimer);
        scrollGuardTimer = setTimeout(function () {
            scrollGuard = false;
            scrollGuardTimer = null;
        }, 150);
    });

    // ==================== 工具 ====================

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeHtmlAttr(text) {
        return String(text == null ? '' : text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * 检测字符画/对齐文本：包含连续空格（≥2 个）时需用等宽字体 + 保留空格。
     * 含 MD 语法标记（* ` [ | # > - 等）的消息视为普通文本走 MD 渲染，避免误伤。
     */
    function isAsciiArt(content) {
        let c = String(content || '');
        if (!/ {2,}/.test(c)) return false;
        // 含 Markdown 语法标记 → 按普通 MD 渲染
        if (/[*`\[|#>]/.test(c)) return false;
        return true;
    }

    /**
     * 完整 Markdown 渲染（marked + DOMPurify，支持 GFM 全语法）
     * 安全流程：escapeHtml 转义 → B站链接占位 → marked.parse → DOMPurify 消毒
     */
    /**
     * 按钮颜色：解析 ::文本色|按钮色（#RRGGBB/RRGGBB/#RGB/RGB 或 -1 透明），返回 style 属性字符串
     */
    function normColor(c) {
        if (/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/.test(c)) return '#' + c;
        if (/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/.test(c)) return c;
        return '';
    }

    function buildColorStyle(fg, bg) {
        let parts = [];
        let nf = normColor(fg);
        if (nf) parts.push('color:' + nf);
        if (bg === '-1') {
            parts.push('background-color:transparent');
        } else {
            let nb = normColor(bg);
            if (nb) parts.push('background-color:' + nb);
        }
        return parts.length ? ' style="' + parts.join(';') + '"' : '';
    }

    /**
     * 解析点击次数限制规则（click= 参数值）：
     *   5              → 全局共享 5 次（mode=global）
     *   *5             → 每人独立 5 次（mode=per-user）
     *   5@名1:2@名2:1  → 全局共享 5 次 + 特定人覆盖次数（mode=mixed）
     * 返回 { mode, globalLimit, perUserLimit, extra }，无规则返回 null
     */
    function parseClickLimit(raw) {
        let r = String(raw || '').trim();
        if (!r) return null;
        let result = { mode: 'global', globalLimit: 0, perUserLimit: 0, extra: {} };
        let segs = r.split('@');
        let head = segs[0];
        if (head.charAt(0) === '*') {
            result.mode = 'per-user';
            result.perUserLimit = parseInt(head.slice(1), 10) || 0;
        } else {
            result.globalLimit = parseInt(head, 10) || 0;
        }
        for (let i = 1; i < segs.length; i++) {
            let seg = segs[i];
            let cIdx = seg.lastIndexOf(':');
            if (cIdx > 0) {
                let nm = seg.slice(0, cIdx).trim();
                let n = parseInt(seg.slice(cIdx + 1), 10) || 0;
                if (nm) result.extra[nm] = n;
            }
        }
        if (Object.keys(result.extra).length > 0) result.mode = 'mixed';
        return result;
    }

    // 按 | 分割参数，但跳过括号内（() 和 []）的 |，避免嵌套按钮/组件内部的 | 被误切分
    // 例：details:标题|内容[!x](music:URL|t=①) → 内层 |t=① 受括号保护，不被外层切走
    function splitTopLevelByPipe(str) {
        let parts = [];
        let cur = '';
        let depth = 0;
        for (let i = 0; i < str.length; i++) {
            let ch = str.charAt(i);
            if (ch === '(' || ch === '[') depth++;
            else if (ch === ')' || ch === ']') depth = Math.max(0, depth - 1);
            if (ch === '|' && depth === 0) { parts.push(cur); cur = ''; }
            else cur += ch;
        }
        parts.push(cur);
        return parts;
    }

    function splitBtnParams(raw) {
        let content = raw, fg = '', bg = '', perm = '', sound = '', anim = '', click = '';
        let rawStr = String(raw);
        // 兼容旧写法颜色后缀：::前景|背景 / ::前景/背景 / ::单色 / ::-1（透明）
        // 在 | 分割前提取，避免 ::fg|bg 里的 | 被当成参数分隔符导致颜色混乱
        // 优先级：显式 color=/color.bg= 参数 > :: 后缀（:: 仅兜底）
        let ccM = rawStr.match(/::([#0-9a-fA-F]{3,8}|-1)(?:[|/]([#0-9a-fA-F]{3,8}|-1))?\s*$/);
        if (ccM) {
            fg = ccM[1] || '';
            bg = ccM[2] !== undefined ? ccM[2] : '';
            // ::-1 单独写 = 透明背景（与 color.bg=-1 语义一致）
            if (fg === '-1' && bg === '') { bg = '-1'; fg = ''; }
            rawStr = rawStr.slice(0, ccM.index).replace(/\s+$/, '');
        }
        let parts = splitTopLevelByPipe(rawStr);
        // v2 标准：识别命名参数（键=值），其余为主参数
        let params = {};
        let mainParts = [];
        for (let i = 0; i < parts.length; i++) {
            let p = parts[i];
            // URL（含 ://）作为主参数，不尝试解析命名参数
            if (p.indexOf('://') >= 0) { mainParts.push(p); continue; }
            // v2 标准：命名参数只用 键=值 分隔（: 用于类型/动作前缀，不参与参数解析）
            let eq = p.indexOf('=');
            if (eq > 0) {
                let key = p.slice(0, eq).trim();
                if (/^[a-z][a-z0-9.]*$/.test(key)) { params[key] = p.slice(eq + 1); continue; }
            }
            mainParts.push(p);
        }
        content = mainParts.join('|');
        if (params.color !== undefined) {
            let cParts = String(params.color).split('|');
            if (cParts.length === 1 && cParts[0].trim() === '-1') { fg = ''; bg = '-1'; }
            else { fg = (cParts[0] || '').trim(); bg = (cParts[1] || '').trim(); }
        }
        if (params['color.bg'] !== undefined) bg = params['color.bg'];
        if (params.perm !== undefined) perm = params.perm;
        if (params.sound !== undefined) sound = params.sound;
        if (params.anim !== undefined) anim = params.anim;
        if (params.click !== undefined) click = params.click;
        return { content: content, fg: fg, bg: bg, perm: perm, sound: sound, anim: anim, click: click };
    }

    /**
     * 根据当前用户应用按钮权限：
     * 白名单（@名）不匹配 → 禁用；黑名单（!@名）匹配 → 禁用；
     * 内容映射（@名=内容）匹配 → 替换按钮内容
     * 返回 { allowed, content }
     */
    function applyBtnPermission(info, currentUser) {
        let user = String(currentUser || '').trim();
        let whitelist = [], blacklist = [], map = {};
        if (info.perm) {
            let segs = info.perm.split(',');
            for (let i = 0; i < segs.length; i++) {
                let seg = segs[i].trim();
                if (!seg) continue;
                if (seg.charAt(0) === '!') {
                    blacklist.push(seg.slice(1).replace(/^@/, '').trim());
                } else {
                    let eq = seg.indexOf('=');
                    if (eq >= 0) {
                        let nm = seg.slice(0, eq).replace(/^@/, '').trim();
                        map[nm] = seg.slice(eq + 1).trim();
                    } else {
                        whitelist.push(seg.replace(/^@/, '').trim());
                    }
                }
            }
        }
        let content = info.content;
        if (map[user]) content = map[user];
        let allowed = true;
        if (blacklist.indexOf(user) >= 0) allowed = false;
        if (whitelist.length > 0 && whitelist.indexOf(user) < 0) allowed = false;
        return { allowed: allowed, content: content };
    }

    function mdFormat(content, opts) {
        opts = opts || {};
        let allowImg = !!opts.allowImg; // 弹窗内允许图片，消息内禁止（防流量攻击）
        let text = escapeHtml(content);
        // 保护代码块（fenced / 内联代码）：防止其中的自定义语法（B站链接、动作按钮）被误解析
        let codeProtect = protectMarkdownCode(text);
        text = codeProtect.text;
        // B站/抖音链接 → 占位（在纯文本上处理，避免 marked 自动链接生成 <a> 包裹冲突）
        text = parseBilibiliLinks(text);

        // 预处理动作按钮：modal:/send:/copy:/embed:/confirm:/details:/rand:/...
        // 内容可含空格/中文/括号/嵌套，marked 的 URL 解析有限制，统一提前提取为占位符
        let actionBtns = [];
        let btnRe = /\[!([^\]]+)\]\((modal:|send:|copy:|embed:|confirm:|details:|rand:|input:|get:|ok:|cancel:|close:|switch:|var:|def:|cipher:|table:|music:|timer:|bar:|if:|hide:|text:|board:|vote:|dice:|at:|gallery:)/g;
        let bm;
        while ((bm = btnRe.exec(text))) {
            let bLabel = bm[1];
            let bType = bm[2];
            let bStart = btnRe.lastIndex;
            let depth = 1;
            let bi = bStart;
            for (; bi < text.length; bi++) {
                if (text[bi] === '(') depth++;
                else if (text[bi] === ')') { depth--; if (depth === 0) break; }
            }
            let bRaw = text.slice(bStart, bi);
            let bIdx = actionBtns.length;
            actionBtns.push({ label: bLabel, type: bType, raw: bRaw });
            let placeholder = '[[MDBTNACT' + bIdx + ']]';
            text = text.slice(0, bm.index) + placeholder + text.slice(bi + 1);
            btnRe.lastIndex = bm.index + placeholder.length;
        }

        // 恢复代码块：交回 marked 正常渲染（代码块内的自定义语法保持原样显示）
        for (let ci = 0; ci < codeProtect.parts.length; ci++) {
            text = text.split('[[RAWCODE' + ci + ']]').join(codeProtect.parts[ci]);
        }

        let rawHtml;
        if (window.marked) {
            // 自定义 Renderer：链接 [!文字](普通url) 渲染为跳转按钮样式
            let renderer = new window.marked.Renderer();
            let origLink = renderer.link ? renderer.link.bind(renderer) : null;
            // 图片渲染器：添加安全属性 + 懒加载 + 来源隔离
            let origImage = renderer.image ? renderer.image.bind(renderer) : null;
            if (origImage) {
                renderer.image = function (href, title, text) {
                    let url = String(href || '');
                    // 仅允许 http(s) + 图片扩展名白名单（png/jpg/gif/webp/bmp/svg/ico），非法链接渲染为占位文字
                    if (!isValidImageUrl(url)) {
                        return '<span class="md-img-error">[图片链接不合法]</span>';
                    }
                    // 添加安全属性
                    let attrs = ' src="' + escapeHtmlAttr(url) + '"';
                    attrs += ' alt="' + escapeHtmlAttr(String(text || '')) + '"';
                    if (title) attrs += ' title="' + escapeHtmlAttr(String(title)) + '"';
                    attrs += ' loading="lazy" referrerpolicy="no-referrer"';
                    return '<img' + attrs + '>';
                };
            }
            // 任务列表：不渲染原生 checkbox 输入框，改用符号 ☑/☐ 显示
            let origListitem = renderer.listitem ? renderer.listitem.bind(renderer) : null;
            if (origListitem) {
                renderer.listitem = function (text, task, checked) {
                    if (task) {
                        let mark = checked ? '☑ ' : '☐ ';
                        // marked 的 text 参数已包含 checkbox 标签，移除它改用符号
                        text = String(text).replace(/<input[^>]*>/g, '');
                        return '<li>' + mark + text + '</li>';
                    }
                    return origListitem(text);
                };
            }
            if (origLink) {
                renderer.link = function (href, title, text) {
                    let html = origLink(href, title, text);
                    let t = String(text || '').trim();
                    if (t.charAt(0) === '!') {
                        let u = String(href || '');
                        try { u = decodeURIComponent(u); } catch (e) { }
                        // 新标准：剥离类型前缀（btn: 等，http/https 除外）
                        let typeM = u.match(/^([a-z][a-z0-9.]*):(.*)$/s);
                        if (typeM && !/^https?:\/\//i.test(u)) {
                            u = typeM[2];
                        }
                        // 统一用 splitBtnParams 解析（新标准 键=值 + 兼容旧 :: ;; @@ ## ^^）
                        let sp = splitBtnParams(u);
                        let cleanHref = sp.content;
                        let fg = sp.fg, bg = sp.bg, perm = sp.perm, click = sp.click, snd = sp.sound, anm = sp.anim;
                        let permInfo = applyBtnPermission({ content: cleanHref, perm: perm }, myNickname);
                        let styleAttr = buildColorStyle(fg, bg);
                        let gCls = bg === '-1' ? ' md-btn-ghost' : '';
                        let dCls = permInfo.allowed ? '' : ' md-btn-disabled';
                        let dAttr = permInfo.allowed ? '' : ' data-disabled="1"';
                        let sndAttr = snd ? ' data-sound="' + escapeHtmlAttr(snd) + '"' : '';
                        let anmAttr = anm ? ' data-anim="' + escapeHtmlAttr(anm) + '"' : '';
                        let clickAttr = click ? ' data-click="' + escapeHtmlAttr(JSON.stringify(parseClickLimit(click))) + '"' : '';
                        return '<a class="md-btn' + gCls + dCls + '" href="' + escapeHtmlAttr(permInfo.content) + '" target="_blank" rel="noopener noreferrer"' + styleAttr + dAttr + sndAttr + anmAttr + clickAttr + '>' + t.slice(1) + '</a>';
                    }
                    return html;
                };
            }
            // 去掉 marked 输出的首尾换行（<p>xxx</p>\n 尾部换行会在气泡内多出一行）
            rawHtml = window.marked.parse(text, { renderer: renderer, breaks: true, gfm: true }).replace(/\s+$/, '');
        } else {
            // 降级：无 marked 时仅转换行
            rawHtml = text.replace(/\n/g, '<br>');
        }
        // XSS 消毒：style 仅允许颜色相关属性；图片仅在弹窗内（allowImg）放行
        if (window.DOMPurify) {
            let sanitizeCfg = {
                FORBID_TAGS: allowImg ? [] : ['img'],
                ALLOWED_ATTR: ['class', 'href', 'target', 'rel', 'data-copy', 'data-send', 'data-embed', 'data-modal-title', 'data-modal-content', 'style'],
                ALLOWED_CSS_PROPERTIES: ['color', 'background-color']
            };
            if (allowImg) {
                sanitizeCfg.ALLOWED_ATTR.push('src', 'alt', 'loading', 'referrerpolicy');
            }
            rawHtml = window.DOMPurify.sanitize(rawHtml, sanitizeCfg);
        }
        // 恢复动作按钮占位符
        for (let bi2 = 0; bi2 < actionBtns.length; bi2++) {
            let ab = actionBtns[bi2];
            let abParams = splitBtnParams(ab.raw);
            let abContent = abParams.content;
            let abPerm = applyBtnPermission(abParams, myNickname);
            let abStyle = buildColorStyle(abParams.fg, abParams.bg);
            let ghostClass = abParams.bg === '-1' ? ' md-btn-ghost' : '';
            let disClass = abPerm.allowed ? '' : ' md-btn-disabled';
            let disAttr = abPerm.allowed ? '' : ' data-disabled="1"';
            let soundAttr = abParams.sound ? ' data-sound="' + escapeHtmlAttr(abParams.sound) + '"' : '';
            let animAttr = abParams.anim ? ' data-anim="' + escapeHtmlAttr(abParams.anim) + '"' : '';
            let clickAttr = abParams.click ? ' data-click="' + escapeHtmlAttr(JSON.stringify(parseClickLimit(abParams.click))) + '"' : '';
            let btnHtml = '';
            if (ab.type === 'modal:') {
                let mpFull = parseNewMdParams(ab.raw); // 取 t 参数（自定义标题）
                let modalRaw2 = abPerm.content; // 映射后的完整 modal 内容（标题|内容）
                let sepIdx = modalRaw2.indexOf('|');
                let mTitle = mpFull.t || (sepIdx >= 0 ? modalRaw2.slice(0, sepIdx) : '提示');
                let mContent = sepIdx >= 0 ? modalRaw2.slice(sepIdx + 1) : modalRaw2;
                btnHtml = '<a class="md-btn md-btn-modal' + ghostClass + disClass + '" href="#" data-modal-title="' +
                    escapeHtmlAttr(encodeURIComponent(mTitle)) + '" data-modal-content="' +
                    escapeHtmlAttr(encodeURIComponent(mContent)) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'copy:') {
                btnHtml = '<a class="md-btn md-btn-copy' + ghostClass + disClass + '" href="#" data-copy="' + escapeHtmlAttr(abPerm.content) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'send:') {
                btnHtml = '<a class="md-btn md-btn-send' + ghostClass + disClass + '" href="#" data-send="' + escapeHtmlAttr(abPerm.content) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'embed:') {
                btnHtml = '<a class="md-btn md-btn-embed' + ghostClass + disClass + '" href="#" data-embed="' + escapeHtmlAttr(abPerm.content) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'confirm:') {
                // confirm:确认提示语|执行动作（动作可为 send:/copy:/https://embed:/modal:）
                let cSep = abPerm.content.indexOf('|');
                let cMsg = cSep >= 0 ? abPerm.content.slice(0, cSep) : '确定执行吗？';
                let cAct = cSep >= 0 ? abPerm.content.slice(cSep + 1) : '';
                btnHtml = '<a class="md-btn md-btn-confirm' + ghostClass + disClass + '" href="#" data-confirm-msg="' +
                    escapeHtmlAttr(encodeURIComponent(cMsg)) + '" data-confirm-action="' +
                    escapeHtmlAttr(encodeURIComponent(cAct)) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'details:') {
                // details:折叠标题|折叠内容（点击展开/收起）
                let dSep = abPerm.content.indexOf('|');
                let dTitle = dSep >= 0 ? abPerm.content.slice(0, dSep) : '详情';
                let dContent = dSep >= 0 ? abPerm.content.slice(dSep + 1) : abPerm.content;
                btnHtml = '<a class="md-btn md-btn-details' + ghostClass + disClass + '" href="#" data-details-title="' +
                    escapeHtmlAttr(encodeURIComponent(dTitle)) + '" data-details-content="' +
                    escapeHtmlAttr(encodeURIComponent(dContent)) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'rand:') {
                // 随机：rand:值1|值2|...（默认随机发送；mode=modal 随机弹窗）
                let rp = parseNewMdParams(ab.raw);
                let randMode = rp.mode === 'modal' ? 'modal' : 'send';
                let randTitle = rp.t || '';
                btnHtml = '<a class="md-btn md-btn-rand' + ghostClass + disClass + '" href="#" data-rand="' +
                    escapeHtmlAttr(encodeURIComponent(abPerm.content)) + '" data-rand-mode="' + randMode + '"' +
                    (randTitle ? ' data-rand-title="' + escapeHtmlAttr(encodeURIComponent(randTitle)) + '"' : '') +
                    abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'input:') {
                // 输入框：input:占位符|id:foo|ok:答案|colorof:col|onchange:操作
                let ip = parseNewMdParams(ab.raw);
                let inputId = ip.id || ('inp' + bi2);
                let okVal = ip.ok || '';
                let colorofAttr = ip.colorof ? ' data-colorof="' + escapeHtmlAttr(ip.colorof) + '"' : '';
                let ipOn = ip.on;
                let onchangeAttr = ipOn ? ' data-onchange="' + escapeHtmlAttr(encodeURIComponent(ipOn)) + '"' : '';
                btnHtml = '<span class="md-input-box" data-ui-id="' + escapeHtmlAttr(inputId) + '"' + colorofAttr + abStyle + '>' +
                    (ab.label ? '<span class="md-input-label">' + escapeHtml(ab.label) + '</span>' : '') +
                    '<input class="md-input" type="text" data-input-id="' + escapeHtmlAttr(inputId) + '" data-ok="' + escapeHtmlAttr(okVal) + '" placeholder="' + escapeHtmlAttr(ip.value) + '"' + onchangeAttr + '>' +
                    '</span>';
            } else if (ab.type === 'get:') {
                // 获取内容：get:foo|colorof:col（显示当前消息内输入框 foo 的内容）
                let gp = parseNewMdParams(ab.raw);
                let gid = gp.value.trim();
                let colorofAttr2 = gp.colorof ? ' data-colorof="' + escapeHtmlAttr(gp.colorof) + '"' : '';
                btnHtml = '<span class="md-get" data-get-id="' + escapeHtmlAttr(gid) + '"' + colorofAttr2 + abStyle + '></span>';
            } else if (ab.type === 'ok:') {
                // 确认按钮：ok:输入框id|right=动作|wrong=动作|lock=组（倒计时锁定组）
                let ob = parseNewMdParams(ab.raw);
                let bindId = ob.value.trim();
                let right = ob.right || '';
                let wrong = ob.wrong || '';
                let obLock = ob.lock ? ' data-timer-lock-group="' + escapeHtmlAttr(ob.lock) + '"' : '';
                btnHtml = '<a class="md-btn md-btn-ok' + ghostClass + disClass + '" href="#" data-ok="' + escapeHtmlAttr(bindId) + '" data-right="' + escapeHtmlAttr(encodeURIComponent(right)) + '" data-wrong="' + escapeHtmlAttr(encodeURIComponent(wrong)) + '"' + obLock + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'cancel:') {
                // 取消按钮：cancel:动作
                let cb = abPerm.content.trim();
                btnHtml = '<a class="md-btn md-btn-cancel' + ghostClass + disClass + '" href="#" data-cancel="' + escapeHtmlAttr(encodeURIComponent(cb)) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'close:') {
                // 关闭按钮：close:动作
                let clb = abPerm.content.trim();
                btnHtml = '<a class="md-btn md-btn-close' + ghostClass + disClass + '" href="#" data-close="' + escapeHtmlAttr(encodeURIComponent(clb)) + '"' + abStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'switch:') {
                // 可改变内容：switch:值1|值2|...|id:foo|c:1|cc:颜色1/颜色2/...|onchange:操作|lock:组
                let sw = parseSwitchParams(ab.raw);
                let swId = sw.id || ('sw' + bi2);
                let swVals = sw.values.length ? sw.values : [ab.label];
                let swStyle = abStyle;
                let swColorAttr = '';
                if (sw.color) {
                    let initColor = sw.colors.length ? sw.colors[0] : swVals[0];
                    if (sw.colors.length > 0) {
                        swColorAttr = ' data-switch-colors="' + escapeHtmlAttr(JSON.stringify(sw.colors)) + '"';
                    }
                    if (/^#?[0-9a-fA-F]{3,8}$/.test(String(initColor).trim())) {
                        let c0 = String(initColor).trim();
                        if (c0.charAt(0) !== '#') c0 = '#' + c0;
                        swStyle = ' style="background-color:' + c0 + '"';
                    }
                }
                let sw2 = parseNewMdParams(ab.raw);
                let swOn = sw2.on;
                let swOnchange = swOn ? ' data-onchange="' + escapeHtmlAttr(encodeURIComponent(swOn)) + '"' : '';
                // lock 参数：parseSwitchParams 不解析 lock，统一从 parseNewMdParams 取（兼容两种解析结果）
                let swLockVal = sw2.lock !== undefined ? sw2.lock : sw.lock;
                let swLock = swLockVal ? ' data-timer-lock-group="' + escapeHtmlAttr(swLockVal) + '"' : '';
                btnHtml = '<a class="md-btn md-btn-switch' + ghostClass + disClass + '" href="#" data-ui-id="' + escapeHtmlAttr(swId) + '" data-switch-id="' + escapeHtmlAttr(swId) + '" data-switch-vals="' + escapeHtmlAttr(JSON.stringify(swVals)) + '"' + (sw.color ? ' data-switch-color="1"' : '') + swColorAttr + swOnchange + swLock + swStyle + disAttr + soundAttr + animAttr + clickAttr + '>' + escapeHtml(swVals[0]) + '</a>';
            } else if (ab.type === 'var:') {
                // 全局变量：var:foo|init:初始值
                let vp = parseNewMdParams(ab.raw);
                let varId = vp.value.trim();
                let varInit = vp.init !== undefined ? vp.init : '';
                btnHtml = '<span class="md-var" data-var-id="' + escapeHtmlAttr(varId) + '">' + escapeHtml(varInit) + '</span>';
            } else if (ab.type === 'def:') {
                // 定义值（隐藏）：def:a|内容（注册到全局变量，不显示，其他处用 %a% 或 {a} 引用）
                let dp = parseNewMdParams(ab.raw);
                let defName = dp.value.trim();
                let defVal = dp.init !== undefined ? dp.init : '';
                btnHtml = '<span class="md-def" data-def-name="' + escapeHtmlAttr(defName) + '" data-def-value="' + escapeHtmlAttr(defVal) + '" style="display:none"></span>';
            } else if (ab.type === 'cipher:') {
                // 加密内容：cipher:明文|key:密钥（渲染时加密，点击解密弹窗）
                let cpp = parseNewMdParams(ab.raw);
                let cipherKey = cpp.key || 'md';
                let enc = mdEncrypt(cpp.value, cipherKey);
                btnHtml = '<a class="md-btn md-btn-cipher' + ghostClass + disClass + '" href="#" data-cipher="' + escapeHtmlAttr(enc) + '" data-cipher-key="' + escapeHtmlAttr(cipherKey) + '"' + abStyle + disAttr + clickAttr + '>' + ab.label + '</a>';
            } else if (ab.type === 'table:') {
                // 自定义表格：table:col:N|表头...|数据...
                let tbl = parseTableParams(ab.raw);
                let tcols = Math.max(1, tbl.cols);
                let tHtml = '<table class="md-table"><thead><tr>';
                for (let ti = 0; ti < Math.min(tcols, tbl.cells.length); ti++) {
                    tHtml += '<th>' + escapeHtml(tbl.cells[ti]) + '</th>';
                }
                tHtml += '</tr></thead><tbody>';
                let tBody = tbl.cells.slice(tcols);
                for (let ti = 0; ti < tBody.length; ti += tcols) {
                    tHtml += '<tr>';
                    for (let tj = 0; tj < tcols; tj++) {
                        tHtml += '<td>' + escapeHtml(tBody[ti + tj] || '') + '</td>';
                    }
                    tHtml += '</tr>';
                }
                tHtml += '</tbody></table>';
                btnHtml = tHtml;
            } else if (ab.type === 'music:') {
                // 音乐播放器：music:音频URL|t:标题（URL 审核同音效，复用 isValidAudioUrl）
                let mp = parseNewMdParams(ab.raw);
                let mUrl = mp.value.trim();
                let mTitle = mp.t || '';
                if (!isValidAudioUrl(mUrl)) {
                    btnHtml = '<span class="md-img-error">[音频链接不合法]</span>';
                } else {
                    btnHtml = '<span class="md-music">' +
                        (mTitle ? '<span class="md-music-title">' + escapeHtml(mTitle) + '</span>' : '') +
                        '<audio controls preload="none" src="' + escapeHtmlAttr(mUrl) + '"></audio>' +
                        '</span>';
                }
            } else if (ab.type === 'timer:') {
                // 倒计时：timer:秒数|id:foo|end:操作|lock:组|bar:进度条id
                let tp = parseNewMdParams(ab.raw);
                let timerId = tp.id || ('tmr' + bi2);
                let timerTotal = parseInt(tp.value, 10) || 30;
                let timerEnd = tp.end || '';
                let timerLock = tp.lock || '';
                let timerBar = tp.bar || '';
                btnHtml = '<span class="md-timer" data-timer-id="' + escapeHtmlAttr(timerId) + '" data-timer-total="' + timerTotal + '"' +
                    (timerEnd ? ' data-timer-end="' + escapeHtmlAttr(encodeURIComponent(timerEnd)) + '"' : '') +
                    (timerLock ? ' data-timer-lock="' + escapeHtmlAttr(timerLock) + '"' : '') +
                    (timerBar ? ' data-timer-bar="' + escapeHtmlAttr(timerBar) + '"' : '') +
                    '>' + timerTotal + '</span>';
            } else if (ab.type === 'bar:') {
                // 进度条：bar:当前值/最大值|id:foo
                let bp = parseNewMdParams(ab.raw);
                let barId = bp.id || ('bar' + bi2);
                let bm = String(bp.value).match(/^(\d+)\s*\/\s*(\d+)$/);
                let barVal = bm ? parseInt(bm[1], 10) : 0;
                let barMax = bm ? parseInt(bm[2], 10) : 100;
                btnHtml = '<span class="md-bar" data-bar-id="' + escapeHtmlAttr(barId) + '" data-bar-max="' + barMax + '" data-bar-init="' + barVal + '">' +
                    '<span class="md-bar-fill" style="width:' + (barMax > 0 ? (barVal / barMax) * 100 : 0) + '%"></span>' +
                    '<span class="md-bar-text">' + barVal + '/' + barMax + '</span>' +
                    '</span>';
            } else if (ab.type === 'if:') {
                // 条件显示：if:条件|then=显示内容（条件满足才显示）
                let sp = parseNewMdParams(ab.raw);
                let spContent = sp.then !== undefined ? sp.then : '';
                btnHtml = '<span class="md-if" data-if-cond="' + escapeHtmlAttr(sp.value.trim()) + '">' + escapeHtml(spContent) + '</span>';
            } else if (ab.type === 'hide:') {
                // 隐藏按钮：hide:动作（普通文本外观，无按钮样式，但点击执行动作）
                let hRaw = abPerm.content;
                let hType = '';
                ['send:', 'copy:', 'modal:', 'embed:', 'confirm:', 'details:', 'rand:', 'ok:', 'cancel:', 'close:', 'switch:'].forEach(function (t) {
                    if (hRaw.indexOf(t) === 0 && !hType) hType = t;
                });
                let hContent = hType ? hRaw.slice(hType.length) : hRaw;
                if (hType === 'send:') {
                    btnHtml = '<span class="md-hide" data-send="' + escapeHtmlAttr(hContent) + '">' + escapeHtml(ab.label) + '</span>';
                } else if (hType === 'copy:') {
                    btnHtml = '<span class="md-hide" data-copy="' + escapeHtmlAttr(hContent) + '">' + escapeHtml(ab.label) + '</span>';
                } else if (hType === 'switch:') {
                    let hs = parseSwitchParams(hContent);
                    let hsId = hs.id || ('hs' + bi2);
                    let hsVals = hs.values.length ? hs.values : [ab.label];
                    btnHtml = '<span class="md-hide md-hide-switch" data-switch-id="' + escapeHtmlAttr(hsId) + '" data-switch-vals="' + escapeHtmlAttr(JSON.stringify(hsVals)) + '">' + escapeHtml(hsVals[0]) + '</span>';
                } else {
                    btnHtml = '<span class="md-hide">' + escapeHtml(ab.label) + '</span>';
                }
            } else if (ab.type === 'text:') {
                // 文本框：text:内容|t=标题|color=..|color.bg=..|align=..|size=..
                let tp = parseNewMdParams(ab.raw);
                let txt = tp.value;
                let tTitle = tp.t || '';
                let tAlign = tp.align || 'left';
                let tSize = tp.size || 'md';
                let tStyle = tp.style || 'note';
                let tColor = normColor(tp.color || '');
                let tBg = tp['color.bg'] === '-1' ? '' : normColor(tp['color.bg'] || '');
                // 合并样式（避免重复 style 属性被浏览器忽略）
                let tStyleParts = ['text-align:' + tAlign];
                if (tColor) tStyleParts.push('color:' + tColor);
                if (tBg) tStyleParts.push('background-color:' + tBg);
                let tStyleAttr = ' style="' + tStyleParts.join(';') + '"';
                btnHtml = '<div class="md-textbox md-textbox-' + tSize + ' md-textbox-' + tStyle + '"' + tStyleAttr + '>' +
                    (tTitle ? '<div class="md-textbox-title">' + escapeHtml(tTitle) + '</div>' : '') +
                    '<div class="md-textbox-body">' + escapeHtml(txt) + '</div>' +
                    '</div>';
            } else if (ab.type === 'board:') {
                // 画板：board:大小|shapes=图形|text=文本|bg=背景|id=xxx|modal=1|hide=1
                let bp2 = parseNewMdParams(ab.raw);
                let bSize = Math.max(1, Math.min(20, parseInt(bp2.value, 10) || 20));
                let bShapes = bp2.shapes || '';
                let bText = bp2.text || '';
                let bBg = bp2.bg || '';
                let bId = bp2.id || ('board' + bi2);
                let bModal = bp2.modal === '1';
                let bHide = bp2.hide === '1';
                let bGrid = bp2.grid === '0' ? '0' : '1';
                // 文本自定义：tx/ty=位置 ts=字号 tc=颜色（省略则居中/默认字号/黑色，支持 %值% 引用）
                let bTx = bp2.tx !== undefined ? String(bp2.tx).trim() : '';
                let bTy = bp2.ty !== undefined ? String(bp2.ty).trim() : '';
                let bTs = bp2.ts !== undefined ? String(bp2.ts).trim() : '';
                let bTc = bp2.tc !== undefined ? String(bp2.tc).trim() : '';
                let boardHtml = '<span class="md-board" data-board-id="' + escapeHtmlAttr(bId) + '" data-board-size="' + bSize + '" data-board-shapes="' + escapeHtmlAttr(bShapes) + '" data-board-text="' + escapeHtmlAttr(bText) + '" data-board-bg="' + escapeHtmlAttr(bBg) + '" data-board-grid="' + bGrid + '"' +
                    (bTx !== '' ? ' data-board-tx="' + escapeHtmlAttr(bTx) + '"' : '') +
                    (bTy !== '' ? ' data-board-ty="' + escapeHtmlAttr(bTy) + '"' : '') +
                    (bTs !== '' ? ' data-board-ts="' + escapeHtmlAttr(bTs) + '"' : '') +
                    (bTc !== '' ? ' data-board-tc="' + escapeHtmlAttr(bTc) + '"' : '') +
                    (bHide ? ' style="display:none"' : '') + '></span>';
                if (bModal) {
                    btnHtml = '<a class="md-btn md-btn-board' + ghostClass + disClass + '" href="#" data-board-modal="' + escapeHtmlAttr(bId) + '"' + abStyle + disAttr + '>' + ab.label + '</a>' + boardHtml;
                } else {
                    btnHtml = boardHtml;
                }
            } else if (ab.type === 'vote:') {
                // 投票：vote:问题|选项1|选项2|...|id=v1|max=1|mode=bar（本地消息级计数，localStorage 防重复）
                let vRaw = splitTopLevelByPipe(ab.raw);
                let vId = 'v' + bi2;
                let vQuestion = (vRaw[0] || '').trim();
                let vOpts = [];
                let vMax = 1, vMode = 'bar';
                for (let vi = 1; vi < vRaw.length; vi++) {
                    let seg = vRaw[vi];
                    let veq = seg.indexOf('=');
                    if (veq > 0 && /^[a-z][a-z0-9.]*$/.test(seg.slice(0, veq).trim())) {
                        let vk = seg.slice(0, veq).trim(), vv = seg.slice(veq + 1);
                        if (vk === 'id') vId = vv;
                        else if (vk === 'max') vMax = parseInt(vv, 10) || 1;
                        else if (vk === 'mode') vMode = vv;
                    } else {
                        vOpts.push(seg);
                    }
                }
                if (!vOpts.length) vOpts = [ab.label];
                let voteHtml = '<div class="md-vote" data-vote-id="' + escapeHtmlAttr(vId) + '" data-vote-max="' + vMax + '" data-vote-mode="' + escapeHtmlAttr(vMode) + '" data-vote-opts="' + escapeHtmlAttr(JSON.stringify(vOpts)) + '">' +
                    (vQuestion ? '<div class="md-vote-q">' + escapeHtml(vQuestion) + '</div>' : '') +
                    '<div class="md-vote-opts">';
                for (let vo = 0; vo < vOpts.length; vo++) {
                    voteHtml += '<div class="md-vote-opt" data-vote-opt="' + vo + '" data-vote-picked="0">' +
                        '<span class="md-vote-opt-name">' + escapeHtml(vOpts[vo]) + '</span>' +
                        '<span class="md-vote-bar"><i style="width:0%"></i></span>' +
                        '<span class="md-vote-num">0 票</span>' +
                        '</div>';
                }
                voteHtml += '</div><div class="md-vote-foot">' + (vMax > 1 ? '最多选 ' + vMax + ' 项' : '单选') + '</div></div>';
                btnHtml = voteHtml;
            } else if (ab.type === 'dice:') {
                // 骰子：dice:2d6+3|id=d1（支持 XdY±Z / dY / 纯数字；结果存入 %id% 变量）
                let dp = parseNewMdParams(ab.raw);
                let dExpr = (dp.value || '').trim();
                let dId = dp.id || '';
                if (!/^(\d*)d(\d+)([+-]\d+)?$/i.test(dExpr)) dExpr = '1d6';
                btnHtml = '<span class="md-dice">' +
                    '<a class="md-btn md-btn-dice' + ghostClass + disClass + '" href="#" data-dice="' + escapeHtmlAttr(dExpr) + '"' + (dId ? ' data-dice-id="' + escapeHtmlAttr(dId) + '"' : '') + abStyle + disAttr + soundAttr + animAttr + '>' + (ab.label || ('🎲 ' + dExpr)) + '</a>' +
                    '<span class="md-dice-result"></span></span>';
            } else if (ab.type === 'at:') {
                // 定时到点：at:HH:MM[:SS]|end=动作|id=t1|repeat=1（到点执行动作，repeat=1 每天重复）
                let ap = parseNewMdParams(ab.raw);
                let aTime = (ap.value || '').trim();
                let aId = ap.id || ('at' + bi2);
                let aEnd = ap.end || '';
                let aRepeat = ap.repeat === '1';
                if (!/^\d{1,2}:\d{2}(:\d{2})?$/.test(aTime)) aTime = '00:00';
                btnHtml = '<span class="md-at" data-at-time="' + escapeHtmlAttr(aTime) + '" data-at-id="' + escapeHtmlAttr(aId) + '"' +
                    (aEnd ? ' data-at-end="' + escapeHtmlAttr(encodeURIComponent(aEnd)) + '"' : '') +
                    (aRepeat ? ' data-at-repeat="1"' : '') +
                    '>⏰ 定时 ' + escapeHtml(aTime) + '</span>';
            } else if (ab.type === 'gallery:') {
                // 图集轮播：gallery:标题|url1|url2|...|autoplay=3（图片过白名单，弹窗轮播）
                let gRaw = splitTopLevelByPipe(ab.raw);
                let gTitle = (gRaw[0] || '').trim();
                let gImgs = [];
                let gAutoplay = 0;
                for (let gi = 1; gi < gRaw.length; gi++) {
                    let seg = gRaw[gi];
                    let geq = seg.indexOf('=');
                    if (geq > 0 && /^[a-z][a-z0-9.]*$/.test(seg.slice(0, geq).trim())) {
                        let gk = seg.slice(0, geq).trim(), gv = seg.slice(geq + 1);
                        if (gk === 'autoplay') gAutoplay = parseInt(gv, 10) || 0;
                    } else if (isValidImageUrl(seg.trim())) {
                        gImgs.push(seg.trim());
                    }
                }
                let gBtn = '<a class="md-btn md-btn-gallery' + ghostClass + disClass + '" href="#" data-gallery="' + escapeHtmlAttr(JSON.stringify(gImgs)) + '"' +
                    (gTitle ? ' data-gallery-title="' + escapeHtmlAttr(encodeURIComponent(gTitle)) + '"' : '') +
                    (gAutoplay ? ' data-gallery-autoplay="' + gAutoplay + '"' : '') +
                    abStyle + disAttr + '>' + (ab.label || '📸 查看图集') + '</a>';
                btnHtml = gImgs.length ? gBtn : '<span class="md-img-error">[图集链接不合法]</span>';
            }
            rawHtml = rawHtml.replace('[[MDBTNACT' + bi2 + ']]', btnHtml);
        }
        return rawHtml;
    }

    /**
     * 保护 Markdown 代码块（fenced code + 内联代码）内容，替换为占位符，
     * 避免自定义语法（B站链接、动作按钮等）在代码块内被误解析。
     * 返回 { text, parts }，恢复时按索引把 [[RAWCODEi]] 换回 parts[i]。
     */
    function protectMarkdownCode(text) {
        const parts = [];
        let out = '';
        let i = 0;
        const len = text.length;

        while (i < len) {
            // 1) fenced code：行首（允许 0-3 空格）``` 或 ~~~
            let nl = text.indexOf('\n', i);
            let lineEnd = nl === -1 ? len : nl;
            let line = text.slice(i, lineEnd);
            let fenceM = /^ {0,3}(`{3,}|~{3,})[^\n]*$/.exec(line);
            if (fenceM) {
                let fenceChar = fenceM[1].charAt(0);
                let fenceLen = fenceM[1].length;
                let closeRe = new RegExp('^ {0,3}' + fenceChar + '{' + fenceLen + ',}[ \\t]*$');
                let searchStart = nl === -1 ? len : nl + 1;
                let blockEnd = -1;
                while (searchStart <= len) {
                    let cnl = text.indexOf('\n', searchStart);
                    let cEnd = cnl === -1 ? len : cnl;
                    let cLine = text.slice(searchStart, cEnd);
                    if (closeRe.test(cLine)) {
                        blockEnd = cnl === -1 ? len : cnl + 1;
                        break;
                    }
                    if (cnl === -1) break;
                    searchStart = cnl + 1;
                }
                if (blockEnd !== -1) {
                    let raw = text.slice(i, blockEnd);
                    let ph = '[[RAWCODE' + parts.length + ']]';
                    parts.push(raw);
                    out += ph;
                    i = blockEnd;
                    continue;
                }
                // 未闭合的 fence：按普通文本行处理
                out += line;
                i = (nl === -1 ? len : nl + 1);
                continue;
            }

            // 2) inline code：反引号（不跨行）
            if (text[i] === '`') {
                let run = 0;
                while (i + run < len && text[i + run] === '`') run++;
                let nextNl = text.indexOf('\n', i + run);
                let searchEnd = nextNl === -1 ? len : nextNl;
                let close = text.indexOf('`'.repeat(run), i + run);
                if (close !== -1 && close < searchEnd) {
                    let raw = text.slice(i, close + run);
                    let ph = '[[RAWCODE' + parts.length + ']]';
                    parts.push(raw);
                    out += ph;
                    i = close + run;
                    continue;
                }
            }

            out += text[i];
            i++;
        }

        return { text: out, parts: parts };
    }

    /**
     * 解析已转义文本中的 B站/抖音视频链接，替换为占位元素
     * 必须在 escapeHtml 之后、autoLink 之前调用。
     * 异步解析由 resolveBilibiliEmbeds 完成（同一 API 支持多平台）。
     */
    function parseBilibiliLinks(text) {
        let regex = /https?:\/\/(?:www\.)?bilibili\.com\/video\/[^\s<>"'，。！？、；：》\)\]]+|https?:\/\/b23\.tv\/[^\s<>"'，。！？、；：》\)\]]+|https?:\/\/v\.douyin\.com\/[^\s<>"'，。！？、；：》\)\]]+|BV[0-9A-Za-z]{10}/gi;
        return text.replace(regex, function (match) {
            // 剥离 GET 参数
            let cleanUrl = match.replace(/\?.*$/, '');
            // 纯 BV 号 → 补全为 B 站视频链接
            if (/^BV[0-9A-Za-z]{10}$/i.test(cleanUrl)) {
                cleanUrl = 'https://www.bilibili.com/video/' + cleanUrl;
            }
            return '<div class="bili-embed" data-bili-url="' + encodeURIComponent(cleanUrl) + '">' +
                '<div class="bili-loading">' + BILI_SPINNER_SVG + '解析中...</div>' +
                '</div>';
        });
    }

    /**
     * 对容器内所有 B站占位元素发起 API 解析请求，替换为播放器
     */
    function resolveBilibiliEmbeds(container) {
        let placeholders = container.querySelectorAll('.bili-embed[data-bili-url]');
        for (let i = 0; i < placeholders.length; i++) {
            biliObserver.observe(placeholders[i]);
        }
    }

    let biliObserver = new IntersectionObserver(function (entries) {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            let el = entry.target;
            biliObserver.unobserve(el);
            let url = decodeURIComponent(el.getAttribute('data-bili-url'));
            if (!url) return;
            let apiUrl = 'https://api.xiaofengqwq.com/api/v1/tools/video-parse?url=' + encodeURIComponent(url);
            fetchBiliWithRetry(el, apiUrl, 0, function (json) {
                if (json && json.code === 200 && json.data && json.data.video_url) {
                    let data = json.data;
                    let videoUrl = data.video_url;
                    let title = data.title || '';
                    let cover = data.cover || '';
                    el.innerHTML =
                        '<video class="bili-video" src="' + videoUrl + '" controls></video>' +
                        '<div class="bili-title"><a href="' + url + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(title) + '</a></div>';
                    if (cover) {
                        let vid = el.querySelector('.bili-video');
                        vid.setAttribute('poster', cover);
                    }
                    try { new Plyr(el.querySelector('.bili-video'), { controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'fullscreen'] }); } catch (e) { }
                } else {
                    el.innerHTML =
                        '<div class="bili-error">⚠ 视频解析失败</div>' +
                        '<div class="bili-title"><a href="' + escapeHtmlAttr(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(url) + '</a></div>';
                }
            });
        });
    }, { rootMargin: '200px' });

    function fetchBiliWithRetry(el, apiUrl, attempt, onDone) {
        fetch(apiUrl)
            .then((res) => { return res.json(); })
            .then((json) => { onDone(json); })
            .catch(() => {
                if (attempt < 2) {
                    let loading = el.querySelector('.bili-loading');
                    if (loading) loading.innerHTML = BILI_SPINNER_SVG + '解析中...(' + (attempt + 2) + '/3)';
                    setTimeout(function () { fetchBiliWithRetry(el, apiUrl, attempt + 1, onDone); }, 1000);
                } else {
                    onDone(null);
                }
            });
    }

    /**
     * 自动检测已转义文本中的 URL 并转为可点击链接
     * 先 escape 再匹配，杜绝 XSS 风险。
     */
    function autoLink(text) {
        // 匹配 http/https URL，以及 www. 开头的域名
        return text.replace(
            /(https?:\/\/[^\s<>"'，。！？、；：》\)\]]+)|(?<!\w)www\.[^\s<>"'，。！？、；：》\)\]]+/gi,
            function (match) {
                let href = match;
                // www. 开头没有协议 → 补 https://
                if (/^www\./i.test(href)) {
                    href = 'https://' + href;
                }
                return '<a href="' + href + '" target="_blank" rel="noopener noreferrer" class="auto-link">' + match + '</a>';
            }
        );
    }

    function parseStickerId(text) {
        const m = text.match(/^\[sticker:([^\]]+)\]/);
        return m ? m[1] : null;
    }

    // ==================== 点歌系统 ====================

    function formatDuration(ms) {
        if (!ms || ms <= 0) return '--:--';
        let s = Math.floor(ms / 1000);
        let m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function updateConnStatusSong() {
        if (songPlaying && songListen) {
            $btnSong.classList.add('playing');
        } else {
            $btnSong.classList.remove('playing');
        }
    }

    function openSongInfo(e, song) {
        if (!$songInfo) return;
        // 填充内容
        $songInfoCover.src = song.picurl || '';
        $songInfoName.textContent = song.name || '';
        $songInfoArtist.textContent = song.artist || '';
        $songInfoAdder.textContent = song.adder ? '点歌人: ' + song.adder : '';
        // 计算进度（音频实际播放进度优先，与服务端时间推算一致）
        let elapsed = (songCurAudio && songCurAudio.src && !songCurAudio.paused &&
            isFinite(songCurAudio.currentTime) && songCurAudio.currentTime > 0)
            ? songCurAudio.currentTime
            : (Date.now() / 1000) - (song.start_time || 0);
        let total = (song.duration || 0) / 1000;
        let pct = total > 0 ? Math.min(100, Math.max(0, (elapsed / total) * 100)) : 0;
        $songInfoProgressBar.style.width = pct + '%';
        $songInfoTime.textContent = formatDuration(elapsed * 1000) + ' / ' + formatDuration(song.duration);
        // 下一首（循环队列）
        let nextText = '';
        if (songList.length > 0) {
            let curIdx = -1;
            for (let k = 0; k < songList.length; k++) {
                if (String(songList[k].id) === String(song.id)) { curIdx = k; break; }
            }
            let nextIdx = (curIdx >= 0) ? (curIdx + 1) % songList.length : 0;
            if (songList[nextIdx]) {
                nextText = '下一首: ' + songList[nextIdx].name + ' (' + songList[nextIdx].votes + '票)';
            }
        } else {
            nextText = '投票池为空';
        }
        $songInfoNext.textContent = nextText;
        // 定位
        let target = e.target;
        let rect = target.getBoundingClientRect();
        let left = rect.left;
        let top = rect.bottom + 6;
        // 防止超出右边界
        if (left + 280 > window.innerWidth - 16) {
            left = window.innerWidth - 296;
        }
        if (left < 12) left = 12;
        // 防止超出下边界
        if (top + 160 > window.innerHeight - 16) {
            top = rect.top - 166;
        }
        $songInfo.style.left = left + 'px';
        $songInfo.style.top = top + 'px';
        $songInfo.style.display = 'flex';
        // 启动实时进度更新
        startSongProgress(song);
    }

    function closeSongInfo() {
        if (!$songInfo) return;
        $songInfo.style.display = 'none';
        stopSongProgress();
    }

    function startSongProgress(song) {
        stopSongProgress();
        songProgressTimer = setInterval(function () {
            if (!songPlaying || songPlaying.id !== song.id) {
                stopSongProgress();
                return;
            }
            // 歌词进度以本地音频实际播放位置为准（currentTime），
            // 彻底消除缓冲延迟/时钟偏差导致的歌词与音乐错位
            let elapsed;
            if (songCurAudio && songCurAudio.src && !songCurAudio.paused &&
                isFinite(songCurAudio.currentTime) && songCurAudio.currentTime > 0) {
                elapsed = songCurAudio.currentTime;
            } else {
                // 音频未播放（未开听歌/暂停/缓冲中）时退回服务端时间推算
                elapsed = (Date.now() / 1000) - song.start_time;
            }
            let total = (song.duration || 0) / 1000;

            // 提前 60 秒预加载下一首（音频+歌词），实现无缝衔接
            if (songListen && total > 0 && (total - elapsed) <= 60 && (total - elapsed) >= 0) {
                preloadNextSong();
            }

            // 歌曲播放完毕：停止本地播放并通知服务端立即切歌广播（全员同步下一首）
            if (total > 0 && elapsed >= total) {
                stopSongProgress();
                if (songCurAudio) { try { songCurAudio.pause(); } catch (e) { } }
                send({ type: 'lobby_song_finished' });
                return;
            }

            let pct = total > 0 ? Math.min(100, Math.max(0, (elapsed / total) * 100)) : 0;
            let timeText = formatDuration(elapsed * 1000) + ' / ' + formatDuration(song.duration);
            let panelVisible = $songPlayingInfo && $songPlayingInfo.style.display !== 'none';
            let tipVisible = $songInfo && $songInfo.style.display !== 'none';
            // 仅在没有面板打开时暂停 UI 更新，但继续运行定时器以检测歌曲结束
            if (!panelVisible && !tipVisible) { return; }
            if (tipVisible) {
                $songInfoProgressBar.style.width = pct + '%';
                $songInfoTime.textContent = timeText;
            }
            if (panelVisible) {
                let fill = $songPlayingInfo.querySelector('.spi-progress-fill');
                let time = $songPlayingInfo.querySelector('.spi-time');
                if (fill) fill.style.width = pct + '%';
                if (time) time.textContent = timeText;
            }
            // 更新歌词
            updateLyrics(elapsed);
        }, 500);
    }

    function stopSongProgress() {
        if (songProgressTimer) {
            clearInterval(songProgressTimer);
            songProgressTimer = null;
        }
    }

    function tryUnlockAudio() {
        if (audioUnlocked) return;
        audioUnlocked = true;
        let ctx = new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        if (songCurAudio && songCurAudio.src && songCurAudio.paused && songListen) {
            if (songPlaying && songPlaying.start_time && songPlaying.duration) {
                let elapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                let durSec = songPlaying.duration / 1000;
                if (elapsed > 1 && elapsed < durSec &&
                    Math.abs(songCurAudio.currentTime - elapsed) > 2) {
                    songCurAudio.currentTime = elapsed;
                }
            }
            songCurAudio.play().catch(() => { });
        }
    }

    // 启动歌曲同步检查定时器（每10秒检查一次，漂移>10秒才同步，减轻服务器压力）
    function startSongSyncTimer() {
        stopSongSyncTimer();
        songSyncTimer = setInterval(function () {
            if (!songPlaying || !songPlaying.duration) return;
            let totalSec = songPlaying.duration / 1000;
            let serverElapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
            // 歌曲已结束 → 同步下一首
            if (totalSec > 0 && serverElapsed >= totalSec) {
                send({ type: 'lobby_song_finished' });
                return;
            }
            // 本地有音频播放时用实际播放位置比对
            if (songCurAudio && songCurAudio.src && !songCurAudio.paused &&
                isFinite(songCurAudio.currentTime) && songCurAudio.currentTime > 0) {
                let drift = Math.abs(songCurAudio.currentTime - serverElapsed);
                if (drift > 10) {
                    send({ type: 'lobby_song_current' });
                }
                return;
            }
            // 无本地播放时：超过 30 秒未收到服务器广播则请求同步
            if (lastSongServerTime > 0 && (Date.now() - lastSongServerTime) > 30000) {
                send({ type: 'lobby_song_current' });
            }
        }, 10000);
    }

    function stopSongSyncTimer() {
        if (songSyncTimer) {
            clearInterval(songSyncTimer);
            songSyncTimer = null;
        }
    }

    // 首次用户手势时解锁音频
    ['click', 'touchstart', 'keydown'].forEach((evt) => {
        document.addEventListener(evt, tryUnlockAudio, { once: true });
    });

    // 从播放队列中查找下一首（循环队列：当前歌在队尾时回到队首）
    function getNextSong() {
        if (!songPlaying || !songList || songList.length === 0) return null;
        let curIdx = -1;
        for (let k = 0; k < songList.length; k++) {
            if (String(songList[k].id) === String(songPlaying.id)) { curIdx = k; break; }
        }
        if (curIdx === -1) return songList[0] || null;
        let nextIdx = (curIdx + 1) % songList.length;
        return songList[nextIdx] || null;
    }

    // 预加载下一首：提前把音频与歌词加载好，实现无缝衔接（幂等，已预加载同一首则跳过）
    function preloadNextSong() {
        if (!songListen || !songPlaying) return;
        let next = getNextSong();
        if (!next || !next.url) return;
        if (preloadedSongId === String(next.id)) return;
        // 用空闲的 Audio 实例预加载（不干扰当前播放）
        let idleAudio = (songCurAudio === songAudioA) ? songAudioB : songAudioA;
        idleAudio.src = next.url;
        idleAudio.preload = 'auto';
        idleAudio.load();
        preloadedSongId = String(next.id);
        // 同步预加载歌词
        preloadedLrc = [];
        if (next.lrc) {
            fetch(next.lrc)
                .then((res) => { return res.text(); })
                .then((text) => { preloadedLrc = parseLrc(text); })
                .catch(() => { preloadedLrc = []; });
        }
    }

    function handleForcePlay(data, manual) {
        let song = data.song;
        if (!song || !song.url) return;
        // 个人模式：不跟随服务端同步播放；手动点播（个人播放）仍允许
        if (!songSyncMode && !manual) return;
        lastSongServerTime = Date.now();
        // 同 ID 去重：正在播放同一首歌且进度相同时跳过；
        // 若服务端重新广播了同一首歌但 start_time 变化（校准/重播），则重新同步
        if (songPlaying && String(songPlaying.id) === String(song.id) && songCurAudio && !songCurAudio.paused) {
            let newStart = parseFloat(data.start_time || song.start_time || 0);
            let oldStart = parseFloat(songPlaying.start_time || 0);
            if (Math.abs(newStart - oldStart) < 1) {
                return;
            }
        }
        // 无缝切换：若新歌正是已预加载的下一首，空闲实例已加载好音频，直接复用避免卡顿
        let oldAudio = songCurAudio;
        let isPreloaded = songListen && preloadedSongId === String(song.id);
        let nextAudio = (oldAudio === songAudioA) ? songAudioB : songAudioA;

        stopSongPlayback();
        songPlaying = {
            id: song.id,
            name: song.name || '',
            artist: song.artist || '',
            picurl: song.picurl || '',
            url: song.url,
            duration: song.duration || 0,
            adder: song.adder || '',
            start_time: data.start_time || Date.now() / 1000
        };
        if (songListen) {
            if (!isPreloaded) {
                // 未预加载：正常设置音频源
                nextAudio.src = song.url;
                nextAudio.preload = 'auto';
            }
            // 监听真实播放结束事件，自动同步下一首
            nextAudio.onended = function () {
                if (songPlaying && String(songPlaying.id) === String(song.id)) {
                    send({ type: 'lobby_song_finished' });
                }
            };
            // 自动校准：歌曲已开始一段时间时，将音频 seek 到真实进度。
            // 在 loadedmetadata 后重新计算偏移，避免音频加载延迟导致 seek 过时
            let totalSec = (song.duration || 0) / 1000;
            nextAudio.addEventListener('loadedmetadata', function h() {
                nextAudio.removeEventListener('loadedmetadata', h);
                let offset = (Date.now() / 1000) - (song.start_time || 0);
                if (offset > 1 && totalSec > 0 && offset < totalSec - 1) {
                    try {
                        if (Math.abs(nextAudio.currentTime - offset) > 1) {
                            nextAudio.currentTime = offset;
                        }
                    } catch (e) { }
                }
            });
            nextAudio.play().catch(() => { });
        } else {
            // 不听歌：不加载音频（避免浪费流量），清理旧音频源
            if (oldAudio) {
                try { oldAudio.pause(); } catch (e) { }
                oldAudio.src = '';
            }
            nextAudio.src = '';
        }
        songCurAudio = nextAudio;
        updateConnStatusSong();
        renderSongPanel();
        startSongProgress(songPlaying);
        startSongSyncTimer();
        // 歌词：已预加载则直接使用，否则重新拉取
        if (song.lrc && songListen) {
            if (isPreloaded && preloadedLrc.length > 0) {
                lyricsLines = preloadedLrc;
            } else {
                fetchLrc(song.lrc);
            }
        }
        // 消耗预加载标记，随后预加载新的下一首（循环衔接）
        preloadedSongId = null;
        preloadedLrc = [];
        preloadNextSong();
    }

    function handleVoteUpdate(data) {
        if (!data.song_id) return;
        let targetId = String(data.song_id);
        for (let i = 0; i < songPool.length; i++) {
            if (String(songPool[i].id) === targetId) {
                songPool[i].votes = data.votes;
                break;
            }
        }
        songPool.sort((a, b) => b.votes - a.votes);
        renderSongPanel();
    }

    function handleRemoveVoteUpdate(data) {
        if (!data.song_id) return;
        let targetId = String(data.song_id);
        for (let i = 0; i < songList.length; i++) {
            if (String(songList[i].id) === targetId) {
                songList[i].remove_votes = data.remove_votes;
                break;
            }
        }
        renderSongPanel();
    }

    function stopSongPlayback() {
        stopSongProgress();
        stopSongSyncTimer();
        lyricsLines = [];
        if ($lyrics) $lyrics.innerHTML = '';
        if (songCurAudio) {
            try { songCurAudio.pause(); } catch (e) { }
            songCurAudio.onended = null;
            songCurAudio = null;
        }
        songPlaying = null;
        updateConnStatusSong();
    }

    // ==================== 歌词 ====================

    /**
     * 从 URL 拉取 LRC 歌词并解析
     */
    function fetchLrc(url) {
        lyricsLines = [];
        if ($lyrics) $lyrics.textContent = '...';
        fetch(url)
            .then((res) => { return res.text(); })
            .then((text) => { handleLrcResponse(text); })
            .catch(() => {
                lyricsLines = [];
                if ($lyrics) $lyrics.innerHTML = '';
            });
    }

    /**
     * 服务器代理返回 LRC 内容后调用
     */
    function handleLrcResponse(text) {
        if (!text) {
            if ($lyrics) $lyrics.innerHTML = '';
            return;
        }
        lyricsLines = parseLrc(text);
        if ($lyrics && lyricsLines.length === 0) {
            $lyrics.innerHTML = '';
        }
    }

    /**
     * 解析 LRC 格式字符串 → [{time, text}, ...]
     * [00:12.00]歌词文本
     */
    function parseLrc(lrcText) {
        let lines = [];
        let parts = String(lrcText).split('\n');
        for (let i = 0; i < parts.length; i++) {
            let match = parts[i].match(/\[(\d{2}):(\d{2}(?:\.\d+)?)\](.*)/);
            if (!match) continue;
            let min = parseInt(match[1], 10);
            let sec = parseFloat(match[2]);
            let time = min * 60 + sec;
            let text = match[3].trim();
            if (text) lines.push({ time: time, text: text });
        }
        lines.sort((a, b) => a.time - b.time);
        return lines;
    }

    /**
     * 根据当前播放秒数更新歌词显示
     */
    function updateLyrics(elapsed) {
        if (!$lyrics) return;
        if (!songListen) { $lyrics.innerHTML = ''; return; }
        if (!lyricsLines.length || !$lyrics) return;
        let currentLine = '';
        for (let i = lyricsLines.length - 1; i >= 0; i--) {
            if (elapsed >= lyricsLines[i].time) {
                currentLine = lyricsLines[i].text;
                break;
            }
        }
        // 拆分翻译括号：主歌词(翻译) → 两行
        let html = '';
        let transMatch = currentLine.match(/^(.+?)\s*[（(]([^)）]+)[）)]\s*$/);
        if (transMatch) {
            html = '<div class="lyric-line">' + escapeHtml(transMatch[1].trim()) + '</div>' +
                '<div class="lyric-sub">' + escapeHtml(transMatch[2].trim()) + '</div>';
        } else {
            html = '<div class="lyric-line">' + escapeHtml(currentLine) + '</div>';
        }
        $lyrics.innerHTML = html;
        // 检测溢出：哪个超长滚哪个，不同时滚动（主歌词行优先）
        // 需等浏览器布局完成后再检测 scrollWidth，否则刚设置 innerHTML 检测不到溢出
        requestAnimationFrame(function () {
            let lines = $lyrics.querySelectorAll('.lyric-line, .lyric-sub');
            let scrollTarget = null;
            for (let j = 0; j < lines.length; j++) {
                if (!scrollTarget && lines[j].scrollWidth > lines[j].clientWidth) {
                    scrollTarget = lines[j];
                }
            }
            if (scrollTarget) {
                scrollTarget.style.setProperty('--scroll-distance', (scrollTarget.scrollWidth - scrollTarget.clientWidth) + 'px');
                scrollTarget.classList.add('scrolling');
            }
        });
    }

    function toggleSongPanel(e) {
        if (e) e.stopPropagation();
        if (!$songPanel) return;
        if ($songPanel.style.display === 'none') {
            $songPanel.style.display = 'flex';
            if ($usersPanel && $usersPanel.style.display !== 'none') {
                closeSidebar($usersPanel);
            }
            if ($overlay) $overlay.style.display = 'block';
            renderSongPanel();
        } else {
            closeSidebar($songPanel);
        }
    }

    // 侧边栏关闭动画：先滑出再隐藏
    function closeSidebar(panel) {
        if (!panel || panel.style.display === 'none') return;
        panel.classList.add('closing');
        setTimeout(function () {
            panel.classList.remove('closing');
            panel.style.display = 'none';
            // 两个面板都关闭时隐藏遮罩
            if ($overlay && $usersPanel && $songPanel &&
                $usersPanel.style.display === 'none' &&
                $songPanel.style.display === 'none') {
                $overlay.style.display = 'none';
            }
        }, 100);
    }

    function renderSongPanel() {
        if (!$songPlaylist) return;
        // 当前播放
        if ($songPlayingInfo) {
            if (songPlaying) {
                $songPlayingInfo.style.display = 'block';
                let elapsed = (songCurAudio && songCurAudio.src && !songCurAudio.paused &&
                    isFinite(songCurAudio.currentTime) && songCurAudio.currentTime > 0)
                    ? songCurAudio.currentTime
                    : (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                let totalSec = (songPlaying.duration || 0) / 1000;
                let pct = totalSec > 0 ? Math.min(100, Math.max(0, (elapsed / totalSec) * 100)) : 0;
                let nextName = '';
                let next = getNextSong();
                if (next) {
                    nextName = next.name + (next.artist ? ' - ' + next.artist : '') + ' (' + (next.votes || 0) + '票)';
                }
                $songPlayingInfo.innerHTML =
                    '<div class="spi-main">' +
                    '<div class="spi-cover-wrap">' +
                    '<img class="spi-cover" src="' + escapeHtmlAttr(songPlaying.picurl || '') + '" alt="" />' +
                    '</div>' +
                    '<div class="spi-body">' +
                    '<div class="spi-header">' + escapeHtml(songPlaying.name) + ' — ' + escapeHtml(songPlaying.artist || '') + ' <button class="doodle-btn spi-sync-btn" onclick="syncSongNow()" title="手动同步歌曲" style="font-size:11px;padding:2px 8px;margin-left:6px;">同步</button></div>' +
                    '<div class="spi-adder">点歌人: ' + escapeHtml(songPlaying.adder || '未知') + '</div>' +
                    (songListen
                        ? '<div class="spi-progress-bar"><div class="spi-progress-fill" style="width:' + pct.toFixed(1) + '%"></div></div>' +
                        '<div class="spi-time">' + formatDuration(elapsed * 1000) + ' / ' + formatDuration(songPlaying.duration) + '</div>'
                        : '<div class="spi-paused">⏸ 已暂停听歌</div>') +
                    (nextName ? '<div class="spi-next">下一首: ' + escapeHtml(nextName) + '</div>' : '') +
                    '</div></div>';
            } else {
                $songPlayingInfo.style.display = 'none';
            }
        }
        // 歌单列表：播放队列（完整信息 + 移除投票按钮）+ 投票池（基本信息 + 投票按钮）
        let html = '';
        let removeThreshold = Math.max(2, Math.ceil(onlinePlayerCount / 2));
        // 播放队列
        if (songList.length > 0) {
            html += '<div class="song-section-title">即将播放</div>';
            for (let i = 0; i < songList.length; i++) {
                let s = songList[i];
                let dur = s.duration ? formatDuration(s.duration) : '';
                let isCurrent = songPlaying && String(s.id) === String(songPlaying.id);
                let sIdStr = String(s.id);
                let hasRemoveVoted = removeVotedSongs.has(sIdStr);
                let removeVotes = s.remove_votes || 0;
                html += '<div class="song-item song-item-playlist' + (isCurrent ? ' song-item-current' : '') + '" data-play-id="' + escapeHtmlAttr(s.id) + '">' +
                    '<span class="song-votes">' + (i + 1) + '</span>' +
                    '<span class="song-info">' +
                    '<div class="song-title"><span class="song-title-text">' + escapeHtml(s.name || '') + '</span></div>' +
                    '<div class="song-meta">' + escapeHtml(s.artist || '') + (s.album ? ' · ' + escapeHtml(s.album) : '') + (dur ? ' · ' + dur : '') + (s.adder ? ' · ' + escapeHtml(s.adder) : '') + '</div>' +
                    '</span>';
                if (isCurrent) {
                    html += '<span class="song-playing-badge">播放中</span>';
                } else {
                    html +=
                        '<span class="song-remove-area">' +
                        '<span class="song-remove-count' + (hasRemoveVoted ? ' voted' : '') + '" title="移除投票 ' + removeVotes + '/' + removeThreshold + '">' + removeVotes + '/' + removeThreshold + '</span>' +
                        '<button class="song-remove-btn' + (hasRemoveVoted ? ' voted' : '') + '" data-remove-id="' + escapeHtmlAttr(s.id) + '" title="' + (hasRemoveVoted ? '已投移除票' : '投移除票') + '">✕</button>' +
                        '</span>';
                }
                // 管理员：直接移除歌曲（替代原 \removesong 指令）
                if (isLobbyAdmin) {
                    html += '<button class="song-admin-remove" data-admin-remove-id="' + escapeHtmlAttr(s.id) + '" title="管理员移除">🗑</button>';
                }
                html += '</div>';
            }
        }
        // 投票池
        if (songPool.length > 0) {
            html += '<div class="song-section-title">投票池</div>';
            for (let i = 0; i < songPool.length; i++) {
                let s = songPool[i];
                html += '<div class="song-item">' +
                    '<span class="song-votes">' + (s.votes || 0) + '</span>' +
                    '<span class="song-info">' +
                    '<div class="song-title"><span class="song-title-text">' + escapeHtml(s.name || '') + '</span></div>' +
                    '<div class="song-meta">' + escapeHtml(s.artist || '') + ' · ' + (s.voter_count || 0) + '人已投' + (s.adder ? ' · ' + escapeHtml(s.adder) : '') + '</div>' +
                    '</span>' +
                    '<button class="song-vote-btn" data-song-id="' + escapeHtmlAttr(s.id) + '">投票</button>' +
                    (isLobbyAdmin ? '<button class="song-admin-remove" data-admin-remove-id="' + escapeHtmlAttr(s.id) + '" title="管理员移除">🗑</button>' : '') +
                    '</div>';
            }
        }
        if (songList.length === 0 && songPool.length === 0) {
            html = '<div style="font-size:11px;color:let(--text-subtle);text-align:center;padding:12px 0;">歌单为空，搜索歌曲来点歌吧</div>';
        }
        $songPlaylist.innerHTML = html;
        // 绑定投票按钮事件
        let btns = $songPlaylist.querySelectorAll('.song-vote-btn');
        for (let j = 0; j < btns.length; j++) {
            btns[j].addEventListener('click', function (e) {
                let id = this.getAttribute('data-song-id');
                if (id) voteSong(id);
            });
        }
        // 绑定移除投票按钮事件（先绑定，且阻止冒泡，避免同时触发播放歌曲点击）
        let removeBtns = $songPlaylist.querySelectorAll('.song-remove-btn');
        for (let r = 0; r < removeBtns.length; r++) {
            removeBtns[r].addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                let id = this.getAttribute('data-remove-id');
                if (id) removeVoteSong(id);
            });
        }
        // 绑定管理员移除歌曲按钮
        let adminRemoveBtns = $songPlaylist.querySelectorAll('.song-admin-remove');
        for (let ar = 0; ar < adminRemoveBtns.length; ar++) {
            adminRemoveBtns[ar].addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                let id = this.getAttribute('data-admin-remove-id');
                if (id) send({ type: 'lobby_song_admin_remove', song_id: id });
            });
        }
        // 个人模式：点击播放队列歌曲直接本地播放（同步模式仍由服务端统一控制，不可选播）
        if (!songSyncMode) {
            let playItems = $songPlaylist.querySelectorAll('.song-item-playlist');
            for (let p = 0; p < playItems.length; p++) {
                playItems[p].classList.add('song-item-playable');
                playItems[p].addEventListener('click', function () {
                    let id = this.getAttribute('data-play-id');
                    if (!id) return;
                    // 当前正在播放的歌不重复触发
                    if (songPlaying && String(songPlaying.id) === String(id)) return;
                    for (let k = 0; k < songList.length; k++) {
                        if (String(songList[k].id) === String(id)) {
                            handleForcePlay({ song: songList[k], start_time: Date.now() / 1000 }, true);
                            break;
                        }
                    }
                });
            }
        }
        // 检测歌名溢出，溢出时启用滚动动画
        let titles = $songPlaylist.querySelectorAll('.song-title');
        for (let t = 0; t < titles.length; t++) {
            let textEl = titles[t].querySelector('.song-title-text');
            if (textEl && textEl.scrollWidth > titles[t].clientWidth) {
                titles[t].style.setProperty('--scroll-distance', (textEl.scrollWidth - titles[t].clientWidth) + 'px');
                titles[t].classList.add('scrolling');
            }
        }
    }

    // 仅更新移除投票计数显示（人数变化时阈值改变，不重建列表避免打断滚动动画）
    function updateRemoveVoteDisplay() {
        if (!$songPlaylist) return;
        let newThreshold = Math.max(2, Math.ceil(onlinePlayerCount / 2));
        let counts = $songPlaylist.querySelectorAll('.song-remove-count');
        for (let i = 0; i < counts.length; i++) {
            let text = counts[i].textContent || '';
            let votes = text.split('/')[0] || '0';
            counts[i].textContent = votes + '/' + newThreshold;
            counts[i].title = '移除投票 ' + votes + '/' + newThreshold;
        }
    }

    function renderSongSearchResults(results) {
        if (!$songSearchResults) return;
        if (!results || results.length === 0) {
            $songSearchResults.innerHTML = '<div style="font-size:11px;color:let(--text-subtle);text-align:center;padding:8px 0;">未找到歌曲</div>';
            if ($songSearchClear) $songSearchClear.style.display = 'inline-block';
            return;
        }
        let html = '';
        for (let i = 0; i < results.length; i++) {
            let r = results[i];
            html += '<div class="song-search-item" data-song-id="' + escapeHtmlAttr(r.id) + '" data-song-name="' + escapeHtmlAttr(r.name || '') + '" data-song-artist="' + escapeHtmlAttr(r.artist || '') + '">' +
                '<span class="search-item-name">' + escapeHtml(r.name || '') + '</span>' +
                '<span class="search-item-artist">' + escapeHtml(r.artist || '') + '</span>' +
                '</div>';
        }
        $songSearchResults.innerHTML = html;
        // 绑定点击事件
        let items = $songSearchResults.querySelectorAll('.song-search-item');
        for (let j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                let id = this.getAttribute('data-song-id');
                let name = this.getAttribute('data-song-name');
                let artist = this.getAttribute('data-song-artist');
                if (id) requestSong(id, name, artist);
            });
        }
        if ($songSearchClear) $songSearchClear.style.display = 'inline-block';
    }

    function searchSong() {
        if (!$songSearchInput) return;
        let keyword = $songSearchInput.value.trim();
        if (!keyword || keyword.length < 1) {
            showTopToast('请输入歌曲名', true);
            return;
        }
        send({ type: 'lobby_song_search', keyword: keyword });
        $songSearchResults.innerHTML = '<div style="font-size:11px;color:let(--text-subtle);text-align:center;padding:8px 0;">搜索中...</div>';
        if ($songSearchClear) $songSearchClear.style.display = 'none';
    }

    function clearSongSearch() {
        if ($songSearchInput) $songSearchInput.value = '';
        $songSearchResults.innerHTML = '';
        if ($songSearchClear) $songSearchClear.style.display = 'none';
    }

    function requestSong(songId, songName, artist) {
        send({ type: 'lobby_song_request', song_id: songId, song_name: songName, artist: artist, nickname: myNickname });
    }

    function voteSong(songId) {
        send({ type: 'lobby_song_vote', song_id: songId });
    }

    function removeVoteSong(songId) {
        let idStr = String(songId);
        if (removeVotedSongs.has(idStr)) {
            showTopToast('你已经投过移除票了', true);
            return;
        }
        // 先乐观标记，服务器拒绝时会通过 lobby_error 提示
        removeVotedSongs.add(idStr);
        send({ type: 'lobby_song_remove_vote', song_id: songId });
    }

    function toggleSongListen() {
        songListen = !songListen;
        let ud = getUserdata();
        ud.song_listen = songListen;
        saveUserdata(ud);
        if (songListen) {
            // 重新打开听歌：立即请求服务器同步当前播放状态
            send({ type: 'lobby_song_current' });
            send({ type: 'lobby_song_list' });
            // 若音频已清理（之前关了听歌），从服务端重新同步当前歌曲
            if (songPlaying && (!songCurAudio || !songCurAudio.src)) {
                // handleForcePlay will be called when server responds
            } else if (songPlaying && songCurAudio && songCurAudio.src && songCurAudio.paused) {
                // 音频还在：seek 到正确位置并恢复播放
                let elapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                let durSec = songPlaying.duration / 1000;
                if (elapsed > 1 && elapsed < durSec) {
                    try { songCurAudio.currentTime = elapsed; } catch (e) { }
                }
                songCurAudio.play().catch(() => { });
            }
        } else {
            // 关闭听歌：停止加载音频 + 清空歌词
            if (songCurAudio) {
                try { songCurAudio.pause(); } catch (e) { }
                songCurAudio.src = '';
            }
            if ($lyrics) $lyrics.innerHTML = '';
            updateConnStatusSong();
        }
        renderSongPanel();
    }

    function toggleSongSyncMode() {
        songSyncMode = $songSyncToggle.checked;
        let ud = getUserdata();
        ud.song_sync_mode = songSyncMode;
        saveUserdata(ud);
        if ($songSyncLabel) {
            $songSyncLabel.textContent = songSyncMode ? '同步模式' : '个人模式';
        }
        if (songSyncMode) {
            // 切回同步模式：立即请求服务器同步当前播放状态
            send({ type: 'lobby_song_current' });
        } else {
            // 切到个人模式：停止跟随服务器播放
            stopSongPlayback();
        }
        renderSongPanel();
    }

    // ==================== 点歌事件绑定 ====================
    if ($btnSong) {
        $btnSong.addEventListener('click', toggleSongPanel);
    }
    if ($songListenToggle) {
        $songListenToggle.checked = songListen;
        $songListenToggle.addEventListener('change', toggleSongListen);
    }
    if ($songSyncToggle) {
        $songSyncToggle.checked = songSyncMode;
        if ($songSyncLabel) $songSyncLabel.textContent = songSyncMode ? '同步模式' : '个人模式';
        $songSyncToggle.addEventListener('change', toggleSongSyncMode);
    }
    if ($songSearchBtn) {
        $songSearchBtn.addEventListener('click', searchSong);
    }
    if ($songSearchClear) {
        $songSearchClear.addEventListener('click', clearSongSearch);
    }
    if ($songSearchInput) {
        $songSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') searchSong();
        });
    }
    // 点击外部关闭歌曲信息提示
    document.addEventListener('click', function (e) {
        if (!$songInfo || $songInfo.style.display === 'none') return;
        if (!$songInfo.contains(e.target) && !e.target.closest('#lobby-song-status-name')) {
            closeSongInfo();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSongInfo();
    });

    // ==================== 初始化 ====================
    autoUpgradeOldUserdata();
    showIdentityState();
    updateNotifyUI();
    if (notifyEnabled && 'Notification' in window && Notification.permission !== 'granted') {
        requestNotifyPermission();
    }

    // 如果处于iframe环境
    if (window.self !== window.top) {
        $header.style.display = 'none';
        $lobbyChatHeader.style.display = 'none';
        $main.style.height = '100vh';
    }

    // ==================== 退出确认 ====================

    // 返回按钮
    $btnBack.addEventListener('click', function () {
        leaveLobbyGracefully('/');
    });

    // 关闭/刷新标签页：已进入聊天室时主动关闭 WS
    window.addEventListener('beforeunload', function (e) {
        if ($hasIdentity.style.display !== 'none') {
            stopHeartbeat();
            intentionalClose = true;
            if (ws) { try { ws.close(); } catch(e) {} ws = null; }
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // pagehide 兜底：页面隐藏时一定关闭 WS（前进/后退/关闭等场景）
    window.addEventListener('pagehide', function () {
        stopHeartbeat();
        intentionalClose = true;
        if (ws) { try { ws.close(); } catch(e) {} ws = null; }
    });

    /** 优雅离开聊天室：关闭WS后延迟导航，确保服务端先收到 close 帧 */
    function leaveLobbyGracefully(url) {
        stopHeartbeat();
        intentionalClose = true;
        if (ws) { try { ws.close(); } catch(e) {} ws = null; }
        setTimeout(function () { location.href = url; }, 50);
    }

    // 暴露渲染函数给五子棋聊天室复用
    window.LobbyRenderer = {
        makeBubble: makeBubble,
        renderRecordCard: renderRecordCard,
        renderGomokuInviteCard: renderGomokuInviteCard,
        mdFormat: mdFormat,
        escapeHtml: escapeHtml,
    };

    // 手动同步歌曲：向服务器请求当前播放状态
    window.syncSongNow = function () {
        send({ type: 'lobby_song_current' });
        send({ type: 'lobby_song_list' });
    };
})();
