# ADR-0045: SSH und SFTP prüfen die Hostidentität vor jeder Anmeldung

Status: Zur Umsetzung beschlossen (AB-E1, AB-E1a); technische Abnahme von K1 ausstehend.

## Kontext

PHP und Worker senden das Ansible-Passwort über SSH und Dateien mit ESXi-Zugangsdaten über SFTP. Verschlüsselung ohne Prüfung des Server-Schlüssels bindet diese Geheimnisse nicht an den richtigen Ubuntu-Host. Ein einmaliger Zugangstest schützt spätere Verbindungen nicht. phpseclib kann bei einem Transportfehler intern erneut verbinden und `login()` wiederholen.

## Entscheidung

Jeder Ansible-Zugang speichert den OpenSSH-kompatiblen SHA256-Fingerprint des vollständigen öffentlichen Host-Schlüsselblobs, seinen Schlüsseltyp, die erste Anheftung sowie Zeitpunkt und Benutzer der Bestätigung. Der gemeinsame Guard prüft den signierten Schlüssel vor jeder Passwortanmeldung. Auf einem Verbindungsobjekt ist nur ein Loginversuch erlaubt: phpseclib behält seinen privaten Signaturprüfungsmerker über ein internes Reconnect hinweg. Eine interne Wiederanmeldung wird deshalb mit `reconnect_unverified` gesperrt; neue externe Verbindungen nutzen ein frisches Guard-Objekt. Die Repository-Prüfung liest unter Zeilensperre den aktuellen Zugang, prüft Typ, Host, kanonischen Port und Benutzer gegen den verwendeten Snapshot und serialisiert das erste Anheften. Eine bereits offene Repository-Transaktion sperrt die Anmeldung, damit der Pin vor Passwortübertragung dauerhaft committet ist. Keine Datenbanktransaktion bleibt während einer Netzwerk-Anmeldung offen.

Nur vor dem Upgrade vorhandene Ansible-Zugänge erhalten die einmalige Berechtigung zum vorläufigen Anheften. Fingerprint und Audit werden vor Login gemeinsam committet. Neue Zugänge lesen im Test nur den Host-Schlüssel und bleiben bis zur ausdrücklichen Bestätigung gesperrt. Ein Wechsel von Typ, exaktem Host oder kanonischem Port setzt Pin, Beobachtung, Bestätigung und Upgrade-Ausnahme gemeinsam zurück; das neue Ziel benötigt ausdrückliche Bestätigung. Umbenennen und Passwortwechsel behalten den Pin. Der Guard liest den aktuellen Pin, sodass ein alter Worker-Snapshot nach einer bewussten Rotation nur den neu bestätigten Schlüssel zulässt.

Beobachtung und Vertrauensanker liegen in getrennten Spalten: eine Abweichung wird angezeigt, überschreibt den Pin aber nicht und endet mit `SshHostIdentityRejected` und Kategorie `ansible_host_identity`. Eine Speicher-/Auditstörung kann keine Anmeldung freigeben. Änderungen am Pin erfordern Adminberechtigung, CSRF, unabhängigen Vergleich, explizite Bestätigung und den zuvor angezeigten Pin plus Zugangsrevision. Eine neue Identität invalidiert den Volltest; die Bestätigung des unveränderten vorläufigen Pins lässt gültige Evidenz bestehen.

## Folgen und Grenzen

Die Upgrade-Ausnahme vermeidet einen Betriebsstopp, bleibt aber Trust on First Use: ein Angreifer während der allerersten Verbindung kann vorläufig angeheftet werden. Der unabhängige Hostvergleich ist deshalb nötig. Ein legitimer Schlüsselwechsel sperrt bis zur bewussten Bestätigung. Bestehende SSH-/SFTP-Timeouts, Maschine-API-Verträge und Worker-Endzustände bleiben bei ihren bisherigen Fachownern. Die synthetische QA prüft Verhalten mit kontrollierten Transporten und Datenbank; LP-09 liefert der Nutzer auf einem Testhost.

Implementierung und Operatorweg: [Verbindungen und Vertrauensanker](../operations/trust-flows.md). Abnahme und Stopps: [Arbeitsauftrag K1](../audits/2026-10-04-codex-work-order.md).
