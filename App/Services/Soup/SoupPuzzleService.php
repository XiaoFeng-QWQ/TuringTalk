<?php

namespace App\Services\Soup;

use App\Config\Config;
use App\Core\Sanitizer;
use App\Services\Repository\SoupPuzzleRepository;

/**
 * 汤面库业务逻辑：上传 / 管理 / 共享 / 选题
 *
 * 与 Repository 的区别：这里做字段校验、配额限制、可见性裁剪（对猜题人隐藏汤底）。
 */
class SoupPuzzleService
{
    /** 汤面/汤底最大长度 */
    private const MAX_SURFACE = 1000;
    private const MAX_TRUTH   = 2000;
    private const MAX_HINT    = 200;

    // ==================== 我的汤面 ====================

    /**
     * 我的汤面列表（含汤底，仅本人可见）
     */
    public function listMine(string $ownerId): array
    {
        return SoupPuzzleRepository::listByOwner($ownerId);
    }

    /**
     * 新建汤面
     * @return array{success: bool, error?: string, id?: int}
     */
    public function create(string $ownerId, array $data): array
    {
        if ($ownerId === '') return ['success' => false, 'error' => '身份验证失败'];

        $limit = (int)Config::get('Soup.PublicMaxMyPuzzles', 100);
        if ($limit > 0 && SoupPuzzleRepository::countByOwner($ownerId) >= $limit) {
            return ['success' => false, 'error' => "汤面数量已达上限（{$limit}）"];
        }

        $clean = $this->validate($data);
        if ($clean === null) return ['success' => false, 'error' => '汤面和汤底均不能为空'];

        $id = SoupPuzzleRepository::create($ownerId, $clean);
        if ($id === null) return ['success' => false, 'error' => '保存失败，请稍后再试'];

        return ['success' => true, 'id' => $id];
    }

    /**
     * 编辑汤面（仅本人）
     */
    public function update(string $ownerId, int $id, array $data): array
    {
        if ($ownerId === '' || $id <= 0) return ['success' => false, 'error' => '参数不完整'];
        $clean = $this->validate($data);
        if ($clean === null) return ['success' => false, 'error' => '汤面和汤底均不能为空'];

        if (!SoupPuzzleRepository::update($id, $ownerId, $clean)) {
            return ['success' => false, 'error' => '汤面不存在或无权编辑'];
        }
        return ['success' => true];
    }

    /**
     * 删除汤面（仅本人）
     */
    public function delete(string $ownerId, int $id): array
    {
        if ($ownerId === '' || $id <= 0) return ['success' => false, 'error' => '参数不完整'];
        if (!SoupPuzzleRepository::delete($id, $ownerId)) {
            return ['success' => false, 'error' => '汤面不存在或无权删除'];
        }
        return ['success' => true];
    }

    /**
     * 切换共享（公开/私有），仅本人
     * @return array{success: bool, scope?: string, error?: string}
     */
    public function toggleShare(string $ownerId, int $id): array
    {
        if ($ownerId === '' || $id <= 0) return ['success' => false, 'error' => '参数不完整'];

        $puzzle = SoupPuzzleRepository::findById($id);
        if (!$puzzle || (string)$puzzle['owner_id'] !== $ownerId || (string)$puzzle['source'] !== 'member') {
            return ['success' => false, 'error' => '汤面不存在或无权操作'];
        }
        $newScope = ($puzzle['scope'] ?? 'private') === 'public' ? 'private' : 'public';
        $result = SoupPuzzleRepository::setScope($id, $ownerId, $newScope);
        if ($result === null) return ['success' => false, 'error' => '操作失败，请稍后再试'];
        return ['success' => true, 'scope' => $result];
    }

    // ==================== 公开汤池 ====================

    /**
     * 公开汤池（官方 + 玩家公开），对猜题人隐藏汤底
     */
    public function listPublic(): array
    {
        $rows = SoupPuzzleRepository::listPublic();
        return array_map([$this, 'toPublicView'], $rows);
    }

    // ==================== 选题 ====================

    /**
     * 根据题库来源解析出开局用汤面（含汤底，仅服务端/出题人使用）
     *
     * @param string $source      mine / public / official / random
     * @param int    $puzzleId    指定题目 id（random 时可忽略）
     * @param int[]  $excludeIds  随机时排除的已用题目 id
     * @return array{success: bool, puzzle?: array, error?: string}
     */
    public function resolveForRoom(string $ownerId, string $source, int $puzzleId, array $excludeIds = []): array
    {
        $source = in_array($source, ['mine', 'public', 'official', 'random'], true) ? $source : 'random';

        if ($source === 'random') {
            $puzzle = SoupPuzzleRepository::randomPublic($excludeIds);
            if (!$puzzle) return ['success' => false, 'error' => '公开汤池暂无可用题目'];
            return ['success' => true, 'puzzle' => $puzzle];
        }

        if ($puzzleId <= 0) return ['success' => false, 'error' => '请选择一道汤面'];

        $puzzle = SoupPuzzleRepository::findById($puzzleId);
        if (!$puzzle || (string)$puzzle['status'] !== 'active') {
            return ['success' => false, 'error' => '汤面不存在或已下架'];
        }

        $isOfficial = (string)$puzzle['source'] === 'official';
        $isPublic   = $isOfficial || (string)$puzzle['scope'] === 'public';

        switch ($source) {
            case 'mine':
                if ((string)$puzzle['owner_id'] !== $ownerId || (string)$puzzle['source'] !== 'member') {
                    return ['success' => false, 'error' => '只能选择自己的汤面'];
                }
                break;
            case 'official':
                if (!$isOfficial) return ['success' => false, 'error' => '该题不是官方题目'];
                break;
            case 'public':
            default:
                if (!$isPublic) return ['success' => false, 'error' => '该汤面未公开共享'];
                break;
        }

        return ['success' => true, 'puzzle' => $puzzle];
    }

    // ==================== 内部 ====================

    /**
     * 校验并整理输入字段
     */
    private function validate(array $data): ?array
    {
        $title   = Sanitizer::text((string)($data['title'] ?? ''), 100);
        $surface = Sanitizer::text((string)($data['surface'] ?? ''), self::MAX_SURFACE);
        $truth   = Sanitizer::text((string)($data['truth'] ?? ''), self::MAX_TRUTH);
        if ($surface === '' || $truth === '') return null;
        if ($title === '') $title = mb_substr($surface, 0, 20);

        // key_points / hints：数组裁剪
        $keyPoints = [];
        foreach ((array)($data['key_points'] ?? []) as $kp) {
            $kp = Sanitizer::text((string)$kp, 100);
            if ($kp !== '') $keyPoints[] = $kp;
        }
        $hints = [];
        foreach ((array)($data['hints'] ?? []) as $h) {
            $h = Sanitizer::text((string)$h, self::MAX_HINT);
            if ($h !== '') $hints[] = $h;
        }

        $tags = Sanitizer::text((string)($data['tags'] ?? ''), 255);
        $difficulty = max(1, min(3, (int)($data['difficulty'] ?? 1)));
        $isPublic = !empty($data['is_public']);
        $scope = in_array($data['scope'] ?? '', ['private', 'public'], true)
            ? $data['scope']
            : ($isPublic ? 'public' : 'private');

        return [
            'title'      => $title,
            'surface'    => $surface,
            'truth'      => $truth,
            'key_points' => $keyPoints,
            'hints'      => $hints,
            'difficulty' => $difficulty,
            'tags'       => $tags,
            'scope'      => $scope,
        ];
    }

    /**
     * 公开视图裁剪：隐藏汤底与判定关键点
     */
    private function toPublicView(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'source'     => (string)$row['source'],
            'scope'      => (string)$row['scope'],
            'title'      => (string)$row['title'],
            'surface'    => (string)$row['surface'],
            'difficulty' => (int)$row['difficulty'],
            'tags'       => (string)$row['tags'],
            'used_count' => (int)$row['used_count'],
        ];
    }
}