<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::canAccessSystemSettings()) {
    header('Location: /index.php');
    exit;
}

$message = '';
$error = '';

// Get current settings from database or config
$db = Database::getContentDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    try {
        // Ensure system_settings table exists (one-time check)
        try {
            $db->query("SELECT 1 FROM system_settings LIMIT 1");
        } catch (Exception $e) {
            // Table doesn't exist, create it
            $db->exec("
                CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(100) PRIMARY KEY,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by INT
                )
            ");
        }

        if (isset($_POST['update_system_settings'])) {
            $siteName = $_POST['site_name'] ?? 'IBC Intranet';
            $siteDescription = $_POST['site_description'] ?? '';
            $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;

            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
            ");

            $stmt->execute(['site_name', $siteName, $_SESSION['user_id']]);
            $stmt->execute(['site_description', $siteDescription, $_SESSION['user_id']]);
            $stmt->execute(['maintenance_mode', $maintenanceMode, $_SESSION['user_id']]);
            $stmt->execute(['allow_registration', $allowRegistration, $_SESSION['user_id']]);

            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                'update_system_settings',
                'settings',
                'System-Einstellungen aktualisiert',
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $message = 'Einstellungen erfolgreich gespeichert';
        }
    } catch (Exception $e) {
        $error = 'Fehler beim Speichern: ' . $e->getMessage();
    }
}

// Load current settings
function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$siteName = getSetting($db, 'site_name', 'IBC Intranet');
$siteDescription = getSetting($db, 'site_description', '');
$maintenanceMode = getSetting($db, 'maintenance_mode', '0');
$allowRegistration = getSetting($db, 'allow_registration', '1');

$title = 'Systemeinstellungen - IBC Intranet';
ob_start();
?>

<style>
/* ── Systemeinstellungen ─────────────────────────────── */
@keyframes setSlideUp {
  from { opacity:0; transform:translateY(18px) scale(.98); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.set-page { animation: setSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.set-page-header { display:flex; align-items:center; gap:1rem; margin-bottom:2rem; flex-wrap:wrap; }
.set-header-icon {
  width:3rem; height:3rem; border-radius:.875rem;
  background:linear-gradient(135deg,#7c3aed,#4f46e5);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 14px rgba(124,58,237,.4); flex-shrink:0;
}
.set-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0 0 .2rem; }
.set-page-sub   { color:var(--text-muted); margin:0; font-size:.9rem; }

/* Flash messages */
.set-flash {
  margin-bottom: 1.5rem; padding: 1rem 1.25rem; border-radius: .875rem;
  display:flex; align-items:center; gap:.75rem; font-weight:500;
  animation: setSlideUp .3s cubic-bezier(.22,.68,0,1.2) both;
}
.set-flash-ok  { background:rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  color:rgba(21,128,61,1);  }
.set-flash-err { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:rgba(185,28,28,1); }

/* Settings card */
.set-card {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  transition: box-shadow .25s, transform .2s;
  animation: setSlideUp .4s .05s cubic-bezier(.22,.68,0,1.2) both;
}
.set-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.07); }

.set-section-icon {
  width:2.25rem; height:2.25rem; border-radius:.625rem;
  background:rgba(99,102,241,.12); display:flex; align-items:center; justify-content:center; flex-shrink:0;
}

/* Form elements */
.set-field { margin-bottom:1rem; }
.set-label { display:block; font-size:.8rem; font-weight:700; color:var(--text-muted); margin-bottom:.4rem; text-transform:uppercase; letter-spacing:.05em; }
.set-input {
  width:100%; padding:.625rem 1rem;
  border-radius:.625rem; border:1px solid var(--border-color);
  background:var(--bg-body); color:var(--text-main);
  font-size:.9rem; transition:border-color .2s, box-shadow .2s;
  outline:none; box-sizing:border-box;
}
.set-input:focus { border-color:rgba(99,102,241,.6); box-shadow:0 0 0 3px rgba(99,102,241,.12); }

.set-check-row {
  display:flex; align-items:center; gap:.625rem;
  min-height:44px; cursor:pointer;
  padding:.5rem .75rem;
  border-radius:.625rem; border:1px solid var(--border-color);
  background:var(--bg-body); transition:background .2s, border-color .2s;
}
.set-check-row:hover { background:rgba(99,102,241,.05); border-color:rgba(99,102,241,.25); }
.set-check-row input[type="checkbox"] { width:1.1rem; height:1.1rem; accent-color:#6366f1; cursor:pointer; flex-shrink:0; }

.set-check-rows { display:flex; flex-direction:column; gap:.625rem; margin-bottom:1.5rem; }

.set-save-btn {
  display:inline-flex; align-items:center; gap:.5rem;
  padding:.7rem 1.75rem; min-height:46px;
  background:linear-gradient(135deg,#7c3aed,#4f46e5);
  color:#fff; font-weight:700; font-size:.9rem;
  border-radius:.75rem; border:none; cursor:pointer;
  transition:opacity .2s, transform .15s, box-shadow .2s;
  box-shadow:0 2px 12px rgba(124,58,237,.4);
}
.set-save-btn:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 4px 18px rgba(124,58,237,.5); }
.set-save-btn:active { opacity:1; transform:none; }

.set-info-box {
  border-radius:.875rem; padding:1.25rem 1.5rem;
  background:rgba(59,130,246,.07); border:1px solid rgba(59,130,246,.2);
  display:flex; gap:1rem; align-items:flex-start;
  animation: setSlideUp .4s .10s cubic-bezier(.22,.68,0,1.2) both;
}

/* Responsive */
@media (max-width:480px) {
  .set-page-title { font-size:1.35rem; }
  .set-card { padding:1.25rem 1rem; }
  .set-save-btn { width:100%; justify-content:center; }
}
</style>

<div class="set-page">

<!-- Page Header -->
<div class="set-page-header">
  <div class="set-header-icon">
    <i class="fas fa-cog" style="color:#fff;font-size:1.35rem;"></i>
  </div>
  <div>
    <h1 class="set-page-title">Systemeinstellungen</h1>
    <p class="set-page-sub">Konfiguriere allgemeine Systemeinstellungen und Parameter</p>
  </div>
</div>

<?php if ($message): ?>
<div class="set-flash set-flash-ok">
  <i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
  <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="set-flash set-flash-err">
  <i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
  <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Settings Card -->
<div class="set-card">
  <div style="display:flex;align-items:center;gap:.625rem;margin-bottom:1.5rem;">
    <div class="set-section-icon">
      <i class="fas fa-sliders-h" style="color:#6366f1;"></i>
    </div>
    <h2 style="font-size:1.05rem;font-weight:700;color:var(--text-main);margin:0;">Allgemeine Einstellungen</h2>
  </div>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

    <div class="set-field">
      <label class="set-label">Website-Name</label>
      <input type="text" name="site_name" value="<?php echo htmlspecialchars($siteName); ?>" class="set-input" required>
    </div>

    <div class="set-field">
      <label class="set-label">Website-Beschreibung</label>
      <textarea name="site_description" rows="3" class="set-input" style="resize:vertical;"><?php echo htmlspecialchars($siteDescription); ?></textarea>
    </div>

    <div class="set-check-rows">
      <label class="set-check-row">
        <input type="checkbox" name="maintenance_mode" <?php echo $maintenanceMode == '1' ? 'checked' : ''; ?>>
        <div>
          <div style="font-size:.9rem;font-weight:600;color:var(--text-main);">Wartungsmodus aktivieren</div>
          <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem;">Zeigt eine Wartungsseite für alle nicht-Admin-Benutzer</div>
        </div>
      </label>

      <label class="set-check-row">
        <input type="checkbox" name="allow_registration" <?php echo $allowRegistration == '1' ? 'checked' : ''; ?>>
        <div>
          <div style="font-size:.9rem;font-weight:600;color:var(--text-main);">Registrierung erlauben</div>
          <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem;">Ermöglicht neuen Benutzern, sich selbst zu registrieren</div>
        </div>
      </label>
    </div>

    <button type="submit" name="update_system_settings" class="set-save-btn">
      <i class="fas fa-save"></i>Einstellungen speichern
    </button>
  </form>
</div>

<!-- Info Box -->
<div class="set-info-box">
  <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:rgba(59,130,246,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
    <i class="fas fa-info-circle" style="color:rgba(59,130,246,1);font-size:1.1rem;"></i>
  </div>
  <div>
    <h3 style="font-size:.9rem;font-weight:700;color:var(--text-main);margin:0 0 .3rem;">Hinweis zu Systemeinstellungen</h3>
    <p style="font-size:.875rem;color:var(--text-muted);margin:0;line-height:1.6;">
      Einige Einstellungen erfordern möglicherweise einen Server-Neustart oder eine Cache-Löschung, um wirksam zu werden.
      Sicherheitseinstellungen (Passwörter, MFA, Zugriffsrichtlinien) werden über Microsoft Entra verwaltet.
    </p>
  </div>
</div>

</div><!-- .set-page -->

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
