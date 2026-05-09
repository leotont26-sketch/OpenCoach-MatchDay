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
  <?php include __DIR__ . '/head.inc.html'; ?>
</head>
<body>
<main class="container">
  <?php include __DIR__ . '/header.inc.html'; ?>

  <header style="display:flex; justify-content: space-between; align-items:center; gap:.75rem; flex-wrap:wrap;">
    <form method="get" style="display:flex; gap:.5rem; align-items:center;">
      <select name="group" onchange="this.form.submit()">
        <option value="">Alle Gruppen</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= htmlspecialchars($g) ?>" <?= $selected===$g ? 'selected':'' ?>><?= htmlspecialchars($g) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="dashboard.php" role="button" class="secondary">Admin</a>
    </form>
  </header>

  <div class="table-wrap">
    <table class="sticky">
      <thead>
        <tr>
          <th>Anstoß</th>
          <th>Feld</th>
          <th>Gruppe</th>
          <th>Heim</th>
          <th>Gast</th>
          <th style="text-align:right;">Ergebnis</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($matches as $m): if ($selected && $m['group'] !== $selected) continue; ?>
        <tr>
          <td><?php $ts=strtotime($m['kickoff']); echo $ts?date($fmt,$ts):htmlspecialchars($m['kickoff']); ?></td>
          <td><?= htmlspecialchars($m['field']) ?></td>
          <td><span class="badge badge-brand"><?= htmlspecialchars($m['group']) ?></span></td>
          <td><?= htmlspecialchars($m['home']) ?></td>
          <td><?= htmlspecialchars($m['away']) ?></td>
          <td style="text-align:right;">
            <?php if ($m['home_score'] !== null && $m['away_score'] !== null): ?>
              <strong><?= (int)$m['home_score'] ?> : <?= (int)$m['away_score'] ?></strong>
            <?php else: ?>
              <span class="muted">– : –</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<script> setInterval(()=>{ if(!document.hidden) location.reload(); }, 30000); </script>
</body>
</html>
