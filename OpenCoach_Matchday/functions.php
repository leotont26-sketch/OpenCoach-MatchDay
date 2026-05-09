<?php

if (file_exists(__DIR__ . '/demo_config.php')) {
    require_once __DIR__ . '/demo_config.php';

    if (defined('DEMO_EXPIRES_AT') && time() > DEMO_EXPIRES_AT) {
        exit('Diese Demo ist abgelaufen. Bitte starte eine neue Demo.');
    }
}
function cfg() { static $c=null; if($c===null){ $c = include __DIR__.'/config.php'; } return $c; }
function data_file(){ return __DIR__.'/data/matches.json'; }
function settings_file(){ return __DIR__.'/data/settings.php'; }
function ensure_datafile(){
  $f = data_file(); if(!file_exists(dirname($f))) mkdir(dirname($f),0775,true);
  if(!file_exists($f)) file_put_contents($f, json_encode([]));
}
function ensure_settings_file(){
  $f = settings_file(); if(!file_exists(dirname($f))) mkdir(dirname($f),0775,true);
  if(!file_exists($f)) save_settings(load_settings());
}
function load_matches(){ ensure_datafile(); $j=@file_get_contents(data_file()); $a=json_decode($j, true); return is_array($a)?$a:[]; }
function save_matches($m){ $f=data_file(); if(!is_dir(dirname($f))) @mkdir(dirname($f),0775,true); $fp=fopen($f,'c+'); if(!$fp) return false; flock($fp,LOCK_EX); ftruncate($fp,0); fwrite($fp, json_encode(array_values($m), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); fflush($fp); flock($fp,LOCK_UN); fclose($fp); return true; }
function default_settings(){
  return [
    'spielort' => '',
    'spielbeginn' => '',
    'spielprinzip' => '',
    'spieldauer' => '18',
    'wechselzeit' => '3',
    'status_upcoming' => '10',
    'status_live' => '18',
    'turniermodus' => 'groups',
    'gruppen_anzahl' => 2,
    'spielfelder_anzahl' => 2,
    'ko_phase' => 1,
    'platz3spiel' => 1,
    'teams_by_group' => [],
    'tournament_generated_at' => '',
    'club_name' => 'JSG Haßmersheim / Hüffenhardt',
    'portal_title' => 'Turnierportal',
    'team_label' => 'E-Jugend',
    'logo_path' => 'logo.png',
    'control_mode' => 'auto',
    'show_table' => 1,
    'show_public_scores' => 1,
    'allow_internal_scores' => 1,
    'referee_tokens' => [],
    'three_group_best_second_override' => '',
  ];
}
function load_settings(){
  ensure_settings_file();
  $arr = include settings_file();
  if (!is_array($arr)) $arr = [];
  $arr = array_merge(default_settings(), $arr);
  if (trim((string)($arr['status_live'] ?? '')) === '') $arr['status_live'] = (string)$arr['spieldauer'];
  if (!is_array($arr['teams_by_group'] ?? null)) $arr['teams_by_group'] = [];
  $arr['control_mode'] = in_array(($arr['control_mode'] ?? 'auto'), ['auto','central','referee'], true) ? $arr['control_mode'] : 'auto';
  $arr['show_table'] = !empty($arr['show_table']) ? 1 : 0;
  $arr['show_public_scores'] = !empty($arr['show_public_scores']) ? 1 : 0;
  $arr['allow_internal_scores'] = !empty($arr['allow_internal_scores']) ? 1 : 0;
  if (!is_array($arr['referee_tokens'] ?? null)) $arr['referee_tokens'] = [];
  $arr['three_group_best_second_override'] = trim((string)($arr['three_group_best_second_override'] ?? ''));
  return $arr;
}
function save_settings($arr){
  if (!is_dir(dirname(settings_file()))) @mkdir(dirname(settings_file()), 0775, true);
  $arr = array_merge(default_settings(), $arr);
  $arr['status_live'] = (string)$arr['spieldauer'];
  $arr['club_name'] = trim((string)($arr['club_name'] ?? '')) ?: 'Turnierportal';
  $arr['portal_title'] = trim((string)($arr['portal_title'] ?? '')) ?: 'Turnierportal';
  $arr['team_label'] = trim((string)($arr['team_label'] ?? ''));
  $arr['logo_path'] = trim((string)($arr['logo_path'] ?? '')) ?: 'logo.png';
  $arr['control_mode'] = in_array(($arr['control_mode'] ?? 'auto'), ['auto','central','referee'], true) ? $arr['control_mode'] : 'auto';
  $arr['show_table'] = !empty($arr['show_table']) ? 1 : 0;
  $arr['show_public_scores'] = !empty($arr['show_public_scores']) ? 1 : 0;
  $arr['allow_internal_scores'] = !empty($arr['allow_internal_scores']) ? 1 : 0;
  if (!is_array($arr['referee_tokens'] ?? null)) $arr['referee_tokens'] = [];
  $arr['three_group_best_second_override'] = trim((string)($arr['three_group_best_second_override'] ?? ''));
  $php = "<?php\nreturn " . var_export($arr, true) . ";\n";
  $ok = @file_put_contents(settings_file(), $php) !== false;
  if ($ok) sync_site_manifest($arr);
  return $ok;
}
function branding_settings(){
  $s = load_settings();
  return [
    'club_name' => trim((string)($s['club_name'] ?? '')) ?: 'Turnierportal',
    'portal_title' => trim((string)($s['portal_title'] ?? '')) ?: 'Turnierportal',
    'team_label' => trim((string)($s['team_label'] ?? '')),
    'logo_path' => trim((string)($s['logo_path'] ?? '')) ?: 'logo.png',
  ];
}
function site_title($suffix = ''){
  $b = branding_settings();
  $base = trim($b['portal_title'] . ' - ' . $b['club_name'], ' -');
  return $suffix !== '' ? ($base . ' - ' . $suffix) : $base;
}
function brand_logo_url(){
  $b = branding_settings();
  $path = trim((string)($b['logo_path'] ?? 'logo.png'));
  if ($path === '') $path = 'logo.png';
  $absolute = __DIR__ . '/' . ltrim($path, '/');
  $version = is_file($absolute) ? @filemtime($absolute) : time();
  return htmlspecialchars($path . '?v=' . $version, ENT_QUOTES, 'UTF-8');
}
function sync_site_manifest($settings = null){
  if ($settings === null) $settings = load_settings();
  $logo = trim((string)($settings['logo_path'] ?? '')) ?: 'logo.png';
  $ext = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
  $type = 'image/png';
  if ($ext === 'jpg' || $ext === 'jpeg') $type = 'image/jpeg';
  elseif ($ext === 'webp') $type = 'image/webp';
  elseif ($ext === 'ico') $type = 'image/x-icon';
  $name = trim((string)($settings['club_name'] ?? '')) ?: 'Turnierportal';
  $short = trim((string)($settings['portal_title'] ?? '')) ?: 'Turnierportal';
  $manifest = [
    'name' => $name . ' – ' . $short,
    'short_name' => function_exists('mb_substr') ? mb_substr($short, 0, 30) : substr($short, 0, 30),
    'icons' => [
      ['src' => $logo, 'sizes' => '192x192', 'type' => $type],
      ['src' => $logo, 'sizes' => '512x512', 'type' => $type],
    ],
    'theme_color' => '#0b0c10',
    'background_color' => '#0b0c10',
    'display' => 'standalone',
  ];
  @file_put_contents(__DIR__ . '/site.webmanifest', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function save_uploaded_logo($file){
  if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [true, null, ''];
  if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false, null, 'Logo-Upload fehlgeschlagen.'];
  if (!is_uploaded_file($file['tmp_name'] ?? '')) return [false, null, 'Upload konnte nicht geprüft werden.'];
  $info = @getimagesize($file['tmp_name']);
  if (!$info || empty($info['mime'])) return [false, null, 'Bitte nur gültige Bilddateien hochladen.'];
  $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
  $mime = strtolower((string)$info['mime']);
  if (!isset($map[$mime])) return [false, null, 'Erlaubt sind PNG, JPG oder WEBP.'];
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return [false, null, 'Upload-Ordner konnte nicht erstellt werden.'];
  foreach (glob($dir . '/logo.*') ?: [] as $old) { @unlink($old); }
  $relative = 'uploads/logo.' . $map[$mime];
  $target = __DIR__ . '/' . $relative;
  if (!@move_uploaded_file($file['tmp_name'], $target)) return [false, null, 'Logo konnte nicht gespeichert werden.'];
  return [true, $relative, ''];
}
function save_admin_password_hash($plainPassword){
  $plainPassword = trim((string)$plainPassword);
  if ($plainPassword === '') return false;
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
  if ($hash === false) return false;
  $cfg = cfg();
  $cfg['admin_pass_hash'] = $hash;
  unset($cfg['admin_pass']);
  $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
  return @file_put_contents(__DIR__ . '/config.php', $php) !== false;
}

function backup_dir(){
  $dir = __DIR__ . '/data/backups';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function backup_slug($name){
  $name = trim((string)$name);
  if ($name === '') return 'OpenCoach_MatchDay';
  $map = ['Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'];
  $name = strtr($name, $map);
  if (function_exists('iconv')) {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($tmp !== false && $tmp !== '') $name = $tmp;
  }
  $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
  $name = preg_replace('/_+/', '_', $name);
  $name = trim($name, '._- ');
  return $name !== '' ? $name : 'OpenCoach_MatchDay';
}
function backup_default_filename($customName = ''){
  $ts = date('Y-m-d_H-i-s');
  $label = backup_slug($customName !== '' ? $customName : 'OpenCoach_MatchDay');
  return $ts . '_' . $label . '.zip';
}
function backup_path($filename){
  return backup_dir() . '/' . basename((string)$filename);
}
function create_full_backup($customName = ''){
  if (!class_exists('ZipArchive')) return [false, null, 'ZipArchive ist auf dem Server nicht verfügbar.'];
  $filename = backup_default_filename($customName);
  $path = backup_path($filename);
  $zip = new ZipArchive();
  if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return [false, null, 'Backup-Datei konnte nicht erstellt werden.'];

  $meta = [
    'tool' => 'OpenCoach MatchDay',
    'format' => 'matchday-backup-v1',
    'created_at' => date('c'),
    'file' => $filename,
  ];
  $zip->addFromString('backup_meta.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

  $items = [
    'data/matches.json' => __DIR__ . '/data/matches.json',
    'data/settings.php' => __DIR__ . '/data/settings.php',
    'config.php' => __DIR__ . '/config.php',
    'logo.png' => __DIR__ . '/logo.png',
    'site.webmanifest' => __DIR__ . '/site.webmanifest',
  ];
  foreach ($items as $local => $source) {
    if (is_file($source)) $zip->addFile($source, $local);
  }
  foreach (glob(__DIR__ . '/uploads/logo.*') ?: [] as $uploadLogo) {
    if (is_file($uploadLogo)) $zip->addFile($uploadLogo, 'uploads/' . basename($uploadLogo));
  }
  $zip->close();
  return [true, $filename, ''];
}
function list_backup_entries(){
  $dir = backup_dir();
  $items = [];
  foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $file) {
    if ($file === '.' || $file === '..') continue;
    $path = $dir . '/' . $file;
    if (!is_file($path)) continue;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $type = $ext === 'zip' ? 'zip' : ($ext === 'json' ? 'legacy-json' : 'other');
    if (!in_array($type, ['zip','legacy-json'], true)) continue;
    $items[] = [
      'file' => $file,
      'path' => $path,
      'size' => (int)@filesize($path),
      'mtime' => (int)@filemtime($path),
      'type' => $type,
    ];
  }
  usort($items, function($a,$b){ return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0); });
  return $items;
}
function human_filesize($bytes){
  $bytes = max(0, (int)$bytes);
  $units = ['B','KB','MB','GB'];
  $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
  $power = min($power, count($units) - 1);
  $value = $bytes / pow(1024, $power ?: 0);
  return number_format($value, $power === 0 ? 0 : 2, ',', '.') . ' ' . $units[$power];
}
function delete_backup_file($filename){
  $path = backup_path($filename);
  return is_file($path) ? @unlink($path) : false;
}
function restore_backup_file($filename){
  $path = backup_path($filename);
  if (!is_file($path)) return [false, 'Backup-Datei nicht gefunden.'];
  return restore_backup_from_path($path);
}
function restore_backup_from_upload(array $file){
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, 'Bitte eine Backup-Datei auswählen.'];
  if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false, 'Backup-Upload fehlgeschlagen.'];
  $name = strtolower((string)($file['name'] ?? ''));
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['zip','json'], true)) return [false, 'Erlaubt sind ZIP-Backups oder alte JSON-Backups.'];
  $tmpDir = sys_get_temp_dir() . '/matchday_restore_' . bin2hex(random_bytes(6));
  if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) return [false, 'Temporärer Ordner konnte nicht erstellt werden.'];
  $tmpFile = $tmpDir . '/upload.' . $ext;
  if (!@move_uploaded_file($file['tmp_name'], $tmpFile)) return [false, 'Backup konnte nicht temporär gespeichert werden.'];
  $result = restore_backup_from_path($tmpFile);
  @unlink($tmpFile);
  @rmdir($tmpDir);
  return $result;
}
function restore_backup_from_path($path){
  $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
  if ($ext === 'json') {
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [false, 'JSON-Backup ist ungültig.'];
    [$okBackup] = create_full_backup('Vor_Restore');
    if (!save_matches($data)) return [false, 'Legacy-Backup konnte nicht eingespielt werden.'];
    return [true, 'Legacy-Backup wiederhergestellt.' . ($okBackup ? ' Sicherheitsbackup wurde erstellt.' : '')];
  }
  if ($ext !== 'zip') return [false, 'Unbekanntes Backup-Format.'];
  if (!class_exists('ZipArchive')) return [false, 'ZipArchive ist auf dem Server nicht verfügbar.'];

  $tmpDir = sys_get_temp_dir() . '/matchday_restore_' . bin2hex(random_bytes(6));
  if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) return [false, 'Temporärer Ordner konnte nicht erstellt werden.'];

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) { @rmdir($tmpDir); return [false, 'Backup-ZIP konnte nicht geöffnet werden.']; }
  $zip->extractTo($tmpDir);
  $zip->close();

  $metaFile = $tmpDir . '/backup_meta.json';
  $matchesFile = $tmpDir . '/data/matches.json';
  $settingsFile = $tmpDir . '/data/settings.php';
  if (!is_file($metaFile) || !is_file($matchesFile) || !is_file($settingsFile)) {
    rrmdir($tmpDir);
    return [false, 'Das Backup ist kein gültiges MatchDay-Backup.'];
  }
  $meta = json_decode((string)@file_get_contents($metaFile), true);
  if (!is_array($meta) || ($meta['format'] ?? '') !== 'matchday-backup-v1') {
    rrmdir($tmpDir);
    return [false, 'Backup-Metadaten fehlen oder sind ungültig.'];
  }

  [$okBackup] = create_full_backup('Vor_Restore');

  $restoreMap = [
    $matchesFile => __DIR__ . '/data/matches.json',
    $settingsFile => __DIR__ . '/data/settings.php',
    $tmpDir . '/config.php' => __DIR__ . '/config.php',
    $tmpDir . '/logo.png' => __DIR__ . '/logo.png',
    $tmpDir . '/site.webmanifest' => __DIR__ . '/site.webmanifest',
  ];
  foreach ($restoreMap as $from => $to) {
    if (!is_file($from)) continue;
    if (!is_dir(dirname($to))) @mkdir(dirname($to), 0775, true);
    if (!@copy($from, $to)) { rrmdir($tmpDir); return [false, 'Backup konnte nicht vollständig wiederhergestellt werden.']; }
  }
  foreach (glob($tmpDir . '/uploads/logo.*') ?: [] as $logoFile) {
    $target = __DIR__ . '/uploads/' . basename($logoFile);
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0775, true);
    @copy($logoFile, $target);
  }
  rrmdir($tmpDir);
  return [true, 'Backup erfolgreich wiederhergestellt.' . ($okBackup ? ' Vorher wurde ein Sicherheitsbackup erstellt.' : '')];
}
function rrmdir($dir){
  if (!is_dir($dir)) return;
  foreach (scandir($dir) ?: [] as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . '/' . $item;
    if (is_dir($path)) rrmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function next_id($matches){ $max=0; foreach($matches as $m){ if(isset($m['id']) && $m['id']>$max) $max=$m['id']; } return $max+1; }
function sort_matches(&$matches){
  usort($matches, function($a,$b){
    $ka = strtotime($a['kickoff'] ?? '') ?: 0; $kb = strtotime($b['kickoff'] ?? '') ?: 0;
    if($ka===$kb){
      $oa = (int)($a['slot_order'] ?? 0); $ob = (int)($b['slot_order'] ?? 0);
      if ($oa === $ob) return strnatcasecmp((string)($a['field'] ?? ''),(string)($b['field'] ?? ''));
      return $oa <=> $ob;
    }
    return $ka <=> $kb;
  });
}
function sort_matches_for_display(&$matches, $settings = null, $now = null){
  if($settings===null) $settings = load_settings();
  if($now===null) $now = time();
  usort($matches, function($a,$b) use ($settings, $now){
    $ka = match_display_kickoff_ts($a); $kb = match_display_kickoff_ts($b);
    if($ka === $kb){
      $sa = match_runtime_state($a, $settings, $now);
      $sb = match_runtime_state($b, $settings, $now);
      $prio = ['live' => 0, 'upcoming' => 1, 'planned' => 1, 'scheduled' => 1, 'finished' => 2];
      $pa = $prio[$sa] ?? 9;
      $pb = $prio[$sb] ?? 9;
      if($pa !== $pb) return $pa <=> $pb;
      $oa = (int)($a['slot_order'] ?? 0); $ob = (int)($b['slot_order'] ?? 0);
      if($oa !== $ob) return $oa <=> $ob;
      return strnatcasecmp((string)($a['field'] ?? ''),(string)($b['field'] ?? ''));
    }
    return $ka <=> $kb;
  });
}
function unique_groups($matches){ $g=[]; foreach($matches as $m){ if(!empty($m['group'])) $g[$m['group']]=1; } $k=array_keys($g); sort($k, SORT_NATURAL|SORT_FLAG_CASE); return $k; }
function is_knockout_match($match){ return in_array(($match['phase'] ?? 'group'), ['quarterfinal','semifinal','final','third_place'], true); }
function match_group_label($match){
  $phase = $match['phase'] ?? 'group';
  if ($phase === 'group') return (string)($match['group'] ?? '');
  $map = ['quarterfinal' => 'VF', 'semifinal' => 'HF', 'final' => 'Finale', 'third_place' => 'Platz 3'];
  return $map[$phase] ?? strtoupper($phase);
}
function normalize_teams_by_group($raw){
  $out = [];
  if (!is_array($raw)) return $out;
  foreach ($raw as $group => $teams) {
    $group = trim((string)$group);
    if ($group === '') continue;
    if (!is_array($teams)) $teams = preg_split('/\r\n|\r|\n/', (string)$teams);
    $clean = [];
    foreach ($teams as $team) {
      $team = trim((string)$team);
      if ($team !== '') $clean[] = preg_replace('/\s+/', ' ', $team);
    }
    $out[$group] = array_values(array_unique($clean));
  }
  ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}
function circle_round_robin($teams){
  $teams = array_values($teams);
  if (count($teams) < 2) return [];
  if (count($teams) % 2 === 1) $teams[] = '__BYE__';
  $n = count($teams);
  $rounds = [];
  for ($r=0; $r < $n-1; $r++) {
    $pairs = [];
    for ($i=0; $i < $n/2; $i++) {
      $a = $teams[$i];
      $b = $teams[$n-1-$i];
      if ($a !== '__BYE__' && $b !== '__BYE__') {
        $home = ($r % 2 === 0) ? $a : $b;
        $away = ($r % 2 === 0) ? $b : $a;
        if ($i === 0 && $r % 2 === 1) { $home = $a; $away = $b; }
        $pairs[] = [$home, $away];
      }
    }
    $rounds[] = $pairs;
    $fixed = array_shift($teams);
    $last = array_pop($teams);
    array_unshift($teams, $fixed, $last);
  }
  return $rounds;
}
function build_group_stage_matches($teamsByGroup, $mode='groups'){
  $all = [];
  if ($mode === 'league') {
    $leagueTeams = [];
    foreach ($teamsByGroup as $teams) foreach ($teams as $t) $leagueTeams[] = $t;
    $teamsByGroup = ['Tabelle' => array_values(array_unique($leagueTeams))];
  }
  foreach ($teamsByGroup as $group => $teams) {
    $rounds = circle_round_robin($teams);
    foreach ($rounds as $roundIndex => $pairs) {
      foreach ($pairs as $pairIndex => $pair) {
        $all[] = [
          'phase' => 'group',
          'group' => (string)$group,
          'home' => $pair[0],
          'away' => $pair[1],
          'round' => $roundIndex + 1,
          'pair_order' => $pairIndex + 1,
        ];
      }
    }
  }
  return $all;
}
function schedule_matches_balanced($matches, $startDateTime, $fieldCount, $durationMinutes, $breakMinutes){
  $unscheduled = array_values($matches);
  $scheduled = [];
  $nextId = 1;
  $fieldCount = max(1, min(4, (int)$fieldCount));
  $slotMinutes = max(1, (int)$durationMinutes) + max(0, (int)$breakMinutes);
  $slotStart = new DateTime($startDateTime);
  $teamLastSlot = [];
  $slotIndex = 0;
  while ($unscheduled) {
    $usedTeams = [];
    $scheduledThisSlot = [];
    for ($field = 1; $field <= $fieldCount && $unscheduled; $field++) {
      $bestIdx = null; $bestScore = -999999;
      foreach ($unscheduled as $idx => $m) {
        $h = $m['home']; $a = $m['away'];
        if (isset($usedTeams[$h]) || isset($usedTeams[$a])) continue;
        $lastH = $teamLastSlot[$h] ?? -99;
        $lastA = $teamLastSlot[$a] ?? -99;
        $restH = $slotIndex - $lastH;
        $restA = $slotIndex - $lastA;
        $sameGroupPenalty = 0;
        foreach ($scheduledThisSlot as $sm) if (($sm['group'] ?? '') === ($m['group'] ?? '')) $sameGroupPenalty += 2;
        $score = min($restH, $restA) * 100 + max($restH, $restA) * 10 - $sameGroupPenalty - (($m['round'] ?? 0) * 0.01) - (($m['pair_order'] ?? 0) * 0.001);
        if ($score > $bestScore) { $bestScore = $score; $bestIdx = $idx; }
      }
      if ($bestIdx === null) break;
      $match = $unscheduled[$bestIdx];
      array_splice($unscheduled, $bestIdx, 1);
      $kickoff = clone $slotStart;
      $match['id'] = $nextId++;
      $match['field'] = (string)$field;
      $match['kickoff'] = $kickoff->format('Y-m-d H:i');
      $match['home_score'] = null;
      $match['away_score'] = null;
      $match['status'] = 'scheduled';
      $match['slot_order'] = $slotIndex;
      $scheduled[] = $match;
      $scheduledThisSlot[] = $match;
      $usedTeams[$match['home']] = true;
      $usedTeams[$match['away']] = true;
      $teamLastSlot[$match['home']] = $slotIndex;
      $teamLastSlot[$match['away']] = $slotIndex;
    }
    $slotIndex++;
    $slotStart->modify('+' . $slotMinutes . ' minutes');
  }
  return $scheduled;
}
function ko_stage_definition($settings){
  $mode = $settings['turniermodus'] ?? 'groups';
  $groupCount = ($mode === 'league') ? 1 : (int)($settings['gruppen_anzahl'] ?? 1);
  $third = !empty($settings['platz3spiel']);
  if (empty($settings['ko_phase'])) return ['matches' => [], 'third_place' => false];
  if ($mode === 'league' || $groupCount === 1) {
    return [
      'matches' => [
        ['phase'=>'semifinal','label'=>'Halbfinale 1','home_ref'=>['type'=>'table_rank','group'=>'Tabelle','rank'=>1],'away_ref'=>['type'=>'table_rank','group'=>'Tabelle','rank'=>4]],
        ['phase'=>'semifinal','label'=>'Halbfinale 2','home_ref'=>['type'=>'table_rank','group'=>'Tabelle','rank'=>2],'away_ref'=>['type'=>'table_rank','group'=>'Tabelle','rank'=>3]],
        ['phase'=>'final','label'=>'Finale','home_ref'=>['type'=>'winner_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'winner_of','label'=>'Halbfinale 2'] ],
      ],
      'third_place' => $third,
      'third_refs' => ['home_ref'=>['type'=>'loser_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'loser_of','label'=>'Halbfinale 2']],
    ];
  }
  if ($groupCount === 2) {
    return [
      'matches' => [
        ['phase'=>'semifinal','label'=>'Halbfinale 1','home_ref'=>['type'=>'group_rank','group'=>'A','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'B','rank'=>2]],
        ['phase'=>'semifinal','label'=>'Halbfinale 2','home_ref'=>['type'=>'group_rank','group'=>'B','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'A','rank'=>2]],
        ['phase'=>'final','label'=>'Finale','home_ref'=>['type'=>'winner_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'winner_of','label'=>'Halbfinale 2'] ],
      ],
      'third_place' => $third,
      'third_refs' => ['home_ref'=>['type'=>'loser_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'loser_of','label'=>'Halbfinale 2']],
    ];
  }
  if ($groupCount === 3) {
    return [
      'matches' => [
        ['phase'=>'semifinal','label'=>'Halbfinale 1','home_ref'=>['type'=>'best_group_rank','groups'=>['A','B','C'],'rank'=>2],'away_ref'=>['type'=>'group_rank','group'=>'A','rank'=>1]],
        ['phase'=>'semifinal','label'=>'Halbfinale 2','home_ref'=>['type'=>'group_rank','group'=>'B','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'C','rank'=>1]],
        ['phase'=>'final','label'=>'Finale','home_ref'=>['type'=>'winner_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'winner_of','label'=>'Halbfinale 2'] ],
      ],
      'third_place' => $third,
      'third_refs' => ['home_ref'=>['type'=>'loser_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'loser_of','label'=>'Halbfinale 2']],
    ];
  }
  return [
    'matches' => [
      ['phase'=>'quarterfinal','label'=>'Viertelfinale 1','home_ref'=>['type'=>'group_rank','group'=>'A','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'B','rank'=>2] ],
      ['phase'=>'quarterfinal','label'=>'Viertelfinale 2','home_ref'=>['type'=>'group_rank','group'=>'B','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'A','rank'=>2] ],
      ['phase'=>'quarterfinal','label'=>'Viertelfinale 3','home_ref'=>['type'=>'group_rank','group'=>'C','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'D','rank'=>2] ],
      ['phase'=>'quarterfinal','label'=>'Viertelfinale 4','home_ref'=>['type'=>'group_rank','group'=>'D','rank'=>1],'away_ref'=>['type'=>'group_rank','group'=>'C','rank'=>2] ],
      ['phase'=>'semifinal','label'=>'Halbfinale 1','home_ref'=>['type'=>'winner_of','label'=>'Viertelfinale 1'],'away_ref'=>['type'=>'winner_of','label'=>'Viertelfinale 3'] ],
      ['phase'=>'semifinal','label'=>'Halbfinale 2','home_ref'=>['type'=>'winner_of','label'=>'Viertelfinale 2'],'away_ref'=>['type'=>'winner_of','label'=>'Viertelfinale 4'] ],
      ['phase'=>'final','label'=>'Finale','home_ref'=>['type'=>'winner_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'winner_of','label'=>'Halbfinale 2'] ],
    ],
    'third_place' => $third,
    'third_refs' => ['home_ref'=>['type'=>'loser_of','label'=>'Halbfinale 1'],'away_ref'=>['type'=>'loser_of','label'=>'Halbfinale 2']],
  ];
}
function ko_phase_stage_index($phase, $hasQuarterfinals){
  $phase = (string)$phase;
  if ($hasQuarterfinals) {
    if ($phase === 'quarterfinal') return 0;
    if ($phase === 'semifinal') return 1;
    return 2;
  }
  if ($phase === 'semifinal') return 0;
  return 1;
}
function append_knockout_matches($scheduledGroupMatches, $settings){
  $def = ko_stage_definition($settings);
  if (empty($def['matches'])) return $scheduledGroupMatches;

  $fieldCount = max(1, min(4, (int)($settings['spielfelder_anzahl'] ?? 1)));
  $duration = max(1, (int)($settings['spieldauer'] ?? 18));
  $break = max(0, (int)($settings['wechselzeit'] ?? 0));
  $slotMinutes = $duration + $break;

  $lastKickoff = null;
  $lastSlotOrder = 0;
  foreach ($scheduledGroupMatches as $m) {
    $ts = strtotime($m['kickoff'] ?? '');
    if ($ts && ($lastKickoff === null || $ts > $lastKickoff)) {
      $lastKickoff = $ts;
      $lastSlotOrder = (int)($m['slot_order'] ?? 0);
    }
  }

  $nextId = next_id($scheduledGroupMatches);
  $baseTime = $lastKickoff
    ? (new DateTime(date('Y-m-d H:i', $lastKickoff)))->modify('+' . $slotMinutes . ' minutes')
    : new DateTime();
  $baseSlotOrder = $lastSlotOrder + 1;

  $hasQuarterfinals = false;
  foreach ($def['matches'] as $m) {
    if (($m['phase'] ?? '') === 'quarterfinal') { $hasQuarterfinals = true; break; }
  }

  $fieldByStage = [];
  foreach ($def['matches'] as $m) {
    $stageIndex = ko_phase_stage_index($m['phase'] ?? '', $hasQuarterfinals);
    if (!isset($fieldByStage[$stageIndex])) $fieldByStage[$stageIndex] = 1;

    $currentTime = clone $baseTime;
    if ($stageIndex > 0) $currentTime->modify('+' . ($stageIndex * $slotMinutes) . ' minutes');

    $field = $fieldByStage[$stageIndex];
    $match = [
      'id' => $nextId++,
      'phase' => $m['phase'],
      'group' => match_group_label(['phase' => $m['phase']]),
      'field' => (string)$field,
      'kickoff' => $currentTime->format('Y-m-d H:i'),
      'home' => build_placeholder_label($m['home_ref']),
      'away' => build_placeholder_label($m['away_ref']),
      'home_score' => null,
      'away_score' => null,
      'status' => 'scheduled',
      'slot_order' => $baseSlotOrder + $stageIndex,
      'label' => $m['label'],
      'home_ref' => $m['home_ref'],
      'away_ref' => $m['away_ref'],
    ];
    $scheduledGroupMatches[] = $match;

    $fieldByStage[$stageIndex]++;
    if ($fieldByStage[$stageIndex] > $fieldCount) $fieldByStage[$stageIndex] = 1;
  }

  if (!empty($def['third_place'])) {
    $stageIndex = $hasQuarterfinals ? 2 : 1;
    if (!isset($fieldByStage[$stageIndex])) $fieldByStage[$stageIndex] = 1;

    $currentTime = clone $baseTime;
    if ($stageIndex > 0) $currentTime->modify('+' . ($stageIndex * $slotMinutes) . ' minutes');

    $field = $fieldByStage[$stageIndex];
    $scheduledGroupMatches[] = [
      'id' => $nextId++,
      'phase' => 'third_place',
      'group' => 'Platz 3',
      'field' => (string)$field,
      'kickoff' => $currentTime->format('Y-m-d H:i'),
      'home' => build_placeholder_label($def['third_refs']['home_ref']),
      'away' => build_placeholder_label($def['third_refs']['away_ref']),
      'home_score' => null,
      'away_score' => null,
      'status' => 'scheduled',
      'slot_order' => $baseSlotOrder + $stageIndex,
      'label' => 'Spiel um Platz 3',
      'home_ref' => $def['third_refs']['home_ref'],
      'away_ref' => $def['third_refs']['away_ref'],
    ];
  }

  return $scheduledGroupMatches;
}
function standing_sort_compare($a, $b){
  if (($a['points'] ?? 0) !== ($b['points'] ?? 0)) return ((int)($b['points'] ?? 0)) <=> ((int)($a['points'] ?? 0));
  if (($a['gd'] ?? 0) !== ($b['gd'] ?? 0)) return ((int)($b['gd'] ?? 0)) <=> ((int)($a['gd'] ?? 0));
  if (($a['gf'] ?? 0) !== ($b['gf'] ?? 0)) return ((int)($b['gf'] ?? 0)) <=> ((int)($a['gf'] ?? 0));
  if (($a['ga'] ?? 0) !== ($b['ga'] ?? 0)) return ((int)($a['ga'] ?? 0)) <=> ((int)($b['ga'] ?? 0));
  return strnatcasecmp((string)($a['team'] ?? ''), (string)($b['team'] ?? ''));
}
function standings_rows_equal_for_ko($a, $b){
  foreach (['points','gd','gf','ga'] as $key) {
    if ((int)($a[$key] ?? 0) !== (int)($b[$key] ?? 0)) return false;
  }
  return true;
}
function best_group_rank_resolution($tables, $settings, $groups = ['A','B','C'], $rank = 2){
  $candidates = [];
  foreach ($groups as $group) {
    if (empty($tables[$group])) continue;
    foreach ($tables[$group] as $row) {
      if ((int)($row['rank'] ?? 0) === (int)$rank) {
        $row['group'] = $group;
        $candidates[] = $row;
        break;
      }
    }
  }
  if (count($candidates) < count($groups)) {
    return ['team'=>null, 'group'=>null, 'tie'=>false, 'candidates'=>$candidates, 'tied'=>[]];
  }
  usort($candidates, 'standing_sort_compare');
  $top = $candidates[0] ?? null;
  if (!$top) return ['team'=>null, 'group'=>null, 'tie'=>false, 'candidates'=>$candidates, 'tied'=>[]];
  $tied = array_values(array_filter($candidates, fn($row) => standings_rows_equal_for_ko($row, $top)));
  if (count($tied) > 1) {
    $override = trim((string)($settings['three_group_best_second_override'] ?? ''));
    if ($override !== '') {
      foreach ($tied as $row) {
        if ((string)($row['team'] ?? '') === $override) {
          return ['team'=>$row['team'], 'group'=>$row['group'], 'tie'=>true, 'candidates'=>$candidates, 'tied'=>$tied, 'manual'=>true];
        }
      }
    }
    return ['team'=>null, 'group'=>null, 'tie'=>true, 'candidates'=>$candidates, 'tied'=>$tied, 'manual'=>false];
  }
  return ['team'=>$top['team'], 'group'=>$top['group'], 'tie'=>false, 'candidates'=>$candidates, 'tied'=>[$top], 'manual'=>false];
}
function three_group_ko_slot_resolution($tables, $settings){
  $groups = ['A','B','C'];
  $best = best_group_rank_resolution($tables, $settings, $groups, 2);
  if (empty($best['team']) || empty($best['group'])) return null;
  $bestGroup = (string)$best['group'];
  $opponentGroup = null;
  foreach ($groups as $group) {
    if ($group !== $bestGroup) { $opponentGroup = $group; break; }
  }
  $remaining = array_values(array_filter($groups, fn($group) => $group !== $opponentGroup));
  return [
    'best_second' => ['team'=>$best['team'], 'group'=>$bestGroup],
    'opponent_group' => $opponentGroup,
    'remaining_groups' => $remaining,
  ];
}
function build_placeholder_label($ref){
  if (!is_array($ref)) return 'TBD';

  if (($ref['type'] ?? '') === 'group_rank') {
    $group = $ref['group'] ?? '';
    $rank = (int)($ref['rank'] ?? 0);

    if ($rank === 1) return 'Gruppensieger ' . $group;
    if ($rank === 2) return 'Zweiter ' . $group;

    return $group . $rank;
  }

  if (($ref['type'] ?? '') === 'table_rank') {
    return 'Platz ' . ($ref['rank'] ?? '');
  }

  if (($ref['type'] ?? '') === 'best_group_rank') {
    return 'Bester Zweiter';
  }

  if (($ref['type'] ?? '') === 'three_group_best_second_opponent') {
    return 'Gruppensieger A';
  }

  if (($ref['type'] ?? '') === 'three_group_remaining_winner') {
    $slot = (int)($ref['slot'] ?? 0);

    if ($slot === 1) return 'Gruppensieger B';
    if ($slot === 2) return 'Gruppensieger C';

    return 'Gruppensieger';
  }

  if (($ref['type'] ?? '') === 'winner_of') {
    return 'Sieger ' . ($ref['label'] ?? '');
  }

  if (($ref['type'] ?? '') === 'loser_of') {
    return 'Verlierer ' . ($ref['label'] ?? '');
  }

  return 'TBD';
}
function build_tournament($settings){
  $mode = $settings['turniermodus'] ?? 'groups';
  $teamsByGroup = normalize_teams_by_group($settings['teams_by_group'] ?? []);
  $base = trim((string)($settings['spielbeginn'] ?? ''));
  if ($base === '') return ['error' => 'Spielbeginn fehlt.'];
  $stageMatches = build_group_stage_matches($teamsByGroup, $mode);
  $scheduled = schedule_matches_balanced($stageMatches, $base, (int)($settings['spielfelder_anzahl'] ?? 1), (int)($settings['spieldauer'] ?? 18), (int)($settings['wechselzeit'] ?? 0));
  if (!empty($settings['ko_phase'])) $scheduled = append_knockout_matches($scheduled, $settings);
  sort_matches($scheduled);
  return ['matches' => $scheduled];
}

function all_group_matches_finished($matches){
  foreach ($matches as $m) {
    if (($m['phase'] ?? 'group') !== 'group') continue;
    if (($m['home_score'] ?? null) === null || ($m['away_score'] ?? null) === null) return false;
  }
  return true;
}
function compute_standings($matches){
  $tables = [];
  foreach ($matches as $m) {
    if (($m['phase'] ?? 'group') !== 'group') continue;
    $group = (string)($m['group'] ?? 'Tabelle');
    foreach ([$m['home'], $m['away']] as $team) {
      if (!isset($tables[$group][$team])) {
        $tables[$group][$team] = ['team'=>$team,'played'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'gd'=>0,'points'=>0];
      }
    }
    if (($m['home_score'] ?? null) === null || ($m['away_score'] ?? null) === null) continue;
    $hs = (int)$m['home_score']; $as = (int)$m['away_score'];
    $tables[$group][$m['home']]['played']++;
    $tables[$group][$m['away']]['played']++;
    $tables[$group][$m['home']]['gf'] += $hs; $tables[$group][$m['home']]['ga'] += $as;
    $tables[$group][$m['away']]['gf'] += $as; $tables[$group][$m['away']]['ga'] += $hs;
    if ($hs > $as) {
      $tables[$group][$m['home']]['wins']++; $tables[$group][$m['home']]['points'] += 3;
      $tables[$group][$m['away']]['losses']++;
    } elseif ($hs < $as) {
      $tables[$group][$m['away']]['wins']++; $tables[$group][$m['away']]['points'] += 3;
      $tables[$group][$m['home']]['losses']++;
    } else {
      $tables[$group][$m['home']]['draws']++; $tables[$group][$m['away']]['draws']++;
      $tables[$group][$m['home']]['points']++; $tables[$group][$m['away']]['points']++;
    }
    $tables[$group][$m['home']]['gd'] = $tables[$group][$m['home']]['gf'] - $tables[$group][$m['home']]['ga'];
    $tables[$group][$m['away']]['gd'] = $tables[$group][$m['away']]['gf'] - $tables[$group][$m['away']]['ga'];
  }
  foreach ($tables as $group => &$rows) {
    $rows = array_values($rows);
    usort($rows, 'standing_sort_compare');
    foreach ($rows as $i => &$row) $row['rank'] = $i + 1;
  }
  unset($rows, $row);
  ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
  return $tables;
}
function resolve_ref_to_team($ref, $tables, $matchesByLabel, $groupStageFinished = true){
  if (!is_array($ref)) return null;

  $type = $ref['type'] ?? '';
  $groupDependentTypes = ['group_rank', 'table_rank', 'best_group_rank', 'three_group_best_second_opponent', 'three_group_remaining_winner'];
  if (!$groupStageFinished && in_array($type, $groupDependentTypes, true)) {
    return null;
  }

  switch ($type) {
    case 'group_rank':
    case 'table_rank':
      $group = $ref['group']; $rank = (int)$ref['rank'];
      if (!empty($tables[$group])) {
        foreach ($tables[$group] as $row) if ((int)$row['rank'] === $rank) return $row['team'];
      }
      return null;
    case 'best_group_rank':
      $groups = $ref['groups'] ?? ['A','B','C'];
      $rank = (int)($ref['rank'] ?? 2);
      $settings = load_settings();
      $best = best_group_rank_resolution($tables, $settings, $groups, $rank);
      return $best['team'] ?? null;
    case 'three_group_best_second_opponent':
      $settings = load_settings();
      $slots = three_group_ko_slot_resolution($tables, $settings);
      if (!$slots || empty($slots['opponent_group'])) return null;
      $group = $slots['opponent_group'];
      if (!empty($tables[$group])) foreach ($tables[$group] as $row) if ((int)($row['rank'] ?? 0) === 1) return $row['team'];
      return null;
    case 'three_group_remaining_winner':
      $settings = load_settings();
      $slots = three_group_ko_slot_resolution($tables, $settings);
      $slot = max(1, (int)($ref['slot'] ?? 1));
      $group = $slots['remaining_groups'][$slot - 1] ?? null;
      if (!$group) return null;
      if (!empty($tables[$group])) foreach ($tables[$group] as $row) if ((int)($row['rank'] ?? 0) === 1) return $row['team'];
      return null;
    case 'winner_of':
    case 'loser_of':
      $label = $ref['label'];
      if (!empty($matchesByLabel[$label])) {
        $m = $matchesByLabel[$label];
        if (($m['home_score'] ?? null) !== null && ($m['away_score'] ?? null) !== null && (int)$m['home_score'] !== (int)$m['away_score']) {
          $winner = ((int)$m['home_score'] > (int)$m['away_score']) ? ($m['resolved_home'] ?? $m['home']) : ($m['resolved_away'] ?? $m['away']);
          $loser  = ((int)$m['home_score'] > (int)$m['away_score']) ? ($m['resolved_away'] ?? $m['away']) : ($m['resolved_home'] ?? $m['home']);
          return $ref['type'] === 'winner_of' ? $winner : $loser;
        }
      }
      return null;
  }
  return null;
}
function resolve_match_labels($matches, $tables){
  $groupStageFinished = all_group_matches_finished($matches);
  $byLabel = [];
  foreach ($matches as $m) if (!empty($m['label'])) $byLabel[$m['label']] = $m;
  $out = [];
  foreach ($matches as $m) {
    $m['resolved_home'] = $m['home'];
    $m['resolved_away'] = $m['away'];
    if (!empty($m['home_ref'])) {
      $team = resolve_ref_to_team($m['home_ref'], $tables, $byLabel, $groupStageFinished);
      if ($team) $m['resolved_home'] = $team;
    }
    if (!empty($m['away_ref'])) {
      $team = resolve_ref_to_team($m['away_ref'], $tables, $byLabel, $groupStageFinished);
      if ($team) $m['resolved_away'] = $team;
    }
    if (!empty($m['label'])) $byLabel[$m['label']] = $m;
    $out[] = $m;
  }
  return $out;
}
function tournament_end_time($matches, $settings){
  if (!$matches) return null;
  $duration = max(1, (int)($settings['spieldauer'] ?? 18));
  $latest = 0;
  foreach ($matches as $m) { $ts = strtotime($m['kickoff'] ?? ''); if ($ts > $latest) $latest = $ts; }
  if (!$latest) return null;
  return date('Y-m-d H:i', $latest + $duration * 60);
}
function validate_tournament_form($settings){
  $errors = [];
  $mode = $settings['turniermodus'] ?? 'groups';
  $groups = ($mode === 'league') ? 1 : (int)($settings['gruppen_anzahl'] ?? 0);
  if ($mode !== 'league' && !in_array($groups, [1,2,3,4], true)) $errors[] = 'Erlaubt sind 1, 2, 3 oder 4 Gruppen.';
  $seenTeams = [];
  $tables = normalize_teams_by_group($settings['teams_by_group'] ?? []);
  foreach ($tables as $group => $teams) {
    if (count($teams) < 2) $errors[] = 'Gruppe ' . $group . ' braucht mindestens 2 Teams.';
    if (count($teams) > 8) $errors[] = 'Gruppe ' . $group . ' darf maximal 8 Teams haben.';
    foreach ($teams as $team) {
      $key = function_exists('mb_strtolower') ? mb_strtolower($team, 'UTF-8') : strtolower($team);
      if (isset($seenTeams[$key])) $errors[] = 'Doppelter Teamname: ' . $team;
      $seenTeams[$key] = true;
    }
  }
  if ($mode === 'league' && count($seenTeams) < 4 && !empty($settings['ko_phase'])) $errors[] = 'Für eine Finalrunde in der Gesamttabelle werden mindestens 4 Teams benötigt.';
  if ($mode !== 'league' && $groups === 1 && count($seenTeams) < 4 && !empty($settings['ko_phase'])) $errors[] = 'Für eine Finalrunde mit nur einer Gruppe werden mindestens 4 Teams benötigt.';
  if ($mode !== 'league') {
    if ($groups === 2) {
      foreach (['A','B'] as $g) if (count($tables[$g] ?? []) < 2) $errors[] = 'Für 2 Gruppen werden Teams in A und B benötigt.';
    }
    if ($groups === 3) {
      foreach (['A','B','C'] as $g) if (count($tables[$g] ?? []) < 2) $errors[] = 'Für 3 Gruppen werden Teams in A bis C benötigt.';
    }
    if ($groups === 4) {
      foreach (['A','B','C','D'] as $g) if (count($tables[$g] ?? []) < 2) $errors[] = 'Für 4 Gruppen werden Teams in A bis D benötigt.';
    }
  }
  return $errors;
}


function time_key($kickoff){
  $ts = strtotime((string)$kickoff);
  return $ts ? date('Y-m-d H:i', $ts) : '';
}
function team_name_key($team){
  $team = trim((string)$team);
  if (function_exists('mb_strtolower')) return mb_strtolower($team, 'UTF-8');
  return strtolower($team);
}
function match_team_names($match){
  $teams = [];
  foreach (['resolved_home','home','resolved_away','away'] as $k) {
    if (!empty($match[$k])) $teams[] = trim((string)$match[$k]);
  }
  return array_values(array_unique(array_filter($teams, fn($v) => $v !== '')));
}
function rebuild_slot_orders($matches){
  usort($matches, function($a,$b){
    $ka = strtotime($a['kickoff'] ?? '') ?: 0; $kb = strtotime($b['kickoff'] ?? '') ?: 0;
    if($ka === $kb){ return ((int)($a['field'] ?? 0)) <=> ((int)($b['field'] ?? 0)); }
    return $ka <=> $kb;
  });
  $slotMap = [];
  $slotIndex = 0;
  foreach ($matches as &$m) {
    $key = time_key($m['kickoff'] ?? '');
    if ($key === '') continue;
    if (!array_key_exists($key, $slotMap)) $slotMap[$key] = $slotIndex++;
    $m['slot_order'] = $slotMap[$key];
  }
  unset($m);
  return $matches;
}
function find_match_by_id($matches, $id){
  foreach ($matches as $idx => $m) {
    if ((int)($m['id'] ?? 0) === (int)$id) return [$idx, $m];
  }
  return [null, null];
}
function validate_schedule_conflicts($matches, $ignoreIds = []){
  $issues = [];
  $ignoreMap = array_fill_keys(array_map('intval', $ignoreIds), true);
  foreach ($matches as $m) {
    $id = (int)($m['id'] ?? 0);
    if (isset($ignoreMap[$id])) continue;
    $timeKey = time_key($m['kickoff'] ?? '');
    if ($timeKey === '') continue;
    $fieldKey = $timeKey . '|' . (string)($m['field'] ?? '1');
    if (!isset($fieldSeen)) $fieldSeen = [];
    if (!isset($teamSeen)) $teamSeen = [];
    if (isset($fieldSeen[$fieldKey])) {
      $issues[] = 'Feld ' . ($m['field'] ?? '1') . ' ist um ' . date('H:i', strtotime($timeKey)) . ' bereits belegt.';
    } else {
      $fieldSeen[$fieldKey] = $id;
    }
    foreach (match_team_names($m) as $teamName) {
      $teamKey = team_name_key($teamName) . '|' . $timeKey;
      if (isset($teamSeen[$teamKey])) {
        $issues[] = $teamName . ' wäre um ' . date('H:i', strtotime($timeKey)) . ' doppelt eingeplant.';
      } else {
        $teamSeen[$teamKey] = $id;
      }
    }
  }
  return array_values(array_unique($issues));
}



function tournament_has_started($matches = null, $settings = null){
  if($matches === null) $matches = load_matches();
  if($settings === null) $settings = load_settings();
  foreach($matches as $m){
    $state = match_runtime_state($m, $settings, time());
    if($state === 'live' || $state === 'finished') return true;
    if(!empty($m['actual_start']) || !empty($m['actual_end'])) return true;
    $status = (string)($m['status'] ?? '');
    if($status === 'live' || $status === 'finished') return true;
  }
  return false;
}

function show_table_enabled($settings = null){ if($settings===null) $settings = load_settings(); return !empty($settings['show_table']); }
function public_scores_enabled($settings = null){ if($settings===null) $settings = load_settings(); return !empty($settings['show_public_scores']); }
function internal_scores_enabled($settings = null){ if($settings===null) $settings = load_settings(); return !empty($settings['allow_internal_scores']); }
function control_mode($settings = null){ if($settings===null) $settings = load_settings(); return in_array(($settings['control_mode'] ?? 'auto'), ['auto','central','referee'], true) ? $settings['control_mode'] : 'auto'; }
function public_index_url(){
  $base = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  $url = $base . rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\') . '/index.php';
  $url = preg_replace('~(?<!:)//+~', '/', $url);
  $url = preg_replace('~^https:/~', 'https://', $url);
  $url = preg_replace('~^http:/~', 'http://', $url);
  return $url;
}
function qr_code_url($targetUrl = null){
  if($targetUrl === null) $targetUrl = public_index_url();
  return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode($targetUrl);
}
function referee_login_expiry($settings = null, $matches = null){
  if($settings === null) $settings = load_settings();
  if($matches === null) $matches = load_matches();
  $end = tournament_end_time($matches, $settings);
  $ts = $end ? strtotime($end) : time();
  return $ts + 3600;
}
function ensure_referee_tokens($settings = null, $matches = null){
  if($settings === null) $settings = load_settings();
  $expiry = referee_login_expiry($settings, $matches);
  $tokens = is_array($settings['referee_tokens'] ?? null) ? $settings['referee_tokens'] : [];
  for($i=1;$i<=4;$i++){
    if (empty($tokens[$i]['token']) || empty($tokens[$i]['expires']) || (int)$tokens[$i]['expires'] < time()) {
      $tokens[$i] = ['token' => bin2hex(random_bytes(8)), 'expires' => $expiry];
    } else {
      $tokens[$i]['expires'] = $expiry;
    }
  }
  $settings['referee_tokens'] = $tokens;
  return $settings;
}
function validate_referee_access($field, $token, $settings = null){
  if($settings===null) $settings = load_settings();
  $field = (int)$field;
  if($field < 1 || $field > 4) return false;
  $row = $settings['referee_tokens'][$field] ?? null;
  if(!$row || empty($row['token']) || empty($token)) return false;
  if(!hash_equals((string)$row['token'], (string)$token)) return false;
  if((int)($row['expires'] ?? 0) < time()) return false;
  return true;
}
function save_matches_with_resolved_order($matches){
  $matches = rebuild_slot_orders($matches);
  return save_matches($matches);
}

function update_match_scores(&$matches, $matchId, $homeScore, $awayScore, $field = null, $preserveExistingOnBlank = false){
  $matchId = (int)$matchId;
  $field = $field === null ? null : (string)(int)$field;
  foreach($matches as &$m){
    if((int)($m['id'] ?? 0) !== $matchId) continue;
    if($field !== null && (string)($m['field'] ?? '1') !== $field) {
      unset($m);
      return false;
    }
    $homeBlank = trim((string)$homeScore) === '';
    $awayBlank = trim((string)$awayScore) === '';
    if(!$homeBlank || !$preserveExistingOnBlank) $m['home_score'] = $homeBlank ? null : max(0, (int)$homeScore);
    if(!$awayBlank || !$preserveExistingOnBlank) $m['away_score'] = $awayBlank ? null : max(0, (int)$awayScore);
    unset($m);
    return true;
  }
  unset($m);
  return false;
}

function match_display_kickoff_ts($match){
  $display = (string)($match['_display_kickoff'] ?? '');
  $ts = $display !== '' ? strtotime($display) : false;
  if(!$ts) $ts = strtotime((string)($match['kickoff'] ?? ''));
  return $ts ?: time();
}
function match_display_kickoff($match, $format = 'Y-m-d H:i'){
  return date($format, match_display_kickoff_ts($match));
}
function apply_dynamic_display_times($matches, $settings = null, $now = null){
  if($settings===null) $settings = load_settings();
  if($now===null) $now = time();
  $duration = max(1, (int)($settings['spieldauer'] ?? 18));
  $break = max(0, (int)($settings['wechselzeit'] ?? 0));
  $slotSeconds = ($duration + $break) * 60;

  $byField = [];
  foreach($matches as $idx => $m){
    $field = (string)($m['field'] ?? '1');
    if(!isset($byField[$field])) $byField[$field] = [];
    $byField[$field][] = ['idx' => $idx, 'match' => $m];
  }

  foreach($byField as $field => $rows){
    usort($rows, function($a,$b){
      $ka = strtotime((string)($a['match']['kickoff'] ?? '')) ?: 0;
      $kb = strtotime((string)($b['match']['kickoff'] ?? '')) ?: 0;
      if($ka === $kb){
        $oa = (int)($a['match']['slot_order'] ?? 0);
        $ob = (int)($b['match']['slot_order'] ?? 0);
        if($oa === $ob) return ((int)($a['match']['id'] ?? 0)) <=> ((int)($b['match']['id'] ?? 0));
        return $oa <=> $ob;
      }
      return $ka <=> $kb;
    });

    $hasRuntimeData = false;
    foreach($rows as $row){
      $m = $row['match'];
      if(!empty($m['actual_start']) || !empty($m['actual_end']) || (string)($m['status'] ?? '') === 'live' || (string)($m['status'] ?? '') === 'finished'){
        $hasRuntimeData = true;
        break;
      }
    }

    $cursor = null;
    foreach($rows as $row){
      $idx = $row['idx'];
      $m = $row['match'];
      $plannedTs = strtotime((string)($m['kickoff'] ?? '')) ?: $now;
      $status = (string)($m['status'] ?? 'scheduled');
      $actualStart = !empty($m['actual_start']) ? strtotime((string)$m['actual_start']) : false;
      $actualEnd = !empty($m['actual_end']) ? strtotime((string)$m['actual_end']) : false;

      if(!$hasRuntimeData){
        $displayTs = $plannedTs;
      } elseif($actualStart){
        $displayTs = $actualStart;
      } elseif($actualEnd){
        $displayTs = $actualEnd - ($duration * 60);
      } elseif($cursor !== null){
        $displayTs = max($plannedTs, $cursor);
      } else {
        $displayTs = $plannedTs;
      }

      $matches[$idx]['_display_kickoff'] = date('Y-m-d H:i', $displayTs);
      $matches[$idx]['_display_delay_min'] = (int) floor(($displayTs - $plannedTs) / 60);

      if($actualEnd){
        $cursor = $actualEnd + ($break * 60);
      } elseif($status === 'live' && $actualStart){
        $cursor = $actualStart + $slotSeconds;
      } else {
        $cursor = $displayTs + $slotSeconds;
      }
    }
  }

  return $matches;
}

function match_runtime_state($match, $settings = null, $now = null){
  if($settings===null) $settings = load_settings();
  if($now===null) $now = time();
  $mode = control_mode($settings);
  $duration = max(1, (int)($settings['spieldauer'] ?? 18));
  if($mode === 'auto'){
    $kickoff = strtotime((string)($match['kickoff'] ?? ''));
    if(!$kickoff) return 'planned';
    $end = $kickoff + ($duration * 60);
    if($now >= $kickoff && $now < $end) return 'live';
    if($now >= $end) return 'finished';
    return 'planned';
  }
  $status = (string)($match['status'] ?? 'scheduled');
  if($status === 'live') return 'live';
  if($status === 'finished') return 'finished';
  return 'planned';
}

function match_has_decisive_score($match){
  return (($match['home_score'] ?? null) !== null)
    && (($match['away_score'] ?? null) !== null)
    && ((int)$match['home_score'] !== (int)$match['away_score']);
}
function match_dependencies_ready($match, $allMatches, $settings = null){
  if (($match['phase'] ?? 'group') === 'group') return true;
  if($settings===null) $settings = load_settings();

  $tables = compute_standings($allMatches);
  $byLabel = [];
  foreach ($allMatches as $m) {
    if (!empty($m['label'])) $byLabel[(string)$m['label']] = $m;
  }

  $refs = [];
  if (!empty($match['home_ref']) && is_array($match['home_ref'])) $refs[] = $match['home_ref'];
  if (!empty($match['away_ref']) && is_array($match['away_ref'])) $refs[] = $match['away_ref'];

  foreach ($refs as $ref) {
    $type = $ref['type'] ?? '';

    if (in_array($type, ['group_rank','table_rank','best_group_rank','three_group_best_second_opponent','three_group_remaining_winner'], true)) {
      if (!all_group_matches_finished($allMatches)) return false;
      if ($type === 'best_group_rank') {
        $groups = $ref['groups'] ?? ['A','B','C'];
        $rank = (int)($ref['rank'] ?? 2);
        $best = best_group_rank_resolution($tables, $settings, $groups, $rank);
        if (empty($best['team'])) return false;
      }
      continue;
    }

    if (in_array($type, ['winner_of','loser_of'], true)) {
      $label = (string)($ref['label'] ?? '');
      if ($label === '' || empty($byLabel[$label])) return false;
      if (!match_has_decisive_score($byLabel[$label])) return false;
      continue;
    }
  }

  return true;
}
function match_display_state($match, $allMatches, $settings = null, $now = null){
  if($settings===null) $settings = load_settings();
  if($now===null) $now = time();

  $runtime = match_runtime_state($match, $settings, $now);
  if ($runtime === 'live' || $runtime === 'finished') return $runtime;

  $upcomingWindowMinutes = max(0, (int)($settings['status_upcoming'] ?? 0));
  $kickoffTs = match_display_kickoff_ts($match);
  $secondsUntilKickoff = $kickoffTs - $now;
  $isWithinUpcomingWindow = $upcomingWindowMinutes > 0
    && $secondsUntilKickoff > 0
    && $secondsUntilKickoff <= ($upcomingWindowMinutes * 60);

  if (control_mode($settings) !== 'central') {
    return $isWithinUpcomingWindow ? 'upcoming' : 'planned';
  }

  $field = (string)($match['field'] ?? '1');
  $fieldMatches = array_values(array_filter($allMatches, fn($m) => (string)($m['field'] ?? '1') === $field));
  sort_matches($fieldMatches);

  $nextPlannedId = null;
  foreach ($fieldMatches as $fm) {
    $state = match_runtime_state($fm, $settings, $now);
    if ($state === 'finished' || $state === 'live') continue;
    $nextPlannedId = (int)($fm['id'] ?? 0);
    break;
  }

  return (((int)($match['id'] ?? 0) === $nextPlannedId) && $isWithinUpcomingWindow) ? 'upcoming' : 'planned';
}

function current_or_next_match_for_field($matches, $field, $settings = null){
  if($settings===null) $settings = load_settings();
  $field = (string)(int)$field;
  $now = time();
  $fieldMatches = array_values(array_filter($matches, fn($m) => (string)($m['field'] ?? '1') === $field));
  sort_matches($fieldMatches);
  foreach($fieldMatches as $m){
    if(match_runtime_state($m, $settings, $now) === 'live') return $m;
  }
  foreach($fieldMatches as $m){
    if(match_runtime_state($m, $settings, $now) === 'finished') continue;
    if(!match_dependencies_ready($m, $matches, $settings)) continue;
    return $m;
  }
  return null;
}
function shift_following_matches_for_field(&$matches, $field, $anchorId, $anchorStartTs, $settings = null, $useActualEnd = false){
  if($settings===null) $settings = load_settings();
  if (control_mode($settings) === 'central') return;
  $duration = max(1, (int)($settings['spieldauer'] ?? 18));
  $break = max(0, (int)($settings['wechselzeit'] ?? 0));
  $slotMinutes = $duration + $break;
  $field = (string)(int)$field;
  $fieldMatches = [];
  foreach($matches as $idx => $m){ if((string)($m['field'] ?? '1') === $field) $fieldMatches[] = ['idx'=>$idx,'m'=>$m]; }
  usort($fieldMatches, function($a,$b){ return (strtotime($a['m']['kickoff'] ?? '') ?: 0) <=> (strtotime($b['m']['kickoff'] ?? '') ?: 0); });
  $found = false; $nextTs = $anchorStartTs + $slotMinutes*60;
  foreach($fieldMatches as $row){
    $idx = $row['idx']; $m = $row['m'];
    if((int)($m['id'] ?? 0) === (int)$anchorId){ $found = true; continue; }
    if(!$found) continue;
    if(($m['status'] ?? 'scheduled') === 'finished') {
      $base = strtotime((string)($m['actual_end'] ?? $m['kickoff'] ?? ''));
      if($base) $nextTs = $base + $break*60;
      continue;
    }
    $plannedTs = strtotime((string)($m['kickoff'] ?? '')) ?: $nextTs;
    $nextTs = max($plannedTs, $nextTs);
    $matches[$idx]['kickoff'] = date('Y-m-d H:i', $nextTs);
    $matches[$idx]['slot_order'] = null;
    $nextTs += $slotMinutes*60;
  }
}
function start_match(&$matches, $matchId, $settings = null){
  if($settings===null) $settings = load_settings();
  $now = time();
  foreach($matches as &$m){
    if((int)($m['id'] ?? 0) === (int)$matchId){
      if(!match_dependencies_ready($m, $matches, $settings)) {
        unset($m);
        return false;
      }
      $m['status'] = 'live';
      $m['actual_start'] = date('Y-m-d H:i:s', $now);
      $m['actual_end'] = null;
      shift_following_matches_for_field($matches, (string)($m['field'] ?? '1'), $matchId, $now, $settings);
      unset($m);
      return true;
    }
  }
  unset($m);
  return false;
}
function finish_match(&$matches, $matchId, $settings = null){
  if($settings===null) $settings = load_settings();
  $now = time();
  foreach($matches as &$m){
    if((int)($m['id'] ?? 0) === (int)$matchId){
      $m['status'] = 'finished';
      if(empty($m['actual_start'])) $m['actual_start'] = date('Y-m-d H:i:s', strtotime((string)($m['kickoff'] ?? 'now')) ?: $now);
      $m['actual_end'] = date('Y-m-d H:i:s', $now);
      shift_following_matches_for_field($matches, (string)($m['field'] ?? '1'), $matchId, $now, $settings);
      unset($m);
      return true;
    }
  }
  unset($m);
  return false;
}
function central_start_next_round(&$matches, $settings = null){
  if($settings===null) $settings = load_settings();
  $now = time();
  $targets = [];
  for($field=1; $field<=max(1,(int)($settings['spielfelder_anzahl'] ?? 1)); $field++){
    $m = current_or_next_match_for_field($matches, $field, $settings);
    if($m && match_runtime_state($m, $settings, $now) === 'planned') $targets[] = (int)$m['id'];
  }
  foreach($targets as $id) start_match($matches, $id, $settings);
  return count($targets);
}
function central_finish_live_round(&$matches, $settings = null){
  if($settings===null) $settings = load_settings();
  $now = time();
  $targets = [];
  foreach($matches as $m){
    if(match_runtime_state($m, $settings, $now) === 'live') $targets[] = (int)$m['id'];
  }
  foreach($targets as $id) finish_match($matches, $id, $settings);
  return count($targets);
}
function runtime_state_lists($matches, $settings = null){
  if($settings===null) $settings = load_settings();
  $now = time();
  $current=[]; $upcoming=[]; $finished=[];
  foreach($matches as $m){
    $m['_has_score'] = (($m['home_score'] ?? null) !== null && ($m['away_score'] ?? null) !== null);
    $state = match_runtime_state($m, $settings, $now);
    if($state === 'live') $current[] = $m;
    elseif($state === 'finished') $finished[] = $m;
    else $upcoming[] = $m;
  }
  usort($finished, function($a,$b){ return strtotime((string)($b['actual_end'] ?? $b['kickoff'] ?? '')) <=> strtotime((string)($a['actual_end'] ?? $a['kickoff'] ?? '')); });
  sort_matches_for_display($upcoming, $settings, $now);
  sort_matches_for_display($current, $settings, $now);
  return [$current,$upcoming,$finished];
}
if (is_file(__DIR__ . '/install_report.php')) {
    require_once __DIR__ . '/install_report.php';
    if (function_exists('matchday_install_report_bootstrap')) {
        matchday_install_report_bootstrap();
    }
}
