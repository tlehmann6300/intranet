<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';

// Only board members can access
if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';

// Handle accept action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_application'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $applicationId = intval($_POST['application_id'] ?? 0);
    $role = $_POST['role'] ?? 'member';

    if (!in_array($role, ['lead', 'member'])) {
        $error = 'Ungültige Rolle ausgewählt';
    } else {
        try {
            $db = Database::getContentDB();

            $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ?");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch();

            if (!$application) throw new Exception('Bewerbung nicht gefunden');
            if ($application['status'] === 'accepted') throw new Exception('Diese Bewerbung wurde bereits akzeptiert');

            $projectId = $application['project_id'];
            $project = Project::getById($projectId);
            if (!$project) throw new Exception('Projekt nicht gefunden');

            $db->beginTransaction();
            try {
                Project::assignMember($projectId, $application['user_id'], $role);
                $stmt = $db->prepare("UPDATE project_applications SET status = 'accepted' WHERE id = ?");
                $stmt->execute([$applicationId]);
                $db->commit();

                $user = User::getById($application['user_id']);
                $clientData = null;
                if (!empty($project['client_name']) || !empty($project['client_contact_details'])) {
                    $clientData = ['name' => $project['client_name'] ?? '', 'contact' => $project['client_contact_details'] ?? ''];
                }

                $emailSent = false;
                if ($user) {
                    try {
                        $emailSent = MailService::sendProjectApplicationStatus(
                            $user['email'], $project['title'], 'accepted', $application['project_id'], $clientData
                        );
                    } catch (Exception $emailError) {
                        error_log("Failed to send project acceptance email: " . $emailError->getMessage());
                    }
                }

                $maxConsultants = isset($project['max_consultants']) ? intval($project['max_consultants']) : 0;
                if ($maxConsultants > 0 && !in_array($project['status'], ['assigned', 'running', 'completed', 'archived'])) {
                    $stmt = $db->prepare("SELECT COUNT(*) as assignment_count FROM project_assignments WHERE project_id = ?");
                    $stmt->execute([$projectId]);
                    $assignmentResult = $stmt->fetch();
                    $assignmentCount = $assignmentResult ? intval($assignmentResult['assignment_count']) : 0;

                    if ($assignmentCount >= $maxConsultants) {
                        $stmt = $db->prepare("UPDATE projects SET status = 'assigned' WHERE id = ?");
                        $stmt->execute([$projectId]);
                        $leadUserIds = Project::getProjectLeads($projectId);
                        $leadNotificationsSent = 0;
                        foreach ($leadUserIds as $leadUserId) {
                            $leadUser = User::getById($leadUserId);
                            if ($leadUser && !empty($leadUser['email'])) {
                                try {
                                    if (MailService::sendTeamCompletionNotification($leadUser['email'], $project['title'])) $leadNotificationsSent++;
                                } catch (Exception $emailError) {
                                    error_log("Failed to send team completion notification to lead {$leadUserId}: " . $emailError->getMessage());
                                }
                            }
                        }
                        if ($emailSent && $leadNotificationsSent > 0) $message = "Status aktualisiert, Team vollständig und Benachrichtigungen versendet (inkl. {$leadNotificationsSent} Lead(s))";
                        elseif ($emailSent) $message = "Status aktualisiert, Team vollständig und Benachrichtigung an Bewerber versendet";
                        elseif ($leadNotificationsSent > 0) $message = "Status aktualisiert und Team vollständig (Benachrichtigungen an {$leadNotificationsSent} Lead(s) versendet)";
                        else $message = "Status aktualisiert und Team vollständig";
                    } else {
                        $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';
                    }
                } else {
                    $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';
                }
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            $error = 'Fehler beim Akzeptieren: ' . $e->getMessage();
        }
    }
}

// Handle reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_application'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $applicationId = intval($_POST['application_id'] ?? 0);
    try {
        $db = Database::getContentDB();
        $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
        if (!$application) throw new Exception('Bewerbung nicht gefunden');

        $projectId = $application['project_id'];
        $project = Project::getById($projectId);
        if (!$project) throw new Exception('Projekt nicht gefunden');

        $stmt = $db->prepare("UPDATE project_applications SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$applicationId]);

        $user = User::getById($application['user_id']);
        $emailSent = false;
        if ($user) {
            try {
                $emailSent = MailService::sendProjectApplicationStatus(
                    $user['email'], $project['title'], 'rejected', $application['project_id']
                );
            } catch (Exception $emailError) {
                error_log("Failed to send project rejection email: " . $emailError->getMessage());
            }
        }
        $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';
    } catch (Exception $e) {
        $error = 'Fehler beim Ablehnen: ' . $e->getMessage();
    }
}

// Load all pending applications
$db = Database::getContentDB();
$stmt = $db->prepare("
    SELECT pa.id, pa.project_id, pa.user_id, pa.motivation, pa.experience_count, pa.status, pa.created_at,
           p.title as project_title
    FROM project_applications pa
    JOIN projects p ON pa.project_id = p.id
    WHERE pa.status = 'pending'
    ORDER BY pa.created_at ASC
");
$stmt->execute();
$applications = $stmt->fetchAll();

foreach ($applications as &$application) {
    $user = User::getById($application['user_id']);
    $application['user_email'] = $user ? $user['email'] : 'Unbekannt';
}
unset($application);

$title = 'Bewerbungsverwaltung - IBC Intranet';
ob_start();
?>

<style>
/* ── Bewerbungsverwaltung ────────────────────────────── */
@keyframes applSlideUp {
  from { opacity:0; transform:translateY(18px) scale(.98); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.appl-page { animation: applSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.appl-page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2rem;
  flex-wrap: wrap;
  gap: 1rem;
}
.appl-page-header-left {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-width: 0;
}
.appl-header-icon {
  width: 3rem; height: 3rem;
  border-radius: .875rem;
  background: linear-gradient(135deg,#7c3aed,#4f46e5);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px rgba(124,58,237,.4);
  flex-shrink: 0;
}
.appl-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0 0 .2rem; }
.appl-page-sub   { color:var(--text-muted); margin:0; font-size:.9rem; }

/* Flash messages */
.appl-flash {
  margin-bottom: 1.5rem;
  padding: 1rem 1.25rem;
  border-radius: .875rem;
  display: flex;
  align-items: center;
  gap: .75rem;
  animation: applSlideUp .3s cubic-bezier(.22,.68,0,1.2) both;
  font-weight: 500;
}
.appl-flash-ok  { background:rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  color:rgba(21,128,61,1);  }
.appl-flash-err { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:rgba(185,28,28,1); }

/* Application cards */
.appl-card {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.5rem;
  transition: box-shadow .25s, transform .2s, border-color .2s;
  position: relative;
  overflow: hidden;
  animation: applSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
}
.appl-card::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  background: linear-gradient(180deg, rgba(124,58,237,1), rgba(99,102,241,1));
  border-radius: 4px 0 0 4px;
  opacity: .65;
  transition: opacity .2s;
}
.appl-card:hover { box-shadow: 0 8px 28px rgba(124,58,237,.12); border-color: rgba(124,58,237,.3); transform: translateY(-2px); }
.appl-card:hover::before { opacity: 1; }

.appl-card:nth-child(1) { animation-delay: .05s; }
.appl-card:nth-child(2) { animation-delay: .10s; }
.appl-card:nth-child(3) { animation-delay: .15s; }
.appl-card:nth-child(4) { animation-delay: .20s; }
.appl-card:nth-child(5) { animation-delay: .25s; }
.appl-card:nth-child(n+6) { animation-delay: .30s; }

.appl-project-tag {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .25rem .75rem; border-radius: 999px;
  font-size: .75rem; font-weight: 600;
  background: rgba(124,58,237,.1); color: rgba(109,40,217,1);
  border: 1px solid rgba(124,58,237,.2);
  margin-bottom: .75rem;
}

.appl-motivation {
  border-radius: .625rem;
  background: var(--bg-body);
  border: 1px solid var(--border-color);
  padding: .875rem 1rem;
  font-size: .875rem;
  color: var(--text-main);
  line-height: 1.6;
  margin-bottom: 1rem;
}

.appl-meta {
  display: flex; align-items: center; gap: 1rem;
  flex-wrap: wrap; font-size: .8rem; color: var(--text-muted);
  margin-bottom: 1.25rem;
}

.appl-actions { display:flex; gap:.75rem; flex-wrap:wrap; padding-top:1rem; border-top:1px solid var(--border-color); }

.appl-btn-accept, .appl-btn-reject {
  flex: 1;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  padding: .7rem 1.25rem;
  border-radius: .75rem;
  cursor: pointer;
  font-weight: 600; font-size: .875rem;
  transition: background .2s, transform .15s, box-shadow .15s;
  min-height: 44px;
}
.appl-btn-accept { background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3); }
.appl-btn-accept:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); box-shadow:0 4px 12px rgba(34,197,94,.2); }
.appl-btn-reject { background:rgba(239,68,68,.1); color:rgba(185,28,28,1); border:1px solid rgba(239,68,68,.3); }
.appl-btn-reject:hover { background:rgba(239,68,68,.2); transform:translateY(-1px); box-shadow:0 4px 12px rgba(239,68,68,.15); }

/* Modal */
.appl-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45); backdrop-filter: blur(4px);
  z-index: 200;
  display: flex; align-items: center; justify-content: center;
  padding: 1rem;
  opacity: 0; pointer-events: none;
  transition: opacity .25s;
}
.appl-modal-overlay.open { opacity:1; pointer-events:auto; }
.appl-modal {
  background-color: var(--bg-card);
  border-radius: 1.25rem;
  width: 100%; max-width: 480px; max-height: 85vh;
  overflow: hidden; display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.3);
  transform: translateY(20px) scale(.97);
  transition: transform .3s cubic-bezier(.22,.68,0,1.2);
}
.appl-modal-overlay.open .appl-modal { transform: translateY(0) scale(1); }
.appl-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);
}
.appl-modal-title { font-size:1rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:.5rem; margin:0; }
.appl-modal-close { background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1.2rem; padding:.25rem .4rem; border-radius:.375rem; transition:background .15s; }
.appl-modal-close:hover { background:rgba(0,0,0,.06); }
.appl-modal-body { padding:1.5rem; overflow-y:auto; flex:1; }
.appl-modal-footer { padding:1rem 1.5rem; border-top:1px solid var(--border-color); display:flex; gap:.75rem; }

.appl-select {
  width: 100%; padding: .625rem 1rem;
  border-radius: .625rem; border: 1px solid var(--border-color);
  background: var(--bg-body); color: var(--text-main);
  font-size: .9rem; outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.appl-select:focus { border-color:rgba(34,197,94,.5); box-shadow:0 0 0 3px rgba(34,197,94,.1); }

/* Empty state */
.appl-empty {
  text-align: center; padding: 4rem 1rem; color: var(--text-muted);
  border-radius: 1rem; border: 1px solid var(--border-color);
  background-color: var(--bg-card);
}
.appl-empty i { font-size:3.5rem; margin-bottom:1rem; display:block; opacity:.3; }

/* Mobile: bottom-sheet modal + responsive */
@media (max-width:600px) {
  .appl-modal-overlay { align-items:flex-end; padding:0; }
  .appl-modal { border-radius:1.25rem 1.25rem 0 0; max-height:92vh; max-width:100%; }
}
@media (max-width:480px) {
  .appl-page-header { flex-direction:column; align-items:flex-start; }
  .appl-page-title { font-size:1.35rem; }
}
</style>

<div class="appl-page">

<!-- Page Header -->
<div class="appl-page-header">
  <div class="appl-page-header-left">
    <div class="appl-header-icon">
      <i class="fas fa-briefcase" style="color:#fff;font-size:1.35rem;"></i>
    </div>
    <div>
      <h1 class="appl-page-title">Bewerbungsverwaltung</h1>
      <p class="appl-page-sub">Alle offenen Projektbewerbungen &bull; <?php echo count($applications); ?> ausstehend</p>
    </div>
  </div>
</div>

<?php if ($message): ?>
<div class="appl-flash appl-flash-ok">
  <i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
  <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="appl-flash appl-flash-err">
  <i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
  <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Applications List -->
<?php if (empty($applications)): ?>
<div class="appl-empty">
  <i class="fas fa-inbox"></i>
  <h3 style="font-size:1.1rem;font-weight:700;color:var(--text-main);margin:0 0 .5rem;">Keine offenen Bewerbungen</h3>
  <p style="margin:0;font-size:.9rem;">Aktuell liegen keine ausstehenden Bewerbungen vor.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1rem;">
  <?php foreach ($applications as $application): ?>
  <div class="appl-card">
    <!-- Project tag + status -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
      <div class="appl-project-tag">
        <i class="fas fa-briefcase"></i>
        <?php echo htmlspecialchars($application['project_title']); ?>
      </div>
      <span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;background:rgba(234,179,8,.12);color:rgba(161,98,7,1);border:1px solid rgba(234,179,8,.35);">
        Ausstehend
      </span>
    </div>

    <!-- User -->
    <div style="display:flex;align-items:center;gap:.625rem;margin-bottom:.875rem;">
      <div style="width:2.5rem;height:2.5rem;border-radius:.625rem;background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(99,102,241,.2));display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-user" style="color:rgba(109,40,217,1);"></i>
      </div>
      <div>
        <div style="font-size:1rem;font-weight:700;color:var(--text-main);word-break:break-all;">
          <?php echo htmlspecialchars($application['user_email']); ?>
        </div>
      </div>
    </div>

    <!-- Meta -->
    <div class="appl-meta">
      <span><i class="fas fa-calendar" style="margin-right:.3rem;"></i><?php echo date('d.m.Y H:i', strtotime($application['created_at'])); ?></span>
      <span><i class="fas fa-star" style="margin-right:.3rem;"></i><?php echo $application['experience_count']; ?> Projekt(e) Erfahrung</span>
    </div>

    <!-- Motivation -->
    <?php if (!empty($application['motivation'])): ?>
    <div class="appl-motivation">
      <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;">Motivation</div>
      <?php echo nl2br(htmlspecialchars($application['motivation'])); ?>
    </div>
    <?php endif; ?>

    <!-- Action buttons -->
    <div class="appl-actions">
      <button class="appl-btn-accept"
              data-application-id="<?php echo $application['id']; ?>"
              data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>">
        <i class="fas fa-check"></i>Akzeptieren
      </button>
      <button class="appl-btn-reject"
              data-application-id="<?php echo $application['id']; ?>"
              data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>">
        <i class="fas fa-times"></i>Ablehnen
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- .appl-page -->

<!-- Accept Modal -->
<div id="acceptModal" class="appl-modal-overlay">
  <div class="appl-modal">
    <div class="appl-modal-header">
      <h3 class="appl-modal-title">
        <i class="fas fa-check-circle" style="color:rgba(21,128,61,1);"></i>
        Bewerbung akzeptieren
      </h3>
      <button type="button" class="appl-modal-close" onclick="closeAcceptModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST" id="acceptForm">
      <div class="appl-modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="application_id" id="acceptApplicationId" value="">
        <input type="hidden" name="accept_application" value="1">
        <p style="color:var(--text-muted);margin:0 0 1.25rem;font-size:.9rem;">
          Bewerbung von <strong style="color:var(--text-main);" id="acceptUserEmail"></strong> akzeptieren.
        </p>
        <div>
          <label style="display:block;font-size:.8rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;">
            Rolle auswählen <span style="color:rgba(185,28,28,1);">*</span>
          </label>
          <select name="role" required class="appl-select">
            <option value="member">Member (Mitglied)</option>
            <option value="lead">Lead (Projektleitung)</option>
          </select>
        </div>
      </div>
      <div class="appl-modal-footer">
        <button type="button" onclick="closeAcceptModal()"
                style="flex:1;padding:.7rem 1rem;border-radius:.75rem;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-muted);font-weight:600;cursor:pointer;font-size:.875rem;transition:background .2s;">
          Abbrechen
        </button>
        <button type="submit"
                style="flex:1;padding:.7rem 1rem;border-radius:.75rem;border:1px solid rgba(34,197,94,.3);background:rgba(34,197,94,.12);color:rgba(21,128,61,1);font-weight:600;cursor:pointer;font-size:.875rem;transition:background .2s;">
          <i class="fas fa-check" style="margin-right:.35rem;"></i>Akzeptieren
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="appl-modal-overlay">
  <div class="appl-modal">
    <div class="appl-modal-header">
      <h3 class="appl-modal-title">
        <i class="fas fa-exclamation-triangle" style="color:rgba(185,28,28,1);"></i>
        Bewerbung ablehnen
      </h3>
      <button type="button" class="appl-modal-close" onclick="closeRejectModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="appl-modal-body">
      <p style="color:var(--text-muted);margin:0;font-size:.9rem;">
        Möchtest Du die Bewerbung von <strong style="color:var(--text-main);" id="rejectUserEmail"></strong> wirklich ablehnen?
      </p>
    </div>
    <form method="POST" id="rejectForm">
      <div class="appl-modal-footer">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="application_id" id="rejectApplicationId" value="">
        <input type="hidden" name="reject_application" value="1">
        <button type="button" onclick="closeRejectModal()"
                style="flex:1;padding:.7rem 1rem;border-radius:.75rem;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-muted);font-weight:600;cursor:pointer;font-size:.875rem;transition:background .2s;">
          Abbrechen
        </button>
        <button type="submit"
                style="flex:1;padding:.7rem 1rem;border-radius:.75rem;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.12);color:rgba(185,28,28,1);font-weight:600;cursor:pointer;font-size:.875rem;transition:background .2s;">
          <i class="fas fa-times" style="margin-right:.35rem;"></i>Ablehnen
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.appl-btn-accept').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('acceptApplicationId').value = this.dataset.applicationId;
        document.getElementById('acceptUserEmail').textContent = this.dataset.userEmail;
        document.getElementById('acceptModal').classList.add('open');
    });
});

document.querySelectorAll('.appl-btn-reject').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('rejectApplicationId').value = this.dataset.applicationId;
        document.getElementById('rejectUserEmail').textContent = this.dataset.userEmail;
        document.getElementById('rejectModal').classList.add('open');
    });
});

function closeAcceptModal() {
    document.getElementById('acceptModal').classList.remove('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}

// Close on backdrop click
document.getElementById('acceptModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeAcceptModal();
});
document.getElementById('rejectModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeRejectModal();
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeAcceptModal(); closeRejectModal(); }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
