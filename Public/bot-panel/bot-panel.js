/* ==================== BOT 管理面板（复用玩家登录态 token） ==================== */
(function () {
    'use strict';

    // 元素存在性保护：任一关键元素缺失则中止
    var need = ['panel-body', 'nick-input', 'info-status', 'info-created',
        'bot-key-value', 'btn-copy-key', 'btn-rotate-key',
        'btn-save-nick', 'btn-back', 'loading-tip', 'unbound-tip', 'unbound-text'];
    for (var i = 0; i < need.length; i++) {
        if (!document.getElementById(need[i])) {
            console.error('[BOT面板] 缺少元素: ' + need[i]);
            return;
        }
    }

    // 复用玩家登录态 token（与其他玩法一致）
    var token = '';
    try { token = getUserToken(); } catch (e) { }
    if (!token) {
        // 未登录 → 跳首页登录
        location.href = '/';
        return;
    }

    /** 玩家 token 鉴权请求头 */
    function authHeaders() {
        return { 'Authorization': 'Bearer ' + token };
    }

    /** 复制文本到剪贴板（含降级方案） */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; })
                .catch(function () { return false; });
        }
        return new Promise(function (resolve) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            resolve(ok);
        });
    }

    function showPanel(data) {
        document.getElementById('loading-tip').style.display = 'none';
        document.getElementById('unbound-tip').classList.add('hidden');
        document.getElementById('panel-body').classList.remove('hidden');

        document.getElementById('nick-input').value = data.nickname || '';
        document.getElementById('info-status').textContent = data.status_text || '';
        document.getElementById('info-created').textContent = data.created_at || '';
        document.getElementById('bot-key-value').textContent = data.bot_key || '-';
    }

    function showUnbound(message) {
        document.getElementById('loading-tip').style.display = 'none';
        document.getElementById('panel-body').classList.add('hidden');
        document.getElementById('unbound-tip').classList.remove('hidden');
        document.getElementById('unbound-text').textContent = message || '该账号尚未绑定 BOT';
    }

    function load() {
        document.getElementById('loading-tip').style.display = 'block';
        fetch('/api/bot/panel', { headers: authHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { showPanel(data); return; }
                // token 失效 → 重新登录
                if (String(data.error || '').indexOf('token') >= 0) {
                    location.href = '/';
                    return;
                }
                showUnbound(data.error || '该账号尚未绑定 BOT');
            })
            .catch(function () {
                document.getElementById('loading-tip').style.display = 'none';
                showTopToast('网络错误，请重试', true);
            });
    }

    function refresh() {
        load();
    }

    // 事件绑定
    document.getElementById('btn-back').addEventListener('click', function () { location.href = '/'; });

    // 复制 KEY
    document.getElementById('btn-copy-key').addEventListener('click', function () {
        var key = document.getElementById('bot-key-value').textContent;
        if (!key || key === '-') { showTopToast('暂无 KEY', true); return; }
        copyText(key).then(function (ok) {
            showTopToast(ok ? 'KEY 已复制' : '复制失败，请手动复制', !ok);
        });
    });

    // 轮换 KEY
    document.getElementById('btn-rotate-key').addEventListener('click', function () {
        if (!window.confirm('确认轮换 KEY？轮换后旧 KEY 即刻失效，BOT 下次连接需使用新 KEY。')) return;
        var btn = document.getElementById('btn-rotate-key');
        btn.disabled = true;
        fetch('/api/bot/panel/key', { method: 'POST', headers: authHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    document.getElementById('bot-key-value').textContent = data.bot_key;
                    showTopToast('KEY 已轮换，请复制新 KEY', false);
                } else {
                    showTopToast(data.error || '轮换失败', true);
                }
            })
            .catch(function () { btn.disabled = false; showTopToast('网络错误', true); });
    });

    document.getElementById('btn-save-nick').addEventListener('click', function () {
        var nick = document.getElementById('nick-input').value.trim();
        if (!nick) { showTopToast('请输入昵称', true); return; }
        var btn = document.getElementById('btn-save-nick');
        btn.disabled = true;
        fetch('/api/bot/panel/nickname', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: JSON.stringify({ nickname: nick })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    showTopToast('昵称已保存，下次连接生效', false);
                    refresh();
                } else {
                    showTopToast(data.error || '保存失败', true);
                }
            })
            .catch(function () { btn.disabled = false; showTopToast('网络错误', true); });
    });

    load();
})();
