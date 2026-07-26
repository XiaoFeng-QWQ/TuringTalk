/**
 * @file 管理员前端逻辑
 * @requires script.js - 全局变量：{@link adminToken}, {@link spectateSessionId}, {@link _cachedSessions},
 *     {@link _sessionSearchKeyword}, {@link _reportsPage}, {@link _reportsFilter}, {@link _currentDetailReportId},
 *     {@link _currentDetailReason}, {@link _adminReady}, {@link uploadConfig}, {@link announceQueue}, {@link announceShowing}
 * @requires script.js - 全局函数：{@link getCookie}, {@link setCookie}, {@link delCookie}, {@link escapeHtml},
 *     {@link escapeHtmlAttr}, {@link resolvePath}, {@link showDanmaku}, {@link showBanReasonDialog}, {@link addBanButton}
 * @requires script.js - 全局类：{@link WebSocketTransport}
 * @requires window.__ADMIN_CONFIG__ = { ws_url, api_login }（服务端注入）
 */

// ==================== 管理 WS 专用全局状态 ====================
let _adminConnected = false;
let _adminReady = false;
let _adminConnecting = false;
let _isSuperAdmin = false;
let adminTransport = null;
let _wsGeneration = 0;  // 每次重连递增，防止旧 onclose 覆盖新连接状态
let _wsRetryCount = 0;  // 当前重试次数
let _wsRetryTimer = null;  // 重试定时器
const _WS_MAX_RETRIES = 5;  // 最大重试次数
const _WS_RETRY_BASE_MS = 1000;  // 重试基础间隔（ms）

// 日志分页状态
let _currentLogPage = 1;
let _currentLogAdminId = null;

// 举报筛选
let _reportsFilter = 'all';

// ==================== DOM 引用（所有引用在 DOMContentLoaded 初始化）====================
// 管理面板
let adminPanelOverlay, btnAdminPanel, btnCloseAdminPanel, btnExitAdmin;
// 公告
let broadcastInput, btnSendBroadcast, broadcastStatus;
// 对局列表
let btnRefreshSessions, searchSessionsInput, sessionsList;
// 旁观
let spectateInfo, spectateDetail, btnExitSpectate;
// 标签
let tabSessions, tabReports, tabStickers;
let panelSessions, panelReports, panelStickers;
// 举报审核
let reportsList, reportsPagination;
let reportDetailOverlay, reportDetailTitle, reportDetailContent, reportDetailChat;
let btnReportDetailClose, btnReportDetailReviewed;
// 表情管理
let stickerNameInput, stickerUrlInput, stickerPreview, stickerPreviewImg;
let btnAddSticker, stickerList, stickerListEmpty;
let btnStickerUpload, stickerFileInput, stickerUploadStatus;
let stickerLightbox, stickerLightboxImg, stickerLightboxClose;

// 动态创建的 DOM 元素
let tabAdmin, tabLogs, panelAdmin, panelLogs;
let adminListEl, adminLogListEl, adminLogPaginationEl;
let onlineStatusBar, onlineStatusList;
let adminLoginOverlay;

// ==================== 管理 WS 连接 ====================

/**
 * 连接管理 WebSocket
 * @param {string} url - 管理 WS 地址
 */
function connectAdminWS(url) {
    // 清除之前的重试定时器
    if (_wsRetryTimer) {
        clearTimeout(_wsRetryTimer);
        _wsRetryTimer = null;
    }

    if (adminTransport) {
        try { adminTransport.disconnect(); } catch (e) { /* ignore */ }
    }

    const generation = ++_wsGeneration;
    const ws = new WebSocket(url);

    ws.onopen = () => {
        _wsRetryCount = 0;  // 连接成功，重置重试计数
        DebugLogger.log('admin', '管理 WS 已连接');
        // 如果有 token，直接发送 admin_connect
        if (adminToken) {
            _adminConnecting = true;
            ws.send(JSON.stringify({ type: 'admin_connect', token: adminToken }));
        }
        // 等待 need_admin_login 或 admin_connected
    };

    ws.onmessage = (event) => {
        let data;
        try { data = JSON.parse(event.data); } catch (e) { return; }
        handleAdminMessage(data);
    };

    ws.onerror = () => {
        DebugLogger.log('admin', '管理 WS 连接错误');
    };

    ws.onclose = () => {
        // 仅当前代际的连接关闭才更新状态
        if (generation !== _wsGeneration) return;

        // 判断是否被动断开（网络异常等），而非主动 disconnect
        const wasUnexpected = adminTransport && adminTransport._ws === ws;

        DebugLogger.log('admin', '管理 WS 已断开');
        _adminConnected = false;
        _adminReady = false;
        _adminConnecting = false;
        adminTransport = null;
        updateOnlineStatusBar();

        // 被动断开时自动重试，指数退避
        if (wasUnexpected && _wsRetryCount < _WS_MAX_RETRIES) {
            _wsRetryCount++;
            const delay = _WS_RETRY_BASE_MS * Math.pow(2, _wsRetryCount - 1);
            DebugLogger.log('admin', `管理 WS 将在 ${delay}ms 后重试 (${_wsRetryCount}/${_WS_MAX_RETRIES})`);
            _wsRetryTimer = setTimeout(() => connectAdminWS(url), delay);
        }
    };

    adminTransport = {
        _ws: ws,
        disconnect() {
            try { ws.close(); } catch (e) { /* ignore */ }
            adminTransport = null;
        }
    };
}

/**
 * 通过管理 WebSocket 发送消息
 * @param {string} type - 消息类型
 * @param {Object} [payload={}] - 消息 payload
 */
function adminSend(type, payload) {
    if (!adminTransport || !adminTransport._ws || adminTransport._ws.readyState !== WebSocket.OPEN) {
        showAdminToast('管理连接未就绪');
        return;
    }
    adminTransport._ws.send(JSON.stringify({ type: type, ...payload }));
}

// ==================== 登录流程 ====================

/**
 * 显示管理员登录覆盖层（首次调用时动态创建 DOM）
 */
function showAdminLogin() {
    if (adminLoginOverlay) {
        adminLoginOverlay.style.display = 'flex';
        const userInput = document.getElementById('admin-login-username');
        const passInput = document.getElementById('admin-login-password');
        if (userInput) userInput.value = '';
        if (passInput) passInput.value = '';
        const errEl = document.getElementById('admin-login-error');
        if (errEl) errEl.style.display = 'none';
        if (userInput) userInput.focus();
        return;
    }

    adminLoginOverlay = document.createElement('div');
    adminLoginOverlay.id = 'admin-login-overlay';
    adminLoginOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';
    adminLoginOverlay.innerHTML = `
        <div class="doodle-border" style="padding:24px;max-width:360px;width:90%;background:#fff;">
            <h2 style="font-size:20px;color:var(--ink-blue);margin:0 0 16px;text-align:center;">
                <svg class="icon" viewBox="0 0 24 24" style="width:20px;height:20px;">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                管理员登录
            </h2>
            <div style="margin-bottom:12px;">
                <input type="text" id="admin-login-username" placeholder="用户名"
                    style="width:100%;padding:10px 12px;border:2px solid var(--ink-black);border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;margin-bottom:8px;">
                <input type="password" id="admin-login-password" placeholder="密码"
                    style="width:100%;padding:10px 12px;border:2px solid var(--ink-black);border-radius:10px;font-size:14px;outline:none;box-sizing:border-box;">
            </div>
            <div id="admin-login-error" style="color:#e74c3c;font-size:12px;margin-bottom:8px;display:none;"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="doodle-btn" id="btn-admin-login-cancel" style="font-size:14px;">取消</button>
                <button class="doodle-btn" id="btn-admin-login-submit"
                    style="font-size:14px;background:var(--ink-blue);color:var(--surface-white);border-color:var(--ink-blue);">登录</button>
            </div>
        </div>
    `;
    document.body.appendChild(adminLoginOverlay);

    const userInput = document.getElementById('admin-login-username');
    const passInput = document.getElementById('admin-login-password');
    const errEl = document.getElementById('admin-login-error');

    document.getElementById('btn-admin-login-submit').addEventListener('click', () => {
        doAdminLogin(userInput.value.trim(), passInput.value, errEl);
    });

    const onKeydown = (e) => {
        if (e.key === 'Enter') {
            doAdminLogin(userInput.value.trim(), passInput.value, errEl);
        }
    };
    userInput.addEventListener('keydown', onKeydown);
    passInput.addEventListener('keydown', onKeydown);

    document.getElementById('btn-admin-login-cancel').addEventListener('click', () => {
        hideAdminLogin();
    });

    adminLoginOverlay.addEventListener('click', (e) => {
        if (e.target === adminLoginOverlay) hideAdminLogin();
    });

    userInput.focus();
}

/**
 * 隐藏管理员登录覆盖层
 */
function hideAdminLogin() {
    if (adminLoginOverlay) {
        adminLoginOverlay.style.display = 'none';
    }
}

/**
 * 执行管理员 HTTP 登录
 * @param {string} username - 用户名
 * @param {string} password - 密码
 * @param {HTMLElement} [errEl] - 错误提示元素
 * @returns {Promise<void>}
 */
/**
 * 设置登录按钮的加载/禁用状态
 * @param {boolean} loading - 是否正在加载
 */
function setLoginLoading(loading) {
    const btn = document.getElementById('btn-admin-login-submit');
    if (!btn) return;
    btn.disabled = loading;
    btn.style.opacity = loading ? '0.6' : '1';
    btn.innerHTML = loading
        ? '<span class="login-spinner"></span> 登录中...'
        : '登录';
}

async function doAdminLogin(username, password, errEl) {
    if (!username) {
        if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入用户名'; }
        return;
    }
    if (!password) {
        if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入密码'; }
        return;
    }

    if (!window.__ADMIN_CONFIG__ || !window.__ADMIN_CONFIG__.api_login) {
        if (errEl) { errEl.style.display = 'block'; errEl.textContent = '登录接口未配置'; }
        return;
    }

    setLoginLoading(true);

    try {
        const resp = await fetch(window.__ADMIN_CONFIG__.api_login, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: username, password: password }),
        });
        const result = await resp.json();

        if (result.ok) {
            adminToken = result.token;
            setCookie('turing_admin_token', adminToken, 1);
            // 登录成功，跳回主页（管理入口仅用于登录）
            window.location.href = '/';
        } else {
            setLoginLoading(false);
            if (errEl) { errEl.style.display = 'block'; errEl.textContent = result.error || '登录失败'; }
        }
    } catch (e) {
        setLoginLoading(false);
        if (errEl) { errEl.style.display = 'block'; errEl.textContent = '网络错误，请重试'; }
    }
}

// ==================== 缓存 Token 恢复 ====================

/**
 * 通过游戏 WebSocket 发送 admin_verify 验证缓存 token，
 * 收到 admin_config 后自动连接管理 WebSocket。
 */
function tryAdminFromCache() {
    if (!adminToken) return;

    function sendVerify() {
        if (!transport || !transport._ws) return;

        const origHandler = transport._adminHandler;
        transport._adminHandler = (data) => {
            if (data.type === 'admin_config') {
                transport._adminHandler = origHandler;
                _isSuperAdmin = !!data.super_admin;
                connectAdminWS(data.ws_url);
                updateAdminTabs();
                return true;
            }
            return origHandler ? origHandler(data) : false;
        };

        transport._ws.send(JSON.stringify({ type: 'admin_verify', token: adminToken }));
    }

    if (transport && transport._ws && transport._ws.readyState === WebSocket.OPEN) {
        sendVerify();
    } else if (transport && transport._ws && transport._ws.readyState === WebSocket.CONNECTING) {
        const origOnOpen = transport._ws.onopen;
        transport._ws.onopen = (e) => {
            if (origOnOpen) origOnOpen.call(transport._ws, e);
            sendVerify();
        };
    } else {
        setTimeout(tryAdminFromCache, 100);
    }
}

// ==================== 管理 WS 消息处理 ====================

/**
 * 管理 WebSocket 消息路由
 * @param {Object} data - 收到的 JSON 消息
 */
function handleAdminMessage(data) {
    switch (data.type) {
        case 'need_admin_login':
            if (!_adminConnecting) {
                showAdminLogin();
            }
            break;

        case 'admin_connected':
            _adminReady = true;
            _adminConnected = true;
            _adminConnecting = false;
            _isSuperAdmin = data.role === 'super_admin';
            hideAdminLogin();
            btnAdminPanel.style.display = 'inline-flex';
            if (typeof addBanButton === 'function') addBanButton();
            updateAdminTabs();
            updateOnlineStatusBar();
            adminSend('admin_get_upload_config');
            break;

        case 'admin_config':
            _isSuperAdmin = !!data.super_admin;
            updateAdminTabs();
            break;

        case 'admin_status':
            updateOnlineStatusBar();
            break;

        case 'admin_online_list':
            renderOnlineList(data.online_list || []);
            break;

        case 'sessions_list':
            renderSessionsList(data.sessions);
            break;

        case 'session_detail':
            enterSpectatorView(data.session_id, data.player1, data.player2, data.history || []);
            break;

        case 'spectate_message':
            if (typeof appendMessage === 'function') {
                appendMessage(data.text, data.side || 'left', data.sender);
            }
            break;

        case 'spectate_system':
            if (typeof game !== 'undefined' && game._onSystem) {
                game._onSystem({ text: data.text });
            }
            break;

        case 'spectate_sticker':
            if (typeof appendSticker === 'function' && data.id && stickerMap && stickerMap[data.id]) {
                appendSticker(data.id, data.name || stickerMap[data.id].name, data.side || 'left', data.sender || '玩家');
            }
            break;

        case 'spectate_ended':
            if (spectateSessionId === data.session_id) {
                const reasonMap = {
                    '玩家断开连接': '玩家断开连接，观战结束',
                    '玩家离开': '玩家离开了房间，观战结束',
                    '判定超时': '判定超时，观战结束',
                    'no_mutual_chat': '双方未互发消息，对局无效',
                    '双方判定完成': '判定完成，观战结束' + (data.result ? '（玩家1: ' + data.result.player1_truth + ' 玩家2: ' + data.result.player2_truth + '）' : ''),
                };
                showAdminToast(reasonMap[data.reason] || data.reason || '观战结束');
                setTimeout(() => exitSpectatorView(), 3000);
            }
            break;

        case 'admin_unspectated':
            exitSpectatorView();
            break;

        case 'broadcast_result':
            broadcastStatus.style.display = 'block';
            broadcastStatus.textContent = data.message || '已发送';
            broadcastStatus.style.color = 'var(--success)';
            setTimeout(() => { broadcastStatus.style.display = 'none'; }, 4000);
            break;

        case 'admin_reports':
            renderReportsList(data.reports, data.total, data.page, data.page_size);
            break;

        case 'admin_report_detail':
            renderReportDetail(data.report);
            break;

        case 'admin_mark_reviewed_result':
            reportDetailOverlay.style.display = 'none';
            _currentDetailReportId = null;
            loadReports(_reportsPage);
            break;

        case 'admin_ban_player_result':
            alert(data.message || '封禁完成');
            break;

        case 'admin_ban_by_info_result':
            reportDetailOverlay.style.display = 'none';
            _currentDetailReportId = null;
            loadReports(_reportsPage);
            alert(data.message || '封禁完成');
            break;

        case 'admin_sticker_added':
            if (stickerNameInput) stickerNameInput.value = '';
            if (stickerUrlInput) stickerUrlInput.value = '';
            if (stickerPreview) stickerPreview.style.display = 'none';
            loadStickers();
            break;

        case 'admin_sticker_deleted':
            loadStickers();
            break;

        case 'admin_stickers_list':
            renderStickerList(data.stickers || []);
            break;

        case 'admin_upload_config':
            uploadConfig = data.config || {};
            break;

        case 'admin_list':
            renderAdminList(data.admins || []);
            break;

        case 'admin_added':
            showAdminToast('管理员已添加', false);
            loadAdminList();
            // 清空添加输入
            { const u = document.getElementById('admin-add-username'); if (u) u.value = ''; }
            { const p = document.getElementById('admin-add-password'); if (p) p.value = ''; }
            break;

        case 'admin_deleted':
            showAdminToast('管理员已删除', false);
            loadAdminList();
            break;

        case 'admin_password_changed':
            showAdminToast('密码已修改', false);
            if (data.self) {
                const o = document.getElementById('admin-own-password-old');
                const n = document.getElementById('admin-own-password-new');
                if (o) o.value = '';
                if (n) n.value = '';
            }
            break;

        case 'admin_my_logs':
        case 'admin_all_logs':
            renderLogList(data.logs || [], data.total || 0, data.page || 1, data.page_size || 20, data.type);
            break;

        case 'error':
            showAdminToast(data.message || '管理操作出错');
            break;

        case 'room_announce':
            if (typeof showDanmaku === 'function') {
                showDanmaku(data.text, '管理警告');
            }
            break;

        default:
            break;
    }
}

// ==================== 管理面板 UI ====================

/**
 * 打开管理面板并自动刷新对局列表
 */
function openAdminPanel() {
    adminPanelOverlay.style.display = 'flex';
    if (_adminConnected) {
        refreshSessions();
    }
}

/**
 * 关闭管理面板（带动画）
 */
function closeAdminPanel() {
    closeOverlay(adminPanelOverlay);
}

/**
 * 切换管理面板标签页
 * @param {string} tab - 目标标签页名称（sessions|reports|stickers|admin|logs）
 */
function switchAdminTab(tab) {
    const allTabs = [
        { btn: tabSessions, panel: panelSessions, name: 'sessions' },
        { btn: tabReports, panel: panelReports, name: 'reports' },
        { btn: tabStickers, panel: panelStickers, name: 'stickers' },
    ];

    if (_isSuperAdmin && tabAdmin && tabLogs) {
        allTabs.push(
            { btn: tabAdmin, panel: panelAdmin, name: 'admin' },
            { btn: tabLogs, panel: panelLogs, name: 'logs' }
        );
    }

    allTabs.forEach(t => {
        const active = t.name === tab;
        t.btn.classList.toggle('active', active);
        t.btn.style.background = active ? '#e8e0d4' : 'transparent';
        t.btn.style.color = active ? 'var(--ink-blue)' : '#999';
        t.panel.style.display = active ? '' : 'none';
    });

    if (tab === 'reports') {
        loadReports(1);
    }
    if (tab === 'stickers') {
        loadStickers();
    }
    if (tab === 'admin' && _isSuperAdmin) {
        loadAdminList();
    }
    if (tab === 'logs') {
        loadLogs(1);
    }
}

/**
 * 更新管理标签页可见性。
 * 根据当前角色（super_admin）动态创建/显示/隐藏 admin 和 logs 标签及面板。
 */
function updateAdminTabs() {
    if (!tabSessions || !tabReports || !tabStickers) return;

    // 获取或创建 admin/logs 标签
    const tabContainer = tabSessions.parentElement;
    if (!tabContainer) return;

    if (_isSuperAdmin) {
        if (!tabAdmin) {
            tabAdmin = document.createElement('button');
            tabAdmin.className = 'admin-tab-btn';
            tabAdmin.id = 'tab-admin';
            tabAdmin.style.cssText = 'flex:1;padding:6px 0;font-size:13px;border:none;background:transparent;color:#999;cursor:pointer;';
            tabAdmin.textContent = '管理员';
            tabAdmin.addEventListener('click', () => switchAdminTab('admin'));
            tabContainer.appendChild(tabAdmin);

            tabLogs = document.createElement('button');
            tabLogs.className = 'admin-tab-btn';
            tabLogs.id = 'tab-logs';
            tabLogs.style.cssText = 'flex:1;padding:6px 0;font-size:13px;border:none;background:transparent;color:#999;cursor:pointer;';
            tabLogs.textContent = '操作日志';
            tabLogs.addEventListener('click', () => switchAdminTab('logs'));
            tabContainer.appendChild(tabLogs);
        }

        // 创建 admin 面板
        if (!panelAdmin) {
            const panelContainer = adminPanelOverlay.querySelector('.paper-content');
            if (panelContainer) {
                panelAdmin = createAdminManagementPanel();
                panelLogs = createAdminLogsPanel();
                panelContainer.insertBefore(panelLogs, document.getElementById('spectate-info'));
                panelContainer.insertBefore(panelAdmin, panelLogs);
            }
        }
        // 面板是动态创建的，需要重新绑定事件
        bindAdminManagementEvents();
        bindLogFilterEvents();
        if (tabAdmin) tabAdmin.style.display = '';
        if (tabLogs) tabLogs.style.display = '';
        if (panelAdmin) panelAdmin.style.display = 'none';
        if (panelLogs) panelLogs.style.display = 'none';
    } else {
        if (tabAdmin) tabAdmin.style.display = 'none';
        if (tabLogs) tabLogs.style.display = 'none';
        if (panelAdmin) panelAdmin.style.display = 'none';
        if (panelLogs) panelLogs.style.display = 'none';
    }
}

// ==================== 对局列表 ====================

/**
 * 向服务端请求对局列表
 */
function loadSessions() {
    if (!_adminConnected) {
        sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">管理连接未就绪</div>';
        return;
    }
    if (!adminTransport || !adminTransport._ws || adminTransport._ws.readyState !== WebSocket.OPEN) {
        _adminConnected = false;
        sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">管理连接已断开，请重新进入</div>';
        updateOnlineStatusBar();
        return;
    }
    adminSend('admin_sessions');
}

/**
 * 刷新对局列表（显示 loading，然后请求数据）
 */
function refreshSessions() {
    if (!_adminConnected) {
        sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">管理连接未就绪，请稍后重试</div>';
        return;
    }
    sessionsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';
    loadSessions();
}

/**
 * 渲染从服务端获取的对局列表
 * @param {Array} sessions - 对局列表
 */
function renderSessionsList(sessions) {
    if (!sessions) return;

    _cachedSessions = sessions;
    _doRenderSessionsList();
}

/**
 * 渲染对局列表（过滤搜索关键字后）
 */
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
        const shortId = s.id ? s.id.substring(0, 12) : '';
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

    sessionsList.querySelectorAll('.spectate-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (typeof game !== 'undefined' && game && game._sessionId) {
                alert('你当前正在对局中，请先结束或离开对局后再进行旁观。');
                return;
            }
            closeOverlay(adminPanelOverlay);
            adminSend('admin_spectate', { session_id: btn.dataset.sid });
        });
    });
}

// ==================== 旁观 ====================

/**
 * 进入旁观视图
 * @param {string} sessionId - 对局 ID
 * @param {Object} player1 - 玩家 1 信息 { fd, nickname, truth, tag }
 * @param {Object} player2 - 玩家 2 信息 { fd, nickname, truth, tag }
 * @param {Array} [history=[]] - 聊天历史消息
 */
function enterSpectatorView(sessionId, player1, player2, history) {
    spectateSessionId = sessionId;

    window._spectatePlayers = { p1: player1, p2: player2 };

    // 显示聊天页面
    const landingPage = document.getElementById('landing-page');
    const matchingPage = document.getElementById('matching-page');
    const chatPage = document.getElementById('chat-page');
    const chatBody = document.getElementById('chat-body');
    const chatInputArea = document.getElementById('chat-input-area');
    const judgementZone = document.getElementById('judgement-zone');
    const resultArea = document.getElementById('result-area');
    const btnBack = document.getElementById('btn-back');
    const stickerPicker = document.getElementById('sticker-picker');
    const timerDisplay = document.getElementById('timer-display');
    const logoText = document.getElementById('logo-text');

    if (landingPage) landingPage.style.display = 'none';
    if (matchingPage) matchingPage.style.display = 'none';
    if (chatPage) chatPage.style.display = 'flex';
    if (resultArea) resultArea.style.display = 'none';
    if (btnBack) btnBack.style.display = 'inline-flex';

    if (chatBody) chatBody.innerHTML = '';

    // 渲染历史消息
    if (history && history.length && typeof appendMessage === 'function' && typeof appendSticker === 'function') {
        for (const msg of history) {
            if (msg.sticker_id) {
                if (stickerMap && stickerMap[msg.sticker_id]) {
                    appendSticker(msg.sticker_id, msg.sticker_name || stickerMap[msg.sticker_id].name, msg.side || 'left', msg.sender || '');
                }
            } else {
                appendMessage(msg.text, msg.side || 'left', msg.sender);
            }
        }
    }

    // 添加旁观横幅
    let banner = document.getElementById('spectate-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'spectate-banner';
    }
    banner.innerHTML = `
        <div class="spec-row spec-header">你正在旁观对局</div>
        <div class="spec-row">
            <span class="spec-player">
                <span style="color:#ffeb3b;">${escapeHtml(player1.nickname)}</span>
                <span>是 <b>${player1.truth}</b></span>
                ${player1.tag ? '<span class="spectate-tag">' + escapeHtml(player1.tag) + '</span>' : ''}
            </span>
            <button class="doodle-btn spectate-ban-btn" data-fd="${player1.fd}" data-name="${escapeHtml(player1.nickname)}" style="color:#ffeb3b;border-color:#ffeb3b;">封禁</button>
            <span style="opacity:0.5;">|</span>
            <span class="spec-player">
                <span style="color:#ffeb3b;">${escapeHtml(player2.nickname)}</span>
                <span>是 <b>${player2.truth}</b></span>
                ${player2.tag ? '<span class="spectate-tag">' + escapeHtml(player2.tag) + '</span>' : ''}
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

    const notebookContainer = document.querySelector('.notebook-container');
    if (notebookContainer) {
        notebookContainer.insertBefore(banner, notebookContainer.firstChild);
    }

    // 封禁按钮事件
    banner.querySelectorAll('.spectate-ban-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetFd = parseInt(btn.dataset.fd);
            const targetName = btn.dataset.name;
            if (isNaN(targetFd) || targetFd <= 0) {
                showAdminToast('无法封禁（对方可能是 AI）');
                return;
            }
            if (typeof showBanReasonDialog === 'function') {
                showBanReasonDialog(targetName, (reason) => {
                    adminSend('admin_ban_player', { player_fd: targetFd, reason: reason });
                });
            }
        });
    });

    const spectateLeaveBtn = document.getElementById('btn-spectate-leave');
    if (spectateLeaveBtn) {
        spectateLeaveBtn.addEventListener('click', () => {
            if (spectateSessionId) {
                adminSend('admin_unspectate');
            }
            exitSpectatorView();
        });
    }

    // 房间公告
    const roomInput = document.getElementById('room-broadcast-input');
    const roomSendBtn = document.getElementById('btn-send-room-broadcast');
    if (roomSendBtn && roomInput) {
        roomSendBtn.addEventListener('click', () => {
            const text = roomInput.value.trim();
            if (!text) {
                showAdminToast('请输入房间公告内容');
                return;
            }
            adminSend('admin_room_broadcast', { text: text });
            roomInput.value = '';
        });
        roomInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (roomSendBtn) roomSendBtn.click();
            }
        });
    }

    // 隐藏输入区和判定区
    if (chatInputArea) chatInputArea.style.display = 'none';
    if (judgementZone) judgementZone.style.display = 'none';
    if (stickerPicker) stickerPicker.style.display = 'none';

    // 更新对手信息
    const infoDiv = document.querySelector('.opponent-info > div:nth-of-type(2)');
    if (infoDiv) {
        infoDiv.innerHTML = `
            <div style="font-size:12px;color:#888;">旁观对局</div>
            <strong style="font-size:15px;">${escapeHtml(player1.nickname)} vs ${escapeHtml(player2.nickname)}</strong>
        `;
    }

    // 更新 Logo
    if (logoText) {
        if (!window._origLogoHTML) {
            window._origLogoHTML = (typeof origLogoHTML !== 'undefined') ? origLogoHTML : logoText.innerHTML;
        }
        logoText.innerHTML = `
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            旁观模式
        `;
    }

    // 更新管理面板
    if (spectateInfo) spectateInfo.style.display = 'block';
    if (spectateDetail) {
        spectateDetail.innerHTML = `
            <b>${escapeHtml(player1.nickname)}</b>: ${player1.truth}<br>
            <b>${escapeHtml(player2.nickname)}</b>: ${player2.truth}
        `;
    }

    // 停止计时器
    if (typeof timerInterval !== 'undefined' && timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
    if (timerDisplay) timerDisplay.textContent = '旁观';
}

/**
 * 退出旁观，恢复游戏 UI
 */
function exitSpectatorView() {
    if (!spectateSessionId) return;

    adminSend('admin_unspectate');

    spectateSessionId = null;
    window._spectatePlayers = null;

    // 移除旁观横幅
    const banner = document.getElementById('spectate-banner');
    if (banner) banner.remove();

    // 恢复 UI
    const chatInputArea = document.getElementById('chat-input-area');
    const judgementZone = document.getElementById('judgement-zone');
    const chatBody = document.getElementById('chat-body');
    const chatPage = document.getElementById('chat-page');
    const landingPage = document.getElementById('landing-page');
    const matchingPage = document.getElementById('matching-page');
    const btnBack = document.getElementById('btn-back');
    const logoText = document.getElementById('logo-text');
    const resultArea = document.getElementById('result-area');
    const timerDisplay = document.getElementById('timer-display');

    if (chatInputArea) chatInputArea.style.display = '';
    if (judgementZone) judgementZone.style.display = '';
    if (chatBody) {
        chatBody.innerHTML = `
            <div class="sys-msg">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                </svg>
                连线成功，对方身份未知，计时开始
            </div>
        `;
    }

    if (chatPage) chatPage.style.display = 'none';
    if (matchingPage && matchingPage.style.display !== 'flex') {
        if (landingPage) landingPage.style.display = 'flex';
    }
    if (btnBack) btnBack.style.display = 'none';
    if (logoText && window._origLogoHTML) {
        logoText.innerHTML = window._origLogoHTML;
    }

    if (spectateInfo) spectateInfo.style.display = 'none';
    if (spectateDetail) spectateDetail.innerHTML = '';

    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    if (timerDisplay) timerDisplay.textContent = '';
    if (resultArea) resultArea.style.display = '';
}

// ==================== 公告 ====================

/**
 * 发送全服公告
 */
function sendBroadcast() {
    const text = broadcastInput.value.trim();
    if (!text) {
        broadcastStatus.style.display = 'block';
        broadcastStatus.textContent = '请输入公告内容';
        broadcastStatus.style.color = 'var(--danger)';
        return;
    }
    adminSend('admin_broadcast', { text: text });
    broadcastInput.value = '';
}

// ==================== 举报审核 ====================

/**
 * 加载举报列表
 * @param {number} page - 页码
 */
function loadReports(page) {
    _reportsPage = page;
    reportsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';

    if (!_adminConnected) {
        reportsList.innerHTML = '<div style="text-align:center;color:#f44336;padding:10px;">管理员连接未就绪</div>';
        return;
    }

    let reviewed = null;
    if (_reportsFilter === '0') reviewed = '0';
    else if (_reportsFilter === '1') reviewed = '1';

    adminSend('admin_reports', {
        page: page,
        page_size: 20,
        reviewed: reviewed,
    });
}

/**
 * 渲染举报列表
 * @param {Array} reports - 举报列表
 * @param {number} total - 总数
 * @param {number} page - 当前页码
 * @param {number} pageSize - 每页条数
 */
function renderReportsList(reports, total, page, pageSize) {
    if (!reports || reports.length === 0) {
        const msg = _reportsFilter === '0' ? '暂无未审核的举报' : (_reportsFilter === '1' ? '暂无已审核的举报' : '暂无举报记录');
        reportsList.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">' + msg + '</div>';
        reportsPagination.innerHTML = '';
        return;
    }

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

    reportsList.querySelectorAll('.session-row').forEach(row => {
        row.addEventListener('click', () => {
            const rid = parseInt(row.dataset.rid);
            if (rid) openReportDetail(rid);
        });
    });

    const totalPages = Math.ceil(total / pageSize);
    if (totalPages <= 1) {
        reportsPagination.innerHTML = '';
        return;
    }
    let pageHtml = '';
    for (let i = 1; i <= totalPages; i++) {
        if (i === page) {
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:var(--ink-blue);color:var(--surface-white);border-radius:4px;">' + i + '</span>';
        } else {
            pageHtml += '<span style="cursor:pointer;padding:2px 8px;border:1px solid var(--ink-blue);border-radius:4px;" data-pg="' + i + '">' + i + '</span>';
        }
    }
    reportsPagination.innerHTML = pageHtml;
    reportsPagination.querySelectorAll('[data-pg]').forEach(el => {
        el.addEventListener('click', () => loadReports(parseInt(el.dataset.pg)));
    });
}

/**
 * 打开举报详情浮层
 * @param {number} reportId - 举报 ID
 */
function openReportDetail(reportId) {
    _currentDetailReportId = reportId;
    if (!_adminConnected) return;

    reportDetailContent.innerHTML = '加载中...';
    reportDetailChat.innerHTML = '';
    reportDetailOverlay.style.display = 'flex';

    adminSend('admin_report_detail', { report_id: reportId });
}

/**
 * 渲染举报详情内容
 * @param {Object} report - 举报详情数据
 */
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
            <button class="doodle-btn ban-info-btn" data-ip="${escapeHtml(ip)}" data-fp="${escapeHtml(fp)}" data-label="${escapeHtml(label)}"
                style="font-size:10px;padding:2px 8px;border-color:var(--danger);color:var(--danger);">封禁</button>
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

    reportDetailContent.querySelectorAll('.ban-info-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const ip = btn.dataset.ip;
            const fp = btn.dataset.fp;
            const label = btn.dataset.label || '该用户';
            reportDetailOverlay.style.display = 'none';
            if (typeof showBanReasonDialog === 'function') {
                showBanReasonDialog(label, (reason) => {
                    const finalReason = reason || _currentDetailReason || '';
                    adminSend('admin_ban_by_info', { ip: ip, fingerprint: fp, reason: finalReason });
                }, _currentDetailReason, () => {
                    reportDetailOverlay.style.display = 'flex';
                });
            }
        });
    });

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

// ==================== 表情管理 ====================

/**
 * 向服务端请求表情列表
 */
function loadStickers() {
    adminSend('admin_sticker_list');
}

/**
 * 渲染表情列表
 * @param {Array} stickers - 表情列表
 */
function renderStickerList(stickers) {
    if (!stickerList || !stickerListEmpty) return;
    stickerList.innerHTML = '';
    if (!stickers || stickers.length === 0) {
        stickerList.appendChild(stickerListEmpty);
        stickerListEmpty.style.display = 'block';
        return;
    }
    stickerListEmpty.style.display = 'none';
    stickers.forEach(s => {
        const item = document.createElement('div');
        item.className = 'sticker-item';
        item.innerHTML =
            '<img src="' + escapeHtmlAttr(s.url) + '" alt="' + escapeHtmlAttr(s.name) + '" loading="lazy">' +
            '<span class="sticker-name">' + escapeHtml(s.name) + '</span>' +
            '<button class="sticker-delete" data-sticker-id="' + escapeHtmlAttr(s.id) + '">删除</button>';
        item.querySelector('img').addEventListener('click', () => {
            if (stickerLightbox && stickerLightboxImg) {
                stickerLightboxImg.src = s.url;
                stickerLightbox.style.display = 'flex';
            }
        });
        stickerList.appendChild(item);
    });
}

// ==================== 管理员管理（仅 super_admin）====================

/**
 * 创建管理员管理面板（仅 super_admin 可见）
 * @returns {HTMLElement}
 */
function createAdminManagementPanel() {
    const panel = document.createElement('div');
    panel.className = 'setting-row';
    panel.id = 'panel-admin';
    panel.style.display = 'none';
    panel.innerHTML = `
        <h4>
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            管理员列表
        </h4>
        <div style="display:flex;gap:8px;margin-bottom:8px;">
            <input type="text" id="admin-add-username" placeholder="用户名"
                style="flex:1;padding:6px 10px;border:2px solid var(--ink-blue);border-radius:6px;font-size:13px;outline:none;">
            <input type="password" id="admin-add-password" placeholder="密码"
                style="flex:1;padding:6px 10px;border:2px solid var(--ink-blue);border-radius:6px;font-size:13px;outline:none;">
            <button class="doodle-btn" id="btn-add-admin"
                style="white-space:nowrap;font-size:12px;padding:6px 12px;">添加</button>
        </div>
        <div id="admin-add-error" style="color:#e74c3c;font-size:12px;margin-bottom:6px;display:none;"></div>
        <div id="admin-list" style="max-height:200px;overflow-y:auto;font-size:13px;">
            <div style="text-align:center;color:#999;padding:10px;">加载中...</div>
        </div>
        <div style="margin-top:12px;padding-top:10px;border-top:1px dashed #ddd;">
            <label style="font-size:13px;font-weight:bold;display:block;margin-bottom:6px;">修改自己的密码</label>
            <div style="display:flex;gap:8px;margin-bottom:4px;">
                <input type="password" id="admin-own-password-old" placeholder="当前密码"
                    style="flex:1;padding:6px 10px;border:2px solid var(--ink-blue);border-radius:6px;font-size:13px;outline:none;">
                <input type="password" id="admin-own-password-new" placeholder="新密码"
                    style="flex:1;padding:6px 10px;border:2px solid var(--ink-blue);border-radius:6px;font-size:13px;outline:none;">
                <button class="doodle-btn" id="btn-change-own-password"
                    style="white-space:nowrap;font-size:12px;padding:6px 12px;">修改</button>
            </div>
            <div id="admin-own-password-error" style="color:#e74c3c;font-size:12px;display:none;"></div>
        </div>
    `;
    return panel;
}

/**
 * 创建操作日志面板
 * @returns {HTMLElement}
 */
function createAdminLogsPanel() {
    const panel = document.createElement('div');
    panel.className = 'setting-row';
    panel.id = 'panel-logs';
    panel.style.display = 'none';
    panel.innerHTML = `
        <h4>
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
            操作日志
        </h4>
        <div style="display:flex;gap:6px;margin-bottom:8px;">
            <button class="doodle-btn log-filter-btn active" data-filter="my"
                style="flex:1;font-size:11px;padding:4px 0;justify-content:center;">我的操作</button>
            <button class="doodle-btn log-filter-btn" data-filter="all"
                style="flex:1;font-size:11px;padding:4px 0;justify-content:center;">全部日志</button>
        </div>
        <div id="admin-log-list" style="max-height:300px;overflow-y:auto;font-size:13px;">
            <div style="text-align:center;color:#999;padding:10px;">点击上方按钮查看</div>
        </div>
        <div id="admin-log-pagination"
            style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:8px;font-size:12px;color:#888;">
        </div>
    `;
    return panel;
}

/**
 * 向服务端请求管理员列表（仅 super_admin）
 */
function loadAdminList() {
    if (!_isSuperAdmin || !_adminConnected) return;
    adminSend('admin_list');
}

/**
 * 渲染管理员列表
 * @param {Array} admins - 管理员列表
 */
function renderAdminList(admins) {
    adminListEl = document.getElementById('admin-list');
    if (!adminListEl) return;
    if (!admins || admins.length === 0) {
        adminListEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">暂无可管理的管理员</div>';
        return;
    }
    let html = '';
    admins.forEach(a => {
        html += `
            <div class="session-row" style="align-items:center;">
                <div class="session-info">
                    <span style="font-weight:bold;">${escapeHtml(a.username)}</span>
                    <span style="font-size:11px;color:#888;">${a.role === 'super_admin' ? '(超级管理员)' : '普通管理员'} · ${escapeHtml(a.created_at || '')} 最后一次登录时间：${escapeHtml(a.last_login_at || '')}</span>
                </div>
                <button class="doodle-btn admin-delete-btn" data-id="${escapeHtml(a.id)}"
                    style="font-size:10px;padding:2px 8px;color:var(--danger);border-color:var(--danger);">删除</button>
            </div>
        `;
    });
    adminListEl.innerHTML = html;

    adminListEl.querySelectorAll('.admin-delete-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!id) return;
            if (!confirm('确定删除该管理员吗？此操作不可恢复。')) return;
            adminSend('admin_delete', { admin_id: id });
        });
    });
}

// ==================== 操作日志 ====================

/**
 * 加载操作日志
 * @param {number} page - 页码
 */
function loadLogs(page) {
    _currentLogPage = page;
    adminLogListEl = document.getElementById('admin-log-list');
    if (adminLogListEl) {
        adminLogListEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">加载中...</div>';
    }

    if (!_adminConnected) return;

    const filterBtns = document.querySelectorAll('.log-filter-btn');
    let filterType = 'my';
    filterBtns.forEach(b => {
        if (b.classList.contains('active')) filterType = b.dataset.filter;
    });

    if (filterType === 'my') {
        adminSend('admin_my_logs', { page: page, page_size: 20 });
    } else {
        adminSend('admin_all_logs', { page: page, page_size: 20 });
    }
}

/**
 * 渲染操作日志列表
 * @param {Array} logs - 日志条目
 * @param {number} total - 总数
 * @param {number} page - 当前页码
 * @param {number} pageSize - 每页条数
 * @param {string} logType - 日志类型（admin_my_logs|admin_all_logs）
 */
function renderLogList(logs, total, page, pageSize, logType) {
    adminLogListEl = document.getElementById('admin-log-list');
    adminLogPaginationEl = document.getElementById('admin-log-pagination');
    if (!adminLogListEl || !adminLogPaginationEl) return;

    if (!logs || logs.length === 0) {
        adminLogListEl.innerHTML = '<div style="text-align:center;color:#999;padding:10px;">暂无操作日志</div>';
        adminLogPaginationEl.innerHTML = '';
        return;
    }

    let html = '';
    const actionLabels = {
        'login': '登录', 'login_failed': '登录失败', 'logout': '登出', 'ban_player': '封禁玩家',
        'broadcast': '全服公告', 'room_broadcast': '房间公告', 'add_sticker': '添加表情',
        'delete_sticker': '删除表情', 'review_report': '审核举报', 'spectate': '旁观',
        'add_admin': '添加管理员', 'delete_admin': '删除管理员', 'change_password': '修改密码',
    };
    logs.forEach(log => {
        const time = log.created_at ? log.created_at.substring(0, 19).replace('T', ' ') : '';
        const label = actionLabels[log.action] || log.action || '?';
        let targetInfo = '';
        if (log.target_type && log.target_id) {
            targetInfo = `目标: ${escapeHtml(log.target_type)}/${log.target_id.substring(0, 16)}`;
        }
        html += `
            <div class="session-row" style="flex-wrap:wrap;">
                <div class="session-info" style="flex:1;min-width:0;">
                    <div style="font-size:12px;">
                        <span style="font-weight:bold;color:var(--ink-blue);">${escapeHtml(label)}</span>
                        <span style="font-size:10px;color:#888;">by ${escapeHtml(log.username || '?')}</span>
                        ${log.ip ? `<span style="font-size:10px;color:#999;margin-left:4px;">IP: ${escapeHtml(log.ip)}</span>` : ''}
                    </div>
                    <div style="font-size:10px;color:#aaa;">
                        ${escapeHtml(log.detail || '')}
                        ${targetInfo ? ' · ' + targetInfo : ''}
                        ${' · ' + time}
                    </div>
                </div>
            </div>
        `;
    });
    adminLogListEl.innerHTML = html;

    const totalPages = Math.ceil(total / pageSize);
    if (totalPages <= 1) {
        adminLogPaginationEl.innerHTML = '';
        return;
    }
    let pageHtml = '';
    for (let i = 1; i <= totalPages; i++) {
        if (i === page) {
            pageHtml += '<span style="font-weight:bold;padding:2px 8px;background:var(--ink-blue);color:var(--surface-white);border-radius:4px;">' + i + '</span>';
        } else {
            pageHtml += '<span style="cursor:pointer;padding:2px 8px;border:1px solid var(--ink-blue);border-radius:4px;" data-pg="' + i + '">' + i + '</span>';
        }
    }
    adminLogPaginationEl.innerHTML = pageHtml;
    adminLogPaginationEl.querySelectorAll('[data-pg]').forEach(el => {
        el.addEventListener('click', () => loadLogs(parseInt(el.dataset.pg)));
    });
}

// ==================== 在线状态 ====================

/**
 * 初始化在线状态下拉面板
 */
function initOnlineStatus() {
    const countEl = document.getElementById('online-count');
    if (!countEl || countEl._ready) return;
    countEl._ready = true;
    countEl.style.position = 'relative';
    countEl.style.cursor = 'default';

    // 下拉面板
    const panel = document.createElement('div');
    panel.id = 'online-status-dropdown';
    panel.style.cssText = `
        display:none;
        position:absolute;top:100%;right:0;margin-top:6px;
        background:var(--surface-white);border:2px solid var(--ink-black);
        border-radius:10px;padding:10px 12px;min-width:170px;
        max-height:240px;overflow-y:auto;font-size:12px;
        box-shadow:0 4px 16px rgba(0,0,0,0.12);z-index:1000;
    `;
    panel.innerHTML = `
        <div style="padding:4px 0 8px;border-bottom:1px solid #eee;margin-bottom:6px;">
            <span id="dropdown-player-count">在线玩家: ...</span>
        </div>
        <div id="dropdown-admin-list" style="color:#888;">
            仅管理员可见
        </div>
    `;
    countEl.appendChild(panel);
    onlineStatusList = panel;

    countEl.addEventListener('click', (e) => {
        e.stopPropagation();
        if (_adminConnected) {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
    });

    document.addEventListener('click', () => {
        panel.style.display = 'none';
    });

    // 监听 online-num 变化，同步更新下拉面板的玩家数
    const numEl = document.getElementById('online-num');
    if (numEl) {
        const observer = new MutationObserver(() => {
            updateOnlinePlayerCount();
        });
        observer.observe(numEl, { characterData: true, childList: true, subtree: true });
    }

    updateOnlinePlayerCount();
}

/**
 * 同步在线玩家数到下拉面板
 */
function updateOnlinePlayerCount() {
    const numEl = document.getElementById('online-num');
    const dropdownEl = document.getElementById('dropdown-player-count');
    if (numEl && dropdownEl) {
        dropdownEl.textContent = '在线玩家: ' + (numEl.textContent || '...');
    }
}

/**
 * 根据管理连接状态更新在线状态下拉面板
 */
function updateOnlineStatusBar() {
    const countEl = document.getElementById('online-count');
    const adminListEl = document.getElementById('dropdown-admin-list');

    if (_adminConnected) {
        if (countEl) countEl.style.cursor = 'pointer';
    } else {
        if (countEl) countEl.style.cursor = 'default';
        if (adminListEl) {
            adminListEl.innerHTML = '<div style="color:#888;text-align:center;">仅管理员可见</div>';
        }
        // 关闭下拉面板
        if (onlineStatusList) onlineStatusList.style.display = 'none';
    }
}

/**
 * 渲染在线管理员列表（下拉面板）
 * @param {Array} list - 在线管理员列表
 */
function renderOnlineList(list) {
    const adminListEl = document.getElementById('dropdown-admin-list');
    if (!adminListEl) return;

    // 管理员焦点切换光标
    const countEl = document.getElementById('online-count');
    if (countEl && list.length > 0) {
        countEl.style.cursor = _adminConnected ? 'pointer' : 'default';
    }

    if (!_adminConnected) {
        adminListEl.innerHTML = '<div style="color:#888;text-align:center;">仅管理员可见</div>';
        return;
    }

    if (list.length === 0) {
        adminListEl.innerHTML = '<div style="color:#999;text-align:center;padding:4px 0;">暂无其他管理员在线</div>';
        return;
    }

    let html = '<div style="font-weight:bold;font-size:11px;color:var(--ink-blue);margin-bottom:4px;">在线管理员:</div>';
    list.forEach(admin => {
        const badge = admin.is_super
            ? '<span style="color:#e74c3c;font-size:10px;margin-left:4px;">超级</span>'
            : '';
        const op = admin.current_operation
            ? '<div style="font-size:10px;color:#f39c12;">正在: ' + escapeHtml(admin.current_operation) + '</div>'
            : '';
        html += '<div style="padding:3px 0;border-bottom:1px solid #f5f5f5;">'
            + '<span style="width:6px;height:6px;border-radius:50%;background:#27ae60;display:inline-block;margin-right:6px;"></span>'
            + '<span>' + escapeHtml(admin.username) + '</span>' + badge
            + op
            + '</div>';
    });
    adminListEl.innerHTML = html;
}

// ==================== 辅助 ====================

/**
 * 显示一个自动消失的管理 toast 通知
 * @param {string} message - 通知文本
 * @param {boolean} [isError=true] - true 为错误风格（红），false 为成功风格（绿）
 */
function showAdminToast(message, isError = true) {
    const el = document.createElement('div');
    const bg = isError ? 'var(--danger-light)' : 'var(--success-light, #d4edda)';
    const color = isError ? 'var(--danger-dark)' : 'var(--success-dark, #155724)';
    const border = isError ? 'var(--danger)' : 'var(--success, #28a745)';
    const offset = document.querySelectorAll('.top-toast').length * 48;
    el.className = 'top-toast';
    el.style.cssText = `
        position: fixed; top: ${12 + offset}px; left: 50%; transform: translateX(-50%); z-index: 1002;
        max-width: min(90vw, 400px);
        background: ${bg}; color: ${color}; border: 2px solid ${border};
        padding: 8px 16px; border-radius: 8px 4px 8px 4px;
        font-size: 14px;
        animation: announceIn 0.35s ease forwards;
        pointer-events: none;
    `;
    el.textContent = (isError ? '\u26A0 ' : '\u2714 ') + message;
    document.body.appendChild(el);

    setTimeout(() => {
        el.style.animation = 'announceOut 0.3s ease forwards';
        el.addEventListener('animationend', () => {
            el.remove();
            repositionToasts();
        }, { once: true });
    }, 5000);
}

/**
 * 重新计算所有顶部 toast 的垂直位置（堆叠用）
 */
function repositionToasts() {
    document.querySelectorAll('.top-toast').forEach((t, i) => {
        t.style.top = (12 + i * 48) + 'px';
        t.style.transition = 'top 0.25s ease';
    });
}

// ==================== 初始化入口 ====================

/**
 * 缓存所有管理面板 DOM 引用
 */
function initAdminDOMRefs() {
    adminPanelOverlay = document.getElementById('admin-panel-overlay');
    btnAdminPanel = document.getElementById('btn-admin-panel');
    btnCloseAdminPanel = document.getElementById('btn-close-admin-panel');
    btnExitAdmin = document.getElementById('btn-exit-admin');
    broadcastInput = document.getElementById('broadcast-input');
    btnSendBroadcast = document.getElementById('btn-send-broadcast');
    broadcastStatus = document.getElementById('broadcast-status');
    btnRefreshSessions = document.getElementById('btn-refresh-sessions');
    searchSessionsInput = document.getElementById('search-sessions-input');
    sessionsList = document.getElementById('sessions-list');
    spectateInfo = document.getElementById('spectate-info');
    spectateDetail = document.getElementById('spectate-detail');
    btnExitSpectate = document.getElementById('btn-exit-spectate');
    tabSessions = document.getElementById('tab-sessions');
    tabReports = document.getElementById('tab-reports');
    tabStickers = document.getElementById('tab-stickers');
    panelSessions = document.getElementById('panel-sessions');
    panelReports = document.getElementById('panel-reports');
    panelStickers = document.getElementById('panel-stickers');
    reportsList = document.getElementById('reports-list');
    reportsPagination = document.getElementById('reports-pagination');
    reportDetailOverlay = document.getElementById('report-detail-overlay');
    reportDetailTitle = document.getElementById('report-detail-title');
    reportDetailContent = document.getElementById('report-detail-content');
    reportDetailChat = document.getElementById('report-detail-chat');
    btnReportDetailClose = document.getElementById('btn-report-detail-close');
    btnReportDetailReviewed = document.getElementById('btn-report-detail-reviewed');
    stickerNameInput = document.getElementById('sticker-name-input');
    stickerUrlInput = document.getElementById('sticker-url-input');
    stickerPreview = document.getElementById('sticker-preview');
    stickerPreviewImg = document.getElementById('sticker-preview-img');
    btnAddSticker = document.getElementById('btn-add-sticker');
    stickerList = document.getElementById('sticker-list');
    stickerListEmpty = document.getElementById('sticker-list-empty');
    btnStickerUpload = document.getElementById('btn-sticker-upload');
    stickerFileInput = document.getElementById('sticker-file-input');
    stickerUploadStatus = document.getElementById('sticker-upload-status');
    stickerLightbox = document.getElementById('sticker-lightbox');
    stickerLightboxImg = document.getElementById('sticker-lightbox-img');
    stickerLightboxClose = document.getElementById('btn-sticker-lightbox-close');
}

/**
 * 绑定所有管理面板事件监听
 */
function initAdminEvents() {
    // 管理面板开关
    if (btnAdminPanel) {
        btnAdminPanel.addEventListener('click', () => openAdminPanel());
    }
    if (btnCloseAdminPanel) {
        btnCloseAdminPanel.addEventListener('click', () => closeAdminPanel());
    }
    if (adminPanelOverlay) {
        adminPanelOverlay.addEventListener('click', (e) => {
            if (e.target === adminPanelOverlay) closeAdminPanel();
        });
    }

    // 退出管理模式
    if (btnExitAdmin) {
        btnExitAdmin.addEventListener('click', () => {
            if (confirm('确定退出管理模式吗？Token 将被清除，需要重新输入密码才能再次进入。')) {
                if (spectateSessionId) {
                    adminSend('admin_unspectate');
                    exitSpectatorView();
                }
                _adminReady = false;
                _adminConnected = false;
                _adminConnecting = false;
                adminToken = '';
                if (typeof delCookie !== 'undefined') {
                    delCookie('turing_admin_token');
                }
                btnAdminPanel.style.display = 'none';
                closeOverlay(adminPanelOverlay);
                if (_wsRetryTimer) {
                    clearTimeout(_wsRetryTimer);
                    _wsRetryTimer = null;
                }
                _wsRetryCount = 0;
                if (adminTransport) {
                    try { adminTransport.disconnect(); } catch (e) { /* ignore */ }
                    adminTransport = null;
                }
                updateOnlineStatusBar();
                const banBtn = document.getElementById('btn-admin-ban');
                if (banBtn) banBtn.remove();
            }
        });
    }

    // 全服公告
    if (btnSendBroadcast) {
        btnSendBroadcast.addEventListener('click', () => sendBroadcast());
    }
    if (broadcastInput) {
        broadcastInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && btnSendBroadcast) btnSendBroadcast.click();
        });
    }

    // 对局列表
    if (btnRefreshSessions) {
        btnRefreshSessions.addEventListener('click', () => refreshSessions());
    }
    if (searchSessionsInput) {
        searchSessionsInput.addEventListener('input', () => {
            _sessionSearchKeyword = searchSessionsInput.value;
            _doRenderSessionsList();
        });
    }

    // 退出旁观
    if (btnExitSpectate) {
        btnExitSpectate.addEventListener('click', () => {
            if (spectateSessionId) adminSend('admin_unspectate');
            exitSpectatorView();
        });
    }

    // 标签切换
    if (tabSessions) {
        tabSessions.addEventListener('click', () => switchAdminTab('sessions'));
    }
    if (tabReports) {
        tabReports.addEventListener('click', () => switchAdminTab('reports'));
    }
    if (tabStickers) {
        tabStickers.addEventListener('click', () => switchAdminTab('stickers'));
    }

    // 举报审核筛选按钮
    document.querySelectorAll('.report-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.report-filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _reportsFilter = btn.dataset.filter;
            loadReports(1);
        });
    });

    // 举报详情关闭
    if (btnReportDetailClose) {
        btnReportDetailClose.addEventListener('click', () => {
            reportDetailOverlay.style.display = 'none';
            _currentDetailReportId = null;
        });
    }
    if (reportDetailOverlay) {
        reportDetailOverlay.addEventListener('click', (e) => {
            if (e.target === reportDetailOverlay) {
                reportDetailOverlay.style.display = 'none';
                _currentDetailReportId = null;
            }
        });
    }

    // 举报标记已审
    if (btnReportDetailReviewed) {
        btnReportDetailReviewed.addEventListener('click', () => {
            if (!_currentDetailReportId) return;
            if (!_adminConnected) return;
            adminSend('admin_mark_reviewed', { report_id: _currentDetailReportId });
        });
    }

    // 添加表情
    if (btnAddSticker) {
        btnAddSticker.addEventListener('click', () => {
            const name = stickerNameInput ? stickerNameInput.value.trim() : '';
            const url = stickerUrlInput ? stickerUrlInput.value.trim() : '';
            if (name.length > 20) { alert('表情名称最多20个字符'); return; }
            if (!/^https?:\/\/.+\.(png|jpg|jpeg|gif|webp|svg)(\?.*)?$/i.test(url)) {
                alert('请输入有效的图片 URL（支持 png/jpg/gif/webp/svg）');
                return;
            }
            adminSend('admin_sticker_add', { name: name, url: url });
        });
    }

    // 表情预览
    if (stickerUrlInput) {
        stickerUrlInput.addEventListener('input', () => {
            const url = stickerUrlInput.value.trim();
            if (url && stickerPreview && stickerPreviewImg) {
                stickerPreviewImg.src = url;
                stickerPreviewImg.onerror = () => { stickerPreview.style.display = 'none'; };
                stickerPreviewImg.onload = () => { stickerPreview.style.display = 'block'; };
            } else if (stickerPreview) {
                stickerPreview.style.display = 'none';
            }
        });
    }

    // 表情删除（委托）
    if (stickerList) {
        stickerList.addEventListener('click', (e) => {
            const btn = e.target.closest('.sticker-delete');
            if (!btn) return;
            const id = btn.dataset.stickerId;
            if (!id) return;
            if (!confirm('确定删除这个表情吗？')) return;
            adminSend('admin_sticker_delete', { id: id });
        });
    }

    // 图片上传
    if (btnStickerUpload && stickerFileInput) {
        btnStickerUpload.addEventListener('click', () => {
            stickerFileInput.click();
        });

        stickerFileInput.addEventListener('change', async () => {
            const file = stickerFileInput.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                alert('图片大小不能超过 10MB');
                stickerFileInput.value = '';
                return;
            }

            if (stickerUploadStatus) {
                stickerUploadStatus.style.display = 'block';
                stickerUploadStatus.textContent = '上传中...';
                stickerUploadStatus.style.color = '#888';
            }

            try {
                if (!uploadConfig || !uploadConfig.upload_url) {
                    if (stickerUploadStatus) {
                        stickerUploadStatus.textContent = '上传配置未加载';
                        stickerUploadStatus.style.color = '#f44336';
                    }
                    return;
                }

                const formData = new FormData();
                formData.append('backstage', uploadConfig.backstage);
                formData.append('appid', uploadConfig.appid);
                formData.append('key', uploadConfig.key);
                formData.append('file', file);

                const headers = {};
                if (uploadConfig.request_script) {
                    try {
                        const fn = new Function('cfg', uploadConfig.request_script);
                        fn({ upload_url: uploadConfig.upload_url, headers: headers, formData: formData });
                    } catch (e) {
                        if (stickerUploadStatus) {
                            stickerUploadStatus.textContent = '鉴权脚本执行失败: ' + e.message;
                            stickerUploadStatus.style.color = '#f44336';
                        }
                        return;
                    }
                }

                const resp = await fetch(uploadConfig.upload_url, {
                    method: 'POST',
                    headers: headers,
                    body: formData,
                });
                const result = await resp.json();

                const success = (typeof resolvePath === 'function')
                    ? resolvePath(result, uploadConfig.success_field || 'code')
                    : result[uploadConfig.success_field || 'code'];
                const successValue = uploadConfig.success_value !== undefined ? uploadConfig.success_value : 1;
                const url = (typeof resolvePath === 'function')
                    ? resolvePath(result, uploadConfig.url_field || 'url')
                    : result[uploadConfig.url_field || 'url'];

                if (success === successValue && url) {
                    if (stickerUrlInput) {
                        stickerUrlInput.value = url;
                        stickerUrlInput.dispatchEvent(new Event('input'));
                    }
                    if (stickerUploadStatus) {
                        stickerUploadStatus.textContent = '上传成功';
                        stickerUploadStatus.style.color = '#4caf50';
                        setTimeout(() => { stickerUploadStatus.style.display = 'none'; }, 4000);
                    }
                } else {
                    const errMsg = (typeof resolvePath === 'function')
                        ? resolvePath(result, uploadConfig.error_field || 'msg')
                        : result[uploadConfig.error_field || 'msg'];
                    if (stickerUploadStatus) {
                        stickerUploadStatus.textContent = '上传失败: ' + (errMsg || '未知错误');
                        stickerUploadStatus.style.color = '#f44336';
                    }
                }
            } catch (e) {
                if (stickerUploadStatus) {
                    stickerUploadStatus.textContent = '上传出错: ' + e.message;
                    stickerUploadStatus.style.color = '#f44336';
                }
            } finally {
                stickerFileInput.value = '';
            }
        });
    }

    // 大图预览关闭
    if (stickerLightbox && stickerLightboxClose) {
        stickerLightboxClose.addEventListener('click', () => {
            stickerLightbox.style.display = 'none';
        });
        const lightboxBg = stickerLightbox.querySelector('.sticker-lightbox-bg');
        if (lightboxBg) {
            lightboxBg.addEventListener('click', () => {
                stickerLightbox.style.display = 'none';
            });
        }
    }

    // 管理员管理 - 延迟绑定（面板动态创建）
    bindAdminManagementEvents();

    // 日志筛选按钮 - 延迟绑定
    bindLogFilterEvents();
}

/**
 * 绑定管理员管理面板事件（添加管理员、修改自己密码）。
 * 面板动态创建后才可调用，使用 _bound 标记避免重复绑定。
 */
function bindAdminManagementEvents() {
    const btnAddAdmin = document.getElementById('btn-add-admin');
    if (btnAddAdmin && !btnAddAdmin._bound) {
        btnAddAdmin._bound = true;
        btnAddAdmin.addEventListener('click', () => {
            const usernameInput = document.getElementById('admin-add-username');
            const passwordInput = document.getElementById('admin-add-password');
            const errEl = document.getElementById('admin-add-error');
            const username = usernameInput ? usernameInput.value.trim() : '';
            const password = passwordInput ? passwordInput.value : '';

            if (!username) {
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入用户名'; }
                return;
            }
            if (!password) {
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入密码'; }
                return;
            }
            if (errEl) errEl.style.display = 'none';
            adminSend('admin_add', { username: username, password: password });
        });
    }

    const btnChangeOwnPwd = document.getElementById('btn-change-own-password');
    if (btnChangeOwnPwd && !btnChangeOwnPwd._bound) {
        btnChangeOwnPwd._bound = true;
        btnChangeOwnPwd.addEventListener('click', () => {
            const oldPwd = document.getElementById('admin-own-password-old');
            const newPwd = document.getElementById('admin-own-password-new');
            const errEl = document.getElementById('admin-own-password-error');
            const oldVal = oldPwd ? oldPwd.value : '';
            const newVal = newPwd ? newPwd.value : '';

            if (!oldVal) {
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入当前密码'; }
                return;
            }
            if (!newVal) {
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = '请输入新密码'; }
                return;
            }
            if (errEl) errEl.style.display = 'none';
            adminSend('admin_own_password', { old_password: oldVal, new_password: newVal });
        });
    }
}

/**
 * 绑定日志筛选按钮事件（面板动态创建后调用）。
 * 使用 _logBound 标记避免重复绑定。
 */
function bindLogFilterEvents() {
    document.querySelectorAll('.log-filter-btn').forEach(btn => {
        if (btn._logBound) return;
        btn._logBound = true;
        btn.addEventListener('click', () => {
            document.querySelectorAll('.log-filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadLogs(1);
        });
    });
}

// ==================== 初始化 ====================

/**
 * 管理员前端主初始化入口。
 * - 缓存 DOM 引用
 * - 绑定事件
 * - 初始化在线状态
 * - 管理入口页无 token → 连接管理 WS 等待登录
 * - 有缓存 token → 通过游戏 WS 验证后开启管理模式
 */
function initAdmin() {
    initAdminDOMRefs();
    initAdminEvents();
    initOnlineStatus();

    // 管理入口页：无 token 时连接管理 WS 以接收 need_admin_login
    if (window.__ADMIN_CONFIG__ && window.__ADMIN_CONFIG__.ws_url && !adminToken) {
        connectAdminWS(window.__ADMIN_CONFIG__.ws_url);
    }

    // 有缓存 token：通过游戏 WS 验证并开启管理模式
    if (adminToken) {
        btnAdminPanel.style.display = 'inline-flex';
        tryAdminFromCache();
    }
}

// 在 DOM 就绪后初始
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdmin);
} else {
    initAdmin();
}