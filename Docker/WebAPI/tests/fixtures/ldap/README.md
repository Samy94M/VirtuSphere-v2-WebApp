# LDAP-Hermetik-Fixtures

Wegwerf-Testzertifikate fuer den hermetischen LDAPS-Fixture-Stack
(`Docker/ldap-fixture`, `Docker/qa/docker-compose.qa.yml`). Gleiche Konvention
wie `tests/fixtures/https/`: `.txt`-Endung, damit kein Deploy-Tooling sie als
echtes Material einsammelt, `.key.txt` in `.gitleaks.toml` allowlisted.

Jede Datei isoliert genau eine gebrochene TLS-Eigenschaft gegenueber der
Basislinie (`root-a` = vertrauenswuerdig), damit ein Testfall nie zwei
Fehlerursachen gleichzeitig beweist:

| Datei | Rolle | Aussteller | CN / SAN | Eigenschaft |
|---|---|---|---|---|
| `root-a.*` | dc1, vertrauenswuerdige Root | selbstsigniert | `dc1.vs-ldap.test` | Basislinie: gueltig, korrekter Name, vertraut |
| `dc2.*` | dc2 (zweiter Controller) | root-a | `dc2.vs-ldap.test` | gueltig, korrekter Name, von root-a signiert |
| `root-b.*` | dc-unknown-ca | selbstsigniert | `dc-unknown-ca.vs-ldap.test` | korrekter Name, aber NICHT im Bundle (unbekannte CA) |
| `expired.*` | dc-expired | root-a | `expired.vs-ldap.test` | von root-a signiert, aber `notBefore`/`notAfter` fest auf 2020-01-01/2021-01-01 (unabhaengig vom Systemdatum abgelaufen) |
| `wrongname.*` | dc-wrongname | root-a | `unexpected.vs-ldap.test` | von root-a signiert und gueltig, aber Servername (`dc-wrongname.vs-ldap.test`) weicht vom SAN ab |
| `root-c.*` | dc-rotated | selbstsigniert | `dc-rotated.vs-ldap.test` | zweite, unabhaengige Root fuer den CA-Rotationstest (ueberlappendes Bundle root-a+root-c, dann Cutover) |

Neu erzeugen (nur bei Bedarf, z.B. nach Ablauf der 20-Jahres-Gueltigkeit der
langlebigen Zertifikate): `openssl req -x509 -newkey rsa:2048 -nodes -days
7300 -subj "/CN=<name>" -addext "subjectAltName=DNS:<name>"` fuer die Roots,
`openssl x509 -req -CA root-a.crt.txt -CAkey root-a.key.txt -CAcreateserial`
fuer die von root-a signierten Blaetter. `expired.*` braucht `openssl ca` mit
expliziten `-startdate`/`-enddate` (ASN1 GENERALIZEDTIME), da `openssl x509
-req` keine Datumsflaggen kennt; die festen Vergangenheitswerte machen das
Zertifikat unabhaengig vom Build-Zeitpunkt reproduzierbar abgelaufen.
