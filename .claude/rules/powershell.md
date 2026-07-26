---
globs:
  - Powershell-MECM/**
---

Treat MECM scripts as integration clients. If endpoint payloads or response fields change, update `Docker/WebAPI/tests/Integration/MachineApiWireTest.php` and migration docs first.

A task must report the value it acts on, and both must be the same expression. Three of the four scripts clamped their configured interval only upward (`[Math]::Max(30, …)`) while `Send-VsRunReport` clamps both ends: a registry value above the wire maximum made the task sleep longer than the number it sent, and the System status page derives its colour from the number it *received*, so the row sat on "Verzögert" forever while the task did exactly what it was configured to do. `Resolve-VsInterval` (`VirtuSphere-Common.ps1`) resolves it once for the sleep and the report; the per-task floors live in `$script:VsIntervalBounds`, which `install-VirtuSphere-MECM.ps1` mirrors in `ValidateRange` and `VirtuSphere.RunReport.Tests.ps1` pins in both directions. Clamping is never silent: a corrected setting writes a WARN line, because a value that quietly changes is the same defect one turn earlier. A setting the operator can tune must also survive a re-run of the installer that did not name it, or a script update resets it and the page reports the new cadence as fact.
