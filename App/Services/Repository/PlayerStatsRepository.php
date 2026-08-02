<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;
use PDO;

/**
 * 玩家数据存储（MySQL，表名 player_data）
 *
 * 每个玩法独立一个序列化列，方便扩展：
 * - turing_test   TEXT  图灵测试战绩（PHP 序列化数组）
 * - WhoisAI    TEXT  人类 vs AI 战绩（PHP 序列化数组）
 *
 * 玩家身份由 12 位恢复码（4 组 3 位单词，如 cat-dog-sun-sky）标识。
 */
class PlayerStatsRepository
{
    private static bool $initialized = false;

    // 恢复码单词池
    private const WORD_POOL = [
        'ace', 'air', 'ape', 'arc', 'art', 'ash', 'ate', 'bad', 'bag', 'bat',
        'bed', 'bet', 'bit', 'box', 'bud', 'bug', 'bus', 'cab', 'cam', 'can',
        'cap', 'cat', 'cog', 'cop', 'cow', 'cry', 'cub', 'cue', 'cup', 'cut',
        'dad', 'dam', 'day', 'den', 'dew', 'did', 'dig', 'dim', 'dip', 'dog',
        'dot', 'dry', 'dug', 'duo', 'ear', 'eat', 'egg', 'ego', 'elf', 'elm',
        'emu', 'end', 'era', 'eve', 'eye', 'fan', 'far', 'fat', 'fax', 'fee',
        'few', 'fig', 'fin', 'fir', 'fit', 'fix', 'fly', 'foe', 'fog', 'for',
        'fox', 'fun', 'fur', 'gag', 'gap', 'gel', 'gem', 'get', 'gin', 'gnu',
        'got', 'gum', 'gun', 'gut', 'guy', 'gym', 'had', 'ham', 'has', 'hat',
        'hay', 'hen', 'hew', 'hid', 'him', 'hip', 'his', 'hit', 'hog', 'hop',
        'hot', 'how', 'hub', 'hue', 'hug', 'hut', 'ice', 'icy', 'ill', 'imp',
        'ink', 'inn', 'ion', 'ire', 'irk', 'its', 'ivy', 'jab', 'jag', 'jam',
        'jar', 'jaw', 'jay', 'jet', 'jig', 'job', 'jog', 'jot', 'joy', 'jug',
        'jut', 'keg', 'ken', 'key', 'kid', 'kin', 'kit', 'lab', 'lad', 'lag',
        'lap', 'law', 'lax', 'lay', 'lea', 'led', 'leg', 'let', 'lid', 'lie',
        'lip', 'lit', 'log', 'lot', 'low', 'lug', 'mad', 'man', 'map', 'mar',
        'mat', 'maw', 'may', 'men', 'met', 'mid', 'mix', 'mob', 'mod', 'mom',
        'mop', 'mow', 'mud', 'mug', 'mum', 'nab', 'nag', 'nap', 'net', 'new',
        'nil', 'nip', 'nit', 'nod', 'nor', 'not', 'now', 'nun', 'nut', 'oak',
        'oar', 'oat', 'odd', 'ode', 'off', 'oft', 'oil', 'old', 'one', 'opt',
        'orb', 'ore', 'our', 'out', 'ova', 'owe', 'owl', 'own', 'pad', 'pal',
        'pan', 'pap', 'par', 'pat', 'paw', 'pay', 'pea', 'peg', 'pen', 'pep',
        'per', 'pet', 'pie', 'pig', 'pin', 'pit', 'ply', 'pod', 'pop', 'pot',
        'pow', 'pry', 'pub', 'pug', 'pun', 'pup', 'put', 'rag', 'ram', 'ran',
        'rap', 'rat', 'raw', 'ray', 'red', 'ref', 'rib', 'rid', 'rig', 'rim',
        'rip', 'rob', 'rod', 'roe', 'rot', 'row', 'rub', 'rug', 'rum', 'run',
        'rut', 'rye', 'sad', 'sag', 'sap', 'sat', 'saw', 'say', 'sea', 'set',
        'sew', 'she', 'shy', 'sin', 'sip', 'sir', 'sis', 'sit', 'six', 'ski',
        'sky', 'sly', 'sob', 'sod', 'son', 'sop', 'sot', 'sow', 'soy', 'spa',
        'spy', 'sub', 'sue', 'sum', 'sun', 'tab', 'tad', 'tag', 'tan', 'tap',
        'tar', 'tat', 'tax', 'tea', 'ted', 'tee', 'ten', 'the', 'thy', 'tic',
        'tie', 'tin', 'tip', 'toe', 'ton', 'too', 'top', 'tot', 'tow', 'toy',
        'try', 'tub', 'tug', 'two', 'urn', 'use', 'van', 'vat', 'vet', 'vex',
        'via', 'vie', 'vim', 'vow', 'war', 'was', 'wax', 'way', 'web', 'wet',
        'who', 'why', 'wig', 'win', 'wit', 'woe', 'wok', 'won', 'woo', 'wow',
        'yak', 'yam', 'yap', 'yaw', 'yea', 'yes', 'yet', 'yew', 'you', 'zap',
        'zed', 'zen', 'zig', 'zip', 'zoo',
        // A
        'ado', 'aft', 'aid', 'ail', 'aim', 'ala', 'alb', 'ale', 'all', 'alp',
        'als', 'alt', 'ama', 'ami', 'amp', 'amu', 'ana', 'and', 'ane', 'ani',
        'ant', 'any', 'ape', 'apt', 'arb', 'arc', 'are', 'ark', 'arm', 'ars',
        'art', 'ary', 'ash', 'ask', 'asp', 'ass', 'ate', 'att', 'auk', 'ava',
        'ave', 'awe', 'awl', 'awn', 'axe', 'aye', 'ays',
        // B
        'baa', 'bad', 'bag', 'bah', 'bam', 'ban', 'bap', 'bar', 'bat', 'bay',
        'bed', 'bee', 'beg', 'bel', 'ben', 'bet', 'bib', 'bid', 'big', 'bin',
        'bio', 'bit', 'biz', 'boa', 'bob', 'bod', 'bog', 'bok', 'bop', 'bot',
        'bow', 'box', 'boy', 'bra', 'bro', 'bub', 'bud', 'bug', 'bum', 'bun',
        'bup', 'bur', 'bus', 'but', 'buy', 'bye',
        // C
        'cab', 'cad', 'cam', 'can', 'cap', 'car', 'cat', 'caw', 'cay', 'cep',
        'cha', 'che', 'chi', 'cis', 'cob', 'cod', 'cog', 'col', 'con', 'coo',
        'cop', 'cor', 'cos', 'cot', 'cow', 'cox', 'coy', 'coz', 'cry', 'cub',
        'cud', 'cue', 'cum', 'cup', 'cur', 'cut', 'cuz',
        // D
        'dad', 'dag', 'dah', 'dak', 'dal', 'dam', 'dan', 'dap', 'dar', 'daw',
        'day', 'deb', 'dee', 'del', 'den', 'dev', 'dew', 'dex', 'dey', 'dib',
        'did', 'die', 'dig', 'dim', 'din', 'dip', 'dis', 'dit', 'doc', 'doe',
        'dog', 'doh', 'dol', 'dom', 'don', 'dor', 'dos', 'dot', 'dow', 'dry',
        'dub', 'dud', 'due', 'dug', 'duh', 'dun', 'duo', 'dup', 'dux',
        // E
        'ear', 'eat', 'eau', 'ebb', 'ecu', 'edh', 'eel', 'eff', 'eft', 'egg',
        'ego', 'eke', 'eld', 'elf', 'elk', 'ell', 'elm', 'els', 'eme', 'emu',
        'end', 'eng', 'ens', 'eon', 'era', 'ere', 'erg', 'err', 'ers', 'ess',
        'eta', 'eth', 'eve', 'ewe', 'eye',
        // F
        'fad', 'fag', 'fah', 'fan', 'far', 'fas', 'fat', 'fay', 'fed', 'fee',
        'feh', 'fem', 'fen', 'fer', 'fet', 'feu', 'few', 'fey', 'fez', 'fib',
        'fid', 'fie', 'fig', 'fin', 'fir', 'fit', 'fix', 'fiz', 'flu', 'fly',
        'fob', 'foe', 'fog', 'foh', 'fon', 'foo', 'fop', 'for', 'fou', 'fox',
        'foy', 'fro', 'fry', 'fub', 'fud', 'fug', 'fun', 'fur',
        // G
        'gab', 'gad', 'gae', 'gag', 'gal', 'gam', 'gan', 'gap', 'gar', 'gas',
        'gat', 'gau', 'gay', 'ged', 'gee', 'gel', 'gem', 'gen', 'geo', 'ger',
        'get', 'gey', 'ghi', 'gib', 'gid', 'gie', 'gif', 'gig', 'gin', 'gip',
        'git', 'gnu', 'goa', 'gob', 'god', 'goo', 'gor', 'gos', 'got', 'gow',
        'gox', 'goy', 'gul', 'gum', 'gun', 'gut', 'guy', 'gym', 'gyp',
        // H
        'had', 'hae', 'hag', 'hah', 'haj', 'ham', 'hao', 'hap', 'has', 'hat',
        'haw', 'hay', 'heh', 'hem', 'hen', 'hep', 'her', 'het', 'hew', 'hex',
        'hey', 'hic', 'hid', 'hie', 'him', 'hin', 'hip', 'his', 'hit', 'hob',
        'hoc', 'hod', 'hoe', 'hog', 'hoh', 'hoi', 'hom', 'hoo', 'hop', 'hor',
        'hos', 'hot', 'how', 'hub', 'hue', 'hug', 'huh', 'hum', 'hun', 'hup',
        'hut', 'hye',
        // I
        'ice', 'ich', 'ick', 'icy', 'ide', 'ids', 'iff', 'ifs', 'igg', 'ilk',
        'ill', 'imp', 'ink', 'inn', 'ins', 'ion', 'ire', 'irk', 'ish', 'ism',
        'its', 'ivy',
        // J
        'jab', 'jag', 'jam', 'jar', 'jaw', 'jay', 'jee', 'jet', 'jeu', 'jew',
        'jib', 'jig', 'jin', 'jiz', 'job', 'joe', 'jog', 'jot', 'joy', 'jug',
        'jun', 'jus', 'jut',
        // K
        'kab', 'kae', 'kaf', 'kas', 'kat', 'kay', 'kea', 'keg', 'ken', 'kep',
        'kex', 'key', 'khi', 'kid', 'kif', 'kin', 'kip', 'kir', 'kit', 'koa',
        'kob', 'koi', 'kop', 'kor', 'kos', 'kow', 'kue', 'kwa',
        // L
        'lab', 'lac', 'lad', 'lag', 'lam', 'lap', 'lar', 'las', 'lat', 'lav',
        'law', 'lax', 'lay', 'lea', 'led', 'lee', 'leg', 'lei', 'lek', 'let',
        'leu', 'lev', 'lew', 'lex', 'ley', 'lib', 'lid', 'lie', 'lin', 'lip',
        'lis', 'lit', 'lob', 'log', 'loo', 'lop', 'lot', 'low', 'lox', 'lug',
        'lum', 'luv', 'lux', 'lye',
        // M
        'mac', 'mad', 'mae', 'mag', 'man', 'map', 'mar', 'mas', 'mat', 'maw',
        'max', 'may', 'med', 'mee', 'meg', 'mem', 'men', 'met', 'mew', 'mho',
        'mib', 'mic', 'mid', 'mig', 'mil', 'mim', 'mir', 'mis', 'mix', 'miz',
        'moa', 'mob', 'mod', 'mog', 'moi', 'mol', 'mom', 'mon', 'moo', 'mop',
        'mor', 'mos', 'mot', 'mow', 'mud', 'mug', 'mum', 'mun', 'mus', 'mut',
        'mux', 'myc',
        // N
        'nab', 'nad', 'nae', 'nag', 'nah', 'nam', 'nan', 'nap', 'naw', 'nay',
        'neb', 'ned', 'nee', 'neg', 'net', 'new', 'nib', 'nil', 'nim', 'nip',
        'nit', 'nix', 'nob', 'nod', 'nog', 'noh', 'nom', 'noo', 'nor', 'nos',
        'not', 'now', 'nth', 'nub', 'nun', 'nus', 'nut',
        // O
        'oaf', 'oak', 'oar', 'oat', 'oba', 'obe', 'obi', 'oca', 'odd', 'ode',
        'ods', 'oes', 'off', 'oft', 'ohm', 'oho', 'ohs', 'oil', 'oka', 'oke',
        'old', 'ole', 'olm', 'olt', 'oma', 'one', 'ono', 'ons', 'oof', 'ooh',
        'oot', 'ope', 'ops', 'opt', 'ora', 'orb', 'orc', 'ore', 'ort', 'ose',
        'our', 'out', 'ova', 'owe', 'owl', 'own', 'oxo',
        // P
        'pac', 'pad', 'pah', 'pal', 'pam', 'pan', 'pap', 'par', 'pas', 'pat',
        'paw', 'pax', 'pay', 'pea', 'pec', 'ped', 'pee', 'peg', 'peh', 'pen',
        'pep', 'per', 'pes', 'pet', 'pew', 'phi', 'pht', 'pia', 'pic', 'pie',
        'pig', 'pin', 'pip', 'pir', 'pis', 'pit', 'piu', 'ply', 'pod', 'poi',
        'pol', 'pom', 'pop', 'pot', 'pow', 'pox', 'pry', 'psi', 'pub', 'pug',
        'pul', 'pun', 'pup', 'pur', 'pus', 'put', 'pya', 'pye',
        // Q
        'qat', 'qis', 'qua',
        // R
        'rad', 'rag', 'rah', 'ram', 'ran', 'rap', 'ras', 'rat', 'raw', 'rax',
        'ray', 'reb', 'rec', 'red', 'ree', 'ref', 'reg', 'rei', 'rem', 'rep',
        'res', 'ret', 'rev', 'rew', 'rex', 'rho', 'rib', 'rid', 'rig', 'rim',
        'rin', 'rip', 'rob', 'roc', 'rod', 'roe', 'rot', 'row', 'rub', 'rug',
        'rum', 'run', 'rut', 'rya', 'rye',
        // S
        'sac', 'sad', 'sae', 'sag', 'sal', 'sam', 'san', 'sap', 'sar', 'sat',
        'saw', 'sax', 'say', 'sea', 'sec', 'sed', 'see', 'seg', 'sei', 'sen',
        'ser', 'set', 'sew', 'sex', 'sha', 'she', 'shy', 'sic', 'sin', 'sip',
        'sir', 'sis', 'sit', 'six', 'ski', 'sky', 'sly', 'sob', 'sod', 'son',
        'sop', 'sot', 'sow', 'soy', 'spa', 'spy', 'sub', 'sue', 'sum', 'sun',
        'sup', 'sus', 'syn',
        // T
        'tab', 'tad', 'tae', 'tag', 'taj', 'tam', 'tan', 'tao', 'tap', 'tar',
        'tas', 'tat', 'tau', 'tav', 'taw', 'tax', 'tay', 'tea', 'ted', 'tee',
        'teg', 'ten', 'tep', 'tet', 'tew', 'the', 'tho', 'thy', 'tic', 'tie',
        'til', 'tin', 'tip', 'tis', 'tit', 'toc', 'toe', 'tog', 'tom', 'ton',
        'too', 'top', 'tor', 'tot', 'tow', 'toy', 'try', 'tsk', 'tub', 'tug',
        'tui', 'tun', 'tup', 'tut', 'tux', 'two', 'tye',
        // U
        'ugh', 'uke', 'ulu', 'umm', 'ump', 'uns', 'upo', 'ups', 'urb', 'urd',
        'urn', 'urp', 'use', 'uta', 'uts',
        // V
        'vac', 'van', 'var', 'vas', 'vat', 'vau', 'vaw', 'vee', 'veg', 'vet',
        'vex', 'via', 'vic', 'vie', 'vim', 'vis', 'voe', 'vow', 'vox', 'vug',
        // W
        'wab', 'wad', 'wae', 'wag', 'wan', 'wap', 'war', 'was', 'wat', 'waw',
        'wax', 'way', 'web', 'wed', 'wee', 'wen', 'wet', 'wha', 'who', 'why',
        'wig', 'win', 'wit', 'wiz', 'woe', 'wok', 'won', 'woo', 'wop', 'wos',
        'wot', 'wow', 'wry', 'wud', 'wye',
        // X
        'xen', 'xis',
        // Y
        'yad', 'yah', 'yak', 'yam', 'yap', 'yar', 'yaw', 'yay', 'yea', 'yeh',
        'yen', 'yep', 'yes', 'yet', 'yew', 'yid', 'yin', 'yip', 'yis', 'yon',
        'you', 'yow', 'yuh',
        // Z
        'zap', 'zed', 'zee', 'zen', 'zig', 'zin', 'zip', 'zit', 'zoo', 'zuz',
    ];

    /**
     * 初始化玩家数据仓库
     */
    public static function initialize(): void
    {
        if (self::$initialized) return;
        $pdo = Database::connect();
        self::ensureTable($pdo);
        self::$initialized = true;
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_data (
            id VARCHAR(64) PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            discriminator INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT "",
            fp VARCHAR(64) NOT NULL DEFAULT "",
            turing_test TEXT,
            WhoisAI TEXT,
            sticker_favorites TEXT,
            messages TEXT,
            created_at INT NOT NULL DEFAULT 0,
            last_played_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 对手标签累计表
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_tags (
            code VARCHAR(32) NOT NULL,
            tag VARCHAR(50) NOT NULL,
            count INT NOT NULL DEFAULT 1,
            PRIMARY KEY (code, tag),
            INDEX idx_player_tags_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    // ================================================================
    //  序列化读写辅助
    // ================================================================

    /**
     * 获取某个玩法的空战绩结构
     */
    public static function getEmptyStats(string $gameMode): array
    {
        return match ($gameMode) {
            'turing_test' => [
                'wins' => 0, 'losses' => 0, 'timeouts' => 0,
                'guess_human' => 0, 'guess_ai' => 0,
                'opp_human' => 0, 'opp_ai' => 0,
                'total_msgs' => 0, 'total_duration' => 0, 'total_games' => 0,
                'guess_correct' => 0,
                'guess_ai_correct' => 0, 'guess_human_correct' => 0,
                'exposure_correct' => 0, 'exposure_total' => 0,
                'judge_duration_ms' => 0, 'judge_count' => 0,
                'active_hours' => [],
                'wins_by_hour' => [],
                'current_streak' => 0,
                'best_win_streak' => 0,
            ],
            'WhoisAI' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0,
                'active_hours' => [],
            ],
            default => [],
        };
    }

    private static function getGameStats(string $code, string $gameMode): array
    {
        $col = $gameMode === 'WhoisAI' ? 'WhoisAI' : 'turing_test';
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT {$col} FROM player_data WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $raw = $stmt->fetchColumn();
        if (empty($raw)) return self::getEmptyStats($gameMode);
        try {
            $data = @unserialize($raw);
            return is_array($data) ? $data : self::getEmptyStats($gameMode);
        } catch (\Throwable $e) {
            Logger::warning('PlayerStatsRepository: unserialize game stats failed', ['code' => $code, 'gameMode' => $gameMode, 'error' => $e->getMessage()]);
            return self::getEmptyStats($gameMode);
        }
    }

    private static function saveGameStats(string $code, string $gameMode, array $stats): void
    {
        $col = $gameMode === 'WhoisAI' ? 'WhoisAI' : 'turing_test';
        $pdo = Database::connect();
        $stmt = $pdo->prepare("UPDATE player_data SET {$col} = ? WHERE code = ?");
        $stmt->execute([serialize($stats), $code]);
    }

    // ================================================================
    //  恢复码
    // ================================================================

    /**
     * 生成恢复码（纯随机，4 组 3 字母单词，如 cat-dog-sun-sky）
     * 词库 1000+ 单词，1000^4 ≈ 1万亿 组合，碰撞概率可忽略。
     * 即使生成 1亿 个码，碰撞概率也仅为 1/10000
     * UNIQUE 索引兜底。
     */
    public static function generateCode(): string
    {
        $poolSize = count(self::WORD_POOL);
        $parts = [];
        for ($j = 0; $j < 4; $j++) {
            $parts[] = self::WORD_POOL[random_int(0, $poolSize - 1)];
        }
        return implode('-', $parts);
    }

    /**
     * 通过恢复码查找玩家
     */
    public static function findByCode(string $code): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM player_data WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 通过 IP + 指纹查找已有玩家（防止同设备重复生成恢复码）
     */
    public static function findByIpFingerprint(string $ip, string $fp): ?array
    {
        if (empty($ip) || empty($fp)) return null;
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM player_data WHERE ip = ? AND fp = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$ip, $fp]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 同步客户端 UserData 到服务端（设置页上传按钮触发）
     * 将本地 localStorage 的 camelCase 战绩映射到服务端 snake_case 格式
     */
    public static function syncUserData(string $code, string $nickname, string $ip, string $fp, array $localStats): bool
    {
        $player = self::findByCode($code);

        if (!$player) {
            // 新玩家：创建记录并写入战绩
            $id = bin2hex(random_bytes(16));
            $discriminator = random_int(1000, 9999);
            $now = time();

            $serverStats = self::mapLocalStatsToServer($localStats);

            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO player_data (id, code, nickname, discriminator, ip, fp, turing_test, sticker_favorites, created_at, last_played_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $id, $code, $nickname, $discriminator, $ip, $fp,
                serialize($serverStats),
                json_encode($localStats['stickerFavorites'] ?? [], JSON_UNESCAPED_UNICODE),
                $now, $now,
            ]);

            Logger::info('UserData synced (new player)', ['code' => $code]);
        } else {
            // 已有玩家：更新昵称/IP/指纹，合并战绩（取最大值）
            self::updateNickname($code, $nickname, $ip, $fp);

            $existing = self::getGameStats($code, 'turing_test');
            $incoming = self::mapLocalStatsToServer($localStats);
            $merged = self::mergeStats($existing, $incoming);

            self::saveGameStats($code, 'turing_test', $merged);

            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ?, sticker_favorites = ? WHERE code = ?');
            $stmt->execute([time(), json_encode($localStats['stickerFavorites'] ?? [], JSON_UNESCAPED_UNICODE), $code]);

            Logger::info('UserData synced (existing player)', ['code' => $code]);
        }

        return true;
    }

    /**
     * 将客户端 camelCase 战绩映射到服务端 snake_case 格式
     */
    private static function mapLocalStatsToServer(array $local): array
    {
        return [
            'total_games'    => max(0, (int)($local['total']        ?? 0)),
            'wins'           => max(0, (int)($local['wins']         ?? 0)),
            'losses'         => max(0, (int)($local['losses']       ?? 0)),
            'timeouts'       => max(0, (int)($local['timeouts']     ?? 0)),
            'guess_human'    => max(0, (int)($local['guessHuman']   ?? 0)),
            'guess_ai'       => max(0, (int)($local['guessAI']      ?? 0)),
            'opp_human'      => max(0, (int)($local['oppHuman']     ?? 0)),
            'opp_ai'         => max(0, (int)($local['oppAI']        ?? 0)),
            'total_msgs'     => max(0, (int)($local['totalMsgs']    ?? 0)),
            'total_duration' => max(0, (int)($local['totalDuration'] ?? 0)),
        ];
    }

    /**
     * 合并战绩：逐字段取最大值，避免覆盖丢失
     */
    private static function mergeStats(array $existing, array $incoming): array
    {
        $keys = ['total_games', 'wins', 'losses', 'timeouts',
                 'guess_human', 'guess_ai', 'opp_human', 'opp_ai',
                 'total_msgs', 'total_duration'];
        foreach ($keys as $key) {
            $existing[$key] = max((int)($existing[$key] ?? 0), (int)($incoming[$key] ?? 0));
        }
        return $existing;
    }

    /**
     * 全局昵称唯一性校验（跨所有活跃模式）
     * 大小写不敏感
     */
    public static function findByNickname(string $nickname): ?array
    {
        if (empty($nickname)) return null;
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT id, code, nickname, ip, fp FROM player_data WHERE LOWER(nickname) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([trim($nickname)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ================================================================
    //  玩家管理
    // ================================================================

    /**
     * 创建新玩家（首次上榜）
     */
    public static function createPlayer(string $code, string $nickname, string $ip, string $fp): string
    {
        $id = bin2hex(random_bytes(16));
        $discriminator = random_int(1000, 9999);
        $now = time();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_data (id, code, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $code, $nickname, $discriminator, $ip, $fp,
            serialize(self::getEmptyStats('turing_test')),
            $now, $now,
        ]);

        Logger::debug('Player created', [
            'id' => $id, 'code' => $code, 'nickname' => $nickname,
        ]);

        return $id;
    }

    /**
     * 更新玩家昵称（每次游戏时更新）
     */
    public static function updateNickname(string $code, string $nickname, string $ip, string $fp): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE player_data SET nickname = ?, ip = ?, fp = ? WHERE code = ?'
        );
        $stmt->execute([$nickname, $ip, $fp, $code]);
    }

    // ================================================================
    //  战绩记录
    // ================================================================

    /**
     * 记录一局图灵测试结果
     */
    public static function recordGame(array $params): void
    {
        $code = $params['code'];
        $player = self::findByCode($code);

        if (!$player) {
            Logger::warning('recordGame: code not found', ['code' => $code]);
            return;
        }

        self::updateNickname($code, $params['nickname'], $params['ip'], $params['fp']);

        $stats = self::getGameStats($code, 'turing_test');

        // 兼容旧数据，补齐新增字段默认值
        $stats += [
            'guess_correct' => 0,
            'guess_ai_correct' => 0, 'guess_human_correct' => 0,
            'exposure_correct' => 0, 'exposure_total' => 0,
            'judge_duration_ms' => 0, 'judge_count' => 0,
            'active_hours' => [],
            'wins_by_hour' => [],
            'current_streak' => 0,
            'best_win_streak' => 0,
        ];

        $timeoutReason = $params['timeout_reason'] ?? null;
        $userGuess     = $params['user_guess']     ?? null;
        $opponentTruth = $params['opponent_truth'] ?? null;
        $totalMsgs     = (int)($params['total_msgs'] ?? 0);
        $duration      = (int)($params['duration']   ?? 0);
        $wasExposed    = $params['was_exposed'] ?? null;       // 对手是否猜对了我
        $opponentGuess = $params['opponent_guess'] ?? null;
        $judgeDurationMs = (int)($params['judge_duration_ms'] ?? 0);

        $stats['total_games']++;

        $prevWins = $stats['wins'];

        if ($timeoutReason === 'opponent') {
            $stats['wins']++;
        } elseif ($timeoutReason === 'you') {
            $stats['losses']++;
            $stats['timeouts']++;
        } elseif ($timeoutReason !== 'both') {
            $condWin = ($userGuess === $opponentTruth);
            if ($condWin) {
                $stats['wins']++;
            } elseif ($userGuess !== null && $opponentTruth !== null) {
                $stats['losses']++;
            }
        }

        if ($userGuess === 'human') $stats['guess_human']++;
        if ($userGuess === 'ai')    $stats['guess_ai']++;
        if ($opponentTruth === 'human') $stats['opp_human']++;
        if ($opponentTruth === 'ai')    $stats['opp_ai']++;
        $stats['total_msgs']     += $totalMsgs;
        $stats['total_duration'] += $duration;

        if ($userGuess !== null && $opponentTruth !== null && $userGuess === $opponentTruth) {
            $stats['guess_correct']++;
            if ($userGuess === 'ai') $stats['guess_ai_correct']++;
            if ($userGuess === 'human') $stats['guess_human_correct']++;
        }
        if ($wasExposed !== null && $opponentGuess !== null) {
            $stats['exposure_total']++;
            if ($wasExposed) $stats['exposure_correct']++;
        }
        if ($judgeDurationMs > 0) {
            $stats['judge_duration_ms'] += $judgeDurationMs;
            $stats['judge_count']++;
        }
        // 活跃时段
        $hour = (int)date('G');
        $activeHours = $stats['active_hours'];
        $activeHours[$hour] = ($activeHours[$hour] ?? 0) + 1;
        $stats['active_hours'] = $activeHours;

        // 时段胜率
        $winsByHour = $stats['wins_by_hour'];
        if (!isset($winsByHour[$hour])) {
            $winsByHour[$hour] = ['games' => 0, 'wins' => 0];
        }
        $winsByHour[$hour]['games']++;
        if ($stats['wins'] > $prevWins) {
            $winsByHour[$hour]['wins']++;
        }
        $stats['wins_by_hour'] = $winsByHour;

        // 连胜/连败
        $gameWon = $stats['wins'] > $prevWins;
        $prevStreak = (int)$stats['current_streak'];
        if ($gameWon && $prevStreak > 0) {
            $stats['current_streak'] = $prevStreak + 1;
        } elseif (!$gameWon && $prevStreak < 0) {
            $stats['current_streak'] = $prevStreak - 1;
        } else {
            $stats['current_streak'] = $gameWon ? 1 : -1;
        }
        if ($gameWon) {
            $stats['best_win_streak'] = max((int)($stats['best_win_streak'] ?? 0), $stats['current_streak']);
        }

        self::saveGameStats($code, 'turing_test', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE code = ?');
        $stmt->execute([time(), $code]);

        Logger::debug('Game recorded', [
            'code' => $code, 'guess' => $userGuess,
            'truth' => $opponentTruth, 'timeout' => $timeoutReason,
        ]);
    }

    /**
     * 记录一局谁是AI结果
     */
    public static function recordWhoisAIGame(string $code, bool $win, int $activeHour = 0): void
    {
        $player = self::findByCode($code);
        if (!$player) return;

        $stats = self::getGameStats($code, 'WhoisAI');
        $stats['total_games']++;
        if ($win) $stats['wins']++;
        else $stats['losses']++;

        if ($activeHour > 0) {
            $h = (int)$activeHour;
            $stats['active_hours'][$h] = ($stats['active_hours'][$h] ?? 0) + 1;
        }

        self::saveGameStats($code, 'WhoisAI', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE code = ?');
        $stmt->execute([time(), $code]);
    }

    /**
     * recordGame 的直接执行版本（由 AsyncDbWriter 异步调用）
     */
    public static function recordGameDirect(array $params): void
    {
        self::recordGame($params);
    }

    /**
     * 记录对手标签（由 AsyncDbWriter 异步调用）
     * 使用 INSERT ... ON DUPLICATE KEY UPDATE 原子累加
     */
    public static function recordTag(string $code, string $tag): void
    {
        if (empty($code) || empty($tag)) return;
        $tag = mb_substr($tag, 0, 50);
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_tags (code, tag, count) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$code, $tag]);
    }

    /**
     * 获取玩家标签统计（按出现次数降序）
     * @return array<int, array{tag: string, count: int}>
     */
    public static function getPlayerTags(string $code): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT tag, count FROM player_tags WHERE code = ? ORDER BY count DESC LIMIT 20'
        );
        $stmt->execute([$code]);
        return $stmt->fetchAll();
    }

    // ================================================================
    //  玩家统计
    // ================================================================

    /**
     * 获取单个玩家的完整统计（前端恢复显示用，不暴露 id/ip/fp）
     */
    public static function getPlayerStats(string $code): ?array
    {
        $player = self::findByCode($code);
        if (!$player) return null;

        $turingStats = self::getGameStats($code, 'turing_test');
        $WhoisAIStats = self::getGameStats($code, 'WhoisAI');
        $allGames = $turingStats['total_games'] + $WhoisAIStats['total_games'];
        $allWins  = $turingStats['wins'] + $WhoisAIStats['wins'];

        $result = [
            'code'          => $player['code'],
            'nickname'      => $player['nickname'],
            'discriminator' => (int)$player['discriminator'],
            'created_at'    => (int)$player['created_at'],
            'last_played_at' => (int)$player['last_played_at'],
            'turing_test'    => $turingStats,
            'WhoisAI'    => $WhoisAIStats,
            'total_games'    => $allGames,
            'win_rate'       => $allGames > 0 ? round(($allWins / $allGames) * 100) : 0,
            'avg_msgs'       => $turingStats['total_games'] > 0
                ? round($turingStats['total_msgs'] / $turingStats['total_games']) : 0,
        ];

        return $result;
    }

    /**
     * 获取玩家公开身份档案
     * 通过昵称查找，不暴露恢复码。
     */
    public static function getPlayerProfileByNickname(string $nickname): ?array
    {
        $player = self::findByNickname($nickname);
        if (!$player) return null;

        $code = $player['code'];
        $turing = self::getGameStats($code, 'turing_test');
        $WhoisAI = self::getGameStats($code, 'WhoisAI');
        $allGames = $turing['total_games'] + $WhoisAI['total_games'];
        $allWins  = $turing['wins'] + $WhoisAI['wins'];

        $tg = (int)($turing['total_games'] ?? 0);

        // ── 风格画像 ──
        $profile = [
            'nickname'       => $player['nickname'],
            'total_games'    => $allGames,
            'turing_games'   => $turing['total_games'],
            'whoisai_games'  => $WhoisAI['total_games'],
            'win_rate'       => $allGames > 0 ? round(($allWins / $allGames) * 100) : 0,
            'guess_accuracy' => $tg > 0
                ? round(((int)($turing['guess_correct'] ?? 0) / $tg) * 100) : 0,
            'ai_win_rate'    => 0,
            'human_win_rate' => 0,
            'exposure_rate'  => 0,
            'avg_msgs'       => $tg > 0 ? round((int)($turing['total_msgs'] ?? 0) / $tg) : 0,
            'avg_judge_seconds' => 0,
            'peak_hours'     => [],
            'tags'           => [],
            'title'          => '',
        ];

        $ec = (int)($turing['exposure_correct'] ?? 0);
        $et = (int)($turing['exposure_total'] ?? 0);
        if ($et > 0) {
            $profile['exposure_rate'] = (int)round(($ec / $et) * 100);
        }

        $oppAi = (int)($turing['opp_ai'] ?? 0);
        $oppHuman = (int)($turing['opp_human'] ?? 0);
        if ($oppAi > 0) {
            $profile['ai_win_rate'] = (int)round(((int)($turing['guess_ai_correct'] ?? 0) / $oppAi) * 100);
        }
        if ($oppHuman > 0) {
            $profile['human_win_rate'] = (int)round(((int)($turing['guess_human_correct'] ?? 0) / $oppHuman) * 100);
        }

        $jc = (int)($turing['judge_count'] ?? 0);
        if ($jc > 0) {
            $profile['avg_judge_seconds'] = (int)round(($turing['judge_duration_ms'] ?? 0) / $jc / 1000);
        }

        if ($WhoisAI['total_games'] > 0) {
            $profile['whoisai_win_rate'] = (int)round(($WhoisAI['wins'] / $WhoisAI['total_games']) * 100);
        } else {
            $profile['whoisai_win_rate'] = 0;
        }

        // 最佳时段（胜率最高，至少3局）
        $bestHour = null;
        $bestHourRate = 0;
        $byHour = $turing['wins_by_hour'] ?? [];
        foreach ($byHour as $h => $d) {
            if ($d['games'] >= 3 && $d['wins'] / $d['games'] > $bestHourRate) {
                $bestHourRate = $d['wins'] / $d['games'];
                $bestHour = (int)$h;
            }
        }
        $profile['best_hour'] = $bestHour;
        $profile['best_hour_rate'] = $bestHour !== null ? (int)round($bestHourRate * 100) : 0;

        $profile['current_streak'] = (int)($turing['current_streak'] ?? 0);
        $profile['best_win_streak'] = (int)($turing['best_win_streak'] ?? 0);

        // 活跃时段（合并图灵测试 + WhoisAI）
        $activeHours = $turing['active_hours'] ?? [];
        $whoisaiHours = $WhoisAI['active_hours'] ?? [];
        if (!empty($whoisaiHours)) {
            foreach ($whoisaiHours as $h => $c) {
                $activeHours[$h] = ($activeHours[$h] ?? 0) + $c;
            }
        }
        if (!empty($activeHours)) {
            arsort($activeHours);
            $profile['peak_hours'] = array_map('intval', array_slice(array_keys($activeHours), 0, 3));
        }

        $tags = self::getPlayerTags($code);
        $profile['tags'] = $tags;
        $profile['title'] = !empty($tags) ? $tags[0]['tag'] : '';

        // 留言墙
        $msgData = self::getMessageData($code);
        $profile['messages'] = self::visibleMessages($msgData);
        $profile['allow_messages'] = $msgData['allow_messages'];

        return $profile;
    }

    // ================================================================
    //  对手留言墙
    //  数据格式: ['messages' => [...], 'allow_messages' => bool]
    //  每条留言: ['id' => string, 'from' => string, 'text' => string, 'created_at' => int, 'hidden' => bool]
    // ================================================================

    /**
     * 获取玩家留言数据（含隐藏状态，仅用于本人管理）
     */
    public static function getMessageDataForOwner(string $code): array
    {
        return self::getMessageData($code);
    }

    private static function getMessageData(string $code): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT messages FROM player_data WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $raw = $stmt->fetchColumn();
        if (empty($raw)) {
            return ['messages' => [], 'allow_messages' => true];
        }
        try {
            $data = @unserialize($raw);
            if (!is_array($data)) return ['messages' => [], 'allow_messages' => true];
            return [
                'messages' => $data['messages'] ?? [],
                'allow_messages' => $data['allow_messages'] ?? true,
            ];
        } catch (\Throwable $e) {
            Logger::warning('PlayerStatsRepository: unserialize message data failed', ['code' => $code, 'error' => $e->getMessage()]);
            return ['messages' => [], 'allow_messages' => true];
        }
    }

    private static function saveMessageData(string $code, array $data): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET messages = ? WHERE code = ?');
        $stmt->execute([serialize($data), $code]);
    }

    /**
     * 提取可见留言（排除 hidden=true）
     */
    private static function visibleMessages(array $msgData): array
    {
        $visible = [];
        foreach ($msgData['messages'] as $msg) {
            if (empty($msg['hidden'])) {
                $visible[] = $msg;
            }
        }
        return array_slice($visible, 0, 20);
    }

    /**
     * 给玩家留言
     * @return array{success: bool, message: string}
     */
    public static function leaveMessage(string $targetCode, string $fromNickname, string $text, bool $checkPermission = true): array
    {
        $player = self::findByCode($targetCode);
        if (!$player) {
            return ['success' => false, 'message' => '目标玩家不存在'];
        }

        $msgData = self::getMessageData($targetCode);
        if ($checkPermission && empty($msgData['allow_messages'])) {
            return ['success' => false, 'message' => '该玩家已关闭留言功能'];
        }

        if (empty($msgData['allow_messages'])) {
            return ['success' => true, 'message' => '留言已保存'];
        }

        $text = mb_substr(trim($text), 0, 20);
        if (empty($text)) {
            return ['success' => false, 'message' => '留言内容不能为空'];
        }

        $sender = self::findByNickname($fromNickname);
        if (!$sender) {
            return ['success' => false, 'message' => '发送者不存在'];
        }

        $msgData['messages'][] = [
            'id' => $sender['id'],
            'from' => mb_substr($fromNickname, 0, 16),
            'text' => $text,
            'created_at' => time(),
            'hidden' => false,
        ];

        self::saveMessageData($targetCode, $msgData);
        return ['success' => true, 'message' => '留言已保存'];
    }

    /**
     * 隐藏/显示某条留言
     */
    public static function hideMessage(string $code, string $messageId, bool $hidden): array
    {
        $msgData = self::getMessageData($code);
        $found = false;
        foreach ($msgData['messages'] as &$msg) {
            if (($msg['id'] ?? '') === $messageId) {
                $msg['hidden'] = $hidden;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['success' => false, 'message' => '留言不存在'];
        }

        self::saveMessageData($code, $msgData);
        return ['success' => true, 'message' => $hidden ? '留言已隐藏' : '留言已显示'];
    }

    /**
     * 更新留言设置（是否接收留言）
     */
    public static function updateMessageSettings(string $code, bool $allow): void
    {
        $msgData = self::getMessageData($code);
        $msgData['allow_messages'] = $allow;
        self::saveMessageData($code, $msgData);
    }
}
