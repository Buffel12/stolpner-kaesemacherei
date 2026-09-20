<?php
/**
 * Mail-Handler für Kontakt- und Buchungsformular der Stolpner Käsemacherei.
 * Läuft auf dem Hostinger-Server, liefert die Formulardaten per E-Mail zu.
 *
 * Empfänger hier zentral pflegen (später auf stolpner + gmail umstellen):
 */
$EMPFAENGER = ['info@unitednet-design.com'];
// Später z. B.: ['info@stolpner-kaesemacherei.de', 'stolpnerkaesemacherei@gmail.com'];

$ABSENDER = 'no-reply@unitednet-design.com'; // Absender der Formular-Mails (Zustellung bestätigt)

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

// Header-Injection verhindern
function clean_header($s) {
  return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], '', (string) $s));
}

$email = clean_header($_POST['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
  exit;
}

$typ = (($_POST['formular'] ?? '') === 'kursanmeldung') ? 'Kursanmeldung' : 'Kontaktanfrage';
$subject = 'Neue ' . $typ . ' über die Website';

// Technische Felder nicht in den Text übernehmen
$ignore = ['botcheck', 'formular', 'access_key', 'from_name', 'replyto', 'ccemail', 'subject', 'datenschutz'];
$labels = [
  'email'   => 'E-Mail',
  'name'    => 'Name',
  'Name'    => 'Name',
  'message' => 'Nachricht',
  'Nachricht' => 'Nachricht',
  'telefon' => 'Telefon',
  'Telefon' => 'Telefon',
  'anliegen' => 'Anliegen',
];

$lines = [];
foreach ($_POST as $key => $val) {
  if (in_array($key, $ignore, true)) continue;
  if (is_array($val)) $val = implode(', ', $val);
  $val = trim((string) $val);
  if ($val === '') continue;
  $label = $labels[$key] ?? $key;
  $lines[] = $label . ': ' . $val;
}

$body  = 'Neue ' . $typ . " über stolpner-kaesemacherei.de\n\n";
$body .= implode("\n", $lines) . "\n";

$headers = [];
$headers[] = 'From: Stolpner Käsemacherei (Website) <' . $ABSENDER . '>';
$headers[] = 'Reply-To: ' . $email;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

$encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$to = implode(', ', $EMPFAENGER);

$ok = @mail($to, $encSubject, $body, implode("\r\n", $headers));

if ($ok) {
  echo json_encode(['success' => true]);
} else {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Der Versand hat nicht geklappt. Bitte später erneut versuchen oder direkt anrufen.']);
}
