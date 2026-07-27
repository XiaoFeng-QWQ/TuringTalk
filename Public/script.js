// ================================================================
// 传输层基类
// ================================================================
const DebugLogger = { log: function() {}, count: async function() { return 0; }, download: async function() { return { count: 0 }; } };
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
    }

    // ---- 公开方法 ----

    start(nickname) {
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
        this._transport.reconnect(this._nickname, duration);
    }

    sendMessage() {
        const text = chatInput.value.trim();
        if (!text) return;

        appendMessage(text, 'right', this._nickname);
        chatInput.value = '';
        charCount.textContent = '0/300';
        charCount.style.color = 'var(--text-subtle)';
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

        // 重置顶部计时器为 60 秒，继续倒计时
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
                timerDisplay.style.color = 'var(--danger)';
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

        // 清理 UI 和本地状态（跳过 reset()，因为我们要断开 WS 而不是发 leave）
        this._disconnecting = true;
        if (this._transport) this._transport._intentionalClose = true;
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

        // 断开旧连接（服务端 onClose 会完整清理 playersTable/sessionsTable/queue）
        if (this._transport._ws) {
            try { this._transport._ws.close(); } catch (e) {}
            this._transport._ws = null;
        }

        // reconnect 检测到 WS 已关闭，会创建新连接并重新计算 PoW
        setTimeout(() => this._transport.reconnect(nickname, duration), 0);
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
                        <div style="font-size: 12px; color: var(--text-subtle);">当前对手</div>
                        <strong style="font-size: 18px;">${escapeHtml(this._opponentName)}</strong>
                    `;
        }

        matchingPage.style.display = 'none';
        landingPage.style.display = 'none';
        resultArea.style.display = 'none';
        chatPage.style.display = 'flex';

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
            <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:var(--ink-blue);stroke-width:2;flex-shrink:0;">
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
                timerDisplay.style.color = 'var(--danger)';
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
                    timerDisplay.style.color = 'var(--danger)';
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
        notifyDiv.style.color = 'var(--danger)';
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
        renderResult(false, this._userGuess, this._opponentTruth, data.opponent_guess, data.opponent_tag);
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
            renderResult('you', this._userGuess, this._opponentTruth, null);
        } else if (data && data.reason === 'both_timeout') {
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult('both', this._userGuess, this._opponentTruth, null);
        } else if (data && data.reason === 'no_mutual_chat') {
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult('no_mutual_chat', this._userGuess, this._opponentTruth, null);
        } else if (data && data.reason) {
            // opponent_timeout / opponent_disconnected / opponent_left
            if (data.opponent_truth) this._opponentTruth = data.opponent_truth;
            renderResult(data.reason, this._userGuess, this._opponentTruth, null);
        }
    }

    _onDisconnected(data) {
        DebugLogger.log('ws', '客户端收到disconnected事件', data || {});
        if (this._disconnecting) return;

        // 自动重连中，显示提示而不 reset
        if (data && data.reconnecting) {
            if (chatPage.style.display === 'flex') {
                const existing = document.getElementById('reconnect-banner');
                if (!existing) {
                    const banner = document.createElement('div');
                    banner.id = 'reconnect-banner';
                    banner.style.cssText =
                        'position:fixed;top:0;left:0;right:0;z-index:999;' +
                        'padding:10px;background:var(--note-yellow);color:var(--ink-black);' +
                        'text-align:center;font-size:14px;font-weight:bold;' +
                        'animation:slideDown 0.35s ease;';
                    banner.textContent = '连接已断开，正在自动重连...';
                    document.body.appendChild(banner);
                }
            }
            return;
        }

        if (chatPage.style.display === 'flex') {
            this.reset();
        }
    }

    _onError(data) {
        DebugLogger.log('error', '传输层错误', { text: data.text });
        console.error('传输层错误:', data.text);
        // 匹配阶段：显示在匹配页
        if (matchingPage.style.display === 'flex') {
            showMatchError(data.text || '连接出错');
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
            banner.style.cssText = 'padding:14px 20px;margin-bottom:16px;background:var(--danger-light);color:var(--danger-dark);font-size:15px;font-weight:bold;text-align:center;animation:wiggle 0.3s ease;';
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

    _onSaveHistoryStatus(data) {
        const btnSave = document.getElementById('btn-save-history');
        const statusEl = document.getElementById('save-history-status');
        statusEl.style.display = 'block';
        if (data.success) {
            statusEl.style.color = 'var(--success)';
            statusEl.textContent = data.message || '聊天记录已保存';
            if (btnSave) btnSave.style.display = 'none';
        } else {
            statusEl.style.color = 'var(--danger)';
            statusEl.textContent = data.message || '保存失败';
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.textContent = '保存聊天记录';
            }
        }
    }
}

const landingPage = document.getElementById('landing-page');
const matchingPage = document.getElementById('matching-page');
const chatPage = document.getElementById('chat-page');
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
let stickerMap = {};

const origLogoHTML = logoText.innerHTML;

// ================================================================
// 浏览器指纹
// ================================================================
function generateFingerprint() {
    const data = [
        navigator.userAgent || '',
        navigator.language || '',
        screen.colorDepth || '',
        screen.width || '',
        screen.height || '',
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 0,
        navigator.deviceMemory || 0,
    ].join('|');
    let hash = 0;
    for (let i = 0; i < data.length; i++) {
        const chr = data.charCodeAt(i);
        hash = ((hash << 5) - hash) + chr;
        hash |= 0;
    }
    return Math.abs(hash).toString(36);
}

const browserFingerprint = generateFingerprint();

const nicknameInput = document.getElementById('nickname-input');
// 迁移旧存储到统一的 userdata 结构（临时，下个版本移除）
// USERDATA_KEY 已在 shared.js 中声明
let _chatHistoryPage = 1;
migrateLegacyData();

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

    // 有恢复码时隐藏首页恢复码输入框（数据已在本地，无需恢复）
    const recoverLine = document.getElementById('recover-line');
    if (recoverLine && getLbCode()) {
        recoverLine.style.display = 'none';
    }
}

// 首页恢复码入口
document.getElementById('btn-recover-main').addEventListener('click', () => {
    const input = document.getElementById('recover-input-main');
    const code = input.value.trim();
    if (!code) { showTopToast('请输入恢复码'); return; }
    const nickname = nicknameInput.value.trim();
    if (!nickname) { showTopToast('请先填写昵称'); return; }
    fetch('/api/player-stats?code=' + encodeURIComponent(code) + '&nickname=' + encodeURIComponent(nickname))
        .then(r => r.json())
        .then(data => {
            if (data.error) { showTopToast(data.error); return; }
            saveLbCode(data.code);
            updateLbUI();
            updateLbMyStats(data);
            mergeServerStats(data);
            input.value = '';
            showTopToast('战绩已恢复！', false);

            // 切换到 ID 卡展示模式（与页面加载时有数据时一致）
            setUserNickname(nickname);
            const inputLine = document.getElementById('nickname-input-line');
            const systemIdLine = document.getElementById('system-id-line');
            const idCardDisplay = document.getElementById('id-card-display');
            if (inputLine) inputLine.style.display = 'none';
            if (systemIdLine) systemIdLine.style.display = 'none';
            if (idCardDisplay) {
                idCardDisplay.style.display = 'block';
                document.getElementById('id-card-nickname').textContent = nickname;
                document.getElementById('id-card-fingerprint').textContent = browserFingerprint;
            }

            // 恢复成功后隐藏首页恢复码输入框
            const recoverLine = document.getElementById('recover-line');
            if (recoverLine) recoverLine.style.display = 'none';
        })
        .catch(() => showTopToast('网络错误，请稍后重试'));
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
    const code = getLbCode();
    if (code) {
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
            transport.send('update_nickname', { code, nickname: trimmed, fp: browserFingerprint });
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
    if (!game) {
        alert('系统正在初始化，请稍后再试...');
        return;
    }
    const nickname = nicknameInput.value.trim() || 'You';
    game.start(nickname);
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
            thinkAudio.play().catch(() => {});
        }
    }
}

/** 渲染表情到聊天区 */
function appendSticker(stickerId, stickerName, side, sender) {
    const url = stickerMap[stickerId] ? stickerMap[stickerId].url : '';
    if (!url) return;

    const bubble = document.createElement('div');
    bubble.className = 'bubble bubble-sticker ' + (side === 'right' ? 'bubble-right anim-slide-right' : 'bubble-left anim-slide-left');

    const now = new Date();
    const ts = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');

    bubble.innerHTML = `
                <div class="bubble-info">${escapeHtml(sender)} (${ts})</div>
                <img src="${escapeHtmlAttr(url)}" alt="${escapeHtmlAttr(stickerName)}" class="sticker-msg-img" loading="lazy">
            `;

    scrollChatToBottom();
    chatBody.appendChild(bubble);
}

/** 发送表情 */
function sendSticker(stickerId) {
    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) return;
    transport._ws.send(JSON.stringify({
        type: 'sticker',
        id: stickerId
    }));
    // 自己发出的表情也渲染在右边
    if (stickerMap[stickerId]) {
        appendSticker(stickerId, stickerMap[stickerId].name, 'right', getNickname());
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

function renderResult(timeoutReason, userGuess, opponentTruth, opponentGuess, opponentTag) {
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
                        <span class="value" style="background:var(--ink-blue);color:var(--surface-white);padding:2px 10px;border-radius:12px 3px 12px 3px;font-size:13px;">${escapeHtml(opponentTag)}</span>
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
    } catch (_) {}
}

function clearUserdata() {
    try {
        localStorage.removeItem(USERDATA_KEY);
    } catch (_) {}
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
        } catch (_) {}
    }

    // 迁移旧 key: turing_nickname / turing_player_code / turing_stats
    const oldNick = localStorage.getItem('turing_nickname');
    if (oldNick && !ud.nickname) {
        ud.nickname = oldNick;
        migrated = true;
    }

    // 迁移恢复码
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
    } catch (_) {}

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
function getUserRecoveryCode() { return getUserdata().recovery_code || ''; }
function setUserRecoveryCode(code) { const d = getUserdata(); d.recovery_code = code; saveUserdata(d); }
function getUserStats() { return getUserdata().stats || null; }
function setUserStats(s) { const d = getUserdata(); d.stats = s; saveUserdata(d); }
function getUserNicknameUpdatedAt() { return getUserdata().nickname_updated_at || ''; }
function setUserNicknameUpdatedAt(ym) { const d = getUserdata(); d.nickname_updated_at = ym; saveUserdata(d); }

// ================================================================
//  战局统计
// ================================================================

const STATS_KEY = 'turing_stats'; // @deprecated 使用 getUserStats/setUserStats

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
                        } catch (e) {}
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
        statusEl.style.color = 'var(--danger)';
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
        statusEl.style.color = 'var(--danger)';
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
    charCount.style.color = len > 280 ? 'var(--danger)' : len > 250 ? 'var(--warn)' : 'var(--text-subtle)';
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
    // 只在聊天页面激活时触发
    if (chatPage.style.display === 'flex') {
        // 标准方式：设置 event.returnValue
        e.preventDefault();
        e.returnValue = '你正在进行一局图灵测试，确定要离开吗？';
        return e.returnValue;
    }
    // 不在聊天页面时，不拦截
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
            try { ws.onclose = null; } catch (_) {}
            ws.close();
        }
        transport._intentionalClose = false;
        transport._lastPongTime = 0;
        transport.connect(transport._lastNickname || '', transport._lastDuration || 600);
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
    const code = getUserRecoveryCode();
    if (!code) {
        showTopToast('请先在首页创建或恢复您的恢复码', true);
        return;
    }

    const ud = getUserdata();
    const payload = {
        nickname: ud.nickname || '',
        recovery_code: code,
        fp: browserFingerprint,
        stats: ud.stats || {},
    };

    btn.disabled = true;
    btn.textContent = '上传中...';

    try {
        const resp = await fetch('/api/upload-userdata', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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
        // 先显示再测量尺寸（visibility hidden 避免闪烁）
        stickerPicker.style.visibility = 'hidden';
        stickerPicker.style.display = 'flex';
        // 根据按钮位置动态定位
        const btnRect = btnStickerPicker.getBoundingClientRect();
        const pickerWidth = stickerPicker.offsetWidth || 260;
        const pickerHeight = stickerPicker.offsetHeight;
        let left = btnRect.left;
        if (left + pickerWidth > window.innerWidth - 8) {
            left = Math.max(8, window.innerWidth - pickerWidth - 8);
        }
        stickerPicker.style.left = left + 'px';
        stickerPicker.style.top = (btnRect.top - pickerHeight - 16) + 'px';
        stickerPicker.style.visibility = 'visible';
    } else {
        stickerPicker.style.display = 'none';
    }
}

/** 根据 stickerMap 渲染表情选择器内容 */
function renderStickerPicker() {
    stickerPickerBody.innerHTML = '';
    const ids = Object.keys(stickerMap);
    if (ids.length === 0) {
        stickerPickerBody.innerHTML = '<div style="text-align:center;color:#999;padding:20px;font-size:13px;">暂无可用表情</div>';
        return;
    }
    ids.forEach(function(id) {
        const s = stickerMap[id];
        const item = document.createElement('div');
        item.className = 'sticker-picker-item';
        item.title = s.name;
        item.innerHTML = '<img src="' + escapeHtmlAttr(s.url) + '" alt="' + escapeHtmlAttr(s.name) + '" loading="lazy">';
        item.addEventListener('click', function() {
            sendSticker(id);
        });
        stickerPickerBody.appendChild(item);
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

// 全服公告区域（用于 showDanmaku 渲染）
const announcementArea = document.getElementById('announcement-area');

// 举报相关
const btnReport = document.getElementById('btn-report');
const reportOverlay = document.getElementById('report-overlay');
const reportReason = document.getElementById('report-reason');
const btnReportCancel = document.getElementById('btn-report-cancel');
const btnReportSubmit = document.getElementById('btn-report-submit');
const reportError = document.getElementById('report-error');

// --- 全服公告横幅 ---
const ANNOUNCE_DISPLAY_MS = 5000; // 每条公告展示时长
const ANNOUNCE_MAX = 3;           // 最多同时展示条数

let announceQueue = [];            // 待展示队列
let announceShowing = 0;           // 当前正在展示的数量

function showDanmaku(text, label = '全服公告') {
    announceQueue.push({ text, label });
    dequeueAnnounce();
}

function dequeueAnnounce() {
    while (announceQueue.length > 0 && announceShowing < ANNOUNCE_MAX) {
        const item = announceQueue.shift();
        const text = item.text || item;
        const label = item.label || '全服公告';
        announceShowing++;
        const banner = document.createElement('div');
        banner.className = 'announcement-banner' + (label !== '全服公告' ? ' room-warn' : '');
        banner.innerHTML = `
            <svg class="ann-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 11h2.586a1 1 0 0 1 .707.293l7.414 7.414A.5.5 0 0 0 14.5 18.35V5.65a.5.5 0 0 0-.793-.357L6.293 12.707a1 1 0 0 1-.707.293H3a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1z"/>
                <path d="M16 9.5a4.5 4.5 0 0 1 0 5"/>
                <path d="M19 7a8 8 0 0 1 0 10"/>
            </svg>
            <span class="ann-label">${label}</span>
            <span class="ann-text">${text}</span>
        `;
        announcementArea.appendChild(banner);

        // 展示 N 秒后滑出
        const dismiss = () => {
            if (!banner.parentNode) return;
            banner.classList.add('ann-leaving');
            banner.addEventListener('animationend', () => {
                banner.remove();
                announceShowing--;
                dequeueAnnounce(); // 释放一个位置，展示下一条
            }, { once: true });
        };

        setTimeout(dismiss, ANNOUNCE_DISPLAY_MS);
    }
}

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
    banBtn.style.cssText = 'font-size:13px;padding:4px 10px;color:var(--danger);border-color:var(--danger);margin-left:8px;';
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
                style="width:100%;height:80px;padding:12px;border:2px solid var(--ink-black);border-radius:10px;font-size:14px;resize:none;box-sizing:border-box;outline:none;margin-bottom:16px;">${escapeHtml(defaultReason)}</textarea>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="doodle-btn" id="ban-reason-cancel" style="font-size:14px;">取消</button>
                <button class="doodle-btn" id="ban-reason-confirm" style="font-size:14px;background:var(--ink-blue);color:var(--surface-white);border-color:var(--ink-blue);">确认封禁</button>
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
WebSocketTransport.prototype.connect = function (nickname, duration) {
    const wsUrl = this._url;

    // 保存参数供自动重连使用
    this._lastNickname = nickname || '';
    this._lastDuration = duration || 600;

    // 取消pending的重连timer，避免onclose和visibilitychange双重触发
    if (this._reconnectTimer) {
        clearTimeout(this._reconnectTimer);
        this._reconnectTimer = null;
    }

    // 关闭旧连接（如果有），避免旧onopen引用被新ws覆盖
    if (this._ws) {
        try { this._ws.onopen = null; this._ws.onclose = null; this._ws.onerror = null; this._ws.onmessage = null; } catch (e) {}
        try { this._ws.close(); } catch (e) {}
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

            // 移除重连提示
            const reconnectBanner = document.getElementById('reconnect-banner');
            if (reconnectBanner) reconnectBanner.remove();

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
                    recovery_code: getUserRecoveryCode() || undefined,
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
                // 每次进入对局（首次匹配 / 重连恢复）拉取最新表情列表
                ws.send(JSON.stringify({ type: 'get_stickers' }));
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
                this._emit('system', { text: data.text });
                break;
            case 'judged':
                DebugLogger.log('game', '对方已判定', { truth: data.truth, session_id: data.session_id });
                if (data.recovery_code) saveLbCode(data.recovery_code);
                this._emit('opponent_judged', {
                    truth: data.truth,
                    opponent_guess: data.opponent_guess,
                    opponent_tag: data.opponent_tag || '',
                    session_id: data.session_id,
                });
                break;
            case 'judge_notify':
                DebugLogger.log('game', '判定通知', { message: data.message });
                this._emit('judge_notify', { message: data.message, seconds_remaining: data.seconds_remaining });
                break;
            case 'timeout':
                DebugLogger.log('game', '收到timeout事件', { reason: data.reason, session_id: data.session_id });
                if (data.recovery_code) saveLbCode(data.recovery_code);
                this._emit('opponent_timeout', {
                    reason: data.reason,
                    session_id: data.session_id,
                    opponent_truth: data.opponent_truth,
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
                document.getElementById('online-num').textContent = '🔥' + data.count + '名玩家激战中🔥';
                break;
            case 'broadcast':
                showDanmaku(data.text);
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
            case 'stickers_list':
                // 收到表情列表，建立本地 id→{name, url} 映射
                stickerMap = {};
                if (data.stickers && data.stickers.length) {
                    data.stickers.forEach(function(s) {
                        stickerMap[s.id] = { name: s.name, url: s.url };
                    });
                }
                break;
            case 'sticker':
                // 收到对手发来的表情（仅 id+name，不含 URL）
                if (data.id && stickerMap[data.id]) {
                    appendSticker(data.id, data.name || stickerMap[data.id].name, 'left', data.sender || '对方');
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

        // 主动关闭（用户离开或 reset）→ 不重连
        // 或者后端返回"已有活跃连接"错误 → 不重连
        if (this._intentionalClose || this._preventReconnect) {
            this._intentionalClose = false;
            this._emit('disconnected', {});
            return;
        }

        // 指数退避重连：1s, 2s, 4s, 8s, 16s, 30s...
        const maxDelay = 30000;
        const delay = Math.min(1000 * Math.pow(2, this._reconnectAttempts), maxDelay);
        this._reconnectAttempts++;

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
            this._emit('disconnected', { reconnecting: true });
        }
    };
};

// reconnect: 复用现有 WS 连接发 join；WS 已断则重新计算 PoW 参数后再建连
WebSocketTransport.prototype.reconnect = function (nickname, duration) {
    if (this._ws && this._ws.readyState === WebSocket.OPEN) {
        DebugLogger.log('match', '复用现有WS发送join', { nickname: nickname, readyState: this._ws.readyState });
        this._ws.send(JSON.stringify({
            type: 'join',
            nickname: nickname,
            duration: duration || 600,
            token: adminToken,
            fingerprint: browserFingerprint,
            recovery_code: getUserRecoveryCode() || undefined,
        }));
        return;
    }
    DebugLogger.log('ws', 'WS已断开，直接重连', { readyState: this._ws ? this._ws.readyState : 'null' });
    this.connect(nickname, duration);
};

// preconnect: 页面加载即建立 WS 并启动心跳，不发送 join
WebSocketTransport.prototype.preconnect = function () {
    if (this._ws && (this._ws.readyState === WebSocket.OPEN || this._ws.readyState === WebSocket.CONNECTING)) {
        return;
    }
    this.connect('');  // nickname 为空，onopen 只启心跳不发 join
};

// ================================================================
// 初始化传输层和游戏客户端
// ================================================================
let transport, game;

(async function () {
    // 记录环境信息
    var conn = (navigator.connection || navigator.mozConnection || navigator.webkitConnection);
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
    try {
        const wsProtocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        transport = new WebSocketTransport(wsProtocol + window.location.host + '/ws');
        game = new GameClient(transport);
        transport.preconnect();  // 页面加载即建立 WS 连接并启动心跳
        DebugLogger.log('lifecycle', 'WebSocket preconnect已调用');
        console.log('[Turing] Game client ready');
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
const THEME_KEY = 'theme';
const themeBtn = document.getElementById('btn-theme');

function getStoredTheme() {
    return localStorage.getItem(THEME_KEY) || 'default';
}

function applyTheme(theme) {
    if (theme === 'system') {
        const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.dataset.theme = isDark ? 'dark' : '';
    } else if (theme === 'dark') {
        document.documentElement.dataset.theme = 'dark';
    } else {
        document.documentElement.dataset.theme = '';
    }
}

function updateThemeIcon(theme) {
    if (!themeBtn) return;
    const svg = themeBtn.querySelector('svg');
    if (!svg) return;

    if (theme === 'dark') {
        // 月亮图标
        svg.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
    } else if (theme === 'system') {
        // 显示器图标
        svg.innerHTML = '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>';
    } else {
        // 太阳图标
        svg.innerHTML = '<circle cx="12" cy="12" r="5"/><path d="M12 1v2"/><path d="M12 21v2"/><path d="M4.22 4.22l1.42 1.42"/><path d="M18.36 18.36l1.42 1.42"/><path d="M1 12h2"/><path d="M21 12h2"/><path d="M4.22 19.78l1.42-1.42"/><path d="M18.36 5.64l1.42-1.42"/>';
    }
}

function setTheme(theme) {
    localStorage.setItem(THEME_KEY, theme);
    applyTheme(theme);
    updateThemeIcon(theme);
}

// 按钮点击循环切换：默认 → 暗色 → 跟随系统 → 默认
const themeCycle = ['default', 'dark', 'system'];
themeBtn?.addEventListener('click', () => {
    const current = getStoredTheme();
    const idx = themeCycle.indexOf(current);
    const next = themeCycle[(idx + 1) % themeCycle.length];
    setTheme(next);
});

// 初始化
const initialTheme = getStoredTheme();
applyTheme(initialTheme);
updateThemeIcon(initialTheme);

// 系统主题变化时自动跟随（仅 system 模式生效）
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (getStoredTheme() === 'system') {
        applyTheme('system');
    }
});

// ================================================================
//  个人战绩记录
// ================================================================

function getLbCode() { return getUserRecoveryCode(); }
function saveLbCode(code) { setUserRecoveryCode(code); }

function updateLbUI() {
    const code = getLbCode();

    // 设置面板中的战绩展示
    const recoverArea = document.getElementById('lb-recover-area');
    const myStatsEl = document.getElementById('lb-my-stats');

    if (code) {
        recoverArea.style.display = 'none';
        myStatsEl.style.display = '';
        document.getElementById('lb-my-code').textContent = code;

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
    var historySection = document.getElementById('chat-history-section');
    if (historySection) {
        if (code) {
            historySection.style.display = '';
            loadChatHistoryList(1);
        } else {
            historySection.style.display = 'none';
        }
    }
}

function updateLbMyStats(stats) {
    if (!stats) return;
    const tt = stats.turing_test || {};
    const hva = stats.WhoisAI || {};
    document.getElementById('lb-my-code').textContent = getLbCode();
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

    // 同步昵称
    if (stats.nickname) {
        setUserNickname(stats.nickname);
        document.getElementById('nickname-input').value = stats.nickname;
    }
}

// ---- 导出战绩图 ----

async function exportStatsImage() {
    const code = getLbCode();
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
        });
        const link = document.createElement('a');
        link.download = '我的战绩_' + code + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    } finally {
        document.body.removeChild(container);
    }
}

// ---- 自动初始化恢复码 ----

function autoInitRecoveryCode() {
    if (getLbCode()) {
        updateLbUI();
        return;
    }
    const nickname = getUserNickname() || '';
    if (!nickname) return; // 首次访问，等用户填写昵称后由 validateNickname 触发

    fetch('/api/generate-code?fp=' + encodeURIComponent(browserFingerprint) + '&nickname=' + encodeURIComponent(nickname))
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            saveLbCode(data.code);
            updateLbUI();
            if (data.stats) { updateLbMyStats(data.stats); mergeServerStats(data.stats); }
        })
        .catch(() => {});
}

document.getElementById('btn-export-stats').addEventListener('click', exportStatsImage);

// ---- 恢复码输入事件 ----

document.getElementById('btn-recover-lb').addEventListener('click', () => {
    const code = document.getElementById('lb-recover-input').value.trim();
    if (!code) {
        alert('请输入恢复码');
        return;
    }
    const nickname = getUserNickname();
    if (!nickname) {
        alert('请先在首页填写昵称');
        return;
    }
    fetch('/api/player-stats?code=' + encodeURIComponent(code) + '&nickname=' + encodeURIComponent(nickname))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }
            saveLbCode(data.code);
            updateLbUI();
            updateLbMyStats(data);
            mergeServerStats(data);
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
    const code = getUserRecoveryCode();
    if (!code) return;

    _chatHistoryPage = page || 1;

    const listEl = document.getElementById('chat-history-list');
    listEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';

    fetch('/api/chat-history?code=' + encodeURIComponent(code) + '&page=' + _chatHistoryPage)
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
        html += `
            <div class="chat-history-row" data-id="${item.id}" style="padding:8px 10px;margin-bottom:6px;background:var(--note-green);border:2px solid var(--ink-black);border-radius:8px 3px 8px 3px;cursor:pointer;font-size:13px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span><b>${escapeHtml(item.player_name)}</b> vs ${escapeHtml(item.opponent_name)}</span>
                    <span style="color:${resultColor};font-weight:bold;font-size:12px;">${resultBadge}</span>
                </div>
                <div style="font-size:11px;color:#888;margin-top:2px;">
                    ${guessLabels[item.player_guess] || '未判定'} · ${truthLabels[item.opponent_truth] || ''} · ${item.message_count}条消息 · ${time}
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
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:var(--ink-blue);color:var(--surface-white);border-radius:4px;margin:0 2px;">' + i + '</span>';
        } else {
            pageHtml += '<span style="cursor:pointer;padding:2px 8px;border:1px solid var(--ink-blue);border-radius:4px;margin:0 2px;" data-pg="' + i + '">' + i + '</span>';
        }
    }
    pagEl.innerHTML = pageHtml;
    pagEl.querySelectorAll('[data-pg]').forEach(el => {
        el.addEventListener('click', () => loadChatHistoryList(parseInt(el.dataset.pg)));
    });
}

/** 显示聊天记录详情 */
function showChatHistoryDetail(id) {
    const code = getUserRecoveryCode();
    if (!code) return;

    const overlay = document.getElementById('chat-history-detail-overlay');
    const titleEl = document.getElementById('chat-detail-title');
    const infoEl = document.getElementById('chat-detail-info');
    const msgEl = document.getElementById('chat-detail-messages');

    titleEl.textContent = '加载中...';
    infoEl.innerHTML = '';
    msgEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px;">加载中...</div>';
    overlay.style.display = 'block';

    fetch('/api/chat-history/detail?id=' + id + '&code=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                msgEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:20px;">' + escapeHtml(data.error) + '</div>';
                return;
            }

            const resultLabels = { 'win': '胜利', 'lose': '失败', 'draw': '平局' };
            const time = data.created_at ? data.created_at.substring(0, 16).replace('T', ' ') : '';

            titleEl.textContent = escapeHtml(data.player_name) + ' vs ' + escapeHtml(data.opponent_name);
            infoEl.innerHTML = '结果：<b>' + (resultLabels[data.result] || '') + '</b> · ' + data.message_count + '条消息 · ' + time;

            if (!data.messages || data.messages.length === 0) {
                msgEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px;">无聊天消息</div>';
                return;
            }

            let msgHtml = '';
            data.messages.forEach(msg => {
                const isRight = msg.side === 'right';
                const bg = isRight ? '#d3e2ed' : '#fdf5c9';
                const align = isRight ? 'flex-end' : 'flex-start';
                const radius = isRight ? '12px 12px 0 12px' : '12px 12px 12px 0';
                msgHtml += `
                    <div style="display:flex;justify-content:${align};margin-bottom:8px;">
                        <div style="max-width:75%;padding:8px 12px;background:${bg};border:1.5px solid #2b2b2b;border-radius:${radius};font-size:13px;line-height:1.4;">
                            <div style="font-size:10px;color:#888;">${escapeHtml(msg.sender)} · ${escapeHtml(msg.time || '')}</div>
                            <div style="margin-top:2px;">${escapeHtml(msg.text)}</div>
                        </div>
                    </div>
                `;
            });
            msgEl.innerHTML = msgHtml;
        })
        .catch(() => {
            msgEl.innerHTML = '<div style="text-align:center;color:#f44336;padding:20px;">加载失败</div>';
        });
}

// 关闭详情弹窗事件
document.getElementById('btn-chat-detail-close').addEventListener('click', () => {
    document.getElementById('chat-history-detail-overlay').style.display = 'none';
});

document.getElementById('chat-history-detail-overlay').addEventListener('click', (e) => {
    if (e.target === e.currentTarget || e.target.classList.contains('admin-overlay-bg')) {
        document.getElementById('chat-history-detail-overlay').style.display = 'none';
    }
});