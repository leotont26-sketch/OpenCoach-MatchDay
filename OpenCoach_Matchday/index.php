<?php
require_once __DIR__ . '/functions.php';
date_default_timezone_set(cfg()['timezone']);
$settings = load_settings();
$matches = load_matches();
sort_matches($matches);
$tables = compute_standings($matches);
$resolvedMatches = resolve_match_labels($matches, $tables);
$resolvedMatches = apply_dynamic_display_times($resolvedMatches, $settings);
sort_matches_for_display($resolvedMatches, $settings);
$groups = unique_groups($matches);
$teamsMap = [];
foreach ($resolvedMatches as $m) { if (!empty($m['resolved_home'])) $teamsMap[$m['resolved_home']] = true; if (!empty($m['resolved_away'])) $teamsMap[$m['resolved_away']] = true; }
$teams = array_keys($teamsMap); sort($teams, SORT_NATURAL | SORT_FLAG_CASE);
$selectedGroup = $_GET['group'] ?? '';
$selectedTeam  = $_GET['team'] ?? '';
$groupTables = $tables;
$knockoutMatches = array_values(array_filter($resolvedMatches, fn($m) => ($m['phase'] ?? 'group') !== 'group'));
$showTable = show_table_enabled($settings);
$showScores = public_scores_enabled($settings);
$runtimeNow = time();
$statusLabels = ['upcoming' => 'BALD', 'live' => 'LIVE', 'finished' => 'ENDE'];
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="color-scheme" content="dark">
  <title><?= htmlspecialchars(site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <?php if (file_exists(__DIR__.'/head.inc.html')) include __DIR__ . '/head.inc.html'; ?>
  <?php if (!empty($settings['spielbeginn']) && preg_match('~^(\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}~', $settings['spielbeginn'], $m)): ?><meta name="match-base-date" content="<?= htmlspecialchars($m[1]) ?>"><?php endif; ?>
  <meta name="match-duration" content="<?= (int)$settings['spieldauer'] ?>">
  <meta name="match-upcoming-window" content="<?= (int)$settings['status_upcoming'] ?>">
  <meta name="control-mode" content="<?= htmlspecialchars(control_mode($settings), ENT_QUOTES, 'UTF-8') ?>">

<style>

/* ===== BASIS ===== */
:root{--bg:#0b0c10;--card:#13161c;--card2:#0f141c;--muted:#98a2b3;--text:#e8eaed;--line:#1f2937;--acc:#60a5fa;--acc2:#5eead4;--danger:#ef4444;color-scheme:dark}

html,body{
  min-height:100%;
  background:linear-gradient(180deg,var(--bg),#0f1115 60%)!important;
  color:var(--text)!important
}

body *,input,select,textarea,button{color-scheme:dark}

main.container{max-width:1180px;margin:0 auto;padding:16px}

.hero,.card,.table-wrap{
  border:1px solid var(--line);
  border-radius:16px;
  background:var(--card);
  box-shadow:0 10px 28px rgba(0,0,0,.25)
}

.hero{padding:16px 18px;margin-bottom:16px}
.hero h1{margin:0 0 4px}
.muted{color:var(--muted)}


/* ===== FILTER ===== */

.filters form{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  align-items:center;
  width:100%;
}

.filters select,
.filters input,
.filters button{
  flex:1 1 0;
  min-width:0;
}

.filters button,
.filters a[role="button"]{
  flex:0 0 auto;
}

.filters select,
.filters input{
  background:#0f141c!important;
  color:var(--text)!important;
  border:1px solid var(--line)!important;
  width:100%;
  min-width:0;
}

@media (max-width: 640px){
  .filters form{
    gap:10px;
  }

  .filters select,
  .filters input{
    flex:1 1 calc(50% - 5px);
    min-width:0;
  }

  .filters button,
  .filters a[role="button"]{
    flex:1 1 100%;
  }
}


/* ===== TURNIERDETAILS (KLAPPBAR) ===== */

.hero-toggle summary{
  list-style:none;
  cursor:pointer;
  font-size:1rem;
  font-weight:600;
  margin:0;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px
}

.hero-toggle summary::-webkit-details-marker{display:none}

.hero-toggle summary::after{
  content:"▾";
  font-size:1.2rem;
  color:var(--acc);
  transition:transform .2s ease
}

.hero-toggle:not([open]) summary::after{
  transform:rotate(-90deg)
}

.hero-toggle .info-list{margin-top:14px}


/* ===== TABELLEN ===== */

.table-wrap{overflow:auto}

table{width:100%;border-collapse:collapse}

thead th{
  position:sticky;
  top:0;
  background:#121721;
  color:#fff!important;
  padding:12px;
  font-weight:700
}

tbody td{
  padding:12px;
  border-top:1px solid #1e2430
}

tbody tr:nth-child(odd){background:#0f141c}
tbody tr:hover{background:#171d28}

.badge{
  display:inline-flex;
  padding:4px 10px;
  border-radius:999px;
  background:rgba(96,165,250,.16);
  color:#d8edff;
  font-weight:700
}

.scoreboard{font-weight:800}

.runtime-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:4px 10px;
  border-radius:999px;
  font-size:.82rem;
  font-weight:800;
  letter-spacing:.02em;
  margin-right:10px;
  min-width:64px;
}

.runtime-badge.live{
  animation:pulse 2.5s infinite ;
}

@keyframes pulse{
  0%{box-shadow:0 0 0 0 rgba(255, 0, 0, 0.9);}
  70%{box-shadow:0 0 0 10px rgba(255, 0, 0, 0);}
  100%{box-shadow:0 0 0 0 rgba(255, 0, 0, 0);}
}

.runtime-badge.upcoming{
  background:#0ea5e9;
  color:#fff;
  box-shadow:0 0 8px rgba(14,165,233,.6);
}

.runtime-badge.finished{
  background:#6b7280;
  color:#fff;
  opacity:.8;
}




/* ===== FINALRUNDE ===== */

.section{display:grid;gap:16px;margin-top:16px}

.grid2{
  display:grid;
  gap:16px;
  grid-template-columns:repeat(2,minmax(0,1fr))
}

.ko-grid{
  display:grid;
  gap:12px;
  grid-template-columns:repeat(2,minmax(0,1fr))
}

.ko-card{
  padding:14px;
  border-radius:14px;
  border:1px solid var(--line);
  background:var(--card2)
}

.ko-card h3{margin:0 0 6px;font-size:1rem}

.ko-teams{display:grid;gap:6px}

.ko-teams div{
  display:flex;
  justify-content:space-between;
  gap:10px
}

.info-list{display:grid;gap:6px}


/* ===== FOOTER ===== */

.site-footer{
  margin-top:40px;
  padding:20px 10px;
  border-top:1px solid var(--line)
}

.footer-inner{
  max-width:1180px;
  margin:auto;
  display:flex;
  justify-content:space-between;
  align-items:center;
  color:var(--muted);
  font-size:.9rem
}

.admin-link{
  color:var(--muted);
  text-decoration:none;
  opacity:.6;
  transition:opacity .2s ease
}

.admin-link:hover{
  opacity:1;
  color:var(--text)
}

.footer-link{
  color:var(--muted);
  text-decoration:none;
  border-bottom:1px dotted rgba(255,255,255,.2);
  transition:all .2s ease
}

.footer-link:hover{
  color:var(--text);
  border-bottom-color:var(--acc)
}


/* ===== MOBILE ===== */

@media (max-width:700px){
  .matches-table,
  .matches-table thead,
  .matches-table tbody,
  .matches-table th,
  .matches-table td,
  .matches-table tr{
    display:block;
    width:100%;
  }

  .matches-table thead{display:none;}
  .matches-table tbody{display:grid;gap:12px;}
  .matches-table tbody tr{
    border:1px solid var(--line);
    border-radius:16px;
    background:var(--card2);
    padding:12px;
    box-shadow:0 8px 20px rgba(0,0,0,.18);
  }

  .matches-table tbody td{
    border:0;
    padding:6px 0;
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    text-align:left !important;
  }

  .matches-table tbody td::before{
    content:attr(data-label);
    min-width:86px;
    color:var(--muted);
    font-weight:700;
    flex-shrink:0;
  }
}

@media (max-width:1100px){
  .grid2,
  .ko-grid{
    grid-template-columns:1fr;
  }
}



/* ===== SANFTER LIVE-REFRESH ===== */
.live-fade{
  transition:opacity .35s ease, transform .35s ease;
}
.live-fade.is-refreshing{
  opacity:.55;
  transform:translateY(2px);
}

</style>

</head>
<body>
 <?php if (defined('DEMO_MODE') && DEMO_MODE === true): ?>
  <div class="demo-banner" data-demo-expires="<?= (int)DEMO_EXPIRES_AT ?>">
    <strong>DEMO-MODUS</strong>
    <span>Login: <b>admin</b></span>
    <span>Passwort: <b>12345678</b></span>
    <span>Noch verfügbar: <b id="demo-timer">30:00</b></span>
  </div>
<?php endif; ?> 
<main class="container">
  <?php if (file_exists(__DIR__.'/header.inc.html')) include __DIR__ . '/header.inc.html'; ?>
  <div id="live-content">
  <details class="hero hero-toggle">
  <summary>Turnierdetails</summary>
  <div class="info-list muted">
    <?php if (!empty($settings['spielort'])): ?><div><strong>Spielort:</strong> <?= htmlspecialchars($settings['spielort']) ?></div><?php endif; ?>
    <?php if (!empty($settings['spielbeginn'])): ?><div><strong>Turnierbeginn:</strong> <?= htmlspecialchars(date('d.m.Y H:i', strtotime($settings['spielbeginn']))) ?> Uhr</div><?php endif; ?>
    <?php if (!empty($settings['spielprinzip'])): ?><div><strong>Spielprinzip:</strong> <?= htmlspecialchars($settings['spielprinzip']) ?></div><?php endif; ?>
    <div><strong>Spieldauer:</strong> <?= (int)$settings['spieldauer'] ?> Minuten &nbsp; <strong>Pause:</strong> <?= (int)$settings['wechselzeit'] ?> Minuten</div>
	</div>
	</details>

<header class="filters" style="margin-bottom:16px;">
  <form method="get">
    <select name="group" aria-label="Nach Gruppe filtern" onchange="this.form.submit()">
      <option value="" <?= $selectedGroup === '' ? 'selected' : '' ?>>Alle Gruppen</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>" <?= $selectedGroup === $g ? 'selected' : '' ?>>
          <?= htmlspecialchars($g) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="team" aria-label="Nach Team filtern" onchange="this.form.submit()">
      <option value="" <?= $selectedTeam === '' ? 'selected' : '' ?>>Alle Teams</option>
      <?php foreach ($teams as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>" <?= $selectedTeam === $t ? 'selected' : '' ?>>
          <?= htmlspecialchars($t) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Filtern</button>

    <?php if ($selectedGroup || $selectedTeam): ?>
      <a href="index.php" role="button" class="secondary">Reset</a>
    <?php endif; ?>
  </form>
</header>

  <div class="table-wrap">
  <table class="matches-table">
      <thead><tr><th>Uhrzeit</th><th>Feld</th><th>Gruppe</th><th>Heim</th><th>Gast</th><th style="text-align:right;">Ergebnis</th></tr></thead>
      <tbody>
      <?php $shown = 0; foreach ($resolvedMatches as $m):
        $phaseLabel = !empty($m['label']) ? $m['label'] : match_group_label($m);
        if ($selectedGroup && (string)($m['group'] ?? '') !== $selectedGroup) continue;
        if ($selectedTeam) {
          $hay = mb_strtolower(($m['resolved_home'] ?? '').' '.($m['resolved_away'] ?? ''));
          if (mb_strpos($hay, mb_strtolower($selectedTeam)) === false) continue;
        }
        $shown++;
       $runtimeState = match_display_state($m, $resolvedMatches, $settings, $runtimeNow); ?>
        <tr data-runtime-state="<?= htmlspecialchars($runtimeState, ENT_QUOTES, 'UTF-8') ?>">
  <td data-label="Uhrzeit"><?php if (isset($statusLabels[$runtimeState])): ?><span class="runtime-badge <?= htmlspecialchars($runtimeState, ENT_QUOTES, 'UTF-8') ?>"><?= $statusLabels[$runtimeState] ?></span><?php endif; ?><?= htmlspecialchars(match_display_kickoff($m, 'H:i')) ?></td>
  <td data-label="Feld"><?= htmlspecialchars((string)$m['field']) ?></td>
  <td data-label="Phase"><span class="badge"><?= htmlspecialchars($phaseLabel) ?></span></td>
  <td data-label="Heim"><?= htmlspecialchars((string)($m['resolved_home'] ?? $m['home'])) ?></td>
  <td data-label="Gast"><?= htmlspecialchars((string)($m['resolved_away'] ?? $m['away'])) ?></td>
  <td data-label="Ergebnis" style="text-align:right;">
    <?php if ($showScores && $m['home_score'] !== null && $m['away_score'] !== null): ?>
      <span class="scoreboard"><?= (int)$m['home_score'] ?> : <?= (int)$m['away_score'] ?></span>
    <?php else: ?>
      <span class="muted">– : –</span>
    <?php endif; ?>
  </td>
</tr>
      <?php endforeach; ?>
      <?php if ($shown === 0): ?><tr><td colspan="6" class="muted" style="text-align:center;">Kein Turnier geplant</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($showTable && $groupTables): ?>
  <section class="section">
    <div class="card" style="padding:16px;"><h2 style="margin:0;">Tabellen</h2><p class="muted" style="margin:.35rem 0 0;">Berechnet nach Punkten, Tordifferenz und erzielten Toren.</p></div>
    <div class="grid2">
      <?php foreach ($groupTables as $group => $rows): ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th colspan="8"><?= htmlspecialchars($group) ?></th></tr><tr><th>#</th><th>Team</th><th>Sp</th><th>T</th><th>GT</th><th>TD</th><th>P</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= (int)$row['rank'] ?></td>
                <td><?= htmlspecialchars($row['team']) ?></td>
                <td><?= (int)$row['played'] ?></td>
                <td><?= (int)$row['gf'] ?></td>
                <td><?= (int)$row['ga'] ?></td>
                <td><?= (int)$row['gd'] ?></td>
                <td><strong><?= (int)$row['points'] ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($knockoutMatches): ?>
  <section class="section">
    <div class="card" style="padding:16px;"><h2 style="margin:0;">Finalrunde</h2></div>
    <div class="ko-grid">
      <?php foreach ($knockoutMatches as $m): ?>
        <article class="ko-card">
          <h3><?= htmlspecialchars($m['label'] ?? match_group_label($m)) ?></h3>
          <div class="muted" style="margin-bottom:8px;"><?= htmlspecialchars(match_display_kickoff($m, 'd.m.Y H:i')) ?> Uhr · Feld <?= htmlspecialchars((string)$m['field']) ?></div>
          <div class="ko-teams">
            <div><span><?= htmlspecialchars((string)($m['resolved_home'] ?? $m['home'])) ?></span><strong><?= $showScores && $m['home_score'] !== null ? (int)$m['home_score'] : '–' ?></strong></div>
            <div><span><?= htmlspecialchars((string)($m['resolved_away'] ?? $m['away'])) ?></span><strong><?= $showScores && $m['away_score'] !== null ? (int)$m['away_score'] : '–' ?></strong></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  </div>

  <div id="refresh-status" class="muted" style="margin-top:10px;font-size:.9rem;text-align:right;">Letzte Aktualisierung: <span id="last-refresh"></span></div>
</main>
<footer class="site-footer">
  <div class="footer-inner">
    <span>© <?= date('Y') ?> <a href="https://www.tont-online.de" target="_blank" rel="noopener" class="footer-link">OpenCoach – MatchDay </a>
	<div>by <a href="https://www.sportfreundehassmersheim.de" target="_blank" rel="noopener" class="footer-link">
    Spfr. Haßmersheim
  </a></div>
	</span>
    <a href="dashboard.php" class="admin-link">Admin</a>
  </div>
</footer>

<script>
(function(){
  const REFRESH_MS = 30000;
  const content = document.getElementById('live-content');
  const stamp = document.getElementById('last-refresh');
  if (!content || !stamp) return;

  content.classList.add('live-fade');

  function updateStamp(date = new Date()) {
    stamp.textContent = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  async function refreshLiveContent() {
    if (document.hidden) return;

    try {
      content.classList.add('is-refreshing');

      const url = new URL(window.location.href);
      url.searchParams.set('_refresh', Date.now().toString());

      const response = await fetch(url.toString(), {
        method: 'GET',
        headers: { 'X-Requested-With': 'matchday-live-refresh' },
        cache: 'no-store'
      });

      if (!response.ok) throw new Error('HTTP ' + response.status);

      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      const newContent = doc.getElementById('live-content');
      if (!newContent) throw new Error('Kein Live-Container gefunden');

      content.innerHTML = newContent.innerHTML;
      updateStamp();
    } catch (error) {
      console.error('Auto-Refresh fehlgeschlagen:', error);
    } finally {
      window.setTimeout(() => content.classList.remove('is-refreshing'), 120);
    }
  }

  updateStamp();
  window.setInterval(refreshLiveContent, REFRESH_MS);
})();
</script>
<?php if (defined('DEMO_MODE') && DEMO_MODE === true): ?>
<script>
(function(){
  var banner = document.querySelector('[data-demo-expires]');
  var timer = document.getElementById('demo-timer');

  if (!banner || !timer) return;

  var expiresAt = parseInt(banner.getAttribute('data-demo-expires'), 10) * 1000;

  function updateDemoTimer(){
    var diff = Math.max(0, expiresAt - Date.now());
    var totalSeconds = Math.floor(diff / 1000);
    var minutes = Math.floor(totalSeconds / 60);
    var seconds = totalSeconds % 60;

    timer.textContent =
      String(minutes).padStart(2, '0') + ':' +
      String(seconds).padStart(2, '0');

    if (totalSeconds <= 0) {
      timer.textContent = 'abgelaufen';
    }
  }

  updateDemoTimer();
  setInterval(updateDemoTimer, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
