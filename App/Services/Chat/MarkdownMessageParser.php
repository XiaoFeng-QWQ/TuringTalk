<?php

namespace App\Services\Chat;

/**
 * 聊天室特殊 Markdown 语法解析器
 *
 * 把原始文本中的 [!文字](类型:内容) 组件语法解析为结构化组件树 blocks。
 * 文本流中的 %变量% 引用拆分为 ref 节点；组件属性内的 %x% 保留字符串，由客户端本地求值。
 *
 * 单遍扫描，线性复杂度；递归深度/节点数/JSON 长度均有上限，超限降级为纯文本节点。
 */
class MarkdownMessageParser
{
    /** 内容型组件递归深度上限（超过后该片段降级为 text） */
    private const MAX_DEPTH = 10;

    /** 单条消息最大节点数（超过后整条降级为 text） */
    private const MAX_NODES = 200;

    /** blocks JSON 最大字节数（超过后整条降级为 text） */
    private const MAX_JSON_BYTES = 16384;

    /** 组件类型前缀 */
    private const COMPONENT_RE = '/\[!([^\]]+)\]\((modal|send|copy|embed|confirm|details|rand|input|get|ok|cancel|close|switch|var|def|cipher|table|music|timer|bar|if|hide|text|board|vote|dice|at|gallery):/';

    /** 文本流中的变量引用：%标识符% 或 %标识符|默认值% */
    private const REF_RE = '/(%[A-Za-z_\x{4e00}-\x{9fa5}][A-Za-z0-9_\x{4e00}-\x{9fa5}|-]*%)/u';

    private int $nodeCount = 0;

    /** 动作按钮序号（用于 vote/switch/timer/bar/board/at 等默认 id 的 v0/sw0/... 命名） */
    private int $btnIndex = 0;

    /** 当前正在构建的节点序号 */
    private int $currentBtnIndex = 0;

    /**
     * 判断文本是否包含需要结构化解析的特殊语法（组件语法或 %变量% 引用）。
     */
    public static function hasSpecialSyntax(string $text): bool
    {
        if (preg_match(self::COMPONENT_RE, $text)) {
            return true;
        }
        if (preg_match(self::REF_RE, $text)) {
            return true;
        }
        return false;
    }

    /**
     * 解析文本为组件树。
     *
     * @return array{blocks: array, text: string}
     */
    public function parse(string $text): array
    {
        $this->nodeCount = 0;
        $this->btnIndex = 0;
        $this->currentBtnIndex = 0;

        $blocks = [];
        $this->scanText($text, 0, $blocks);

        $plainText = $this->extractPlainText($blocks);
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 安全边界：节点数 / JSON 长度超限，整条降级为纯文本
        if ($this->nodeCount > self::MAX_NODES || strlen($json) > self::MAX_JSON_BYTES) {
            return [
                'blocks' => [['t' => 'text', 'text' => $text]],
                'text'   => $text,
            ];
        }

        return [
            'blocks' => $blocks,
            'text'   => $plainText,
        ];
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

            [$start, $label, $type, $contentStart, $contentEnd, $end] = $found;

            $buf .= substr($text, $i, $start - $i);
            $this->appendTextFlow($buf, $out);
            $buf = '';

            $raw = substr($text, $contentStart, $contentEnd - $contentStart);
            $this->currentBtnIndex = $this->btnIndex++;
            $node = $this->buildNode($type, $label, $raw, $depth);
            if ($node !== null) {
                $out[] = $node;
                $this->nodeCount++;
            }

            $i = $end;
        }

        if ($buf !== '') {
            $this->appendTextFlow($buf, $out);
        }
    }

    /**
     * 在 offset 处查找下一个组件。返回 [start, label, type, contentStart, contentEnd, end]，找不到或未闭合返回 null。
     */
    private function findComponent(string $text, int $offset): ?array
    {
        if (!preg_match(self::COMPONENT_RE, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }

        $label = $m[1][0];
        $type = $m[2][0];
        $start = $m[0][1];
        $contentStart = $m[0][1] + strlen($m[0][0]);

        // 括号深度计数，找到匹配的右括号
        $depth = 1;
        $len = strlen($text);
        $bi = $contentStart;
        for (; $bi < $len; $bi++) {
            $ch = $text[$bi];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        if ($bi >= $len) {
            return null; // 未闭合，按普通文本处理
        }

        return [$start, $label, $type, $contentStart, $bi, $bi + 1];
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
     * 把文本片段拆分为 text 节点与 %变量% ref 节点。
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
            if (strlen($part) > 2 && $part[0] === '%' && substr($part, -1) === '%') {
                $out[] = ['t' => 'ref', 'var' => substr($part, 1, -1)];
                $this->nodeCount++;
            } else {
                $this->appendTextNode($part, $out);
            }
        }
    }

    // ==================== 节点构建 ====================

    private function buildNode(string $type, string $label, string $raw, int $depth): ?array
    {
        switch ($type) {
            case 'modal':
                return $this->buildModal($label, $raw, $depth);
            case 'details':
                return $this->buildDetails($label, $raw, $depth);
            case 'send':
            case 'copy':
            case 'embed':
            case 'confirm':
            case 'rand':
            case 'input':
            case 'get':
            case 'ok':
            case 'cancel':
            case 'close':
            case 'switch':
            case 'var':
            case 'def':
            case 'cipher':
            case 'table':
            case 'music':
            case 'timer':
            case 'bar':
            case 'if':
            case 'hide':
            case 'text':
            case 'board':
            case 'vote':
            case 'dice':
            case 'at':
            case 'gallery':
                return $this->buildGenericNode($type, $label, $raw);
        }

        return null;
    }

    private function buildModal(string $label, string $raw, int $depth): array
    {
        $sp = $this->splitBtnParams($raw);
        $mp = $this->parseNewMdParams($raw);
        $modalRaw = $sp['content'];
        $sep = strpos($modalRaw, '|');

        $title = $this->firstNonEmpty($mp['t'] ?? '', $sep !== false ? substr($modalRaw, 0, $sep) : '', '提示');
        $content = $sep !== false ? substr($modalRaw, $sep + 1) : $modalRaw;

        return $this->buttonNode('modal', $sp, $label, [
            'title'    => $title,
            'children' => $this->parseChildren($content, $depth),
        ]);
    }

    private function buildDetails(string $label, string $raw, int $depth): array
    {
        $sp = $this->splitBtnParams($raw);
        $dRaw = $sp['content'];
        $sep = strpos($dRaw, '|');

        $title = $sep !== false ? substr($dRaw, 0, $sep) : '详情';
        $content = $sep !== false ? substr($dRaw, $sep + 1) : $dRaw;

        return $this->buttonNode('details', $sp, $label, [
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

    private function buildGenericNode(string $type, string $label, string $raw): array
    {
        $idx = $this->currentBtnIndex;

        switch ($type) {
            case 'send':
            case 'copy':
            case 'embed':
                $sp = $this->splitBtnParams($raw);
                return $this->buttonNode($type, $sp, $label);

            case 'confirm':
                $sp = $this->splitBtnParams($raw);
                $cSep = strpos($sp['content'], '|');
                return $this->buttonNode('confirm', $sp, $label, [
                    'message' => $cSep !== false ? substr($sp['content'], 0, $cSep) : '确定执行吗？',
                    'action'  => $cSep !== false ? substr($sp['content'], $cSep + 1) : '',
                ]);

            case 'rand':
                $sp = $this->splitBtnParams($raw);
                $rp = $this->parseNewMdParams($raw);
                $options = array_values(array_filter(array_map('trim', explode('|', $sp['content'])), fn($s) => $s !== ''));
                $node = $this->buttonNode('rand', $sp, $label, [
                    'options' => $options,
                    'mode'    => ($rp['mode'] ?? '') === 'modal' ? 'modal' : 'send',
                ]);
                if (($rp['t'] ?? '') !== '') {
                    $node['title'] = $rp['t'];
                }
                return $node;

            case 'input':
                $sp = $this->splitBtnParams($raw);
                $ip = $this->parseNewMdParams($raw);
                $node = $this->buttonNode('input', $sp, $label, [
                    'placeholder' => $ip['value'] ?? '',
                    'id'          => $ip['id'] ?? ('inp' . $idx),
                    'ok'          => $ip['ok'] ?? '',
                ]);
                if (isset($ip['colorof'])) $node['colorof'] = $ip['colorof'];
                if (isset($ip['on'])) $node['onchange'] = $ip['on'];
                return $node;

            case 'get':
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

            case 'ok':
                $sp = $this->splitBtnParams($raw);
                $ob = $this->parseNewMdParams($raw);
                $node = $this->buttonNode('ok', $sp, $label, [
                    'bind'  => trim($ob['value'] ?? ''),
                    'right' => $ob['right'] ?? '',
                    'wrong' => $ob['wrong'] ?? '',
                ]);
                if (isset($ob['lock'])) $node['lock'] = $ob['lock'];
                return $node;

            case 'cancel':
            case 'close':
                $sp = $this->splitBtnParams($raw);
                return $this->buttonNode($type, $sp, $label, ['action' => trim($sp['content'])]);

            case 'switch':
                $sp = $this->splitBtnParams($raw);
                $sw = $this->parseSwitchParams($raw);
                $sw2 = $this->parseNewMdParams($raw);
                $values = count($sw['values']) > 0 ? $sw['values'] : [$label];
                $node = $this->buttonNode('switch', $sp, $label, [
                    'values' => $values,
                    'id'     => $sw['id'] !== '' ? $sw['id'] : ('sw' . $idx),
                    'color'  => $sw['color'],
                ]);
                if (count($sw['colors']) > 0) $node['colors'] = $sw['colors'];
                if (isset($sw2['on'])) $node['onchange'] = $sw2['on'];
                $lock = $sw2['lock'] ?? ($sw['lock'] ?? '');
                if ($lock !== '') $node['lock'] = $lock;
                return $node;

            case 'var':
                $vp = $this->parseNewMdParams($raw);
                return [
                    't'    => 'var',
                    'var'  => trim($vp['value'] ?? ''),
                    'init' => $vp['init'] ?? '',
                ];

            case 'def':
                $dp = $this->parseNewMdParams($raw);
                return [
                    't'    => 'def',
                    'var'  => trim($dp['value'] ?? ''),
                    'init' => $dp['init'] ?? '',
                ];

            case 'cipher':
                $sp = $this->splitBtnParams($raw);
                $cpp = $this->parseNewMdParams($raw);
                return $this->buttonNode('cipher', $sp, $label, [
                    'value' => $cpp['value'] ?? '',
                    'key'   => $cpp['key'] ?? 'md',
                ]);

            case 'table':
                $tbl = $this->parseTableParams($raw);
                return [
                    't'     => 'table',
                    'cols'  => max(1, $tbl['cols']),
                    'cells' => $tbl['cells'],
                ];

            case 'music':
                $mp = $this->parseNewMdParams($raw);
                $node = [
                    't'   => 'music',
                    'url' => trim($mp['value'] ?? ''),
                ];
                if (($mp['t'] ?? '') !== '') $node['title'] = $mp['t'];
                return $node;

            case 'timer':
                $tp = $this->parseNewMdParams($raw);
                $node = [
                    't'       => 'timer',
                    'seconds' => ((int) ($tp['value'] ?? 0)) ?: 30,
                    'id'      => $tp['id'] ?? ('tmr' . $idx),
                ];
                if (($tp['end'] ?? '') !== '') $node['end'] = $tp['end'];
                if (($tp['lock'] ?? '') !== '') $node['lock'] = $tp['lock'];
                if (($tp['bar'] ?? '') !== '') $node['bar'] = $tp['bar'];
                return $node;

            case 'bar':
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
                $ip2 = $this->parseNewMdParams($raw);
                return [
                    't'    => 'if',
                    'cond' => trim($ip2['value'] ?? ''),
                    'then' => $ip2['then'] ?? '',
                ];

            case 'hide':
                $sp = $this->splitBtnParams($raw);
                $hRaw = $sp['content'];
                $hType = '';
                foreach (['send:', 'copy:', 'modal:', 'embed:', 'confirm:', 'details:', 'rand:', 'ok:', 'cancel:', 'close:', 'switch:'] as $t) {
                    if (str_starts_with($hRaw, $t)) {
                        $hType = rtrim($t, ':');
                        break;
                    }
                }
                $hContent = $hType !== '' ? substr($hRaw, strlen($hType) + 1) : $hRaw;
                $node = $this->buttonNode('hide', $sp, $label, [
                    'action_type' => $hType,
                    'action'      => $hContent,
                ]);
                if ($hType === 'switch') {
                    $hs = $this->parseSwitchParams($hContent);
                    $node['switch_values'] = count($hs['values']) > 0 ? $hs['values'] : [$label];
                    $node['switch_id'] = $hs['id'] !== '' ? $hs['id'] : ('hs' . $idx);
                }
                return $node;

            case 'text':
                $tp2 = $this->parseNewMdParams($raw);
                $node = [
                    't'     => 'textbox',
                    'text'  => $tp2['value'] ?? '',
                    'align' => $tp2['align'] ?? 'left',
                    'size'  => $tp2['size'] ?? 'md',
                    'style' => $tp2['style'] ?? 'note',
                ];
                if (($tp2['t'] ?? '') !== '') $node['title'] = $tp2['t'];
                if (($tp2['color'] ?? '') !== '') $node['color'] = $tp2['color'];
                if (($tp2['color.bg'] ?? '') !== '') $node['bg'] = $tp2['color.bg'];
                return $node;

            case 'board':
                $bp2 = $this->parseNewMdParams($raw);
                $sp2 = $this->splitBtnParams($raw);
                $size = max(1, min(20, ((int) ($bp2['value'] ?? 0)) ?: 20));
                $node = [
                    't'         => 'board',
                    'size'      => $size,
                    'shapes'    => $bp2['shapes'] ?? '',
                    'text'      => $bp2['text'] ?? '',
                    'canvas_bg' => $bp2['bg'] ?? '',
                    'id'        => $bp2['id'] ?? ('board' . $idx),
                    'grid'      => ($bp2['grid'] ?? '') === '0' ? '0' : '1',
                    'modal'     => ($bp2['modal'] ?? '') === '1',
                    'hide'      => ($bp2['hide'] ?? '') === '1',
                ];
                foreach (['tx', 'ty', 'ts', 'tc'] as $k) {
                    if (($bp2[$k] ?? '') !== '') $node[$k] = $bp2[$k];
                }
                if ($node['modal']) {
                    $node['label'] = $label;
                    if ($sp2['fg'] !== '') $node['fg'] = $sp2['fg'];
                    if ($sp2['bg'] !== '') $node['bg'] = $sp2['bg'];
                    if ($sp2['perm'] !== '') $node['perm'] = $sp2['perm'];
                }
                return $node;

            case 'vote':
                $vRaw = $this->splitTopLevelByPipe($raw);
                $vId = 'v' . $idx;
                $question = trim($vRaw[0] ?? '');
                $options = [];
                $max = 1;
                $mode = 'bar';
                for ($vi = 1; $vi < count($vRaw); $vi++) {
                    $seg = $vRaw[$vi];
                    $veq = strpos($seg, '=');
                    if ($veq > 0 && preg_match('/^[a-z][a-z0-9.]*$/', trim(substr($seg, 0, $veq)))) {
                        $vk = trim(substr($seg, 0, $veq));
                        $vv = substr($seg, $veq + 1);
                        if ($vk === 'id') $vId = $vv;
                        elseif ($vk === 'max') $max = ((int) $vv) ?: 1;
                        elseif ($vk === 'mode') $mode = $vv;
                    } else {
                        $options[] = $seg;
                    }
                }
                if (count($options) === 0) $options = [$label];
                return [
                    't'        => 'vote',
                    'id'       => $vId,
                    'question' => $question,
                    'options'  => $options,
                    'max'      => $max,
                    'mode'     => $mode,
                    'sync'     => true,
                ];

            case 'dice':
                $sp3 = $this->splitBtnParams($raw);
                $dp3 = $this->parseNewMdParams($raw);
                $expr = trim($dp3['value'] ?? '');
                if (!preg_match('/^(\d*)d(\d+)([+-]\d+)?$/i', $expr)) $expr = '1d6';
                $node = $this->buttonNode('dice', $sp3, $label, ['expr' => $expr]);
                if (($dp3['id'] ?? '') !== '') $node['id'] = $dp3['id'];
                return $node;

            case 'at':
                $ap = $this->parseNewMdParams($raw);
                $time = trim($ap['value'] ?? '');
                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) $time = '00:00';
                $node = [
                    't'    => 'at',
                    'time' => $time,
                    'id'   => $ap['id'] ?? ('at' . $idx),
                ];
                if (($ap['end'] ?? '') !== '') $node['end'] = $ap['end'];
                if (($ap['repeat'] ?? '') === '1') $node['repeat'] = true;
                return $node;

            case 'gallery':
                $gRaw = $this->splitTopLevelByPipe($raw);
                $sp4 = $this->splitBtnParams($raw);
                $title = trim($gRaw[0] ?? '');
                $images = [];
                $autoplay = 0;
                for ($gi = 1; $gi < count($gRaw); $gi++) {
                    $seg = $gRaw[$gi];
                    $geq = strpos($seg, '=');
                    if ($geq > 0 && preg_match('/^[a-z][a-z0-9.]*$/', trim(substr($seg, 0, $geq)))) {
                        $gk = trim(substr($seg, 0, $geq));
                        $gv = substr($seg, $geq + 1);
                        if ($gk === 'autoplay') $autoplay = ((int) $gv) ?: 0;
                    } else {
                        $seg = trim($seg);
                        if ($seg !== '') $images[] = $seg;
                    }
                }
                $node = $this->buttonNode('gallery', $sp4, $label, ['images' => $images]);
                if ($title !== '') $node['title'] = $title;
                if ($autoplay) $node['autoplay'] = $autoplay;
                return $node;
        }

        return ['t' => 'text', 'text' => $label];
    }

    // ==================== 按钮 / 参数解析辅助 ====================

    /**
     * 构造通用按钮节点，合并 splitBtnParams 的颜色/权限/音效/动画/点击限制。
     */
    private function buttonNode(string $type, array $sp, string $label, array $extra = []): array
    {
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
     * 解析按钮参数：提取颜色后缀、命名参数（键=值），返回
     * [content, fg, bg, perm, sound, anim, click]（对齐前端 splitBtnParams）。
     */
    private function splitBtnParams(string $raw): array
    {
        $content = $raw;
        $fg = '';
        $bg = '';
        $perm = '';
        $sound = '';
        $anim = '';
        $click = '';

        // 兼容旧写法颜色后缀：::前景|背景 / ::前景/背景 / ::单色 / ::-1（透明）
        if (preg_match('/::([#0-9a-fA-F]{3,8}|-1)(?:[|\/]([#0-9a-fA-F]{3,8}|-1))?\s*$/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $fg = $m[1][0] ?? '';
            $bg = isset($m[2]) ? $m[2][0] : '';
            if ($fg === '-1' && $bg === '') {
                $bg = '-1';
                $fg = '';
            }
            $raw = rtrim(substr($raw, 0, $m[0][1]));
        }

        $parts = $this->splitTopLevelByPipe($raw);
        $params = [];
        $mainParts = [];
        foreach ($parts as $p) {
            if (str_contains($p, '://')) {
                $mainParts[] = $p;
                continue;
            }
            $eq = strpos($p, '=');
            if ($eq > 0) {
                $key = trim(substr($p, 0, $eq));
                if (preg_match('/^[a-z][a-z0-9.]*$/', $key)) {
                    $params[$key] = substr($p, $eq + 1);
                    continue;
                }
            }
            $mainParts[] = $p;
        }

        $content = implode('|', $mainParts);
        if (isset($params['color'])) {
            $cParts = explode('|', (string) $params['color']);
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
        if (isset($params['sound'])) $sound = $params['sound'];
        if (isset($params['anim'])) $anim = $params['anim'];
        if (isset($params['click'])) $click = $params['click'];

        return [
            'content' => $content,
            'fg'      => $fg,
            'bg'      => $bg,
            'perm'    => $perm,
            'sound'   => $sound,
            'anim'    => $anim,
            'click'   => $click,
        ];
    }

    /**
     * 解析 | 分隔的键=值 参数（对齐前端 parseNewMdParams），首个为 value。
     */
    private function parseNewMdParams(string $content): array
    {
        $parts = $this->splitTopLevelByPipe($content);
        $result = ['value' => $parts[0] ?? ''];
        for ($i = 1; $i < count($parts); $i++) {
            $p = $parts[$i];
            $eq = strpos($p, '=');
            if ($eq > 0) {
                $result[trim(substr($p, 0, $eq))] = substr($p, $eq + 1);
            }
        }
        return $result;
    }

    /**
     * 解析 switch 参数（对齐前端 parseSwitchParams）。
     */
    private function parseSwitchParams(string $content): array
    {
        $parts = explode('|', $content);
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
     * 解析表格参数（对齐前端 parseTableParams）。
     */
    private function parseTableParams(string $content): array
    {
        $parts = explode('|', $content);
        $cols = 2;
        $cells = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (str_starts_with($p, 'col=')) {
                $cols = ((int) substr($p, 4)) ?: 2;
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
                if (($b['var'] ?? '') !== '') $out[] = '%' . $b['var'] . '%';
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