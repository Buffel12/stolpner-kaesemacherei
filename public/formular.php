<?php
/**
 * Mail-Handler der Stolpner Käsemacherei — Versand per authentifiziertem SMTP
 * über das Postfach info@stolpner-kaesemacherei.de (DKIM/SPF-konform).
 *
 * Zwei getrennte Mails:
 * - an das Team: alle Angaben, Antworten gehen direkt an den Absender;
 * - an den Absender: neutrale Bestätigung OHNE seine Freitexte, damit das
 *   Formular nicht als Spam-Schleuder an fremde Adressen missbraucht werden kann.
 *
 * Spamschutz: Honeypot, Mindest-Ausfüllzeit (per JS gemessen — direkte Bot-POSTs
 * haben sie nicht), Links in Textfeldern, nur bekannte Felder, Limit je IP.
 * Erkannter Spam wird still verworfen (Antwort „success", keine Mail).
 *
 * Kursanmeldungen ziehen die Plätze atomar in der Datenbank ab (Buchungssystem):
 * Reicht der Platz nicht mehr, gibt es keine Mail und keinen Abzug (HTTP 409).
 * Ist die Datenbank nicht erreichbar oder der Termin dort unbekannt, läuft die
 * Anmeldung wie früher als reine Mail-Anfrage (manuelle Bestätigung durchs Team).
 */

require __DIR__ . '/lib/phpmailer/Exception.php';
require __DIR__ . '/lib/phpmailer/PHPMailer.php';
require __DIR__ . '/lib/phpmailer/SMTP.php';
require __DIR__ . '/lib/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Konfiguration ---------------------------------------------------------
// Passwort liegt in einer geschützten Datei außerhalb des Web-Roots, damit es
// nicht im Web erreichbar ist und Deploys es nicht überschreiben:
// Gesucht wird eine und zwei Ebenen über dem Web-Root.
$secret = null;
foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $dir) {
  if (is_file($dir . '/formular-secret.php')) { $secret = include $dir . '/formular-secret.php'; break; }
}
$SMTP_PASS = is_array($secret) ? ($secret['smtp_pass'] ?? '') : '';

$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;                                   // 465 = SSL
$SMTP_USER = 'info@stolpner-kaesemacherei.de';      // Postfach = Absender
$ABSENDER  = 'info@stolpner-kaesemacherei.de';
$TEAM      = ['info@unitednet-design.com', 'stolpnerkaesemacherei@gmail.com'];

const MIN_AUSFUELLZEIT_MS = 3000;   // schneller füllt kein Mensch das Formular aus
const LIMIT_PRO_STUNDE    = 5;      // Anfragen je IP und Stunde

header('Content-Type: application/json; charset=utf-8');

function antwort(int $code, array $daten): void {
  http_response_code($code);
  echo json_encode($daten);
  exit;
}

/** Spam: so tun, als hätte alles geklappt (Bots lernen nichts), aber nichts senden. */
function still_verwerfen(string $grund): void {
  error_log('formular spam verworfen: ' . $grund . ' (' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ')');
  antwort(200, ['success' => true]);
}

/** Textfeld holen: getrimmt, ohne Steuerzeichen, auf Länge gekürzt. */
function feld(string $key, int $max): string {
  $val = $_POST[$key] ?? '';
  if (!is_string($val)) return '';
  $val = preg_replace('/[^\P{C}\n\t]/u', '', $val) ?? '';
  return mb_substr(trim($val), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  antwort(405, ['success' => false, 'message' => 'Method not allowed']);
}

// --- Spamschutz ----------------------------------------------------------------

// Honeypot: von Bots ausgefüllt.
if (!empty($_POST['botcheck'])) still_verwerfen('honeypot');

// Ausfüllzeit: setzt nur das Skript der Website. Fehlt sie oder ist sie zu kurz,
// kam die Anfrage nicht von einem Menschen im Browser.
$dauer = filter_var($_POST['js_dauer'] ?? '', FILTER_VALIDATE_INT);
if ($dauer === false || $dauer < MIN_AUSFUELLZEIT_MS) still_verwerfen('ausfuellzeit');

$istBuchung = (($_POST['formular'] ?? '') === 'kursanmeldung');

// Nur bekannte Felder, jeweils mit Höchstlänge. Alles andere wird ignoriert.
$felder = $istBuchung
  ? ['Kurstermin' => 120, 'Name' => 80, 'Anzahl_Personen' => 2, 'email' => 190, 'Telefon' => 40, 'Nachricht' => 2000]
  : ['name' => 80, 'email' => 190, 'telefon' => 40, 'anliegen' => 80, 'message' => 3000];
$labels = [
  'Kurstermin' => 'Kurstermin', 'Name' => 'Name', 'name' => 'Name', 'Anzahl_Personen' => 'Anzahl Personen',
  'email' => 'E-Mail', 'Telefon' => 'Telefon', 'telefon' => 'Telefon', 'anliegen' => 'Anliegen',
  'Nachricht' => 'Nachricht', 'message' => 'Nachricht',
];
$werte = [];
foreach ($felder as $key => $max) $werte[$key] = feld($key, $max);

// Links in Textfeldern: echte Kursanfragen brauchen keine.
foreach ($werte as $key => $val) {
  if ($key !== 'email' && preg_match('~(https?://|www\.|\[url|<a\s)~i', $val)) still_verwerfen('link in ' . $key);
}

$email = $werte['email'];
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  antwort(422, ['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
}

// Limit je IP (Datei im Temp-Verzeichnis, Zeitstempel der letzten Stunde).
$limitDatei = sys_get_temp_dir() . '/kaese_formular_' . md5($_SERVER['REMOTE_ADDR'] ?? '-');
$zeiten = array_filter(
  (array) (@json_decode((string) @file_get_contents($limitDatei), true) ?: []),
  fn($t) => is_int($t) && $t > time() - 3600
);
if (count($zeiten) >= LIMIT_PRO_STUNDE) {
  antwort(429, ['success' => false, 'message' => 'Es wurden gerade sehr viele Anfragen gesendet. Bitte versuch es später noch einmal oder ruf uns an: 0171 818 1435.']);
}
$zeiten[] = time();
@file_put_contents($limitDatei, json_encode(array_values($zeiten)));

$name = $istBuchung ? $werte['Name'] : $werte['name'];
$anrede = $name !== '' ? $name : 'zusammen';

// Alle Angaben fürs Team (nur die bekannten Felder, in fester Reihenfolge).
$lines = [];
foreach ($werte as $key => $val) {
  if ($val !== '') $lines[] = $labels[$key] . ': ' . $val;
}
$angaben = implode("\n", $lines);

// --- Platzabzug (nur Kursanmeldung) -----------------------------------------
// $platzFest = true  → Platz ist in der DB verbindlich reserviert.
// $platzFest = false → Fallback: DB weg/Termin unbekannt, Team bestätigt von Hand.
$platzFest = false;
$db = null;
$personen = 0;
$terminText = '';
if ($istBuchung) {
  $kursId = (string) ($_POST['kurs_id'] ?? '');
  $personen = filter_var($werte['Anzahl_Personen'], FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 14]]);
  if ($personen === false) {
    antwort(422, ['success' => false, 'message' => 'Bitte eine Personenzahl zwischen 1 und 14 angeben.']);
  }
  $terminText = $werte['Kurstermin'];

  $db = preg_match('/^[a-z0-9-]{1,64}$/', $kursId) ? kaese_db() : null;
  if ($db) {
    try {
      $db->beginTransaction();
      // Atomar: nur abziehen, wenn genug frei ist. Gleichzeitige Anmeldungen warten
      // auf die Zeilensperre und sehen danach den aktuellen Stand → keine Überbuchung.
      $upd = $db->prepare('UPDATE kurse SET gebucht = gebucht + :n
        WHERE id = :id AND datum >= CURDATE() AND gebucht + :n2 <= plaetze');
      $upd->execute([':n' => $personen, ':n2' => $personen, ':id' => $kursId]);

      if ($upd->rowCount() === 1) {
        $ins = $db->prepare('INSERT INTO buchungen (kurs_id, name, email, telefon, personen, kanal, notiz)
          VALUES (:kurs, :name, :email, :tel, :p, \'online\', :notiz)');
        $ins->execute([
          ':kurs'  => $kursId,
          ':name'  => $name !== '' ? $name : '(ohne Namen)',
          ':email' => $email,
          ':tel'   => $werte['Telefon'] ?: null,
          ':p'     => $personen,
          ':notiz' => mb_substr($werte['Nachricht'], 0, 500) ?: null,
        ]);
        // Termin für die Mails aus der Datenbank, nicht aus dem Formular.
        $k = $db->prepare('SELECT titel, datum, startzeit FROM kurse WHERE id = :id');
        $k->execute([':id' => $kursId]);
        if ($row = $k->fetch()) {
          $terminText = $row['titel'] . ' am ' . date('d.m.Y', strtotime($row['datum'])) . ', ' . $row['startzeit'] . ' Uhr';
        }
        $platzFest = true;   // Commit erst nach erfolgreicher Team-Mail (unten).
      } else {
        $db->rollBack();
        $info = $db->prepare('SELECT plaetze - gebucht AS frei FROM kurse WHERE id = :id AND datum >= CURDATE()');
        $info->execute([':id' => $kursId]);
        $row = $info->fetch();
        if ($row !== false) {
          $frei = max(0, (int) $row['frei']);
          antwort(409, [
            'success' => false,
            'frei'    => $frei,
            'message' => $frei === 0
              ? 'Dieser Termin ist leider gerade ausgebucht. Schau gern nach einem anderen Termin oder ruf uns an: 0171 818 1435.'
              : 'Für diesen Termin ' . ($frei === 1 ? 'ist leider nur noch 1 Platz' : "sind leider nur noch $frei Plätze") . ' frei. Bitte passe die Personenzahl an.',
          ]);
        }
        // Termin (noch) nicht in der DB → Fallback: Anfrage per Mail wie früher.
      }
    } catch (PDOException $e) {
      error_log('formular buchung: ' . $e->getMessage());
      if ($db->inTransaction()) $db->rollBack();
      $platzFest = false;
    }
  }
}

// --- Mailtexte ---------------------------------------------------------------

$gruss = "Herzliche Grüße\nPetra & Lutz Gräfe\nStolpner Käsemacherei · Vorwerk 10 · 01833 Stolpen";
$personenText = $personen === 1 ? '1 Person' : "$personen Personen";

// An den Absender: bewusst ohne dessen Freitexte (Nachricht, Anliegen).
if ($istBuchung && $platzFest) {
  $kSubject = 'Dein Platz im Käsekurs ist reserviert – Stolpner Käsemacherei';
  $kBody  = "Hallo $anrede,\n\n";
  $kBody .= "vielen Dank für Deine Anmeldung zum Käsekurs – Dein Platz ist fest für Dich reserviert. Wir freuen uns riesig, Dich bald am Kupferkessel zu begrüßen!\n\n";
  $kBody .= "Termin: $terminText\nTeilnehmer: $personenText\n\n";
  $kBody .= "Den Kursbeitrag (69 € pro Person) zahlst Du ganz entspannt in bar am Kurstag vor Ort. Falls Du doch nicht kommen kannst, gib uns bitte kurz Bescheid – dann rückt jemand von der Warteliste nach.\n\n";
  $kBody .= "Fragen oder Änderungen? Antworte einfach auf diese E-Mail oder ruf uns an: 0171 818 1435.\n\n";
} elseif ($istBuchung) {
  $kSubject = 'Deine Anmeldung zum Käsekurs – Stolpner Käsemacherei';
  $kBody  = "Hallo $anrede,\n\n";
  $kBody .= "vielen Dank für Deine Anmeldung zum Käsekurs – wir freuen uns riesig, Dich bald am Kupferkessel zu begrüßen!\n\n";
  $kBody .= "Termin: $terminText\nTeilnehmer: $personenText\n\n";
  $kBody .= "Wie es weitergeht:\nWir prüfen kurz die Verfügbarkeit und bestätigen Dir Deinen Platz persönlich. Den Kursbeitrag (69 € pro Person) zahlst Du ganz entspannt in bar am Kurstag vor Ort.\n\n";
  $kBody .= "Fragen oder Änderungen? Antworte einfach auf diese E-Mail oder ruf uns an: 0171 818 1435.\n\n";
} else {
  $kSubject = 'Danke für Deine Nachricht – Stolpner Käsemacherei';
  $kBody  = "Hallo $anrede,\n\n";
  $kBody .= "vielen Dank für Deine Nachricht – wir haben sie erhalten und melden uns so schnell wie möglich bei Dir, meist innerhalb von zwei Werktagen.\n\n";
  $kBody .= "Falls es dringend ist, ruf uns einfach an: 0171 818 1435.\n\n";
}
$kBody .= $gruss;

// Ans Team: alle Angaben; „Antworten" geht direkt an den Absender.
if ($istBuchung) {
  $tSubject = ($platzFest ? 'Neue Kursanmeldung (reserviert): ' : 'Neue Kursanfrage (bitte bestätigen): ')
    . ($terminText !== '' ? $terminText : 'ohne Termin') . " – $personenText";
  $tBody = ($platzFest
      ? "Der Platz ist im Buchungssystem bereits reserviert und abgezogen (siehe /admin/).\n\n"
      : "Achtung: Diese Anmeldung konnte NICHT automatisch gebucht werden (Datenbank nicht erreichbar oder Termin unbekannt). Bitte Platz prüfen und dem Kunden bestätigen.\n\n")
    . "$angaben\n";
} else {
  $tSubject = 'Neue Kontaktanfrage' . ($werte['anliegen'] !== '' ? ': ' . $werte['anliegen'] : '') . ($name !== '' ? " – $name" : '');
  $tBody = "$angaben\n";
}

// --- Versand -------------------------------------------------------------------

if ($SMTP_PASS === '') {
  if ($db && $db->inTransaction()) $db->rollBack();
  antwort(500, ['success' => false, 'message' => 'Serverkonfiguration unvollständig.']);
}

function neue_mail(string $host, int $port, string $user, string $pass, string $absender): PHPMailer {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = $host;
  $mail->SMTPAuth = true;
  $mail->Username = $user;
  $mail->Password = $pass;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail->Port = $port;
  $mail->CharSet = 'UTF-8';
  $mail->setFrom($absender, 'Stolpner Käsemacherei');
  return $mail;
}

// 1) Team-Mail zuerst: Ohne sie keine Buchung, damit nichts unbemerkt bleibt.
try {
  $team = neue_mail($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $ABSENDER);
  foreach ($TEAM as $t) $team->addAddress($t);
  $team->addReplyTo($email, $name !== '' ? $name : $email);
  $team->Subject = $tSubject;
  $team->Body = $tBody;
  $team->send();
} catch (Exception $e) {
  if ($db && $db->inTransaction()) $db->rollBack();
  error_log('formular team-mail: ' . $e->getMessage());
  antwort(500, ['success' => false, 'message' => 'Der Versand hat nicht geklappt. Bitte später erneut versuchen oder direkt anrufen.']);
}

// Team ist informiert → Buchung festschreiben.
if ($db && $db->inTransaction()) {
  try { $db->commit(); } catch (PDOException $e) { error_log('formular commit: ' . $e->getMessage()); }
}

// 2) Bestätigung an den Absender (scheitert sie, hat das Team trotzdem alle Daten).
try {
  $kunde = neue_mail($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $ABSENDER);
  $kunde->addAddress($email);
  $kunde->addReplyTo($ABSENDER, 'Stolpner Käsemacherei');
  $kunde->Subject = $kSubject;
  $kunde->Body = $kBody;
  $kunde->send();
} catch (Exception $e) {
  error_log('formular kunden-mail: ' . $e->getMessage());
}

antwort(200, ['success' => true, 'reserviert' => $platzFest]);
