# Central Authentication Gateway

Zentrale Anmeldung für lokale Benutzer, Gruppen, Rollen, Tenants, Zielsysteme sowie Active Directory und LDAP.

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/central-auth-gateway/main/install.sh | sudo bash
```

Ausführliches Installationslog:

```text
/tmp/central-auth-gateway-install.log
```

## Schnittstellen

```text
/?sys=<systemname>
/?key=<kid>
/?jwks=1
```

## Modell

- Benutzer und Gruppen können ohne Tenant bestehen.
- Eine Gruppe kann global oder einem Tenant zugeordnet sein.
- Die Adminansicht kann nach Tenant und Gruppe gefiltert werden.
- Eine Zuweisung verbindet Benutzer oder Gruppe mit System, Rolle und optionalem Tenant.
- Das interne System wird nicht in der Adminansicht angezeigt.
- Tokens gelten zehn Stunden und besitzen je Token ein eigenes RSA-Schlüsselpaar.
