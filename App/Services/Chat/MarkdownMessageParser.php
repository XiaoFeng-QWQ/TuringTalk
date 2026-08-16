<?php

namespace App\Services\Chat;

/**
 * 聊天室特殊 Markdown 语法解析器（v3）
 *
 * 把原始文本中的 [!类型:内容|参数] 组件语法解析为结构化组件树 blocks。
 * 文本流中的 {变量} 引用拆分为 ref 节点；组件属性内的 {x} 保留字符串，由客户端本地求值。
 *
 * 单遍扫描，线性复杂度；递归深度/节点数/JSON 长度均有上限，超限降级为纯文本节点。
 *
 * v3 变化（相对 v2）：
 * - 组件格式：[!类型:内容|参数]，v2 的 [!标签](类型:内容) 双层结构废弃（按普通文本显示）
 * - 变量引用：%名% → {名}
 * - 类型改名：文本/画板/表格/音乐/图集/投票/进度/倒计时/定时/按钮/发送/复制/弹窗/确认/输入/显示/开关/隐藏/变量/条件
 * - 画板：第一段即图形序列（无 shapes= 前缀），尺寸/背景/网格可省
 * - 投票：新增 多选= / perm= / 洗牌 参数
 * - 按钮：支持动作嵌套（发送:/复制:/弹窗:/跳转:/开关:/显示:/隐藏:/倒计时:/变量:/循环:/洗牌:）
 * - 颜色：统一 颜色=#hex 或裸 #hex；废弃 ::颜色 后缀
 * - 权限：perm=@昵称（白名单）/!@昵称（黑名单）/@名=内容（映射）
 */
class MarkdownMessageParser
{
    /** 内容型组件递归深度上限（超过后该片段降级为 text） */
    private const MAX_DEPTH = 10;

    /** 单条消息最大节点数（超过后整条降级为 text） */
    private const MAX_NODES = 200;

    /** blocks JSON 最大字节数（超过后整条降级为 text） */
    private const MAX_JSON_BYTES = 32768;

    /** v3 类型名 → 内部类型映射（不在映射内的按普通文本显示） */
    private const V3_TYPE_MAP = [
        '文本'   => 'text',
        '画板'   => 'board',
        '表格'   => 'table',
        '音乐'   => 'music',
        '图集'   => 'gallery',
        '投票'   => 'vote',
        '进度'   => 'bar',
        '倒计时' => 'timer',
        '计时'   => 'stopwatch',
        '定时'   => 'at',
        '按钮'   => 'button',
        '发送'   => 'send',
        '复制'   => 'copy',
        '弹窗'   => 'modal',
        '确认'   => 'confirm',
        '输入'   => 'input',
        '显示'   => 'get',
        '开关'   => 'switch',
        '隐藏'   => 'hide',
        '变量'   => 'var',
        '条件'   => 'if',
        '动作链'   => 'chain',
    ];

    /** v3 动作前缀 → 内部动作类型 */
    private const ACTION_TYPE_MAP = [
        '发送'   => 'send',
        '复制'   => 'copy',
        '弹窗'   => 'modal',
        '跳转'   => 'url',
        '开关'   => 'switch',
        '显示'   => 'get',
        '隐藏'   => 'hide',
        '倒计时' => 'timer',
        '变量'   => 'var',
        '循环'   => 'loop',
        '洗牌'   => 'shuffle',
        '计时'   => 'stopwatch',
        '音乐'   => 'music',
        '随机'   => 'rand',
        '等待'   => 'wait',
        '分支'   => 'branch',
        '动作链' => 'chain',
    ];

    /** v3 动作前缀列表（按此顺序检测） */
    private const ACTION_PREFIXES = ['发送', '复制', '弹窗', '跳转', '开关', '显示', '隐藏', '倒计时', '变量', '循环', '洗牌', '计时', '音乐', '随机', '等待', '分支', '动作链'];

    /** 文本流中的变量引用：{标识符} 或 {标识符|默认值} */
    private const REF_RE = '/(\{[A-Za-z_\x{4e00}-\x{9fa5}][A-Za-z0-9_\x{4e00}-\x{9fa5}|-]*\})/u';

    private int $nodeCount = 0;

    /** 解析过程中收集的错误（语法不完整 / 代码块异常 / 超限降级），元素：['type','reason','frag'] */
    private array $errors = [];

    /** 错误片段最大展示长度 */
    private const MAX_ERROR_FRAG = 60;

    /**
     * 把组件内容文本中的字面 \n 转换为真实换行。
     * - `\n` → 换行符（内容换行）
     * - `\\n` → 字面 `\n`（转义保留，反斜杠不消费）
     * 仅作用于组件 raw 内容，不影响纯文本消息。
     */
    private function unescapeNewlines(string $s): string
    {
        if (strpos($s, '\\n') === false) {
            return $s;
        }
        $s = str_replace('\\\\n', "\x00N", $s); // \\n → 占位
        $s = str_replace('\\n', "\n", $s);       // \n → 换行
        $s = str_replace("\x00N", '\\n', $s);    // 占位 → 字面 \n
        return $s;
    }

    /**
     * 组件序号（用于 vote/switch/timer/bar/board/at 等默认 id 的 v0/sw0/... 命名） */
    private int $btnIndex = 0;

    /** 当前正在构建的节点序号 */
    private int $currentBtnIndex = 0;

    /**
     * 判断文本是否包含需要结构化解析的特殊语法（组件语法或 {变量} 引用）。
     * 代码块（fenced / 内联代码）内的内容不参与判定，保持原样显示。
     */
    public static function hasSpecialSyntax(string $text): bool
    {
        [$protected] = self::protectCodeBlocks($text);
        if (preg_match('/\[!/u', $protected)) {
            return true;
        }
        if (preg_match(self::REF_RE, $protected)) {
            return true;
        }
        return false;
    }
    /**
     * 安全截取错误片段：按字节起点切割但绝不切断多字节字符（mb_strcut 自动对齐字符边界），
     * 再按字符数截断到 MAX_ERROR_FRAG。保证片段始终是合法 UTF-8，
     * 避免字节截断破坏多字节字符导致 json_encode 返回 false。
     */
    private static function safeFrag(string $text, int $byteStart, int $byteLen): string
    {
        return mb_substr(mb_strcut($text, $byteStart, $byteLen, 'UTF-8'), 0, self::MAX_ERROR_FRAG);
    }

    /**
     * 保护 Markdown 代码块（fenced code + 内联代码），替换为占位符，
     * 避免其中的自定义语法（组件、变量引用）被误解析。
     * 返回 [text, parts, errors]，恢复时把 [[RAWCODEi]] 换回 parts[i]。
     * errors 记录代码块保护阶段的异常（未闭合的 fence / 内联代码）。
     * 逻辑与前端 protectMarkdownCode 保持一致。
     */
    private static function protectCodeBlocks(string $text): array
    {
        $parts = [];
        $out = '';
        $errors = [];
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            // 1) fenced code：行首（允许 0-3 空格）``` 或 ~~~
            $nl = strpos($text, "\n", $i);
            $lineEnd = $nl === false ? $len : $nl;
            $line = substr($text, $i, $lineEnd - $i);
            if (preg_match('/^ {0,3}(`{3,}|~{3,})[^\n]*$/', $line, $fm)) {
                $fenceChar = $fm[1][0];
                $fenceLen = strlen($fm[1]);
                $closeRe = '/^ {0,3}' . $fenceChar . '{' . $fenceLen . ',}[ \t]*$/';
                $searchStart = $nl === false ? $len : $nl + 1;
                $blockEnd = -1;
                while ($searchStart <= $len) {
                    $cnl = strpos($text, "\n", $searchStart);
                    $cEnd = $cnl === false ? $len : $cnl;
                    $cLine = substr($text, $searchStart, $cEnd - $searchStart);
                    if (preg_match($closeRe, $cLine)) {
                        $blockEnd = $cnl === false ? $len : $cnl + 1;
                        break;
                    }
                    if ($cnl === false) break;
                    $searchStart = $cnl + 1;
                }
                if ($blockEnd !== -1) {
                    $raw = substr($text, $i, $blockEnd - $i);
                    $ph = '[[RAWCODE' . count($parts) . ']]';
                    $parts[] = $raw;
                    $out .= $ph;
                    $i = $blockEnd;
                    continue;
                }
                // 未闭合的 fence：按普通文本行处理，并记录错误
                $errors[] = [
                    'type'   => 'code_unclosed_fence',
                    'reason' => "代码块未闭合（缺少结束的 {$fenceChar}）",
                    'frag'   => self::safeFrag($text, $i, $lineEnd - $i),
                ];
                $out .= $line;
                $i = ($nl === false ? $len : $nl + 1);
                continue;
            }

            // 2) inline code：反引号（不跨行）
            if ($text[$i] === '`') {
                $run = 0;
                while ($i + $run < $len && $text[$i + $run] === '`') $run++;
                $nextNl = strpos($text, "\n", $i + $run);
                $searchEnd = $nextNl === false ? $len : $nextNl;
                $close = strpos($text, str_repeat('`', $run), $i + $run);
                if ($close !== false && $close < $searchEnd) {
                    $raw = substr($text, $i, $close + $run - $i);
                    $ph = '[[RAWCODE' . count($parts) . ']]';
                    $parts[] = $raw;
                    $out .= $ph;
                    $i = $close + $run;
                    continue;
                }
                // 未闭合的 inline code：仅当反引号后紧跟非空白字符（明确的代码意图）才记录错误，
                // 避免中文文本中单个反引号当普通符号使用时误报。
                $nextChar = ($i + $run < $len) ? $text[$i + $run] : '';
                if ($nextChar !== '' && !ctype_space($nextChar)) {
                    $errors[] = [
                        'type'   => 'code_unclosed_inline',
                        'reason' => '内联代码未闭合（缺少匹配的反引号）',
                        'frag'   => self::safeFrag($text, $i, $run + 8),
                    ];
                }
            }

            $out .= $text[$i];
            $i++;
        }

        return [$out, $parts, $errors];
    }

    /**
     * 把 blocks 中的 [[RAWCODEi]] 占位符恢复为原始代码块文本。
     */
    private static function restoreCodeBlocks(array $blocks, array $parts): array
    {
        if (count($parts) === 0) {
            return $blocks;
        }
        foreach ($blocks as $k => $b) {
            if (!is_array($b)) continue;
            if (($b['t'] ?? '') === 'text' && isset($b['text'])) {
                foreach ($parts as $i => $raw) {
                    $b['text'] = str_replace('[[RAWCODE' . $i . ']]', $raw, $b['text']);
                }
                $blocks[$k] = $b;
            } elseif (isset($b['children']) && is_array($b['children'])) {
                $blocks[$k]['children'] = self::restoreCodeBlocks($b['children'], $parts);
            }
        }
        return $blocks;
    }

    /**
     * 解析文本为组件树。
     *
     * @return array{blocks: array, text: string}
     */
    public function parse(string $text): array
    {
        $this->errors = [];
        $this->nodeCount = 0;
        $this->btnIndex = 0;
        $this->currentBtnIndex = 0;

        // 保护代码块（fenced / 内联代码），防止其中自定义语法被误解析
        [$protectedText, $codeParts, $codeErrors] = self::protectCodeBlocks($text);
        $this->errors = $codeErrors;

        // 画板特殊兼容：v2 双层语法 [!标签](board:尺寸|shapes=...|...) 转换为 v3 单层
        // （仅画板支持 v2+v3，其余组件仍只认 v3）
        $protectedText = $this->convertV2BoardToV3($protectedText);

        $blocks = [];
        $this->scanText($protectedText, 0, $blocks);
        $blocks = self::restoreCodeBlocks($blocks, $codeParts);

        // JSON 编码（JSON_INVALID_UTF8_SUBSTITUTE 兜底：即使出现非法 UTF-8 字节也不会返回 false）
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // 安全边界：节点数 / JSON 长度超限，整条降级为错误提示
        if ($this->nodeCount > self::MAX_NODES || ($json !== false && strlen($json) > self::MAX_JSON_BYTES)) {
            $this->errors[] = [
                'type'   => 'limit_exceeded',
                'reason' => '消息过长，超出结构化解析上限，已降级为纯文本',
                'frag'   => mb_substr($text, 0, self::MAX_ERROR_FRAG),
            ];
        }

        if (count($this->errors) > 0) {
            // 解析出错：整条消息只渲染错误节点（不再附带部分解析成功的原文 blocks）
            $blocks = $this->withErrorNode($text);
        }

        // 统一过滤非法 UTF-8 字节，确保外层再 json_encode 永远不会返回 false
        $blocks = self::sanitizeUtf8($blocks);

        return [
            'blocks' => $blocks,
            'text'   => $this->extractPlainText($blocks),
        ];
    }

    /**
     * 收集当前解析错误列表（每条：['type','reason','frag']）。
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 构造整条消息的错误节点（t=error，errors 为结构化 JSON 数组，raw 为原始输入文本）。
     * 解析出错时整条消息只渲染错误节点，不再附带部分解析成功的原文 blocks。
     */
    private function withErrorNode(string $rawText): array
    {
        $errs = [];
        foreach ($this->errors as $e) {
            $errs[] = [
                'type'   => (string)($e['type'] ?? 'unknown'),
                'reason' => (string)($e['reason'] ?? '解析错误'),
                'frag'   => mb_substr((string)($e['frag'] ?? ''), 0, self::MAX_ERROR_FRAG),
            ];
        }
        return [[
            't'      => 'error',
            'errors' => $errs,
            'raw'    => $rawText,
        ]];
    }

    /**
     * 过滤数组中所有字符串的非法 UTF-8 字节（替换为 U+FFFD），
     * 确保外层再 json_encode 时永远不会返回 false。
     */
    private static function sanitizeUtf8(array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return ($json !== false) ? json_decode($json, true) : $data;
    }

    // ==================== v2 画板兼容 ====================

    /**
     * 仅画板支持 v2 双层语法（其余组件只认 v3）：
     *   v2: [!标签](board:尺寸|shapes=图形|bg=..|grid=0|text=..|id=..|modal=1|hide=1|tx/ty/ts/tc)
     *   → 转换为 v3: [!画板[:尺寸]|图形|bg=..|grid=0|...]
     * shapes= 值内的无键修饰段（|f / |wN）贪婪并入图形序列。
     */
    private function convertV2BoardToV3(string $text): string
    {
        return preg_replace_callback(
            '/\[!([^\]]*)\]\((board):?([^)]*)\)/u',
            function (array $m): string {
                $raw = trim($m[3]);
                $size = 20;
                if (preg_match('/^(\d{1,2})/', $raw, $sm)) {
                    $size = (int) $sm[1];
                    $raw = substr($raw, strlen($sm[1]));
                    $raw = ltrim($raw, '|');
                }
                if ($raw === '') {
                    return $m[0];
                }
                $parts = $this->splitTopLevelByPipe($raw);
                $shapes = '';
                $params = [];
                foreach ($parts as $seg) {
                    if ($seg === '') {
                        continue;
                    }
                    $eq = strpos($seg, '=');
                    if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($seg, 0, $eq)))) {
                        $k = trim(substr($seg, 0, $eq));
                        if ($k === 'shapes') {
                            $shapes = substr($seg, $eq + 1);
                        } else {
                            $params[$k] = substr($seg, $eq + 1);
                        }
                    } else {
                        // 无键段（|f 闭合填充 / |wN 线宽等修饰）→ 追加到图形序列
                        $shapes = ($shapes === '') ? $seg : $shapes . '|' . $seg;
                    }
                }
                if ($shapes === '') {
                    return $m[0];
                }
                $v3 = '[!画板';
                if ($size !== 20) {
                    $v3 .= ':' . $size;
                }
                $v3 .= '|' . $shapes;
                foreach ($params as $k => $v) {
                    $v3 .= '|' . $k . '=' . $v;
                }
                $label = trim($m[1]);
                if (($params['modal'] ?? '') === '1' && $label !== '') {
                    $v3 .= '|label=' . $label;
                }
                return $v3 . ']';
            },
            $text
        );
    }

    // ==================== 主扫描 ====================

    private function scanText(string $text, int $depth, array &$out): void
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->appendTextNode($text, $out);
            return;
        }

        $len = strlen($text);
        $i = 0;
        $buf = '';

        while ($i < $len) {
            $found = $this->findComponent($text, $i);

            if ($found === null) {
                $buf .= substr($text, $i);
                break;
            }

            [$start, $type, $raw, $end] = $found;

            $buf .= substr($text, $i, $start - $i);
            $this->appendTextFlow($buf, $out);
            $buf = '';

            $this->currentBtnIndex = $this->btnIndex++;
            // v3.7 内容换行：组件内容文本内的字面 \n 转真实换行（\\n 转义保留字面 \n）
            $raw = $this->unescapeNewlines($raw);
            $node = $this->buildNode($type, $raw, $depth);
            if ($node !== null) {
                $out[] = $node;
                $this->nodeCount++;
            } else {
                // 未知类型（含 v2 废弃语法）：整段按普通文本保留，并记录错误
                $this->errors[] = [
                    'type'   => 'unknown_type',
                    'reason' => "未知组件类型「{$type}」",
                    'frag'   => self::safeFrag($text, $start, $end - $start),
                ];
                $this->appendTextFlow(substr($text, $start, $end - $start), $out);
            }

            $i = $end;
        }

        if ($buf !== '') {
            $this->appendTextFlow($buf, $out);
        }
    }

    /**
     * 在 offset 处查找下一个 v3 组件。返回 [start, type, raw, end]，找不到或未闭合返回 null。
     * v3 格式：[!类型[:内容|参数]]
     */
    private function findComponent(string $text, int $offset): ?array
    {
        if (!preg_match('/\[!/u', $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }

        $start = $m[0][1];
        $len = strlen($text);
        $pos = $start + 2;

        // 类型名：到 : | ] 或空白为止（类型名为中文/字母，不含空白与括号）
        $typeStart = $pos;
        while ($pos < $len) {
            $ch = $text[$pos];
            if ($ch === ':' || $ch === '|' || $ch === ']' || $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                break;
            }
            $pos++;
        }
        $type = trim(substr($text, $typeStart, $pos - $typeStart));
        if ($type === '') {
            // [! 后无类型名，不视为组件；记录语法不完整错误
            $this->errors[] = [
                'type'   => 'syntax_no_type',
                'reason' => '组件缺少类型名（格式应为 [!类型:内容|参数]）',
                'frag'   => self::safeFrag($text, $start, $len - $start),
            ];
            return null;
        }

        // raw 起点：冒号后 / | 后 / 直接到 ]
        if ($pos < $len && ($text[$pos] === ':' || $text[$pos] === '|')) {
            $rawStart = $pos + 1;
        } else {
            $rawStart = $pos;
        }

        // 找匹配的 ]（深度感知 [ ]，支持嵌套组件）
        $depth = 0;
        $bi = $rawStart;
        for (; $bi < $len; $bi++) {
            $ch = $text[$bi];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
        }

        if ($bi >= $len) {
            // 未闭合，按普通文本处理；记录语法不完整错误
            $this->errors[] = [
                'type'   => 'syntax_unclosed',
                'reason' => '组件未闭合（缺少 ]）',
                'frag'   => self::safeFrag($text, $start, $len - $start),
            ];
            return null;
        }

        $raw = substr($text, $rawStart, $bi - $rawStart);
        return [$start, $type, $raw, $bi + 1];
    }

    private function appendTextNode(string $text, array &$out): void
    {
        if ($text === '') {
            return;
        }
        $out[] = ['t' => 'text', 'text' => $text];
        $this->nodeCount++;
    }

    /**
     * 把文本片段拆分为 text 节点与 {变量} ref 节点。
     */
    private function appendTextFlow(string $text, array &$out): void
    {
        if ($text === '') {
            return;
        }

        $parts = preg_split(self::REF_RE, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $this->appendTextNode($text, $out);
            return;
        }

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (strlen($part) > 2 && $part[0] === '{' && substr($part, -1) === '}') {
                $out[] = ['t' => 'ref', 'var' => substr($part, 1, -1)];
                $this->nodeCount++;
            } else {
                $this->appendTextNode($part, $out);
            }
        }
    }

    // ==================== 节点构建 ====================

    private function buildNode(string $type, string $raw, int $depth): ?array
    {
        $node = $this->buildNodeInner($type, $raw, $depth);
        if ($node === null) {
            return null;
        }
        // v3.5 x/y 定位参数（弹窗内组件位置调整）+ 统一 id（全组件 id= 生效）
        $mp = $this->parseNewMdParams($raw);
        if (isset($mp['x'])) {
            $node['x'] = $mp['x'];
        }
        if (isset($mp['y'])) {
            $node['y'] = $mp['y'];
        }
        if (!isset($node['id']) && isset($mp['id']) && $mp['id'] !== '') {
            $node['id'] = $mp['id'];
        }
        return $node;
    }

    private function buildNodeInner(string $type, string $raw, int $depth): ?array
    {
        $internal = self::V3_TYPE_MAP[$type] ?? null;
        if ($internal === null) {
            return null; // 未知类型（含 v2 双层废弃语法），按普通文本显示
        }

        switch ($internal) {
            case 'modal':
                return $this->buildModal($raw, $depth);
            case 'button':
            case 'send':
            case 'copy':
            case 'confirm':
            case 'input':
            case 'get':
            case 'switch':
            case 'var':
            case 'table':
            case 'music':
            case 'timer':
            case 'stopwatch':
            case 'bar':
            case 'if':
            case 'hide':
            case 'text':
            case 'board':
            case 'vote':
            case 'at':
            case 'gallery':
            case 'chain':
                return $this->buildGenericNode($internal, $raw);
        }

        return null;
    }

    private function buildModal(string $raw, int $depth): array
    {
        $sp = $this->splitBtnParams($raw);
        $mp = $this->parseNewMdParams($raw);
        $parts = $this->splitTopLevelByPipe($raw);

        $title = $this->firstNonEmpty($mp['t'] ?? '', $mp['标题'] ?? '', trim($parts[0] ?? ''), '提示');

        // 内容 = 后续无键段（排除键=值参数段）
        $childrenRaw = [];
        for ($i = 1; $i < count($parts); $i++) {
            $p = $parts[$i];
            $eq = strpos($p, '=');
            if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($p, 0, $eq)))) {
                continue;
            }
            $childrenRaw[] = $p;
        }
        $content = implode('|', $childrenRaw);

        return $this->buttonNode('modal', $sp, $title, [
            'title'    => $title,
            'children' => $this->parseChildren($content, $depth),
        ]);
    }

    private function parseChildren(string $content, int $depth): array
    {
        $out = [];
        $this->scanText($content, $depth + 1, $out);
        return $out;
    }

    private function buildGenericNode(string $type, string $raw): array
    {
        $idx = $this->currentBtnIndex;

        switch ($type) {
            case 'button':
                // 按钮：[!按钮:文字|动作或URL]
                $sp = $this->splitBtnParams($raw);
                $bp = $this->parseNewMdParams($raw);
                $label = trim($bp['value'] ?? '');
                if ($label === '') $label = '按钮';
                [$aType, $aContent] = $this->parseAction($raw);
                return $this->buttonNode('button', $sp, $label, [
                    'action_type' => $aType,
                    'action'      => $aContent,
                ]);

            case 'send':
            case 'copy':
                // 发送/复制：[!发送:文字|内容]
                $sp = $this->splitBtnParams($raw);
                $pp = $this->parseNewMdParams($raw);
                $label = trim($pp['value'] ?? '');
                $parts = $this->splitTopLevelByPipe($raw);
                $restParts = [];
                for ($i = 1; $i < count($parts); $i++) {
                    $p = $parts[$i];
                    $eq = strpos($p, '=');
                    if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($p, 0, $eq)))) {
                        continue;
                    }
                    $restParts[] = $p;
                }
                $sp['content'] = implode('|', $restParts);
                return $this->buttonNode($type, $sp, $label);

            case 'confirm':
                // 确认：[!确认:提示语|动作]
                $sp = $this->splitBtnParams($raw);
                $cp = $this->parseNewMdParams($raw);
                $label = trim($cp['value'] ?? '');
                if ($label === '') $label = '确认';
                [$cType, $cAction] = $this->parseAction($raw);
                if ($cType === '' && $cAction === '') {
                    // 无动作前缀时，动作 = 第一参数段（兼容旧写法）
                    $parts = $this->splitTopLevelByPipe($raw);
                    $cAction = trim($parts[1] ?? '');
                }
                return $this->buttonNode('confirm', $sp, $label, [
                    'message' => $label,
                    'action'  => $cAction,
                ]);

            case 'input':
                // 输入：[!输入:占位符|id=名|ok=..|on=..]
                $sp = $this->splitBtnParams($raw);
                $ip = $this->parseNewMdParams($raw);
                $node = $this->buttonNode('input', $sp, '', [
                    'placeholder' => $ip['value'] ?? '',
                    'id'          => $ip['id'] ?? ('inp' . $idx),
                    'ok'          => $ip['ok'] ?? '',
                ]);
                if (isset($ip['colorof'])) $node['colorof'] = $ip['colorof'];
                if (isset($ip['on'])) $node['onchange'] = $ip['on'];
                return $node;

            case 'get':
                // 显示：[!显示:名]
                $sp = $this->splitBtnParams($raw);
                $gp = $this->parseNewMdParams($raw);
                $node = [
                    't'  => 'get',
                    'id' => trim($gp['value'] ?? ''),
                ];
                if (isset($gp['colorof'])) $node['colorof'] = $gp['colorof'];
                if ($sp['fg'] !== '') $node['fg'] = $sp['fg'];
                if ($sp['bg'] !== '') $node['bg'] = $sp['bg'];
                return $node;

            case 'switch':
                // 开关：[!开关:值1/值2/值3|id=名|颜色=1]
                $sp = $this->splitBtnParams($raw);
                $sw = $this->parseSwitchParams($raw);
                $sw3 = $this->parseNewMdParams($raw);
                $values = $sw['values'];
                $val0 = trim($sw3['value'] ?? '');
                if ($val0 !== '') {
                    $values = preg_split('/[\/,]/', $val0, -1, PREG_SPLIT_NO_EMPTY);
                    $parts = $this->splitTopLevelByPipe($raw);
                    for ($i = 1; $i < count($parts); $i++) {
                        $p = $parts[$i];
                        $eq = strpos($p, '=');
                        if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($p, 0, $eq)))) {
                            continue;
                        }
                        $values[] = $p;
                    }
                }
                if (count($values) === 0) $values = ['开', '关'];
                $node = $this->buttonNode('switch', $sp, $values[0], [
                    'values' => $values,
                    'id'     => $sw['id'] !== '' ? $sw['id'] : ('sw' . $idx),
                    'color'  => $sw['color'] || ($sw3['颜色'] ?? '') === '1' || ($sw3['color'] ?? '') === '1',
                ]);
                if (count($sw['colors']) > 0) $node['colors'] = $sw['colors'];
                if (isset($sw3['on'])) $node['onchange'] = $sw3['on'];
                $lock = $sw3['lock'] ?? ($sw['lock'] ?? '');
                if ($lock !== '') $node['lock'] = $lock;
                return $node;

            case 'var':
                // 变量：[!变量:名=值] 或 [!变量:名|init=值]
                $vp = $this->parseNewMdParams($raw);
                $val = trim($vp['value'] ?? '');
                $eq = strpos($val, '=');
                $var = $eq !== false ? trim(substr($val, 0, $eq)) : $val;
                $init = $eq !== false ? trim(substr($val, $eq + 1)) : '';
                if (isset($vp['init'])) $init = $vp['init'];
                return [
                    't'    => 'var',
                    'var'  => $var,
                    'init' => $init,
                ];

            case 'table':
                // 表格：[!表格:列数|表头1|表头2|数据...]
                $tbl = $this->parseTableParams($raw);
                return [
                    't'     => 'table',
                    'cols'  => max(1, $tbl['cols']),
                    'cells' => $tbl['cells'],
                ];

            case 'music':
                // 音乐：[!音乐:URL|标题=歌名|id=名|状态=变量名]
                // id：按钮绑定控制用；状态：输出播放/暂停状态到变量
                $mp = $this->parseNewMdParams($raw);
                $node = [
                    't'   => 'music',
                    'url' => trim($mp['value'] ?? ''),
                ];
                $title = $mp['标题'] ?? $mp['t'] ?? '';
                if ($title !== '') $node['title'] = $title;
                if (($mp['id'] ?? '') !== '') $node['id'] = $mp['id'];
                if (($mp['状态'] ?? '') !== '') $node['state_var'] = $mp['状态'];
                return $node;

            case 'timer':
                // 倒计时：[!倒计时:1时30分|id=名|绑定=1|end=动作|lock=组|bar=进度条id]
                // 时间格式：90(秒) / 5分 / 2时 / 1时30分15秒 / 1:30 均可，组合使用
                $tp = $this->parseNewMdParams($raw);
                $seconds = $this->parseDuration($tp['value'] ?? '');
                if ($seconds <= 0) $seconds = 30;
                $node = [
                    't'       => 'timer',
                    'seconds' => $seconds,
                    'id'      => $tp['id'] ?? ('tmr' . $idx),
                ];
                if (($tp['end'] ?? '') !== '') $node['end'] = $tp['end'];
                if (($tp['lock'] ?? '') !== '') $node['lock'] = $tp['lock'];
                if (($tp['bar'] ?? '') !== '') $node['bar'] = $tp['bar'];
                if (($tp['绑定'] ?? '') === '1' || ($tp['bind'] ?? '') === '1') $node['bind'] = true;
                return $node;

            case 'stopwatch':
                // 计时器：[!计时:最大时长|id=名|绑定=1|end=动作|重复=1]
                // 从0正向计时；最大时长可省（=不限）；到达最大时长结束计时并执行 end 动作
                $cp = $this->parseNewMdParams($raw);
                $max = $this->parseDuration($cp['value'] ?? '');
                $node = [
                    't'    => 'stopwatch',
                    'max'  => $max,
                    'id'   => $cp['id'] ?? ('swt' . $idx),
                ];
                if (($cp['end'] ?? '') !== '') $node['end'] = $cp['end'];
                if (($cp['绑定'] ?? '') === '1' || ($cp['bind'] ?? '') === '1') $node['bind'] = true;
                if (($cp['重复'] ?? '') === '1' || ($cp['repeat'] ?? '') === '1') $node['repeat'] = true;
                return $node;

            case 'bar':
                // 进度：[!进度:7/10|id=名]
                $bp = $this->parseNewMdParams($raw);
                $value = 0;
                $max = 100;
                if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $bp['value'] ?? '', $m)) {
                    $value = (int) $m[1];
                    $max = (int) $m[2];
                }
                return [
                    't'     => 'bar',
                    'value' => $value,
                    'max'   => $max,
                    'id'    => $bp['id'] ?? ('bar' . $idx),
                ];

            case 'if':
                // 条件：[!条件:表达式|内容]
                $ip2 = $this->parseNewMdParams($raw);
                $parts = $this->splitTopLevelByPipe($raw);
                $then = $ip2['then'] ?? (trim($parts[1] ?? ''));
                return [
                    't'    => 'if',
                    'cond' => trim($ip2['value'] ?? ''),
                    'then' => $then,
                ];

            case 'hide':
                // 隐藏：[!隐藏:文字|动作]
                $sp = $this->splitBtnParams($raw);
                $hp = $this->parseNewMdParams($raw);
                $label = trim($hp['value'] ?? '');
                if ($label === '') $label = '隐藏';
                [$hType, $hContent] = $this->parseAction($raw);
                return $this->buttonNode('hide', $sp, $label, [
                    'action_type' => $hType,
                    'action'      => $hContent,
                ]);

            case 'text':
                // 文本：[!文本:内容|#f00|大|居中]
                $sp = $this->splitBtnParams($raw);
                $tp2 = $this->parseNewMdParams($raw);
                $node = [
                    't'     => 'textbox',
                    'text'  => $tp2['value'] ?? '',
                    'align' => 'left',
                    'style' => 'note',
                ];
                if ($sp['fg'] !== '') $node['color'] = $sp['fg'];
                if ($sp['bg'] !== '') $node['bg'] = $sp['bg'];
                $parts = $this->splitTopLevelByPipe($raw);
                for ($i = 1; $i < count($parts); $i++) {
                    $p = trim($parts[$i]);
                    // 无显式 大/小 时前端按文本长度自适应（node 不存 size）
                    if ($p === '大') $node['size'] = 'lg';
                    elseif ($p === '小') $node['size'] = 'sm';
                    elseif ($p === '居中') $node['align'] = 'center';
                    elseif ($p === '右') $node['align'] = 'right';
                }
                if (($tp2['标题'] ?? '') !== '') $node['title'] = $tp2['标题'];
                if (($tp2['t'] ?? '') !== '') $node['title'] = $tp2['t'];
                if (($tp2['颜色'] ?? '') !== '') $node['color'] = $tp2['颜色'];
                if (($tp2['color'] ?? '') !== '') $node['color'] = $tp2['color'];
                return $node;

            case 'board':
                // 画板：[!画板[:尺寸]|图形序列|bg=色|grid=0|text=..|id=..|modal=1|hide=1]
                $bp3 = $this->splitTopLevelByPipe($raw);
                $size = 20;
                $params = [];
                $shapeParts = [];
                $first = trim($bp3[0] ?? '');
                $start = 0;
                if ($first !== '' && preg_match('/^\d{1,2}$/', $first)) {
                    $size = (int) $first;
                    $start = 1;
                }
                for ($i = $start; $i < count($bp3); $i++) {
                    $seg = $bp3[$i];
                    $eq = strpos($seg, '=');
                    if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($seg, 0, $eq)))) {
                        $params[trim(substr($seg, 0, $eq))] = substr($seg, $eq + 1);
                    } else {
                        // 无键段：贪婪吞并（shapes 内部含 |f |wN 等修饰段）
                        $shapeParts[] = $seg;
                    }
                }
                $shapes = implode('|', $shapeParts);
                // v2 遗留兼容：shapes= 前缀写法（[!画板:20|shapes=l:0,0,1,1|bg=#fff]）
                if ($shapes === '' && !empty($params['shapes'])) {
                    $shapes = $params['shapes'];
                    unset($params['shapes']);
                }
                $node = [
                    't'         => 'board',
                    'size'      => max(1, min(20, $size)),
                    'shapes'    => $shapes,
                    'text'      => $params['text'] ?? '',
                    'canvas_bg' => $params['bg'] ?? '',
                    'id'        => $params['id'] ?? ('board' . $idx),
                    'grid'      => ($params['grid'] ?? '') === '0' ? '0' : '1',
                    'modal'     => ($params['modal'] ?? '') === '1',
                    'hide'      => ($params['hide'] ?? '') === '1',
                ];
                foreach (['tx', 'ty', 'ts', 'tc'] as $k) {
                    if (($params[$k] ?? '') !== '') $node[$k] = $params[$k];
                }
                if ($node['modal']) {
                    $node['label'] = $params['文字'] ?? $params['label'] ?? '查看画板';
                    $sp2 = $this->splitBtnParams($raw);
                    if ($sp2['fg'] !== '') $node['fg'] = $sp2['fg'];
                    if ($sp2['bg'] !== '') $node['bg'] = $sp2['bg'];
                    if ($sp2['perm'] !== '') $node['perm'] = $sp2['perm'];
                }
                return $node;

            case 'chain':
                // 动作链：[!动作链:步骤/步骤|循环=N|id=名|绑定=1]（隐形）
                $bp3 = $this->splitTopLevelByPipe($raw);
                $steps = trim($bp3[0] ?? '');
                $loop = '0';
                $id = 'chain' . $idx;
                $bind = '0';
                for ($i = 1; $i < count($bp3); $i++) {
                    $seg = trim($bp3[$i]);
                    if ($seg === '循环') { $loop = 'inf'; continue; }
                    $eq = strpos($seg, '=');
                    if ($eq > 0) {
                        $k = trim(substr($seg, 0, $eq));
                        $v = trim(substr($seg, $eq + 1));
                        if ($k === '循环') {
                            if ($v === '') $loop = 'inf';
                            elseif (preg_match('/^\d+$/', $v)) $loop = $v;
                        } elseif ($k === 'id') $id = $v;
                        elseif ($k === '绑定') $bind = $v === '1' ? '1' : '0';
                    }
                }
                return [
                    't'     => 'chain',
                    'steps' => $steps,
                    'loop'  => $loop,
                    'id'    => $id,
                    'bind'  => $bind,
                ];

            case 'vote':
                // 投票：[!投票:问题|选项1|选项2|...|多选=N|perm=@名|洗牌|id=..|mode=..]
                $vRaw = $this->splitTopLevelByPipe($raw);
                $vId = 'v' . $idx;
                $question = trim($vRaw[0] ?? '');
                $options = [];
                $max = 1;
                $mode = 'bar';
                $perm = '';
                $shuffle = false;
                for ($vi = 1; $vi < count($vRaw); $vi++) {
                    $seg = $vRaw[$vi];
                    $veq = strpos($seg, '=');
                    if ($veq > 0 && preg_match(self::KEY_RE, trim(substr($seg, 0, $veq)))) {
                        $vk = trim(substr($seg, 0, $veq));
                        $vv = substr($seg, $veq + 1);
                        if ($vk === 'id') $vId = $vv;
                        elseif ($vk === '多选' || $vk === 'max') $max = ((int) $vv) ?: 1;
                        elseif ($vk === 'mode') $mode = $vv;
                        elseif ($vk === 'perm') $perm = $vv;
                    } else {
                        $seg2 = trim($seg);
                        if ($seg2 === '洗牌') $shuffle = true;
                        else $options[] = $seg2;
                    }
                }
                if (count($options) === 0) $options = ['是', '否'];
                $node = [
                    't'        => 'vote',
                    'id'       => $vId,
                    'question' => $question,
                    'options'  => $options,
                    'max'      => $max,
                    'mode'     => $mode,
                    'sync'     => true,
                ];
                if ($perm !== '') $node['perm'] = $perm;
                if ($shuffle) $node['shuffle'] = true;
                return $node;

            case 'at':
                // 定时：[!定时:09:00|动作|重复=1|绑定=1]
                // 绑定=1 时自动启动（v3.7）；未绑定且 end 含发送: → 前端不自动启动（防刷屏），需按钮 定时:名 手动启动
                $ap = $this->parseNewMdParams($raw);
                $time = trim($ap['value'] ?? '');
                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) $time = '00:00';
                $node = [
                    't'    => 'at',
                    'time' => $time,
                    'id'   => $ap['id'] ?? ('at' . $idx),
                ];
                $parts = $this->splitTopLevelByPipe($raw);
                $end = $ap['end'] ?? (trim($parts[1] ?? ''));
                if ($end !== '') $node['end'] = $end;
                if (($ap['repeat'] ?? '') === '1' || ($ap['重复'] ?? '') === '1') $node['repeat'] = true;
                if (($ap['绑定'] ?? '') === '1' || ($ap['bind'] ?? '') === '1') $node['bind'] = true;
                return $node;

            case 'gallery':
                // 图集：[!图集:标题|URL1|URL2|...|自动=秒|id=名]
                $gRaw = $this->splitTopLevelByPipe($raw);
                $sp4 = $this->splitBtnParams($raw);
                $title = trim($gRaw[0] ?? '');
                $images = [];
                $autoplay = 0;
                for ($gi = 1; $gi < count($gRaw); $gi++) {
                    $seg = $gRaw[$gi];
                    $geq = strpos($seg, '=');
                    if ($geq > 0 && preg_match(self::KEY_RE, trim(substr($seg, 0, $geq)))) {
                        $gk = trim(substr($seg, 0, $geq));
                        $gv = substr($seg, $geq + 1);
                        if ($gk === 'autoplay' || $gk === '自动') $autoplay = ((int) $gv) ?: 0;
                    } else {
                        $seg = trim($seg);
                        if ($seg !== '') $images[] = $seg;
                    }
                }
                $node = $this->buttonNode('gallery', $sp4, $title !== '' ? $title : '查看图集', ['images' => $images]);
                if ($title !== '') $node['title'] = $title;
                if ($autoplay) $node['autoplay'] = $autoplay;
                return $node;
        }

        return ['t' => 'text', 'text' => ''];
    }

    // ==================== 按钮 / 参数解析辅助 ====================

    /** 键名正则：小写字母或中文开头，含字母数字中文._- */
    private const KEY_RE = '/^[a-z\x{4e00}-\x{9fa5}][a-z0-9\x{4e00}-\x{9fa5}._-]*$/u';

    /**
     * 解析动作参数（v3）：从 raw 的第一个无键参数段识别动作前缀（发送:/复制:/弹窗:/跳转:/...），
     * 后续无键段贪婪吞并（弹窗:标题|内容 的内容部分），直到键=值参数段停止。
     * 返回 [actionType, actionContent]；非动作返回 ['', '']。
     */
    private function parseAction(string $raw): array
    {
        $parts = $this->splitTopLevelByPipe($raw);
        $first = trim($parts[1] ?? '');
        if ($first === '') {
            return ['', ''];
        }
        foreach (self::ACTION_PREFIXES as $ap) {
            if (str_starts_with($first, $ap . ':')) {
                $content = substr($first, strlen($ap) + 1);
                // 吞并后续无键段（弹窗/图集等动作内容可含 |）
                for ($i = 2; $i < count($parts); $i++) {
                    $p = trim($parts[$i]);
                    $eq = strpos($p, '=');
                    if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($p, 0, $eq)))) {
                        break;
                    }
                    $content .= '|' . $p;
                }
                return [self::ACTION_TYPE_MAP[$ap], $content];
            }
        }
        if (preg_match('#^https?://#i', $first)) {
            return ['url', $first];
        }
        return ['', ''];
    }

    /**
     * 构造通用按钮节点，合并 splitBtnParams 的颜色/权限/音效/动画/点击限制。
     */
    private function buttonNode(string $type, array $sp, string $label, array $extra = []): array
    {
        // 文字= 参数可覆盖外部显示文本（v3）
        if (($sp['label'] ?? '') !== '') {
            $label = $sp['label'];
        }
        $node = array_merge([
            't'       => $type,
            'label'   => $label,
            'content' => $sp['content'],
        ], $extra);

        $this->applyButtonExtras($node, $sp);
        return $node;
    }

    private function applyButtonExtras(array &$node, array $sp): void
    {
        if (($sp['id'] ?? '') !== '') $node['id'] = $sp['id'];
        if ($sp['fg'] !== '') $node['fg'] = $sp['fg'];
        if ($sp['bg'] !== '') $node['bg'] = $sp['bg'];
        if ($sp['perm'] !== '') $node['perm'] = $sp['perm'];
        if ($sp['sound'] !== '') $node['sound'] = $sp['sound'];
        if ($sp['anim'] !== '') $node['anim'] = $sp['anim'];
        if ($sp['click'] !== '') {
            $node['click'] = $sp['click'];
            $node['sync'] = true;
        }
    }

    /**
     * 按 | 分割参数，但跳过括号内（() 和 []）的 |（对齐前端 splitTopLevelByPipe）。
     */
    private function splitTopLevelByPipe(string $str): array
    {
        $parts = [];
        $cur = '';
        $depth = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === '|' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $parts[] = $cur;
        return $parts;
    }

    /**
     * 解析按钮参数（v3）：裸色 #hex / 颜色= / 颜色.bg= / perm= / 音效= / 动画= / 点击=。
     * 返回 [content, fg, bg, perm, sound, anim, click]（对齐前端 splitBtnParams）。
     */
    private function splitBtnParams(string $raw): array
    {
        $fg = '';
        $bg = '';
        $perm = '';
        $sound = '';
        $anim = '';
        $click = '';

        $parts = $this->splitTopLevelByPipe($raw);
        $params = [];
        $mainParts = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq > 0) {
                $key = trim(substr($p, 0, $eq));
                if (preg_match(self::KEY_RE, $key)) {
                    $params[$key] = substr($p, $eq + 1);
                    continue;
                }
            }
            if (str_contains($p, '://')) {
                $mainParts[] = $p;
                continue;
            }
            // 裸色简写：#hex（第一个为前景，第二个为背景）
            $pt = trim($p);
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $pt)) {
                if ($fg === '') $fg = $pt;
                elseif ($bg === '') $bg = $pt;
                continue;
            }
            $mainParts[] = $p;
        }

        $content = implode('|', $mainParts);
        $label = $params['文字'] ?? '';
        $cv = $params['颜色'] ?? $params['color'] ?? '';
        // 仅当为合法颜色格式（#hex / hex / -1）才应用；否则留给组件自身解析（如开关的 颜色=1）
        if ($cv !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|[0-9a-fA-F]{3,8}|-1)$/', trim($cv))) {
            $cParts = preg_split('/[|\/]/', $cv);
            if (count($cParts) === 1 && trim($cParts[0]) === '-1') {
                $fg = '';
                $bg = '-1';
            } else {
                $fg = trim($cParts[0] ?? '');
                $bg = trim($cParts[1] ?? '');
            }
        }
        if (isset($params['color.bg'])) $bg = $params['color.bg'];
        if (isset($params['perm'])) $perm = $params['perm'];
        if (isset($params['音效'])) $sound = $params['音效'];
        if (isset($params['sound'])) $sound = $params['sound'];
        if (isset($params['动画'])) $anim = $params['动画'];
        if (isset($params['anim'])) $anim = $params['anim'];
        if (isset($params['点击'])) $click = $params['点击'];
        if (isset($params['click'])) $click = $params['click'];

        return [
            'content' => $content,
            'label'   => $label,
            'fg'      => $fg,
            'bg'      => $bg,
            'perm'    => $perm,
            'sound'   => $sound,
            'anim'    => $anim,
            'click'   => $click,
            'id'      => $params['id'] ?? '',
        ];
    }

    /**
     * 解析 | 分隔的键=值 参数（对齐前端 parseNewMdParams），首个为 value。
     */
    private function parseNewMdParams(string $content): array
    {
        $parts = $this->splitTopLevelByPipe($content);
        $result = ['value' => $parts[0] ?? ''];
        // 首段也可能是键=值（无内容写法，如 [!计时|id=c2]），一并解析（value 保留原文）
        if (count($parts) > 0) {
            $p0 = $parts[0];
            $eq0 = strpos($p0, '=');
            if ($eq0 > 0 && preg_match(self::KEY_RE, trim(substr($p0, 0, $eq0)))) {
                $result[trim(substr($p0, 0, $eq0))] = substr($p0, $eq0 + 1);
            }
        }
        for ($i = 1; $i < count($parts); $i++) {
            $p = $parts[$i];
            $eq = strpos($p, '=');
            if ($eq > 0 && preg_match(self::KEY_RE, trim(substr($p, 0, $eq)))) {
                $result[trim(substr($p, 0, $eq))] = substr($p, $eq + 1);
            }
        }
        return $result;
    }

    /**
     * 解析时长（v3 倒计时/计时器通用）：
     * 90 / 5分 / 2时 / 1时30分15秒 / 1:30 → 总秒数
     */
    private function parseDuration(string $str): int
    {
        $s = trim($str);
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $s)) {
            return (int) $s;
        }
        if (preg_match('/^\d{1,2}:\d{1,2}(:\d{1,2})?$/', $s)) {
            $p = array_map('intval', explode(':', $s));
            return $p[0] * 3600 + ($p[1] ?? 0) * 60 + ($p[2] ?? 0);
        }
        $total = 0;
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(小时|时|分钟|分|秒)/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $v = (float) $mm[1];
                $u = $mm[2];
                if ($u === '小时' || $u === '时') {
                    $total += $v * 3600;
                } elseif ($u === '分钟' || $u === '分') {
                    $total += $v * 60;
                } else {
                    $total += $v;
                }
            }
        }
        return (int) round($total);
    }

    /**
     * 解析 switch 参数（对齐前端 parseSwitchParams）。
     */
    private function parseSwitchParams(string $content): array
    {
        $parts = $this->splitTopLevelByPipe($content);
        $values = [];
        $colors = [];
        $id = '';
        $color = false;
        foreach ($parts as $p) {
            if (str_starts_with($p, 'id=')) {
                $id = substr($p, 3);
            } elseif (str_starts_with($p, 'cc=')) {
                $colors = explode('/', substr($p, 3));
            } elseif (str_starts_with($p, 'c=')) {
                $color = (substr($p, 2) === '1');
            } else {
                $values[] = $p;
            }
        }
        return ['values' => $values, 'colors' => $colors, 'id' => $id, 'color' => $color];
    }

    /**
     * 解析表格参数：首段纯数字=列数 / col=N / 其余为单元格（对齐前端 parseTableParams）。
     */
    private function parseTableParams(string $content): array
    {
        $parts = $this->splitTopLevelByPipe($content);
        $cols = 2;
        $cells = [];
        foreach ($parts as $i => $p) {
            $p = trim($p);
            if (str_starts_with($p, 'col=')) {
                $cols = ((int) substr($p, 4)) ?: 2;
            } elseif ($i === 0 && preg_match('/^\d+$/', $p)) {
                $cols = (int) $p;
            } else {
                $cells[] = $p;
            }
        }
        return ['cols' => $cols, 'cells' => $cells];
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $v) {
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    // ==================== 纯文本摘要 ====================

    /**
     * 从已解析的 blocks 数组提取纯文本摘要（供举报证据 / 广播降级 / 管理后台展示）。
     */
    public function plainTextOf(array $blocks): string
    {
        return $this->extractPlainText($blocks);
    }

    private function extractPlainText(array $blocks): string
    {
        $parts = [];
        $this->collectPlainText($blocks, $parts);
        $text = trim(implode(' ', $parts));
        return preg_replace('/\s+/u', ' ', $text) ?: $text;
    }

    private function collectPlainText(array $blocks, array &$out): void
    {
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            $t = $b['t'] ?? '';
            if ($t === 'text') {
                if (($b['text'] ?? '') !== '') $out[] = $b['text'];
            } elseif ($t === 'ref') {
                if (($b['var'] ?? '') !== '') $out[] = '{' . $b['var'] . '}';
            } elseif ($t === 'error') {
                // 解析错误节点：整条只含错误信息，用原始文本作为纯文本摘要（举报证据/通知预览）
                if (($b['raw'] ?? '') !== '') $out[] = $b['raw'];
            } else {
                if (isset($b['label']) && $b['label'] !== '') $out[] = $b['label'];
                if (isset($b['title']) && $b['title'] !== '') $out[] = $b['title'];
                if (isset($b['text']) && $b['text'] !== '') $out[] = $b['text'];
                if (isset($b['question']) && $b['question'] !== '') $out[] = $b['question'];
                foreach (['cells', 'options', 'values'] as $listKey) {
                    if (isset($b[$listKey]) && is_array($b[$listKey])) {
                        foreach ($b[$listKey] as $item) {
                            if (is_string($item) && $item !== '') $out[] = $item;
                        }
                    }
                }
                if (isset($b['children']) && is_array($b['children'])) {
                    $this->collectPlainText($b['children'], $out);
                }
            }
        }
    }
}
