---
globs:
  - Ansible/**
---

Keep playbook filenames compatible with the desktop workflow. `upload_mac_list.py` must stay air-gap friendly and use Python stdlib HTTP, not `requests`, after its migration.

A task under `ignore_errors: true` must be verified against its module's argument spec, and its outcome must reach the marker the PHP side reads. Tolerated failure plus an unchecked argument list is a task that can never succeed while looking like a host that has nothing: `vmware_portgroup_info` requires one of `cluster_name`/`esxi_hostname` and `vmware_dvs_portgroup_info` requires `datacenter`; neither was passed, so the portal reported 0 portgroups for a host with thirteen and no error said otherwise. The pinned collection answers the question directly, without an ESXi host: `docker run --rm virtusphere-qa-ansible:latest ansible-playbook <a task calling the module with dummy credentials>` fails on argument validation before it opens a connection. `AnsiblePlaybookHygieneContractTest` pins both the required arguments and that every registered task appears in the `queries` block.