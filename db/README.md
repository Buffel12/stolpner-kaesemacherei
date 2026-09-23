# Buchungssystem — Datenbank

MySQL auf Hostinger (Account `u261902578`), Zugriff aus PHP über `public/lib/db.php`.

| Umgebung | Datenbank / User          | Zugangsdatei (außerhalb des Web-Roots)                              |
|----------|---------------------------|---------------------------------------------------------------------|
| Staging  | `u261902578_kaesestg`     | `/home/u261902578/domains/unitednet-design.com/buchung-secret.php`  |
| Live     | `u261902578_kaese`        | `/home/u261902578/domains/stolpner-kaesemacherei.de/buchung-secret.php` |

Vorlage für die Zugangsdatei: `buchung-secret.example.php`. Die echte Datei wird
einmalig per Hostinger-Dateimanager hochgeladen und NIE committet.

## Einrichten / aktualisieren

1. `schema.sql` in phpMyAdmin ausführen (idempotent).
2. `npm run db:seed-sql > seed.sql` und in phpMyAdmin ausführen.
   - Neue Termine werden mit `gebucht = plaetze − frei` angelegt.
   - Bestehende Termine: nur Titel/Datum/Uhrzeit werden aktualisiert; Plätze und
     Buchungen bleiben (gepflegt über /admin/, `frei:` in der .md nur noch Fallback).

Invariante: `kurse.gebucht` = Summe `personen` aller bestätigten `buchungen` des Kurses.
Plätze aus der Zeit vor dem System stehen als Buchung mit `kanal = 'altbestand'`.
Freie Plätze immer als `plaetze - gebucht` berechnen; Abzug nur atomar über
`UPDATE … SET gebucht = gebucht + :n WHERE id = :kurs AND gebucht + :n <= plaetze`
(nicht `plaetze - gebucht >= :n` — Spalten sind UNSIGNED).

## Betrieb

- Verwaltung für Petra & Lutz: `/admin/` (gemeinsames Passwort, Hash als `admin_hash`
  in der Zugangsdatei).
- Löschkonzept: Buchungen 8 Wochen nach dem Kurstermin löschen — bei jedem Aufruf von
  `/admin/` und per Hostinger-Cron täglich 03:15:
  `/usr/bin/php /home/u261902578/domains/stolpner-kaesemacherei.de/public_html/lib/aufraeumen.php`
- Neuer Kurstermin: .md in `src/content/kurse/` anlegen, `npm run db:seed-sql` in
  phpMyAdmin der Live-DB ausführen, dann bauen und deployen.
