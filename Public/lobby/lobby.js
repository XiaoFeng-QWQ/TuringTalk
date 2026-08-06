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
    const $lyrics = document.getElementById('lobby-lyrics');
    const $hasIdentity = document.getElementById('lobby-has-identity');
    const $noIdentity = document.getElementById('lobby-no-identity');
    const $fillName = document.getElementById('lobby-fill-name');
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
    const $btnBack = document.getElementById('btn-back');
    const $replyPreview = document.getElementById('lobby-reply-preview');
    const $replyPreviewText = document.getElementById('lobby-reply-preview-text');
    const $replyPreviewCancel = document.getElementById('lobby-reply-preview-cancel');
    const $btnNotify = document.getElementById('lobby-btn-notify');
    // 身份状态 DOM
    const $recoverNickname = document.getElementById('lobby-recover-nickname');
    const $recoverInput = document.getElementById('lobby-recover-input');
    const $btnRecover = document.getElementById('lobby-btn-recover');
    const $recoverMsg = document.getElementById('lobby-recover-msg');
    const $btnGoHome = document.getElementById('lobby-btn-go-home');
    const $btnNewName = document.getElementById('lobby-btn-new-name');
    const $nicknameInput = document.getElementById('lobby-nickname-input');
    const $btnJoin = document.getElementById('lobby-btn-join');
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
    let myPlayerId = '';          // player_data.id，用于消息归属判断（防止昵称冒用）
    let lastSentStickerId = '';   // 本地渲染去重，防止服务端广播回传导致重复
    let replyTarget = null;      // { id, name, text }
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
    let songProgressTimer = null; // 进度条更新定时器
    let audioUnlocked = false;    // 浏览器自动播放策略是否已解锁
    let songListen = getUserdata().song_listen ?? true;  // 是否参与听歌

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
        Notification.requestPermission().then(function (perm) {
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
        var nickname = getUserNickname();
        var pid = getUserPlayerId();

        if (nickname && pid) {
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
            player_id: getUserPlayerId() || '',
            recovery_code: getUserRecoveryCode() || ''
        });
    }

    function handleJoined(data) {
        var localPid = getUserPlayerId();
        // 仅在本地无 player_id 或服务端返回的 player_id 与本地一致时才写入，防止数据被其他玩家覆盖
        var pidMatch = !localPid || String(localPid) === String(data.player_id || '');
        if (data.player_id && pidMatch) {
            if (!localPid) setUserPlayerId(data.player_id);
            myPlayerId = String(data.player_id);
        }
        if (data.recovery_code && pidMatch) {
            if (!getUserRecoveryCode()) setUserRecoveryCode(data.recovery_code);
        }
        // 仅在 player_id 一致时使用服务端昵称，否则保留本地昵称
        if (pidMatch && data.nickname && data.nickname !== myNickname) {
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
        // 请求当前播放状态和歌单
        send({ type: 'lobby_song_current' });
        send({ type: 'lobby_song_list' });
    }

    // 返回首页
    $btnGoHome.addEventListener('click', function () {
        location.href = '/';
    });

    // 恢复码恢复
    function doRecover() {
        var pid = $recoverInput.value.trim();
        if (!pid) { showRecoverMsg('请输入恢复码'); return; }
        var nickname = $recoverNickname.value.trim();
        if (!nickname) { showRecoverMsg('请先填写昵称'); return; }
        $recoverMsg.style.display = 'none';
        $btnRecover.disabled = true;
        $btnRecover.textContent = '恢复中...';
        fetch('/api/player-stats?recovery_code=' + encodeURIComponent(pid) + '&nickname=' + encodeURIComponent(nickname))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                $btnRecover.disabled = false;
                $btnRecover.textContent = '恢复';
                if (data.error) { showRecoverMsg(data.error); return; }
                setUserNickname(nickname);
                setUserPlayerId(data.player_id);
                if (data.code) setUserRecoveryCode(data.code);
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
                if (!isMineMessage(data) && document.hidden) {
                    var preview = data.content || '';
                    if (preview.length > 60) preview = preview.substring(0, 60) + '...';
                    sendNotification(data.sender_name, preview);
                }
                break;

            case 'sticker':
                // 如果该表情已由本地渲染过（发送时立即渲染），跳过服务端广播回传
                if (lastSentStickerId === (data.id || '')) {
                    lastSentStickerId = '';
                    break;
                }
                appendStickerMessage(data);
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
                sendNotification('有人@了你', data.sender_name + ' 在公共聊天室提到了你');
                break;

            case 'system':
                if (data.text && data.text.includes('已有活跃连接')) {
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

            case 'stickers_list':
                stickerMap = handleStickersList(data);
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
                    if (!songPlaying) {
                        handleForcePlay({ song: data.playing, start_time: data.playing.start_time || Date.now() / 1000 });
                    }
                } else {
                    songPlaying = null;
                    stopSongPlayback();
                    updateConnStatusSong();
                }
                renderSongPanel();
                break;

            case 'lobby_song_requested':
                showTopToast('已点歌: ' + (data.song ? data.song.name : ''), false);
                break;

            case 'list_update':
                songList = data.playlist || [];
                songPool = data.pool || [];
                if (data.playing) {
                    if (!songPlaying) {
                        handleForcePlay({ song: data.playing, start_time: data.playing.start_time || Date.now() / 1000 });
                    }
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

    function insertMention(name) {
        if (!name || !$chatInput) return;
        var at = '@' + name + ' ';
        if (document.activeElement === $chatInput) {
            var start = $chatInput.selectionStart;
            var end = $chatInput.selectionEnd;
            var val = $chatInput.value;
            $chatInput.value = val.substring(0, start) + at + val.substring(end);
            $chatInput.selectionStart = $chatInput.selectionEnd = start + at.length;
        } else {
            $chatInput.value += at;
        }
        $chatInput.focus();
    }

    // 消息归属判断：优先用 player_data.id，防止昵称冒用导致消息归属错误
    function isMineMessage(data) {
        if (myPlayerId && data.sender_id) {
            return String(data.sender_id) === myPlayerId;
        }
        return data.sender_name === myNickname;
    }

    function makeBubble(data, isMine) {
        var senderName = data.sender_name || '';

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

        // 长按头像 → @昵称
        (function (av, name) {
            var timer = null;
            var started = false;

            function onStart(e) {
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

        var replyHtml = '';
        if (data.reply_to && data.reply_to.id) {
            replyHtml = '<div class="lobby-msg-reply" data-reply-id="' + data.reply_to.id + '">' +
                '<span class="reply-name">' + escapeHtml(data.reply_to.name) + '</span>: ' +
                escapeHtml(data.reply_to.text) +
                '</div>';
        }

        // 表情消息：渲染为图片
        if (data.type === 'sticker' && data.sticker_id) {
            var stickerUrl = resolveStickerUrl(data.sticker_id, data.sticker_url, stickerMap);
            bubble.innerHTML = stickerUrl
                ? '<img class="sticker-img" src="' + escapeHtmlAttr(stickerUrl) + '" alt="表情" title="' + escapeHtmlAttr(data.sticker_name || '') + '">'
                : '<span style="color:#999;font-style:italic;">[表情不存在: ' + escapeHtml(data.sticker_id) + ']</span>';
            if (stickerUrl) {
                (function (url) {
                    var img = bubble.querySelector('.sticker-img');
                    if (img) {
                        img.addEventListener('click', function () {
                            showStickerLightbox(url);
                        });
                    }
                })(stickerUrl);
            }
        } else {
            bubble.innerHTML =
                replyHtml +
                '<div class="lobby-msg-text">' + autoLink(parseBilibiliLinks(escapeHtml(data.content))) + '</div>';
        }

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

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        return wrapper;
    }

    function appendStickerMessage(data) {
        var senderName = data.sender || '';
        var stickerId = data.id || '';
        var stickerUrl = resolveStickerUrl(stickerId, data.url, stickerMap);
        var isMine = data.sender_id ? String(data.sender_id) === myPlayerId : senderName === myNickname;

        var wrapper = document.createElement('div');
        wrapper.className = 'lobby-msg-row';
        if (isMine) wrapper.classList.add('mine');

        var avatar = document.createElement('div');
        avatar.className = 'lobby-avatar';
        avatar.textContent = getAvatarChar(senderName);
        avatar.style.background = isMine ? 'var(--note-blue)' : getAvatarColor(senderName);

        var content = document.createElement('div');
        content.className = 'lobby-msg-content';

        var meta = document.createElement('div');
        meta.className = 'lobby-msg-meta';
        var nameSpan = document.createElement('span');
        nameSpan.className = 'lobby-msg-sender';
        nameSpan.textContent = senderName;
        meta.appendChild(nameSpan);
        content.appendChild(meta);

        var bubble = document.createElement('div');
        bubble.className = 'lobby-msg' + (isMine ? ' mine' : '');
        bubble.innerHTML = stickerUrl
            ? '<img class="sticker-img" src="' + escapeHtmlAttr(stickerUrl) + '" alt="表情">'
            : '<span style="color:#999;font-style:italic;">[表情不存在: ' + escapeHtml(stickerId) + ']</span>';

        if (stickerUrl) {
            var img = bubble.querySelector('.sticker-img');
            img.addEventListener('click', function () {
                showStickerLightbox(stickerUrl);
            });
        }

        content.appendChild(bubble);
        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        $messages.appendChild(wrapper);
        scrollToBottom();
    }

    function renderHistory(messages) {
        $messages.innerHTML = '';
        if (!messages || messages.length === 0) {
            appendSystem('欢迎来到公共聊天室', true);
            return;
        }
        appendSystem('── 以下是最近消息 ──', false);
        messages.forEach(function (m) {
            var bubble = makeBubble(m, isMineMessage(m));
            $messages.appendChild(bubble);
            resolveBilibiliEmbeds(bubble);
        });
        scrollToBottom();
    }

    function appendMessage(data) {
        var bubble = makeBubble(data, isMineMessage(data));
        $messages.appendChild(bubble);
        resolveBilibiliEmbeds(bubble);
        scrollToBottom();

        if (new Date().getDay() === 4 && data.content && /疯狂星期四|V我50|KFC|鸡腿|全家桶|原味鸡/.test(data.content)) {
            var rect = bubble.getBoundingClientRect();
            var cx = rect.left + rect.width / 2;
            var cy = rect.top + rect.height / 2;
            spawnKfcBurst(cx, cy, 5);
        }
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
        if (!el) return;
        const row = el.closest('.lobby-msg-row');
        if (!row) return;
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(function () { row.remove(); }, 300);
    }

    function revokeMessageUI(messageId, senderName) {
        // 显示系统消息
        appendSystem((senderName || '有人') + ' 撤回了一条消息', false);
        // 从 DOM 移除原消息
        removeMessage(messageId);
        // 更新所有引用该消息的回复预览
        document.querySelectorAll('.lobby-msg-reply[data-reply-id="' + messageId + '"]').forEach(function (reply) {
            reply.innerHTML = '<span class="reply-name">' + escapeHtml(senderName || '有人') + '</span>: <i>消息已撤回</i>';
            reply.classList.add('revoked');
        });
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

        var isMine = isMineMessage(data);
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
            id: /^\d+$/.test(bubble.dataset.msgId) ? parseInt(bubble.dataset.msgId, 10) : bubble.dataset.msgId,
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
        // 按 fd 去重（兜底：防止后端清理延迟导致的重复条目）
        var seen = {};
        var deduped = [];
        for (var i = 0; i < players.length; i++) {
            var p = players[i];
            if (seen.hasOwnProperty(p.fd)) continue;
            seen[p.fd] = true;
            deduped.push(p);
        }
        onlinePlayers = deduped;
        onlinePlayerCount = deduped.length;

        if ($usersCount) $usersCount.textContent = onlinePlayerCount;
        // 在线人数变化 → 移除投票阈值变化 → 只更新移除投票计数显示，不重建列表
        updateRemoveVoteDisplay();
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

        // 右上角连接状态栏已移除，无需刷新
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
                sender: myNickname, sender_id: myPlayerId
            });
            // 记录已渲染的表情，防止服务端广播回报时重复追加
            lastSentStickerId = id;
            $stickerPicker.style.display = 'none';
        });
    }

    bindStickerPickerTabs('lobby-sticker-picker', renderStickerPicker, repositionStickerPicker);

    function requestStickers() {
        send({ type: 'get_stickers', version: getStickerCacheVersion(), player_id: myPlayerId });
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
    $btnToggleUsers.addEventListener('click', function () {
        if (!$usersPanel) return;
        if ($usersPanel.style.display === 'none') {
            $usersPanel.style.display = 'flex';
            // 关闭点歌面板
            if ($songPanel && $songPanel.style.display !== 'none') {
                $songPanel.style.display = 'none';
            }
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

    /**
     * 解析已转义文本中的 B站视频链接，替换为占位元素
     * 必须在 escapeHtml 之后、autoLink 之前调用。
     * 异步解析由 resolveBilibiliEmbeds 完成。
     */
    function parseBilibiliLinks(text) {
        var regex = /https?:\/\/(?:www\.)?bilibili\.com\/video\/[^\s<>"'，。！？、；：》\)\]]+|https?:\/\/b23\.tv\/[^\s<>"'，。！？、；：》\)\]]+/gi;
        return text.replace(regex, function (match) {
            // 剥离 GET 参数
            var cleanUrl = match.replace(/\?.*$/, '');
            return '<div class="bili-embed" data-bili-url="' + encodeURIComponent(cleanUrl) + '">' +
                   '<div class="bili-loading">' + BILI_SPINNER_SVG + '解析中...</div>' +
                   '</div>';
        });
    }

    /**
     * 对容器内所有 B站占位元素发起 API 解析请求，替换为播放器
     */
    function resolveBilibiliEmbeds(container) {
        var placeholders = container.querySelectorAll('.bili-embed[data-bili-url]');
        for (var i = 0; i < placeholders.length; i++) {
            biliObserver.observe(placeholders[i]);
        }
    }

    var biliObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var el = entry.target;
            biliObserver.unobserve(el);
            var url = decodeURIComponent(el.getAttribute('data-bili-url'));
            if (!url) return;
            var apiUrl = 'https://api.xiaofengqwq.com/api/v1/tools/video-parse?url=' + encodeURIComponent(url);
            fetchBiliWithRetry(el, apiUrl, 0, function (json) {
                if (json && json.code === 200 && json.data && json.data.video_url) {
                    var data = json.data;
                    var videoUrl = data.video_url;
                    var title = data.title || '';
                    var cover = data.cover || '';
                    el.innerHTML =
                        '<video class="bili-video" src="' + videoUrl + '"' + (cover ? ' poster="' + cover + '"' : '') + ' controls></video>' +
                        '<div class="bili-title"><a href="' + url + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(title) + '</a></div>';
                    try { new Plyr(el.querySelector('.bili-video')); } catch (e) {}
                } else {
                    el.innerHTML = '<div class="bili-error">⚠ 视频解析失败</div>';
                }
            });
        });
    }, { rootMargin: '200px' });

    function fetchBiliWithRetry(el, apiUrl, attempt, onDone) {
        fetch(apiUrl)
            .then(function (res) { return res.json(); })
            .then(function (json) { onDone(json); })
            .catch(function () {
                if (attempt < 2) {
                    var loading = el.querySelector('.bili-loading');
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
                var href = match;
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
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function updateConnStatusSong() {
        if (songPlaying) {
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
        // 计算进度
        var elapsed = (Date.now() / 1000) - (song.start_time || 0);
        var total = (song.duration || 0) / 1000;
        var pct = total > 0 ? Math.min(100, Math.max(0, (elapsed / total) * 100)) : 0;
        $songInfoProgressBar.style.width = pct + '%';
        $songInfoTime.textContent = formatDuration(elapsed * 1000) + ' / ' + formatDuration(song.duration);
        // 下一首
        var nextText = '';
        if (songList.length > 0) {
            var curIdx = -1;
            for (var k = 0; k < songList.length; k++) {
                if (String(songList[k].id) === String(song.id)) { curIdx = k; break; }
            }
            var nextIdx;
            if (curIdx >= 0 && curIdx + 1 < songList.length) {
                nextIdx = curIdx + 1;
            } else if (curIdx === -1) {
                nextIdx = 0;
            } else {
                nextIdx = -1;
            }
            if (nextIdx >= 0) {
                nextText = '下一首: ' + songList[nextIdx].name + ' (' + songList[nextIdx].votes + '票)';
            }
        } else {
            nextText = '投票池为空';
        }
        $songInfoNext.textContent = nextText;
        // 定位
        var target = e.target;
        var rect = target.getBoundingClientRect();
        var left = rect.left;
        var top = rect.bottom + 6;
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
            var elapsed = (Date.now() / 1000) - song.start_time;
            var total = (song.duration || 0) / 1000;

            // 歌曲播放完毕，自动切到下一首
            if (total > 0 && elapsed >= total) {
                stopSongProgress();
                advanceToNext();
                return;
            }

            var pct = total > 0 ? Math.min(100, Math.max(0, (elapsed / total) * 100)) : 0;
            var timeText = formatDuration(elapsed * 1000) + ' / ' + formatDuration(song.duration);
            var panelVisible = $songPlayingInfo && $songPlayingInfo.style.display !== 'none';
            var tipVisible = $songInfo && $songInfo.style.display !== 'none';
            if (!panelVisible && !tipVisible) { stopSongProgress(); return; }
            if (tipVisible) {
                $songInfoProgressBar.style.width = pct + '%';
                $songInfoTime.textContent = timeText;
            }
            if (panelVisible) {
                var fill = $songPlayingInfo.querySelector('.spi-progress-fill');
                var time = $songPlayingInfo.querySelector('.spi-time');
                if (fill) fill.style.width = pct + '%';
                if (time) time.textContent = timeText;
            }
            // 更新歌词
            updateLyrics(elapsed);
        }, 800);
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
        // 唤醒 AudioContext
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
        // 如果已有待播放的歌曲，从正确位置恢复播放
        if (songCurAudio && songCurAudio.src && songCurAudio.paused && songListen) {
            if (songPlaying && songPlaying.start_time && songPlaying.duration) {
                var elapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                var durSec = songPlaying.duration / 1000;
                if (elapsed > 1 && elapsed < durSec) {
                    songCurAudio.currentTime = elapsed;
                }
            }
            songCurAudio.play().catch(function () { });
        }
    }

    // 首次用户手势时解锁音频
    ['click', 'touchstart', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, tryUnlockAudio, { once: true });
    });

    function handleForcePlay(data) {
        var song = data.song;
        if (!song || !song.url) return;
        // 同 ID 去重：正在播放同一首歌时跳过（手动切歌后可避免服务器广播重新加载）
        if (songPlaying && String(songPlaying.id) === String(song.id) && songCurAudio && !songCurAudio.paused) {
            return;
        }
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
        // 播放
        var nextAudio = (songCurAudio === songAudioA) ? songAudioB : songAudioA;
        if (songCurAudio) {
            try { songCurAudio.pause(); } catch (e) { }
        }
        nextAudio.src = song.url;
        if (songListen) {
            nextAudio.play().catch(function () { });
        }
        songCurAudio = nextAudio;
        updateConnStatusSong();
        renderSongPanel();
        startSongProgress(songPlaying);
        // 加载歌词
        if (song.lrc) {
            fetchLrc(song.lrc);
        }
    }

    function advanceToNext() {
        var next = null;
        if (songList.length > 0 && songPlaying) {
            var curIdx = -1;
            for (var i = 0; i < songList.length; i++) {
                if (String(songList[i].id) === String(songPlaying.id)) { curIdx = i; break; }
            }
            if (curIdx >= 0 && curIdx + 1 < songList.length) {
                // 当前歌曲在列表中，且后面还有歌 → 正常切到下一首
                next = songList[curIdx + 1];
            } else if (curIdx === -1) {
                // 当前歌曲不在列表中（已被服务端 pop 出队）→ 队列头即下一首
                next = songList[0];
            }
            // curIdx >= 0 但在列表末尾 → next 保持 null，等待服务端广播新歌单
        }
        if (next) {
            handleForcePlay({ song: next, start_time: Date.now() / 1000 });
        }
    }

    // 点击播放队列中指定歌曲切歌
    function playSongById(id) {
        var target = null;
        for (var i = 0; i < songList.length; i++) {
            if (String(songList[i].id) === String(id)) { target = songList[i]; break; }
        }
        if (!target) return;
        // 点击的就是当前播放歌曲 → 不做任何事
        if (songPlaying && String(target.id) === String(songPlaying.id)) return;
        // 客户端自主切歌：不上报服务端，仅本地播放（服务端 playing 状态不随切歌变化）
        handleForcePlay({ song: target, start_time: Date.now() / 1000 });
    }

    function handleVoteUpdate(data) {
        if (!data.song_id) return;
        var targetId = String(data.song_id);
        for (var i = 0; i < songPool.length; i++) {
            if (String(songPool[i].id) === targetId) {
                songPool[i].votes = data.votes;
                break;
            }
        }
        songPool.sort(function (a, b) { return b.votes - a.votes; });
        renderSongPanel();
    }

    function handleRemoveVoteUpdate(data) {
        if (!data.song_id) return;
        var targetId = String(data.song_id);
        for (var i = 0; i < songList.length; i++) {
            if (String(songList[i].id) === targetId) {
                songList[i].remove_votes = data.remove_votes;
                break;
            }
        }
        renderSongPanel();
    }

    function stopSongPlayback() {
        stopSongProgress();
        // 清空歌词
        lyricsLines = [];
        if ($lyrics) $lyrics.innerHTML = '';
        if (songCurAudio) {
            try { songCurAudio.pause(); } catch (e) { }
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
            .then(function (res) { return res.text(); })
            .then(function (text) { handleLrcResponse(text); })
            .catch(function () {
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
        var lines = [];
        var parts = String(lrcText).split('\n');
        for (var i = 0; i < parts.length; i++) {
            var match = parts[i].match(/\[(\d{2}):(\d{2}(?:\.\d+)?)\](.*)/);
            if (!match) continue;
            var min = parseInt(match[1], 10);
            var sec = parseFloat(match[2]);
            var time = min * 60 + sec;
            var text = match[3].trim();
            if (text) lines.push({ time: time, text: text });
        }
        lines.sort(function (a, b) { return a.time - b.time; });
        return lines;
    }

    /**
     * 根据当前播放秒数更新歌词显示
     */
    function updateLyrics(elapsed) {
        if (!lyricsLines.length || !$lyrics) return;
        var currentLine = '';
        for (var i = lyricsLines.length - 1; i >= 0; i--) {
            if (elapsed >= lyricsLines[i].time) {
                currentLine = lyricsLines[i].text;
                break;
            }
        }
        // 拆分翻译括号：主歌词(翻译) → 两行
        var html = '';
        var transMatch = currentLine.match(/^(.+?)\s*[（(]([^)）]+)[）)]\s*$/);
        if (transMatch) {
            html = '<div class="lyric-line">' + escapeHtml(transMatch[1].trim()) + '</div>' +
                   '<div class="lyric-sub">' + escapeHtml(transMatch[2].trim()) + '</div>';
        } else {
            html = '<div class="lyric-line">' + escapeHtml(currentLine) + '</div>';
        }
        $lyrics.innerHTML = html;
        // 检测溢出，启用滚动动画
        var lines = $lyrics.querySelectorAll('.lyric-line, .lyric-sub');
        for (var j = 0; j < lines.length; j++) {
            if (lines[j].scrollWidth > lines[j].clientWidth) {
                lines[j].style.setProperty('--scroll-distance', (lines[j].scrollWidth - lines[j].clientWidth) + 'px');
                lines[j].classList.add('scrolling');
            }
        }
    }

    function toggleSongPanel() {
        if (!$songPanel) return;
        if ($songPanel.style.display === 'none') {
            $songPanel.style.display = 'flex';
            if ($usersPanel && $usersPanel.style.display !== 'none') {
                $usersPanel.style.display = 'none';
            }
        } else {
            $songPanel.style.display = 'none';
        }
    }

    function renderSongPanel() {
        if (!$songPlaylist) return;
        // 当前播放
        if ($songPlayingInfo) {
            if (songPlaying) {
                $songPlayingInfo.style.display = 'block';
                var elapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                var totalSec = (songPlaying.duration || 0) / 1000;
                var pct = totalSec > 0 ? Math.min(100, Math.max(0, (elapsed / totalSec) * 100)) : 0;
                var nextName = '';
                if (songList.length > 0) {
                    var curIdx = -1;
                    for (var k = 0; k < songList.length; k++) {
                        if (String(songList[k].id) === String(songPlaying.id)) { curIdx = k; break; }
                    }
                    var nextIdx;
                    if (curIdx >= 0 && curIdx + 1 < songList.length) {
                        nextIdx = curIdx + 1;
                    } else if (curIdx === -1) {
                        nextIdx = 0;
                    } else {
                        nextIdx = -1;
                    }
                    if (nextIdx >= 0) {
                        var next = songList[nextIdx];
                        nextName = next.name + (next.artist ? ' - ' + next.artist : '') + ' (' + (next.votes || 0) + '票)';
                    }
                }
                $songPlayingInfo.innerHTML =
                    '<div class="spi-main">' +
                    '<div class="spi-cover-wrap">' +
                    '<img class="spi-cover" src="' + escapeHtmlAttr(songPlaying.picurl || '') + '" alt="" />' +
                    '</div>' +
                    '<div class="spi-body">' +
                    '<div class="spi-header">' + escapeHtml(songPlaying.name) + ' — ' + escapeHtml(songPlaying.artist || '') + '</div>' +
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
        var html = '';
        var removeThreshold = Math.max(2, Math.ceil(onlinePlayerCount / 2));
        // 播放队列
        if (songList.length > 0) {
            html += '<div class="song-section-title">即将播放</div>';
            for (var i = 0; i < songList.length; i++) {
                var s = songList[i];
                var dur = s.duration ? formatDuration(s.duration) : '';
                var isCurrent = songPlaying && String(s.id) === String(songPlaying.id);
                var sIdStr = String(s.id);
                var hasRemoveVoted = removeVotedSongs.has(sIdStr);
                var removeVotes = s.remove_votes || 0;
                html += '<div class="song-item song-item-playlist' + (isCurrent ? ' song-item-current' : ' song-item-clickable') + '"' +
                    (isCurrent ? '' : ' data-play-id="' + escapeHtmlAttr(s.id) + '"') + '>' +
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
                html += '</div>';
            }
        }
        // 投票池
        if (songPool.length > 0) {
            html += '<div class="song-section-title">投票池</div>';
            for (var i = 0; i < songPool.length; i++) {
                var s = songPool[i];
                html += '<div class="song-item">' +
                    '<span class="song-votes">' + (s.votes || 0) + '</span>' +
                    '<span class="song-info">' +
                    '<div class="song-title"><span class="song-title-text">' + escapeHtml(s.name || '') + '</span></div>' +
                    '<div class="song-meta">' + escapeHtml(s.artist || '') + ' · ' + (s.voter_count || 0) + '人已投' + (s.adder ? ' · ' + escapeHtml(s.adder) : '') + '</div>' +
                    '</span>' +
                    '<button class="song-vote-btn" data-song-id="' + escapeHtmlAttr(s.id) + '">投票</button>' +
                    '</div>';
            }
        }
        if (songList.length === 0 && songPool.length === 0) {
            html = '<div style="font-size:11px;color:var(--text-subtle);text-align:center;padding:12px 0;">歌单为空，搜索歌曲来点歌吧</div>';
        }
        $songPlaylist.innerHTML = html;
        // 绑定投票按钮事件
        var btns = $songPlaylist.querySelectorAll('.song-vote-btn');
        for (var j = 0; j < btns.length; j++) {
            btns[j].addEventListener('click', function (e) {
                var id = this.getAttribute('data-song-id');
                if (id) voteSong(id);
            });
        }
        // 绑定移除投票按钮事件（先绑定，且阻止冒泡，避免同时触发播放歌曲点击）
        var removeBtns = $songPlaylist.querySelectorAll('.song-remove-btn');
        for (var r = 0; r < removeBtns.length; r++) {
            removeBtns[r].addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                var id = this.getAttribute('data-remove-id');
                if (id) removeVoteSong(id);
            });
        }
        // 绑定播放队列歌曲点击事件
        var playItems = $songPlaylist.querySelectorAll('.song-item-clickable[data-play-id]');
        for (var k = 0; k < playItems.length; k++) {
            playItems[k].addEventListener('click', function () {
                var id = this.getAttribute('data-play-id');
                if (id) playSongById(id);
            });
        }
        // 检测歌名溢出，溢出时启用滚动动画
        var titles = $songPlaylist.querySelectorAll('.song-title');
        for (var t = 0; t < titles.length; t++) {
            var textEl = titles[t].querySelector('.song-title-text');
            if (textEl && textEl.scrollWidth > titles[t].clientWidth) {
                titles[t].style.setProperty('--scroll-distance', (textEl.scrollWidth - titles[t].clientWidth) + 'px');
                titles[t].classList.add('scrolling');
            }
        }
    }

    // 仅更新移除投票计数显示（人数变化时阈值改变，不重建列表避免打断滚动动画）
    function updateRemoveVoteDisplay() {
        if (!$songPlaylist) return;
        var newThreshold = Math.max(2, Math.ceil(onlinePlayerCount / 2));
        var counts = $songPlaylist.querySelectorAll('.song-remove-count');
        for (var i = 0; i < counts.length; i++) {
            var text = counts[i].textContent || '';
            var votes = text.split('/')[0] || '0';
            counts[i].textContent = votes + '/' + newThreshold;
            counts[i].title = '移除投票 ' + votes + '/' + newThreshold;
        }
    }

    function renderSongSearchResults(results) {
        if (!$songSearchResults) return;
        if (!results || results.length === 0) {
            $songSearchResults.innerHTML = '<div style="font-size:11px;color:var(--text-subtle);text-align:center;padding:8px 0;">未找到歌曲</div>';
            if ($songSearchClear) $songSearchClear.style.display = 'inline-block';
            return;
        }
        var html = '';
        for (var i = 0; i < results.length; i++) {
            var r = results[i];
            html += '<div class="song-search-item" data-song-id="' + escapeHtmlAttr(r.id) + '" data-song-name="' + escapeHtmlAttr(r.name || '') + '" data-song-artist="' + escapeHtmlAttr(r.artist || '') + '">' +
                '<span class="search-item-name">' + escapeHtml(r.name || '') + '</span>' +
                '<span class="search-item-artist">' + escapeHtml(r.artist || '') + '</span>' +
                '</div>';
        }
        $songSearchResults.innerHTML = html;
        // 绑定点击事件
        var items = $songSearchResults.querySelectorAll('.song-search-item');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                var id = this.getAttribute('data-song-id');
                var name = this.getAttribute('data-song-name');
                var artist = this.getAttribute('data-song-artist');
                if (id) requestSong(id, name, artist);
            });
        }
        if ($songSearchClear) $songSearchClear.style.display = 'inline-block';
    }

    function searchSong() {
        if (!$songSearchInput) return;
        var keyword = $songSearchInput.value.trim();
        if (!keyword || keyword.length < 1) {
            showTopToast('请输入歌曲名', true);
            return;
        }
        send({ type: 'lobby_song_search', keyword: keyword });
        $songSearchResults.innerHTML = '<div style="font-size:11px;color:var(--text-subtle);text-align:center;padding:8px 0;">搜索中...</div>';
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
        var idStr = String(songId);
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
        var ud = getUserdata();
        ud.song_listen = songListen;
        saveUserdata(ud);
        if (songListen) {
            // 恢复播放：seek 到正确位置并播放
            if (songPlaying && songCurAudio && songCurAudio.src && songCurAudio.paused) {
                var elapsed = (Date.now() / 1000) - parseFloat(songPlaying.start_time);
                var durSec = songPlaying.duration / 1000;
                if (elapsed > 1 && elapsed < durSec) {
                    songCurAudio.currentTime = elapsed;
                }
                songCurAudio.play().catch(function () { });
            }
        } else {
            // 暂停播放
            if (songCurAudio) {
                try { songCurAudio.pause(); } catch (e) { }
            }
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
    showIdentityState();
    updateNotifyUI();
    if (notifyEnabled && 'Notification' in window && Notification.permission !== 'granted') {
        requestNotifyPermission();
    }

    if (new Date().getDay() === 4) {
        showTopToast('疯狂星期四 V我50', false);

        $chatInput.addEventListener('input', function () {
            var v = $chatInput.value;
            if (v.indexOf('疯狂星期四') !== -1 && v.indexOf('V我50') === -1) {
                $chatInput.value = v.replace('疯狂星期四', '疯狂星期四 V我50');
            }
        });

        document.addEventListener('click', function (e) {
            spawnKfcBurst(e.clientX, e.clientY, 3);
        });
        document.addEventListener('touchend', function (e) {
            var t = e.changedTouches[0];
            if (!t) return;
            spawnKfcBurst(t.clientX, t.clientY, 3);
        });
    }

    function spawnKfcParticle(x, y, delay) {
        var el = document.createElement('div');
        el.className = 'kfc-particle';
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.style.fontSize = (16 + Math.random() * 18) + 'px';
        el.style.setProperty('--dx', (Math.random() * 120 - 60) + 'px');
        el.style.setProperty('--dy', (Math.random() * -140 - 40) + 'px');
        el.style.setProperty('--rot', (Math.random() * 360 - 180) + 'deg');
        el.style.animationDelay = (delay || 0) + 'ms';
        var pool = ['\uD83C\uDF57', '\uD83C\uDF5F', '\uD83E\uDD64', '\uD83C\uDF54', '\uD83C\uDF89'];
        el.textContent = pool[Math.floor(Math.random() * pool.length)];
        document.body.appendChild(el);
        el.addEventListener('animationend', function () { el.remove(); });
    }

    function spawnKfcBurst(x, y, count) {
        for (var i = 0; i < count; i++) {
            spawnKfcParticle(x + (Math.random() * 10 - 5), y + (Math.random() * 10 - 5), Math.random() * 200);
        }
    }

    // 如果处于iframe环境
    if (window.self !== window.top) {
        $header.style.display = 'none';
        $lobbyChatHeader.style.display = 'none';
        $main.style.height = '100vh';
    }

    // ==================== 退出确认 ====================

    // 返回按钮：点击前确认
    $btnBack.addEventListener('click', function () {
        window.location.href = '/';
    });

    // 关闭/刷新标签页：已进入聊天室时拦截
    window.addEventListener('beforeunload', function (e) {
        if ($hasIdentity.style.display !== 'none') {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
