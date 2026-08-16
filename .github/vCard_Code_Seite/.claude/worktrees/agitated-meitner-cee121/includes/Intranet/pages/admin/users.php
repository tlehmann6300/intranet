<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';

if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error   = '';
$warning = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        if ($userId === $_SESSION['user_id']) {
            $error = 'Du kannst dein eigenes Konto nicht löschen.';
        } else {
            try {
                User::delete($userId);
                $message = 'Benutzer erfolgreich gelöscht.';
            } catch (Exception $e) {
                $error = 'Fehler beim Löschen: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['reset_2fa']) && isset($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        try {
            User::reset2FA($userId);
            $message = '2FA erfolgreich zurückgesetzt.';
        } catch (Exception $e) {
            $error = 'Fehler beim 2FA-Reset: ' . $e->getMessage();
        }
    }

    if (isset($_POST['toggle_alumni_validation']) && isset($_POST['user_id'])) {
        $userId      = intval($_POST['user_id']);
        $isValidated = intval($_POST['is_validated'] ?? 0);
        try {
            User::setAlumniValidated($userId, $isValidated);
            $message = 'Alumni-Verifizierungsstatus aktualisiert.';
        } catch (Exception $e) {
            $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }
    }

    if (isset($_POST['import_entra_user'])) {
        $entraId          = trim($_POST['entra_id']       ?? '');
        $displayName      = trim($_POST['display_name']   ?? '');
        $entraEmail       = trim($_POST['entra_email']    ?? '');
        $role             = trim($_POST['role']           ?? 'mitglied');
        $userType         = trim($_POST['user_type']      ?? 'member');
        $assignEntraRole  = !empty($_POST['assign_entra_role']); // checkbox

        if (!in_array($role, Auth::VALID_ROLES, true)) {
            $error = 'Ungültige Rolle.';
        } elseif (empty($entraId) || empty($entraEmail)) {
            $error = 'Entra-ID und E-Mail sind erforderlich.';
        } else {
            try {
                User::importFromEntra($entraId, $displayName, $entraEmail, $role, $userType);
                $message = 'Benutzer erfolgreich dem Intranet hinzugefügt.';

                // Optionally assign the role in the Entra Enterprise App as well
                if ($assignEntraRole) {
                    try {
                        $graphService = new MicrosoftGraphService();
                        $graphService->updateUserRole($entraId, $role);
                        $message .= ' Die Rolle wurde zusätzlich in der Unternehmensapp (Entra) zugewiesen.';
                    } catch (Exception $graphEx) {
                        error_log('Entra role assignment failed for OID ' . $entraId . ': ' . $graphEx->getMessage());
                        // DB import succeeded but Entra assignment failed — show warning, not hard error
                        $warning = 'Benutzer wurde im Intranet angelegt, aber die Rollenzuweisung in der Entra-Unternehmensapp ist fehlgeschlagen: '
                                 . $graphEx->getMessage()
                                 . ' — Du kannst die Rolle nachträglich über den Button in der Benutzerliste zuweisen.';
                    }
                }
            } catch (Exception $e) {
                $error = 'Fehler beim Importieren: ' . $e->getMessage();
            }
        }
    }
}

$users = User::getAll();

$currentUser     = Auth::user();
$currentUserRole = $currentUser['role'] ?? '';

$title = 'Benutzerverwaltung - IBC Intranet';
ob_start();
?>

<style>
/* ── Benutzerverwaltung ──────────────────────────────── */
@keyframes usrSlideUp {
  from { opacity:0; transform:translateY(18px) scale(.98); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.usr-page { animation: usrSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.usr-page-header { display:flex; align-items:center; gap:1rem; margin-bottom:2rem; flex-wrap:wrap; }
.usr-header-icon {
  width:3rem; height:3rem; border-radius:.875rem;
  background:linear-gradient(135deg,#7c3aed,#4f46e5);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 14px rgba(124,58,237,.4); flex-shrink:0;
}
.usr-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0 0 .2rem; }
.usr-page-sub   { color:var(--text-muted); margin:0; font-size:.9rem; }

/* Tab navigation */
.usr-tabs {
  display: flex;
  border-radius: 1rem; overflow: hidden;
  border: 1px solid var(--border-color); background-color: var(--bg-card);
  margin-bottom: 2rem;
}
.usr-tab {
  flex: 1; padding: .875rem 1rem;
  font-weight: 600; font-size: .875rem; cursor: pointer;
  background: var(--bg-body); color: var(--text-muted);
  border: none; transition: background .2s, color .2s;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  min-height: 48px;
}
.usr-tab:hover { background:rgba(124,58,237,.05); color:var(--text-main); }
.usr-tab--active { background:linear-gradient(135deg,rgba(124,58,237,1),rgba(99,102,241,1)); color:#fff !important; }

@media (max-width:480px) {
  .usr-tab { font-size:.8rem; padding:.75rem .625rem; gap:.35rem; }
  .usr-tab span { display:none; }
}

/* Flash messages */
.usr-flash-ok, .usr-flash-err, .usr-flash-warn {
  margin-bottom: 1.5rem; padding: 1rem 1.25rem; border-radius: .875rem;
  display:flex; align-items:flex-start; gap:.75rem; font-weight:500; line-height:1.5;
  animation: usrSlideUp .3s cubic-bezier(.22,.68,0,1.2) both;
}
.usr-flash-ok   { background:rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  color:rgba(21,128,61,1);  }
.usr-flash-err  { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:rgba(185,28,28,1); }
.usr-flash-warn { background:rgba(234,179,8,.1);  border:1px solid rgba(234,179,8,.3);  color:rgba(161,98,7,1);  }

/* Section cards */
.usr-card {
  border-radius: 1rem; border: 1px solid var(--border-color);
  background-color: var(--bg-card); overflow: hidden; margin-bottom: 1.5rem;
}

/* Filter bar */
.usr-filter-bar {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
  background: rgba(124,58,237,.03);
}
.usr-filter-grid {
  display: grid; grid-template-columns: 1fr 220px 220px;
  gap: 1rem; margin-bottom: 1rem;
}
@media (max-width:900px) { .usr-filter-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:600px) { .usr-filter-grid { grid-template-columns:1fr; } }

.usr-filter-label {
  font-size:.775rem; font-weight:700; color:var(--text-muted);
  text-transform:uppercase; letter-spacing:.05em;
  display:block; margin-bottom:.35rem;
}
.usr-filter-input {
  width:100%; padding:.6rem .875rem;
  border-radius:.625rem; border:1px solid var(--border-color);
  background:var(--bg-card); color:var(--text-main);
  font-size:.875rem; outline:none; box-sizing:border-box;
  transition:border-color .2s, box-shadow .2s;
}
.usr-filter-input:focus { border-color:rgba(124,58,237,.5); box-shadow:0 0 0 3px rgba(124,58,237,.1); }
.usr-export-btn {
  display:inline-flex; align-items:center; gap:.5rem;
  padding:.55rem 1.1rem; border-radius:.75rem; min-height:38px;
  background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3);
  font-weight:600; font-size:.8rem; cursor:pointer;
  transition:background .2s, transform .15s;
}
.usr-export-btn:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); }

/* User table */
.usr-table-scroll { overflow-x:auto; }
.usr-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.usr-table thead tr { border-bottom:2px solid var(--border-color); background:rgba(124,58,237,.04); }
.usr-table th { padding:.75rem 1.25rem; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; text-align:left; }
.usr-row { border-bottom:1px solid var(--border-color); transition:background .15s, transform .15s, box-shadow .15s; }
.usr-row:last-child { border-bottom:none; }
.usr-row:hover { background:rgba(124,58,237,.04); transform:translateY(-1px); box-shadow:0 2px 8px rgba(0,0,0,.05); }
.usr-table td { padding:.75rem 1.25rem; vertical-align:middle; color:var(--text-main); }

/* Avatar */
.usr-avatar {
  width:2.25rem; height:2.25rem; border-radius:999px; overflow:hidden; flex-shrink:0;
  background:linear-gradient(135deg,rgba(124,58,237,.7),rgba(99,102,241,.7));
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 6px rgba(0,0,0,.12);
}

/* Role select */
.usr-role-select {
  padding:.4rem .75rem; font-size:.8rem; border-radius:.5rem;
  border:1px solid var(--border-color); background:var(--bg-body); color:var(--text-main);
  outline:none; cursor:pointer; transition:border-color .2s, box-shadow .2s;
}
.usr-role-select:focus { border-color:rgba(124,58,237,.5); box-shadow:0 0 0 3px rgba(124,58,237,.1); }

/* Action buttons */
.usr-btn-del, .usr-btn-2fa, .usr-btn-entra-role {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.38rem .85rem; font-size:.78rem; font-weight:600; border-radius:.5rem;
  cursor:pointer; transition:background .2s, transform .15s; min-height:34px;
}
.usr-btn-del  { background:rgba(239,68,68,.1);  color:rgba(185,28,28,1);  border:1px solid rgba(239,68,68,.25); }
.usr-btn-del:hover  { background:rgba(239,68,68,.2);  transform:translateY(-1px); }
.usr-btn-2fa  { background:rgba(234,179,8,.1);  color:rgba(161,98,7,1);   border:1px solid rgba(234,179,8,.25); }
.usr-btn-2fa:hover  { background:rgba(234,179,8,.2);  transform:translateY(-1px); }
.usr-btn-entra-role { background:rgba(37,99,235,.1); color:rgba(37,99,235,1); border:1px solid rgba(37,99,235,.25); }
.usr-btn-entra-role:hover { background:rgba(37,99,235,.2); transform:translateY(-1px); }

/* Info banner */
.usr-info-banner {
  border-radius:.875rem; padding:1rem 1.25rem; margin-bottom:1.5rem;
  background:rgba(59,130,246,.07); border:1px solid rgba(59,130,246,.2);
  display:flex; gap:.875rem; align-items:flex-start;
}

/* Entra search */
.usr-entra-card { border-radius:1rem; border:1px solid var(--border-color); background-color:var(--bg-card); overflow:hidden; }
.usr-entra-header { padding:1.25rem 1.5rem; border-bottom:1px solid var(--border-color); background:rgba(59,130,246,.04); }
.usr-entra-body { padding:1.5rem; }
.usr-search-input {
  width:100%; padding:.6rem .875rem .6rem 2.5rem;
  border-radius:.75rem; border:1px solid var(--border-color);
  background:var(--bg-body); color:var(--text-main);
  font-size:.875rem; outline:none; box-sizing:border-box;
  transition:border-color .2s, box-shadow .2s;
}
.usr-search-input:focus {
  border-color: rgba(59,130,246,.5);
  box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}
.usr-search-btn {
  padding: .6rem 1.25rem; border-radius: .75rem;
  background: linear-gradient(135deg, rgba(37,99,235,1), rgba(79,70,229,1));
  color: #fff; border: none; font-weight: 600; font-size: .875rem;
  cursor: pointer; transition: opacity .2s, transform .15s;
  white-space: nowrap;
}
.usr-search-btn:hover { opacity: .92; transform: translateY(-1px); }

.usr-import-box {
  margin-top:1.5rem; padding:1.25rem;
  border-radius:.875rem;
  background:rgba(34,197,94,.07); border:1px solid rgba(34,197,94,.2);
}

/* Counter badge */
.usr-count-badge {
  display:inline-block; padding:.2rem .65rem; border-radius:999px;
  font-size:.75rem; font-weight:600;
  background:rgba(124,58,237,.1); color:rgba(109,40,217,1);
  border:1px solid rgba(124,58,237,.2);
}

/* Entra role assignment inline panel */
.usr-entra-role-panel {
  margin-top:.5rem; padding:.75rem .875rem; border-radius:.75rem;
  background:rgba(37,99,235,.06); border:1.5px solid rgba(37,99,235,.2);
  display:none;
}
.usr-entra-role-panel.open { display:block; }
.usr-entra-role-panel select {
  padding:.4rem .75rem; font-size:.8rem; border-radius:.5rem;
  border:1px solid var(--border-color); background:var(--bg-body); color:var(--text-main);
  outline:none; cursor:pointer;
}

/* Mobile table cards */
@media (max-width:640px) {
  .usr-row:hover { transform:none; box-shadow:none; }
  .usr-table thead { display:none; }
  .usr-table, .usr-table tbody, .usr-row, .usr-table td { display:block; width:100%; }
  .usr-row { padding:.875rem 1rem; }
  .usr-table td { padding:.3rem 0; border:none; display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
  .usr-table td::before {
    content: attr(data-label) ': ';
    font-weight:700; font-size:.7rem; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; flex-shrink:0;
  }
  .usr-btn-del, .usr-btn-2fa, .usr-btn-entra-role { min-height:42px; padding:.55rem 1rem; }
}
@media (max-width:1023px) { .usr-hide-lg { display:none !important; } }
@media (max-width:480px) {
  .usr-page-title { font-size:1.35rem; }
  .usr-filter-bar { padding:1rem .875rem; }
}
</style>

<div class="usr-page">
<input type="hidden" id="csrf-token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">

<!-- Page Header -->
<div class="usr-page-header">
  <div class="usr-header-icon">
    <i class="fas fa-users" style="color:#fff;font-size:1.35rem;"></i>
  </div>
  <div>
    <h1 class="usr-page-title">Benutzerverwaltung</h1>
    <p class="usr-page-sub"><?php echo count($users); ?> Benutzer im System</p>
  </div>
</div>

<!-- Tab Navigation -->
<div class="usr-tabs">
  <button class="usr-tab usr-tab--active" data-tab="users">
    <i class="fas fa-users"></i><span>Benutzerliste</span>
  </button>
  <?php if (Auth::canManageUsers()): ?>
  <button class="usr-tab" data-tab="entra-search">
    <i class="fab fa-microsoft"></i><span>Entra-Benutzer</span>
  </button>
  <?php endif; ?>
</div>

<?php if ($message): ?>
<div class="usr-flash-ok">
  <i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>
  <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="usr-flash-err">
  <i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>
  <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>
<?php if ($warning): ?>
<div class="usr-flash-warn">
  <i class="fas fa-exclamation-triangle" style="font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>
  <span><?php echo htmlspecialchars($warning); ?></span>
</div>
<?php endif; ?>

<!-- Tab: Benutzerliste -->
<div id="tab-users" class="usr-tab-content">

  <!-- Info Banner -->
  <div class="usr-info-banner">
    <div style="width:2.5rem;height:2.5rem;border-radius:.625rem;background:rgba(59,130,246,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-info-circle" style="color:rgba(37,99,235,1);font-size:1.1rem;"></i>
    </div>
    <div>
      <h3 style="font-size:.9rem;font-weight:700;color:var(--text-main);margin:0 0 .25rem;">Microsoft Only Authentifizierung</h3>
      <p style="font-size:.875rem;color:var(--text-muted);margin:0;line-height:1.5;">
        Benutzer werden ausschließlich über Microsoft Entra ID verwaltet. Neue Benutzer können über den Entra-Benutzer-Tab hinzugefügt werden.
      </p>
    </div>
  </div>

  <!-- Users Card -->
  <div class="usr-card">
    <!-- Filter Bar -->
    <div class="usr-filter-bar">
      <div class="usr-filter-grid">
        <div>
          <label class="usr-filter-label">Suche</label>
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.8rem;"></i>
            <input type="text" id="userSearch" placeholder="Nach E-Mail oder ID suchen…" class="usr-filter-input" style="padding-left:2.25rem;">
          </div>
        </div>
        <div>
          <label class="usr-filter-label">Rolle</label>
          <div style="position:relative;">
            <i class="fas fa-filter" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.8rem;"></i>
            <select id="roleFilter" class="usr-filter-input" style="padding-left:2.25rem;cursor:pointer;">
              <option value="">Alle Rollen</option>
              <?php foreach (Auth::VALID_ROLES as $role): ?>
              <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(translateRole($role)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="usr-filter-label">Sortierung</label>
          <div style="position:relative;">
            <i class="fas fa-sort" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.8rem;"></i>
            <select id="sortBy" class="usr-filter-input" style="padding-left:2.25rem;cursor:pointer;">
              <option value="email">E-Mail (A-Z)</option>
              <option value="email-desc">E-Mail (Z-A)</option>
              <option value="id">ID (aufsteigend)</option>
              <option value="id-desc">ID (absteigend)</option>
            </select>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
        <div style="display:flex;align-items:center;gap:.5rem;">
          <span class="usr-count-badge">
            <span id="visibleCount"><?php echo count($users); ?></span> / <span id="totalCount"><?php echo count($users); ?></span>
          </span>
          <span style="font-size:.8rem;color:var(--text-muted);">Benutzer</span>
        </div>
        <button id="exportUsers" class="usr-export-btn">
          <i class="fas fa-download"></i>Export CSV
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="usr-table-scroll">
      <table class="usr-table" id="usersTable">
        <thead>
          <tr>
            <th>Profil</th>
            <th>Benutzer</th>
            <th class="usr-hide-lg">Entra-Status</th>
            <th>Intranet-Rolle</th>
            <th>Status</th>
            <th style="text-align:center;">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <?php
          foreach ($users as $user):
          ?>
          <tr class="usr-row"
              data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>"
              data-role="<?php echo htmlspecialchars($user['role']); ?>"
              data-id="<?php echo $user['id']; ?>">

            <!-- Avatar -->
            <td data-label="Profil">
              <?php
              $defaultImg    = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';
              $resolvedImg   = getProfileImageUrl($user['avatar_path'] ?? null);
              $avatarImageUrl = ($resolvedImg !== $defaultImg) ? asset($resolvedImg) : null;
              $avatarInitials = getMemberInitials($user['first_name'] ?? '', $user['last_name'] ?? '');
              if ($avatarInitials === '?') {
                  $avatarInitials = strtoupper(mb_substr($user['email'] ?? '', 0, 2, 'UTF-8')) ?: '?';
              }
              ?>
              <div class="usr-avatar">
                <?php if ($avatarImageUrl): ?>
                <img src="<?php echo htmlspecialchars($avatarImageUrl); ?>"
                     alt="<?php echo htmlspecialchars($avatarInitials); ?>"
                     style="width:100%;height:100%;object-fit:cover;"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;">
                  <?php echo htmlspecialchars($avatarInitials ?: '?'); ?>
                </div>
                <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;">
                  <?php echo htmlspecialchars($avatarInitials ?: '?'); ?>
                </div>
                <?php endif; ?>
              </div>
            </td>

            <!-- User email + ID -->
            <td data-label="Benutzer">
              <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <span style="font-weight:600;font-size:.875rem;word-break:break-all;">
                  <?php echo htmlspecialchars($user['email']); ?>
                </span>
                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                <span style="padding:.1rem .5rem;border-radius:999px;font-size:.7rem;font-weight:700;background:rgba(59,130,246,.15);color:rgba(37,99,235,1);border:1px solid rgba(59,130,246,.25);">Du</span>
                <?php endif; ?>
              </div>
              <div style="font-size:.75rem;color:var(--text-muted);font-family:monospace;margin-top:.15rem;">ID: <?php echo $user['id']; ?></div>
            </td>

            <!-- Entra status -->
            <td data-label="Entra-Status" class="usr-hide-lg">
              <?php $userType = strtolower($user['user_type'] ?? 'member'); ?>
              <?php if ($userType === 'guest'): ?>
              <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .7rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(249,115,22,.1);color:rgba(194,65,12,1);border:1px solid rgba(249,115,22,.25);">
                <i class="fas fa-user-friends" style="font-size:.7rem;"></i>Gast
              </span>
              <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .7rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(59,130,246,.1);color:rgba(37,99,235,1);border:1px solid rgba(59,130,246,.25);">
                <i class="fas fa-user" style="font-size:.7rem;"></i>Mitglied
              </span>
              <?php endif; ?>
            </td>

            <!-- Role -->
            <td data-label="Intranet-Rolle">
              <?php if ($user['id'] != $_SESSION['user_id']): ?>
                <?php if (!empty($user['azure_oid'])): ?>
                <div>
                  <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:.5rem;font-size:.8rem;font-weight:600;background:rgba(124,58,237,.1);color:rgba(109,40,217,1);border:1px solid rgba(124,58,237,.2);">
                    <i class="fab fa-microsoft" style="font-size:.7rem;"></i>
                    <?php echo htmlspecialchars(translateRole($user['role'])); ?>
                  </span>
                  <div style="margin-top:.35rem;">
                    <button type="button"
                            class="usr-btn-entra-role"
                            data-azure-oid="<?php echo htmlspecialchars($user['azure_oid']); ?>"
                            data-current-role="<?php echo htmlspecialchars($user['role']); ?>"
                            onclick="toggleEntraRolePanel(this)">
                      <i class="fab fa-microsoft" style="font-size:.7rem;"></i>Entra-Rolle
                    </button>
                  </div>
                  <!-- Inline Entra role assignment panel -->
                  <div class="usr-entra-role-panel" id="erp-<?php echo $user['id']; ?>">
                    <div style="font-size:.72rem;font-weight:700;color:rgba(37,99,235,1);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                      <i class="fab fa-microsoft" style="margin-right:.3rem;"></i>Entra-Unternehmensapp Rolle
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                      <select id="erp-select-<?php echo $user['id']; ?>" style="flex:1;min-width:120px;">
                        <?php foreach (Auth::VALID_ROLES as $r): ?>
                        <option value="<?php echo htmlspecialchars($r); ?>"
                          <?php echo $r === $user['role'] ? ' selected' : ''; ?>>
                          <?php echo htmlspecialchars(translateRole($r)); ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="button"
                              onclick="assignEntraRoleFromPanel(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['azure_oid']); ?>')"
                              style="padding:.4rem .875rem;font-size:.78rem;font-weight:600;border-radius:.5rem;background:rgba(37,99,235,1);color:#fff;border:none;cursor:pointer;white-space:nowrap;">
                        <i class="fas fa-check" style="margin-right:.3rem;"></i>Zuweisen
                      </button>
                    </div>
                    <div id="erp-status-<?php echo $user['id']; ?>" style="margin-top:.5rem;font-size:.78rem;display:none;"></div>
                  </div>
                </div>
                <?php else: ?>
                <select class="role-select usr-role-select" data-user-id="<?php echo $user['id']; ?>">
                  <?php foreach (Auth::VALID_ROLES as $r): ?>
                  <option value="<?php echo htmlspecialchars($r); ?>"<?php echo $r === $user['role'] ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars(translateRole($r)); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:.5rem;font-size:.8rem;font-weight:600;background:rgba(124,58,237,.1);color:rgba(109,40,217,1);border:1px solid rgba(124,58,237,.2);">
                <i class="fas fa-user-tag" style="font-size:.7rem;"></i>
                <?php echo htmlspecialchars(translateRole($user['role'])); ?>
              </span>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td data-label="Status">
              <div style="display:flex;flex-direction:column;gap:.375rem;">
                <?php
                $isLocked = !empty($user['is_locked_permanently'])
                    || (!empty($user['locked_until']) && ($lockedTs = strtotime($user['locked_until'])) !== false && $lockedTs > time());
                ?>
                <?php if ($isLocked): ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(239,68,68,.15);color:rgba(185,28,28,1);border:1px solid rgba(239,68,68,.3);">
                  <i class="fas fa-ban" style="font-size:.65rem;"></i>Inaktiv
                </span>
                <?php elseif (!empty($user['azure_oid'])): ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border:1px solid rgba(34,197,94,.3);">
                  <i class="fas fa-circle" style="font-size:.5rem;"></i>Aktiv
                </span>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(156,163,175,.12);color:rgba(107,114,128,1);border:1px solid rgba(156,163,175,.3);">
                  <i class="fas fa-envelope" style="font-size:.65rem;"></i>Eingeladen
                </span>
                <?php endif; ?>

                <?php if ($user['tfa_enabled']): ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(59,130,246,.1);color:rgba(37,99,235,1);border:1px solid rgba(59,130,246,.25);">
                  <i class="fas fa-shield-alt" style="font-size:.65rem;"></i>2FA Aktiv
                </span>
                <?php endif; ?>

                <?php if ($user['role'] == 'alumni'): ?>
                  <?php if ($user['is_alumni_validated']): ?>
                  <form method="POST" class="inline" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                    <input type="hidden" name="is_validated" value="0">
                    <button type="submit" name="toggle_alumni_validation"
                            style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;min-height:32px;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border:1px solid rgba(34,197,94,.3);cursor:pointer;transition:background .2s;">
                      <i class="fas fa-check-circle" style="font-size:.65rem;"></i>Verifiziert
                    </button>
                  </form>
                  <?php else: ?>
                  <form method="POST" class="inline" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                    <input type="hidden" name="is_validated" value="1">
                    <button type="submit" name="toggle_alumni_validation"
                            style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;min-height:32px;border-radius:.5rem;font-size:.75rem;font-weight:600;background:rgba(234,179,8,.1);color:rgba(161,98,7,1);border:1px solid rgba(234,179,8,.3);cursor:pointer;transition:background .2s;">
                      <i class="fas fa-clock" style="font-size:.65rem;"></i>Ausstehend
                    </button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>

            <!-- Actions -->
            <td data-label="Aktionen" style="text-align:center;">
              <?php if ($user['id'] != $_SESSION['user_id']): ?>
              <div style="display:flex;flex-direction:column;align-items:center;gap:.375rem;">
                <form method="POST" style="margin:0;" onsubmit="return confirm('Bist Du sicher, dass Du diesen Benutzer löschen möchtest? Das Profil in alumni_profiles wird ebenfalls entfernt.');">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" name="delete_user" class="usr-btn-del">
                    <i class="fas fa-trash" style="font-size:.7rem;"></i>Löschen
                  </button>
                </form>
                <?php if ($user['tfa_enabled'] && in_array($_SESSION['user_role'] ?? '', ['ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'])): ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('2FA für diesen Benutzer wirklich zurücksetzen?');">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                  <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                  <button type="submit" name="reset_2fa" class="usr-btn-2fa">
                    <i class="fas fa-shield-alt" style="font-size:.7rem;"></i>2FA reset
                  </button>
                </form>
                <?php endif; ?>
              </div>
              <?php else: ?>
              <div style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.5rem;background:rgba(0,0,0,.04);">
                <i class="fas fa-lock" style="font-size:.8rem;color:var(--text-muted);"></i>
              </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<!-- End Tab: Benutzerliste -->

<!-- Tab: Entra-Benutzer -->
<?php if (Auth::canManageUsers()): ?>
<div id="tab-entra-search" class="usr-tab-content" style="display:none;">
  <div class="usr-entra-card">
    <div class="usr-entra-header">
      <h3 style="font-size:1rem;font-weight:700;color:var(--text-main);margin:0 0 .25rem;display:flex;align-items:center;gap:.5rem;">
        <i class="fab fa-microsoft" style="color:rgba(37,99,235,1);"></i>
        Benutzer aus Microsoft Entra hinzufügen
      </h3>
      <p style="font-size:.85rem;color:var(--text-muted);margin:0;line-height:1.5;">
        Suche nach Benutzern im Azure-Tenant (per Name oder E-Mail) und füge sie dem Intranet hinzu.
        Der Login erfolgt ausschließlich über Microsoft Entra – es wird kein Passwort benötigt.
      </p>
    </div>
    <div class="usr-entra-body">
      <!-- Search field -->
      <div style="display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;position:relative;">
          <i class="fas fa-search" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.8rem;"></i>
          <input type="text" id="entraSearchInput" placeholder="Name oder E-Mail (mind. 2 Zeichen)…" class="usr-search-input">
        </div>
        <button id="entraSearchBtn" type="button" class="usr-search-btn">
          <i class="fas fa-search" style="margin-right:.4rem;"></i>Suchen
        </button>
      </div>

      <!-- Status -->
      <div id="entraSearchStatus" style="display:none;font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;">
        <i class="fas fa-spinner fa-spin"></i>Suche läuft…
      </div>

      <!-- Results -->
      <div id="entraSearchResults" style="display:none;">
        <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">Suchergebnisse</div>
        <div id="entraResultsList" style="display:flex;flex-direction:column;gap:.5rem;"></div>
      </div>

      <!-- Import form -->
      <form id="entraImportForm" method="POST" style="display:none;">
        <div class="usr-import-box">
          <input type="hidden" name="entra_id" id="importEntraId">
          <input type="hidden" name="display_name" id="importDisplayName">
          <input type="hidden" name="entra_email" id="importEntraEmail">
          <input type="hidden" name="user_type" id="importUserType">

          <div style="font-size:.85rem;font-weight:700;color:var(--text-main);margin-bottom:.875rem;display:flex;align-items:center;gap:.4rem;">
            <i class="fas fa-user-plus" style="color:rgba(21,128,61,1);"></i>Benutzer hinzufügen
          </div>

          <!-- User preview card -->
          <div style="padding:.875rem 1rem;border-radius:.75rem;background:var(--bg-card);border:1px solid var(--border-color);margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;">
            <div style="width:2.5rem;height:2.5rem;border-radius:.625rem;background:rgba(59,130,246,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="fab fa-microsoft" style="color:rgba(37,99,235,1);"></i>
            </div>
            <div style="flex:1;min-width:0;">
              <div id="importPreviewName" style="font-weight:700;color:var(--text-main);font-size:.9rem;"></div>
              <div id="importPreviewEmail" style="font-size:.8rem;color:var(--text-muted);word-break:break-all;"></div>
              <div id="importPreviewId" style="font-size:.75rem;color:var(--text-muted);font-family:monospace;"></div>
              <div id="importPreviewUserType" style="margin-top:.25rem;"></div>
            </div>
          </div>

          <!-- Current Entra roles -->
          <div id="currentEntraRolesBox" style="display:none;margin-bottom:1rem;padding:.75rem 1rem;border-radius:.75rem;background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.2);">
            <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;">
              <i class="fab fa-microsoft" style="margin-right:.3rem;"></i>Aktuelle Entra-Unternehmensrollen
            </div>
            <div id="currentEntraRolesList" style="font-size:.85rem;color:var(--text-main);"></div>
          </div>

          <!-- Role select -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:1rem;" id="importRoleGrid">
            <div>
              <label class="usr-filter-label">Rolle im Intranet (lokal)</label>
              <select name="role" id="importRoleSelect" class="usr-filter-input">
                <?php foreach (Auth::VALID_ROLES as $r): ?>
                <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars(translateRole($r)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;flex-direction:column;justify-content:flex-end;">
              <div style="padding:.6rem .875rem;border-radius:.625rem;border:1px solid var(--border-color);background:var(--bg-body);font-size:.8rem;color:var(--text-muted);min-height:2.45rem;display:flex;align-items:center;gap:.4rem;">
                <i class="fab fa-microsoft" style="color:rgba(37,99,235,.6);font-size:.75rem;"></i>
                <span id="importRoleEntraPreview" style="font-style:italic;">wird Entra-Rolle spiegeln</span>
              </div>
            </div>
          </div>

          <!-- Checkbox: also assign in Entra -->
          <label style="display:flex;align-items:flex-start;gap:.6rem;margin-bottom:1.1rem;cursor:pointer;padding:.75rem 1rem;border-radius:.75rem;border:1.5px solid rgba(37,99,235,.2);background:rgba(37,99,235,.04);">
            <input type="checkbox" name="assign_entra_role" id="assignEntraRoleCheck" value="1" checked
                   style="margin-top:.15rem;accent-color:rgba(37,99,235,1);width:1rem;height:1rem;flex-shrink:0;">
            <div>
              <div style="font-size:.875rem;font-weight:600;color:var(--text-main);">
                <i class="fab fa-microsoft" style="color:rgba(37,99,235,1);margin-right:.3rem;"></i>
                Rolle auch in der Entra-Unternehmensapp zuweisen
              </div>
              <div style="font-size:.78rem;color:var(--text-muted);margin-top:.15rem;line-height:1.4;">
                Weist dem Benutzer die gewählte Rolle direkt in der Intranet-Unternehmensapp in Entra zu. Der Login via Microsoft funktioniert danach sofort mit der korrekten Rolle.
              </div>
            </div>
          </label>

          <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <button type="submit" name="import_entra_user"
                    style="padding:.625rem 1.25rem;border-radius:.75rem;background:linear-gradient(135deg,rgba(34,197,94,1),rgba(21,128,61,1));color:#fff;font-weight:600;font-size:.875rem;border:none;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
              <i class="fas fa-user-plus"></i>Hinzufügen
            </button>
            <button type="button" id="cancelImport"
                    style="padding:.625rem 1.25rem;border-radius:.75rem;background:var(--bg-body);color:var(--text-muted);font-weight:600;font-size:.875rem;border:1px solid var(--border-color);cursor:pointer;">
              Abbrechen
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<!-- End Tab: Entra-Benutzer -->

</div><!-- .usr-page -->

<script>
// ── Tab switching ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const tabButtons  = document.querySelectorAll('.usr-tab');
    const tabContents = document.querySelectorAll('.usr-tab-content');

    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = this.dataset.tab;

            tabButtons.forEach(function(b) {
                b.classList.remove('usr-tab--active');
            });
            this.classList.add('usr-tab--active');

            tabContents.forEach(function(c) { c.style.display = 'none'; });
            const el = document.getElementById('tab-' + target);
            if (el) el.style.display = '';
        });
    });
});

// ── Filter / Sort / Export ────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const userSearch  = document.getElementById('userSearch');
    const roleFilter  = document.getElementById('roleFilter');
    const sortBy      = document.getElementById('sortBy');
    const exportBtn   = document.getElementById('exportUsers');
    const userRows    = document.querySelectorAll('.usr-row');
    const visibleCount = document.getElementById('visibleCount');

    function filterAndSortUsers() {
        const searchTerm   = userSearch.value.toLowerCase();
        const selectedRole = roleFilter.value;
        const sortOption   = sortBy.value;

        let rowsArray  = Array.from(userRows);
        let visibleRows = rowsArray.filter(function(row) {
            const email = row.dataset.email;
            const id    = row.dataset.id;
            const role  = row.dataset.role;
            return (email.includes(searchTerm) || id.toString().includes(searchTerm))
                && (!selectedRole || role === selectedRole);
        });

        visibleRows.sort(function(a, b) {
            switch (sortOption) {
                case 'email':      return a.dataset.email.localeCompare(b.dataset.email);
                case 'email-desc': return b.dataset.email.localeCompare(a.dataset.email);
                case 'id':         return parseInt(a.dataset.id) - parseInt(b.dataset.id);
                case 'id-desc':    return parseInt(b.dataset.id) - parseInt(a.dataset.id);
                default: return 0;
            }
        });

        userRows.forEach(function(row) { row.style.display = 'none'; });
        const tbody = document.querySelector('#usersTable tbody');
        visibleRows.forEach(function(row) { row.style.display = ''; tbody.appendChild(row); });
        if (visibleCount) visibleCount.textContent = visibleRows.length;
    }

    if (userSearch) userSearch.addEventListener('input', filterAndSortUsers);
    if (roleFilter) roleFilter.addEventListener('change', filterAndSortUsers);
    if (sortBy)     sortBy.addEventListener('change', filterAndSortUsers);

    function sanitizeCsvValue(val) {
        var s = String(val).replace(/"/g, '""');
        if (/^[=+\-@]/.test(s)) s = "'" + s;
        return s;
    }

    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            const rows = Array.from(userRows).filter(function(r) { return r.style.display !== 'none'; });
            let csv = 'ID,E-Mail,Rolle,2FA Aktiviert,Alumni Verifiziert\n';
            rows.forEach(function(row) {
                const id    = row.dataset.id;
                const email = row.dataset.email;
                const role  = row.dataset.role;
                const cells = row.querySelectorAll('td');
                const tfaBadge  = cells[4] ? cells[4].querySelector('.fa-shield-alt') : null;
                const tfa       = tfaBadge ? 'Ja' : 'Nein';
                const verifBadge = cells[4] ? cells[4].querySelector('.fa-check-circle') : null;
                const verif = verifBadge ? 'Ja' : (cells[4] && cells[4].querySelector('.fa-clock') ? 'Nein' : 'N/A');
                csv += `${sanitizeCsvValue(id)},"${sanitizeCsvValue(email)}","${sanitizeCsvValue(role)}","${sanitizeCsvValue(tfa)}","${sanitizeCsvValue(verif)}"\n`;
            });
            const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href     = URL.createObjectURL(blob);
            link.download = 'benutzer_export_' + new Date().toLocaleDateString('de-DE').replace(/\./g, '-') + '.csv';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});

// ── Role AJAX ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.getElementById('csrf-token') ? document.getElementById('csrf-token').value : '';

    document.querySelectorAll('.role-select').forEach(function(select) {
        select.dataset.originalValue = select.value;

        select.addEventListener('change', function() {
            const userId        = this.dataset.userId;
            const newRole       = this.value;
            const originalValue = this.dataset.originalValue;

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('new_role', newRole);
            formData.append('csrf_token', csrfToken);

            fetch('/api/update_user_role.php', { method:'POST', body:formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    const row = select.closest('tr');
                    if (row) row.dataset.role = newRole;
                    select.dataset.originalValue = newRole;
                    select.style.outline = '2px solid rgba(34,197,94,.6)';
                    select.style.outlineOffset = '1px';
                    setTimeout(function() { select.style.outline = ''; select.style.outlineOffset = ''; }, 1500);
                } else {
                    alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
                    select.value = originalValue;
                }
            })
            .catch(function() {
                alert('Netzwerkfehler beim Speichern der Rolle.');
                select.value = originalValue;
            });
        });
    });
});

// ── Entra search ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const searchInput  = document.getElementById('entraSearchInput');
    const searchBtn    = document.getElementById('entraSearchBtn');
    const statusEl     = document.getElementById('entraSearchStatus');
    const resultsEl    = document.getElementById('entraSearchResults');
    const resultsList  = document.getElementById('entraResultsList');
    const importForm   = document.getElementById('entraImportForm');
    const cancelBtn    = document.getElementById('cancelImport');

    if (!searchInput) return;

    let searchTimer = null;

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function guestBadge(isGuest) {
        return isGuest
            ? `<span style="display:inline-block;padding:.1rem .45rem;border-radius:.375rem;font-size:.7rem;font-weight:600;background:rgba(249,115,22,.1);color:rgba(194,65,12,1);border:1px solid rgba(249,115,22,.25);margin-left:.35rem;">Gast</span>`
            : `<span style="display:inline-block;padding:.1rem .45rem;border-radius:.375rem;font-size:.7rem;font-weight:600;background:rgba(59,130,246,.1);color:rgba(37,99,235,1);border:1px solid rgba(59,130,246,.25);margin-left:.35rem;">Mitglied</span>`;
    }

    function doSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) { resultsEl.style.display = 'none'; return; }

        statusEl.style.display = 'flex';
        resultsEl.style.display = 'none';
        importForm.style.display = 'none';

        fetch('/api/search_entra_users.php?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            statusEl.style.display = 'none';
            if (data.error) {
                resultsList.innerHTML = `<div style="padding:.75rem 1rem;border-radius:.625rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:rgba(185,28,28,1);font-size:.85rem;"><i class="fas fa-exclamation-circle" style="margin-right:.5rem;"></i>${escapeHtml(data.error)}</div>`;
                resultsEl.style.display = 'block';
                return;
            }
            const users = data.users || [];
            if (users.length === 0) {
                resultsList.innerHTML = `<div style="font-size:.875rem;color:var(--text-muted);font-style:italic;padding:.5rem 0;">Keine Benutzer gefunden.</div>`;
            } else {
                resultsList.innerHTML = users.map(function(u) {
                    const isGuest = (u.userType || '').toLowerCase() === 'guest';
                    return `<div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.75rem 1rem;border-radius:.75rem;border:1px solid var(--border-color);background:var(--bg-body);flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:.625rem;min-width:0;flex:1;">
                            <div style="width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(59,130,246,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fab fa-microsoft" style="color:rgba(37,99,235,1);font-size:.9rem;"></i>
                            </div>
                            <div style="min-width:0;">
                                <div style="font-weight:600;font-size:.875rem;color:var(--text-main);word-break:break-word;">${escapeHtml(u.displayName || '(kein Name)')}${guestBadge(isGuest)}</div>
                                <div style="font-size:.75rem;color:var(--text-muted);word-break:break-all;">${escapeHtml(u.mail || '(keine E-Mail)')}</div>
                                <div style="font-size:.7rem;color:var(--text-muted);font-family:monospace;">${escapeHtml(u.id)}</div>
                            </div>
                        </div>
                        <button type="button"
                                style="padding:.5rem 1rem;background:rgba(37,99,235,1);color:#fff;font-size:.8rem;font-weight:600;border-radius:.625rem;border:none;cursor:pointer;white-space:nowrap;min-height:36px;"
                                data-id="${escapeHtml(u.id)}"
                                data-name="${escapeHtml(u.displayName || '')}"
                                data-email="${escapeHtml(u.mail || '')}"
                                data-usertype="${escapeHtml(u.userType || 'member')}"
                                onclick="selectEntraUser(this)">
                            <i class="fas fa-plus" style="margin-right:.35rem;"></i>Auswählen
                        </button>
                    </div>`;
                }).join('');
            }
            resultsEl.style.display = 'block';
        })
        .catch(function() {
            statusEl.style.display = 'none';
            resultsList.innerHTML = `<div style="padding:.75rem 1rem;border-radius:.625rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:rgba(185,28,28,1);font-size:.85rem;"><i class="fas fa-exclamation-circle" style="margin-right:.5rem;"></i>Netzwerkfehler bei der Suche.</div>`;
            resultsEl.style.display = 'block';
        });
    }

    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
    searchInput.addEventListener('input', function() { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 400); });

    if (cancelBtn) cancelBtn.addEventListener('click', function() { importForm.style.display = 'none'; });

    // Update role preview label when role dropdown changes
    const importRoleSelect = document.getElementById('importRoleSelect');
    if (importRoleSelect) {
        importRoleSelect.addEventListener('change', function() {
            const preview = document.getElementById('importRoleEntraPreview');
            if (preview) {
                const selected = this.options[this.selectedIndex];
                preview.textContent = selected ? selected.text : '';
                preview.style.fontStyle = 'normal';
                preview.style.color = 'var(--text-main)';
            }
        });
    }

    window.selectEntraUser = function(btn) {
        const id       = btn.dataset.id;
        const name     = btn.dataset.name;
        const email    = btn.dataset.email;
        const userType = btn.dataset.usertype || 'member';
        const isGuest  = userType.toLowerCase() === 'guest';
        const typeLabel = isGuest ? 'Gast' : 'Mitglied';
        const typeBg  = isGuest ? 'rgba(249,115,22,.1)' : 'rgba(59,130,246,.1)';
        const typeCol = isGuest ? 'rgba(194,65,12,1)'   : 'rgba(37,99,235,1)';
        const typeBdr = isGuest ? 'rgba(249,115,22,.25)' : 'rgba(59,130,246,.25)';

        document.getElementById('importEntraId').value     = id;
        document.getElementById('importDisplayName').value = name;
        document.getElementById('importEntraEmail').value  = email;
        document.getElementById('importUserType').value    = isGuest ? 'guest' : 'member';
        document.getElementById('importPreviewName').textContent  = name  || '(kein Name)';
        document.getElementById('importPreviewEmail').textContent = email || '(keine E-Mail)';
        document.getElementById('importPreviewId').textContent    = 'Entra-ID: ' + id;
        document.getElementById('importPreviewUserType').innerHTML =
            `<span style="display:inline-block;padding:.15rem .45rem;border-radius:.375rem;font-size:.7rem;font-weight:600;background:${typeBg};color:${typeCol};border:1px solid ${typeBdr};">Entra-Typ: ${typeLabel}</span>`;

        // Reset and show import form
        const rolesBox = document.getElementById('currentEntraRolesBox');
        if (rolesBox) { rolesBox.style.display = 'none'; }
        importForm.style.display = 'block';
        importForm.scrollIntoView({ behavior:'smooth', block:'nearest' });

        // Sync role preview label
        const sel = document.getElementById('importRoleSelect');
        if (sel) {
            const preview = document.getElementById('importRoleEntraPreview');
            if (preview && sel.options[sel.selectedIndex]) {
                preview.textContent  = sel.options[sel.selectedIndex].text;
                preview.style.fontStyle = 'normal';
                preview.style.color     = 'var(--text-main)';
            }
        }

        // Fetch current Entra app roles for this user (non-blocking)
        fetch('/api/admin/get_entra_user_roles.php?entra_id=' + encodeURIComponent(id))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!rolesBox) return;
            const listEl = document.getElementById('currentEntraRolesList');
            if (data.error) {
                rolesBox.style.display = 'block';
                if (listEl) listEl.innerHTML = `<span style="color:rgba(185,28,28,1);font-size:.8rem;">${escapeHtml(data.error)}</span>`;
                return;
            }
            rolesBox.style.display = 'block';
            if (listEl) {
                if (!data.roles || data.roles.length === 0) {
                    listEl.innerHTML = '<span style="color:var(--text-muted);font-size:.82rem;font-style:italic;">Keine Rollen in der Unternehmensapp zugewiesen</span>';
                } else {
                    listEl.innerHTML = data.roles.map(function(r) {
                        return `<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:.4rem;font-size:.78rem;font-weight:600;background:rgba(37,99,235,.1);color:rgba(37,99,235,1);border:1px solid rgba(37,99,235,.25);margin-right:.35rem;">${escapeHtml(r)}</span>`;
                    }).join('');
                }
            }
        })
        .catch(function() {
            // Silently ignore if Graph isn't reachable
        });
    };
});

// ── Entra role panel (user table) ─────────────────────────
window.toggleEntraRolePanel = function(btn) {
    const oid      = btn.dataset.azureOid;
    const userId   = btn.closest('tr').dataset.id;
    const panelId  = 'erp-' + userId;
    const panel    = document.getElementById(panelId);
    if (!panel) return;

    const isOpen = panel.classList.contains('open');
    panel.classList.toggle('open', !isOpen);
    btn.style.background = isOpen ? '' : 'rgba(37,99,235,.18)';
};

window.assignEntraRoleFromPanel = function(userId, azureOid) {
    const sel      = document.getElementById('erp-select-' + userId);
    const statusEl = document.getElementById('erp-status-' + userId);
    if (!sel || !statusEl) return;

    const role       = sel.value;
    const csrfToken  = document.getElementById('csrf-token') ? document.getElementById('csrf-token').value : '';

    statusEl.style.display = 'block';
    statusEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:.35rem;"></i>Wird zugewiesen…';
    statusEl.style.color = 'var(--text-muted)';

    const body = new FormData();
    body.append('entra_id',   azureOid);
    body.append('role',       role);
    body.append('csrf_token', csrfToken);

    fetch('/api/admin/assign_entra_app_role.php', { method: 'POST', body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            statusEl.innerHTML = '<i class="fas fa-check-circle" style="margin-right:.3rem;"></i>' + (data.message || 'Entra-Rolle zugewiesen.');
            statusEl.style.color = 'rgba(21,128,61,1)';
        } else {
            statusEl.innerHTML = '<i class="fas fa-exclamation-circle" style="margin-right:.3rem;"></i>' + (data.error || 'Fehler beim Zuweisen.');
            statusEl.style.color = 'rgba(185,28,28,1)';
        }
    })
    .catch(function() {
        statusEl.innerHTML = '<i class="fas fa-exclamation-circle" style="margin-right:.3rem;"></i>Netzwerkfehler.';
        statusEl.style.color = 'rgba(185,28,28,1)';
    });
};
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
