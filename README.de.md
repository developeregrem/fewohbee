# FewohBee — Pensionsverwaltung & Hotelsoftware

**Die Open-Source-Buchungs- und Verwaltungssoftware für kleine und mittlere Pensionen,
Ferienwohnungen, Gästehäuser und Hotels.**

[![Lizenz: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000.svg)](https://symfony.com/)

*English version: [README.md](README.md) · Dokumentation: [Wiki](https://github.com/developeregrem/fewohbee/wiki) · Website: [fewohbee.app](https://fewohbee.app)*

---

Kleinere Unterkünfte verwalten ihre Zimmer in der Regel noch auf die alte Art — mit Stift und
Papier oder einer Tabellenkalkulation. Genau daraus ist dieses Projekt 2014 entstanden, und
mittlerweile ist ein vollständiges Property Management System (PMS) daraus geworden:
Reservierungen, Gästedaten, Rechnungen, Buchhaltung, Korrespondenz und Kalender-Sync mit den
Buchungsportalen — alles an einem Ort und auf dem eigenen Server.

Entwickelt **von Hoteliers für Hoteliers** — geprägt vom Alltag in einer echten Pension und nicht
von einer Feature-Liste. Themen wie doppelte Buchführung oder E-Rechnung lassen sich heute nicht
mehr umgehen, deshalb ist das Ziel, sie so zu verpacken, dass man sie ohne Fachausbildung bedienen
kann.

- 🔓 **Kostenlos und quelloffen** (GPL-3.0) — selbst hosten, forken, anpassen
- 🇩🇪 🇬🇧 **Zweisprachig** — vollständig auf Deutsch und Englisch verfügbar
- 🐳 **Docker-ready** — in wenigen Minuten einsatzbereit
- 🧾 **E-Rechnung integriert** — EN 16931, XRechnung, ZUGFeRD
- 🔐 **Moderne Anmeldung** — Passwort, Passkeys (WebAuthn), API-Token mit Scopes

Neugierig, wie das aussieht? Auf [fewohbee.app](https://fewohbee.app) gibt es eine Feature-Tour,
und das [Wiki](https://github.com/developeregrem/fewohbee/wiki) erklärt alles im Detail.

---

## Funktionen

| | |
|---|---|
| 🛏️ **Reservierungen** | Kalenderraster, Verfügbarkeits- und Kapazitätsprüfung, Zimmersperrungen, konfigurierbare Herkünfte und Status |
| 👥 **Gäste** | Gästeprofile, Firmen, Gästekategorien für altersabhängige Preise, DSGVO-Export |
| 🌐 **Online-Buchung** | Direktbuchung über die eigene Website — Suche oder Kalendermodus, einbettbar, mit Missbrauchsschutz |
| 🧾 **Rechnungen** | PDF-Rechnungen, konfigurierbare Nummernkreise und E-Rechnung nach EN 16931 / XRechnung / ZUGFeRD |
| 📒 **Buchhaltung** | Doppelte Buchführung mit geführtem Kontenrahmen, DATEV-Export, CSV-Bankimport mit automatischem Rechnungsabgleich, Kassenbuch |
| 📋 **Betrieb** | Rezeptionsansicht, Housekeeping-Listen, druckfertige Tagesberichte |
| ⚡ **Automatisierung** | Regelwerk aus Auslösern, Bedingungen und Aktionen — Mails versenden, Status ändern, Buchungen anlegen, ganz ohne Code |
| ✉️ **Korrespondenz** | Mailversand aus der Anwendung, mit visuellem Vorlagen-Editor und Live-Vorschau |
| 📅 **Kalender-Sync** | iCal/ICS in beide Richtungen mit Airbnb, Booking.com und anderen |
| 📊 **Statistiken** | Belegung und Auslastung, Monats-Snapshots, Kurtaxe-Auswertung |
| ⚙️ **Verwaltung** | Mehrere Betriebsstätten, feingranulare Rollen, Passkeys, lesende REST-API |

📖 **[Vollständige Funktionsübersicht im Wiki](https://github.com/developeregrem/fewohbee/wiki#features)**
 · **[Benutzerhandbuch](https://www.fewohbee.app/documentation/documentation.html)**

---

## Anforderungen

- **PHP 8.4 oder höher** (das offizielle Docker-Image läuft auf PHP 8.5)
  - Erweiterungen: `intl` (mit vollständigen ICU-Daten), `gd`, `pdo_mysql`, `exif`, `ctype`,
    `iconv`
- Ein Webserver — nginx oder Apache
- **MySQL 8.0+** oder **MariaDB** — `DB_SERVER_VERSION` in der `.env` passend zum Server setzen
- [Composer](https://getcomposer.org/download/)

Optional:

- **Redis** — für Cache und Sessions (`USE_REDIS_CACHE=true`). Nicht zwingend nötig; für eine
  einzelne Instanz reicht der Dateisystem-Cache.
- **HTTPS und eine konfigurierte `RELYING_PARTY_ID`** — Voraussetzung für die Anmeldung per
  Passkey (`PASSKEY_ENABLED=true`).
- **S3-kompatibler Speicher** — alternativ zur lokalen Dateiablage (`STORAGE_ADAPTER`).

Die allgemeine Grundlage beschreiben die
[technischen Anforderungen von Symfony](https://symfony.com/doc/current/setup.html#technical-requirements).

---

## Schnellstart

### Variante A: Docker (empfohlen)

Ein vorkonfiguriertes Docker-Compose-Setup wird separat gepflegt:

👉 **[fewohbee-dockerized](https://github.com/developeregrem/fewohbee-dockerized)**

Es bringt Anwendung, Webserver und Datenbank mit und ist der schnellste Weg zu einer laufenden
Installation. Die Anleitung dazu steht im
[Docker-Setup-Guide](https://github.com/developeregrem/fewohbee/wiki/Docker-Setup) im Wiki.

### Variante B: Manuelle Installation

**1. Datenbank anlegen**

```sql
CREATE DATABASE fewohbee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**2. Anwendung konfigurieren**

Die Datei `.env.dist` nach `.env` kopieren und anpassen:

- `DATABASE_URL` — die eigenen Datenbank-Zugangsdaten
- `APP_SECRET` — ein zufälliger Wert, z.B. `php -r 'echo bin2hex(random_bytes(16));'`
- `APP_ENV=prod` für Produktivinstallationen

**3. Abhängigkeiten installieren**

```bash
composer install --no-dev --optimize-autoloader
```

**4. Datenbank und Anwendung initialisieren**

```bash
php bin/console doctrine:migrations:migrate
php bin/console asset-map:compile
php bin/console app:first-run
```

`app:first-run` führt durch das Anlegen des ersten Administrators und der eigenen Unterkunft.
Mit `--load-sample-data` werden zusätzlich Beispieldaten zum Ausprobieren angelegt.

**5. Anmelden**

Das Document-Root des Webservers auf `public/` zeigen lassen, die Anwendung im Browser öffnen und
mit dem soeben angelegten Benutzer anmelden.

### Nicht vergessen: geplante Aufgaben

Kalender-Sync und zeitbasierte Automatisierungen funktionieren nur, wenn ihre Kommandos regelmäßig
laufen. Das Docker-Setup bringt dafür einen eigenen Cron-Container mit; bei einer manuellen
Installation gehört Folgendes in die Crontab:

```bash
# Kalender-Synchronisierung mit den Buchungsportalen (beide Richtungen)
*/15 * * * * cd /pfad/zu/fewohbee && php bin/console calendar:import:sync --force
*/15 * * * * cd /pfad/zu/fewohbee && php bin/console calendars:sync

# Zeitbasierte Workflows (Erinnerungen, Nachfassen, geplante Mails)
*/15 * * * * cd /pfad/zu/fewohbee && php bin/console workflow:process-scheduled

# Aufbewahrung von Logs
0 3 * * * cd /pfad/zu/fewohbee && php bin/console app:purge-logs --days=90
```

Die Referenz-Zeitplanung steht in [`docker/app/crontab`](docker/app/crontab) — ein Blick dorthin
lohnt sich, um mit den Standardwerten gleichzuziehen.

Das Statistikmodul kann außerdem monatliche Snapshots für den Vorjahresvergleich vorhalten. Wer das
nutzt, plant es einmal im Monat ein:

```bash
5 0 1 * * cd /pfad/zu/fewohbee && php bin/console stats:snapshot:month
```

---

## Aktualisierung

**Vor jedem Update ein Datenbank-Backup anlegen** und einen Blick in die
[Release Notes](https://github.com/developeregrem/fewohbee/releases) werfen — dort stehen
versionsspezifische Hinweise.

### Docker

Das Setup [fewohbee-dockerized](https://github.com/developeregrem/fewohbee-dockerized) bringt ein
Update-Skript mit, das die ganze Arbeit übernimmt:

```bash
./update-docker.sh
```

Es lädt die neuen Images, startet den Stack neu und übernimmt neu hinzugekommene
Umgebungsvariablen automatisch in die `.env` und beide Compose-Dateien. Diese neuen Variablen
danach kurz durchsehen — manche brauchen noch einen passenden Wert.

### Manuelle Installation

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate
php bin/console asset-map:compile
php bin/console cache:clear
```

---

## REST-API

FewohBee stellt eine **lesende REST-API** für Reservierungen, Kalenderdaten, Rechnungen und
Statistiken bereit.

- Die Authentifizierung erfolgt über **persönliche Zugriffstoken** aus dem Benutzerprofil (Präfix
  `fwb_`), übergeben als `Authorization: Bearer <token>` oder — für Clients, die nur
  Benutzername/Passwort unterstützen (z.B. Kalenderanwendungen) — als Passwort per HTTP Basic Auth.
- Jedes Token trägt **Scopes**, und ein Scope greift nur, wenn der Token-Inhaber auch die
  zugehörige Anwendungsrolle besitzt — ein Token kann also nie mehr erlauben als sein Inhaber darf.
- Die vollständige Spezifikation liegt in [`docs/openapi.yaml`](docs/openapi.yaml).

📖 **Anleitung und Beispiele:
[REST-API-Dokumentation im Wiki](https://github.com/developeregrem/fewohbee/wiki/REST-API)**

---

## Gehostete Version

Nicht jeder möchte einen eigenen Server betreiben, ihn aktuell halten und sich um Backups kümmern.
Für diesen Fall gibt es unter **[fewohbee.app](https://fewohbee.app)** eine gehostete Variante —
dieselbe Anwendung, betrieben und gewartet, deren Erlöse die Entwicklung dieses Open-Source-Projekts
finanzieren.

Selbst hosten bleibt kostenlos und vollwertig unterstützt. Beide Wege nutzen dieselbe Codebasis.

---

## Mehrsprachigkeit

FewohBee ist von Grund auf mehrsprachig angelegt und wird **vollständig auf Deutsch und Englisch**
ausgeliefert. Beide Sprachen werden gemeinsam gepflegt — kein Feature erscheint nur in einer
Sprache.

Eine weitere Sprache gewünscht? Gerne ein
[Issue anlegen](https://github.com/developeregrem/fewohbee/issues) — Beiträge sind sehr willkommen.

---

## Mitwirken

Beiträge sind willkommen — ob Fehlermeldung, Übersetzung, Dokumentation oder neues Feature.

- 📋 **Zuerst [AGENTS.md](AGENTS.md) lesen.** Dort stehen Architektur, Sicherheitsanforderungen,
  Regeln zur Mehrsprachigkeit, Test-Erwartungen und Coding-Standards des Projekts. Die Datei ist
  für KI-Coding-Agents geschrieben, gilt aber genauso als verbindlicher Leitfaden für Menschen.
- 🐛 **Fehler und Ideen:** [Issue anlegen](https://github.com/developeregrem/fewohbee/issues)
- 🔀 **Pull Requests:** möglichst fokussiert halten, Tests beilegen und darauf achten, dass die
  deutschen *und* englischen Übersetzungen vollständig sind

**Tests ausführen:**

```bash
php bin/phpunit tests/Unit    # Unit-Tests — schnell, ohne Datenbank
bin/run-tests.sh              # gesamte Suite — setzt die Testdatenbank zurück
vendor/bin/phpstan analyse    # statische Analyse, Level 6
```

---

## Sicherheit

Wer eine Sicherheitslücke entdeckt, sollte **kein öffentliches Issue anlegen**, sondern sie
vertraulich an **info (at) fewohbee.app** melden, damit sie vor der Veröffentlichung behoben werden
kann.

Sicherheit hat in diesem Projekt hohe Priorität — Gästedaten sind personenbezogene Daten im Sinne
der DSGVO, Rechnungen sind Finanzunterlagen. Welche Standards für jeden Beitrag gelten, steht im
Sicherheitsabschnitt der [AGENTS.md](AGENTS.md).

---

## Lizenz

FewohBee steht unter der **[GNU General Public License v3.0](LICENSE)**.

Die Software darf frei genutzt, untersucht, verändert und weitergegeben werden. Wird eine
veränderte Fassung weitergegeben, muss sie ebenfalls unter der GPL-3.0 stehen und der Quellcode
verfügbar gemacht werden.

---

## Autor & Unterstützung

Entwickelt von **Alexander Elchlepp** seit 2014.

Das Projekt entsteht größtenteils in Einzelarbeit und in der Freizeit. Über Fragen, Rückmeldungen
und Beiträge freue ich mich jederzeit — einfach ein
[Issue anlegen](https://github.com/developeregrem/fewohbee/issues) oder eine Mail an
info (at) fewohbee.app.

Wenn dir FewohBee nützt und du die Entwicklung unterstützen möchtest:

[![Spenden](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate?hosted_button_id=ZQPG864PB4TBE)

⭐ Ein Stern für das Repository hilft ebenfalls, damit andere das Projekt finden.
