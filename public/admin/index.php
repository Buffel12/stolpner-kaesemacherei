<?php
/**
 * Buchungsverwaltung der Stolpner Käsemacherei (/admin/) — für Petra & Lutz.
 *
 * - Übersicht aller Termine mit belegten/freien Plätzen
 * - je Termin: Buchungsliste, Telefonbuchung eintragen, stornieren,
 *   Personenzahl einer Buchung verringern, Gesamtplätze ändern
 *
 * Anmeldung mit einem gemeinsamen Passwort. Gespeichert ist nur dessen Hash
 * (`admin_hash`) in buchung-secret.php außerhalb des Web-Roots.
 * Alle Platzänderungen laufen in Transaktionen, damit `kurse.gebucht` immer der
 * Summe der bestätigten Buchungen entspricht.
 */

require __DIR__ . '/../lib/db.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

session_name('kaese_admin');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/admin/',
  'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();

const SITZUNG_MAX_SEKUNDEN = 8 * 3600;   // nach 8 h Inaktivität neu anmelden
const SPERRE_VERSUCHE      = 5;          // Fehlversuche bis zur Sperre …
const SPERRE_SEKUNDEN      = 15 * 60;    // … für 15 Minuten

// --- Helfer ------------------------------------------------------------------

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function weiter(string $ziel = ''): void {
  header('Location: /admin/' . $ziel, true, 303);
  exit;
}

function meldung(string $text, string $art = 'ok'): void { $_SESSION['meldung'] = [$art, $text]; }

function platz_wort(int $n): string { return $n === 1 ? 'Platz' : 'Plätze'; }

function datum_lang(string $ymd): string {
  static $tage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  $t = strtotime($ymd);
  return $tage[(int) date('w', $t)] . ', ' . date('d.m.Y', $t);
}

function kurs_link(string $id): string { return '?kurs=' . rawurlencode($id); }

// Einfache Sperre nach mehreren Fehlversuchen (je IP, Datei im Temp-Verzeichnis).
function sperr_datei(): string {
  return sys_get_temp_dir() . '/kaese_admin_' . md5($_SERVER['REMOTE_ADDR'] ?? '-');
}
function sperr_stand(): array {
  $d = @json_decode((string) @file_get_contents(sperr_datei()), true);
  return is_array($d) ? $d + ['n' => 0, 't' => 0] : ['n' => 0, 't' => 0];
}

// --- Sitzung & Anmeldung -----------------------------------------------------

$cfg = kaese_config();
$angemeldet = !empty($_SESSION['ok']);
if ($angemeldet && time() - ($_SESSION['zuletzt'] ?? 0) > SITZUNG_MAX_SEKUNDEN) {
  $_SESSION = [];
  session_regenerate_id(true);
  $angemeldet = false;
}
if ($angemeldet) $_SESSION['zuletzt'] = time();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$aktion = $_POST['aktion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    meldung('Die Seite war zu lange offen. Bitte noch einmal versuchen.', 'fehler');
    weiter();
  }

  if ($aktion === 'anmelden') {
    $stand = sperr_stand();
    if ($stand['n'] >= SPERRE_VERSUCHE && time() - $stand['t'] < SPERRE_SEKUNDEN) {
      meldung('Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.', 'fehler');
      weiter();
    }
    $hash = (string) ($cfg['admin_hash'] ?? '');
    if ($hash !== '' && password_verify((string) ($_POST['passwort'] ?? ''), $hash)) {
      @unlink(sperr_datei());
      session_regenerate_id(true);
      $_SESSION['ok'] = true;
      $_SESSION['zuletzt'] = time();
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
      weiter();
    }
    if (time() - $stand['t'] >= SPERRE_SEKUNDEN) $stand['n'] = 0;
    @file_put_contents(sperr_datei(), json_encode(['n' => $stand['n'] + 1, 't' => time()]));
    sleep(1);
    meldung($hash === '' ? 'Die Verwaltung ist noch nicht eingerichtet (admin_hash fehlt).' : 'Das Passwort stimmt nicht.', 'fehler');
    weiter();
  }

  if (!$angemeldet) weiter();

  if ($aktion === 'abmelden') {
    $_SESSION = [];
    session_regenerate_id(true);
    weiter();
  }
}

// --- Seitengerüst ------------------------------------------------------------

function kopf(string $titel): void { ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titel) ?> · Käsemacherei-Verwaltung</title>
<style>
  :root {
    --bg: #f6f1e7; --card: #fffdf8; --ink: #2b2118; --muted: #74675a; --line: #e4d9c6;
    --accent: #b9832a; --accent-ink: #fff; --ok: #4f6b2f; --ok-bg: #eef3e3;
    --warn: #9a6a12; --warn-bg: #fbf0d9; --err: #9b2c20; --err-bg: #fbe6e2;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .wrap { max-width: 920px; margin: 0 auto; padding: 16px; }
  header.top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0 16px; }
  header.top h1 { font-size: 20px; margin: 0; }
  header.top h1 a { color: inherit; text-decoration: none; }
  h2 { font-size: 18px; margin: 28px 0 10px; }
  a { color: var(--accent); }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
  .meldung { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-weight: 500; }
  .meldung.ok { background: var(--ok-bg); color: var(--ok); }
  .meldung.fehler { background: var(--err-bg); color: var(--err); }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { font-size: 13px; color: var(--muted); font-weight: 600; }
  tr:last-child td { border-bottom: 0; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
  .pill.frei { background: var(--ok-bg); color: var(--ok); }
  .pill.wenige { background: var(--warn-bg); color: var(--warn); }
  .pill.voll { background: #eee7dc; color: var(--muted); }
  .pill.storno { background: var(--err-bg); color: var(--err); }
  .bar { height: 6px; background: #eee7dc; border-radius: 3px; overflow: hidden; margin-top: 6px; min-width: 80px; }
  .bar span { display: block; height: 100%; background: var(--accent); }
  .klein { font-size: 13px; color: var(--muted); }
  .storniert td { color: var(--muted); text-decoration: line-through; }
  .storniert td.aktion { text-decoration: none; }
  form.inline { display: inline; }
  label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  input, textarea { width: 100%; font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--ink); }
  textarea { min-height: 70px; }
  .grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
  .grid .voll { grid-column: 1 / -1; }
  button, .btn { font: inherit; font-weight: 600; border: 0; border-radius: 8px; padding: 10px 16px; cursor: pointer; background: var(--accent); color: var(--accent-ink); text-decoration: none; display: inline-block; }
  button.leise { background: transparent; color: var(--accent); border: 1px solid var(--line); padding: 6px 10px; font-size: 14px; }
  button.gefahr { background: transparent; color: var(--err); border: 1px solid #efc9c2; padding: 6px 10px; font-size: 14px; }
  .aktion { white-space: nowrap; }
  .aktion form + form { margin-left: 4px; }
  .zurueck { display: inline-block; margin-bottom: 8px; }
  .kopfzeile { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; }
  .gross { font-size: 28px; font-weight: 700; }
  .login { max-width: 360px; margin: 12vh auto 0; }
  @media (max-width: 640px) {
    .grid { grid-template-columns: 1fr; }
    .tabelle-mobil thead { display: none; }
    .tabelle-mobil tr { display: block; padding: 10px 0; border-bottom: 1px solid var(--line); }
    .tabelle-mobil td { display: block; border: 0; padding: 2px 0; }
    .tabelle-mobil td.num { text-align: left; }
  }
</style>
</head>
<body>
<div class="wrap">
<?php }

function fuss(): void { ?>
</div>
</body>
</html>
<?php }

function meldung_zeigen(): void {
  if (empty($_SESSION['meldung'])) return;
  [$art, $text] = $_SESSION['meldung'];
  unset($_SESSION['meldung']);
  echo '<div class="meldung ' . h($art) . '" role="status">' . h($text) . '</div>';
}

function csrf_feld(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }

// --- Anmeldeseite ------------------------------------------------------------

if (!$angemeldet) {
  kopf('Anmelden'); ?>
  <div class="login">
    <h1 style="font-size:22px">Käsemacherei · Buchungen</h1>
    <?php meldung_zeigen(); ?>
    <form method="post" class="card">
      <?= csrf_feld() ?>
      <input type="hidden" name="aktion" value="anmelden">
      <label for="pw">Passwort</label>
      <input id="pw" type="password" name="passwort" autocomplete="current-password" required autofocus>
      <p style="margin:14px 0 0"><button type="submit">Anmelden</button></p>
    </form>
  </div>
  <?php fuss();
  exit;
}

// --- Aktionen (angemeldet) ---------------------------------------------------

$db = kaese_db();
if (!$db) {
  kopf('Keine Verbindung');
  echo '<div class="meldung fehler">Die Datenbank ist gerade nicht erreichbar. Bitte später noch einmal versuchen.</div>';
  fuss();
  exit;
}

try {
  kaese_aufraeumen($db);
} catch (PDOException $e) {
  error_log('admin aufraeumen: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $kursId = (string) ($_POST['kurs_id'] ?? '');
  $zurueck = $kursId !== '' ? kurs_link($kursId) : '';

  try {
    switch ($aktion) {

      case 'telefon': {
        $name = trim((string) ($_POST['name'] ?? ''));
        $personen = filter_var($_POST['personen'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($name === '' || $personen === false) {
          meldung('Bitte Name und Personenzahl angeben.', 'fehler');
          break;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
          meldung('Die E-Mail-Adresse sieht nicht gültig aus.', 'fehler');
          break;
        }
        $db->beginTransaction();
        $upd = $db->prepare('UPDATE kurse SET gebucht = gebucht + :n WHERE id = :id AND gebucht + :n2 <= plaetze');
        $upd->execute([':n' => $personen, ':n2' => $personen, ':id' => $kursId]);
        if ($upd->rowCount() !== 1) {
          $db->rollBack();
          $f = $db->prepare('SELECT plaetze - gebucht FROM kurse WHERE id = :id');
          $f->execute([':id' => $kursId]);
          $frei = (int) $f->fetchColumn();
          meldung("Nicht eingetragen: Es " . ($frei === 1 ? 'ist' : 'sind') . " nur noch $frei " . platz_wort($frei) . ' frei.', 'fehler');
          break;
        }
        $db->prepare('INSERT INTO buchungen (kurs_id, name, email, telefon, personen, kanal, notiz)
          VALUES (:kurs, :name, :email, :tel, :p, \'telefon\', :notiz)')->execute([
          ':kurs'  => $kursId,
          ':name'  => mb_substr($name, 0, 120),
          ':email' => $email !== '' ? mb_substr($email, 0, 190) : null,
          ':tel'   => mb_substr(trim((string) ($_POST['telefon'] ?? '')), 0, 50) ?: null,
          ':p'     => $personen,
          ':notiz' => mb_substr(trim((string) ($_POST['notiz'] ?? '')), 0, 500) ?: null,
        ]);
        $db->commit();
        meldung("Telefonbuchung für $name ($personen " . ($personen === 1 ? 'Person' : 'Personen') . ') eingetragen.');
        break;
      }

      case 'storno':
      case 'verringern': {
        $bid = filter_var($_POST['buchung_id'] ?? '', FILTER_VALIDATE_INT);
        $db->beginTransaction();
        $sel = $db->prepare('SELECT id, kurs_id, name, personen FROM buchungen WHERE id = :id AND status = \'bestaetigt\' FOR UPDATE');
        $sel->execute([':id' => $bid]);
        $b = $sel->fetch();
        if (!$b) {
          $db->rollBack();
          meldung('Diese Buchung gibt es nicht (mehr) oder sie ist schon storniert.', 'fehler');
          break;
        }
        $zurueck = kurs_link($b['kurs_id']);
        $alt = (int) $b['personen'];

        if ($aktion === 'storno') {
          $db->prepare('UPDATE buchungen SET status = \'storniert\' WHERE id = :id')->execute([':id' => $b['id']]);
          $weniger = $alt;
          $text = "Buchung von {$b['name']} storniert — $alt " . platz_wort($alt) . ' wieder frei.';
        } else {
          $neu = filter_var($_POST['personen_neu'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $alt - 1]]);
          if ($neu === false) {
            $db->rollBack();
            meldung('Bitte eine kleinere Personenzahl als bisher angeben (mindestens 1). Zum kompletten Absagen „Stornieren" nutzen.', 'fehler');
            break;
          }
          $db->prepare('UPDATE buchungen SET personen = :p,
              notiz = CONCAT_WS(\' · \', NULLIF(notiz, \'\'), :vermerk) WHERE id = :id')
            ->execute([':p' => $neu, ':vermerk' => 'am ' . date('d.m.Y') . " von $alt auf $neu Pers. verringert", ':id' => $b['id']]);
          $weniger = $alt - $neu;
          $text = "Buchung von {$b['name']} auf $neu " . ($neu === 1 ? 'Person' : 'Personen') . " verringert — $weniger " . platz_wort($weniger) . ' wieder frei.';
        }
        $db->prepare('UPDATE kurse SET gebucht = gebucht - :n WHERE id = :id')
          ->execute([':n' => $weniger, ':id' => $b['kurs_id']]);
        $db->commit();
        meldung($text);
        break;
      }

      case 'plaetze': {
        $plaetze = filter_var($_POST['plaetze'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if ($plaetze === false) {
          meldung('Bitte eine Platzzahl zwischen 1 und 60 angeben.', 'fehler');
          break;
        }
        $upd = $db->prepare('UPDATE kurse SET plaetze = :p WHERE id = :id AND gebucht <= :p2');
        $upd->execute([':p' => $plaetze, ':p2' => $plaetze, ':id' => $kursId]);
        if ($upd->rowCount() === 1) {
          meldung("Gesamtplätze auf $plaetze gesetzt.");
        } else {
          $g = $db->prepare('SELECT gebucht, plaetze FROM kurse WHERE id = :id');
          $g->execute([':id' => $kursId]);
          $k = $g->fetch();
          meldung($k && (int) $k['plaetze'] === $plaetze
            ? 'Die Platzzahl war schon so eingestellt.'
            : 'Geht nicht: Es sind bereits mehr Plätze gebucht. Erst Buchungen stornieren oder verringern.', $k && (int) $k['plaetze'] === $plaetze ? 'ok' : 'fehler');
        }
        break;
      }

      default:
        meldung('Unbekannte Aktion.', 'fehler');
    }
  } catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('admin ' . $aktion . ': ' . $e->getMessage());
    meldung('Das hat nicht geklappt (Datenbankfehler). Bitte noch einmal versuchen.', 'fehler');
  }
  weiter($zurueck);
}

// --- Ansichten ---------------------------------------------------------------

function status_pill(int $frei): string {
  if ($frei <= 0) return '<span class="pill voll">ausgebucht</span>';
  $klasse = $frei <= 3 ? 'wenige' : 'frei';
  return '<span class="pill ' . $klasse . '">' . $frei . ' ' . platz_wort($frei) . ' frei</span>';
}

function kopfleiste(): void { ?>
  <header class="top">
    <h1><a href="/admin/">🧀 Käsekurs-Buchungen</a></h1>
    <form method="post" class="inline">
      <?= csrf_feld() ?>
      <input type="hidden" name="aktion" value="abmelden">
      <button type="submit" class="leise">Abmelden</button>
    </form>
  </header>
<?php }

$kursId = (string) ($_GET['kurs'] ?? '');

// Detailansicht eines Termins
if ($kursId !== '') {
  $q = $db->prepare('SELECT * FROM kurse WHERE id = :id');
  $q->execute([':id' => $kursId]);
  $kurs = $q->fetch();
  if (!$kurs) weiter();

  $q = $db->prepare('SELECT * FROM buchungen WHERE kurs_id = :id
    ORDER BY status = \'storniert\', zeitpunkt');
  $q->execute([':id' => $kursId]);
  $buchungen = $q->fetchAll();

  $plaetze = (int) $kurs['plaetze'];
  $gebucht = (int) $kurs['gebucht'];
  $frei = max(0, $plaetze - $gebucht);
  $kanalText = ['online' => 'Online', 'telefon' => 'Telefon', 'altbestand' => 'Altbestand'];

  kopf(datum_lang($kurs['datum']));
  kopfleiste();
  meldung_zeigen(); ?>
  <a class="zurueck" href="/admin/">← Alle Termine</a>
  <div class="card">
    <div class="kopfzeile">
      <div>
        <div class="gross"><?= h(datum_lang($kurs['datum'])) ?></div>
        <div class="klein"><?= h($kurs['titel']) ?> · <?= h($kurs['startzeit']) ?> Uhr</div>
      </div>
      <div style="text-align:right">
        <div><strong><?= $gebucht ?></strong> von <?= $plaetze ?> Plätzen belegt</div>
        <?= status_pill($frei) ?>
      </div>
    </div>
  </div>

  <h2>Buchungen</h2>
  <div class="card">
  <?php if (!$buchungen): ?>
    <p class="klein" style="margin:0">Noch keine Buchungen.</p>
  <?php else: ?>
    <table class="tabelle-mobil">
      <thead><tr><th>Name</th><th class="num">Pers.</th><th>Kontakt</th><th>Weg / Zeitpunkt</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($buchungen as $b):
        $storniert = $b['status'] === 'storniert'; ?>
        <tr class="<?= $storniert ? 'storniert' : '' ?>">
          <td>
            <strong><?= h($b['name']) ?></strong>
            <?php if ($b['notiz']): ?><div class="klein"><?= nl2br(h($b['notiz'])) ?></div><?php endif; ?>
          </td>
          <td class="num"><?= (int) $b['personen'] ?></td>
          <td>
            <?php if ($b['telefon']): ?><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $b['telefon'])) ?>"><?= h($b['telefon']) ?></a><br><?php endif; ?>
            <?php if ($b['email']): ?><a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a><?php endif; ?>
          </td>
          <td class="klein"><?= h($kanalText[$b['kanal']] ?? $b['kanal']) ?><br><?= h(date('d.m.Y H:i', strtotime($b['zeitpunkt']))) ?></td>
          <td class="aktion">
            <?php if ($storniert): ?>
              <span class="pill storno">storniert</span>
            <?php else: ?>
              <?php if ((int) $b['personen'] > 1): ?>
              <form method="post" class="inline" data-verringern>
                <?= csrf_feld() ?>
                <input type="hidden" name="aktion" value="verringern">
                <input type="hidden" name="buchung_id" value="<?= (int) $b['id'] ?>">
                <input type="hidden" name="personen_neu" value="">
                <button type="submit" class="leise" data-alt="<?= (int) $b['personen'] ?>" data-name="<?= h($b['name']) ?>">Weniger</button>
              </form>
              <?php endif; ?>
              <form method="post" class="inline" data-storno>
                <?= csrf_feld() ?>
                <input type="hidden" name="aktion" value="storno">
                <input type="hidden" name="buchung_id" value="<?= (int) $b['id'] ?>">
                <button type="submit" class="gefahr" data-name="<?= h($b['name']) ?>" data-personen="<?= (int) $b['personen'] ?>">Stornieren</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>

  <h2>Telefonbuchung eintragen</h2>
  <form method="post" class="card">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="telefon">
    <input type="hidden" name="kurs_id" value="<?= h($kurs['id']) ?>">
    <div class="grid">
      <div><label for="t-name">Name</label><input id="t-name" name="name" required maxlength="120"></div>
      <div><label for="t-pers">Personen</label><input id="t-pers" name="personen" type="number" min="1" max="<?= max(1, $frei) ?>" value="1" required inputmode="numeric"></div>
      <div><label for="t-tel">Telefon <span class="klein">(optional)</span></label><input id="t-tel" name="telefon" type="tel" maxlength="50"></div>
      <div><label for="t-mail">E-Mail <span class="klein">(optional)</span></label><input id="t-mail" name="email" type="email" maxlength="190"></div>
      <div class="voll"><label for="t-notiz">Notiz <span class="klein">(optional)</span></label><textarea id="t-notiz" name="notiz" maxlength="500"></textarea></div>
    </div>
    <p style="margin:14px 0 0">
      <button type="submit" <?= $frei <= 0 ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>Buchung eintragen</button>
      <?php if ($frei <= 0): ?><span class="klein">&nbsp;Der Termin ist ausgebucht.</span><?php endif; ?>
    </p>
  </form>

  <h2>Gesamtplätze</h2>
  <form method="post" class="card">
    <?= csrf_feld() ?>
    <input type="hidden" name="aktion" value="plaetze">
    <input type="hidden" name="kurs_id" value="<?= h($kurs['id']) ?>">
    <div class="grid">
      <div><label for="p-anz">Plätze insgesamt</label><input id="p-anz" name="plaetze" type="number" min="<?= max(1, $gebucht) ?>" max="60" value="<?= $plaetze ?>" required inputmode="numeric"></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit">Speichern</button></p>
  </form>

  <script>
    document.querySelectorAll('form[data-storno]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        var b = f.querySelector('button');
        if (!confirm('Buchung von ' + b.dataset.name + ' (' + b.dataset.personen + ' Pers.) wirklich stornieren?')) e.preventDefault();
      });
    });
    document.querySelectorAll('form[data-verringern]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        var b = f.querySelector('button');
        var alt = parseInt(b.dataset.alt, 10);
        var neu = prompt(b.dataset.name + ' hat ' + alt + ' Personen gebucht. Wie viele kommen jetzt?', String(alt - 1));
        var n = parseInt(neu, 10);
        if (!neu || isNaN(n) || n < 1 || n >= alt) { e.preventDefault(); return; }
        f.querySelector('input[name=personen_neu]').value = String(n);
      });
    });
  </script>
  <?php fuss();
  exit;
}

// Übersicht aller Termine
$alle = isset($_GET['alle']);
$kurse = $db->query('SELECT k.*,
    (SELECT COUNT(*) FROM buchungen b WHERE b.kurs_id = k.id AND b.status = \'bestaetigt\') AS anzahl
  FROM kurse k ' . ($alle ? '' : 'WHERE k.datum >= CURDATE() ') . 'ORDER BY k.datum ' . ($alle ? 'DESC' : 'ASC'))->fetchAll();

kopf('Termine');
kopfleiste();
meldung_zeigen(); ?>
<div class="kopfzeile">
  <h2 style="margin-top:0"><?= $alle ? 'Alle Termine' : 'Kommende Termine' ?></h2>
  <a href="<?= $alle ? '/admin/' : '?alle' ?>" class="klein"><?= $alle ? 'Nur kommende zeigen' : 'Auch vergangene zeigen' ?></a>
</div>
<div class="card">
<?php if (!$kurse): ?>
  <p class="klein" style="margin:0">Keine Termine gefunden.</p>
<?php else: ?>
  <table class="tabelle-mobil">
    <thead><tr><th>Termin</th><th class="num">Belegt</th><th>Status</th><th class="num">Buchungen</th></tr></thead>
    <tbody>
    <?php foreach ($kurse as $k):
      $p = (int) $k['plaetze'];
      $g = (int) $k['gebucht']; ?>
      <tr>
        <td><a href="<?= h(kurs_link($k['id'])) ?>"><strong><?= h(datum_lang($k['datum'])) ?></strong></a><br><span class="klein"><?= h($k['startzeit']) ?> Uhr</span></td>
        <td class="num"><?= $g ?> / <?= $p ?><div class="bar"><span style="width:<?= $p > 0 ? min(100, round($g / $p * 100)) : 0 ?>%"></span></div></td>
        <td><?= status_pill($p - $g) ?></td>
        <td class="num"><a href="<?= h(kurs_link($k['id'])) ?>"><?= (int) $k['anzahl'] ?> ansehen →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<p class="klein">Neue Termine werden weiterhin von der Agentur auf der Website angelegt und erscheinen dann hier.
  Buchungsdaten werden <?= KAESE_LOESCHEN_NACH_WOCHEN ?> Wochen nach dem Kurstermin automatisch gelöscht (Datenschutz).</p>
<?php fuss();
