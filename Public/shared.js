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
    document.querySelectorAll('.top-toast').forEach((t, i) => {
        t.style.top = (12 + i * 48) + 'px';
        t.style.transition = 'top 0.25s ease';
    });
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
    } catch (_) {}
}

function getUserNickname() { return getUserdata().nickname || ''; }
function setUserNickname(name) { const d = getUserdata(); d.nickname = name; saveUserdata(d); }
function getUserRecoveryCode() { return getUserdata().recovery_code || ''; }
function setUserRecoveryCode(code) { const d = getUserdata(); d.recovery_code = code; saveUserdata(d); }
