<?php
require_once 'config/database.php';
require_once 'includes/NavigationHelper.php';

$club_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = isset($_GET['slug']) ? $_GET['slug'] : '';

// Get club details
if ($club_id > 0 || !empty($slug)) {
    $sql = "SELECT club_id, club_name, slug FROM clubs WHERE ";
    $params = [];
    
    if ($club_id > 0) {
        $sql .= "club_id = ?";
        $params[] = $club_id;
    } else {
        $sql .= "slug = ?";
        $params[] = $slug;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($club) {
        $club_id = $club['club_id'];
    }
}

if (!$club) {
    header("Location: index.php");
    exit();
}

// Generate base URL for sorting links
$base_url_param = !empty($club['slug']) ? 'slug=' . urlencode($club['slug']) : 'id=' . $club_id;
$club_url = !empty($club['slug']) ? $club['slug'] : 'club_stats.php?id=' . $club_id;

$sort_column = isset($_GET['sort']) ? $_GET['sort'] : 'played_at';
$order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';
// Updated allowed columns for sorting combined results
$allowed_columns = ['played_at', 'game_name', 'winner_identifier', 'participants', 'game_type'];
if (!in_array($sort_column, $allowed_columns)) {
    $sort_column = 'played_at';
}

// Pagination settings
$view_all = (isset($_GET['all']) && $_GET['all'] === '1') || (isset($_GET['limit']) && strtolower((string)$_GET['limit']) === 'all');
$results_per_page = 25; // Default show 25 results at a time
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($view_all) {
    $base_url_param .= '&all=1';
}

// Get total count of results first
$count_sql = "
    SELECT COUNT(*) as total FROM (
        SELECT gr.result_id
        FROM game_results gr
        JOIN games g ON gr.game_id = g.game_id
        JOIN members m ON gr.winner = m.member_id
        WHERE m.club_id = :club_id_individual

        UNION ALL

        SELECT tgr.result_id
        FROM team_game_results tgr
        JOIN games g ON tgr.game_id = g.game_id
        JOIN teams t ON tgr.winner = t.team_id
        WHERE t.club_id = :club_id_team

        UNION ALL

        SELECT cgr.result_id
        FROM cooperative_game_results cgr
        JOIN games g ON cgr.game_id = g.game_id
        WHERE g.club_id = :club_id_coop
    ) as all_results
";

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute(['club_id_individual' => $club_id, 'club_id_team' => $club_id, 'club_id_coop' => $club_id]);
    $total_results = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    if ($view_all) {
        $results_per_page = max(1, $total_results);
        $page = 1;
        $offset = 0;
        $total_pages = 1;
        $has_more = false;
    } else {
        $total_pages = (int)ceil($total_results / $results_per_page);
        $offset = ($page - 1) * $results_per_page;
        $has_more = $page < $total_pages;
    }
} catch (PDOException $e) {
    die("Database count query failed: " . $e->getMessage());
}

// Get combined game results (individual, team, and cooperative) for the club with LIMIT
$sql = "
    -- Individual Games
    SELECT
        gr.played_at,
        g.game_name,
        g.game_image,
        m.nickname as winner_identifier,
        gr.num_players as participants,
        gr.game_id,
        'Individual' as game_type,
        gr.result_id as record_id
    FROM game_results gr
    JOIN games g ON gr.game_id = g.game_id
    JOIN members m ON gr.winner = m.member_id
    WHERE m.club_id = :club_id_individual

    UNION ALL

    -- Team Games
    SELECT
        tgr.played_at,
        g.game_name,
        g.game_image,
        t.team_name as winner_identifier,
        tgr.num_teams as participants,
        tgr.game_id,
        'Team' as game_type,
        tgr.result_id as record_id
    FROM team_game_results tgr
    JOIN games g ON tgr.game_id = g.game_id
    JOIN teams t ON tgr.winner = t.team_id
    WHERE t.club_id = :club_id_team

    UNION ALL

    -- Cooperative Games
    SELECT
        cgr.played_at,
        g.game_name,
        g.game_image,
        CONCAT(UPPER(cgr.outcome), ' - Co-op') as winner_identifier,
        cgr.num_participants as participants,
        cgr.game_id,
        'Cooperative' as game_type,
        cgr.result_id as record_id
    FROM cooperative_game_results cgr
    JOIN games g ON cgr.game_id = g.game_id
    WHERE g.club_id = :club_id_coop

    ORDER BY $sort_column $order
    LIMIT :limit OFFSET :offset
";

// Prepare and execute the statement
try {
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':club_id_individual', $club_id, PDO::PARAM_INT);
    $stmt->bindValue(':club_id_team', $club_id, PDO::PARAM_INT);
    $stmt->bindValue(':club_id_coop', $club_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $results_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $game_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // It's good practice to catch potential errors
    die("Database query failed: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<!-- Rest of your HTML code remains the same -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Results - <?php echo htmlspecialchars($club['club_name']); ?></title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="js/dark-mode.js"></script>
    <style>
        .game-thumbnail {
            width: 48px !important;
            height: 48px !important;
            min-width: 48px !important;
            flex-shrink: 0;
            object-fit: cover;
            border-radius: var(--radius-sm);
            border: 1px solid var(--color-border);
            display: block;
            background: var(--color-surface-muted);
            overflow: hidden;
            position: relative;
            max-width: none !important;
        }
        .col-image {
            width: 48px;
            padding-right: 0 !important;
        }
        .game-thumbnail--skeleton::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, 
                rgba(17, 24, 39, 0.03) 25%, 
                rgba(17, 24, 39, 0.06) 37%, 
                rgba(17, 24, 39, 0.03) 63%);
            background-size: 400% 100%;
            animation: skeleton-loading 2s ease infinite;
        }
        @keyframes skeleton-loading {
            0% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
    </style>
</head>
<body class="has-sidebar">
    <?php
    // Render sidebar navigation
    NavigationHelper::renderSidebar('results', $club_id, $club['club_name']);
    ?>

    <div class="header header--compact">
        <?php NavigationHelper::renderSidebarToggle(); ?>
        <?php NavigationHelper::renderCompactHeader('Game Results', $club['club_name']); ?>
    </div>

    <div class="container container--wide">
        <div class="card">
            <div class="card-header card-header--stack">
                <div>
                    <h2>Game History</h2>
                    <p class="card-subtitle card-subtitle--muted">Sorted chronologically across individual and team results.</p>
                </div>
            </div>

            <?php if (!empty($game_results)): ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><a href="?<?php echo $base_url_param; ?>&sort=played_at&order=<?php echo ($sort_column === 'played_at' && $order === 'DESC') ? 'asc' : 'desc'; ?>" class="table-sort-link sort-link" onclick="saveScroll()"><span>Date Played</span><?php if ($sort_column === 'played_at'): ?><span class="table-sort-link__icon"><?php echo $order === 'ASC' ? '▲' : '▼'; ?></span><?php endif; ?></a></th>
                            <th class="col-image"></th>
                            <th><a href="?<?php echo $base_url_param; ?>&sort=game_name&order=<?php echo ($sort_column === 'game_name' && $order === 'DESC') ? 'asc' : 'desc'; ?>" class="table-sort-link sort-link" onclick="saveScroll()"><span>Game</span><?php if ($sort_column === 'game_name'): ?><span class="table-sort-link__icon"><?php echo $order === 'ASC' ? '▲' : '▼'; ?></span><?php endif; ?></a></th>
                            <th><a href="?<?php echo $base_url_param; ?>&sort=game_type&order=<?php echo ($sort_column === 'game_type' && $order === 'DESC') ? 'asc' : 'desc'; ?>" class="table-sort-link sort-link" onclick="saveScroll()"><span>Type</span><?php if ($sort_column === 'game_type'): ?><span class="table-sort-link__icon"><?php echo $order === 'ASC' ? '▲' : '▼'; ?></span><?php endif; ?></a></th>
                            <th><a href="?<?php echo $base_url_param; ?>&sort=winner_identifier&order=<?php echo ($sort_column === 'winner_identifier' && $order === 'DESC') ? 'asc' : 'desc'; ?>" class="table-sort-link sort-link" onclick="saveScroll()"><span>Winner / Team</span><?php if ($sort_column === 'winner_identifier'): ?><span class="table-sort-link__icon"><?php echo $order === 'ASC' ? '▲' : '▼'; ?></span><?php endif; ?></a></th>
                            <th><a href="?<?php echo $base_url_param; ?>&sort=participants&order=<?php echo ($sort_column === 'participants' && $order === 'DESC') ? 'asc' : 'desc'; ?>" class="table-sort-link sort-link" onclick="saveScroll()"><span>Participants</span><?php if ($sort_column === 'participants'): ?><span class="table-sort-link__icon"><?php echo $order === 'ASC' ? '▲' : '▼'; ?></span><?php endif; ?></a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($game_results as $result): ?>
                        <?php
                        $detail_url = match($result['game_type']) {
                            'Team' => 'team_game_play_details.php',
                            'Cooperative' => 'cooperative_game_play_details.php',
                            default => 'game_play_details.php'
                        };
                        ?>
                        <tr onclick="window.location='<?php echo $detail_url; ?>?result_id=<?php echo urlencode($result['record_id']); ?>'" class="table-row--link">
                            <td data-label="Date Played"><?php echo date('F j, Y', strtotime($result['played_at'])); ?></td>
                            <td class="col-image">
                                <?php if ($result['game_image']): ?>
                                    <img src="images/game_images/<?php echo htmlspecialchars($result['game_image']); ?>" alt="" class="game-thumbnail" loading="lazy">
                                <?php else: ?>
                                    <div class="game-thumbnail game-thumbnail--skeleton" title="No image uploaded"></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Game"><?php echo htmlspecialchars($result['game_name']); ?></td>
                            <td data-label="Type"><?php echo htmlspecialchars($result['game_type']); ?></td>
                            <td data-label="Winner / Team">
                                <?php if ($result['game_type'] === 'Cooperative'): ?>
                                    <?php
                                    $outcome = strtolower(explode(' - ', $result['winner_identifier'])[0]);
                                    ?>
                                    <span class="outcome-badge outcome-<?php echo $outcome; ?>"><?php echo htmlspecialchars($result['winner_identifier']); ?></span>
                                <?php else: ?>
                                    <span class="position-badge position-1"><?php echo htmlspecialchars($result['winner_identifier']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Participants"><?php echo htmlspecialchars($result['participants']); ?> <?php echo ($result['game_type'] === 'Individual') ? 'Players' : (($result['game_type'] === 'Team') ? 'Teams' : 'Players'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Info and Load More Button -->
            <?php if ($total_results > 0): ?>
                <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--border-radius); text-align: center;">
                    <?php if ($view_all): ?>
                        <p style="color: var(--text-light); margin-bottom: 1rem;">
                            Showing all <?php echo $total_results; ?> results
                        </p>
                        <div style="display: flex; gap: 1rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                            <?php 
                            $paginated_base_param = !empty($club['slug']) ? 'slug=' . urlencode($club['slug']) : 'id=' . $club_id;
                            ?>
                            <a href="?<?php echo $paginated_base_param; ?>&sort=<?php echo urlencode($sort_column); ?>&order=<?php echo urlencode($order); ?>" 
                               class="btn btn--ghost">
                                Switch to Paginated View (25 per page)
                            </a>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--text-light); margin-bottom: 1rem;">
                            Showing <?php echo min($offset + 1, $total_results); ?> - <?php echo min($offset + count($game_results), $total_results); ?> of <?php echo $total_results; ?> results
                        </p>
                        
                        <div style="display: flex; gap: 1rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo $base_url_param; ?>&sort=<?php echo urlencode($sort_column); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $page - 1; ?>" 
                                   class="btn btn--ghost">
                                    ← Show Previous Results
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($has_more): ?>
                                <a href="?<?php echo $base_url_param; ?>&sort=<?php echo urlencode($sort_column); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $page + 1; ?>" 
                                   class="btn btn--secondary">
                                    Load More Results →
                                </a>
                            <?php endif; ?>

                            <a href="?<?php echo $base_url_param; ?>&sort=<?php echo urlencode($sort_column); ?>&order=<?php echo urlencode($order); ?>&all=1" 
                               class="btn btn--ghost">
                                View All Results (<?php echo $total_results; ?>)
                            </a>
                        </div>
                        
                        <?php if ($has_more): ?>
                            <p style="color: var(--text-light); font-size: 0.875rem; margin-top: 0.75rem;">
                                <?php echo $total_results - ($offset + count($game_results)); ?> more results available
                            </p>
                        <?php elseif ($page > 1): ?>
                            <p style="color: var(--text-light); font-style: italic; margin-top: 0.75rem;">
                                All results loaded • Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php else: ?>
                <p>No game results available for this club.</p>
            <?php endif; ?>
        </div>
    </div>
    <script src="js/sidebar.js"></script>
    <script src="js/form-loading.js"></script>
    <script src="js/empty-states.js"></script>
</body>
</html>
<script>
function saveScroll() {
    sessionStorage.setItem('scrollPos', window.scrollY);
}
window.addEventListener('DOMContentLoaded', function() {
    var scrollPos = sessionStorage.getItem('scrollPos');
    if (scrollPos !== null) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('scrollPos');
    }
});
</script>
