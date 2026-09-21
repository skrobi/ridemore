<?php
// views/web/partials/form-error.php
// Oczekuje $error w scope (string|null) — używane przez formularze auth.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<?php if (!empty($error)): ?>
<p class="form-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
