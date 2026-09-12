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
startzeit: "18:00"
dauerStunden: 4
preis: 69
plaetze: 14
frei: 14
---
```

## Freie Plätze pflegen (`frei`)
Du pflegst **nur eine Zahl**: `frei` = wie viele Plätze noch frei sind. Alles
andere (Badge-Text, „ausgebucht", Button) leitet sich automatisch daraus ab:

- `frei: 0`        → **Ausgebucht** (grau, „Anmelden"-Button deaktiviert)
- `frei: 1`–`3`    → **„Nur noch X Plätze frei"** (gelbe Markierung)
- `frei: 4` +      → **„X Plätze frei"** (grüne Markierung)

Kommt eine Buchung rein, einfach `frei` um die Personenzahl verringern.

> Die aktuell vorhandenen 2027er-Termine stammen von der alten Website und
> sollten aktuell gehalten werden. `README.md` selbst wird nicht als Termin
> angezeigt.
