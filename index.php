<?php
declare(strict_types=1);

/**
 * Domino Tree — Self-contained Single-File PHP Multiplayer Game
 * Features:
 * - 5 Tournament Rule Engines (All Fives, Block, Draw, All Threes, Fives & Threes)
 * - Autonomous CPU Bots Integration
 * - Strict Tournament Spinner Branch Closure & Fully Verified Bindings
 * - Live Text Chat & Voice/Video Controls (WebRTC P2P + volume/activity highlights)
 * - Auto-advancing rounds with background continuity & manual dismiss popups for all players
 * - Strict Geometric Spatial Engine for deterministic layouts & corner turning
 * - Unified Tile Shadows and updated HUD
 * - Proportional hand scaling with fluid layout (Settings slider)
 * - Board tile size slider to close visual gaps between dominoes
 * - Player card info aligned to top, with expanded size slider for video views
 * - Admin panel: change game name, set default user settings, reset all game rooms, delete all tables (password: adminpass5)
 * - User & admin toggle to highlight the last played domino on the board
 * - 12 Domino Themes & 8 Board Colors in user settings
 * - Auto-clear inactive tables after admin‑set timeout (minutes)
 * - Private table toggle: hidden from public list, join only by code
 * - Admin password change (hashed storage, backward compatible)
 * - Improved table list: fixed‑height scrollable container, filter by name, show only open tables, sort by name/players/created
 * - Admin setting for default room list height
 * - Rules help button: shows scrollable popup with current game rules
 */

session_start();
header_remove('X-Powered-By');

define('BUILD_VERSION', '1.11.15_' . dechex((int)filemtime(__FILE__)) . '_' . substr(md5_file(__FILE__) ?: '', 0, 6));

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

const DB_FILE = __DIR__ . '/domino.sqlite';
const MAX_PLAYERS = 4;
const MIN_PLAYERS = 2;

function db(): SQLite3 {
    static $db = null;
    if ($db instanceof SQLite3) return $db;
    
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        target INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT "lobby",
        state TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    // Add private column if missing
    $res = $db->query("PRAGMA table_info(games)");
    $hasPrivate = false;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'private') {
            $hasPrivate = true;
            break;
        }
    }
    if (!$hasPrivate) {
        $db->exec('ALTER TABLE games ADD COLUMN private INTEGER DEFAULT 0');
    }

    $db->exec('CREATE TABLE IF NOT EXISTS chat (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_code TEXT NOT NULL,
        player_id TEXT NOT NULL,
        nickname TEXT NOT NULL,
        message TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS signals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_code TEXT NOT NULL,
        from_player TEXT NOT NULL,
        to_player TEXT NOT NULL,
        data TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    // Admin settings table
    $db->exec('CREATE TABLE IF NOT EXISTS admin_settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )');
    $defaults = [
        'game_name' => 'Domino Tree',
        'hand_scale_default' => '100',
        'board_tile_scale_default' => '100',
        'expanded_card_height_default' => '112',
        'auto_play_default' => '1',
        'highlight_last_tile_default' => '1',
        'auto_pan_last_tile_default' => '1',
        'inactivity_timeout_minutes' => '0',
        'room_list_height_default' => '320',
        'admin_password_hash' => ''
    ];
    foreach ($defaults as $key => $val) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO admin_settings (key, value) VALUES (:key, :val)');
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':val', $val);
        $stmt->execute();
    }
    return $db;
}

function getAdminSettings(): array {
    $result = db()->query('SELECT key, value FROM admin_settings');
    $settings = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function getAdminPasswordHash(): ?string {
    $settings = getAdminSettings();
    $hash = $settings['admin_password_hash'] ?? '';
    return $hash === '' ? null : $hash;
}

function verifyAdminPassword(string $input): bool {
    $hash = getAdminPasswordHash();
    if ($hash !== null) {
        return password_verify($input, $hash);
    }
    $valid = $input === 'adminpass5';
    if ($valid) {
        $newHash = password_hash('adminpass5', PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE admin_settings SET value = :hash WHERE key = "admin_password_hash"');
        $stmt->bindValue(':hash', $newHash);
        $stmt->execute();
    }
    return $valid;
}

function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $data['buildVersion'] = BUILD_VERSION;
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanNick(string $s): string {
    $s = trim(preg_replace('/[^\p{L}\p{N} _.-]/u', '', $s) ?? '');
    return mb_substr($s, 0, 24) ?: 'Player';
}

function cleanGameName(string $s): string {
    $s = trim(preg_replace('/[^\p{L}\p{N} _.,!?\'"()-]/u', '', $s) ?? '');
    return mb_substr($s, 0, 50) ?: 'Domino Table';
}

function ensurePlayerToken(): string {
    if (empty($_SESSION['domino_player_id'])) {
        $_SESSION['domino_player_id'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['domino_player_id'];
}

function generateGameCode(): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $stmt = db()->prepare('SELECT id FROM games WHERE code=:c');
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $exists = $stmt->execute()->fetchArray();
    } while ($exists);
    return $code;
}

function allTiles(): array {
    $tiles = [];
    $id = 0;
    for ($a = 0; $a <= 6; $a++) {
        for ($b = $a; $b <= 6; $b++) {
            $tiles[] = ['id' => 't' . $id++, 'a' => $a, 'b' => $b];
        }
    }
    return $tiles;
}

function tileMap(): array {
    $m = [];
    foreach (allTiles() as $t) $m[$t['id']] = $t;
    return $m;
}

function pips(array $tile): int {
    return $tile['a'] + $tile['b'];
}

function tileLabel(array $tile): string {
    return $tile['a'] . '|' . $tile['b'];
}

function formatRulesetName(string $r): string {
    return [
        'all_fives' => 'All Fives',
        'block' => 'Block',
        'draw' => 'Draw',
        'all_threes' => 'All Threes',
        'fives_and_threes' => 'Fives & Threes'
    ][$r] ?? 'All Fives';
}

function findGame(string $code): ?array {
    $stmt = db()->prepare('SELECT * FROM games WHERE code=:c LIMIT 1');
    $stmt->bindValue(':c', strtoupper(trim($code)), SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $row['state'] = json_decode($row['state'], true) ?: [];
    return $row;
}

function saveGame(array $game): void {
    $stmt = db()->prepare('UPDATE games SET status=:s,state=:state,updated_at=:u WHERE id=:id');
    $stmt->bindValue(':s', $game['status'], SQLITE3_TEXT);
    $stmt->bindValue(':state', json_encode($game['state'], JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $stmt->bindValue(':u', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':id', $game['id'], SQLITE3_INTEGER);
    $stmt->execute();
}

function deleteGame(string $code): void {
    $db = db();
    $db->exec('BEGIN TRANSACTION');
    try {
        $stmt = $db->prepare('DELETE FROM signals WHERE game_code = :c');
        $stmt->bindValue(':c', $code);
        $stmt->execute();
        $stmt = $db->prepare('DELETE FROM chat WHERE game_code = :c');
        $stmt->bindValue(':c', $code);
        $stmt->execute();
        $stmt = $db->prepare('DELETE FROM games WHERE code = :c');
        $stmt->bindValue(':c', $code);
        $stmt->execute();
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
    }
}

function emptyBoard(): array {
    return ['tiles' => [], 'endpoints' => []];
}

function sortHand(array &$hand): void {
    $map = tileMap();
    usort($hand, function($x, $y) use ($map) {
        $a = $map[$x] ?? null; $b = $map[$y] ?? null;
        if (!$a || !$b) return 0;
        $pa = pips($a); $pb = pips($b);
        return $pa <=> $pb ?: ($a['a'] <=> $b['a']) ?: ($a['b'] <=> $b['b']);
    });
}

function handPips(array $hand): int {
    $map = tileMap();
    $sum = 0;
    foreach ($hand as $id) {
        if (isset($map[$id])) $sum += pips($map[$id]);
    }
    return $sum;
}

function canPlayAny(array $hand, array $board): bool {
    $map = tileMap();
    if (empty($board['tiles'])) return true;
    foreach ($hand as $id) {
        if (!isset($map[$id])) continue;
        $t = $map[$id];
        foreach ($board['endpoints'] as $ep) {
            if ($t['a'] === $ep['value'] || $t['b'] === $ep['value']) return true;
        }
    }
    return false;
}

function calculateBoardSum(array $board): int {
    $sum = 0;
    foreach ($board['endpoints'] as $ep) {
        $sum += (int)$ep['value'];
    }
    return $sum;
}

function calculateMoveScore(int $boardSum, string $ruleset): int {
    if ($boardSum <= 0) return 0;
    if ($ruleset === 'all_fives') {
        return ($boardSum % 5 === 0) ? $boardSum : 0;
    }
    if ($ruleset === 'all_threes') {
        return ($boardSum % 3 === 0) ? $boardSum : 0;
    }
    if ($ruleset === 'fives_and_threes') {
        $pts = 0;
        if ($boardSum % 5 === 0) $pts += (int)($boardSum / 5);
        if ($boardSum % 3 === 0) $pts += (int)($boardSum / 3);
        return $pts;
    }
    return 0;
}

function checkCollision(int $cx, int $cy, int $w, int $h, array $boardTiles): bool {
    $padding = -2;
    foreach ($boardTiles as $t) {
        if (abs($t['x'] - $cx) < ($t['w'] + $w) / 2 + $padding &&
            abs($t['y'] - $cy) < ($t['h'] + $h) / 2 + $padding) {
            return true;
        }
    }
    return false;
}

function simulateScore(array $board, string $tileId, string $endpointId, string $ruleset): int {
    $tempState = ['board' => $board];
    try {
        if ($endpointId === '') {
            addOpeningTile($tempState, $tileId);
        } else {
            playOnEndpoint($tempState, $tileId, $endpointId);
        }
        $sum = calculateBoardSum($tempState['board']);
        return calculateMoveScore($sum, $ruleset);
    } catch (Exception $e) {
        return -1;
    }
}

function publicState(array $game, string $token): array {
    $s = $game['state'];
    $players = [];
    foreach ($s['players'] as $p) {
        $players[] = [
            'id' => $p['id'],
            'nickname' => $p['nickname'],
            'score' => $p['score'],
            'count' => count($p['hand']),
            'isMe' => $p['id'] === $token,
            'isCpu' => $p['isCpu'] ?? false,
            'connected' => ($p['isCpu'] ?? false) || (time() - ($p['lastSeen'] ?? 0) < 15),
        ];
    }

    $myHandIds = [];
    foreach ($s['players'] as $p) {
        if ($p['id'] === $token) {
            $myHandIds = $p['hand'];
            break;
        }
    }

    $map = tileMap();
    $myHand = [];
    foreach ($myHandIds as $id) {
        if (isset($map[$id])) $myHand[] = $map[$id];
    }

    $chatStmt = db()->prepare('SELECT id, player_id, nickname, message, created_at FROM chat WHERE game_code=:c ORDER BY id ASC LIMIT 50');
    $chatStmt->bindValue(':c', $game['code'], SQLITE3_TEXT);
    $chatRes = $chatStmt->execute();
    $chatMessages = [];
    while ($cr = $chatRes->fetchArray(SQLITE3_ASSOC)) {
        $chatMessages[] = [
            'id' => (int)$cr['id'],
            'nickname' => $cr['nickname'],
            'message' => $cr['message'],
            'isMe' => $cr['player_id'] === $token,
            'time' => date('H:i', (int)$cr['created_at'])
        ];
    }

    $signals = [];
    $signalStmt = db()->prepare('SELECT from_player, data FROM signals WHERE game_code=:c AND to_player=:t ORDER BY id ASC');
    $signalStmt->bindValue(':c', $game['code'], SQLITE3_TEXT);
    $signalStmt->bindValue(':t', $token, SQLITE3_TEXT);
    $sigRes = $signalStmt->execute();
    while ($row = $sigRes->fetchArray(SQLITE3_ASSOC)) {
        $signals[] = [
            'from' => $row['from_player'],
            'data' => $row['data']
        ];
    }
    if (!empty($signals)) {
        $delStmt = db()->prepare('DELETE FROM signals WHERE game_code=:c AND to_player=:t');
        $delStmt->bindValue(':c', $game['code'], SQLITE3_TEXT);
        $delStmt->bindValue(':t', $token, SQLITE3_TEXT);
        $delStmt->execute();
    }

    return [
        'code' => $game['code'],
        'name' => $game['name'],
        'target' => (int)$game['target'],
        'status' => $game['status'],
        'ruleset' => $s['ruleset'] ?? 'all_fives',
        'rulesetName' => formatRulesetName($s['ruleset'] ?? 'all_fives'),
        'round' => $s['round'],
        'turnIndex' => $s['turnIndex'],
        'turnPlayerId' => $s['players'][$s['turnIndex']]['id'] ?? null,
        'starterId' => $s['starterId'] ?? null,
        'requiredStartTile' => $s['requiredStartTile'] ?? null,
        'players' => $players,
        'maxPlayers' => MAX_PLAYERS,
        'myHand' => $myHand,
        'boneyardCount' => count($s['boneyard']),
        'board' => $s['board'],
        'chat' => $chatMessages,
        'message' => $s['message'] ?? '',
        'lastEvent' => $s['lastEvent'] ?? '',
        'winner' => $s['winner'] ?? null,
        'winnerScore' => $s['winnerScore'] ?? null,
        'lastRoundSummary' => $s['lastRoundSummary'] ?? null,
        'canAct' => ($s['players'][$s['turnIndex']]['id'] ?? null) === $token && $game['status'] === 'playing',
        'signals' => $signals,
        'isPrivate' => (bool)($game['private'] ?? 0),
    ];
}

function startRound(array &$game, ?int $preferredStarter): void {
    $s =& $game['state'];
    $deck = array_map(fn($t) => $t['id'], allTiles());
    shuffle($deck);

    foreach ($s['players'] as &$p) {
        $p['hand'] = [];
    }
    unset($p);

    $n = count($s['players']);
    $handSize = ($n === 4) ? 5 : 7;

    for ($r = 0; $r < $handSize; $r++) {
        for ($i = 0; $i < $n; $i++) {
            $s['players'][$i]['hand'][] = array_pop($deck);
        }
    }

    foreach ($s['players'] as &$p) {
        sortHand($p['hand']);
    }
    unset($p);

    $map = tileMap();
    $starter = null;
    $bestScore = -1;
    $bestIsDouble = false;

    if ($preferredStarter === null) {
        foreach ($s['players'] as $i => $p) {
            foreach ($p['hand'] as $id) {
                $t = $map[$id];
                if ($t['a'] === $t['b'] && $t['a'] > $bestScore) {
                    $bestScore = $t['a'];
                    $starter = $i;
                    $bestIsDouble = true;
                }
            }
        }
        if ($starter === null) {
            foreach ($s['players'] as $i => $p) {
                foreach ($p['hand'] as $id) {
                    $t = $map[$id];
                    if (pips($t) > $bestScore) {
                        $bestScore = pips($t);
                        $starter = $i;
                    }
                }
            }
        }
    } else {
        $starter = $preferredStarter;
    }

    $s['starterId'] = $s['players'][$starter]['id'];
    $s['turnIndex'] = $starter;
    $s['board'] = emptyBoard();
    $s['boneyard'] = array_values($deck);
    $s['requiredStartTile'] = null;
    $s['message'] = $s['players'][$starter]['nickname'] . ' starts the hand.';
    $s['lastEvent'] = $s['message'];

    if ($preferredStarter === null) {
        $required = null;
        if ($bestIsDouble) {
            foreach ($s['players'][$starter]['hand'] as $id) {
                $t = $map[$id];
                if ($t['a'] === $t['b'] && $t['a'] === $bestScore) {
                    $required = $id;
                    break;
                }
            }
        } else {
            foreach ($s['players'][$starter]['hand'] as $id) {
                if (pips($map[$id]) === $bestScore) {
                    $required = $id;
                    break;
                }
            }
        }
        $s['requiredStartTile'] = $required;
    }

    if ($s['players'][$starter]['isCpu'] ?? false) {
        $s['cpuNextActionAt'] = time() + 1;
    }
}

function addOpeningTile(array &$s, string $tileId): void {
    $map = tileMap();
    $t = $map[$tileId];
    $nodeId = 'n' . bin2hex(random_bytes(4));
    $isDouble = ($t['a'] === $t['b']);

    $w = 100; 
    $h = 52;
    $cx = 1500; 
    $cy = 1500;

    $s['board']['tiles'][] = [
        'nodeId' => $nodeId,
        'tile' => $t,
        'parentEndpoint' => null,
        'parentNodeId' => null,
        'x' => $cx,
        'y' => $cy,
        'w' => $w,
        'h' => $h,
        'dx' => 1,
        'dy' => 0,
        'orientation' => 0,
        'isDouble' => $isDouble
    ];

    if ($isDouble) {
        $dirs = [['dx'=>0, 'dy'=>-1], ['dx'=>0, 'dy'=>1], ['dx'=>-1, 'dy'=>0], ['dx'=>1, 'dy'=>0]];
        foreach ($dirs as $dir) {
            $s['board']['endpoints'][] = [
                'id' => 'e' . bin2hex(random_bytes(4)),
                'value' => $t['a'],
                'nodeId' => $nodeId,
                'branch' => true,
                'x' => $cx + $dir['dx'] * ($w / 2),
                'y' => $cy + $dir['dy'] * ($h / 2),
                'dx' => $dir['dx'],
                'dy' => $dir['dy']
            ];
        }
    } else {
        $s['board']['endpoints'][] = [
            'id' => 'e'.bin2hex(random_bytes(4)), 
            'value' => $t['a'], 
            'nodeId' => $nodeId, 
            'branch' => false, 
            'x' => $cx - ($w / 2), 
            'y' => $cy, 
            'dx' => -1, 
            'dy' => 0
        ];
        $s['board']['endpoints'][] = [
            'id' => 'e'.bin2hex(random_bytes(4)), 
            'value' => $t['b'], 
            'nodeId' => $nodeId, 
            'branch' => false, 
            'x' => $cx + ($w / 2), 
            'y' => $cy, 
            'dx' => 1, 
            'dy' => 0
        ];
    }
}

function playOnEndpoint(array &$s, string $tileId, string $endpointId): bool {
    $map = tileMap();
    if (!isset($map[$tileId])) return false;

    $ei = -1;
    foreach ($s['board']['endpoints'] as $i => $e) {
        if ($e['id'] === $endpointId) {
            $ei = $i;
            break;
        }
    }
    if ($ei < 0) return false;

    $ep = $s['board']['endpoints'][$ei];
    $t = $map[$tileId];

    if ($t['a'] !== $ep['value'] && $t['b'] !== $ep['value']) return false;

    $matchSide = ($t['a'] === $ep['value']) ? 'a' : 'b';
    $outer = ($matchSide === 'a') ? $t['b'] : $t['a'];
    $isDouble = ($t['a'] === $t['b']);

    $placed = false;
    $bestCx = 0; $bestCy = 0; $bestNdx = 0; $bestNdy = 0;
    $bestW = 0; $bestH = 0;

    $dirsToTry = [
        ['ndx' => $ep['dx'], 'ndy' => $ep['dy']],
        ['ndx' => -$ep['dy'], 'ndy' => $ep['dx']],
        ['ndx' => $ep['dy'], 'ndy' => -$ep['dx']]
    ];

    foreach ($dirsToTry as $dir) {
        $ndx = $dir['ndx']; 
        $ndy = $dir['ndy'];
        if ($ndx === 0 && $ndy === 0) continue;

        if ($isDouble) {
            $new_w = ($ndx != 0) ? 52 : 100;
            $new_h = ($ndy != 0) ? 52 : 100;
        } else {
            $new_w = ($ndx != 0) ? 100 : 52;
            $new_h = ($ndy != 0) ? 100 : 52;
        }

        $isCornering = ($ep['dx'] !== $ndx || $ep['dy'] !== $ndy);
        
        $cx = $ep['x'] + $ndx * ($new_w / 2) + ($isCornering ? $ep['dx'] * ($new_w / 2) : 0);
        $cy = $ep['y'] + $ndy * ($new_h / 2) + ($isCornering ? $ep['dy'] * ($new_h / 2) : 0);

        if (!checkCollision((int)$cx, (int)$cy, $new_w, $new_h, $s['board']['tiles'])) {
            $placed = true;
            $bestCx = $cx; $bestCy = $cy;
            $bestNdx = $ndx; $bestNdy = $ndy;
            $bestW = $new_w; $bestH = $new_h;
            break;
        }
    }

    if (!$placed) {
        throw new RuntimeException("Cannot place tile here - board space is visually blocked!");
    }

    array_splice($s['board']['endpoints'], $ei, 1);
    $nodeId = 'n' . bin2hex(random_bytes(4));

    $s['board']['tiles'][] = [
        'nodeId' => $nodeId,
        'tile' => $t,
        'parentEndpoint' => $endpointId,
        'parentNodeId' => $ep['nodeId'],
        'x' => $bestCx,
        'y' => $bestCy,
        'w' => $bestW,
        'h' => $bestH,
        'dx' => $bestNdx,
        'dy' => $bestNdy,
        'orientation' => ($matchSide === 'a') ? 0 : 1,
        'isDouble' => $isDouble
    ];

    if ($isDouble) {
        $fwd = ['dx' => $bestNdx, 'dy' => $bestNdy];
        $side1 = ['dx' => -$bestNdy, 'dy' => $bestNdx];
        $side2 = ['dx' => $bestNdy, 'dy' => -$bestNdx];
        
        foreach ([$fwd, $side1, $side2] as $nd) {
            $s['board']['endpoints'][] = [
                'id' => 'e' . bin2hex(random_bytes(4)),
                'value' => $outer,
                'nodeId' => $nodeId,
                'branch' => true,
                'x' => $bestCx + $nd['dx'] * ($bestW / 2),
                'y' => $bestCy + $nd['dy'] * ($bestH / 2),
                'dx' => $nd['dx'],
                'dy' => $nd['dy']
            ];
        }
    } else {
        $s['board']['endpoints'][] = [
            'id' => 'e' . bin2hex(random_bytes(4)),
            'value' => $outer,
            'nodeId' => $nodeId,
            'branch' => false,
            'x' => $bestCx + $bestNdx * ($bestW / 2),
            'y' => $bestCy + $bestNdy * ($bestH / 2),
            'dx' => $bestNdx,
            'dy' => $bestNdy
        ];
    }

    return true;
}

function checkWinOrAdvance(array &$g, int $playerIndex): void {
    $s =& $g['state'];
    $target = (int)$g['target'];
    
    if ($s['players'][$playerIndex]['score'] >= $target) {
        $g['status'] = 'finished';
        $s['winner'] = $s['players'][$playerIndex]['nickname'];
        $s['winnerScore'] = $s['players'][$playerIndex]['score'];
        $s['message'] = $s['winner'] . ' wins the match!';
        return;
    }
    
    if (count($s['players'][$playerIndex]['hand']) === 0) {
        scoreHandAndAdvance($g);
        return;
    }
    
    $s['turnIndex'] = ($s['turnIndex'] + 1) % count($s['players']);
    if ($s['players'][$s['turnIndex']]['isCpu'] ?? false) {
        $s['cpuNextActionAt'] = time() + 2;
    }
}

function scoreHandAndAdvance(array &$game): void {
    $s =& $game['state'];
    $ruleset = $s['ruleset'] ?? 'all_fives';
    $scores = [];
    foreach ($s['players'] as $p) {
        $scores[] = handPips($p['hand']);
    }

    $winnerIndex = null;
    $blocked = false;

    foreach ($s['players'] as $i => $p) {
        if (count($p['hand']) === 0) {
            $winnerIndex = $i;
            break;
        }
    }

    if ($winnerIndex === null) {
        $blocked = true;
        $lowest = min($scores);
        $candidates = array_keys(array_filter($scores, fn($v) => $v === $lowest));
        if (count($candidates) === 1) {
            $winnerIndex = $candidates[0];
        }
    }

    $summaryId = 'r_' . $s['round'] . '_' . bin2hex(random_bytes(3));

    if ($winnerIndex === null) {
        $s['message'] = 'Blocked hand — tied pip totals. No points awarded.';
        $s['lastEvent'] = $s['message'];
        $s['lastRoundSummary'] = [
            'summaryId' => $summaryId,
            'roundId' => $s['round'],
            'winnerNick' => 'None',
            'isBlock' => true,
            'pipTotal' => 0,
            'awarded' => 0,
            'playerScores' => array_map(fn($p) => ['nick' => $p['nickname'], 'score' => $p['score']], $s['players'])
        ];
        
        $s['round']++;
        startRound($game, null);
        return;
    }

    $points = 0;
    foreach ($scores as $i => $v) {
        if ($i !== $winnerIndex) $points += $v;
    }

    if ($blocked) {
        $points -= $scores[$winnerIndex];
        if ($points < 0) $points = 0;
    }

    $awarded = 0;
    if ($ruleset === 'fives_and_threes') {
        $awarded = (int)round($points / 5);
    } else if ($ruleset === 'all_fives') {
        $awarded = (int)(round($points / 5) * 5);
    } else if ($ruleset === 'all_threes') {
        $awarded = (int)(round($points / 3) * 3);
    } else {
        $awarded = $points;
    }

    $s['players'][$winnerIndex]['score'] += $awarded;
    $winnerName = $s['players'][$winnerIndex]['nickname'];
    
    $s['lastEvent'] = $winnerName . ' won the round (+' . $awarded . ' pts).';
    $s['message'] = $s['lastEvent'];

    $s['lastRoundSummary'] = [
        'summaryId' => $summaryId,
        'roundId' => $s['round'],
        'winnerNick' => $winnerName,
        'isBlock' => $blocked,
        'pipTotal' => $points,
        'awarded' => $awarded,
        'playerScores' => array_map(fn($p) => ['nick' => $p['nickname'], 'score' => $p['score']], $s['players'])
    ];

    if ($s['players'][$winnerIndex]['score'] >= (int)$game['target']) {
        $game['status'] = 'finished';
        $s['winner'] = $winnerName;
        $s['winnerScore'] = $s['players'][$winnerIndex]['score'];
        return;
    }

    $s['round']++;
    startRound($game, null);
}

function doPlay(array &$g, int $playerIndex, string $tileId, string $endpointId): void {
    $s =& $g['state'];
    $player =& $s['players'][$playerIndex];
    $map = tileMap();
    $ruleset = $s['ruleset'] ?? 'all_fives';

    if (!in_array($tileId, $player['hand'], true)) {
        throw new RuntimeException('You do not hold that domino.');
    }

    if (!$s['board']['tiles']) {
        if ($s['requiredStartTile'] !== null && $s['requiredStartTile'] !== $tileId) {
            throw new RuntimeException('Must play highest double/pip to start.');
        }
        addOpeningTile($s, $tileId);
    } else {
        if (!playOnEndpoint($s, $tileId, $endpointId)) {
            throw new RuntimeException('Domino does not match selected end.');
        }
    }

    $boardSum = calculateBoardSum($s['board']);
    $awardedPoints = calculateMoveScore($boardSum, $ruleset);
    $player['score'] += $awardedPoints;

    $player['hand'] = array_values(array_diff($player['hand'], [$tileId]));

    $msg = $player['nickname'] . ' played ' . tileLabel($map[$tileId]);
    if ($awardedPoints > 0) $msg .= " & scored {$awardedPoints} pts!";
    else $msg .= ".";
    
    $s['message'] = $msg;
    $s['lastEvent'] = $msg;
    $s['requiredStartTile'] = null;

    checkWinOrAdvance($g, $playerIndex);
}

function doDraw(array &$g, int $playerIndex): void {
    $s =& $g['state'];
    $player =& $s['players'][$playerIndex];
    
    if ($s['ruleset'] === 'block') {
        throw new RuntimeException('Drawing is not allowed in Block Dominoes.');
    }
    if (canPlayAny($player['hand'], $s['board'])) {
        throw new RuntimeException('You have a playable domino! Play it before drawing.');
    }
    if (empty($s['boneyard'])) {
        throw new RuntimeException('Boneyard is empty. Pass your turn.');
    }

    $id = array_pop($s['boneyard']);
    $player['hand'][] = $id;
    sortHand($player['hand']);

    $s['message'] = $player['nickname'] . ' drew a domino.';
    $s['lastEvent'] = $s['message'];
}

function doPass(array &$g, int $playerIndex): void {
    $s =& $g['state'];
    $player =& $s['players'][$playerIndex];

    if ($s['ruleset'] !== 'block' && !empty($s['boneyard'])) {
        throw new RuntimeException('Boneyard has tiles available. Draw before passing.');
    }
    if (canPlayAny($player['hand'], $s['board'])) {
        throw new RuntimeException('You have playable dominoes in hand.');
    }

    $s['message'] = $player['nickname'] . ' passed.';
    $s['lastEvent'] = $s['message'];
    $s['turnIndex'] = ($s['turnIndex'] + 1) % count($s['players']);

    $anyPlayable = false;
    foreach ($s['players'] as $p) {
        if (canPlayAny($p['hand'], $s['board'])) {
            $anyPlayable = true;
            break;
        }
    }

    if (!$anyPlayable && empty($s['boneyard'])) {
        scoreHandAndAdvance($g);
    } else {
        if ($s['players'][$s['turnIndex']]['isCpu'] ?? false) {
            $s['cpuNextActionAt'] = time() + 2;
        }
    }
}

function executeCpuAction(array &$g): void {
    $s =& $g['state'];
    $playerIndex = $s['turnIndex'];
    $player = $s['players'][$playerIndex];
    $ruleset = $s['ruleset'] ?? 'all_fives';

    $playableMoves = [];
    if (!$s['board']['tiles']) {
        if ($s['requiredStartTile']) {
            $playableMoves[] = ['tile' => $s['requiredStartTile'], 'endpoint' => ''];
        }
    } else {
        $map = tileMap();
        foreach ($player['hand'] as $tileId) {
            $t = $map[$tileId];
            foreach ($s['board']['endpoints'] as $ep) {
                if ($t['a'] === $ep['value'] || $t['b'] === $ep['value']) {
                    $playableMoves[] = ['tile' => $tileId, 'endpoint' => $ep['id']];
                }
            }
        }
    }
    
    $validMoves = [];
    foreach ($playableMoves as $move) {
        $tempState = ['board' => $s['board']];
        $canPlay = false;
        try {
            if ($move['endpoint'] === '') addOpeningTile($tempState, $move['tile']);
            else playOnEndpoint($tempState, $move['tile'], $move['endpoint']);
            $canPlay = true;
        } catch (Exception $e) {}
        if ($canPlay) $validMoves[] = $move;
    }
    $playableMoves = $validMoves;

    if (empty($playableMoves)) {
        if ($ruleset === 'block' || empty($s['boneyard'])) {
            doPass($g, $playerIndex);
        } else {
            doDraw($g, $playerIndex);
            $s['cpuNextActionAt'] = time() + 1;
        }
        return;
    }

    $bestMove = $playableMoves[array_rand($playableMoves)];
    $maxPoints = -1;

    if (in_array($ruleset, ['all_fives', 'all_threes', 'fives_and_threes'])) {
        foreach ($playableMoves as $move) {
            $score = simulateScore($s['board'], $move['tile'], $move['endpoint'], $ruleset);
            if ($score > $maxPoints) {
                $maxPoints = $score;
                $bestMove = $move;
            }
        }
    } else {
        $maxPips = -1;
        $map = tileMap();
        foreach ($playableMoves as $move) {
            $p = pips($map[$move['tile']]);
            if ($p > $maxPips) {
                $maxPips = $p;
                $bestMove = $move;
            }
        }
    }

    doPlay($g, $playerIndex, $bestMove['tile'], $bestMove['endpoint']);
}

function lockGame(int $id): bool {
    return db()->exec('BEGIN IMMEDIATE') !== false;
}

function unlockGame(bool $commit = true): void {
    db()->exec($commit ? 'COMMIT' : 'ROLLBACK');
}

$token = ensurePlayerToken();
$api = $_GET['api'] ?? '';

if ($api === 'list') {
    $rows = [];
    $result = db()->query("SELECT code,name,target,status,state,created_at FROM games WHERE status IN ('lobby','playing') AND private = 0 ORDER BY updated_at DESC LIMIT 50");
    while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
        $st = json_decode($r['state'], true) ?: [];
        $rows[] = [
            'code' => $r['code'],
            'name' => $r['name'],
            'target' => (int)$r['target'],
            'rulesetName' => formatRulesetName($st['ruleset'] ?? 'all_fives'),
            'status' => $r['status'],
            'players' => count($st['players'] ?? []),
            'maxPlayers' => MAX_PLAYERS,
            'createdAt' => (int)$r['created_at'],
        ];
    }
    jsonOut(['ok' => true, 'games' => $rows]);
}

if ($api === 'state') {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
    $g = findGame($code);
    if (!$g) jsonOut(['ok' => false, 'error' => 'Game not found.'], 404);

    $playerIndex = -1;
    foreach ($g['state']['players'] as $i => $p) {
        if ($p['id'] === $token) {
            $playerIndex = $i;
            $g['state']['players'][$i]['lastSeen'] = time();
            break;
        }
    }
    if ($playerIndex >= 0) {
        saveGame($g);
        $g = findGame($code);
    }

    $settings = getAdminSettings();
    $timeoutMinutes = (int)($settings['inactivity_timeout_minutes'] ?? 0);
    if ($timeoutMinutes > 0) {
        $now = time();
        $threshold = $now - ($timeoutMinutes * 60);
        $humanPlayers = array_filter($g['state']['players'], fn($p) => empty($p['isCpu']));
        $delete = false;
        if (empty($humanPlayers)) {
            if ($g['updated_at'] < $threshold) {
                $delete = true;
            }
        } else {
            $allAway = true;
            foreach ($humanPlayers as $p) {
                if (($p['lastSeen'] ?? 0) >= $threshold) {
                    $allAway = false;
                    break;
                }
            }
            if ($allAway) {
                $delete = true;
            }
        }
        if ($delete) {
            deleteGame($code);
            jsonOut(['ok' => false, 'error' => 'Table cleared due to inactivity.'], 404);
        }
    }

    if ($g['status'] === 'playing') {
        $s = $g['state'];
        $turnIdx = $s['turnIndex'];
        $turnPlayer = $s['players'][$turnIdx] ?? null;
        
        if ($turnPlayer && ($turnPlayer['isCpu'] ?? false)) {
            if (time() >= ($s['cpuNextActionAt'] ?? 0)) {
                if (lockGame((int)$g['id'])) {
                    try {
                        $g = findGame($code); 
                        $s = $g['state'];
                        $turnIdx = $s['turnIndex'];
                        $turnPlayer = $s['players'][$turnIdx] ?? null;
                        if ($g['status'] === 'playing' && $turnPlayer && ($turnPlayer['isCpu'] ?? false) && time() >= ($s['cpuNextActionAt'] ?? 0)) {
                            executeCpuAction($g);
                            saveGame($g);
                        }
                        unlockGame(true);
                    } catch (Throwable $e) {
                        unlockGame(false);
                    }
                }
            }
        }
    }

    jsonOut(['ok' => true, 'state' => publicState($g, $token)]);
}

if ($api === 'admin_settings') {
    jsonOut(['ok' => true, 'settings' => getAdminSettings()]);
}

if ($api === 'admin_login') {
    $password = (string)($_POST['password'] ?? '');
    $valid = verifyAdminPassword($password);
    jsonOut(['ok' => $valid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_login') {
        $password = (string)($_POST['password'] ?? '');
        $valid = verifyAdminPassword($password);
        jsonOut(['ok' => $valid]);
    }

    if ($action === 'create') {
        $nick = cleanNick((string)($_POST['nickname'] ?? ''));
        $name = cleanGameName((string)($_POST['name'] ?? ''));
        $target = (int)($_POST['target'] ?? 100);
        $ruleset = in_array($_POST['ruleset'] ?? '', ['all_fives', 'block', 'draw', 'all_threes', 'fives_and_threes']) ? $_POST['ruleset'] : 'all_fives';
        $private = isset($_POST['private']) && $_POST['private'] === '1' ? 1 : 0;

        if ($nick === '') jsonOut(['ok' => false, 'error' => 'Please enter a nickname.'], 400);
        if ($name === '') $name = $nick . "'s Table";

        $code = generateGameCode();
        $state = [
            'ruleset' => $ruleset,
            'round' => 1,
            'players' => [[
                'id' => $token,
                'nickname' => $nick,
                'hand' => [],
                'score' => 0,
                'lastSeen' => time(),
            ]],
            'boneyard' => [],
            'board' => emptyBoard(),
            'turnIndex' => 0,
            'starterId' => null,
            'requiredStartTile' => null,
            'message' => 'Waiting for players…',
            'lastEvent' => '',
            'winner' => null,
            'winnerScore' => null,
            'lastRoundSummary' => null,
        ];

        $stmt = db()->prepare('INSERT INTO games(code,name,target,status,state,created_at,updated_at,private) VALUES(:c,:n,:t,"lobby",:s,:now,:now,:p)');
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $stmt->bindValue(':n', $name, SQLITE3_TEXT);
        $stmt->bindValue(':t', $target, SQLITE3_INTEGER);
        $stmt->bindValue(':s', json_encode($state, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':p', $private, SQLITE3_INTEGER);
        $stmt->execute();

        $_SESSION['domino_code'] = $code;
        jsonOut(['ok' => true, 'code' => $code]);
    }

    if ($action === 'join') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $nick = cleanNick((string)($_POST['nickname'] ?? ''));

        if ($nick === '' || $code === '') jsonOut(['ok' => false, 'error' => 'Nickname and code required.'], 400);

        $g = findGame($code);
        if (!$g) jsonOut(['ok' => false, 'error' => 'Game code not found.'], 404);
        if ($g['status'] !== 'lobby') jsonOut(['ok' => false, 'error' => 'Game is already in progress.'], 409);
        if (count($g['state']['players']) >= MAX_PLAYERS) jsonOut(['ok' => false, 'error' => 'Table is full.'], 409);

        foreach ($g['state']['players'] as $p) {
            if (strcasecmp($p['nickname'], $nick) === 0) jsonOut(['ok' => false, 'error' => 'Nickname is taken.'], 409);
        }

        $g['state']['players'][] = [
            'id' => $token,
            'nickname' => $nick,
            'hand' => [],
            'score' => 0,
            'lastSeen' => time(),
        ];

        saveGame($g);
        $_SESSION['domino_code'] = $code;
        jsonOut(['ok' => true, 'code' => $code]);
    }

    $code = strtoupper(trim((string)($_POST['code'] ?? $_SESSION['domino_code'] ?? '')));
    $g = findGame($code);
    if (!$g && $action !== 'leave' && $action !== 'reset_game' && $action !== 'admin_update_settings' && $action !== 'admin_reset_all_games' && $action !== 'admin_delete_all_games') jsonOut(['ok' => false, 'error' => 'Game not found.'], 404);

    if ($action === 'add_cpu') {
        if ($g['status'] !== 'lobby') jsonOut(['ok' => false, 'error' => 'Game already started.'], 400);
        if ($g['state']['players'][0]['id'] !== $token) jsonOut(['ok' => false, 'error' => 'Only host can add CPUs.'], 403);
        if (count($g['state']['players']) >= MAX_PLAYERS) jsonOut(['ok' => false, 'error' => 'Table is full.'], 409);

        $botNames = ['Bot Alpha', 'Bot Beta', 'Bot Gamma', 'Bot Delta'];
        $existing = array_column($g['state']['players'], 'nickname');
        $name = 'Bot ' . rand(1, 99);
        foreach ($botNames as $bn) {
            if (!in_array($bn, $existing)) {
                $name = $bn;
                break;
            }
        }

        $g['state']['players'][] = [
            'id' => 'cpu_' . bin2hex(random_bytes(4)),
            'nickname' => $name,
            'isCpu' => true,
            'hand' => [],
            'score' => 0,
            'lastSeen' => time(),
        ];
        saveGame($g);
        jsonOut(['ok' => true]);
    }

    if ($action === 'leave' && $g) {
        if (lockGame((int)$g['id'])) {
            try {
                $g = findGame($code);
                $playerIndex = -1;
                $leavingNick = 'A player';
                foreach ($g['state']['players'] as $i => $p) {
                    if ($p['id'] === $token) {
                        $playerIndex = $i;
                        $leavingNick = $p['nickname'];
                        break;
                    }
                }

                if ($playerIndex >= 0) {
                    array_splice($g['state']['players'], $playerIndex, 1);
                    $humanCount = count(array_filter($g['state']['players'], fn($p) => empty($p['isCpu'])));
                    
                    if ($humanCount === 0) {
                        $g['status'] = 'finished';
                    } else if ($g['status'] === 'playing') {
                        if ($g['state']['turnIndex'] >= count($g['state']['players'])) {
                            $g['state']['turnIndex'] = 0;
                        }
                        $g['state']['message'] = $leavingNick . ' left the match.';
                        $g['state']['lastEvent'] = $g['state']['message'];
                        
                        if ($g['state']['players'][$g['state']['turnIndex']]['isCpu'] ?? false) {
                             $g['state']['cpuNextActionAt'] = time() + 1;
                        }
                    }
                    saveGame($g);
                }
                unlockGame(true);
            } catch (Throwable $e) {
                unlockGame(false);
            }
        }
        unset($_SESSION['domino_code']);
        jsonOut(['ok' => true]);
    }

    if ($action === 'start') {
        if (count($g['state']['players']) < MIN_PLAYERS) jsonOut(['ok' => false, 'error' => 'Need at least 2 players.'], 400);
        if ($g['status'] !== 'lobby') jsonOut(['ok' => false, 'error' => 'Game has already started.'], 400);
        if ($g['state']['players'][0]['id'] !== $token) jsonOut(['ok' => false, 'error' => 'Only the host can start.'], 403);

        $g['status'] = 'playing';
        startRound($g, null);
        saveGame($g);
        jsonOut(['ok' => true]);
    }

    if ($action === 'reset_game') {
        if ($g['state']['players'][0]['id'] !== $token) jsonOut(['ok' => false, 'error' => 'Only the host can reset.'], 403);
        
        $g['status'] = 'lobby';
        $s =& $g['state'];
        $s['round'] = 1;
        $s['board'] = emptyBoard();
        $s['boneyard'] = [];
        $s['turnIndex'] = 0;
        $s['starterId'] = null;
        $s['requiredStartTile'] = null;
        $s['message'] = 'Game reset by host.';
        $s['lastEvent'] = '';
        $s['winner'] = null;
        $s['winnerScore'] = null;
        $s['lastRoundSummary'] = null;
        foreach ($s['players'] as &$p) {
            $p['hand'] = [];
            $p['score'] = 0;
        }
        unset($p);
        saveGame($g);
        jsonOut(['ok' => true]);
    }

    if ($action === 'signal') {
        $to = (string)($_POST['to'] ?? '');
        $data = (string)($_POST['data'] ?? '');
        if ($to === '' || $data === '') jsonOut(['ok' => false, 'error' => 'Missing to or data'], 400);
        $stmt = db()->prepare('INSERT INTO signals(game_code, from_player, to_player, data, created_at) VALUES(:c, :f, :t, :d, :now)');
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $stmt->bindValue(':f', $token, SQLITE3_TEXT);
        $stmt->bindValue(':t', $to, SQLITE3_TEXT);
        $stmt->bindValue(':d', $data, SQLITE3_TEXT);
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->execute();
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_update_settings') {
        $password = (string)($_POST['password'] ?? '');
        if (!verifyAdminPassword($password)) {
            jsonOut(['ok' => false, 'error' => 'Invalid admin password'], 403);
        }
        $allowed = [
            'game_name',
            'hand_scale_default',
            'board_tile_scale_default',
            'expanded_card_height_default',
            'auto_play_default',
            'highlight_last_tile_default',
            'auto_pan_last_tile_default',
            'inactivity_timeout_minutes',
            'room_list_height_default'
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $val = trim((string)$_POST[$key]);
                $stmt = db()->prepare('UPDATE admin_settings SET value = :val WHERE key = :key');
                $stmt->bindValue(':val', $val);
                $stmt->bindValue(':key', $key);
                $stmt->execute();
            }
        }
        $newPass = trim((string)($_POST['new_password'] ?? ''));
        $confirmPass = trim((string)($_POST['confirm_password'] ?? ''));
        if ($newPass !== '' && $newPass === $confirmPass) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE admin_settings SET value = :hash WHERE key = "admin_password_hash"');
            $stmt->bindValue(':hash', $hash);
            $stmt->execute();
        } elseif ($newPass !== '' && $newPass !== $confirmPass) {
            jsonOut(['ok' => false, 'error' => 'New passwords do not match.'], 400);
        }
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_reset_all_games') {
        $password = (string)($_POST['password'] ?? '');
        if (!verifyAdminPassword($password)) {
            jsonOut(['ok' => false, 'error' => 'Invalid admin password'], 403);
        }
        $result = db()->query("SELECT id, state FROM games WHERE status IN ('lobby','playing')");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $gameId = $row['id'];
            $state = json_decode($row['state'], true);
            $state['round'] = 1;
            $state['board'] = emptyBoard();
            $state['boneyard'] = [];
            $state['turnIndex'] = 0;
            $state['starterId'] = null;
            $state['requiredStartTile'] = null;
            $state['message'] = 'Game reset by admin.';
            $state['lastEvent'] = '';
            $state['winner'] = null;
            $state['winnerScore'] = null;
            $state['lastRoundSummary'] = null;
            foreach ($state['players'] as &$p) {
                $p['hand'] = [];
                $p['score'] = 0;
            }
            unset($p);
            $stmt = db()->prepare('UPDATE games SET status = "lobby", state = :state, updated_at = :now WHERE id = :id');
            $stmt->bindValue(':state', json_encode($state, JSON_UNESCAPED_SLASHES));
            $stmt->bindValue(':now', time());
            $stmt->bindValue(':id', $gameId);
            $stmt->execute();
        }
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_delete_all_games') {
        $password = (string)($_POST['password'] ?? '');
        if (!verifyAdminPassword($password)) {
            jsonOut(['ok' => false, 'error' => 'Invalid admin password'], 403);
        }
        $db = db();
        $db->exec('BEGIN TRANSACTION');
        try {
            $db->exec('DELETE FROM signals');
            $db->exec('DELETE FROM chat');
            $db->exec('DELETE FROM games');
            $db->exec('COMMIT');
            jsonOut(['ok' => true]);
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            jsonOut(['ok' => false, 'error' => 'Failed to delete tables: ' . $e->getMessage()], 500);
        }
    }

    if (!lockGame((int)$g['id'])) {
        jsonOut(['ok' => false, 'error' => 'Game busy, please retry.'], 409);
    }

    try {
        $g = findGame($code);
        $playerIndex = -1;
        foreach ($g['state']['players'] as $i => $p) {
            if ($p['id'] === $token) {
                $playerIndex = $i;
                $g['state']['players'][$i]['lastSeen'] = time();
                break;
            }
        }

        if ($playerIndex < 0) throw new RuntimeException('You are not seated at this table.');
        if ($g['status'] !== 'playing') throw new RuntimeException('Game is not active.');
        if ($g['state']['turnIndex'] !== $playerIndex && $action !== 'chat' && $action !== 'signal') throw new RuntimeException('It is not your turn.');

        if ($action === 'play') {
            doPlay($g, $playerIndex, (string)($_POST['tile'] ?? ''), (string)($_POST['endpoint'] ?? ''));
        } elseif ($action === 'draw') {
            doDraw($g, $playerIndex);
        } elseif ($action === 'pass') {
            doPass($g, $playerIndex);
        } elseif ($action === 'chat') {
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($msg !== '') {
                $stmt = db()->prepare('INSERT INTO chat(game_code, player_id, nickname, message, created_at) VALUES(:c, :p, :n, :m, :t)');
                $stmt->bindValue(':c', $code, SQLITE3_TEXT);
                $stmt->bindValue(':p', $token, SQLITE3_TEXT);
                $stmt->bindValue(':n', $g['state']['players'][$playerIndex]['nickname'], SQLITE3_TEXT);
                $stmt->bindValue(':m', htmlspecialchars($msg), SQLITE3_TEXT);
                $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
                $stmt->execute();
            }
        } else {
            throw new RuntimeException('Invalid action requested.');
        }

        saveGame($g);
        unlockGame(true);
        jsonOut(['ok' => true, 'state' => publicState($g, $token)]);
    } catch (Throwable $e) {
        unlockGame(false);
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

$initialCode = htmlspecialchars((string)($_SESSION['domino_code'] ?? ''), ENT_QUOTES);
$adminSettings = getAdminSettings();
$gameName = htmlspecialchars($adminSettings['game_name'] ?? 'Domino Tree');
$roomListHeight = (int)($adminSettings['room_list_height_default'] ?? 320);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= $gameName ?> — Deluxe Multiplayer</title>
<style>
.hidden { display: none !important; }

:root {
    --felt-1: #0e4032;
    --felt-2: #07261d;
    --gold: #f3c258;
    --gold-glow: rgba(243, 194, 88, 0.4);
    --gold-hover: #ffd875;
    --dark-panel: rgba(7, 24, 19, 0.88);
    --light-text: #f4efe2;
    --muted-text: #a1b8ad;
    --danger: #e0564e;
    --pip-1: #1d4ed8; --pip-2: #15803d; --pip-3: #dc2626;
    --pip-4: #7c3aed; --pip-5: #0891b2; --pip-6: #ea580c;
    --tile-bg-1: #ffffff;
    --tile-bg-2: #ece6d2;
    --tile-line: rgba(0, 0, 0, 0.28);
}

/* ===== Domino Themes ===== */
[data-theme="classic"] {
    --tile-bg-1: #ffffff;
    --tile-bg-2: #ece6d2;
    --tile-line: rgba(0, 0, 0, 0.28);
    --gold: #f3c258;
    --gold-glow: rgba(243, 194, 88, 0.4);
    --gold-hover: #ffd875;
}
[data-theme="dark-wood"] {
    --tile-bg-1: #d4b896;
    --tile-bg-2: #b58d6a;
    --tile-line: #5a3e2b;
    --gold: #c9a84c;
    --gold-glow: rgba(201, 168, 76, 0.4);
    --gold-hover: #e6c87a;
}
[data-theme="ivory"] {
    --tile-bg-1: #faf6e8;
    --tile-bg-2: #e8dfc8;
    --tile-line: #9a8b7a;
    --gold: #d4af37;
    --gold-glow: rgba(212, 175, 55, 0.4);
    --gold-hover: #e8c84a;
}
[data-theme="blue-ocean"] {
    --tile-bg-1: #cbe4f9;
    --tile-bg-2: #a0c4e8;
    --tile-line: #2a5f7a;
    --gold: #4fc3f7;
    --gold-glow: rgba(79, 195, 247, 0.4);
    --gold-hover: #81d4fa;
}
[data-theme="red-velvet"] {
    --tile-bg-1: #f5d0d0;
    --tile-bg-2: #e8b0b0;
    --tile-line: #7a2a2a;
    --gold: #e57373;
    --gold-glow: rgba(229, 115, 115, 0.4);
    --gold-hover: #ef9a9a;
}
[data-theme="green-forest"] {
    --tile-bg-1: #c8e6c9;
    --tile-bg-2: #a5d6a7;
    --tile-line: #2e5d2e;
    --gold: #66bb6a;
    --gold-glow: rgba(102, 187, 106, 0.4);
    --gold-hover: #81c784;
}
[data-theme="gold-royal"] {
    --tile-bg-1: #ffe082;
    --tile-bg-2: #ffd54f;
    --tile-line: #7a5a1a;
    --gold: #ffb300;
    --gold-glow: rgba(255, 179, 0, 0.5);
    --gold-hover: #ffca28;
}
[data-theme="monochrome"] {
    --tile-bg-1: #f0f0f0;
    --tile-bg-2: #d0d0d0;
    --tile-line: #444;
    --gold: #888;
    --gold-glow: rgba(136, 136, 136, 0.4);
    --gold-hover: #aaa;
}
[data-theme="neon"] {
    --tile-bg-1: #2a2a3a;
    --tile-bg-2: #1a1a2a;
    --tile-line: #7a7aff;
    --gold: #00ffcc;
    --gold-glow: rgba(0, 255, 204, 0.5);
    --gold-hover: #66ffdd;
}
[data-theme="pastel"] {
    --tile-bg-1: #fce4ec;
    --tile-bg-2: #f8bbd0;
    --tile-line: #8a6a7a;
    --gold: #f06292;
    --gold-glow: rgba(240, 98, 146, 0.4);
    --gold-hover: #f48fb1;
}
[data-theme="night-sky"] {
    --tile-bg-1: #1a2a3a;
    --tile-bg-2: #0d1a26;
    --tile-line: #5a7a9a;
    --gold: #64b5f6;
    --gold-glow: rgba(100, 181, 246, 0.4);
    --gold-hover: #90caf9;
}
[data-theme="autumn"] {
    --tile-bg-1: #f5d6b3;
    --tile-bg-2: #e6b88a;
    --tile-line: #6a4a2a;
    --gold: #e67e22;
    --gold-glow: rgba(230, 126, 34, 0.4);
    --gold-hover: #f39c12;
}

/* ===== Board Colors ===== */
[data-board="green"] {
    --board-bg: radial-gradient(circle at 50% 20%, #155744 0%, #0e4032 50%, #07261d 100%);
}
[data-board="dark-green"] {
    --board-bg: radial-gradient(circle at 50% 20%, #1a4a3a 0%, #0d3226 50%, #051a12 100%);
}
[data-board="blue"] {
    --board-bg: radial-gradient(circle at 50% 20%, #1a3a5a 0%, #0e2a42 50%, #061a2a 100%);
}
[data-board="red"] {
    --board-bg: radial-gradient(circle at 50% 20%, #5a1a1a 0%, #421010 50%, #2a0808 100%);
}
[data-board="purple"] {
    --board-bg: radial-gradient(circle at 50% 20%, #3a1a5a 0%, #2a0e42 50%, #1a062a 100%);
}
[data-board="grey"] {
    --board-bg: radial-gradient(circle at 50% 20%, #3a3a3a 0%, #282828 50%, #1a1a1a 100%);
}
[data-board="black"] {
    --board-bg: radial-gradient(circle at 50% 20%, #2a2a2a 0%, #101010 50%, #000 100%);
}
[data-board="tan"] {
    --board-bg: radial-gradient(circle at 50% 20%, #8a7a5a 0%, #6a5a42 50%, #4a3a2a 100%);
}

body {
    background: var(--board-bg, radial-gradient(circle at 50% 20%, #155744 0%, #0e4032 50%, #07261d 100%));
}

.domino-tile {
    background: linear-gradient(145deg, var(--tile-bg-1) 0%, var(--tile-bg-2) 100%);
    border-color: var(--tile-line);
}
.domino-tile .tile-half:first-child {
    border-color: var(--tile-line);
}
.domino-tile.horizontal .tile-half:first-child {
    border-color: var(--tile-line);
}
.tree-endpoint {
    border-color: var(--gold);
    background: rgba(243,194,88,0.2);
    color: var(--gold);
    box-shadow: 0 0 20px var(--gold-glow);
}
.tree-endpoint:hover, .tree-endpoint.selected {
    background: rgba(243,194,88,0.45);
}
.domino-tile.playable {
    box-shadow: 0 0 0 2px var(--gold), 0 4px 12px rgba(0,0,0,0.5);
    animation: pulse-glow 1.5s infinite alternate;
}
.domino-tile.selected {
    box-shadow: 0 0 0 2px var(--gold-hover), 0 8px 16px rgba(0,0,0,0.6);
}
.hud-card-right div { color: var(--gold); }
#hudTurn { color: var(--gold); }
.chat-header { color: var(--gold); }
.brand span { color: var(--gold); }

.board-node .domino-tile.last-played {
    box-shadow: 0 0 0 3px var(--gold-hover), 0 8px 16px rgba(243, 194, 88, 0.5) !important;
    border-color: var(--gold-hover);
}
@keyframes highlight-pulse {
    0% { box-shadow: 0 0 0 3px var(--gold-hover), 0 6px 14px rgba(243, 194, 88, 0.4); }
    100% { box-shadow: 0 0 0 5px var(--gold-hover), 0 10px 24px rgba(243, 194, 88, 0.7); }
}

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; color: var(--light-text); font-family: system-ui, sans-serif; user-select: none; }
.app { display: flex; flex-direction: column; height: 100dvh; width: 100vw; position: relative; overflow: hidden; }

header { height: 52px; padding: 0 16px; display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.35); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,0.08); z-index: 30; flex-shrink: 0; }
.brand { font-weight: 900; font-size: 19px; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px; }
.brand span { color: var(--gold); }
.pill { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.15); padding: 4px 10px; border-radius: 20px; font-size: 12px; color: var(--muted-text); font-weight: 600; }

main { flex: 1; position: relative; width: 100%; height: 100%; overflow: hidden; }
.screen { position: absolute; inset: 0; display: flex; flex-direction: column; padding: 16px; overflow-y: auto; z-index: 10; }

.panel { background: var(--dark-panel); border: 1px solid rgba(255,255,255,0.12); border-radius: 20px; padding: 20px; box-shadow: 0 16px 36px rgba(0,0,0,0.4); backdrop-filter: blur(8px); }
h1, h2, h3 { margin: 0 0 10px; font-weight: 800; }
p.desc { color: var(--muted-text); font-size: 14px; margin: 0 0 16px; line-height: 1.4; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }

label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted-text); margin: 10px 0 4px; font-weight: 700; }
input, select { width: 100%; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.18); border-radius: 12px; padding: 12px 14px; color: #fff; font-size: 15px; outline: none; }
input:focus, select:focus { border-color: var(--gold); }

button { font-family: inherit; font-size: 15px; font-weight: 800; padding: 12px 18px; border-radius: 12px; border: none; background: var(--gold); color: #1a1408; cursor: pointer; box-shadow: 0 4px 12px rgba(243,194,88,0.25); transition: transform 0.1s; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
button:active { transform: scale(0.97); }
button.secondary { background: rgba(255,255,255,0.12); color: var(--light-text); border: 1px solid rgba(255,255,255,0.18); box-shadow: none; }
button.primary { background: var(--gold); color: #1a1408; box-shadow: 0 4px 12px rgba(243,194,88,0.25); }
button.danger { background: rgba(224,86,78,0.25); color: #ff918c; border: 1px solid rgba(224,86,78,0.5); box-shadow: none; }
button:disabled { opacity: 0.45; cursor: not-allowed; transform: none !important; }

.actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.table-list-container {
    max-height: <?= $roomListHeight ?>px;
    overflow-y: auto;
    margin-top: 10px;
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    background: rgba(0,0,0,0.15);
}
.table-list { display: grid; gap: 8px; padding: 8px; }
.table-item { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
.table-item .table-info strong { color: var(--light-text); }
.table-item .table-info .sub { font-size: 11px; color: var(--muted-text); }

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 4px;
    margin-bottom: 6px;
}
.filter-bar input, .filter-bar select, .filter-bar label {
    flex: 1 1 auto;
    min-width: 80px;
}
.filter-bar input[type="text"] {
    flex: 3;
}
.filter-bar label {
    display: flex;
    align-items: center;
    gap: 6px;
    text-transform: none;
    color: var(--light-text);
    font-weight: 500;
    font-size: 13px;
    cursor: pointer;
    margin: 0;
}
.filter-bar input[type="checkbox"] { width: auto; margin: 0; }

#playerCards {
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    padding: 6px 10px;
    background: rgba(0,0,0,0.25);
    backdrop-filter: blur(4px);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    --expanded-height: 112px;
}

.seat-card {
    flex: 1 1 25%;
    max-width: 25%;
    min-width: 60px;
    background: rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    padding: 4px 6px;
    position: relative;
    cursor: pointer;
    transition: height 0.3s ease, border-color 0.2s;
    min-height: calc(64px * var(--player-text-scale, 1));
    height: auto;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
}
.seat-card.expanded {
    height: var(--expanded-height, 112px);
}
.seat-card.active {
    border-color: var(--gold);
    box-shadow: 0 0 12px var(--gold-glow);
}
.seat-card .video-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    border-radius: 8px;
}
.seat-card .video-bg video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.seat-card .content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    height: 100%;
    pointer-events: none;
    padding-top: 4px;
}
.seat-card .top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
}
.seat-card .top-row .p-name {
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 70%;
    background: rgba(0,0,0,0.5);
    padding: 0 4px;
    border-radius: 3px;
}
.seat-card .mic-icon {
    font-size: 12px;
    color: #10b981;
    display: none;
    text-shadow: 0 0 8px rgba(16,185,129,0.6);
    margin-left: 2px;
}
.seat-card .bottom-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    margin-top: 2px;
}
.seat-card .bottom-row .p-meta {
    background: rgba(0,0,0,0.5);
    padding: 0 4px;
    border-radius: 3px;
    font-weight: 600;
    color: var(--muted-text);
}
.score-badge {
    display: inline-flex;
    align-items: center;
    background: rgba(255,255,255,0.12);
    padding: 0 6px;
    border-radius: 4px;
    font-weight: 900;
    font-size: 11px;
    color: var(--light-text);
}
.tile-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: rgba(255,255,255,0.12);
    padding: 0 6px;
    border-radius: 4px;
    font-weight: 900;
    font-size: 11px;
    color: var(--light-text);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.seat-card.has-video .p-name,
.seat-card.has-video .score-badge,
.seat-card.has-video .tile-count-badge {
    background: rgba(0,0,0,0.7) !important;
    color: #fff !important;
}
.seat-card.has-video .third-row {
    background: transparent !important;
}
.seat-card.active .tile-count-badge {
    background: var(--gold);
    color: #1a1408;
}
.seat-card.active.has-video .tile-count-badge {
    color: #fff !important;
}

#boardContainer {
    flex: 1;
    position: relative;
    width: 100%;
    overflow: hidden;
    cursor: grab;
    touch-action: none;
    min-height: 0;
}
#boardContainer:active { cursor: grabbing; }
#boardWorld { position: absolute; width: 3000px; height: 3000px; top: 50%; left: 50%; transform-origin: center center; transition: transform 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94); }
#boardWorld.no-transition { transition: transform 0s !important; }

#boardWorld {
    --board-tile-scale: 1;
}
.board-node .domino-tile {
    transform: scale(var(--board-tile-scale, 1));
    transform-origin: center center;
    transition: transform 0.1s;
}
.tree-endpoint {
    transform: translate(-50%, -50%) scale(var(--board-tile-scale, 1));
    transform-origin: center center;
    transition: transform 0.1s;
}

.tree-endpoint { position: absolute; width: 52px; height: 52px; border-radius: 50%; border: 2px dashed var(--gold); background: rgba(243,194,88,0.2); color: var(--gold); font-weight: 900; font-size: 18px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 0 20px var(--gold-glow); z-index: 20; animation: endpoint-pulse 1.2s infinite alternate; }
.tree-endpoint:hover, .tree-endpoint.selected { background: rgba(243,194,88,0.45); border-style: solid; }
@keyframes endpoint-pulse { 0% { transform: translate(-50%, -50%) scale(0.95); opacity: 0.8; } 100% { transform: translate(-50%, -50%) scale(1.08); opacity: 1; } }
.board-node { position: absolute; transform: translate(-50%, -50%); z-index: 10; }

.hud-bar {
    position: absolute;
    top: 8px;
    left: 8px;
    right: 8px;
    display: flex;
    justify-content: space-between;
    align-items: stretch;
    pointer-events: none;
    z-index: 25;
}
.hud-card {
    pointer-events: auto;
    background: rgba(7,24,19,0.85);
    border: 1px solid rgba(255,255,255,0.12);
    backdrop-filter: blur(10px);
    border-radius: 14px;
    padding: 6px 12px;
    font-size: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
}
.hud-card-left { flex: 1; margin-right: 8px; }
.hud-card-left .hud-event { font-size: 10px; color: var(--muted-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hud-card-left .hud-meta { display: flex; gap: 12px; margin-top: 2px; font-size: 10px; color: var(--muted-text); }
.hud-card-left .hud-meta strong { color: var(--gold); }
.hud-card-right { padding: 4px 12px; min-width: 70px; align-items: flex-end; }
.hud-card-right div { font-weight: 800; color: var(--gold); font-size: 10px; text-transform: uppercase; }
.hud-card-right #hudBoardSum { font-size: 24px; font-weight: 900; color: #22c55e; line-height: 1.2; }

#rightControls {
    position: absolute;
    right: 8px;
    z-index: 30;
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: center;
    pointer-events: none;
    bottom: calc(env(safe-area-inset-bottom) + 180px);
}
#rightControls .zoom-btn {
    pointer-events: auto;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    padding: 0;
    background: rgba(7,24,19,0.92);
    border: 1px solid rgba(255,255,255,0.2);
    color: var(--light-text);
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
}
#rightControls .zoom-btn.active { color: #10b981; border-color: #10b981; }
#rightControls .badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: var(--danger);
    color: white;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: bold;
    border: 1px solid #111;
}

.chat-panel {
    position: absolute;
    right: 56px;
    width: 260px;
    height: 300px;
    background: rgba(12,34,26,0.95);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    z-index: 45;
    box-shadow: 0 16px 40px rgba(0,0,0,0.7);
    backdrop-filter: blur(12px);
    overflow: hidden;
    bottom: calc(env(safe-area-inset-bottom) + 240px);
}
.chat-header { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; font-weight: bold; color: var(--gold); font-size: 13px; }
.chat-header button { background: none; border: none; color: white; padding: 0; box-shadow: none; font-size: 16px; }
.chat-messages { flex: 1; overflow-y: auto; padding: 8px; display: flex; flex-direction: column; gap: 6px; font-size: 12px; }
.chat-msg { background: rgba(255,255,255,0.06); padding: 6px 8px; border-radius: 6px; user-select: text; }
.chat-msg.me { background: rgba(243,194,88,0.15); align-self: flex-end; border: 1px solid rgba(243,194,88,0.25); text-align: right; }
.chat-msg strong { display: block; font-size: 10px; color: var(--muted-text); margin-bottom: 2px; }
.chat-input-area { padding: 6px 8px; display: flex; gap: 4px; border-top: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); }
.chat-input-area input { flex: 1; padding: 6px 10px; font-size: 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: white; }
.chat-input-area button { padding: 4px 12px; font-size: 12px; border-radius: 6px; }

.hand-drawer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(5,18,14,0.92);
    border-top: 1px solid rgba(255,255,255,0.12);
    backdrop-filter: blur(16px);
    padding: calc(6px * var(--hand-scale, 1)) calc(8px * var(--hand-scale, 1)) calc((6px + env(safe-area-inset-bottom)) * var(--hand-scale, 1));
    z-index: 30;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.hand-scroll {
    display: flex;
    gap: calc(8px * var(--hand-scale, 1));
    overflow-x: auto;
    padding: calc(16px * var(--hand-scale, 1)) 2px calc(6px * var(--hand-scale, 1));
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.hand-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    font-size: calc(11px * var(--hand-scale, 1));
}
.hand-actions button.icon-btn { 
    padding: calc(4px * var(--hand-scale, 1)); 
    font-size: calc(16px * var(--hand-scale, 1)); 
    background: rgba(255,255,255,0.08);
    position: relative;
}
.hand-actions button.icon-btn.active { color: #10b981; border: 1px solid #10b981; }
.hand-actions .badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: var(--danger);
    color: white;
    font-size: 8px;
    padding: 1px 4px;
    border-radius: 10px;
    font-weight: bold;
    border: 1px solid #111;
}
.hand-actions span {
    font-size: calc(11px * var(--hand-scale, 1));
}
#handHint {
    font-size: calc(11px * var(--hand-scale, 1));
    color: var(--muted-text);
    text-align: center;
    padding: 2px 0;
    min-height: calc(22px * var(--hand-scale, 1));
    letter-spacing: 0.02em;
}

.domino-tile {
    width: calc(42px * var(--hand-scale, 1));
    height: calc(80px * var(--hand-scale, 1));
    border-radius: 6px;
    background: linear-gradient(145deg, var(--tile-bg-1) 0%, var(--tile-bg-2) 100%);
    box-shadow: 0 4px 12px rgba(0,0,0,0.4), inset 0 1px 1px rgba(255,255,255,0.8), inset 0 -1px 2px rgba(0,0,0,0.2);
    border: 1px solid rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform 0.15s, box-shadow 0.15s;
    overflow: hidden;
}
.domino-tile.horizontal { width: calc(80px * var(--hand-scale, 1)); height: calc(42px * var(--hand-scale, 1)); flex-direction: row; }

.board-node .domino-tile {
    box-shadow: inset 0 1px 1px rgba(255,255,255,0.8), inset 0 -1px 2px rgba(0,0,0,0.2);
}

.board-node .domino-tile.last-played {
    box-shadow: 0 0 0 3px var(--gold-hover), 0 8px 16px rgba(243, 194, 88, 0.5) !important;
    transform: scale(1.06);
    z-index: 15;
    border-color: var(--gold-hover);
    animation: highlight-pulse 1.2s ease-in-out infinite alternate;
}
@keyframes highlight-pulse {
    0% { box-shadow: 0 0 0 3px var(--gold-hover), 0 6px 14px rgba(243, 194, 88, 0.4); }
    100% { box-shadow: 0 0 0 5px var(--gold-hover), 0 10px 24px rgba(243, 194, 88, 0.7); }
}

.tile-half { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; }
.domino-tile:not(.horizontal) .tile-half:first-child { border-bottom: 2px solid var(--tile-line); }
.domino-tile.horizontal .tile-half:first-child { border-right: 2px solid var(--tile-line); }
.pip-grid { 
    display: grid; 
    grid-template-columns: repeat(3, calc(6px * var(--hand-scale, 1))); 
    grid-template-rows: repeat(3, calc(6px * var(--hand-scale, 1))); 
    gap: calc(2px * var(--hand-scale, 1)); 
}
.pip-dot { 
    width: calc(6px * var(--hand-scale, 1)); 
    height: calc(6px * var(--hand-scale, 1)); 
    border-radius: 50%; 
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.6), 0 1px 1px rgba(255,255,255,0.4); 
}
.pip-1 { background-color: var(--pip-1); } .pip-2 { background-color: var(--pip-2); }
.pip-3 { background-color: var(--pip-3); } .pip-4 { background-color: var(--pip-4); }
.pip-5 { background-color: var(--pip-5); } .pip-6 { background-color: var(--pip-6); }
.domino-tile.playable { box-shadow: 0 0 0 2px var(--gold), 0 4px 12px rgba(0,0,0,0.5); animation: pulse-glow 1.5s infinite alternate; }
.domino-tile.selected { transform: translateY(-6px) scale(1.04); box-shadow: 0 0 0 2px var(--gold-hover), 0 8px 16px rgba(0,0,0,0.6); }
@keyframes pulse-glow { 0% { box-shadow: 0 0 0 2px var(--gold), 0 4px 10px rgba(0,0,0,0.4); } 100% { box-shadow: 0 0 0 3px var(--gold-hover), 0 6px 16px rgba(243,194,88,0.4); } }

#toast { position: fixed; top: 64px; left: 50%; transform: translateX(-50%) translateY(-20px); background: #112821; color: #fff; border: 1px solid var(--gold); padding: 8px 16px; border-radius: 30px; font-weight: 700; font-size: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.2s, transform 0.2s; }
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

.modal-overlay .panel {
    max-height: 90vh;
    overflow-y: auto;
}

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 200; }
.game-over .winner-name { color: var(--gold); animation: pulse-glow 1s infinite alternate; }
.game-over .final-scores { margin: 16px 0; display: flex; flex-direction: column; gap: 8px; }
.game-over .final-scores .score-row { display: flex; justify-content: space-between; background: rgba(255,255,255,0.05); padding: 8px 12px; border-radius: 8px; }
.game-over .final-scores .score-row.winner { background: rgba(243,194,88,0.2); border: 1px solid var(--gold); }

/* Rules modal content */
.rules-content {
    line-height: 1.6;
    color: var(--light-text);
    padding: 4px 0;
}
.rules-content h3 {
    color: var(--gold);
    margin: 16px 0 8px;
}
.rules-content ul {
    padding-left: 20px;
    margin: 6px 0;
}
.rules-content li {
    margin-bottom: 4px;
}
.rules-content .rule-note {
    font-size: 13px;
    color: var(--muted-text);
    background: rgba(255,255,255,0.06);
    padding: 8px 12px;
    border-radius: 8px;
    margin-top: 10px;
}

@media (max-width: 600px) {
    .seat-card { height: 48px; padding: 2px 4px; }
    .seat-card.expanded { height: var(--expanded-height, 96px); }
    .seat-card .top-row { font-size: 10px; }
    .seat-card .bottom-row { font-size: 9px; }
    .hud-card { font-size: 10px; padding: 4px 8px; }
    .hud-card-right #hudBoardSum { font-size: 20px; }
    .chat-panel { width: 220px; height: 260px; right: 48px; }
    #rightControls .zoom-btn { width: 36px; height: 36px; font-size: 14px; }
}
@media (max-width: 400px) {
    .seat-card { height: 42px; }
    .seat-card.expanded { height: var(--expanded-height, 84px); }
    .seat-card .top-row { font-size: 9px; }
    .seat-card .bottom-row { font-size: 8px; }
    .hud-card { font-size: 9px; padding: 3px 6px; }
    .hud-card-right #hudBoardSum { font-size: 18px; }
}
</style>
</head>
<body>

<div class="app">
    <header>
        <div class="brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="3"/><line x1="3" y1="12" x2="21" y2="12"/><circle cx="8" cy="7.5" r="1" fill="currentColor"/><circle cx="16" cy="16.5" r="1" fill="currentColor"/></svg>
            <span id="brandName"><?= $gameName ?></span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <div id="headerStatus" class="pill">Lobby</div>
            <button id="btnHeaderLeave" class="danger hidden" style="padding:4px 10px; font-size:12px; border-radius:20px;" onclick="leaveTable()">Leave</button>
        </div>
    </header>

    <main>
        <section id="screenHome" class="screen">
            <div class="panel" style="max-width:800px; margin:auto; width:100%;">
                <div class="grid-2">
                    <div>
                        <h2>Join Table</h2>
                        <p class="desc">Select an open multiplayer table or join directly with a code.</p>
                        <div class="filter-bar">
                            <input type="text" id="tableFilter" placeholder="Filter by name..." style="flex:2;">
                            <select id="tableSort">
                                <option value="name">Sort by Name</option>
                                <option value="players">Sort by Players</option>
                                <option value="created">Sort by Created</option>
                            </select>
                            <label style="flex:0 0 auto;">
                                <input type="checkbox" id="showOnlyOpen" checked> Open tables
                            </label>
                        </div>
                        <div class="table-list-container" id="tableListContainer">
                            <div id="tableList" class="table-list"><div class="desc">Searching for open tables…</div></div>
                        </div>
                    </div>
                    <div>
                        <h2>Create Table</h2>
                        <p class="desc">Set up a new table with Custom Rule Engines.</p>
                        <label>Your Nickname</label>
                        <input id="createNick" maxlength="24" placeholder="e.g. MasterPip">
                        <label>Table Name</label>
                        <input id="tableName" maxlength="50" placeholder="Domino Showdown">
                        <div class="grid-2">
                            <div>
                                <label>Game Rules</label>
                                <select id="createRuleset">
                                    <option value="all_fives" selected>All Fives (Muggins)</option>
                                    <option value="all_threes">All Threes</option>
                                    <option value="fives_and_threes">Fives & Threes (UK)</option>
                                    <option value="block">Block Dominoes</option>
                                    <option value="draw">Draw Dominoes</option>
                                </select>
                            </div>
                            <div>
                                <label>Target Score</label>
                                <select id="targetScore">
                                    <option value="31">31 Points (Quick)</option>
                                    <option value="61">61 Points (Standard 5&3)</option>
                                    <option value="100" selected>100 Points</option>
                                    <option value="150">150 Points</option>
                                    <option value="250">250 Points</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin:12px 0; display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="createPrivate" style="width:auto; margin:0;">
                            <label for="createPrivate" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">🔒 Make Private (join by code only)</label>
                        </div>
                        <div class="actions">
                            <button onclick="createTable()" style="width:100%;">Create Table</button>
                        </div>
                        <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:20px 0;">
                        <h3>Join via Code</h3>
                        <input id="joinCode" maxlength="6" placeholder="CODE123" style="text-transform:uppercase; margin-bottom:8px;">
                        <input id="joinNick" maxlength="24" placeholder="Your Nickname">
                        <div class="actions">
                            <button id="joinBtn" class="secondary" onclick="joinTable()" style="width:100%;" disabled>Join by Code</button>
                        </div>
                    </div>
                </div>
                <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                    <button onclick="openAdminPanel()" style="background:rgba(0,0,0,0.4); border:1px solid rgba(255,255,255,0.2); color:var(--muted-text); padding:4px 12px; border-radius:20px; font-size:12px;">⚙️ Admin</button>
                </div>
            </div>
        </section>

        <section id="screenLobby" class="screen hidden">
            <div class="panel" style="max-width:550px; margin:auto; width:100%;">
                <h2 id="lobbyTitle">Table</h2>
                <p class="desc">
                    Code: <strong id="lobbyCodeDisplay" style="color:var(--gold);">---</strong>
                    <span id="lobbyPrivateIndicator" style="margin-left:6px; font-size:12px; color:var(--gold);">🔒</span>
                    · Rules: <strong id="lobbyRulesDisplay">All Fives</strong>
                    · Target: <strong id="lobbyTargetDisplay">100</strong>
                </p>
                
                <label>Seated Players</label>
                <div id="lobbySeats" class="table-list" style="margin-bottom:10px;"></div>
                <button id="btnAddCpu" onclick="addCpu()" class="secondary" style="margin-bottom: 20px; width: 100%; display:none;">+ Add CPU Bot</button>

                <div class="actions">
                    <button id="btnStartGame" onclick="startGame()" style="flex:1;">Start Match</button>
                    <button class="danger" onclick="leaveTable()">Leave Table</button>
                </div>
            </div>
        </section>

        <section id="screenGame" class="screen hidden" style="padding:0; overflow:hidden;">
            <div id="playerCards"></div>
            <div id="boardContainer">
                <div id="boardWorld"></div>
                <div class="hud-bar">
                    <div class="hud-card hud-card-left">
                        <div id="hudTurn" style="font-weight:800; color:var(--gold);">Turn: ---</div>
                        <div id="hudEvent" class="hud-event">---</div>
                        <div class="hud-meta">
                            <span>Round <strong id="hudRound">1</strong></span>
                        </div>
                    </div>
                    <div class="hud-card hud-card-right">
                        <div>Board Sum</div>
                        <div id="hudBoardSum">0</div>
                    </div>
                </div>
            </div>

            <div id="chatPanel" class="chat-panel hidden">
                <div class="chat-header">Table Chat <button onclick="toggleChat()">✕</button></div>
                <div id="chatMessages" class="chat-messages"></div>
                <div class="chat-input-area">
                    <input type="text" id="chatInput" placeholder="Message..." onkeypress="if(event.key==='Enter') sendChat()">
                    <button onclick="sendChat()">Send</button>
                </div>
            </div>

            <div class="hand-drawer">
                <div class="hand-actions">
                    <div style="display:flex; gap:6px; align-items:center;">
                        <button class="icon-btn" onclick="toggleChat()" title="Chat">💬 <span id="chatBadge" class="badge hidden">0</span></button>
                        <button id="btnVoice" class="icon-btn" onclick="toggleVoice()" title="Voice Chat">🎤</button>
                        <button id="btnVideo" class="icon-btn" onclick="toggleVideo()" title="Video Chat">📹</button>
                        <!-- New Rules Help Button -->
                        <button class="icon-btn" onclick="showRules()" title="Game Rules">❓</button>
                        <button class="icon-btn" onclick="toggleSettings()" title="Settings">⚙️</button>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button id="btnDraw" class="secondary" style="padding:4px 10px; font-size:11px;" onclick="drawTile()">Draw</button>
                        <button id="btnPass" class="secondary" style="padding:4px 10px; font-size:11px;" onclick="passTurn()">Pass</button>
                    </div>
                </div>
                <div id="handHint"></div>
                <div id="handList" class="hand-scroll"></div>
            </div>
        </section>
    </main>

    <div id="toast">Notice message</div>

    <!-- Video Chat Overlay -->
    <div id="modalVideoChat" class="modal-overlay hidden">
        <div class="panel" style="width: 100%; height: 100%; max-width: none; border-radius: 0; padding: 20px;">
            <div style="position: relative; width: 100%; height: 100%;">
                <div id="videoChatMain" style="width: 100%; height: 100%; background: #000; border-radius: 12px; display: flex; align-items: center; justify-content: center;"></div>
                <div id="videoChatMini" style="position: absolute; bottom: 80px; right: 20px; width: 150px; height: 100px; background: #333; border-radius: 8px; border: 2px solid #fff; overflow: hidden;"></div>
                <div style="position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px;">
                    <button onclick="toggleVoice()">Audio</button>
                    <button onclick="toggleVideo()">Video</button>
                    <button onclick="closeVideoChat()" class="danger">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Round End Popup -->
    <div id="modalRoundPopup" class="modal-overlay round-popup hidden">
        <div class="panel" style="max-width:400px; text-align:center;">
            <h2 id="roundPopupTitle" style="color:var(--gold);">Round Over</h2>
            <p id="roundPopupBody" class="desc" style="font-size:16px; margin-bottom: 24px;"></p>
            <button onclick="closeRoundPopup()" style="width:100%;">Close</button>
        </div>
    </div>

    <!-- Game Over Overlay -->
    <div id="modalGameOver" class="modal-overlay game-over hidden">
        <div class="panel" style="max-width:450px; text-align:center;">
            <h2 style="color:var(--gold); font-size:28px;">🏆 Match Winner</h2>
            <div class="winner-name" id="gameOverWinner" style="font-size:24px; font-weight:900; margin:12px 0;"></div>
            <div class="final-scores" id="gameOverScores"></div>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:center;">
                <button onclick="leaveTable()" class="secondary">Leave Table</button>
                <button id="btnResetGame" onclick="resetGame()" style="display:none;">🔄 Reset Game (Host)</button>
            </div>
        </div>
    </div>

    <!-- Settings Overlay -->
    <div id="modalSettings" class="modal-overlay hidden">
        <div class="panel" style="max-width:420px;">
            <h2 style="margin-top:0;">Settings</h2>
            
            <div style="margin-bottom:20px;">
                <label for="handScaleSlider" style="display:flex; justify-content:space-between; margin-bottom:4px;">Hand Size <span id="handScaleLabel">100%</span></label>
                <input type="range" id="handScaleSlider" min="50" max="200" value="100" step="5" style="width:100%;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label for="boardScaleSlider" style="display:flex; justify-content:space-between; margin-bottom:4px;">Board Tile Size <span id="boardScaleLabel">100%</span></label>
                <input type="range" id="boardScaleSlider" min="80" max="130" value="100" step="1" style="width:100%;">
            </div>

            <div style="margin-bottom:20px;">
                <label for="expandedCardSlider" style="display:flex; justify-content:space-between; margin-bottom:4px;">Expanded Player Card <span id="expandedCardLabel">112px</span></label>
                <input type="range" id="expandedCardSlider" min="80" max="200" value="112" step="2" style="width:100%;">
            </div>
            
            <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                <input type="checkbox" id="autoPlayToggle" checked style="width:auto; margin:0;">
                <label for="autoPlayToggle" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Auto‑play only move</label>
            </div>

            <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                <input type="checkbox" id="highlightLastTileToggle" checked style="width:auto; margin:0;">
                <label for="highlightLastTileToggle" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Highlight last played tile</label>
            </div>
            
            <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                <input type="checkbox" id="autoPanToggle" checked style="width:auto; margin:0;">
                <label for="autoPanToggle" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Auto‑pan to last tile</label>
            </div>

            <div style="margin-bottom:20px;">
                <label for="themeSelect" style="display:block; margin-bottom:4px;">Domino Theme</label>
                <select id="themeSelect">
                    <option value="classic">Classic (Default)</option>
                    <option value="dark-wood">Dark Wood</option>
                    <option value="ivory">Ivory</option>
                    <option value="blue-ocean">Blue Ocean</option>
                    <option value="red-velvet">Red Velvet</option>
                    <option value="green-forest">Green Forest</option>
                    <option value="gold-royal">Gold Royal</option>
                    <option value="monochrome">Monochrome</option>
                    <option value="neon">Neon</option>
                    <option value="pastel">Pastel</option>
                    <option value="night-sky">Night Sky</option>
                    <option value="autumn">Autumn</option>
                </select>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:4px;">Board Color</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <div class="color-swatch" data-color="green" style="width:30px; height:30px; border-radius:50%; background:#0e4032; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('green')"></div>
                    <div class="color-swatch" data-color="dark-green" style="width:30px; height:30px; border-radius:50%; background:#0d3226; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('dark-green')"></div>
                    <div class="color-swatch" data-color="blue" style="width:30px; height:30px; border-radius:50%; background:#0e2a42; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('blue')"></div>
                    <div class="color-swatch" data-color="red" style="width:30px; height:30px; border-radius:50%; background:#421010; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('red')"></div>
                    <div class="color-swatch" data-color="purple" style="width:30px; height:30px; border-radius:50%; background:#2a0e42; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('purple')"></div>
                    <div class="color-swatch" data-color="grey" style="width:30px; height:30px; border-radius:50%; background:#282828; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('grey')"></div>
                    <div class="color-swatch" data-color="black" style="width:30px; height:30px; border-radius:50%; background:#101010; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('black')"></div>
                    <div class="color-swatch" data-color="tan" style="width:30px; height:30px; border-radius:50%; background:#6a5a42; border:2px solid rgba(255,255,255,0.3); cursor:pointer;" onclick="applyBoardColor('tan')"></div>
                </div>
            </div>
            
            <p class="desc" style="font-size:12px; opacity:0.8; margin-bottom:20px;">Customize your table experience. Board tile size helps close gaps between dominoes. Expanded card height lets you see video feeds larger.</p>
            
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button onclick="resetSettingsToDefaults()" style="width:100%; background:var(--muted-text); color:white;">🔄 Reset to Defaults</button>
                <div id="settingsResetRow" style="display:none;">
                    <button onclick="resetGame()" style="width:100%; background:var(--danger); color:white;">🔄 Reset Match</button>
                </div>
                <button onclick="toggleSettings()" style="width:100%; background:var(--gold); color:#1a1408;">Done</button>
            </div>
        </div>
    </div>

    <!-- Rules Popup -->
    <div id="modalRules" class="modal-overlay hidden">
        <div class="panel" style="max-width:500px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h2 style="margin:0; color:var(--gold);">Game Rules</h2>
                <button onclick="closeRules()" style="background:none; border:none; color:var(--light-text); font-size:24px; padding:0 8px; box-shadow:none;">✕</button>
            </div>
            <div id="rulesContent" class="rules-content">
                <!-- Filled by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Admin Panel -->
    <div id="modalAdmin" class="modal-overlay hidden">
        <div class="panel" style="max-width:480px;">
            <h2 style="margin-top:0;">Admin Controls</h2>
            <div id="adminAuth" style="margin-bottom:16px;">
                <label>Admin Password <span style="font-weight:normal;opacity:0.7;font-size:11px;">(required to save)</span></label>
                <input type="password" id="adminPassword" placeholder="Enter password" style="margin-bottom:8px;">
                <button onclick="adminLogin()" style="width:100%;">Login</button>
                <button onclick="closeAdminPanel()" class="secondary" style="width:100%; margin-top:8px;">Cancel</button>
            </div>
            <div id="adminPanel" style="display:none;">
                <div style="margin-bottom:16px;">
                    <label>Game Name</label>
                    <input type="text" id="adminGameName" placeholder="Game Name" style="margin-bottom:4px;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex; justify-content:space-between;">Default Hand Size <span id="adminHandScaleLabel">100%</span></label>
                    <input type="range" id="adminHandScale" min="50" max="200" value="100" step="5" style="width:100%;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex; justify-content:space-between;">Default Board Tile Size <span id="adminBoardScaleLabel">100%</span></label>
                    <input type="range" id="adminBoardScale" min="80" max="130" value="100" step="1" style="width:100%;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:flex; justify-content:space-between;">Default Expanded Card <span id="adminExpandedCardLabel">112px</span></label>
                    <input type="range" id="adminExpandedCard" min="80" max="200" value="112" step="2" style="width:100%;">
                </div>
                <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                    <input type="checkbox" id="adminAutoPlay" checked style="width:auto; margin:0;">
                    <label for="adminAutoPlay" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Default Auto‑play</label>
                </div>
                <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                    <input type="checkbox" id="adminHighlightLastTile" checked style="width:auto; margin:0;">
                    <label for="adminHighlightLastTile" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Default Highlight Last Tile</label>
                </div>
                <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                    <input type="checkbox" id="adminAutoPanLastTile" checked style="width:auto; margin:0;">
                    <label for="adminAutoPanLastTile" style="margin:0; font-weight:600; cursor:pointer; text-transform:none; color:var(--light-text);">Default Auto-pan to Last Tile</label>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:flex; justify-content:space-between;">Clear inactive tables after (minutes, 0=disabled)</label>
                    <input type="number" id="adminTimeout" min="0" step="1" value="0" style="width:100%;">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:flex; justify-content:space-between;">Room List Height (px) <span id="adminRoomListLabel">320</span></label>
                    <input type="range" id="adminRoomListHeight" min="100" max="600" step="10" value="320" style="width:100%;">
                </div>

                <div style="margin-bottom:20px; border-top:1px solid rgba(255,255,255,0.2); padding-top:16px;">
                    <h3 style="font-size:14px; color:var(--gold);">Change Admin Password</h3>
                    <label>New Password</label>
                    <input type="password" id="adminNewPassword" placeholder="Enter new password" style="margin-bottom:8px;">
                    <label>Confirm Password</label>
                    <input type="password" id="adminConfirmPassword" placeholder="Confirm new password" style="margin-bottom:8px;">
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <button onclick="adminSaveSettings()" style="flex:1;">Save Settings</button>
                    <button onclick="adminResetAllGames()" style="flex:1; background:var(--danger); color:white;">Reset All Games</button>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <button onclick="adminDeleteAllGames()" style="flex:1; background:#b91c1c; color:white; border-color:#b91c1c;">🗑️ Delete All Tables</button>
                </div>
                <button onclick="closeAdminPanel()" style="width:100%; background:var(--muted-text); color:white;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const CLIENT_BUILD_VERSION = <?= json_encode(BUILD_VERSION) ?>;
const POLL_INTERVAL = 1000;
const ADMIN_DEFAULTS = <?= json_encode($adminSettings) ?>;

let code = localStorage.getItem('domino_code') || <?= json_encode($initialCode) ?> || '';
let state = null;
let selectedTileId = null;
let selectedEndpointId = null;
let pollTimer = null;
let tableListTimer = null;
let currentScreenId = 'screenHome';
let lastSeenSummaryId = '';
let isInitialLoad = true;

let tablesData = [];

// WebRTC
let peerConnections = new Map();
let localStream = null;
let audioEnabled = false;
let videoEnabled = false;

let volumeCtx = null;
let localAnalyser = null;
let remoteAnalysers = new Map();
let volumeInterval = null;

let remoteVideoElements = new Map();
let localVideoElement = null;

let lastRenderHash = '';

let boardScale = 1.0;
let boardX = 0, boardY = 0;
let isDragging = false;
let startX = 0, startY = 0;
let initialPinchDist = null, initialScale = 1.0;
let initialPinchCenter = { x: 0, y: 0 };
let initialBoardX = 0, initialBoardY = 0;
let previousTileCount = 0;

let chatUnreadCount = 0;
let lastChatId = 0;
let isChatOpen = false;

let highlightLastTile = true;

const qs = s => document.querySelector(s);

const dominoIcon = `<svg width="10" height="16" viewBox="0 0 24 44" style="vertical-align:middle; margin-right:2px;">
    <rect x="2" y="2" width="20" height="40" rx="4" fill="#f4efe2" stroke="#222" stroke-width="2"/>
    <line x1="2" y1="22" x2="22" y2="22" stroke="#222" stroke-width="2"/>
    <circle cx="12" cy="12" r="2.5" fill="#dc2626"/>
    <circle cx="12" cy="32" r="2.5" fill="#1d4ed8"/>
</svg>`;

const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
function playSound(type) {
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain); gain.connect(audioCtx.destination);
    const now = audioCtx.currentTime;
    if (type === 'play') {
        osc.frequency.setValueAtTime(300, now); osc.frequency.exponentialRampToValueAtTime(120, now + 0.08);
        gain.gain.setValueAtTime(0.4, now); gain.gain.exponentialRampToValueAtTime(0.01, now + 0.08);
        osc.start(now); osc.stop(now + 0.08);
    } else if (type === 'draw') {
        osc.frequency.setValueAtTime(150, now); osc.frequency.exponentialRampToValueAtTime(400, now + 0.1);
        gain.gain.setValueAtTime(0.2, now); gain.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
        osc.start(now); osc.stop(now + 0.1);
    } else if (type === 'turn') {
        osc.frequency.setValueAtTime(523.25, now); osc.frequency.setValueAtTime(659.25, now + 0.08);
        gain.gain.setValueAtTime(0.325, now); gain.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
        osc.start(now); osc.stop(now + 0.2);
    }
}

function triggerHaptic() { if (navigator.vibrate) navigator.vibrate(25); }
function triggerTurnHaptic() { if (navigator.vibrate) navigator.vibrate(120); }

function showToast(msg) {
    const t = qs('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(showToast.t);
    showToast.t = setTimeout(() => t.classList.remove('show'), 2500);
}

function showScreen(id) {
    currentScreenId = id;
    ['screenHome', 'screenLobby', 'screenGame'].forEach(s => qs('#' + s).classList.toggle('hidden', s !== id));
    if (id === 'screenHome') { qs('#btnHeaderLeave').classList.add('hidden'); startTableListAutoRefresh(); } 
    else { qs('#btnHeaderLeave').classList.remove('hidden'); stopTableListAutoRefresh(); }
}

function showRoundPopup(summary) {
    const popup = qs('#modalRoundPopup');
    const title = qs('#roundPopupTitle');
    const body = qs('#roundPopupBody');
    
    if (!summary || summary.winnerNick === 'None') {
        title.textContent = 'Round Blocked – No Points';
        body.textContent = 'All players are blocked. No one scores this hand.';
    } else {
        title.textContent = summary.winnerNick + ' wins the hand!';
        body.textContent = `${summary.isBlock ? 'Blocked' : 'Hand emptied'} – Pip total: ${summary.pipTotal}. Awarded +${summary.awarded} pts.`;
    }
    
    popup.classList.remove('hidden');
}

function closeRoundPopup() {
    qs('#modalRoundPopup').classList.add('hidden');
}

function showGameOver(winner, winnerScore, players) {
    const overlay = qs('#modalGameOver');
    qs('#gameOverWinner').textContent = `${winner} wins with ${winnerScore} points!`;
    const scoresContainer = qs('#gameOverScores');
    scoresContainer.innerHTML = players.map(p => `
        <div class="score-row ${p.nickname === winner ? 'winner' : ''}">
            <span>${p.nickname}</span>
            <span style="font-weight:bold;">${p.score} pts</span>
        </div>
    `).join('');
    overlay.classList.remove('hidden');
    
    const me = state.players.find(p => p.isMe);
    const isHost = me && state.players[0].id === me.id;
    const resetBtn = qs('#btnResetGame');
    const settingsReset = qs('#settingsResetRow');
    if (isHost) {
        resetBtn.style.display = 'inline-flex';
        settingsReset.style.display = 'block';
    } else {
        resetBtn.style.display = 'none';
        settingsReset.style.display = 'none';
    }
}

function hideGameOver() {
    qs('#modalGameOver').classList.add('hidden');
}

async function resetGame() {
    if (!confirm('Reset the match for all players?')) return;
    try {
        await apiPost({ action: 'reset_game', code });
        hideGameOver();
        await poll();
        showToast('Match reset. Ready to start again.');
    } catch(e) {}
}

function toggleChat() {
    isChatOpen = !isChatOpen;
    const panel = qs('#chatPanel');
    if (isChatOpen) {
        panel.classList.remove('hidden');
        chatUnreadCount = 0;
        qs('#chatBadge').classList.add('hidden');
        const box = qs('#chatMessages');
        box.scrollTop = box.scrollHeight;
        qs('#chatInput').focus();
    } else {
        panel.classList.add('hidden');
    }
}

async function sendChat() {
    const input = qs('#chatInput');
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    try {
        await apiPost({ action: 'chat', code, message: msg });
        await poll();
    } catch(e) { }
}

function renderChat() {
    if (!state || !state.chat) return;
    const box = qs('#chatMessages');
    let hasNew = false;
    let html = '';
    state.chat.forEach(msg => {
        html += `
            <div class="chat-msg ${msg.isMe ? 'me' : ''}">
                <strong>${msg.nickname} <span style="float:right; font-weight:normal; opacity:0.6">${msg.time}</span></strong>
                ${msg.message}
            </div>`;
        if (msg.id > lastChatId) {
            hasNew = true;
            lastChatId = msg.id;
            if (!isChatOpen && !msg.isMe) chatUnreadCount++;
        }
    });
    box.innerHTML = html;
    if (hasNew) box.scrollTop = box.scrollHeight;
    const badge = qs('#chatBadge');
    if (chatUnreadCount > 0) {
        badge.textContent = chatUnreadCount > 9 ? '9+' : chatUnreadCount;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

// ---- Theme and Board Color ----
function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('dominoTheme', theme);
    const sel = document.getElementById('themeSelect');
    if (sel) sel.value = theme;
}

function applyBoardColor(color) {
    document.documentElement.setAttribute('data-board', color);
    localStorage.setItem('boardColor', color);
    document.querySelectorAll('.color-swatch').forEach(el => {
        el.style.borderColor = (el.dataset.color === color) ? 'var(--gold)' : 'rgba(255,255,255,0.3)';
        el.style.borderWidth = (el.dataset.color === color) ? '3px' : '2px';
    });
}

function loadThemeAndBoard() {
    const savedTheme = localStorage.getItem('dominoTheme') || 'classic';
    applyTheme(savedTheme);
    const savedBoard = localStorage.getItem('boardColor') || 'green';
    applyBoardColor(savedBoard);
    const sel = document.getElementById('themeSelect');
    if (sel) sel.value = savedTheme;
}

// ---- Rules Popup ----
function getRulesText(ruleset) {
    const rules = {
        'all_fives': {
            title: 'All Fives (Muggins)',
            desc: 'Score points when the sum of the open ends is a multiple of 5.',
            details: [
                'Each player starts with 5–7 tiles (depending on player count).',
                'The player with the highest double (or highest pip total) opens the round.',
                'After each play, the board sum is calculated (sum of all open end values).',
                'If the board sum is divisible by 5, the player scores that sum.',
                'If a player cannot play, they must draw from the boneyard (if available).',
                'If the boneyard is empty, the turn passes.',
                'The round ends when a player plays all their tiles or the game is blocked.',
                'The winner of the hand scores the total pip count of opponents\' remaining tiles (rounded to nearest 5).',
                'First player to reach the target score wins the match.'
            ]
        },
        'all_threes': {
            title: 'All Threes',
            desc: 'Score points when the sum of the open ends is a multiple of 3.',
            details: [
                'Each player starts with 5–7 tiles.',
                'The highest double/pip opens the round.',
                'After each play, if the board sum is divisible by 3, the player scores that sum.',
                'Players can draw from the boneyard if unable to play (except in Block variant).',
                'When a hand ends (tiles emptied or blocked), the winning player scores the opponents\' pip total rounded to nearest 3.',
                'Match target score wins the game.'
            ]
        },
        'fives_and_threes': {
            title: 'Fives & Threes (UK)',
            desc: 'Score points based on multiples of 5 and 3 on the open ends.',
            details: [
                'Each player starts with 5–7 tiles.',
                'Opening: highest double or highest pip total.',
                'After each play, the board sum is evaluated:',
                '  - For each multiple of 5, score 1 point per 5 (e.g., sum=15 → 3 pts).',
                '  - For each multiple of 3, score 1 point per 3 (e.g., sum=9 → 3 pts).',
                '  - Both can be combined (e.g., sum=15 is multiple of both → 3+5=8 pts).',
                'If no play is possible, draw (if available) or pass.',
                'End of hand: winner scores opponents\' pip total divided by 5 (rounded).',
                'First to target score wins.'
            ]
        },
        'block': {
            title: 'Block Dominoes',
            desc: 'No drawing – players must pass if they cannot play.',
            details: [
                'Each player starts with 5–7 tiles.',
                'Highest double/pip opens.',
                'Players take turns placing matching tiles on open ends.',
                'If a player cannot play, they pass their turn.',
                'Play continues until a player empties their hand or all players are blocked.',
                'When a hand ends, the winner scores the sum of all remaining pips in opponents\' hands.',
                'No points for board sums during play – all scoring happens at hand end.',
                'First to target score wins.'
            ]
        },
        'draw': {
            title: 'Draw Dominoes',
            desc: 'Players draw from the boneyard if unable to play, but may pass if empty.',
            details: [
                'Each player starts with 5–7 tiles.',
                'Highest double/pip opens.',
                'If you cannot play, you must draw one tile from the boneyard.',
                'If the boneyard is empty, you pass your turn.',
                'Play continues until someone empties their hand or all players are blocked.',
                'Winner scores the total pips remaining in opponents\' hands.',
                'No in‑play scoring – all points come from hand end.',
                'Target score wins the match.'
            ]
        }
    };
    return rules[ruleset] || rules['all_fives'];
}

function showRules() {
    if (!state) {
        showToast('No game active.');
        return;
    }
    const ruleset = state.ruleset || 'all_fives';
    const rule = getRulesText(ruleset);
    const container = document.getElementById('rulesContent');
    let detailsHtml = rule.details.map(d => `<li>${d}</li>`).join('');
    container.innerHTML = `
        <h3>${rule.title}</h3>
        <p style="color:var(--muted-text);">${rule.desc}</p>
        <ul>${detailsHtml}</ul>
        <div class="rule-note">🎯 Target: ${state.target} points · ${state.players.length} players</div>
    `;
    document.getElementById('modalRules').classList.remove('hidden');
}

function closeRules() {
    document.getElementById('modalRules').classList.add('hidden');
}

// ---- End Rules ----

function renderTableList() {
    const container = qs('#tableList');
    const filterText = (qs('#tableFilter').value || '').toLowerCase().trim();
    const sortKey = qs('#tableSort').value;
    const showOnlyOpen = qs('#showOnlyOpen').checked;

    let filtered = tablesData.filter(g => {
        if (showOnlyOpen && (g.status !== 'lobby' || g.players >= g.maxPlayers)) return false;
        if (filterText && !g.name.toLowerCase().includes(filterText)) return false;
        return true;
    });

    if (sortKey === 'name') {
        filtered.sort((a,b) => a.name.localeCompare(b.name));
    } else if (sortKey === 'players') {
        filtered.sort((a,b) => a.players - b.players || a.name.localeCompare(b.name));
    } else if (sortKey === 'created') {
        filtered.sort((a,b) => a.createdAt - b.createdAt);
    }

    if (!filtered.length) {
        container.innerHTML = `<div class="desc">No tables match your criteria.</div>`;
        return;
    }

    container.innerHTML = filtered.map(g => `
        <div class="table-item">
            <div class="table-info">
                <strong>${g.name}</strong>
                <div class="sub">${g.rulesetName} · ${g.players}/${g.maxPlayers} Players · Target ${g.target}</div>
            </div>
            <button class="${g.players >= g.maxPlayers || g.status !== 'lobby' ? 'secondary' : ''}"
                ${g.players >= g.maxPlayers || g.status !== 'lobby' ? 'disabled' : ''}
                onclick="prefillJoin('${g.code}')">
                ${g.status === 'lobby' ? 'Join' : 'In Game'}
            </button>
        </div>
    `).join('');
}

async function fetchTables() {
    try {
        const res = await apiGet('?api=list');
        if (res && res.games) {
            tablesData = res.games;
            renderTableList();
        } else {
            tablesData = [];
            renderTableList();
        }
    } catch(e) {
        tablesData = [];
        renderTableList();
    }
}

function startTableListAutoRefresh() { stopTableListAutoRefresh(); fetchTables(); tableListTimer = setInterval(() => { if (currentScreenId === 'screenHome') fetchTables(); }, 1500); }
function stopTableListAutoRefresh() { if (tableListTimer) clearInterval(tableListTimer); tableListTimer = null; }

function toggleSettings() {
    qs('#modalSettings').classList.toggle('hidden');
    if (state && state.players) {
        const me = state.players.find(p => p.isMe);
        const isHost = me && state.players[0].id === me.id;
        const savedScale = localStorage.getItem('handScale');
        if (savedScale) {
            const val = parseInt(savedScale, 10);
            document.getElementById('handScaleSlider').value = val;
            applyHandScale(val);
        } else {
            const def = parseInt(ADMIN_DEFAULTS.hand_scale_default || '100');
            document.getElementById('handScaleSlider').value = def;
            applyHandScale(def);
        }
        const savedBoardScale = localStorage.getItem('boardTileScale');
        if (savedBoardScale) {
            const val = parseInt(savedBoardScale, 10);
            document.getElementById('boardScaleSlider').value = val;
            applyBoardTileScale(val);
        } else {
            const def = parseInt(ADMIN_DEFAULTS.board_tile_scale_default || '100');
            document.getElementById('boardScaleSlider').value = def;
            applyBoardTileScale(def);
        }
        const savedExpanded = localStorage.getItem('expandedCardHeight');
        if (savedExpanded) {
            const val = parseInt(savedExpanded, 10);
            document.getElementById('expandedCardSlider').value = val;
            applyExpandedCardHeight(val);
        } else {
            const def = parseInt(ADMIN_DEFAULTS.expanded_card_height_default || '112');
            document.getElementById('expandedCardSlider').value = def;
            applyExpandedCardHeight(def);
        }
        const savedAuto = localStorage.getItem('autoPlayEnabled');
        if (savedAuto !== null) {
            document.getElementById('autoPlayToggle').checked = savedAuto !== 'false';
        } else {
            document.getElementById('autoPlayToggle').checked = (ADMIN_DEFAULTS.auto_play_default || '1') === '1';
        }
        const savedHighlight = localStorage.getItem('highlightLastTile');
        if (savedHighlight !== null) {
            document.getElementById('highlightLastTileToggle').checked = savedHighlight !== 'false';
        } else {
            document.getElementById('highlightLastTileToggle').checked = (ADMIN_DEFAULTS.highlight_last_tile_default || '1') === '1';
        }
        const savedAutoPan = localStorage.getItem('autoPanEnabled');
        if (savedAutoPan !== null) {
            document.getElementById('autoPanToggle').checked = savedAutoPan !== 'false';
        } else {
            document.getElementById('autoPanToggle').checked = (ADMIN_DEFAULTS.auto_pan_last_tile_default || '1') === '1';
        }
        
        highlightLastTile = document.getElementById('highlightLastTileToggle').checked;
        if (state && state.board && state.board.tiles.length > 0) {
            renderBoard();
        }
        qs('#settingsResetRow').style.display = isHost ? 'block' : 'none';

        const themeSel = document.getElementById('themeSelect');
        if (themeSel) themeSel.value = localStorage.getItem('dominoTheme') || 'classic';
        const boardColor = localStorage.getItem('boardColor') || 'green';
        document.querySelectorAll('.color-swatch').forEach(el => {
            el.style.borderColor = (el.dataset.color === boardColor) ? 'var(--gold)' : 'rgba(255,255,255,0.3)';
            el.style.borderWidth = (el.dataset.color === boardColor) ? '3px' : '2px';
        });
    }
}

function applyHandScale(percent) {
    const scale = percent / 100;
    const drawer = document.querySelector('.hand-drawer');
    if (drawer) {
        drawer.style.setProperty('--hand-scale', scale);
    }
    document.getElementById('handScaleLabel').textContent = percent + '%';
    localStorage.setItem('handScale', String(percent));
}

function applyBoardTileScale(percent) {
    const scale = percent / 100;
    const world = document.getElementById('boardWorld');
    if (world) {
        world.style.setProperty('--board-tile-scale', scale);
    }
    document.getElementById('boardScaleLabel').textContent = percent + '%';
    localStorage.setItem('boardTileScale', String(percent));
}

function applyExpandedCardHeight(px, shouldReCenter = false) {
    const container = document.getElementById('playerCards');
    if (container) {
        container.style.setProperty('--expanded-height', px + 'px');
    }
    document.getElementById('expandedCardLabel').textContent = px + 'px';
    localStorage.setItem('expandedCardHeight', String(px));
    
    if (shouldReCenter && typeof centerOnLastTile === 'function' && typeof state !== 'undefined' && state) {
        centerOnLastTile();
    }
}

function applyHighlightLastTile(enable) {
    highlightLastTile = enable;
    localStorage.setItem('highlightLastTile', enable ? 'true' : 'false');
    if (state && state.board && state.board.tiles.length > 0) {
        renderBoard();
    }
}

function resetSettingsToDefaults() {
    if (!confirm('Are you sure you want to reset all settings to defaults?')) return;
    
    const handDef = parseInt(ADMIN_DEFAULTS.hand_scale_default || '100');
    const boardDef = parseInt(ADMIN_DEFAULTS.board_tile_scale_default || '100');
    const expDef = parseInt(ADMIN_DEFAULTS.expanded_card_height_default || '112');
    const autoDef = (ADMIN_DEFAULTS.auto_play_default || '1') === '1';
    const highlightDef = (ADMIN_DEFAULTS.highlight_last_tile_default || '1') === '1';
    const autoPanDef = (ADMIN_DEFAULTS.auto_pan_last_tile_default || '1') === '1';
    
    localStorage.removeItem('handScale');
    localStorage.removeItem('boardTileScale');
    localStorage.removeItem('expandedCardHeight');
    localStorage.removeItem('autoPlayEnabled');
    localStorage.removeItem('highlightLastTile');
    localStorage.removeItem('autoPanEnabled');
    localStorage.removeItem('dominoTheme');
    localStorage.removeItem('boardColor');
    
    document.getElementById('handScaleSlider').value = handDef;
    applyHandScale(handDef);
    document.getElementById('boardScaleSlider').value = boardDef;
    applyBoardTileScale(boardDef);
    document.getElementById('expandedCardSlider').value = expDef;
    applyExpandedCardHeight(expDef);
    document.getElementById('autoPlayToggle').checked = autoDef;
    localStorage.setItem('autoPlayEnabled', autoDef ? 'true' : 'false');
    document.getElementById('highlightLastTileToggle').checked = highlightDef;
    applyHighlightLastTile(highlightDef);
    document.getElementById('autoPanToggle').checked = autoPanDef;
    localStorage.setItem('autoPanEnabled', autoPanDef ? 'true' : 'false');
    
    applyTheme('classic');
    applyBoardColor('green');
    showToast('Reset to admin defaults');
}

document.addEventListener('DOMContentLoaded', () => {
    const handSlider = document.getElementById('handScaleSlider');
    if (handSlider) {
        handSlider.addEventListener('input', function() {
            applyHandScale(parseInt(this.value, 10));
        });
    }
    const boardSlider = document.getElementById('boardScaleSlider');
    if (boardSlider) {
        boardSlider.addEventListener('input', function() {
            applyBoardTileScale(parseInt(this.value, 10));
        });
    }
    const expandedSlider = document.getElementById('expandedCardSlider');
    if (expandedSlider) {
        expandedSlider.addEventListener('input', function() {
            applyExpandedCardHeight(parseInt(this.value, 10), true);
        });
    }
    
    const autoPlayToggle = document.getElementById('autoPlayToggle');
    if (autoPlayToggle) {
        autoPlayToggle.checked = localStorage.getItem('autoPlayEnabled') !== 'false';
        autoPlayToggle.addEventListener('change', () => {
            localStorage.setItem('autoPlayEnabled', autoPlayToggle.checked ? 'true' : 'false');
        });
    }
    
    const highlightToggle = document.getElementById('highlightLastTileToggle');
    if (highlightToggle) {
        const savedHighlight = localStorage.getItem('highlightLastTile');
        highlightToggle.checked = savedHighlight !== null ? savedHighlight !== 'false' : (ADMIN_DEFAULTS.highlight_last_tile_default || '1') === '1';
        highlightToggle.addEventListener('change', () => {
            applyHighlightLastTile(highlightToggle.checked);
        });
    }
    
    const autoPanToggle = document.getElementById('autoPanToggle');
    if (autoPanToggle) {
        const savedAutoPan = localStorage.getItem('autoPanEnabled');
        if (savedAutoPan !== null) {
            autoPanToggle.checked = savedAutoPan !== 'false';
        } else {
            autoPanToggle.checked = (ADMIN_DEFAULTS.auto_pan_last_tile_default || '1') === '1';
        }
        autoPanToggle.addEventListener('change', () => {
            localStorage.setItem('autoPanEnabled', autoPanToggle.checked ? 'true' : 'false');
        });
    }

    const themeSelect = document.getElementById('themeSelect');
    if (themeSelect) {
        themeSelect.addEventListener('change', function() {
            applyTheme(this.value);
        });
    }

    const savedHandScale = localStorage.getItem('handScale');
    if (savedHandScale) {
        const val = parseInt(savedHandScale, 10);
        document.getElementById('handScaleSlider').value = val;
        applyHandScale(val);
    } else {
        const def = parseInt(ADMIN_DEFAULTS.hand_scale_default || '100');
        document.getElementById('handScaleSlider').value = def;
        applyHandScale(def);
    }
    const savedBoardScale = localStorage.getItem('boardTileScale');
    if (savedBoardScale) {
        const val = parseInt(savedBoardScale, 10);
        document.getElementById('boardScaleSlider').value = val;
        applyBoardTileScale(val);
    } else {
        const def = parseInt(ADMIN_DEFAULTS.board_tile_scale_default || '100');
        document.getElementById('boardScaleSlider').value = def;
        applyBoardTileScale(def);
    }
    const savedExpanded = localStorage.getItem('expandedCardHeight');
    if (savedExpanded) {
        const val = parseInt(savedExpanded, 10);
        document.getElementById('expandedCardSlider').value = val;
        applyExpandedCardHeight(val);
    } else {
        const def = parseInt(ADMIN_DEFAULTS.expanded_card_height_default || '112');
        document.getElementById('expandedCardSlider').value = def;
        applyExpandedCardHeight(def);
    }
    const savedAuto = localStorage.getItem('autoPlayEnabled');
    if (savedAuto !== null) {
        document.getElementById('autoPlayToggle').checked = savedAuto !== 'false';
    } else {
        document.getElementById('autoPlayToggle').checked = (ADMIN_DEFAULTS.auto_play_default || '1') === '1';
    }
    const savedHighlight = localStorage.getItem('highlightLastTile');
    highlightLastTile = savedHighlight !== null ? savedHighlight !== 'false' : (ADMIN_DEFAULTS.highlight_last_tile_default || '1') === '1';
    document.getElementById('highlightLastTileToggle').checked = highlightLastTile;

    const savedAutoPan = localStorage.getItem('autoPanEnabled');
    if (savedAutoPan !== null) {
        document.getElementById('autoPanToggle').checked = savedAutoPan !== 'false';
    } else {
        document.getElementById('autoPanToggle').checked = (ADMIN_DEFAULTS.auto_pan_last_tile_default || '1') === '1';
    }

    loadThemeAndBoard();
    document.getElementById('brandName').textContent = ADMIN_DEFAULTS.game_name || 'Domino Tree';

    document.getElementById('tableFilter').addEventListener('input', renderTableList);
    document.getElementById('tableSort').addEventListener('change', renderTableList);
    document.getElementById('showOnlyOpen').addEventListener('change', renderTableList);

    const roomSlider = document.getElementById('adminRoomListHeight');
    const roomLabel = document.getElementById('adminRoomListLabel');
    if (roomSlider) {
        roomSlider.value = ADMIN_DEFAULTS.room_list_height_default || 320;
        roomLabel.textContent = roomSlider.value;
        roomSlider.addEventListener('input', function() {
            roomLabel.textContent = this.value;
        });
    }
});

// ---- WebRTC ----

function getMyId() {
    if (!state || !state.players) return '';
    const me = state.players.find(p => p.isMe);
    return me ? me.id : '';
}

function addTrackToAll(track) {
    for (const [remoteId, pc] of peerConnections) {
        const exists = pc.getSenders().some(s => s.track === track);
        if (!exists) {
            pc.addTrack(track, localStream);
        }
    }
}

function removeTrackFromAll(track) {
    for (const [remoteId, pc] of peerConnections) {
        const sender = pc.getSenders().find(s => s.track === track);
        if (sender) {
            pc.removeTrack(sender);
        }
    }
}

function setVideoDirection(direction) {
    for (const [remoteId, pc] of peerConnections) {
        const transceivers = pc.getTransceivers();
        for (const tr of transceivers) {
            if (tr.receiver.track.kind === 'video') {
                tr.direction = direction;
                break;
            }
        }
    }
}

function setAudioDirection(direction) {
    for (const [remoteId, pc] of peerConnections) {
        const transceivers = pc.getTransceivers();
        for (const tr of transceivers) {
            if (tr.receiver.track.kind === 'audio') {
                tr.direction = direction;
                break;
            }
        }
    }
}

async function renegotiateAll() {
    for (const [remoteId, pc] of peerConnections) {
        if (pc.signalingState === 'stable' || pc.signalingState === 'have-local-offer') {
            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                sendSignal(remoteId, { type: 'offer', offer });
            } catch(e) { console.warn('Renegotiate error', e); }
        }
    }
}

function ensureConnections() {
    if (!state || (state.status !== 'playing' && state.status !== 'lobby')) return;
    const myId = getMyId();
    if (!myId) return;

    state.players.forEach(p => {
        if (p.id !== myId && !p.isCpu) {
            if (!peerConnections.has(p.id)) {
                createPeerConnection(p.id);
            }
            const pc = peerConnections.get(p.id);
            if (pc && (pc.signalingState === 'new' || pc.signalingState === 'stable') && !pc._offerSent && myId < p.id) {
                createOffer(pc, p.id);
                pc._offerSent = true;
            }
        }
    });
}

function rebuildConnections() {
    for (const [id, pc] of peerConnections) {
        pc.close();
        const videoEl = remoteVideoElements.get(id);
        if (videoEl) {
            videoEl.srcObject = null;
            videoEl.remove();
            remoteVideoElements.delete(id);
        }
    }
    peerConnections.clear();
    remoteAnalysers.clear();

    ensureConnections();
    updateMediaIndicators();
}

function createPeerConnection(remoteId) {
    if (peerConnections.has(remoteId)) return;
    const pc = new RTCPeerConnection({
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
    });

    pc.addTransceiver('audio', { direction: 'recvonly' });
    pc.addTransceiver('video', { direction: 'recvonly' });

    // Removed: automatic local stream tracking addition here to prevent accidental audio/video sharing
    
    pc.onicecandidate = (event) => {
        if (event.candidate) {
            sendSignal(remoteId, { type: 'candidate', candidate: event.candidate });
        }
    };

    pc.ontrack = (event) => {
        if (event.track.kind === 'audio') {
            const audio = new Audio();
            audio.srcObject = event.streams[0];
            audio.autoplay = true;
            // Force play as it might be blocked by browser policy without user interaction
            audio.play().catch(e => console.warn('Audio play error, will attempt on interaction', e));

            if (volumeCtx) {
                try {
                    const source = volumeCtx.createMediaStreamSource(event.streams[0]);
                    const analyser = volumeCtx.createAnalyser();
                    analyser.fftSize = 256;
                    source.connect(analyser);
                    remoteAnalysers.set(remoteId, analyser);
                } catch(e) { /* ignore */ }
            }
        }
        else if (event.track.kind === 'video') {
            let videoEl = remoteVideoElements.get(remoteId);
            if (!videoEl) {
                videoEl = document.createElement('video');
                videoEl.autoplay = true;
                videoEl.playsInline = true;
                videoEl.muted = true;
                remoteVideoElements.set(remoteId, videoEl);
            }
            videoEl.srcObject = event.streams[0];
            videoEl.play().catch(e => console.warn('Video play error', e));
            attachVideoToSeat(remoteId);
        }
    };

    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'connected' || pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
            updateMediaIndicators();
        }
        if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
            pc.close();
            peerConnections.delete(remoteId);
            remoteAnalysers.delete(remoteId);
            const videoEl = remoteVideoElements.get(remoteId);
            if (videoEl) {
                videoEl.srcObject = null;
                videoEl.remove();
                remoteVideoElements.delete(remoteId);
            }
            const card = document.querySelector(`.seat-card[data-player-id="${remoteId}"]`);
            if (card) card.classList.remove('has-video');
            updateMediaIndicators();
        }
    };

    peerConnections.set(remoteId, pc);
    return pc;
}

function attachVideoToSeat(playerId) {
    const videoEl = remoteVideoElements.get(playerId);
    if (!videoEl) return;
    const card = document.querySelector(`.seat-card[data-player-id="${playerId}"]`);
    if (!card) return;
    let bg = card.querySelector('.video-bg');
    if (!bg) {
        bg = document.createElement('div');
        bg.className = 'video-bg';
        card.prepend(bg);
    }
    bg.innerHTML = '';
    bg.appendChild(videoEl);
    card.classList.add('has-video');
}

function setupLocalVideo() {
    if (!localStream) return;
    if (!localVideoElement) {
        localVideoElement = document.createElement('video');
        localVideoElement.autoplay = true;
        localVideoElement.playsInline = true;
        localVideoElement.muted = true;
    }
    localVideoElement.srcObject = localStream;
    localVideoElement.play().catch(e => console.warn('Local video play error', e));
    const myId = getMyId();
    if (myId) {
        attachLocalVideoToSeat(myId);
    }
}

function attachLocalVideoToSeat(playerId) {
    if (!localVideoElement) return;
    const card = document.querySelector(`.seat-card[data-player-id="${playerId}"]`);
    if (!card) return;
    let bg = card.querySelector('.video-bg');
    if (!bg) {
        bg = document.createElement('div');
        bg.className = 'video-bg';
        card.prepend(bg);
    }
    bg.innerHTML = '';
    bg.appendChild(localVideoElement);
    card.classList.add('has-video');
}

function removeLocalVideo() {
    if (localVideoElement) {
        localVideoElement.srcObject = null;
        localVideoElement.remove();
        localVideoElement = null;
    }
    document.querySelectorAll('.video-bg').forEach(bg => {
        if (bg.querySelector('video')) {
            bg.innerHTML = '';
        }
    });
    const myId = getMyId();
    if (myId) {
        const card = document.querySelector(`.seat-card[data-player-id="${myId}"]`);
        if (card) card.classList.remove('has-video');
    }
}

function updateMediaIndicators() {
    const myId = getMyId();

    document.querySelectorAll('.seat-card').forEach(card => {
        const playerId = card.dataset.playerId;
        const micIcon = card.querySelector('.mic-icon');
        if (!playerId || !micIcon) return;

        let show = false;
        if (playerId === myId) {
            show = audioEnabled;
        } else {
            show = remoteAnalysers.has(playerId);
        }
        micIcon.style.display = show ? 'inline-block' : 'none';
        if (!show) {
            micIcon.style.filter = 'brightness(1)';
            micIcon.style.textShadow = '0 0 8px rgba(16,185,129,0.6)';
        }
    });

    const videoBtn = qs('#btnVideo');
    if (videoEnabled) {
        videoBtn.classList.add('active');
        videoBtn.style.color = '#10b981';
    } else {
        videoBtn.classList.remove('active');
        videoBtn.style.color = 'var(--muted-text)';
    }

    const voiceBtn = qs('#btnVoice');
    if (audioEnabled) {
        voiceBtn.classList.add('active');
        voiceBtn.style.color = '#10b981';
    } else {
        voiceBtn.classList.remove('active');
        voiceBtn.style.color = 'var(--muted-text)';
    }
}

function updateVolumes() {
    if (!audioEnabled) return;

    if (localAnalyser) {
        const dataArray = new Uint8Array(localAnalyser.fftSize);
        localAnalyser.getByteTimeDomainData(dataArray);
        let sum = 0;
        for (let i = 0; i < dataArray.length; i++) {
            const value = (dataArray[i] - 128) / 128;
            sum += value * value;
        }
        const rms = Math.sqrt(sum / dataArray.length);
        const volume = Math.min(rms * 3, 1);
        const brightness = 0.8 + volume * 2.2;
        const btn = qs('#btnVoice');
        if (btn) {
            btn.style.filter = `brightness(${brightness})`;
        }
    }

    for (const [playerId, analyser] of remoteAnalysers) {
        const dataArray = new Uint8Array(analyser.fftSize);
        analyser.getByteTimeDomainData(dataArray);
        let sum = 0;
        for (let i = 0; i < dataArray.length; i++) {
            const value = (dataArray[i] - 128) / 128;
            sum += value * value;
        }
        const rms = Math.sqrt(sum / dataArray.length);
        const volume = Math.min(rms * 3, 1);
        const brightness = 0.8 + volume * 2.2;
        const seatCard = document.querySelector(`.seat-card[data-player-id="${playerId}"]`);
        if (seatCard) {
            const micIcon = seatCard.querySelector('.mic-icon');
            if (micIcon) {
                micIcon.style.filter = `brightness(${brightness})`;
                micIcon.style.textShadow = `0 0 ${10 + volume * 20}px rgba(16,185,129,${0.3 + volume * 0.7})`;
            }
        }
    }
}

function sendSignal(to, data) {
    apiPost({ action: 'signal', code, to, data: JSON.stringify(data) }).catch(e => console.warn('Signal error', e));
}

function handleSignal(from, signal) {
    let pc = peerConnections.get(from);
    if (!pc) {
        pc = createPeerConnection(from);
    }

    const handleOffer = (offer) => {
        pc.setRemoteDescription(new RTCSessionDescription(offer));
        pc.createAnswer().then(answer => {
            pc.setLocalDescription(answer);
            sendSignal(from, { type: 'answer', answer });
        }).catch(e => console.warn('Answer error', e));
    };

    if (signal.type === 'offer') {
        handleOffer(signal.offer);
    } else if (signal.type === 'answer') {
        pc.setRemoteDescription(new RTCSessionDescription(signal.answer)).catch(e => console.warn('Set remote desc error', e));
    } else if (signal.type === 'candidate') {
        pc.addIceCandidate(new RTCIceCandidate(signal.candidate)).catch(e => console.warn('Add candidate error', e));
    }
}

function createOffer(pc, remoteId) {
    pc.createOffer().then(offer => {
        pc.setLocalDescription(offer);
        sendSignal(remoteId, { type: 'offer', offer });
    }).catch(e => console.warn('Offer error', e));
}

function processSignals(signals) {
    if (!signals) return;
    signals.forEach(sig => {
        try {
            const data = JSON.parse(sig.data);
            handleSignal(sig.from, data);
        } catch(e) {}
    });
}

async function toggleVoice() {
    if (audioEnabled) {
        audioEnabled = false;
        if (localStream) {
            const audioTracks = localStream.getAudioTracks();
            audioTracks.forEach(t => {
                removeTrackFromAll(t);
                t.stop();
                localStream.removeTrack(t);
            });
        }
        setAudioDirection('recvonly');
        await renegotiateAll();
        updateMediaIndicators();
        showToast('Voice chat off');
    } else {
        try {
            const audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            if (!localStream) {
                localStream = audioStream;
            } else {
                audioStream.getAudioTracks().forEach(track => localStream.addTrack(track));
            }
            audioEnabled = true;
            localStream.getAudioTracks().forEach(t => addTrackToAll(t));
            setAudioDirection('sendrecv');
            await renegotiateAll();
            updateMediaIndicators();
            showToast('Voice chat on');
        } catch(e) {
            audioEnabled = false;
            showToast('Microphone access denied');
        }
    }
}

function openVideoChat(playerId) {
    const overlay = qs('#modalVideoChat');
    const mainArea = qs('#videoChatMain');
    const miniArea = qs('#videoChatMini');
    
    // Move main video
    const card = document.querySelector(`.seat-card[data-player-id="${playerId}"]`);
    if (card) {
        const video = card.querySelector('video');
        if (video) {
            mainArea.appendChild(video);
            video.style.width = '100%';
            video.style.height = '100%';
            video.style.objectFit = 'contain';
            video.play().catch(e => console.warn('Video play error in overlay', e));
        }
    }
    
    // Move mini video
    const myId = getMyId();
    if (myId && myId !== playerId) {
        const myCard = document.querySelector(`.seat-card[data-player-id="${myId}"]`);
        if (myCard) {
            const myVideo = myCard.querySelector('video');
            if (myVideo) {
                miniArea.appendChild(myVideo);
                myVideo.style.width = '100%';
                myVideo.style.height = '100%';
                myVideo.style.objectFit = 'cover';
                myVideo.play().catch(e => console.warn('Local video play error in overlay', e));
            }
        }
    }
    
    overlay.classList.remove('hidden');
}

function closeVideoChat() {
    const overlay = qs('#modalVideoChat');
    const mainArea = qs('#videoChatMain');
    const miniArea = qs('#videoChatMini');
    
    // Explicitly remove video elements from containers so renderSeats can re-attach them
    mainArea.innerHTML = '';
    miniArea.innerHTML = '';
    
    overlay.classList.add('hidden');
}

async function toggleVideo() {
    if (videoEnabled) {
        videoEnabled = false;
        if (localStream) {
            const videoTracks = localStream.getVideoTracks();
            videoTracks.forEach(t => {
                removeTrackFromAll(t);
                t.stop();
                localStream.removeTrack(t);
            });
        }
        setVideoDirection('recvonly');
        removeLocalVideo();
        await renegotiateAll();
        updateMediaIndicators();
        showToast('Video chat off');
        return;
    }

    try {
        const videoStream = await navigator.mediaDevices.getUserMedia({ video: true });
        if (!localStream) {
            localStream = videoStream;
        } else {
            videoStream.getVideoTracks().forEach(track => localStream.addTrack(track));
        }
        videoEnabled = true;

        setupLocalVideo();

        localStream.getVideoTracks().forEach(t => addTrackToAll(t));
        setVideoDirection('sendrecv');
        await renegotiateAll();
        updateMediaIndicators();
        showToast('Video chat on');
    } catch(e) {
        videoEnabled = false;
        showToast('Camera access denied');
    }
}

// ---- Admin Panel ----
function openAdminPanel() {
    qs('#modalAdmin').classList.remove('hidden');
    qs('#adminAuth').style.display = 'block';
    qs('#adminPanel').style.display = 'none';
    qs('#adminPassword').value = '';
    qs('#adminGameName').value = ADMIN_DEFAULTS.game_name || 'Domino Tree';
    qs('#adminHandScale').value = ADMIN_DEFAULTS.hand_scale_default || '100';
    qs('#adminBoardScale').value = ADMIN_DEFAULTS.board_tile_scale_default || '100';
    qs('#adminExpandedCard').value = ADMIN_DEFAULTS.expanded_card_height_default || '112';
    qs('#adminAutoPlay').checked = (ADMIN_DEFAULTS.auto_play_default || '1') === '1';
    qs('#adminHighlightLastTile').checked = (ADMIN_DEFAULTS.highlight_last_tile_default || '1') === '1';
    qs('#adminAutoPanLastTile').checked = (ADMIN_DEFAULTS.auto_pan_last_tile_default || '1') === '1';
    qs('#adminTimeout').value = ADMIN_DEFAULTS.inactivity_timeout_minutes || '0';
    const roomHeight = parseInt(ADMIN_DEFAULTS.room_list_height_default || '320');
    qs('#adminRoomListHeight').value = roomHeight;
    qs('#adminRoomListLabel').textContent = roomHeight;
    qs('#adminNewPassword').value = '';
    qs('#adminConfirmPassword').value = '';
    updateAdminSliderLabels();
}

function closeAdminPanel() {
    qs('#modalAdmin').classList.add('hidden');
}

function updateAdminSliderLabels() {
    document.getElementById('adminHandScaleLabel').textContent = document.getElementById('adminHandScale').value + '%';
    document.getElementById('adminBoardScaleLabel').textContent = document.getElementById('adminBoardScale').value + '%';
    document.getElementById('adminExpandedCardLabel').textContent = document.getElementById('adminExpandedCard').value + 'px';
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('adminHandScale').addEventListener('input', updateAdminSliderLabels);
    document.getElementById('adminBoardScale').addEventListener('input', updateAdminSliderLabels);
    document.getElementById('adminExpandedCard').addEventListener('input', updateAdminSliderLabels);
});

async function adminLogin() {
    const pass = document.getElementById('adminPassword').value;
    try {
        const res = await apiPost({ action: 'admin_login', password: pass });
        if (res.ok) {
            document.getElementById('adminAuth').style.display = 'none';
            document.getElementById('adminPanel').style.display = 'block';
            showToast('Admin access granted');
        } else {
            showToast('Invalid password');
        }
    } catch(e) {
        showToast('Login failed');
    }
}

async function adminSaveSettings() {
    const password = document.getElementById('adminPassword').value.trim();
    if (!password) {
        showToast('Please enter the admin password before saving.');
        return;
    }

    try {
        const data = {
            action: 'admin_update_settings',
            password: password,
            game_name: document.getElementById('adminGameName').value.trim() || 'Domino Tree',
            hand_scale_default: document.getElementById('adminHandScale').value,
            board_tile_scale_default: document.getElementById('adminBoardScale').value,
            expanded_card_height_default: document.getElementById('adminExpandedCard').value,
            auto_play_default: document.getElementById('adminAutoPlay').checked ? '1' : '0',
            highlight_last_tile_default: document.getElementById('adminHighlightLastTile').checked ? '1' : '0',
            auto_pan_last_tile_default: document.getElementById('adminAutoPanLastTile').checked ? '1' : '0',
            inactivity_timeout_minutes: document.getElementById('adminTimeout').value.trim() || '0',
            room_list_height_default: document.getElementById('adminRoomListHeight').value
        };

        const newPass = document.getElementById('adminNewPassword').value.trim();
        const confirmPass = document.getElementById('adminConfirmPassword').value.trim();
        if (newPass || confirmPass) {
            if (newPass !== confirmPass) {
                showToast('New passwords do not match');
                return;
            }
            if (newPass.length < 4) {
                showToast('Password must be at least 4 characters');
                return;
            }
            data.new_password = newPass;
            data.confirm_password = confirmPass;
        }

        const res = await apiPost(data);
        if (res.ok) {
            showToast('Settings saved');
            location.reload();
        } else {
            showToast('Failed to save: ' + (res.error || 'unknown error'));
        }
    } catch (e) {
        showToast('Error: ' + e.message);
    }
}

async function adminResetAllGames() {
    if (!confirm('⚠️ This will reset ALL active games to lobby state. Continue?')) return;
    try {
        const res = await apiPost({ action: 'admin_reset_all_games', password: document.getElementById('adminPassword').value || 'adminpass5' });
        if (res.ok) {
            showToast('All games have been reset');
            closeAdminPanel();
        }
    } catch(e) {}
}

async function adminDeleteAllGames() {
    if (!confirm('⚠️ This will DELETE ALL game tables, chat history, and signals. This cannot be undone. Continue?')) return;
    try {
        const res = await apiPost({ action: 'admin_delete_all_games', password: document.getElementById('adminPassword').value || 'adminpass5' });
        if (res.ok) {
            showToast('All tables have been deleted');
            closeAdminPanel();
        }
    } catch(e) {}
}

// ---- End Admin Panel ----

(function initJoinButton() {
    const joinCode = document.getElementById('joinCode');
    const joinNick = document.getElementById('joinNick');
    const joinBtn = document.getElementById('joinBtn');

    function updateJoinButton() {
        if (joinCode.value.trim() && joinNick.value.trim()) {
            joinBtn.disabled = false;
            joinBtn.classList.remove('secondary');
            joinBtn.classList.add('primary');
        } else {
            joinBtn.disabled = true;
            joinBtn.classList.remove('primary');
            joinBtn.classList.add('secondary');
        }
    }

    joinCode.addEventListener('input', updateJoinButton);
    joinNick.addEventListener('input', updateJoinButton);
    updateJoinButton();
})();

function prefillJoin(c) {
    const joinCode = qs('#joinCode');
    joinCode.value = c;
    joinCode.dispatchEvent(new Event('input', { bubbles: true }));
    qs('#joinNick').focus();
}

async function apiPost(data) {
    try {
        const r = await fetch(`?_v=${CLIENT_BUILD_VERSION}&_t=${Date.now()}`, { method: 'POST', body: new URLSearchParams(data), cache: 'no-store' });
        const j = await r.json();
        if (j.buildVersion && j.buildVersion !== CLIENT_BUILD_VERSION) location.reload();
        if (!j.ok) throw new Error(j.error || 'Server error');
        return j;
    } catch(e) {
        if (!e.message.includes('fetch')) showToast(e.message);
        throw e;
    }
}

async function apiGet(url) {
    const cacheBustUrl = url + (url.includes('?') ? '&' : '?') + `_v=${CLIENT_BUILD_VERSION}&_t=${Date.now()}`;
    const r = await fetch(cacheBustUrl, { cache: 'no-store' });
    if (!r.ok) return { ok: false };
    const j = await r.json();
    if (j.buildVersion && j.buildVersion !== CLIENT_BUILD_VERSION) location.reload();
    return j;
}

function getPipGridHTML(count) {
    const pipColors = { 1:'pip-1', 2:'pip-2', 3:'pip-3', 4:'pip-4', 5:'pip-5', 6:'pip-6' };
    const pipSpots = { 0:[], 1:[4], 2:[0,8], 3:[0,4,8], 4:[0,2,6,8], 5:[0,2,4,6,8], 6:[0,2,3,5,6,8] }[count] || [];
    const dots = Array.from({ length: 9 }, (_, i) => pipSpots.includes(i) ? `<i class="pip-dot ${pipColors[count]}"></i>` : `<i></i>`).join('');
    return `<div class="pip-grid">${dots}</div>`;
}

function renderDominoHTML(tile, isHorizontal = false, extraClass = '', flipHalves = false) {
    const topOrLeft = flipHalves ? tile.b : tile.a;
    const bottomOrRight = flipHalves ? tile.a : tile.b;
    return `
    <div class="domino-tile ${isHorizontal ? 'horizontal' : ''} ${extraClass}" data-id="${tile.id}">
        <div class="tile-half">${getPipGridHTML(topOrLeft)}</div>
        <div class="tile-half">${getPipGridHTML(bottomOrRight)}</div>
    </div>`;
}

window.fetchTables = fetchTables;

window.addEventListener('DOMContentLoaded', () => {
    const savedNick = localStorage.getItem('domino_nick');
    if (savedNick) {
        qs('#createNick').value = savedNick;
        const joinNick = qs('#joinNick');
        joinNick.value = savedNick;
        joinNick.dispatchEvent(new Event('input', { bubbles: true }));
    }

    const resumeAudioOnFirstInteraction = () => {
        if (volumeCtx && volumeCtx.state === 'suspended') {
            volumeCtx.resume();
        }
        // Also try to play all remote audio elements
        document.querySelectorAll('audio').forEach(a => a.play().catch(()=>{}));
        document.body.removeEventListener('click', resumeAudioOnFirstInteraction);
    };
    document.body.addEventListener('click', resumeAudioOnFirstInteraction);
});

async function createTable() {
    try {
        const nick = qs('#createNick').value;
        const privateVal = document.getElementById('createPrivate').checked ? '1' : '0';
        const j = await apiPost({
            action: 'create',
            nickname: nick,
            name: qs('#tableName').value,
            target: qs('#targetScore').value,
            ruleset: qs('#createRuleset').value,
            private: privateVal
        });
        localStorage.setItem('domino_nick', nick);
        enterRoom(j.code);
    } catch(e) { }
}

async function joinTable() {
    try {
        const nick = qs('#joinNick').value;
        const j = await apiPost({ action: 'join', code: qs('#joinCode').value, nickname: nick });
        localStorage.setItem('domino_nick', nick);
        enterRoom(j.code);
    } catch(e) {}
}

async function addCpu() {
    try { await apiPost({ action: 'add_cpu', code }); poll(); } catch(e) {}
}

async function leaveTable() {
    if (code) try { await apiPost({ action: 'leave', code }); } catch(e) {}
    code = ''; state = null; lastRenderHash = ''; lastSeenSummaryId = ''; isInitialLoad = true;
    localStorage.removeItem('domino_code');
    clearTimeout(pollTimer);
    closeRoundPopup();
    hideGameOver();
    for (const [id, pc] of peerConnections) pc.close();
    peerConnections.clear();
    remoteAnalysers.clear();
    remoteVideoElements.clear();
    if (localStream) {
        localStream.getTracks().forEach(t => t.stop());
        localStream = null;
    }
    if (volumeInterval) {
        clearInterval(volumeInterval);
        volumeInterval = null;
    }
    if (volumeCtx) {
        volumeCtx.close();
        volumeCtx = null;
    }
    localAnalyser = null;
    audioEnabled = false;
    videoEnabled = false;
    removeLocalVideo();
    showScreen('screenHome');
}

function enterRoom(c) {
    code = c.toUpperCase();
    localStorage.setItem('domino_code', code);
    showScreen('screenLobby');
    poll();
}

function centerOnLastTile() {
    if (!state || !state.board || !state.board.tiles || state.board.tiles.length === 0) return;
    
    const autoPanToggle = document.getElementById('autoPanToggle');
    const autoPan = autoPanToggle ? autoPanToggle.checked : true;
    
    if (!autoPan && state.board.tiles.length > 1) return;

    const world = qs('#boardWorld');
    const tiles = state.board.tiles;
    const lastNode = tiles[tiles.length - 1];
    
    const newX = (1500 - lastNode.x) * boardScale;
    const newY = (1500 - lastNode.y) * boardScale;
    
    world.classList.remove('no-transition');
    boardX = newX;
    boardY = newY;
    updateBoardTransform();
}

function updateBoardTransform() {
    const world = qs('#boardWorld');
    world.style.transform = `translate(-50%, -50%) translate(${boardX}px, ${boardY}px) scale(${boardScale})`;
}

async function poll() {
    clearTimeout(pollTimer);
    if (!code) return;
    try {
        const res = await apiGet('?api=state&code=' + encodeURIComponent(code));
        if (res && res.ok && res.state) {
            const prevTilesCount = state?.board?.tiles?.length || 0;
            const prevTurn = state?.turnPlayerId;
            state = res.state;
            
            if (state.signals) {
                processSignals(state.signals);
            }

            if (state.status === 'playing' || state.status === 'lobby') {
                ensureConnections();
            }

            if (isInitialLoad) {
                lastSeenSummaryId = state.lastRoundSummary ? state.lastRoundSummary.summaryId : '';
                isInitialLoad = false;
            }
            
            const stateCopy = JSON.parse(JSON.stringify(state));
            if (stateCopy && stateCopy.players) {
                stateCopy.players.forEach(p => { delete p.lastSeen; delete p.connected; });
            }
            const currentRenderHash = JSON.stringify(stateCopy);

            if (currentRenderHash !== lastRenderHash) {
                render();
                lastRenderHash = currentRenderHash;
            } else {
                renderSeats();
            }

            const newTileCount = state.board.tiles.length;
            if (newTileCount > prevTilesCount) {
                setTimeout(() => centerOnLastTile(), 100);
            }
            if (prevTilesCount > 0 && newTileCount > prevTilesCount) playSound('play');
            if (prevTurn && prevTurn !== state.turnPlayerId && state.turnPlayerId === (state.players.find(p=>p.isMe)?.id)) {
                playSound('turn'); triggerTurnHaptic();
            }
        } else if (res && !res.ok) {
            leaveTable(); showToast("Game session expired."); return; 
        }
    } catch(e) {} finally {
        if (code) pollTimer = setTimeout(poll, POLL_INTERVAL);
    }
}

function render() {
    qs('#headerStatus').textContent = state.status === 'lobby' ? 'Lobby' : (state.status === 'playing' ? 'Live Match' : 'Finished');

    if (state.status === 'lobby') {
        showScreen('screenLobby');
        qs('#lobbyTitle').textContent = state.name;
        qs('#lobbyCodeDisplay').textContent = state.code;
        qs('#lobbyTargetDisplay').textContent = state.target;
        qs('#lobbyRulesDisplay').textContent = state.rulesetName;
        const privIndicator = qs('#lobbyPrivateIndicator');
        if (state.isPrivate) {
            privIndicator.style.display = 'inline';
        } else {
            privIndicator.style.display = 'none';
        }
        qs('#lobbySeats').innerHTML = state.players.map((p, i) => `
            <div class="table-item">
                <div>
                    <strong>${p.nickname} ${p.isMe ? '(You)' : ''}</strong>
                    <div style="font-size:11px; color:var(--muted-text);">${i === 0 ? 'Host' : (p.isCpu ? 'CPU Bot' : 'Player')}</div>
                </div>
                <div style="font-size:12px; color:${p.connected || p.isCpu ? 'var(--gold)' : 'var(--muted-text)'};">
                    ${p.isCpu ? '🤖 Ready' : (p.connected ? '● Online' : '○ Away')}
                </div>
            </div>
        `).join('');
        const me = state.players.find(p => p.isMe);
        const isHost = me && state.players[0].id === me.id;
        qs('#btnStartGame').disabled = !isHost || state.players.length < 2;
        qs('#btnAddCpu').style.display = (isHost && state.players.length < state.maxPlayers) ? 'block' : 'none';
        
        lastSeenSummaryId = '';
        closeRoundPopup();
        hideGameOver();
        return;
    }

    showScreen('screenGame');
    renderSeats(); renderBoard(); renderHand(); renderChat();

    qs('#hudRound').textContent = state.round;
    let sum = 0;
    if (state.board.endpoints) {
        state.board.endpoints.forEach(ep => { sum += parseInt(ep.value, 10); });
    }
    qs('#hudBoardSum').textContent = sum;
    
    let isScoring = false;
    if (state.ruleset === 'all_fives') isScoring = (sum > 0 && sum % 5 === 0);
    else if (state.ruleset === 'all_threes') isScoring = (sum > 0 && sum % 3 === 0);
    else if (state.ruleset === 'fives_and_threes') isScoring = (sum > 0 && (sum % 5 === 0 || sum % 3 === 0));
    
    if (isScoring) {
        qs('#hudBoardSum').style.color = '#10b981';
        qs('#hudBoardSum').style.textShadow = '0 0 12px rgba(16,185,129,0.6)';
    } else {
        qs('#hudBoardSum').style.color = '#22c55e';
        qs('#hudBoardSum').style.textShadow = 'none';
    }

    const activePlayer = state.players.find(p => p.id === state.turnPlayerId);
    qs('#hudTurn').textContent = activePlayer ? (activePlayer.isMe ? 'YOUR TURN' : 'Turn: ' + activePlayer.nickname) : '---';
    qs('#hudEvent').textContent = state.message || '';

    const anyPlayable = state.myHand.some(t => !state.board.tiles.length ?
        t.id === state.requiredStartTile : state.board.endpoints.some(e => t.a === e.value || t.b === e.value));

    if (state.ruleset === 'block') {
        qs('#btnDraw').style.display = 'none';
        qs('#btnPass').disabled = !state.canAct || anyPlayable;
    } else {
        qs('#btnDraw').style.display = 'inline-block';
        qs('#btnDraw').disabled = !state.canAct || state.boneyardCount === 0 || anyPlayable;
        qs('#btnPass').disabled = !state.canAct || state.boneyardCount > 0 || anyPlayable;
    }

    if (state.status === 'finished' && state.winner) {
        closeRoundPopup();
        showGameOver(state.winner, state.winnerScore, state.players);
    } else {
        if (state.lastRoundSummary && state.lastRoundSummary.summaryId !== lastSeenSummaryId) {
            lastSeenSummaryId = state.lastRoundSummary.summaryId;
            showRoundPopup(state.lastRoundSummary);
        }
    }
}

function renderSeats() {
    const container = qs('#playerCards');
    const players = state.players;
    const existingCards = new Map();
    container.querySelectorAll('.seat-card').forEach(card => {
        const id = card.dataset.playerId;
        if (id) existingCards.set(id, card);
    });

    const currentIds = new Set(players.map(p => p.id));
    for (const [id, card] of existingCards) {
        if (!currentIds.has(id)) {
            card.remove();
            existingCards.delete(id);
        }
    }

    players.forEach((p) => {
        let card = existingCards.get(p.id);
        if (!card) {
            card = document.createElement('div');
            card.className = 'seat-card';
            card.dataset.playerId = p.id;
            const bg = document.createElement('div');
            bg.className = 'video-bg';
            card.prepend(bg);
            const content = document.createElement('div');
            content.className = 'content';
            content.innerHTML = `
                <div class="top-row">
                    <span class="p-name"></span>
                    <span class="mic-icon">🎤</span>
                </div>
                <div class="bottom-row">
                    <span class="p-meta"></span>
                </div>
                <div class="third-row" style="margin-top:2px;">
                    <span class="tile-count-badge">${dominoIcon} <span class="tile-count"></span></span>
                </div>
            `;
            card.appendChild(content);
            card.dataset.lastTapTime = '0';
            card.addEventListener('click', (e) => {
                const currentTime = new Date().getTime();
                const lastTapTime = parseInt(card.dataset.lastTapTime);
                const tapLength = currentTime - lastTapTime;
                
                if (tapLength < 300 && tapLength > 0) {
                    clearTimeout(card.dataset.tapTimeout);
                    openVideoChat(p.id);
                } else {
                    card.dataset.tapTimeout = setTimeout(() => {
                        if (card.classList.contains('expanded')) {
                            card.classList.remove('expanded');
                        } else {
                            card.classList.add('expanded');
                        }
                    }, 300);
                }
                card.dataset.lastTapTime = currentTime.toString();
            });
            container.appendChild(card);
            existingCards.set(p.id, card);
        }

        const nameSpan = card.querySelector('.p-name');
        const metaSpan = card.querySelector('.p-meta');
        const countSpan = card.querySelector('.tile-count');
        if (nameSpan) nameSpan.textContent = p.nickname;
        if (metaSpan) {
            metaSpan.textContent = `${p.score}/${state.target}`;
            // FIX: Retain both classes so the element can be found again
            metaSpan.className = 'p-meta score-badge';
        }
        if (countSpan) countSpan.textContent = p.count;

        card.classList.toggle('active', p.id === state.turnPlayerId);
    });

    for (const [playerId, videoEl] of remoteVideoElements) {
        // Fix: Do not re-attach if it's already in the video chat overlay
        if (videoEl.parentElement && videoEl.parentElement.id.startsWith('videoChat')) continue;

        const card = container.querySelector(`.seat-card[data-player-id="${playerId}"]`);
        if (card) {
            let bg = card.querySelector('.video-bg');
            if (!bg) {
                bg = document.createElement('div');
                bg.className = 'video-bg';
                card.prepend(bg);
            }
            if (!bg.contains(videoEl)) {
                bg.innerHTML = '';
                videoEl.style.objectFit = 'cover'; // Enforce cropping
                bg.appendChild(videoEl);
                card.classList.add('has-video');
                // Ensure stream resumes
                videoEl.play().catch(e => console.warn('Video resume error in seat', e));
            }

        }
    }

    if (localVideoElement && videoEnabled) {
        // Fix: Do not re-attach if it's already in the video chat overlay
        if (localVideoElement.parentElement && localVideoElement.parentElement.id.startsWith('videoChat')) return;

        const myId = getMyId();
        if (myId) {
            const card = container.querySelector(`.seat-card[data-player-id="${myId}"]`);
            if (card) {
                let bg = card.querySelector('.video-bg');
                if (!bg) {
                    bg = document.createElement('div');
                    bg.className = 'video-bg';
                    card.prepend(bg);
                }
                if (!bg.contains(localVideoElement)) {
                    bg.innerHTML = '';
                    localVideoElement.style.objectFit = 'cover'; // Enforce cropping
                    bg.appendChild(localVideoElement);
                    card.classList.add('has-video');
                    // Ensure stream resumes
                    localVideoElement.play().catch(e => console.warn('Local video resume error in seat', e));
                }
            }
        }
    }

    updateMediaIndicators();
}

function renderHand() {
    const container = qs('#handList');
    const hint = qs('#handHint');
    if (!container) return;

    const endpointVals = (state.board.endpoints || []).map(e => e.value);
    container.innerHTML = state.myHand.map(tile => {
        const isPlayable = state.canAct && (!state.board.tiles.length ?
            tile.id === state.requiredStartTile : (endpointVals.includes(tile.a) || endpointVals.includes(tile.b)));
        const isSelected = selectedTileId === tile.id;
        return renderDominoHTML(tile, false, `${isPlayable ? 'playable' : ''} ${isSelected ? 'selected' : ''}`);
    }).join('');
    container.querySelectorAll('.domino-tile').forEach(el => {
        el.addEventListener('pointerdown', (ev) => ev.stopPropagation());
        el.addEventListener('click', () => handleHandTileClick(el.dataset.id));
    });
    const anyPlayable = state.myHand.some(t => !state.board.tiles.length ?
        t.id === state.requiredStartTile : state.board.endpoints.some(e => t.a === e.value || t.b === e.value));
    if (hint) {
        hint.textContent = state.canAct ?
            (!state.board.tiles.length ? 'Play opening domino' : (anyPlayable ? 'Tap tile then end' : (state.ruleset === 'block' ? 'No match — Pass' : 'No match — draw/pass'))) : '';
    }
}

function handleHandTileClick(tileId) {
    if (!state.canAct) return;
    triggerHaptic();
    try {
        if (selectedEndpointId) { 
            playTile(tileId, selectedEndpointId); 
            return; 
        }
        selectedTileId = (selectedTileId === tileId) ? null : tileId;
        lastRenderHash = ''; 
        renderHand();
        if (selectedTileId) {
            const tile = state.myHand.find(t => t.id === selectedTileId);
            const matchingEnds = state.board.endpoints.filter(e => tile && (tile.a === e.value || tile.b === e.value));
            const autoPlayEnabled = localStorage.getItem('autoPlayEnabled') !== 'false';
            
            if (autoPlayEnabled && matchingEnds.length === 1) {
                playTile(selectedTileId, matchingEnds[0].id);
            } else if (!state.board.tiles.length && matchingEnds.length === 0) {
                playTile(selectedTileId, '');
            } else if (matchingEnds.length === 0 && state.board.tiles.length > 0) {
                showToast('No matching end on board');
                selectedTileId = null;
                renderHand();
            }
        }
    } catch (e) {
        console.warn('Tile click handler error:', e);
        if (selectedTileId && !state.board.tiles.length) {
            playTile(selectedTileId, '');
        }
    }
}

function renderBoard() {
    const world = qs('#boardWorld');
    world.innerHTML = '';
    const tiles = state.board.tiles || [];
    const endpoints = state.board.endpoints || [];

    if (!tiles.length) {
        world.innerHTML = `<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:var(--muted-text); font-weight:700;">Waiting for opening play...</div>`;
        return;
    }

    const tLayer = document.createElement('div');
    tLayer.id = 'boardTilesLayer';
    tLayer.style.position = 'absolute';
    tLayer.style.inset = '0';
    tLayer.style.filter = 'drop-shadow(0 10px 20px rgba(0,0,0,0.5))';
    world.appendChild(tLayer);

    const eLayer = document.createElement('div');
    eLayer.id = 'boardEndpointsLayer';
    eLayer.style.position = 'absolute';
    eLayer.style.inset = '0';
    world.appendChild(eLayer);

    const lastIndex = highlightLastTile && tiles.length > 0 ? tiles.length - 1 : -1;

    tiles.forEach((node, index) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'board-node';
        wrapper.style.left = node.x + 'px'; 
        wrapper.style.top = node.y + 'px';
        
        const isHorizontal = node.w > node.h;
        let flipHalves = false;
        if (isHorizontal) {
            flipHalves = (node.dx === 1) ? node.orientation === 1 : node.orientation === 0;
        } else {
            flipHalves = (node.dy === 1) ? node.orientation === 1 : node.orientation === 0;
        }
        
        let extraClass = '';
        if (index === lastIndex) {
            extraClass = 'last-played';
        }
        wrapper.innerHTML = renderDominoHTML(node.tile, isHorizontal, extraClass, flipHalves);
        tLayer.appendChild(wrapper);
    });

    endpoints.forEach(ep => {
        const el = document.createElement('div');
        el.className = `tree-endpoint ${selectedEndpointId === ep.id ? 'selected' : ''}`;
        
        const epOffset = 26; 
        el.style.left = (ep.x + ep.dx * epOffset) + 'px'; 
        el.style.top = (ep.y + ep.dy * epOffset) + 'px';
        
        el.textContent = ep.value;
        
        el.addEventListener('pointerdown', (ev) => ev.stopPropagation());

        el.addEventListener('click', (ev) => {
            ev.stopPropagation(); triggerHaptic();
            if (selectedTileId) playTile(selectedTileId, ep.id);
            else {
                selectedEndpointId = (selectedEndpointId === ep.id) ? null : ep.id;
                lastRenderHash = ''; 
                renderBoard();
                if (selectedEndpointId) showToast(`Selected end ${ep.value} — tap tile in hand`);
            }
        });
        eLayer.appendChild(el);
    });
    updateBoardTransform();
}

const container = qs('#boardContainer');

container.addEventListener('pointerdown', e => {
    // Only allow left-click (button 0) for dragging
    if (e.button !== 0) return;
    isDragging = true;
    startX = e.clientX - boardX;
    startY = e.clientY - boardY;
    container.setPointerCapture(e.pointerId);
    qs('#boardWorld').classList.add('no-transition');
});
container.addEventListener('pointermove', e => {
    // Only pan if dragging is active
    if (!isDragging || initialPinchDist !== null) return;
    boardX = e.clientX - startX;
    boardY = e.clientY - startY;
    updateBoardTransform();
});
// Need to find the pointerup listener, it's missing in the previous read
container.addEventListener('pointerup', e => {
    if (!isDragging) return;
    isDragging = false;
    container.releasePointerCapture(e.pointerId);
    qs('#boardWorld').classList.remove('no-transition');
});
container.addEventListener('wheel', e => {
    e.preventDefault();
    const zoomSpeed = 0.001;
    const delta = -e.deltaY;
    const factor = Math.pow(1.1, delta / 100);
    
    const newScale = Math.min(Math.max(0.35, boardScale * factor), 2.5);
    
    // Zoom relative to mouse cursor
    const rect = container.getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;
    
    // Calculate new position
    const dx = (mouseX - (window.innerWidth/2 + boardX)) / boardScale;
    const dy = (mouseY - (window.innerHeight/2 + boardY)) / boardScale;
    
    boardX -= dx * (newScale - boardScale);
    boardY -= dy * (newScale - boardScale);
    
    boardScale = newScale;
    updateBoardTransform();
}, { passive: false });

function getPinchCenter(e) {
    return {
        x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
        y: (e.touches[0].clientY + e.touches[1].clientY) / 2
    };
}

container.addEventListener('touchstart', e => {
    if (e.touches.length === 2) { 
        initialPinchDist = getTouchDistance(e); 
        initialScale = boardScale; 
        initialPinchCenter = getPinchCenter(e);
        initialBoardX = boardX;
        initialBoardY = boardY;
        qs('#boardWorld').classList.add('no-transition');
    }
});

container.addEventListener('touchmove', e => {
    if (e.touches.length === 2 && initialPinchDist) {
        e.preventDefault();
        const dist = getTouchDistance(e);
        if (dist) {
            const newScale = Math.min(Math.max(0.35, initialScale * (dist / initialPinchDist)), 2.5);
            const currentCenter = getPinchCenter(e);
            const rect = container.getBoundingClientRect();
            const cx = rect.left + rect.width / 2;
            const cy = rect.top + rect.height / 2;
            const panX = currentCenter.x - initialPinchCenter.x;
            const panY = currentCenter.y - initialPinchCenter.y;
            const dx = initialPinchCenter.x - cx;
            const dy = initialPinchCenter.y - cy;
            boardX = panX + dx - ((dx - initialBoardX) / initialScale) * newScale;
            boardY = panY + dy - ((dy - initialBoardY) / initialScale) * newScale;
            boardScale = newScale;
            updateBoardTransform();
        }
    }
}, { passive: false });

container.addEventListener('touchend', e => { 
    if (e.touches.length < 2) {
        initialPinchDist = null; 
        if (e.touches.length === 1) {
            startX = e.touches[0].clientX - boardX;
            startY = e.touches[0].clientY - boardY;
        }
    }
});

function getTouchDistance(e) {
    if (e.touches && e.touches.length >= 2) return Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
    return null;
}

async function playTile(tileId, endpointId) {
    if (!state.canAct) return;
    try { await apiPost({ action: 'play', code, tile: tileId, endpoint: endpointId }); selectedTileId = null; selectedEndpointId = null; await poll(); } catch(e) {}
}

async function drawTile() {
    try { await apiPost({ action: 'draw', code }); playSound('draw'); triggerHaptic(); await poll(); } catch(e) {}
}

async function passTurn() {
    try { await apiPost({ action: 'pass', code }); triggerHaptic(); await poll(); } catch(e) {}
}

async function startGame() {
    try { await apiPost({ action: 'start', code }); await poll(); } catch(e) {}
}

window.addEventListener('load', () => { showScreen(code ? 'screenLobby' : 'screenHome'); if (code) { poll(); } });
</script>
</body>
</html>
