<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
date_default_timezone_set(cfg()['timezone']);

$settings = load_settings();
$msg = '';
$notice = '';
$activeTab = $_GET['tab'] ?? 'generator';
$allowedTabs = ['generator', 'scores', 'settings', 'danger'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'generator';
$generatorStep = max(1, min(4, (int)($_GET['step'] ?? 1)));
$backupEntries = list_backup_entries();

function read_post_settings($current) {
  $mode = ($_POST['turniermodus'] ?? 'groups') === 'league' ? 'league' : 'groups';
  $groupCount = (int)($_POST['gruppen_anzahl'] ?? ($mode === 'league' ? 1 : 2));
  if ($mode === 'league') $groupCount = 1;
  if (!in_array($groupCount, [1,2,3,4], true)) $groupCount = 2;
  $date = trim((string)($_POST['spielbeginn_date'] ?? ''));
  $time = trim((string)($_POST['spielbeginn_time'] ?? ''));
  $spielbeginn = ($date && $time) ? ($date . ' ' . $time) : '';
  $teamsByGroup = [];
  $groupKeys = $mode === 'league' ? ['Tabelle'] : array_slice(['A','B','C','D'], 0, $groupCount);
  foreach ($groupKeys as $group) {
    $teamsByGroup[$group] = preg_split('/\r\n|\r|\n/', (string)($_POST['teams_'.$group] ?? ''));
  }
  return array_merge($current, [
    'spielort' => trim((string)($_POST['spielort'] ?? $current['spielort'] ?? '')),
    'spielprinzip' => trim((string)($_POST['spielprinzip'] ?? $current['spielprinzip'] ?? '')),
    'spielbeginn' => $spielbeginn,
    'spieldauer' => (string)max(1, (int)($_POST['spieldauer'] ?? 18)),
    'wechselzeit' => (string)max(0, (int)($_POST['wechselzeit'] ?? 0)),
    'status_upcoming' => (string)max(0, (int)($_POST['status_upcoming'] ?? 10)),
    'spielfelder_anzahl' => max(1, min(4, (int)($_POST['spielfelder_anzahl'] ?? 1))),
    'turniermodus' => $mode,
    'gruppen_anzahl' => $groupCount,
    'ko_phase' => isset($_POST['ko_phase']) ? 1 : 0,
    'platz3spiel' => isset($_POST['platz3spiel']) ? 1 : 0,
    'teams_by_group' => normalize_teams_by_group($teamsByGroup),
    'control_mode' => in_array(($_POST['control_mode'] ?? ($current['control_mode'] ?? 'auto')), ['auto','central','referee'], true) ? ($_POST['control_mode'] ?? ($current['control_mode'] ?? 'auto')) : 'auto',
    'show_table' => isset($_POST['show_table']) ? 1 : 0,
    'show_public_scores' => isset($_POST['show_public_scores']) ? 1 : 0,
    'allow_internal_scores' => (isset($_POST['allow_internal_scores']) || isset($_POST['show_public_scores'])) ? 1 : 0,
  ]);
}

function tournament_has_started_guard(array $matches, array $settings, ?int $now = null): bool {
  $now = $now ?? time();
  $spielbeginnTs = !empty($settings['spielbeginn']) ? strtotime((string)$settings['spielbeginn']) : false;
  if ($spielbeginnTs && $spielbeginnTs <= $now) return true;
  foreach ($matches as $m) {
    $status = (string)($m['status'] ?? '');
    if (in_array($status, ['live', 'finished'], true)) return true;
    if (!empty($m['actual_start']) || !empty($m['actual_end']) || !empty($m['started_at']) || !empty($m['finished_at'])) return true;
    if (($m['home_score'] ?? null) !== null || ($m['away_score'] ?? null) !== null) return true;
  }
  return false;
}

if (isset($_GET['logout'])) {
  logout();
  header('Location: dashboard.php');
  exit;
}


if (is_logged_in() && isset($_GET['download_backup'])) {
  $file = basename((string)($_GET['download_backup'] ?? ''));
  $path = backup_path($file);
  if (!is_file($path)) {
    http_response_code(404);
    exit('Backup nicht gefunden.');
  }
  header('Content-Description: File Transfer');
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . basename($path) . '"');
  header('Content-Length: ' . (string)filesize($path));
  header('Cache-Control: no-store, no-cache, must-revalidate');
  readfile($path);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
  $ok = try_login($_POST['username'] ?? '', $_POST['password'] ?? '');
  if (!$ok) $msg = 'Login fehlgeschlagen.';
  else { header('Location: dashboard.php'); exit; }
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_tournament'])) {
  $activeTab = 'generator';
  $settings = read_post_settings($settings);
  $errors = validate_tournament_form($settings);
  if ($errors) {
    $msg = implode(' ', $errors);
  } else {
    $built = build_tournament($settings);
    if (!empty($built['error'])) {
      $msg = $built['error'];
    } elseif (!save_matches($built['matches']) || !save_settings(array_merge($settings, ['tournament_generated_at' => date('Y-m-d H:i:s'), 'three_group_best_second_override' => '']))) {
      $msg = 'Turnier konnte nicht gespeichert werden. Dateirechte prüfen.';
    } else {
      $notice = 'Turnierplan wurde generiert.';
      $settings = load_settings();
    }
  }
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branding_settings'])) {
  $activeTab = 'settings';
  $clubName = trim((string)($_POST['club_name'] ?? ''));
  $portalTitle = trim((string)($_POST['portal_title'] ?? ''));
  $teamLabel = trim((string)($_POST['team_label'] ?? ''));
  $controlMode = in_array(($_POST['control_mode'] ?? 'auto'), ['auto','central','referee'], true) ? $_POST['control_mode'] : 'auto';
  $showTable = isset($_POST['show_table']) ? 1 : 0;
  $showPublicScores = isset($_POST['show_public_scores']) ? 1 : 0;
  $allowInternalScores = (isset($_POST['allow_internal_scores']) || isset($_POST['show_public_scores'])) ? 1 : 0;
  $newPassword = (string)($_POST['new_password'] ?? '');
  $confirmPassword = (string)($_POST['confirm_password'] ?? '');

  if ($clubName === '') {
    $msg = 'Bitte einen Vereinsnamen eintragen.';
  } elseif ($portalTitle === '') {
    $msg = 'Bitte einen Portalnamen eintragen.';
  } else {
    $merged = array_merge($settings, [
      'club_name' => $clubName,
      'portal_title' => $portalTitle,
      'team_label' => $teamLabel,
      'control_mode' => $controlMode,
      'show_table' => $showTable,
      'show_public_scores' => $showPublicScores,
      'allow_internal_scores' => $allowInternalScores,
    ]);
    $merged = ensure_referee_tokens($merged, load_matches());

    if (!empty($_FILES['logo']['name'] ?? '')) {
      [$okUpload, $logoPath, $uploadError] = save_uploaded_logo($_FILES['logo']);
      if (!$okUpload) {
        $msg = $uploadError;
      } elseif ($logoPath) {
        $merged['logo_path'] = $logoPath;
      }
    }

    if ($msg === '' && ($newPassword !== '' || $confirmPassword !== '')) {
      if (strlen($newPassword) < 8) {
        $msg = 'Das neue Passwort muss mindestens 8 Zeichen haben.';
      } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $msg = 'Die neuen Passwörter stimmen nicht überein.';
      } elseif (!save_admin_password_hash($newPassword)) {
        $msg = 'Das Passwort konnte nicht gespeichert werden.';
      }
    }

    if ($msg === '') {
      if (!save_settings($merged)) {
        $msg = 'Einstellungen konnten nicht gespeichert werden.';
      } else {
        $notice = 'Einstellungen gespeichert.' . (($newPassword !== '' && $confirmPassword !== '') ? ' Passwort aktualisiert.' : '');
        $settings = load_settings();
      }
    }
  }
}


if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_manual_backup'])) {
  $activeTab = 'settings';
  [$okBackup, $backupFile, $backupError] = create_full_backup((string)($_POST['backup_name'] ?? ''));
  if ($okBackup) $notice = 'Backup erstellt: ' . $backupFile;
  else $msg = $backupError ?: 'Backup konnte nicht erstellt werden.';
  $backupEntries = list_backup_entries();
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_uploaded_backup'])) {
  $activeTab = 'settings';
  [$okRestore, $restoreMessage] = restore_backup_from_upload($_FILES['backup_file'] ?? []);
  if ($okRestore) {
    $notice = $restoreMessage;
    $settings = load_settings();
  } else {
    $msg = $restoreMessage ?: 'Backup konnte nicht wiederhergestellt werden.';
  }
  $backupEntries = list_backup_entries();
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_existing_backup'])) {
  $activeTab = 'settings';
  [$okRestore, $restoreMessage] = restore_backup_file((string)($_POST['backup_file_name'] ?? ''));
  if ($okRestore) {
    $notice = $restoreMessage;
    $settings = load_settings();
  } else {
    $msg = $restoreMessage ?: 'Backup konnte nicht wiederhergestellt werden.';
  }
  $backupEntries = list_backup_entries();
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
  $activeTab = 'settings';
  $targetFile = (string)($_POST['backup_file_name'] ?? '');
  if ($targetFile === '') {
    $msg = 'Keine Backup-Datei ausgewählt.';
  } elseif (delete_backup_file($targetFile)) {
    $notice = 'Backup gelöscht.';
  } else {
    $msg = 'Backup konnte nicht gelöscht werden.';
  }
  $backupEntries = list_backup_entries();
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_matches'])) {
  $activeTab = 'danger';
  if (($_POST['confirm'] ?? '') !== 'yes') $msg = 'Löschen abgebrochen. Bestätigung fehlt.';
  else {
    $withBackup = isset($_POST['reset_with_backup']);
    $backupInfo = '';
    if ($withBackup) {
      [$backupOk, $backupFile, $backupError] = create_full_backup((string)($_POST['reset_backup_name'] ?? 'Vor_Reset'));
      $backupInfo = $backupOk ? (' Backup erstellt: ' . $backupFile) : (' Backup fehlgeschlagen: ' . ($backupError ?: 'unbekannter Fehler') . '.');
    }
    if (save_matches([])) $notice = 'Spielplan gelöscht.' . $backupInfo;
    else $msg = 'Spielplan konnte nicht gelöscht werden.';
  }
  $backupEntries = list_backup_entries();
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_score'])) {
  $activeTab = 'scores';
  if (!internal_scores_enabled($settings)) { $msg = 'Ergebnisseingabe ist deaktiviert.'; } else {
  $id = (int)($_POST['id'] ?? 0);
  $hs = $_POST['home_score'] === '' ? null : max(0, (int)$_POST['home_score']);
  $as = $_POST['away_score'] === '' ? null : max(0, (int)$_POST['away_score']);
  $matches = load_matches();
  $found = false;
  foreach ($matches as &$m) {
    if ((int)($m['id'] ?? 0) === $id) {
      if (is_knockout_match($m) && $hs !== null && $as !== null && $hs === $as) {
        $msg = 'K.O.-Spiele brauchen einen Sieger. Unentschieden also bitte nicht ins Finale schleppen.';
        $found = true;
        break;
      }
      $m['home_score'] = $hs;
      $m['away_score'] = $as;
      $found = true;
      break;
    }
  }
  unset($m);
  if ($found && $msg === '') {
    if (save_matches($matches)) $notice = 'Ergebnis gespeichert.';
    else $msg = 'Ergebnis konnte nicht gespeichert werden.';
  } elseif (!$found) {
    $msg = 'Spiel nicht gefunden.';
  }
}
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bulk_scores'])) {
  $activeTab = 'scores';
  if (!internal_scores_enabled($settings)) { $msg = 'Ergebnisseingabe ist deaktiviert.'; } else {
  $matches = load_matches();
  $scoreMap = $_POST['scores'] ?? [];
  $issues = [];
  foreach ($matches as &$m) {
    $id = (int)($m['id'] ?? 0);
    if (!array_key_exists($id, $scoreMap)) continue;
    $row = is_array($scoreMap[$id]) ? $scoreMap[$id] : [];
    $hs = (($row['home'] ?? '') === '') ? null : max(0, (int)$row['home']);
    $as = (($row['away'] ?? '') === '') ? null : max(0, (int)$row['away']);
    if (is_knockout_match($m) && $hs !== null && $as !== null && $hs === $as) {
      $issues[] = 'K.O.-Spiel #' . $id . ' braucht einen Sieger.';
      continue;
    }
    $m['home_score'] = $hs;
    $m['away_score'] = $as;
  }
  unset($m);
  if ($issues) {
    $msg = implode(' ', array_unique($issues));
  } elseif (save_matches($matches)) {
    $notice = 'Alle Ergebnisse gespeichert.';
  } else {
    $msg = 'Ergebnisse konnten nicht gespeichert werden.';
  }
}
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_three_group_best_second'])) {
  $activeTab = 'scores';
  $choice = trim((string)($_POST['three_group_best_second'] ?? ''));
  $currentMatchesForDecision = load_matches();
  $currentTablesForDecision = compute_standings($currentMatchesForDecision);
  $decisionState = best_group_rank_resolution($currentTablesForDecision, $settings, ['A','B','C'], 2);
  $allowedTeams = array_map(fn($row) => (string)($row['team'] ?? ''), $decisionState['tied'] ?? []);
  if ($choice === '') {
    $settings['three_group_best_second_override'] = '';
    if (save_settings($settings)) {
      $notice = 'Manuelle Entscheidung zurückgesetzt.';
      $settings = load_settings();
    } else {
      $msg = 'Entscheidung konnte nicht gespeichert werden.';
    }
  } elseif (!in_array($choice, $allowedTeams, true)) {
    $msg = 'Ausgewähltes Team ist kein gültiger Kandidat für den besten Zweiten.';
  } else {
    $settings['three_group_best_second_override'] = $choice;
    if (save_settings($settings)) {
      $notice = 'Bester Zweiter manuell festgelegt: ' . $choice;
      $settings = load_settings();
    } else {
      $msg = 'Entscheidung konnte nicht gespeichert werden.';
    }
  }
}


if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_match_drag'])) {
  $activeTab = 'scores';
  $dragId = (int)($_POST['drag_match_id'] ?? 0);
  $targetField = (string)($_POST['target_field'] ?? '');
  $targetKickoff = trim((string)($_POST['target_kickoff'] ?? ''));
  $swapId = (int)($_POST['swap_match_id'] ?? 0);
  $matches = load_matches();
  [$fromIdx, $dragMatch] = find_match_by_id($matches, $dragId);
  if (tournament_has_started_guard($matches, $settings)) {
    $msg = 'Nach Turnierbeginn können Spiele nicht mehr verschoben werden.';
  } elseif ($fromIdx === null || !$dragMatch) {
    $msg = 'Das gezogene Spiel wurde nicht gefunden.';
  } elseif ($targetKickoff === '' || $targetField === '') {
    $msg = 'Zielslot fehlt.';
  } else {
    [$swapIdx, $swapMatch] = $swapId > 0 ? find_match_by_id($matches, $swapId) : [null, null];
    $oldKickoff = $dragMatch['kickoff'] ?? '';
    $oldField = (string)($dragMatch['field'] ?? '1');
    $matches[$fromIdx]['kickoff'] = $targetKickoff;
    $matches[$fromIdx]['field'] = $targetField;
    if ($swapIdx !== null && $swapMatch) {
      $matches[$swapIdx]['kickoff'] = $oldKickoff;
      $matches[$swapIdx]['field'] = $oldField;
    }
    $conflicts = validate_schedule_conflicts($matches);
    if ($conflicts) {
      $msg = $conflicts[0];
    } else {
      $matches = rebuild_slot_orders($matches);
      if (save_matches($matches)) {
        $notice = $swapIdx !== null ? 'Spiele wurden getauscht.' : 'Spiel wurde verschoben.';
      } else {
        $msg = 'Spiel konnte nicht verschoben werden.';
      }
    }
  }
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_match'])) {
  $activeTab = 'scores';
  $id = (int)($_POST['delete_match_id'] ?? 0);
  $matches = load_matches();
  $before = count($matches);
  $matches = array_values(array_filter($matches, fn($m) => (int)($m['id'] ?? 0) !== $id));
  if (count($matches) === $before) {
    $msg = 'Spiel zum Löschen nicht gefunden.';
  } else {
    $matches = rebuild_slot_orders($matches);
    if (save_matches($matches)) $notice = 'Spiel wurde gelöscht.';
    else $msg = 'Spiel konnte nicht gelöscht werden.';
  }
}


if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['central_start_round'])) {
  $activeTab = 'scores';
  $matches = load_matches();
  $count = central_start_next_round($matches, $settings);
  if ($count > 0 && save_matches_with_resolved_order($matches)) $notice = 'Zentrale Runde gestartet.';
  elseif ($count === 0) $msg = 'Keine startbereiten Spiele gefunden.';
  else $msg = 'Runde konnte nicht gestartet werden.';
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['central_finish_round'])) {
  $activeTab = 'scores';
  $matches = load_matches();
  $count = central_finish_live_round($matches, $settings);
  if ($count > 0 && save_matches_with_resolved_order($matches)) $notice = 'Live-Runde beendet.';
  elseif ($count === 0) $msg = 'Aktuell läuft keine Runde.';
  else $msg = 'Runde konnte nicht beendet werden.';
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_match_action'])) {
  $activeTab = 'scores';
  $matches = load_matches();
  $id = (int)($_POST['match_id'] ?? 0);
  $action = (string)($_POST['admin_match_action'] ?? '');
  $ok = false;
  if ($action === 'start') $ok = start_match($matches, $id, $settings);
  if ($action === 'finish') $ok = finish_match($matches, $id, $settings);
  if ($ok && save_matches_with_resolved_order($matches)) $notice = $action === 'start' ? 'Spiel wurde manuell gestartet.' : 'Spiel wurde manuell beendet.';
  elseif (!$ok) $msg = 'Spielaktion konnte nicht ausgeführt werden.';
  else $msg = 'Spielstatus konnte nicht gespeichert werden.';
}

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_layout_changes'])) {
  $activeTab = 'scores';
  $payload = json_decode((string)($_POST['layout_payload'] ?? '[]'), true);
  $matches = load_matches();
  if (tournament_has_started_guard($matches, $settings)) {
    $msg = 'Nach Turnierbeginn können Spiele nicht mehr verschoben werden.';
  } else {
    $map = [];
    foreach ($payload as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id > 0) $map[$id] = ['kickoff' => trim((string)($row['kickoff'] ?? '')), 'field' => (string)($row['field'] ?? '')];
    }
    if (!$map) {
      $msg = 'Keine Änderungen zum Speichern vorhanden.';
    } else {
      foreach ($matches as &$m) {
        $id = (int)($m['id'] ?? 0);
        if (!isset($map[$id])) continue;
        $m['kickoff'] = $map[$id]['kickoff'];
        $m['field'] = $map[$id]['field'];
      }
      unset($m);
      $conflicts = validate_schedule_conflicts($matches);
      if ($conflicts) $msg = $conflicts[0];
      else {
        if (save_matches_with_resolved_order($matches)) $notice = 'Spielplanänderungen gespeichert.';
        else $msg = 'Spielplan konnte nicht gespeichert werden.';
      }
    }
  }
}

$matches = load_matches();
sort_matches($matches);
$tables = compute_standings($matches);
$resolvedMatches = resolve_match_labels($matches, $tables);
$resolvedMatches = apply_dynamic_display_times($resolvedMatches, $settings);
sort_matches_for_display($resolvedMatches, $settings);
$displayShowTable = show_table_enabled($settings);
$displayPublicScores = public_scores_enabled($settings);
$displayInternalScores = internal_scores_enabled($settings);
$isThreeGroupKoMode = (($settings['turniermodus'] ?? 'groups') !== 'league') && (int)($settings['gruppen_anzahl'] ?? 0) === 3 && !empty($settings['ko_phase']);
$threeGroupBestSecondState = $isThreeGroupKoMode ? best_group_rank_resolution($tables, $settings, ['A','B','C'], 2) : null;
$runtimeMode = control_mode($settings);
$runtimeNow = time();
$settingsWithTokens = ensure_referee_tokens($settings, $matches);
if (($settings['referee_tokens'] ?? []) !== ($settingsWithTokens['referee_tokens'] ?? [])) save_settings($settingsWithTokens);
$settings = $settingsWithTokens;
$groupKeys = ($settings['turniermodus'] ?? 'groups') === 'league' ? ['Tabelle'] : array_slice(['A','B','C','D'], 0, (int)($settings['gruppen_anzahl'] ?? 2));
$scoredMatches = 0;
foreach ($matches as $m) if (($m['home_score'] ?? null) !== null && ($m['away_score'] ?? null) !== null) $scoredMatches++;
$teamCount = 0;
foreach (($settings['teams_by_group'] ?? []) as $groupTeams) $teamCount += count($groupTeams);
$endTime = tournament_end_time($matches, $settings);

$baseDate = '';
if (!empty($settings['spielbeginn']) && preg_match('~^(\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}~', $settings['spielbeginn'], $m)) $baseDate = $m[1];
$slotTimes = [];
$matchMatrix = [];
foreach ($resolvedMatches as $rm) {
  $timeKey = date('Y-m-d H:i', strtotime((string)($rm['kickoff'] ?? 'now')));
  $slotTimes[$timeKey] = true;
  $matchMatrix[$timeKey][(string)($rm['field'] ?? '1')] = $rm;
}
$slotTimes = array_keys($slotTimes);
sort($slotTimes);
$fieldNumbers = range(1, max(1, (int)($settings['spielfelder_anzahl'] ?? 1)));
$liveMatchesOverview = [];
$upcomingMatchesOverview = [];
foreach ($resolvedMatches as $overviewMatch) {
  $overviewState = match_display_state($overviewMatch, $resolvedMatches, $settings, $runtimeNow);
  if ($overviewState === 'live') $liveMatchesOverview[] = $overviewMatch;
  elseif (in_array($overviewState, ['upcoming', 'bald', 'soon'], true)) $upcomingMatchesOverview[] = $overviewMatch;
}

$centralTimerStartTs = null;
if ($runtimeMode === 'central' && $liveMatchesOverview) {
  foreach ($liveMatchesOverview as $liveTimerMatch) {
    foreach (['actual_start', 'started_at', 'live_started_at', 'runtime_started_at'] as $startKey) {
      if (!empty($liveTimerMatch[$startKey])) {
        $startCandidate = strtotime((string)$liveTimerMatch[$startKey]);
        if ($startCandidate) {
          $centralTimerStartTs = $centralTimerStartTs === null ? $startCandidate : min($centralTimerStartTs, $startCandidate);
          break;
        }
      }
    }
  }
}

if (!function_exists('dashboard_runtime_label')) {
  function dashboard_runtime_label(string $state): string {
    return match ($state) {
      'live' => 'LIVE',
      'upcoming', 'bald', 'soon' => 'BALD',
      'finished' => 'BEENDET',
      default => 'GEPLANT',
    };
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="color-scheme" content="dark">
  <title><?= htmlspecialchars(site_title('Adminbereich'), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <?php if (file_exists(__DIR__ . '/head.inc.html')) include __DIR__ . '/head.inc.html'; ?>
  <?php if ($baseDate): ?><meta name="match-base-date" content="<?= htmlspecialchars($baseDate, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
  <meta name="match-duration" content="<?= (int)$settings['spieldauer'] ?>">
  <meta name="match-upcoming-window" content="<?= (int)$settings['status_upcoming'] ?>">
  <style>
    :root { color-scheme: dark; --bg:#070b13; --surface:rgba(14,19,31,.96); --surface2:rgba(18,25,40,.88); --line:#1d2940; --text:#eef3fb; --muted:#9cadc6; --acc:#3fd2ff; --acc2:#64f0d5; --danger:#ff7b7b; --ok:#3ddc97; --radius:18px; --shadow:0 18px 48px rgba(0,0,0,.38); }
    html,body{min-height:100%;background:radial-gradient(1200px 700px at 0% -10%, rgba(63,210,255,.16), transparent 55%),radial-gradient(900px 500px at 100% 0%, rgba(100,240,213,.10), transparent 48%),linear-gradient(180deg, #060911 0%, #0b1020 62%, #070b13 100%)!important;color:var(--text)!important}
    body *, input, textarea, select, button { color-scheme: dark; }
    main.container{max-width:1380px;padding:20px 18px 40px}
    .shell{display:grid;gap:18px}.topbar,.panel,.flash,.stats,.tabbar{border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
    .topbar{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 18px;background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));backdrop-filter:blur(10px)}
    .topbar h1{margin:0}.topbar p{margin:4px 0 0;color:var(--muted)}
    .links,.tabbar{display:flex;gap:10px;flex-wrap:wrap}.btn,.tabbtn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 16px;border-radius:14px;text-decoration:none;border:1px solid var(--line);font-weight:700;background:rgba(255,255,255,.05);color:var(--text);cursor:pointer}
    .btn.primary,.tabbtn.active{background:linear-gradient(90deg, var(--acc), var(--acc2));color:#06111a;border-color:transparent}.btn.danger{background:rgba(255,107,107,.12);color:#ffd8d8;border-color:rgba(255,107,107,.4)}
    .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;padding:16px;background:transparent}.stat{padding:16px;border-radius:16px;border:1px solid var(--line);background:var(--surface2)}.stat .k{color:var(--muted);font-size:.9rem}.stat .v{font-size:1.45rem;font-weight:800}
    .tabbar{padding:12px;background:rgba(11,17,28,.78)} .panel{display:none;padding:20px;background:var(--surface)} .panel.active{display:block}
    .grid2,.grid3{display:grid;gap:16px}.grid2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid3{grid-template-columns:repeat(3,minmax(0,1fr))}
    .card{padding:16px;border-radius:16px;border:1px solid var(--line);background:var(--surface2)} .card h3,.card h2{margin:0 0 8px}.muted{color:var(--muted)}
    .control{display:grid;gap:8px}.stack{display:grid;gap:12px} label{font-weight:700} small{color:var(--muted)}
    input,select,textarea{width:100%;border-radius:14px!important;border:1px solid var(--line)!important;background:rgba(6,10,18,.96)!important;color:var(--text)!important;box-shadow:none!important}
    textarea{min-height:180px} input:focus,select:focus,textarea:focus{border-color:rgba(63,210,255,.6)!important;box-shadow:0 0 0 3px rgba(63,210,255,.14)!important}
    input[type='checkbox']{width:auto;accent-color:var(--acc)}
	.option-list{display:grid;gap:12px}

.option-row{
  display:grid;
  grid-template-columns:24px 1fr;
  align-items:start;
  gap:14px;
  width:100%;
  padding:16px 18px;
  border:1px solid rgba(63,210,255,.16);
  border-radius:16px;
  background:rgba(255,255,255,.025);
  cursor:pointer;
  transition:border-color .18s ease, background .18s ease, box-shadow .18s ease, transform .18s ease;
}

.option-row:hover{
  border-color:rgba(63,210,255,.28);
  background:rgba(255,255,255,.04);
  box-shadow:0 10px 24px rgba(0,0,0,.14);
}

.option-row:active{transform:scale(.997)}

.option-row input[type='checkbox']{
  appearance:none;
  -webkit-appearance:none;
  width:22px !important;
  height:22px !important;
  min-width:22px;
  min-height:22px;
  margin:2px 0 0 0;
  border:2px solid rgba(63,210,255,.32);
  border-radius:7px;
  background:rgba(6,10,18,.92);
  box-shadow:none;
  cursor:pointer;
  position:relative;
  transition:border-color .18s ease, background .18s ease, box-shadow .18s ease;
}

.option-row input[type='checkbox']:hover{
  border-color:rgba(63,210,255,.55);
}

.option-row input[type='checkbox']:checked{
  background:rgba(63,210,255,.14);
  border-color:rgba(63,210,255,.82);
  box-shadow:0 0 0 3px rgba(63,210,255,.10);
}

.option-row input[type='checkbox']:checked::after{
  content:"";
  position:absolute;
  left:5px;
  top:1px;
  width:6px;
  height:11px;
  border:solid #7fe7ff;
  border-width:0 2px 2px 0;
  transform:rotate(45deg);
}

.option-row:has(input[type='checkbox']:checked){
  background:linear-gradient(180deg, rgba(63,210,255,.06), rgba(63,210,255,.035));
  border-color:rgba(63,210,255,.42);
  box-shadow:inset 0 0 0 1px rgba(63,210,255,.07), 0 10px 24px rgba(0,0,0,.14);
}

.option-row:has(input[type='checkbox']:checked) .option-title{color:var(--text)}
.option-row:has(input[type='checkbox']:checked) .option-subtitle{color:#bfd4e2}

.option-copy{display:grid;gap:4px;min-width:0}

.option-title{
  display:block;
  font-size:1.08rem;
  font-weight:800;
  line-height:1.35;
  color:var(--text);
}

.option-subtitle{
  display:block;
  font-size:.95rem;
  line-height:1.45;
  color:var(--muted);
}

@media (max-width: 640px){
  .option-row{grid-template-columns:22px 1fr;padding:14px 14px;gap:12px}
  .option-row input[type='checkbox']{width:20px !important;height:20px !important;min-width:20px;min-height:20px}
  .option-title{font-size:1rem}
  .option-subtitle{font-size:.92rem}
}

@media (max-width: 900px){
  #panel-scores .table-box{
    overflow:visible;
  }

  #panel-scores table,
  #panel-scores thead,
  #panel-scores tbody,
  #panel-scores th,
  #panel-scores td,
  #panel-scores tr{
    display:block;
    width:100%;
  }

  #panel-scores thead{
    display:none;
  }

  #panel-scores tbody{
    display:grid;
    gap:12px;
  }

  #panel-scores tbody tr{
    display:block;
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(7,12,20,.92);
    box-shadow:0 8px 20px rgba(0,0,0,.18);
  }

  #panel-scores tbody td{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    border:0;
    padding:7px 0;
    text-align:left !important;
  }

  #panel-scores tbody td::before{
    content:attr(data-label);
    min-width:88px;
    font-weight:800;
    color:var(--muted);
    flex-shrink:0;
  }

  #panel-scores .score-form{
    width:100%;
    justify-content:flex-start;
    flex-wrap:wrap;
    gap:8px;
  }

  #panel-scores .score-form input[type='number']{
    max-width:64px;
    min-width:64px;
  }

  #panel-scores .score-form button{
    min-height:42px;
    padding:0 14px;
  }
}

@media (max-width: 900px){
  #panel-scores .card{padding:14px}
  #panel-scores h2{font-size:1.15rem}
  #panel-scores .save-row{
    align-items:stretch;
  }
  #panel-scores .save-row > *{
    width:100%;
  }
  #panel-scores .save-row .btn,
  #panel-scores .save-row button{
    width:100%;
  }

  #panel-scores .bulk-score-table{
    overflow:visible;
    border:0;
    background:transparent;
  }

  #panel-scores .bulk-score-table table,
  #panel-scores .bulk-score-table thead,
  #panel-scores .bulk-score-table tbody,
  #panel-scores .bulk-score-table tr,
  #panel-scores .bulk-score-table th,
  #panel-scores .bulk-score-table td{
    display:block;
    width:100%;
  }

  #panel-scores .bulk-score-table thead{
    display:none;
  }

  #panel-scores .bulk-score-table tbody{
    display:grid;
    gap:14px;
  }

  #panel-scores .bulk-score-table tbody tr{
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(7,12,20,.92);
    box-shadow:0 8px 20px rgba(0,0,0,.18);
  }

  #panel-scores .bulk-score-table tbody td{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:7px 0;
    border:0;
    text-align:left !important;
  }

  #panel-scores .bulk-score-table tbody td::before{
    content:attr(data-label);
    min-width:96px;
    font-weight:800;
    color:var(--muted);
    flex-shrink:0;
  }

  #panel-scores .bulk-score-table tbody td.center{
    display:grid;
    grid-template-columns:96px 1fr;
    align-items:center;
  }

  #panel-scores .bulk-score-table input[type='number']{
    width:100%;
    max-width:none;
    min-height:48px;
    font-size:1rem;
    text-align:center;
  }

  #panel-scores .slot-board-wrap{
    overflow:visible;
    padding-bottom:0;
  }

  #panel-scores .slot-board{
    min-width:0;
    gap:14px;
  }

  #panel-scores .slot-board-header{
    display:none;
  }

  #panel-scores .slot-row{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(7,12,20,.92);
    box-shadow:0 8px 20px rgba(0,0,0,.18);
  }

  #panel-scores .time-label{
    justify-content:flex-start;
    padding:10px 12px;
    font-size:1rem;
  }

  #panel-scores .drop-slot{
    min-height:0;
    padding:10px;
  }

  #panel-scores .drop-slot::before{
    content:'Feld ' attr(data-field);
    display:block;
    width:100%;
    margin-bottom:8px;
    color:var(--muted);
    font-weight:800;
  }

  #panel-scores .drop-slot.empty{
    min-height:64px;
    align-items:flex-start;
  }

  #panel-scores .drop-slot.empty::after{
    margin:0;
  }

  #panel-scores .match-card{
    gap:12px;
    padding:14px;
  }

  #panel-scores .match-card .meta{
    flex-wrap:wrap;
  }

  #panel-scores .card-actions{
    display:grid;
    grid-template-columns:1fr;
    gap:8px;
  }

  #panel-scores .card-actions form,
  #panel-scores .card-actions .btn,
  #panel-scores .card-actions button{
    width:100%;
  }

  #panel-scores .card-actions .btn,
  #panel-scores .card-actions button{
    min-height:44px;
  }
}

@media (max-width: 520px){
  #panel-scores .bulk-score-table tbody td,
  #panel-scores .bulk-score-table tbody td.center{
    grid-template-columns:1fr;
    display:grid;
    gap:6px;
  }

  #panel-scores .bulk-score-table tbody td::before{
    min-width:0;
  }

  #panel-scores .bulk-score-table tbody td[data-label='Heim'],
  #panel-scores .bulk-score-table tbody td[data-label='Gast']{
    gap:4px;
  }

  #panel-scores .time-label{
    font-size:.96rem;
  }
}

@media (max-width: 700px){
  .tabbar{
    display:grid;
    grid-template-columns:1fr;
  }

  .tabbtn{
    width:100%;
  }

  .topbar{
    padding:14px;
  }

  .stats{
    gap:12px;
  }

  .panel{
    padding:14px;
  }
}


    .central-control-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,340px);gap:14px;align-items:stretch;margin-top:12px}
    .central-timer-box{display:grid;gap:8px;padding:14px 16px;border-radius:16px;border:1px solid rgba(63,210,255,.22);background:linear-gradient(180deg, rgba(63,210,255,.08), rgba(255,255,255,.03))}
    .central-timer-label{font-size:.88rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
    .central-timer-value{font-size:2rem;line-height:1;font-weight:900;font-variant-numeric:tabular-nums}
    .central-timer-box.is-warning{border-color:rgba(255,123,123,.4);background:linear-gradient(180deg, rgba(255,123,123,.14), rgba(255,255,255,.03))}
    .central-timer-box.is-live{border-color:rgba(61,220,151,.35);background:linear-gradient(180deg, rgba(61,220,151,.12), rgba(255,255,255,.03))}
    .central-timer-note{color:var(--muted);font-size:.92rem}
    .central-countdown-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(4,8,15,.78);backdrop-filter:blur(6px);z-index:9999}
    .central-countdown-overlay.active{display:flex}
    .central-countdown-pill{min-width:180px;padding:28px 34px;border-radius:28px;border:1px solid rgba(63,210,255,.28);background:linear-gradient(180deg, rgba(10,18,31,.96), rgba(10,18,31,.88));box-shadow:0 20px 60px rgba(0,0,0,.35);text-align:center}
    .central-countdown-title{font-size:.95rem;color:var(--muted);font-weight:800;letter-spacing:.05em;text-transform:uppercase}
    .central-countdown-number{font-size:4.25rem;line-height:1;font-weight:900;margin-top:8px}
    @media (max-width: 980px){.central-control-row{grid-template-columns:1fr}.central-timer-value{font-size:1.75rem}}


    .runtime-strip{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .runtime-box{padding:16px;border-radius:16px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
    .runtime-box.live-box{border-color:rgba(61,220,151,.28);background:rgba(61,220,151,.07)}
    .runtime-box.upcoming-box{border-color:rgba(63,210,255,.24);background:rgba(63,210,255,.06)}
    .runtime-box h3{margin:0 0 10px}
    .runtime-list{display:grid;gap:10px}
    .runtime-item{display:grid;gap:4px;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.07);background:rgba(7,12,20,.82)}
    .runtime-item strong{line-height:1.35}
    .runtime-meta{display:flex;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:.92rem}
    .status-badge{display:inline-flex;align-items:center;justify-content:center;min-width:82px;padding:7px 12px;border-radius:999px;font-weight:800;font-size:.84rem;letter-spacing:.02em;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.05)}
    .status-badge.live{background:rgba(61,220,151,.16);border-color:rgba(61,220,151,.35);color:#bdf4d7}
    .status-badge.upcoming,.status-badge.bald,.status-badge.soon{background:rgba(63,210,255,.14);border-color:rgba(63,210,255,.35);color:#bcecff}
    .status-badge.finished{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.14);color:#d9e2f2}
    .status-badge.planned{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.1);color:#b8c7da}
    tr.runtime-live{background:rgba(61,220,151,.08)!important}
    tr.runtime-upcoming, tr.runtime-bald, tr.runtime-soon{background:rgba(63,210,255,.05)!important}
    @media (max-width: 980px){.runtime-strip{grid-template-columns:1fr}}
    .flash{padding:14px 16px;background:rgba(63,210,255,.09)}.flash.error{background:rgba(255,107,107,.11);border-color:rgba(255,107,107,.35)}

    .save-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:18px}.inline-note{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:rgba(100,240,213,.08);border:1px solid rgba(100,240,213,.18)}
    table{width:100%;border-collapse:collapse}.table-box{overflow:auto;border:1px solid var(--line);border-radius:16px;background:rgba(7,12,20,.88)} th,td{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:middle} thead th{position:sticky;top:0;background:#0d1626;color:#fff!important;font-weight:800} tbody tr:nth-child(odd){background:rgba(255,255,255,.018)} tbody tr:hover{background:rgba(63,210,255,.06)}
    .score-form{display:flex;gap:8px;align-items:center}.score-form input[type='number']{max-width:76px;margin:0}.danger-box{border:1px solid rgba(255,107,107,.28);background:rgba(255,107,107,.08);border-radius:16px;padding:16px}
    @media (max-width: 980px){.grid2,.grid3,.stats{grid-template-columns:1fr}.score-form{flex-wrap:wrap}}

.bulk-score-form{display:grid;gap:14px}
.bulk-score-table input[type="number"]{max-width:72px;margin:0 auto;text-align:center}
.slot-board-wrap{overflow:auto;padding-bottom:6px}
.slot-board{display:grid;gap:12px;min-width:860px}
.slot-board-header,.slot-row{display:grid;grid-template-columns:120px repeat(var(--field-count), minmax(220px, 1fr));gap:12px;align-items:stretch}
.slot-head,.time-label{display:flex;align-items:center;justify-content:center;padding:12px 10px;border-radius:14px;border:1px solid var(--line);background:#0d1626;font-weight:800}
.time-label{background:rgba(255,255,255,.04);color:var(--muted)}
.drop-slot{min-height:132px;border:1px dashed rgba(63,210,255,.24);border-radius:18px;background:rgba(255,255,255,.025);padding:8px;display:flex;align-items:stretch;justify-content:stretch;transition:border-color .18s ease, background .18s ease, transform .18s ease}
.drop-slot.empty::after{content:'Hierher ziehen';margin:auto;color:var(--muted);font-size:.92rem}
.drop-slot.drag-over{border-color:rgba(100,240,213,.75);background:rgba(63,210,255,.08);transform:translateY(-1px)}
.match-card{width:100%;display:grid;gap:10px;border-radius:16px;border:1px solid rgba(63,210,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));padding:12px;box-shadow:0 10px 24px rgba(0,0,0,.18);cursor:grab}
.match-card:active{cursor:grabbing}
.match-card .meta{display:flex;justify-content:space-between;gap:8px;align-items:center;color:var(--muted);font-size:.82rem}
.match-card .phase-pill{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:rgba(63,210,255,.12);color:#bcecff;font-weight:800}
.match-card .teams{display:grid;gap:4px;font-weight:800;line-height:1.35}
.match-card .scoreline{font-size:.95rem;color:var(--muted)}
.match-time-edit{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border-radius:12px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);color:var(--muted);font-size:.88rem;font-weight:800}
.match-time-edit input[type="time"]{max-width:118px;margin:0;padding:8px 10px;border-radius:10px;font-weight:800;text-align:center}
.match-time-edit input[type="time"]:disabled{opacity:.65;cursor:not-allowed}
.card-actions{display:flex;gap:8px;flex-wrap:wrap}
.card-actions .btn{min-height:38px;padding:0 12px;border-radius:12px;font-size:.92rem}
.section-stack{display:grid;gap:18px}
.hint{color:var(--muted);font-size:.94rem}
    .wizard-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
    .wizard-step{display:flex;gap:10px;align-items:center;padding:14px 16px;border-radius:16px;border:1px solid var(--line);background:rgba(255,255,255,.03);text-decoration:none;color:var(--text);font-weight:800}
    .wizard-step small{display:block;color:var(--muted);font-weight:600}
    .wizard-step .nr{display:grid;place-items:center;width:34px;height:34px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);flex-shrink:0}
    .wizard-step.active{background:linear-gradient(180deg, rgba(63,210,255,.14), rgba(63,210,255,.06));border-color:rgba(63,210,255,.38)}
    .wizard-step.done{border-color:rgba(100,240,213,.28)}
    .wizard-pane{display:none}
    .wizard-pane.active{display:block}
    .wizard-nav{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:18px}
    .wizard-nav .right{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}
    .summary-list{display:grid;gap:12px}
    .summary-item{padding:14px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
    .link-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    @media (max-width: 980px){.wizard-steps,.link-grid{grid-template-columns:1fr}}
  
.backup-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.backup-actions form{margin:0}
.notice-box{padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.035);color:var(--muted)}
.backup-table td,.backup-table th{vertical-align:middle}


@media (max-width: 980px){
  #panel-settings .grid2{
    grid-template-columns:1fr;
  }

  #panel-settings .card{
    padding:14px;
  }

  #panel-settings .save-row{
    align-items:stretch;
  }

  #panel-settings .save-row > *{
    width:100%;
  }

  #panel-settings .save-row .btn,
  #panel-settings .save-row button{
    width:100%;
  }

  #panel-settings .backup-actions{
    display:grid;
    grid-template-columns:1fr;
    width:100%;
  }

  #panel-settings .backup-actions .btn,
  #panel-settings .backup-actions button,
  #panel-settings .backup-actions form{
    width:100%;
  }

  #panel-settings .backup-actions .btn,
  #panel-settings .backup-actions button{
    min-height:44px;
  }

  #panel-settings .table-scroll{
    overflow:visible;
  }

  #panel-settings .backup-table,
  #panel-settings .backup-table thead,
  #panel-settings .backup-table tbody,
  #panel-settings .backup-table tr,
  #panel-settings .backup-table th,
  #panel-settings .backup-table td{
    display:block;
    width:100%;
  }

  #panel-settings .backup-table thead{
    display:none;
  }

  #panel-settings .backup-table tbody{
    display:grid;
    gap:14px;
  }

  #panel-settings .backup-table tbody tr{
    padding:14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:rgba(7,12,20,.92);
    box-shadow:0 8px 20px rgba(0,0,0,.18);
  }

  #panel-settings .backup-table tbody td{
    display:grid;
    grid-template-columns:minmax(92px, 120px) 1fr;
    gap:10px;
    align-items:start;
    padding:7px 0;
    border:0;
    text-align:left !important;
    word-break:break-word;
  }

  #panel-settings .backup-table tbody td::before{
    content:attr(data-label);
    font-weight:800;
    color:var(--muted);
  }

  #panel-settings .backup-table tbody td:last-child{
    padding-bottom:0;
  }
}

@media (max-width: 700px){
  #panel-settings{
    padding:14px;
  }

  #panel-settings .card h2,
  #panel-settings .card h3{
    line-height:1.3;
  }

  #panel-settings [style*="display:flex;align-items:center;gap:16px;flex-wrap:wrap;"]{
    display:grid !important;
    grid-template-columns:1fr;
    justify-items:start;
  }

  #panel-settings img[alt="Aktuelles Logo"]{
    max-width:96px !important;
    max-height:96px !important;
  }

  #panel-settings input[type='file']{
    padding:10px 12px;
  }
}

@media (max-width: 520px){
  #panel-settings .backup-table tbody td{
    grid-template-columns:1fr;
    gap:6px;
  }
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
<?php if (!is_logged_in()): ?>
  <section class="panel active" style="max-width:460px;margin:4vh auto 0;display:block;">
    <h2>Admin Login</h2>
    <?php if ($msg): ?><div class="flash error"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="login" value="1">
      <div class="control"><label>Benutzername</label><input name="username" value="admin" required></div>
      <div class="control"><label>Passwort</label><input name="password" type="password" required></div>
      <button type="submit" class="btn primary">Login</button>
    </form>
  </section>
<?php else: ?>
  <div class="shell">
    <section class="topbar">
      <div>
        <h1>Adminbereich</h1>
        <p><?= htmlspecialchars(site_title(), ENT_QUOTES, 'UTF-8') ?> verwalten.</p>
      </div>
      <nav class="links">
        <a class="btn" href="index.php">Turnierplan</a>
        <a class="btn" href="screen.php" target="_blank" rel="noopener">Screen</a>
        <a class="btn" href="export_pdf.php" target="_blank" rel="noopener">PDF</a>
        <a class="btn" href="dashboard.php?logout=1">Logout</a>
      </nav>
    </section>
    <?php if ($notice): ?><div class="flash"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="flash error"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <section class="stats">
      <article class="stat"><div class="k">Spiele gesamt</div><div class="v"><?= count($matches) ?></div></article>
      <article class="stat"><div class="k">Teams gesamt</div><div class="v"><?= (int)$teamCount ?></div></article>
      <article class="stat"><div class="k">Mit Ergebnis</div><div class="v"><?= (int)$scoredMatches ?></div></article>
      <article class="stat"><div class="k">Voraussichtliches Ende</div><div class="v" style="font-size:1.1rem;"><?= $endTime ? htmlspecialchars(date('d.m.Y H:i', strtotime($endTime))) : '–' ?></div></article>
    </section>

    <nav class="tabbar">
      <?php foreach (['generator'=>'Turnier-Einstellungen','scores'=>'Ergebnisse / Steuerung','settings'=>'Einstellungen','danger'=>'Zurücksetzen'] as $tabKey => $tabLabel): ?>
        <button type="button" class="tabbtn<?= $activeTab === $tabKey ? ' active' : '' ?>" data-tab="<?= $tabKey ?>"><?= $tabLabel ?></button>
      <?php endforeach; ?>
    </nav>

    <section id="panel-generator" class="panel<?= $activeTab === 'generator' ? ' active' : '' ?>">
      <div class="card" style="margin-bottom:16px;">
        <h2>Turnier-Einstellungen</h2>
      </div>

      <form method="post" class="stack" id="generatorWizardForm">
        <nav class="wizard-steps" aria-label="Turnier-Einstellungen Schritte">
          <?php
            $stepLabels = [
              1 => ['Grunddaten'],
              2 => ['Mannschaften'],
              3 => ['Steuerung & Anzeige'],
              4 => ['Übersicht & Generieren'],
            ];
            foreach ($stepLabels as $stepNo => $stepData):
              $stepClass = $generatorStep === $stepNo ? ' active' : ($generatorStep > $stepNo ? ' done' : '');
          ?>
            <button type="button" class="wizard-step<?= $stepClass ?>" data-goto-step="<?= $stepNo ?>">
              <span class="nr"><?= $stepNo ?></span>
              <span><span><?= htmlspecialchars($stepData[0], ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($stepData[1], ENT_QUOTES, 'UTF-8') ?></small></span>
            </button>
          <?php endforeach; ?>
        </nav>
        <input type="hidden" name="wizard_current_step" id="wizard_current_step" value="<?= (int)$generatorStep ?>">
        <div class="wizard-pane<?= $generatorStep === 1 ? ' active' : '' ?>" data-step="1">
          <div class="grid2">
            <div class="card stack">
              <h3>1. Grunddaten</h3>
              <div class="control"><label>Spieldatum</label><input type="date" name="spielbeginn_date" value="<?php if (!empty($settings['spielbeginn']) && preg_match('~^(\d{4}-\d{2}-\d{2})~', $settings['spielbeginn'], $m)) echo htmlspecialchars($m[1]); ?>" required></div>
              <div class="control"><label>Spielbeginn</label><input type="time" name="spielbeginn_time" value="<?php if (!empty($settings['spielbeginn']) && preg_match('~^\d{4}-\d{2}-\d{2}\s+(\d{2}:\d{2})~', $settings['spielbeginn'], $m)) echo htmlspecialchars($m[1]); ?>" required></div>
              <div class="control"><label>Spieldauer pro Spiel (Minuten)</label><input type="number" min="1" max="60" name="spieldauer" value="<?= htmlspecialchars((string)$settings['spieldauer']) ?>" required></div>
              <div class="control"><label>Pause zwischen Spielen (Minuten)</label><input type="number" min="0" max="30" name="wechselzeit" value="<?= htmlspecialchars((string)$settings['wechselzeit']) ?>"></div>
              <div class="control"><label>Spielfelder (max. 4)</label><input type="number" min="1" max="4" name="spielfelder_anzahl" value="<?= htmlspecialchars((string)$settings['spielfelder_anzahl']) ?>" required></div>
              <div class="control"><label>Spielort</label><input name="spielort" value="<?= htmlspecialchars((string)$settings['spielort']) ?>" placeholder="z. B. Sportplatz Haßmersheim"></div>
              <div class="control"><label>Spielprinzip</label><input name="spielprinzip" value="<?= htmlspecialchars((string)$settings['spielprinzip']) ?>" placeholder="z. B. 5+1"></div>
              <div class="control"><label>Status &quot;BALD&quot; (Minuten)</label><input type="number" min="0" max="60" name="status_upcoming" value="<?= htmlspecialchars((string)$settings['status_upcoming']) ?>"></div>
            </div>
            <div class="card stack">
              <h3>Turnierrahmen</h3>
              <div class="control">
                <label>Turniermodus</label>
                <select name="turniermodus" id="turniermodusSelect">
                  <option value="groups" <?= ($settings['turniermodus'] ?? 'groups') === 'groups' ? 'selected' : '' ?>>Gruppenphase</option>
                  <option value="league" <?= ($settings['turniermodus'] ?? '') === 'league' ? 'selected' : '' ?>>Eine Gruppe ( max.8 Teams )</option>
                </select>
              </div>
              <div class="control" id="gruppenCountWrap">
                <label>Gruppenanzahl</label>
                <select name="gruppen_anzahl" id="gruppenSelect">
                  <?php foreach ([2,3,4] as $gc): ?><option value="<?= $gc ?>" <?= (int)$settings['gruppen_anzahl'] === $gc ? 'selected' : '' ?>><?= $gc ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="option-list">
                <label class="option-row">
                  <input type="checkbox" name="ko_phase" value="1" <?= !empty($settings['ko_phase']) ? 'checked' : '' ?>>
                  <span class="option-copy">
                    <span class="option-title">Finalrunden erzeugen</span>
                    <span class="option-subtitle">Bei 3 Gruppen: 3 Gruppensieger + bester Zweiter</span>
                    <span class="option-subtitle"></span>
                    <span class="option-subtitle">Viertelfinale automatisch bei 4 Gruppen und mind. 4 Teams á Gruppe.</span>
                  </span>
                </label>
                <label class="option-row">
                  <input type="checkbox" name="platz3spiel" value="1" <?= !empty($settings['platz3spiel']) ? 'checked' : '' ?>>
                  <span class="option-copy">
                    <span class="option-title">Spiel um Platz 3</span>
                    <span class="option-subtitle">Nur in mit Finalrunden</span>
                  </span>
                </label>
              </div>
            </div>
          </div>
          <div class="wizard-nav">
            <div class="right">
              <button type="button" class="btn primary" data-next-step="2">Weiter zu Schritt 2</button>
            </div>
          </div>
        </div>

        <div class="wizard-pane<?= $generatorStep === 2 ? ' active' : '' ?>" data-step="2">
  <div class="card stack">
    <h3>2. Mannschaften eintragen</h3>
    <div class="hint">Jede Mannschaft in eine neue Zeile.</div>

    <div class="grid2" id="teamsGrid">
      <?php foreach (['Tabelle','A','B','C','D'] as $group): $teamsText = implode("\n", $settings['teams_by_group'][$group] ?? []); ?>
        <div class="card team-group" data-group="<?= $group ?>" style="padding:14px;">
          <h3 style="margin-bottom:10px;"><?= $group === 'Tabelle' ? 'Teams Gesamttabelle' : ('Gruppe ' . $group) ?></h3>
          <div class="control">
            <textarea name="teams_<?= $group ?>" placeholder="Jede Mannschaft in eine neue Zeile"><?= htmlspecialchars($teamsText) ?></textarea>
            <small>Mindestens 2 Teams, maximal 8.</small>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="wizard-nav">
    <button type="button" class="btn" data-prev-step="1">← Zurück</button>
    <div class="right">
      <button type="button" class="btn primary" data-next-step="3">Weiter zu Schritt 3</button>
    </div>
  </div>
</div>

        <div class="wizard-pane<?= $generatorStep === 3 ? ' active' : '' ?>" data-step="3">
          <div class="grid2">
            <div class="card stack">
              <h3>3. Steuerung</h3>
              <div class="control">
                <label>Modus für den Spielbetrieb</label>
                <select name="control_mode">
                  <option value="auto" <?= ($runtimeMode==='auto'?'selected':'') ?>>Automatisch</option>
                  <option value="central" <?= ($runtimeMode==='central'?'selected':'') ?>>Zentraler Anpfiff</option>
                  <option value="referee" <?= ($runtimeMode==='referee'?'selected':'') ?>>Schiedsrichter je Feld</option>
                </select>
              </div>
              <div class="hint">Automatisch = fester Zeitplan / Zentraler Anpfiff = Turnierleitung macht Start/Stop / Schiedsrichter = je Feld Modus</div>
              <div class="hint">Zentraler Anpfiff = Turnierleitung Start/Stop alle aktuellen Felder gleichzeit</div>
              <div class="hint">Schiedsrichter je Feld = Steuerung des Verantwortliche auf dem Feld von Start/Stop</div>
            </div>
            <div class="card stack">
              <h3>Anzeigeformen</h3>
              <div class="option-list">
                <label class="option-row"><input type="checkbox" name="show_table" value="1" <?= $displayShowTable ? 'checked' : '' ?>><span class="option-copy"><span class="option-title">Tabelle anzeigen</span><span class="option-subtitle">Öffentliche Tabellenblöcke und Screen-Tabelle sichtbar.</span></span></label>
                <label class="option-row"><input type="checkbox" id="show_public_scores" name="show_public_scores" value="1" <?= $displayPublicScores ? 'checked' : '' ?>><span class="option-copy"><span class="option-title">Ergebnisse öffentlich anzeigen</span><span class="option-subtitle">Scores auf Spielplan und Screen sichtbar.</span></span></label>
                <label class="option-row"><input type="checkbox" id="allow_internal_scores" name="allow_internal_scores" value="1" <?= $displayInternalScores ? 'checked' : '' ?>><span class="option-copy"><span class="option-title">Ergebnisse intern erfassen</span><span class="option-subtitle">Ergebnisse werden nicht öffentlich dargestellt.</span></span></label>
              </div>
            </div>
          </div>
          <div class="wizard-nav">
            <button type="button" class="btn" data-prev-step="2">← Zurück</button>
            <div class="right">
              <button type="button" class="btn primary" data-next-step="4">Weiter zu Schritt 4</button>
            </div>
          </div>
        </div>

        <div class="wizard-pane<?= $generatorStep === 4 ? ' active' : '' ?>" data-step="4">
          <div class="grid2">
            <div class="card stack">
              <h3>4. Übersicht</h3>
              <div class="summary-list">
                <div class="summary-item"><strong>Turnierdatum:</strong> <span id="summary_spielbeginn"><?= !empty($settings['spielbeginn']) ? htmlspecialchars(date('d.m.Y H:i', strtotime($settings['spielbeginn'])), ENT_QUOTES, 'UTF-8') : 'Bitte Datum und Uhrzeit wählen' ?></span></div>
                <div class="summary-item"><strong>Spielfelder:</strong> <span id="summary_spielfelder"><?= (int)($settings['spielfelder_anzahl'] ?? 1) ?></span></div>
                <div class="summary-item"><strong>Turniermodus:</strong> <span id="summary_turniermodus"><?= ($settings['turniermodus'] ?? 'groups') === 'league' ? 'Alle gegen alle' : 'Gruppenphase' ?><?= ($settings['turniermodus'] ?? 'groups') !== 'league' ? ' mit ' . (int)($settings['gruppen_anzahl'] ?? 2) . ' Gruppen' : '' ?></span></div>
                <div class="summary-item"><strong>Teams aktuell eingetragen:</strong> <span id="summary_teamcount"><?= (int)$teamCount ?></span></div>
                <div class="summary-item"><strong>Live-Steuerung:</strong> <span id="summary_control_mode"><?= htmlspecialchars((string)($runtimeMode), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div class="summary-item"><strong>Anzeigen:</strong> <span id="summary_display_flags">Tabelle <?= $displayShowTable ? 'an' : 'aus' ?>, öffentliche Ergebnisse <?= $displayPublicScores ? 'an' : 'aus' ?>, interne Ergebnisse <?= $displayInternalScores ? 'an' : 'aus' ?></span></div>
              </div>
              <div class="inline-note">Nach dem Generieren stehen im Bereich Ergebnisse auch direkt die Schiri-Links zum Öffnen, Kopieren und Teilen bereit.</div>
            </div>
            <div class="card stack">
              <h3>Speichern & Generieren</h3>
              <div class="hint">Daten prüfen, wenn i.O. generieren!</div>
              <button type="submit" name="generate_tournament" class="btn primary">Turnierplan generieren</button>
            </div>
          </div>
          <div class="wizard-nav">
            <button type="button" class="btn" data-prev-step="3">← Zurück</button>
          </div>
        </div>
      </form>
    </section>

    <section id="panel-scores" class="panel<?= $activeTab === 'scores' ? ' active' : '' ?>">
      <div class="section-stack">
        <div class="card" style="margin-bottom:16px;">
          <h2>Live-Steuerung</h2>
          <div class="hint">Modus aktuell: <strong><?= htmlspecialchars($runtimeMode) ?></strong></div>
          <?php if ($runtimeMode === 'central'): ?>
            <div class="central-control-row">
              <div class="card-actions">
                <form method="post" id="centralStartForm"><button type="submit" name="central_start_round" value="1" class="btn primary" id="centralStartBtn">Zentrale Runde starten</button></form>
                <form method="post" id="centralFinishForm"><button type="submit" name="central_finish_round" value="1" class="btn">Live-Runde beenden</button></form>
              </div>
              <div class="central-timer-box<?= $centralTimerStartTs ? ' is-live' : '' ?>" id="centralTimerBox" data-duration="<?= (int)($settings['spieldauer'] ?? 0) ?>" data-live-start="<?= $centralTimerStartTs ? (int)$centralTimerStartTs : '' ?>">
                <div class="central-timer-label">Spieltimer</div>
                <div class="central-timer-value" id="centralTimerValue"><?= $centralTimerStartTs ? '00:00' : sprintf('%02d:00', max(0, (int)($settings['spieldauer'] ?? 0))) ?></div>
                <div class="central-timer-note" id="centralTimerNote"><?= $centralTimerStartTs ? 'Runde läuft gerade.' : 'Nach dem 3-Sekunden-Countdown startet die Runde.' ?></div>
              </div>
            </div>
          <?php else: ?>
            <div class="card-actions" style="margin-top:12px;">
              <div class="hint">Zentrale Start/Stop-Steuerung ist nur im Modus <strong>Zentraler Anpfiff</strong> aktiv.</div>
            </div>
          <?php endif; ?>
          <?php if ($runtimeMode === 'referee'): ?>
            <div class="grid2" style="margin-top:14px;">
              <?php for ($rf=1; $rf<=max(1,(int)($settings['spielfelder_anzahl'] ?? 1)); $rf++): ?>
                <div class="card" style="padding:12px;">
                  <strong>Schiri Feld <?= $rf ?></strong><br>
                  <?php $refUrl = 'referee.php?field=' . $rf . '&token=' . urlencode((string)($settings['referee_tokens'][$rf]['token'] ?? '')); ?>
                  <div class="card-actions" style="margin-top:10px;">
                    <a class="btn" target="_blank" rel="noopener" href="<?= htmlspecialchars($refUrl, ENT_QUOTES, 'UTF-8') ?>">Öffnen</a>
                    <button type="button" class="btn copy-link-btn" data-link="<?= htmlspecialchars($refUrl, ENT_QUOTES, 'UTF-8') ?>">Link kopieren</button>
                    <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . ltrim($refUrl, '/')) ?>">WhatsApp</a>
                  </div>
                </div>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="runtime-strip">
          <div class="runtime-box live-box">
            <h3>Aktuell live</h3>
            <?php if ($liveMatchesOverview): ?>
              <div class="runtime-list">
                <?php foreach ($liveMatchesOverview as $liveMatch): ?>
                  <div class="runtime-item">
                    <strong><?= htmlspecialchars((string)($liveMatch['resolved_home'] ?? $liveMatch['home'])) ?> vs. <?= htmlspecialchars((string)($liveMatch['resolved_away'] ?? $liveMatch['away'])) ?></strong>
                    <div class="runtime-meta">
                      <span><?= htmlspecialchars(match_display_kickoff($liveMatch, 'd.m. H:i')) ?></span>
                      <span>Feld <?= htmlspecialchars((string)($liveMatch['field'] ?? '1')) ?></span>
                      <span><?= htmlspecialchars(!empty($liveMatch['label']) ? $liveMatch['label'] : match_group_label($liveMatch)) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="hint">Gerade läuft kein Spiel.</div>
            <?php endif; ?>
          </div>
          <div class="runtime-box upcoming-box">
            <h3>Als Nächstes dran</h3>
            <?php if ($upcomingMatchesOverview): ?>
              <div class="runtime-list">
                <?php foreach (array_slice($upcomingMatchesOverview, 0, max(2, (int)($settings['spielfelder_anzahl'] ?? 1))) as $upcomingMatch): ?>
                  <div class="runtime-item">
                    <strong><?= htmlspecialchars((string)($upcomingMatch['resolved_home'] ?? $upcomingMatch['home'])) ?> vs. <?= htmlspecialchars((string)($upcomingMatch['resolved_away'] ?? $upcomingMatch['away'])) ?></strong>
                    <div class="runtime-meta">
                      <span><?= htmlspecialchars(match_display_kickoff($upcomingMatch, 'd.m. H:i')) ?></span>
                      <span>Feld <?= htmlspecialchars((string)($upcomingMatch['field'] ?? '1')) ?></span>
                      <span><?= htmlspecialchars(!empty($upcomingMatch['label']) ? $upcomingMatch['label'] : match_group_label($upcomingMatch)) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="hint">Kein Spiel steht als nächstes an.</div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($isThreeGroupKoMode): ?>
          <div class="card stack">
            <h2>3-Gruppen K.O.-Qualifikation</h2>
            <div class="inline-note">Modus: Die 3 Gruppensieger plus der beste Zweitplatzierte erreichen die K.O.-Runde. Vergleich: Punkte, Tordifferenz, erzielte Tore, weniger Gegentore.</div>
            <?php if (empty($threeGroupBestSecondState['candidates']) || count($threeGroupBestSecondState['candidates']) < 3): ?>
              <div class="hint">Der beste Zweite wird angezeigt, sobald in A, B und C jeweils ein zweiter Platz berechnet werden kann.</div>
            <?php elseif (!empty($threeGroupBestSecondState['tie']) && empty($threeGroupBestSecondState['team'])): ?>
              <div class="flash error">Gleichstand beim besten Zweitplatzierten. Bitte manuell entscheiden, wer in die K.O.-Runde kommt.</div>
              <form method="post" class="row">
                <input type="hidden" name="save_three_group_best_second" value="1">
                <div class="control">
                  <label>Bester Zweiter</label>
                  <select name="three_group_best_second" required>
                    <option value="">Bitte auswählen</option>
                    <?php foreach (($threeGroupBestSecondState['tied'] ?? []) as $candidate): ?>
                      <option value="<?= htmlspecialchars((string)$candidate['team'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$candidate['team'], ENT_QUOTES, 'UTF-8') ?> (Gruppe <?= htmlspecialchars((string)$candidate['group'], ENT_QUOTES, 'UTF-8') ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn primary">Für K.O.-Runde übernehmen</button>
              </form>
            <?php elseif (!empty($threeGroupBestSecondState['team'])): ?>
              <div class="notice-box">
                <strong>Bester Zweiter:</strong>
                <?= htmlspecialchars((string)$threeGroupBestSecondState['team'], ENT_QUOTES, 'UTF-8') ?>
                <span class="muted">(Gruppe <?= htmlspecialchars((string)$threeGroupBestSecondState['group'], ENT_QUOTES, 'UTF-8') ?><?= !empty($threeGroupBestSecondState['manual']) ? ', manuell festgelegt' : '' ?>)</span>
              </div>
              <?php if (!empty($threeGroupBestSecondState['manual'])): ?>
                <form method="post" class="card-actions">
                  <input type="hidden" name="save_three_group_best_second" value="1">
                  <input type="hidden" name="three_group_best_second" value="">
                  <button type="submit" class="btn">Manuelle Entscheidung zurücksetzen</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <h2>Ergebnisse eintragen</h2>
          <form method="post" class="bulk-score-form">
            <div class="table-box bulk-score-table">
              <table>
                <thead><tr><th>Status</th><th>Spiel</th><th>Uhrzeit</th><th>Feld</th><th>Gruppe</th><th>Heim</th><th style="text-align:center">Tore</th><th style="text-align:center">Tore</th><th>Gast</th></tr></thead>
                <tbody>
                  <?php foreach ($resolvedMatches as $m): $rowRuntimeState = match_display_state($m, $resolvedMatches, $settings, $runtimeNow); ?>
                    <tr class="runtime-<?= htmlspecialchars($rowRuntimeState, ENT_QUOTES, 'UTF-8') ?>" data-runtime-state="<?= htmlspecialchars($rowRuntimeState, ENT_QUOTES, 'UTF-8') ?>">
                      <td data-label="Status"><span class="status-badge <?= htmlspecialchars($rowRuntimeState, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(dashboard_runtime_label((string)$rowRuntimeState), ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td data-label="Spiel">#<?= (int)$m['id'] ?></td>
                      <td data-label="Uhrzeit"><?= htmlspecialchars(match_display_kickoff($m, 'd.m. H:i')) ?></td>
                      <td data-label="Feld"><?= htmlspecialchars((string)$m['field']) ?></td>
                      <td data-label="Gruppe"><?= htmlspecialchars(!empty($m['label']) ? $m['label'] : match_group_label($m)) ?></td>
                      <td data-label="Heim"><?= htmlspecialchars((string)($m['resolved_home'] ?? $m['home'])) ?></td>
                      <td data-label="Tore Heim" class="center"><input type="number" min="0" <?= !$displayInternalScores ? "disabled" : "" ?> name="scores[<?= (int)$m['id'] ?>][home]" value="<?= $m['home_score'] !== null ? (int)$m['home_score'] : '' ?>"></td>
                      <td data-label="Tore Gast" class="center"><input type="number" min="0" <?= !$displayInternalScores ? "disabled" : "" ?> name="scores[<?= (int)$m['id'] ?>][away]" value="<?= $m['away_score'] !== null ? (int)$m['away_score'] : '' ?>"></td>
                      <td data-label="Gast"><?= htmlspecialchars((string)($m['resolved_away'] ?? $m['away'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="save-row">
              <button class="btn primary" type="submit" name="save_bulk_scores">Alle Ergebnisse speichern</button>
            </div>
          </form>
        </div>

        <div class="card">
          <h2>Spiele verschieben</h2>
          <form method="post" id="layoutSaveForm" style="display:none;">
            <input type="hidden" name="save_layout_changes" value="1">
            <input type="hidden" name="layout_payload" id="layout_payload" value="">
          </form>
          <div class="save-row" style="margin:0 0 14px;">
            <div class="inline-note" id="layoutLockNote"><?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? 'Turnier hat bereits begonnen. Verschieben ist jetzt gesperrt.' : 'Spiele erst verschieben, dann gesammelt speichern. Die Prüfung läuft beim Speichern.' ?></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="btn primary" id="saveLayoutBtn"<?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? ' disabled' : '' ?>>Änderungen speichern</button>
              <button type="button" class="btn" id="resetLayoutBtn"<?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? ' disabled' : '' ?>>Änderungen verwerfen</button>
            </div>
          </div>
          <div class="slot-board-wrap">
            <div class="slot-board" style="--field-count:<?= count($fieldNumbers) ?>;">
              <div class="slot-board-header">
                <div class="slot-head">Zeit</div>
                <?php foreach ($fieldNumbers as $fieldNo): ?>
                  <div class="slot-head">Feld <?= (int)$fieldNo ?></div>
                <?php endforeach; ?>
              </div>
              <?php foreach ($slotTimes as $slotTime): ?>
                <div class="slot-row">
                  <div class="time-label"><?= htmlspecialchars(date('H:i', strtotime($slotTime))) ?></div>
                  <?php foreach ($fieldNumbers as $fieldNo): $slotMatch = $matchMatrix[$slotTime][(string)$fieldNo] ?? null; $slotRuntimeState = $slotMatch ? match_runtime_state($slotMatch, $settings, $runtimeNow) : 'planned'; ?>
                    <div class="drop-slot<?= $slotMatch ? '' : ' empty' ?>" data-kickoff="<?= htmlspecialchars($slotTime, ENT_QUOTES, 'UTF-8') ?>" data-field="<?= (int)$fieldNo ?>" data-match-id="<?= $slotMatch ? (int)$slotMatch['id'] : 0 ?>">
                      <?php if ($slotMatch): ?>
                        <article class="match-card<?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? ' drag-locked' : '' ?>" draggable="<?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? 'false' : 'true' ?>" data-match-id="<?= (int)$slotMatch['id'] ?>">
                          <div class="meta">
                            <span>#<?= (int)$slotMatch['id'] ?></span>
                            <span class="phase-pill"><?= htmlspecialchars(!empty($slotMatch['label']) ? $slotMatch['label'] : match_group_label($slotMatch)) ?></span>
                          </div>
                          <div class="teams">
                            <span><?= htmlspecialchars((string)($slotMatch['resolved_home'] ?? $slotMatch['home'])) ?></span>
                            <span><?= htmlspecialchars((string)($slotMatch['resolved_away'] ?? $slotMatch['away'])) ?></span>
                          </div>
                          <div class="scoreline"><?= $slotMatch['home_score'] !== null && $slotMatch['away_score'] !== null ? ((int)$slotMatch['home_score'] . ' : ' . (int)$slotMatch['away_score']) : 'Noch ohne Ergebnis' ?></div>
                          <label class="match-time-edit">
                            <span>Uhrzeit</span>
                            <input type="time" class="match-time-input" value="<?= htmlspecialchars(date('H:i', strtotime((string)($slotMatch['kickoff'] ?? $slotTime))), ENT_QUOTES, 'UTF-8') ?>"<?= tournament_has_started_guard($matches, $settings, $runtimeNow) ? ' disabled' : '' ?>>
                          </label>
                          <div class="card-actions">
                            <form method="post">
                              <input type="hidden" name="match_id" value="<?= (int)$slotMatch['id'] ?>">
                              <?php if ($slotRuntimeState !== 'live' && $slotRuntimeState !== 'finished'): ?>
                                <button type="submit" name="admin_match_action" value="start" class="btn">Start</button>
                              <?php elseif ($slotRuntimeState === 'live'): ?>
                                <button type="submit" name="admin_match_action" value="finish" class="btn">Beenden</button>
                              <?php endif; ?>
                            </form>
                            <form method="post" onsubmit="return confirm('Spiel wirklich löschen?');">
                              <input type="hidden" name="delete_match" value="1">
                              <input type="hidden" name="delete_match_id" value="<?= (int)$slotMatch['id'] ?>">
                              <button type="submit" class="btn danger">Löschen</button>
                            </form>
                          </div>
                        </article>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="panel-settings" class="panel<?= $activeTab === 'settings' ? ' active' : '' ?>">
      <div class="card" style="margin-bottom:16px;"><h2>Einstellungen</h2></div>
      <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="save_branding_settings" value="1">
        <div class="grid2">
          <div class="card stack">
            <h3>Branding</h3>
            <div class="control"><label>Vereinsname</label><input name="club_name" value="<?= htmlspecialchars((string)($settings['club_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="control"><label>Portalname</label><input name="portal_title" value="<?= htmlspecialchars((string)($settings['portal_title'] ?? 'Turnierportal'), ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="control"><label>Zusatzzeile</label><input name="team_label" value="<?= htmlspecialchars((string)($settings['team_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="z. B. E-Jugend"></div>
            <div class="control">
              <label>Neues Logo</label>
              <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
              <small>PNG, JPG oder WEBP.</small>
            </div>
          </div>
          <div class="card stack">
            <h3>Admin-Passwort ändern</h3>
            <div class="control"><label>Neues Passwort</label><input type="password" name="new_password" autocomplete="new-password" placeholder="Mindestens 8 Zeichen"></div>
            <div class="control"><label>Neues Passwort wiederholen</label><input type="password" name="confirm_password" autocomplete="new-password"></div>
          </div>
        </div>

        <div class="save-row">
          <button type="submit" class="btn primary">Einstellungen speichern</button>
        </div>
      </form>

      <div class="grid2" style="margin-top:16px;">
        <div class="card stack">
          <h3>Backup erstellen</h3>
          <form method="post" class="stack">
            <input type="hidden" name="create_manual_backup" value="1">
            <div class="control">
              <label>Dateiname</label>
              <input name="backup_name" placeholder="Optionaler Name, ansonsten Automatisch">
            </div>
            <button type="submit" class="btn primary">Backup erstellen</button>
          </form>
        </div>
        <div class="card stack">
          <h3>Backup wiederherstellen</h3>
          <form method="post" enctype="multipart/form-data" class="stack">
            <input type="hidden" name="restore_uploaded_backup" value="1">
            <div class="control">
              <label>Backup-Datei hochladen</label>
              <input type="file" name="backup_file" accept=".zip,.json,application/zip,application/json" required>
              <small>Unterstützt ZIP-Backups und JSON-Backups.</small>
            </div>
            <button type="submit" class="btn">Backup hochladen &amp; wiederherstellen</button>
          </form>
          <div class="notice-box" style="margin-top:8px;">
            <small>Vor jeder Wiederherstellung wird automatisch ein Sicherheitsbackup angelegt.</small>
          </div>
        </div>
      </div>

      <div class="card stack" style="margin-top:16px;">
        <h3>Vorhandene Backups</h3>
        <div class="table-scroll">
          <table class="matches-table backup-table">
            <thead>
              <tr>
                <th>Datei</th>
                <th>Typ</th>
                <th>Erstellt</th>
                <th>Größe</th>
                <th>Aktionen</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$backupEntries): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);">Noch keine Backups vorhanden.</td></tr>
              <?php else: ?>
                <?php foreach ($backupEntries as $entry): ?>
                  <tr>
                    <td data-label="Datei"><?= htmlspecialchars((string)$entry['file'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Typ"><?= $entry['type'] === 'zip' ? 'ZIP' : 'Legacy JSON' ?></td>
                    <td data-label="Erstellt"><?= !empty($entry['mtime']) ? date('d.m.Y H:i:s', (int)$entry['mtime']) : '-' ?></td>
                    <td data-label="Größe"><?= htmlspecialchars(human_filesize((int)($entry['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Aktionen">
                      <div class="backup-actions">
                        <a class="btn" href="dashboard.php?tab=settings&amp;download_backup=<?= rawurlencode((string)$entry['file']) ?>">Download</a>
                        <form method="post" onsubmit="return confirm('Backup wirklich wiederherstellen? Der aktuelle Stand wird vorher gesichert.');">
                          <input type="hidden" name="restore_existing_backup" value="1">
                          <input type="hidden" name="backup_file_name" value="<?= htmlspecialchars((string)$entry['file'], ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" class="btn">Wiederherstellen</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Backup wirklich löschen?');">
                          <input type="hidden" name="delete_backup" value="1">
                          <input type="hidden" name="backup_file_name" value="<?= htmlspecialchars((string)$entry['file'], ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" class="btn danger">Löschen</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="panel-danger" class="panel<?= $activeTab === 'danger' ? ' active' : '' ?>">
      <div class="card" style="margin-bottom:16px;"><h2>Spielplan zurücksetzen</h2></div>
      <div class="danger-box">
        <form method="post" class="stack">
          <input type="hidden" name="reset_matches" value="1">
          <label class="option-row">
            <input type="checkbox" name="confirm" value="yes" required>
            <span class="option-copy">
              <span class="option-title">Ja, ich möchte alle Spiele löschen</span>
            </span>
          </label>
          <label class="option-row">
            <input type="checkbox" name="reset_with_backup" value="1" checked>
            <span class="option-copy">
              <span class="option-title">Vorher Backup erstellen (Empfohlen)</span>
            </span>
          </label>
          <div class="control">
            <label>Backup-Name</label>
            <input name="reset_backup_name" placeholder="Optionaler Name, ansonsten Automatisch">
          </div>
          <button type="submit" class="btn danger">Alle Spiele löschen</button>
        </form>
      </div>
    </section>
  </div>

<div class="central-countdown-overlay" id="centralCountdownOverlay" aria-hidden="true">
  <div class="central-countdown-pill">
    <div class="central-countdown-title">Anpfiff in</div>
    <div class="central-countdown-number" id="centralCountdownNumber">3</div>
  </div>
</div>
  
<script>
    (function(){
      const tabs=[...document.querySelectorAll('.tabbtn')]; const panels=[...document.querySelectorAll('.panel')];
      function activate(name){ tabs.forEach(b=>b.classList.toggle('active', b.dataset.tab===name)); panels.forEach(p=>p.classList.toggle('active', p.id==='panel-'+name)); const url=new URL(location.href); url.searchParams.set('tab', name); history.replaceState({},'',url); }
      tabs.forEach(b=>b.addEventListener('click', ()=>activate(b.dataset.tab)));

      const modeSelect=document.getElementById('turniermodusSelect'); const groupsWrap=document.getElementById('gruppenCountWrap'); const groupsSelect=document.getElementById('gruppenSelect');
      function renderTeamAreas(){
        if(!modeSelect||!groupsSelect) return;
        const mode=modeSelect.value; const count=mode==='league'?1:parseInt(groupsSelect.value||'1',10);
        groupsWrap.style.display=mode==='league'?'none':'grid';
        const visible = mode==='league' ? ['Tabelle'] : ['A','B','C','D'].slice(0,count);
        document.querySelectorAll('.team-group').forEach(el=>{ el.style.display = visible.includes(el.dataset.group) ? 'block' : 'none'; });
      }
      if(modeSelect){ modeSelect.addEventListener('change', renderTeamAreas); groupsSelect && groupsSelect.addEventListener('change', renderTeamAreas); renderTeamAreas(); }
      const publicScoresCheckbox = document.getElementById('show_public_scores');
      const internalScoresCheckbox = document.getElementById('allow_internal_scores');

      if (publicScoresCheckbox && internalScoresCheckbox) {
        publicScoresCheckbox.addEventListener('change', () => {
          if (publicScoresCheckbox.checked) {
            internalScoresCheckbox.checked = true;
            internalScoresCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      }

      function linesCount(value){
        return String(value || '').split(/\r\n|\r|\n/).map(v => v.trim()).filter(Boolean).length;
      }
      function updateGeneratorSummary(){
        const dateVal = document.querySelector('[name="spielbeginn_date"]')?.value || '';
        const timeVal = document.querySelector('[name="spielbeginn_time"]')?.value || '';
        const fieldsVal = document.querySelector('[name="spielfelder_anzahl"]')?.value || '1';
        const modeVal = modeSelect?.value || 'groups';
        const groupVal = groupsSelect?.value || '2';
        const controlVal = document.querySelector('[name="control_mode"]')?.value || 'auto';
        const showTableVal = document.querySelector('[name="show_table"]')?.checked ? 'an' : 'aus';
        const showScoresVal = document.querySelector('[name="show_public_scores"]')?.checked ? 'an' : 'aus';
        const internalScoresVal = document.querySelector('[name="allow_internal_scores"]')?.checked ? 'an' : 'aus';
        let teamCountVal = 0;
        document.querySelectorAll('.team-group').forEach(group => {
          if (group.style.display === 'none') return;
          const ta = group.querySelector('textarea');
          teamCountVal += linesCount(ta ? ta.value : '');
        });
        const dateTarget = document.getElementById('summary_spielbeginn');
        const fieldsTarget = document.getElementById('summary_spielfelder');
        const modeTarget = document.getElementById('summary_turniermodus');
        const teamTarget = document.getElementById('summary_teamcount');
        const controlTarget = document.getElementById('summary_control_mode');
        const displayTarget = document.getElementById('summary_display_flags');
        if (dateTarget) {
          if (dateVal && timeVal) {
            const [year, month, day] = dateVal.split('-');
            dateTarget.textContent = `${day}.${month}.${year} ${timeVal}`;
          } else {
            dateTarget.textContent = 'Bitte Datum und Uhrzeit wählen';
          }
        }
        if (fieldsTarget) fieldsTarget.textContent = fieldsVal;
        if (modeTarget) modeTarget.textContent = modeVal === 'league' ? 'Alle gegen alle' : `Gruppenphase mit ${groupVal} Gruppen`;
        if (teamTarget) teamTarget.textContent = String(teamCountVal);
        if (controlTarget) controlTarget.textContent = controlVal;
        if (displayTarget) displayTarget.textContent = `Tabelle ${showTableVal}, öffentliche Ergebnisse ${showScoresVal}, interne Ergebnisse ${internalScoresVal}`;
      }
      document.querySelectorAll('#generatorWizardForm input, #generatorWizardForm select, #generatorWizardForm textarea').forEach(el => {
        el.addEventListener('input', updateGeneratorSummary);
        el.addEventListener('change', updateGeneratorSummary);
      });
      updateGeneratorSummary();

      const wizardSteps=[...document.querySelectorAll('[data-goto-step]')];
      const wizardPanes=[...document.querySelectorAll('.wizard-pane')];
      const wizardField=document.getElementById('wizard_current_step');
      function setWizardStep(step){
        const n=Math.max(1,Math.min(4,parseInt(step||'1',10)||1));
        if(wizardField) wizardField.value=String(n);
        wizardPanes.forEach(p=>p.classList.toggle('active', p.dataset.step===String(n)));
        wizardSteps.forEach(btn=>{
          const s=parseInt(btn.dataset.gotoStep||'0',10);
          btn.classList.toggle('active', s===n);
          btn.classList.toggle('done', s<n);
        });
        const url=new URL(location.href);
        url.searchParams.set('tab','generator');
        url.searchParams.set('step', String(n));
        history.replaceState({},'',url);
      }
      wizardSteps.forEach(btn=>btn.addEventListener('click',()=>setWizardStep(btn.dataset.gotoStep)));
      document.querySelectorAll('[data-next-step]').forEach(btn=>btn.addEventListener('click',()=>setWizardStep(btn.dataset.nextStep)));
      document.querySelectorAll('[data-prev-step]').forEach(btn=>btn.addEventListener('click',()=>setWizardStep(btn.dataset.prevStep)));
      setWizardStep(<?= (int)$generatorStep ?>);

      const saveLayoutForm = document.getElementById('layoutSaveForm');
      const payloadInput = document.getElementById('layout_payload');
      const saveLayoutBtn = document.getElementById('saveLayoutBtn');
      const resetLayoutBtn = document.getElementById('resetLayoutBtn');
      const layoutLocked = !!document.querySelector('.match-card.drag-locked');
      let dragId = null;
      let pending = false;
      function markPending(state){ pending = state; if(saveLayoutBtn) saveLayoutBtn.disabled = !state; }
      markPending(false);
      document.querySelectorAll('.match-time-input').forEach(input=>{
        input.addEventListener('change', ()=>markPending(true));
        input.addEventListener('input', ()=>markPending(true));
      });
      function buildPayload(){
        const payload = [];
        document.querySelectorAll('.drop-slot').forEach(slot=>{
          const card = slot.querySelector('.match-card');
          if(!card) return;
          let kickoff = slot.dataset.kickoff || '';
          const timeInput = card.querySelector('.match-time-input');
          if(timeInput && timeInput.value && kickoff){
            kickoff = kickoff.substring(0, 10) + ' ' + timeInput.value;
          }
          payload.push({id:parseInt(card.dataset.matchId||'0',10), kickoff:kickoff, field:slot.dataset.field||''});
        });
        return payload;
      }
      document.querySelectorAll('.match-card').forEach(card => {
        card.addEventListener('dragstart', (ev) => { if(layoutLocked || card.classList.contains('drag-locked')) { ev.preventDefault(); return; } dragId = card.dataset.matchId; ev.dataTransfer.effectAllowed='move'; ev.dataTransfer.setData('text/plain', dragId||''); card.classList.add('dragging'); });
        card.addEventListener('dragend', () => { dragId=null; card.classList.remove('dragging'); document.querySelectorAll('.drop-slot.drag-over').forEach(el=>el.classList.remove('drag-over')); });
      });
      document.querySelectorAll('.drop-slot').forEach(slot => {
        slot.addEventListener('dragover', (ev) => { if(layoutLocked || !dragId) return; ev.preventDefault(); slot.classList.add('drag-over'); });
        slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
        slot.addEventListener('drop', (ev) => {
          if(layoutLocked || !dragId) return; ev.preventDefault(); slot.classList.remove('drag-over');
          const sourceCard = document.querySelector('.match-card[data-match-id="'+dragId+'"]');
          if(!sourceCard) return; const sourceSlot = sourceCard.closest('.drop-slot'); if(!sourceSlot || sourceSlot===slot) return;
          const targetCard = slot.querySelector('.match-card');
          if(targetCard) sourceSlot.appendChild(targetCard);
          slot.appendChild(sourceCard);
          sourceSlot.classList.toggle('empty', !sourceSlot.querySelector('.match-card'));
          slot.classList.remove('empty');
          markPending(true);
        });
      });
      if(saveLayoutBtn){ saveLayoutBtn.addEventListener('click', ()=>{ if(!saveLayoutForm || !payloadInput) return; payloadInput.value = JSON.stringify(buildPayload()); saveLayoutForm.submit(); }); }
      if(resetLayoutBtn){ resetLayoutBtn.addEventListener('click', ()=>window.location.reload()); }

      const centralStartForm = document.getElementById('centralStartForm');
      const centralStartBtn = document.getElementById('centralStartBtn');
      const centralTimerBox = document.getElementById('centralTimerBox');
      const centralTimerValue = document.getElementById('centralTimerValue');
      const centralTimerNote = document.getElementById('centralTimerNote');
      const centralCountdownOverlay = document.getElementById('centralCountdownOverlay');
      const centralCountdownNumber = document.getElementById('centralCountdownNumber');
      let centralTimerInterval = null;
      let centralCountdownActive = false;

      function formatCentralTimer(seconds){
        const safe = Math.max(0, Math.floor(seconds));
        const mins = Math.floor(safe / 60);
        const secs = safe % 60;
        return String(mins).padStart(2,'0') + ':' + String(secs).padStart(2,'0');
      }

      function runCentralTimer(startTs, durationMinutes){
        if(!centralTimerBox || !centralTimerValue) return;
        const durationSeconds = Math.max(0, parseInt(durationMinutes || '0', 10) || 0) * 60;
        if(centralTimerInterval) window.clearInterval(centralTimerInterval);
        const tick = () => {
          const nowTs = Math.floor(Date.now() / 1000);
          const elapsed = Math.max(0, nowTs - startTs);
          const remaining = Math.max(0, durationSeconds - elapsed);
          centralTimerBox.classList.add('is-live');
          centralTimerBox.classList.toggle('is-warning', remaining <= 60);
          centralTimerValue.textContent = formatCentralTimer(remaining);
          if (centralTimerNote) {
            centralTimerNote.textContent = remaining > 0 ? 'Runde läuft gerade.' : 'Spielzeit abgelaufen.';
          }
        };
        tick();
        centralTimerInterval = window.setInterval(tick, 1000);
      }

      if (centralTimerBox) {
        const liveStart = parseInt(centralTimerBox.dataset.liveStart || '', 10);
        const duration = parseInt(centralTimerBox.dataset.duration || '0', 10) || 0;
        if (liveStart > 0 && duration > 0) {
          runCentralTimer(liveStart, duration);
        }
      }

      if (centralStartForm && centralStartBtn && centralTimerBox && centralCountdownOverlay && centralCountdownNumber) {
        centralStartForm.addEventListener('submit', (event) => {
          if (centralCountdownActive) return;
          event.preventDefault();
          centralCountdownActive = true;
          centralStartBtn.disabled = true;
          let count = 3;
          centralCountdownNumber.textContent = String(count);
          centralCountdownOverlay.classList.add('active');
          const countdownTick = () => {
            count -= 1;
            if (count > 0) {
              centralCountdownNumber.textContent = String(count);
              window.setTimeout(countdownTick, 1000);
              return;
            }
            centralCountdownOverlay.classList.remove('active');
            const duration = parseInt(centralTimerBox.dataset.duration || '0', 10) || 0;
            const nowTs = Math.floor(Date.now() / 1000);
            centralTimerBox.dataset.liveStart = String(nowTs);
            runCentralTimer(nowTs, duration);
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'central_start_round';
            hidden.value = '1';
            centralStartForm.appendChild(hidden);
            centralStartForm.submit();
          };
          window.setTimeout(countdownTick, 1000);
        });
      }
      document.querySelectorAll('.copy-link-btn').forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const link = btn.dataset.link || '';
          if(!link) return;
          const abs = new URL(link, location.href).toString();
          try {
            await navigator.clipboard.writeText(abs);
            const old = btn.textContent;
            btn.textContent = 'Kopiert';
            setTimeout(()=>btn.textContent = old, 1400);
          } catch (e) {
            window.prompt('Link kopieren:', abs);
          }
        });
      });
    })();
  </script>
<?php endif; ?>
</main>
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
