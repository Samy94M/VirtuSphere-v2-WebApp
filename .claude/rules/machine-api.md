---
globs:
  - Docker/WebAPI/mecm*
  - Docker/WebAPI/db_importMAC.php
---

This is the machine API surface. Preserve wire fields and status semantics for MECM, PowerShell and Ansible. Harden with prepared statements and explicit methods, but do not remove endpoints without an E3 retirement decision. The desktop token API is retired (ADR-0035): its paths answer 404 by wire contract (`MachineApiWireTest`), and `db_importMAC.php` requires `job_id`. ADR-0019 candidates 1-3 and 5 remain open and need their own decisions.