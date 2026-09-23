<?php
/**
 * Mail-Handler der Stolpner Käsemacherei — Versand per authentifiziertem SMTP
 * über das Postfach info@stolpner-kaesemacherei.de (DKIM/SPF-konform).
 * Sendet eine freundliche Bestätigung an den Kunden, Kopie (Bcc) ans Team.
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
// Live eine Ebene über public_html, Staging (Subdomain-Ordner) zwei Ebenen darüber.
$secret = null;
foreach ([dirname(__DIR__), dirname(__DIR__, 2)] as $dir) {
  if (is_file($dir . '/formular-secret.php')) { $secret = include $dir . '/formular-secret.php'; break; }
}
$SMTP_PASS = is_array($secret) ? ($secret['smtp_pass'] ?? '') : '';

$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;                                   // 465 = SSL
$SMTP_USER = 'info@stolpner-kaesemacherei.de';      // Postfach = Absender
$ABSENDER  = 'info@stolpner-kaesemacherei.de';
$KOPIE     = ['info@unitednet-design.com', 'stolpnerkaesemacherei@gmail.com']; // Bcc ans Team
// Staging (kaesepetra.unitednet-design.com): Kopien nur an die Agentur, damit
// Testbuchungen nicht bei Petra & Lutz landen.
if (stripos($_SERVER['HTTP_HOST'] ?? '', 'unitednet-design.com') !== false) {
  $KOPIE = ['info@unitednet-design.com'];
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit;
}

// Honeypot: von Bots ausgefüllt -> still "erfolgreich" tun und verwerfen.
if (!empty($_POST['botcheck'])) {
  echo json_encode(['success' => true]);
  exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
  exit;
}

$istBuchung = (($_POST['formular'] ?? '') === 'kursanmeldung');
$name = trim((string) ($_POST['Name'] ?? ($_POST['name'] ?? '')));
$anrede = $name !== '' ? $name : 'zusammen';

// Übermittelte Angaben sammeln (technische Felder ausklammern), sinnvoll sortiert.
$ignore = ['botcheck', 'formular', 'kurs_id', 'access_key', 'from_name', 'replyto', 'ccemail', 'subject', 'datenschutz'];
$labels = [
  'email' => 'E-Mail', 'name' => 'Name', 'Name' => 'Name',
  'message' => 'Nachricht', 'Nachricht' => 'Nachricht',
  'telefon' => 'Telefon', 'Telefon' => 'Telefon', 'anliegen' => 'Anliegen',
  'Anzahl_Personen' => 'Anzahl Personen', 'Kurstermin' => 'Kurstermin',
];
$order = ['Kurstermin', 'Name', 'Anzahl_Personen', 'email', 'Telefon', 'anliegen', 'Nachricht', 'message'];
$lines = [];
$seen = [];
$emit = function ($key) use (&$lines, &$seen, $labels, $ignore) {
  if (isset($seen[$key]) || in_array($key, $ignore, true) || !isset($_POST[$key])) return;
  $val = $_POST[$key];
  if (is_array($val)) $val = implode(', ', $val);
  $val = trim((string) $val);
  if ($val === '') return;
  $seen[$key] = true;
  $lines[] = ($labels[$key] ?? $key) . ': ' . $val;
};
foreach ($order as $k) $emit($k);
foreach (array_keys($_POST) as $k) $emit($k);
$angaben = implode("\n", $lines);

// --- Platzabzug (nur Kursanmeldung) -----------------------------------------
// $platzFest = true  → Platz ist in der DB verbindlich reserviert.
// $platzFest = false → Fallback: DB weg/Termin unbekannt, Team bestätigt von Hand.
$platzFest = false;
$db = null;
if ($istBuchung) {
  $kursId = (string) ($_POST['kurs_id'] ?? '');
  $personen = filter_var($_POST['Anzahl_Personen'] ?? '', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 14]]);
  if ($personen === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Bitte eine Personenzahl zwischen 1 und 14 angeben.']);
    exit;
  }

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
          ':name'  => mb_substr($name !== '' ? $name : '(ohne Namen)', 0, 120),
          ':email' => mb_substr($email, 0, 190),
          ':tel'   => mb_substr(trim((string) ($_POST['Telefon'] ?? '')), 0, 50) ?: null,
          ':p'     => $personen,
          ':notiz' => mb_substr(trim((string) ($_POST['Nachricht'] ?? '')), 0, 500) ?: null,
        ]);
        $platzFest = true;   // Commit erst nach erfolgreichem Mailversand (unten).
      } else {
        $db->rollBack();
        $info = $db->prepare('SELECT plaetze - gebucht AS frei FROM kurse WHERE id = :id AND datum >= CURDATE()');
        $info->execute([':id' => $kursId]);
        $row = $info->fetch();
        if ($row !== false) {
          $frei = max(0, (int) $row['frei']);
          http_response_code(409);
          echo json_encode([
            'success' => false,
            'frei'    => $frei,
            'message' => $frei === 0
              ? 'Dieser Termin ist leider gerade ausgebucht. Schau gern nach einem anderen Termin oder ruf uns an: 0171 818 1435.'
              : 'Für diesen Termin ' . ($frei === 1 ? 'ist leider nur noch 1 Platz' : "sind leider nur noch $frei Plätze") . ' frei. Bitte passe die Personenzahl an.',
          ]);
          exit;
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

if ($istBuchung && $platzFest) {
  $subject = 'Dein Platz im Käsekurs ist reserviert – Stolpner Käsemacherei';
  $body  = "Hallo $anrede,\n\n";
  $body .= "vielen Dank für Deine Anmeldung zum Käsekurs – Dein Platz ist fest für Dich reserviert. Wir freuen uns riesig, Dich bald am Kupferkessel zu begrüßen!\n\n";
  $body .= "Das haben wir notiert:\n$angaben\n\n";
  $body .= "Den Kursbeitrag (69 € pro Person) zahlst Du ganz entspannt in bar am Kurstag vor Ort. Falls Du doch nicht kommen kannst, gib uns bitte kurz Bescheid – dann rückt jemand von der Warteliste nach.\n\n";
  $body .= "Fragen oder Änderungen? Antworte einfach auf diese E-Mail oder ruf uns an: 0171 818 1435.\n\n";
} elseif ($istBuchung) {
  $subject = 'Deine Anmeldung zum Käsekurs – Stolpner Käsemacherei';
  $body  = "Hallo $anrede,\n\n";
  $body .= "vielen Dank für Deine Anmeldung zum Käsekurs – wir freuen uns riesig, Dich bald am Kupferkessel zu begrüßen!\n\n";
  $body .= "Das haben wir notiert:\n$angaben\n\n";
  $body .= "Wie es weitergeht:\nWir prüfen kurz die Verfügbarkeit und bestätigen Dir Deinen Platz persönlich. Den Kursbeitrag (69 € pro Person) zahlst Du ganz entspannt in bar am Kurstag vor Ort.\n\n";
  $body .= "Fragen oder Änderungen? Antworte einfach auf diese E-Mail oder ruf uns an: 0171 818 1435.\n\n";
} else {
  $subject = 'Danke für Deine Nachricht – Stolpner Käsemacherei';
  $body  = "Hallo $anrede,\n\n";
  $body .= "vielen Dank für Deine Nachricht – wir haben sie erhalten und melden uns so schnell wie möglich bei Dir, meist innerhalb von zwei Werktagen.\n\n";
  $body .= "Deine Angaben:\n$angaben\n\n";
  $body .= "Falls es dringend ist, ruf uns einfach an: 0171 818 1435.\n\n";
}
$body .= "Herzliche Grüße\nPetra & Lutz Gräfe\nStolpner Käsemacherei · Vorwerk 10 · 01833 Stolpen";

if ($SMTP_PASS === '') {
  if ($db && $db->inTransaction()) $db->rollBack();
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Serverkonfiguration unvollständig.']);
  exit;
}

$mail = new PHPMailer(true);
try {
  $mail->isSMTP();
  $mail->Host = $SMTP_HOST;
  $mail->SMTPAuth = true;
  $mail->Username = $SMTP_USER;
  $mail->Password = $SMTP_PASS;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail->Port = $SMTP_PORT;
  $mail->CharSet = 'UTF-8';

  $mail->setFrom($ABSENDER, 'Stolpner Käsemacherei');
  $mail->addReplyTo($ABSENDER, 'Stolpner Käsemacherei');
  $mail->addAddress($email);
  foreach ($KOPIE as $k) {
    $mail->addBCC($k);
  }

  $mail->Subject = $subject;
  $mail->Body = $body;

  $mail->send();
  // Mail ist raus → Buchung festschreiben. (Ohne Mail keine Buchung, damit
  // Kunde, Team-Postfach und Datenbank nie auseinanderlaufen.)
  if ($db && $db->inTransaction()) {
    try { $db->commit(); } catch (PDOException $e) { error_log('formular commit: ' . $e->getMessage()); }
  }
  echo json_encode(['success' => true, 'reserviert' => $platzFest]);
} catch (Exception $e) {
  if ($db && $db->inTransaction()) $db->rollBack();
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Der Versand hat nicht geklappt. Bitte später erneut versuchen oder direkt anrufen.']);
}
