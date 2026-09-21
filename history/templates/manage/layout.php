<?php
/* ============================================================================
   PLIK: templates/manage/layout.php
   Wspólny layout dla manage panel
   ============================================================================ */

$user = getManageUser();
$currentModule = $currentModule ?? 'challenges';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Panel Zarządzania' ?> - RideMore.bike</title>
    
    <link rel="stylesheet" href="<?= asset('css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/manage.css') ?>">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="manage-body">

    <!-- Header -->
    <header class="manage-header">
        <div class="manage-header-content">
            <div class="manage-logo">
                <a href="<?= manage_url(); ?>">RideMore.bike</a>
                <span class="manage-badge">Panel Zarządzania</span>
            </div>
            
            <div class="manage-user">
                <span class="manage-user-name"><?= e($user['username'] ?? $user['email']) ?></span>
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="manage-role-badge admin">Admin</span>
                <?php else: ?>
                    <span class="manage-role-badge premium">Premium</span>
                <?php endif; ?>
                    <a href="<?= get_base_url() ?>" class="btn btn-secondary btn-sm">← Wróć do mapy</a>
            </div>
        </div>
    </header>

    <!-- Navigation Tabs -->
    <nav class="manage-nav">
        <a href="<?= manage_url('/challenges/'); ?>" class="manage-nav-item <?= $currentModule === 'challenges' ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i>
            Wyzwania
        </a>
        <a href="<?= manage_url('/groups/'); ?>" class="manage-nav-item <?= $currentModule === 'groups' ? 'active' : '' ?>">
            <i class="fas fa-folder"></i>
            Grupy
        </a>
    </nav>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="manage-flash success">
            <i class="fas fa-check-circle"></i>
            <?= e($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="manage-flash error">
            <i class="fas fa-exclamation-circle"></i>
            <?= e($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="manage-content">
        <?php include $contentTemplate; ?>
    </main>

    <script src="<?= asset('js/manage.js') ?>"></script>
</body>
</html>