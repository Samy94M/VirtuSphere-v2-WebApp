---
globs:
  - Docker/WebAPI/mecm*
  - Docker/WebAPI/db_importMAC.php
---

This is the machine API surface. Preserve wire fields and status semantics for MECM, PowerShell and Ansible. Harden with prepared statements and explicit methods, but do not remove endpoints without an E3 retirement decision.