<?php
/**
 * Datenbank-Verbindung des Buchungssystems.
 *
 * Zugangsdaten liegen in `buchung-secret.php` AUSSERHALB des Web-Roots:
 *   Live:    /home/u261902578/domains/stolpner-kaesemacherei.de/buchung-secret.php
 *   Staging: /home/u261902578/domains/unitednet-design.com/buchung-secret.php
 * Vorlage: db/buchung-secret.example.php im Repo.
 *
 * kaese_db() liefert ein PDO-Objekt oder null, wenn die DB nicht erreichbar ist —
 * Aufrufer müssen dann auf den Fallback (frei-Wert aus der .md) ausweichen.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
  http_response_code(404);
  exit;
}

/** Inhalt von buchung-secret.php (oder leeres Array, wenn nicht gefunden). */
function kaese_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfg = [];
  // lib/ → Web-Root → eine Ebene darüber (Live) bzw. zwei darüber (Staging-Subdomain).
  foreach ([dirname(__DIR__, 2), dirname(__DIR__, 3)] as $dir) {
    $datei = $dir . '/buchung-secret.php';
    if (is_file($datei)) {
      $inhalt = include $datei;
      if (is_array($inhalt)) $cfg = $inhalt;
      break;
    }
  }
  return $cfg;
}

// Alle Zeiten (PHP und MySQL) in deutscher Zeit — der DB-Server läuft in UTC.
date_default_timezone_set('Europe/Berlin');

function kaese_db(): ?PDO {
  static $pdo = null, $versucht = false;
  if ($versucht) return $pdo;
  $versucht = true;

  $cfg = kaese_config();
  if (empty($cfg['db_name'])) return null;

  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $cfg['db_host'] ?? '127.0.0.1', $cfg['db_port'] ?? 3306, $cfg['db_name']);
  try {
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
      PDO::ATTR_TIMEOUT            => 3,
    ]);
    // Offset statt Zonenname, da MySQL-Zeitzonentabellen evtl. nicht geladen sind.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
  } catch (PDOException $e) {
    error_log('kaese_db: ' . $e->getMessage());
    $pdo = null;
  }
  return $pdo;
}

/**
 * Löschkonzept (DSGVO): Buchungen werden 8 Wochen nach dem Kurstermin gelöscht.
 * Der Termin selbst (ohne Personendaten) bleibt stehen. Liefert die Anzahl
 * gelöschter Buchungen. Läuft täglich per Cron (lib/aufraeumen.php) und
 * zusätzlich bei jedem Aufruf der Verwaltung.
 */
const KAESE_LOESCHEN_NACH_WOCHEN = 8;

function kaese_aufraeumen(PDO $db): int {
  $stmt = $db->prepare('DELETE b FROM buchungen b JOIN kurse k ON k.id = b.kurs_id
    WHERE k.datum < CURDATE() - INTERVAL :w WEEK');
  $stmt->execute([':w' => KAESE_LOESCHEN_NACH_WOCHEN]);
  return $stmt->rowCount();
}
