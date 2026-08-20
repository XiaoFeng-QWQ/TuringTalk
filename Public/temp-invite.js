/* ==================== 临时聊天邀请（全站通用：搜索面板 + 首页轻量WS收邀请） ==================== */
window.TempInvite = (function () {
    'use strict';

    let opts = { autoConnect: true };
    let ws = null;
    let toastEl = null;
    let panelEl = null;

    function escapeHtml(text) {
        let div = document.createElement('div');
        div.textContent = String(text == null ? '' : text);
        return div.innerHTML;
    }

    function escapeHtmlAttr(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function toast(text) {
        let el = document.createElement('div');
        el.className = 'temp-invite-flash';
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s';
            el.style.opacity = '0';
            setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 320);
        }, 2600);
    }

    // ==================== 首页轻量 WS（收邀请/结果；autoConnect=false 时页面自行转发消息给 handleMessage） ====================
    function connect() {
        if (!opts.autoConnect || ws) return;
        try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/tempchat'); } catch (e) { return; }
        ws.onopen = function () {
            let msg = { type: 'temp_join', nickname: '游客' + Math.random().toString(36).slice(2, 6) };
            try { if (getUserToken()) msg.player_token = getUserToken(); } catch (e) { }
            ws.send(JSON.stringify(msg));
        };
        ws.onmessage = function (ev) {
            let data;
            try { data = JSON.parse(ev.data); } catch (e) { return; }
            handleMessage(data);
        };
        ws.onclose = function () {
            ws = null;
            // 轻量连接断线后重连（除非页面卸载）
            setTimeout(function () { if (opts.autoConnect) connect(); }, 5000);
        };
        ws.onerror = function () { };
    }

    // ==================== 消息处理（页面 WS 收到 temp_* 消息时转发到这里） ====================
    function handleMessage(data) {
        if (!data || !data.type) return;
        switch (data.type) {
            case 'temp_invite':
                showInviteToast(data);
                break;
            case 'temp_invite_expired':
                hideInviteToast();
                toast(data.text || '邀请已过期');
                break;
            case 'temp_invite_result':
                hideInviteToast();
                toast(data.ok ? '对方接受了邀请' : (data.error || '邀请失败'));
                break;
            case 'temp_room_created':
                // 邀请方：收到房间接管通知 → 跳转临时聊天页
                if (data.room_id && data.pending_join) {
                    let nick = '';
                    try { nick = getUserNickname(); } catch (e) { }
                    location.href = '/temp-chat?room=' + encodeURIComponent(data.room_id) + '&nick=' + encodeURIComponent(nick || '');
                }
                break;
        }
    }

    // ==================== 收到邀请提示 ====================
    function showInviteToast(data) {
        hideInviteToast();
        toastEl = document.createElement('div');
        toastEl.id = 'temp-invite-toast';
        toastEl.className = 'temp-invite-toast';
        toastEl.innerHTML =
            '<span class="temp-invite-toast-text">' + escapeHtml(data.from_name || '') + ' 邀请你进入临时聊天</span>' +
            '<button class="temp-invite-btn-yes">同意</button>' +
            '<button class="temp-invite-btn-no">拒绝</button>';
        document.body.appendChild(toastEl);
        toastEl.dataset.inviteId = data.invite_id || '';
        toastEl.querySelector('.temp-invite-btn-yes').addEventListener('click', function () {
            let iid = toastEl.dataset.inviteId;
            hideInviteToast();
            location.href = '/temp-chat?invite=' + encodeURIComponent(iid);
        });
        toastEl.querySelector('.temp-invite-btn-no').addEventListener('click', function () {
            let iid = toastEl.dataset.inviteId;
            hideInviteToast();
            // 原地拒绝（HTTP API），不跳转临时聊天页
            let headers = { 'Content-Type': 'application/json' };
            let body = { invite_id: iid };
            try {
                let t = getUserToken();
                if (t) headers.Authorization = 'Bearer ' + t;
            } catch (e) { }
            fetch('/api/temp/invite/decline', { method: 'POST', headers: headers, body: JSON.stringify(body) })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) toast(data.error || '拒绝失败');
                })
                .catch(function () { });
        });
        toastEl._t = setTimeout(function () {
            if (toastEl && toastEl.parentNode) { toastEl.parentNode.removeChild(toastEl); toastEl = null; }
            toast('邀请已过期');
        }, (data.timeout || 60) * 1000);
    }

    function hideInviteToast() {
        if (toastEl) { clearTimeout(toastEl._t); if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl); toastEl = null; }
    }

    // ==================== 搜索邀请面板 ====================
    function openPanel() {
        if (panelEl) { panelEl.style.display = 'flex'; return; }
        panelEl = document.createElement('div');
        panelEl.id = 'temp-invite-panel';
        panelEl.className = 'temp-invite-panel';
        panelEl.innerHTML =
            '<div class="clipboard-drawer">' +
            '<div class="paper-content doodle-border">' +
            '<div class="drawer-header">' +
            '<span class="temp-invite-panel-title">邀请好友临时聊天</span>' +
            '<button id="temp-invite-panel-close" class="temp-invite-panel-close">&times;</button></div>' +
            '<div class="temp-invite-panel-searchbar">' +
            '<input id="temp-invite-panel-input" type="text" maxlength="20" placeholder="输入昵称搜索在线用户" autocomplete="off" class="temp-invite-panel-input">' +
            '<button id="temp-invite-panel-search" class="temp-invite-panel-searchbtn">搜索</button></div>' +
            '<div class="temp-invite-panel-hint">输入昵称点击搜索；留空查看全部用户。在线用户可邀请，离线/繁忙用户无法邀请。</div>' +
            '<div id="temp-invite-panel-results"></div>' +
            '</div></div>';
        document.body.appendChild(panelEl);

        panelEl.addEventListener('click', function (e) { if (e.target === panelEl) closePanel(); });
        document.getElementById('temp-invite-panel-close').addEventListener('click', closePanel);
        document.getElementById('temp-invite-panel-search').addEventListener('click', search);
        document.getElementById('temp-invite-panel-input').addEventListener('keydown', function (e) { if (e.key === 'Enter') search(); });
        document.getElementById('temp-invite-panel-input').focus();
    }

    function closePanel() {
        if (!panelEl) return;
        // 关闭动画（与主站弹层一致：遮罩淡出 + 抽屉缩放淡出）
        var drawer = panelEl.querySelector('.clipboard-drawer');
        panelEl.style.animation = 'fadeOut 0.2s ease forwards';
        if (drawer) drawer.style.animation = 'fadeScaleOut 0.2s ease forwards';
        setTimeout(function () {
            if (panelEl && panelEl.parentNode) panelEl.parentNode.removeChild(panelEl);
            panelEl = null;
        }, 200);
    }

    function search() {
        let kw = document.getElementById('temp-invite-panel-input').value.trim();
        let headers = {};
        try { if (getUserToken()) headers.Authorization = 'Bearer ' + getUserToken(); } catch (e) { }
        fetch('/api/temp/users?keyword=' + encodeURIComponent(kw), { headers: headers })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderResults((data && data.users) || []);
            })
            .catch(function () { toast('搜索失败，网络错误'); });
    }

    function renderResults(users) {
        let box = document.getElementById('temp-invite-panel-results');
        if (!users.length) { box.innerHTML = '<div class="temp-invite-empty">没有找到用户</div>'; return; }
        let html = '';
        for (let i = 0; i < users.length; i++) {
            let u = users[i];
            let online = u.status === 'online';
            let statusText = u.status === 'online' ? '空闲' : (u.status === 'offline' ? '离线' : '繁忙');
            let statusClass = u.status === 'online' ? 'online' : 'busy';
            html += '<div class="temp-invite-user">' +
                '<span class="temp-invite-user-avatar">' + escapeHtml((u.nickname || '?').charAt(0)) + '</span>' +
                '<span class="temp-invite-user-name">' + escapeHtml(u.nickname) + '</span>' +
                '<span class="temp-invite-user-status ' + statusClass + '">' + statusText + '</span>' +
                (online
                    ? '<button data-pid="' + escapeHtmlAttr(u.player_id) + '" class="temp-invite-user-invite">邀请</button>'
                    : '<button disabled class="temp-invite-user-disabled">' + statusText + '</button>') +
                '</div>';
        }
        box.innerHTML = html;
        let btns = box.querySelectorAll('button[data-pid]');
        for (let i = 0; i < btns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    let pid = btn.getAttribute('data-pid');
                    let headers = { 'Content-Type': 'application/json' };
                    let body = { target_player_id: pid };
                    try {
                        let t = getUserToken();
                        if (t) headers.Authorization = 'Bearer ' + t;
                        try { body.from_name = getUserNickname(); } catch (e) { }
                    } catch (e) { }
                    fetch('/api/temp/invite', { method: 'POST', headers: headers, body: JSON.stringify(body) })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.success) { toast('邀请已发送，等待对方回应…'); closePanel(); }
                            else { toast(data.error || '邀请失败'); }
                        })
                        .catch(function () { toast('邀请失败，网络错误'); });
                });
            })(btns[i]);
        }
    }

    // ==================== 初始化 ====================
    function init(cfg) {
        if (cfg) opts = Object.assign(opts, cfg || {});
        connect();
    }

    return {
        init: init,
        openPanel: openPanel,
        closePanel: closePanel,
        handleMessage: handleMessage,
    };
})();
