<?php
/**
 * Admin: Neue Alumni-Anfragen Verwaltung
 * Displays all new alumni registration requests and allows approving/rejecting pending ones.
 * Access: alumni_finanz, alumni_vorstand, vorstand_finanzen, vorstand_extern, vorstand_intern
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/NewAlumniRequest.php';
require_once __DIR__ . '/../../includes/helpers.php';

// --- Strict role check ---
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$allowedRoles = ['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    include __DIR__ . '/../../includes/templates/403.php';
    exit;
}

// --- Load data ---
$requests = NewAlumniRequest::getAll();
$counts   = NewAlumniRequest::countByStatus();

$csrfToken = CSRFHandler::getToken();

ob_start();
?>

<style>
/* ── Neue Alumni-Anfragen ────────────────────────────── */
@keyframes nalmSlideUp {
  from { opacity:0; transform:translateY(18px) scale(.98); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.nalm-page { animation: nalmSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.nalm-page-header { display:flex; align-items:center; gap:1rem; margin-bottom:1.75rem; flex-wrap:wrap; }
.nalm-header-icon {
  width:3rem; height:3rem; border-radius:.875rem;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 14px rgba(37,99,235,.4); flex-shrink:0;
}
.nalm-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0 0 .2rem; }
.nalm-page-sub   { color:var(--text-muted); margin:0; font-size:.9rem; }

/* Stat grid */
.nalm-stat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 2rem;
}
@media (max-width:640px) { .nalm-stat-grid { grid-template-columns:repeat(2,1fr); gap:.75rem; } }
@media (max-width:400px) { .nalm-stat-grid { grid-template-columns:1fr; } }

.nalm-stat {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.25rem 1.5rem;
  display: flex; align-items: center; gap: 1rem;
  transition: box-shadow .25s, transform .2s;
  animation: nalmSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
}
.nalm-stat:nth-child(1) { animation-delay:.05s; }
.nalm-stat:nth-child(2) { animation-delay:.10s; }
.nalm-stat:nth-child(3) { animation-delay:.15s; }
.nalm-stat:hover { box-shadow:0 6px 20px rgba(0,0,0,.08); transform:translateY(-2px); }

.nalm-stat-icon { width:2.75rem; height:2.75rem; border-radius:.75rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.nalm-stat-val  { font-size:1.75rem; font-weight:800; line-height:1; margin-bottom:.2rem; }
.nalm-stat-lbl  { font-size:.8rem; color:var(--text-muted); font-weight:500; }

/* Table */
.nalm-table-wrap   { border-radius:1rem; border:1px solid var(--border-color); background-color:var(--bg-card); overflow:hidden; }
.nalm-table-scroll { overflow-x:auto; width:100%; }

.nalm-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.nalm-table thead tr { border-bottom:2px solid var(--border-color); background:rgba(37,99,235,.05); }
.nalm-table th { padding:.75rem 1rem; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; }
.nalm-table tbody tr { border-bottom:1px solid var(--border-color); transition:background .15s; }
.nalm-table tbody tr:last-child { border-bottom:none; }
.nalm-table tbody tr:hover { background:rgba(37,99,235,.04); }
.nalm-table td { padding:.75rem 1rem; color:var(--text-main); vertical-align:middle; }

/* Action buttons */
.nalm-btn-approve, .nalm-btn-reject {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .4rem .9rem; min-height: 38px;
  font-size: .8rem; font-weight: 600; border-radius: .5rem;
  cursor: pointer; transition: background .2s, transform .15s, box-shadow .15s;
}
.nalm-btn-approve { background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3); }
.nalm-btn-approve:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); box-shadow:0 3px 10px rgba(34,197,94,.2); }
.nalm-btn-reject  { background:rgba(239,68,68,.1);  color:rgba(185,28,28,1);  border:1px solid rgba(239,68,68,.3); }
.nalm-btn-reject:hover  { background:rgba(239,68,68,.2);  transform:translateY(-1px); box-shadow:0 3px 10px rgba(239,68,68,.15); }

/* Toast */
#nalm-toast {
  position:fixed; bottom:1.5rem; right:1.5rem; z-index:100;
  display:flex; align-items:center; gap:.75rem;
  padding:.8rem 1.25rem; border-radius:.875rem;
  box-shadow:0 8px 24px rgba(0,0,0,.2); color:#fff;
  font-size:.875rem; font-weight:500;
  opacity:0; pointer-events:none; transform:translateY(10px);
  transition:opacity .3s, transform .3s cubic-bezier(.22,.68,0,1.2);
  min-width:220px;
}
#nalm-toast.open { opacity:1; pointer-events:auto; transform:translateY(0); }
@media (max-width:480px) {
  #nalm-toast { left:1rem; right:1rem; min-width:0; bottom:1rem; border-radius:.75rem; }
}

/* Empty state */
.nalm-empty { text-align:center; padding:3.5rem 1rem; color:var(--text-muted); }
.nalm-empty i { font-size:3rem; margin-bottom:.75rem; display:block; opacity:.4; }

/* Hide helpers */
@media (max-width:767px)  { .nalm-hide-md { display:none !important; } }
@media (max-width:1023px) { .nalm-hide-lg { display:none !important; } }
@media (max-width:639px)  { .nalm-hide-sm { display:none !important; } }

/* Mobile table cards */
@media (max-width:640px) {
  .nalm-table thead { display:none; }
  .nalm-table, .nalm-table tbody, .nalm-table tr, .nalm-table td { display:block; width:100%; }
  .nalm-table tr { padding:.875rem 1rem; border-bottom:1px solid var(--border-color); }
  .nalm-table td { padding:.3rem 0; border:none; display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
  .nalm-table td::before {
    content: attr(data-label) ': ';
    font-weight:700; font-size:.7rem; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; flex-shrink:0;
  }
  .nalm-btn-approve, .nalm-btn-reject { min-height:44px; padding:.6rem 1rem; }
}
</style>

<div class="nalm-page">

<!-- Page Header -->
<div class="nalm-page-header">
  <div class="nalm-header-icon">
    <i class="fas fa-user-plus" style="color:#fff;font-size:1.35rem;"></i>
  </div>
  <div>
    <h1 class="nalm-page-title">Neue Alumni-Anfragen</h1>
    <p class="nalm-page-sub">Verwaltung der eingehenden Neue-Alumni-Registrierungsanfragen</p>
  </div>
</div>

<!-- Toast -->
<div id="nalm-toast">
  <i id="nalm-toast-icon" class="fas fa-check-circle"></i>
  <span id="nalm-toast-msg"></span>
</div>

<!-- Stat Cards -->
<div class="nalm-stat-grid">
  <div class="nalm-stat">
    <div class="nalm-stat-icon" style="background:rgba(234,179,8,.12);">
      <i class="fas fa-clock" style="color:rgba(161,98,7,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="nalm-stat-val" style="color:rgba(161,98,7,1);"><?php echo $counts['pending']; ?></div>
      <div class="nalm-stat-lbl">Ausstehend</div>
    </div>
  </div>
  <div class="nalm-stat">
    <div class="nalm-stat-icon" style="background:rgba(34,197,94,.12);">
      <i class="fas fa-check-circle" style="color:rgba(21,128,61,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="nalm-stat-val" style="color:rgba(21,128,61,1);"><?php echo $counts['approved']; ?></div>
      <div class="nalm-stat-lbl">Akzeptiert</div>
    </div>
  </div>
  <div class="nalm-stat">
    <div class="nalm-stat-icon" style="background:rgba(239,68,68,.12);">
      <i class="fas fa-times-circle" style="color:rgba(185,28,28,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="nalm-stat-val" style="color:rgba(185,28,28,1);"><?php echo $counts['rejected']; ?></div>
      <div class="nalm-stat-lbl">Abgelehnt</div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="nalm-table-wrap">
  <div class="nalm-table-scroll">
    <table class="nalm-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>E-Mail (neu)</th>
          <th class="nalm-hide-md">E-Mail (alt)</th>
          <th class="nalm-hide-lg">Studiengang</th>
          <th class="nalm-hide-lg">Semester</th>
          <th class="nalm-hide-sm">Alumni Vertrag</th>
          <th class="nalm-hide-sm">Eingereicht</th>
          <th>Status</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody id="requests-table-body">
        <?php if (empty($requests)): ?>
        <tr>
          <td colspan="9">
            <div class="nalm-empty">
              <i class="fas fa-inbox"></i>
              Keine Anfragen vorhanden
            </div>
          </td>
        </tr>
        <?php else: ?>
        <?php
        $statusStyles = [
            'pending'  => ['bg'=>'rgba(234,179,8,0.12)',  'color'=>'rgba(161,98,7,1)',   'border'=>'rgba(234,179,8,0.35)',  'label'=>'Ausstehend'],
            'approved' => ['bg'=>'rgba(34,197,94,0.12)',  'color'=>'rgba(21,128,61,1)',  'border'=>'rgba(34,197,94,0.35)',  'label'=>'Akzeptiert'],
            'rejected' => ['bg'=>'rgba(239,68,68,0.12)',  'color'=>'rgba(185,28,28,1)',  'border'=>'rgba(239,68,68,0.35)',  'label'=>'Abgelehnt'],
        ];
        foreach ($requests as $req):
            $ss = $statusStyles[$req['status']] ?? ['bg'=>'rgba(156,163,175,0.12)','color'=>'rgba(107,114,128,1)','border'=>'rgba(156,163,175,0.35)','label'=>htmlspecialchars($req['status'])];
        ?>
        <tr id="row-<?php echo (int)$req['id']; ?>">
          <td data-label="Name" style="font-weight:600;">
            <?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="E-Mail (neu)" style="color:var(--text-muted);word-break:break-all;">
            <?php echo htmlspecialchars($req['new_email'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="E-Mail (alt)" style="color:var(--text-muted);word-break:break-all;" class="nalm-hide-md">
            <?php if ($req['old_email']): ?>
              <?php echo htmlspecialchars($req['old_email'], ENT_QUOTES, 'UTF-8'); ?>
            <?php else: ?><span style="opacity:.45;font-style:italic;">—</span><?php endif; ?>
          </td>
          <td data-label="Studiengang" style="color:var(--text-muted);" class="nalm-hide-lg">
            <?php echo htmlspecialchars($req['study_program'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="Semester" style="color:var(--text-muted);" class="nalm-hide-lg">
            <?php echo htmlspecialchars($req['graduation_semester'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="Alumni Vertrag" class="nalm-hide-sm">
            <?php if ($req['has_alumni_contract']): ?>
              <span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;border:1px solid;background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border-color:rgba(34,197,94,.35);">
                <i class="fas fa-check" style="margin-right:.3rem;"></i>Ja
              </span>
            <?php else: ?>
              <span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;border:1px solid;background:rgba(249,115,22,.1);color:rgba(194,65,12,1);border-color:rgba(249,115,22,.3);">
                <i class="fas fa-times" style="margin-right:.3rem;"></i>Nein
              </span>
            <?php endif; ?>
          </td>
          <td data-label="Eingereicht" style="color:var(--text-muted);white-space:nowrap;" class="nalm-hide-sm">
            <?php echo date('d.m.Y H:i', strtotime($req['created_at'])); ?>
          </td>
          <td data-label="Status">
            <span class="status-badge-<?php echo (int)$req['id']; ?>"
                  style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;border:1px solid;white-space:nowrap;background:<?php echo $ss['bg']; ?>;color:<?php echo $ss['color']; ?>;border-color:<?php echo $ss['border']; ?>;">
              <?php echo $ss['label']; ?>
            </span>
          </td>
          <td data-label="Aktionen">
            <?php if ($req['status'] === 'pending'): ?>
            <div class="flex gap-2 flex-wrap action-buttons-<?php echo (int)$req['id']; ?>">
              <button onclick="handleAction(<?php echo (int)$req['id']; ?>, 'approve')"
                      class="nalm-btn-approve" title="Akzeptieren & Alumni-Zugang einrichten">
                <i class="fas fa-user-check"></i>
                <span>Akzeptieren</span>
              </button>
              <button onclick="handleAction(<?php echo (int)$req['id']; ?>, 'reject')"
                      class="nalm-btn-reject" title="Ablehnen">
                <i class="fas fa-user-times"></i>
                <span>Ablehnen</span>
              </button>
            </div>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:.8rem;font-style:italic;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- .nalm-page -->

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const API_URL    = <?php echo json_encode(asset('api/admin/process_neue_alumni_request.php')); ?>;

const STATUS_STYLES = {
    approved: { bg:'rgba(34,197,94,0.12)',  color:'rgba(21,128,61,1)',  border:'rgba(34,197,94,0.35)',  label:'Akzeptiert' },
    rejected:  { bg:'rgba(239,68,68,0.12)', color:'rgba(185,28,28,1)', border:'rgba(239,68,68,0.35)', label:'Abgelehnt'  }
};

function showToast(message, type) {
    const toast     = document.getElementById('nalm-toast');
    const toastMsg  = document.getElementById('nalm-toast-msg');
    const toastIcon = document.getElementById('nalm-toast-icon');

    toastMsg.textContent = message;
    if (type === 'success') {
        toast.style.background = 'rgba(22,163,74,1)';
        toastIcon.className    = 'fas fa-check-circle';
    } else if (type === 'warning') {
        toast.style.background = 'rgba(202,138,4,1)';
        toastIcon.className    = 'fas fa-exclamation-triangle';
    } else {
        toast.style.background = 'rgba(220,38,38,1)';
        toastIcon.className    = 'fas fa-times-circle';
    }
    toast.classList.add('open');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('open'), 4500);
}

async function handleAction(requestId, action) {
    const label = action === 'approve' ? 'akzeptieren' : 'ablehnen';
    if (!confirm(`Anfrage wirklich ${label}?`)) return;

    const btnsEl = document.querySelector(`.action-buttons-${requestId}`);
    if (btnsEl) btnsEl.querySelectorAll('button').forEach(b => {
        b.disabled = true; b.style.opacity = '.5'; b.style.cursor = 'not-allowed';
    });

    const formData = new FormData();
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('request_id', requestId);
    formData.append('action', action);

    try {
        const resp = await fetch(API_URL, { method:'POST', body:formData });
        const data = await resp.json();

        if (data.success) {
            const badge = document.querySelector(`.status-badge-${requestId}`);
            if (badge) {
                const key = action === 'approve' ? 'approved' : 'rejected';
                const ss  = STATUS_STYLES[key];
                badge.textContent       = ss.label;
                badge.style.background  = ss.bg;
                badge.style.color       = ss.color;
                badge.style.borderColor = ss.border;
            }
            if (btnsEl) btnsEl.innerHTML = '<span style="color:var(--text-muted);font-size:.8rem;font-style:italic;">—</span>';

            const toastType = data.warning ? 'warning' : 'success';
            const toastMsg  = data.warning ? `Gespeichert – Entra-Warnung: ${data.warning}` : data.message;
            showToast(toastMsg, toastType);
        } else {
            showToast(data.message || 'Fehler beim Verarbeiten der Anfrage', 'error');
            if (btnsEl) btnsEl.querySelectorAll('button').forEach(b => {
                b.disabled = false; b.style.opacity = ''; b.style.cursor = '';
            });
        }
    } catch (err) {
        showToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
        if (btnsEl) btnsEl.querySelectorAll('button').forEach(b => {
            b.disabled = false; b.style.opacity = ''; b.style.cursor = '';
        });
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
