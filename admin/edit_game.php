<?php
session_start();
require_once '../config/database.php';
require_once '../includes/helpers.php';
require_once '../includes/SecurityUtils.php';
require_once '../includes/NavigationHelper.php';

if ((!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) && (!isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin'])) {
    header("Location: login.php");
    exit();
}

$security = new SecurityUtils($pdo);
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;

// Get game info
$stmt = $pdo->prepare("SELECT g.*, c.club_name FROM games g JOIN clubs c ON g.club_id = c.club_id WHERE g.game_id = ? AND g.club_id = ?");
$stmt->execute([$game_id, $club_id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    header("Location: manage_games.php?club_id=" . $club_id);
    exit();
}

// Handle game update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !$security->verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid security token. Please try again.";
        header("Location: edit_game.php?club_id=" . $club_id . "&game_id=" . $game_id);
        exit();
    }

    try {
        $game_image = $game['game_image'];
        $uploadDir = '../images/game_images/';

        // Handle image removal
        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($game_image && file_exists($uploadDir . $game_image)) {
                unlink($uploadDir . $game_image);
            }
            $game_image = null;
        }

        // Handle image upload
        $uploadError = null;
        if (isset($_FILES['game_image']) && $_FILES['game_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['game_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['game_image'];
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                $maxSize = 1 * 1024 * 1024; // 1MB

                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $actualMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($extension, $allowedExtensions) || !in_array($actualMime, $allowedMimes)) {
                    $uploadError = "Invalid file type. Only JPG, PNG, and GIF allowed.";
                } elseif ($file['size'] > $maxSize) {
                    $uploadError = "File is too large. Max size is 1MB.";
                } else {
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    // Delete old image if exists
                    if ($game_image && file_exists($uploadDir . $game_image)) {
                        unlink($uploadDir . $game_image);
                    }

                    $filename = 'game_' . $game_id . '_' . time() . '.' . $extension;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        require_once '../includes/ImageHelper.php';
                        ImageHelper::optimizeImage($uploadDir . $filename, $uploadDir . $filename);
                        $game_image = $filename;
                    } else {
                        $uploadError = "Failed to move uploaded file. Check folder permissions.";
                    }
                }
            } else {
                $uploadError = "Upload error: " . $_FILES['game_image']['error'];
            }
        }

        if ($uploadError) {
            $_SESSION['error'] = $uploadError;
            header("Location: edit_game.php?club_id=$club_id&game_id=$game_id");
            exit();
        }

        $stmt = $pdo->prepare("UPDATE games SET game_name = ?, min_players = ?, max_players = ?, game_image = ? WHERE game_id = ? AND club_id = ?");
        $stmt->execute([
            trim($_POST['game_name']),
            $_POST['min_players'],
            $_POST['max_players'],
            $game_image,
            $game_id,
            $club_id
        ]);
        $_SESSION['success'] = "Game updated successfully!";
        header("Location: manage_games.php?club_id=" . $club_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Failed to update game: " . $e->getMessage();
    }
}

// Generate CSRF token for form
$csrf_token = $security->generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Game - <?php echo htmlspecialchars($game['game_name']); ?></title>
    <link rel="stylesheet" href="../css/styles.css">
    <script src="../js/dark-mode.js"></script>
    <style>
        .admin-form-shell {
            max-width: 800px;
            margin: 0 auto;
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing-4);
        }
        .upload-zone {
            border: 2px dashed var(--color-border-strong);
            border-radius: var(--radius-lg);
            padding: var(--spacing-6);
            text-align: center;
            transition: all var(--transition-fast);
            background: var(--color-surface-muted);
            cursor: pointer;
            position: relative;
        }
        .upload-zone:hover {
            border-color: var(--color-primary);
            background: rgba(var(--color-primary-rgb), 0.05);
        }
        .upload-zone.has-preview {
            border-style: solid;
            padding: var(--spacing-2);
        }
        .upload-zone.has-preview .upload-zone__icon,
        .upload-zone.has-preview .upload-zone__text,
        .upload-zone.has-preview .upload-zone__hint {
            display: none;
        }
        .upload-zone__preview-img {
            max-width: 100%;
            max-height: 250px;
            border-radius: var(--radius-md);
            display: block;
            margin: 0 auto;
        }
        .upload-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .upload-zone__icon {
            font-size: 2.5rem;
            margin-bottom: var(--spacing-2);
            display: block;
        }
        .upload-zone__text {
            display: block;
            font-weight: var(--font-weight-medium);
            color: var(--color-heading);
        }
        .upload-zone__hint {
            font-size: var(--font-size-xs);
            color: var(--color-text-soft);
        }
        .modern-card {
            border: none;
            box-shadow: var(--shadow-md);
            border-radius: var(--radius-xl);
            padding: var(--spacing-6);
            background: var(--color-surface);
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--spacing-6);
            border-bottom: 2px solid var(--color-border);
            padding-bottom: var(--spacing-3);
        }
        .section-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--color-heading);
        }
        .current-image-preview {
            max-width: 200px;
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-3);
            border: 1px solid var(--color-border);
        }
        @media (max-width: 48rem) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="has-sidebar">
    <?php NavigationHelper::renderAdminSidebar('games', $club_id, $game['club_name']); ?>

    <div class="header header--compact">
        <?php NavigationHelper::renderSidebarToggle(); ?>
        <?php NavigationHelper::renderCompactHeader('Edit Game', htmlspecialchars($game['game_name'])); ?>
    </div>

    <div class="container">
        <?php display_session_message('error'); ?>

        <div class="admin-form-shell">
            <div class="modern-card">
                <div class="section-header">
                    <h2>Edit Game Details</h2>
                </div>
                <form method="POST" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <?php if ($game['game_image']): ?>
                        <div class="form-group">
                            <label class="form-label">Current Image</label>
                            <div style="display: flex; flex-direction: column; align-items: flex-start; gap: var(--spacing-2);">
                                <img src="../images/game_images/<?php echo htmlspecialchars($game['game_image']); ?>" alt="Game Image" class="current-image-preview" loading="lazy">
                                <label class="form-check">
                                    <input type="checkbox" name="remove_image" value="1" class="form-check-input">
                                    <span class="form-check-label">Remove current image</span>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="game_name" class="form-label">Game Name</label>
                        <input type="text" name="game_name" id="game_name" placeholder="Game Name" value="<?php echo htmlspecialchars($game['game_name']); ?>" required class="form-control">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="min_players" class="form-label">Min Players</label>
                            <select name="min_players" id="min_players" class="form-control" required>
                                <option value="">Select...</option>
                                <?php for ($i = 1; $i <= 20; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($game['min_players'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="max_players" class="form-label">Max Players</label>
                            <input type="number" name="max_players" id="max_players" placeholder="Max Players" value="<?php echo $game['max_players']; ?>" required min="1" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Update Image (Optional)</label>
                        <div class="upload-zone" id="upload-zone">
                            <span class="upload-zone__icon">🔄</span>
                            <span class="upload-zone__text">Click to replace or drag & drop</span>
                            <span class="upload-zone__hint">JPG, PNG, GIF (Max 1MB, 600px recommended)</span>
                            <input type="file" name="game_image" id="game_image" accept="image/jpeg,image/png,image/gif">
                        </div>
                    </div>

                    <div style="margin-top: var(--spacing-6); display: flex; justify-content: flex-end; gap: var(--spacing-3);">
                        <a href="manage_games.php?club_id=<?php echo $club_id; ?>" class="btn btn--secondary btn--large">Cancel</a>
                        <input type="hidden" name="action" value="update">
                        <button type="submit" class="btn btn--primary btn--large">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('game_image')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const uploadZone = document.getElementById('upload-zone');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    let preview = uploadZone.querySelector('.upload-zone__preview-img');
                    if (!preview) {
                        preview = document.createElement('img');
                        preview.className = 'upload-zone__preview-img';
                        uploadZone.prepend(preview);
                    }
                    preview.src = event.target.result;
                    uploadZone.classList.add('has-preview');
                };
                reader.readAsDataURL(file);
                uploadZone.style.borderColor = 'var(--color-primary)';
            } else {
                const preview = uploadZone.querySelector('.upload-zone__preview-img');
                if (preview) preview.remove();
                uploadZone.classList.remove('has-preview');
                uploadZone.style.borderColor = '';
            }
        });
    </script>
    <script src="../js/sidebar.js"></script>
    <script src="../js/form-loading.js"></script>
    <script src="../js/confirmations.js"></script>
    <script src="../js/form-validation.js"></script>
    <script src="../js/empty-states.js"></script>
</body>
</html>