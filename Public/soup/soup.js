'use strict';

/* ==================== 海龟汤 前端逻辑 ====================
 * WS: /ws/soup（soup_ 前缀），身份字段随 soup_lobby / soup_create / soup_join_room 携带
 * HTTP: /api/soup/my-puzzles（Authorization: Bearer player_token）
 * ======================================================== */

// ==================== DOM 引用 ====================

const pages = {
    lobby: document.getElementById('page-lobby'),
    wait: document.getElementById('page-wait'),
    game: document.getElementById('page-game'),
    puzzles: document.getElementById('page-puzzles'),
};

const $ = (id) => document.getElementById(id);

// ==================== 状态 ====================

let currentPage = 'lobby';

let ws = null;
let wsAuthed = false;
let wsActionQueue = [];
let heartbeatTimer = null;
let pongTimer = null;
let reconnectTimer = null;
let reconnecting = false;
let intentionalClose = false;
let rejoining = false;

let roomState = null;   // soup_room 快照（唯一渲染数据源）
let roomId = '';        // 当前所在房间
let lastRoomId = '';    // 重连恢复用
let roundInfo = null;   // soup_round 载荷（对局页汤面卡）
let revealWinner = '';  // soup_won 带来的命中者昵称
let sysLog = [];        // 对局内系统消息（重渲染时保留）

let pickTab = 'random';
let pickCache = {};     // source -> puzzles[]
let pickedId = 0;       // 当前已选题 id（高亮）

let myPuzzles = [];
let editingId = 0;
let filterScope = 'all';

let lastAskTs = 0;
let lastLeftTs = 0;     // soup_left 防抖（解散/离开路径可能重复下发）
const ASK_COOLDOWN = 3000;

const VERDICT_LABEL = {
    yes: '是',
    no: '不是',
    unrelated: '无关',
    close: '接近',
    correct: '命中',
};

// ==================== 小工具 ====================

function el(tag, attrs = {}, text = '') {
    const node = document.createElement(tag);
    Object.assign(node, attrs);
    if (text) node.textContent = text;
    return node;
}

// ==================== 内联 SVG 图标（对齐整站 .icon stroke 风格） ====================

const SOUP_ICONS = {
    pot: '<path d="M3 12h18a9 9 0 0 1-9 9 9 9 0 0 1-9-9z"/><path d="M8 8c0-1.5 1-2 1-3.5"/><path d="M12 8c0-1.5 1-2 1-3.5"/><path d="M16 8c0-1.5 1-2 1-3.5"/>',
    trophy: '<path d="M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M7 6H4a3 3 0 0 0 3 5"/><path d="M17 6h3a3 3 0 0 1-3 5"/><path d="M12 14v7"/><path d="M8 21h8"/>',
    shuffle: '<path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/>',
};

function iconEl(name, size = 14) {
    const span = document.createElement('span');
    span.className = 'soup-ic';
    span.innerHTML = '<svg class="icon" viewBox="0 0 24 24" style="width:' + size +
        'px;height:' + size + 'px;">' + (SOUP_ICONS[name] || '') + '</svg>';
    return span;
}

function stars(n) {
    n = Math.max(0, Math.min(3, n | 0));
    return '★'.repeat(n) + '☆'.repeat(3 - n);
}

function fmtDuration(sec) {
    sec = Math.max(0, sec | 0);
    const m = Math.floor(sec / 60), s = sec % 60;
    return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
}

function showLoading(text = '处理中…') {
    $('soup-loading-text').textContent = text;
    $('soup-loading').style.display = 'flex';
}

function hideLoading() {
    $('soup-loading').style.display = 'none';
}

function logLine(text) {
    sysLog.push(text);
    if (sysLog.length > 80) sysLog.shift();
    if (currentPage === 'wait') {
        const box = $('wait-log');
        box.appendChild(el('div', {}, '· ' + text));
        while (box.childNodes.length > 60) box.removeChild(box.firstChild);
        box.scrollTop = box.scrollHeight;
    } else if (currentPage === 'game') {
        const list = $('qa-list');
        const line = el('div', { className: 'qa-sys' }, '· ' + text);
        list.appendChild(line);
        list.scrollTop = list.scrollHeight;
    }
}

function resetSysLog() {
    sysLog = [];
    const box = $('wait-log');
    if (box) box.textContent = '';
}

function showPage(name) {
    currentPage = name;
    Object.entries(pages).forEach(([key, sec]) => {
        sec.style.display = (key === name) ? 'flex' : 'none';
    });
}

// ==================== 身份 ====================

function hasIdentity() {
    return !!(getUserToken() || getUserNickname());
}

function identityPayload() {
    return {
        fp: getFingerprint(),
        player_token: getUserToken() || '',
        password: '',
        nickname: getUserNickname() || ''
    };
}

function showIdentityCard() {
    $('identity-card').style.display = 'flex';
}

function hideIdentityCard() {
    $('identity-card').style.display = 'none';
}

// ==================== WebSocket 层 ====================

function sendWs(obj) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(obj));
        return true;
    }
    return false;
}

function sendSoupLobby() {
    sendWs(Object.assign({ type: 'soup_lobby' }, identityPayload()));
}

function sendCreateRoom() {
    sendWs(Object.assign({ type: 'soup_create', room_name: '' }, identityPayload()));
}

function sendJoinRoom(roomId) {
    sendWs(Object.assign({ type: 'soup_join_room', room_id: roomId }, identityPayload()));
}

function sendLeave() {
    sendWs({ type: 'soup_leave' });
}

function sendPick(source, puzzleId) {
    if (!sendWs({ type: 'soup_pick', source, puzzle_id: puzzleId })) return;
    showLoading('选择汤面…');
}

function connectWs(afterOpen) {
    if (typeof afterOpen === 'function') wsActionQueue.push(afterOpen);

    if (ws && ws.readyState === WebSocket.OPEN) {
        if (wsAuthed) flushActionQueue();
        else sendSoupLobby();
        return;
    }
    if (ws && ws.readyState === WebSocket.CONNECTING) return;

    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = proto + '//' + window.location.host + '/ws/soup';
    ws = new WebSocket(url);
    wsAuthed = false;

    ws.onopen = () => {
        console.log('[Soup] WS connected');
        reconnecting = false;
        sendSoupLobby();
        startHeartbeat();
    };
    ws.onerror = () => showTopToast('无法连接到服务器', true);
    ws.onclose = () => {
        console.log('[Soup] WS closed');
        stopHeartbeat();
        wsAuthed = false;
        if (!intentionalClose) scheduleReconnect();
        intentionalClose = false;
    };
    ws.onmessage = (e) => {
        let msg;
        try { msg = JSON.parse(e.data); } catch (_) { return; }
        handleWsMsg(msg);
    };
}

function flushActionQueue() {
    const cbs = wsActionQueue.slice();
    wsActionQueue = [];
    cbs.forEach(cb => { try { cb(); } catch (err) { console.error(err); } });
}

function startHeartbeat() {
    stopHeartbeat();
    heartbeatTimer = setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'ping' }));
        }
        if (pongTimer) clearTimeout(pongTimer);
        pongTimer = setTimeout(() => {
            console.log('[Soup] Pong timeout');
            if (ws) ws.close();
        }, 10000);
    }, 20000);
}

function stopHeartbeat() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
}

const RECONNECT_DELAY = 2000;
function scheduleReconnect() {
    if (reconnecting) return;
    reconnecting = true;
    showTopToast('连接已断开，正在重连...', true);
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        connectWs(() => {
            // 重连后若之前在等待房中，尝试重新加入
            if (lastRoomId) {
                rejoining = true;
                sendJoinRoom(lastRoomId);
            }
        });
    }, RECONNECT_DELAY);
}

function resetToLobby(toast) {
    roomState = null;
    roundInfo = null;
    revealWinner = '';
    roomId = '';
    resetSysLog();
    hideLoading();
    $('reveal-overlay').style.display = 'none';
    showPage('lobby');
    if (toast) showTopToast(toast, false);
    sendSoupLobby();
}

// ==================== WS 消息分发 ====================

function handleWsMsg(msg) {
    const type = msg.type;
    const data = msg.data;

    switch (type) {
        case 'soup_connected':
            break;

        case 'soup_joined':
            wsAuthed = true;
            if (data && data.token && !getUserToken()) setUserToken(data.token);
            flushActionQueue();
            break;

        case 'soup_room_list':
            wsAuthed = true;
            flushActionQueue();
            renderRoomList(msg.rooms || []);
            break;

        case 'soup_room_created':
            lastRoomId = msg.room_id;
            roomId = msg.room_id;
            hideLoading();
            showPage('wait');
            break;

        case 'soup_room':
            applyRoomState(data);
            break;

        case 'soup_round':
            roundInfo = msg;
            if (currentPage === 'game') renderGame();
            break;

        case 'soup_question':
            upsertQuestion({
                id: msg.question_id,
                text: msg.text,
                nickname: msg.nickname,
                kind: msg.kind,
                verdict: null,
                reason: '',
                ai: null,
            });
            if (currentPage === 'game') renderQA();
            break;

        case 'soup_verdict':
            updateQuestion(msg.question_id, (q) => {
                q.verdict = msg.verdict;
                q.reason = msg.reason || '';
            });
            if (currentPage === 'game') renderQA();
            break;

        case 'soup_ai_suggested':
            updateQuestion(msg.question_id, (q) => {
                q.ai = { verdict: msg.verdict, reason: msg.reason || '' };
            });
            showTopToast('AI 建议：' + (VERDICT_LABEL[msg.verdict] || msg.verdict) +
                (msg.reason ? '（' + msg.reason + '）' : ''), false);
            if (currentPage === 'game') renderQA();
            break;

        case 'soup_guess_result':
            logLine(msg.nickname + ' 的猜底' + (msg.correct ? '命中了汤底！' : '未命中'));
            break;

        case 'soup_hint':
            logLine('提示 ' + msg.index + '/' + msg.total + '：' + msg.text);
            if (roomState) roomState.hints_revealed = msg.index;
            if (currentPage === 'game') renderGame();
            break;

        case 'soup_won':
            revealWinner = msg.nickname || '';
            logLine((msg.nickname || '有人') + ' 猜中了汤底！');
            break;

        case 'soup_over':
            showReveal(msg);
            break;

        case 'soup_system':
            if (msg.text) logLine(msg.text);
            break;

        case 'soup_left':
            // 防抖：房主解散 / 主动离开 / 管理端解散可能触发多条 soup_left，1.5s 内只处理一次
            if (Date.now() - lastLeftTs < 1500) break;
            lastLeftTs = Date.now();
            resetToLobby('已回到大厅');
            break;

        case 'soup_error':
            hideLoading();
            showTopToast(msg.message || '操作失败', true);
            if (rejoining) {
                // 重连恢复失败：房间已解散或已开局，回大厅
                rejoining = false;
                lastRoomId = '';
                resetToLobby();
            }
            break;

        case 'error':
            showTopToast(msg.message || '连接失败，请刷新重试', true);
            reconnecting = false;
            if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
            intentionalClose = true;
            stopHeartbeat();
            wsActionQueue = [];
            wsAuthed = false;
            if (ws) { try { ws.close(); } catch (err) { } ws = null; }
            break;

        case 'pong':
            if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
            break;

        default:
            break;
    }
}

// ==================== 房间状态路由 ====================

function applyRoomState(data) {
    if (!data || !data.id) return;
    roomState = data;
    roomId = data.id;
    lastRoomId = data.id;
    pickedId = data.puzzle ? (data.puzzle.id | 0) : 0;

    // 任何 soup_room 快照都代表本次操作已完成（建房/加入/选题/开汤），先收掉 loading
    hideLoading();

    if (data.state === 'playing' || data.state === 'revealed') {
        if (currentPage !== 'game') showPage('game');
        renderGame();
    } else {
        // lobby / picking：进入新一轮选题，清掉上一锅的残留
        if (data.state === 'picking' || data.state === 'lobby') {
            roundInfo = null;
            revealWinner = '';
            resetSysLog();
        }
        $('reveal-overlay').style.display = 'none';
        if (currentPage !== 'wait') showPage('wait');
        renderWait();
    }
}

// ==================== 大厅渲染 ====================

function renderRoomList(rooms) {
    const box = $('room-list');
    box.textContent = '';

    if (!rooms.length) {
        box.appendChild(el('div', { className: 'soup-room-empty' },
            '暂无可加入的汤房——点上方「创建房间」，当第一位出题人'));
        return;
    }

    rooms.forEach((r) => {
        const item = el('div', { className: 'soup-room-item' });

        const main = el('div');
        main.appendChild(el('div', { className: 'room-name' }, r.name || '未命名汤房'));
        main.appendChild(el('div', { className: 'room-host' }, '出题人：' + (r.host_nickname || '???') +
            (r.has_puzzle ? '' : ' · 选题中')));
        item.appendChild(main);

        const meta = el('div', { className: 'room-meta' });
        if (r.difficulty > 0) meta.appendChild(el('span', { className: 'diff-star' }, stars(r.difficulty)));
        meta.appendChild(el('span', {}, r.count + '/' + r.max + ' 人'));
        const joinBtn = el('button', { className: 'doodle-btn' }, '加入');
        joinBtn.style.cssText = 'padding:4px 14px;font-size:13px;';
        joinBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            tryJoin(r.id);
        });
        meta.appendChild(joinBtn);
        item.appendChild(meta);

        item.addEventListener('click', () => tryJoin(r.id));
        box.appendChild(item);
    });
}

function tryJoin(id) {
    if (!hasIdentity()) {
        showIdentityCard();
        showTopToast('请先创建身份', true);
        return;
    }
    showLoading('加入汤房…');
    sendJoinRoom(id);
}

// ==================== 等待页渲染 ====================

function renderWait() {
    const r = roomState;
    if (!r) return;

    $('wait-code').textContent = r.id || '------';
    $('wait-title').textContent = r.name || '汤房就绪';

    // 已选汤面展示
    const wp = $('wait-puzzle');
    wp.textContent = '';
    if (r.puzzle) {
        wp.classList.add('on');
        wp.appendChild(el('div', { className: 'wp-title' },
            '《' + (r.puzzle.title || '未命名') + '》 ' + stars(r.puzzle.difficulty) +
            (r.puzzle.tags ? ' · ' + r.puzzle.tags : '')));
        wp.appendChild(el('div', { className: 'wp-surface' }, r.puzzle.surface || ''));
        if (r.is_host && r.puzzle.truth) {
            wp.appendChild(el('div', { className: 'wp-truth' }, '【汤底】' + r.puzzle.truth));
        }
    } else {
        wp.classList.remove('on');
        wp.appendChild(el('div', {}, r.is_host ? '尚未选择汤面' : '出题人正在选题…'));
    }

    // 选题面板（房主专用）
    $('pick-panel').style.display = (r.is_host && r.state !== 'playing') ? 'block' : 'none';

    // 成员
    const members = $('wait-members');
    members.textContent = '';
    (r.members || []).forEach((m) => {
        const chip = el('span', { className: 'member-chip' + (m.is_host ? ' host' : '') });
        if (m.is_host) {
            chip.appendChild(iconEl('pot', 12));
            chip.appendChild(document.createTextNode(' ' + (m.nickname || '玩家') + '（出题人）'));
        } else {
            chip.textContent = m.nickname || '玩家';
        }
        members.appendChild(chip);
    });

    // 开汤按钮
    const readyBtn = $('btn-ready');
    if (r.is_host) {
        readyBtn.style.display = '';
        const canStart = !!r.puzzle && (r.members || []).length >= 2;
        readyBtn.disabled = !canStart;
        readyBtn.title = canStart ? '' : (r.puzzle ? '至少需要 2 人' : '请先选择汤面');
    } else {
        readyBtn.style.display = 'none';
    }
}

// ==================== 选题面板 ====================

function switchPickTab(source) {
    pickTab = source;
    document.querySelectorAll('.pick-tab').forEach((t) => {
        t.classList.toggle('active', t.dataset.source === source);
    });
    renderPickList();
}

async function renderPickList() {
    const box = $('pick-list');
    box.textContent = '';

    if (pickTab === 'random') {
        const item = el('div', { className: 'pick-item' + (pickedId ? '' : ' picked') });
        const title = el('div', { className: 'pi-title' });
        const label = el('span', { style: 'display:inline-flex;align-items:center;gap:6px;' });
        label.appendChild(iconEl('shuffle', 14));
        label.appendChild(document.createTextNode('随机来一锅'));
        title.appendChild(label);
        item.appendChild(title);
        item.appendChild(el('div', { className: 'pi-meta' }, '从公开汤池随机抽取'));
        item.addEventListener('click', () => sendPick('random', 0));
        box.appendChild(item);
        return;
    }

    try {
        let puzzles;
        if (pickTab === 'mine') {
            puzzles = await fetchMyPuzzles();
        } else {
            puzzles = await fetchPublicPuzzles();
            puzzles = puzzles.filter((p) => pickTab === 'official'
                ? p.source === 'official'
                : p.source === 'member');
        }

        if (!puzzles.length) {
            box.appendChild(el('div', { className: 'pick-empty' },
                pickTab === 'mine' ? '你还没有上传汤面，去「我的汤面」写一锅吧'
                    : '该来源暂无可用题目'));
            return;
        }

        puzzles.forEach((p) => {
            const item = el('div', {
                className: 'pick-item' + (p.id === pickedId ? ' picked' : ''),
            });
            const head = el('div', { className: 'pi-title' });
            head.appendChild(el('span', {}, p.title || '未命名'));
            head.appendChild(el('span', {}, stars(p.difficulty)));
            item.appendChild(head);
            item.appendChild(el('div', { className: 'pi-meta' },
                (p.tags || '无标签') + ' · 被选 ' + (p.used_count || 0) + ' 次'));
            item.addEventListener('click', () => sendPick(pickTab, p.id));
            box.appendChild(item);
        });
    } catch (err) {
        box.textContent = '';
        box.appendChild(el('div', { className: 'pick-empty' }, '加载失败：' + err.message));
    }
}

// ==================== 对局页渲染 ====================

function renderGame() {
    const r = roomState;
    if (!r) return;

    const p = roundInfo || r.puzzle;
    const isHost = !!r.is_host;
    const playing = r.state === 'playing';

    // 信息条
    $('game-title').textContent = p && p.title ? '《' + p.title + '》' : '未命名汤面';
    const badge = $('game-state');
    badge.textContent = playing ? '推理中' : '已揭示';
    badge.className = 'soup-state-badge ' + (playing ? 'playing' : 'revealed');
    const qCount = (r.questions || []).length;
    $('game-qcount').textContent = qCount + '/' + (r.max_questions || 30) + ' 问';
    $('game-hcount').textContent = '提示 ' + (r.hints_revealed || 0) + '/' + (r.max_hints || 3);

    // 成员
    const members = $('game-members');
    members.textContent = '';
    (r.members || []).forEach((m) => {
        const chip = el('span', { className: 'member-chip' + (m.is_host ? ' host' : '') });
        if (m.is_host) {
            chip.appendChild(iconEl('pot', 12));
            chip.appendChild(document.createTextNode(' ' + (m.nickname || '玩家') + '（出题人）'));
        } else {
            chip.textContent = m.nickname || '玩家';
        }
        members.appendChild(chip);
    });

    // 汤面卡
    $('surface-text').textContent = (p && p.surface) || '（汤面加载中…）';

    const truthBlock = $('host-truth');
    if (isHost && p && p.truth) {
        truthBlock.style.display = 'block';
        $('truth-text').textContent = p.truth;
        const kpBox = $('key-points');
        kpBox.textContent = '';
        (p.key_points || []).forEach((kp) => kpBox.appendChild(el('span', {}, kp)));
        if (!(p.key_points || []).length) kpBox.appendChild(el('span', {}, '（无）'));
        $('btn-truth-toggle').textContent =
            $('host-truth-body').style.display === 'none' ? '查看汤底（仅出题人可见）' : '收起汤底';
    } else {
        truthBlock.style.display = 'none';
        $('host-truth-body').style.display = 'none';
    }

    renderQA();

    // 输入区（仅猜题人 + 推理中）
    const inputArea = $('input-area');
    inputArea.textContent = '';
    if (!isHost && playing) {
        inputArea.appendChild(buildGuesserInput());
    } else if (!isHost && !playing) {
        inputArea.appendChild(el('div', { className: 'pick-empty' },
            '本锅汤已揭示，等待出题人开下一题…'));
    }

    // 操作区
    const actions = $('game-actions');
    actions.textContent = '';
    if (isHost) {
        if (playing) {
            const hintBtn = el('button', { className: 'doodle-btn' }, '给提示');
            const hintsAvail = r.puzzle && (r.puzzle.hints || []).length > (r.hints_revealed || 0)
                && (r.hints_revealed || 0) < (r.max_hints || 0);
            if (!hintsAvail) hintBtn.disabled = true;
            hintBtn.addEventListener('click', () => sendWs({ type: 'soup_hint' }));
            actions.appendChild(hintBtn);

            const giveupBtn = el('button', { className: 'doodle-btn' }, '揭示汤底');
            giveupBtn.style.cssText = 'color:var(--danger);border-color:var(--danger);';
            giveupBtn.addEventListener('click', () => {
                if (confirm('确定揭示汤底并结束本局？')) sendWs({ type: 'soup_giveup' });
            });
            actions.appendChild(giveupBtn);
        } else {
            const nextBtn = el('button', { className: 'doodle-btn' }, '下一题（重新选题）');
            nextBtn.style.cssText = 'color:var(--success);border-color:var(--success);';
            nextBtn.addEventListener('click', () => sendWs({ type: 'soup_next' }));
            actions.appendChild(nextBtn);
        }
    }
    const leaveBtn = el('button', { className: 'doodle-btn' }, isHost ? '解散并离开' : '离开房间');
    leaveBtn.style.cssText = 'color:var(--text-subtle);';
    leaveBtn.addEventListener('click', () => {
        const tip = isHost ? '离开将解散整个汤房，确定？' : '确定离开本汤房？';
        if (confirm(tip)) sendLeave();
    });
    actions.appendChild(leaveBtn);
}

function buildGuesserInput() {
    const wrap = el('div', { className: 'guess-input-row' });

    const tabs = el('div', { className: 'pick-tabs', style: 'margin:0;' });
    const askTab = el('button', { className: 'pick-tab active' }, '提问');
    const guessTab = el('button', { className: 'pick-tab' }, '猜汤底');
    let mode = 'ask';
    askTab.addEventListener('click', () => {
        mode = 'ask';
        askTab.classList.add('active');
        guessTab.classList.remove('active');
        input.placeholder = '只能用「是 / 不是 / 无关」回答的问题…';
    });
    guessTab.addEventListener('click', () => {
        mode = 'guess';
        guessTab.classList.add('active');
        askTab.classList.remove('active');
        input.placeholder = '用一段话描述你推断的完整真相…';
    });
    tabs.appendChild(askTab);
    tabs.appendChild(guessTab);
    wrap.appendChild(tabs);

    const input = el('input', { className: 'doodle-input', maxLength: 300 });
    input.placeholder = '只能用「是 / 不是 / 无关」回答的问题…';
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') sendBtn.click(); });

    const sendBtn = el('button', { className: 'doodle-btn' }, '发送');
    sendBtn.addEventListener('click', () => {
        const text = (input.value || '').trim();
        if (!text) return;
        const now = Date.now();
        if (mode === 'ask' && now - lastAskTs < ASK_COOLDOWN) {
            showTopToast('提问太快了，请稍候', true);
            return;
        }
        if (!sendWs({ type: mode === 'ask' ? 'soup_ask' : 'soup_guess', text })) {
            showTopToast('连接已断开', true);
            return;
        }
        if (mode === 'ask') lastAskTs = now;
        input.value = '';
    });

    const row = el('div');
    row.appendChild(input);
    row.appendChild(sendBtn);
    wrap.appendChild(row);
    return wrap;
}

// ==================== 问答流渲染 ====================

function qaItems() {
    return (roomState && roomState.questions) || [];
}

function upsertQuestion(q) {
    if (!roomState) return;
    if (!Array.isArray(roomState.questions)) roomState.questions = [];
    const idx = roomState.questions.findIndex((x) => x.id === q.id);
    if (idx === -1) roomState.questions.push(q);
}

function updateQuestion(id, fn) {
    if (!roomState || !Array.isArray(roomState.questions)) return;
    const q = roomState.questions.find((x) => x.id === id);
    if (q) fn(q);
}

function renderQA() {
    const list = $('qa-list');
    list.textContent = '';
    const items = qaItems();

    if (!items.length) {
        list.appendChild(el('div', { className: 'qa-empty' }, '还没有提问，大胆猜！'));
    }

    const r = roomState;
    const isHost = !!r.is_host;
    const playing = r.state === 'playing';

    items.forEach((q) => {
        const item = el('div', { className: 'qa-item ' + (q.kind === 'guess' ? 'guess' : 'ask') });

        const head = el('div', { className: 'qa-head' });
        head.appendChild(el('span', {}, q.nickname || '玩家'));
        head.appendChild(el('span', {}, q.kind === 'guess' ? '【猜底】' : '【提问】'));
        if (q.verdict) {
            head.appendChild(el('span', { className: 'verdict-chip ' + q.verdict },
                VERDICT_LABEL[q.verdict] || q.verdict));
        }
        if (isHost && q.ai) {
            head.appendChild(el('span', { className: 'ai-chip' },
                'AI:' + (VERDICT_LABEL[q.ai.verdict] || q.ai.verdict)));
        }
        item.appendChild(head);

        item.appendChild(el('div', { className: 'qa-text' }, q.text || ''));

        if (q.verdict && q.reason) {
            item.appendChild(el('div', { className: 'verdict-reason' }, q.reason));
        }

        // 房主判定按钮
        if (isHost && playing && !q.verdict) {
            const row = el('div', { className: 'verdict-btns' });
            const verdicts = q.kind === 'guess'
                ? ['correct', 'close', 'no']
                : ['yes', 'no', 'unrelated', 'close'];
            verdicts.forEach((v) => {
                const b = el('button', { className: 'doodle-btn vbtn ' + v }, VERDICT_LABEL[v]);
                b.addEventListener('click', () => {
                    sendWs({ type: 'soup_answer', question_id: q.id, verdict: v });
                });
                row.appendChild(b);
            });
            if (q.kind === 'ask' && r.ai_assist && !q.ai) {
                const aiBtn = el('button', { className: 'doodle-btn vbtn unrelated' }, 'AI 建议');
                aiBtn.style.cssText = 'color:var(--ink-blue);border-style:dashed;';
                aiBtn.addEventListener('click', () => {
                    sendWs({ type: 'soup_ai_suggest', question_id: q.id });
                });
                row.appendChild(aiBtn);
            }
            item.appendChild(row);
        }

        list.appendChild(item);
    });

    // 系统消息（重渲染后保留）
    sysLog.forEach((t) => list.appendChild(el('div', { className: 'qa-sys' }, '· ' + t)));

    list.scrollTop = list.scrollHeight;
}

// ==================== 揭示浮层 ====================

function showReveal(msg) {
    hideLoading();
    const winnerBox = $('reveal-winner');
    winnerBox.textContent = '';
    const winner = revealWinner || msg.winner || '';
    if (winner) {
        winnerBox.appendChild(iconEl('trophy', 16));
        winnerBox.appendChild(document.createTextNode(' ' + winner + ' 猜中了汤底！'));
    } else {
        winnerBox.appendChild(iconEl('pot', 16));
        winnerBox.appendChild(document.createTextNode(' 没有人猜中，出题人守住了真相'));
    }
    $('reveal-solution').textContent = msg.solution || '（无汤底）';

    const stats = $('reveal-stats');
    stats.textContent = '';
    stats.appendChild(el('span', {}, '用时 ' + fmtDuration(msg.duration || 0)));
    stats.appendChild(el('span', {}, '提问 ' + (msg.questions_used || 0) + ' 个'));
    stats.appendChild(el('span', {}, '提示 ' + (msg.hints_used || 0) + ' 条'));

    $('btn-reveal-next').style.display = (roomState && roomState.is_host) ? '' : 'none';
    $('btn-reveal-stay').style.display = (roomState && roomState.is_host) ? 'none' : '';
    $('reveal-overlay').style.display = 'flex';
}

// ==================== 我的汤面（HTTP） ====================

async function api(method, url, body) {
    const headers = { 'Content-Type': 'application/json' };
    const token = getUserToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;
    const res = await fetch(url, {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch (err) { /* ignore */ }
    if (!res.ok || (json && json.error)) {
        throw new Error((json && json.error) || ('HTTP ' + res.status));
    }
    return json;
}

async function fetchMyPuzzles() {
    if (pickCache.mine) return pickCache.mine;
    const data = await api('GET', '/api/soup/my-puzzles');
    pickCache.mine = (data.puzzles || []);
    return pickCache.mine;
}

async function fetchPublicPuzzles() {
    if (pickCache.public) return pickCache.public;
    const data = await api('GET', '/api/soup/public-puzzles');
    pickCache.public = (data.puzzles || []);
    return pickCache.public;
}

async function loadPuzzlesPage() {
    if (!getUserToken()) {
        showIdentityCard();
        showTopToast('登录后才能管理汤面', true);
        return;
    }
    showPage('puzzles');
    pickCache.mine = null;
    await renderMyPuzzles();
}

async function renderMyPuzzles() {
    const grid = $('my-puzzle-grid');
    grid.textContent = '';
    grid.appendChild(el('div', { className: 'puzzle-empty' }, '加载中…'));

    try {
        myPuzzles = await fetchMyPuzzles();
    } catch (err) {
        grid.textContent = '';
        grid.appendChild(el('div', { className: 'puzzle-empty' }, '加载失败：' + err.message));
        return;
    }

    grid.textContent = '';
    const list = myPuzzles.filter((p) => filterScope === 'all' || p.scope === filterScope);

    if (!list.length) {
        grid.appendChild(el('div', { className: 'puzzle-empty' },
            myPuzzles.length ? '该筛选下暂无汤面' : '还没有汤面，点右上角「＋ 新建汤面」写第一锅'));
        return;
    }

    list.forEach((p) => {
        const card = el('div', { className: 'puzzle-card' });

        const title = el('div', { className: 'pc-title' });
        title.appendChild(el('span', {}, p.title || '未命名'));
        title.appendChild(el('span', { className: 'scope-chip' + (p.scope === 'public' ? ' public' : '') },
            p.scope === 'public' ? '已共享' : '私有'));
        card.appendChild(title);

        card.appendChild(el('div', { className: 'pc-surface' }, p.surface || ''));

        const meta = el('div', { className: 'pc-meta' });
        meta.appendChild(el('span', {}, stars(p.difficulty)));
        if (p.tags) meta.appendChild(el('span', {}, p.tags));
        meta.appendChild(el('span', {}, '被选 ' + (p.used_count || 0) + ' 次'));
        card.appendChild(meta);

        const actions = el('div', { className: 'pc-actions' });

        const editBtn = el('button', { className: 'doodle-btn' }, '编辑');
        editBtn.addEventListener('click', () => openEditor(p));
        actions.appendChild(editBtn);

        const shareBtn = el('button', { className: 'doodle-btn' },
            p.scope === 'public' ? '取消共享' : '共享');
        shareBtn.addEventListener('click', async () => {
            showLoading('切换共享…');
            try {
                const data = await api('POST', '/api/soup/my-puzzles/' + p.id + '/share');
                p.scope = data.scope || (p.scope === 'public' ? 'private' : 'public');
                pickCache.mine = null;
                showTopToast(p.scope === 'public' ? '已共享到公开汤池' : '已转为私有', false);
                renderMyPuzzles();
            } catch (err) {
                showTopToast(err.message, true);
            }
            hideLoading();
        });
        actions.appendChild(shareBtn);

        const delBtn = el('button', { className: 'doodle-btn' }, '删除');
        delBtn.style.cssText = 'color:var(--danger);border-color:var(--danger);';
        delBtn.addEventListener('click', async () => {
            if (!confirm('删除汤面《' + (p.title || '未命名') + '》？不可恢复')) return;
            showLoading('删除中…');
            try {
                await api('DELETE', '/api/soup/my-puzzles/' + p.id);
                pickCache.mine = null;
                showTopToast('已删除', false);
                renderMyPuzzles();
            } catch (err) {
                showTopToast(err.message, true);
            }
            hideLoading();
        });
        actions.appendChild(delBtn);

        card.appendChild(actions);
        grid.appendChild(card);
    });
}

// ==================== 汤面编辑器 ====================

function openEditor(puzzle) {
    editingId = puzzle ? (puzzle.id | 0) : 0;
    $('editor-heading').textContent = editingId ? '编辑汤面' : '新建汤面';
    $('editor-title').value = puzzle ? (puzzle.title || '') : '';
    $('editor-surface').value = puzzle ? (puzzle.surface || '') : '';
    $('editor-truth').value = puzzle ? (puzzle.truth || '') : '';
    $('editor-key-points').value = puzzle ? (puzzle.key_points || []).join(', ') : '';
    $('editor-hints').value = puzzle ? (puzzle.hints || []).join('\n') : '';
    $('editor-difficulty').value = puzzle ? String(puzzle.difficulty || 2) : '2';
    $('editor-tags').value = puzzle ? (puzzle.tags || '') : '';
    $('editor-share').checked = puzzle ? (puzzle.scope === 'public') : false;
    $('puzzle-editor').style.display = 'flex';
}

async function saveEditor() {
    const surface = $('editor-surface').value.trim();
    const truth = $('editor-truth').value.trim();
    if (!surface || !truth) {
        showTopToast('汤面和汤底都不能为空', true);
        return;
    }
    const body = {
        title: $('editor-title').value.trim(),
        surface,
        truth,
        key_points: $('editor-key-points').value.split(/[,，]/).map(s => s.trim()).filter(Boolean),
        hints: $('editor-hints').value.split('\n').map(s => s.trim()).filter(Boolean),
        difficulty: parseInt($('editor-difficulty').value, 10) || 1,
        tags: $('editor-tags').value.trim(),
        is_public: $('editor-share').checked,
    };

    showLoading('保存中…');
    try {
        if (editingId) {
            await api('PUT', '/api/soup/my-puzzles/' + editingId, body);
        } else {
            await api('POST', '/api/soup/my-puzzles', body);
        }
        pickCache.mine = null;
        $('puzzle-editor').style.display = 'none';
        showTopToast('已保存', false);
        if (currentPage === 'puzzles') renderMyPuzzles();
    } catch (err) {
        showTopToast(err.message, true);
    }
    hideLoading();
}

// ==================== 事件绑定与启动 ====================

document.addEventListener('DOMContentLoaded', async () => {
    autoUpgradeOldUserdata();

    // 返回
    $('btn-back').addEventListener('click', () => {
        if (currentPage === 'lobby') {
            window.location.href = '/';
            return;
        }
        if (roomState) {
            const tip = roomState.is_host ? '离开将解散整个汤房，确定？' : '确定离开本汤房？';
            if (confirm(tip)) sendLeave();
            return;
        }
        showPage('lobby');
    });

    // 身份卡
    $('identity-btn-go-home').addEventListener('click', () => {
        stopHeartbeat();
        intentionalClose = true;
        if (ws) ws.close();
        setTimeout(() => { window.location.href = '/'; }, 50);
    });
    $('identity-btn-guest').addEventListener('click', hideIdentityCard);

    // 大厅
    $('btn-create-room').addEventListener('click', () => {
        if (!hasIdentity()) {
            showIdentityCard();
            showTopToast('请先创建身份', true);
            return;
        }
        showLoading('创建汤房…');
        sendCreateRoom();
    });
    $('btn-my-puzzles').addEventListener('click', loadPuzzlesPage);

    // 等待页
    $('btn-ready').addEventListener('click', () => {
        if (!roomState || !roomState.puzzle) {
            showTopToast('请先选择汤面', true);
            return;
        }
        sendWs({ type: 'soup_ready' });
    });
    $('btn-wait-leave').addEventListener('click', () => {
        if (confirm('确定离开？（房主离开将解散汤房）')) sendLeave();
    });
    document.querySelectorAll('.pick-tab').forEach((tab) => {
        tab.addEventListener('click', () => switchPickTab(tab.dataset.source));
    });

    // 对局页
    $('btn-truth-toggle').addEventListener('click', () => {
        const body = $('host-truth-body');
        const shown = body.style.display === 'none';
        body.style.display = shown ? 'block' : 'none';
        $('btn-truth-toggle').textContent = shown ? '收起汤底' : '查看汤底（仅出题人可见）';
    });
    $('btn-reveal-leave').addEventListener('click', () => sendLeave());
    $('btn-reveal-stay').addEventListener('click', () => {
        $('reveal-overlay').style.display = 'none';
    });
    $('btn-reveal-next').addEventListener('click', () => {
        $('reveal-overlay').style.display = 'none';
        sendWs({ type: 'soup_next' });
    });

    // 我的汤面
    $('btn-puzzles-back').addEventListener('click', () => showPage('lobby'));
    $('btn-new-puzzle').addEventListener('click', () => openEditor(null));
    $('puzzle-filter').addEventListener('change', (e) => {
        filterScope = e.target.value;
        renderMyPuzzles();
    });
    $('btn-editor-cancel').addEventListener('click', () => {
        $('puzzle-editor').style.display = 'none';
    });
    $('btn-editor-save').addEventListener('click', saveEditor);

    // 启动
    if (!hasIdentity()) {
        showIdentityCard();
        $('room-list').textContent = '';
        $('room-list').appendChild(el('div', { className: 'soup-room-empty' },
            '创建或恢复身份后，即可浏览汤房并加入对局'));
    } else {
        connectWs();
    }
});
