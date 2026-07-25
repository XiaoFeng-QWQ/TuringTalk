// ================================================================
// 调试日志器（IndexedDB 存储，支持导出压缩包）
// ================================================================
const DebugLogger = (() => {
    const DB_NAME = 'turing_debug';
    const DB_VERSION = 1;
    const STORE_NAME = 'logs';
    const MAX_LOGS = 5000;

    let _db = null, _ready = false, _openPromise = null, _pendingLogs = [];

    function openDB() {
        if (_openPromise) return _openPromise;
        _openPromise = new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('ts', 'ts', { unique: false });
                    store.createIndex('category', 'category', { unique: false });
                }
            };
            req.onsuccess = (e) => {
                _db = e.target.result;
                _ready = true;
                resolve(_db);
                if (_pendingLogs.length > 0) {
                    const batch = _pendingLogs.splice(0);
                    batch.forEach(function(e) { _writeLog(e); });
                }
            };
            req.onerror = function() {
                console.warn('[DebugLogger] IndexedDB open failed, logging disabled');
                _ready = false;
                reject(req.error);
            };
        });
        return _openPromise;
    }

    function _writeLog(entry) {
        if (!_db || !_ready) {
            _pendingLogs.push(entry);
            if (_pendingLogs.length > 200) _pendingLogs.shift();
            return;
        }
        try {
            var tx = _db.transaction(STORE_NAME, 'readwrite');
            var store = tx.objectStore(STORE_NAME);
            store.add(entry);
        } catch (e) {
            _pendingLogs.push(entry);
        }
    }

    function _prune() {
        if (!_db || !_ready) return;
        try {
            var tx2 = _db.transaction(STORE_NAME, 'readwrite');
            var store2 = tx2.objectStore(STORE_NAME);
            var countReq = store2.count();
            countReq.onsuccess = function() {
                var excess = countReq.result - MAX_LOGS;
                if (excess <= 0) return;
                var idx = store2.index('ts');
                var cursorReq = idx.openCursor();
                var deleted = 0;
                cursorReq.onsuccess = function(e) {
                    var cursor = e.target.result;
                    if (cursor && deleted < excess) {
                        cursor.delete();
                        deleted++;
                        cursor.continue();
                    }
                };
            };
        } catch (e) { /* ignore */ }
    }

    /** @param {string} category - ws/match/game/error/lifecycle/timer/network */
    /** @param {string} message */
    /** @param {*} [data] */
    function log(category, message, data) {
        var entry = {
            ts: Date.now(),
            category: String(category),
            message: String(message),
            data: data !== undefined && data !== null ? JSON.stringify(data) : null
        };
        if (!_ready) { openDB().catch(function(){}); }
        if (_ready) {
            _writeLog(entry);
            if (Math.random() < 0.05) _prune();
        } else {
            _pendingLogs.push(entry);
            if (_pendingLogs.length > 200) _pendingLogs.shift();
        }
    }

    function exportJSON() {
        return new Promise(function(resolve, reject) {
            if (!_db || !_ready) { resolve(JSON.stringify(_pendingLogs, null, 2)); return; }
            var tx3 = _db.transaction(STORE_NAME, 'readonly');
            var store3 = tx3.objectStore(STORE_NAME);
            var req = store3.getAll();
            req.onsuccess = function() { resolve(JSON.stringify(req.result, null, 2)); };
            req.onerror = function() { reject(req.error); };
        });
    }

    async function download() {
        try {
            var json = await exportJSON();
            var encoder = new TextEncoder();
            var uint8 = encoder.encode(json);
            var blob;
            if (typeof CompressionStream !== 'undefined') {
                var cs = new CompressionStream('gzip');
                var writer = cs.writable.getWriter();
                writer.write(uint8);
                writer.close();
                var reader = cs.readable.getReader();
                var chunks = [];
                while (true) {
                    var chunk = await reader.read();
                    if (chunk.done) break;
                    chunks.push(chunk.value);
                }
                var totalLen = 0;
                for (var i = 0; i < chunks.length; i++) totalLen += chunks[i].length;
                var combined = new Uint8Array(totalLen);
                var offset = 0;
                for (var i2 = 0; i2 < chunks.length; i2++) {
                    combined.set(chunks[i2], offset);
                    offset += chunks[i2].length;
                }
                blob = new Blob([combined], { type: 'application/gzip' });
            } else {
                blob = new Blob([uint8], { type: 'application/json' });
            }
            var ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
            var ext = (typeof CompressionStream !== 'undefined') ? '.json.gz' : '.json';
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'turing-debug-' + ts + ext;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            return { count: (json.match(/"ts"/g) || []).length };
        } catch (e) {
            console.error('[DebugLogger] Export failed:', e);
            throw e;
        }
    }

    async function getCount() {
        if (!_db || !_ready) return _pendingLogs.length;
        return new Promise(function(resolve) {
            var tx4 = _db.transaction(STORE_NAME, 'readonly');
            var req2 = tx4.objectStore(STORE_NAME).count();
            req2.onsuccess = function() { resolve(req2.result); };
            req2.onerror = function() { resolve(0); };
        });
    }

    openDB().catch(function(){});
    setInterval(function() {
        if (_ready && _pendingLogs.length > 0) {
            var batch = _pendingLogs.splice(0);
            for (var j = 0; j < batch.length; j++) _writeLog(batch[j]);
        }
    }, 5000);

    return { log: log, exportJSON: exportJSON, download: download, count: getCount };
})();

// ================================================================
// 传输层基类
// ================================================================
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
// 本地 Bot 传输层（单机模拟模式）
// ================================================================
class LocalBotTransport extends ChatTransport {
    constructor() {
        super();
        this._opponentTruth = null;
        this._replyTimer = null;
        this._judgeTimer = null;
    }

    connect(nickname) {
        this._opponentTruth = Math.random() < 0.5 ? 'human' : 'ai';

        const delay = 1000 + Math.random() * 2000;
        setTimeout(() => {
            this._emit('connected', {
                opponent_name: '对方'
            });
        }, delay);
    }

    sendMessage(text) {
        const botReplies = [
            '你好呀！今天天气不错~',
            '你平时喜欢做什么？',
            '哈哈，这个问题有意思',
            '我觉得人类比 AI 有趣多了',
            '那你觉得我是人还是 AI 呢？',
            '嗯...让我想想怎么回答',
            '说实话，我也分不太清你是谁',
            '周末有什么计划吗？',
            '我喜欢看电影和听音乐',
            '你这问题问得很有水平啊',
            '😄 有意思，继续聊聊',
            '我有时候会想，AI 也会有感情吗？',
            '别试探我了，好好聊天不行吗',
            '你是哪里人呀？',
            '这个话题有点哲学了呢',
            '哈哈，或许我们都是 AI 也说不定',
            '最近有什么好看的电影推荐吗？',
            '我感觉你挺有趣的',
            '饿了，等会去吃个火锅',
            '你相信图灵测试吗？'
        ];

        const delay = 1000 + Math.random() * 2000;
        this._replyTimer = setTimeout(() => {
            const reply = botReplies[Math.floor(Math.random() * botReplies.length)];
            this._emit('message', {
                text: reply,
                sender: '对方'
            });
        }, delay);
    }

    sendJudgement(guess, tag) {
        const willDecide = Math.random() < 0.8;
        if (willDecide) {
            const delay = 2000 + Math.random() * 3000;
            this._judgeTimer = setTimeout(() => {
                this._emit('opponent_judged', {
                    truth: this._opponentTruth
                });
            }, delay);
        }
    }

    disconnect() {
        if (this._replyTimer) {
            clearTimeout(this._replyTimer);
            this._replyTimer = null;
        }
        if (this._judgeTimer) {
            clearTimeout(this._judgeTimer);
            this._judgeTimer = null;
        }
        this._emit('disconnected', {});
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
    }

    connect(nickname, duration) {
        this._ws = new WebSocket(this._url);

        this._ws.onopen = () => {
            this._ws.send(JSON.stringify({
                type: 'join',
                nickname: nickname,
                duration: duration || 600
            }));

            // 启动心跳：每 25 秒发送一次 ping，防止代理/防火墙切断空闲连接
            this._heartbeatTimer = setInterval(() => {
                if (this._ws && this._ws.readyState === WebSocket.OPEN) {
                    this._ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 25000);
        };

        this._ws.onmessage = (event) => {
            let data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                console.warn('[WS] JSON parse error, raw data:', event.data);
                return;
            }
            switch (data.type) {
                case 'matched':
                    this._emit('connected', {
                        opponent_name: data.opponent_name,
                        duration: data.duration,
                        session_id: data.session_id,
                    });
                    break;
                case 'message':
                    this._emit('message', {
                        text: data.text,
                        sender: data.sender
                    });
                    break;
                case 'system':
                    this._emit('system', { text: data.text });
                    break;
                case 'banned':
                    alert(data.text);
                    break;
                case 'opponent_banned':
                    stopChat();
                    this._emit('system', { text: data.text });
                    this._emit('opponent_timeout', {
                        reason: 'opponent_banned',
                        opponent_truth: data.opponent_truth,
                    });
                    break;
                case 'judged':
                    this._emit('opponent_judged', {
                        truth: data.truth,
                        opponent_guess: data.opponent_guess,
                        opponent_tag: data.opponent_tag || '',
                        session_id: data.session_id,
                    });
                    break;
                case 'judge_notify':
                    this._emit('judge_notify', { message: data.message });
                    break;
                case 'timeout':
                    this._emit('opponent_timeout', {
                        reason: data.reason,
                        session_id: data.session_id,
                        opponent_truth: data.opponent_truth,
                    });
                    break;
                case 'error':
                    this._emit('system', { text: data.message });
                    break;
                case 'admin_connected':
                    this._adminConnected = true;
                    break;
                case 'save_history_status':
                    this._emit('save_history_status', data);
                    break;
                default:
                    if (this._adminHandler) this._adminHandler(data);
                    break;
            }
        };

        this._ws.onerror = () => {
            this._emit('error', { text: 'WebSocket 连接失败' });
        };

        this._ws.onclose = () => {
            this._emit('disconnected', {});
        };
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

        localStorage.setItem('turing_nickname', this._nickname);

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
        charCount.style.color = '#999';
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
                timerDisplay.style.color = '#f44336';
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

        // 通知服务端离开，清理服务端排队/对局状态
        if (this._transport && this._transport._ws
            && this._transport._ws.readyState === WebSocket.OPEN) {
            try {
                this._transport._ws.send(JSON.stringify({ type: 'leave' }));
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

        document.getElementById('system-id').textContent = generateId();

        this._disconnecting = false;
    }

    resetAndPlay() {
        DebugLogger.log('game', 'resetAndPlay被调用');
        const nickname = localStorage.getItem('turing_nickname') || 'You';
        const durationSelect = document.getElementById('duration-select');
        const duration = parseInt(durationSelect?.value) || 600;
        this._nickname = nickname;
        this._sessionId = '';

        // 清理 UI 和本地状态（跳过 reset()，因为我们要断开 WS 而不是发 leave）
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
        document.getElementById('system-id').textContent = generateId();
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
        this._opponentName = data.opponent_name;
        this._duration = data.duration || 600;
        this._sessionId = data.session_id || '';

        DebugLogger.log('game', '对局开始', { opponent: this._opponentName, session_id: this._sessionId, duration: this._duration });

        const infoDiv = document.querySelector('.opponent-info > div:nth-of-type(2)');
        if (infoDiv) {
            infoDiv.innerHTML = `
                        <div style="font-size: 12px; color: #888;">当前对手</div>
                        <strong style="font-size: 18px;">${escapeHtml(this._opponentName)}</strong>
                    `;
        }

        matchingPage.style.display = 'none';
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
        const sysMsg = chatBody.querySelector('.sys-msg');
        chatBody.innerHTML = '';
        if (sysMsg) chatBody.appendChild(sysMsg);

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
                timerDisplay.style.color = '#f44336';
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
                    timerDisplay.style.color = '#f44336';
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
        notifyDiv.style.color = '#f44336';
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
            renderResult('opponent', this._userGuess, this._opponentTruth, null);
        }
    }

    _onDisconnected() {
        DebugLogger.log('ws', '客户端收到disconnected事件');
        if (this._disconnecting) return;
        if (chatPage.style.display === 'flex') {
            this.reset();
        }
    }

    _onError(data) {
        DebugLogger.log('error', '传输层错误', { text: data.text });
        console.error('传输层错误:', data.text);
        alert('连接出错，请刷新页面重试');
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
            banner.style.cssText = 'padding:14px 20px;margin-bottom:16px;background:#fde2e4;color:#c62828;font-size:15px;font-weight:bold;text-align:center;animation:wiggle 0.3s ease;';
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
            statusEl.style.color = '#4caf50';
            statusEl.textContent = data.message || '聊天记录已保存';
            if (btnSave) btnSave.style.display = 'none';
        } else {
            statusEl.style.color = '#f44336';
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

const origLogoHTML = logoText.innerHTML;

const nicknameInput = document.getElementById('nickname-input');
const savedNickname = localStorage.getItem('turing_nickname');
if (savedNickname) {
    nicknameInput.value = savedNickname;
}

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
document.getElementById('system-id').textContent = generateId();

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

function getNickname() {
    return localStorage.getItem('turing_nickname') || 'You';
}

function formatTime(s) {
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
}

function escapeHtml(str) {
    return ('' + str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
    const isWin = (timeoutReason === 'opponent')
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

    const verdict = timeoutReason === 'opponent' ? '对方超时未判定，你赢了！'
        : timeoutReason === 'you' ? '你超时未判定，对方赢了...'
            : timeoutReason === 'both' ? '双方超时，平局'
                : timeoutReason === 'no_mutual_chat' ? '未互发消息，平局不记战绩'
                    : timeoutReason === 'opponent_banned' ? '对方已被封禁，对局结束'
                    : (isWin ? '猜对啦！' : '猜错了...');
    const reveal = isTimeout
        ? (timeoutReason === 'opponent' ? '对方未能在 60 秒内完成判定'
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
                        <span class="value" style="background:var(--ink-blue);color:#fff;padding:2px 10px;border-radius:12px 3px 12px 3px;font-size:13px;">${escapeHtml(opponentTag)}</span>
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

    document.getElementById('btn-export-image').addEventListener('click', () => {
        exportChatImage(verdict, reveal, isWin, guessLabel, isTimeout ? (timeoutReason === 'opponent' ? '对方未判定' : timeoutReason === 'you' ? '你未判定' : '双方未判定') : truthLabel, opponentGuessLabel);
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

function resetGame() {
    game.resetAndPlay();
}

// ================================================================
//  战局统计
// ================================================================

const STATS_KEY = 'turing_stats';

function getStats() {
    try {
        const raw = localStorage.getItem(STATS_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
}

function saveStats(s) {
    try {
        localStorage.setItem(STATS_KEY, JSON.stringify(s));
    } catch (_) {}
}

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
    if (result.timeoutReason === 'opponent') {
        s.wins++;  // 对方超时，你赢
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

async function exportChatImage(verdict, reveal, isCorrect, guessLabel, truthLabel, opponentGuessLabel) {
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

    // 聊天记录
    const bubbles = chatBody.querySelectorAll('.bubble');
    let chatHTML = '<div style="padding:18px 24px;background:#fff;">';
    if (bubbles.length === 0) {
        chatHTML += '<div style="text-align:center;color:#aaa;padding:30px;">暂无聊天记录</div>';
    } else {
        bubbles.forEach(b => {
            const isRight = b.classList.contains('bubble-right');
            const bg = isRight ? '#d3e2ed' : '#fdf5c9';
            const align = isRight ? 'flex-end' : 'flex-start';
            const radius = isRight ? '15px 15px 0 15px' : '15px 15px 15px 0';
            chatHTML += `
                        <div style="display:flex;justify-content:${align};margin-bottom:16px;">
                            <div style="max-width:75%;padding:10px 16px;background:${bg};border:2px solid #2b2b2b;border-radius:${radius};font-size:15px;line-height:1.5;color:#2b2b2b;">
                                ${b.innerHTML}
                            </div>
                        </div>
                    `;
        });
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
        statusEl.style.color = '#f44336';
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
        statusEl.style.color = '#f44336';
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
    charCount.style.color = len > 280 ? '#e74c3c' : len > 250 ? '#f39c12' : '#999';
});

btnBack.addEventListener('click', function (e) {
    if (chatPage.style.display === 'flex') {
        // 旁观模式：退出旁观不走 resetState
        if (spectateSessionId) {
            if (confirm('确定退出旁观吗？')) {
                DebugLogger.log('game', '退出旁观');
                adminSend('admin_unspectate');
                exitSpectatorView();
            }
            return;
        }
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
});

btnSettings.addEventListener('click', () => {
    settingsOverlay.style.display = 'block';
    // 更新日志条数显示
    DebugLogger.count().then(function(n) {
        var btn = document.getElementById('btn-export-debug');
        if (btn) btn.textContent = '导出调试日志 (' + n + ' 条)';
    });
});

btnCloseSettings.addEventListener('click', () => {
    settingsOverlay.style.display = 'none';
});

settingsOverlay.addEventListener('click', (e) => {
    if (e.target === settingsOverlay) {
        settingsOverlay.style.display = 'none';
    }
});

// ================================================================
// SSE 事件流（在线人数 + 全服公告）
// ================================================================
let sseSource = null;

function connectSSE() {
    if (sseSource) {
        sseSource.close();
        sseSource = null;
    }
    sseSource = new EventSource('/api/sse');

    sseSource.addEventListener('connected', () => {
        // SSE 连接已建立
    });

    sseSource.addEventListener('broadcast', (e) => {
        const data = JSON.parse(e.data);
        showDanmaku(data.text);
    });

    sseSource.addEventListener('online_count', (e) => {
        const data = JSON.parse(e.data);
        document.getElementById('online-num').textContent = data.count;
    });
    

    sseSource.onerror = () => {
        // SSE 断线，5s 后自动重连
        DebugLogger.log('network', 'SSE连接断开，5s后重连');
        if (sseSource) {
            sseSource.close();
            sseSource = null;
        }
        document.getElementById('online-num').textContent = '--';
        setTimeout(connectSSE, 5000);
    };
}

// 页面加载时立即建立 SSE
connectSSE();

// ================================================================
// 初始化传输层和游戏客户端
// ================================================================
// 本地模式（调试用）
// const transport = new LocalBotTransport();

// WebSocket 模式（连接后端）
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

// ================================================================
// 管理员功能
// ================================================================
let adminToken = sessionStorage.getItem('turing_admin_token') || '';
let spectateSessionId = null;
// 搜索状态：缓存服务端返回的完整列表 + 当前搜索关键字
let _cachedSessions = [];
let _sessionSearchKeyword = '';

// DOM 元素
const adminPanelOverlay = document.getElementById('admin-panel-overlay');
const btnAdminPanel = document.getElementById('btn-admin-panel');
const btnCloseAdminPanel = document.getElementById('btn-close-admin-panel');
const btnExitAdmin = document.getElementById('btn-exit-admin');
const broadcastInput = document.getElementById('broadcast-input');
const btnSendBroadcast = document.getElementById('btn-send-broadcast');
const broadcastStatus = document.getElementById('broadcast-status');
const btnRefreshSessions = document.getElementById('btn-refresh-sessions');
const searchSessionsInput = document.getElementById('search-sessions-input');
const sessionsList = document.getElementById('sessions-list');
const spectateInfo = document.getElementById('spectate-info');
const spectateDetail = document.getElementById('spectate-detail');
const btnExitSpectate = document.getElementById('btn-exit-spectate');
const announcementArea = document.getElementById('announcement-area');

// 举报相关
const btnReport = document.getElementById('btn-report');
const reportOverlay = document.getElementById('report-overlay');
const reportReason = document.getElementById('report-reason');
const btnReportCancel = document.getElementById('btn-report-cancel');
const btnReportSubmit = document.getElementById('btn-report-submit');
const reportError = document.getElementById('report-error');

// 举报审核相关
const tabSessions = document.getElementById('tab-sessions');
const tabReports = document.getElementById('tab-reports');
const panelSessions = document.getElementById('panel-sessions');
const panelReports = document.getElementById('panel-reports');
const reportsList = document.getElementById('reports-list');
const reportsPagination = document.getElementById('reports-pagination');
const reportDetailOverlay = document.getElementById('report-detail-overlay');
const reportDetailTitle = document.getElementById('report-detail-title');
const reportDetailContent = document.getElementById('report-detail-content');
const reportDetailChat = document.getElementById('report-detail-chat');
const btnReportDetailClose = document.getElementById('btn-report-detail-close');
const btnReportDetailReviewed = document.getElementById('btn-report-detail-reviewed');
// 举报审核状态
let _reportsPage = 1;
let _reportsFilter = ''; // ''=全部, '0'=未审, '1'=已审
let _currentDetailReportId = null;
let _currentDetailReason = '';

// ==============================
// 管理面板 - 标签切换
// ==============================
tabSessions.addEventListener('click', () => switchAdminTab('sessions'));
tabReports.addEventListener('click', () => switchAdminTab('reports'));

function switchAdminTab(tab) {
    const isSessions = (tab === 'sessions');
    tabSessions.classList.toggle('active', isSessions);
    tabSessions.style.background = isSessions ? '#e8e0d4' : 'transparent';
    tabSessions.style.color = isSessions ? 'var(--ink-blue)' : '#999';
    tabReports.classList.toggle('active', !isSessions);
    tabReports.style.background = !isSessions ? '#e8e0d4' : 'transparent';
    tabReports.style.color = !isSessions ? 'var(--ink-blue)' : '#999';
    panelSessions.style.display = isSessions ? '' : 'none';
    panelReports.style.display = !isSessions ? '' : 'none';

    if (!isSessions) {
        // 切换到举报审核，自动加载
        loadReports(1);
    }
}

// ==============================
// 举报审核 - 筛选按钮
// ==============================
document.querySelectorAll('.report-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.report-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        _reportsFilter = btn.dataset.filter;
        loadReports(1);
    });
});

// ==============================
// 举报审核 - 加载列表
// ==============================
function loadReports(page) {
    _reportsPage = page;
    reportsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';

    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) {
        reportsList.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;">管理员连接未就绪</div>';
        return;
    }

    adminSend('admin_reports', {
        page: page,
        page_size: 20,
        reviewed: _reportsFilter || null,
    });
}

function renderReportsList(reports, total, page, pageSize) {
    if (!reports || reports.length === 0) {
        const msg = _reportsFilter === '0' ? '暂无未审核的举报' : (_reportsFilter === '1' ? '暂无已审核的举报' : '暂无举报记录');
        reportsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">' + msg + '</div>';
        reportsPagination.innerHTML = '';
        return;
    }

    const reviewedMap = { '1': '已审', '0': '未审' };

    let html = '';
    reports.forEach(r => {
        const time = r.created_at ? r.created_at.substring(0, 16).replace('T', ' ') : '';
        const reviewedBadge = r.reviewed == 1
            ? '<span style="color:#27ae60;">已审</span>'
            : '<span style="color:#e74c3c;">未审</span>';
        html += `
            <div class="session-row" style="flex-wrap:wrap;cursor:pointer;" data-rid="${r.id}">
                <div class="session-info" style="flex:1;min-width:0;">
                    <div style="font-size:12px;font-weight:bold;">
                        ${escapeHtml(r.reporter_name || '?')} 举报 ${escapeHtml(r.target_name || '?')}
                        <span style="font-size:10px;">${reviewedBadge}</span>
                    </div>
                    <div style="font-size:11px;color:#888;">${escapeHtml(r.reason || '无原因')} · ${time}</div>
                </div>
                <span style="font-size:10px;color:#aaa;white-space:nowrap;">${r.has_history > 0 ? '有记录' : '无记录'}</span>
            </div>
        `;
    });
    reportsList.innerHTML = html;

    // 点击打开详情
    reportsList.querySelectorAll('.session-row').forEach(row => {
        row.addEventListener('click', () => {
            const rid = parseInt(row.dataset.rid);
            if (rid) openReportDetail(rid);
        });
    });

    // 分页
    const totalPages = Math.ceil(total / pageSize);
    if (totalPages <= 1) {
        reportsPagination.innerHTML = '';
        return;
    }
    let pageHtml = '';
    for (let i = 1; i <= totalPages; i++) {
        if (i === page) {
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:var(--ink-blue);color:#fff;border-radius:4px;">' + i + '</span>';
        } else {
            pageHtml += '<span style="cursor:pointer;padding:2px 8px;border:1px solid var(--ink-blue);border-radius:4px;" data-pg="' + i + '">' + i + '</span>';
        }
    }
    reportsPagination.innerHTML = pageHtml;
    reportsPagination.querySelectorAll('[data-pg]').forEach(el => {
        el.addEventListener('click', () => loadReports(parseInt(el.dataset.pg)));
    });
}

// ==============================
// 举报审核 - 详情弹窗
// ==============================
function openReportDetail(reportId) {
    _currentDetailReportId = reportId;
    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) return;

    reportDetailContent.innerHTML = '加载中...';
    reportDetailChat.innerHTML = '';
    reportDetailOverlay.style.display = 'flex';

    adminSend('admin_report_detail', { report_id: reportId });
}

function renderReportDetail(report) {
    reportDetailTitle.textContent = '举报详情 #' + report.id;
    const reviewedText = report.reviewed == 1 ? '已审核' : '未审核';
    _currentDetailReason = report.reason || '';
    _currentDetailReportId = report.id;

    const banBtn = (ip, fp, label) => {
        const fpShort = fp ? fp.substring(0, 12) + '...' : '(空)';
        return `
            <span>${label}</span>
            <span style="font-size:10px;color:#888;margin:0 4px;">IP: ${escapeHtml(ip)} · FP: ${fpShort}</span>
            <button class="doodle-btn ban-info-btn" data-ip="${escapeHtml(ip)}" data-fp="${escapeHtml(fp)}"
                style="font-size:10px;padding:2px 8px;border-color:#e74c3c;color:#e74c3c;">封禁</button>
        `;
    };

    reportDetailContent.innerHTML = `
        <div style="margin-bottom:6px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
            ${banBtn(report.reporter_ip, report.reporter_fingerprint || '', '举报者: ' + escapeHtml(report.reporter_name || '?'))}
        </div>
        <div style="margin-bottom:6px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
            ${banBtn(report.target_ip, report.target_fingerprint || '', '被举报者: ' + escapeHtml(report.target_name || '?'))}
        </div>
        <p><b>原因：</b>${escapeHtml(report.reason || '无')}</p>
        <p><b>时间：</b>${escapeHtml(report.created_at)}</p>
        <p><b>状态：</b>${reviewedText}</p>
    `;

    btnReportDetailReviewed.style.display = report.reviewed == 1 ? 'none' : '';

    // 绑定封禁按钮
    reportDetailContent.querySelectorAll('.ban-info-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const ip = btn.dataset.ip;
            const fp = btn.dataset.fp;
            const reasonText = _currentDetailReason ? '原因: ' + _currentDetailReason : '无原因';
            if (!confirm('确认封禁？\nIP: ' + (ip || '(空)') + '\n指纹: ' + (fp ? fp.substring(0, 16) + '...' : '(空)') + '\n' + reasonText)) return;
            adminSend('admin_ban_by_info', { ip: ip, fingerprint: fp, reason: _currentDetailReason });
        });
    });

    // 渲染聊天记录
    const chatHistory = report.chat_history;
    if (chatHistory && chatHistory.messages && chatHistory.messages.length > 0) {
        let chatHtml = '<div style="font-size:11px;color:#888;margin-bottom:4px;">'
            + '对局：' + escapeHtml(chatHistory.player1) + ' vs ' + escapeHtml(chatHistory.player2)
            + ' · ' + (chatHistory.duration || 0) + '秒</div>';
        chatHistory.messages.forEach(msg => {
            const cls = msg.role === 'system' ? 'color:#e74c3c;' : '';
            chatHtml += '<div style="margin-bottom:6px;' + cls + '">'
                + '<b>' + escapeHtml(msg.sender || msg.role || 'System') + '：</b>'
                + escapeHtml(msg.text || msg.content || '')
                + '</div>';
        });
        reportDetailChat.innerHTML = chatHtml;
    } else {
        reportDetailChat.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">无聊天记录（可能对局未被举报或已清理）</div>';
    }
}

btnReportDetailClose.addEventListener('click', () => {
    reportDetailOverlay.style.display = 'none';
    _currentDetailReportId = null;
});

reportDetailOverlay.addEventListener('click', (e) => {
    if (e.target === reportDetailOverlay) {
        reportDetailOverlay.style.display = 'none';
        _currentDetailReportId = null;
    }
});

btnReportDetailReviewed.addEventListener('click', () => {
    if (!_currentDetailReportId) return;
    if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) return;

    adminSend('admin_mark_reviewed', { report_id: _currentDetailReportId });
});

// ==============================
// 初始化管理员 UI
if (adminToken) {
    btnAdminPanel.style.display = 'inline-flex';
}

// --- 管理员命令复用游戏 WebSocket（不新建连接）---
let _adminReady = false;

/**
 * 确保管理员模式在游戏 WS 上已激活
 * 发送 admin_connect 并等待 admin_connected 回执
 */
function ensureAdminConnected() {
    return new Promise((resolve) => {
        if (_adminReady) { resolve(); return; }
        if (!transport || !transport._ws || transport._ws.readyState !== WebSocket.OPEN) {
            return; // 游戏 WS 未就绪，等下次
        }

        // 接管 transport 的 admin 消息处理
        transport._adminHandler = (data) => {
            switch (data.type) {
                case 'admin_connected':
                    _adminReady = true;
                    resolve();
                    break;
                case 'sessions_list':
                    renderSessionsList(data.sessions);
                    break;
                case 'session_detail':
                    enterSpectatorView(data.session_id, data.player1, data.player2, data.history || []);
                    break;
                case 'spectate_message':
                    appendMessage(data.text, data.side || 'left', data.sender);
                    break;
                case 'spectate_system':
                    if (typeof game !== 'undefined' && game._onSystem) {
                        game._onSystem({ text: data.text });
                    }
                    break;
                case 'spectate_ended':
                    if (spectateSessionId === data.session_id) {
                        exitSpectatorView();
                        if (data.result) {
                            alert('对局结束！\n玩家1: ' + data.result.player1_truth + '\n玩家2: ' + data.result.player2_truth);
                        }
                    }
                    break;
                case 'admin_unspectated':
                    exitSpectatorView();
                    break;
                case 'admin_reports':
                    renderReportsList(data.reports, data.total, data.page, data.page_size);
                    break;
                case 'admin_report_detail':
                    renderReportDetail(data.report);
                    break;
                case 'admin_mark_reviewed':
                    reportDetailOverlay.style.display = 'none';
                    _currentDetailReportId = null;
                    loadReports(_reportsPage);
                    break;
                case 'admin_banned_by_info':
                    alert(data.message || '封禁完成');
                    break;
                case 'room_announce':
                    showDanmaku(data.text, '管理警告');
                    break;
                case 'error':
                    showAdminError(data.message || '管理操作出错');
                    break;
            }
        };

        transport._ws.send(JSON.stringify({ type: 'admin_connect', token: adminToken }));
    });
}

function adminSend(type, payload = {}) {
    if (transport && transport._ws && transport._ws.readyState === WebSocket.OPEN) {
        transport._ws.send(JSON.stringify({ type, token: adminToken, ...payload }));
    }
}

// --- 管理员面板开关 ---
btnAdminPanel.addEventListener('click', () => {
    adminPanelOverlay.style.display = 'block';
});

btnCloseAdminPanel.addEventListener('click', () => {
    adminPanelOverlay.style.display = 'none';
});

adminPanelOverlay.addEventListener('click', (e) => {
    if (e.target === adminPanelOverlay) {
        adminPanelOverlay.style.display = 'none';
    }
});

// --- 退出管理模式 ---
btnExitAdmin.addEventListener('click', () => {
    if (confirm('确定退出管理模式吗？\nToken 将被清除，需要重新输入密码才能再次进入。')) {
        // 退出旁观
        if (spectateSessionId) {
            adminSend('admin_unspectate');
            exitSpectatorView();
        }
        // 重置管理员状态
        _adminReady = false;
        adminToken = '';
        sessionStorage.removeItem('turing_admin_token');
        btnAdminPanel.style.display = 'none';
        adminPanelOverlay.style.display = 'none';
        // 如果正在对局中，移除封禁按钮
        const banBtn = document.getElementById('btn-admin-ban');
        if (banBtn) banBtn.remove();
    }
});

// --- 全服公告 ---
btnSendBroadcast.addEventListener('click', () => {
    const text = broadcastInput.value.trim();
    if (!text) {
        broadcastStatus.style.display = 'block';
        broadcastStatus.textContent = '请输入公告内容';
        broadcastStatus.style.color = '#f44336';
        return;
    }
    adminSend('admin_broadcast', { text: text });
    broadcastInput.value = '';
    broadcastStatus.style.display = 'block';
    broadcastStatus.textContent = '已发送';
    broadcastStatus.style.color = '#4caf50';
    setTimeout(() => { broadcastStatus.style.display = 'none'; }, 2000);
});

broadcastInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') btnSendBroadcast.click();
});

// --- 刷新对局列表 ---
btnRefreshSessions.addEventListener('click', () => {
    sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">连接中...</div>';
    ensureAdminConnected().then(() => {
        adminSend('admin_sessions');
        sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';
    });
});

// --- 搜索对局 ---
searchSessionsInput.addEventListener('input', () => {
    _sessionSearchKeyword = searchSessionsInput.value;
    _doRenderSessionsList();
});

// 打开面板时自动拉取一次
btnAdminPanel.addEventListener('click', async () => {
    await ensureAdminConnected();
    btnRefreshSessions.click();
});

// --- 退出旁观 ---
btnExitSpectate.addEventListener('click', () => {
    if (spectateSessionId) {
        adminSend('admin_unspectate');
    }
    exitSpectatorView();
});

// --- 全服公告横幅 ---
const ANNOUNCE_DISPLAY_MS = 5000; // 每条公告展示时长
const ANNOUNCE_MAX = 3;           // 最多同时展示条数

let announceQueue = [];            // 待展示队列
let announceShowing = 0;           // 当前正在展示的数量

/**
 * 管理员操作错误提示（红色小横幅，3s 自动消失，不走全服公告样式）
 */
function showAdminError(message) {
    const el = document.createElement('div');
    el.style.cssText = `
        position: fixed; top: 12px; left: 50%; transform: translateX(-50%); z-index: 10000;
        background: #ffe0e0; color: #c0392b; border: 2px solid #e74c3c;
        padding: 8px 20px; border-radius: 8px 4px 8px 4px;
        font-size: 14px; white-space: nowrap;
        animation: announceIn 0.35s ease forwards;
        pointer-events: none;
    `;
    el.textContent = '⚠ ' + message;
    document.body.appendChild(el);

    setTimeout(() => {
        el.style.animation = 'announceOut 0.3s ease forwards';
        el.addEventListener('animationend', () => el.remove(), { once: true });
    }, 3000);
}

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

// --- 旁观模式 ---
function enterSpectatorView(sessionId, player1, player2, history) {
    spectateSessionId = sessionId;

    // 存下玩家 fd 信息，封禁时需要
    window._spectatePlayers = { p1: player1, p2: player2 };

    // 显示聊天页面
    landingPage.style.display = 'none';
    matchingPage.style.display = 'none';
    chatPage.style.display = 'flex';
    resultArea.style.display = 'none';
    btnBack.style.display = 'inline-flex';

    // 清理聊天区
    chatBody.innerHTML = '';

    // 渲染历史消息
    if (history && history.length) {
        for (const msg of history) {
            appendMessage(msg.text, msg.side || 'left', msg.sender);
        }
    }

    // 添加旁观横幅
    let banner = document.getElementById('spectate-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'spectate-banner';
        const notebookContainer = document.querySelector('.notebook-container');
        notebookContainer.insertBefore(banner, notebookContainer.firstChild);
    }
    banner.innerHTML = `
        <div class="spec-row spec-header">你正在旁观对局</div>
        <div class="spec-row">
            <span class="spec-player">
                <span style="color:#ffeb3b;">${escapeHtml(player1.nickname)}</span>
                <span>是 <b>${player1.truth}</b></span>
                ${player1.tag ? `<span class="spectate-tag">${escapeHtml(player1.tag)}</span>` : ''}
            </span>
            <button class="doodle-btn spectate-ban-btn" data-fd="${player1.fd}" data-name="${escapeHtml(player1.nickname)}" style="color:#ffeb3b;border-color:#ffeb3b;">封禁</button>
            <span style="opacity:0.5;">|</span>
            <span class="spec-player">
                <span style="color:#ffeb3b;">${escapeHtml(player2.nickname)}</span>
                <span>是 <b>${player2.truth}</b></span>
                ${player2.tag ? `<span class="spectate-tag">${escapeHtml(player2.tag)}</span>` : ''}
            </span>
            <button class="doodle-btn spectate-ban-btn" data-fd="${player2.fd}" data-name="${escapeHtml(player2.nickname)}" style="color:#ffeb3b;border-color:#ffeb3b;">封禁</button>
            <button class="doodle-btn" id="btn-spectate-leave" style="margin-left:auto;">退出旁观</button>
        </div>
        <div class="spec-row spec-warn-row">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11h2.586a1 1 0 0 1 .707.293l7.414 7.414A.5.5 0 0 0 14.5 18.35V5.65a.5.5 0 0 0-.793-.357L6.293 12.707a1 1 0 0 1-.707.293H3a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1z"/><path d="M16 9.5a4.5 4.5 0 0 1 0 5"/></svg>
            <span style="font-size:12px;white-space:nowrap;opacity:0.85;">房间警告：</span>
            <input type="text" id="room-broadcast-input" placeholder="输入警告内容..." maxlength="100">
            <button class="doodle-btn" id="btn-send-room-broadcast" style="background:#d32f2f;border-color:#d32f2f;">发送</button>
        </div>
    `;

    // 封禁按钮事件（事件委托）
    banner.querySelectorAll('.spectate-ban-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetFd = parseInt(btn.dataset.fd);
            const targetName = btn.dataset.name;
            if (isNaN(targetFd) || targetFd <= 0) {
                showAdminError('无法封禁（对方可能是 AI）');
                return;
            }
            if (!confirm(`确定要封禁「${targetName}」吗？\n封禁后将踢出游戏，IP 和浏览器指纹被拉黑。`)) return;
            adminSend('admin_ban_player', { player_fd: targetFd });
        });
    });

    document.getElementById('btn-spectate-leave').addEventListener('click', () => {
        if (spectateSessionId) {
            adminSend('admin_unspectate');
        }
        exitSpectatorView();
    });

    // 房间公告发送按钮
    const roomInput = document.getElementById('room-broadcast-input');
    document.getElementById('btn-send-room-broadcast').addEventListener('click', () => {
        const text = roomInput.value.trim();
        if (!text) {
            showAdminError('请输入房间公告内容');
            return;
        }
        adminSend('admin_room_broadcast', { text });
        roomInput.value = '';
    });
    roomInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('btn-send-room-broadcast').click();
        }
    });

    // 隐藏输入区和判定区
    chatInputArea.style.display = 'none';
    judgementZone.style.display = 'none';

    // 更新对手信息为对局详情
    const infoDiv = document.querySelector('.opponent-info > div:nth-of-type(2)');
    if (infoDiv) {
        infoDiv.innerHTML = `
            <div style="font-size:12px;color:#888;">旁观对局</div>
            <strong style="font-size:15px;">${escapeHtml(player1.nickname)} vs ${escapeHtml(player2.nickname)}</strong>
        `;
    }

    // 更新 Logo
    logoText.innerHTML = `
        <svg class="icon" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
        旁观模式
    `;

    // 更新管理面板
    spectateInfo.style.display = 'block';
    spectateDetail.innerHTML = `
        <b>${escapeHtml(player1.nickname)}</b>: ${player1.truth}<br>
        <b>${escapeHtml(player2.nickname)}</b>: ${player2.truth}
    `;

    // 停止计时器
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    timerDisplay.textContent = '旁观';
}

function exitSpectatorView() {
    spectateSessionId = null;
    window._spectatePlayers = null;

    // 移除旁观横幅
    const banner = document.getElementById('spectate-banner');
    if (banner) banner.remove();

    // 恢复 UI
    chatInputArea.style.display = '';
    judgementZone.style.display = '';
    chatBody.innerHTML = '';
    chatBody.innerHTML = `
        <div class="sys-msg">
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            </svg>
            连线成功，对方身份未知，计时开始
        </div>
    `;

    chatPage.style.display = 'none';
    landingPage.style.display = 'flex';
    btnBack.style.display = 'none';
    logoText.innerHTML = origLogoHTML;

    spectateInfo.style.display = 'none';
    spectateDetail.innerHTML = '';
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

// --- 渲染对局列表 ---
function renderSessionsList(sessions) {
    if (!sessions) return; // 不传 sessions 表示仅用缓存重新过滤

    // 缓存原始列表
    _cachedSessions = sessions;

    _doRenderSessionsList();
}

function _doRenderSessionsList() {
    const keyword = _sessionSearchKeyword.trim().toLowerCase();
    const sessions = keyword
        ? _cachedSessions.filter(s =>
            (s.player1 && s.player1.toLowerCase().includes(keyword)) ||
            (s.player2 && s.player2.toLowerCase().includes(keyword))
        )
        : _cachedSessions;

    if (sessions.length === 0) {
        if (_sessionSearchKeyword.trim()) {
            sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">没有匹配「' + escapeHtml(_sessionSearchKeyword) + '」的对局</div>';
        } else if (_cachedSessions.length === 0) {
            sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">当前无活跃对局</div>';
        } else {
            sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">当前无活跃对局</div>';
        }
        return;
    }

    const stateBadges = {
        'chatting': '<span class="session-badge badge-chatting">聊天中</span>',
        'judging': '<span class="session-badge badge-judging">判定中</span>',
        'finished': '<span class="session-badge badge-finished">已结束</span>',
    };

    let html = '';
    sessions.forEach(s => {
        const badge = stateBadges[s.state] || s.state;
        const shortId = s.id.substring(0, 12);
        html += `
            <div class="session-row">
                <div class="session-info">
                    <span style="font-weight:bold;">${escapeHtml(s.player1)} vs ${escapeHtml(s.player2)}</span>
                    <span style="font-size:11px;color:#888;">${shortId}... ${badge}</span>
                </div>
                <button class="doodle-btn spectate-btn" data-sid="${escapeHtml(s.id)}" style="font-size:12px;padding:4px 10px;">
                    旁观
                </button>
            </div>
        `;
    });
    sessionsList.innerHTML = html;

    // 绑定旁观按钮
    sessionsList.querySelectorAll('.spectate-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            adminSend('admin_spectate', { session_id: btn.dataset.sid });
        });
    });
}

// 管理员模式：访问特定路径时显示密码弹窗
if (window.__ADMIN_MODE__) {
    const adminOverlay = document.getElementById('admin-overlay');
    const adminPasswordInput = document.getElementById('admin-password-input');
    const btnAdminLogin = document.getElementById('btn-admin-login');
    const btnAdminCancel = document.getElementById('btn-admin-cancel');
    const adminError = document.getElementById('admin-error');

    adminOverlay.style.display = 'block';
    adminPasswordInput.focus();

    async function doAdminLogin() {
        const password = adminPasswordInput.value.trim();
        if (!password) {
            adminError.style.display = 'block';
            adminError.textContent = '请输入密码';
            return;
        }

        try {
            const resp = await fetch('/api/admin/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password }),
            });
            const result = await resp.json();

            if (result.ok) {
                adminToken = result.token;
                sessionStorage.setItem('turing_admin_token', adminToken);
                adminOverlay.style.display = 'none';
                btnAdminPanel.style.display = 'inline-flex';
                window.history.replaceState({}, '', '/');
            } else {
                adminError.style.display = 'block';
                adminError.textContent = result.error || '登录失败';
            }
        } catch (e) {
            adminError.style.display = 'block';
            adminError.textContent = '网络错误，请重试';
        }
    }

    btnAdminLogin.addEventListener('click', doAdminLogin);
    adminPasswordInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') doAdminLogin();
    });
    btnAdminCancel.addEventListener('click', () => {
        adminOverlay.style.display = 'none';
        window.history.replaceState({}, '', '/');
    });
}

// 封禁按钮（管理员专用）
function addBanButton() {
    const existing = document.getElementById('btn-admin-ban');
    if (existing) return;

    const opponentInfo = document.querySelector('.opponent-info');
    if (!opponentInfo) return;

    const banBtn = document.createElement('button');
    banBtn.id = 'btn-admin-ban';
    banBtn.className = 'doodle-btn';
    banBtn.style.cssText = 'font-size:13px;padding:4px 10px;color:#f44336;border-color:#f44336;margin-left:8px;';
    banBtn.innerHTML = `
        <svg class="icon" viewBox="0 0 24 24" style="width:14px;height:14px;">
            <circle cx="12" cy="12" r="10" />
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
        </svg>
        封禁
    `;
    banBtn.addEventListener('click', () => {
        if (!confirm('确定要封禁对方吗？\n将永久禁止该 IP 和浏览器指纹访问。')) return;
        transport._ws.send(JSON.stringify({
            type: 'admin_ban',
            token: adminToken,
        }));
    });

    opponentInfo.appendChild(banBtn);
}

// ================================================================
// WebSocket Transport 原型增强（注入 token + fingerprint + 新消息处理）
// ================================================================
const origConnect = WebSocketTransport.prototype.connect;
WebSocketTransport.prototype.connect = function (nickname, duration) {
    const wsUrl = this._url;

    this._ws = new WebSocket(wsUrl);

    DebugLogger.log('ws', 'WebSocket连接创建', {
        hasNickname: !!nickname,
        bufferedAmount: 0,
        readyState_after_new: this._ws.readyState
    });

    this._ws.onopen = () => {
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
            }
        }, 25000);

        DebugLogger.log('ws', 'WebSocket onopen', { nickname: nickname || '(preconnect)' });

        // 仅当有 nickname 时发送 join（preconnect 不发送）
        if (nickname) {
            DebugLogger.log('match', '发送join请求', { nickname: nickname, duration: duration || 600 });
            this._ws.send(JSON.stringify({
                type: 'join',
                nickname: nickname,
                duration: duration || 600,
                token: adminToken,
                fingerprint: browserFingerprint,
            }));
        }
    };

    this._ws.onmessage = (event) => {
        let data;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            DebugLogger.log('error', 'WebSocket JSON解析失败', { raw_len: event.data ? event.data.length : 0, error: e.message });
            console.warn('[WS] JSON parse error, raw data:', event.data);
            return;
        }
        switch (data.type) {
            case 'matched':
                DebugLogger.log('match', '收到matched事件', { opponent: data.opponent_name, session_id: data.session_id, duration: data.duration, elapsed_ms: window._matchStartTs ? Date.now() - window._matchStartTs : -1 });
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
                this._emit('opponent_judged', {
                    truth: data.truth,
                    opponent_guess: data.opponent_guess,
                    opponent_tag: data.opponent_tag || '',
                    session_id: data.session_id,
                });
                break;
            case 'judge_notify':
                DebugLogger.log('game', '判定通知', { message: data.message });
                this._emit('judge_notify', { message: data.message });
                break;
            case 'timeout':
                DebugLogger.log('game', '收到timeout事件', { reason: data.reason, session_id: data.session_id });
                this._emit('opponent_timeout', {
                    reason: data.reason,
                    session_id: data.session_id,
                    opponent_truth: data.opponent_truth,
                });
                break;
            case 'error':
                DebugLogger.log('error', '服务端错误', { message: data.message });
                if (data.message && data.message.includes('封禁')) {
                    this._emit('banned', { message: data.message });
                } else {
                    this._emit('system', { text: data.message });
                }
                break;
            case 'room_announce':
                DebugLogger.log('game', '广播通知', { text: data.text });
                showDanmaku(data.text, '管理警告');
                break;
            // ===== 管理员消息 =====
            case 'sessions_list':
                renderSessionsList(data.sessions);
                break;
            case 'session_detail':
                enterSpectatorView(data.session_id, data.player1, data.player2, data.history || []);
                break;
            case 'spectate_message':
                appendMessage(data.text, data.side || 'left', data.sender);
                break;
            case 'spectate_system':
                this._emit('system', { text: data.text });
                break;
            case 'spectate_ended':
                if (spectateSessionId === data.session_id) {
                    exitSpectatorView();
                    if (data.result) {
                        alert('对局结束！\n玩家1: ' + data.result.player1_truth + '\n玩家2: ' + data.result.player2_truth);
                    }
                }
                break;
            case 'admin_unspectated':
                exitSpectatorView();
                break;
            case 'report_result':
                if (data.success) {
                    alert(data.message || '举报已提交');
                } else {
                    alert(data.message || '举报失败');
                }
                break;
            case 'admin_connected':
            default:
                if (this._adminHandler) this._adminHandler(data);
                break;
        }
    };

    this._ws.onerror = () => {
        DebugLogger.log('error', 'WebSocket onerror触发');
        this._emit('error', { text: 'WebSocket 连接失败' });
    };

    this._ws.onclose = () => {
        DebugLogger.log('ws', 'WebSocket onclose触发');
        this._emit('disconnected', {});
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
        }));
        return;
    }
    DebugLogger.log('ws', 'WS已断开，发起重连-PoW', { readyState: this._ws ? this._ws.readyState : 'null' });
    // WS 已断（超时/网络中断），重新计算 PoW 挑战获取新的 d 参数
    var _reconnStart = Date.now();
    initPoW().then((powQuery) => {
        DebugLogger.log('ws', '重连PoW完成', { elapsed_ms: Date.now() - _reconnStart });
        if (powQuery) {
            const wsProtocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
            this._url = wsProtocol + window.location.host + '/ws' + powQuery;
        }
        this.connect(nickname, duration);
    }).catch(() => {
        DebugLogger.log('error', '重连PoW计算失败，直接connect');
        this.connect(nickname, duration);
    });
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
        var powStart = Date.now();
        const powQuery = await initPoW();
        DebugLogger.log('lifecycle', 'PoW计算完成', { elapsed_ms: Date.now() - powStart, hasPow: !!powQuery });
        const wsProtocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        transport = new WebSocketTransport(wsProtocol + window.location.host + '/ws' + powQuery);
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

    // 页面加载时初始化战绩 + 已有码则拉取最新战绩
    updateLbUI();
    const savedCode = getLbCode();
    if (savedCode) {
        fetch('/api/player-stats?code=' + encodeURIComponent(savedCode))
            .then(r => r.json())
            .then(data => {
                if (data.stats) {
                    updateLbMyStats(data.stats);
                }
            })
            .catch(() => {});
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
const LB_CODE_KEY = 'turing_player_code';

function getLbCode() {
    return localStorage.getItem(LB_CODE_KEY) || '';
}

function saveLbCode(code) {
    localStorage.setItem(LB_CODE_KEY, code);
}

function updateLbUI() {
    const code = getLbCode();
    const cb = document.getElementById('cb-auto-record');

    if (code) {
        cb.checked = true;
    } else {
        cb.checked = false;
    }

    // 设置面板中的战绩展示
    const recoverArea = document.getElementById('lb-recover-area');
    const myStatsEl = document.getElementById('lb-my-stats');
    const statusText = document.getElementById('lb-status-text');

    if (code) {
        recoverArea.style.display = 'none';
        myStatsEl.style.display = '';
        statusText.textContent = '你的战绩：';
        document.getElementById('lb-my-code').textContent = code;
    } else {
        recoverArea.style.display = '';
        myStatsEl.style.display = 'none';
        statusText.textContent = '开启后战绩自动记录，换设备可用恢复码找回';
    }
}

function updateLbMyStats(stats) {
    if (!stats) return;
    document.getElementById('lb-my-code').textContent = getLbCode();
    document.getElementById('lb-my-wins').textContent = stats.wins || 0;
    document.getElementById('lb-my-losses').textContent = stats.losses || 0;
    document.getElementById('lb-my-games').textContent = stats.total_games || 0;
    document.getElementById('lb-my-rate').textContent = (stats.win_rate !== undefined ? stats.win_rate + '%' : '-');
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

document.getElementById('btn-export-stats').addEventListener('click', exportStatsImage);

// 调试日志导出按钮
document.getElementById('btn-export-debug').addEventListener('click', async function () {
    const btn = this;
    const origText = btn.textContent;
    btn.textContent = '导出中...';
    btn.disabled = true;
    try {
        const result = await DebugLogger.download();
        btn.textContent = '已导出 (' + result.count + ' 条)';
        setTimeout(function () { btn.textContent = origText; btn.disabled = false; }, 2000);
    } catch (e) {
        btn.textContent = '导出失败';
        btn.disabled = false;
        alert('导出失败: ' + e.message);
        setTimeout(function () { btn.textContent = origText; }, 2000);
    }
});

// ---- 复选框事件 ----

document.getElementById('cb-auto-record').addEventListener('change', function () {
    if (!this.checked) return; // 仅处理勾选

    let nickname = document.getElementById('nickname-input').value.trim();
    if (!nickname) {
        nickname = prompt('请输入昵称：');
        if (!nickname || !nickname.trim()) {
            this.checked = false;
            return;
        }
        nickname = nickname.trim();
    }
    if (nickname.length > 16) { alert('昵称不能超过16个字符'); this.checked = false; return; }

    fetch('/api/leaderboard-join', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nickname, fp: browserFingerprint }),
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); this.checked = false; return; }
            saveLbCode(data.code);
            updateLbUI();
            updateLbMyStats(data.stats);
        })
        .catch(() => { alert('网络错误，请稍后重试'); this.checked = false; });
});

document.getElementById('btn-recover-lb').addEventListener('click', () => {
    const code = document.getElementById('lb-recover-input').value.trim();
    if (!code) {
        alert('请输入恢复码');
        return;
    }
    fetch('/api/player-stats?code=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }
            saveLbCode(data.code);
            updateLbUI();
            updateLbMyStats(data.stats);
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

let _chatHistoryPage = 1;

/** 加载聊天记录列表 */
function loadChatHistoryList(page) {
    const code = getLbCode();
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
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:var(--ink-blue);color:#fff;border-radius:4px;margin:0 2px;">' + i + '</span>';
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
    const code = getLbCode();
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

// 更新 updateLbUI 以显示/隐藏聊天记录回顾区域
const _origUpdateLbUI = updateLbUI;
updateLbUI = function () {
    _origUpdateLbUI();
    const code = getLbCode();
    const section = document.getElementById('chat-history-section');
    if (code) {
        section.style.display = '';
        loadChatHistoryList(1);
    } else {
        section.style.display = 'none';
    }
};

// ================================================================