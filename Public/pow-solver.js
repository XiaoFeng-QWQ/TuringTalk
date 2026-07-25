/**
 * PoW 工作量证明求解器 + 防重放传输 + 浏览器特征绑定（防脚本刷接口）
 *
 * 流程：
 *   1. 生成 clientId（sessionStorage 持久化）
 *   2. computeBrowserProof() 收集浏览器硬件特征（canvas/WebGL/screen/Intl…）
 *   3. POST /api/pow/challenge 发送 { clientId, browserProof } 获取挑战 + 一次性 token
 *   4. solvePoW() 解 SHA-256 哈希难题
 *   5. encodePoWData() 用专用字母表 + XOR 编码 challenge:nonce:token:clientId:browserProof
 *   6. WS 连接 /ws?d={编码后数据}
 *
 * browserProof 由 canvas 渲染、WebGL 显卡信息、屏幕参数等真实浏览器 API 计算，
 * 服务端将其混入 HMAC 挑战签名 → 脚本必须在真实浏览器环境中运行才能通过。
 *
 * 自定义编码（非标准 base64），每部署字母表唯一。
 */

// ── 浏览器特征收集（需要真实浏览器 API） ──

function getCanvasHash() {
    try {
        const c = document.createElement('canvas');
        c.width = 280; c.height = 60;
        const ctx = c.getContext('2d');
        if (!ctx) return 'noctx';

        // 绘制文字 + 图形（不同 OS/字体/GPU 渲染结果不同）
        ctx.textBaseline = 'top';
        ctx.font = '14px "Arial", sans-serif';
        ctx.fillStyle = '#f60';
        ctx.fillRect(0, 0, 100, 60);
        ctx.fillStyle = '#069';
        ctx.fillText('PoW Proof 验证', 2, 17);
        ctx.fillStyle = 'rgba(102,204,0,0.7)';
        ctx.fillText('Browser Canvas', 4, 36);
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 1;
        ctx.strokeRect(0, 0, 279, 59);

        // 采样像素点做摘要（取部分像素，减少开销）
        const pixels = ctx.getImageData(0, 0, 280, 60).data;
        let hash = 0;
        for (let i = 0; i < pixels.length; i += 16) {
            hash = ((hash << 5) - hash + pixels[i]) | 0;
            hash = ((hash << 5) - hash + pixels[i + 1]) | 0;
            hash = ((hash << 5) - hash + pixels[i + 2]) | 0;
        }
        return hash.toString(36);
    } catch (e) {
        return 'nocvs';
    }
}

function getWebGLVendor() {
    try {
        const c = document.createElement('canvas');
        const gl = c.getContext('webgl') || c.getContext('experimental-webgl');
        if (!gl) return 'nogl';
        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        if (!debugInfo) return String(gl.getParameter(gl.RENDERER) || 'unknown');
        return String(gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || '') +
               '|' + String(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '');
    } catch (e) {
        return 'nogl';
    }
}

async function computeBrowserProof() {
    const fp = {
        sw: screen.width || 0,
        sh: screen.height || 0,
        cd: screen.colorDepth || 0,
        hwc: navigator.hardwareConcurrency || 1,
        tz: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        mxt: navigator.maxTouchPoints || 0,
        ch: getCanvasHash(),
        wv: getWebGLVendor(),
    };
    const raw = JSON.stringify(fp);
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
    return Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('')
        .substring(0, 24);
}

// ── 自定义编码 ──

function alphaEnc6(bytes) {
    const ALPHA = window.__POW_ALPHABET__;
    let bits = '';
    for (let i = 0; i < bytes.length; i++) {
        bits += bytes[i].toString(2).padStart(8, '0');
    }
    while (bits.length % 6 !== 0) bits += '0';
    let out = '';
    for (let i = 0; i < bits.length; i += 6) {
        const idx = parseInt(bits.substring(i, i + 6), 2);
        out += ALPHA[idx];
    }
    return out;
}

async function sha256Bytes(input) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
    return new Uint8Array(buf);
}

async function encodePoWData(challenge, nonce, token, clientId, browserProof) {
    const payload = challenge + ':' + nonce + ':' + token + ':' + clientId + ':' + browserProof;
    const raw = new Uint8Array(payload.length);
    for (let i = 0; i < payload.length; i++) raw[i] = payload.charCodeAt(i);

    const ALPHA = window.__POW_ALPHABET__;
    const baseHash = await sha256Bytes(ALPHA);

    const encoded = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        encoded[i] = raw[i] ^ baseHash[i % 32] ^ ((i * 17) & 0xFF);
    }

    return alphaEnc6(encoded);
}

// ── PoW 求解 ──

async function solvePoW(challengeBase64) {
    const raw = atob(challengeBase64);
    const parts = raw.split('|');
    if (parts.length !== 6) throw new Error('Invalid challenge format');
    const difficulty = parseInt(parts[1], 10);  // parts: ts|dif|random|token|browserProof|sig
    const prefix = '0'.repeat(difficulty);

    const encoder = new TextEncoder();
    const rawBytes = encoder.encode(raw);
    let nonce = 0;
    const BATCH = 256;

    while (true) {
        for (let i = 0; i < BATCH; i++, nonce++) {
            const nonceStr = nonce.toString();
            const data = new Uint8Array(rawBytes.length + nonceStr.length);
            data.set(rawBytes);
            for (let j = 0; j < nonceStr.length; j++) {
                data[rawBytes.length + j] = nonceStr.charCodeAt(j);
            }

            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashHex = Array.from(new Uint8Array(hashBuffer))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');

            if (hashHex.startsWith(prefix)) {
                return { nonce: nonceStr, challenge: challengeBase64 };
            }
        }
        await new Promise(r => setTimeout(r, 0));
    }
}

// ── 初始化 ──

function getClientId() {
    let id = sessionStorage.getItem('_pow_cid');
    if (!id) {
        id = crypto.randomUUID();
        sessionStorage.setItem('_pow_cid', id);
    }
    return id;
}

async function initPoW() {
    const alpha = window.__POW_ALPHABET__;
    if (!alpha) {
        console.warn('PoW alphabet missing, skipping');
        if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
            DebugLogger.log('error', 'PoW字母表缺失', { reason: '__POW_ALPHABET__未注入' });
        }
        window.__POW_QUERY__ = '';
        return '';
    }

    try {
        // 整体超时保护（10 秒），超时跳过 PoW 直接连接
        const q = await Promise.race([
            doInitPoW(),
            new Promise((resolve) => setTimeout(() => {
                console.warn('PoW init timed out');
                if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
                    DebugLogger.log('error', 'PoW初始化超时', { timeout_ms: 5000 });
                }
                resolve('');
            }, 5000))  // 5 秒超时
        ]);
        window.__POW_QUERY__ = q;
        return q;
    } catch (e) {
        console.error('PoW init failed:', e);
        if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
            DebugLogger.log('error', 'PoW初始化异常', {
                error: e.message || String(e),
                name: e.name || 'Error',
                online: navigator.onLine
            });
        }
        window.__POW_QUERY__ = '';
        return '';
    }
}

async function doInitPoW() {
    // Step 0: 收集浏览器特征（必须真实浏览器环境）
    var stepStart = Date.now();
    var browserProof;
    try {
        browserProof = await computeBrowserProof();
    } catch (e) {
        if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
            DebugLogger.log('error', 'PoW-浏览器特征收集失败', { error: e.message, elapsed_ms: Date.now() - stepStart });
        }
        throw e;
    }

    // Step 1: 获取挑战 + 一次性 token（服务端将 browserProof 混入签名）
    stepStart = Date.now();
    const clientId = getClientId();
    var resp;
    try {
        resp = await fetch('/api/pow/challenge', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ clientId: clientId, browserProof: browserProof })
        });
    } catch (e) {
        if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
            DebugLogger.log('error', 'PoW-获取challenge网络失败', { error: e.message, elapsed_ms: Date.now() - stepStart, online: navigator.onLine });
        }
        throw e;
    }
    if (!resp.ok) {
        var fetchElapsed = Date.now() - stepStart;
        if (typeof DebugLogger !== 'undefined' && DebugLogger.log) {
            DebugLogger.log('error', 'PoW-challenge接口异常', { status: resp.status, elapsed_ms: fetchElapsed });
        }
        throw new Error('Challenge request failed: ' + resp.status);
    }
    const data = await resp.json();

    // Step 2: 解 PoW
    const result = await solvePoW(data.challenge);

    // Step 3: 自定义编码（含 browserProof）
    const encoded = await encodePoWData(
        result.challenge,
        result.nonce,
        data.token,
        clientId,
        browserProof
    );

    return '?d=' + encodeURIComponent(encoded);
}
