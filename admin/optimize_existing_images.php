<?php
declare(strict_types=1);
session_start();
require_once '../config/database.php';
require_once '../includes/helpers.php';
require_once '../includes/SecurityUtils.php';
require_once '../includes/NavigationHelper.php';
require_once '../includes/ImageHelper.php';

// Authenticate user
if ((!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) && (!isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin'])) {
    header("Location: login.php");
    exit();
}

$security = new SecurityUtils($pdo);
$uploadDir = '../images/game_images/';
$results = [];
$totalOriginal = 0;
$totalOptimized = 0;
$optimizedCount = 0;
$errorMsg = '';

if (isset($_POST['optimize_all'])) {
    if (!$security->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = "Invalid CSRF token.";
    } else {
        if (file_exists($uploadDir) && is_dir($uploadDir)) {
            $files = scandir($uploadDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $filePath = $uploadDir . $file;
                if (is_file($filePath)) {
                    $originalSize = filesize($filePath);
                    $totalOriginal += $originalSize;
                    
                    // Optimize image in place
                    if (ImageHelper::optimizeImage($filePath, $filePath)) {
                        clearstatcache(true, $filePath);
                        $optimizedSize = filesize($filePath);
                        $totalOptimized += $optimizedSize;
                        $optimizedCount++;
                        
                        $results[] = [
                            'name' => $file,
                            'original' => $originalSize,
                            'optimized' => $optimizedSize,
                            'status' => 'Success'
                        ];
                    } else {
                        $totalOptimized += $originalSize;
                        $results[] = [
                            'name' => $file,
                            'original' => $originalSize,
                            'optimized' => $originalSize,
                            'status' => 'Failed/Skipped'
                        ];
                    }
                }
            }
        } else {
            $errorMsg = "Game images directory does not exist.";
        }
    }
}

$csrfToken = $security->generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optimize Game Images - Admin CP</title>
    <link rel="stylesheet" href="../css/styles.css">
    <script src="../js/dark-mode.js"></script>
    <style>
        .optimize-container {
            margin: var(--spacing-6) auto;
            max-width: 50rem;
        }
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
            gap: var(--spacing-4);
            margin: var(--spacing-5) 0;
        }
        .stat-box {
            background: var(--color-surface-alt);
            border: 1px solid var(--color-border);
            padding: var(--spacing-4);
            border-radius: var(--radius-md);
            text-align: center;
        }
        .stat-box__val {
            font-size: 1.8rem;
            font-weight: var(--font-weight-bold);
            color: var(--color-primary);
        }
        .stat-box__lbl {
            font-size: 0.85rem;
            color: var(--color-text-soft);
            margin-top: var(--spacing-1);
        }
    </style>
</head>
<body class="has-sidebar">
    <?php
    // Render sidebar navigation
    NavigationHelper::renderSidebar('games');
    ?>

    <div class="header header--compact">
        <?php NavigationHelper::renderSidebarToggle(); ?>
        <?php NavigationHelper::renderCompactHeader('Optimize Cover Images', 'Admin Tools'); ?>
    </div>

    <div class="container optimize-container">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Game Covers Compression Tool</h2>
                    <p class="card-subtitle card-subtitle--muted">Batch resize and compress all uploaded board game cover images to improve page load speeds.</p>
                </div>
            </div>
            
            <div class="card-body">
                <?php if ($errorMsg): ?>
                    <div class="message message--error"><?php echo htmlspecialchars($errorMsg); ?></div>
                <?php endif; ?>

                <p>This script scans the folder <code>images/game_images/</code> and compresses each file in-place to fit within a maximum size of 400x533 pixels (3:4 ratio) with 80% compression quality. Transparent images will remain transparent.</p>
                
                <form method="POST" action="" class="form-group" style="margin-top: var(--spacing-4);">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <button type="submit" name="optimize_all" class="btn btn--primary">
                        Start Batch Optimization
                    </button>
                </form>

                <?php if (isset($_POST['optimize_all']) && !$errorMsg): ?>
                    <div style="margin-top: var(--spacing-6);">
                        <h3>Optimization Results</h3>
                        
                        <div class="stats-summary-grid">
                            <div class="stat-box">
                                <div class="stat-box__val"><?php echo $optimizedCount; ?></div>
                                <div class="stat-box__lbl">Files Processed</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box__val"><?php echo number_format($totalOriginal / 1024, 1); ?> KB</div>
                                <div class="stat-box__lbl">Original Size</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box__val"><?php echo number_format($totalOptimized / 1024, 1); ?> KB</div>
                                <div class="stat-box__lbl">Optimized Size</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box__val">
                                    <?php 
                                    $saved = $totalOriginal - $totalOptimized;
                                    $percent = $totalOriginal > 0 ? ($saved / $totalOriginal) * 100 : 0;
                                    echo number_format($percent, 1) . '%';
                                    ?>
                                </div>
                                <div class="stat-box__lbl">Space Saved</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Original Size</th>
                                        <th>Optimized Size</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $res): ?>
                                        <tr>
                                            <td style="text-align: left; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($res['name']); ?></td>
                                            <td><?php echo number_format($res['original'] / 1024, 1); ?> KB</td>
                                            <td><?php echo number_format($res['optimized'] / 1024, 1); ?> KB</td>
                                            <td>
                                                <span class="badge badge--<?php echo $res['status'] === 'Success' ? 'success' : 'neutral'; ?>">
                                                    <?php echo $res['status']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../js/sidebar.js"></script>
</body>
</html>
