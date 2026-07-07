<?php
declare(strict_types=1);
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

// Get club_id from URL
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;

if (!$club_id) {
    $_SESSION['error'] = "Club ID is required to add a game.";
    header("Location: manage_games.php");
    exit();
}

// Verify admin access to club
$stmt = $pdo->prepare("
    SELECT 1 
    FROM club_admins 
    WHERE club_id = ? AND admin_id = ?
");
$stmt->execute([$club_id, $_SESSION['admin_id']]);
if (!$stmt->fetch()) {
    $_SESSION['error'] = "Unauthorized club access.";
    header("Location: dashboard.php");
    exit();
}

// Fetch club name
$stmt = $pdo->prepare("SELECT club_name FROM clubs WHERE club_id = ?");
$stmt->execute([$club_id]);
$club = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$club) {
    $_SESSION['error'] = "Club not found.";
    header("Location: manage_games.php");
    exit();
}
$club_name = $club['club_name'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !$security->verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid security token. Please try again.";
        header("Location: add_game.php?club_id=$club_id");
        exit();
    }

    if (!empty($_POST['game_name'])) {
        $game_image = null;
        
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
                    $uploadDir = '../images/game_images/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $filename = 'game_' . uniqid() . '_' . time() . '.' . $extension;
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
        } else {
            $stmt = $pdo->prepare("INSERT INTO games (club_id, game_name, min_players, max_players, game_image) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $club_id,
                trim($_POST['game_name']),
                $_POST['min_players'],
                $_POST['max_players'],
                $game_image
            ]);
            $_SESSION['success'] = "Game added successfully!";
            header("Location: manage_games.php?club_id=$club_id");
            exit();
        }
    } else {
        $_SESSION['error'] = "Game name is required.";
    }
}

// Generate CSRF token
$csrf_token = $security->generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Game - Board Game Club StatApp</title>
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
        @media (max-width: 48rem) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="has-sidebar">
    <?php NavigationHelper::renderAdminSidebar('games', $club_id, $club_name); ?>

    <div class="header header--compact">
        <?php NavigationHelper::renderSidebarToggle(); ?>
        <?php NavigationHelper::renderCompactHeader('Add New Game', $club_name); ?>
    </div>
    
    <div class="container">
        <?php display_session_message('error'); ?>

        <div class="admin-form-shell">
            <div class="modern-card">
                <div class="section-header">
                    <h2>Add New Game Information</h2>
                </div>
                <form method="POST" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <div class="form-group">
                        <label for="game_name" class="form-label">Game Name</label>
                        <input type="text" name="game_name" id="game_name" placeholder="Enter game title..." required class="form-control">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="min_players" class="form-label">Min Players</label>
                            <input type="number" name="min_players" id="min_players" placeholder="e.g. 1" required min="1" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="max_players" class="form-label">Max Players</label>
                            <input type="number" name="max_players" id="max_players" placeholder="e.g. 4" required min="1" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Game Image</label>
                        <div class="upload-zone" id="upload-zone">
                            <span class="upload-zone__icon">🖼️</span>
                            <span class="upload-zone__text">Click to upload or drag & drop</span>
                            <span class="upload-zone__hint">JPG, PNG, GIF (Max 1MB, 600px recommended)</span>
                            <input type="file" name="game_image" id="game_image" accept="image/jpeg,image/png,image/gif">
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top: var(--spacing-6); display: flex; justify-content: flex-end; gap: var(--spacing-3);">
                        <a href="manage_games.php?club_id=<?php echo $club_id; ?>" class="btn btn--subtle">Cancel</a>
                        <button type="submit" class="btn btn--primary btn--large">Create Game</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/sidebar.js"></script>
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
</body>
</html>
