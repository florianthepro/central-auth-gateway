# Central Authentication Gateway

Kompakter zentraler Auth-Server für lokale Benutzer, LDAP und Active Directory.

## Installation

```bash
sudo bash install.sh
```

## Schnittstellen

```text
/?sys=<systemname>         Anmeldung für ein Zielsystem
/?key=<kid>                öffentlicher PEM-Schlüssel eines Tokens
/?jwks=1                   alle noch gültigen öffentlichen Schlüssel
```

Ohne `sys` erfolgt nur die Anmeldung in die eigene Konto- oder Adminoberfläche. Das interne CAG-System wird weder gelistet noch als Ziel auswählbar angezeigt.

## Benutzerablauf

- normale Anmeldung auf einer Seite
- bei TOTP-Pflicht wird beim ersten Login sofort QR-Code oder kopierbarer Schlüssel angezeigt
- Bestätigung des ersten TOTP-Codes ist zwingend
- Passwortwechselpflicht führt in die kompakte Kontoseite
- Benutzer können ausschließlich ihr eigenes Icon und lokales Passwort ändern

## Administration

Die Benutzeranlage enthält nur Benutzername, Passwort sowie Checkboxen für Admin, TOTP-Pflicht und Passwortwechsel beim ersten Login. Passwort zurücksetzen erzeugt ein einmalig angezeigtes, kopierbares Initialpasswort.

## LDAP / AD

Konfigurierbar sind URI, Base DN, Bind DN, verschlüsseltes Bind-Passwort, Benutzerfilter und StartTLS. Erfolgreiche LDAP-Benutzer werden lokal provisioniert; `memberOf`-Werte werden als lokale Gruppen übernommen.

Beispiel:

```text
URI: ldap://dc01.example.org:389
Base DN: DC=example,DC=org
Filter: (|(sAMAccountName={username})(uid={username}))
StartTLS: an
```

Bei `ldaps://...:636` StartTLS ausschalten. Das CA-Zertifikat des Verzeichnisservers muss systemweit vertrauenswürdig sein.

## Tokens

Jedes Token gilt 10 Stunden und enthält `iat`, `created_at`, `exp` und `expires_at`. Pro Token entsteht ein eigenes RSA-Schlüsselpaar. Der private Schlüssel wird nicht gespeichert; der öffentliche Schlüssel bleibt bis Tokenablauf verfügbar.
