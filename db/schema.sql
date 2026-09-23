-- Buchungssystem der Stolpner Käsemacherei — Datenbankschema (MySQL/MariaDB).
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
--
-- kurse     … ein Eintrag je Kurstermin. id = Dateiname der .md in src/content/kurse/.
--             frei wird NIE gespeichert, sondern immer als plaetze - gebucht berechnet.
-- buchungen … Protokoll jeder Anmeldung (online, telefonisch oder Altbestand aus der
--             Zeit vor dem System). Invariante: gebucht = Summe der Personen aller
--             bestätigten Buchungen des Kurses.

CREATE TABLE IF NOT EXISTS kurse (
  id           VARCHAR(64)       NOT NULL,
  datum        DATE              NOT NULL,
  startzeit    VARCHAR(5)        NOT NULL DEFAULT '10:00',
  titel        VARCHAR(120)      NOT NULL,
  plaetze      SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  gebucht      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  aktualisiert TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_datum (datum),
  CONSTRAINT chk_nicht_ueberbucht CHECK (gebucht <= plaetze)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS buchungen (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  kurs_id    VARCHAR(64)      NOT NULL,
  name       VARCHAR(120)     NOT NULL,
  email      VARCHAR(190)     NULL,
  telefon    VARCHAR(50)      NULL,
  personen   TINYINT UNSIGNED NOT NULL,
  kanal      ENUM('online','telefon','altbestand') NOT NULL DEFAULT 'online',
  status     ENUM('bestaetigt','storniert')        NOT NULL DEFAULT 'bestaetigt',
  notiz      VARCHAR(500)     NULL,
  zeitpunkt  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kurs (kurs_id),
  KEY idx_zeitpunkt (zeitpunkt),
  CONSTRAINT fk_buchung_kurs FOREIGN KEY (kurs_id) REFERENCES kurse (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_personen CHECK (personen >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
