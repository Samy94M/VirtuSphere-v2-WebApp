---
globs:
  - Docker/WebAPI/mecm*
  - Docker/WebAPI/db_importMAC.php
---

This is the machine API surface. Preserve wire fields and status semantics for MECM, PowerShell and Ansible. Harden with prepared statements and explicit methods, but do not remove endpoints without an E3 retirement decision. The desktop token API is retired (ADR-0035): its paths answer 404 by wire contract (`MachineApiWireTest`), and `db_importMAC.php` requires `job_id`. Its strict V2 result is additive and needs exact VM/WDS matching, an export-capable active job, current attempt/runtime/remote-handle generation and the semantic callback fingerprint under the fixed lock order. Historical V1 remains readable; only an identical replay of the same active execution is 200/no-op, while conflicts and terminal replays are 409 without domain writes. ADR-0019 candidates 1-3 and 5 remain open and need their own decisions.
