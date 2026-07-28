/**
 * 共享工具：顶部 toast 通知 + 用户数据存储（script.js / whoisai_script.js 共用）
 */

// ---- Toast ----

function showTopToast(message, isError = true) {
    const el = document.createElement('div');
    const bg = isError ? '#ffe0e0' : '#d4edda';
    const color = isError ? '#c0392b' : '#155724';
    const border = isError ? '#e74c3c' : '#28a745';
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

function showDanmaku(text, label = '全服公告', displayMs = 0) {
    announceQueue.push({ text, label, displayMs });
    dequeueAnnounce();
}

function dequeueAnnounce() {
    while (announceQueue.length > 0 && announceShowing < ANNOUNCE_MAX) {
        const item = announceQueue.shift();
        const text = item.text || item;
        const label = item.label || '全服公告';
        const ms = (item.displayMs > 0 ? item.displayMs : ANNOUNCE_DISPLAY_MS) * 1000;
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

        setTimeout(dismiss, ms);
    }
}

// ================================================================
// 主题管理（默认 / 暗色 / 跟随系统）
// ================================================================
const THEME_KEY = 'theme';

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

function setTheme(theme) {
    localStorage.setItem(THEME_KEY, theme);
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

var USERDATA_KEY = 'UserData';

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
function getUserRecoveryCode() { return getUserdata().recovery_code || ''; }
function setUserRecoveryCode(code) { const d = getUserdata(); d.recovery_code = code; saveUserdata(d); }

// ---- Sticker 跨页面缓存（供 whoisai 等子页面复用 stickerMap） ----
var STICKER_CACHE_KEY = 'sticker_cache';

function saveStickerCache(map) {
    try {
        localStorage.setItem(STICKER_CACHE_KEY, JSON.stringify(map));
    } catch (_) { }
}

function loadStickerCache() {
    try {
        var raw = localStorage.getItem(STICKER_CACHE_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (_) { return {}; }
}

function escapeHtmlAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
