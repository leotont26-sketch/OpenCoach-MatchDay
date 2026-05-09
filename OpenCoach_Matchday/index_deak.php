<?php
require_once __DIR__ . '/functions.php';
date_default_timezone_set(cfg()['timezone']);
$matches = load_matches();
sort_matches($matches);
$groups = unique_groups($matches);
$selected = $_GET['group'] ?? '';
$fmt = cfg()['datetime_format'];
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Spielplan & Ergebnisse</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <style>
    .match-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 0.75rem 1rem; margin-bottom: 0.5rem; }
    .muted { color: #6b7280; font-size: 0.9rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem; }
    header { margin-bottom: 1rem; }
    .badge { font-size: 0.75rem; background: #eef2ff; padding: 0.15rem 0.5rem; border-radius: 8px; }
    .score { font-weight: bold; }
  </style>
</head>
<body>
<main class="container">
  <header>
    <h1>Spielplan</h1>
    <small class="muted">Öffentliche Ansicht</small>
    <nav>
      <form method="get">
        <select name="group" onchange="this.form.submit()">
          <option value="">Alle Gruppen</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= htmlspecialchars($g) ?>" <?= $selected===$g ? 'selected':'' ?>><?= htmlspecialchars($g) ?></option>
          <?php endforeach; ?>
        </select>
        <a href="dashboard.php" role="button" class="secondary">Admin</a>
      </form>
    </nav>
  </header>

  <section class="grid">
  <?php foreach ($matches as $m): if ($selected && $m['group'] !== $selected) continue; ?>
    <article class="match-card">
      <header style="display:flex; justify-content: space-between; align-items:center;">
        <span class="badge"><?= htmlspecialchars($m['group']) ?></span>
        <span class="muted">Feld <?= htmlspecialchars($m['field']) ?></span>
      </header>
      <h3><?= htmlspecialchars($m['home']) ?> <span class="muted">vs</span> <?= htmlspecialchars($m['away']) ?></h3>
      <footer style="display:flex; justify-content: space-between; align-items:center;">
        <span class="muted">
          <?php $ts=strtotime($m['kickoff']); echo $ts?date($fmt,$ts):htmlspecialchars($m['kickoff']); ?>
        </span>
        <?php if ($m['home_score'] !== null && $m['away_score'] !== null): ?>
          <span class="score"><?= (int)$m['home_score'] ?> : <?= (int)$m['away_score'] ?></span>
        <?php else: ?>
          <span class="muted">– : –</span>
        <?php endif; ?>
      </footer>
    </article>
  <?php endforeach; ?>
  </section>
</main>
</body>
</html>
