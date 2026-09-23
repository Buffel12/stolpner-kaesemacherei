<?php
/**
 * Live-Verfügbarkeit der Kurstermine (Buchungssystem, Phase 2).
 * GET → {"success":true,"kurse":{"2027-11-13-kaesekurs":{"plaetze":14,"frei":6}, …}}
 * Liefert nur heutige und künftige Termine. Ist die Datenbank nicht erreichbar,
 * antwortet es mit 503 — die Kursseite zeigt dann die Werte aus dem Build.
 */

require __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false]);
  exit;
}

$db = kaese_db();
if (!$db) {
  http_response_code(503);
  echo json_encode(['success' => false]);
  exit;
}

try {
  $zeilen = $db->query(
    'SELECT id, plaetze, gebucht FROM kurse WHERE datum >= CURDATE() ORDER BY datum'
  )->fetchAll();
} catch (PDOException $e) {
  error_log('verfuegbarkeit: ' . $e->getMessage());
  http_response_code(503);
  echo json_encode(['success' => false]);
  exit;
}

$kurse = [];
foreach ($zeilen as $z) {
  $plaetze = (int) $z['plaetze'];
  $kurse[$z['id']] = [
    'plaetze' => $plaetze,
    'frei'    => max(0, $plaetze - (int) $z['gebucht']),
  ];
}

echo json_encode(['success' => true, 'kurse' => $kurse]);
