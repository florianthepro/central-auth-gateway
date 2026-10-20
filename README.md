### Central Authentication Gateway

```text
Projektname:    Central Authentication Gateway
Repository:     central-auth-gateway
Kürzel:         CAG
Version:        1.0.0
Einstiegspunkt: index.php
```

### Zielbild

CAG ist ein zentraler, mandantenfähiger Authentifizierungsserver für interne und externe Websysteme. Benutzer melden sich einmal mit Passwort und optional verpflichtendem TOTP an. Nach erfolgreicher Anmeldung erzeugt CAG ein kurzlebiges, systemspezifisches und mit RS256 signiertes Token und leitet den Benutzer zum hinterlegten Zielsystem weiter.

Die Oberfläche und die Login-Maske orientieren sich am dunklen, reduzierten Erscheinungsbild von Network Asset Documentation Audit.

### Grundmodell

Benutzer
├── Passwort
├── TOTP/MFA
├── Passwortwechsel bei erster Anmeldung
├── verpflichtende MFA-Einrichtung
└── ausschließlich durch Administratoren pflegbares Benutzer-Icon

Zuweisungen
├── Benutzer
├── Gruppe, optional
├── Rolle
├── Tenant
└── System

Systeme
├── eindeutiger Systemname
├── verpflichtende Ziel-URL
├── optionales Icon
└── systemspezifische Benutzer-, Gruppen-, Rollen- und Tenant-Claims

### Interne Rollen

CAG kennt intern nur zwei Verwaltungsstufen:

User
└── kann das eigene Passwort und die eigene MFA-Konfiguration ändern

Admin
├── Benutzer verwalten
├── Gruppen verwalten
├── Rollen verwalten
├── Tenants verwalten
├── Systeme verwalten
├── Icons verwalten
└── Zuweisungen verwalten

Fachliche Rollen eines Zielsystems sind davon getrennt. Eine Rolle mit dem Namen Admin in einem Zielsystem macht einen Benutzer nicht automatisch zum CAG-Administrator.

### Ersteinrichtung

Beim ersten Aufruf werden gemeinsam angelegt:

1. der erste Administrator,
2. das interne System CAG mit eigener Webadresse,
3. die Rolle admin,
4. eine direkte Einzelzuweisung des ersten Administrators zum internen System und zur Rolle admin.

Der erste Administrator darf später gelöscht werden, sobald mindestens ein anderer interner Administrator vorhanden ist. Der letzte interne Administrator kann weder gelöscht noch zum User herabgestuft werden.

### Login und Weiterleitung

Direkter Login:

https://auth.example.org/

Login für ein Zielsystem:

https://auth.example.org/?sys=mein-system

Nach erfolgreicher Anmeldung erzeugt CAG ein signiertes Token und leitet weiter:

https://ziel.example.org/login/callback?token=eyJ...

Ein Systemname aus dem Request wird ausschließlich serverseitig gegen die hinterlegten Systeme aufgelöst. Freie Redirect-URLs werden nicht akzeptiert.

### Token

Der Token ist ein JWT mit RS256-Signatur. Beispielhafte Claims:

iss: https://auth.example.org
sub: eindeutige Benutzer-ID
aud: mein-system
iat: Ausstellungszeit
nbf: frühester Nutzungszeitpunkt
exp: Ablaufzeit
jti: eindeutige Token-ID
system: mein-system
username: florian
groups: systemspezifische Gruppen
roles: systemspezifische Rollen
tenants: systemspezifische Tenants

Das Zielsystem verwaltet selbst:

- Token-Lebensdauer innerhalb seiner akzeptierten Obergrenze,
- Lese- und Schreibrechte,
- Auswertung der übermittelten Gruppen, Rollen und Tenants,
- lokale Sitzungen,
- Widerruf oder zusätzliche Richtlinien.

CAG bestätigt nur die Identität und liefert die für das angefragte System geltenden Zuweisungen. CAG entscheidet nicht über Fachrechte des Zielsystems.

### Öffentlicher Schlüssel

Die öffentlichen Schlüssel sind maschinenlesbar erreichbar:

https://auth.example.org/?jwks=1

Token enthalten eine kid-Kennung. Bei einer Schlüsselrotation bleibt der vorherige öffentliche Schlüssel während einer Übergangsfrist verfügbar. Private Schlüssel liegen ausschließlich außerhalb des öffentlich erreichbaren Webbereichs beziehungsweise in einem gesperrten Storage-Verzeichnis.

### MFA

CAG unterstützt TOTP mit 30-Sekunden-Zeitfenster und sechs Ziffern. Administratoren können bei einem Benutzer festlegen:

- TOTP optional,
- TOTP verpflichtend,
- TOTP bei der ersten Anmeldung einzurichten,
- vorhandene MFA-Konfiguration zurückzusetzen.

Der Benutzer kann im eigenen Profil Passwort und MFA ändern. Das Benutzer-Icon kann nur durch einen Administrator geändert werden.

### LDAP und Active Directory

CAG unterstützt eine optionale LDAP-Anbindung. Microsoft Active Directory wird über LDAP beziehungsweise LDAPS angebunden. Vorgesehen sind:

- Server-URI,
- Base DN,
- Bind DN,
- Bind-Passwort,
- Benutzerfilter,
- StartTLS oder LDAPS,
- Zuordnung externer Gruppen zu lokalen Gruppen, Rollen, Tenants und Systemen.

Lokale Notfalladministratoren bleiben unabhängig vom Verzeichnisdienst möglich. Ein Ausfall von LDAP oder AD darf den letzten lokalen Administrator nicht aussperren.

### Icons

Für Benutzer, Gruppen, Rollen, Tenants und Systeme kann ein PNG- oder JPG-Icon hinterlegt werden. Ohne Icon wird kein leeres Bild, kein Buchstaben-Platzhalter und kein defektes Bildelement ausgegeben. Der für Text vorgesehene Platz wird vollständig genutzt.

### Datenmodell

storage/
├── auth.sqlite
├── audit.jsonl
├── keys/
│   ├── active-private.pem
│   ├── active-public.pem
│   └── previous-public.pem
├── icons/
│   ├── users/
│   ├── groups/
│   ├── roles/
│   ├── tenants/
│   └── systems/
└── config.php

Zentrale Tabellen:

users
groups
roles
tenants
systems
user_groups
assignments
ldap_sources
signing_keys
audit_events

Eine Assignment-Zeile verbindet Benutzer oder Gruppe mit System, Rolle und optional Tenant. Direkte Benutzerzuweisungen und geerbte Gruppenzuweisungen werden beim Tokenaufbau zusammengeführt und dedupliziert.

### Start

Voraussetzungen:

PHP 8.2 oder neuer
PDO SQLite
OpenSSL
PHP LDAP für LDAP/AD
HTTPS im produktiven Betrieb
Schreibzugriff auf storage/

Repository bereitstellen:

```bash
git clone https://github.com/florianthepro/central-auth-gateway.git
cd central-auth-gateway
php -S 0.0.0.0:8080
```

Danach öffnen:

http://localhost:8080

Beim ersten Aufruf wird die Ersteinrichtung angezeigt.

### Apache unter Debian und Ubuntu

```bash
sudo apt update
sudo apt install -y apache2 php libapache2-mod-php php-sqlite3 php-ldap php-curl openssl
sudo mkdir -p /var/www/html/central-auth-gateway
curl -fsSL https://github.com/florianthepro/central-auth-gateway/archive/refs/heads/main.tar.gz | sudo tar -xz --strip-components=1 -C /var/www/html/central-auth-gateway
sudo chown -R www-data:www-data /var/www/html/central-auth-gateway
sudo chmod -R u+rwX,g+rwX,o-rwx /var/www/html/central-auth-gateway
sudo systemctl enable --now apache2
```

Aufruf:

http://localhost/central-auth-gateway/

### Sicherheit

- ausschließlich HTTPS im produktiven Betrieb,
- Argon2id für lokale Passwörter, sofern in PHP verfügbar,
- CSRF-Schutz für alle schreibenden Formulare,
- rotierende Session-ID nach Anmeldung,
- HttpOnly-, Secure- und SameSite-Cookies,
- Login-Rate-Limit pro Benutzer und IP,
- TOTP-Replay-Schutz,
- RS256 statt gemeinsamem HMAC-Geheimnis,
- kid-basierte Schlüsselrotation,
- feste Redirect-Ziele aus der Systemverwaltung,
- kurze Token-Laufzeit, standardmäßig 120 Sekunden,
- Prüfung von iss, aud, exp, nbf und Signatur im Zielsystem,
- serverseitige Prüfung aller Verwaltungsrechte,
- Audit-Protokoll für Login, MFA, Benutzer-, System- und Rechteänderungen,
- letzter Administrator ist gegen Löschen und Herabstufung geschützt,
- Storage, Datenbank, Schlüssel und Icons dürfen nicht direkt als Verzeichnis ausgeliefert werden,
- LDAP ausschließlich über StartTLS oder LDAPS,
- Bind-Passwörter nicht in der Datenbank im Klartext speichern.

### Abgrenzung

CAG ist Identitätsanbieter und Claim-Aussteller. Das Zielsystem bleibt für Autorisierung, Fachrechte, Schreib- und Leseregeln sowie seine lokale Sitzung verantwortlich.

Für standardisierte Drittanbieter-Anbindungen sollte die Schnittstelle als OpenID Connect Authorization Code Flow mit PKCE ausgebaut werden. Der beschriebene direkte Token-Redirect ist für kontrollierte interne Systeme gedacht und muss vor produktivem Einsatz einem Sicherheitsreview unterzogen werden.

### Repository

central-auth-gateway/
├── index.php
├── README.md
└── storage/
    └── wird zur Laufzeit erzeugt

### Leitlinie

Eine zentrale Anmeldung.
Getrennte interne und fachliche Rollen.
Systemspezifische Claims.
Keine freien Redirects.
Kurzlebige signierte Tokens.
Zielsysteme entscheiden über Fachrechte.
Kein vollständiger Admin-Lockout.
LDAP und AD optional, lokale Notfalladministration bleibt erhalten.
