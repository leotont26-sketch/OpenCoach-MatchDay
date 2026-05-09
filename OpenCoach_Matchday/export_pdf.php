<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
if (!is_logged_in()) {
  header('Location: dashboard.php');
  exit;
}
date_default_timezone_set(cfg()['timezone']);
$settings = load_settings();
$matches = load_matches();
sort_matches($matches);
$tables = compute_standings($matches);
$resolvedMatches = resolve_match_labels($matches, $tables);
$resolvedMatches = apply_dynamic_display_times($resolvedMatches, $settings);
usort($resolvedMatches, function($a, $b){
  $ta = match_display_kickoff_ts($a);
  $tb = match_display_kickoff_ts($b);
  if($ta === $tb){
    $fa = (int)($a['field'] ?? 0);
    $fb = (int)($b['field'] ?? 0);
    if($fa === $fb){
      return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    }
    return $fa <=> $fb;
  }
  return $ta <=> $tb;
});
$clubName = trim((string)($settings['club_name'] ?? '')) ?: 'Turnierplan';
$teamLabel = trim((string)($settings['team_label'] ?? ''));
$logoPath = trim((string)($settings['logo_path'] ?? 'logo.png')) ?: 'logo.png';
$logoFile = __DIR__ . '/' . ltrim($logoPath, '/');
$logoUrl = is_file($logoFile) ? $logoPath . '?v=' . @filemtime($logoFile) : 'logo.png';
$location = trim((string)($settings['spielort'] ?? ''));
$fields = max(1, (int)($settings['spielfelder_anzahl'] ?? 1));
$teamsTotal = 0;
foreach (($settings['teams_by_group'] ?? []) as $groupTeams) $teamsTotal += count((array)$groupTeams);
$startTs = !empty($settings['spielbeginn']) ? strtotime((string)$settings['spielbeginn']) : false;
$displayDate = $startTs ? date('d.m.Y', $startTs) : '–';
$displayStart = $startTs ? date('H:i', $startTs) . ' Uhr' : '–';
$publicBase = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$publicIndex = $publicBase . rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\') . '/index.php';
$publicIndex = preg_replace('~(?<!:)//+~', '/', $publicIndex);
$publicIndex = preg_replace('~^https:/~', 'https://', $publicIndex);
$publicIndex = preg_replace('~^http:/~', 'http://', $publicIndex);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode($publicIndex);
$primary = '#021B33';
$secondary = '#02315E';
$lightLine = '#d8e1ea';
$softText = '#4c5f74';
$pageRows = 20;
$showScores = public_scores_enabled($settings);
$pages = array_chunk($resolvedMatches, $pageRows);
if (!$pages) $pages = [[]];

function e_pdf($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function pdf_match_title($match){
  if (!empty($match['label'])) return (string)$match['label'];
  $phase = $match['phase'] ?? 'group';
  if ($phase !== 'group') return match_group_label($match);
  return (string)($match['group'] ?? '');
}
function svg_data_uri($svg) {
  return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

$isDemoMode = defined('DEMO_MODE') && DEMO_MODE === true;

$sheetBgUrl = svg_data_uri(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1120 1500" preserveAspectRatio="none">'
  . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
  . '<stop offset="0%" stop-color="#ffffff"/><stop offset="100%" stop-color="#f4f8fc"/>'
  . '</linearGradient></defs>'
  . '<rect width="1120" height="1500" fill="url(#g)"/>'
  . '<circle cx="980" cy="140" r="170" fill="#e8f0f8"/>'
  . '<circle cx="1040" cy="210" r="110" fill="#f3f7fb"/>'
  . '<circle cx="120" cy="1260" r="180" fill="#eef4fa"/>'
  . '</svg>'
);

$bandBgUrl = svg_data_uri(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 180" preserveAspectRatio="none">'
  . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
  . '<stop offset="0%" stop-color="#021B33"/><stop offset="100%" stop-color="#02315E"/>'
  . '</linearGradient></defs>'
  . '<rect width="800" height="180" fill="url(#g)"/>'
  . '<circle cx="690" cy="-10" r="150" fill="rgba(255,255,255,0.06)"/>'
  . '<circle cx="780" cy="140" r="110" fill="rgba(255,255,255,0.05)"/>'
  . '<path d="M0 145 C180 95, 320 185, 800 88 L800 180 L0 180 Z" fill="rgba(255,255,255,0.06)"/>'
  . '</svg>'
);

$theadBgUrl = svg_data_uri(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1120 56" preserveAspectRatio="none">'
  . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
  . '<stop offset="0%" stop-color="#021B33"/><stop offset="100%" stop-color="#02315E"/>'
  . '</linearGradient></defs>'
  . '<rect width="1120" height="56" fill="url(#g)"/>'
  . '<path d="M0 44 C120 28, 260 62, 420 42 C600 20, 760 62, 1120 30 L1120 56 L0 56 Z" fill="rgba(255,255,255,0.08)"/>'
  . '</svg>'
);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title><?= e_pdf(site_title('PDF Export')) ?></title>
  <style>
    :root{--primary:<?= $primary ?>;--secondary:<?= $secondary ?>;--line:<?= $lightLine ?>;--text:#132235;--muted:<?= $softText ?>;--page-bg:#fff;--soft:#f5f8fb;--shadow:0 12px 36px rgba(2,27,51,.08)}
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:#eef2f6;color:var(--text);font-family:Arial,Helvetica,sans-serif;min-width:1120px}
    body{padding:18px;overflow-x:auto}
    .toolbar{max-width:1120px;margin:0 auto 14px;display:flex;justify-content:flex-end;gap:10px}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border-radius:12px;border:1px solid #c7d3df;background:#fff;color:var(--text);text-decoration:none;font-weight:700;box-shadow:var(--shadow)}
    .btn.primary{background:linear-gradient(135deg,var(--primary),var(--secondary));border-color:transparent;color:#fff}

    .sheet{position:relative;width:1120px;max-width:none;margin:0 auto 18px;background:var(--page-bg);border:1px solid var(--line);border-radius:26px;box-shadow:var(--shadow);padding:26px 28px 20px;page-break-after:always;overflow:hidden;isolation:isolate}
    .sheet:last-child{page-break-after:auto}
    .sheet-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0;pointer-events:none;user-select:none}
    .sheet > *{position:relative;z-index:1}

    .demo-watermark{
      position:absolute;
      inset:0;
      z-index:5;
      pointer-events:none;
      user-select:none;
      display:flex;
      align-items:center;
      justify-content:center;
      transform:rotate(-28deg);
      opacity:.18;
      color:#b00000;
      font-size:96px;
      font-weight:900;
      letter-spacing:.08em;
      text-align:center;
      line-height:1.05;
      text-transform:uppercase;
    }

    .demo-watermark small{
      display:block;
      margin-top:14px;
      font-size:34px;
      letter-spacing:.04em;
}

    .hero{display:grid;grid-template-columns:118px 1fr 132px;gap:14px;align-items:stretch;border:1px solid var(--line);border-radius:22px;overflow:hidden;background:#fff}
    .logo-box,.qr-box{display:flex;align-items:center;justify-content:center;padding:20px;background:#fff}
    .logo-box img{max-width:none;height:75px;object-fit:contain}
    .brand-main{display:grid;grid-template-rows:auto auto;min-width:0}
    .brand-band{position:relative;overflow:hidden;background:none;padding:0;color:#fff;min-height:118px}
    .brand-band .band-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0;pointer-events:none}
    .brand-band .band-content{position:relative;z-index:1;padding:26px 24px 20px}
    .club-name{font-size:28px;line-height:1.1;font-weight:800}
    .subline{margin-top:6px;font-size:17px;opacity:.96}
    .meta-row{display:flex;flex-wrap:wrap;gap:14px;padding:14px 24px 16px;border-top:1px solid var(--line);font-size:14px;color:var(--muted);background:rgba(255,255,255,0.92)}
    .meta-row b{color:var(--text)}
    .qr-card{width:100%;height:100%;border-left:1px solid var(--line);display:flex;align-items:center;justify-content:center;padding:12px;background:#fff}
    .qr-inner{display:grid;gap:8px;justify-items:center;text-align:center}
    .qr-inner img{width:86px;height:86px;border-radius:10px;border:1px solid var(--line);background:#fff;padding:5px}
    .qr-note{font-size:12px;color:var(--muted)}
    .qr-note a,.qr-inner a{color:inherit;text-decoration:none}
    .qr-inner a{display:inline-flex;align-items:center;justify-content:center}

    .section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;margin:14px 0 10px}
    .section-head h2{margin:0;font-size:28px;line-height:1.1}
    .section-head .page-note{font-size:13px;color:var(--muted)}

    .table-shell{position:relative;border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff}
    .table-head-bg{position:absolute;top:0;left:0;width:100%;height:49px;object-fit:cover;z-index:0;pointer-events:none;user-select:none}
    table{position:relative;z-index:1;width:100%;border-collapse:collapse}
    thead th{background:transparent;color:#fff;padding:12px 14px;font-size:14px;text-align:left;white-space:nowrap}
    tbody td{padding:11px 14px;border-top:1px solid var(--line);font-size:14px;vertical-align:middle;background:rgba(255,255,255,0.94)}
    tbody tr:nth-child(even) td{background:rgba(248,251,255,0.96)}
    td.center,th.center{text-align:center}
    td.match-col{font-weight:700;color:#274666;text-align:center;white-space:nowrap}
    td.team{width:31%}

    .footer{display:flex;justify-content:space-between;gap:12px;margin-top:16px;padding-top:10px;border-top:1px solid var(--line);font-size:13px;color:var(--muted)}
    .footer a{color:inherit;text-decoration:none}

    @page{size:A4 portrait;margin:10mm}
    @media print{
      html,body{background:#fff;padding:0;min-width:auto;-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important}
      .toolbar{display:none!important}
      .sheet{width:auto;max-width:none;margin:0;box-shadow:none;border:0;border-radius:0;padding:0 0 2mm 0}
      .club-name{font-size:26px}
      .section-head h2{font-size:24px}
      tbody td,thead th{font-size:13px;padding:10px 12px}
      .table-head-bg{height:45px}
    }
  </style>
</head>
<body>
<div class="toolbar"><button class="btn primary" onclick="window.print()">Als PDF drucken</button></div>
<?php foreach ($pages as $pageIndex => $pageMatches): ?>
<section class="sheet">
   <?php if ($isDemoMode): ?>
    <div class="demo-watermark">
      <div>
        DEMOVERSION
        <small>Nicht für echten Turnierbetrieb</small>
      </div>
    </div>
  <?php endif; ?>
  <header class="hero">
    <div class="logo-box"><img src="<?= e_pdf($logoUrl) ?>" alt="Logo"></div>
    <div class="brand-main">
      <div class="brand-band">
        <img src="<?= e_pdf($bandBgUrl) ?>" alt="" class="band-bg" aria-hidden="true">
        <div class="band-content">
          <div class="club-name"><?= e_pdf($clubName) ?></div>
          <div class="subline">Spielplan<?= $teamLabel !== '' ? ' · ' . e_pdf($teamLabel) : '' ?></div>
        </div>
      </div>
      <div class="meta-row"><span><b>Ort:</b> <?= e_pdf($location !== '' ? $location : '–') ?></span><span><b>Datum:</b> <?= e_pdf($displayDate) ?></span><span><b>Beginn:</b> <?= e_pdf($displayStart) ?></span><span><b>Teams / Felder:</b> <?= (int)$teamsTotal ?> / <?= (int)$fields ?></span></div>
    </div>
    <div class="qr-card"><div class="qr-inner"><a href="<?= e_pdf($publicIndex) ?>" target="_blank" rel="noopener"><img src="<?= e_pdf($qrUrl) ?>" alt="QR-Code"></a><div class="qr-note"><a href="<?= e_pdf($publicIndex) ?>" target="_blank" rel="noopener">Live Ansicht</a></div></div></div>
  </header>

  <div class="section-head"><h2>Spielplan</h2><div class="page-note">Seite <?= $pageIndex + 1 ?> von <?= count($pages) ?></div></div>

  <div class="table-shell">
    <img src="<?= e_pdf($theadBgUrl) ?>" alt="" class="table-head-bg" aria-hidden="true">
    <table>
      <thead>
        <tr>
          <th class="center" style="width:7%">Spiel</th>
          <th class="center" style="width:12%">Zeit</th>
          <th class="center" style="width:10%">Feld</th>
          <th class="center" style="width:11%">Gruppe</th>
          <th style="width:25%">Team A</th>
          <th style="width:25%">Team B</th>
          <?php if ($showScores): ?><th class="center" style="width:10%">Ergebnis</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pageMatches): ?>
        <tr><td colspan="<?= $showScores ? 7 : 6 ?>" style="text-align:center;padding:28px 12px;color:var(--muted)">Noch keine Spiele vorhanden.</td></tr>
      <?php else: foreach ($pageMatches as $row): ?>
        <tr>
          <td class="center"><?= (int)($row['id'] ?? 0) ?></td>
          <td class="center"><?= e_pdf(match_display_kickoff($row, 'H:i')) ?></td>
          <td class="center"><?= e_pdf('Feld ' . (string)($row['field'] ?? '1')) ?></td>
          <td class="match-col"><?= e_pdf(pdf_match_title($row)) ?></td>
          <td class="team"><?= e_pdf((string)($row['resolved_home'] ?? $row['home'] ?? '')) ?></td>
          <td class="team"><?= e_pdf((string)($row['resolved_away'] ?? $row['away'] ?? '')) ?></td>
          <?php if ($showScores): ?>
            <td class="center"><?php if (($row['home_score'] ?? null) !== null && ($row['away_score'] ?? null) !== null): ?><?= (int)$row['home_score'] ?> : <?= (int)$row['away_score'] ?><?php else: ?>–<?php endif; ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <footer class="footer"><div>Generiert mit <a href="https://www.tont-online.de" target="_blank" rel="noopener" class="footer-link"><strong>OpenCoach – MatchDay </strong></a></div><a href="https://www.sportfreundehassmersheim.de" target="_blank" rel="noopener">by Spfr. Haßmersheim</a></footer>
</section>
<?php endforeach; ?>
<script>if(new URLSearchParams(window.location.search).get('autoprint')==='1'){window.addEventListener('load',()=>window.print(),{once:true});}</script>
</body>
</html>
