<?php
/**
 * Täglicher Cron-Job: löscht Buchungsdaten 8 Wochen nach dem Kurstermin (DSGVO).
 * Nur über die Kommandozeile ausführbar (lib/ ist per .htaccess gesperrt).
 *   php /home/u261902578/domains/<domain>/public_html[/…]/lib/aufraeumen.php
 */
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require __DIR__ . '/db.php';

$db = kaese_db();
if (!$db) {
  fwrite(STDERR, date('c') . " aufraeumen: Datenbank nicht erreichbar\n");
  exit(1);
}
$n = kaese_aufraeumen($db);
echo date('c') . " aufraeumen: $n Buchung(en) gelöscht\n";
