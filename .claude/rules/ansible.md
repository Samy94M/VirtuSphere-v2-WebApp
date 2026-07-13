---
globs:
  - Ansible/**
---

Keep playbook filenames compatible with the desktop workflow. `upload_mac_list.py` must stay air-gap friendly and use Python stdlib HTTP, not `requests`, after its migration.