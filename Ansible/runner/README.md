# Durable Remote Runner (8R-O)

Dieses Verzeichnis ist das offline pruefbare, aber noch nicht aktivierte
Ausfuehrungsprotokoll fuer einen spaeteren systemd-User-Runner auf dem
Ansible-Host. `protocol-v1.json` ist die geschlossene Wire-Definition;
unbekannte Felder, freie Shell-Kommandos und nicht zugeordnete Playbooks werden
abgewiesen. `create` und die zusammengesetzte `full`-Pipeline sind bis Etappe
14B absichtlich nicht Teil der Policy.

Der Launcher akzeptiert genau `manifest.json` und den 128-Bit-Run-Token. Er
prueft Pfad, Besitzer, Modi, Symlinks, Hashes und die aus der Identitaet
abgeleitete Unit, bevor er `systemd-run --user` ohne Shell startet. Ein
vorhandenes `started.json` oder `result.json` fuehrt niemals zu einem zweiten
Start. Der Runner schreibt `started.json`, `heartbeat.json` und `result.json`
atomar, begrenzt `output.log` und entfernt die im zwingenden Redaction-Artefakt
benannten Werte auch ueber Chunk-Grenzen hinweg.

`virtusphere_remote_observer.py` ist der rein lesende Poll-/Reattach-Endpunkt.
Er validiert dieselbe Manifestidentitaet, liefert Unitzustand und vorhandene
Marker als geschlossenes Observation-Dokument und liest `output.log` ab genau
dem angeforderten Rohbyte-Offset in einem begrenzten Chunk. Ein Offset hinter
dem aktuellen Dateiende gilt als Rotation/Truncation und wird abgewiesen; der
Observer startet, stoppt und loescht keine Unit und keine Datei.

`virtusphere_remote_preflight.py` ist rein lesend. Sein JSON ist nur ein
Evidenzartefakt fuer 8R-S; es aktiviert weder Linger noch den Produktpfad. Der
erforderliche freie Speicher wird bewusst ohne Default uebergeben, weil dieser
Wert am echten Standort gemessen und freigegeben werden muss.

Installation aus dem verifizierten Offline-Bundle:

```sh
python3 runner/virtusphere_remote_install.py runner
python3 ~/.local/libexec/virtusphere/virtusphere_remote_preflight.py \
  --required-free-bytes <standortfreigabe>
```

Die Installation aendert keine Privilegien und aktiviert kein Linger. Ein
bestehendes Installationsziel wird nicht ueberschrieben; ein Austausch braucht
einen gesondert geprueften Betriebsablauf.
