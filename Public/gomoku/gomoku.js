'use strict';

// ================= DOM 引用 =================
const pages = {
    menu: document.getElementById('page-menu'),
    setup: document.getElementById('page-setup'),
    wait: document.getElementById('page-wait'),
    join: document.getElementById('page-join'),
    game: document.getElementById('page-game'),
};
const canvas = document.getElementById('boardCanvas');
const ctx = canvas ? canvas.getContext('2d') : null;
let boardSize = 15;
const padding = 30;
let cellSize = 0;

// ================= 游戏状态 =================
const BLACK = 1, WHITE = 2;
let board = [];
let currentPlayer = BLACK;
let gameOver = false;
let lastMove = null;
let isAnimating = false;

// 单机
let isOnline = false;
let isSpectator = false;
let myColor = BLACK;
let humanColor = BLACK, aiColor = WHITE;
let difficulty = 'normal';
let mode = 'normal';
let timeLimitSec = 0;
let timerInterval = null;
let timeLeft = 0;

// 在线
let ws = null;
let roomId = '';
let opponentFd = null;
let heartbeatTimer = null;
let pongTimer = null;
let reconnectTimer = null;
let reconnecting = false;
let intentionalClose = false;
let _pendingRecoveryCode = '';
let _pendingNickname = '';

// ================= 音效 =================
const playStoneSound = () => {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const audioCtx = new AudioContext();
        const now = audioCtx.currentTime;
        const bufferSize = audioCtx.sampleRate * 0.04;
        const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < bufferSize; i++) data[i] = Math.random() * 2 - 1;
        const noise1 = audioCtx.createBufferSource();
        noise1.buffer = buffer;
        const noiseFilter1 = audioCtx.createBiquadFilter();
        noiseFilter1.type = 'bandpass';
        noiseFilter1.frequency.setValueAtTime(2500, now);
        noiseFilter1.Q.setValueAtTime(4, now);
        const noiseGain1 = audioCtx.createGain();
        noiseGain1.gain.setValueAtTime(0.4, now);
        noiseGain1.gain.exponentialRampToValueAtTime(0.001, now + 0.015);
        noise1.connect(noiseFilter1);
        noiseFilter1.connect(noiseGain1);
        noiseGain1.connect(audioCtx.destination);
        noise1.start(now);
        noise1.stop(now + 0.02);
        const freqs = [135, 250, 420];
        const gains = [0.45, 0.22, 0.08];
        const decays = [0.12, 0.07, 0.03];
        freqs.forEach((freq, i) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, now);
            osc.frequency.exponentialRampToValueAtTime(freq * 0.85, now + decays[i]);
            gain.gain.setValueAtTime(gains[i], now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + decays[i]);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now);
            osc.stop(now + decays[i]);
        });
    } catch (e) {}
};

// ================= 页面导航 =================
function showPage(pageId) {
    Object.values(pages).forEach(p => { if (p) p.style.display = 'none'; });
    const target = pages[pageId];
    if (target) target.style.display = 'flex';
}

// ================= 棋盘绘制 =================
function initCanvas() {
    if (!canvas) return;
    const size = 500;
    canvas.width = size;
    canvas.height = size;
    canvas.style.width = Math.min(500, window.innerWidth - 32) + 'px';
    canvas.style.height = Math.min(500, window.innerWidth - 32) + 'px';
    cellSize = (canvas.width - padding * 2) / (boardSize - 1);
}

function drawBoard() {
    if (!ctx) return;
    const w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    // 棋盘底色
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--surface-white').trim() || '#ffffff';
    ctx.fillRect(0, 0, w, h);

    // 坐标标注
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-subtle').trim() || '#888';
    ctx.font = '11px "LXGW WenKai", "Patrick Hand", cursive';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (let i = 0; i < boardSize; i++) {
        const pos = padding + i * cellSize;
        ctx.fillText(String.fromCharCode(65 + i), pos, padding / 2);
        ctx.fillText(String.fromCharCode(65 + i), pos, h - padding / 2);
        ctx.fillText((i + 1).toString(), padding / 2, pos);
        ctx.fillText((i + 1).toString(), w - padding / 2, pos);
    }

    // 网格线
    ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--border-light').trim() || '#ccc';
    ctx.lineWidth = 1;
    for (let i = 0; i < boardSize; i++) {
        const pos = Math.floor(padding + i * cellSize) + 0.5;
        ctx.beginPath();
        ctx.moveTo(padding, pos);
        ctx.lineTo(w - padding, pos);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(pos, padding);
        ctx.lineTo(pos, h - padding);
        ctx.stroke();
    }

    // 星位
    if (boardSize === 15) {
        const stars = [[3, 3], [11, 3], [3, 11], [11, 11], [7, 7]];
        ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--ink-black').trim() || '#2b2b2b';
        stars.forEach(([r, c]) => {
            ctx.beginPath();
            ctx.arc(padding + c * cellSize, padding + r * cellSize, 3, 0, Math.PI * 2);
            ctx.fill();
        });
    }
}

function drawPiece(r, c, color) {
    if (!ctx) return;
    const x = padding + c * cellSize;
    const y = padding + r * cellSize;
    const radius = cellSize * 0.44;
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
    ctx.shadowBlur = 5;
    ctx.shadowOffsetX = 2;
    ctx.shadowOffsetY = 3;
    const gradient = ctx.createRadialGradient(x - radius * 0.25, y - radius * 0.25, radius * 0.1, x, y, radius);
    if (color === BLACK) {
        gradient.addColorStop(0, '#6c6c6c');
        gradient.addColorStop(0.3, '#2a2a2a');
        gradient.addColorStop(1, '#050505');
    } else {
        gradient.addColorStop(0, '#ffffff');
        gradient.addColorStop(0.7, '#ececec');
        gradient.addColorStop(1, '#c0c0c0');
    }
    ctx.fillStyle = gradient;
    ctx.fill();
    ctx.shadowBlur = 0;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 0;
}

function drawAllPieces(skipR, skipC) {
    drawBoard();
    for (let r = 0; r < boardSize; r++) {
        for (let c = 0; c < boardSize; c++) {
            if (board[r][c] !== 0 && (r !== skipR || c !== skipC)) {
                drawPiece(r, c, board[r][c]);
            }
        }
    }
}

function drawLastMoveMarker() {
    if (!ctx || !lastMove) return;
    const [r, c] = lastMove;
    const x = padding + c * cellSize;
    const y = padding + r * cellSize;
    const s = cellSize * 0.2;
    const offset = cellSize * 0.36;
    ctx.strokeStyle = 'rgba(217, 65, 50, 0.85)';
    ctx.lineWidth = 2;
    ctx.lineCap = 'square';
    for (const [sx, sy] of [[-1, -1], [1, -1], [-1, 1], [1, 1]]) {
        ctx.beginPath();
        ctx.moveTo(x + sx * offset, y + sy * (offset - s));
        ctx.lineTo(x + sx * offset, y + sy * offset);
        ctx.lineTo(x + sx * (offset - s), y + sy * offset);
        ctx.stroke();
    }
}

// ================= 落子动画 =================
function placePieceAnim(r, c, color, isLocalAction) {
    isAnimating = true;
    board[r][c] = color;
    lastMove = [r, c];
    const startTime = performance.now();
    const duration = 250;
    const step = (now) => {
        let progress = (now - startTime) / duration;
        if (progress > 1) progress = 1;
        let easeProgress = 1 - Math.pow(1 - progress, 3);
        drawAllPieces(r, c);
        ctx.save();
        ctx.globalAlpha = easeProgress;
        let scale = 1.3 - 0.3 * easeProgress;
        const px = padding + c * cellSize, py = padding + r * cellSize;
        ctx.translate(px, py);
        ctx.scale(scale, scale);
        ctx.translate(-px, -py);
        drawPiece(r, c, color);
        ctx.restore();
        if (progress === 1) { drawLastMoveMarker(); playStoneSound(); }
        if (progress < 1) requestAnimationFrame(step);
        else {
            isAnimating = false;
            onPiecePlaced(r, c, color, isLocalAction);
        }
    };
    requestAnimationFrame(step);
}

function onPiecePlaced(r, c, color, isLocalAction) {
    currentPlayer = color === BLACK ? WHITE : BLACK;
    updateTurnDisplay();
    if (mode === 'time') resetTimer();
    if (!isOnline) {
        const winPath = checkWin(r, c, color);
        if (winPath) { playWinAnim(winPath, color, color === humanColor ? '胜天半子，您赢了！' : '惜败，电脑获胜。'); return; }
        if (checkDraw()) { endGame(0, '棋局僵持，平局！'); return; }
        if (!gameOver && currentPlayer === aiColor && isLocalAction) {
            setTimeout(aiMove, Math.random() * 300 + 300);
        }
    }
}

function playWinAnim(path, winner, msg) {
    isAnimating = true;
    const startTime = performance.now();
    const duration = 500;
    const x0 = padding + path[0][1] * cellSize, y0 = padding + path[0][0] * cellSize;
    const x1 = padding + path[4][1] * cellSize, y1 = padding + path[4][0] * cellSize;
    const step = (now) => {
        let progress = (now - startTime) / duration;
        if (progress > 1) progress = 1;
        let easeProgress = 1 - Math.pow(1 - progress, 4);
        drawAllPieces(-1, -1);
        drawLastMoveMarker();
        ctx.beginPath();
        ctx.moveTo(x0, y0);
        ctx.lineTo(x0 + (x1 - x0) * easeProgress, y0 + (y1 - y0) * easeProgress);
        ctx.strokeStyle = 'rgba(168, 66, 50, 0.85)';
        ctx.lineWidth = 6;
        ctx.lineCap = 'round';
        ctx.stroke();
        if (progress < 1) requestAnimationFrame(step);
        else { isAnimating = false; endGame(winner, msg); }
    };
    requestAnimationFrame(step);
}

// ================= 对局结束 =================
function endGame(winner, msg) {
    gameOver = true;
    clearInterval(timerInterval);
    document.getElementById('turn-display').textContent = msg;

    const btnSurrender = document.getElementById('btn-surrender');
    const btnRematch = document.getElementById('btn-rematch');
    if (btnSurrender) btnSurrender.style.display = 'none';
    if (btnRematch) { btnRematch.style.display = isSpectator ? 'none' : 'inline-flex'; btnRematch.textContent = '再弈一局'; }

    const overlay = document.getElementById('gameover-overlay');
    const titleEl = document.getElementById('gameover-title');
    const reasonEl = document.getElementById('gameover-reason');
    if (overlay && titleEl && reasonEl) {
        overlay.style.display = 'flex';
        titleEl.textContent = msg;
        reasonEl.textContent = '';
        if (!isOnline) {
            document.getElementById('btn-gameover-rematch').onclick = () => {
                overlay.style.display = 'none';
                initGame();
            };
        }
    }
}

// ================= 判定 =================
function checkWin(r, c, color) {
    const dirs = [[1, 0], [0, 1], [1, 1], [1, -1]];
    for (let [dr, dc] of dirs) {
        let path = [[r, c]];
        for (let step = 1; step <= 4; step++) {
            let nr = r + dr * step, nc = c + dc * step;
            if (nr >= 0 && nr < boardSize && nc >= 0 && nc < boardSize && board[nr][nc] === color) path.push([nr, nc]);
            else break;
        }
        for (let step = 1; step <= 4; step++) {
            let nr = r - dr * step, nc = c - dc * step;
            if (nr >= 0 && nr < boardSize && nc >= 0 && nc < boardSize && board[nr][nc] === color) path.unshift([nr, nc]);
            else break;
        }
        if (path.length >= 5) return path.slice(0, 5);
    }
    return null;
}

function checkWinFast(r, c, color) {
    const dirs = [[1, 0], [0, 1], [1, 1], [1, -1]];
    for (let [dr, dc] of dirs) {
        let count = 1;
        for (let step = 1; step <= 4; step++) {
            let nr = r + dr * step, nc = c + dc * step;
            if (nr >= 0 && nr < boardSize && nc >= 0 && nc < boardSize && board[nr][nc] === color) count++;
            else break;
        }
        for (let step = 1; step <= 4; step++) {
            let nr = r - dr * step, nc = c - dc * step;
            if (nr >= 0 && nr < boardSize && nc >= 0 && nc < boardSize && board[nr][nc] === color) count++;
            else break;
        }
        if (count >= 5) return true;
    }
    return false;
}

function checkDraw() { return board.every(row => row.every(cell => cell !== 0)); }

// ================= AI =================
function aiMove() {
    if (gameOver || isAnimating) return;
    if (currentPlayer === BLACK && lastMove === null) {
        const center = Math.floor(boardSize / 2);
        placePieceAnim(center, center, aiColor, true);
        return;
    }
    const candidates = getSmartCandidates();
    if (candidates.length === 0) return;
    for (const cand of candidates) {
        if (evaluatePoint(cand.r, cand.c, aiColor) >= 100000) {
            placePieceAnim(cand.r, cand.c, aiColor, true);
            return;
        }
    }
    for (const cand of candidates) {
        if (evaluatePoint(cand.r, cand.c, humanColor) >= 100000) {
            placePieceAnim(cand.r, cand.c, aiColor, true);
            return;
        }
    }
    if (difficulty === 'master') {
        aiMoveMaster(candidates);
    } else {
        aiMoveGreedy(candidates);
    }
}

function aiMoveMaster(candidates) {
    let bestMove = null, maxVal = -Infinity;
    let alpha = -Infinity, beta = Infinity;
    const searchDepth = 3;
    const branchLimit = Math.min(candidates.length, 15);
    for (let i = 0; i < branchLimit; i++) {
        const { r, c } = candidates[i];
        board[r][c] = aiColor;
        const val = minimax(searchDepth - 1, alpha, beta, false);
        board[r][c] = 0;
        if (val > maxVal) { maxVal = val; bestMove = { r, c }; }
        alpha = Math.max(alpha, val);
    }
    if (bestMove) {
        placePieceAnim(bestMove.r, bestMove.c, aiColor, true);
    } else {
        aiMoveGreedy(candidates);
    }
}

function minimax(depth, alpha, beta, isAI) {
    if (depth === 0) return evaluateFullBoard();
    const candidates = getSmartCandidates();
    if (candidates.length === 0) return 0;
    const branchLimit = Math.min(candidates.length, 10);
    if (isAI) {
        let maxEval = -Infinity;
        for (let i = 0; i < branchLimit; i++) {
            const { r, c } = candidates[i];
            board[r][c] = aiColor;
            if (checkWinFast(r, c, aiColor)) { board[r][c] = 0; return 1000000 + depth; }
            const evaluation = minimax(depth - 1, alpha, beta, false);
            board[r][c] = 0;
            maxEval = Math.max(maxEval, evaluation);
            alpha = Math.max(alpha, evaluation);
            if (beta <= alpha) break;
        }
        return maxEval;
    } else {
        let minEval = Infinity;
        for (let i = 0; i < branchLimit; i++) {
            const { r, c } = candidates[i];
            board[r][c] = humanColor;
            if (checkWinFast(r, c, humanColor)) { board[r][c] = 0; return -1000000 - depth; }
            const evaluation = minimax(depth - 1, alpha, beta, true);
            board[r][c] = 0;
            minEval = Math.min(minEval, evaluation);
            beta = Math.min(beta, evaluation);
            if (beta <= alpha) break;
        }
        return minEval;
    }
}

function evaluateFullBoard() {
    let aiTotal = 0, humanTotal = 0;
    for (let r = 0; r < boardSize; r++) {
        for (let c = 0; c < boardSize; c++) {
            if (board[r][c] === aiColor) aiTotal += evaluatePoint(r, c, aiColor);
            else if (board[r][c] === humanColor) humanTotal += evaluatePoint(r, c, humanColor);
        }
    }
    return aiTotal - humanTotal * 1.2;
}

function aiMoveGreedy(candidates) {
    let bestMoves = [], maxScore = -1;
    candidates.forEach((cand) => {
        const r = cand.r, c = cand.c;
        const attackScore = evaluatePoint(r, c, aiColor);
        const defenseScore = evaluatePoint(r, c, humanColor);
        let totalScore = 0;
        if (difficulty === 'normal') {
            totalScore = attackScore + defenseScore + Math.random() * 10;
        } else {
            totalScore = attackScore + defenseScore * 0.3 + Math.random() * 500;
        }
        if (totalScore > maxScore) { maxScore = totalScore; bestMoves = [[r, c]]; }
        else if (totalScore === maxScore) bestMoves.push([r, c]);
    });
    if (difficulty === 'beginner' && maxScore < 10000 && Math.random() < 0.25) {
        const m = candidates[Math.floor(Math.random() * candidates.length)];
        placePieceAnim(m.r, m.c, aiColor, true);
        return;
    }
    const move = bestMoves[Math.floor(Math.random() * bestMoves.length)];
    if (move) placePieceAnim(move[0], move[1], aiColor, true);
}

function getSmartCandidates() {
    const candidates = [];
    for (let r = 0; r < boardSize; r++) {
        for (let c = 0; c < boardSize; c++) {
            if (board[r][c] === 0) {
                let hasN = false;
                for (let i = -2; i <= 2; i++) {
                    for (let j = -2; j <= 2; j++) {
                        const nr = r + i, nc = c + j;
                        if (nr >= 0 && nr < boardSize && nc >= 0 && nc < boardSize && board[nr][nc] !== 0) {
                            hasN = true; break;
                        }
                    }
                    if (hasN) break;
                }
                if (hasN) {
                    const score = evaluatePoint(r, c, aiColor) + evaluatePoint(r, c, humanColor) * 1.1;
                    candidates.push({ r, c, score });
                }
            }
        }
    }
    if (candidates.length === 0) {
        for (let r = 0; r < boardSize; r++) {
            for (let c = 0; c < boardSize; c++) {
                if (board[r][c] === 0) candidates.push({ r, c, score: 0 });
            }
        }
    }
    candidates.sort((a, b) => b.score - a.score);
    return candidates;
}

function evaluatePoint(r, c, color) {
    let score = 0;
    const dirs = [[1, 0], [0, 1], [1, 1], [1, -1]];
    for (const [dr, dc] of dirs) {
        let str = '';
        for (let i = -4; i <= 4; i++) {
            if (i === 0) { str += '1'; continue; }
            const nr = r + dr * i, nc = c + dc * i;
            if (nr < 0 || nr >= boardSize || nc < 0 || nc >= boardSize) str += '2';
            else if (board[nr][nc] === color) str += '1';
            else if (board[nr][nc] === 0) str += '0';
            else str += '2';
        }
        if (str.includes('11111')) score += 100000;
        else if (str.includes('011110')) score += 10000;
        else if (str.includes('011112') || str.includes('211110') || str.includes('10111') || str.includes('11101') || str.includes('11011')) score += 1500;
        else if (str.includes('01110') || str.includes('010110') || str.includes('011010')) score += 1000;
        else if (str.includes('001112') || str.includes('211100') || str.includes('210112') || str.includes('211012') || str.includes('10011') || str.includes('11001')) score += 100;
        else if (str.includes('01100') || str.includes('00110') || str.includes('01010')) score += 50;
        else if (str.includes('000112') || str.includes('211000')) score += 10;
        else score += 1;
    }
    return score;
}

// ================= 计时器 =================
function startTimer() {
    clearInterval(timerInterval);
    updateTimerDisplay();
    timerInterval = setInterval(() => {
        if (gameOver || isAnimating) return;
        timeLeft--;
        updateTimerDisplay();
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            if (isOnline && !isSpectator && ws && currentPlayer === myColor) {
                ws.send(JSON.stringify({ type: 'gomoku_timeout' }));
            } else if (!isOnline && !isSpectator) {
                if (currentPlayer === humanColor) {
                    endGame(aiColor, '漏算超时，电脑获胜！');
                } else {
                    endGame(humanColor, '电脑超时，您赢了！');
                }
            }
        }
    }, 1000);
}

function resetTimer() {
    if (mode !== 'time') return;
    clearInterval(timerInterval);
    timeLeft = timeLimitSec;
    updateTimerDisplay();
    startTimer();
}

function updateTimerDisplay() {
    const el = document.getElementById('timer-display');
    if (!el) return;
    const m = Math.floor(timeLeft / 60);
    const s = timeLeft % 60;
    el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    if (timeLeft <= 10) {
        el.style.color = 'var(--danger)';
    } else {
        el.style.color = 'var(--ink-blue)';
    }
}

// ================= UI =================
function updateTurnDisplay() {
    const el = document.getElementById('turn-display');
    if (!el) return;
    if (gameOver) return;
    const name = currentPlayer === BLACK ? '黑棋' : '白棋';
    el.textContent = name + '行棋';
}

// ================= 棋盘点击 =================
if (canvas) {
    canvas.addEventListener('click', (e) => {
        if (gameOver || isAnimating || isSpectator) return;
        if (isOnline && currentPlayer !== myColor) return;

        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const x = (e.clientX - rect.left) * scaleX - padding;
        const y = (e.clientY - rect.top) * scaleY - padding;
        const c = Math.round(x / cellSize);
        const r = Math.round(y / cellSize);
        if (r >= 0 && r < boardSize && c >= 0 && c < boardSize && board[r][c] === 0) {
            if (isOnline) {
                ws.send(JSON.stringify({ type: 'gomoku_place_piece', r, c }));
            } else if (currentPlayer === humanColor) {
                placePieceAnim(r, c, humanColor, true);
            }
        }
    });
}

// ================= 游戏初始化 =================
function initGame() {
    board = Array.from({ length: boardSize }, () => Array(boardSize).fill(0));
    currentPlayer = BLACK;
    gameOver = false;
    lastMove = null;
    isAnimating = false;
    initCanvas();
    drawBoard();
    updateTurnDisplay();

    document.getElementById('btn-surrender').style.display = isSpectator ? 'none' : 'inline-flex';
    document.getElementById('btn-rematch').style.display = 'none';
    document.getElementById('gameover-overlay').style.display = 'none';

    // 聊天区控制
    const chatArea = document.getElementById('chat-area');
    const specBadge = document.getElementById('spectator-badge');
    if (chatArea) chatArea.style.display = isOnline ? 'flex' : 'none';
    if (specBadge) specBadge.style.display = isSpectator ? 'inline' : 'none';

    // 联机时禁用观战者输入
    const chatInputArea = document.querySelector('.game-chat-area .chat-input-area');
    if (chatInputArea) chatInputArea.style.display = isSpectator ? 'none' : 'flex';

    // 清空聊天
    const chatMsgs = document.getElementById('chat-messages');
    if (chatMsgs) chatMsgs.innerHTML = '<div class="chat-msg sys">对局开始，落子为定</div>';

    // AI 先手
    if (!isOnline && currentPlayer === aiColor) {
        setTimeout(aiMove, 600);
    }

    // 计时器
    clearInterval(timerInterval);
    const timerEl = document.getElementById('timer-display');
    if (timerEl) {
        if (mode === 'time') {
            timeLeft = timeLimitSec;
            timerEl.style.display = 'block';
            updateTimerDisplay();
            startTimer();
        } else {
            timerEl.style.display = 'none';
        }
    }
}

// ================= 单机模式 =================
function readBoardSize() {
    const checked = document.querySelector('input[name="boardSize"]:checked');
    if (!checked) return 15;
    if (checked.value === 'custom') {
        const v = parseInt(document.getElementById('custom-board-size').value);
        return (v >= 5 && v <= 30) ? v : 15;
    }
    return parseInt(checked.value);
}

function startLocalGame() {
    isOnline = false;
    isSpectator = false;

    const diffInput = document.querySelector('input[name="difficulty"]:checked');
    difficulty = diffInput ? diffInput.value : 'normal';

    boardSize = readBoardSize();

    const modeInput = document.querySelector('input[name="mode"]:checked');
    mode = modeInput ? modeInput.value : 'normal';

    const timeInput = document.querySelector('input[name="timeLimit"]:checked');
    timeLimitSec = timeInput ? parseInt(timeInput.value) : 0;

    const firstInput = document.querySelector('input[name="firstMove"]:checked');
    const hostFirst = firstInput ? firstInput.value === 'host' : true;
    humanColor = hostFirst ? BLACK : WHITE;
    aiColor = hostFirst ? WHITE : BLACK;
    myColor = humanColor;

    showPage('game');
    initGame();
}

// ================= 在线模式 - WebSocket =================
function connectWs(afterOpen) {
    if (ws && ws.readyState === WebSocket.OPEN) { afterOpen(); return; }
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = proto + '//' + window.location.host + '/ws/gomoku';
    ws = new WebSocket(url);
    ws.onopen = () => {
        console.log('[Gomoku] WS connected');
        reconnecting = false;
        // 发送指纹和已有身份信息
        ws.send(JSON.stringify({
            type: 'gomoku_join',
            fp: getFingerprint(),
            player_id: getUserPlayerId() || '',
            recovery_code: getUserRecoveryCode() || _pendingRecoveryCode || '',
            nickname: _pendingNickname || getUserNickname() || ''
        }));
        // 启动心跳
        startHeartbeat();
        afterOpen();
    };
    ws.onerror = () => {
        showTopToast('无法连接到服务器', true);
    };
    ws.onclose = () => {
        console.log('[Gomoku] WS closed');
        stopHeartbeat();
        if (!intentionalClose) scheduleReconnect();
        intentionalClose = false;
    };
    ws.onmessage = (e) => {
        let msg;
        try { msg = JSON.parse(e.data); } catch (_) { return; }
        handleWsMsg(msg);
    };
}

function startHeartbeat() {
    stopHeartbeat();
    heartbeatTimer = setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'ping' }));
        }
        if (pongTimer) clearTimeout(pongTimer);
        pongTimer = setTimeout(() => {
            console.log('[Gomoku] Pong timeout');
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
            // 重连后如果在房间中，重新加入
            if (roomId && !gameOver) {
                ws.send(JSON.stringify({ type: 'gomoku_join_room', roomId }));
            }
        });
    }, RECONNECT_DELAY);
}

function handleWsMsg(msg) {
    const { type, data } = msg;
    switch (type) {
        case 'gomoku_connected':
            break;

        case 'gomoku_joined':
            if (data && data.player_id && !getUserPlayerId()) {
                setUserPlayerId(data.player_id);
            }
            if (data && data.recovery_code && !getUserRecoveryCode()) {
                setUserRecoveryCode(data.recovery_code);
            }
            _pendingRecoveryCode = '';
            _pendingNickname = '';
            break;

        case 'gomoku_error':
            showTopToast(data || '未知错误', true);
            _pendingRecoveryCode = '';
            _pendingNickname = '';
            showIdentityState();
            break;

        case 'gomoku_room_created':
            roomId = data.roomId;
            myColor = data.color;
            document.getElementById('wait-code').textContent = roomId;
            showPage('wait');
            break;

        case 'gomoku_game_start':
            isSpectator = false;
            myColor = data.myColor;
            if (data.settings && data.settings.boardSize) {
                boardSize = parseInt(data.settings.boardSize);
            }
            if (data.settings) {
                mode = data.settings.mode || 'normal';
                timeLimitSec = parseInt(data.settings.timeLimit) || 0;
            }
            showPage('game');
            initGame();
            break;

        case 'gomoku_spectate_start':
            isSpectator = true;
            myColor = 0;
            if (data.settings && data.settings.boardSize) {
                boardSize = parseInt(data.settings.boardSize);
            }
            if (data.settings) {
                mode = data.settings.mode || 'normal';
                timeLimitSec = parseInt(data.settings.timeLimit) || 0;
            }
            showPage('game');
            initGame();
            if (data.board) {
                for (let r = 0; r < boardSize; r++) {
                    for (let c = 0; c < boardSize; c++) {
                        if (data.board[r][c] !== 0) {
                            board[r][c] = data.board[r][c];
                            drawPiece(r, c, data.board[r][c]);
                        }
                    }
                }
            }
            currentPlayer = data.currentTurn;
            updateTurnDisplay();
            showTopToast('房间已满，您以观战身份加入', false);
            break;

        case 'gomoku_piece_placed':
            placePieceAnim(data.r, data.c, data.color, false);
            break;

        case 'gomoku_game_over':
            if (data.reason === 'win') {
                const msgText = isSpectator
                    ? (data.winner === BLACK ? '黑方胜出！' : '白方胜出！')
                    : (data.winner === myColor ? '胜天半子，您赢了！' : '很遗憾，对手获胜。');
                if (data.winPath) playWinAnim(data.winPath, data.winner, msgText);
                else endGame(data.winner, msgText);
            } else if (data.reason === 'draw') {
                endGame(0, '棋局僵持，平局！');
            } else {
                const txt = data.reason === '认输' ? '推枰认输' : (data.reason === '超时' ? '漏算超时' : data.reason);
                const msgText = isSpectator
                    ? (data.winner === BLACK ? `黑方胜（白方${txt}）` : `白方胜（黑方${txt}）`)
                    : (data.winner === myColor ? `对方${txt}，您赢了！` : `您已${txt}，对手获胜。`);
                endGame(data.winner, msgText);
            }
            break;

        case 'gomoku_chat_message':
            if (data.msg) appendChat(data.msg, 'other');
            break;

        case 'gomoku_opponent_disconnected':
            showTopToast(data.msg || '对手已离开', true);
            endGame(myColor, '对手已离开，您赢了！');
            break;

        case 'pong':
            if (pongTimer) { clearTimeout(pongTimer); pongTimer = null; }
            break;

        case 'system':
            // system 消息字段是 msg.text 而非 msg.data.text
            if (msg.text && (msg.text.indexOf('活跃连接') !== -1 || msg.text.indexOf('已在其他地方登录') !== -1)) {
                showTopToast(msg.text, true);
                reconnecting = false;
                if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
                intentionalClose = true;
                stopHeartbeat();
                if (ws) { try { ws.close(); } catch (e) { } ws = null; }
            }
            break;

        default:
            break;
    }
}

function createRoom() {
    const firstInput = document.querySelector('input[name="firstMove"]:checked');
    const firstMove = firstInput ? firstInput.value : 'host';
    const bs = readBoardSize();
    const modeInput = document.querySelector('input[name="mode"]:checked');
    mode = modeInput ? modeInput.value : 'normal';
    const timeInput = document.querySelector('input[name="timeLimit"]:checked');
    timeLimitSec = timeInput ? parseInt(timeInput.value) : 0;
    connectWs(() => {
        ws.send(JSON.stringify({ type: 'gomoku_create_room', boardSize: bs, firstMove, mode, timeLimit: timeLimitSec }));
    });
}

function joinRoom() {
    const code = document.getElementById('join-code').value.trim().toUpperCase();
    if (code.length !== 5) { showTopToast('请输入 5 位正确的凭证', true); return; }
    connectWs(() => {
        ws.send(JSON.stringify({ type: 'gomoku_join_room', roomId: code }));
    });
}

function leaveOnline() {
    intentionalClose = true;
    if (ws) { ws.close(); ws = null; }
    isOnline = false;
    isSpectator = false;
    clearInterval(timerInterval);
    resetToMenu();
}

function resetToMenu() {
    // 如果在等待中，先通知服务端销毁房间
    if (ws && ws.readyState === WebSocket.OPEN && roomId && !isOnline) {
        ws.send(JSON.stringify({ type: 'gomoku_cancel_wait' }));
    }
    intentionalClose = true;
    if (ws) { try { ws.close(); } catch (_) {} ws = null; }
    isOnline = false;
    isSpectator = false;
    roomId = '';
    clearInterval(timerInterval);
    showPage('menu');
}

// ================= 在线操作 =================
function onlineSurrender() {
    if (gameOver || isAnimating || isSpectator) return;
    if (ws) ws.send(JSON.stringify({ type: 'gomoku_surrender' }));
}

function onlineRematch() {
    if (isSpectator) return;
    if (isOnline && ws) {
        ws.send(JSON.stringify({ type: 'gomoku_request_rematch' }));
        document.getElementById('btn-rematch').textContent = '等待回应...';
    } else if (!isOnline) {
        initGame();
    }
}

// ================= 聊天 =================
function sendChat() {
    const input = document.getElementById('chat-input');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg || msg.length > 300) return;
    input.value = '';
    if (isOnline && ws) {
        ws.send(JSON.stringify({ type: 'gomoku_chat_message', msg }));
    }
    appendChat(msg, 'self');
}

function appendChat(msg, side) {
    const container = document.getElementById('chat-messages');
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'chat-msg ' + side;
    div.textContent = msg;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ================= 身份检测 =================
function showIdentityState() {
    const pid = getUserPlayerId();
    const idCard = document.getElementById('identity-card');
    const menu = document.getElementById('page-menu');

    if (pid) {
        // 有身份：隐藏match面板，显示主菜单，自动连接
        document.getElementById('gomoku-match-panel').style.display = 'none';
        if (idCard) idCard.style.display = 'none';
        if (menu) menu.style.display = 'flex';
        connectWs(() => {});
    } else {
        // 无身份：显示match面板，隐藏主菜单
        document.getElementById('gomoku-match-panel').style.display = '';
        if (idCard) idCard.style.display = 'flex';
        if (menu) menu.style.display = 'none';
    }
}

function hideIdentityCard() {
    document.getElementById('gomoku-match-panel').style.display = 'none';
    const idCard = document.getElementById('identity-card');
    const fillName = document.getElementById('identity-fill-name');
    const menu = document.getElementById('page-menu');
    if (idCard) idCard.style.display = 'none';
    if (fillName) fillName.style.display = 'none';
    if (menu) menu.style.display = 'flex';
    document.getElementById('identity-recover-nickname').value = '';
    document.getElementById('identity-recover-input').value = '';
    document.getElementById('identity-recover-msg').style.display = 'none';
}

function doRecover() {
    const nickname = document.getElementById('identity-recover-nickname').value.trim();
    const code = document.getElementById('identity-recover-input').value.trim();
    const msgEl = document.getElementById('identity-recover-msg');
    if (!nickname) {
        msgEl.textContent = '请输入昵称';
        msgEl.style.display = 'block';
        return;
    }
    if (!code) {
        msgEl.textContent = '请输入恢复码';
        msgEl.style.display = 'block';
        return;
    }
    // 格式校验：恢复码为 xxx-xxx-xxx-xxx（英文单词）格式
    if (!/^[a-zA-Z]+-[a-zA-Z]+-[a-zA-Z]+-[a-zA-Z]+$/.test(code)) {
        msgEl.textContent = '恢复码格式错误';
        msgEl.style.display = 'block';
        return;
    }
    msgEl.style.display = 'none';
    // 不立即存本地，等服务端 gomoku_joined 确认后再存
    _pendingRecoveryCode = code;
    _pendingNickname = nickname;
    connectWs(() => {
        hideIdentityCard();
    });
}

function doNewPlayer() {
    const idCard = document.getElementById('identity-card');
    const fillName = document.getElementById('identity-fill-name');
    const input = document.getElementById('identity-nickname-input');
    if (idCard) idCard.style.display = 'none';
    if (fillName) fillName.style.display = 'flex';
    if (input) setTimeout(() => input.focus(), 0);
}

function doJoinWithNickname() {
    const nickname = document.getElementById('identity-nickname-input').value.trim();
    if (!nickname || nickname.length > 12) {
        showTopToast('昵称 1~12 字符', true);
        return;
    }
    _pendingNickname = nickname;
    connectWs(() => {
        hideIdentityCard();
    });
}

// ================= 事件绑定 =================
document.addEventListener('DOMContentLoaded', () => {

    // 检测已有身份并自动连接
    showIdentityState();

    // 身份卡片事件
    document.getElementById('identity-btn-recover').addEventListener('click', doRecover);
    document.getElementById('identity-recover-nickname').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('identity-recover-input').focus();
        }
    });
    document.getElementById('identity-recover-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') doRecover();
    });
    document.getElementById('identity-btn-go-home').addEventListener('click', () => {
        stopHeartbeat();
        intentionalClose = true;
        if (ws) ws.close();
        setTimeout(() => { window.location.href = '/'; }, 50);
    });
    document.getElementById('identity-btn-new').addEventListener('click', doNewPlayer);
    document.getElementById('identity-btn-join').addEventListener('click', doJoinWithNickname);
    document.getElementById('identity-nickname-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') doJoinWithNickname();
    });

    // 主菜单
    document.getElementById('btn-local-ai').addEventListener('click', () => {
        isOnline = false;
        showPage('setup');
        document.getElementById('ai-difficulty-group').style.display = 'block';
        document.getElementById('color-group').style.display = 'block';
        document.getElementById('setup-title').textContent = '单机对弈';
        document.getElementById('color-host-label').textContent = '玩家执黑';
        document.getElementById('color-guest-label').textContent = '电脑执黑';
        document.getElementById('btn-setup-start').textContent = '开局落子';
        document.getElementById('btn-setup-start').onclick = startLocalGame;
        document.querySelector('input[name="mode"][value="normal"]').checked = true;
        document.getElementById('time-setting').style.display = 'none';
        document.querySelector('input[name="boardSize"][value="15"]').checked = true;
        document.getElementById('custom-size-area').style.display = 'none';
        const joinBtn = document.getElementById('btn-setup-join');
        if (joinBtn) joinBtn.style.display = 'none';
    });

    document.getElementById('btn-online').addEventListener('click', () => {
        isOnline = true;
        showPage('setup');
        document.getElementById('ai-difficulty-group').style.display = 'none';
        document.getElementById('color-group').style.display = 'block';
        document.getElementById('setup-title').textContent = '在线对弈';
        document.getElementById('color-host-label').textContent = '房主执黑';
        document.getElementById('color-guest-label').textContent = '客机执黑';
        document.getElementById('btn-setup-start').textContent = '设局摆阵';
        document.getElementById('btn-setup-start').onclick = () => { createRoom(); };
        document.querySelector('input[name="mode"][value="normal"]').checked = true;
        document.getElementById('time-setting').style.display = 'none';
        document.querySelector('input[name="boardSize"][value="15"]').checked = true;
        document.getElementById('custom-size-area').style.display = 'none';
        // 在线模式额外增加"加入房间"按钮
        let joinBtn = document.getElementById('btn-setup-join');
        if (!joinBtn) {
            joinBtn = document.createElement('button');
            joinBtn.id = 'btn-setup-join';
            joinBtn.className = 'doodle-btn';
            joinBtn.textContent = '入局切磋';
            const actions = document.querySelector('.setting-actions');
            if (actions) actions.appendChild(joinBtn);
        }
        joinBtn.style.display = 'inline-flex';
        joinBtn.onclick = () => showPage('join');
    });

    // 设置页返回
    document.getElementById('btn-setup-back').addEventListener('click', () => {
        const joinBtn = document.getElementById('btn-setup-join');
        if (joinBtn) joinBtn.style.display = 'none';
        showPage('menu');
    });

    // 模式切换
    document.querySelectorAll('input[name="mode"]').forEach(el => {
        el.addEventListener('change', () => {
            document.getElementById('time-setting').style.display =
                el.value === 'time' ? 'block' : 'none';
        });
    });

    // 棋盘大小切换
    document.querySelectorAll('input[name="boardSize"]').forEach(el => {
        el.addEventListener('change', () => {
            document.getElementById('custom-size-area').style.display =
                el.value === 'custom' ? 'block' : 'none';
        });
    });

    // 等待页取消
    document.getElementById('btn-wait-cancel').addEventListener('click', resetToMenu);

    // 加入页返回
    document.getElementById('btn-join-back').addEventListener('click', () => showPage('setup'));
    document.getElementById('btn-join-room').addEventListener('click', joinRoom);

    // 加入码回车
    document.getElementById('join-code').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') joinRoom();
    });

    // 对局操作
    document.getElementById('btn-surrender').addEventListener('click', () => {
        if (isOnline && !isSpectator) onlineSurrender();
        else if (!isOnline && !gameOver) endGame(aiColor, '推枰认输，电脑获胜！');
    });
    document.getElementById('btn-rematch').addEventListener('click', onlineRematch);
    document.getElementById('btn-game-leave').addEventListener('click', () => {
        clearInterval(timerInterval);
        intentionalClose = true;
        if (isOnline && ws) { try { ws.close(); } catch (_) {} ws = null; }
        isOnline = false;
        isSpectator = false;
        showPage('menu');
    });

    // 对局结束覆盖层
    document.getElementById('btn-gameover-rematch').addEventListener('click', () => {
        document.getElementById('gameover-overlay').style.display = 'none';
        onlineRematch();
    });
    document.getElementById('btn-gameover-leave').addEventListener('click', () => {
        clearInterval(timerInterval);
        document.getElementById('gameover-overlay').style.display = 'none';
        intentionalClose = true;
        if (isOnline && ws) { try { ws.close(); } catch (_) {} ws = null; }
        isOnline = false;
        isSpectator = false;
        showPage('menu');
    });

    // 聊天
    document.getElementById('btn-chat-send').addEventListener('click', sendChat);
    document.getElementById('chat-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); sendChat(); }
    });

    // 返回按钮
    document.getElementById('btn-back').addEventListener('click', () => {
        clearInterval(timerInterval);
        intentionalClose = true;
        if (isOnline && ws) { try { ws.close(); } catch (_) {} ws = null; }
        isOnline = false;
        isSpectator = false;
        showPage('menu');
        window.location.href = '/';
    });

    // URL 快速加入: /gomoku?room=ABC12
    const params = new URLSearchParams(window.location.search);
    const roomCode = params.get('room');
    if (roomCode && roomCode.length === 5) {
        document.getElementById('join-code').value = roomCode.toUpperCase();
        isOnline = true;
        joinRoom();
    }
});
