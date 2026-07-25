# Testplan-Index

Die Pre-Release-Härtungskampagne 2026-07 ist abgeschlossen. Ihr vollständiges Kampagnendokument, inklusive aller Phasen, Checkbox-Stände, der Befundtabellen und der offenen Entscheidungen, liegt eingefroren unter [`docs/audits/2026-07-hardening.md`](audits/2026-07-hardening.md). Querverweise der Form "TESTPLAN 2.2" oder "TESTPLAN 4.7" in Tests, ADRs und Changelog meinen die Phasen-/Befundnummern dieses Archivs.

Dieses Dokument ist seither nur noch ein Wegweiser; es sammelt keine Checkboxen und keine Befunde mehr.

## Wo was lebt

| Rolle | Ort |
|---|---|
| Ausführbare SSoT aller Qualitätsgates | `scripts/check.ps1` (Lanes Fast/Integration/Release, ADR-0031) |
| Bedeutung, Voraussetzungen und Interpretation der Gates | `docs/QUALITY-GATES.md` |
| Bedienung und lokale Fehlersuche | `docs/QA.md` |
| Release-Abnahme (Vorlage, ohne gespeicherte Nachweise) | `PRE-SHIP-CHECKLIST.md` |
| Entscheidungen | `docs/adr/README.md` (Kampagnen-relevant: ADR-0028 bis ADR-0032) |
| Seiten- und Aktions-Inventar (vormals Anhang A) | `docs/QUALITY-GATES.md` plus die geschlossenen Kataloge der Contract-Tests (`PortalConfirmContractTest`, `PortalPostGuardContractTest`) und die E2E-Suite `tests/e2e/specs/` |
| Feld-Wertematrix (vormals Anhang B) | erschöpfend in `ValidatorRulesTest`, je Render-Kontext in `tests/e2e/specs/field-roundtrip.spec.js` |
| ESXi-Standortauswahl (Risiko-Gruppen, Beweis-Nenner, Datastore-Health, Picker-Markup) | `EsxiInventoryPresenceBucketsTest`, `EsxiInventoryOptionFlagsTest`, `EsxiDatastoreHealthTest`, `EsxiInventoryOptionsTest`, `InventorySelectFieldTest` |
| Zwillinge, die auseinanderlaufen können (Portal↔Repo-Gate, PHP↔`deploy.js`, Maske↔Spaltenliste) | `DeployDatacenterResolutionTest`, `DeployStorageMirrorContractTest`, `MissionFormStickyContractTest`, `DeployFormStateContractTest` |
| YAML-Injection-Matrix (vormals Anhang C) | `AnsibleServerlistYamlSafetyTest` plus das `yaml-roundtrip`-Gate (`tests/fixtures/golden-mission.json`) |
| Lastprofil und Schwellen | `tests/load/README.md` |
| Befund-Historie und Release-Notes | `docs/audits/2026-07-hardening.md`, `docs/CHANGELOG.md` |

## Bewusst manuell gebliebene Prüfungen

Diese Punkte sind keine vergessenen Reste, sondern die im Runner als manuell deklarierten Release-Gates (Begründung in `docs/QUALITY-GATES.md`):

- Tastatur-, Fokus- und Screenreader-Durchgang der Kernflüsse.
- SYSTEM-Smoke der PowerShell-Clients in einer Wegwerf-Windows-VM und die MECM-Staging-Abnahme.
- Reales Ansible-/ESXi-Staging mit zweitem Idempotenzlauf.
- Clean-Checkout-Releaseprobe auf frischem Host.
