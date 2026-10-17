# MCP: weitere Ausbaustufen

Stand: 24.09.2026. Phase 1 (lesende Tools, Reservierung anlegen, Token-Auth) ist umgesetzt; siehe
Release Notes 4.13.0 und die Wiki-Seite „AI assistants (MCP)“. Dieses Dokument hält fest, was als
Nächstes sinnvoll ist, was dafür umgebaut werden muss und welche Entscheidungen schon gefallen
sind. Es ist eine Planungsnotiz, kein Umsetzungsplan.

## 1. Was heute steht

- Endpunkt `/mcp`, zustandsloses und Handshake-Protokoll, nur JSON-Antworten, keine offenen Streams.
- Zugang über persönliche Tokens mit der Verwendung „KI-Assistent“ (Scope `mcp:access`), getrennt
  von REST-Tokens. Scopes wirken nie über die Rolle des Besitzers hinaus.
- Zwei Schalter: `MCP_ENABLED` (Betreiber) und der Admin-Schalter je Installation, dazu die
  erlaubten Adressen und ein eigener Schalter fürs Anlegen von Reservierungen.
- 26 Tools: Stammdaten, Reservierungen, Verfügbarkeit, Auslastungsprognose, Preisauskunft,
  Ratenkalender, Preisliste, Statistik, Monatskennzahlen, Buchungsverlauf, Umsatz, Umsatz-Prognose
  (`get_revenue_forecast`, auch in der Umsatz-Statistik, Issue #302), Kurtaxe, Rechnungen,
  Gästesuche, Betriebsbericht (Zimmerplan mit Housekeeping), Vorschau und Anlegen von Reservierungen
  inklusive Extras, Vorschau und Anlegen von Sonderpreisen, Kontoauszug-Entwürfe vorbereiten. Prompts für
  Monatsbericht, Tagesbriefing und Preis-Review.
- Absicherung: zentraler Scope-Guard (`ScopedReferenceHandler` in `src/Mcp/Security/`),
  Datenfilter für Gästedaten, Audit-Log, Ratenlimits, Vorschau-Token vor dem Schreiben,
  Benachrichtigung bei KI-Buchungen über den Workflow-Trigger `assistant_booking.created`.

## 2. Entscheidungen

- **OAuth: beobachten, nicht bauen.** Web-KIs (claude.ai, ChatGPT) bleiben vorerst außen vor.
- **Dynamische Tagespreise ergänzen klassische Preise über einen gemeinsamen Resolver.**
  Sonderzeiträume bleiben vorrangig und geschützt. Siehe [Umsetzungsplan](dynamic-pricing-plan.md).
- **Betriebsberichte waren der erste Ausbauschritt** (umgesetzt, siehe §7).

## 3. OAuth für Web-KIs (beobachten)

OIDC hilft dabei kaum: Dort ist FewohBee Client (Relying Party), für Web-KIs müsste es selbst
Tokens ausstellen, also Authorization Server sein.

Was schon da ist: Das MCP-SDK bringt die Resource-Server-Seite mit (`vendor/mcp/sdk/…/Http/OAuth/`):
Token-Prüfung per JWT und JWKS, OIDC-Discovery, Protected-Resource-Metadata (RFC 9728), ein
Interface für Dynamic Client Registration. Das Bundle verdrahtet davon nichts, es hat keine
`oauth`-Konfiguration.

Was fehlt: Authorize-Endpunkt mit Zustimmungsseite, Token-Endpunkt, Refresh-Rotation,
Client-Registrierung mit Redirect-Allowlist, Schlüsselverwaltung je Installation,
Metadata-Dokumente, Audience-Bindung.

Wege, falls es später doch gebraucht wird:
1. Eigener Server mit `league/oauth2-server-bundle`. Funktioniert auch ohne IdP; am
   Zustimmungsscreen kann man sich per Passwort, Passkey oder OIDC anmelden.
2. Fremder IdP als Authorization Server. Nur mit Dynamic Client Registration sinnvoll
   (Keycloak ja, Authelia nein), im SaaS mit zentralem IdP denkbar.
3. Abwarten: Ein AS-Ansatz im Bundle wurde im Juli 2026 zugunsten von „Resource Server plus
   Delegation“ verworfen, es gibt ein Community-Bundle (`omouren/mcp-oauth-bundle`).

**Wieder anschauen, wenn:** das Bundle OAuth-Konfiguration bekommt, oder Web-KIs konkret gebraucht
werden. Dann vorher klären: Wer darf sich verbinden, wie sieht die Zustimmungsseite aus, wie lange
leben Tokens, wie widerruft man sie.

## 4. Preise und dynamische Preisfindung

Arbeitsteilung: Das Wissen über Feste und Großereignisse bringt die KI mit. FewohBee liefert
Auslastung, Buchungsverlauf und Raten. Der Mensch bestätigt jede Änderung.

### 4.1 Lesende Tools (klein) – umgesetzt

| Tool | Basis | Bemerkung |
|---|---|---|
| `get_rate_calendar` | `Api/RateCalendarService::build()` | Existiert als REST-Endpunkt, fehlt nur als Tool |
| `get_occupancy_forecast` | `AvailabilityService::getRoomNightsPerDay()` | Gebuchte, gesperrte und freie Zimmer pro Nacht |
| `get_booking_pace` | neue Abfrage über `Reservation::reservationDate` | Buchungsstand für einen Zeitraum im Vorjahresvergleich |
| `get_price_rules` | `PriceRepository`, `Dto/Api/PriceDto` | Welche Preiszeilen gelten wann, für welche Herkunft und Kategorie |

Damit kann die KI schon beraten, ganz ohne Schreibrecht. Guter Zwischenschritt.

### 4.2 Sonderzeitraum-Preise schreiben – umgesetzt

Entscheidungen (29.09.2026): Die KI hängt einen Zeitraum an eine bestehende Sonderpreis-Zeile oder legt
eine Kopie einer Zimmerpreis-Zeile mit neuem Betrag an. Jeder Zeitraum trägt eine Bezeichnung
(`PricePeriod.description`, auch im Preisformular). Überschneidungen mit anderen Sonderzeilen werden
angezeigt und nur mit ausdrücklicher Zustimmung überschrieben (die neuen Nächte werden aus den anderen
Zeiträumen herausgeschnitten); ohne Überschneidung bleiben vorhandene Zeiträume unverändert. Noch nicht
abgerechnete Altreservierungen im Zeitraum werden weiterhin als Warnung gelistet. Neue Reservierungen
speichern seit der dynamischen Preisumsetzung ihren Preisstand; Katalogänderungen verändern ihn nicht. Scope `prices:write` nur für ROLE_ADMIN, bewusst ohne eigenen globalen Schalter (der Admin
vergibt die Berechtigung ohnehin selbst), Vorschau-Token über Anfrage und aktuellen Preisstand,
Ratenlimit, Audit. Ausnahme von AGENTS.md §7.1 („keine Einstellungen ändern“)
bewusst nur hier festgehalten, AGENTS.md bleibt unverändert.

Ursprüngliche Planung:

Sonderzeiträume sind vorhanden: `Price` trägt `pricePeriods` (Sammlung von `PricePeriod` mit
Start und Ende) und das Kennzeichen `allPeriods`, dazu Saison, Wochentage, Zimmerkategorien,
Herkünfte, Mindestaufenthalt und Personenzahl. Ein Event-Preis ist also eine zusätzliche
Preiszeile, die nur in diesem Zeitraum gilt.

Was ein Schreib-Tool braucht:
- **Sessionfreier Service** zum Anlegen und Ändern von Preiszeilen. Heute läuft das über
  `PriceService` mit `Request`-Objekten (`getPriceFromForm`), analog zu `ReservationBookingService`
  umzubauen.
- **Konfliktprüfung** ist im Repository schon vorhanden (`findConflictingPrices…`) und muss hart
  greifen, nicht nur warnen.
- **Vorschau mit Vorher-Nachher**: Raten für den Zeitraum vor und nach der Änderung über den
  Ratenkalender, dazu signiertes Vorschau-Token wie beim Buchen.
- **Absicherung**: eigener Scope `prices:write`, eigener globaler Schalter, Audit, Ratenlimit.
- Neue Buchungen besitzen unveränderliche Preisstände. Betroffene Altbuchungen werden vor dynamischer
  Veröffentlichung übernommen; Sonderzeitraumvorschauen behalten ihre Hinweise auf Altbuchungen.

Fachfragen (beantwortet, siehe oben): Darf die KI bestehende Preiszeilen ändern oder nur neue
Sonderzeiträume anlegen? Wie werden angelegte Zeilen gekennzeichnet, damit man sie wiederfindet und
zurücknehmen kann? Was passiert bei Überschneidung mit bestehenden Sonderzeiträumen?

## 5. Weitere Tools nach Aufwand

**Sofort, weil die Services schon ohne Session arbeiten**

- Ratenkalender und Preisliste (siehe oben).
- Zimmersperrungen lesen und anlegen (`RoomBlockService`).
- Kalender, Feiertage, eigene Kalendereinträge.
- Housekeeping- und Frontdesk-Listen. Stark personenbezogen, also nur mit `guests:read`.

**Kleiner Umbau**

- ~~**Betriebsberichte.**~~ Umgesetzt ohne Umbau von `OperationsReportService`: Das Tool setzt eine
  Ebene tiefer an (`HousekeepingViewService::buildRangeView()`) und liefert selbst die
  serialisierbare, gefilterte Fassung; die Zusatzschicht von `buildReportData()` (Übersetzungsschlüssel,
  formatierte Texte) dient nur den PDF-Vorlagen. Offen und klein: Rechnungs- und Online-Check-in-Status
  der Rezeptions-Checkliste. Optional: vorhandene PDF-Vorlagen rendern; Vorlagen bearbeiten darf die
  KI nie, weil Twig ohne Sandbox läuft.
- **Reservierung ändern und stornieren.** `ReservationService::updateReservation()` hängt am
  `Request`; sessionfreie Variante nötig, dann Verschieben, Personenzahl, Status.
- **Gäste anlegen und ändern.** `CustomerService::getCustomerFromForm(Request)` ebenso.

**Größerer Umbau**

- ~~**Kontoauszug und Buchungsjournal.**~~ Umgesetzt, siehe §7. Ursprüngliche Notiz: Offene Positionen eines
  Imports lagen in der Browser-Session (`BankImportDraftSession`, jetzt `BankImportDraftStore`). Für MCP müssten die Entwürfe in die Datenbank, pro Benutzer, hinter
  derselben Schnittstelle. Tools: offene Importe, offene Positionen, Buchungsgedächtnis (frühere
  Buchungen je Gegenpartei und die Import-Regeln), Kontenrahmen, Position vervollständigen. Den
  Buchungsabschluss zunächst in der Oberfläche lassen. Eigener Scope, gebunden an
  `ROLE_CASHJOURNAL`; Kontoumsätze enthalten Namen, IBANs und Verwendungszwecke, der
  Datenschutzhinweis muss das nennen.
- **Rechnungen erstellen.** Session-gebunden im Controller, dazu Nummernkreise und GoBD. Die KI
  sollte höchstens vorbereiten.
- **Kanal-Anbindung.** Eigener Plan; `ReservationBookingService` ist dafür schon der Baustein.

**Bewusst außen vor**

E-Mails und jede ausgehende Kommunikation (sonst wird die KI zum Exfiltrationskanal), Löschen,
Einstellungen ändern, Vorlagen bearbeiten, Meldebuch (Modul soll entfallen).

## 6. Querschnittsthemen bei mehr Tools

- ~~**Berechtigungen drohen unübersichtlich zu werden.**~~ Umgesetzt (30.09.2026): Das Token-Formular
  stellt drei Fragen (sehen, persönliche Daten, ändern), zeigt nicht vergebbare Rechte ausgegraut mit
  Grund, nur die Rechte der gewählten Zugangsart und eine Zusammenfassung in Klartext, die auch in den
  Zugangslisten steht. Gruppe und Zugangsart hängen am `ApiScope`-Enum, die freigeschalteten Tools
  kommen aus `McpToolCatalog` (liest `#[McpRequiresScope]`). Vorlagen bewusst nicht gebaut. Heute
  14 Scopes und zwei globale Schalter.
- **Vorschau-und-Bestätigen verallgemeinern.** `PreviewTokenSigner` ist generisch; für
  Preisänderungen und Buchhaltung wiederverwenden statt nachbauen.
- ~~**`tools/list` nach Scopes filtern.**~~ Umgesetzt mit `ScopedListToolsHandler` über den
  offiziellen Tag `mcp.request_handler` des Bundles, ohne Registry-Decorator.
- **Antwortgrößen.** Paginierung und harte Obergrenzen konsequent durchziehen.
- **Rückfragen des Servers (Elicitation).** Im neuen Protokoll möglich, Client-Unterstützung
  wechselhaft. Vorerst fragt die KI im Chat.
- **MCP Apps.** Das Bundle kann interaktive HTML-Ansichten ausliefern, etwa eine
  Ratenkalender-Heatmap. Nettes Extra.
- **Mehr Prompts.** Der Monatsbericht-Prompt war billig und hilfreich; Jahresvergleich,
  Kurtaxe-Abrechnung und Preis-Review bieten sich an.
- **Bundle-Version.** `symfony/mcp-bundle` ist experimentell (0.x) ohne BC-Versprechen. Bei Updates
  prüfen: Compiler-Pass (`src/Mcp/DependencyInjection/McpServerPass.php`), Protokollrevisionen,
  Session-Store.

## 7. Reihenfolge

1. ~~**Betriebsberichte**~~ – umgesetzt: `get_operations_report` auf Basis von
   `HousekeepingViewService::buildRangeView()`, eigener Scope `operations:read` (ROLE_OPERATIONS),
   Personal- und Gästenamen, Notizen und Sperrgründe nur mit `guests:read`. Vorhandene PDF-Vorlagen
   rendern ist offen.
2. ~~**Lesende Preis- und Auslastungs-Tools**~~ – umgesetzt: `get_rate_calendar` (Nächte mit gleichen
   Raten zu Zeiträumen zusammengefasst), `get_price_rules`, `get_occupancy_forecast`
   (`AvailabilityService::getRoomNightsPerDay()`), `get_booking_pace` (Stichtag über
   `reservationDate`; Stornos fehlen mangels Historie auch rückwirkend), Prompt `price_review`.
3. ~~**Sonderzeitraum-Preise schreiben**~~ – umgesetzt: `preview_special_price` /
   `create_special_price` über `Pricing/SpecialPriceService`, Entscheidungen siehe §4.2.
4. ~~**Kontoauszug und Buchungsjournal**~~ – umgesetzt (29.09.2026): Entwürfe pro Benutzer in der DB
   (`BankImportDraft`, gesperrte Updates, Löschung nach 2 Tagen ohne Änderung, nicht im Änderungsprotokoll),
   Zeilenlogik in `BankImportLineEditor`, Tools `list_bank_import_drafts`, `get_bank_import_lines`,
   `get_bank_import_context`, `update_bank_import_lines`; Scope `bank-import:write` (ROLE_CASHJOURNAL) schließt
   Namen und Verwendungszwecke ein, IBANs gekürzt. Buchungsgedächtnis nur über die Import-Regeln, keine
   zusätzliche Speicherung der Gegenpartei. Hochladen und Übernehmen bleiben in der Oberfläche.

OAuth bleibt beobachtet und kommt dazwischen, sobald es gebraucht wird.

## Dynamische Tagespreise – umgesetzt am 02.10.2026

`get_dynamic_pricing_context` liefert nur freigegebene Bereiche, Preisdimensionen, Tageswerte und
aggregierte Auslastung. `submit_price_recommendations` nimmt idempotente Sammelvorschläge an und
veröffentlicht grundsätzlich nicht, auch wenn die REST-Quelle eine Automatik besitzt. Scopes:
`dynamic-pricing:read` und `dynamic-pricing:recommend`; zusätzlich muss der Bereich den Token erlauben.
Die Werkzeuge ändern weder Einstellungen noch geschützte Preise und geben keine Gästekontaktdaten
oder Begründungsfreitexte aus. Vor der Buchung wird das bestätigte Preisangebot erneut geprüft.
Ratenkalender unterstützen einen Niederlassungsbezug; mehrdeutige dynamische Bereiche werden abgelehnt.
Konkrete Anbieteradapter und Portalverteilung bleiben Stufe 4 des [Plans](dynamic-pricing-plan.md).
