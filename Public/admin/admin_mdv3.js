/**
 * @file MDv3 分享站管理（独立 JS 文件，避免 admin.js 过大）
 * 依赖：
 *   - escapeHtml / escapeHtmlAttr / getCookie / setCookie / showAdminToast（来自 admin.js）
 *   - adminToken 全局 / switchAdminTab() 全局
 *   - window.CustomEvent('mdv3:tab-switched') 钩子（来自 switchAdminTab）
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'turing_mdv3_config_v1';
    const DEFAULT_BASE_URL = 'https://你的域名';
    const MDV3_SUB_KEYS = ['posts', 'reports', 'restrictions', 'settings'];
    /** 帖子前台页面 URL 前缀：跳转原帖用 */
    const POST_PAGE_URL_BASE = 'http://share.xfcode.top/post.html?id=';

    // ==================== 工具：统一 API 调用 ====================

    function loadSettings() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                const obj = JSON.parse(raw);
                if (obj && typeof obj.baseUrl === 'string') return obj;
            }
        } catch (e) { /* ignore */ }
        return { baseUrl: DEFAULT_BASE_URL };
    }

    function saveSettings(cfg) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(cfg || {}));
    }

    function getBaseUrl() {
        let s = (loadSettings().baseUrl || DEFAULT_BASE_URL).trim();
        if (s.endsWith('/')) s = s.slice(0, -1);
        return s;
    }

    function getAdminToken() {
        // 优先读取 admin.js 中同名全局；未注入则读 cookie（两种方式都兼容）
        if (typeof window.adminToken === 'string' && window.adminToken) return window.adminToken;
        if (typeof getCookie === 'function') return getCookie('turing_admin_token') || '';
        return '';
    }

    /**
     * MDv3 所有接口统一：POST + JSON body + token + 返回 {ok, ...}
     * @returns {Promise<{ok:boolean, data:object, error?:string, httpStatus:number}>}
     */
    async function mdv3Call(path, payload) {
        const token = getAdminToken();
        const url = getBaseUrl() + path;
        const body = Object.assign({}, payload || {}, { token: token });
        let httpStatus = 0;
        let rawText = '';
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify(body),
            });
            httpStatus = resp.status;
            rawText = await resp.text();
            let data;
            try { data = JSON.parse(rawText); }
            catch (e) {
                return { ok: false, httpStatus, data: null, error: '响应不是合法 JSON（HTTP ' + httpStatus + '）：' + String(rawText || '').slice(0, 120) };
            }
            if (!data || data.ok !== true) {
                return { ok: false, httpStatus, data: data || null, error: (data && data.error) ? data.error : ('接口返回失败：HTTP ' + httpStatus) };
            }
            return { ok: true, httpStatus, data: data };
        } catch (e) {
            return { ok: false, httpStatus: 0, data: null, error: '网络错误：' + (e && e.message ? e.message : String(e)) };
        }
    }

    // ==================== 通用：状态文字 ====================

    function setStatus(el, text, type) {
        if (!el) return;
        if (!text) { el.textContent = ''; return; }
        el.style.display = 'inline';
        el.textContent = text;
        el.style.color = !type ? ''
            : (type === 'success' ? '#4caf50'
            : type === 'warn' ? '#ff9800'
            : type === 'error' ? '#f44336'
            : '#888');
    }

    function formatTs(v) {
        if (!v) return '-';
        const n = Number(v);
        if (!Number.isFinite(n) || n <= 0) return String(v);
        const d = new Date(n * 1000);
        const pad = (x) => (x < 10 ? '0' + x : '' + x);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' '
            + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function escapeHtmlSafe(str) {
        if (typeof escapeHtml === 'function') return escapeHtml(str);
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttrSafe(str) {
        if (typeof escapeHtmlAttr === 'function') return escapeHtmlAttr(str);
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function toastSafe(msg, type) {
        if (typeof showAdminToast === 'function') { showAdminToast(msg, type); return; }
        alert(msg);
    }

    function showPromptDialog(title, message, placeholder, confirmText, onConfirm) {
        // 复用样式一致的小对话框
        const overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.style.position = 'fixed';
        overlay.innerHTML = '<div class="admin-dialog" style="max-width:460px;width:90%;">' +
            '<h3>' + escapeHtmlSafe(title) + '</h3>' +
            (message ? '<p style="font-size:12px;color:var(--text-muted);margin:6px 0 10px;">' + escapeHtmlSafe(message) + '</p>' : '') +
            '<textarea id="mdv3-prompt-ta" placeholder="' + escapeHtmlSafe(placeholder || '') + '" style="width:100%;"></textarea>' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="mdv3-prompt-cancel">取消</button>' +
            '<button class="doodle-btn btn-primary" id="mdv3-prompt-ok">' + escapeHtmlSafe(confirmText || '确定') + '</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        const ta = overlay.querySelector('#mdv3-prompt-ta');
        if (ta) ta.focus();
        overlay.querySelector('#mdv3-prompt-cancel').addEventListener('click', () => overlay.remove());
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('#mdv3-prompt-ok').addEventListener('click', () => {
            const val = ta ? ta.value : '';
            overlay.remove();
            onConfirm && onConfirm(val);
        });
    }

    function showReportHandleDialog(report, onSubmit) {
        const overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.style.position = 'fixed';
        overlay.innerHTML = '<div class="admin-dialog" style="max-width:520px;width:92%;">' +
            '<h3>处理举报 #' + escapeHtmlSafe(String(report.id)) + '</h3>' +
            '<div class="mdv3-report-info">' +
            '<div class="mdv3-report-info-row"><span class="info-label">举报人</span><span>' + escapeHtmlSafe(report.reporter || '-') + '</span></div>' +
            '<div class="mdv3-report-info-row"><span class="info-label">被举报</span><span>' + escapeHtmlSafe(report.reported || '-') + '</span></div>' +
            '<div class="mdv3-report-info-row"><span class="info-label">目标</span><span>' + escapeHtmlSafe(report.target_type || '') + ' #' + escapeHtmlSafe(String(report.target_id || '')) + '</span></div>' +
            '<div class="mdv3-report-info-row"><span class="info-label">理由</span><span>' + escapeHtmlSafe(report.reason || '-') + '</span></div>' +
            '</div>' +
            '<div class="dialog-field">' +
            '<label for="mdv3-rpt-action">处理动作</label>' +
            '<select id="mdv3-rpt-action">' +
            '<option value="restrict_post">限制发帖（限时）</option>' +
            '<option value="restrict_comment">限制评论（限时）</option>' +
            '<option value="ban">永久封禁</option>' +
            '</select>' +
            '</div>' +
            '<div class="dialog-field" id="mdv3-rpt-hours-wrap">' +
            '<label for="mdv3-rpt-hours">时长（小时，1~720）</label>' +
            '<input id="mdv3-rpt-hours" type="number" min="1" max="720" value="24">' +
            '</div>' +
            '<div class="dialog-field">' +
            '<label for="mdv3-rpt-reason">处理理由（必填，通知被举报人）</label>' +
            '<textarea id="mdv3-rpt-reason" placeholder="例如：违规内容，禁止再次发布"></textarea>' +
            '</div>' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="mdv3-rpt-cancel">取消</button>' +
            '<button class="doodle-btn btn-primary" id="mdv3-rpt-ok">提交处理</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        const actionSel = overlay.querySelector('#mdv3-rpt-action');
        const hoursWrap = overlay.querySelector('#mdv3-rpt-hours-wrap');
        const updateHours = () => { const a = actionSel.value; hoursWrap.style.display = (a === 'ban') ? 'none' : ''; };
        actionSel.addEventListener('change', updateHours);
        updateHours();
        overlay.querySelector('#mdv3-rpt-cancel').addEventListener('click', () => overlay.remove());
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
        overlay.querySelector('#mdv3-rpt-ok').addEventListener('click', async () => {
            const action = actionSel.value;
            const hours = parseInt(overlay.querySelector('#mdv3-rpt-hours').value, 10);
            const reason = overlay.querySelector('#mdv3-rpt-reason').value.trim();
            const payload = { report_id: Number(report.id), action: action, reason: reason };
            if (action !== 'ban') {
                if (!Number.isFinite(hours) || hours < 1 || hours > 720) { toastSafe('时长必须在 1 ~ 720 小时之间', 'error'); return; }
                payload.hours = hours;
            }
            if (!reason) { toastSafe('请填写处理理由', 'error'); return; }
            overlay.remove();
            onSubmit && onSubmit(payload);
        });
    }

    // ==================== 侧边栏折叠 + 子菜单 click ====================

    function bindSidebarFold() {
        const group = document.getElementById('mdv3-fold-group');
        const toggle = document.getElementById('mdv3-fold-toggle');
        if (!group || !toggle) return;
        toggle.addEventListener('click', () => group.classList.toggle('open'));
        const btns = document.querySelectorAll('.mdv3-sub-btn');
        btns.forEach(b => {
            b.addEventListener('click', () => {
                const key = b.getAttribute('data-mdv3');
                if (!MDV3_SUB_KEYS.includes(key)) return;
                // 点击子菜单时，若折叠组关闭则展开
                if (!group.classList.contains('open')) group.classList.add('open');
                if (typeof window.switchAdminTab === 'function') {
                    window.switchAdminTab(key);
                }
            });
        });
    }

    // ==================== 设置面板 ====================

    function bindSettingsPanel() {
        const $base = document.getElementById('mdv3-baseurl');
        const $save = document.getElementById('mdv3-save-settings');
        const $test = document.getElementById('mdv3-settest-settings');
        const $status = document.getElementById('mdv3-settings-status');
        if (!$base) return;
        const cfg = loadSettings();
        $base.value = cfg.baseUrl || DEFAULT_BASE_URL;

        $save && $save.addEventListener('click', () => {
            let v = ($base.value || '').trim();
            if (!v) { toastSafe('请填写基础地址', 'error'); return; }
            if (v.endsWith('/')) v = v.slice(0, -1);
            if (!/^https?:\/\//i.test(v)) { toastSafe('基础地址必须以 http:// 或 https:// 开头', 'error'); return; }
            saveSettings({ baseUrl: v });
            setStatus($status, '已保存基础地址：' + v, 'success');
            toastSafe('已保存', 'success');
        });

        $test && $test.addEventListener('click', async () => {
            setStatus($status, '正在测试帖子列表接口...', 'warn');
            // 顺手请求下帖子列表，验证 baseURL + token 都生效
            const r = await mdv3Call('/api/admin/posts', {});
            if (r.ok) {
                const admin = String(r.data.admin || '-');
                const role = String(r.data.role || '-');
                const total = Number(r.data.total || 0);
                setStatus($status, '连通性测试成功（admin=' + admin + '，role=' + role + '，共 ' + total + ' 篇帖子）', 'success');
                toastSafe('MDv3 接口连通正常', 'success');
            } else {
                setStatus($status, '失败：' + r.error, 'error');
                toastSafe('测试失败：' + r.error, 'error');
            }
        });
    }

    // ==================== 模块 1：帖子列表 ====================

    function statusLabelForPost(s) {
        if (s === 'pending')  return '<span class="mdv3-status-badge mdv3-status-pending">待审核</span>';
        if (s === 'approved') return '<span class="mdv3-status-badge mdv3-status-approved">已通过</span>';
        if (s === 'rejected') return '<span class="mdv3-status-badge mdv3-status-rejected">已退回</span>';
        return '<span class="mdv3-status-badge">' + escapeHtmlSafe(s || '') + '</span>';
    }

    async function loadPostsList() {
        const $list = document.getElementById('mdv3-posts-list');
        const $status = document.getElementById('mdv3-posts-status');
        if (!$list) return;
        $list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:10px;">加载中...</div>';
        setStatus($status, '加载帖子列表中...', 'warn');
        const r = await mdv3Call('/api/admin/posts', {});
        if (!r.ok) {
            $list.innerHTML = '<div class="list-empty">' + escapeHtmlSafe(r.error || '加载失败') + '</div>';
            setStatus($status, '加载失败：' + r.error, 'error');
            toastSafe('加载帖子列表失败：' + r.error, 'error');
            return;
        }
        const posts = (r.data && Array.isArray(r.data.posts)) ? r.data.posts : [];
        setStatus($status, '加载成功，共 ' + posts.length + ' 条', 'success');
        if (posts.length === 0) {
            $list.innerHTML = '<div class="list-empty">暂无帖子</div>';
            return;
        }
        const html = posts.map(p => {
            const id = p.id;
            const title = escapeHtmlSafe(p.title || '（无标题）');
            const desc = p.description ? escapeHtmlSafe(p.description) : '';
            const author = escapeHtmlSafe(p.author || '-');
            const status = statusLabelForPost(p.status);
            const pendingFlag = p.pending ? '<span class="mdv3-status-badge mdv3-status-pending">审核中不落库</span>' : '';
            const rejectReason = p.reject_reason ? ' · 退回原因：' + escapeHtmlSafe(p.reject_reason) : '';
            const like = p.like_count || 0;
            const fav = p.favorite_count || 0;
            const cmts = p.comment_count || 0;
            const ip = p.author_ip ? escapeHtmlSafe(p.author_ip) : '-';
            const fp = p.author_fp ? escapeHtmlSafe(p.author_fp) : '-';
            return '<div class="mdv3-row" data-post-row-id="' + escapeAttrSafe(String(id)) + '">' +
                '<div class="mdv3-row-head">' +
                '<span class="mdv3-row-title">' + title + '</span>' + status + pendingFlag +
                '<span class="mdv3-row-meta">' +
                '<span>#' + escapeHtmlSafe(String(id)) + '</span>' +
                '<span>作者：' + author + '</span>' +
                '<span>' + formatTs(p.created_at) + '</span>' +
                '</span></div>' +
                '<div class="mdv3-row-meta mdv3-row-stats">' +
                '<span>赞 ' + like + '</span><span>藏 ' + fav + '</span><span>评 ' + cmts + '</span>' +
                '</div>' +
                (desc ? '<div class="mdv3-row-body mdv3-row-body-clamp">' + desc + '</div>' : '') +
                '<div class="mdv3-row-meta">' +
                '<span>IP: ' + ip + '</span><span>FP: ' + fp + '</span>' + rejectReason +
                '</div>' +
                '<div class="mdv3-row-actions">' +
                '<button class="doodle-btn btn-xs" data-action="open-post-detail" data-id="' + escapeAttrSafe(String(id)) + '">详情 &amp; 评论</button>' +
                '<a class="doodle-btn btn-xs" target="_blank" rel="noopener" href="' + POST_PAGE_URL_BASE + escapeAttrSafe(String(id)) + '">打开原帖</a>' +
                ((p.status !== 'rejected') ? '<button class="doodle-btn btn-xs btn-danger-ghost" data-action="reject-post" data-id="' + escapeAttrSafe(String(id)) + '">退回</button>' : '') +
                '<button class="doodle-btn btn-xs btn-danger-ghost" data-action="delete-post" data-id="' + escapeAttrSafe(String(id)) + '">彻底删除</button>' +
                '</div>' +
                '</div>';
        }).join('');
        $list.innerHTML = html;

        // 绑定操作
        $list.querySelectorAll('[data-action="open-post-detail"]').forEach(b => {
            b.addEventListener('click', () => {
                const id = Number(b.dataset.id);
                showPostDetailDialog(id);
            });
        });
        $list.querySelectorAll('[data-action="reject-post"]').forEach(b => {
            b.addEventListener('click', () => {
                const id = Number(b.dataset.id);
                showPromptDialog('退回帖子 #' + id, '退回理由必填，将通知作者', '请输入退回理由...', '退回', (val) => {
                    const v = (val || '').trim();
                    if (!v) { toastSafe('退回理由不能为空', 'error'); return; }
                    postAction(id, 'reject', v);
                });
            });
        });
        $list.querySelectorAll('[data-action="delete-post"]').forEach(b => {
            b.addEventListener('click', () => {
                const id = Number(b.dataset.id);
                showPromptDialog('删除帖子 #' + id, '删除操作不可撤销，理由必填，将通知作者', '请输入删除理由...', '永久删除', (val) => {
                    const v = (val || '').trim();
                    if (!v) { toastSafe('删除理由不能为空', 'error'); return; }
                    if (!confirm('确认永久删除帖子 #' + id + '？此操作不可撤销。')) return;
                    postAction(id, 'delete', v);
                });
            });
        });
    }

    async function postAction(id, action, reason) {
        const $status = document.getElementById('mdv3-posts-status');
        setStatus($status, '正在执行 [' + action + '] ...', 'warn');
        const r = await mdv3Call('/api/admin/post/action', { id: Number(id), action: action, reason: String(reason || '') });
        if (!r.ok) {
            setStatus($status, '操作失败：' + r.error, 'error');
            toastSafe('操作失败：' + r.error, 'error');
            return;
        }
        setStatus($status, '操作成功：' + (r.data.action || action) + ' #' + (r.data.id || id), 'success');
        toastSafe('操作成功', 'success');
        // 如果该帖子详情弹窗正打开，操作完成后刷新弹窗内容
        if (_postDetailDialog && Number(_postDetailDialog.id) === Number(id)
            && _postDetailDialog.body && _postDetailDialog.body.isConnected) {
            loadPostDetailInto(id, _postDetailDialog.body, () => {});
        }
        loadPostsList();
    }

    // ==================== 模块 2：帖子详情 + 评论（弹窗） ====================

    /** 当前打开的帖子详情弹窗：{ id, overlay, body, data } */
    let _postDetailDialog = null;
    /** 当前打开的原始数据弹窗（与详情弹窗互斥，同时只存在一个） */
    let _rawDialog = null;
    /** 最近一次加载的帖子详情原始数据：{ id, data } */
    let _lastPostDetailData = null;

    /**
     * 打开帖子详情弹窗（帖子全文 + 评论列表 + 删评论操作）
     * @param {number} postId
     */
    function showPostDetailDialog(postId) {
        // 互斥：先关闭原始数据弹窗与已有详情弹窗
        if (_rawDialog && _rawDialog.overlay && _rawDialog.overlay.isConnected) {
            _rawDialog.overlay.remove();
            _rawDialog = null;
        }
        if (_postDetailDialog && _postDetailDialog.overlay && _postDetailDialog.overlay.isConnected) {
            _postDetailDialog.overlay.remove();
            _postDetailDialog = null;
        }
        const overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.style.position = 'fixed';
        overlay.innerHTML = '<div class="admin-dialog" style="max-width:720px;width:92%;">' +
            '<h3 id="mdv3-post-detail-title">帖子详情 #' + escapeHtmlSafe(String(postId)) + '</h3>' +
            '<p class="admin-dialog-target" id="mdv3-post-detail-sub"></p>' +
            '<div id="mdv3-post-detail-body"></div>' +
            '<div class="admin-dialog-actions">' +
            '<a class="doodle-btn" id="mdv3-post-detail-open" target="_blank" rel="noopener" href="' + POST_PAGE_URL_BASE + escapeHtmlSafe(String(postId)) + '" style="margin-right:auto;">跳转原帖</a>' +
            '<button class="doodle-btn" id="mdv3-post-detail-raw">查看原始数据</button>' +
            '<button class="doodle-btn btn-primary" id="mdv3-post-detail-close">关闭</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        overlay.querySelector('#mdv3-post-detail-close').addEventListener('click', () => overlay.remove());
        overlay.querySelector('#mdv3-post-detail-raw').addEventListener('click', () => showPostRawDialog(postId));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

        _postDetailDialog = { id: Number(postId), overlay: overlay, body: overlay.querySelector('#mdv3-post-detail-body'), data: null };
        loadPostDetailInto(postId, _postDetailDialog.body, () => {});
    }

    /**
     * 打开"原始数据"弹窗：显示帖子原始 JSON（含 blocks 结构），仅供管理员排查。
     * 与详情弹窗互斥切换：关闭详情 → 打开原始；点"返回帖子详情"→ 重新打开详情。
     * @param {number} postId
     */
    function showPostRawDialog(postId) {
        // 互斥：先关闭详情弹窗与已有原始弹窗
        if (_postDetailDialog && _postDetailDialog.overlay && _postDetailDialog.overlay.isConnected) {
            _postDetailDialog.overlay.remove();
            _postDetailDialog = null;
        }
        if (_rawDialog && _rawDialog.overlay && _rawDialog.overlay.isConnected) {
            _rawDialog.overlay.remove();
            _rawDialog = null;
        }
        const data = (_lastPostDetailData && Number(_lastPostDetailData.id) === Number(postId)) ? _lastPostDetailData.data : null;
        const post = (data && data.post) ? data.post : {};
        const overlay = document.createElement('div');
        overlay.className = 'admin-dialog-overlay';
        overlay.style.position = 'fixed';
        overlay.innerHTML = '<div class="admin-dialog" style="max-width:760px;width:92%;">' +
            '<h3>原始数据 #' + escapeHtmlSafe(String(postId)) + '</h3>' +
            '<p class="admin-dialog-target">post 原始 JSON（含 blocks 结构），仅供排查</p>' +
            '<pre class="raw-json">' + escapeHtmlSafe(JSON.stringify(post, null, 2)) + '</pre>' +
            '<div class="admin-dialog-actions">' +
            '<button class="doodle-btn" id="mdv3-raw-back">返回帖子详情</button>' +
            '<button class="doodle-btn btn-primary" id="mdv3-raw-close">关闭</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        overlay.querySelector('#mdv3-raw-back').addEventListener('click', () => {
            overlay.remove();
            _rawDialog = null;
            showPostDetailDialog(postId);
        });
        overlay.querySelector('#mdv3-raw-close').addEventListener('click', () => { overlay.remove(); _rawDialog = null; });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) { overlay.remove(); _rawDialog = null; }
        });
        _rawDialog = { id: Number(postId), overlay: overlay };
    }

    /**
     * 块拼接：按 blocks 数组顺序，把每个块的内容提取为纯文本，拼接成一个字符串。
     * 后台只做拼接，不做任何样式与交互渲染。
     * 块格式见《blocks格式说明.md》
     * @param {Array} blocks - post.blocks 数组
     * @returns {string} 拼接后的纯文本（块之间以换行分隔）
     */
    function blocksToPlainText(blocks) {
        if (!Array.isArray(blocks)) return '';
        const lines = [];
        blocks.forEach(b => {
            if (!b || typeof b !== 'object') return;
            const t = b.t || 'text';
            switch (t) {
                case 'text':
                    if (b.text) lines.push(b.text);
                    break;
                case 'textbox':
                    if (b.text) lines.push(b.text);
                    break;
                case 'button':
                case 'hide':
                    if (b.label || b.content) lines.push(b.label || b.content);
                    break;
                case 'input':
                case 'switch':
                    if (b.placeholder || b.label || b.content) lines.push(b.placeholder || b.label || b.content);
                    break;
                case 'timer':
                case 'stopwatch': {
                    const s = ((b.label || '') + (b.seconds ? ' ' + Number(b.seconds) + '秒' : '')).trim();
                    if (s) lines.push(s);
                    break;
                }
                case 'vote':
                    if (b.question) lines.push(b.question + '：');
                    if (Array.isArray(b.options) && b.options.length) {
                        b.options.forEach(o => lines.push('- ' + String(o)));
                    }
                    break;
                case 'table': {
                    const cells = Array.isArray(b.cells) ? b.cells : [];
                    if (cells.length) {
                        const n = Math.max(1, Number(b.cols) || 1);
                        for (let i = 0; i < cells.length; i += n) {
                            lines.push(cells.slice(i, i + n).map(c => (c === null || c === undefined) ? '' : String(c)).join(' '));
                        }
                    }
                    break;
                }
                case 'board': {
                    const s = [(b.size ? b.size + '×' + b.size : ''), (b.shapes ? '图形:' + b.shapes : '')].filter(Boolean).join(' ');
                    if (s) lines.push(s);
                    break;
                }
                case 'music':
                    if (b.url) lines.push(b.url);
                    break;
                case 'modal':
                    if (b.label || b.title) lines.push(b.label || b.title);
                    if (Array.isArray(b.children) && b.children.length) {
                        const child = blocksToPlainText(b.children);
                        if (child) lines.push(child);
                    }
                    break;
                default:
                    if (b.label || b.content || b.text) lines.push(b.label || b.content || b.text);
            }
        });
        return lines.join('\n');
    }

    /**
     * 构建详情 HTML（帖子正文 + 评论列表 + 删评论按钮）
     * @param {number} postId
     * @param {object} data  { post, comments }
     * @returns {string} html
     */
    function buildDetailHtml(postId, data) {
        const post = (data && data.post) ? data.post : {};
        const comments = (data && Array.isArray(data.comments)) ? data.comments : [];

        let bodyContent = '';
        if (Array.isArray(post.blocks) && post.blocks.length) {
            // 后台只做块的拼接：blocks 顺序拼接为一个纯文本字符串，不做任何样式
            bodyContent = escapeHtmlSafe(blocksToPlainText(post.blocks));
        } else if (post.content) {
            bodyContent = escapeHtmlSafe(post.content);
        }

        const statusHtml = statusLabelForPost(post.status);
        const postCard = '<div class="mdv3-block-card">' +
            '<div class="mdv3-row-head">' +
            '<span class="mdv3-row-title">' + escapeHtmlSafe(post.title || '（无标题）') + '</span>' +
            statusHtml +
            '<span class="mdv3-row-meta">' +
            '<span>#' + escapeHtmlSafe(String(post.id || postId)) + '</span>' +
            '<span>作者：' + escapeHtmlSafe(post.author || '-') + '</span>' +
            '</span>' +
            '</div>' +
            (bodyContent ? '<div class="mdv3-row-body">' + bodyContent + '</div>' : '') +
            '</div>';

        let commentsHtml = '<div class="mdv3-block-card"><h5>评论（' + comments.length + '）</h5>';
        if (comments.length === 0) {
            commentsHtml += '<div class="list-empty" style="padding:12px 0;">暂无评论</div>';
        } else {
            commentsHtml += '<div style="margin-top:6px;">';
            comments.forEach(c => {
                commentsHtml += '<div class="mdv3-row" data-comment-id="' + escapeAttrSafe(String(c.id)) + '">' +
                    '<div class="mdv3-row-head">' +
                    '<span style="font-weight:700;">' + escapeHtmlSafe(c.author || '匿名') + '</span>' +
                    (c.parent_id ? '<span class="mdv3-row-meta"><span>回复 #' + escapeHtmlSafe(String(c.parent_id)) + '</span></span>' : '') +
                    '<span class="mdv3-row-meta">' +
                    '<span>#' + escapeHtmlSafe(String(c.id)) + '</span>' +
                    '<span>贴子 #' + escapeHtmlSafe(String(c.post_id || '')) + '</span>' +
                    '<span>赞 ' + (c.like_count || 0) + '</span>' +
                    '<span>' + formatTs(c.created_at) + '</span>' +
                    '</span></div>' +
                    '<div class="mdv3-row-body">' + escapeHtmlSafe(c.content || '') + '</div>' +
                    '<div class="mdv3-row-actions">' +
                    '<button class="doodle-btn btn-xs btn-danger-ghost" data-action="mdv3-delete-comment" data-id="' + escapeAttrSafe(String(c.id)) + '">删除（含子评论）</button>' +
                    '</div></div>';
            });
            commentsHtml += '</div>';
        }
        commentsHtml += '</div>';

        return postCard + commentsHtml;
    }

    /**
     * 把帖子详情+评论加载并渲染到指定节点，再绑定删评论按钮
     */
    async function loadPostDetailInto(postId, mount, onSuccess) {
        if (!mount) return;
        const $status = document.getElementById('mdv3-posts-status');
        mount.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:10px 0;">详情加载中...</div>';
        setStatus($status, '加载帖子 #' + postId + ' 详情...', 'warn');

        const r = await mdv3Call('/api/admin/post', { id: Number(postId), type: 'all' });
        if (!r.ok) {
            mount.innerHTML = '<div class="list-empty" style="padding:10px 0;">' + escapeHtmlSafe(r.error || '加载失败') + '</div>';
            setStatus($status, '加载帖子详情失败：' + r.error, 'error');
            toastSafe('加载帖子详情失败：' + r.error, 'error');
            return;
        }
        setStatus($status, '加载帖子 #' + postId + ' 详情成功', 'success');
        mount.innerHTML = buildDetailHtml(postId, r.data || {});

        // 弹窗场景：更新标题与副标题，并缓存原始数据供"查看原始数据"使用
        if (_postDetailDialog && Number(_postDetailDialog.id) === Number(postId)) {
            _postDetailDialog.data = r.data || null;
            _lastPostDetailData = { id: Number(postId), data: r.data || {} };
            const post = (r.data && r.data.post) ? r.data.post : {};
            const tEl = _postDetailDialog.overlay.querySelector('#mdv3-post-detail-title');
            const sEl = _postDetailDialog.overlay.querySelector('#mdv3-post-detail-sub');
            if (tEl) tEl.textContent = '帖子详情 #' + postId + (post.title ? ' · ' + post.title : '');
            if (sEl) sEl.textContent = '作者：' + (post.author || '-')
                + ' · 状态：' + (post.status || '-')
                + (post.created_at ? ' · 发布于：' + formatTs(post.created_at) : '');
        }

        mount.querySelectorAll('[data-action="mdv3-delete-comment"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = Number(btn.dataset.id);
                showPromptDialog('删除评论 #' + cid, '删除包含所有子评论，理由必填并通知作者', '请输入删除理由...', '删除评论', (val) => {
                    const v = (val || '').trim();
                    if (!v) { toastSafe('删除理由不能为空', 'error'); return; }
                    deleteComment(cid, v, () => loadPostDetailInto(postId, mount, () => {}));
                });
            });
        });
        onSuccess && onSuccess();
    }

    // ==================== 模块 3：删除评论（共用 API） ====================

    async function deleteComment(commentId, reason, onSuccess) {
        const $status = document.getElementById('mdv3-posts-status');
        setStatus($status, '正在删除评论 #' + commentId + ' ...', 'warn');
        const r = await mdv3Call('/api/admin/comment/delete', { id: Number(commentId), reason: String(reason || '') });
        if (!r.ok) {
            setStatus($status, '删除失败：' + r.error, 'error');
            toastSafe('删除评论失败：' + r.error, 'error');
            return;
        }
        setStatus($status, '删除成功，已删除评论 #' + (r.data.deleted || commentId), 'success');
        toastSafe('删除成功', 'success');
        onSuccess && onSuccess();
    }

    // ==================== 模块 4：举报列表 + 处理 ====================

    async function loadReportsList() {
        const $list = document.getElementById('mdv3-reports-list');
        const $status = document.getElementById('mdv3-reports-status');
        const $sel = document.getElementById('mdv3-report-status');
        if (!$list) return;
        const statusVal = $sel ? $sel.value : '';
        $list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:10px;">加载中...</div>';
        setStatus($status, '加载举报列表...', 'warn');
        const payload = {};
        if (statusVal) payload.status = statusVal;
        const r = await mdv3Call('/api/admin/reports', payload);
        if (!r.ok) {
            $list.innerHTML = '<div class="list-empty">' + escapeHtmlSafe(r.error || '加载失败') + '</div>';
            setStatus($status, '加载失败：' + r.error, 'error');
            toastSafe('加载举报列表失败：' + r.error, 'error');
            return;
        }
        const arr = (r.data && Array.isArray(r.data.reports)) ? r.data.reports : [];
        setStatus($status, '加载成功，共 ' + arr.length + ' 条', 'success');
        if (arr.length === 0) { $list.innerHTML = '<div class="list-empty">暂无举报记录</div>'; return; }
        const html = arr.map(rt => {
            const id = rt.id;
            const statusBadge = (rt.status === 'reviewed')
                ? '<span class="mdv3-status-badge mdv3-status-reviewed">已处理</span>'
                : '<span class="mdv3-status-badge mdv3-status-pending">待处理</span>';
            return '<div class="mdv3-row" data-report-id="' + escapeAttrSafe(String(id)) + '">' +
                '<div class="mdv3-row-head">' +
                '<span class="mdv3-row-title">举报 #' + escapeHtmlSafe(String(id)) + '</span>' +
                statusBadge +
                '<span class="mdv3-status-badge mdv3-status-neutral">目标：' + escapeHtmlSafe(rt.target_type || '-') + ' #' + escapeHtmlSafe(String(rt.target_id || '')) + '</span>' +
                '</div>' +
                '<div class="mdv3-row-body">理由：' + escapeHtmlSafe(rt.reason || '-') + '</div>' +
                '<div class="mdv3-row-meta">' +
                '<span>举报人：' + escapeHtmlSafe(rt.reporter || '-') + '</span>' +
                '<span>被举报：' + escapeHtmlSafe(rt.reported || '-') + '</span>' +
                '<span>IP:' + escapeHtmlSafe(rt.reporter_ip || '-') + '/FP:' + escapeHtmlSafe(rt.reporter_fp || '-') + '</span>' +
                '</div>' +
                '<div class="mdv3-row-meta">' +
                '<span>被举报 IP:' + escapeHtmlSafe(rt.reported_ip || '-') + ' / FP:' + escapeHtmlSafe(rt.reported_fp || '-') + '</span>' +
                '<span>' + formatTs(rt.created_at) + '</span>' +
                '</div>' +
                '<div class="mdv3-row-actions">' +
                (rt.status === 'pending'
                    ? '<button class="doodle-btn btn-xs btn-primary" data-action="mdv3-handle-report" data-id="' + escapeAttrSafe(String(id)) + '">处理（封禁/限制）</button>'
                    : '<button class="doodle-btn btn-xs" data-action="mdv3-handle-report" data-id="' + escapeAttrSafe(String(id)) + '">再次处理</button>') +
                '</div></div>';
        }).join('');
        $list.innerHTML = html;
        $list.querySelectorAll('[data-action="mdv3-handle-report"]').forEach(b => {
            b.addEventListener('click', () => {
                const id = Number(b.dataset.id);
                const cur = arr.find(x => Number(x.id) === id) || { id: id };
                showReportHandleDialog(cur, async (payload) => {
                    await handleReport(payload);
                });
            });
        });
    }

    async function handleReport(payload) {
        const $status = document.getElementById('mdv3-reports-status');
        setStatus($status, '正在处理举报 #' + payload.report_id + ' ...', 'warn');
        const r = await mdv3Call('/api/admin/report/handle', payload);
        if (!r.ok) {
            setStatus($status, '处理失败：' + r.error, 'error');
            toastSafe('处理举报失败：' + r.error, 'error');
            return;
        }
        const d = r.data || {};
        const info = 'action=' + escapeHtmlSafe(d.action || payload.action)
            + (d.restriction_id ? '，限制记录 #' + d.restriction_id : '')
            + (d.end_at ? '，到期：' + formatTs(d.end_at) : '');
        setStatus($status, '举报处理成功：' + info, 'success');
        toastSafe('处理成功', 'success');
        loadReportsList();
    }

    function bindReportsPanel() {
        const $sel = document.getElementById('mdv3-report-status');
        const $btn = document.getElementById('mdv3-reports-refresh');
        $sel && $sel.addEventListener('change', loadReportsList);
        $btn && $btn.addEventListener('click', loadReportsList);
    }

    // ==================== 模块 5：限制 / 封禁列表 + 解除 ====================

    function typeBadgeForRestriction(t) {
        if (t === 'ban')     return '<span class="mdv3-status-badge mdv3-type-ban">永久封禁</span>';
        if (t === 'post')    return '<span class="mdv3-status-badge mdv3-type-post">限制发帖</span>';
        if (t === 'comment') return '<span class="mdv3-status-badge mdv3-type-comment">限制评论</span>';
        return '<span class="mdv3-status-badge">' + escapeHtmlSafe(t || '') + '</span>';
    }

    async function loadRestrictionsList() {
        const $list = document.getElementById('mdv3-restrictions-list');
        const $status = document.getElementById('mdv3-restrictions-status');
        const $sel = document.getElementById('mdv3-restriction-type');
        if (!$list) return;
        const typeVal = $sel ? $sel.value : '';
        $list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:10px;">加载中...</div>';
        setStatus($status, '加载限制记录...', 'warn');
        const payload = {};
        if (typeVal) payload.type = typeVal;
        const r = await mdv3Call('/api/admin/restrictions', payload);
        if (!r.ok) {
            $list.innerHTML = '<div class="list-empty">' + escapeHtmlSafe(r.error || '加载失败') + '</div>';
            setStatus($status, '加载失败：' + r.error, 'error');
            toastSafe('加载限制记录失败：' + r.error, 'error');
            return;
        }
        const arr = (r.data && Array.isArray(r.data.restrictions)) ? r.data.restrictions : [];
        setStatus($status, '加载成功，共 ' + arr.length + ' 条', 'success');
        if (arr.length === 0) { $list.innerHTML = '<div class="list-empty">暂无限制记录</div>'; return; }
        const html = arr.map(rr => {
            const id = rr.id;
            const active = !!rr.active;
            const activeBadge = active
                ? '<span class="mdv3-status-badge mdv3-status-pending">生效中</span>'
                : '<span class="mdv3-status-badge mdv3-status-reviewed">已解除</span>';
            const endTs = Number(rr.end_at || 0);
            const endLabel = (rr.rtype === 'ban' || endTs === 0) ? '永久' : formatTs(rr.end_at);
            return '<div class="mdv3-row" data-rid="' + escapeAttrSafe(String(id)) + '">' +
                '<div class="mdv3-row-head">' +
                typeBadgeForRestriction(rr.rtype) + activeBadge +
                '<span class="mdv3-row-meta">' +
                '<span>记录 #' + escapeHtmlSafe(String(id)) + '</span>' +
                '<span>创建人：' + escapeHtmlSafe(rr.created_by || '-') + '</span>' +
                '</span></div>' +
                '<div class="mdv3-row-meta">' +
                '<span>昵称：' + escapeHtmlSafe(rr.nickname || '-') + '</span>' +
                '<span>IP:' + escapeHtmlSafe(rr.ip || '-') + '</span>' +
                '<span>FP:' + escapeHtmlSafe(rr.fp || '-') + '</span>' +
                (rr.token ? '<span>token:' + escapeHtmlSafe(String(rr.token).slice(0, 10) + '...') + '</span>' : '') +
                '</div>' +
                '<div class="mdv3-row-meta">' +
                '<span>开始：' + formatTs(rr.start_at) + '</span>' +
                '<span>结束：' + endLabel + '</span>' +
                '</div>' +
                (rr.reason ? '<div class="mdv3-row-body">理由：' + escapeHtmlSafe(rr.reason) + '</div>' : '') +
                '<div class="mdv3-row-actions">' +
                (active
                    ? '<button class="doodle-btn btn-xs" data-action="mdv3-remove-restriction" data-id="' + escapeAttrSafe(String(id)) + '">解除限制</button>'
                    : '') +
                '</div></div>';
        }).join('');
        $list.innerHTML = html;
        $list.querySelectorAll('[data-action="mdv3-remove-restriction"]').forEach(b => {
            b.addEventListener('click', () => {
                const id = Number(b.dataset.id);
                if (!confirm('确认解除限制记录 #' + id + '？')) return;
                removeRestriction(id);
            });
        });
    }

    async function removeRestriction(id) {
        const $status = document.getElementById('mdv3-restrictions-status');
        setStatus($status, '正在解除限制 #' + id + ' ...', 'warn');
        const r = await mdv3Call('/api/admin/restriction/remove', { id: Number(id) });
        if (!r.ok) {
            setStatus($status, '解除失败：' + r.error, 'error');
            toastSafe('解除限制失败：' + r.error, 'error');
            return;
        }
        setStatus($status, '已解除限制 #' + (r.data.removed || id) + '（' + (r.data.rtype || '') + '）', 'success');
        toastSafe('解除成功', 'success');
        loadRestrictionsList();
    }

    function bindRestrictionsPanel() {
        const $sel = document.getElementById('mdv3-restriction-type');
        const $btn = document.getElementById('mdv3-restrictions-refresh');
        $sel && $sel.addEventListener('change', loadRestrictionsList);
        $btn && $btn.addEventListener('click', loadRestrictionsList);
    }

    // ==================== 其他按钮绑定 ====================

    function bindPostsPanel() {
        const $btn = document.getElementById('mdv3-posts-refresh');
        $btn && $btn.addEventListener('click', loadPostsList);
    }

    // ==================== tab 切换自动加载 ====================

    function bindTabSwitchHook() {
        window.addEventListener('mdv3:tab-switched', (ev) => {
            const tab = (ev && ev.detail && ev.detail.tab) || '';
            if (tab === 'posts') loadPostsList();
            if (tab === 'reports') loadReportsList();
            if (tab === 'restrictions') loadRestrictionsList();
        });
    }

    // ==================== 初始化 ====================

    function init() {
        bindSidebarFold();
        bindSettingsPanel();
        bindPostsPanel();
        bindReportsPanel();
        bindRestrictionsPanel();
        bindTabSwitchHook();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
