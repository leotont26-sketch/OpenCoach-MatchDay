<?php
require_once __DIR__ . '/functions.php';
date_default_timezone_set(cfg()['timezone']);
$settings = load_settings();
$settingsWithTokens = ensure_referee_tokens($settings, load_matches());
if (($settings['referee_tokens'] ?? []) !== ($settingsWithTokens['referee_tokens'] ?? [])) save_settings($settingsWithTokens);
$settings = $settingsWithTokens;
$field = (int)($_GET['field'] ?? 0);
$token = (string)($_GET['token'] ?? '');
if (!validate_referee_access($field, $token, $settings)) {
  http_response_code(403);
  echo '<!doctype html><meta charset="utf-8"><title>Kein Zugriff</title><body style="font-family:Arial;background:#08192f;color:#fff;padding:40px;">Zugriff abgelaufen oder ungültig.</body>';
  exit;
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function referee_json_response($payload, $statusCode = 200){
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $matches = load_matches();
  $id = (int)($_POST['match_id'] ?? 0);

  if (isset($_POST['ref_save_score'])) {
    if (!internal_scores_enabled($settings)) {
      referee_json_response(['ok' => false, 'message' => 'Interne Ergebnisse sind deaktiviert.'], 403);
    }

    $updated = update_match_scores(
      $matches,
      $id,
      $_POST['home_score'] ?? '',
      $_POST['away_score'] ?? '',
      $field,
      false
    );

    if (!$updated) {
      referee_json_response(['ok' => false, 'message' => 'Spiel konnte nicht gespeichert werden.'], 404);
    }

    if (!save_matches_with_resolved_order($matches)) {
      referee_json_response(['ok' => false, 'message' => 'Speichern fehlgeschlagen.'], 500);
    }

    referee_json_response([
      'ok' => true,
      'message' => 'Ergebnis gespeichert.',
      'home_score' => ($_POST['home_score'] ?? '') === '' ? null : max(0, (int)$_POST['home_score']),
      'away_score' => ($_POST['away_score'] ?? '') === '' ? null : max(0, (int)$_POST['away_score']),
    ]);
  }

  if (isset($_POST['ref_start'])) {
    if (start_match($matches, $id, $settings) && save_matches_with_resolved_order($matches)) $msg = 'Spiel gestartet.';
    else $msg = 'Spiel konnte nicht gestartet werden.';
  }

  if (isset($_POST['ref_finish'])) {
    if (internal_scores_enabled($settings)) {
      update_match_scores(
        $matches,
        $id,
        $_POST['home_score'] ?? '',
        $_POST['away_score'] ?? '',
        $field,
        true
      );
    }
    if (finish_match($matches, $id, $settings) && save_matches_with_resolved_order($matches)) $msg = 'Spiel beendet.';
    else $msg = 'Spiel konnte nicht beendet werden.';
  }
}
$matches = load_matches(); sort_matches($matches); $tables = compute_standings($matches); $resolvedMatches = resolve_match_labels($matches, $tables); $resolvedMatches = apply_dynamic_display_times($resolvedMatches, $settings);
sort_matches_for_display($resolvedMatches, $settings);
$match = current_or_next_match_for_field($resolvedMatches, $field, $settings);
$duration = max(1, (int)($settings['spieldauer'] ?? 18));
$showInternal = internal_scores_enabled($settings);
?><!doctype html>
<html lang="de"><head><meta charset="UTF-8"><meta http-equiv="refresh" content="20"><title><?= e(site_title('Schiedsrichter Feld '.$field)) ?></title>
<style>
:root{--primary:#021B33;--secondary:#02315E;--line:rgba(255,255,255,.12);--card:rgba(255,255,255,.06);--text:#eef4fb;--muted:#afc0d8;--warn:#ffd36e;--ok:#69e3a8;--danger:#ff8d8d}
*{box-sizing:border-box} html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif;background:radial-gradient(circle at top left, rgba(2,49,94,.55), transparent 36%),linear-gradient(180deg, #04101f 0%, #08192f 100%);color:var(--text)}
body{padding:22px}.wrap{max-width:760px;margin:0 auto;display:grid;gap:18px}.box{background:var(--card);border:1px solid var(--line);border-radius:24px;backdrop-filter:blur(10px)}.head{padding:22px;background:linear-gradient(135deg,var(--primary),var(--secondary))}.head h1{margin:0;font-size:34px}.sub{margin-top:8px;color:#d9e8f7}.panel{padding:22px}.teams{display:grid;gap:14px;margin-top:18px}.team{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;font-size:30px;font-weight:800}.score{font-size:42px;font-weight:900;color:var(--warn)}.vs{font-size:16px;color:var(--muted);text-transform:uppercase;font-weight:700}.btnrow{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 18px;border-radius:14px;border:1px solid transparent;font-weight:800;background:#fff;color:#08192f;text-decoration:none;cursor:pointer}.btn.primary{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff}.btn.warn{background:#ffd36e}.muted{color:var(--muted)}.timer{font-size:64px;font-weight:900;line-height:1;text-align:center}.inputs{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}.inputs input{width:100%;min-height:52px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:#fff;padding:0 14px;font-size:24px;font-weight:800;text-align:center}.flash{padding:14px 16px;border-radius:14px;background:rgba(81,227,160,.14);border:1px solid rgba(81,227,160,.32)}.savehint{margin-top:10px;font-size:13px;color:var(--muted);min-height:18px}.savehint.ok{color:var(--ok)}.savehint.error{color:var(--danger)}.savehint.busy{color:var(--warn)}
</style></head><body><main class="wrap"><section class="box head"><h1>Schiedsrichter · Feld <?= $field ?></h1><div class="sub">Timer läuft sobald Start gedrückt. Ergebnisse werden sofort gespeichert und übertragen!</div></section><?php if($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
<section class="box panel">
<?php if(!$match): ?><div class="muted">Für dieses Feld ist aktuell kein Spiel mehr geplant.</div><?php else: ?>
<?php $status = match_runtime_state($match, $settings); $isLive = $status === 'live'; $kickoff = match_display_kickoff_ts($match);
 $startTs = !empty($match['actual_start']) ? strtotime((string)$match['actual_start']) : null; if (!$startTs && $isLive && control_mode($settings) === 'auto') $startTs = $kickoff; $remaining = $isLive && $startTs ? max(0, ($startTs + $duration*60) - time()) : $duration*60; ?>
<div class="muted"><?= e(match_display_kickoff($match, 'H:i')) ?> · <?= e(!empty($match['label']) ? $match['label'] : match_group_label($match)) ?></div>
<div class="teams">
  <div class="team"><span><?= e((string)($match['resolved_home'] ?? $match['home'])) ?></span><span class="score" data-score-home><?= ($showInternal && ($match['home_score'] ?? null) !== null) ? (int)$match['home_score'] : '–' ?></span></div>
  <div class="vs"><?= $isLive ? 'live' : (($status === 'finished') ? 'beendet' : 'bereit') ?></div>
  <div class="team"><span><?= e((string)($match['resolved_away'] ?? $match['away'])) ?></span><span class="score" data-score-away><?= ($showInternal && ($match['away_score'] ?? null) !== null) ? (int)$match['away_score'] : '–' ?></span></div>
</div>
<div class="timer" data-remaining="<?= (int)$remaining ?>" data-status="<?= e($status) ?>">--:--</div>
<form method="post" id="referee-form">
  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
  <?php if ($showInternal): ?>
  <div class="inputs"><input type="number" min="0" name="home_score" value="<?= ($match['home_score'] ?? null) !== null ? (int)$match['home_score'] : '' ?>" placeholder="Heim" inputmode="numeric"><input type="number" min="0" name="away_score" value="<?= ($match['away_score'] ?? null) !== null ? (int)$match['away_score'] : '' ?>" placeholder="Gast" inputmode="numeric"></div>
  <div class="savehint" id="savehint" aria-live="polite"></div>
  <?php endif; ?>
  <div class="btnrow">
    <?php if ($status !== 'live' && $status !== 'finished'): ?><button class="btn primary" type="submit" name="ref_start" value="1">Start</button><?php endif; ?>
    <?php if ($status === 'live'): ?><button class="btn warn" type="submit" name="ref_finish" value="1">Fertig / Abpfiff</button><?php endif; ?>
  </div>
</form>
<?php endif; ?>
</section></main>
<script>
const t = document.querySelector('.timer');

if (t) {
  const status = t.dataset.status || '';
  let s = parseInt(t.dataset.remaining || '0', 10);

  const drawClock = () => {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    t.textContent = hh + ':' + mm;
  };

  const drawCountdown = () => {
    const m = String(Math.floor(s / 60)).padStart(2, '0');
    const sec = String(Math.max(0, s % 60)).padStart(2, '0');
    t.textContent = m + ':' + sec;
  };

  if (status === 'live') {
    drawCountdown();
    setInterval(() => {
      if (s > 0) s--;
      drawCountdown();
    }, 1000);
  } else if (status === 'finished') {
    t.textContent = '00:00';
  } else {
    drawClock();
    setInterval(drawClock, 1000);
  }
}

const form = document.getElementById('referee-form');
const saveHint = document.getElementById('savehint');

if (form && saveHint) {
  const homeInput = form.querySelector('input[name="home_score"]');
  const awayInput = form.querySelector('input[name="away_score"]');
  const matchId = form.querySelector('input[name="match_id"]')?.value || '';
  const homeScoreDisplay = document.querySelector('[data-score-home]');
  const awayScoreDisplay = document.querySelector('[data-score-away]');
  let saveTimer = null;
  let lastPayload = null;

  const setHint = (text, cls = '') => {
    saveHint.textContent = text;
    saveHint.className = 'savehint' + (cls ? ' ' + cls : '');
  };

  const syncVisibleScores = () => {
    if (homeScoreDisplay) homeScoreDisplay.textContent = homeInput.value === '' ? '–' : homeInput.value;
    if (awayScoreDisplay) awayScoreDisplay.textContent = awayInput.value === '' ? '–' : awayInput.value;
  };

  const saveScores = async () => {
    const payload = JSON.stringify({
      match_id: matchId,
      home_score: homeInput.value,
      away_score: awayInput.value
    });

    if (payload === lastPayload) return;
    lastPayload = payload;
    syncVisibleScores();
    setHint('Speichert …', 'busy');

    const body = new URLSearchParams();
    body.set('ref_save_score', '1');
    body.set('match_id', matchId);
    body.set('home_score', homeInput.value);
    body.set('away_score', awayInput.value);

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });

      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Speichern fehlgeschlagen.');
      setHint('Ergebnis gespeichert', 'ok');
    } catch (error) {
      lastPayload = null;
      setHint(error.message || 'Speichern fehlgeschlagen.', 'error');
    }
  };

  const queueSave = () => {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveScores, 500);
  };

  [homeInput, awayInput].forEach((input) => {
    if (!input) return;
    input.addEventListener('input', () => {
      syncVisibleScores();
      queueSave();
    });
    input.addEventListener('change', saveScores);
    input.addEventListener('blur', saveScores);
  });
}
</script></body></html>
