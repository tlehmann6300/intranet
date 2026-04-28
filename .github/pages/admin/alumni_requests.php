<?php
/**
 * Admin: Alumni-Anfragen Verwaltung
 * Displays all alumni access requests and allows approving/rejecting pending ones.
 * Access: alumni_finanz, alumni_vorstand, vorstand_finanzen, vorstand_extern, vorstand_intern
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';
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
$requests = AlumniAccessRequest::getAll();
$counts   = AlumniAccessRequest::countByStatus();

$csrfToken = CSRFHandler::getToken();

ob_start();
?>

<style>
/* ── Alumni-Anfragen ─────────────────────────────────── */
@keyframes almSlideUp {
  from { opacity:0; transform:translateY(18px) scale(.98); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.alm-page { animation: almSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.alm-page-header { display:flex; align-items:center; gap:1rem; margin-bottom:1.75rem; flex-wrap:wrap; }
.alm-header-icon {
  width:3rem; height:3rem; border-radius:.875rem;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 14px rgba(37,99,235,.4); flex-shrink:0;
}
.alm-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0 0 .2rem; }
.alm-page-sub   { color:var(--text-muted); margin:0; font-size:.9rem; }

/* Stat grid */
.alm-stat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 2rem;
}
@media (max-width:640px) { .alm-stat-grid { grid-template-columns:repeat(2,1fr); gap:.75rem; } }
@media (max-width:400px) { .alm-stat-grid { grid-template-columns:1fr; } }

.alm-stat {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.25rem 1.5rem;
  display: flex; align-items: center; gap: 1rem;
  transition: box-shadow .25s, transform .2s;
  animation: almSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
}
.alm-stat:nth-child(1) { animation-delay:.05s; }
.alm-stat:nth-child(2) { animation-delay:.10s; }
.alm-stat:nth-child(3) { animation-delay:.15s; }
.alm-stat:hover { box-shadow:0 6px 20px rgba(0,0,0,.08); transform:translateY(-2px); }

.alm-stat-icon { width:2.75rem; height:2.75rem; border-radius:.75rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.alm-stat-val  { font-size:1.75rem; font-weight:800; line-height:1; margin-bottom:.2rem; }
.alm-stat-lbl  { font-size:.8rem; color:var(--text-muted); font-weight:500; }

/* Table wrapper */
.alm-table-wrap   { border-radius:1rem; border:1px solid var(--border-color); background-color:var(--bg-card); overflow:hidden; }
.alm-table-scroll { overflow-x:auto; width:100%; }

.alm-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.alm-table thead tr { border-bottom:2px solid var(--border-color); background:rgba(37,99,235,.05); }
.alm-table th { padding:.75rem 1rem; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; }
.alm-table tbody tr { border-bottom:1px solid var(--border-color); transition:background .15s; }
.alm-table tbody tr:last-child { border-bottom:none; }
.alm-table tbody tr:hover { background:rgba(37,99,235,.04); }
.alm-table td { padding:.75rem 1rem; color:var(--text-main); vertical-align:middle; }

/* Action buttons */
.alm-btn-approve, .alm-btn-reject {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .4rem .9rem; min-height: 38px;
  font-size: .8rem; font-weight: 600; border-radius: .5rem;
  cursor: pointer; transition: background .2s, transform .15s, box-shadow .15s;
}
.alm-btn-approve { background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3); }
.alm-btn-approve:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); box-shadow:0 3px 10px rgba(34,197,94,.2); }
.alm-btn-reject  { background:rgba(239,68,68,.1);  color:rgba(185,28,28,1);  border:1px solid rgba(239,68,68,.3); }
.alm-btn-reject:hover  { background:rgba(239,68,68,.2);  transform:translateY(-1px); box-shadow:0 3px 10px rgba(239,68,68,.15); }

/* Toast */
#alm-toast {
  position:fixed; bottom:1.5rem; right:1.5rem; z-index:100;
  display:flex; align-items:center; gap:.75rem;
  padding:.8rem 1.25rem; border-radius:.875rem;
  box-shadow:0 8px 24px rgba(0,0,0,.2); color:#fff;
  font-size:.875rem; font-weight:500;
  opacity:0; pointer-events:none; transform:translateY(10px);
  transition:opacity .3s, transform .3s cubic-bezier(.22,.68,0,1.2);
  min-width:220px;
}
#alm-toast.open { opacity:1; pointer-events:auto; transform:translateY(0); }

@media (max-width:480px) {
  #alm-toast { left:1rem; right:1rem; min-width:0; bottom:1rem; border-radius:.75rem; }
}

/* Empty state */
.alm-empty { text-align:center; padding:3.5rem 1rem; color:var(--text-muted); }
.alm-empty i { font-size:3rem; margin-bottom:.75rem; display:block; opacity:.4; }

/* Mobile table cards */
@media (max-width:640px) {
  .alm-table thead { display:none; }
  .alm-table, .alm-table tbody, .alm-table tr, .alm-table td { display:block; width:100%; }
  .alm-table tr { padding:.875rem 1rem; border-bottom:1px solid var(--border-color); }
  .alm-table td { padding:.3rem 0; border:none; display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
  .alm-table td::before {
    content: attr(data-label) ': ';
    font-weight:700; font-size:.7rem; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; flex-shrink:0;
  }
  .alm-btn-approve, .alm-btn-reject { min-height:44px; padding:.6rem 1rem; }
}
</style>

<div class="alm-page">

<!-- Page Header -->
<div class="alm-page-header">
  <div class="alm-header-icon">
    <i class="fas fa-user-graduate" style="color:#fff;font-size:1.35rem;"></i>
  </div>
  <div>
    <h1 class="alm-page-title">Alumni-Anfragen</h1>
    <p class="alm-page-sub">Verwaltung der eingehenden Alumni-Zugangsanfragen</p>
  </div>
</div>

<!-- Toast -->
<div id="alm-toast">
  <i id="alm-toast-icon" class="fas fa-check-circle"></i>
  <span id="alm-toast-msg"></span>
</div>

<!-- Stat Cards -->
<div class="alm-stat-grid">
  <div class="alm-stat">
    <div class="alm-stat-icon" style="background:rgba(234,179,8,.12);">
      <i class="fas fa-clock" style="color:rgba(161,98,7,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="alm-stat-val" style="color:rgba(161,98,7,1);"><?php echo $counts['pending']; ?></div>
      <div class="alm-stat-lbl">Ausstehend</div>
    </div>
  </div>
  <div class="alm-stat">
    <div class="alm-stat-icon" style="background:rgba(34,197,94,.12);">
      <i class="fas fa-check-circle" style="color:rgba(21,128,61,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="alm-stat-val" style="color:rgba(21,128,61,1);"><?php echo $counts['approved']; ?></div>
      <div class="alm-stat-lbl">Akzeptiert</div>
    </div>
  </div>
  <div class="alm-stat">
    <div class="alm-stat-icon" style="background:rgba(239,68,68,.12);">
      <i class="fas fa-times-circle" style="color:rgba(185,28,28,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="alm-stat-val" style="color:rgba(185,28,28,1);"><?php echo $counts['rejected']; ?></div>
      <div class="alm-stat-lbl">Abgelehnt</div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="alm-table-wrap">
  <div class="alm-table-scroll">
    <table class="alm-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>E-Mail (neu)</th>
          <th class="hidden-md">E-Mail (alt)</th>
          <th class="hidden-lg">Studiengang</th>
          <th class="hidden-lg">Semester</th>
          <th class="hidden-sm">Eingereicht</th>
          <th>Status</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody id="requests-table-body">
        <?php if (empty($requests)): ?>
        <tr>
          <td colspan="8">
            <div class="alm-empty">
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
          <td data-label="E-Mail (alt)" style="color:var(--text-muted);word-break:break-all;" class="hidden-md">
            <?php if ($req['old_email']): ?>
              <?php echo htmlspecialchars($req['old_email'], ENT_QUOTES, 'UTF-8'); ?>
            <?php else: ?><span style="opacity:.45;font-style:italic;">—</span><?php endif; ?>
          </td>
          <td data-label="Studiengang" style="color:var(--text-muted);" class="hidden-lg">
            <?php echo htmlspecialchars($req['study_program'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="Semester" style="color:var(--text-muted);" class="hidden-lg">
            <?php echo htmlspecialchars($req['graduation_semester'], ENT_QUOTES, 'UTF-8'); ?>
          </td>
          <td data-label="Eingereicht" style="color:var(--text-muted);white-space:nowrap;" class="hidden-sm">
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
                      class="alm-btn-approve" title="Akzeptieren & in Entra anlegen">
                <i class="fas fa-user-check"></i>
                <span>Akzeptieren</span>
              </button>
              <button onclick="handleAction(<?php echo (int)$req['id']; ?>, 'reject')"
                      class="alm-btn-reject" title="Ablehnen">
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

</div><!-- .alm-page -->

<style>
@media (max-width:767px)  { .hidden-md { display:none !important; } }
@media (max-width:1023px) { .hidden-lg { display:none !important; } }
@media (max-width:639px)  { .hidden-sm { display:none !important; } }
</style>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const API_URL    = <?php echo json_encode(asset('api/process_alumni_request.php')); ?>;

const STATUS_STYLES = {
    approved: { bg:'rgba(34,197,94,0.12)',  color:'rgba(21,128,61,1)',  border:'rgba(34,197,94,0.35)',  label:'Akzeptiert' },
    rejected:  { bg:'rgba(239,68,68,0.12)', color:'rgba(185,28,28,1)', border:'rgba(239,68,68,0.35)', label:'Abgelehnt'  }
};

function showToast(message, type) {
    const toast     = document.getElementById('alm-toast');
    const toastMsg  = document.getElementById('alm-toast-msg');
    const toastIcon = document.getElementById('alm-toast-icon');

    toastMsg.textContent = message;
    if (type === 'success') {
        toast.style.background  = 'rgba(22,163,74,1)';
        toastIcon.className     = 'fas fa-check-circle';
    } else if (type === 'warning') {
        toast.style.background  = 'rgba(202,138,4,1)';
        toastIcon.className     = 'fas fa-exclamation-triangle';
    } else {
        toast.style.background  = 'rgba(220,38,38,1)';
        toastIcon.className     = 'fas fa-times-circle';
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
                badge.textContent        = ss.label;
                badge.style.background   = ss.bg;
                badge.style.color        = ss.color;
                badge.style.borderColor  = ss.border;
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
