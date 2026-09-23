<?php
/**
 * Mail-Handler für Kontakt- und Buchungsformular der Stolpner Käsemacherei.
 * Sendet eine freundliche Bestätigung an den Kunden – in Kopie (Bcc) ans Team,
 * inklusive aller übermittelten Angaben.
 */
$ABSENDER = 'info@stolpner-kaesemacherei.de'; // Absender & Antwort-Adresse (echtes Postfach)
$KOPIE    = ['info@unitednet-design.com', 'stolpnerkaesemacherei@gmail.com']; // Team-Kopien (Bcc)

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

function clean_header($s) {
  return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], '', (string) $s));
}

$email = clean_header($_POST['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
  exit;
}

$istBuchung = (($_POST['formular'] ?? '') === 'kursanmeldung');
$name = clean_header($_POST['Name'] ?? ($_POST['name'] ?? ''));
$anrede = $name !== '' ? $name : 'zusammen';

// Übermittelte Angaben sammeln (technische Felder ausklammern), in sinnvoller Reihenfolge.
$ignore = ['botcheck', 'formular', 'access_key', 'from_name', 'replyto', 'ccemail', 'subject', 'datenschutz'];
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

if ($istBuchung) {
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

$headers = [];
$headers[] = 'From: Stolpner Käsemacherei <' . $ABSENDER . '>';
$headers[] = 'Reply-To: ' . $ABSENDER;
if (!empty($KOPIE)) $headers[] = 'Bcc: ' . implode(', ', $KOPIE);
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

$ok = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

if ($ok) {
  echo json_encode(['success' => true]);
} else {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Der Versand hat nicht geklappt. Bitte später erneut versuchen oder direkt anrufen.']);
}
