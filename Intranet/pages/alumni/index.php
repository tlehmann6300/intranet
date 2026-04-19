<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user     = Auth::user();
$viewerRole     = $user['role'] ?? '';
$canViewPrivate = in_array($viewerRole, ['alumni','vorstand_intern','vorstand_extern','vorstand_finanzen']);

$searchKeyword  = $_GET['search']   ?? '';
$industryFilter = $_GET['industry'] ?? '';

$filters = [];
if (!empty($searchKeyword))  $filters['search']   = $searchKeyword;
if (!empty($industryFilter)) $filters['industry'] = $industryFilter;

$profiles   = Alumni::searchProfiles($filters);
$industries = Alumni::getAllIndustries();

// Role style map — RGBA, no dark: classes needed
$roleStyles = [
    'alumni'          => ['c'=>'#6b7280','b'=>'rgba(107,114,128,0.1)','border'=>'rgba(107,114,128,0.25)','grad'=>'#6b7280,#4b5563'],
    'alumni_vorstand' => ['c'=>'#6366f1','b'=>'rgba(99,102,241,0.1)', 'border'=>'rgba(99,102,241,0.25)', 'grad'=>'#6366f1,#4f46e5'],
    'alumni_finanz'   => ['c'=>'#6366f1','b'=>'rgba(99,102,241,0.1)', 'border'=>'rgba(99,102,241,0.25)', 'grad'=>'#6366f1,#4f46e5'],
    'ehrenmitglied'   => ['c'=>'#d97706','b'=>'rgba(217,119,6,0.1)',  'border'=>'rgba(217,119,6,0.25)',  'grad'=>'#f59e0b,#d97706'],
];
$defaultStyle = ['c'=>'#7c3aed','b'=>'rgba(124,58,237,0.1)','border'=>'rgba(124,58,237,0.25)','grad'=>'#7c3aed,#4f46e5'];

$title = 'Alumni-Verzeichnis - IBC Intranet';
ob_start();
?>
<style>
/* ── Alumni Directory ─────────────────────────────────────────── */
.dir-search-input {
    width: 100%;
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
    border-radius: 9999px;
    padding: 0.55rem 1rem 0.55rem 2.375rem;
    font-size: 0.875rem;
    color: var(--text-main);
    outline: none;
    transition: border-color 0.18s, box-shadow 0.18s;
    -webkit-appearance: none;
    min-height: 2.5rem;
}
.dir-search-input::placeholder { color: var(--text-muted); opacity: 0.7; }
.dir-search-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
}
.dir-select {
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
    border-radius: 9999px;
    padding: 0.55rem 2rem 0.55rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-main);
    outline: none;
    cursor: pointer;
    -webkit-appearance: none;
    appearance: none;
    min-height: 2.5rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    transition: border-color 0.18s;
    width: 100%;
}
.dir-select:focus { border-color: #7c3aed; }

/* ── Profile Card ─────────────────────────────────────────────── */
.dir-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    text-align: center;
    transition: transform 0.28s cubic-bezier(0.34,1.2,0.64,1),
                box-shadow 0.28s ease,
                border-color 0.22s ease;
    position: relative;
}
.dir-card--alumni:hover    { transform: translateY(-5px); box-shadow: 0 16px 36px rgba(124,58,237,0.14); border-color: #7c3aed; }

.dir-card-banner {
    height: 3.75rem;
    flex-shrink: 0;
    position: relative;
}
.dir-avatar-wrap {
    position: absolute;
    left: 50%;
    top: 100%;
    transform: translate(-50%, -50%);
    z-index: 2;
}
.dir-avatar {
    width: 4.5rem;
    height: 4.5rem;
    border-radius: 50%;
    border: 3px solid var(--bg-card);
    box-shadow: 0 3px 14px rgba(0,0,0,0.18);
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
}
.dir-avatar img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.dir-card-body {
    padding: 2.875rem 1.125rem 1.125rem;
    display: flex;
    flex-direction: column;
    flex: 1;
    align-items: center;
}
.dir-card-name {
    font-size: 0.9375rem;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.25;
    margin: 0 0 0.5rem;
    word-break: break-word;
}
.dir-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.625rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    border: 1px solid transparent;
    white-space: nowrap;
    margin-bottom: 0.625rem;
}
.dir-info-snippet {
    font-size: 0.8rem;
    color: var(--text-muted);
    line-height: 1.45;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    flex: 1;
    min-height: 2.4rem;
    margin-bottom: 0.75rem;
    word-break: break-word;
    hyphens: manual;
}
.dir-contact-icons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 0.875rem;
    flex-wrap: wrap;
}
.dir-icon-btn {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    text-decoration: none;
    border: 1.5px solid var(--border-color);
    background: var(--bg-body);
    color: var(--text-muted);
    transition: border-color 0.18s, color 0.18s, background 0.18s, transform 0.18s;
    flex-shrink: 0;
}
.dir-icon-btn:hover {
    transform: translateY(-2px) scale(1.1);
}
.dir-icon-btn--mail:hover    { border-color: var(--ibc-blue);  color: var(--ibc-blue);  background: rgba(0,102,179,0.08);  }
.dir-icon-btn--linkedin:hover { border-color: #0a66c2; color: #0a66c2; background: rgba(10,102,194,0.08); }
.dir-icon-btn--xing:hover    { border-color: #006567; color: #006567; background: rgba(0,101,103,0.08);  }
.dir-view-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    width: 100%;
    padding: 0.55rem 0.875rem;
    border-radius: 0.625rem;
    font-size: 0.8125rem;
    font-weight: 700;
    text-decoration: none;
    color: #fff;
    border: none;
    cursor: pointer;
    transition: opacity 0.18s, transform 0.18s;
    white-space: nowrap;
    min-height: 2.375rem;
}
.dir-view-btn:hover { opacity: 0.9; transform: translateY(-1px); }

/* ── Stagger ─────────────────────────────────────────────────── */
@keyframes dirCardIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: none; }
}
.dir-card { animation: dirCardIn 0.3s ease both; }
.dir-card:nth-child(2)  { animation-delay: 0.04s; }
.dir-card:nth-child(3)  { animation-delay: 0.08s; }
.dir-card:nth-child(4)  { animation-delay: 0.12s; }
.dir-card:nth-child(5)  { animation-delay: 0.15s; }
.dir-card:nth-child(6)  { animation-delay: 0.18s; }
.dir-card:nth-child(7)  { animation-delay: 0.20s; }
.dir-card:nth-child(8)  { animation-delay: 0.22s; }
.dir-card:nth-child(n+9){ animation-delay: 0.24s; }

.dir-empty {
    background: var(--bg-card);
    border: 2px dashed var(--border-color);
    border-radius: 1rem;
    padding: 4rem 2rem;
    text-align: center;
}
.dir-flash--success {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.125rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1.25rem;
    background: rgba(0,166,81,0.08);
    border: 1.5px solid rgba(0,166,81,0.2);
    color: var(--ibc-green);
}

@media (prefers-reduced-motion: reduce) {
    .dir-card, .dir-card:nth-child(n) { animation: none; }
    .dir-card:hover { transform: none; }
}
</style>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="dir-flash--success">
    <i class="fas fa-check-circle" style="flex-shrink:0;" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
</div>
<?php endif; ?>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(124,58,237,0.32);flex-shrink:0;">
            <i class="fas fa-user-graduate" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div style="min-width:0;">
            <h1 style="font-size:clamp(1.25rem,4vw,1.625rem);font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Alumni-Verzeichnis</h1>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0.125rem 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Entdecke und vernetze dich mit unserem Alumni-Netzwerk</p>
        </div>
    </div>
    <?php if (in_array($user['role'], ['alumni','alumni_vorstand','alumni_finanz','ehrenmitglied'])): ?>
    <a href="../auth/profile.php"
       style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.1rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 3px 12px rgba(124,58,237,0.3);transition:opacity 0.18s,transform 0.18s;flex-shrink:0;"
       onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
       onmouseout="this.style.opacity='1';this.style.transform='none'">
        <i class="fas fa-user-edit" aria-hidden="true"></i>
        Profil bearbeiten
    </a>
    <?php endif; ?>
</div>

<!-- ── Search & Filter ────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:0.875rem;padding:1rem 1.125rem;margin-bottom:1.5rem;">
    <form method="GET" action="">
        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <!-- Keyword search -->
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute;left:0.875rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.75rem;pointer-events:none;" aria-hidden="true"></i>
                <input type="text" name="search"
                       value="<?php echo htmlspecialchars($searchKeyword); ?>"
                       placeholder="Name, Position, Unternehmen…"
                       class="dir-search-input"
                       aria-label="Alumni suchen">
            </div>
            <!-- Industry select -->
            <div style="position:relative;">
                <i class="fas fa-industry" style="position:absolute;left:0.875rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.75rem;pointer-events:none;z-index:1;" aria-hidden="true"></i>
                <select name="industry" class="dir-select" style="padding-left:2.25rem;width:100%;" aria-label="Branche filtern">
                    <option value="">Alle Branchen</option>
                    <?php foreach ($industries as $ind): ?>
                    <option value="<?php echo htmlspecialchars($ind); ?>" <?php echo $industryFilter === $ind ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ind); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Buttons -->
            <div style="display:flex;gap:0.5rem;flex-shrink:0;">
                <button type="submit"
                        style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border:none;border-radius:9999px;font-size:0.8125rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:opacity 0.18s;min-height:2.5rem;flex-shrink:0;">
                    <i class="fas fa-search" style="font-size:0.7rem;" aria-hidden="true"></i>
                    Suchen
                </button>
                <?php if (!empty($searchKeyword) || !empty($industryFilter)): ?>
                <a href="index.php"
                   style="display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;background:var(--bg-body);border:1.5px solid var(--border-color);border-radius:50%;color:var(--text-muted);text-decoration:none;font-size:0.75rem;transition:border-color 0.15s,color 0.15s;flex-shrink:0;"
                   title="Filter zurücksetzen"
                   onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444'"
                   onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- ── Results Count ──────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.125rem;">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:50%;background:rgba(124,58,237,0.1);font-size:0.75rem;font-weight:800;color:#7c3aed;"><?php echo count($profiles); ?></span>
    <span style="font-size:0.875rem;color:var(--text-muted);">
        <?php echo count($profiles) === 1 ? 'Profil' : 'Profile'; ?> gefunden
        <?php if (!empty($searchKeyword) || !empty($industryFilter)): ?>
        <span style="font-size:0.8rem;"> · Gefiltert</span>
        <?php endif; ?>
    </span>
</div>

<?php if (empty($profiles)): ?>
<!-- ── Empty State ────────────────────────────────────────────── -->
<div class="dir-empty">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(124,58,237,0.07);border:1.5px solid rgba(124,58,237,0.15);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-user-graduate" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:800;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">
        <?php echo (!empty($searchKeyword) || !empty($industryFilter)) ? 'Keine Profile gefunden' : 'Noch keine Alumni-Profile vorhanden.'; ?>
    </p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">
        <?php echo (!empty($searchKeyword) || !empty($industryFilter)) ? 'Bitte passe Deinen Suchfilter an.' : 'Schau später wieder vorbei!'; ?>
    </p>
</div>

<?php else: ?>
<!-- ── Profiles Grid ──────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,11rem),1fr));gap:1rem;">
    <?php foreach ($profiles as $profile):
        $roleKey     = Auth::getPrimaryEntraRoleKey($profile['entra_roles'] ?? null, $profile['role'] ?? '');
        $rs          = $roleStyles[$roleKey] ?? $defaultStyle;
        $displayRole = htmlspecialchars($profile['display_role'] ?? Auth::getRoleLabel($profile['role'] ?? ''));
        $initials    = getMemberInitials($profile['first_name'], $profile['last_name']);
        $avatarColor = getAvatarColor($profile['first_name'] . ' ' . $profile['last_name']);
        $alumEmail   = $profile['email'] ?? '';
        $imagePath   = !empty($alumEmail)
            ? asset('fetch-profile-photo.php') . '?email=' . urlencode($alumEmail)
            : asset(getProfileImageUrl($profile['avatar_path'] ?? null));

        // Info snippet
        $snippet = trim(htmlspecialchars($profile['position'] ?? ''));
        if (empty($snippet) && !empty($profile['company'])) {
            $snippet = htmlspecialchars($profile['company']);
        }
        if (!empty($profile['industry'])) {
            $snippet .= (!empty($snippet) ? ' · ' : '') . htmlspecialchars($profile['industry']);
        }

        // LinkedIn validation
        $li = $profile['linkedin_url'] ?? '';
        $validLi = !empty($li) && preg_match('#^https?://(www\.)?linkedin\.com/#i', $li);
        $xi = $profile['xing_url'] ?? '';
        $validXi = !empty($xi) && preg_match('#^https?://(www\.)?xing\.com/#i', $xi);
        $showMail = !empty($profile['email']) && ($canViewPrivate || empty($profile['privacy_hide_email']));
    ?>
    <div class="dir-card dir-card--alumni">
        <!-- Banner with avatar -->
        <div class="dir-card-banner" style="background:linear-gradient(135deg,<?php echo $rs['grad']; ?>);">
            <div class="dir-avatar-wrap">
                <div class="dir-avatar" style="background:<?php echo htmlspecialchars($avatarColor); ?>;">
                    <?php echo htmlspecialchars($initials); ?>
                    <img src="<?php echo htmlspecialchars($imagePath); ?>"
                         alt="<?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.style.display='none';">
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="dir-card-body">
            <h3 class="dir-card-name">
                <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>
            </h3>
            <span class="dir-role-badge"
                  style="background:<?php echo $rs['b']; ?>;color:<?php echo $rs['c']; ?>;border-color:<?php echo $rs['border']; ?>;">
                <?php echo $displayRole; ?>
            </span>

            <!-- Info snippet -->
            <p class="dir-info-snippet">
                <?php echo !empty($snippet) ? $snippet : ''; ?>
            </p>

            <!-- Contact icons -->
            <div class="dir-contact-icons">
                <?php if ($showMail): ?>
                <a href="mailto:<?php echo htmlspecialchars($profile['email']); ?>"
                   class="dir-icon-btn dir-icon-btn--mail"
                   title="E-Mail senden">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
                <?php if ($validLi): ?>
                <a href="<?php echo htmlspecialchars($li); ?>"
                   target="_blank" rel="noopener noreferrer"
                   class="dir-icon-btn dir-icon-btn--linkedin"
                   title="LinkedIn">
                    <i class="fab fa-linkedin-in" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
                <?php if ($validXi): ?>
                <a href="<?php echo htmlspecialchars($xi); ?>"
                   target="_blank" rel="noopener noreferrer"
                   class="dir-icon-btn dir-icon-btn--xing"
                   title="Xing">
                    <i class="fab fa-xing" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
            </div>

            <!-- CTA Button -->
            <a href="view.php?id=<?php echo (int)$profile['id']; ?>"
               class="dir-view-btn"
               style="background:linear-gradient(135deg,#7c3aed,#4f46e5);box-shadow:0 2px 10px rgba(124,58,237,0.25);">
                <i class="fas fa-user" style="font-size:0.75rem;" aria-hidden="true"></i>
                Profil ansehen
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
