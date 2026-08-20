/**
 * 共享工具：顶部 toast 通知 + 用户数据存储（script.js / whoisai_script.js 共用）
 */

// 清理历史残留：某旧版本曾把主题数据写入 key="undefined"
try { if (localStorage.getItem('undefined')) localStorage.removeItem('undefined'); } catch (e) { }

// ---- Toast ----

function showTopToast(message, isError = true) {
    const el = document.createElement('div');
    const bg = isError ? '#ffe0e0' : '#d4edda';
    const color = isError ? '#c0392b' : '#155724';
    const border = isError ? '#e74c3c' : '#28a745';
    const offset = document.querySelectorAll('.top-toast').length * 48;
    
    el.className = 'top-toast';
    el.style.cssText = `
        position: fixed; 
        top: ${12 + offset}px; 
        left: 50%;
        transform: translateX(-50%) translateY(-120%) scale(0.95);
        z-index: 1002;
        max-width: min(90vw, 400px);
        background: ${bg};
        color: ${color};
        border: 2px solid ${border};
        padding: 8px 16px;
        border-radius: 8px 4px 8px 4px;
        font-size: 14px;
        opacity: 0;
        pointer-events: none;
        transition: top 0.25s ease;
    `;
    el.textContent = (isError ? '\u26A0 ' : '\u2714 ') + message;
    document.body.appendChild(el);

    requestAnimationFrame(() => {
        el.style.animation = 'announceIn 0.35s ease forwards';
    });

    setTimeout(() => {
        el.style.animation = 'announceOut 0.3s ease forwards';
        el.addEventListener('animationend', () => {
            el.remove();
            repositionToasts();
        }, { once: true });
    }, 5000);
}

function repositionToasts() {
    const all = document.querySelectorAll('.top-toast');
    all.forEach((t, i) => {
        t.style.top = (12 + i * 48) + 'px';
        t.style.transition = 'top 0.25s ease';
    });
}

// --- 全服公告横幅 ---
const ANNOUNCE_DISPLAY_MS = 5000;
const ANNOUNCE_MAX = 3;

let announceQueue = [];
let announceShowing = 0;

function showDanmaku(text, label = '全服公告', durationSec = 0) {
    announceQueue.push({ text, label, durationSec });
    dequeueAnnounce();
}

function dequeueAnnounce() {
    while (announceQueue.length > 0 && announceShowing < ANNOUNCE_MAX) {
        const item = announceQueue.shift();
        const text = item.text || item;
        const label = item.label || '全服公告';
        const ms = item.durationSec === Infinity
            ? Infinity
            : (item.durationSec > 0 ? item.durationSec * 1000 : ANNOUNCE_DISPLAY_MS);
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
        const area = document.getElementById('announcement-area');
        if (!area) { announceShowing--; return; }
        area.appendChild(banner);

        const dismiss = () => {
            if (!banner.parentNode) return;
            banner.classList.add('ann-leaving');
            banner.addEventListener('animationend', () => {
                banner.remove();
                announceShowing--;
                dequeueAnnounce();
            }, { once: true });
        };

        if (ms !== Infinity) {
            setTimeout(dismiss, ms);
        }
    }
}

// ================================================================
// 主题管理（默认 / 暗色 / 跟随系统）
// ================================================================

function getStoredTheme() {
    // 迁移旧格式：独立的 'theme' key → userdata
    const oldTheme = localStorage.getItem('theme');
    if (oldTheme) {
        const d = getUserdata();
        if (!d.theme) { d.theme = oldTheme; saveUserdata(d); }
        localStorage.removeItem('theme');
    }
    return getUserdata().theme || 'default';
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

function setTheme(theme) {
    const d = getUserdata();
    d.theme = theme;
    saveUserdata(d);
    applyTheme(theme);
    updateThemeIcon(theme);
}

// 尽早应用已保存主题，避免闪烁
applyTheme(getStoredTheme());

// 系统主题变化时自动跟随（仅 system 模式生效）
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (getStoredTheme() === 'system') {
        applyTheme('system');
    }
});

// DOM 就绪后绑定按钮交互
document.addEventListener('DOMContentLoaded', () => {
    // 重新应用主题（兜底，防止其他脚本覆盖后未恢复）
    applyTheme(getStoredTheme());

    const themeBtn = document.getElementById('btn-theme');
    if (!themeBtn) return;

    updateThemeIcon(getStoredTheme());

    const themeCycle = ['default', 'dark', 'system'];
    themeBtn.addEventListener('click', () => {
        const current = getStoredTheme();
        const idx = themeCycle.indexOf(current);
        const next = themeCycle[(idx + 1) % themeCycle.length];
        setTheme(next);
    });
});

function updateThemeIcon(theme) {
    const themeBtn = document.getElementById('btn-theme');
    if (!themeBtn) return;
    const svg = themeBtn.querySelector('svg');
    if (!svg) return;

    if (theme === 'dark') {
        svg.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
    } else if (theme === 'system') {
        svg.innerHTML = '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>';
    } else {
        svg.innerHTML = '<circle cx="12" cy="12" r="5"/><path d="M12 1v2"/><path d="M12 21v2"/><path d="M4.22 4.22l1.42 1.42"/><path d="M18.36 18.36l1.42 1.42"/><path d="M1 12h2"/><path d="M21 12h2"/><path d="M4.22 19.78l1.42-1.42"/><path d="M18.36 5.64l1.42-1.42"/>';
    }
}

// ---- 用户数据存储 ----

let USERDATA_KEY = 'UserData';

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

function getUserNickname() { return getUserdata().nickname || ''; }
function setUserNickname(name) { const d = getUserdata(); d.nickname = name; saveUserdata(d); }
function getUserToken() { return getUserdata().token || ''; }
function setUserToken(token) { const d = getUserdata(); d.token = token; saveUserdata(d); }
function getUserRecoveryCode() { return getUserToken(); }
function setUserRecoveryCode(code) { setUserToken(code); }

/**
 * 自动将旧用户数据（有 recovery_code 但无 token）升级为新格式。
 * 旧玩家的恢复码 = 密码（迁移脚本已用 bcrypt(恢复码) 存入 password_hash）。
 */
async function autoUpgradeOldUserdata() {
    const d = getUserdata();
    if (!d || !d.recovery_code || d.token) return; // 不需升级
    const nickname = d.nickname;
    if (!nickname) return;
    try {
        const resp = await fetch('/api/generate-player-id?action=recover&nickname='
            + encodeURIComponent(nickname) + '&password=' + encodeURIComponent(d.recovery_code)
            + '&fp=' + encodeURIComponent(getFingerprint()));
        const data = await resp.json();
        if (data.error) return;
        // 升级成功：保存 token，删除旧 recovery_code
        d.token = data.token;
        delete d.recovery_code;
        saveUserdata(d);
        console.log('[自动升级] 旧用户数据已升级为新格式');
    } catch (_) { /* 静默失败，下次重试 */ }
}

function getStickerFavorites() {
    return getUserdata().stickerFavorites || [];
}
function setStickerFavorites(ids) {
    const d = getUserdata();
    d.stickerFavorites = ids;
    saveUserdata(d);
}
function toggleStickerFavorite(id) {
    const favs = getStickerFavorites();
    const idx = favs.indexOf(id);
    if (idx === -1) {
        favs.push(id);
    } else {
        favs.splice(idx, 1);
    }
    setStickerFavorites(favs);
    return idx === -1;
}

// ---- Sticker 跨页面缓存（供 whoisai 等子页面复用 stickerMap） ----
let STICKER_CACHE_KEY = 'sticker_cache';
let STICKER_VERSION_KEY = 'sticker_cache_version';

function saveStickerCache(map, version) {
    try {
        localStorage.setItem(STICKER_CACHE_KEY, JSON.stringify(map));
        if (version !== undefined) {
            localStorage.setItem(STICKER_VERSION_KEY, String(version));
        }
    } catch (_) { }
}

function loadStickerCache() {
    try {
        let raw = localStorage.getItem(STICKER_CACHE_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (_) { return {}; }
}

function getStickerCacheVersion() {
    try {
        let v = localStorage.getItem(STICKER_VERSION_KEY);
        return v ? parseInt(v, 10) : 0;
    } catch (_) { return 0; }
}

function handleStickersList(data) {
    let map = {};
    if (data.stickers) {
        data.stickers.forEach((s) => {
            map[s.id] = { name: s.name, url: s.url, source: s.source || 'default', status: s.status || 'approved' };
        });
    }
    saveStickerCache(map, data.version || 0);

    // 清理已不存在的收藏 ID：后台可能删除了某些表情，
    // 但 UserData.stickerFavorites 还保留着旧 ID，导致收藏标签页显示为空
    let favs = getStickerFavorites();
    if (favs.length > 0) {
        let cleaned = favs.filter((id) => { return map.hasOwnProperty(id); });
        if (cleaned.length !== favs.length) {
            setStickerFavorites(cleaned);
        }
    }

    return map;
}

function renderSharedStickerPicker(bodyEl, stickerMap, onClickSticker) {
    let keys = stickerMap ? Object.keys(stickerMap) : [];
    if (keys.length === 0) {
        let cached = loadStickerCache();
        if (Object.keys(cached).length > 0) stickerMap = cached;
    }

    let favs = getStickerFavorites();
    if (!Array.isArray(favs)) favs = [];

    let activeTab = bodyEl.dataset.tab || 'mine';
    let ids = Object.keys(stickerMap || {});

    if (activeTab === 'mine') {
        ids = ids.filter((id) => { return stickerMap[id].source === 'mine' && stickerMap[id].status === 'approved'; });
    } else if (activeTab === 'default') {
        ids = ids.filter((id) => { return stickerMap[id].source === 'default'; });
    }

    bodyEl.innerHTML = '';

    if (ids.length === 0) {
        bodyEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px;font-size:13px;">' +
            (activeTab === 'mine' ? '你还没有上传表情' : '暂无默认表情') + '</div>';
        return;
    }

    // 一次性构建 HTML 再注入（避免逐个 appendChild 触发多次 reflow，表情多时卡顿）
    let html = '';
    ids.forEach((id) => {
        let s = stickerMap[id];
        let favCls = favs.indexOf(id) !== -1 ? ' favorited' : '';
        html += '<div class="sticker-picker-item' + favCls + '" data-sid="' + escapeHtmlAttr(id) + '" title="' + escapeHtmlAttr(s.name) + '">' +
            '<img src="' + escapeHtmlAttr(s.url) + '" alt="' + escapeHtmlAttr(s.name) + '" loading="lazy">' +
            '</div>';
    });
    bodyEl.innerHTML = html;

    // 事件委托：点击/右键/图片错误 统一处理（避免为每个表情绑定监听器）
    let sidMap = stickerMap;
    let onItemClick = function (ev) {
        let item = ev.target && ev.target.closest ? ev.target.closest('.sticker-picker-item') : null;
        if (!item || !bodyEl.contains(item)) return;
        let id = item.getAttribute('data-sid');
        if (id && sidMap[id]) {
            onClickSticker(id, sidMap[id]);
        }
    };
    let onItemCtx = function (ev) {
        let item = ev.target && ev.target.closest ? ev.target.closest('.sticker-picker-item') : null;
        if (item && bodyEl.contains(item)) ev.preventDefault();
    };
    let onItemSelect = function (ev) { ev.preventDefault(); };
    // 全局事件委托（每次渲染只绑定一次到 bodyEl 自身；用标志防重复绑定）
    if (!bodyEl.dataset.pickerDelegate) {
        bodyEl.dataset.pickerDelegate = '1';
        bodyEl.addEventListener('click', onItemClick);
        bodyEl.addEventListener('contextmenu', onItemCtx);
        bodyEl.addEventListener('selectstart', onItemSelect);
    }
    // 图片加载失败：移除该项（用事件委托监听 error 冒泡）
    bodyEl.addEventListener('error', function (ev) {
        let item = ev.target && ev.target.closest ? ev.target.closest('.sticker-picker-item') : null;
        if (item && bodyEl.contains(item)) item.remove();
    }, true);
}

// 统一解析表情 URL：优先用服务端下发的 url，回退查本地 stickerMap
function resolveStickerUrl(stickerId, serverUrl, stickerMap) {
    return serverUrl || (stickerMap && stickerMap[stickerId] ? stickerMap[stickerId].url : '');
}

// 表情面板 tab 切换（由各页面绑定）
function bindStickerPickerTabs(pickerId, renderFn, repositionFn) {
    let picker = document.getElementById(pickerId);
    if (!picker) return;
    let tabs = picker.querySelectorAll('.sticker-picker-tab');
    tabs.forEach((tab) => {
        tab.addEventListener('click', function () {
            let body = picker.querySelector('.sticker-picker-body');
            tabs.forEach((t) => { t.classList.remove('active'); });
            this.classList.add('active');
            body.dataset.tab = this.dataset.tab;
            if (renderFn) renderFn();
            if (repositionFn) repositionFn();
        });
    });
}

function escapeHtmlAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/\"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;');
}

// ---- 浏览器指纹 ----
//
// 使用 FingerprintJS v3 生成稳定指纹，旧算法作为回退
//

// 回退算法（FingerprintJS 不可用时使用）
function generateFingerprint() {
    let data = [
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
        let chr = data.charCodeAt(i);
        hash = ((hash << 5) - hash) + chr;
        hash |= 0;
    }
    return Math.abs(hash).toString(36);
}

let _fingerprint = generateFingerprint(); // 先用回退值，FingerprintJS 完成后会更新

// 异步初始化 FingerprintJS
(function initFingerprintJS() {
    if (typeof FingerprintJS === 'undefined') return;

    FingerprintJS.load()
        .then((fp) => { return fp.get(); })
        .then((result) => {
            if (result && result.visitorId) {
                _fingerprint = result.visitorId;
                if (typeof window.onFingerprintReady === 'function') {
                    window.onFingerprintReady(_fingerprint);
                }
            }
        })
        .catch(() => {
            // FingerprintJS 失败，保持回退值
        });
})();

function getFingerprint() {
    return _fingerprint;
}

// ================================================================
// OAuth 快捷登录（各页面共用）
// ================================================================

/**
 * 获取服务端已配置的 OAuth provider 列表：[{key, name}, ...]
 */
function getOAuthProviders() {
    return fetch('/api/oauth/providers')
        .then(function (r) { return r.json(); })
        .catch(function () { return []; });
}

/**
 * 构造快捷登录跳转 URL（GET 模式）。
 */
function oauthLoginUrl(provider, redirect) {
    let url = '/oauth/login/' + encodeURIComponent(provider);
    if (redirect) {
        url += '?redirect=' + encodeURIComponent(redirect);
    }
    return url;
}

/**
 * 绑定模式：form POST 携带 token 跳转授权页。
 * （302 跳转无法携带 Authorization 头，故 token 放 form body）
 */
function oauthBindSubmit(provider, redirect) {
    const token = getUserToken();
    if (!token) {
        alert('请先登录后再绑定');
        return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/oauth/login/' + encodeURIComponent(provider);
    form.style.display = 'none';
    const fields = { bind: '1', redirect: redirect || '/', token: token };
    Object.keys(fields).forEach(function (k) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = k;
        input.value = fields[k];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

/**
 * 用一次性 exchange code 换 player token（回调回跳后调用）。
 */
function oauthExchangeCode(code) {
    return fetch('/oauth/complete?code=' + encodeURIComponent(code))
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, error: '网络错误' }; });
}

/**
 * 获取当前玩家的 OAuth 绑定列表。
 */
function oauthFetchBindings() {
    const token = getUserToken();
    if (!token) return Promise.resolve({ ok: false, error: '未登录' });
    return fetch('/api/oauth/bindings', {
        headers: { 'Authorization': 'Bearer ' + token }
    })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, error: '网络错误' }; });
}

/**
 * 解绑 OAuth provider。
 */
function oauthUnbind(provider) {
    const token = getUserToken();
    if (!token) return Promise.resolve({ ok: false, error: '未登录' });
    return fetch('/api/oauth/unbind', {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + token,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'provider=' + encodeURIComponent(provider)
    })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, error: '网络错误' }; });
}

/**
 * 读取 pending 建号确认信息（弹窗展示邮箱 + 预填昵称）。
 */
function oauthPendingInfo(code) {
    return fetch('/api/oauth/pending-info?code=' + encodeURIComponent(code))
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, error: '网络错误' }; });
}

/**
 * 确认创建账户（未注册邮箱场景）。
 */
function oauthConfirmCreate(code, nickname, fp) {
    return fetch('/api/oauth/confirm-create?code=' + encodeURIComponent(code)
        + '&nickname=' + encodeURIComponent(nickname)
        + '&fp=' + encodeURIComponent(fp))
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, error: '网络错误' }; });
}

/**
 * 取消创建账户（清理 pending）。
 */
function oauthCancelCreate(code) {
    return fetch('/api/oauth/cancel?code=' + encodeURIComponent(code))
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false }; });
}

/**
 * 清理当前 URL 中的 oauth 相关参数（避免刷新重放）。
 */
function oauthCleanUrlParams() {
    const params = new URLSearchParams(window.location.search);
    let changed = false;
    ['oauth_code', 'pending_code', 'oauth_error'].forEach(function (key) {
        if (params.has(key)) { params.delete(key); changed = true; }
    });
    if (!changed) return;
    const qs = params.toString();
    const newUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
    window.history.replaceState(null, '', newUrl);
}

// ================================================================
// 头像渲染工具（各页面共用）
// ================================================================

/**
 * 获取头像 API URL。
 * @param {string} playerId
 * @returns {string}
 */
function getAvatarUrl(playerId) {
    return '/api/avatar/' + encodeURIComponent(playerId);
}

/**
 * 根据昵称计算背景色（与各页面 getAvatarColor 算法保持一致）。
 * @param {string} name
 * @returns {string}
 */
function getAvatarColor(name) {
    if (!name) return '#d1f2d3';
    const colors = [
        '#d1f2d3', '#d3e2ed', '#fdf5c9', '#fde2e4',
        '#c8ead1', '#d0ddf0', '#f6ecc0', '#f8d5da',
    ];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
}

/**
 * 渲染头像元素到指定容器。
 *
 * 有 playerId 时尝试加载 OAuth 头像，失败降级为昵称首字符。
 * 降级时不写内联背景色，保留容器自身 class 的默认背景与文字色
 * （如 .lobby-avatar 的 var(--note-green) / 自己消息 var(--note-blue)）。
 *
 * @param {HTMLElement} container 头像容器 DOM 元素。
 * @param {string}      playerId  玩家 ID，用于构造头像 API URL。
 * @param {string}      nickname  玩家昵称，用于降级渲染。
 */
function renderAvatar(container, playerId, nickname) {
    if (!container) return;

    if (playerId) {
        const img = new Image();
        img.onload = function () {
            container.textContent = '';
            container.style.backgroundImage = 'url(' + getAvatarUrl(playerId) + ')';
            container.style.backgroundSize = 'cover';
            container.style.backgroundPosition = 'center';
        };
        img.onerror = function () {
            container.style.backgroundImage = 'none';
            container.textContent = (nickname || '?').charAt(0);
        };
        img.src = getAvatarUrl(playerId);
    } else {
        container.textContent = (nickname || '?').charAt(0);
    }
}

/**
 * 快速创建头像 DOM 元素。
 * @param {string} playerId
 * @param {string} nickname
 * @returns {HTMLElement}
 */
function createAvatarElement(playerId, nickname) {
    const el = document.createElement('div');
    renderAvatar(el, playerId, nickname);
    return el;
}

/**
 * 统一的 OAuth 回调处理（各页面 onload 调用）：
 *   - oauth_code     → 兑换 token 存入 UserData
 *   - pending_code   → 弹建号确认窗
 *   - oauth_error    → 提示错误
 */
function oauthHandleReturn(params) {
    params = params || new URLSearchParams(window.location.search);

    const oauthError = params.get('oauth_error');
    if (oauthError) {
        if (typeof showTopToast === 'function') showTopToast(oauthError, true);
        else alert(oauthError);
        oauthCleanUrlParams();
        return;
    }

    const oauthCode = params.get('oauth_code');
    if (oauthCode) {
        oauthExchangeCode(oauthCode).then(function (data) {
            if (data.ok && data.token) {
                setUserToken(data.token);
                if (data.nickname) setUserNickname(data.nickname);
                if (typeof showTopToast === 'function') {
                    showTopToast('快捷登录成功！', false);
                }
                oauthCleanUrlParams();
                // 刷新页面使登录状态生效（子页面重新走 join 流程）
                window.location.reload();
            } else {
                if (typeof showTopToast === 'function') {
                    showTopToast(data.error || '登录失败，请重试', true);
                } else {
                    alert(data.error || '登录失败，请重试');
                }
                oauthCleanUrlParams();
            }
        });
        return;
    }

    const pendingCode = params.get('pending_code');
    if (pendingCode) {
        if (typeof showOAuthCreateDialog === 'function') {
            showOAuthCreateDialog(pendingCode);
        } else {
            // 子页面未实现弹窗：跳回首页处理
            window.location.href = '/?pending_code=' + encodeURIComponent(pendingCode);
        }
    }
}
