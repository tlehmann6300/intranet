<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/VCard.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Only Vorstand (vorstand_finanzen, vorstand_intern, vorstand_extern) and Ressortleiter (ressortleiter) may access this page
if (!Auth::check() || !Auth::canCreateBasicContent()) {
    header('Location: ../auth/login.php');
    exit;
}

$vcardError = null;
try {
    $vcards = VCard::getAll();
} catch (Exception $e) {
    error_log("VCard page: failed to load vCards – " . $e->getMessage());
    $vcards     = [];
    $vcardError = 'Die Verbindung zur vCard-Datenbank ist fehlgeschlagen. Bitte später erneut versuchen.';
}
$csrfToken = CSRFHandler::getToken();

/** Return initials (max 2 chars) from first + last name */
function vcInitials(string $v, string $n): string {
    $i = mb_strtoupper(mb_substr(trim($v), 0, 1));
    $i .= mb_strtoupper(mb_substr(trim($n), 0, 1));
    return $i ?: '?';
}

/** Deterministic gradient from name hash */
function vcGradient(string $name): string {
    $palettes = [
        ['135deg,rgba(124,58,237,1),rgba(99,102,241,1)'],
        ['135deg,rgba(37,99,235,1),rgba(14,165,233,1)'],
        ['135deg,rgba(13,148,136,1),rgba(5,150,105,1)'],
        ['135deg,rgba(249,115,22,1),rgba(234,179,8,1)'],
        ['135deg,rgba(236,72,153,1),rgba(168,85,247,1)'],
        ['135deg,rgba(239,68,68,1),rgba(249,115,22,1)'],
    ];
    $idx = abs(crc32($name)) % count($palettes);
    return 'linear-gradient(' . $palettes[$idx][0] . ')';
}

$title = 'vCards verwalten - IBC Intranet';
ob_start();
?>

<style>
/* ══ vCards — Digitale Visitenkarten ══════════════════════════════
   Prefix: vc-   Keyframe: vcSlideUp
   Breakpoints: 400 / 480 / 540 / 640 / 900
════════════════════════════════════════════════════════════════ */
@keyframes vcSlideUp {
    from { opacity:0; transform:translateY(18px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
@keyframes vcFadeIn {
    from { opacity:0; }
    to   { opacity:1; }
}
.vc-page { animation: vcSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* ── Page header ─────────────────────────────────────────────── */
.vc-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:1rem; margin-bottom:1.75rem;
}
.vc-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.vc-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background:linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1));
    box-shadow:0 4px 14px rgba(13,148,136,.4);
    display:flex; align-items:center; justify-content:center;
}
.vc-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2; }
.vc-page-sub   { font-size:.85rem; color:var(--text-muted); margin:.2rem 0 0; }

/* ── New-vCard button ─────────────────────────────────────────── */
.vc-btn-new {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.65rem 1.35rem; font-size:.9rem; font-weight:700; border-radius:.875rem;
    background:linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1));
    color:#fff; border:none; cursor:pointer; min-height:44px;
    box-shadow:0 3px 12px rgba(13,148,136,.4);
    transition:opacity .2s, transform .15s, box-shadow .2s;
    text-decoration:none; flex-shrink:0;
}
.vc-btn-new:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 5px 18px rgba(13,148,136,.5); }
.vc-btn-new:active { opacity:1; transform:none; }

/* ── Error banner ─────────────────────────────────────────────── */
.vc-error-banner {
    border-radius:.875rem; padding:1rem 1.25rem; margin-bottom:1.5rem;
    background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3);
    color:rgba(185,28,28,1); display:flex; align-items:center; gap:.75rem;
    animation: vcSlideUp .35s cubic-bezier(.22,.68,0,1.2) both;
}

/* ── Search bar ───────────────────────────────────────────────── */
.vc-search-wrap {
    position:relative; margin-bottom:1.5rem;
    animation: vcFadeIn .4s .1s both;
}
.vc-search-icon {
    position:absolute; left:1rem; top:50%; transform:translateY(-50%);
    color:var(--text-muted); font-size:.9rem; pointer-events:none;
}
.vc-search-input {
    width:100%; padding:.7rem 1rem .7rem 2.625rem; border-radius:.875rem;
    border:1.5px solid var(--border-color); background:var(--bg-card);
    color:var(--text-main); font-size:.9rem; outline:none; box-sizing:border-box;
    transition:border-color .2s, box-shadow .2s;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.vc-search-input:focus {
    border-color:rgba(13,148,136,.55);
    box-shadow:0 0 0 3px rgba(13,148,136,.12), 0 1px 4px rgba(0,0,0,.04);
}
.vc-search-input::placeholder { color:var(--text-muted); }
.vc-search-count {
    position:absolute; right:1rem; top:50%; transform:translateY(-50%);
    font-size:.75rem; color:var(--text-muted); font-weight:500;
    pointer-events:none;
}

/* ── Card grid ────────────────────────────────────────────────── */
.vc-grid {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:1.125rem;
}
@media (max-width:900px)  { .vc-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:540px)  { .vc-grid { grid-template-columns:1fr; } }

/* ── Single vCard ─────────────────────────────────────────────── */
.vc-card {
    background-color:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:1.125rem; overflow:hidden;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    display:flex; flex-direction:column;
    animation: vcSlideUp .42s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .25s, transform .22s, border-color .2s;
}
.vc-card:hover {
    box-shadow:0 6px 24px rgba(13,148,136,.14);
    transform:translateY(-3px);
    border-color:rgba(13,148,136,.25);
}
.vc-card:nth-child(1)  { animation-delay:.04s; }
.vc-card:nth-child(2)  { animation-delay:.08s; }
.vc-card:nth-child(3)  { animation-delay:.12s; }
.vc-card:nth-child(4)  { animation-delay:.16s; }
.vc-card:nth-child(5)  { animation-delay:.20s; }
.vc-card:nth-child(6)  { animation-delay:.24s; }
.vc-card:nth-child(n+7){ animation-delay:.28s; }

/* Card top strip */
.vc-card-top {
    padding:1.25rem 1.25rem .875rem;
    display:flex; align-items:center; gap:.875rem;
}

/* Avatar / profile photo */
.vc-avatar {
    width:3.5rem; height:3.5rem; border-radius:50%; flex-shrink:0;
    overflow:hidden; display:flex; align-items:center; justify-content:center;
    font-size:1.15rem; font-weight:800; color:#fff; letter-spacing:-.5px;
    border:2px solid rgba(255,255,255,.35);
    box-shadow:0 2px 8px rgba(0,0,0,.15);
    position:relative;
}
.vc-avatar img {
    width:100%; height:100%; object-fit:cover; position:absolute; inset:0;
    border-radius:50%;
}

/* Name + role */
.vc-card-name  { font-size:.975rem; font-weight:700; color:var(--text-main); margin:0 0 .2rem; line-height:1.25; }
.vc-card-funktion { font-size:.78rem; color:var(--text-muted); margin:0; line-height:1.4; }

/* Rolle badge */
.vc-rolle-badge {
    display:inline-block; padding:.175rem .65rem; border-radius:9999px;
    font-size:.7rem; font-weight:700; line-height:1.4; border:1px solid transparent;
    margin-top:.3rem;
}
.vc-rolle-vorstand    { background:rgba(124,58,237,.1); border-color:rgba(124,58,237,.25); color:rgba(109,40,217,1); }
.vc-rolle-ressort     { background:rgba(37,99,235,.1);  border-color:rgba(37,99,235,.25);  color:rgba(29,78,216,1);  }

/* Divider */
.vc-card-divider { height:1px; background:var(--border-color); margin:0 1.25rem; }

/* Contact info rows */
.vc-card-info { padding:.75rem 1.25rem; display:flex; flex-direction:column; gap:.45rem; flex:1; }
.vc-info-row  {
    display:flex; align-items:center; gap:.5rem;
    font-size:.8rem; color:var(--text-muted); min-height:1.5rem;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.vc-info-row a { color:inherit; text-decoration:none; }
.vc-info-row a:hover { color:rgba(13,148,136,1); text-decoration:underline; }
.vc-info-icon { width:1.1rem; text-align:center; flex-shrink:0; opacity:.6; font-size:.75rem; }

/* Action buttons */
.vc-card-footer {
    padding:.875rem 1.25rem;
    border-top:1px solid var(--border-color);
    display:flex; gap:.5rem;
    background:rgba(156,163,175,.03);
}
.vc-btn-edit {
    flex:1; display:inline-flex; align-items:center; justify-content:center; gap:.35rem;
    padding:.5rem .75rem; font-size:.8rem; font-weight:600; border-radius:.625rem;
    background:rgba(59,130,246,.1); color:rgba(37,99,235,1); border:1px solid rgba(59,130,246,.25);
    cursor:pointer; transition:background .2s, transform .15s, box-shadow .15s; min-height:36px;
}
.vc-btn-edit:hover { background:rgba(59,130,246,.2); transform:translateY(-1px); box-shadow:0 3px 10px rgba(59,130,246,.2); }
.vc-btn-delete {
    flex:1; display:inline-flex; align-items:center; justify-content:center; gap:.35rem;
    padding:.5rem .75rem; font-size:.8rem; font-weight:600; border-radius:.625rem;
    background:rgba(239,68,68,.1); color:rgba(185,28,28,1); border:1px solid rgba(239,68,68,.25);
    cursor:pointer; transition:background .2s, transform .15s, box-shadow .15s; min-height:36px;
}
.vc-btn-delete:hover { background:rgba(239,68,68,.2); transform:translateY(-1px); box-shadow:0 3px 10px rgba(239,68,68,.15); }

/* ── Empty state ──────────────────────────────────────────────── */
.vc-empty {
    text-align:center; padding:4rem 1rem;
    border-radius:1.125rem; border:1px dashed var(--border-color);
    background:var(--bg-card);
}
.vc-empty-icon {
    width:3.5rem; height:3.5rem; border-radius:50%;
    background:rgba(13,148,136,.1);
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:.875rem; font-size:1.4rem; color:rgba(13,148,136,1);
}

/* ── No-results (search) ─────────────────────────────────────── */
#vc-no-results {
    display:none; text-align:center; padding:3rem 1rem;
    color:var(--text-muted); font-size:.9rem;
}
#vc-no-results i { display:block; font-size:2rem; margin-bottom:.625rem; opacity:.35; }

/* ── Modal overlay ────────────────────────────────────────────── */
.vc-modal-overlay {
    position:fixed; inset:0;
    background:rgba(0,0,0,.45); backdrop-filter:blur(5px);
    z-index:500; display:flex; align-items:center; justify-content:center;
    padding:1rem; opacity:0; pointer-events:none; transition:opacity .25s;
}
.vc-modal-overlay.open { opacity:1; pointer-events:auto; }
.vc-modal {
    background-color:var(--bg-card); border-radius:1.25rem;
    width:100%; max-width:540px; max-height:90vh;
    overflow:hidden; display:flex; flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.3), 0 8px 20px rgba(0,0,0,.18);
    border:1px solid var(--border-color);
    transform:translateY(22px) scale(.97);
    transition:transform .32s cubic-bezier(.22,.68,0,1.2);
}
.vc-modal-overlay.open .vc-modal { transform:translateY(0) scale(1); }

/* Modal header */
.vc-modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:1.125rem 1.5rem; border-bottom:1px solid var(--border-color);
    background:rgba(13,148,136,.04);
}
.vc-modal-header-left { display:flex; align-items:center; gap:.75rem; }
.vc-modal-header-icon {
    width:2.25rem; height:2.25rem; border-radius:.625rem; flex-shrink:0;
    background:linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1));
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 3px 10px rgba(13,148,136,.4);
}
.vc-modal-title  { font-size:1rem; font-weight:700; color:var(--text-main); margin:0; }
.vc-modal-close  {
    background:none; border:none; cursor:pointer; color:var(--text-muted);
    font-size:1.1rem; padding:.35rem .45rem; border-radius:.5rem;
    transition:background .15s, color .15s;
    display:flex; align-items:center; justify-content:center;
}
.vc-modal-close:hover { background:rgba(239,68,68,.1); color:rgba(185,28,28,1); }

/* Modal body */
.vc-modal-body {
    padding:1.375rem 1.5rem; overflow-y:auto; flex:1;
    display:flex; flex-direction:column; gap:.875rem;
}

/* Field label */
.vc-field-label {
    display:block; font-size:.775rem; font-weight:700;
    color:var(--text-muted); text-transform:uppercase;
    letter-spacing:.05em; margin-bottom:.35rem;
}
.vc-field-required { color:rgba(239,68,68,.8); }

/* Field input */
.vc-field-input {
    width:100%; padding:.625rem .9rem;
    border-radius:.625rem; border:1.5px solid var(--border-color);
    background:var(--bg-card); color:var(--text-main);
    font-size:.875rem; outline:none; box-sizing:border-box;
    transition:border-color .2s, box-shadow .2s, background .2s;
    box-shadow:inset 0 1px 3px rgba(0,0,0,.04);
}
.vc-field-input:focus {
    border-color:rgba(13,148,136,.6);
    box-shadow:0 0 0 3px rgba(13,148,136,.14), inset 0 1px 3px rgba(0,0,0,.04);
    background:var(--bg-card);
}
.vc-field-input::placeholder { color:var(--text-muted); }
.vc-field-hint {
    font-size:.72rem; color:var(--text-muted); margin:.3rem 0 0;
}

/* Grid for name pair */
.vc-field-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media (max-width:440px) { .vc-field-grid { grid-template-columns:1fr; } }

/* Photo preview */
.vc-photo-preview-wrap {
    display:flex; align-items:center; gap:1rem; margin-bottom:.5rem;
}
.vc-photo-preview {
    width:3.5rem; height:3.5rem; border-radius:50%; object-fit:cover;
    border:2px solid var(--border-color); flex-shrink:0;
}
.vc-photo-initials-preview {
    width:3.5rem; height:3.5rem; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; font-weight:800; color:#fff;
    border:2px solid rgba(255,255,255,.3);
}

/* Modal footer */
.vc-modal-footer {
    padding:1rem 1.5rem; border-top:1px solid var(--border-color);
    display:flex; gap:.75rem; background:rgba(156,163,175,.03);
}
.vc-modal-cancel {
    flex:1; padding:.65rem 1rem; border-radius:.75rem;
    border:1.5px solid var(--border-color); background:var(--bg-card);
    color:var(--text-main); font-weight:600; cursor:pointer;
    font-size:.875rem; transition:background .2s, border-color .2s;
}
.vc-modal-cancel:hover { background:var(--bg-body); border-color:rgba(156,163,175,.4); }
.vc-modal-save {
    flex:2; padding:.65rem 1rem; border-radius:.75rem;
    background:linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1));
    color:#fff; font-weight:700; cursor:pointer;
    font-size:.875rem; border:none;
    display:flex; align-items:center; justify-content:center; gap:.4rem;
    box-shadow:0 2px 10px rgba(13,148,136,.35);
    transition:opacity .2s, transform .15s, box-shadow .2s;
}
.vc-modal-save:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 4px 16px rgba(13,148,136,.5); }
.vc-modal-save:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* ── Delete-confirm modal ─────────────────────────────────────── */
.vc-confirm-modal {
    background-color:var(--bg-card); border-radius:1.25rem;
    width:100%; max-width:380px;
    overflow:hidden; display:flex; flex-direction:column;
    box-shadow:0 24px 64px rgba(0,0,0,.35);
    border:1px solid var(--border-color);
    transform:translateY(22px) scale(.97);
    transition:transform .32s cubic-bezier(.22,.68,0,1.2);
}
.vc-modal-overlay.open .vc-confirm-modal { transform:translateY(0) scale(1); }
.vc-confirm-body {
    padding:1.75rem 1.5rem 1.25rem;
    display:flex; flex-direction:column; align-items:center; text-align:center; gap:.625rem;
}
.vc-confirm-icon {
    width:3.25rem; height:3.25rem; border-radius:50%;
    background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3);
    display:flex; align-items:center; justify-content:center;
    font-size:1.3rem; color:rgba(239,68,68,1); margin-bottom:.25rem;
}
.vc-confirm-title { font-size:1.05rem; font-weight:700; color:var(--text-main); margin:0; }
.vc-confirm-msg   { font-size:.875rem; color:var(--text-muted); margin:0; line-height:1.5; }
.vc-confirm-footer {
    padding:.875rem 1.5rem 1.25rem;
    display:flex; gap:.625rem;
}
.vc-confirm-cancel {
    flex:1; padding:.625rem; border-radius:.75rem;
    border:1.5px solid var(--border-color); background:var(--bg-card);
    color:var(--text-main); font-weight:600; cursor:pointer; font-size:.875rem;
    transition:background .2s;
}
.vc-confirm-cancel:hover { background:var(--bg-body); }
.vc-confirm-delete {
    flex:1; padding:.625rem; border-radius:.75rem;
    background:linear-gradient(135deg,rgba(239,68,68,1),rgba(220,38,38,1));
    color:#fff; font-weight:700; cursor:pointer; font-size:.875rem; border:none;
    box-shadow:0 2px 8px rgba(239,68,68,.35);
    display:flex; align-items:center; justify-content:center; gap:.4rem;
    transition:opacity .2s, transform .15s, box-shadow .2s;
}
.vc-confirm-delete:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 4px 14px rgba(239,68,68,.5); }

/* ── Toast ────────────────────────────────────────────────────── */
#vc-toast {
    position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999;
    display:flex; align-items:center; gap:.75rem;
    padding:.875rem 1.375rem; border-radius:1rem;
    box-shadow:0 8px 28px rgba(0,0,0,.22); color:#fff;
    font-size:.875rem; font-weight:600;
    opacity:0; pointer-events:none; transform:translateY(12px) scale(.96);
    transition:opacity .3s, transform .35s cubic-bezier(.22,.68,0,1.2);
    min-width:220px;
}
#vc-toast.open { opacity:1; pointer-events:auto; transform:translateY(0) scale(1); }
@media (max-width:480px) {
    #vc-toast { left:1rem; right:1rem; min-width:0; bottom:1rem; }
}

/* ── Responsive modal bottom-sheet on mobile ─────────────────── */
@media (max-width:600px) {
    .vc-modal-overlay { align-items:flex-end; padding:0; }
    .vc-modal, .vc-confirm-modal { border-radius:1.25rem 1.25rem 0 0; max-height:92vh; max-width:100%; }
}
@media (max-width:480px) {
    .vc-page-title { font-size:1.35rem; }
    .vc-page-header { flex-direction:column; }
    .vc-btn-new { width:100%; justify-content:center; }
}

/* ── Dark mode specific ───────────────────────────────────────── */
.dark-mode .vc-card {
    background:var(--gradient-card-dark) !important;
    border-color:rgba(255,255,255,.07) !important;
    box-shadow:0 4px 16px rgba(0,0,0,.45), 0 1px 4px rgba(0,0,0,.35) !important;
}
.dark-mode .vc-card:hover {
    border-color:rgba(13,148,136,.35) !important;
    box-shadow:0 8px 28px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.4) !important;
}
.dark-mode .vc-card-footer {
    background:rgba(255,255,255,.04) !important;
    border-top-color:rgba(255,255,255,.07) !important;
}
.dark-mode .vc-card-divider { background:rgba(255,255,255,.07) !important; }
.dark-mode .vc-modal { border-color:rgba(255,255,255,.08) !important; }
.dark-mode .vc-modal-header { background:rgba(13,148,136,.08) !important; border-bottom-color:rgba(255,255,255,.08) !important; }
.dark-mode .vc-modal-footer { background:rgba(255,255,255,.03) !important; border-top-color:rgba(255,255,255,.07) !important; }
.dark-mode .vc-btn-edit {
    color:#93c5fd !important; background:rgba(59,130,246,.16) !important;
    border-color:rgba(59,130,246,.35) !important;
}
.dark-mode .vc-btn-edit:hover { background:rgba(59,130,246,.28) !important; }
.dark-mode .vc-btn-delete {
    color:#fca5a5 !important; background:rgba(239,68,68,.16) !important;
    border-color:rgba(239,68,68,.35) !important;
}
.dark-mode .vc-btn-delete:hover { background:rgba(239,68,68,.28) !important; }
.dark-mode .vc-rolle-vorstand { color:#c4b5fd !important; }
.dark-mode .vc-rolle-ressort  { color:#93c5fd !important; }
.dark-mode .vc-error-banner { color:#fca5a5 !important; }
.dark-mode .vc-header-icon { box-shadow:0 6px 22px rgba(13,148,136,.55), 0 2px 8px rgba(13,148,136,.3) !important; }
.dark-mode .vc-confirm-icon { background:rgba(239,68,68,.18) !important; }
.dark-mode .vc-search-input { background:var(--bg-card) !important; }
</style>

<div class="vc-page">

<!-- Page Header -->
<div class="vc-page-header">
  <div class="vc-page-header-left">
    <div class="vc-header-icon">
      <i class="fas fa-address-card" style="color:#fff;font-size:1.25rem;"></i>
    </div>
    <div>
      <h1 class="vc-page-title">vCards verwalten</h1>
      <p class="vc-page-sub" id="vcCount"><?php echo count($vcards); ?> Kontakte</p>
    </div>
  </div>
  <button type="button" onclick="openCreateModal()" class="vc-btn-new">
    <i class="fas fa-plus-circle"></i>Neue vCard
  </button>
</div>

<!-- Toast -->
<div id="vc-toast"><i id="vc-toast-icon" class="fas fa-check-circle"></i><span id="vc-toast-msg"></span></div>

<!-- Hidden CSRF -->
<input type="hidden" id="sharedCsrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

<?php if ($vcardError): ?>
<div class="vc-error-banner">
  <i class="fas fa-exclamation-circle" style="flex-shrink:0;font-size:1.1rem;"></i>
  <span><?php echo htmlspecialchars($vcardError); ?></span>
</div>
<?php endif; ?>

<?php if (!empty($vcards)): ?>
<!-- Search -->
<div class="vc-search-wrap">
  <i class="vc-search-icon fas fa-search"></i>
  <input type="text" id="vcSearch" class="vc-search-input"
         placeholder="Name, Funktion oder E-Mail suchen…"
         oninput="filterCards(this.value)">
  <span class="vc-search-count" id="vcSearchCount"></span>
</div>
<?php endif; ?>

<!-- Card grid -->
<?php if (empty($vcards)): ?>
<div class="vc-empty">
  <div class="vc-empty-icon"><i class="fas fa-address-card"></i></div>
  <p style="font-weight:700;color:var(--text-main);margin:0 0 .35rem;">Noch keine vCards vorhanden</p>
  <p style="font-size:.85rem;color:var(--text-muted);margin:0;">Lege die erste vCard mit dem Button oben an.</p>
</div>
<?php else: ?>
<div class="vc-grid" id="vcGrid">
<?php foreach ($vcards as $i => $card): ?>
<?php
    $initials = vcInitials($card['vorname'] ?? '', $card['nachname'] ?? '');
    $grad     = vcGradient(($card['vorname'] ?? '') . ($card['nachname'] ?? ''));
    $hasPhoto = !empty($card['profilbild']);
    $rolleClass = '';
    $rolleLabel = '';
    if (!empty($card['rolle'])) {
        if (stripos($card['rolle'], 'Vorstand') !== false) {
            $rolleClass = 'vc-rolle-vorstand';
            $rolleLabel = $card['rolle'];
        } elseif (stripos($card['rolle'], 'Ressort') !== false) {
            $rolleClass = 'vc-rolle-ressort';
            $rolleLabel = $card['rolle'];
        }
    }
    $searchText = strtolower(
        ($card['vorname'] ?? '') . ' ' .
        ($card['nachname'] ?? '') . ' ' .
        ($card['funktion'] ?? '') . ' ' .
        ($card['email'] ?? '')
    );
?>
<div class="vc-card" data-search="<?php echo htmlspecialchars($searchText); ?>"
     data-id="<?php echo (int)$card['id']; ?>">

  <div class="vc-card-top">
    <div class="vc-avatar" style="background:<?php echo $grad; ?>;">
      <?php if ($hasPhoto): ?>
      <img src="<?php echo htmlspecialchars(asset($card['profilbild'])); ?>"
           alt="<?php echo htmlspecialchars($card['vorname'] . ' ' . $card['nachname']); ?>"
           onerror="this.style.display='none'">
      <?php endif; ?>
      <?php if (!$hasPhoto): echo htmlspecialchars($initials); endif; ?>
    </div>
    <div style="min-width:0;">
      <p class="vc-card-name"><?php echo htmlspecialchars(trim(($card['vorname'] ?? '') . ' ' . ($card['nachname'] ?? ''))); ?></p>
      <p class="vc-card-funktion"><?php echo htmlspecialchars($card['funktion'] ?: '—'); ?></p>
      <?php if ($rolleLabel): ?>
      <span class="vc-rolle-badge <?php echo $rolleClass; ?>"><?php echo htmlspecialchars($rolleLabel); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="vc-card-divider"></div>

  <div class="vc-card-info">
    <?php if (!empty($card['email'])): ?>
    <div class="vc-info-row">
      <i class="vc-info-icon fas fa-envelope"></i>
      <a href="mailto:<?php echo htmlspecialchars($card['email']); ?>"><?php echo htmlspecialchars($card['email']); ?></a>
    </div>
    <?php endif; ?>
    <?php if (!empty($card['telefon'])): ?>
    <div class="vc-info-row">
      <i class="vc-info-icon fas fa-phone"></i>
      <a href="tel:<?php echo htmlspecialchars($card['telefon']); ?>"><?php echo htmlspecialchars($card['telefon']); ?></a>
    </div>
    <?php endif; ?>
    <?php if (!empty($card['linkedin'])): ?>
    <div class="vc-info-row">
      <i class="vc-info-icon fab fa-linkedin"></i>
      <a href="<?php echo htmlspecialchars($card['linkedin']); ?>" target="_blank" rel="noopener">LinkedIn-Profil</a>
    </div>
    <?php endif; ?>
    <?php if (empty($card['email']) && empty($card['telefon']) && empty($card['linkedin'])): ?>
    <div class="vc-info-row" style="opacity:.5;">
      <i class="vc-info-icon fas fa-minus"></i>Keine Kontaktdaten hinterlegt
    </div>
    <?php endif; ?>
  </div>

  <div class="vc-card-footer">
    <button type="button" class="vc-btn-edit"
            onclick="openEditModal(
                <?php echo (int)$card['id']; ?>,
                <?php echo json_encode($card['vorname'] ?? ''); ?>,
                <?php echo json_encode($card['nachname'] ?? ''); ?>,
                <?php echo json_encode($card['rolle'] ?? ''); ?>,
                <?php echo json_encode($card['funktion'] ?? ''); ?>,
                <?php echo json_encode($card['email'] ?? ''); ?>,
                <?php echo json_encode($card['telefon'] ?? ''); ?>,
                <?php echo json_encode($card['linkedin'] ?? ''); ?>,
                <?php echo json_encode($card['profilbild'] ?? ''); ?>
            )">
      <i class="fas fa-pen"></i>Bearbeiten
    </button>
    <button type="button" class="vc-btn-delete"
            onclick="openConfirm(<?php echo (int)$card['id']; ?>, <?php echo json_encode(trim(($card['vorname'] ?? '') . ' ' . ($card['nachname'] ?? ''))); ?>)">
      <i class="fas fa-trash"></i>Löschen
    </button>
  </div>

</div><!-- .vc-card -->
<?php endforeach; ?>
</div><!-- .vc-grid -->

<!-- No-results message -->
<div id="vc-no-results">
  <i class="fas fa-search"></i>Keine Ergebnisse für diese Suche.
</div>

<?php endif; ?>

</div><!-- .vc-page -->

<!-- ═══ Edit Modal ═══════════════════════════════════════════ -->
<div id="editModal" class="vc-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
  <div class="vc-modal">
    <div class="vc-modal-header">
      <div class="vc-modal-header-left">
        <div class="vc-modal-header-icon"><i class="fas fa-pen" style="color:#fff;font-size:.85rem;"></i></div>
        <h3 class="vc-modal-title" id="editModalTitle">vCard bearbeiten</h3>
      </div>
      <button type="button" class="vc-modal-close" onclick="closeEditModal()" aria-label="Schließen">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <form id="editForm" novalidate style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
      <input type="hidden" id="editId" name="id" value="">
      <input type="hidden" id="editCsrf" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
      <div class="vc-modal-body">

        <!-- Photo preview -->
        <div id="editPhotoWrap" style="display:none;">
          <label class="vc-field-label">Aktuelles Bild</label>
          <div class="vc-photo-preview-wrap">
            <img id="editPhotoPreview" class="vc-photo-preview" src="" alt="">
            <div>
              <p style="font-size:.82rem;color:var(--text-muted);margin:0;">
                Neues Bild hochladen, um es zu ersetzen.
              </p>
            </div>
          </div>
        </div>
        <div id="editInitialsWrap" style="display:none;">
          <label class="vc-field-label">Avatar-Vorschau</label>
          <div class="vc-photo-preview-wrap">
            <div id="editInitialsPreview" class="vc-photo-initials-preview"></div>
          </div>
        </div>

        <!-- Name -->
        <div class="vc-field-grid">
          <div>
            <label class="vc-field-label">Vorname <span class="vc-field-required">*</span></label>
            <input type="text" id="editVorname" name="vorname" required class="vc-field-input"
                   oninput="updateEditInitials()">
          </div>
          <div>
            <label class="vc-field-label">Nachname <span class="vc-field-required">*</span></label>
            <input type="text" id="editNachname" name="nachname" required class="vc-field-input"
                   oninput="updateEditInitials()">
          </div>
        </div>

        <!-- Rolle -->
        <div>
          <label class="vc-field-label">Rolle</label>
          <select id="editRolle" name="rolle" class="vc-field-input" style="cursor:pointer;">
            <option value="">— Keine Rolle —</option>
            <option value="Vorstand">Vorstand</option>
            <option value="Ressortleitung">Ressortleitung</option>
          </select>
        </div>

        <!-- Funktion — NOW EDITABLE -->
        <div>
          <label class="vc-field-label">Funktion</label>
          <input type="text" id="editFunktion" name="funktion" class="vc-field-input"
                 placeholder="z. B. Vorstandsvorsitzender">
        </div>

        <!-- Email -->
        <div>
          <label class="vc-field-label">E-Mail</label>
          <input type="email" id="editEmail" name="email" class="vc-field-input"
                 placeholder="name@example.com">
        </div>

        <!-- Telefon -->
        <div>
          <label class="vc-field-label">Telefon</label>
          <input type="tel" id="editTelefon" name="telefon" class="vc-field-input"
                 placeholder="+49 123 456789">
        </div>

        <!-- LinkedIn -->
        <div>
          <label class="vc-field-label">LinkedIn-URL</label>
          <input type="url" id="editLinkedin" name="linkedin" class="vc-field-input"
                 placeholder="https://linkedin.com/in/…">
        </div>

        <!-- Profilbild -->
        <div>
          <label class="vc-field-label">Profilbild austauschen</label>
          <input type="file" id="editProfilbild" name="profilbild"
                 accept="image/jpeg,image/png,image/webp,image/gif"
                 class="vc-field-input" style="padding:.4rem .75rem;cursor:pointer;">
          <p class="vc-field-hint">JPG, PNG, WebP oder GIF – max. 5 MB. Leer lassen, um das aktuelle Bild zu behalten.</p>
        </div>

      </div>
      <div class="vc-modal-footer">
        <button type="button" onclick="closeEditModal()" class="vc-modal-cancel">Abbrechen</button>
        <button type="submit" id="editSubmitBtn" class="vc-modal-save">
          <i class="fas fa-save"></i>Speichern
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Create Modal ═════════════════════════════════════════ -->
<div id="createModal" class="vc-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
  <div class="vc-modal">
    <div class="vc-modal-header">
      <div class="vc-modal-header-left">
        <div class="vc-modal-header-icon" style="background:linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1));">
          <i class="fas fa-plus" style="color:#fff;font-size:.85rem;"></i>
        </div>
        <h3 class="vc-modal-title" id="createModalTitle">Neue vCard anlegen</h3>
      </div>
      <button type="button" class="vc-modal-close" onclick="closeCreateModal()" aria-label="Schließen">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <form id="createForm" novalidate style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
      <input type="hidden" id="createCsrf" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
      <div class="vc-modal-body">

        <!-- Name -->
        <div class="vc-field-grid">
          <div>
            <label class="vc-field-label">Vorname <span class="vc-field-required">*</span></label>
            <input type="text" id="createVorname" name="vorname" required class="vc-field-input">
          </div>
          <div>
            <label class="vc-field-label">Nachname <span class="vc-field-required">*</span></label>
            <input type="text" id="createNachname" name="nachname" required class="vc-field-input">
          </div>
        </div>

        <!-- Rolle -->
        <div>
          <label class="vc-field-label">Rolle</label>
          <select id="createRolle" name="rolle" class="vc-field-input" style="cursor:pointer;">
            <option value="">— Keine Rolle —</option>
            <option value="Vorstand">Vorstand</option>
            <option value="Ressortleitung">Ressortleitung</option>
          </select>
        </div>

        <!-- Funktion -->
        <div>
          <label class="vc-field-label">Funktion</label>
          <input type="text" id="createFunktion" name="funktion" class="vc-field-input"
                 placeholder="z. B. Vorstandsvorsitzender">
        </div>

        <!-- Email -->
        <div>
          <label class="vc-field-label">E-Mail</label>
          <input type="email" id="createEmail" name="email" class="vc-field-input"
                 placeholder="name@example.com">
        </div>

        <!-- Telefon -->
        <div>
          <label class="vc-field-label">Telefon</label>
          <input type="tel" id="createTelefon" name="telefon" class="vc-field-input"
                 placeholder="+49 123 456789">
        </div>

        <!-- LinkedIn -->
        <div>
          <label class="vc-field-label">LinkedIn-URL</label>
          <input type="url" id="createLinkedin" name="linkedin" class="vc-field-input"
                 placeholder="https://linkedin.com/in/…">
        </div>

        <!-- Profilbild -->
        <div>
          <label class="vc-field-label">Profilbild <span style="font-size:.7rem;font-weight:400;text-transform:none;">(optional)</span></label>
          <input type="file" id="createProfilbild" name="profilbild"
                 accept="image/jpeg,image/png,image/webp,image/gif"
                 class="vc-field-input" style="padding:.4rem .75rem;cursor:pointer;">
          <p class="vc-field-hint">JPG, PNG, WebP oder GIF – max. 5 MB</p>
        </div>

      </div>
      <div class="vc-modal-footer">
        <button type="button" onclick="closeCreateModal()" class="vc-modal-cancel">Abbrechen</button>
        <button type="submit" id="createSubmitBtn" class="vc-modal-save">
          <i class="fas fa-plus"></i>Anlegen
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Delete Confirm Modal ═════════════════════════════════ -->
<div id="confirmModal" class="vc-modal-overlay" role="alertdialog" aria-modal="true">
  <div class="vc-confirm-modal">
    <div class="vc-confirm-body">
      <div class="vc-confirm-icon"><i class="fas fa-trash-alt"></i></div>
      <h3 class="vc-confirm-title">vCard löschen?</h3>
      <p class="vc-confirm-msg" id="confirmMsg">Diese Aktion kann nicht rückgängig gemacht werden.</p>
    </div>
    <div class="vc-confirm-footer">
      <button type="button" class="vc-confirm-cancel" onclick="closeConfirm()">Abbrechen</button>
      <button type="button" class="vc-confirm-delete" id="confirmDeleteBtn">
        <i class="fas fa-trash-alt"></i>Löschen
      </button>
    </div>
  </div>
</div>

<script>
/* ── Config ─────────────────────────────── */
const VCARD_API_URL        = <?php echo json_encode(asset('api/admin/update_vcard.php')); ?>;
const VCARD_CREATE_API_URL = <?php echo json_encode(asset('api/admin/create_vcard.php')); ?>;
const VCARD_DELETE_API_URL = <?php echo json_encode(asset('api/admin/delete_vcard.php')); ?>;

/* ── Toast ─────────────────────────────── */
function showVcToast(message, type = 'success') {
    const toast = document.getElementById('vc-toast');
    const msg   = document.getElementById('vc-toast-msg');
    const icon  = document.getElementById('vc-toast-icon');
    msg.textContent = message;
    if (type === 'success') {
        toast.style.background = 'linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1))';
        icon.className = 'fas fa-check-circle';
    } else {
        toast.style.background = 'linear-gradient(135deg,rgba(239,68,68,1),rgba(220,38,38,1))';
        icon.className = 'fas fa-exclamation-circle';
    }
    toast.classList.add('open');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toast.classList.remove('open'), 4000);
}

/* ── Search / filter ───────────────────── */
function filterCards(query) {
    const q     = query.trim().toLowerCase();
    const cards = document.querySelectorAll('#vcGrid .vc-card');
    let visible = 0;
    cards.forEach(c => {
        const match = !q || c.dataset.search.includes(q);
        c.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noRes = document.getElementById('vc-no-results');
    if (noRes) noRes.style.display = (q && visible === 0) ? 'block' : 'none';
    const cnt = document.getElementById('vcSearchCount');
    if (cnt) cnt.textContent = q ? `${visible} Ergebnis${visible === 1 ? '' : 'se'}` : '';
}

/* ── Avatar gradient helper (JS mirror) ─ */
const VC_GRADIENTS = [
    'linear-gradient(135deg,rgba(124,58,237,1),rgba(99,102,241,1))',
    'linear-gradient(135deg,rgba(37,99,235,1),rgba(14,165,233,1))',
    'linear-gradient(135deg,rgba(13,148,136,1),rgba(5,150,105,1))',
    'linear-gradient(135deg,rgba(249,115,22,1),rgba(234,179,8,1))',
    'linear-gradient(135deg,rgba(236,72,153,1),rgba(168,85,247,1))',
    'linear-gradient(135deg,rgba(239,68,68,1),rgba(249,115,22,1))',
];
function vcGradientJS(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (Math.imul(31, h) + name.charCodeAt(i)) | 0;
    return VC_GRADIENTS[Math.abs(h) % VC_GRADIENTS.length];
}

/* ── Edit initials live preview ────────── */
function updateEditInitials() {
    const v = document.getElementById('editVorname').value.trim();
    const n = document.getElementById('editNachname').value.trim();
    const wrap = document.getElementById('editInitialsWrap');
    const el   = document.getElementById('editInitialsPreview');
    if (!wrap || !el) return;
    // Only show initials wrap when there's no photo
    if (document.getElementById('editPhotoWrap').style.display !== 'none') return;
    const initials = ((v[0] || '').toUpperCase() + (n[0] || '').toUpperCase()) || '?';
    el.textContent = initials;
    el.style.background = vcGradientJS(v + n);
    wrap.style.display = 'block';
}

/* ── Scroll lock helpers (prevent page jump on modal open) ─── */
let _vcScrollY = 0;
function _lockScroll() {
    _vcScrollY = window.scrollY;
    document.body.style.position = 'fixed';
    document.body.style.top      = '-' + _vcScrollY + 'px';
    document.body.style.left     = '0';
    document.body.style.right    = '0';
    document.body.style.overflow = 'hidden';
}
function _unlockScroll() {
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.left     = '';
    document.body.style.right    = '';
    document.body.style.overflow = '';
    window.scrollTo(0, _vcScrollY);
}

/* ── Edit Modal ────────────────────────── */
let _currentEditId = null;

function openEditModal(id, vorname, nachname, rolle, funktion, email, telefon, linkedin, profilbild) {
    _currentEditId = id;
    document.getElementById('editId').value       = id;
    document.getElementById('editVorname').value  = vorname;
    document.getElementById('editNachname').value = nachname;
    document.getElementById('editRolle').value    = rolle;
    document.getElementById('editFunktion').value = funktion;
    document.getElementById('editEmail').value    = email;
    document.getElementById('editTelefon').value  = telefon;
    document.getElementById('editLinkedin').value = linkedin;
    document.getElementById('editProfilbild').value = '';

    const photoWrap    = document.getElementById('editPhotoWrap');
    const photoPreview = document.getElementById('editPhotoPreview');
    const initWrap     = document.getElementById('editInitialsWrap');
    const initEl       = document.getElementById('editInitialsPreview');

    if (profilbild) {
        photoPreview.src = profilbild; // profilbild is already an absolute URL from asset()
        photoWrap.style.display = 'block';
        initWrap.style.display  = 'none';
    } else {
        photoWrap.style.display = 'none';
        // Show initials preview
        const initials = ((vorname[0] || '').toUpperCase() + (nachname[0] || '').toUpperCase()) || '?';
        initEl.textContent = initials;
        initEl.style.background = vcGradientJS(vorname + nachname);
        initWrap.style.display = 'block';
    }

    _lockScroll();
    document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
    _unlockScroll();
}

document.getElementById('editModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeEditModal();
});

/* ── Create Modal ──────────────────────── */
function openCreateModal() {
    document.getElementById('createForm').reset();
    _lockScroll();
    document.getElementById('createModal').classList.add('open');
    document.getElementById('createVorname').focus();
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
    _unlockScroll();
}

document.getElementById('createModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeCreateModal();
});

/* ── Delete confirm modal ──────────────── */
let _pendingDeleteId   = null;
let _pendingDeleteCard = null;

function openConfirm(id, name) {
    _pendingDeleteId = id;
    _pendingDeleteCard = document.querySelector(`.vc-card[data-id="${id}"]`);
    document.getElementById('confirmMsg').textContent =
        `„${name}" wird dauerhaft gelöscht. Diese Aktion kann nicht rückgängig gemacht werden.`;
    _lockScroll();
    document.getElementById('confirmModal').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
    _unlockScroll();
    _pendingDeleteId   = null;
    _pendingDeleteCard = null;
}

document.getElementById('confirmModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeConfirm();
});

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
    if (!_pendingDeleteId) return;
    const id   = _pendingDeleteId;
    const card = _pendingDeleteCard;
    closeConfirm();

    const csrf = document.getElementById('sharedCsrf').value;
    const fd   = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', csrf);

    try {
        const resp = await fetch(VCARD_DELETE_API_URL, { method:'POST', body:fd });
        const data = await resp.json();
        if (data.success) {
            showVcToast(data.message || 'vCard erfolgreich gelöscht', 'success');
            if (card) {
                card.style.transition = 'opacity .4s ease, transform .4s ease';
                card.style.opacity    = '0';
                card.style.transform  = 'scale(.9)';
                setTimeout(() => {
                    card.remove();
                    // update count
                    const remaining = document.querySelectorAll('#vcGrid .vc-card').length;
                    const el = document.getElementById('vcCount');
                    if (el) el.textContent = remaining + ' Kontakt' + (remaining === 1 ? '' : 'e');
                }, 400);
            }
        } else {
            showVcToast(data.message || 'Fehler beim Löschen', 'error');
        }
    } catch(err) {
        showVcToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
    }
});

/* ── Create submit ─────────────────────── */
document.getElementById('createForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('createSubmitBtn');
    btn.disabled = true;

    try {
        const resp = await fetch(VCARD_CREATE_API_URL, { method:'POST', body: new FormData(this) });
        const data = await resp.json();
        if (data.success) {
            closeCreateModal();
            showVcToast(data.message || 'vCard erfolgreich angelegt', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showVcToast(data.message || 'Fehler beim Anlegen', 'error');
        }
    } catch(err) {
        showVcToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
    } finally {
        btn.disabled = false;
    }
});

/* ── Edit submit ───────────────────────── */
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('editSubmitBtn');
    btn.disabled = true;

    const fd = new FormData(this);
    try {
        const resp = await fetch(VCARD_API_URL, { method:'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            closeEditModal();
            showVcToast(data.message || 'vCard erfolgreich aktualisiert', 'success');
            // Live-update the card in the grid
            const card = document.querySelector(`.vc-card[data-id="${_currentEditId}"]`);
            if (card) {
                const vorname  = fd.get('vorname') || '';
                const nachname = fd.get('nachname') || '';
                const funktion = fd.get('funktion') || '';
                const rolle    = fd.get('rolle') || '';
                const email    = fd.get('email') || '';
                const telefon  = fd.get('telefon') || '';

                // Update name
                const nameEl = card.querySelector('.vc-card-name');
                if (nameEl) nameEl.textContent = (vorname + ' ' + nachname).trim();

                // Update funktion
                const funkEl = card.querySelector('.vc-card-funktion');
                if (funkEl) funkEl.textContent = funktion || '—';

                // Update rolle badge
                const badgeEl = card.querySelector('.vc-rolle-badge');
                if (rolle && badgeEl) {
                    badgeEl.textContent = rolle;
                    badgeEl.className = 'vc-rolle-badge ' +
                        (rolle.toLowerCase().includes('vorstand') ? 'vc-rolle-vorstand' : 'vc-rolle-ressort');
                    badgeEl.style.display = '';
                } else if (!rolle && badgeEl) {
                    badgeEl.style.display = 'none';
                }

                // Update initials / avatar
                const avatarEl = card.querySelector('.vc-avatar');
                if (avatarEl && !avatarEl.querySelector('img')) {
                    const initials = ((vorname[0] || '').toUpperCase() + (nachname[0] || '').toUpperCase()) || '?';
                    avatarEl.textContent = initials;
                    avatarEl.style.background = vcGradientJS(vorname + nachname);
                }

                // Update search data
                card.dataset.search = [vorname, nachname, funktion, email].join(' ').toLowerCase();

                // Subtle flash
                card.style.transition = 'box-shadow .4s';
                card.style.boxShadow = '0 0 0 3px rgba(13,148,136,.5)';
                setTimeout(() => card.style.boxShadow = '', 800);
            }
        } else {
            showVcToast(data.message || 'Fehler beim Speichern', 'error');
        }
    } catch(err) {
        showVcToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
    } finally {
        btn.disabled = false;
    }
});

/* ── Keyboard close ────────────────────── */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeEditModal();
        closeCreateModal();
        closeConfirm();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
