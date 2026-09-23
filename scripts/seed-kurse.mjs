// seed-kurse.mjs — erzeugt SQL, das die Kurstermine aus src/content/kurse/*.md
// in die Tabelle `kurse` überträgt. Aufruf: npm run db:seed-sql > seed.sql
//
// Regeln (idempotent, mehrfach ausführbar):
// - Neuer Termin → wird angelegt mit gebucht = plaetze − frei (Stand der .md).
// - Bestehender Termin → nur titel/datum/startzeit werden aktualisiert;
//   `plaetze` und `gebucht` bleiben unangetastet (gepflegt über /admin/).
// - Bereits belegte Plätze aus der Zeit vor dem System werden einmalig als
//   Buchung mit kanal = 'altbestand' protokolliert.
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = fileURLToPath(new URL('../src/content/kurse/', import.meta.url));

function frontmatter(text) {
  const m = text.match(/^---\n([\s\S]*?)\n---/);
  if (!m) return {};
  const data = {};
  for (const line of m[1].split('\n')) {
    const kv = line.match(/^(\w+):\s*(.*)$/);
    if (!kv) continue;
    let v = kv[2].trim().replace(/^["']|["']$/g, '');
    data[kv[1]] = /^\d+$/.test(v) ? Number(v) : v;
  }
  return data;
}

const sql = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;

const kurse = readdirSync(DIR)
  .filter((f) => f.endsWith('.md') && f !== 'README.md')
  .sort()
  .map((f) => {
    const d = frontmatter(readFileSync(join(DIR, f), 'utf8'));
    const plaetze = d.plaetze ?? 14;
    const frei = Math.min(Math.max(d.frei ?? plaetze, 0), plaetze);
    return {
      id: f.replace(/\.md$/, ''),
      datum: d.datum,
      startzeit: d.startzeit ?? '10:00',
      titel: d.titel ?? 'Käsekurs am Kessel',
      plaetze,
      gebucht: plaetze - frei,
    };
  });

const werte = kurse.map((k) =>
  `(${sql(k.id)}, ${sql(k.datum)}, ${sql(k.startzeit)}, ${sql(k.titel)}, ${k.plaetze}, ${k.gebucht})`);

console.log([
  '-- Automatisch erzeugt von scripts/seed-kurse.mjs — nicht von Hand bearbeiten.',
  'SET NAMES utf8mb4;',
  'START TRANSACTION;',
  `INSERT INTO kurse (id, datum, startzeit, titel, plaetze, gebucht) VALUES ${werte.join(', ')} ` +
    'ON DUPLICATE KEY UPDATE datum = VALUES(datum), startzeit = VALUES(startzeit), ' +
    'titel = VALUES(titel);',
  "INSERT INTO buchungen (kurs_id, name, personen, kanal, notiz) " +
    "SELECT k.id, 'Altbestand', k.gebucht, 'altbestand', 'Plätze, die vor Einführung des Buchungssystems vergeben wurden' " +
    'FROM kurse k WHERE k.gebucht > 0 AND NOT EXISTS (SELECT 1 FROM buchungen b WHERE b.kurs_id = k.id);',
  'COMMIT;',
].join('\n'));
