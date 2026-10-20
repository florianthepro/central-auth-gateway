Disclaimer: This README.md was created using AI. AI was used for vision finding and implementation planning.

## Central Authentication Gateway
```text
Projektname:         Central Authentication Gateway
Repository:          central-auth-gateway
Kurzname:            CAG
Version:             1.0.0
Einstiegspunkt:      index.php
Installationsskript: install.sh
```

### Ziel

CAG ist ein zentraler, mandantenfähiger Authentifizierungsserver für interne Websysteme. Benutzer melden sich per Passwort und optional verpflichtendem TOTP an. Anschließend erzeugt CAG ein kurzlebiges, systemspezifisches und mit RS256 signiertes JWT und leitet zum fest hinterlegten Zielsystem weiter.

### Enthalten

- Benutzer mit lokalen Passwörtern, Passwortwechselpflicht und TOTP
- interne Stufen User und Admin
- Gruppen, Rollen, Tenants und Systeme
- direkte Benutzerzuweisungen und geerbte Gruppenzuweisungen
- Icons für Benutzer, Gruppen, Rollen, Tenants und Systeme
- kein Bild- oder Buchstaben-Platzhalter bei fehlendem Icon
- initialer Administrator mit internem System und direkter admin-Zuweisung
- Schutz des letzten aktiven Administrators
- RS256-JWTs mit eigenem RSA-Schlüsselpaar pro Token
- öffentlicher Einzelschlüssel und JWKS-Liste für alle noch gültigen Token-Schlüssel
- SQLite-Datenbank und Audit-Protokoll
- vorbereitete LDAP-/AD-Datenstruktur

### Installation

```bash
sudo apt update
sudo apt install -y apache2 php libapache2-mod-php php-sqlite3 php-ldap php-curl openssl curl
sudo mkdir -p /var/www/html/central-auth-gateway
curl -fsSL https://github.com/florianthepro/central-auth-gateway/archive/refs/heads/main.tar.gz | sudo tar -xz --strip-components=1 -C /var/www/html/central-auth-gateway
sudo chown -R www-data:www-data /var/www/html/central-auth-gateway
sudo chmod -R u+rwX,g+rwX,o-rwx /var/www/html/central-auth-gateway
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

Aufruf:

```text
http://localhost/central-auth-gateway/
```

Beim ersten Aufruf werden Administrator, internes System CAG, Rolle admin und direkte Zuweisung in einer Transaktion angelegt.

### Login eines Zielsystems

```text
https://auth.example.org/?sys=mein-system
```

Nach der Anmeldung:

```text
https://ziel.example.org/login/callback?token=eyJ...
```

Das Ziel wird ausschließlich aus der Systemverwaltung übernommen. Freie Redirect-URLs werden nicht akzeptiert.

### Token

Der Token enthält:

```text
iss sub aud iat nbf exp jti
system username display_name
groups roles tenants
```

Feste Laufzeit: 10 Stunden beziehungsweise 36.000 Sekunden. Jedes Token enthält `iat`, `created_at`, `exp` und `expires_at`.

Öffentliche Schlüssel:

```text
https://auth.example.org/?public_key=<kid>
https://auth.example.org/?jwks=1
```

Für jedes Token wird ein eigenes RSA-Schlüsselpaar erzeugt. Der private Schlüssel wird nur im Arbeitsspeicher zum Signieren genutzt und nicht gespeichert. Der öffentliche Schlüssel bleibt exakt bis zum Ablauf des Tokens abrufbar. Danach wird er beim nächsten Schlüsselzugriff automatisch entfernt.

Das Zielsystem muss Signatur, kid, iss, aud, exp und nbf prüfen. Lese- und Schreibrechte bleiben Aufgabe des Zielsystems.

### LDAP und Active Directory

Die Tabelle ldap_sources ist enthalten. Vor produktiver Nutzung muss die Anmeldung gegen LDAPS oder LDAP mit StartTLS ergänzt und getestet werden. Bind-Passwörter dürfen nicht im Klartext gespeichert werden. Lokale Administratoren bleiben als Notfallzugang bestehen.

### Einschränkung

Der direkte Token-Redirect ist für kontrollierte interne Systeme gedacht. Für öffentlich erreichbare oder fremde Clients sollte die Anmeldung als OpenID Connect Authorization Code Flow mit PKCE umgesetzt werden, damit Tokens nicht in URL, Browserhistorie, Proxy-Logs oder Referrer-Daten erscheinen.

### Sicherheit

- produktiv ausschließlich HTTPS
- Argon2id, sofern von PHP unterstützt
- CSRF-Schutz
- HttpOnly-, Secure- und SameSite-Cookies
- Login-Rate-Limit
- TOTP-Replay-Schutz
- eigenes RSA-Schlüsselpaar mit 2048 Bit pro Token
- private Token-Schlüssel werden nicht persistiert
- öffentliche Schlüssel werden bis zum Tokenablauf vorgehalten
- feste Redirect-Ziele
- Token-Laufzeit 10 Stunden
- letzter Administrator geschützt
- Audit-Protokoll
- storage/ durch Apache gesperrt

### Repository

```text
central-auth-gateway/
├── .htaccess
├── README.md
├── index.php
├── install.sh
└── storage/
    ├── auth.sqlite
    └── icons/
```

storage/ wird zur Laufzeit erzeugt.
