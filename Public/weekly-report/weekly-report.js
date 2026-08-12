(function () {
    'use strict';

    // ==================== DOM 引用 ====================
    const $loading   = document.getElementById('loading-indicator');
    const $content   = document.getElementById('report-content');
    const $error     = document.getElementById('error-state');
    const $period    = document.getElementById('report-period');
    const $weekSel   = document.getElementById('week-select');
    const $overview  = document.getElementById('overview-cards');
    const $sortTabs  = document.getElementById('sort-tabs');
    const $playerList = document.getElementById('player-list');
    const $pagination = document.getElementById('pagination');

    // ==================== 排序维度 ====================
    const SORT_OPTIONS = [
        { key: 'total_games',         label: '总对局',   minGames: 0 },
        { key: 'total_wins',          label: '总胜场',   minGames: 0 },
        { key: 'win_rate',            label: '胜率',     minGames: 5 },
        { key: 'turing_games',        label: '图灵对局',  minGames: 0 },
        { key: 'turing_guess_accuracy', label: '猜对率',  minGames: 5 },
        { key: 'turing_best_streak',  label: '连胜',     minGames: 0 },
        { key: 'whoisai_games',       label: '谁是AI',   minGames: 0 },
        { key: 'gomoku_games',        label: '五子棋',   minGames: 0 },
    ];

    let currentSort  = 'total_games';
    let currentPage  = 1;
    let currentMinGames = 0;
    let allWeeks     = [];

    // ==================== 初始化 ====================
    function init() {
        // 主题按钮
        const btnTheme = document.getElementById('btn-theme');
        if (btnTheme && typeof setTheme !== 'undefined') {
            btnTheme.addEventListener('click', () => {
                const themes = ['default', 'dark', 'system'];
                const cur = getStoredTheme();
                const next = themes[(themes.indexOf(cur) + 1) % themes.length];
                setTheme(next);
            });
        }
        loadReport();
    }

    // ==================== 加载数据 ====================
    async function loadReport(week) {
        $loading.style.display = 'block';
        $content.style.display = 'none';
        $error.style.display = 'none';

        try {
            let url = '/api/weekly-report';
            const params = new URLSearchParams();
            if (week) params.set('week', week);
            params.set('sort', currentSort);
            params.set('page', currentPage);
            params.set('limit', '20');
            if (currentMinGames > 0) params.set('min_games', currentMinGames);
            url += '?' + params.toString();

            const resp = await fetch(url);
            const data = await resp.json();

            if (data.error) {
                showError(data.error);
                return;
            }

            allWeeks = data.available_weeks || [];
            render(data);
        } catch (e) {
            showError('加载周报失败: ' + e.message);
        }
    }

    // ==================== 渲染 ====================
    function render(data) {
        $loading.style.display = 'none';
        $content.style.display = 'block';

        const overview = data.overview || {};
        $period.textContent = `${overview.period_start || ''} ~ ${overview.period_end || ''}`;

        // 周选择器
        renderWeekSelector(data.week);

        // 总览卡片
        renderOverview(overview);

        // 排序标签
        renderSortTabs();

        // 玩家列表
        renderPlayers(data.players || []);

        // 分页
        renderPagination(data.pagination || {});

        $content.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderWeekSelector(currentWeek) {
        if (allWeeks.length <= 1) {
            $weekSel.parentElement.style.display = 'none';
            return;
        }
        $weekSel.parentElement.style.display = '';
        $weekSel.innerHTML = allWeeks.map(w =>
            `<option value="${w.week}" ${w.week === currentWeek ? 'selected' : ''}>${w.week}（${w.generated_at}）</option>`
        ).join('');
        $weekSel.onchange = () => {
            currentPage = 1;
            loadReport($weekSel.value);
        };
    }

    function renderOverview(overview) {
        const cards = [
            { value: overview.total_players || 0, label: '总玩家' },
            { value: overview.active_players || 0, label: '活跃玩家' },
            { value: overview.total_games || 0, label: '总局数' },
            { value: overview.avg_games_per_player || '0', label: '人均局数' },
            { value: overview.avg_win_rate + '%' || '0%', label: '平均胜率' },
            { value: overview.turing_games || 0, label: '图灵测试' },
            { value: overview.whoisai_games || 0, label: '谁是AI' },
            { value: overview.gomoku_games || 0, label: '五子棋' },
        ];
        $overview.innerHTML = cards.map(c =>
            `<div class="overview-card"><div class="oc-value">${c.value}</div><div class="oc-label">${c.label}</div></div>`
        ).join('');
    }

    function renderSortTabs() {
        $sortTabs.innerHTML = SORT_OPTIONS.map(opt =>
            `<button class="sort-tab${opt.key === currentSort ? ' active' : ''}"
                data-sort="${opt.key}" data-min="${opt.minGames}">${opt.label}</button>`
        ).join('');

        $sortTabs.querySelectorAll('.sort-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                currentSort = btn.dataset.sort;
                currentMinGames = parseInt(btn.dataset.min, 10) || 0;
                currentPage = 1;
                $sortTabs.querySelectorAll('.sort-tab').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                loadReport($weekSel.value || undefined);
            });
        });
    }

    function renderPlayers(players) {
        if (!players.length) {
            $playerList.innerHTML =
                '<div class="report-loading" style="padding:30px;">暂无数据</div>';
            return;
        }

        const sortOpt = SORT_OPTIONS.find(o => o.key === currentSort);
        const sortKey = sortOpt ? sortOpt.key : 'total_games';

        $playerList.innerHTML = players.map((p, i) => {
            const globalRank = (currentPage - 1) * 20 + i + 1;
            let rankClass = '';
            if (globalRank === 1) rankClass = 'top1';
            else if (globalRank === 2) rankClass = 'top2';
            else if (globalRank === 3) rankClass = 'top3';

            let scoreText = p[sortKey] ?? '';
            if (sortKey === 'win_rate' || sortKey === 'turing_guess_accuracy') {
                scoreText = p[sortKey] + '%';
            }

            const detail = [
                `<span>对局 <b class="highlight">${p.total_games}</b></span>`,
                `<span>胜 <b class="highlight">${p.total_wins}</b></span>`,
            ];
            if (p.turing_best_streak > 0) {
                detail.push(`<span>连胜 <b class="highlight">${p.turing_best_streak}</b></span>`);
            }
            if (p.turing_guess_accuracy > 0) {
                detail.push(`<span>猜对 <b class="highlight">${p.turing_guess_accuracy}%</b></span>`);
            }

            return `
                <div class="player-row">
                    <div class="player-rank ${rankClass}">${globalRank}</div>
                    <div class="player-info">
                        <div class="player-name">${esc(p.nickname)}<span class="disc">#${p.discriminator}</span></div>
                        <div class="player-detail">${detail.join('')}</div>
                    </div>
                    <div class="player-score">${scoreText}<div class="score-label">${sortOpt.label}</div></div>
                </div>`;
        }).join('');
    }

    function renderPagination(pg) {
        if (pg.total_pages <= 1) {
            $pagination.innerHTML = '';
            return;
        }
        const totalPages = pg.total_pages || 1;
        const page = pg.page || 1;
        let html = '';

        html += `<button class="doodle-btn" ${page <= 1 ? 'disabled' : ''} data-page="1">&laquo;</button>`;
        html += `<button class="doodle-btn" ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}">&lsaquo;</button>`;

        const start = Math.max(1, page - 2);
        const end   = Math.min(totalPages, page + 2);
        if (start > 1) html += `<button class="doodle-btn" data-page="1">1</button>`;
        if (start > 2) html += `<span class="page-info">…</span>`;
        for (let i = start; i <= end; i++) {
            html += `<button class="doodle-btn${i === page ? ' active' : ''}" data-page="${i}">${i}</button>`;
        }
        if (end < totalPages - 1) html += `<span class="page-info">…</span>`;
        if (end < totalPages) html += `<button class="doodle-btn" data-page="${totalPages}">${totalPages}</button>`;

        html += `<button class="doodle-btn" ${page >= totalPages ? 'disabled' : ''} data-page="${page + 1}">&rsaquo;</button>`;
        html += `<button class="doodle-btn" ${page >= totalPages ? 'disabled' : ''} data-page="${totalPages}">&raquo;</button>`;

        $pagination.innerHTML = html;
        $pagination.querySelectorAll('button[data-page]').forEach(btn => {
            if (btn.disabled) return;
            btn.addEventListener('click', () => {
                currentPage = parseInt(btn.dataset.page, 10);
                loadReport($weekSel.value || undefined);
                window.scrollTo({ top: $content.offsetTop - 80, behavior: 'smooth' });
            });
        });
    }

    function showError(msg) {
        $loading.style.display = 'none';
        $content.style.display = 'none';
        $error.style.display = 'block';
        $error.textContent = msg;
    }

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    init();
})();
