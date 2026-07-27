/**
 * 人类 vs AI 法庭 - 客户端
 * 匹配池 → 连接检查 → 匿名讨论 → 投票淘汰 → 胜负判定
 */
(function () {
    'use strict';

    const WS_URL = 'wss://' + location.hostname + '/ws/WhoisAI';
    const RECONNECT_DELAY = 2000;
    const HEARTBEAT_INTERVAL = 20000;
    const PONG_GRACE = 15000;

    // ==================== DOM ====================
    const $matchPanel = document.getElementById('whoisai-match-panel');
    const $gamePanel = document.getElementById('whoisai-game-panel');
    const $nickname = document.getElementById('whoisai-nickname');
    const $matchBtn = document.getElementById('whoisai-match-btn');
    const $matchBtnAuthed = document.getElementById('whoisai-match-btn-authed');
    const $matchStatus = document.getElementById('whoisai-match-status');
    const $poolList = document.getElementById('whoisai-pool-list');
    const $timer = document.getElementById('whoisai-timer');
    const $round = document.getElementById('whoisai-round');
    const $identityBadge = document.getElementById('whoisai-identity-badge');
    const $playerList = document.getElementById('whoisai-player-list');
    const $aliveCount = document.getElementById('whoisai-alive-count');
    const $messages = document.getElementById('whoisai-messages');
    const $inputArea = document.getElementById('whoisai-input-area');
    const $chatInput = document.getElementById('whoisai-chat-input');
    const $chatSend = document.getElementById('whoisai-chat-send');
    const $votePanel = document.getElementById('whoisai-vote-panel');
    const $voteCandidates = document.getElementById('whoisai-vote-candidates');
    const $voteStatus = document.getElementById('whoisai-vote-status');
    const $endOverlay = document.getElementById('whoisai-end-overlay');
    const $endCard = document.getElementById('whoisai-end-card');
    const $toast = document.getElementById('whoisai-toast');
    const $hasIdentity = document.getElementById('whoisai-has-identity');
    const $noIdentity = document.getElementById('whoisai-no-identity');
    const $fillName = document.getElementById('whoisai-fill-name');
    const $helloNickname = document.getElementById('whoisai-hello-nickname');
    const $btnGoHome = document.getElementById('whoisai-btn-go-home');
    const $btnNewName = document.getElementById('whoisai-btn-new-name');

    // ==================== 状态 ====================
    let ws = null;
    let heartbeatTimer = null;
    let pongTimer = null;
    let reconnectToastId = null;
    let reconnectTimer = null;
    let reconnecting = false;
    let intentionalClose = false;
    let matching = false;
    let inGame = false;
    let roomId = '';
    let mySeat = 0;
    let myIdentity = '';
    let countdownTimer = null;
    let countdownSec = 0;
    let pendingMatchMsg = null;

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
            console.log('[HVA] WebSocket connected');
            if (reconnectToastId) dismissReconnectToast();
            reconnecting = false;
            startHeartbeat();
            if (pendingMatchMsg) {
                send(pendingMatchMsg);
                pendingMatchMsg = null;
            }
        };

        ws.onmessage = function (e) {
            try {
                var data = JSON.parse(e.data);
                dispatch(data);
            } catch (err) {
                console.warn('[HVA] Invalid message', e.data);
            }
        };

        ws.onclose = function () {
            console.log('[HVA] WebSocket closed');
            stopHeartbeat();
            if (!intentionalClose) scheduleReconnect();
            intentionalClose = false;
        };

        ws.onerror = function () {
            console.log('[HVA] WebSocket error');
        };
    }

    function send(data) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(data));
        }
    }

    function closeWs() {
        intentionalClose = true;
        stopHeartbeat();
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        if (ws) {
            ws.onclose = null;
            ws.close();
            ws = null;
        }
    }

    // ==================== 心跳 ====================
    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(function () {
            send({ type: 'ping' });
            if (pongTimer) clearTimeout(pongTimer);
            pongTimer = setTimeout(function () {
                console.log('[HVA] Pong timeout, reconnecting...');
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

        // 对局中断线：尝试重连回房间
        if (inGame && roomId) {
            showReconnectToast('连接已断开，正在重新加入对局...');
        } else {
            showReconnectToast('连接已断开，正在重新连接...');
        }

        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            connect();
        }, RECONNECT_DELAY);
    }

    function showReconnectToast(msg) {
        if (reconnectToastId) dismissReconnectToast();
        reconnectToastId = document.createElement('div');
        reconnectToastId.className = 'announcement-banner';
        reconnectToastId.innerHTML = '<span class="ann-icon">&#9888;</span><span class="ann-text">' + msg + '</span>';
        document.getElementById('announcement-area').appendChild(reconnectToastId);
    }

    function dismissReconnectToast() {
        if (reconnectToastId) {
            var el = reconnectToastId;
            el.style.animation = 'announceOut 0.3s ease forwards';
            el.addEventListener('animationend', function () { el.remove(); }, { once: true });
            reconnectToastId = null;
        }
    }

    // BFCache 恢复
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            console.log('[HVA] Restored from BFCache, reconnecting...');
            closeWs();
            connect();
        }
    });

    // ==================== 消息分发 ====================
    function dispatch(data) {
        switch (data.type) {
            case 'pong':
                if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
                break;

            case 'WhoisAI_connected':
                break;

            case 'system':
                showTopToast(data.text, true);
                if (data.text && data.text.indexOf('活跃连接') !== -1) {
                    // 该设备已有活跃连接，停止重连
                    reconnecting = false;
                    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
                    intentionalClose = true;
                    stopHeartbeat();
                    if (reconnectToastId) dismissReconnectToast();
                    if (ws) {
                        ws.onclose = null;
                        ws.close();
                        ws = null;
                    }
                }
                break;

            case 'WhoisAI_error':
                showTopToast(data.text, true);
                // 昵称/恢复码错误时重置匹配状态
                if (matching && (data.text.indexOf('昵称') !== -1 || data.text.indexOf('恢复码') !== -1)) {
                    onMatchCancelled();
                }
                break;

            case 'WhoisAI_system':
                appendSystemMessage(data.text);
                break;

            case 'WhoisAI_matched':
                onMatched(data);
                // 服务器返回了恢复码则保存
                if (data.recovery_code && myNickname) {
                    setUserNickname(myNickname);
                    setUserRecoveryCode(data.recovery_code);
                    showIdentityState();
                }
                break;

            case 'WhoisAI_match_cancelled':
                onMatchCancelled();
                break;

            case 'WhoisAI_pool_count':
                updatePoolCount(data);
                break;

            case 'WhoisAI_connect_check':
                onConnectCheck(data);
                break;

            case 'WhoisAI_phase_discussion':
                onDiscussionPhase(data);
                break;

            case 'WhoisAI_phase_voting':
                onVotingPhase(data);
                break;

            case 'WhoisAI_message':
                onChatMessage(data);
                break;

            case 'WhoisAI_vote_ok':
                onVoteOk();
                break;

            case 'WhoisAI_vote_progress':
                updateVoteProgress(data);
                break;

            case 'WhoisAI_vote_result':
                onVoteResult(data);
                break;

            case 'WhoisAI_player_list':
                updatePlayerList(data.players);
                break;

            case 'WhoisAI_game_over':
                onGameOver(data);
                break;
        }
    }

    let myNickname = '';

    // ==================== 身份检测与面板切换 ====================
    function showIdentityState() {
        var nickname = getUserNickname();
        var code = getUserRecoveryCode();

        if (nickname && code) {
            // 有身份：直接显示匹配入口
            myNickname = nickname;
            $hasIdentity.style.display = 'block';
            $noIdentity.style.display = 'none';
            $fillName.style.display = 'none';
            $helloNickname.textContent = nickname;
        } else {
            // 无身份
            $hasIdentity.style.display = 'none';
            $noIdentity.style.display = 'block';
            $fillName.style.display = 'none';
        }
    }

    // 返回首页恢复
    $btnGoHome.addEventListener('click', function () {
        location.href = '/';
    });

    // 直接填写昵称
    $btnNewName.addEventListener('click', function () {
        $hasIdentity.style.display = 'none';
        $noIdentity.style.display = 'none';
        $fillName.style.display = 'block';
        $nickname.focus();
    });
    // ==================== 匹配 ====================
    function doMatchRequest(name, recoveryCode) {
        if (matching) {
            onMatchCancelled();
            if (ws && ws.readyState === WebSocket.OPEN) {
                send({ type: 'WhoisAI_cancel_match' });
            } else {
                // WS 已断开：直接关闭旧连接，下次打开自动重连
                if (ws) { ws.onclose = null; ws.close(); ws = null; }
            }
            return;
        }

        matching = true;
        $matchStatus.textContent = '正在匹配中...';
        $matchStatus.className = 'whoisai-match-status info';
        // 同时更新两个按钮状态
        $matchBtn.textContent = '取消匹配';
        $matchBtn.classList.add('danger');
        $matchBtn.classList.remove('success');
        if ($matchBtnAuthed) {
            $matchBtnAuthed.textContent = '取消匹配';
            $matchBtnAuthed.classList.add('danger');
            $matchBtnAuthed.classList.remove('success');
        }
        $nickname.disabled = true;
        var msg = { type: 'WhoisAI_match', nickname: name };
        if (recoveryCode) msg.recovery_code = recoveryCode;

        if (ws && ws.readyState === WebSocket.OPEN) {
            send(msg);
        } else {
            pendingMatchMsg = msg;
            connect();
        }
    }

    // 填写昵称后点击匹配
    $matchBtn.addEventListener('click', function () {
        var name = $nickname.value.trim();
        if (!name) { showTopToast('请输入昵称', true); return; }
        if (name.length < 1 || name.length > 12) { showTopToast('昵称 1~12 个字符', true); return; }
        myNickname = name;
        doMatchRequest(name, '');
    });

    // 有身份时点击匹配
    if ($matchBtnAuthed) {
        $matchBtnAuthed.addEventListener('click', function () {
            var code = getUserRecoveryCode();
            doMatchRequest(myNickname, code);
        });
    }

    function onMatched(data) {
        $matchStatus.textContent = '已加入匹配池' + (data.pool_count ? ' (' + data.pool_count + '人)' : '');
    }

    function onMatchCancelled() {
        matching = false;
        $matchStatus.textContent = '';
        $matchStatus.className = 'whoisai-match-status';
        $matchBtn.textContent = '开始匹配';
        $matchBtn.classList.remove('danger');
        $matchBtn.classList.add('success');
        if ($matchBtnAuthed) {
            $matchBtnAuthed.textContent = '开始匹配';
            $matchBtnAuthed.classList.remove('danger');
            $matchBtnAuthed.classList.add('success');
        }
        $nickname.disabled = false;
        $poolList.innerHTML = '';
    }

    function updatePoolCount(data) {
        var count = data.pool_count;
        if (!count || count <= 1) {
            $poolList.innerHTML = '';
            return;
        }
        $poolList.innerHTML = '<span>' + (count - 1) + ' 人在等待</span>';
    }

    // ==================== 连接检查 ====================
    function onConnectCheck(data) {
        inGame = true;
        roomId = data.room_id;
        mySeat = data.seat;
        myIdentity = data.identity;

        $matchPanel.style.display = 'none';
        $gamePanel.style.display = 'flex';
        $endOverlay.style.display = 'none';

        $round.textContent = '连接检查中...';
        $timer.textContent = '---';
        $identityBadge.textContent = myIdentity === 'ai' ? 'AI' : '人类';
        updatePlayerList(data.players);

        // 添加连接检查系统消息
        appendSystemMessage('正在检查所有玩家连接状态...');

        // 立即回复连接确认
        send({ type: 'WhoisAI_connect_ack', room_id: data.room_id });

        // 清空消息
        $messages.innerHTML = '';
        $inputArea.style.display = 'none';
        $votePanel.style.display = 'none';
    }

    // ==================== 讨论阶段 ====================
    function onDiscussionPhase(data) {
        $round.textContent = '讨论 · 第' + data.round + '轮';
        $identityBadge.textContent = myIdentity === 'ai' ? 'AI' : '人类';

        // 所有玩家都可以发言（bot 无客户端，不会走到这里）
        $inputArea.style.display = 'flex';
        $chatInput.disabled = false;

        $votePanel.style.display = 'none';

        // 发送给旁观者的数据包含完整身份，给自己和人类玩家的是匿名数据
        var players = data.players_full || data.players;
        updatePlayerList(players);

        startCountdown(data.duration || 300, function () {
            $inputArea.style.display = 'none';
            $chatInput.disabled = true;
        });
    }

    // ==================== 投票阶段 ====================
    function onVotingPhase(data) {
        $inputArea.style.display = 'none';
        $chatInput.disabled = true;
        $votePanel.style.display = 'block';
        $round.textContent = '投票 · 第' + data.round + '轮';

        // 只有人类可以投票
        if (myIdentity === 'human') {
            renderVoteCandidates(data.candidates);
            $voteStatus.textContent = '请投票选出你认为的 AI';
        } else {
            $voteStatus.textContent = '投票进行中...';
            $voteCandidates.innerHTML = '';
        }

        // 更新玩家列表
        var players = data.players_full || data.players;
        updatePlayerList(players);

        startCountdown(data.duration || 30, function () {
            // 时间到，禁用投票按钮
            var btns = $voteCandidates.querySelectorAll('.whoisai-vote-btn');
            for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
        });
    }

    function renderVoteCandidates(candidates) {
        $voteCandidates.innerHTML = '';
        if (!candidates) return;

        candidates.forEach(function (c) {
            var btn = document.createElement('button');
            btn.className = 'whoisai-vote-btn';
            btn.textContent = c.name;
            btn.dataset.seat = c.seat;

            btn.addEventListener('click', function () {
                if (btn.disabled) return;

                // 取消之前的选中
                var all = $voteCandidates.querySelectorAll('.whoisai-vote-btn');
                for (var i = 0; i < all.length; i++) all[i].classList.remove('selected');

                btn.classList.add('selected');
                send({ type: 'WhoisAI_vote', target_seat: c.seat });
            });

            $voteCandidates.appendChild(btn);
        });
    }

    function onVoteOk() {
        var btns = $voteCandidates.querySelectorAll('.whoisai-vote-btn');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
        $voteStatus.textContent = '已投票，等待其他玩家...';
    }

    function updateVoteProgress(data) {
        $voteStatus.textContent = '已投票 ' + data.voted_count + '/' + data.alive_count;
    }

    function onVoteResult(data) {
        if (data.eliminated_seat !== undefined && data.eliminated_seat !== null) {
            var name = data.eliminated_name || ('玩家' + data.eliminated_seat);
            var idText = data.identity === 'ai' ? '[AI]' : '[人类]';
            appendSystemMessage('投票结果：' + name + ' 被淘汰 ' + idText);
        } else {
            appendSystemMessage('投票结果：平票，无人被淘汰');
        }

        $votePanel.style.display = 'none';

        // 更新玩家列表
        var players = data.players_full || data.players;
        updatePlayerList(players);
    }

    // ==================== 聊天 ====================
    $chatSend.addEventListener('click', sendChat);
    $chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') sendChat();
    });

    function sendChat() {
        var text = $chatInput.value.trim();
        if (!text) return;
        send({ type: 'WhoisAI_chat', text: text });
        $chatInput.value = '';
    }

    function onChatMessage(data) {
        var div = document.createElement('div');
        div.className = 'whoisai-chat-msg';
        if (data.sender_seat === mySeat) div.classList.add('mine');

        var sender = document.createElement('div');
        sender.className = 'whoisai-chat-sender';
        sender.textContent = data.sender_name || ('玩家' + data.sender_seat);

        var text = document.createElement('div');
        text.className = 'whoisai-chat-text';
        text.textContent = data.text;

        div.appendChild(sender);
        div.appendChild(text);
        $messages.appendChild(div);
        $messages.scrollTop = $messages.scrollHeight;
    }

    function appendSystemMessage(msg) {
        var div = document.createElement('div');
        div.className = 'whoisai-chat-system';
        div.textContent = msg;
        $messages.appendChild(div);
        $messages.scrollTop = $messages.scrollHeight;
    }

    // ==================== 玩家列表 ====================
    function updatePlayerList(players) {
        if (!players) return;
        $playerList.innerHTML = '';

        var aliveCount = 0;
        Object.values(players).forEach(function (p) {
            var item = document.createElement('div');
            item.className = 'whoisai-player-item';
            if (parseInt(p.seat) === mySeat) item.classList.add('you');
            if (p.alive === false || p.eliminated) {
                item.classList.add('dead');
            } else {
                aliveCount++;
            }

            // 状态图标
            var icon = document.createElement('span');
            if (p.alive === false || p.eliminated) {
                icon.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#e74c3c;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            } else {
                icon.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#27ae60;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg>';
            }

            var name = document.createElement('span');
            name.className = 'whoisai-player-name';
            name.textContent = p.name || ('玩家' + p.seat);

            item.appendChild(icon);
            item.appendChild(name);
            $playerList.appendChild(item);
        });

        $aliveCount.textContent = aliveCount;
    }

    // ==================== 倒计时 ====================
    function startCountdown(seconds, onEnd) {
        stopCountdown();
        countdownSec = seconds;
        updateTimerDisplay();

        countdownTimer = setInterval(function () {
            countdownSec--;
            if (countdownSec <= 0) {
                stopCountdown();
                if (onEnd) onEnd();
            }
            updateTimerDisplay();
        }, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    }

    function updateTimerDisplay() {
        var m = Math.floor(countdownSec / 60);
        var s = countdownSec % 60;
        $timer.textContent = m + ':' + (s < 10 ? '0' : '') + s;

        if (countdownSec <= 30) {
            $timer.classList.add('warning');
        } else {
            $timer.classList.remove('warning');
        }
    }

    // ==================== 游戏结束 ====================
    function onGameOver(data) {
        stopCountdown();
        $endOverlay.style.display = 'flex';
        $inputArea.style.display = 'none';
        $votePanel.style.display = 'none';

        // 保存服务器返回的恢复码
        if (data.recovery_code && myNickname) {
            setUserNickname(myNickname);
            setUserRecoveryCode(data.recovery_code);
            showIdentityState();
        }

        var isDisconnect = data.reason === 'disconnect';
        var winner = data.winner === 'human' ? '人类' : 'AI';
        var content = $endCard.querySelector('.paper-content');
        content.innerHTML = '';

        var h2 = document.createElement('h2');
        if (isDisconnect) {
            h2.textContent = '游戏中断';
        } else {
            h2.textContent = winner + '获胜！';
        }
        content.appendChild(h2);

        var text = document.createElement('p');
        text.className = 'whoisai-end-text';
        if (isDisconnect) {
            text.textContent = '因有玩家断线离开，游戏提前结束';
        } else {
            text.textContent = data.winner === 'human' ? '所有 AI 已被找出！' : '人类仅剩最后 1 人...';
        }
        content.appendChild(text);

        // 玩家身份表格
        if (data.players) {
            var table = document.createElement('table');
            table.className = 'whoisai-end-table';

            var thead = document.createElement('thead');
            var headerRow = document.createElement('tr');
            ['昵称', '身份'].forEach(function (h) {
                var th = document.createElement('th');
                th.textContent = h;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            Object.values(data.players).forEach(function (p) {
                var tr = document.createElement('tr');
                tr.className = p.identity === 'ai' ? 'ai' : 'human';

                var tdName = document.createElement('td');
                tdName.textContent = p.nickname || p.name || ('玩家' + p.seat);

                var tdIdentity = document.createElement('td');
                tdIdentity.textContent = p.identity === 'ai' ? 'AI' : '人类';

                tr.appendChild(tdName);
                tr.appendChild(tdIdentity);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);

            content.appendChild(table);
        }

        var btnGroup = document.createElement('div');
        btnGroup.className = 'whoisai-end-btns';

        var retBtn = document.createElement('button');
        retBtn.className = 'whoisai-end-return';
        retBtn.textContent = '返回首页';
        retBtn.addEventListener('click', function () {
            location.href = '/';
        });
        btnGroup.appendChild(retBtn);

        var againBtn = document.createElement('button');
        againBtn.className = 'whoisai-end-return whoisai-end-again';
        againBtn.textContent = '再来一局';
        againBtn.addEventListener('click', function () {
            // 关闭结算面板
            $endOverlay.style.display = 'none';
            $gamePanel.style.display = 'none';
            $messages.innerHTML = '';
            $inputArea.style.display = 'none';
            $votePanel.style.display = 'none';
            $matchPanel.style.display = 'block';

            // 断开旧连接
            if (ws) {
                intentionalClose = true;
                stopHeartbeat();
                ws.onclose = null;
                ws.close();
                ws = null;
            }
            inGame = false;
            roomId = '';
            mySeat = 0;
            myIdentity = '';

            // 重新匹配
            var code = getUserRecoveryCode();
            doMatchRequest(myNickname, code);
        });
        btnGroup.appendChild(againBtn);

        content.appendChild(btnGroup);

        // 查看历史记录按钮（有聊天记录时显示）
        if (data.messages && data.messages.length > 0) {
            var historyBtn = document.createElement('button');
            historyBtn.className = 'whoisai-end-return';
            historyBtn.textContent = '查看历史记录';
            historyBtn.style.cssText = 'margin-top:12px;width:100%;';
            historyBtn.addEventListener('click', function () {
                showHistoryOverlay(data.messages, data.players);
            });
            content.appendChild(historyBtn);
        }

        // 清理游戏状态
        inGame = false;
        roomId = '';
    }

    // ==================== 历史记录弹窗 ====================
    function showHistoryOverlay(messages, players) {
        // 构建玩家名称映射
        var nameMap = {};
        if (players) {
            Object.values(players).forEach(function (p) {
                nameMap[p.seat] = p.nickname || ('玩家' + p.seat);
            });
        }

        // 创建遮罩
        var overlay = document.createElement('div');
        overlay.className = 'whoisai-history-overlay';

        var card = document.createElement('div');
        card.className = 'whoisai-history-card';

        var header = document.createElement('div');
        header.className = 'whoisai-history-header';
        header.innerHTML = '<h3>聊天记录</h3>';
        var closeBtn = document.createElement('button');
        closeBtn.className = 'whoisai-history-close';
        closeBtn.innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';
        closeBtn.addEventListener('click', function () { overlay.remove(); });
        header.appendChild(closeBtn);
        card.appendChild(header);

        var list = document.createElement('div');
        list.className = 'whoisai-history-list';

        if (!messages || messages.length === 0) {
            list.innerHTML = '<div class="whoisai-history-empty">暂无聊天记录</div>';
        } else {
            messages.forEach(function (msg) {
                var row = document.createElement('div');
                row.className = 'whoisai-history-msg';

                var meta = document.createElement('div');
                meta.className = 'whoisai-history-meta';
                var sender = nameMap[msg.sender_seat] || msg.sender_name || ('玩家' + msg.sender_seat);
                meta.textContent = sender + ' · ' + (msg.time || '');

                var text = document.createElement('div');
                text.className = 'whoisai-history-text';
                text.textContent = msg.text;

                row.appendChild(meta);
                row.appendChild(text);
                list.appendChild(row);
            });
        }

        card.appendChild(list);

        // 底部关闭按钮
        var footer = document.createElement('div');
        footer.className = 'whoisai-history-footer';
        var footerBtn = document.createElement('button');
        footerBtn.className = 'whoisai-end-return';
        footerBtn.textContent = '关闭';
        footerBtn.addEventListener('click', function () { overlay.remove(); });
        footer.appendChild(footerBtn);
        card.appendChild(footer);

        overlay.appendChild(card);

        // 点击遮罩关闭
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.remove();
        });

        document.body.appendChild(overlay);
    }

    // ==================== 工具函数 ====================
    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    // ==================== 初始化 ====================
    showIdentityState();
    // 返回首页恢复后回来时，重新检查身份
    window.addEventListener('pageshow', function () {
        showIdentityState();
    });
    // Enter 键快捷（fill-name 状态）
    $nickname.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') $matchBtn.click();
    });
})();
