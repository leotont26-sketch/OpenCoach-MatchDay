<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: text/plain; charset=utf-8');

if (!is_logged_in()) {
  http_response_code(401);
  echo "Nicht angemeldet.";
  exit;
}

// Ensure data file exists / is creatable
ensure_datafile();

$raw = file_get_contents('php://input');
if ($raw === false) {
  http_response_code(400);
  echo "Konnte Request-Body nicht lesen.";
  exit;
}

$data = json_decode($raw, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(400);
  echo "Ungültiges JSON: " . json_last_error_msg();
  exit;
}

if (!isset($data['matches']) || !is_array($data['matches'])) {
  http_response_code(400);
  echo "Feld 'matches' fehlt oder ist kein Array.";
  exit;
}

$matches = load_matches();
$before = count($matches);
$created = 0;

foreach ($data['matches'] as $m) {
  $group = isset($m['group']) ? trim((string)$m['group']) : '';
  $field = isset($m['field']) ? trim((string)$m['field']) : '';
  $kickoff = isset($m['kickoff']) ? trim((string)$m['kickoff']) : '';
  $home = isset($m['home']) ? trim((string)$m['home']) : '';
  $away = isset($m['away']) ? trim((string)$m['away']) : '';

  if ($group === '' || $field === '' || $kickoff === '' || $home === '' || $away === '') {
    continue; // skip invalid
  }

  $matches[] = [
    'id' => next_id($matches),
    'group' => $group,
    'field' => $field,
    'kickoff' => $kickoff,
    'home' => $home,
    'away' => $away,
    'home_score' => null,
    'away_score' => null,
    'status' => 'scheduled'
  ];
  $created++;
}

// Try saving
if (!save_matches($matches)) {
  http_response_code(500);
  echo "Speichern fehlgeschlagen: Prüfe Schreibrechte für den Ordner 'data/'.";
  exit;
}

$after = count($matches);
echo "Importiert: {$created} (vorher: {$before}, jetzt: {$after})";
