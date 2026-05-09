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
[$current, $upcoming, $finished] = runtime_state_lists($resolvedMatches, $settings);

$clubName = trim((string)($settings['club_name'] ?? '')) ?: 'Turnierplan';
$teamLabel = trim((string)($settings['team_label'] ?? ''));
$logoUrl = brand_logo_url();
$duration = max(1, (int)($settings['spieldauer'] ?? 18));
$fieldCount = max(1, min(4, (int)($settings['spielfelder_anzahl'] ?? 1)));
$showTable = show_table_enabled($settings);
$showScores = public_scores_enabled($settings);
$runtimeNow = time();
$qrUrl = qr_code_url();

$headline = $current ? 'Läuft gerade' : 'Als Nächstes';
$heroMatches = $current ? array_slice($current, 0, $fieldCount) : array_slice($upcoming, 0, $fieldCount);
$nextMatches = $current ? $upcoming : array_slice($upcoming, $fieldCount);
$finished = array_slice($finished, 0, 6);
$nextPageSize = 3;

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function phase_label($m){ return !empty($m['label']) ? (string)$m['label'] : match_group_label($m); }
function table_title($group){
  $group = trim((string)$group);
  if ($group === '' || strtolower($group) === 'tabelle') return 'Gesamttabelle';
  if (stripos($group, 'gruppe') === 0) return $group;
  return 'Gruppe ' . $group;
}
function table_title_key($group){
  $title = strtolower(trim((string)table_title($group)));
  $title = preg_replace('/\s+/', ' ', $title);
  return $title;
}

$nextPages = [];
if ($nextMatches) {
  $nextPages = array_chunk($nextMatches, $nextPageSize);
}

$tableGroups = [];
$seenTableTitles = [];
foreach ($tables as $group => $rows) {
  $titleKey = table_title_key($group);
  if (isset($seenTableTitles[$titleKey])) continue;
  $seenTableTitles[$titleKey] = true;
  $tableGroups[] = [
    'title' => table_title($group),
    'rows' => array_slice($rows, 0, 4),
  ];
}
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(site_title('Screen')) ?></title>
  <style>
    :root{
      --primary:#062246;
      --secondary:#0b4273;
      --line:rgba(255,255,255,.08);
      --line-strong:rgba(255,255,255,.14);
      --card:rgba(255,255,255,.055);
      --card2:rgba(255,255,255,.03);
      --text:#eef4fb;
      --muted:#b8c7db;
      --ok:#55e1a4;
      --warn:#ffd36e;
      --danger:#ff8b8b;
      --shadow:0 1.2vmin 3vmin rgba(0,0,0,.22);
      --pad:clamp(10px, 1.1vw, 22px);
      --gap:clamp(10px, 1vw, 18px);
      --radius:clamp(16px, 1.1vw, 26px);
      --title-size:clamp(1rem, 1.42vw, 1.68rem);
      --small-size:clamp(.78rem, .8vw, .94rem);
      --card-title:clamp(.84rem, .95vw, 1.16rem);
      --team-size:clamp(1.08rem, 1.45vw, 2.02rem);
      --score-size:clamp(1.9rem, 2.45vw, 3.15rem);
      --time-size:clamp(2rem, 2.52vw, 3.45rem);
    }

    *{box-sizing:border-box}
    html,body{
      margin:0;
      width:100%;
      height:100%;
      overflow:hidden;
      font-family:Arial,Helvetica,sans-serif;
      color:var(--text);
      background:
        radial-gradient(circle at top left, rgba(8,70,122,.38), transparent 32%),
        radial-gradient(circle at bottom right, rgba(8,70,122,.18), transparent 28%),
        linear-gradient(180deg, #040d19 0%, #07182c 100%);
    }
    body{padding:clamp(8px, .9vw, 18px)}

    .viewport{width:100%;height:100%}
    .screen{
      width:100%;
      height:100%;
      display:grid;
      grid-template-rows:minmax(96px, 16.5vh) minmax(0, 1fr) auto;
      gap:var(--gap);
    }

    .box{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      min-height:0;
      box-shadow:var(--shadow);
      backdrop-filter:blur(4px);
    }

    .top{
      display:grid;
      grid-template-columns:minmax(90px, 7vw) minmax(0, 1fr) minmax(170px, 13vw);
      gap:var(--gap);
      min-height:0;
    }
    .logo{
      display:flex;
      align-items:center;
      justify-content:center;
      padding:var(--pad);
    }
    .logo img{
      max-width:100%;
      max-height:100%;
      object-fit:contain;
    }
    .brand{
      padding:clamp(12px, 1.1vw, 20px) clamp(14px, 1.3vw, 24px);
      background:linear-gradient(135deg, rgba(6,34,70,.95), rgba(11,66,115,.92));
      display:grid;
      grid-template-columns:minmax(0, 1fr) auto;
      align-items:center;
      gap:clamp(10px, .9vw, 16px);
      min-height:0;
      overflow:hidden;
    }
    .brand-copy{
      min-width:0;
      display:grid;
      align-content:center;
    }
    .brand-copy h1,
    .brand-copy .sub,
    .brand-copy .meta{
      min-width:0;
    }
    .brand-qr{
      display:flex;
      align-items:center;
      justify-content:center;
      align-self:center;
      min-width:0;
      padding-left:clamp(2px, .35vw, 8px);
    }
    .brand-qr img{
      width:clamp(68px, 4.8vw, 98px);
      height:clamp(68px, 4.8vw, 98px);
      object-fit:contain;
      border-radius:12px;
      background:#fff;
      padding:6px;
      border:1px solid rgba(255,255,255,.12);
      display:block;
    }
    .brand-qr .qr-label{
      margin-top:.25rem;
      font-size:clamp(.64rem, .62vw, .8rem);
      font-weight:800;
      color:#eef4fb;
      line-height:1;
      text-align:center;
      white-space:nowrap;
    }
    .brand h1{
      margin:0;
      font-size:clamp(1.58rem, 2.28vw, 3.02rem);
      line-height:1.02;
      letter-spacing:.01em;
    }
    .brand .sub{
      margin-top:.35rem;
      font-size:clamp(.88rem, .98vw, 1.12rem);
      color:#d9e8f7;
      min-height:1.2em;
    }
    .meta{
      display:flex;
      gap:clamp(8px, .95vw, 18px);
      flex-wrap:wrap;
      margin-top:.55rem;
      font-size:var(--small-size);
      color:#d4dfeb;
    }
    .meta strong{color:#fff}

    .clock{
      padding:var(--pad);
      display:grid;
      grid-template-rows:auto auto;
      justify-items:center;
      align-content:center;
      text-align:center;
      gap:.22rem;
      overflow:hidden;
    }
    .clock .time{
      font-size:var(--time-size);
      font-weight:900;
      line-height:1;
      font-variant-numeric:tabular-nums;
    }
    .clock .date{
      font-size:clamp(.82rem, .95vw, 1.1rem);
      color:var(--muted);
    }
    

    .layout{
      display:grid;
      grid-template-columns:minmax(0, 1.85fr) minmax(280px, .95fr);
      gap:var(--gap);
      min-height:0;
    }

    .panel{
      padding:var(--pad);
      min-height:0;
      display:flex;
      flex-direction:column;
    }
    .panel h2{
      margin:0 0 .6rem;
      font-size:var(--title-size);
      line-height:1.05;
    }

    .left-panel{
      display:grid;
      grid-template-rows:auto minmax(0, 1fr);
      gap:var(--gap);
      min-height:0;
    }

    .hero-grid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:var(--gap);
    }
    .hero-grid.cols-1{grid-template-columns:1fr}
    .hero-grid.cols-3{grid-template-columns:repeat(3, minmax(0, 1fr))}
    .hero-grid.cols-4{grid-template-columns:repeat(2, minmax(0, 1fr))}

    .match-card{
      padding:clamp(12px, 1vw, 18px);
      border:1px solid var(--line);
      border-radius:calc(var(--radius) - 4px);
      background:linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
      min-height:0;
      display:flex;
      flex-direction:column;
      justify-content:center;
    }
    .match-card.is-upcoming{
      border:2px solid #00d0ff;
      box-shadow:
        0 0 12px rgba(0,208,255,0.35),
        0 0 30px rgba(0,208,255,0.25),
        var(--shadow);
    }
    .match-card.is-live{
      border:2px solid #ff3b3b;
      box-shadow:
        0 0 15px rgba(255,59,59,0.45),
        0 0 35px rgba(255,59,59,0.35),
        var(--shadow);
    }
    .match-card .when{
      margin-top:.05rem;
      font-size:var(--card-title);
      font-weight:700;
      line-height:1.08;
      color:rgba(238,244,251,.82);
      letter-spacing:.01em;
    }
    .match-card .phase{
      color:rgba(184,199,219,.9);
      font-size:inherit;
      font-weight:700;
    }
    .match-card .teams{
      margin-top:.5rem;
      display:grid;
      gap:.35rem;
    }
    .match-card .team-line{
      display:grid;
      grid-template-columns:1fr auto;
      gap:.8rem;
      align-items:center;
      font-size:var(--team-size);
      line-height:1.08;
      font-weight:900;
    }
    .match-card .team-line span:first-child{
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .match-card .team-line .score-live{
      font-size:var(--score-size);
      line-height:1;
      font-weight:900;
      color:var(--warn);
      min-width:2.2ch;
      text-align:right;
      font-variant-numeric:tabular-nums;
    }
    .match-card .vs-line{
      font-size:clamp(.68rem, .7vw, .9rem);
      color:var(--muted);
      font-weight:800;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .next-block{
      display:flex;
      flex-direction:column;
      min-height:0;
    }
    .next-list-wrap,
    .results-list-wrap,
    .table-wrap{
      flex:1;
      min-height:0;
      position:relative;
      overflow:hidden;
      border-radius:calc(var(--radius) - 8px);
      background:transparent;
      border:0;
      padding:0;
      display:block;
    }
    .page-deck{position:relative;height:100%;min-height:0}
    .next-page,.table-page{display:none;height:100%;min-height:0}
    .next-page.active,.table-page.active{display:block}
    .list-stack,.result-list{display:grid;gap:clamp(6px, .6vw, 10px);align-content:start}

    .mini-row{
      display:grid;
      grid-template-columns:minmax(64px, 72px) minmax(56px, 66px) 1fr;
      gap:clamp(8px, .7vw, 12px);
      align-items:center;
      padding:clamp(8px, .65vw, 10px);
      border-radius:14px;
      background:rgba(255,255,255,.035);
      border:1px solid rgba(255,255,255,.08);
      min-height:0;
    }
    .mini-row .t,
    .result-row .t{
      font-size:clamp(1rem, 1.05vw, 1.45rem);
      font-weight:900;
      font-variant-numeric:tabular-nums;
    }
    .mini-row .f{
      font-size:clamp(.92rem, .92vw, 1.12rem);
      color:var(--warn);
      font-weight:900;
      white-space:nowrap;
    }
    .mini-row .n,
    .result-row .n{
      font-size:clamp(.92rem, .92vw, 1.08rem);
      line-height:1.14;
      overflow:hidden;
    }
    .mini-row .n strong,
    .result-row .n strong{
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      display:inline-block;
      max-width:100%;
      vertical-align:bottom;
    }
    .mini-row.top-priority{
      border-color:rgba(255,211,110,.22);
      background:rgba(255,211,110,.06);
    }

    .right-column{
      display:grid;
      grid-template-rows:minmax(0, 1fr) minmax(0, 1fr);
      gap:var(--gap);
      min-height:0;
    }

    .result-row{
      display:grid;
      grid-template-columns:minmax(58px, 66px) 1fr minmax(68px, 84px);
      gap:clamp(7px, .62vw, 10px);
      align-items:center;
      padding:clamp(8px, .65vw, 10px);
      border-radius:14px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.06);
    }
    .result-row .score{
      font-size:clamp(1.35rem, 1.45vw, 2rem);
      font-weight:900;
      text-align:right;
      font-variant-numeric:tabular-nums;
    }

    .group-card{
      height:100%;
      padding:clamp(8px, .68vw, 10px);
      border-radius:16px;
      background:rgba(255,255,255,.028);
      border:1px solid rgba(255,255,255,.05);
      display:flex;
      flex-direction:column;
    }
    .group-card h3{
      margin:0 0 .35rem;
      font-size:clamp(.92rem, .9vw, 1.08rem);
      line-height:1.05;
    }
    .rank-row{
      display:grid;
      grid-template-columns:1.7rem 1fr 2.8rem;
      gap:.32rem;
      padding:.3rem 0;
      border-top:1px solid rgba(255,255,255,.05);
      font-size:clamp(.8rem, .78vw, .92rem);
      align-items:center;
    }
    .rank-row:first-of-type{border-top:0}
    .rank-row > div:nth-child(2){
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }

    .muted{color:var(--muted)}
    .empty-note{
      height:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding:.85rem;
      border-radius:16px;
      background:rgba(255,255,255,.025);
      border:1px solid rgba(255,255,255,.05);
      font-size:clamp(.9rem, .94vw, 1.08rem);
      color:var(--muted);
    }

    .tv-footer{
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:0;
      padding:.2rem 0 .05rem;
      color:rgba(238,244,251,.62);
      font-size:clamp(.72rem, .72vw, .92rem);
      letter-spacing:.06em;
      text-transform:uppercase;
      text-align:center;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    @media (min-width: 1500px){
      .hero-grid.cols-3{grid-template-columns:repeat(3, minmax(0, 1fr))}
      .hero-grid.cols-4{grid-template-columns:repeat(4, minmax(0, 1fr))}
    }

    @media (max-width: 1400px){
      .layout{grid-template-columns:minmax(0, 1.55fr) minmax(250px, .9fr)}
      .top{grid-template-columns:90px minmax(0, 1fr) 160px}
    }

    @media (max-aspect-ratio: 16/9){
      .screen{grid-template-rows:minmax(92px, 17.5vh) minmax(0, 1fr) auto}
      .layout{grid-template-columns:minmax(0, 1.45fr) minmax(240px, .92fr)}
    }
  </style>
</head>
<body>
  <div class="viewport">
    <main class="screen" id="screenRoot">
      <section class="top">
        <div class="box logo"><img src="<?= e($logoUrl) ?>" alt="Logo"></div>
        <div class="box brand">
          <div class="brand-copy">
            <h1><?= e($clubName) ?></h1>
            <div class="sub"><?= $teamLabel !== '' ? e($teamLabel) : '&nbsp;' ?></div>
            <div class="meta">
              <span><strong>Ort:</strong> <?= e((string)($settings['spielort'] ?? '–')) ?></span>
              <span><strong>Spielzeit:</strong> <?= (int)$duration ?> min</span>
              <span><strong>Spiele:</strong> <?= count($resolvedMatches) ?></span>
            </div>
          </div>
          <div class="brand-qr">
            <div>
              <img src="<?= e($qrUrl) ?>" alt="QR">
              <div class="qr-label">Live-Spielplan</div>
            </div>
          </div>
        </div>
        <div class="box clock">
          <div class="time"><?= e(date('H:i')) ?></div>
          <div class="date"><?= e(date('d.m.Y')) ?></div>
        </div>
      </section>

      <section class="layout">
        <div class="box panel">
          <div class="left-panel">
            <section>
              <h2><?= e($headline) ?></h2>
              <div class="hero-grid <?= count($heroMatches) <= 1 ? 'cols-1' : (count($heroMatches) === 3 ? 'cols-3' : (count($heroMatches) >= 4 ? 'cols-4' : '')) ?>">
                <?php if (!$heroMatches): ?>
                  <div class="match-card is-upcoming" style="grid-column:1/-1;">
                    <div class="teams"><div class="team-line"><span>Es sind aktuell keine Spiele vorhanden.</span></div></div>
                  </div>
                <?php else: ?>
                  <?php foreach ($heroMatches as $m): ?>
                    <?php
                      $isLive = (match_runtime_state($m, $settings, $runtimeNow) === 'live');
                      $hasScore = (($m['home_score'] ?? null) !== null && ($m['away_score'] ?? null) !== null);
                      $homeName = (string)($m['resolved_home'] ?? $m['home']);
                      $awayName = (string)($m['resolved_away'] ?? $m['away']);
                    ?>
                    <article class="match-card <?= $isLive ? 'is-live' : 'is-upcoming' ?>">
                      <div class="when"><?= e(match_display_kickoff($m, 'H:i')) ?> · Feld <?= e((string)$m['field']) ?> <span class="phase">· <?= e(phase_label($m)) ?></span></div>
                      <div class="teams">
                        <?php if ($isLive && $hasScore && $showScores): ?>
                          <div class="team-line"><span><?= e($homeName) ?></span><span class="score-live"><?= (int)$m['home_score'] ?></span></div>
                          <div class="vs-line">live</div>
                          <div class="team-line"><span><?= e($awayName) ?></span><span class="score-live"><?= (int)$m['away_score'] ?></span></div>
                        <?php else: ?>
                          <div class="team-line"><span><?= e($homeName) ?></span></div>
                          <div class="vs-line"><?= $isLive ? 'live' : 'gegen' ?></div>
                          <div class="team-line"><span><?= e($awayName) ?></span></div>
                        <?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </section>

            <section class="next-block">
              <h2>Danach</h2>
              <div class="next-list-wrap">
                <?php if (!$nextPages): ?>
                  <div class="empty-note">Keine weiteren Spiele im Plan.</div>
                <?php else: ?>
                  <div class="page-deck" id="nextDeck">
                    <?php foreach ($nextPages as $pageIndex => $page): ?>
                      <div class="next-page <?= $pageIndex === 0 ? 'active' : '' ?>" data-next-page="<?= $pageIndex ?>">
                        <div class="list-stack">
                          <?php foreach ($page as $idx => $m): ?>
                            <div class="mini-row <?= $idx < 2 ? 'top-priority' : '' ?>">
                              <div class="t"><?= e(match_display_kickoff($m, 'H:i')) ?></div>
                              <div class="f">Feld <?= e((string)$m['field']) ?></div>
                              <div class="n"><strong><?= e((string)($m['resolved_home'] ?? $m['home'])) ?></strong> gegen <strong><?= e((string)($m['resolved_away'] ?? $m['away'])) ?></strong> <span class="muted">· <?= e(phase_label($m)) ?></span></div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </section>
          </div>
        </div>

        <div class="right-column">
          <section class="box panel results-panel">
            <h2>Letzte Ergebnisse</h2>
            <div class="results-list-wrap">
              <?php if (!$finished): ?>
                <div class="empty-note">Noch keine abgeschlossenen Spiele.</div>
              <?php else: ?>
                <div class="result-list">
                  <?php foreach ($finished as $m): ?>
                    <div class="result-row">
                      <div class="t"><?= e(date('H:i', strtotime((string)($m['actual_end'] ?? $m['kickoff'])))) ?></div>
                      <div class="n"><strong><?= e((string)($m['resolved_home'] ?? $m['home'])) ?></strong><br><span class="muted">gegen <?= e((string)($m['resolved_away'] ?? $m['away'])) ?></span></div>
                      <div class="score"><?php if (!empty($m['_has_score']) && $showScores): ?><?= (int)$m['home_score'] ?> : <?= (int)$m['away_score'] ?><?php else: ?>–<?php endif; ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <?php if ($showTable): ?>
          <section class="box panel table-panel">
            <h2>Tabellenstand</h2>
            <div class="table-wrap">
              <?php if (!$tableGroups): ?>
                <div class="empty-note">Keine Tabelle verfügbar.</div>
              <?php else: ?>
                <div class="page-deck" id="tableDeck">
                  <?php foreach ($tableGroups as $tableIndex => $table): ?>
                    <div class="table-page <?= $tableIndex === 0 ? 'active' : '' ?>" data-table-page="<?= $tableIndex ?>">
                      <div class="group-card">
                        <h3><?= e($table['title']) ?></h3>
                        <?php foreach ($table['rows'] as $row): ?>
                          <div class="rank-row">
                            <div><?= (int)$row['rank'] ?>.</div>
                            <div><?= e((string)$row['team']) ?></div>
                            <div style="text-align:right;"><?= (int)$row['points'] ?> P</div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>
          <?php endif; ?>
        </div>
      </section>

      <footer class="tv-footer">OpenCoach MatchDay</footer>
    </main>
  </div>

  <script>
    (function(){
      const cycleMs = 8000;
      let nextTimer = null;
      let tableTimer = null;

      function startCycle(selector, timerRefName){
        const pages = Array.from(document.querySelectorAll(selector));
        if (window[timerRefName]) {
          window.clearInterval(window[timerRefName]);
          window[timerRefName] = null;
        }
        if (!pages.length) return;
        pages.forEach(function(page, idx){ page.classList.toggle('active', idx === 0); });
        if (pages.length <= 1) return;
        let current = 0;
        window[timerRefName] = window.setInterval(function(){
          pages[current].classList.remove('active');
          current = (current + 1) % pages.length;
          pages[current].classList.add('active');
        }, cycleMs);
      }

      function initCycles(){
        startCycle('[data-next-page]', 'nextTimer');
        startCycle('[data-table-page]', 'tableTimer');
      }

      initCycles();

      window.setInterval(function(){
        fetch(window.location.href, { cache: 'no-store' })
          .then(function(res){ return res.text(); })
          .then(function(html){
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshLayout = doc.querySelector('.layout');
            const freshTop = doc.querySelector('.top');
            const currentLayout = document.querySelector('.layout');
            const currentTop = document.querySelector('.top');
            if (freshTop && currentTop) currentTop.innerHTML = freshTop.innerHTML;
            if (freshLayout && currentLayout) currentLayout.innerHTML = freshLayout.innerHTML;
            initCycles();
          })
          .catch(function(){});
      }, 20000);
    })();
  </script>
</body>
</html>
