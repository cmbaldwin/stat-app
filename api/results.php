<?php
/**
 * Agent results API — JSON in/out for automated result submission.
 *
 * Auth:   Authorization: Bearer <STATAPP_API_TOKEN>   (see config/app_config.php)
 *         Fails closed when the token is unset or empty — the whole API 403s.
 *
 *   GET  /api/results.php                      → this usage document (JSON)
 *   POST /api/results.php  { "action": "create", ... }   → create a result
 *   POST /api/results.php  { "action": "delete", "result_id": N, "type": "...",
 *                            "confirm": true }           → delete a result
 *
 * Create payloads (all IDs must belong to the game's club):
 *
 *   ranked          { game_id, member_id (winner), second_place_id,
 *                     additional_places?: [id...], duration_minutes, played_at?, notes? }
 *   winner_losers   { game_id, member_id, losers: [id...], duration_minutes, ... }
 *   team            { game_id, team_id (winner), second_place_id?,
 *                     duration_minutes, ... }
 *   cooperative     { game_id, outcome: "win"|"loss", participants: {
 *                       type: "members", member_ids: [id...] } | { type: "team", team_id },
 *                     score?, difficulty?, scenario?, duration_minutes, ... }
 *
 * played_at: "YYYY-MM-DDTHH:MM" (defaults to now). notes ≤ 1000 chars.
 *
 * Responses: JSON { ok: true, result_id } | { ok: false, error }
 * Status:    200 ok · 400 validation · 401 auth · 404 not found · 405 method
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security_headers.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function usage(): array {
    return [
        'ok' => true,
        'api' => 'results',
        'version' => 1,
        'auth' => 'Authorization: Bearer <STATAPP_API_TOKEN>',
        'actions' => ['create', 'delete'],
        'types' => ['ranked', 'winner_losers', 'team', 'cooperative'],
        'docs' => 'See the header comment of api/results.php.',
    ];
}

// ---------- auth ----------

$api_token = getenv('STATAPP_API_TOKEN');
if (!defined('API_TOKEN') && $api_token === false) {
    $api_token = '';
}
if (defined('API_TOKEN')) {
    $api_token = API_TOKEN;
}

function authenticate(?string $provided, string $expected): bool
{
    if ($expected === '' || $provided === null) {
        return false;
    }
    return hash_equals($expected, $provided);
}

$provided = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
if ($provided && preg_match('/Bearer\s+(\S+)/i', $provided, $m)) {
    $provided = $m[1];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Usage doc is public but harmless; creation/deletion always require the token.
    respond(200, usage());
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (!authenticate($provided, (string) $api_token)) {
    header('WWW-Authenticate: Bearer realm="statapp-results-api"');
    respond(401, ['ok' => false, 'error' => 'Missing or invalid API token.']);
}

// ---------- input ----------

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'error' => 'Body must be a JSON object.']);
}

$action = $data['action'] ?? 'create';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'delete') {
        $result_id = (int) ($data['result_id'] ?? 0);
        $type = (string) ($data['type'] ?? '');
        $confirm = !empty($data['confirm']);
        if (!$result_id || !$confirm) {
            respond(400, ['ok' => false, 'error' => 'delete requires result_id and confirm:true.']);
        }
        $table_map = [
            'ranked' => 'game_results', 'winner_losers' => 'game_results',
            'team' => 'team_game_results', 'cooperative' => 'cooperative_game_results',
        ];
        if (!isset($table_map[$type])) {
            respond(400, ['ok' => false, 'error' => 'Unknown type for delete.']);
        }
        $stmt = $pdo->prepare("DELETE FROM {$table_map[$type]} WHERE result_id = ?");
        $stmt->execute([$result_id]);
        if ($type === 'winner_losers') {
            $pdo->prepare('DELETE FROM game_result_losers WHERE result_id = ?')->execute([$result_id]);
        }
        if ($type === 'cooperative') {
            $pdo->prepare('DELETE FROM cooperative_result_participants WHERE result_id = ?')->execute([$result_id]);
        }
        respond(200, ['ok' => true, 'deleted' => $stmt->rowCount() > 0, 'result_id' => $result_id]);
    }

    if ($action !== 'create') {
        respond(400, ['ok' => false, 'error' => "Unknown action '{$action}'. Use create or delete."]);
    }

    // ---------- create: shared fields ----------

    $type = (string) ($data['type'] ?? '');
    if (!in_array($type, ['ranked', 'winner_losers', 'team', 'cooperative'], true)) {
        respond(400, ['ok' => false, 'error' => 'type must be ranked | winner_losers | team | cooperative.']);
    }

    $game_id = (int) ($data['game_id'] ?? 0);
    if (!$game_id) {
        respond(400, ['ok' => false, 'error' => 'game_id is required.']);
    }
    $stmt = $pdo->prepare('SELECT club_id FROM games WHERE game_id = ?');
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        respond(404, ['ok' => false, 'error' => "Game {$game_id} not found."]);
    }

    $duration = (int) ($data['duration_minutes'] ?? 0);
    if ($duration <= 0 || $duration > 24 * 60) {
        respond(400, ['ok' => false, 'error' => 'duration_minutes must be between 1 and 1440.']);
    }

    $played_at = str_replace('T', ' ', (string) ($data['played_at'] ?? ''));
    if ($played_at === '') {
        $played_at = date('Y-m-d H:i:s');
    }
    if (strlen($played_at) === 16) {
        $played_at .= ':00';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $played_at)) {
        respond(400, ['ok' => false, 'error' => 'played_at must be YYYY-MM-DDTHH:MM.']);
    }

    $notes = mb_substr(trim((string) ($data['notes'] ?? '')), 0, 1000);

    /** All provided member/team ids validated against the game's club. */
    function ids_exist(PDO $pdo, string $table, string $id_col, string $club_col, array $ids, int $club_id): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return true;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$id_col} IN ($ph) AND {$club_col} = ?");
        $stmt->execute(array_merge($ids, [$club_id]));
        return (int) $stmt->fetchColumn() === count($ids);
    }

    $member_ids = array_merge(
        isset($data['member_id']) ? [(int) $data['member_id']] : [],
        isset($data['second_place_id']) ? [(int) $data['second_place_id']] : [],
        array_map('intval', $data['additional_places'] ?? []),
        array_map('intval', $data['losers'] ?? []),
        ($data['participants']['type'] ?? '') === 'members' ? array_map('intval', $data['participants']['member_ids'] ?? []) : []
    );
    $team_ids = array_merge(
        isset($data['team_id']) ? [(int) $data['team_id']] : [],
        isset($data['second_place_id']) && $type === 'team' ? [(int) $data['second_place_id']] : [],
        ($data['participants']['type'] ?? '') === 'team' ? [($data['participants']['team_id'] ?? 0)] : []
    );
    if ($member_ids && !ids_exist($pdo, 'members', 'member_id', 'club_id', $member_ids, (int) $game['club_id'])) {
        respond(400, ['ok' => false, 'error' => 'One or more member_ids do not belong to this club.']);
    }
    if ($team_ids && !ids_exist($pdo, 'teams', 'team_id', 'club_id', $team_ids, (int) $game['club_id'])) {
        respond(400, ['ok' => false, 'error' => 'One or more team_ids do not belong to this club.']);
    }

    $pdo->beginTransaction();
    $session_id = uniqid('api_', true);

    // ---------- create: per type ----------

    if ($type === 'ranked' || $type === 'winner_losers') {
        $winner_id = (int) ($data['member_id'] ?? 0);
        if (!$winner_id) {
            $pdo->rollBack();
            respond(400, ['ok' => false, 'error' => 'member_id (winner) is required.']);
        }

        $places = array_fill(0, 7, null);
        if ($type === 'ranked') {
            $second = (int) ($data['second_place_id'] ?? 0);
            if (!$second) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'ranked requires second_place_id (create a "none" member for solo plays).']);
            }
            if ($second === $winner_id) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'Duplicate members selected.']);
            }
            $additional = array_values(array_unique(array_filter(array_map('intval', $data['additional_places'] ?? []))));
            $all = array_merge([$winner_id, $second], $additional);
            if (count($all) !== count(array_unique($all))) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'Duplicate members selected.']);
            }
            $places[0] = $second;
            foreach (array_slice($additional, 0, 6) as $i => $pid) {
                $places[$i + 1] = $pid;
            }
            $num_players = count($all);
        } else {
            $losers = array_values(array_unique(array_filter(array_map('intval', $data['losers'] ?? []))));
            if (!$losers) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'winner_losers requires at least one loser.']);
            }
            if (in_array($winner_id, $losers, true)) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'Winner cannot also be a loser.']);
            }
            $num_players = 1 + count($losers);
        }

        $stmt = $pdo->prepare('INSERT INTO game_results (game_id, session_id, member_id, position, played_at, duration, notes, num_players, winner, place_2, place_3, place_4, place_5, place_6, place_7, place_8) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $game_id, $session_id, $winner_id, 1, $played_at, $duration, $notes, $num_players,
            $winner_id, $places[0], $places[1], $places[2], $places[3], $places[4], $places[5], $places[6],
        ]);
        $result_id = (int) $pdo->lastInsertId();

        if ($type === 'winner_losers') {
            $loser_stmt = $pdo->prepare('INSERT INTO game_result_losers (result_id, member_id) VALUES (?, ?)');
            foreach ($losers as $loser_id) {
                $loser_stmt->execute([$result_id, $loser_id]);
            }
        }
    } elseif ($type === 'team') {
        $winner_team = (int) ($data['team_id'] ?? 0);
        if (!$winner_team) {
            $pdo->rollBack();
            respond(400, ['ok' => false, 'error' => 'team_id (winner) is required.']);
        }
        $second = (int) ($data['second_place_id'] ?? 0);
        $additional = array_values(array_unique(array_filter(array_map('intval', $data['additional_places'] ?? []))));
        $all = array_merge([$winner_team], $second ? [$second] : [], $additional);
        if (count($all) < 2) {
            $pdo->rollBack();
            respond(400, ['ok' => false, 'error' => 'team results require at least two teams (add second_place_id).']);
        }
        if (count($all) !== count(array_unique($all))) {
            $pdo->rollBack();
            respond(400, ['ok' => false, 'error' => 'Duplicate teams selected.']);
        }

        $places = array_fill(0, 7, null);
        $places[0] = $second ?: null;
        foreach (array_slice($additional, 0, 6) as $i => $tid) {
            $places[$i + 1] = $tid;
        }
        $num_teams = count($all);

        $stmt = $pdo->prepare('INSERT INTO team_game_results (game_id, session_id, team_id, position, played_at, duration, notes, num_teams, winner, place_2, place_3, place_4, place_5, place_6, place_7, place_8) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $game_id, $session_id, $winner_team, 1, $played_at, $duration, $notes, $num_teams,
            $winner_team, $places[0], $places[1], $places[2], $places[3], $places[4], $places[5], $places[6],
        ]);
        $result_id = (int) $pdo->lastInsertId();
    } else { // cooperative
        $outcome = (string) ($data['outcome'] ?? '');
        if (!in_array($outcome, ['win', 'loss'], true)) {
            $pdo->rollBack();
            respond(400, ['ok' => false, 'error' => 'cooperative requires outcome win|loss.']);
        }
        $ptype = (string) ($data['participants']['type'] ?? 'members');
        if ($ptype === 'members') {
            $participant_member_ids = array_values(array_unique(array_filter(array_map('intval', $data['participants']['member_ids'] ?? []))));
            if (!$participant_member_ids) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'cooperative requires participants.member_ids.']);
            }
            $num_participants = count($participant_member_ids);
            $participant_team_id = null;
        } else {
            $participant_member_ids = [];
            $participant_team_id = (int) ($data['participants']['team_id'] ?? 0);
            if (!$participant_team_id) {
                $pdo->rollBack();
                respond(400, ['ok' => false, 'error' => 'participants.team_id required when type=team.']);
            }
            $num_participants = 1;
        }

        $score = $data['score'] ?? null;
        $difficulty = $data['difficulty'] ?? null;
        $scenario = mb_substr(trim((string) ($data['scenario'] ?? '')), 0, 255) ?: null;

        $stmt = $pdo->prepare('INSERT INTO cooperative_game_results (game_id, session_id, outcome, score, difficulty, scenario, num_participants, played_at, duration, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $game_id, $session_id, $outcome,
            $score !== null ? (int) $score : null,
            $difficulty !== null ? (int) $difficulty : null,
            $scenario, $num_participants, $played_at, $duration, $notes,
        ]);
        $result_id = (int) $pdo->lastInsertId();

        $participant_stmt = $pdo->prepare('INSERT INTO cooperative_result_participants (result_id, participant_type, member_id, team_id) VALUES (?, ?, ?, ?)');
        if ($ptype === 'members') {
            foreach ($participant_member_ids as $mid) {
                $participant_stmt->execute([$result_id, 'member', $mid, null]);
            }
        } else {
            $participant_stmt->execute([$result_id, 'team', null, $participant_team_id]);
        }
    }

    $pdo->commit();
    respond(200, ['ok' => true, 'result_id' => $result_id, 'type' => $type, 'game_id' => $game_id]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api/results.php: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Internal error creating result.']);
}
