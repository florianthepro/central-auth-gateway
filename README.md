# Central Authentication Gateway

Zentrales Anmeldesystem für lokale Benutzer, Gruppen, Rollen, Tenants, Zielsysteme, TOTP sowie LDAP und Active Directory.

## Repository

```text
central-auth-gateway/
├── .htaccess
├── README.md
└── index.php
```

Zur Laufzeit erzeugt `index.php` den geschützten Ordner `storage/` mit SQLite-Datenbank, Icons und lokalem Verschlüsselungsschlüssel.

## Installation auf Debian 13

Der Befehl schreibt Paket- und Downloaddetails nach `/tmp/central-auth-gateway-install.log`. Im Terminal erscheinen nur kurze Statuszeilen. Vorhandene Dateien werden nur nach Eingabe von `ja` gelöscht.

```bash
cd / && sudo bash -c '
set -e
TARGET="/var/www/html/central-auth-gateway"
LOG="/tmp/central-auth-gateway-install.log"
STEP=1
ok(){ printf "[%02d] [✓] %s\n" "$STEP" "$1"; STEP=$((STEP+1)); }
fail(){ printf "[%02d] [✗] %s\n" "$STEP" "$1"; printf "Log: %s\n" "$LOG"; exit 1; }
trap '\''fail "Installation fehlgeschlagen"'\'' ERR
cd /
: > "$LOG"
apt-get update >>"$LOG" 2>&1
apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-ldap php-mbstring php-gd phpqrcode curl ca-certificates >>"$LOG" 2>&1
ok "Abhängigkeiten installiert"
if [ -d "$TARGET" ] && [ -n "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  read -rp "$TARGET enthält Daten. Vollständig zurücksetzen? [nein]: " ANSWER
  ANSWER="${ANSWER:-nein}"
  [ "$ANSWER" = "ja" ] || { printf "[%02d] [✗] Abgebrochen\n" "$STEP"; exit 1; }
  rm -rf -- "$TARGET"
  ok "Alter Stand entfernt"
fi
mkdir -p -- "$TARGET"
curl -fsSL https://github.com/florianthepro/central-auth-gateway/archive/refs/heads/main.tar.gz 2>>"$LOG" | tar -xz --strip-components=1 -C "$TARGET" >>"$LOG" 2>&1
ok "Repository installiert"
mkdir -p -- "$TARGET/storage"
chown -R www-data:www-data -- "$TARGET"
chmod -R u+rwX,g+rwX,o-rwx -- "$TARGET"
ok "Rechte gesetzt"
php -l "$TARGET/index.php" >>"$LOG" 2>&1
a2enmod rewrite headers >>"$LOG" 2>&1
apache2ctl configtest >>"$LOG" 2>&1
systemctl enable --now apache2 >>"$LOG" 2>&1
systemctl restart apache2 >>"$LOG" 2>&1
ok "Anwendung geprüft und gestartet"
'
```

Erwartete Ausgabe:

```text
[01] [✓] Abhängigkeiten installiert
[02] [✓] Alter Stand entfernt
[03] [✓] Repository installiert
[04] [✓] Rechte gesetzt
[05] [✓] Anwendung geprüft und gestartet
```

## Ersteinrichtung

Beim ersten Aufruf werden gemeinsam angelegt:

- erster Administrator,
- internes System `CAG`,
- interne Rolle `admin`,
- direkte Zuweisung des ersten Administrators.

Das interne System wird später weder in der Systemliste noch in Zuweisungsformularen angezeigt.

## Öffentliche Schnittstellen

```text
/?sys=<systemname>
```

Startet die Anmeldung für ein Zielsystem. Nach erfolgreicher Anmeldung wird ein systemspezifisches JWT erzeugt und an die fest gespeicherte Zieladresse weitergegeben.

```text
/?key=<kid>
```

Liefert den öffentlichen PEM-Schlüssel eines einzelnen Tokens, solange dieses Token gültig ist.

```text
/?jwks=1
```

Liefert alle noch gültigen öffentlichen Token-Schlüssel als JWKS.

Ohne `sys` wird kein Zielsystem-Token erzeugt. Normale Benutzer gelangen nur zu ihrem Konto. Administratoren gelangen in die kompakte Verwaltung.

## Passwortwechselpflicht

Wenn `Passwortwechsel beim ersten Login` gesetzt ist, wird der Benutzer nach Passwort und gegebenenfalls TOTP ausschließlich auf die Passwortmaske geleitet. Navigation, Icon-Upload und Token-Ausstellung bleiben gesperrt, bis das Passwort erfolgreich geändert wurde.

## TOTP-Pflicht

Ist `TOTP-Pflicht` gesetzt und noch kein TOTP hinterlegt, zeigt der erste Login:

- lokal erzeugten QR-Code,
- kopierbaren TOTP-Schlüssel,
- Eingabe des ersten sechsstelligen Codes.

Der QR-Code wird direkt in PHP mit der Debian-Bibliothek `phpqrcode` erzeugt. Es wird kein Betriebssystembefehl ausgeführt und das Secret wird nicht an einen externen Dienst gesendet.

## LDAP und Active Directory

Unter `LDAP / AD` werden folgende Angaben gespeichert:

- Name,
- URI,
- Base DN,
- Bind DN,
- verschlüsseltes Bind-Passwort,
- Benutzerfilter mit `{username}`,
- StartTLS an oder aus.

Beispiel für Active Directory:

```text
URI: ldap://dc01.example.org:389
Base DN: DC=example,DC=org
Bind DN: CN=svc-auth,OU=Service Accounts,DC=example,DC=org
Filter: (|(sAMAccountName={username})(uid={username}))
StartTLS: an
```

Für `ldaps://dc01.example.org:636` muss StartTLS ausgeschaltet sein. Das CA-Zertifikat des Domänencontrollers muss auf dem Debian-Server als vertrauenswürdig eingerichtet sein.

Nach erfolgreicher LDAP-Anmeldung wird der Benutzer lokal provisioniert. Werte aus `memberOf` werden als lokale Gruppen ergänzt. Interne Adminrechte werden niemals automatisch aus LDAP-Gruppen erteilt.

## Tokenmodell

Jedes Token:

- gilt exakt zehn Stunden,
- enthält `iat`, `created_at`, `nbf`, `exp` und `expires_at`,
- besitzt eine eigene `kid`,
- wird mit einem nur für dieses Token erzeugten RSA-Schlüsselpaar signiert.

Der private Schlüssel wird nach dem Signieren nicht gespeichert. Der öffentliche Schlüssel bleibt bis zum Ablauf des Tokens abrufbar.

## Prüfung

```bash
php -l /var/www/html/central-auth-gateway/index.php
php -m | grep -E "ldap|PDO|sqlite|sodium"
php -r 'require_once "/usr/share/phpqrcode/qrlib.php"; echo class_exists("QRcode") ? "phpqrcode aktiv\n" : "phpqrcode fehlt\n";'
sudo -u www-data test -w /var/www/html/central-auth-gateway/storage && echo "storage beschreibbar"
```
