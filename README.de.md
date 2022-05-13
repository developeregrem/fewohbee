
# Pensionsverwaltung FewohBee

Die Hotelsoftware für kleine bis mittelgroße Pensionen und Hotels - Open Source und kostenlos.

Das Pensionsverwaltungtool, oder auch Property Management System (PMS) im allgemeinen genannt, ist ein PHP-Projekt, das auf dem PHP-Framework Symfony basiert.
Kleine Pensionen oder Unterkünfte verwalten ihre Zimmer oder Appartements in der Regel auf die alte Art und Weise mit einem Stift und einem Blatt Papier oder mit einem Tabellenverwaltungsprogramm. 

Das Ziel dieses Open-Source-Tools ist es, kleineren Unterkünften zu helfen, den handgeschriebenen Ansatz zur Raumverwaltung zu ersetzen und die Produktivität durch das Zusammenführen aller Informationen zu verbessern, was schließlich in einer Zeitersparnis bei der Verwaltung des Gästehauses oder Pension resultiert.

*Für eine ausführliche Dokumentation nutzen sie bitte das [Wiki](https://github.com/developeregrem/fewohbee/wiki).*

## Funktionen

 - Reservierungsübersicht (einfache Möglichkeit, Reservierungen hinzuzufügen und zu verwalten)
 - Verwaltung Ihrer Gästedaten (inkl. DSGVO-Exportfunktion)
 - umfangreiche Einstellungen zur Verwaltung der
	 - Zimmer, Unterkünfte, Preise, Reservierungsherkunft, Vorlagen, etc.
 - Rechnungen erstellen (PDF)
 - Gästekommunikation (Mails aus dem Tool heraus schreiben), Rechnungen, Reservierungsbestätigungen oder andere relevante Informationen an den Gast senden.
 - Statistiken
 - Meldebuch
 - Kassenbuch zur Verwaltung Ihrer Einnahmen und Ausgaben

## Anforderungen

Um das Tool nutzen zu können, benötigt man einen kleinen Webserver, der die Anforderungen von Symfony [requirements](https://symfony.com/doc/current/setup.html#technical-requirements) erfüllt:

 - PHP 8.0.2 oder höher
 - php-intl extension
 - einen Webserver z.B. nginx oder apache
 - einen Datenbankserver (empfohlen wird mysql oder mariadb)

## Quick Start

> Es wird empfohlen das docker-compose Setup zu verwenden: [fewohbee-dockerized](https://github.com/developeregrem/fewohbee-dockerized)

Erstellen einer Datenbank für das Tool:

    CREATE DATABASE fewohbee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

 Kopiere die Datei `.env.dist` und benenne die kopierte Datei in `.env` um.

Bearbeite die Datei `.env` und passe den Wert für `DATABASE_URL` an, um den eigenen Datenbankeinstellungen zu entsprechen.

Erzeuge einen zufällig und sicheren Wert für `APP_SECRET` (man kann einen Wert [hier](http://nux.net/secret) erzeugen lassen).

Wenn noch nicht vorhanden, lade den PHP dependency manager [composer](https://getcomposer.org/download/) herunter, um die Pensionsverwaltungstool Abhängigkeiten installieren zu können. Führe anschließend den folgenden Befehl im root-Ordner des Projekts aus:

    composer install

Führe den folgenden Befehl aus, um die Datenbank und die Anwendung zu initialisieren:

    php bin/console doctrine:migration:migrate
    php bin/console app:first-run

    // optional: Testdaten hinzufügen
    php bin/console doctrine:fixtures:load --append

Anschließend kann mit einem Webbrowser zu dem Installationsordner gewechselt werden  z.B.
http://localhost/fewohbee/public/index.php
um sich mit den zuvor angelegten Logindaten anzumelden.

## i18n

Das Tool ist grundlegend mehrsprachige aufgebaut und ist derzeit in deutsch und englisch verfügbar. Wenn eine neue Sprache unterstützt werden soll, bitte ein Ticket anlegen.

## Author

Alexander Elchlepp

Das Projekt wird durch mich seit 2014 in der Freizeit entwickelt. Wenn Fragen aufkommen, kann ein Ticket angelegt oder mich direkt per mail kontaktiert werden (info (at) fewohbee.de)

Wenn Sie dieses Projekt unterstützen wollen und Sie denken, dass das Projekt für Sie nützlich ist, würde ich mich über eine kleine Spende sehr freuen :)
[![Spenden](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate/?hosted_button_id=ZQPG864PB4TBE)
