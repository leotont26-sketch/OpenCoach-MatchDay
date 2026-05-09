<?php
// header_info.inc.php – zeigt Turnier-Infos aus data/settings.php
$settings_file = __DIR__ . '/data/settings.php';
$settings = [];
if (file_exists($settings_file)) {
  $arr = include $settings_file;
  if (is_array($arr)) { $settings = $arr; }
}
$spielort     = trim((string)($settings['spielort']     ?? ''));
$spielbeginn  = trim((string)($settings['spielbeginn']  ?? ''));
$spielprinzip = trim((string)($settings['spielprinzip'] ?? ''));
$spieldauer   = trim((string)($settings['spieldauer']   ?? ''));
$wechselzeit  = trim((string)($settings['wechselzeit']  ?? ''));

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="header-info" style="display:flex;flex-wrap:wrap;gap:.5rem;margin:.5rem 0;">
  <?php if ($spielort !== ''): ?>
    <span class="badge" style="border:1px solid #2a2f3a;border-radius:999px;padding:.2rem .6rem;">🏟️ <?= h($spielort) ?></span>
  <?php endif; ?>

  <?php if ($spielbeginn !== ''): ?>
    <span class="badge" style="border:1px solid #2a2f3a;border-radius:999px;padding:.2rem .6rem;">
      ⏱️ <?= h($spielbeginn) ?>
    </span>
  <?php endif; ?>

  <?php if ($spielprinzip !== ''): ?>
    <span class="badge" style="border:1px solid #2a2f3a;border-radius:999px;padding:.2rem .6rem;">📋 <?= h($spielprinzip) ?></span>
  <?php endif; ?>

  <?php if ($spieldauer !== ''): ?>
    <span class="badge" style="border:1px solid #2a2f3a;border-radius:999px;padding:.2rem .6rem;">⏳ <?= h($spieldauer) ?></span>
  <?php endif; ?>

  <?php if ($wechselzeit !== ''): ?>
    <span class="badge" style="border:1px solid #2a2f3a;border-radius:999px;padding:.2rem .6rem;">🔁 <?= h($wechselzeit) ?></span>
  <?php endif; ?>
</div>
