/* ===== ND-ACTIVITY-BEGIN 国庆氛围（活动结束删除本段即可完全回滚）===== */
/* 职责收敛到最小：只负责按日期挂载 html[data-event="nd"]，让 CSS 生效。
   不注入任何 DOM、不弹任何提示 —— 装饰全部由 CSS 完成，且不占文档流。
   （独立文件 Public/national-day.{css,js} 是本段源稿，内容保持同步。） */
(function () {
    'use strict';

    if (window.__ND_ACTIVITY__) return;
    window.__ND_ACTIVITY__ = true;

    var CONFIG = {
        enabled: true,
        start: '2026-09-28T00:00:00',   // 本地时间；留空表示该端不限
        end: '2026-10-08T23:59:59'
    };

    function inWindow() {
        if (!CONFIG.enabled) return false;
        var now = Date.now();
        if (CONFIG.start) {
            var a = Date.parse(CONFIG.start);
            if (!isNaN(a) && now < a) return false;
        }
        if (CONFIG.end) {
            var b = Date.parse(CONFIG.end);
            if (!isNaN(b) && now > b) return false;
        }
        return true;
    }

    if (!inWindow()) return;

    // 脚本随 shared.js 同步执行，此处立即挂属性，避免首屏闪默认配色
    document.documentElement.setAttribute('data-event', 'nd');

    window.NationalDay = {
        enable: function () {
            CONFIG.enabled = true;
            document.documentElement.setAttribute('data-event', 'nd');
        },
        disable: function () {
            CONFIG.enabled = false;
            document.documentElement.removeAttribute('data-event');
        },
        config: CONFIG
    };
})();
/* ===== ND-ACTIVITY-END ===== */
