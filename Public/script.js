// ================================================================
// 传输层基类
// ================================================================
const DebugLogger = { log: function () { }, count: async function () { return 0; }, download: async function () { return { count: 0 }; } };
class ChatTransport {
    constructor() {
        this._handlers = {};
    }

    on(event, handler) {
        if (!this._handlers[event]) {
            this._handlers[event] = [];
        }
        this._handlers[event].push(handler);
    }

    _emit(event, data) {
        const handlers = this._handlers[event];
        if (handlers) {
            handlers.forEach(fn => fn(data));
        }
    }

    connect(nickname) {
        throw new Error('ChatTransport.connect() 必须由子类实现');
    }

    sendMessage(text) {
        throw new Error('ChatTransport.sendMessage() 必须由子类实现');
    }

    sendJudgement(guess, tag) {
        throw new Error('ChatTransport.sendJudgement() 必须由子类实现');
    }

    disconnect() {
        throw new Error('ChatTransport.disconnect() 必须由子类实现');
    }

    /**
     * 发送通用消息（用于非聊天/判定的 WS 消息，如 save_history、report 等）
     */
    send(type, payload) {
        // 子类可覆盖
    }
}

// ================================================================
// WebSocket 传输层（后端对接骨架）
// ================================================================
class WebSocketTransport extends ChatTransport {
    constructor(url) {
        super();
        this._url = url;
        this._ws = null;
        this._heartbeatTimer = null;
        this._reconnectAttempts = 0;
        this._intentionalClose = false;
        this._preventReconnect = false;
        this._lastSessionId = '';
        this._lastPongTime = 0;
    }

    connect(nickname, duration) {
        // connect 方法由下方 WebSocketTransport.prototype.connect 完全覆盖
        // 包括 onopen/onmessage/onerror/onclose 的完整实现
    }

    sendMessage(text) {
        if (this._ws && this._ws.readyState === WebSocket.OPEN) {
            this._ws.send(JSON.stringify({
                type: 'message',
                text: text
            }));
        }
    }

    sendJudgement(guess, tag) {
        if (this._ws && this._ws.readyState === WebSocket.OPEN) {
            this._ws.send(JSON.stringify({
                type: 'judge',
                guess: guess,
                tag: tag || ''
            }));
        }
    }

    disconnect() {
        this._intentionalClose = true;
        this._lastSessionId = '';
        if (this._heartbeatTimer) {
            clearInterval(this._heartbeatTimer);
            this._heartbeatTimer = null;
        }
        if (this._ws && this._ws.readyState === WebSocket.OPEN) {
            this._ws.send(JSON.stringify({ type: 'leave' }));
            this._ws.close();
            this._ws = null;
        }
    }

    send(type, payload = {}) {
        if (!this._ws || this._ws.readyState !== WebSocket.OPEN) {
            throw new Error('WebSocket not connected');
        }
        this._ws.send(JSON.stringify({ type, ...payload }));
    }

    sendLeaveResult(sessionId) {
        if (!sessionId) return;
        DebugLogger.log('game', '发送leave_result', { session_id: sessionId });
        try {
            this.send('leave_result', { session_id: sessionId });
        } catch (e) {
            DebugLogger.log('error', 'leave_result发送失败', { error: e.message });
        }
    }
}

// ================================================================
// 游戏客户端
// ================================================================
class GameClient {
    constructor(transport) {
        this._transport = transport;
        this._nickname = '';
        this._opponentName = '';
        this._opponentTruth = null;
        this._userGuess = null;
        this._judgementAllowed = false;
        this._waitTimer = null;
        this._disconnecting = false;
        this._timedOut = false;
        this._banned = false;
        this._sessionId = '';

        transport.on('connected', (data) => this._onConnected(data));
        transport.on('message', (data) => this._onMessage(data));
        transport.on('system', (data) => this._onSystem(data));
        transport.on('opponent_judged', (data) => this._onOpponentJudged(data));
        transport.on('opponent_timeout', (data) => this._onOpponentTimeout(data));
        transport.on('judge_notify', (data) => this._onJudgeNotify(data));
        transport.on('disconnected', () => this._onDisconnected());
        transport.on('error', (data) => this._onError(data));
        transport.on('banned', (data) => this._onBanned(data));
        transport.on('save_history_status', (data) => this._onSaveHistoryStatus(data));
        transport.on('leave_message_status', (data) => this._onLeaveMessageStatus(data));
        transport.on('share_record_status', (data) => this._onShareRecordStatus(data));
    }

    // ---- 公开方法 ----

    start(nickname, password) {
        if (this._banned) return;

        this._nickname = nickname || 'You';
        this._sessionId = '';

        DebugLogger.log('game', 'GameClient.start', { nickname: this._nickname });

        localStorage.setItem('turing_nickname', this._nickname); // @compat
        setUserNickname(this._nickname);

        landingPage.style.display = 'none';
        matchingPage.style.display = 'flex';
        chatPage.style.display = 'none';
        btnBack.style.display = 'inline-flex';

        logoText.innerHTML = `
                    <svg class="icon" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                    匹配中...
                `;

        const durationSelect = document.getElementById('duration-select');
        const duration = parseInt(durationSelect?.value) || 600;
        DebugLogger.log('match', '开始匹配', { duration: duration, wsState: this._transport._ws ? this._transport._ws.readyState : 'null', online: navigator.onLine, ts: Date.now() });
        window._matchStartTs = Date.now();
        this._transport.reconnect(this._nickname, duration, password);
    }

    sendMessage() {
        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage(text, 'right', this._nickname);
        chatInput.value = '';
        charCount.textContent = '0/300';
        charCount.style.color = 'let(--text-subtle)';
        userMsgCount++;
        updateJudgementState(this._judgementAllowed);

        DebugLogger.log('game', '发送消息', { len: text.length, session_id: this._sessionId });
        this._transport.sendMessage(text);
    }

    makeJudgement(guess) {
        if (this._userGuess !== null) return;
        this._userGuess = guess;

        // 取当前输入的标签
        const tagInput = document.getElementById('tag-input');
        const tag = tagInput ? tagInput.value.trim() : '';

        // 最多给对方 60 秒判定时间，但不超过当前剩余时间
        clearInterval(timerInterval);
        totalSeconds = Math.min(totalSeconds, 60);
        timerDisplay.textContent = formatTime(totalSeconds);
        timerDisplay.classList.remove('urgent');
        timerDisplay.style.color = '';
        timerInterval = setInterval(() => {
            totalSeconds--;
            timerDisplay.textContent = formatTime(totalSeconds);
            if (totalSeconds <= 10) {
                timerDisplay.classList.add('urgent');
                timerDisplay.style.color = 'let(--danger)';
            }
            if (totalSeconds <= 0) {
                clearInterval(timerInterval);
                timerInterval = null;
                timerDisplay.textContent = '00:00';
            }
        }, 1000);

        // 只隐藏判定区，输入区保持可见
        judgementZone.style.display = 'none';

        // 在聊天区底部插入简单提示
        const waitDiv = document.createElement('div');
        waitDiv.id = 'waiting-indicator';
        waitDiv.className = 'sys-msg anim-pop-in';
        waitDiv.innerHTML = '你已锁定判断，等待对方判定中... <span class="waiting-countdown" id="wait-countdown">60</span>s';
        scrollChatToBottom();
        chatBody.appendChild(waitDiv);

        DebugLogger.log('game', '发送判定', { guess: guess, session_id: this._sessionId });
        this._transport.sendJudgement(guess, tag);

        const waitCountdownEl = document.getElementById('wait-countdown');
        let waitSeconds = 60;

        this._waitTimer = setInterval(() => {
            waitSeconds--;
            waitCountdownEl.textContent = waitSeconds;

            if (waitSeconds <= 10) {
                waitCountdownEl.classList.add('urgent');
            }

            if (waitSeconds <= 0) {
                clearInterval(this._waitTimer);
                this._waitTimer = null;
                // 等待服务器权威结果
                const sysDiv = document.createElement('div');
                sysDiv.className = 'sys-msg anim-fade-in';
                sysDiv.textContent = '等待超时，正在获取结果...';
                scrollChatToBottom();
                chatBody.appendChild(sysDiv);
            }
        }, 1000);
    }

    reset() {
        DebugLogger.log('game', 'GameClient.reset调用', { session_id: this._sessionId, disconnecting: this._disconnecting });
        this._disconnecting = true;

        // 隐藏断连覆盖层
        this._hideReconnectOverlay();

        // 标记为主动关闭，禁止自动重连（避免幽灵重连导致首页+聊天页叠加）
        if (this._transport) {
            this._transport._intentionalClose = true;
            this._transport._lastSessionId = '';
        }

        // 移除残留的重连横幅
        const reconnectBanner = document.getElementById('reconnect-banner');
        if (reconnectBanner) reconnectBanner.remove();

        // 通知服务端清理对局状态，连接保持以便下次 join 复用
        if (this._transport && this._transport._ws) {
            try {
                if (this._transport._ws.readyState === WebSocket.OPEN) {
                    this._transport._ws.send(JSON.stringify({ type: 'leave' }));
                }
            } catch (e) { /* ignore */ }
        }

        if (this._waitTimer) {
            clearInterval(this._waitTimer);
            this._waitTimer = null;
        }
        stopChat();

        this._userGuess = null;
        this._opponentTruth = null;
        this._judgementAllowed = false;
        this._timedOut = false;
        this._sessionId = '';
        this._savedHistoryId = 0;

        const waitIndicator = document.getElementById('waiting-indicator');
        if (waitIndicator) waitIndicator.remove();

        const reviewBack = document.getElementById('review-back-btn');
        if (reviewBack) reviewBack.remove();

        chatBody.style.display = '';
        chatInputArea.style.display = '';
        judgementZone.style.display = '';
        resultArea.style.display = 'none';

        chatPage.style.display = 'none';
        matchingPage.style.display = 'none';
        landingPage.style.display = 'flex';
        btnBack.style.display = 'none';
        logoText.innerHTML = origLogoHTML;

        // 隐藏连接状态指示器
        updateConnIndicator('online');

        document.getElementById('system-id').textContent = browserFingerprint;

        this._disconnecting = false;
    }

    resetAndPlay() {
        DebugLogger.log('game', 'resetAndPlay被调用');
        // 发送离开确认，等另一方也离开后房间自动清理
        if (this._sessionId) {
            this._transport.sendLeaveResult(this._sessionId);
        }
        const nickname = getUserNickname() || 'You';
        const durationSelect = document.getElementById('duration-select');
        const duration = parseInt(durationSelect?.value) || 600;
        this._nickname = nickname;
        this._sessionId = '';

        // 清除重连用的旧 session ID，避免 connect() 的 onopen 发送旧 reconnect_session_id
        if (this._transport) {
            this._transport._lastSessionId = '';
        }

        // 立即清空聊天区 DOM，防止上局消息在新匹配到来前闪现
        chatBody.innerHTML = '';

        // 清理 UI 和本地状态（复用已有连接，不关闭 WS）
        this._disconnecting = true;
        if (this._waitTimer) {
            clearInterval(this._waitTimer);
            this._waitTimer = null;
        }
        stopChat();
        this._userGuess = null;
        this._opponentTruth = null;
        this._judgementAllowed = false;
        this._timedOut = false;

        const waitIndicator = document.getElementById('waiting-indicator');
        if (waitIndicator) waitIndicator.remove();
        const reviewBack = document.getElementById('review-back-btn');
        if (reviewBack) reviewBack.remove();
        chatBody.style.display = '';
        chatInputArea.style.display = '';
        judgementZone.style.display = '';
        resultArea.style.display = 'none';

        // 显示匹配页面
        chatPage.style.display = 'none';
        matchingPage.style.display = 'flex';
        landingPage.style.display = 'none';
        btnBack.style.display = 'inline-flex';
        logoText.innerHTML = `
            <svg class="icon" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
            匹配中...
        `;
        document.getElementById('system-id').textContent = browserFingerprint;
        this._disconnecting = false;

        // 复用已有连接，服务端 handleJoin 会自动清理旧对局状态
        this._transport.reconnect(nickname, duration);
    }

    // ---- 事件处理器 ----

    _onConnected(data) {
        // 玩家未在匹配页时忽略（后台重连触发的不期望 matched 事件）
        if (matchingPage.style.display !== 'flex') {
            DebugLogger.log('ws', '收到matched但不在匹配页，忽略', { currentPage: matchingPage.style.display === 'none' ? (chatPage.style.display === 'flex' ? 'chat' : 'result') : 'matching' });
            return;
        }

        this._opponentName = data.opponent_name;
        this._duration = data.duration || 600;
        this._sessionId = data.session_id || '';

        DebugLogger.log('game', '对局开始', { opponent: this._opponentName, session_id: this._sessionId, duration: this._duration });

        const infoDiv = document.querySelector('.opponent-info > div:nth-of-type(2)');
        if (infoDiv) {
            infoDiv.innerHTML = `
                        <div style="font-size: 12px; color: let(--text-subtle);">当前对手</div>
                        <strong style="font-size: 18px;">???</strong>
                    `;
        }

        matchingPage.style.display = 'none';
        landingPage.style.display = 'none';
        resultArea.style.display = 'none';
        chatPage.style.display = 'flex';

        // 显示连接状态指示器
        updateConnIndicator('online');

        // 清除上局残留的标签
        const tagInput = document.getElementById('tag-input');
        if (tagInput) tagInput.value = '';

        logoText.innerHTML = `
                    <svg class="icon" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                    更好的图灵测试在线小游戏
                `;

        this._startChat();
    }

    _startChat() {
        DebugLogger.log('game', '进入聊天阶段', { session_id: this._sessionId });
        // 直接清空，不保留旧的 .sys-msg 元素（避免上局系统消息回流）
        chatBody.innerHTML = '';

        // 互发消息规则提示
        const ruleDiv = document.createElement('div');
        ruleDiv.className = 'sys-msg anim-fade-in';
        ruleDiv.innerHTML = `
            <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:let(--ink-blue);stroke-width:2;flex-shrink:0;">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="16" x2="12" y2="12" />
                <line x1="12" y1="8" x2="12.01" y2="8" />
            </svg>
            双方需60秒内互发至少一条消息，否则判为平局不记战绩
        `;
        chatBody.appendChild(ruleDiv);

        userMsgCount = 0;
        botMsgCount = 0;
        totalSeconds = this._duration || 600;
        gameStartTime = Date.now();
        timerDisplay.textContent = formatTime(totalSeconds);
        timerDisplay.classList.remove('urgent');
        timerDisplay.style.color = '';

        this._judgementAllowed = false;
        updateJudgementState(false);

        setTimeout(() => {
            this._judgementAllowed = true;
            updateJudgementState(true);
        }, 10000);

        if (timerInterval) clearInterval(timerInterval);

        timerInterval = setInterval(() => {
            totalSeconds--;
            timerDisplay.textContent = formatTime(totalSeconds);

            if (totalSeconds <= 60) {
                timerDisplay.classList.add('urgent');
            }
            if (totalSeconds <= 10) {
                timerDisplay.style.color = 'let(--danger)';
            }

            if (totalSeconds <= 0) {
                clearInterval(timerInterval);
                timerDisplay.textContent = '00:00';
                chatInput.disabled = true;
                btnSend.disabled = true;
            }
        }, 1000);
    }

    _onMessage(data) {
        // 不在聊天页时忽略消息（可能是上局残留或重连过程中的旧消息）
        if (chatPage.style.display !== 'flex') return;
        appendMessage(data.text, 'left', data.sender);
        botMsgCount++;
        updateJudgementState(this._judgementAllowed);
    }

    _onSystem(data) {
        const sysDiv = document.createElement('div');
        sysDiv.className = 'sys-msg anim-fade-in';
        sysDiv.textContent = data.text;
        scrollChatToBottom();
        chatBody.appendChild(sysDiv);

        // 聊天时间到 → 启用判定按钮 + 开始判定倒计时
        if (data.text && data.text.includes('聊天时间到')) {
            this._judgementAllowed = true;
            updateJudgementState(true);

            clearInterval(timerInterval);
            totalSeconds = 60;
            timerDisplay.textContent = formatTime(totalSeconds);
            timerDisplay.classList.remove('urgent');
            timerDisplay.style.color = '';
            timerInterval = setInterval(() => {
                totalSeconds--;
                timerDisplay.textContent = formatTime(totalSeconds);
                if (totalSeconds <= 10) {
                    timerDisplay.classList.add('urgent');
                    timerDisplay.style.color = 'let(--danger)';
                }
                if (totalSeconds <= 0) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
            }, 1000);
        }
    }

    _onJudgeNotify(data) {
        // 已提交判定的玩家不展示对方已判定的通知（避免等待倒计时和通知同时显示）
        if (this._userGuess !== null) return;

        const notifyDiv = document.createElement('div');
        notifyDiv.className = 'sys-msg anim-pop-in';
        notifyDiv.style.color = 'let(--danger)';
        notifyDiv.style.fontWeight = 'bold';
        notifyDiv.style.fontStyle = 'normal';
        notifyDiv.textContent = '⚠ ' + data.message;
        scrollChatToBottom();
        chatBody.appendChild(notifyDiv);

        // 重启定时器为判定倒计时
        if (data.seconds_remaining) {
            clearInterval(timerInterval);
            totalSeconds = data.seconds_remaining;
            timerDisplay.textContent = formatTime(totalSeconds);
            timerDisplay.classList.remove('urgent');
            timerInterval = setInterval(() => {
                totalSeconds--;
                timerDisplay.textContent = formatTime(totalSeconds);
                if (totalSeconds <= 10) {
                    timerDisplay.classList.add('urgent');
                }
                if (totalSeconds <= 0) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
            }, 1000);
        }

        this._judgementAllowed = true;
        updateJudgementState(true);
    }

    _onOpponentJudged(data) {
        // 结果已经展示过，忽略重复消息
        if (this._opponentTruth !== null) return;
        // 不在游戏页面时忽略，防止 reset() 后缓存的 judged 消息造成页面叠加
        if (landingPage.style.display === 'flex') return;

        if (this._waitTimer) {
            clearInterval(this._waitTimer);
            this._waitTimer = null;
        }
        // 停止判定倒计时，双方都已判定，恢复聊天
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        this._opponentTruth = data.truth;
        this._sessionId = data.session_id || '';
        DebugLogger.log('game', '对局结束-对方已判定', { myGuess: this._userGuess, oppTruth: this._opponentTruth, session_id: this._sessionId });
        renderResult(false, this._userGuess, this._opponentTruth, data.opponent_guess, data.opponent_tag, data.opponent_name || this._opponentName);
    }

    _onOpponentTimeout(data) {
        DebugLogger.log('game', '超时事件', { reason: data.reason, session_id: data.session_id });
        // 不在游戏页面时忽略，防止 reset() 后缓存的消息造成页面叠加
        if (landingPage.style.display === 'flex') return;
        if (data && data.reason === 'chat_expired') {
            clearInterval(timerInterval);
            timerInterval = null;
            timerDisplay.textContent = '00:00';
            chatInput.disabled = true;
            btnSend.disabled = true;
            return;
        }
        // 防止重复触发
        if (this._timedOut) return;
        this._timedOut = true;

        // 结果已经展示过（比如对方已判定），忽略
        if (this._opponentTruth !== null) return;
        if (this._waitTimer) {
            clearInterval(this._waitTimer);
            this._waitTimer = null;
        }

        this._sessionId = data.session_id || '';

        if (data && data.reason === 'you_timeout') {
            // 自己超时，使用服务端返回的对方身份
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult('you', this._userGuess, this._opponentTruth, null, '', data.opponent_name || this._opponentName);
        } else if (data && data.reason === 'both_timeout') {
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult('both', this._userGuess, this._opponentTruth, null, '', data.opponent_name || this._opponentName);
        } else if (data && data.reason === 'no_mutual_chat') {
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult('no_mutual_chat', this._userGuess, this._opponentTruth, null, '', data.opponent_name || this._opponentName);
        } else if (data && data.reason) {
            // opponent_timeout / opponent_disconnected / opponent_left
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult(data.reason, this._userGuess, this._opponentTruth, null, '', data.opponent_name || this._opponentName);
        }
    }

    _onDisconnected(data) {
        DebugLogger.log('ws', '客户端收到disconnected事件', data || {});
        if (this._disconnecting) return;

        // 自动重连中，显示提示而不 reset
        if (data && data.reconnecting) {
            if (chatPage.style.display === 'flex') {
                // 移除旧 banner（如果 overlay 还未显示则由 onclose 负责显示）
                const existing = document.getElementById('reconnect-banner');
                if (existing) existing.remove();
            }
            return;
        }

        // 对局中非主动断连 → 显示覆盖层让用户决定
        if (chatPage.style.display === 'flex' && !this._transport._preventReconnect) {
            this._showReconnectOverlay('disconnected');
        }
    }

    _onError(data) {
        DebugLogger.log('error', '传输层错误', { text: data.text });
        console.error('传输层错误:', data.text);
        // 匹配阶段：显示在匹配页
        if (matchingPage.style.display === 'flex') {
            showMatchError(data.text || '连接出错');
        } else if (chatPage.style.display === 'flex') {
            // 对局中：用 toast 提示，不弹 alert 打断游戏
            showTopToast(data.text || '连接出错，正在重试...', true);
        } else {
            alert('连接出错，请刷新页面重试');
        }
    }

    _onBanned(data) {
        this._banned = true;
        this._disconnecting = true;
        // 回到首页并显示封禁提示
        this.reset();
        this._disconnecting = false;

        // 禁用开始按钮
        btnStart.disabled = true;
        btnStart.style.opacity = '0.4';
        btnStart.style.cursor = 'not-allowed';
        btnStart.textContent = '已被封禁';

        // 禁用昵称输入
        nicknameInput.disabled = true;
        nicknameInput.placeholder = '您已被管理员封禁';

        // 显示封禁横幅
        const existingBanner = document.getElementById('ban-banner');
        if (!existingBanner) {
            const banner = document.createElement('div');
            banner.id = 'ban-banner';
            banner.className = 'doodle-border';
            banner.style.cssText = 'padding:14px 20px;margin-bottom:16px;background:let(--danger-light);color:let(--danger-dark);font-size:15px;font-weight:bold;text-align:center;animation:wiggle 0.3s ease;';
            banner.innerHTML = `
                <svg class="icon" viewBox="0 0 24 24" style="width:18px;height:18px;vertical-align:-4px;">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                ${data.message}
            `;
            landingPage.insertBefore(banner, landingPage.firstChild);
        }
    }

    // ---- 断连兜底覆盖层管理 ----

    _showReconnectOverlay(mode) {
        const overlay = document.getElementById('reconnect-overlay');
        const title = document.getElementById('reconnect-title');
        const desc = document.getElementById('reconnect-desc');
        const retryBtn = document.getElementById('btn-reconnect-retry');

        if (!overlay) return;

        if (mode === 'reconnecting') {
            title.textContent = '连接已断开';
            desc.textContent = '正在自动重连，请稍候...';
        } else {
            title.textContent = '连接已断开';
            desc.textContent = '自动重连失败，请手动重试';
            // 所有进度点标红
            const dots = document.getElementById('reconnect-dots');
            if (dots) {
                dots.querySelectorAll('.reconnect-dot').forEach(d => { d.className = 'reconnect-dot fail'; });
            }
        }

        overlay.style.display = 'flex';
    }

    _hideReconnectOverlay() {
        const overlay = document.getElementById('reconnect-overlay');
        if (overlay) overlay.style.display = 'none';

        // 同时清理旧的 reconnect-banner
        const banner = document.getElementById('reconnect-banner');
        if (banner) banner.remove();
    }

    _updateReconnectProgress(attempt, maxDots) {
        const dots = document.getElementById('reconnect-dots');
        if (!dots) return;
        const dotEls = dots.querySelectorAll('.reconnect-dot');
        maxDots = maxDots || dotEls.length;

        for (let i = 0; i < dotEls.length; i++) {
            if (i < attempt - 1) dotEls[i].className = 'reconnect-dot done';
            else if (i === attempt - 1) dotEls[i].className = 'reconnect-dot active';
            else dotEls[i].className = 'reconnect-dot';
        }
    }

    _onSaveHistoryStatus(data) {
        const btnSave = document.getElementById('btn-save-history');
        const statusEl = document.getElementById('save-history-status');
        statusEl.style.display = 'block';
        if (data.success) {
            statusEl.style.color = 'let(--success)';
            statusEl.textContent = data.message || '聊天记录已保存';
            if (btnSave) btnSave.style.display = 'none';
            if (data.id) {
                game._savedHistoryId = data.id;
                const collectionArea = document.getElementById('collection-area');
                if (collectionArea) collectionArea.style.display = 'block';
            }
        } else {
            statusEl.style.color = 'let(--danger)';
            statusEl.textContent = data.message || '保存失败';
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.textContent = '保存聊天记录';
            }
        }
    }

    _onLeaveMessageStatus(data) {
        const area = document.getElementById('leave-message-area');
        const statusEl = document.getElementById('leave-message-status');
        if (data.success) {
            if (area) area.innerHTML = '<div style="text-align:center;font-size:12px;color:let(--success);">留言已发送</div>';
        } else {
            statusEl.style.display = 'block';
            statusEl.style.color = 'let(--danger)';
            statusEl.textContent = data.message || '发送失败';
            const btn = document.getElementById('btn-leave-message');
            if (btn) {
                btn.disabled = false;
                btn.textContent = '发送留言';
            }
        }
    }

    _onShareRecordStatus(data) {
        showTopToast(data.message || (data.success ? '战绩卡片已分享到聊天室' : '分享失败，请重试'), !data.success);
    }
}

const landingPage = document.getElementById('landing-page');
const matchingPage = document.getElementById('matching-page');
const chatPage = document.getElementById('chat-page');
const profilePage = document.getElementById('profile-page');
const btnStart = document.getElementById('btn-start');
const btnBack = document.getElementById('btn-back');
const logoText = document.querySelector('.logo-text');

const settingsOverlay = document.getElementById('settings-overlay');
const btnSettings = document.getElementById('btn-settings');
const btnCloseSettings = document.getElementById('btn-close-settings');
const changelogOverlay = document.getElementById('changelog-overlay');
const btnChangelog = document.getElementById('btn-changelog');
const btnCloseChangelog = document.getElementById('btn-close-changelog');
const btnClearLocalData = document.getElementById('btn-clear-local-data');
const btnUploadUserData = document.getElementById('btn-upload-userdata');
// 对局内表情选择器
const btnStickerPicker = document.getElementById('btn-sticker-picker');
const stickerPicker = document.getElementById('sticker-picker');
const stickerPickerBody = document.getElementById('sticker-picker-body');
const btnCloseStickerPicker = document.getElementById('btn-close-sticker-picker');
// 表情列表（WS 连接后从服务端获取，id → {name, url}）
let stickerMap = loadStickerCache();
bindStickerPickerTabs('sticker-picker', renderStickerPicker, repositionStickerPicker);

const origLogoHTML = logoText.innerHTML;

// ================================================================
// 浏览器指纹
// ================================================================
let browserFingerprint = getFingerprint();

// FingerprintJS 完成初始化后更新全局指纹变量及 UI
window.onFingerprintReady = function (fp) {
    browserFingerprint = fp;
    let sysId = document.getElementById('system-id');
    if (sysId) sysId.textContent = fp;
    let idFp = document.getElementById('id-card-fingerprint');
    if (idFp) idFp.textContent = fp;
};

const nicknameInput = document.getElementById('nickname-input');
// 迁移旧存储到统一的 userdata 结构（临时，下个版本移除）
// USERDATA_KEY 已在 shared.js 中声明
let _chatHistoryPage = 1;
migrateLegacyData();

// 自动将旧格式用户数据（recovery_code → token）升级为新格式
autoUpgradeOldUserdata();

const savedNickname = getUserNickname();
if (savedNickname) {
    nicknameInput.value = savedNickname;

    // 非首次访问：显示只读 ID 卡，隐藏昵称输入、系统编号行
    const inputLine = document.getElementById('nickname-input-line');
    const systemIdLine = document.getElementById('system-id-line');
    const idCardDisplay = document.getElementById('id-card-display');

    if (inputLine) inputLine.style.display = 'none';
    if (systemIdLine) systemIdLine.style.display = 'none';

    // 显示 ID 卡展示区
    if (idCardDisplay) {
        idCardDisplay.style.display = 'block';
        document.getElementById('id-card-nickname').textContent = savedNickname;
        document.getElementById('id-card-fingerprint').textContent = browserFingerprint;
    }

    // 有 token 时隐藏密码输入和找回区
    const recoverLine = document.getElementById('recover-line');
    if (recoverLine && getUserToken()) {
        recoverLine.style.display = 'none';
    }
} else {
    // 首次访问：显示密码输入框
    const passwordLine = document.getElementById('password-input-line');
    if (passwordLine) passwordLine.style.display = '';
}

// 首页密码找回入口
document.getElementById('btn-recover-main').addEventListener('click', () => {
    const input = document.getElementById('recover-input-main');
    const password = input.value.trim();
    if (!password || password.length < 6) { showTopToast('请输入密码（6位以上）'); return; }
    const nickname = nicknameInput.value.trim();
    if (!nickname) { showTopToast('请先填写昵称'); return; }
    document.getElementById('btn-recover-main').disabled = true;
    fetch('/api/generate-player-id?action=recover&nickname=' + encodeURIComponent(nickname) + '&password=' + encodeURIComponent(password) + '&fp=' + encodeURIComponent(browserFingerprint))
            .then(r => r.json())
            .then(data => {
                document.getElementById('btn-recover-main').disabled = false;
                if (data.error) { showTopToast(data.error); return; }
            if (data.token) setUserToken(data.token);
            updateLbUI();
            if (data.stats) { updateLbMyStats(data.stats); mergeServerStats(data.stats); }
            input.value = '';
            showTopToast('账号已找回！', false);

            // 切换到 ID 卡展示模式（与页面加载时有数据时一致）
            setUserNickname(nickname);
            const nLine = document.getElementById('nickname-input-line');
            const sLine = document.getElementById('system-id-line');
            const idCardDisp = document.getElementById('id-card-display');
            if (nLine) nLine.style.display = 'none';
            if (sLine) sLine.style.display = 'none';
            if (idCardDisp) {
                idCardDisp.style.display = 'block';
                document.getElementById('id-card-nickname').textContent = nickname;
                document.getElementById('id-card-fingerprint').textContent = browserFingerprint;
            }

            // 找回成功后隐藏找回区和密码输入
            const recLine = document.getElementById('recover-line');
            const pwdLine = document.getElementById('password-input-line');
            if (recLine) recLine.style.display = 'none';
            if (pwdLine) pwdLine.style.display = 'none';
        })
        .catch(() => { showTopToast('网络错误，请稍后重试'); document.getElementById('btn-recover-main').disabled = false; });
});

document.getElementById('recover-input-main').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') document.getElementById('btn-recover-main').click();
});

// 昵称修改（每月一次限制）
document.getElementById('btn-edit-nickname').addEventListener('click', () => {
    const now = new Date();
    const currentYM = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    const lastUpdate = getUserNicknameUpdatedAt();

    if (lastUpdate === currentYM) {
        showTopToast('本月已修改过昵称，每月仅可修改一次');
        return;
    }

    const newNick = prompt('输入新昵称（每月限改一次，当前：' + getUserNickname() + '）', getUserNickname());
    if (!newNick || !newNick.trim()) return;
    const trimmed = newNick.trim();
    if (trimmed.length > 16) {
        showTopToast('昵称不能超过16个字符');
        return;
    }
    if (trimmed === getUserNickname()) return;

    // 如果有恢复码，通过 WS 同步更新到后端，等待响应后再决定是否本地更新
    const tok = getUserToken();
    if (tok) {
        try {
            const onResult = (e) => {
                document.removeEventListener('nickname_update_result', onResult);
                if (e.detail.error) {
                    showTopToast('昵称更新失败：' + e.detail.error);
                    return;
                }
                setUserNickname(trimmed);
                setUserNicknameUpdatedAt(currentYM);
                document.getElementById('id-card-nickname').textContent = trimmed;
                document.getElementById('nickname-input').value = trimmed;
                showTopToast('昵称已修改为：' + trimmed, false);
            };
            document.addEventListener('nickname_update_result', onResult);
            transport.send('update_nickname', { nickname: trimmed, fp: browserFingerprint });
            return;
        } catch (e) {
            // WS 未连接时静默降级，仅更新本地
        }
    }

    setUserNickname(trimmed);
    setUserNicknameUpdatedAt(currentYM);
    document.getElementById('id-card-nickname').textContent = trimmed;
    document.getElementById('nickname-input').value = trimmed;
    showTopToast('昵称已修改为：' + trimmed, false);
});

btnStart.disabled = true;
btnStart.textContent = '初始化中...';
btnStart.addEventListener('click', startMatching);

// 用户协议弹窗
const agreementOverlay = document.getElementById('agreement-overlay');
const agreementLink = document.getElementById('agreement-link');
const btnCloseAgreement = document.getElementById('btn-close-agreement');
const btnAgree = document.getElementById('btn-agree');

agreementLink.addEventListener('click', () => {
    agreementOverlay.style.display = 'flex';
});

btnCloseAgreement.addEventListener('click', () => {
    agreementOverlay.style.display = 'none';
});

agreementOverlay.addEventListener('click', (e) => {
    if (e.target === agreementOverlay) {
        agreementOverlay.style.display = 'none';
    }
});

btnAgree.addEventListener('click', () => {
    agreementOverlay.style.display = 'none';
    localStorage.setItem('turing_agreed', '1');
});

function generateId() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let id = '';
    for (let i = 0; i < 5; i++) {
        id += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return id;
}
document.getElementById('system-id').textContent = browserFingerprint;

function startMatching() {
    // 资料页/收藏页返回首页后首次点“开始匹配”时，这里懒创建游戏客户端
    ensureGameClient();
    if (!game) {
        alert('系统正在初始化，请稍后再试...');
        return;
    }
    const nickname = nicknameInput.value.trim() || 'You';
    const passwordInput = document.getElementById('password-input');
    const password = passwordInput ? passwordInput.value : '';
    game.start(nickname, password);
}

const chatBody = document.getElementById('chat-body');
const chatInput = document.getElementById('chat-input');
const charCount = document.getElementById('char-count');
const btnSend = document.getElementById('btn-send');
const timerDisplay = document.getElementById('timer-display');

let timerInterval = null;
let totalSeconds = 600;
let gameStartTime = 0;

let userMsgCount = 0;
let botMsgCount = 0;

// 预加载音效
const thinkAudio = new Audio('https://yuju.99kpk.top:81/pan/1335/a682b90cd2276dc18a2729bd60ba23ca.mp3');
thinkAudio.preload = 'auto';

function getNickname() {
    return getUserNickname() || 'You';
}

function formatTime(s) {
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
}

function timeAgoText(date) {
    const diff = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    const m = date.getMonth() + 1;
    const d = date.getDate();
    return m + '/' + d;
}

function escapeHtml(str) {
    return ('' + str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeHtmlAttr(str) {
    return ('' + str).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

/**
 * 按点号分隔的路径解析对象值（如 data.image.url）
 * @param {Object} obj
 * @param {string} path 如 'data.url'
 * @returns {*} 路径对应的值，不存在返回 undefined
 */
function resolvePath(obj, path) {
    if (!obj || !path) return undefined;
    return path.split('.').reduce((cur, key) => (cur != null ? cur[key] : undefined), obj);
}

/**
 * 自动滚动到聊天底部。
 * 先同步检查用户是否已在底部（基于当前 scrollHeight），若是则在下一帧滚动。
 * 注意：必须在 appendChild 之前调用，否则 scrollHeight 已包含新内容会导致判断失准。
 */
function scrollChatToBottom() {
    const threshold = 50;
    const atBottom = chatBody.scrollTop + chatBody.clientHeight >= chatBody.scrollHeight - threshold;
    if (atBottom) {
        requestAnimationFrame(() => {
            chatBody.scrollTop = chatBody.scrollHeight;
        });
    }
}

function appendMessage(text, side, sender) {
    const bubble = document.createElement('div');
    bubble.className = 'bubble ' + (side === 'right' ? 'bubble-right anim-slide-right' : 'bubble-left anim-slide-left');

    const now = new Date();
    const ts = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');

    bubble.innerHTML = `
                <div class="bubble-info">${escapeHtml(sender)} (${ts})</div>
                <div style="font-size: 18px;">${escapeHtml(text)}</div>
            `;

    scrollChatToBottom();
    chatBody.appendChild(bubble);

    // 🤔🤔🤔
    if (text.includes('🤔')) {
        const soundToggle = document.getElementById('cb-sound-effect');
        if (soundToggle && soundToggle.classList.contains('active')) {
            thinkAudio.currentTime = 0;
            thinkAudio.play().catch(() => { });
        }
    }
}

/** 渲染表情到聊天区 */
function appendSticker(stickerId, stickerName, side, sender, stickerUrl) {
    const url = resolveStickerUrl(stickerId, stickerUrl, stickerMap);

    const bubble = document.createElement('div');
    bubble.className = 'bubble bubble-sticker ' + (side === 'right' ? 'bubble-right anim-slide-right' : 'bubble-left anim-slide-left');

    const now = new Date();
    const ts = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');

    if (!url) {
        bubble.innerHTML = `
                <div class="bubble-info">${escapeHtml(sender)} (${ts})</div>
                <span style="color:#999;font-style:italic;">[表情不存在: ${escapeHtml(stickerName || stickerId)}]</span>
            `;
    } else {
        bubble.innerHTML = `
                <div class="bubble-info">${escapeHtml(sender)} (${ts})</div>
                <img src="${escapeHtmlAttr(url)}" alt="${escapeHtmlAttr(stickerName)}" class="sticker-msg-img" loading="lazy">
            `;
    }

    scrollChatToBottom();
    chatBody.appendChild(bubble);
}

/** 发送表情 */
function sendSticker(stickerId, stickerData) {
    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) return;
    transport._ws.send(JSON.stringify({
        type: 'sticker',
        id: stickerId
    }));
    // 立即本地渲染（用点击时传入的完整数据，不依赖 stickerMap 缓存状态）
    let st = stickerData || (stickerMap[stickerId] || null);
    if (st) {
        appendSticker(stickerId, st.name, 'right', getNickname(), st.url);
    }
    userMsgCount++;
    updateJudgementState(game._judgementAllowed);
    // 关闭表情选择器
    const picker = document.getElementById('sticker-picker');
    if (picker) picker.style.display = 'none';
}

function sendMessage() {
    game.sendMessage();
}

function stopChat() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
    chatInput.value = '';
    chatInput.disabled = false;
    btnSend.disabled = false;
}

const judgementZone = document.getElementById('judgement-zone');
const resultArea = document.getElementById('result-area');
const chatInputArea = document.querySelector('.chat-input-area');

function updateJudgementState(judgementAllowed) {
    const btnHuman = document.getElementById('btn-judge-human');
    const btnAi = document.getElementById('btn-judge-ai');
    const hint = document.getElementById('judgement-hint');

    const wasDisabled = btnHuman.disabled;
    const canJudge = judgementAllowed && userMsgCount >= 1;

    btnHuman.disabled = !canJudge;
    btnAi.disabled = !canJudge;

    if (wasDisabled && canJudge) {
        btnHuman.classList.add('judgement-ready');
        btnAi.classList.add('judgement-ready');
        setTimeout(() => {
            btnHuman.classList.remove('judgement-ready');
            btnAi.classList.remove('judgement-ready');
        }, 600);
    }

    if (!canJudge) {
        const reasons = [];
        if (!judgementAllowed) reasons.push('开局 10 秒后');
        if (userMsgCount < 1) reasons.push('你发送一条消息');
        hint.textContent = reasons.join(' / ') + ' 即可判定';
    } else {
        hint.textContent = '可以锁定你的答案了';
    }
}

function makeJudgement(guess) {
    game.makeJudgement(guess);
}

function renderResult(timeoutReason, userGuess, opponentTruth, opponentGuess, opponentTag, opponentName) {
    const isTimeout = !!timeoutReason;
    const isWin = (timeoutReason === 'opponent' || timeoutReason === 'opponent_timeout')
        || (timeoutReason === 'opponent_disconnected' || timeoutReason === 'opponent_left')
        || (!isTimeout && userGuess === opponentTruth);

    const guessLabel = userGuess === 'human' ? '它是人类' : (userGuess === 'ai' ? '它是 AI' : '未判定');
    const truthLabel = opponentTruth === 'human' ? '人类' : (opponentTruth === 'ai' ? 'AI' : '未知');
    const opponentGuessLabel = opponentGuess
        ? (opponentGuess === 'human' ? '人类' : 'AI')
        : '未判定';
    opponentTag = opponentTag || '';
    opponentName = opponentName || '';

    const iconSVG = isWin
        ? `<svg viewBox="0 0 24 24" style="width:48px;height:48px;fill:none;stroke:#4caf50;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg>`
        : `<svg viewBox="0 0 24 24" style="width:48px;height:48px;fill:none;stroke:#f44336;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;"><circle cx="12" cy="12" r="10"/><line x1="8" y1="8" x2="16" y2="16"/><line x1="16" y1="8" x2="8" y2="16"/></svg>`;

    const verdict = timeoutReason === 'opponent' || timeoutReason === 'opponent_timeout' ? '对方超时未判定，你赢了！'
        : timeoutReason === 'opponent_disconnected' ? '对方断开了连接，你赢了！'
            : timeoutReason === 'opponent_left' ? '对方主动退出，你赢了！'
                : timeoutReason === 'you' ? '你超时未判定，对方赢了...'
                    : timeoutReason === 'both' ? '双方超时，平局'
                        : timeoutReason === 'no_mutual_chat' ? '未互发消息，平局不记战绩'
                            : timeoutReason === 'opponent_banned' ? '对方已被封禁，对局结束'
                                : (isWin ? '猜对啦！' : '猜错了...');
    const reveal = isTimeout
        ? (timeoutReason === 'opponent' || timeoutReason === 'opponent_timeout' ? '对方未能在 60 秒内完成判定'
            : timeoutReason === 'opponent_disconnected' ? '对方断开了连接'
                : timeoutReason === 'opponent_left' ? '对方主动退出了对局'
                    : timeoutReason === 'you' ? '你未能在 60 秒内完成判定'
                        : timeoutReason === 'both' ? '双方均未在 60 秒内完成判定'
                            : timeoutReason === 'no_mutual_chat' ? '双方未互发消息，不计入战绩'
                                : ('对方是：' + truthLabel))
        : ('对方是：' + truthLabel);
    const cardClass = isWin ? 'correct' : 'wrong';
    const totalMsgs = userMsgCount + botMsgCount;

    // 移除等待指示器
    const waitIndicator = document.getElementById('waiting-indicator');
    if (waitIndicator) waitIndicator.remove();

    // 清除重连 session ID，防止后台 WS 重连时恢复旧会话触发 _onConnected 跳回聊天页
    if (transport) transport._lastSessionId = '';

    landingPage.style.display = 'none';
    matchingPage.style.display = 'none';
    chatPage.style.display = 'none';
    resultArea.style.display = 'flex';
    resultArea.innerHTML = `
                <div class="result-card doodle-border ${cardClass} anim-pop-in">
                    <span class="result-icon">${iconSVG}</span>
                    <h2 class="result-verdict">${verdict}</h2>
                    <div class="reveal-text">${reveal}</div>
                    <div class="result-row">
                        <span class="label">你的判断</span>
                        <span class="value">${guessLabel}</span>
                    </div>
                    <div class="result-row">
                        <span class="label">对方身份</span>
                        <span class="value">${truthLabel}</span>
                    </div>
                    ${opponentTag ? `
                    <div class="result-row">
                        <span class="label">对方标签</span>
                        <span class="value" style="background:let(--ink-blue);color:let(--surface-white);padding:2px 10px;border-radius:12px 3px 12px 3px;font-size:13px;">${escapeHtml(opponentTag)}</span>
                    </div>` : ''}
                    <div class="result-row">
                        <span class="label">对方猜你是</span>
                        <span class="value">${opponentGuessLabel}</span>
                    </div>
                    <div class="result-row">
                        <span class="label">对话条数</span>
                        <span class="value">${totalMsgs} 条</span>
                    </div>
                    <div class="result-row">
                        <span class="label">用时</span>
                        <span class="value">${formatTime(Math.round((Date.now() - gameStartTime) / 1000))}</span>
                    </div>
                    <button class="doodle-btn" id="btn-export-image" style="width:100%; justify-content:center; margin-bottom:8px;">
                        <svg class="icon" viewBox="0 0 24 24">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <polyline points="21 15 16 10 5 21" />
                        </svg>
                        导出为图片
                    </button>
                    <button class="doodle-btn" id="btn-save-history" style="width:100%; justify-content:center; margin-bottom:8px;">
                        <svg class="icon" viewBox="0 0 24 24">
                            <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                        </svg>
                        保存聊天记录
                    </button>
                    <div id="save-history-status" style="display:none;text-align:center;font-size:12px;margin-bottom:8px;"></div>
                    <button class="doodle-btn" id="btn-view-chat" style="width:100%; justify-content:center; margin-bottom:8px;">
                        <svg class="icon" viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        查看对话
                    </button>
                    <div id="leave-message-area" style="margin-bottom:8px;">
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="leave-message-input" placeholder="给对手留句话（可选,20字内）" maxlength="20"
                                style="flex:1;padding:6px 10px;border:2px solid let(--ink-black);border-radius:6px;font-size:13px;background:let(--bg);color:let(--text);">
                            <button class="doodle-btn" id="btn-leave-message" style="padding:6px 14px;font-size:13px;white-space:nowrap;">
                                发送留言
                            </button>
                        </div>
                        <div id="leave-message-status" style="display:none;text-align:center;font-size:12px;margin-top:4px;"></div>
                    </div>
                    <div id="collection-area" style="display:none;margin-bottom:8px;">
                        <div style="font-size:12px;font-weight:bold;margin-bottom:4px;color:let(--text);">收藏此局</div>
                        <input type="text" id="collection-title-input" placeholder="给这次对局起个标题（选填）" maxlength="100"
                            style="width:100%;padding:6px 10px;border:2px solid let(--ink-black);border-radius:6px;font-size:13px;margin-bottom:6px;background:let(--bg);color:let(--text);box-sizing:border-box;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:6px;cursor:pointer;">
                            <input type="checkbox" id="collection-public-check" checked>
                            公开到个人资料页
                        </label>
                        <div style="display:flex;gap:6px;">
                            <button class="doodle-btn" id="btn-collection-save" style="flex:1;justify-content:center;font-size:13px;padding:6px;">
                                保存收藏设置
                            </button>
                        </div>
                        <div id="collection-status" style="display:none;text-align:center;font-size:12px;margin-top:4px;"></div>
                    </div>
                    <button class="doodle-btn start-btn" id="btn-replay-inner" style="width:100%; justify-content:center;">
                        <svg class="icon" viewBox="0 0 24 24">
                            <polyline points="23 4 23 10 17 10" />
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                        </svg>
                        再来一局
                    </button>
                </div>
            `;

    document.getElementById('btn-view-chat').addEventListener('click', () => {
        resultArea.style.display = 'none';
        chatPage.style.display = 'flex';
        chatBody.scrollTop = chatBody.scrollHeight;

        const backBtn = document.createElement('div');
        backBtn.className = 'sys-msg';
        backBtn.id = 'review-back-btn';
        backBtn.style.cursor = 'pointer';
        backBtn.style.textDecoration = 'underline';
        backBtn.innerHTML = `
                    <svg class="icon" viewBox="0 0 24 24" style="width:1em;height:1em;">
                        <polyline points="18 15 12 9 6 15" />
                    </svg>
                    返回结果
                `;
        backBtn.addEventListener('click', () => {
            resultArea.style.display = 'flex';
            chatPage.style.display = 'none';
            backBtn.remove();
        });
        chatBody.appendChild(backBtn);
    });

    document.getElementById('btn-export-image').addEventListener('click', function () {
        this.disabled = true;
        this.innerHTML = '<span class="spinner" style="display:inline-block;width:14px;height:14px;border:2px solid #ccc;border-top-color:#2b2b2b;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px;"></span>生成中...';
        exportChatImage(this, verdict, reveal, isWin, guessLabel, isTimeout ? (timeoutReason === 'opponent' ? '对方未判定' : timeoutReason === 'you' ? '你未判定' : '双方未判定') : truthLabel, opponentGuessLabel);
    });

    document.getElementById('btn-save-history').addEventListener('click', () => {
        saveChatHistory();
    });

    document.getElementById('btn-replay-inner').addEventListener('click', resetGame);

    document.getElementById('btn-leave-message').addEventListener('click', () => {
        const input = document.getElementById('leave-message-input');
        const text = input.value.trim();
        if (!text) return;

        const btn = document.getElementById('btn-leave-message');
        btn.disabled = true;
        btn.textContent = '发送中...';

        try {
            transport.send('leave_message', { text });
        } catch (e) {
            const statusEl = document.getElementById('leave-message-status');
            statusEl.style.display = 'block';
            statusEl.style.color = 'let(--danger)';
            statusEl.textContent = '发送失败';
            btn.disabled = false;
            btn.textContent = '发送留言';
        }
    });

    document.getElementById('btn-collection-save').addEventListener('click', () => {
        const savedId = game._savedHistoryId;
        if (!savedId) return;

        const title = document.getElementById('collection-title-input').value.trim();
        const isPublic = document.getElementById('collection-public-check').checked;
        const tok = getUserToken();
        if (!tok) {
            const statusEl = document.getElementById('collection-status');
            statusEl.style.display = 'block';
            statusEl.style.color = 'let(--danger)';
            statusEl.textContent = '请先获取恢复码';
            return;
        }

        const btn = document.getElementById('btn-collection-save');
        btn.disabled = true;
        btn.textContent = '保存中...';

        fetch('/api/chat-history/collect', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + tok
            },
            body: JSON.stringify({ id: savedId, title: title || null, is_public: isPublic }),
        })
        .then(r => r.json())
        .then(result => {
            const statusEl = document.getElementById('collection-status');
            statusEl.style.display = 'block';
            if (result.success) {
                statusEl.style.color = 'let(--success)';
                statusEl.textContent = '收藏设置已保存';
                btn.textContent = '已保存';
            } else {
                statusEl.style.color = 'let(--danger)';
                statusEl.textContent = result.message || '保存失败';
                btn.disabled = false;
                btn.textContent = '保存收藏设置';
            }
        })
        .catch(() => {
            const statusEl = document.getElementById('collection-status');
            statusEl.style.display = 'block';
            statusEl.style.color = 'let(--danger)';
            statusEl.textContent = '网络错误';
            btn.disabled = false;
            btn.textContent = '保存收藏设置';
        });
    });

    // 记录战绩
    recordGameStats({
        userGuess: userGuess,
        opponentTruth: opponentTruth,
        timeoutReason: timeoutReason,
        totalMsgs: totalMsgs,
        duration: Math.round((Date.now() - gameStartTime) / 1000),
    });
}

function resetState() {
    game.reset();
}

/**
 * 匹配阶段错误：在匹配页面展示错误信息并返回首页
 */
function showMatchError(message) {
    const errorEl = document.getElementById('match-error');
    const dotsEl = document.getElementById('matching-dots');
    const hintEl = document.getElementById('matching-hint');

    // 隐藏加载动画，显示错误
    if (dotsEl) dotsEl.style.display = 'none';
    if (hintEl) hintEl.textContent = '匹配失败，请稍后重试';
    if (errorEl) {
        errorEl.textContent = '⚠ ' + (message || '服务器返回错误');
        errorEl.style.display = 'block';
        errorEl.style.animation = 'wiggle 0.3s ease';
    }

    // 1.5 秒后返回首页
    setTimeout(() => {
        if (errorEl) {
            errorEl.style.display = 'none';
            errorEl.style.animation = '';
        }
        if (dotsEl) dotsEl.style.display = '';
        if (hintEl) hintEl.textContent = '稍等一下，马上就好';
        resetState();
    }, 2500);
}

function resetGame() {
    game.resetAndPlay();
}

// ================================================================
//  统一用户数据存储（v2：整合 turing_nickname / turing_player_code / turing_stats）
// ================================================================

function getUserdata() {
    try {
        const raw = localStorage.getItem(USERDATA_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (_) { return {}; }
}

function saveUserdata(d) {
    try {
        localStorage.setItem(USERDATA_KEY, JSON.stringify(d));
    } catch (_) { }
}

function clearUserdata() {
    try {
        localStorage.removeItem(USERDATA_KEY);
    } catch (_) { }
}

/**
 * 旧数据迁移（临时，下个版本移除）
 * 1. 旧 key: turing_nickname / turing_player_code / turing_stats → UserData
 * 2. 旧 key: turing_userdata → UserData
 */
function migrateLegacyData() {
    if (localStorage.getItem(USERDATA_KEY)) return; // 已迁移

    const ud = getUserdata();
    let migrated = false;

    // 从旧 turing_userdata key 迁移（v2.0）
    const oldUd = localStorage.getItem('turing_userdata');
    if (oldUd) {
        try {
            const parsed = JSON.parse(oldUd);
            Object.assign(ud, parsed);
            localStorage.removeItem('turing_userdata');
            migrated = true;
        } catch (_) { }
    }

    // 迁移旧 key: turing_nickname / turing_player_code / turing_stats
    const oldNick = localStorage.getItem('turing_nickname');
    if (oldNick && !ud.nickname) {
        ud.nickname = oldNick;
        migrated = true;
    }

    // 迁移恢复码（旧格式不再兼容，需要通过密码恢复）
    const oldCode = localStorage.getItem('turing_player_code');
    if (oldCode && !ud.recovery_code) {
        ud.recovery_code = oldCode;
        migrated = true;
    }

    // 迁移战局统计
    try {
        const oldStatsRaw = localStorage.getItem('turing_stats');
        if (oldStatsRaw && !ud.stats) {
            ud.stats = JSON.parse(oldStatsRaw);
            migrated = true;
        }
    } catch (_) { }

    if (migrated) {
        saveUserdata(ud);
        // 清理旧 key
        localStorage.removeItem('turing_nickname');
        localStorage.removeItem('turing_player_code');
        localStorage.removeItem('turing_stats');
    }
}

// ---- 用户数据便捷读写 ----
function getUserNickname() { return getUserdata().nickname || ''; }
function setUserNickname(name) { const d = getUserdata(); d.nickname = name; saveUserdata(d); }
function getUserStats() { return getUserdata().stats || null; }
function setUserStats(s) { const d = getUserdata(); d.stats = s; saveUserdata(d); }
function getUserNicknameUpdatedAt() { return getUserdata().nickname_updated_at || ''; }
function setUserNicknameUpdatedAt(ym) { const d = getUserdata(); d.nickname_updated_at = ym; saveUserdata(d); }

// ================================================================
//  战局统计
// ================================================================

function getStats() { return getUserStats(); }
function saveStats(s) { setUserStats(s); }

function recordGameStats(result) {
    const s = getStats() || {
        total: 0, wins: 0, losses: 0, timeouts: 0,
        guessHuman: 0, guessAI: 0,
        oppHuman: 0, oppAI: 0,
        totalMsgs: 0, totalDuration: 0,
    };

    s.total++;
    s.totalMsgs += result.totalMsgs || 0;
    s.totalDuration += result.duration || 0;

    // 胜负
    if (result.timeoutReason === 'opponent' || result.timeoutReason === 'opponent_disconnected' || result.timeoutReason === 'opponent_left') {
        s.wins++;  // 对方超时/断开/离开，你赢
    } else if (result.timeoutReason === 'you') {
        s.losses++;
        s.timeouts++;
    } else if (result.timeoutReason === 'both') {
        // 平局，不计入胜负
    } else if (result.userGuess === result.opponentTruth) {
        s.wins++;
    } else {
        s.losses++;
    }

    // 猜测分布
    if (result.userGuess === 'human') s.guessHuman++;
    else if (result.userGuess === 'ai') s.guessAI++;

    // 对手分布
    if (result.opponentTruth === 'human') s.oppHuman++;
    else if (result.opponentTruth === 'ai') s.oppAI++;

    s.lastPlayed = Date.now();
    saveStats(s);
}

async function exportChatImage(btn, verdict, reveal, isCorrect, guessLabel, truthLabel, opponentGuessLabel) {
    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;left:-9999px;top:0;width:600px;background:#f8f9fa;color:#2b2b2b;font-family:"PingFang SC","Microsoft YaHei",sans-serif;padding:0;z-index:-1;';

    // 结果头部
    const headerBg = isCorrect ? '#d1f2d3' : '#fde2e4';
    const headerHTML = `
                <div style="padding:24px 28px;background:${headerBg};text-align:center;">
                    <div style="font-size:28px;font-weight:bold;color:#2b2b2b;margin-bottom:6px;">${verdict}</div>
                    <div style="font-size:16px;color:#555;">${reveal}</div>
                    <div style="display:flex;justify-content:center;gap:40px;margin-top:14px;font-size:14px;color:#666;">
                        <span>你的判断：<b>${guessLabel}</b></span>
                        <span>对方身份：<b>${truthLabel}</b></span>
                        <span>对方猜你是：<b>${opponentGuessLabel}</b></span>
                    </div>
                </div>
            `;

    // 聊天记录：将图片转为 Blob URL 避免跨域无法渲染
    const bubbles = chatBody.querySelectorAll('.bubble');
    const blobUrls = [];
    let chatHTML = '<div style="padding:18px 24px;background:#fff;">';
    if (bubbles.length === 0) {
        chatHTML += '<div style="text-align:center;color:#aaa;padding:30px;">暂无聊天记录</div>';
    } else {
        for (const b of bubbles) {
            const isRight = b.classList.contains('bubble-right');
            const isSticker = b.classList.contains('bubble-sticker');
            const bg = isRight ? '#d3e2ed' : '#fdf5c9';
            const align = isRight ? 'flex-end' : 'flex-start';
            const radius = isRight ? '15px 15px 0 15px' : '15px 15px 15px 0';

            let bubbleContent;
            if (isSticker) {
                const stickerImg = b.querySelector('.sticker-msg-img');
                const stickerName = stickerImg ? stickerImg.alt : '表情';
                const infoEl = b.querySelector('.bubble-info');
                const infoHtml = infoEl ? infoEl.outerHTML : '';
                let imgHtml = '';

                if (stickerImg && stickerImg.src) {
                    try {
                        const proxyUrl = 'https://api-proxy_image.xfcode.top/proxy_image.php?url=' + encodeURIComponent(stickerImg.src);
                        const resp = await fetch(proxyUrl);
                        const blob = await resp.blob();
                        const blobUrl = URL.createObjectURL(blob);
                        blobUrls.push(blobUrl);
                        imgHtml = `<img src="${blobUrl}" alt="${escapeHtmlAttr(stickerName)}" style="max-width:120px;display:block;border-radius:8px;">`;
                    } catch (e) {
                        imgHtml = `<div style="font-size:14px;color:#999;font-style:italic;">[表情: ${escapeHtml(stickerName)}]</div>`;
                    }
                } else {
                    imgHtml = `<div style="font-size:14px;color:#999;font-style:italic;">[表情: ${escapeHtml(stickerName)}]</div>`;
                }
                bubbleContent = infoHtml + imgHtml;
            } else {
                // 非表情气泡：克隆 DOM 并将内部 <img> 转为 blob URL
                const clone = b.cloneNode(true);
                const imgs = clone.querySelectorAll('img');
                for (const img of imgs) {
                    const src = img.getAttribute('src') || '';
                    if (src && !src.startsWith('blob:') && !src.startsWith('data:')) {
                        try {
                            const proxyUrl = '/api/proxy-image?url=' + encodeURIComponent(src);
                            const resp = await fetch(proxyUrl);
                            const blob = await resp.blob();
                            const blobUrl = URL.createObjectURL(blob);
                            blobUrls.push(blobUrl);
                            img.src = blobUrl;
                        } catch (e) { }
                    }
                }
                bubbleContent = clone.innerHTML;
            }
            chatHTML += `
                        <div style="display:flex;justify-content:${align};margin-bottom:16px;">
                            <div style="max-width:75%;padding:10px 16px;background:${bg};border:2px solid #2b2b2b;border-radius:${radius};font-size:15px;line-height:1.5;color:#2b2b2b;">
                                ${bubbleContent}
                            </div>
                        </div>
                    `;
        }
    }
    chatHTML += '</div>';

    const footerHTML = `
                <div style="padding:18px 24px;background:#fff;display:flex;align-items:center;justify-content:space-between;border-top:2px dashed #ccc;">
                    <div style="font-size:20px;color:#2b2b2b;text-decoration:underline;text-decoration-color:#1e3799;text-decoration-style:wavy;text-underline-offset:6px;">
                        <svg viewBox="0 0 24 24" style="width:22px;height:22px;display:inline-block;vertical-align:-5px;fill:none;stroke:#2b2b2b;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;margin-right:8px;">
                            <circle cx="11" cy="11" r="8" />
                            <path d="M21 21l-4.35-4.35" />
                        </svg>
                        图灵测试小游戏
                    </div>
                    <div style="text-align:center;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=https%3A%2F%2Fgame.xfcode.top%2F" width="80" height="80" style="display:block;" crossorigin="anonymous" />
                        <div style="font-size:10px;color:#999;margin-top:4px;">扫码来玩</div>
                    </div>
                </div>
            `;

    container.innerHTML = headerHTML + chatHTML + footerHTML;
    document.body.appendChild(container);

    try {
        const canvas = await html2canvas(container, {
            scale: 2,
            backgroundColor: '#f8f9fa',
            useCORS: true,
            // 黑夜模式下 [data-theme="dark"] * 会把导出容器的文字强制成浅色，导致图片文字不可读。
            // 在克隆文档中移除 dark 主题标记，让导出图始终按浅色卡片渲染（不影响真实页面）
            onclone: (doc) => {
                const root = doc.documentElement;
                if (root && root.hasAttribute('data-theme')) root.removeAttribute('data-theme');
            },
        });
        const link = document.createElement('a');
        link.download = 'TuringTalk_' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    } finally {
        document.body.removeChild(container);
        // 清理 blob URL
        for (const url of blobUrls) {
            URL.revokeObjectURL(url);
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<svg class="icon" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>导出为图片';
        }
    }
}

/**
 * 保存聊天记录到服务器（通过 WS 从服务端共享内存读取消息）
 */
function saveChatHistory() {
    const sessionId = game._sessionId || '';
    if (!sessionId) {
        const statusEl = document.getElementById('save-history-status');
        statusEl.style.display = 'block';
        statusEl.style.color = 'let(--danger)';
        statusEl.textContent = '无法获取对局标识，保存失败';
        return;
    }

    const btnSave = document.getElementById('btn-save-history');
    btnSave.disabled = true;
    btnSave.textContent = '保存中...';

    try {
        transport.send('save_history', { session_id: sessionId });
    } catch (e) {
        const statusEl = document.getElementById('save-history-status');
        statusEl.style.display = 'block';
        statusEl.style.color = 'let(--danger)';
        statusEl.textContent = '发送失败，请稍后再试';
        btnSave.disabled = false;
        btnSave.textContent = '保存聊天记录';
    }
}

document.getElementById('btn-judge-human').addEventListener('click', () => makeJudgement('human'));
document.getElementById('btn-judge-ai').addEventListener('click', () => makeJudgement('ai'));

btnSend.addEventListener('click', sendMessage);

chatInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
    }
});

chatInput.addEventListener('input', () => {
    const len = chatInput.value.length;
    charCount.textContent = len + '/300';
    charCount.style.color = len > 280 ? 'let(--danger)' : len > 250 ? 'let(--warn)' : 'let(--text-subtle)';
});

btnBack.addEventListener('click', function (e) {
    if (chatPage.style.display === 'flex') {
        if (confirm('确定要离开当前对局吗？\n离开后将断开与对手的连接。')) {
            DebugLogger.log('game', '用户点击离开对局');
            resetState();
        }
    } else {
        DebugLogger.log('match', '用户取消匹配');
        resetState();
    }
});

window.addEventListener('beforeunload', function (e) {
    // 只在真正的游戏对局（非公开回顾页）激活时触发
    if (chatPage.style.display === 'flex' && !window._isPublicCollection) {
        e.preventDefault();
        e.returnValue = '你正在进行一局图灵测试，确定要离开吗？';
        return e.returnValue;
    }
    // 离开页面时主动关闭 WebSocket，避免服务端 ipToFd 残留导致返回时被拦截
    if (transport && transport._ws) {
        try {
            transport._ws.close();
            transport._ws = null;
        } catch (ignore) {}
    }
});

// pagehide 比 beforeunload 更可靠（bfcache 场景也会触发）
window.addEventListener('pagehide', function () {
    if (transport && transport._ws) {
        try {
            transport._ws.close();
            transport._ws = null;
        } catch (ignore) {}
    }
});

// ================================================================
//  全局网络/可见性监控
// ================================================================
window.addEventListener('online', function () {
    DebugLogger.log('network', '浏览器online事件');
});
window.addEventListener('offline', function () {
    DebugLogger.log('network', '浏览器offline事件');
});

document.addEventListener('visibilitychange', function () {
    DebugLogger.log('lifecycle', '页面可见性变化', {
        hidden: document.hidden,
        visibilityState: document.visibilityState,
        matching: matchingPage.style.display === 'flex',
        chatting: chatPage.style.display === 'flex'
    });
    // 标签页恢复可见时，检查 WebSocket 连接状态，如果已断则触发重连
    if (!document.hidden && transport) {
        const ws = transport._ws;
        if (!ws || (ws.readyState !== WebSocket.OPEN && ws.readyState !== WebSocket.CONNECTING)) {
            DebugLogger.log('ws', '页面恢复可见，WS已断开，触发重连');
            transport._intentionalClose = false;
            transport._lastPongTime = 0;
            transport.connect(transport._lastNickname || '', transport._lastDuration || 600);
        }
    }
});

// Back-Forward Cache 恢复时重连 WebSocket
window.addEventListener('pageshow', function (e) {
    if (e.persisted && transport) {
        DebugLogger.log('lifecycle', '页面从bfcache恢复，触发WS重连');
        const ws = transport._ws;
        if (ws) {
            try { ws.onclose = null; } catch (_) { }
            ws.close();
            transport._ws = null;
        }
        transport._intentionalClose = false;
        transport._lastPongTime = 0;
        // 延迟 300ms 重连，确保服务端已处理旧连接的 onClose
        setTimeout(function () {
            transport.connect(transport._lastNickname || '', transport._lastDuration || 600);
        }, 300);
    }
});

// 清除本地数据按钮
btnClearLocalData.addEventListener('click', () => {
    if (!confirm('确定要清除所有本地数据吗？\n昵称、恢复码和战绩记录将被删除，操作不可恢复！')) return;
    clearUserdata();
    // 刷新页面以重置所有状态
    window.location.reload();
});

// 上传本地数据到服务器按钮
btnUploadUserData.addEventListener('click', async () => {
    const btn = document.getElementById('btn-upload-userdata');
    const tok = getUserToken();
    if (!tok) {
        showTopToast('请先在首页创建或恢复您的恢复码', true);
        return;
    }

    const ud = getUserdata();
    const payload = {
        nickname: ud.nickname || '',
        fp: browserFingerprint,
        stats: ud.stats || {},
    };

    btn.disabled = true;
    btn.textContent = '上传中...';

    try {
        const resp = await fetch('/api/upload-userdata', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + tok
            },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();
        if (data.success) {
            showTopToast('数据上传成功', false);
        } else {
            showTopToast(data.error || '上传失败', true);
        }
    } catch (e) {
        showTopToast('网络错误，请稍后再试', true);
    } finally {
        btn.disabled = false;
        btn.textContent = '上传我的数据';
    }
});

btnSettings.addEventListener('click', () => {
    settingsOverlay.style.display = 'flex';
    // 更新战绩记录 UI
    updateLbUI();
});

btnCloseSettings.addEventListener('click', () => {
    closeOverlay(settingsOverlay);
});

settingsOverlay.addEventListener('click', (e) => {
    if (e.target === settingsOverlay) {
        closeOverlay(settingsOverlay);
    }
});

// --- 更新日志面板 ---
btnChangelog.addEventListener('click', () => {
    settingsOverlay.style.display = 'none';
    changelogOverlay.style.display = 'flex';
});

btnCloseChangelog.addEventListener('click', () => {
    closeOverlay(changelogOverlay);
    settingsOverlay.style.display = 'flex';
});

changelogOverlay.addEventListener('click', (e) => {
    if (e.target === changelogOverlay) {
        closeOverlay(changelogOverlay);
        settingsOverlay.style.display = 'flex';
    }
});

// ==================== 评价与打分弹窗（基于 comment-sdk 自定义 UI，风格对齐站点） ====================

const commentOverlay = document.getElementById('comment-overlay');
const btnCommentWidget = document.getElementById('btn-comment-widget');
const btnCloseComment = document.getElementById('btn-close-comment');
const commentWidgetEl = document.getElementById('flm-comment-widget');

const CMT_API = 'https://fakeicp.top';
const CMT_SITE = 'game.xfcode.top';
const CMT_PAGE = '/';
const CMT_TITLE = '图灵测试';

let cmtSdk = null;
let cmtSdkLoading = false;
let cmtRating = 5;

function cmtEsc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function cmtStars(rating) {
    rating = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)));
    let h = '';
    for (let i = 1; i <= 5; i++) {
        h += '<span class="' + (i <= rating ? 'on' : 'off') + '">' + (i <= rating ? '★' : '☆') + '</span>';
    }
    return h;
}

function cmtLoadSdk(cb) {
    if (window.FlmCommentSDK) { cb(null); return; }
    if (cmtSdkLoading) return;
    cmtSdkLoading = true;
    const s = document.createElement('script');
    s.src = CMT_API + '/comment-sdk.js';
    s.async = true;
    s.onload = () => { cmtSdkLoading = false; cb(null); };
    s.onerror = () => { cmtSdkLoading = false; cb(new Error('评价 SDK 加载失败')); };
    document.head.appendChild(s);
}

function cmtOpen() {
    commentOverlay.style.display = 'flex';
    commentWidgetEl.innerHTML = '<div class="cw-loading">加载评价系统...</div>';
    cmtLoadSdk((err) => {
        if (err) {
            commentWidgetEl.innerHTML = '<div class="cw-empty">' + cmtEsc(err.message) + '</div>';
            return;
        }
        if (!cmtSdk) {
            cmtSdk = new window.FlmCommentSDK({ apiBase: CMT_API, siteKey: CMT_SITE, pageKey: CMT_PAGE, pageTitle: CMT_TITLE });
        }
        cmtFetchAndRender();
    });
}

function cmtFetchAndRender() {
    let commentsData = null;
    let reactions = null;
    const done = () => {
        if (commentsData !== null && reactions !== null) {
            cmtRender(commentsData, reactions);
        }
    };
    cmtSdk.fetchComments({ page: 1, limit: 30 }, (err, res) => {
        if (err || !res || !res.ok) {
            commentWidgetEl.innerHTML = '<div class="cw-empty">评价加载失败：' + cmtEsc(err ? err.message : '未知错误') + '</div>';
            return;
        }
        commentsData = res.data;
        done();
    });
    cmtSdk.fetchReactions((err, res) => {
        reactions = (res && res.ok && res.reactions) ? res.reactions : {};
        done();
    });
}

function cmtRender(data, reactions) {
    const stats = data.stats || {};
    const comments = data.comments || [];
    const icpInfo = data.icp_info;
    const total = stats.total_reviews || 0;
    const avg = (Number(stats.avg_rating) || 0).toFixed(1);
    const stamps = reactions.stamps || [];

    let html = '';

    // 头部：标题 + ICP 徽标 + 评分大厅链接
    html += '<div class="cw-header">';
    html += '<div class="cw-title-area">';
    html += '<div class="cw-title">评价与打分</div>';
    html += '<span class="cw-icp-badge">' + cmtEsc((icpInfo && icpInfo.display) || data.site_key || '未备案站点') + '</span>';
    html += '</div>';
    html += '<a class="cw-hall-link" href="' + CMT_API + '/rating.html" target="_blank" rel="noopener">进入假备评分大厅 →</a>';
    html += '</div>';

    // 汇总：均分 + 星级 + 分布条
    html += '<div class="cw-section cw-summary-row">';
    html += '<div class="cw-score-big">' + avg + '</div>';
    html += '<div class="cw-score-meta">';
    html += '<div class="cw-stars-row">' + cmtStars(Math.round(avg)) + '</div>';
    html += '<div class="cw-count-text">共 ' + total + ' 条有效评分</div>';
    html += '</div>';
    html += '<div class="cw-bars">';
    for (let star = 5; star >= 1; star--) {
        const sc = stats['star_' + star + '_count'] || 0;
        const pct = total > 0 ? Math.round((sc / total) * 100) : 0;
        html += '<div class="cw-bar-item"><span>' + star + '星</span>' +
            '<div class="cw-bar-bg"><div class="cw-bar-fill" style="width:' + pct + '%;"></div></div>' +
            '<span class="cw-bar-num">' + pct + '%</span></div>';
    }
    html += '</div>';
    html += '</div>';

    // 印章表态
    html += '<div class="cw-section">';
    html += '<div class="cw-section-title"><span>印章表态</span><span class="cw-section-sub">（免登录，点击即可表态）</span></div>';
    if (stamps.length) {
        html += '<div class="cw-stamp-grid">';
        for (let i = 0; i < stamps.length; i++) {
            const st = stamps[i];
            const sid = st.id || st.type;
            const key = 'flm_stamp_' + CMT_SITE + '_' + CMT_PAGE + '_' + sid;
            const active = window.localStorage ? window.localStorage.getItem(key) === '1' : false;
            html += '<button type="button" class="cw-stamp-btn' + (active ? ' active' : '') + '" data-type="' + cmtEsc(sid) + '" data-icon="' + cmtEsc(st.icon) + '">';
            html += '<span>' + cmtEsc(st.icon) + ' ' + cmtEsc(st.label) + '</span>';
            html += '<span class="cw-stamp-count">' + (Number(st.count) || 0) + '</span>';
            html += '</button>';
        }
        html += '</div>';
    } else {
        html += '<div class="cw-empty">印章数据加载失败，请稍后刷新重试</div>';
    }
    html += '</div>';

    // 发布表单
    html += '<div class="cw-section">';
    html += '<div class="cw-section-title"><span>发布评分与评语</span><span class="cw-section-sub">（打分评价每人限 1 次，重复提交自动覆盖）</span></div>';
    html += '<div class="cw-star-select" id="cw-star-box">';
    for (let s = 1; s <= 5; s++) {
        html += '<span data-star="' + s + '" class="on">★</span>';
    }
    html += '<span class="cw-star-tip" id="cw-star-tip">5星 力荐</span>';
    html += '</div>';
    html += '<div class="cw-input-grid">';
    html += '<input type="text" class="cw-input" id="cw-inp-nick" placeholder="昵称 *" maxlength="50" />';
    html += '<input type="email" class="cw-input" id="cw-inp-email" placeholder="邮箱（选填，支持头像）" />';
    html += '<input type="url" class="cw-input" id="cw-inp-web" placeholder="个人主页（选填，https://...）" />';
    html += '</div>';
    html += '<div class="cw-tag-row"><span class="cw-tag-label">添加标签:</span>';
    const defaultTags = ['独立博客', '前端', '技术干货', '实用工具', 'UI设计', '游戏', '生活'];
    for (let t = 0; t < defaultTags.length; t++) {
        html += '<button type="button" class="cw-tag-pill" data-tag="' + defaultTags[t] + '">+ #' + defaultTags[t] + '</button>';
    }
    html += '<input type="text" class="cw-input cw-tag-input" id="cw-inp-tags" placeholder="自定义标签（如 #前端 #博客）..." />';
    html += '</div>';
    html += '<textarea class="cw-textarea" id="cw-inp-content" placeholder="撰写您的客观评价正文（支持普通评论与星级打分）..."></textarea>';
    html += '<div class="cw-form-foot"><span class="cw-msg" id="cw-msg"></span>' +
        '<button type="button" class="cw-btn" id="cw-btn-submit">提交打分评价</button></div>';
    html += '</div>';

    // 评论列表
    html += '<div class="cw-list">';
    if (!comments.length) {
        html += '<div class="cw-empty">暂无评价，快来抢先留下第一条评价吧！</div>';
    } else {
        for (let i = 0; i < comments.length; i++) {
            html += cmtCard(comments[i]);
        }
    }
    html += '</div>';

    commentWidgetEl.innerHTML = html;
    cmtBindEvents();
}

function cmtCard(c) {
    const avatar = window.FlmCommentSDK.getAvatarUrl(c.email, c.nickname);
    const time = window.FlmCommentSDK.formatTimeAgo(c.created_at);
    let html = '<div class="cw-card" data-id="' + cmtEsc(c.id) + '">';
    html += '<div class="cw-card-head">';
    html += '<div class="cw-user-info">';
    html += '<img class="cw-avatar" src="' + avatar + '" alt="avatar" />';
    html += '<div class="cw-user-meta">';
    html += '<div class="cw-nickname">' + cmtEsc(c.nickname);
    if (c.website) {
        html += '<a class="cw-web-link" href="' + cmtEsc(c.website) + '" target="_blank" rel="nofollow ugc noopener" title="访问个人主页">主页</a>';
    }
    html += '</div>';
    html += '<div class="cw-time">' + time + '</div>';
    html += '</div></div>';
    if (c.rating) {
        html += '<div class="cw-card-rating">' + cmtStars(c.rating) + '</div>';
    }
    html += '</div>';
    html += '<div class="cw-content">' + cmtEsc(c.content) + '</div>';
    if (c.tags) {
        const pills = String(c.tags).split(',')
            .filter((x) => x.trim())
            .map((x) => '<span>#' + cmtEsc(x.trim()) + '</span>')
            .join('');
        if (pills) {
            html += '<div class="cw-card-tags">' + pills + '</div>';
        }
    }
    html += '<div class="cw-card-foot">';
    html += '<button type="button" class="cw-action-btn cw-btn-like" data-id="' + cmtEsc(c.id) + '">赞 <span class="cw-like-num">' + (c.likes || 0) + '</span></button>';
    html += '<button type="button" class="cw-action-btn cw-btn-reply" data-id="' + cmtEsc(c.id) + '">回复 (' + (c.replies ? c.replies.length : 0) + ')</button>';
    html += '</div>';
    if (c.replies && c.replies.length) {
        html += '<div class="cw-replies">';
        for (let r = 0; r < c.replies.length; r++) {
            const rp = c.replies[r];
            html += '<div class="cw-reply-card"><div class="cw-reply-head"><span class="cw-reply-nick">' + cmtEsc(rp.nickname) + '</span>' +
                '<span class="cw-time">' + window.FlmCommentSDK.formatTimeAgo(rp.created_at) + '</span></div>' +
                '<div>' + cmtEsc(rp.content) + '</div></div>';
        }
        html += '</div>';
    }
    html += '<div class="cw-reply-drawer" id="cw-reply-drawer-' + cmtEsc(c.id) + '" style="display:none;"></div>';
    html += '</div>';
    return html;
}

function cmtReplyForm(parentId) {
    return '<div class="cw-reply-form">' +
        '<div class="cw-reply-form-title">回复评论 #' + parentId + '</div>' +
        '<div class="cw-input-grid" style="grid-template-columns:1fr 1fr; margin-bottom:6px;">' +
        '<input type="text" class="cw-input" id="cw-r-nick-' + parentId + '" placeholder="昵称 *" />' +
        '<input type="email" class="cw-input" id="cw-r-email-' + parentId + '" placeholder="邮箱（选填）" />' +
        '</div>' +
        '<textarea class="cw-textarea" id="cw-r-content-' + parentId + '" style="height:54px;" placeholder="撰写回复正文..."></textarea>' +
        '<div class="cw-form-foot"><span class="cw-msg" id="cw-r-msg-' + parentId + '"></span>' +
        '<button type="button" class="cw-btn" id="cw-r-btn-' + parentId + '" style="padding:4px 12px; font-size:12px;">发送回复</button></div>' +
        '</div>';
}

function cmtBindReplyEvents(parentId) {
    const btn = commentWidgetEl.querySelector('#cw-r-btn-' + parentId);
    if (!btn) return;
    btn.addEventListener('click', () => {
        const nick = commentWidgetEl.querySelector('#cw-r-nick-' + parentId).value;
        const email = commentWidgetEl.querySelector('#cw-r-email-' + parentId).value;
        const content = commentWidgetEl.querySelector('#cw-r-content-' + parentId).value;
        const msg = commentWidgetEl.querySelector('#cw-r-msg-' + parentId);
        if (!nick || !content) {
            if (msg) msg.innerText = '请填写昵称与回复正文';
            return;
        }
        btn.disabled = true;
        cmtSdk.reply(parentId, { nickname: nick, email: email, content: content }, (err, res) => {
            btn.disabled = false;
            if (err || !res || !res.ok) {
                if (msg) msg.innerText = err ? err.message : '回复失败';
                return;
            }
            cmtFetchAndRender();
        });
    });
}

function cmtBindEvents() {
    // 印章表态
    commentWidgetEl.querySelectorAll('.cw-stamp-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const type = btn.getAttribute('data-type');
            const key = 'flm_stamp_' + CMT_SITE + '_' + CMT_PAGE + '_' + type;
            if (window.localStorage && window.localStorage.getItem(key) === '1') return;
            const countEl = btn.querySelector('.cw-stamp-count');
            const cur = Number(countEl.innerText) || 0;
            countEl.innerText = cur + 1;
            btn.classList.add('active');
            if (window.localStorage) window.localStorage.setItem(key, '1');
            cmtSdk.sendReaction(type, (err, res) => {
                if (res && res.ok && res.reactions) {
                    countEl.innerText = res.reactions[type] || (cur + 1);
                }
            });
        });
    });

    // 星级选择
    const starBox = commentWidgetEl.querySelector('#cw-star-box');
    if (starBox) {
        const stars = starBox.querySelectorAll('span[data-star]');
        const tip = commentWidgetEl.querySelector('#cw-star-tip');
        const texts = { 1: '1星 极差', 2: '2星 较差', 3: '3星 一般', 4: '4星 推荐', 5: '5星 力荐' };
        stars.forEach((sp) => {
            sp.addEventListener('click', () => {
                cmtRating = Number(sp.getAttribute('data-star'));
                stars.forEach((st) => st.classList.toggle('on', Number(st.getAttribute('data-star')) <= cmtRating));
                if (tip) tip.innerText = texts[cmtRating];
            });
        });
    }

    // 预设标签追加
    const tagInp = commentWidgetEl.querySelector('#cw-inp-tags');
    commentWidgetEl.querySelectorAll('.cw-tag-pill').forEach((btn) => {
        btn.addEventListener('click', () => {
            const t = '#' + btn.getAttribute('data-tag');
            const cur = (tagInp ? tagInp.value : '').trim();
            if (cur.indexOf(t) === -1) {
                if (tagInp) tagInp.value = cur ? (cur + ' ' + t) : t;
            }
        });
    });

    // 提交评价
    const btnSubmit = commentWidgetEl.querySelector('#cw-btn-submit');
    if (btnSubmit) {
        btnSubmit.addEventListener('click', () => {
            const nick = commentWidgetEl.querySelector('#cw-inp-nick').value;
            const email = commentWidgetEl.querySelector('#cw-inp-email').value;
            const web = commentWidgetEl.querySelector('#cw-inp-web').value;
            const tags = tagInp ? tagInp.value : '';
            const content = commentWidgetEl.querySelector('#cw-inp-content').value;
            const msg = commentWidgetEl.querySelector('#cw-msg');
            if (!nick) { if (msg) msg.innerText = '请填写昵称'; return; }
            if (!content) { if (msg) msg.innerText = '请填写评价正文'; return; }
            btnSubmit.disabled = true;
            btnSubmit.innerText = '⏳ 提交中...';
            cmtSdk.submit({
                nickname: nick,
                email: email,
                website: web,
                tags: tags,
                rating: cmtRating,
                content: content
            }, (err, res) => {
                btnSubmit.disabled = false;
                btnSubmit.innerText = '提交打分评价';
                if (err || !res || !res.ok) {
                    if (msg) msg.innerText = err ? err.message : '提交失败';
                    return;
                }
                if (msg) msg.innerText = res.message || '发布成功！';
                cmtFetchAndRender();
            });
        });
    }

    // 点赞 / 回复（事件代理）
    commentWidgetEl.addEventListener('click', (e) => {
        const likeBtn = e.target.closest('.cw-btn-like');
        if (likeBtn) {
            const cid = likeBtn.getAttribute('data-id');
            cmtSdk.like(cid, (err, res) => {
                if (!err && res && res.ok) {
                    const numSpan = likeBtn.querySelector('.cw-like-num');
                    if (numSpan) numSpan.innerText = res.likes;
                    likeBtn.style.color = 'var(--ink-blue)';
                }
            });
            return;
        }
        const replyBtn = e.target.closest('.cw-btn-reply');
        if (replyBtn) {
            const cid = replyBtn.getAttribute('data-id');
            const drawer = commentWidgetEl.querySelector('#cw-reply-drawer-' + cid);
            if (!drawer) return;
            if (drawer.style.display === 'none' || drawer.style.display === '') {
                drawer.style.display = 'block';
                drawer.innerHTML = cmtReplyForm(cid);
                cmtBindReplyEvents(cid);
            } else {
                drawer.style.display = 'none';
            }
        }
    });
}

btnCommentWidget.addEventListener('click', cmtOpen);

btnCloseComment.addEventListener('click', () => {
    closeOverlay(commentOverlay);
});

commentOverlay.addEventListener('click', (e) => {
    if (e.target === commentOverlay) {
        closeOverlay(commentOverlay);
    }
});

// ==================== 表情包管理 ====================

const stickerManagerOverlay = document.getElementById('sticker-manager-overlay');
const btnStickerManager = document.getElementById('btn-sticker-manager');
const btnCloseStickerManager = document.getElementById('btn-close-sticker-manager');
const stickerManagerGrid = document.getElementById('sticker-manager-grid');
const btnStickerUpload = document.getElementById('btn-sticker-upload');
const stickerUploadInput = document.getElementById('sticker-upload-input');
const stickerManagerStatus = document.getElementById('sticker-manager-status');
let stickerManagerTab = 'default';
let stickerManagerData = { defaults: [], mine: [] };

btnStickerManager.addEventListener('click', () => {
    settingsOverlay.style.display = 'none';
    stickerManagerOverlay.style.display = 'flex';
    loadStickerManager();
});

btnCloseStickerManager.addEventListener('click', () => {
    closeOverlay(stickerManagerOverlay);
    settingsOverlay.style.display = 'flex';
});

stickerManagerOverlay.addEventListener('click', (e) => {
    if (e.target === stickerManagerOverlay) {
        closeOverlay(stickerManagerOverlay);
        settingsOverlay.style.display = 'flex';
    }
});

btnStickerUpload.addEventListener('click', () => {
    stickerUploadInput.click();
});

stickerUploadInput.addEventListener('change', () => {
    const file = stickerUploadInput.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        stickerManagerStatus.textContent = '图片大小不能超过 2MB';
        return;
    }
    const reader = new FileReader();
    stickerManagerStatus.textContent = '上传中...';
    reader.onload = function () {
        const base64 = reader.result;
        const ext = (file.name.split('.').pop() || 'png').toLowerCase();
        const tok = getUserToken();
        fetch('/api/sticker/upload', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + tok
            },
            body: JSON.stringify({ image_data: base64, file_ext: ext }),
        })
            .then(r => r.json())
            .then(res => {
                if (res.error) {
                    stickerManagerStatus.textContent = res.error;
                } else {
                    stickerManagerStatus.textContent = '上传成功';
                    loadStickerManager();
                }
            })
            .catch(() => { stickerManagerStatus.textContent = '上传失败，请重试'; });
    };
    reader.readAsDataURL(file);
    stickerUploadInput.value = '';
});

document.querySelectorAll('.sticker-manager-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        stickerManagerTab = this.dataset.tab;
        document.querySelectorAll('.sticker-manager-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        renderStickerManagerGrid();
    });
});

function loadStickerManager() {
    const tok = getUserToken();
    stickerManagerStatus.textContent = '加载中...';
    fetch('/api/sticker/list', {
        headers: { 'Authorization': 'Bearer ' + tok }
    })
        .then(r => r.json())
        .then(res => {
            if (res.stickers) {
                stickerManagerData.defaults = [];
                stickerManagerData.mine = [];
                res.stickers.forEach(s => {
                    if (s.id && s.id.startsWith('us_')) {
                        stickerManagerData.mine.push(s);
                    } else {
                        stickerManagerData.defaults.push(s);
                    }
                });
                renderStickerManagerGrid();
            }
            stickerManagerStatus.textContent = '';
        })
        .catch(() => { stickerManagerStatus.textContent = '加载失败'; });
}

function renderStickerManagerGrid() {
    const stickers = stickerManagerTab === 'mine' ? stickerManagerData.mine : stickerManagerData.defaults;
    stickerManagerGrid.innerHTML = '';
    if (stickers.length === 0) {
        stickerManagerGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#999;padding:20px;">' +
            (stickerManagerTab === 'mine' ? '暂无自定义表情，点击"上传表情"添加' : '暂无默认表情') + '</div>';
        return;
    }
    stickers.forEach(s => {
        const item = document.createElement('div');
        item.style.cssText = 'position:relative;cursor:pointer;border:2px solid transparent;border-radius:8px;padding:4px;';
        item.innerHTML = '<img src="' + escapeHtmlAttr(s.url) + '" alt="' + escapeHtmlAttr(s.name || '') + '" style="width:100%;aspect-ratio:1;object-fit:contain;border-radius:6px;" loading="lazy">';
        if (stickerManagerTab === 'mine') {
            // 审核状态标签
            if (s.status && s.status !== 'approved') {
                const badge = document.createElement('div');
                badge.textContent = s.status === 'pending' ? '审核中' : '已拒绝';
                badge.style.cssText = 'position:absolute;bottom:4px;left:4px;right:4px;font-size:10px;padding:2px 0;'
                    + 'text-align:center;border-radius:4px;'
                    + (s.status === 'pending' ? 'background:rgba(255,152,0,.85);color:#fff;' : 'background:rgba(244,67,54,.85);color:#fff;');
                item.appendChild(badge);
            }
            const del = document.createElement('button');
            del.textContent = '×';
            del.style.cssText = 'position:absolute;top:-4px;right:-4px;width:20px;height:20px;border-radius:50%;border:none;background:#e74c3c;color:#fff;font-size:12px;line-height:1;cursor:pointer;';
            del.title = '删除';
            del.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!confirm('确认删除这个表情？')) return;
                const tok = getUserToken();
                fetch('/api/sticker/delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + tok
                    },
                    body: JSON.stringify({ sticker_id: s.id }),
                })
                    .then(r => r.json())
                    .then(res => {
                        if (!res.error) loadStickerManager();
                    });
            });
            item.appendChild(del);
        }
        stickerManagerGrid.appendChild(item);
    });
}

// 对局内表情选择器：打开/关闭
btnStickerPicker.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleStickerPicker();
});

btnCloseStickerPicker.addEventListener('click', () => {
    stickerPicker.style.display = 'none';
});

// 点击表情选择器外部关闭
document.addEventListener('click', (e) => {
    if (stickerPicker.style.display !== 'none' &&
        !stickerPicker.contains(e.target) &&
        e.target !== btnStickerPicker &&
        !btnStickerPicker.contains(e.target)) {
        stickerPicker.style.display = 'none';
    }
});

/** 切换表情选择器显示/隐藏 */
function toggleStickerPicker() {
    if (stickerPicker.style.display === 'none' || !stickerPicker.style.display) {
        renderStickerPicker();
        stickerPicker.style.visibility = 'hidden';
        stickerPicker.style.display = 'flex';
        repositionStickerPicker();
        stickerPicker.style.visibility = 'visible';
    } else {
        stickerPicker.style.display = 'none';
    }
}

function repositionStickerPicker() {
    if (stickerPicker.style.display !== 'flex') return;
    const btnRect = btnStickerPicker.getBoundingClientRect();
    const pickerWidth = stickerPicker.offsetWidth || 260;
    const pickerHeight = stickerPicker.offsetHeight;
    let left = btnRect.left;
    if (left + pickerWidth > window.innerWidth - 8) {
        left = Math.max(8, window.innerWidth - pickerWidth - 8);
    }
    stickerPicker.style.left = left + 'px';
    stickerPicker.style.top = (btnRect.top - pickerHeight - 16) + 'px';
}

/** 根据 stickerMap 渲染表情选择器内容 */
function renderStickerPicker() {
    renderSharedStickerPicker(stickerPickerBody, stickerMap, function (id, st) {
        sendSticker(id, st);
    });
}

/**
 * Cookie 工具函数
 */
function setCookie(name, value, days) {
    const expires = new Date();
    expires.setTime(expires.getTime() + days * 86400000);
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + expires.toUTCString() + ';path=/;SameSite=Lax';
}
function getCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
}
function delCookie(name) {
    document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;SameSite=Lax';
}

function closeOverlay(el) {
    const drawer = el.querySelector('.clipboard-drawer');
    el.style.animation = 'fadeOut 0.2s ease forwards';
    if (drawer) drawer.style.animation = 'fadeScaleOut 0.2s ease forwards';
    setTimeout(() => {
        el.style.display = 'none';
        el.style.animation = '';
        if (drawer) drawer.style.animation = '';
    }, 200);
}

let adminToken = getCookie('turing_admin_token');
let spectateSessionId = null;
// 搜索状态：缓存服务端返回的完整列表 + 当前搜索关键字
let _cachedSessions = [];
let _sessionSearchKeyword = '';


// 举报相关
const btnReport = document.getElementById('btn-report');
const reportOverlay = document.getElementById('report-overlay');
const reportReason = document.getElementById('report-reason');
const btnReportCancel = document.getElementById('btn-report-cancel');
const btnReportSubmit = document.getElementById('btn-report-submit');
const reportError = document.getElementById('report-error');

// ================================================================
// 举报功能
// ================================================================
btnReport.addEventListener('click', () => {
    reportReason.value = '';
    reportError.style.display = 'none';
    reportOverlay.style.display = 'flex';
    reportReason.focus();
});

btnReportCancel.addEventListener('click', () => {
    reportOverlay.style.display = 'none';
});

reportOverlay.addEventListener('click', (e) => {
    if (e.target === reportOverlay) {
        reportOverlay.style.display = 'none';
    }
});

btnReportSubmit.addEventListener('click', () => {
    const reason = reportReason.value.trim();
    if (!reason) {
        reportError.style.display = 'block';
        reportError.textContent = '请填写举报原因';
        return;
    }

    // 通过游戏 WS 发送举报
    if (typeof game !== 'undefined' && game._transport) {
        const ws = game._transport._ws;
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'report', reason: reason }));
            reportOverlay.style.display = 'none';
            // 结果由 report_result 消息异步返回
        }
    }
});

// 封禁按钮（管理员专用）
function addBanButton() {
    const existing = document.getElementById('btn-admin-ban');
    if (existing) return;

    const opponentInfo = document.querySelector('.opponent-info');
    if (!opponentInfo) return;

    const banBtn = document.createElement('button');
    banBtn.id = 'btn-admin-ban';
    banBtn.className = 'doodle-btn';
    banBtn.style.cssText = 'font-size:13px;padding:4px 10px;color:let(--danger);border-color:let(--danger);margin-left:8px;';
    banBtn.innerHTML = `
        <svg class="icon" viewBox="0 0 24 24" style="width:14px;height:14px;">
            <circle cx="12" cy="12" r="10" />
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
        </svg>
        封禁
    `;
    banBtn.addEventListener('click', () => {
        showBanReasonDialog('对方', (reason) => {
            transport._ws.send(JSON.stringify({
                type: 'admin_ban',
                token: adminToken,
                reason: reason,
            }));
        });
    });

    opponentInfo.appendChild(banBtn);
}

/**
 * 管理员封禁原因输入弹窗（公用于游戏内和旁观模式）
 * @param {string} targetLabel 被封对象的称呼（如"对方""玩家A"）
 * @param {Function} callback 确认回调，接收 reason 字符串参数
 */
function showBanReasonDialog(targetLabel, callback, defaultReason = '', onCancel = null) {
    // 移除已有的弹窗（防重复）
    const existing = document.getElementById('ban-reason-overlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'ban-reason-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
        <div class="doodle-border" style="padding:24px;max-width:360px;width:90%;background:#fff;">
            <h2 style="font-size:18px;color:#e74c3c;margin:0 0 4px;">
                <svg class="icon" viewBox="0 0 24 24" style="width:18px;height:18px;">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
                </svg>
                封禁${targetLabel}
            </h2>
            <p style="margin:0 0 16px;font-size:13px;color:#555;">将永久禁止该 IP 和浏览器指纹访问</p>
            <textarea id="ban-reason-input" maxlength="200" placeholder="封禁原因（可选，如恶意刷屏、人身攻击等）"
                style="width:100%;height:80px;padding:12px;border:2px solid let(--ink-black);border-radius:10px;font-size:14px;resize:none;box-sizing:border-box;outline:none;margin-bottom:16px;">${escapeHtml(defaultReason)}</textarea>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="doodle-btn" id="ban-reason-cancel" style="font-size:14px;">取消</button>
                <button class="doodle-btn" id="ban-reason-confirm" style="font-size:14px;background:let(--ink-blue);color:let(--surface-white);border-color:let(--ink-blue);">确认封禁</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const input = document.getElementById('ban-reason-input');
    document.getElementById('ban-reason-confirm').addEventListener('click', () => {
        overlay.remove();
        callback((input.value || '').trim());
    });
    const closeOverlay = () => {
        overlay.remove();
        if (onCancel) onCancel();
    };
    document.getElementById('ban-reason-cancel').addEventListener('click', closeOverlay);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeOverlay();
    });
    input.focus();
}

// ================================================================
// WebSocket Transport 原型增强（注入 token + fingerprint + 新消息处理）
// ================================================================
const origConnect = WebSocketTransport.prototype.connect;
WebSocketTransport.prototype.connect = function (nickname, duration, password) {
    const wsUrl = this._url;

    // 保存参数供自动重连使用
    this._lastNickname = nickname || '';
    this._lastDuration = duration || 600;
    this._lastPassword = password || '';

    // 取消pending的重连timer，避免onclose和visibilitychange双重触发
    if (this._reconnectTimer) {
        clearTimeout(this._reconnectTimer);
        this._reconnectTimer = null;
    }

    // 关闭旧连接（如果有），避免旧onopen引用被新ws覆盖
    if (this._ws) {
        try { this._ws.onopen = null; this._ws.onclose = null; this._ws.onerror = null; this._ws.onmessage = null; } catch (e) { }
        try { this._ws.close(); } catch (e) { }
        this._ws = null;
    }

    const ws = new WebSocket(wsUrl);
    this._ws = ws;

    DebugLogger.log('ws', 'WebSocket连接创建', {
        hasNickname: !!nickname,
        bufferedAmount: 0,
        readyState_after_new: this._ws.readyState
    });

    ws.onopen = () => {
        // 重连成功，重置计数
        this._reconnectAttempts = 0;
        this._intentionalClose = false;
        this._lastPongTime = Date.now();

        // 隐藏断连覆盖层
        if (game) game._hideReconnectOverlay();

        // 更新连接状态指示器
        updateConnIndicator('online');

        // 启动心跳：每 25 秒发送一次 ping
        let _hbCount = 0;
        this._heartbeatTimer = setInterval(() => {
            if (this._ws && this._ws.readyState === WebSocket.OPEN) {
                this._ws.send(JSON.stringify({ type: 'ping' }));
                _hbCount++;
                // 每 5 次心跳记录一条日志
                if (_hbCount % 5 === 0) {
                    DebugLogger.log('ws', '心跳ping #' + _hbCount, { readyState: this._ws.readyState });
                }
                // 超过 60 秒没收到 pong，主动断开触发重连
                if (this._lastPongTime && (Date.now() - this._lastPongTime) > 60000) {
                    DebugLogger.log('ws', 'pong超时60s，主动断开重连');
                    this._ws.close();
                }
            }
        }, 25000);

        DebugLogger.log('ws', 'WebSocket onopen', { nickname: nickname || '(preconnect)' });

        // 验证连接仍属于当前 ws（防止竞态：旧连接onopen触发时this._ws已被新连接覆盖）
        if (ws.readyState === WebSocket.OPEN) {
            // 仅当有 nickname 时发送 join（preconnect 不发送）
            if (nickname) {
                DebugLogger.log('match', '发送join请求', { nickname: nickname, duration: duration || 600, has_session: !!this._lastSessionId });
                const joinPayload = {
                    type: 'join',
                    nickname: nickname,
                    duration: duration || 600,
                    token: adminToken,
                    fingerprint: browserFingerprint,
                    player_token: getUserToken() || undefined,
                    password: this._lastPassword || undefined,
                };
                // 重连时带上旧会话 ID，后端可恢复而非重新匹配
                if (this._lastSessionId) {
                    joinPayload.reconnect_session_id = this._lastSessionId;
                }
                ws.send(JSON.stringify(joinPayload));
            }
        }
    };

    ws.onmessage = (event) => {
        let data;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            DebugLogger.log('error', 'WebSocket JSON解析失败', { raw_len: event.data ? event.data.length : 0, error: e.message });
            console.warn('[WS] JSON parse error, raw data:', event.data);
            return;
        }
        // 管理员 token 验证回调（admin.js 注入 _adminHandler）
        if (this._adminHandler && this._adminHandler(data)) return;
        switch (data.type) {
            case 'pong':
                this._lastPongTime = Date.now();
                break;
            case 'matched':
                DebugLogger.log('match', '收到matched事件', { opponent: data.opponent_name, session_id: data.session_id, duration: data.duration, elapsed_ms: window._matchStartTs ? Date.now() - window._matchStartTs : -1 });
                this._lastSessionId = data.session_id || '';
                if (data.token && !getUserToken()) setUserToken(data.token);
                // 每次进入对局（首次匹配 / 重连恢复）拉取最新表情列表
                ws.send(JSON.stringify({ type: 'get_stickers', version: getStickerCacheVersion(), player_token: getUserToken() }));
                this._emit('connected', {
                    opponent_name: data.opponent_name,
                    duration: data.duration,
                    session_id: data.session_id,
                });
                if (adminToken) {
                    setTimeout(addBanButton, 100);
                }
                break;
            case 'message':
                DebugLogger.log('game', '收到对方消息', { sender: data.sender, len: data.text ? data.text.length : 0 });
                this._emit('message', {
                    text: data.text,
                    sender: data.sender,
                });
                break;
            case 'system':
                DebugLogger.log('game', '系统消息', { text: data.text });
                if (data.text && (data.text.includes('活跃连接') || data.text.includes('已在其他地方登录'))) {
                    this._preventReconnect = true;
                    this._intentionalClose = true;
                }
                this._emit('system', { text: data.text });
                if (data.text && (data.text.includes('活跃连接') || data.text.includes('已在其他地方登录'))) {
                    if (this._ws) this._ws.close();
                }
                break;
            case 'judged':
                DebugLogger.log('game', '对方已判定', { truth: data.truth, session_id: data.session_id });
                if (data.token && !getUserToken()) setUserToken(data.token);
                this._emit('opponent_judged', {
                    truth: data.truth,
                    opponent_guess: data.opponent_guess,
                    opponent_tag: data.opponent_tag || '',
                    session_id: data.session_id,
                    opponent_name: data.opponent_name,
                });
                break;
            case 'judge_notify':
                DebugLogger.log('game', '判定通知', { message: data.message });
                this._emit('judge_notify', { message: data.message, seconds_remaining: data.seconds_remaining });
                break;
            case 'timeout':
                DebugLogger.log('game', '收到timeout事件', { reason: data.reason, session_id: data.session_id });
                if (data.token && !getUserToken()) setUserToken(data.token);
                this._emit('opponent_timeout', {
                    reason: data.reason,
                    session_id: data.session_id,
                    opponent_truth: data.opponent_truth,
                    opponent_name: data.opponent_name,
                });
                break;
            case 'error':
                DebugLogger.log('error', '服务端错误', { message: data.message });
                // 该IP已有活跃连接 → 阻止自动重连
                if (data.message && data.message.includes('已有活跃连接')) {
                    this._preventReconnect = true;
                    this._intentionalClose = true;
                    this._emit('system', { text: data.message });
                    if (this._ws) this._ws.close();
                } else if (data.message && data.message.includes('封禁') && !data.message.includes('无需封禁')) {
                    this._emit('banned', { message: data.message });
                } else {
                    // 匹配阶段错误：显示在匹配页并返回首页
                    if (matchingPage.style.display === 'flex') {
                        showMatchError(data.message);
                    } else {
                        this._emit('system', { text: data.message });
                    }
                }
                break;
            case 'report_result':
                if (data.success) {
                    alert(data.message || '举报已提交');
                } else {
                    alert(data.message || '举报失败');
                }
                break;
            case 'online_count':
                updateOnlineCarousel(data.count);
                break;
            case 'broadcast':
                showDanmaku(data.text, '全服公告', data.duration || 0);
                break;
            case 'room_announce':
                showDanmaku(data.text, '管理警告');
                break;
            case 'banned':
                showTopToast(data.text);
                break;
            case 'opponent_banned':
                stopChat();
                this._emit('system', { text: data.text });
                this._emit('opponent_timeout', {
                    reason: 'opponent_banned',
                    opponent_truth: data.opponent_truth,
                });
                break;
            case 'save_history_status':
                this._emit('save_history_status', data);
                break;
            case 'leave_message_status':
                this._emit('leave_message_status', data);
                break;
            case 'share_record_status':
                this._emit('share_record_status', data);
                break;
            case 'stickers_list':
                stickerMap = handleStickersList(data);
                break;
            case 'stickers_unchanged':
                stickerMap = loadStickerCache();
                break;
            case 'sticker':
                // 收到对手发来的表情
                if (data.id && (stickerMap[data.id] || data.url)) {
                    appendSticker(data.id, data.name || (stickerMap[data.id] && stickerMap[data.id].name) || '', 'left', data.sender || '对方', data.url);
                    botMsgCount++;
                    updateJudgementState(game._judgementAllowed);
                }
                break;
            case 'update_nickname_result':
                document.dispatchEvent(new CustomEvent('nickname_update_result', { detail: data }));
                break;
            default:
                break;
        }
    };

    ws.onerror = () => {
        DebugLogger.log('error', 'WebSocket onerror触发');
        this._emit('error', { text: 'WebSocket 连接失败' });
    };

    ws.onclose = () => {
        DebugLogger.log('ws', 'WebSocket onclose触发', { intentional: this._intentionalClose, attempts: this._reconnectAttempts });

        // 清理心跳
        if (this._heartbeatTimer) {
            clearInterval(this._heartbeatTimer);
            this._heartbeatTimer = null;
        }

        // 更新连接状态指示器
        updateConnIndicator('offline');

        // 主动关闭（用户离开或 reset）→ 不重连
        // 或者后端返回"已有活跃连接"错误 → 不重连
        if (this._intentionalClose || this._preventReconnect) {
            this._intentionalClose = false;
            // 隐藏覆盖层
            if (game) game._hideReconnectOverlay();
            this._emit('disconnected', {});
            return;
        }

        // 指数退避重连：1s, 2s, 4s, 8s, 16s, 30s...
        const maxDelay = 30000;
        const delay = Math.min(1000 * Math.pow(2, this._reconnectAttempts), maxDelay);
        this._reconnectAttempts++;

        // 更新覆盖层进度点
        if (game) game._updateReconnectProgress(this._reconnectAttempts, 5);

        DebugLogger.log('ws', '自动重连', { attempt: this._reconnectAttempts, delay_ms: delay });

        this._reconnectTimer = setTimeout(() => {
            this._reconnectTimer = null;
            // 如果已经连上了，跳过
            if (this._ws && this._ws.readyState === WebSocket.OPEN) return;
            this._ws = null;
            // 只在匹配页或聊天页时发 join（结果页/首页只建连接不入队列，防止误进对局后再被 timeout 弹窗覆盖）
            const shouldJoin = matchingPage.style.display === 'flex' || chatPage.style.display === 'flex';
            this.connect(shouldJoin ? (this._lastNickname || '') : '', this._lastDuration || 600);
        }, delay);

        // 仅首次断开通知 UI
        if (this._reconnectAttempts === 1) {
            // 对局中且覆盖层未显示时，显示覆盖层
            if (chatPage.style.display === 'flex' && game) {
                game._showReconnectOverlay('reconnecting');
            }
            this._emit('disconnected', { reconnecting: true });
        }
    };
};

// reconnect: 复用现有 WS 连接发 join；WS 已断则重新计算 PoW 参数后再建连
WebSocketTransport.prototype.reconnect = function (nickname, duration, password) {
    if (this._ws && this._ws.readyState === WebSocket.OPEN) {
        this._lastPassword = password || '';
        DebugLogger.log('match', '复用现有WS发送join', { nickname: nickname, readyState: this._ws.readyState });
        this._ws.send(JSON.stringify({
            type: 'join',
            nickname: nickname,
            duration: duration || 600,
            token: adminToken,
            fingerprint: browserFingerprint,
            player_token: getUserToken() || undefined,
            password: password || undefined,
        }));
        return;
    }
    DebugLogger.log('ws', 'WS已断开，直接重连', { readyState: this._ws ? this._ws.readyState : 'null' });
    this.connect(nickname, duration, password);
};

// preconnect: 页面加载即建立 WS 并启动心跳，不发送 join
WebSocketTransport.prototype.preconnect = function () {
    if (this._ws && (this._ws.readyState === WebSocket.OPEN || this._ws.readyState === WebSocket.CONNECTING)) {
        return;
    }
    this.connect('');  // nickname 为空，onopen 只启心跳不发 join
};

// ================================================================
//  连接状态指示器
// ================================================================

/** 更新聊天页顶部连接状态指示点 */
function updateConnIndicator(state) {
    const indicator = document.getElementById('conn-indicator');
    const label = document.getElementById('conn-label');
    if (!indicator) return;

    if (state === 'online') {
        indicator.className = 'connection-indicator online';
        if (label) label.textContent = '在线';
    } else {
        indicator.className = 'connection-indicator offline';
        if (label) label.textContent = '掉线';
    }

    // 只在聊天页显示
    indicator.style.display = (chatPage.style.display === 'flex') ? 'flex' : 'none';
}

// ================================================================
//  断连兜底按钮事件绑定
// ================================================================

/** 覆盖层「重试连接」按钮 */
document.getElementById('btn-reconnect-retry').addEventListener('click', function () {
    if (!transport || !game) return;

    // 更新覆盖层 UI
    const desc = document.getElementById('reconnect-desc');
    if (desc) desc.textContent = '正在手动重连，请稍候...';
    this.disabled = true;

    // 重置重连计数，立即触发连接
    transport._intentionalClose = false;
    transport._preventReconnect = false;
    transport._reconnectAttempts = 0;
    transport._lastPongTime = 0;

    // 取消 pending timer
    if (transport._reconnectTimer) {
        clearTimeout(transport._reconnectTimer);
        transport._reconnectTimer = null;
    }

    transport.connect(transport._lastNickname || '', transport._lastDuration || 600);
});

/** 覆盖层「返回首页」按钮 */
document.getElementById('btn-reconnect-home').addEventListener('click', function () {
    if (!game) return;
    game._hideReconnectOverlay();
    game.reset();
});

// 点击覆盖层空白区域不关闭（防止误操作）
document.getElementById('reconnect-overlay').addEventListener('click', function (e) {
    // 只在点击半透明背景（非卡片区域）时不做任何操作
    // 防止玩家误点空白导致关闭覆盖层
});

// ================================================================
// 初始化传输层和游戏客户端
// ================================================================
let transport, game;
let gameClientInited = false;

// 懒初始化游戏 WS 客户端：普通首页加载时立即预连接；
// 直接访问 /player/xxx（个人资料页）或 /collection/xxx（公开收藏页）时先不建连，
// 等用户返回首页点击开始匹配时再创建，避免资料页/收藏页白白占用一个 WS 连接
function ensureGameClient() {
    if (gameClientInited) return;
    gameClientInited = true;
    try {
        const wsProtocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        transport = new WebSocketTransport(wsProtocol + window.location.host + '/ws');
        game = new GameClient(transport);
        transport.preconnect();  // 建立 WS 连接并启动心跳，onopen 不发 join
        DebugLogger.log('lifecycle', 'WebSocket preconnect已调用');
        console.log('[Turing] Game client ready');
    } catch (e) {
        gameClientInited = false;  // 初始化失败允许下次重试
        throw e;
    }
}

(async function () {
    // 记录环境信息
    let conn = (navigator.connection || navigator.mozConnection || navigator.webkitConnection);
    DebugLogger.log('lifecycle', '页面初始化开始', {
        ua: navigator.userAgent.substring(0, 120),
        platform: navigator.platform,
        screen: screen.width + 'x' + screen.height + '@' + (window.devicePixelRatio || 1),
        url: window.location.host,
        online: navigator.onLine,
        network: conn ? { type: conn.effectiveType, downlink: conn.downlink, rtt: conn.rtt, saveData: conn.saveData } : 'unknown',
        lang: navigator.language,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
    });
    // 公开收藏页 / 个人资料页只做展示，无需立即建立游戏 WS 连接
    const isPublicCollection = !!parseCollectionPath();
    const isProfileView = !!parseProfilePath();
    try {
        if (!isPublicCollection && !isProfileView) {
            ensureGameClient();
        }
        btnStart.disabled = false;
        btnStart.textContent = '马上开始匹配';
    } catch (e) {
        DebugLogger.log('error', '页面初始化失败', { error: e.message });
        console.error('Game init failed:', e);
    }

})();

// ================================================================
// 主题管理（默认 / 暗色 / 跟随系统）

// ================================================================
//  个人战绩记录
// ================================================================

function getPlayerId() { return getUserToken(); }
function savePlayerId(pid) { }

function updateLbUI() {
    const pid = getPlayerId();

    // 设置面板中的战绩展示
    const recoverArea = document.getElementById('lb-recover-area');
    const myStatsEl = document.getElementById('lb-my-stats');

    if (pid) {
        recoverArea.style.display = 'none';
        myStatsEl.style.display = '';

        // 用本地存储的数据刷新战绩数字
        const localStats = getUserStats();
        if (localStats) {
            const totalGames = localStats.total || 0;
            const wins = localStats.wins || 0;
            const losses = localStats.losses || 0;
            updateLbMyStats({
                turing_test: {
                    wins: wins,
                    losses: losses,
                    timeouts: localStats.timeouts || 0,
                },
                WhoisAI: { wins: 0, losses: 0 },
                total_games: totalGames,
                win_rate: totalGames > 0 ? Math.round(wins / totalGames * 100) : 0,
            });
        }
    } else {
        recoverArea.style.display = '';
        myStatsEl.style.display = 'none';
    }

    // 聊天记录回顾区域
    let historySection = document.getElementById('chat-history-section');
    if (historySection) {
        if (getUserToken()) {
            historySection.style.display = '';
            loadChatHistoryList(1);
        } else {
            historySection.style.display = 'none';
        }
    }

    // 对手留言管理区域
    let msgManageSection = document.getElementById('message-manage-section');
    if (msgManageSection) {
        if (pid) {
            msgManageSection.style.display = '';
            loadMessageManageList(pid);
        } else {
            msgManageSection.style.display = 'none';
        }
    }

    // 佩戴标签区域
    let wornTagsSection = document.getElementById('worn-tags-section');
    if (wornTagsSection) {
        if (pid) {
            wornTagsSection.style.display = '';
            loadWornTags();
        } else {
            wornTagsSection.style.display = 'none';
        }
    }

    // OAuth 绑定管理区域
    let oauthSection = document.getElementById('oauth-bindings-section');
    if (oauthSection) {
        if (getUserToken()) {
            loadOAuthBindingsUI();
        } else {
            oauthSection.style.display = 'none';
        }
    }
}

// ================================================================
//  佩戴标签（设置页）
// ================================================================

let wornTagsMax = 3;
let wornTagsSelected = [];
let wornSpecialSelected = [];

function loadWornTags() {
    const tok = getUserToken();
    const listEl = document.getElementById('worn-tags-list');
    const statusEl = document.getElementById('worn-tags-status');
    if (!tok || !listEl) return;

    fetch('/api/player/tags', {
        headers: { 'Authorization': 'Bearer ' + tok }
    })
    .then(r => r.json())
    .then(data => {
        wornTagsMax = data.max || 3;
        wornTagsSelected = Array.isArray(data.worn) ? data.worn.slice() : [];
        wornSpecialSelected = Array.isArray(data.worn_special) ? data.worn_special.slice() : [];

        // 官方特殊称号：可自选佩戴，独立于普通名额
        const special = Array.isArray(data.special) ? data.special : [];
        const specialEl = document.getElementById('worn-tags-special');
        if (specialEl) {
            specialEl.style.display = special.length ? 'flex' : 'none';
            specialEl.innerHTML = '';
            if (special.length) {
                const label = document.createElement('span');
                label.className = 'worn-special-label';
                label.textContent = '官方称号';
                specialEl.appendChild(label);
                special.forEach(t => {
                    const chip = document.createElement('span');
                    chip.className = 'worn-tag-chip special' + (wornSpecialSelected.indexOf(t) !== -1 ? ' selected' : '');
                    chip.textContent = t;
                    chip.title = '官方特殊称号，可自选佩戴（最多 ' + wornTagsMax + ' 个，不占普通名额）';
                    chip.addEventListener('click', function () {
                        const idx = wornSpecialSelected.indexOf(t);
                        if (idx !== -1) {
                            wornSpecialSelected.splice(idx, 1);
                        } else {
                            if (wornSpecialSelected.length >= wornTagsMax) {
                                const st = document.getElementById('worn-tags-status');
                                st.style.display = 'block';
                                st.style.color = 'var(--danger)';
                                st.textContent = '官方称号最多佩戴 ' + wornTagsMax + ' 个';
                                setTimeout(() => { st.style.display = 'none'; }, 2000);
                                return;
                            }
                            wornSpecialSelected.push(t);
                        }
                        chip.classList.toggle('selected', wornSpecialSelected.indexOf(t) !== -1);
                    });
                    specialEl.appendChild(chip);
                });
            }
        }

        // 普通标签（排除特殊标签，特殊已单独展示）
        const tags = (data.tags || []).filter(t => !t.is_special);
        if (!tags.length && (!special.length)) {
            listEl.innerHTML = '<span style="font-size:12px;color:var(--text-subtle);">还没有人给你贴过标签，去对局里赢取对手的评价吧～</span>';
            return;
        }
        if (!tags.length) {
            listEl.innerHTML = '<span style="font-size:12px;color:var(--text-subtle);">还没有可佩戴的普通标签</span>';
            return;
        }
        listEl.innerHTML = '';
        tags.forEach(t => {
            const chip = document.createElement('span');
            chip.className = 'worn-tag-chip' + (wornTagsSelected.indexOf(t.tag) !== -1 ? ' selected' : '');
            chip.textContent = t.tag + ' ×' + t.count;
            chip.dataset.tag = t.tag;
            chip.addEventListener('click', function () {
                const idx = wornTagsSelected.indexOf(t.tag);
                if (idx !== -1) {
                    wornTagsSelected.splice(idx, 1);
                } else {
                    if (wornTagsSelected.length >= wornTagsMax) {
                        const st = document.getElementById('worn-tags-status');
                        st.style.display = 'block';
                        st.style.color = 'var(--danger)';
                        st.textContent = '最多佩戴 ' + wornTagsMax + ' 个标签';
                        setTimeout(() => { st.style.display = 'none'; }, 2000);
                        return;
                    }
                    wornTagsSelected.push(t.tag);
                }
                chip.classList.toggle('selected', wornTagsSelected.indexOf(t.tag) !== -1);
            });
            listEl.appendChild(chip);
        });
        if (statusEl) statusEl.style.display = 'none';
    })
    .catch(() => {
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.color = 'var(--danger)';
            statusEl.textContent = '加载标签失败';
        }
    });
}

document.getElementById('btn-save-worn-tags').addEventListener('click', function () {
    const tok = getUserToken();
    const statusEl = document.getElementById('worn-tags-status');
    if (!tok) {
        statusEl.style.display = 'block';
        statusEl.style.color = 'var(--danger)';
        statusEl.textContent = '请先获取恢复码';
        return;
    }
    const btn = this;
    btn.disabled = true;
    btn.textContent = '保存中...';
    fetch('/api/player/worn-tags', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + tok
        },
        body: JSON.stringify({ tags: wornTagsSelected, special_tags: wornSpecialSelected })
    })
    .then(r => r.json())
    .then(res => {
        statusEl.style.display = 'block';
        if (res.success) {
            statusEl.style.color = 'var(--success)';
            statusEl.textContent = res.message || '已保存';
            wornTagsSelected = res.worn || wornTagsSelected;
            wornSpecialSelected = res.worn_special || wornSpecialSelected;
            document.querySelectorAll('#worn-tags-list .worn-tag-chip').forEach(chip => {
                chip.classList.toggle('selected', wornTagsSelected.indexOf(chip.dataset.tag) !== -1);
            });
            document.querySelectorAll('#worn-tags-special .worn-tag-chip.special').forEach(chip => {
                chip.classList.toggle('selected', wornSpecialSelected.indexOf(chip.textContent) !== -1);
            });
        } else {
            statusEl.style.color = 'var(--danger)';
            statusEl.textContent = res.message || '保存失败';
        }
        btn.disabled = false;
        btn.textContent = '保存佩戴';
    })
    .catch(() => {
        statusEl.style.display = 'block';
        statusEl.style.color = 'var(--danger)';
        statusEl.textContent = '网络错误';
        btn.disabled = false;
        btn.textContent = '保存佩戴';
    });
});

function updateLbMyStats(stats) {
    if (!stats) return;
    const tt = stats.turing_test || {};
    const hva = stats.WhoisAI || {};
    document.getElementById('lb-my-wins').textContent = (tt.wins || 0) + (hva.wins || 0);
    document.getElementById('lb-my-losses').textContent = (tt.losses || 0) + (hva.losses || 0);
    document.getElementById('lb-my-games').textContent = stats.total_games || 0;
    document.getElementById('lb-my-rate').textContent = (stats.win_rate !== undefined ? stats.win_rate + '%' : '-');
}

// 将服务器战绩全量覆盖到本地存储
function mergeServerStats(stats) {
    if (!stats) return;
    const tt = stats.turing_test || {};
    const hva = stats.WhoisAI || {};
    const local = {
        total: stats.total_games || 0,
        wins: (tt.wins || 0) + (hva.wins || 0),
        losses: (tt.losses || 0) + (hva.losses || 0),
        timeouts: tt.timeouts || 0,
        guessHuman: tt.guess_human || 0,
        guessAI: tt.guess_ai || 0,
        oppHuman: tt.opp_human || 0,
        oppAI: tt.opp_ai || 0,
        totalMsgs: tt.total_msgs || 0,
        totalDuration: tt.total_duration || 0,
        lastPlayed: ((stats.last_played_at || stats.created_at) || 0) * 1000,
    };
    saveStats(local);

    // 同步昵称（仅本地无昵称时更新；已有则不覆盖，防止五子棋自动生成的 Gomoku_xxx 覆盖正常昵称）
    if (stats.nickname && !getUserNickname()) {
        setUserNickname(stats.nickname);
        document.getElementById('nickname-input').value = stats.nickname;
    }
}

// ---- 导出战绩图 ----

async function exportStatsImage() {
    const pid = getPlayerId();
    const wins = document.getElementById('lb-my-wins').textContent;
    const losses = document.getElementById('lb-my-losses').textContent;
    const games = document.getElementById('lb-my-games').textContent;
    const rate = document.getElementById('lb-my-rate').textContent;

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;left:-9999px;top:0;width:400px;background:#f8f9fa;color:#2b2b2b;font-family:"PingFang SC","Microsoft YaHei",sans-serif;padding:0;z-index:-1;';

    // 头部
    const headerHTML = `
        <div style="padding:24px 28px;background:#d1f2d3;text-align:center;">
            <div style="font-size:24px;font-weight:bold;color:#2b2b2b;">我的战绩</div>
        </div>
    `;

    // 数据区
    const items = [
        { label: '胜', value: wins, color: '#2e7d32' },
        { label: '负', value: losses, color: '#c62828' },
        { label: '总场', value: games, color: '#555' },
        { label: '胜率', value: rate, color: '#1565c0' },
    ];
    const statsHTML = `
        <div style="padding:20px 24px;background:#fff;display:flex;justify-content:space-around;text-align:center;">
            ${items.map(it => `
                <div>
                    <div style="font-size:32px;font-weight:bold;color:${it.color};">${it.value}</div>
                    <div style="font-size:13px;color:#999;margin-top:2px;">${it.label}</div>
                </div>
            `).join('')}
        </div>
    `;

    // 底部（与聊天导出一致）
    const footerHTML = `
        <div style="padding:18px 24px;background:#fff;display:flex;align-items:center;justify-content:space-between;border-top:2px dashed #ccc;">
            <div style="font-size:20px;color:#2b2b2b;text-decoration:underline;text-decoration-color:#1e3799;text-decoration-style:wavy;text-underline-offset:6px;">
                <svg viewBox="0 0 24 24" style="width:22px;height:22px;display:inline-block;vertical-align:-5px;fill:none;stroke:#2b2b2b;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;margin-right:8px;">
                    <circle cx="11" cy="11" r="8" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                更好的图灵测试在线小游戏
            </div>
            <div style="text-align:center;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=https%3A%2F%2Fgame.xfcode.top%2F" width="80" height="80" style="display:block;" crossorigin="anonymous" />
                <div style="font-size:10px;color:#999;margin-top:4px;">扫码来玩</div>
            </div>
        </div>
    `;

    container.innerHTML = headerHTML + statsHTML + footerHTML;
    document.body.appendChild(container);

    try {
        const canvas = await html2canvas(container, {
            scale: 2,
            backgroundColor: '#f8f9fa',
            useCORS: true,
            // 黑夜模式下 [data-theme="dark"] * 会把导出容器的文字强制成浅色，导致图片文字不可读。
            // 在克隆文档中移除 dark 主题标记，让导出图始终按浅色卡片渲染（不影响真实页面）
            onclone: (doc) => {
                const root = doc.documentElement;
                if (root && root.hasAttribute('data-theme')) root.removeAttribute('data-theme');
            },
        });
        const link = document.createElement('a');
        link.download = '我的战绩_' + pid + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    } finally {
        document.body.removeChild(container);
    }
}

// ---- 自动初始化 token ----

function autoInitPlayerId() {
    if (getPlayerId()) {
        updateLbUI();
        return;
    }
    updateLbUI();
}

document.getElementById('btn-export-stats').addEventListener('click', exportStatsImage);

// 战绩分享到聊天室：复用首页已有 WS 连接发送请求，服务端读取真实战绩生成卡片（防伪造）
document.getElementById('btn-share-record').addEventListener('click', function () {
    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) {
        showTopToast('连接未就绪，请稍后再试', true);
        return;
    }
    showTopToast('正在生成战绩卡片...', false);
    try {
        transport.send('share_record', { player_token: getUserToken() || '' });
    } catch (e) {
        showTopToast('分享失败，请重试', true);
    }
});

// 改密码
document.getElementById('btn-change-password').addEventListener('click', () => {
    const form = document.getElementById('change-password-form');
    form.style.display = form.style.display === 'none' ? '' : 'none';
});

document.getElementById('btn-cp-cancel').addEventListener('click', () => {
    document.getElementById('change-password-form').style.display = 'none';
    document.getElementById('cp-msg').style.display = 'none';
    document.getElementById('cp-old-password').value = '';
    document.getElementById('cp-new-password').value = '';
});

document.getElementById('btn-cp-submit').addEventListener('click', () => {
    const oldPwd = document.getElementById('cp-old-password').value;
    const newPwd = document.getElementById('cp-new-password').value;
    const msgEl = document.getElementById('cp-msg');

    if (!oldPwd || !newPwd) {
        msgEl.textContent = '请填写旧密码和新密码';
        msgEl.style.display = '';
        return;
    }
    if (newPwd.length < 6) {
        msgEl.textContent = '新密码至少6位';
        msgEl.style.display = '';
        return;
    }

    const token = getPlayerId();
    if (!token) {
        msgEl.textContent = '请先开始一局游戏获取身份';
        msgEl.style.display = '';
        return;
    }

    fetch('/api/generate-player-id?action=change_password&old_password=' + encodeURIComponent(oldPwd) + '&new_password=' + encodeURIComponent(newPwd) + '&fp=' + encodeURIComponent(browserFingerprint), {
        headers: { 'Authorization': 'Bearer ' + token }
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                msgEl.textContent = data.error;
                msgEl.style.display = '';
                return;
            }
            if (data.token) setUserToken(data.token);
            msgEl.textContent = '密码修改成功';
            msgEl.style.color = '#28a745';
            msgEl.style.display = '';
            document.getElementById('change-password-form').style.display = 'none';
            document.getElementById('cp-old-password').value = '';
            document.getElementById('cp-new-password').value = '';
        })
        .catch(() => {
            msgEl.textContent = '网络错误，请重试';
            msgEl.style.display = '';
        });
});

// 查看公开资料
document.getElementById('btn-open-profile').addEventListener('click', function () {
    const nickname = getUserNickname();
    if (!nickname) {
        alert('请先在首页填写昵称');
        return;
    }
    history.pushState(null, '', '/player/' + encodeURIComponent(nickname));
    hideAllPagesForProfile();
    showProfilePage();
    loadProfile(nickname);
    // 关闭设置面板
    settingsOverlay.style.display = 'none';
});

// ---- 密码找回输入事件 ----

document.getElementById('btn-recover-lb').addEventListener('click', () => {
    const password = document.getElementById('lb-recover-input').value;
    if (!password || password.length < 6) {
        alert('请输入密码（6位以上）');
        return;
    }
    const nickname = getUserNickname();
    if (!nickname) {
        alert('请先在首页填写昵称');
        return;
    }
    fetch('/api/generate-player-id?action=recover&nickname=' + encodeURIComponent(nickname) + '&password=' + encodeURIComponent(password) + '&fp=' + encodeURIComponent(browserFingerprint))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }
            if (data.token) setUserToken(data.token);
            updateLbUI();
            if (data.stats) { updateLbMyStats(data.stats); mergeServerStats(data.stats); }
            document.getElementById('lb-recover-input').value = '';
        })
        .catch(() => alert('网络错误，请稍后重试'));
});

document.getElementById('lb-recover-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') document.getElementById('btn-recover-lb').click();
});

// ================================================================
//  聊天记录回顾
// ================================================================

/** 加载聊天记录列表 */
function loadChatHistoryList(page) {
    const pid = getPlayerId();
    if (!pid) return;

    _chatHistoryPage = page || 1;

    const listEl = document.getElementById('chat-history-list');
    listEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';

    fetch('/api/chat-history?page=' + _chatHistoryPage, {
        headers: { 'Authorization': 'Bearer ' + pid }
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                listEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;">' + escapeHtml(data.error) + '</div>';
                return;
            }
            renderChatHistoryList(data);
        })
        .catch(() => {
            listEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;">加载失败</div>';
        });
}

function renderChatHistoryList(data) {
    const listEl = document.getElementById('chat-history-list');
    const pagEl = document.getElementById('chat-history-pagination');

    if (!data.list || data.list.length === 0) {
        listEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">暂无保存的聊天记录</div>';
        pagEl.innerHTML = '';
        return;
    }

    const resultLabels = { 'win': '胜', 'lose': '负', 'draw': '平' };
    const guessLabels = { 'human': '猜人类', 'ai': '猜AI' };
    const truthLabels = { 'human': '对方是人类', 'ai': '对方是AI' };

    let html = '';
    data.list.forEach(item => {
        const time = item.created_at ? item.created_at.substring(0, 16).replace('T', ' ') : '';
        const resultBadge = resultLabels[item.result] || '';
        const resultColor = item.result === 'win' ? '#4caf50' : (item.result === 'lose' ? '#f44336' : '#999');
        const displayTitle = item.title ? escapeHtml(item.title) : (escapeHtml(item.player_name) + ' vs ' + escapeHtml(item.opponent_name));
        const titleIcon = item.title ? '&#128278; ' : '';
        const publicBadge = item.is_public ? '<span style="font-size:10px;color:let(--ink-blue);margin-left:4px;">公开</span>' : '';
        html += `
            <div class="chat-history-row doodle-border" data-id="${item.id}" style="padding:8px 10px;margin-bottom:6px;background:let(--note-green);cursor:pointer;font-size:13px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span>${titleIcon}<b>${displayTitle}</b>${publicBadge}</span>
                    <span style="color:${resultColor};font-weight:bold;font-size:12px;">${resultBadge}</span>
                </div>
                <div style="font-size:11px;color:#888;margin-top:2px;">
                    ${guessLabels[item.player_guess] || '未判定'} · ${truthLabels[item.opponent_truth] || ''} · ${item.message_count}条消息 · ${time}
                    ${item.likes ? '<span style="margin-left:6px;">&#10084; ' + item.likes + '</span>' : ''}
                </div>
            </div>
        `;
    });
    listEl.innerHTML = html;

    // 点击打开详情
    listEl.querySelectorAll('.chat-history-row').forEach(row => {
        row.addEventListener('click', () => {
            const id = parseInt(row.dataset.id);
            if (id) showChatHistoryDetail(id);
        });
    });

    // 分页
    const totalPages = Math.ceil(data.total / data.page_size);
    if (totalPages <= 1) {
        pagEl.innerHTML = '';
        return;
    }
    let pageHtml = '';
    for (let i = 1; i <= totalPages; i++) {
        if (i === data.page) {
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:let(--ink-blue);color:let(--surface-white);border-radius:4px;margin:0 2px;">' + i + '</span>';
        } else {
            pageHtml += '<span style="cursor:pointer;padding:2px 8px;border:1px solid let(--ink-blue);border-radius:4px;margin:0 2px;" data-pg="' + i + '">' + i + '</span>';
        }
    }
    pagEl.innerHTML = pageHtml;
    pagEl.querySelectorAll('[data-pg]').forEach(el => {
        el.addEventListener('click', () => loadChatHistoryList(parseInt(el.dataset.pg)));
    });
}

/** 对手留言管理：加载留言列表 */
function loadMessageManageList(token) {
    const listEl = document.getElementById('message-manage-list');
    const allowLabel = document.getElementById('message-allow-label');
    const allowToggle = document.getElementById('message-allow-toggle');
    if (!listEl) return;

    listEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;font-size:12px;">加载中...</div>';

    fetch('/api/player-messages', {
        headers: { 'Authorization': 'Bearer ' + token }
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                listEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;font-size:12px;">' + escapeHtml(data.error) + '</div>';
                return;
            }

            const msgs = data.messages || [];
            const allow = data.allow_messages !== false;

            // 允许留言开关
            if (allowLabel) {
                allowLabel.style.display = 'flex';
                allowToggle.checked = allow;
                allowToggle.onchange = function () {
                    fetch('/api/player-message/settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + token
                        },
                        body: JSON.stringify({ allow_messages: allowToggle.checked }),
                    });
                };
            }

            if (msgs.length === 0) {
                listEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;font-size:12px;">暂无留言</div>';
                return;
            }

            let html = '';
            msgs.forEach(msg => {
                const time = msg.created_at ? new Date(msg.created_at * 1000).toLocaleString('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
                const hidden = msg.hidden ? true : false;
                html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed #ddd;font-size:12px;">' +
                    '<div style="flex:1;min-width:0;">' +
                        '<span style="font-weight:bold;">' + escapeHtml(msg.from) + '</span>' +
                        '<span style="' + (hidden ? 'text-decoration:line-through;color:#999;' : '') + '"> ' + escapeHtml(msg.text) + '</span>' +
                        '<span style="color:#999;margin-left:4px;">' + time + '</span>' +
                    '</div>' +
                    '<button class="doodle-btn msg-hide-btn" data-id="' + escapeHtml(msg.id) + '" style="font-size:11px;padding:2px 8px;white-space:nowrap;">' + (hidden ? '显示' : '隐藏') + '</button>' +
                '</div>';
            });
            listEl.innerHTML = html;

            // 绑定隐藏/显示事件
            listEl.querySelectorAll('.msg-hide-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const msgId = this.dataset.id;
                    const isHidden = this.textContent === '隐藏';
                    this.disabled = true;
                    this.textContent = '...';

                    fetch('/api/player-message/hide', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + token
                        },
                        body: JSON.stringify({ message_id: msgId, hidden: isHidden }),
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            loadMessageManageList(token);
                        } else {
                            this.disabled = false;
                            this.textContent = isHidden ? '隐藏' : '显示';
                        }
                    })
                    .catch(() => {
                        this.disabled = false;
                        this.textContent = isHidden ? '隐藏' : '显示';
                    });
                });
            });
        })
        .catch(() => {
            listEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;font-size:12px;">网络错误</div>';
        });
}

/** 显示聊天记录详情 */
function showChatHistoryDetail(id) {
    const token = getUserToken();
    if (!token) return;

    const overlay = document.getElementById('chat-history-detail-overlay');
    const titleEl = document.getElementById('chat-detail-title');
    const infoEl = document.getElementById('chat-detail-info');
    const msgEl = document.getElementById('chat-detail-messages');

    titleEl.textContent = '加载中...';
    infoEl.innerHTML = '';
    msgEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px;">加载中...</div>';
    settingsOverlay.style.display = 'none';
    changelogOverlay.style.display = 'none';
    overlay.style.display = 'flex';

    fetch('/api/chat-history/detail?id=' + id, {
        headers: { 'Authorization': 'Bearer ' + token }
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                msgEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:20px;">' + escapeHtml(data.error) + '</div>';
                return;
            }

            const resultLabels = { 'win': '胜利', 'lose': '失败', 'draw': '平局' };
            const time = data.created_at ? data.created_at.substring(0, 16).replace('T', ' ') : '';
            const displayTitle = data.title ? escapeHtml(data.title) : (escapeHtml(data.player_name) + ' vs ' + escapeHtml(data.opponent_name));

            titleEl.innerHTML = displayTitle;

            // 基本信息行
            infoEl.innerHTML = `
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:4px;background:let(--ink-black);color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:bold;">
                        ${resultLabels[data.result] || '??'}
                    </div>
                    <span style="font-size:12px;color:let(--text-subtle);">${time}</span>
                    <span style="font-size:12px;color:let(--text-subtle);">${data.message_count}条消息</span>
                </div>
            `;

            // 收藏编辑区
            const hasPublicToken = data.public_token ? true : false;
            const publicUrl = hasPublicToken ? (window.location.origin + '/collection/' + escapeHtml(data.public_token || '')) : '';
            infoEl.innerHTML += `
                <div style="background:let(--bg);border:1.5px dashed let(--ink-black);border-radius:8px;padding:12px;">
                    <div style="font-size:11px;color:let(--text-subtle);margin-bottom:8px;font-weight:bold;">收藏管理</div>
                    <input type="text" id="detail-title-input" placeholder="为此记录起个名字" maxlength="100"
                        style="width:100%;padding:6px 10px;border:1.5px solid let(--ink-black);border-radius:6px;font-size:13px;margin-bottom:8px;background:let(--bg);color:let(--text);box-sizing:border-box;"
                        value="${escapeHtml(data.title || '')}">
                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:8px;cursor:pointer;">
                        <input type="checkbox" id="detail-public-check" ${data.is_public ? 'checked' : ''}>
                        公开聊天记录
                    </label>
                    <div id="detail-public-link" style="display:${hasPublicToken ? 'block' : 'none'};margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <input type="text" id="detail-public-url" readonly
                                style="flex:1;padding:5px 8px;border:1.5px solid let(--ink-black);border-radius:6px;font-size:11px;background:let(--bg);color:let(--text);"
                                value="${publicUrl}">
                            <button class="doodle-btn" id="btn-copy-public-link" style="font-size:11px;padding:5px 12px;">复制</button>
                        </div>
                    </div>
                    <button class="doodle-btn" id="btn-detail-collection-save" style="width:100%;justify-content:center;font-size:12px;padding:6px;">保存</button>
                    <div id="detail-collection-status" style="display:none;text-align:center;font-size:11px;margin-top:6px;"></div>
                </div>
            `;

            if (!data.messages || data.messages.length === 0) {
                msgEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px;">无聊天消息</div>';
            } else {
                let msgHtml = '';
                data.messages.forEach(msg => {
                    const isRight = msg.side === 'right';
                    const bg = isRight ? '#d3e2ed' : '#fdf5c9';
                    const align = isRight ? 'flex-end' : 'flex-start';
                    const radius = isRight ? '12px 12px 0 12px' : '12px 12px 12px 0';
                    // 表情消息：渲染贴纸图片，避免因 text 为空导致表情丢失
                    let contentHtml = escapeHtml(msg.text || '');
                    if (msg.sticker_id) {
                        const sName = escapeHtml(msg.sticker_name || msg.sticker_id);
                        const sUrl = resolveStickerUrl(msg.sticker_id, msg.sticker_url || '', stickerMap);
                        contentHtml = sUrl
                            ? '<img src="' + escapeHtmlAttr(sUrl) + '" alt="' + sName + '" style="max-width:120px;border-radius:8px;display:block;">'
                            : '<span style="font-style:italic;color:#999;">[表情: ' + sName + ']</span>';
                    }
                    msgHtml += `
                        <div style="display:flex;justify-content:${align};margin-bottom:8px;">
                            <div style="max-width:75%;padding:8px 12px;background:${bg};border:1.5px solid #2b2b2b;border-radius:${radius};font-size:13px;line-height:1.4;">
                                <div style="font-size:10px;color:#888;">${escapeHtml(msg.sender)} · ${escapeHtml(msg.time || '')}</div>
                                <div style="margin-top:2px;">${contentHtml}</div>
                            </div>
                        </div>
                    `;
                });
                msgEl.innerHTML = msgHtml;
            }

            // 公开开关 - 立即生效
            document.getElementById('detail-public-check').addEventListener('change', function () {
                const isPublic = this.checked;
                const statusEl = document.getElementById('detail-collection-status');
                const linkArea = document.getElementById('detail-public-link');
                const urlInput = document.getElementById('detail-public-url');
                this.disabled = true;
                statusEl.style.display = 'block';
                statusEl.style.color = '#999';
                statusEl.textContent = '处理中...';

                fetch('/api/chat-history/collect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ id, is_public: isPublic }),
                })
                .then(r => r.json())
                .then(result => {
                    if (result.success) {
                        if (isPublic && result.public_url) {
                            urlInput.value = window.location.origin + result.public_url;
                            linkArea.style.display = 'block';
                            statusEl.style.color = 'let(--success)';
                            statusEl.textContent = '链接已生成';
                        } else {
                            linkArea.style.display = 'none';
                            statusEl.style.color = 'let(--success)';
                            statusEl.textContent = '已关闭公开';
                        }
                    } else {
                        this.checked = !isPublic;
                        statusEl.style.color = 'let(--danger)';
                        statusEl.textContent = result.message || '操作失败';
                    }
                    this.disabled = false;
                })
                .catch(() => {
                    this.checked = !isPublic;
                    this.disabled = false;
                    statusEl.style.color = 'let(--danger)';
                    statusEl.textContent = '网络错误';
                });
            });

            // 复制公开链接
            document.getElementById('btn-copy-public-link').addEventListener('click', () => {
                const urlInput = document.getElementById('detail-public-url');
                urlInput.select();
                document.execCommand('copy');
                const statusEl = document.getElementById('detail-collection-status');
                statusEl.style.display = 'block';
                statusEl.style.color = 'let(--success)';
                statusEl.textContent = '链接已复制';
            });

            // 收藏保存（仅标题）
            document.getElementById('btn-detail-collection-save').addEventListener('click', () => {
                const title = document.getElementById('detail-title-input').value.trim();
                const btn = document.getElementById('btn-detail-collection-save');
                const statusEl = document.getElementById('detail-collection-status');
                btn.disabled = true;
                btn.textContent = '保存中...';

                fetch('/api/chat-history/collect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ id, title: title || null }),
                })
                .then(r => r.json())
                .then(result => {
                    statusEl.style.display = 'block';
                    if (result.success) {
                        statusEl.style.color = 'let(--success)';
                        statusEl.textContent = '已保存';
                    } else {
                        statusEl.style.color = 'let(--danger)';
                        statusEl.textContent = result.message || '保存失败';
                    }
                    btn.disabled = false;
                    btn.textContent = '保存';
                })
                .catch(() => {
                    statusEl.style.display = 'block';
                    statusEl.style.color = 'let(--danger)';
                    statusEl.textContent = '网络错误';
                    btn.disabled = false;
                    btn.textContent = '保存';
                });
            });
        })
        .catch(() => {
            msgEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:20px;">加载失败</div>';
        });
}

function parseProfilePath() {
    const m = window.location.pathname.match(/^\/player\/(.+)/);
    if (!m) return null;
    return decodeURIComponent(m[1]);
}

function parseCollectionPath() {
    const m = window.location.pathname.match(/^\/collection\/(.+)/);
    if (!m) return null;
    return decodeURIComponent(m[1]);
}

function showProfilePage() {
    landingPage.style.display = 'none';
    matchingPage.style.display = 'none';
    chatPage.style.display = 'none';
    resultArea.style.display = 'none';
    profilePage.style.display = 'flex';
    btnBack.style.display = 'none';

    // Hero 入场
    const hero = profilePage.querySelector('.profile-hero');
    if (hero) {
        hero.style.opacity = '0';
        hero.style.transform = 'translateY(-12px)';
        requestAnimationFrame(() => {
            hero.style.transition = 'opacity 0.45s ease-out, transform 0.45s ease-out';
            hero.style.opacity = '1';
            hero.style.transform = 'translateY(0)';
        });
    }
}

async function showPublicCollection(token) {
    // 标记为非游戏对局，阻止 beforeunload 弹窗
    window._isPublicCollection = true;

    // 隐藏其他页面
    landingPage.style.display = 'none';
    matchingPage.style.display = 'none';
    resultArea.style.display = 'none';
    profilePage.style.display = 'none';
    btnBack.style.display = 'none';

    // 改造 chat-page 为公开回顾页
    chatPage.style.display = 'flex';
    let inputArea = document.querySelector('.chat-input-area');
    if (inputArea) inputArea.style.display = 'none';
    let reportBtn = document.getElementById('btn-report');
    if (reportBtn) reportBtn.style.display = 'none';
    let judgeZone = document.getElementById('judgement-zone');
    if (judgeZone) judgeZone.style.display = 'none';
    let headerRight = document.querySelector('#chat-page > div > div.chat-header > div:nth-child(2)');
    if (headerRight) headerRight.style.display = 'none';

    // 标题栏
    const oppInfo = chatPage.querySelector('.opponent-info');
    if (oppInfo) {
        oppInfo.innerHTML = `
            <div class="avatar">
                <svg class="icon" viewBox="0 0 24 24" style="width:24px;height:24px;">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <div>
                <div style="font-size:12px;color:#888;" id="pc-header-sub">加载中...</div>
            </div>
        `;
    }
    // 居中标题
    let chatHeader = chatPage.querySelector('.chat-header');
    let existTitle = document.getElementById('pc-header-center-title');
    if (!existTitle && chatHeader) {
        let centerTitle = document.createElement('strong');
        centerTitle.id = 'pc-header-center-title';
        centerTitle.style.cssText = 'font-size:16px;position:absolute;left:50%;transform:translateX(-50%);';
        centerTitle.textContent = '公开聊天回顾';
        chatHeader.style.position = 'relative';
        chatHeader.appendChild(centerTitle);
    }
    const timerDisplay = document.getElementById('timer-display');
    if (timerDisplay) timerDisplay.textContent = '';

    // 清空聊天区
    chatBody.innerHTML = '';

    try {
        const r = await fetch('/api/collection/by-token?token=' + encodeURIComponent(token));
        const data = await r.json();
        if (data.error) {
            const sys = document.createElement('div');
            sys.className = 'sys-msg anim-fade-in';
            sys.textContent = data.error;
            chatBody.appendChild(sys);
            const hdr = document.getElementById('pc-header-sub');
            if (hdr) hdr.textContent = '无法查看';
            return;
        }

        const resultLabels = { 'win': '胜利', 'lose': '失败', 'draw': '平局' };
        const time = data.created_at ? data.created_at.substring(0, 16).replace('T', ' ') : '';
        const hdr = document.getElementById('pc-header-sub');
        if (hdr) hdr.textContent = escapeHtml(data.player_name) + ' vs ' + escapeHtml(data.opponent_name);
        let centerTitle = document.getElementById('pc-header-center-title');
        if (centerTitle) centerTitle.textContent = data.title || '公开聊天回顾';

        // 结果信息
        const sysMsg = document.createElement('div');
        sysMsg.className = 'sys-msg anim-fade-in';
        sysMsg.textContent = '结果：' + (resultLabels[data.result] || '') + ' · ' + data.message_count + '条消息 · ' + time;
        chatBody.appendChild(sysMsg);

        if (!data.messages || !data.messages.length) {
            const empty = document.createElement('div');
            empty.className = 'sys-msg anim-fade-in';
            empty.textContent = '无聊天消息';
            chatBody.appendChild(empty);
        } else {
            data.messages.forEach(msg => {
                const bubble = document.createElement('div');
                const isRight = msg.side === 'right';
                bubble.className = isRight ? 'bubble bubble-right anim-slide-right' : 'bubble bubble-left anim-slide-left';
                // 表情消息：渲染贴纸图片，避免因 text 为空导致表情丢失
                let contentHtml = escapeHtml(msg.text || '');
                if (msg.sticker_id) {
                    const sName = escapeHtml(msg.sticker_name || msg.sticker_id);
                    const sUrl = resolveStickerUrl(msg.sticker_id, msg.sticker_url || '', stickerMap);
                    contentHtml = sUrl
                        ? '<img src="' + escapeHtmlAttr(sUrl) + '" alt="' + sName + '" style="max-width:120px;border-radius:8px;display:block;">'
                        : '<span style="font-style:italic;color:#999;">[表情: ' + sName + ']</span>';
                }
                bubble.innerHTML = `
                    <div class="bubble-info">${escapeHtml(msg.sender)} (${escapeHtml(msg.time || '')})</div>
                    <div style="font-size:18px;">${contentHtml}</div>
                `;
                chatBody.appendChild(bubble);
            });
        }

        // 点赞按钮（仅登录用户可见）
        let userTok = getUserToken();
        if (userTok && data.id) {
            let likeDiv = document.createElement('div');
            likeDiv.style.cssText = 'text-align:center;margin-top:16px;';
            likeDiv.innerHTML = '<button class="doodle-btn" id="pc-btn-like" style="font-size:13px;padding:6px 16px;">&#10084; 点赞 <span id="pc-like-count">' + (parseInt(data.likes) || 0) + '</span></button>';
            chatBody.appendChild(likeDiv);

            document.getElementById('pc-btn-like').addEventListener('click', function () {
                let btn = this;
                btn.disabled = true;
                btn.textContent = '...';
                fetch('/api/collection/like', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + userTok
                    },
                    body: JSON.stringify({ id: data.id }),
                })
                .then((r) => { return r.json(); })
                .then((result) => {
                    if (result.success) {
                        let countEl = document.getElementById('pc-like-count');
                        countEl.textContent = parseInt(countEl.textContent) + 1;
                        btn.innerHTML = '&#10084; 已赞 <span id="pc-like-count">' + countEl.textContent + '</span>';
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '&#10084; 点赞 <span id="pc-like-count">' + (parseInt(data.likes) || 0) + '</span>';
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '&#10084; 点赞 <span id="pc-like-count">' + (parseInt(data.likes) || 0) + '</span>';
                });
            });
        }

        // 返回按钮
        const backDiv = document.createElement('div');
        backDiv.className = 'sys-msg';
        backDiv.style.marginTop = '16px';
        backDiv.innerHTML = '<a href="/" style="color:let(--ink-blue);font-size:14px;">返回首页</a>';
        chatBody.appendChild(backDiv);
    } catch (e) {
        const sys = document.createElement('div');
        sys.className = 'sys-msg anim-fade-in';
        sys.style.color = 'let(--danger)';
        sys.textContent = '网络错误，请稍后重试';
        chatBody.appendChild(sys);
        const hdr = document.getElementById('pc-header-sub');
        if (hdr) hdr.textContent = '加载失败';
    }
}

function hideAllPagesForProfile() {
    landingPage.style.display = 'none';
    matchingPage.style.display = 'none';
    chatPage.style.display = 'none';
    resultArea.style.display = 'none';
    profilePage.style.display = 'none';
}

async function loadProfile(nickname) {
    const about = document.getElementById('profile-about');
    const keyStats = document.getElementById('profile-key-stats');
    const hours = document.getElementById('profile-hours');
    const tagsArea = document.getElementById('profile-tags-area');
    const msgsArea = document.getElementById('profile-messages-area');
    const social = document.getElementById('profile-social');
    about.style.display = 'none';
    hours.style.display = 'none';
    tagsArea.style.display = 'none';
    msgsArea.style.display = 'none';
    social.style.display = 'none';

    // 加载中 — 复用匹配页的点跳动动画
    keyStats.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;">' +
        '<div class="dot-bounce"><span></span><span></span><span></span></div>' +
        '<div style="margin-top:10px;font-size:14px;color:let(--text-subtle);">正在翻阅档案...</div>' +
        '</div>';

    try {
        const resp = await fetch('/api/player-profile?nickname=' + encodeURIComponent(nickname));
        const data = await resp.json();

        if (data.error) {
            keyStats.innerHTML = '<div class="profile-placeholder">' + escapeHtml(data.error) + '</div>';
            return;
        }

        renderProfile(data);
    } catch (e) {
        keyStats.innerHTML = '<div class="profile-placeholder">加载失败，请稍后重试</div>';
    }
}

function buildAboutLines(data, turingGames) {
    const lines = [];

    if (!turingGames) {
        if ((data.whoisai_games || 0) > 0) {
            lines.push('这里的数据来自 <b>图灵测试（1v1）</b> 模式。');
            lines.push('你主要在玩 <b>谁是AI</b> 模式，去 1v1 打几局就能看到详细分析啦。');
        } else {
            lines.push('这里的数据来自 <b>图灵测试（1v1）</b> 模式。');
            lines.push('去打几局就能看到你的 AI 识别能力分析。');
        }
        return lines;
    }

    const gag = data.guess_accuracy;
    if (gag != null && gag > 0) {
        lines.push(gag >= 60
            ? '你是公认的 <b>AI 克星</b>，' + gag + '% 的判断准确率让 AI 无所遁形。'
            : '你有点 <b>容易被 AI 骗</b>，猜对率只有 ' + gag + '%，下次多留个心眼。');
    }
    const er = data.exposure_rate;
    if (er != null && er > 0) {
        lines.push(er <= 40
            ? '你的伪装能力很强，只有 <b>' + er + '%</b> 的对手能看穿你。'
            : '你的 <b>暴露指数</b> 高达 ' + er + '%，总是藏不住真实身份。');
    }
    const msgs = data.avg_msgs;
    if (msgs != null && msgs > 0) {
        lines.push(msgs >= 15
            ? '你是个 <b>话痨</b>，平均每局发 ' + msgs + ' 条消息，聊天框就是你的主场。'
            : (msgs <= 5
                ? '你 <b>惜字如金</b>，平均每局只发 ' + msgs + ' 条消息，但句句致命。'
                : '你的聊天节奏 <b>不疾不徐</b>，平均每局 ' + msgs + ' 条消息。'));
    }
    const js = data.avg_judge_seconds;
    if (js > 0) {
        lines.push(js <= 10
            ? '你是 <b>急性子</b>，平均 ' + js + ' 秒就做出判断。'
            : '你 <b>深思熟虑</b>，平均花 ' + js + ' 秒才下定论。');
    }
    const ph = data.peak_hours;
    if (ph && ph.length) {
        const top = ph[0];
        const label = top >= 22 ? '夜猫子' : (top >= 6 ? '白天出没' : '深夜党');
        lines.push('你是 <b>' + label + '</b>，最常在 ' + top + ' 点左右上线。');
    }

    if ((data.whoisai_games || 0) > 0) {
        const wr = data.whoisai_win_rate || 0;
        lines.push('谁是AI 模式打了 ' + (data.whoisai_games || 0) + ' 局，胜率 ' + wr + '%。');
    }

    return lines;
}

function renderProfile(data) {
    // 昵称 + 称号
    document.getElementById('profile-nickname').textContent = data.nickname || '？？？';

    const titleBadge = document.getElementById('profile-title');
    if (data.title) {
        titleBadge.textContent = data.title;
        titleBadge.className = 'profile-title-badge visible';
    } else {
        titleBadge.className = 'profile-title-badge';
    }

    // 副标题：区分两种模式
    const tg = data.turing_games || 0;
    const wg = data.whoisai_games || 0;
    const parts = [];
    if (tg > 0) parts.push('图灵测试 ' + tg + ' 局');
    if (wg > 0) parts.push('谁是AI ' + wg + ' 局');
    parts.push('胜率 ' + (data.win_rate || 0) + '%');
    document.getElementById('profile-subtitle').textContent = parts.join(' · ');

    // ── 人物速写 ──
    const aboutLines = buildAboutLines(data, tg);
    const aboutEl = document.getElementById('profile-about');
    if (aboutLines.length) {
        aboutEl.style.display = '';
        document.getElementById('profile-about-lines').innerHTML = aboutLines.map(l =>
            '<div class="about-line">' + l + '</div>'
        ).join('');
        aboutEl.style.opacity = '0';
        aboutEl.style.transform = 'translateY(16px) rotate(0.8deg)';
        requestAnimationFrame(() => {
            aboutEl.style.transition = 'opacity 0.4s ease-out, transform 0.4s ease-out';
            aboutEl.style.opacity = '1';
            aboutEl.style.transform = 'rotate(0.8deg)';
        });
    }

    // ── 核心数据 ──
    const kStats = [
        { label: 'AI 胜率', value: data.ai_win_rate ? data.ai_win_rate + '%' : '-' },
        { label: '真人胜率', value: data.human_win_rate ? data.human_win_rate + '%' : '-' },
        { label: '胜率', value: (data.win_rate || 0) + '%' },
        { label: '总局数', value: data.total_games || 0 },
    ];
    if ((data.whoisai_games || 0) > 0) {
        kStats.push({ label: '谁是AI胜率', value: (data.whoisai_win_rate || 0) + '%' });
    }
    document.getElementById('profile-key-stats').innerHTML = kStats.map((s, i) =>
        '<div class="profile-key-stat anim-pop-in" style="animation-delay:' + (i * 0.08) + 's">' +
        '<div class="ks-label">' + escapeHtml(s.label) + '</div>' +
        '<div class="ks-value">' + escapeHtml(String(s.value)) + '</div>' +
        '</div>'
    ).join('');

    // ── 活跃时段 ──
    const ph = data.peak_hours || [];
    const hoursEl = document.getElementById('profile-hours');
    if (ph.length) {
        hoursEl.style.display = '';
        const bars = document.getElementById('profile-hours-bars');
        bars.innerHTML = ph.map((h, i) => {
            const pct = Math.max(20, 100 - i * 25);
            return (
                '<div class="hours-row anim-slide-left" style="animation-delay:' + (i * 0.1) + 's">' +
                '<div class="hours-time">' + h + '点</div>' +
                '<div class="hours-bar-track"><div class="hours-bar-fill" style="width:0%" data-target="' + pct + '"></div></div>' +
                '<div class="hours-count">' + (i === 0 ? '最活跃' : (i === 1 ? '次活跃' : '')) + '</div>' +
                '</div>'
            );
        }).join('');
        hoursEl.style.opacity = '0';
        hoursEl.style.transform = 'translateY(12px) rotate(-0.5deg)';
        requestAnimationFrame(() => {
            hoursEl.style.transition = 'opacity 0.35s ease-out, transform 0.35s ease-out';
            hoursEl.style.opacity = '1';
            hoursEl.style.transform = 'rotate(-0.5deg)';
        });
        // 柱状图延迟填满
        setTimeout(() => {
            bars.querySelectorAll('.hours-bar-fill').forEach(el => {
                el.style.width = el.getAttribute('data-target') + '%';
            });
        }, 400);
    }

    // ── 标签 ──
    const tagsArea = document.getElementById('profile-tags-area');
    const tagList = document.getElementById('profile-tag-list');
    if (data.tags && data.tags.length) {
        tagsArea.style.display = '';
        tagList.innerHTML = data.tags.map((t, i) =>
            '<span class="profile-tag-item anim-pop-in" style="animation-delay:' + (0.15 + i * 0.08) + 's">' +
            escapeHtml(t.tag) +
            '<span class="tag-count">×' + t.count + '</span>' +
            '</span>'
        ).join('');
    } else {
        tagsArea.style.display = 'none';
    }

    // ── 对手留言墙 ──
    const msgsArea = document.getElementById('profile-messages-area');
    const msgList = document.getElementById('profile-message-list');
    const msgs = data.messages || [];
    if (msgs.length) {
        msgsArea.style.display = '';
        msgList.innerHTML = msgs.map(m => {
            const date = new Date(m.created_at * 1000);
            const timeAgo = timeAgoText(date);
            return `
                <div class="profile-msg-item anim-pop-in">
                    <div class="profile-msg-from">${escapeHtml(m.from)}</div>
                    <div class="profile-msg-text">${escapeHtml(m.text)}</div>
                    <div class="profile-msg-time">${timeAgo}</div>
                </div>
            `;
        }).join('');
    } else {
        msgsArea.style.display = 'none';
    }

    // 无社交数据则隐藏整个卡片
    const social = document.getElementById('profile-social');
    const hasTags = data.tags && data.tags.length;
    const hasMsgs = data.messages && data.messages.length;
    social.style.display = (hasTags || hasMsgs) ? '' : 'none';
}

// 初始化：检测 URL 是否为 /player/xxx
(function initProfileRouting() {
    const nickname = parseProfilePath();
    if (nickname) {
        showProfilePage();
        loadProfile(nickname);
    }
})();

// 初始化：检测 URL 是否为 /collection/{token} 公开收藏页
(function initCollectionRouting() {
    const token = parseCollectionPath();
    if (token) {
        hideAllPagesForProfile();
        showPublicCollection(token);
    }
})();

// 返回首页按钮
document.getElementById('btn-profile-back').addEventListener('click', function () {
    history.pushState(null, '', '/');
    hideAllPagesForProfile();
    landingPage.style.display = 'flex';
    btnBack.style.display = 'none';
});

// 关闭详情弹窗事件（仅管理后台存在这些元素）
let btnChatDetailClose = document.getElementById('btn-chat-detail-close');
if (btnChatDetailClose) {
    btnChatDetailClose.addEventListener('click', function () {
        closeOverlay(document.getElementById('chat-history-detail-overlay'));
        settingsOverlay.style.display = 'flex';
    });
}

let chatDetailOverlay = document.getElementById('chat-history-detail-overlay');
if (chatDetailOverlay) {
    chatDetailOverlay.addEventListener('click', function (e) {
        if (e.target === e.currentTarget) {
            closeOverlay(chatDetailOverlay);
            settingsOverlay.style.display = 'flex';
        }
    });
}

// ================================================================
//  在线人数轮播：数字只在变化时更新，文字每秒轮播
// ================================================================
(function initOnlineCarousel() {
    let phrases = ['🤔🤔', '发癫', '😈😈', '智斗', '😋😋', '激战', '😎😎', '对决', '😱😱', '交锋', '🤯🤯', '切磋', '🤡🤡', '博弈', '😡😡', '比拼', '😋😋', '斗智'];
    let displayPhrases = phrases.concat(phrases[0]);
    let currentIndex = 0;
    let carousel = document.getElementById('online-text-carousel');
    if (!carousel) return;

    // 构建文字轮播结构
    carousel.innerHTML = displayPhrases.map((p) => {
        return '<span>' + p + '</span>';
    }).join('');

    function scrollTo(index) {
        carousel.style.transform = 'translateY(-' + (index * 20) + 'px)';
    }

    function nextPhrase() {
        currentIndex++;
        carousel.style.transition = 'transform 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
        scrollTo(currentIndex);

        if (currentIndex === phrases.length) {
            setTimeout(function () {
                carousel.style.transition = 'none';
                currentIndex = 0;
                scrollTo(0);
            }, 400);
        }
    }

    // 文字轮播间隔
    setInterval(nextPhrase, 1500);

    // 数字更新：仅在数值变化时更新
    window.updateOnlineCarousel = function (count) {
        let numEl = document.getElementById('online-num');
        if (numEl && numEl.textContent !== String(count)) {
            numEl.textContent = count;
        }
    };
})();

// ================================================================
// OAuth 快捷登录
// ================================================================

/**
 * 渲染首页登录区的快捷登录按钮（已配置的 provider 列表）。
 * 仅在"未登录游客 + 已配置 provider"时显示：
 * 已登录用户不需要快捷登录入口（绑定/解绑在设置面板管理）。
 */
function initOAuthLoginButtons() {
    const line = document.getElementById('oauth-quick-line');
    const container = document.getElementById('oauth-quick-buttons');
    if (!line || !container) return;

    // 已登录：不显示快捷登录区
    if (getUserToken()) return;

    getOAuthProviders().then(function (providers) {
        if (!Array.isArray(providers) || providers.length === 0) return;
        container.innerHTML = '';
        providers.forEach(function (p) {
            const btn = document.createElement('a');
            btn.className = 'doodle-btn';
            btn.href = oauthLoginUrl(p.key, '/');
            btn.style.cssText = 'font-size:12px;padding:6px 14px;';
            btn.textContent = p.name + ' 登录';
            container.appendChild(btn);
        });
        line.style.display = '';
    });
}

/**
 * 建号确认弹窗：OAuth 邮箱未关联本站玩家时询问是否创建账户。
 */
function showOAuthCreateDialog(pendingCode) {
    oauthPendingInfo(pendingCode).then(function (info) {
        if (!info.ok) {
            if (typeof showTopToast === 'function') showTopToast(info.error || '登录凭证已失效，请重新登录', true);
            oauthCleanUrlParams();
            return;
        }

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = `
            <div class="doodle-border" style="background:var(--surface-white,#fff);border-radius:14px;padding:22px;width:min(360px,90vw);box-sizing:border-box;">
                <h3 style="margin:0 0 10px;font-size:16px;">创建新账号？</h3>
                <p style="font-size:13px;color:#666;margin:0 0 12px;">该 <b>${escapeHtml(info.provider || '')}</b> 账号尚未关联本站玩家，是否用以下邮箱创建账号？</p>
                <div style="font-size:13px;background:#f5f5f5;border-radius:8px;padding:8px 10px;margin-bottom:12px;word-break:break-all;">邮箱：${escapeHtml(info.email || '（未提供）')}</div>
                <label style="font-size:12px;color:#888;">昵称（可修改）：</label>
                <input id="oauth-create-nickname" type="text" maxlength="12" value="${escapeHtmlAttr(info.nickname || '')}"
                    style="width:100%;box-sizing:border-box;margin-top:4px;padding:8px 10px;border:2px solid var(--ink-black);border-radius:8px;font-size:14px;">
                <div id="oauth-create-error" style="display:none;font-size:12px;color:#e74c3c;margin-top:8px;"></div>
                <div style="display:flex;gap:10px;margin-top:16px;">
                    <button id="oauth-create-confirm" class="doodle-btn" style="flex:1;justify-content:center;">创建账户</button>
                    <button id="oauth-create-cancel" class="doodle-btn" style="flex:1;justify-content:center;">不创建</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        function showErr(msg) {
            const el = overlay.querySelector('#oauth-create-error');
            if (el) { el.textContent = msg; el.style.display = ''; }
        }

        overlay.querySelector('#oauth-create-cancel').addEventListener('click', function () {
            oauthCancelCreate(pendingCode);
            overlay.remove();
            oauthCleanUrlParams();
            if (typeof showTopToast === 'function') showTopToast('已取消创建', false);
        });

        overlay.querySelector('#oauth-create-confirm').addEventListener('click', function () {
            const nickname = overlay.querySelector('#oauth-create-nickname').value.trim();
            if (!nickname) { showErr('昵称不能为空'); return; }
            const btn = overlay.querySelector('#oauth-create-confirm');
            btn.disabled = true;
            oauthConfirmCreate(pendingCode, nickname, getFingerprint()).then(function (data) {
                if (data.ok && data.token) {
                    setUserToken(data.token);
                    if (data.nickname) setUserNickname(data.nickname);
                    overlay.remove();
                    oauthCleanUrlParams();
                    if (typeof showTopToast === 'function') showTopToast('账号创建成功！', false);
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    btn.disabled = false;
                    showErr(data.error || '创建失败，请重试');
                }
            });
        });
    });
}

/**
 * 渲染设置面板的 OAuth 绑定管理区（已绑定列表 + 可添加平台）。
 */
function loadOAuthBindingsUI() {
    const section = document.getElementById('oauth-bindings-section');
    const listEl = document.getElementById('oauth-binding-list');
    const addEl = document.getElementById('oauth-bind-add');
    if (!section || !listEl || !addEl) return;

    const tok = getUserToken();
    if (!tok) { section.style.display = 'none'; return; }
    section.style.display = '';

    Promise.all([getOAuthProviders(), oauthFetchBindings()]).then(function (results) {
        const providers = Array.isArray(results[0]) ? results[0] : [];
        const bindingsData = (results[1] && results[1].ok) ? results[1].bindings : [];
        const boundMap = {};
        (bindingsData || []).forEach(function (b) { boundMap[b.provider] = b; });

        const providerName = function (key) {
            for (let i = 0; i < providers.length; i++) {
                if (providers[i].key === key) return providers[i].name;
            }
            return key;
        };

        // 已绑定列表
        listEl.innerHTML = '';
        const boundKeys = Object.keys(boundMap);
        if (boundKeys.length === 0) {
            listEl.innerHTML = '<div style="font-size:12px;color:#999;padding:4px 0;">尚未绑定任何平台</div>';
        } else {
            boundKeys.forEach(function (pKey) {
                const b = boundMap[pKey];
                const name = providerName(pKey);
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:2px solid var(--ink-black);border-radius:8px;margin-bottom:6px;';
                const emailSpan = (b.email && b.email !== '')
                    ? '<span style="color:#999;font-size:11px;margin-left:6px;">' + escapeHtml(b.email) + '</span>'
                    : '';
                row.innerHTML = '<span style="font-size:13px;">' + escapeHtml(name) + emailSpan + '</span>';
                const btn = document.createElement('button');
                btn.className = 'doodle-btn';
                btn.textContent = '解绑';
                btn.style.cssText = 'font-size:11px;padding:3px 10px;color:#e74c3c;border-color:#e74c3c;flex-shrink:0;';
                btn.addEventListener('click', function () {
                    if (!confirm('确定解绑 ' + name + ' 吗？\n\n解绑后若浏览器缓存被清除，将无法再通过该平台快捷登录（仍可用昵称+密码登录）。')) return;
                    oauthUnbind(pKey).then(function (data) {
                        if (data.ok) {
                            if (typeof showTopToast === 'function') showTopToast('已解绑 ' + name, false);
                            loadOAuthBindingsUI();
                        } else if (typeof showTopToast === 'function') {
                            showTopToast(data.error || '解绑失败', true);
                        }
                    });
                });
                row.appendChild(btn);
                listEl.appendChild(row);
            });
        }

        // 可添加的平台（未绑定的）
        addEl.innerHTML = '';
        const unbound = providers.filter(function (p) { return !boundMap[p.key]; });
        if (unbound.length > 0) {
            const tip = document.createElement('div');
            tip.style.cssText = 'font-size:12px;color:#888;margin-bottom:6px;';
            tip.textContent = '添加绑定：';
            addEl.appendChild(tip);
            unbound.forEach(function (p) {
                const btn = document.createElement('button');
                btn.className = 'doodle-btn';
                btn.textContent = '绑定 ' + p.name;
                btn.style.cssText = 'font-size:12px;padding:5px 12px;margin:0 6px 6px 0;';
                btn.addEventListener('click', function () {
                    oauthBindSubmit(p.key, '/');
                });
                addEl.appendChild(btn);
            });
        }
    });
}

// OAuth 初始化：渲染登录区按钮 + 处理回调参数
initOAuthLoginButtons();
oauthHandleReturn();