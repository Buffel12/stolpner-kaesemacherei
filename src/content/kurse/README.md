# Kurstermine pflegen

Jeder Kurstermin ist **eine Datei** in diesem Ordner. Die Kurs-Seite (`/kurs`)
baut die Terminliste automatisch daraus. **Vergangene Termine verschwinden von
selbst** — nichts zu löschen.

## Neuen Termin anlegen
Neue Datei nach dem Muster `JAHR-MONAT-TAG-kaesekurs.md` (z. B.
`2027-03-14-kaesekurs.md`) mit folgendem Inhalt:

```
---
titel: Käsekurs am Kessel
datum: 2027-03-14
startzeit: "10:00"
dauerStunden: 4
preis: 69
plaetze: 14
status: frei
---
```

## Freie Plätze steuern (`status`)
- `frei`       → normal buchbar (grüne Markierung)
- `wenige`     → nur noch wenige Plätze frei (gelbe Markierung)
- `ausgebucht` → nicht mehr buchbar, „Anmelden"-Button ist deaktiviert

Wird ein Kurs voll, einfach `status:` auf `ausgebucht` ändern.

> Die drei aktuell vorhandenen Dateien sind **Beispieltermine** und sollten
> durch echte Termine ersetzt werden. `README.md` selbst wird nicht als Termin
> angezeigt.
