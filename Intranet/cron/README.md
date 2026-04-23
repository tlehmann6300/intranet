# IBC Intranet — Cronjobs

Dieses Verzeichnis enthält alle automatisierten Hintergrundprozesse, die das
Intranet zur Wartung und für regelmässige Aufgaben benötigt.

Aktuell sind **zwei** Cronjobs zwingend zu konfigurieren:

| Skript                              | Zweck                                  | Empfohlener Takt       |
|-------------------------------------|----------------------------------------|------------------------|
| `send_birthday_wishes.php`          | Geburtstagsmails an Mitglieder         | täglich 07:00 Uhr      |
| `refresh_easyverein_token.php`      | EasyVerein-API-Token automatisch erneuern | täglich 03:30 Uhr   |

Weitere Skripte (z. B. `sync_easyverein.php`, `process_mail_queue.php`,
`backup_database.php`) sind optional und werden hier am Ende kurz aufgelistet.

---

## 0. Voraussetzungen (einmalig einrichten)

Damit beide Cronjobs funktionieren, müssen die folgenden Werte in der
`.env`-Datei (Wurzelverzeichnis des Intranets) gesetzt sein:

```dotenv
# Geheimer Token für HTTP-Trigger (mind. 32 Zeichen, hex/random)
CRON_TOKEN=<RANDOM_STRING_MIN_32_CHARS>

# Datenbankzugang (wird ohnehin vom Intranet benötigt)
DB_HOST=…
DB_USER=…
DB_PASS=…
DB_CONTENT_NAME=…
DB_USER_NAME=…

# SMTP-Daten für ausgehende E-Mails (für die Geburtstagsmails)
SMTP_HOST=…
SMTP_PORT=587
SMTP_USER=…
SMTP_PASS=…
SMTP_FROM_EMAIL=intranet@business-consulting.de
SMTP_FROM_NAME=IBC Intranet

# Initialer EasyVerein-API-Token
# Nach dem ersten erfolgreichen Refresh wird der Token in der DB-Tabelle
# `system_settings` (key: easyverein_api_token) abgelegt und dort fortlaufend
# rotiert. Die .env dient nur als Erst-Bootstrap.
EASYVEREIN_API_TOKEN=<INITIAL_TOKEN>
```

Den **CRON_TOKEN** generierst Du z. B. mit:

```bash
openssl rand -hex 32
```

Den initialen **EASYVEREIN_API_TOKEN** holst Du Dir im EasyVerein-Backend unter
*Einstellungen → API-Schnittstellen → API-Schlüssel*. Wichtig: dem Token müssen
die Module *Adressen (Lesen)*, *Inventar (Lesen & Schreiben)*, *Ausleihen
(Lesen & Schreiben)* und *Individuelle Felder (Lesen & Schreiben)* zugewiesen
sein, sonst meldet die API später einen 403.

---

## 1. Cronjob: Geburtstagsmails

**Datei:** `cron/send_birthday_wishes.php`

Sucht alle aktiven Mitglieder, deren Geburtstag (Tag + Monat) auf den heutigen
Tag fällt, und versendet eine personalisierte Glückwunschmail. Das Geschlecht
des Mitglieds steuert die Anrede; fehlt es, wird neutral angeschrieben.

### Was muss konfiguriert sein?

* `SMTP_*` in der `.env` (siehe oben) — sonst landen die Mails nicht im
  Postausgang.
* In jedem User-Profil müssen **Geburtsdatum** und **E-Mail** gepflegt sein. Wer
  kein Geburtstag im Profil hat, bekommt keine Mail.
* Die Mailvorlage liegt in `assets/mail_vorlage/` und kann dort angepasst
  werden.

### Crontab-Eintrag (Linux-Server, klassische Cron)

```crontab
0 7 * * * /usr/bin/php /var/www/intra/cron/send_birthday_wishes.php >> /var/www/intra/logs/birthday.log 2>&1
```

### Alternative: HTTP-Trigger (z. B. cron-job.org)

Wenn der Webspace keine Shell-Cron unterstützt (typisch bei Shared Hosting),
ruft ein externer Scheduler die URL auf:

```
GET https://intra.business-consulting.de/cron/send_birthday_wishes.php?token=<CRON_TOKEN>
```

Der `token`-Query-Parameter MUSS exakt mit `CRON_TOKEN` aus der `.env`
übereinstimmen, sonst antwortet der Server mit `403 Forbidden`.

### Manuell testen

```bash
php /var/www/intra/cron/send_birthday_wishes.php
```

Ausgabe sollte enthalten: *"Found N user(s) with birthday today"* und für
jeden Empfänger ein *"Email sent successfully"*. Bei `0 user(s)` an einem
Tag ohne Geburtstag ist alles OK.

---

## 2. Cronjob: EasyVerein-API-Token erneuern

**Datei:** `cron/refresh_easyverein_token.php`

EasyVerein-Tokens haben ein **rollendes Ablaufdatum**: wird der Token zu lange
nicht benutzt oder über die Lebensdauer hinaus eingesetzt, wirft die API ab dem
nächsten Aufruf einen 401. Damit das Inventar-Modul (Ausleihen, Items …) und
alle anderen EasyVerein-Funktionen ohne manuellen Eingriff weiterlaufen,
rotiert dieses Skript den Token jede Nacht.

Das Skript

1. liest den aktuellen Token aus `system_settings.easyverein_api_token`
   (falls vorhanden) bzw. aus `EASYVEREIN_API_TOKEN` in der `.env`,
2. ruft `GET https://easyverein.com/api/v3.0/refresh-token` mit dem
   aktuellen Bearer Token,
3. speichert den neuen Token in `system_settings`. Schlägt das DB-Schreiben
   fehl, wird ersatzweise die `.env` aktualisiert.

### Was muss konfiguriert sein?

* Initialer `EASYVEREIN_API_TOKEN` in der `.env` (siehe Abschnitt 0). **Nur einmalig**
  notwendig — danach übernimmt der Cron die Rotation.
* DB-Zugang muss funktionieren (Tabelle `system_settings` wird vom Skript
  bei Bedarf automatisch angelegt).
* Falls die `.env` als Fallback genutzt werden soll, muss die Datei für den
  Webserver-User schreibbar sein.

### Crontab-Eintrag

```crontab
30 3 * * * /usr/bin/php /var/www/intra/cron/refresh_easyverein_token.php >> /var/www/intra/logs/easyverein_token.log 2>&1
```

### HTTP-Trigger

```
GET https://intra.business-consulting.de/cron/refresh_easyverein_token.php?token=<CRON_TOKEN>
```

### Exit-Codes

| Code | Bedeutung                                                       |
|------|-----------------------------------------------------------------|
| 0    | Token erfolgreich erneuert und persistiert.                     |
| 1    | Kein initialer Token konfiguriert.                              |
| 2    | EasyVerein-API antwortete mit Fehler (Netzwerk oder HTTP ≥ 400).|
| 3    | API-Antwort enthielt kein `token`-Feld.                         |
| 4    | Persistierung in DB **und** `.env` fehlgeschlagen.              |

### Manuell testen

```bash
php /var/www/intra/cron/refresh_easyverein_token.php
```

Erwartete Ausgabe:

```
=== EasyVerein Token Refresh ===
Started at: 2026-04-18 03:30:00

Token source: system_settings DB
Token erfolgreich abgerufen (40 Zeichen, rotiert).
Token in system_settings gespeichert.

Fertig: 2026-04-18 03:30:01
```

---

## 3. Logs & Monitoring

* Empfohlen: ein Verzeichnis `logs/` neben dem Intranet-Root anlegen, in das
  beide Crons ihre Stdout/Stderr-Ausgabe via `>> … 2>&1` schreiben.
* Alle Cron-Läufe werden zusätzlich im Intranet unter
  *Admin → Statistiken → System-Log* mit `action = cron_*` mitgeschrieben.

---

## 4. Optionale Skripte

| Skript                          | Zweck                                              | Takt           |
|---------------------------------|----------------------------------------------------|----------------|
| `sync_easyverein.php`           | Inventar von EasyVerein in die lokale DB syncen   | alle 30 Min.   |
| `process_mail_queue.php`        | Abarbeiten der ausgehenden Mailqueue              | alle 5 Min.    |
| `backup_database.php`           | Tagesbackup der MySQL-Datenbank                   | nachts 01:00   |
| `reconcile_bank_payments.php`   | Bankzahlungen mit Rechnungen abgleichen           | stündlich      |
| `send_alumni_reminders.php`     | Erinnerungen für Alumni-Aktivitäten               | wöchentlich    |
| `send_profile_reminders.php`    | "Profil vervollständigen"-Mails                   | wöchentlich    |

Alle laufen mit demselben Pattern: CLI-Aufruf direkt oder HTTP-Aufruf mit
`?token=<CRON_TOKEN>`.

---

## 5. Troubleshooting

* **`403 Forbidden`** beim HTTP-Aufruf → Der Query-Parameter `token` stimmt
  nicht mit `CRON_TOKEN` aus der `.env` überein, oder `CRON_TOKEN` ist kürzer
  als 16 Zeichen.
* **`CRON_TOKEN not configured securely`** → `.env` enthält keinen oder einen
  zu kurzen `CRON_TOKEN`. Mit `openssl rand -hex 32` neu erzeugen.
* **EasyVerein 401 trotz aktiviertem Refresh-Cron** → der initiale Token in
  der `.env` ist bereits abgelaufen. Im EasyVerein-Backend einen neuen Token
  anlegen, in `.env` eintragen, einmal `php cron/refresh_easyverein_token.php`
  ausführen, danach übernimmt die DB-basierte Rotation.
* **Geburtstagsmails kommen nicht an** → `php cron/send_birthday_wishes.php`
  manuell ausführen und Output prüfen. Häufige Ursachen: SMTP-Credentials
  falsch, Mail-Provider blockiert die Absenderadresse, Empfänger-Postfach
  voll.
