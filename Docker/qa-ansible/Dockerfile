# QA-Ansible-Image (AP4): traegt ansible-playbook --syntax-check und
# ansible-lint fuer die Gates ansible-syntax/ansible-lint in scripts/check.ps1.
# Reines CI-/Dev-Tooling, wird nie ausgeliefert (ADR-0028-Familie).
#
# Versionen kommen aus zwei Lockdateien, nicht aus diesem Dockerfile:
#   - Docker/qa-ansible/requirements.txt (pip, mit --require-hashes)
#   - Ansible/requirements.yml (Collection-SSoT, ADR-0025)
# Das Basisimage ist per Digest gepinnt (scripts/tool-lock.json, Eintrag python).
#
# Build laeuft mit Repo-Root-Kontext, damit Ansible/requirements.yml nicht
# dupliziert wird (das Root-.dockerignore haelt den Kontext klein):
#   docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .
FROM python:3.13-alpine@sha256:399babc8b49529dabfd9c922f2b5eea81d611e4512e3ed250d75bd2e7683f4b0

# git: ansible-lint nutzt es zur Dateierkennung im gemounteten Repo.
RUN apk add --no-cache git

COPY Docker/qa-ansible/requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /tmp/requirements.txt

COPY Ansible/requirements.yml /tmp/collection-requirements.yml
RUN ansible-galaxy collection install -r /tmp/collection-requirements.yml \
        -p /usr/share/ansible/collections

# Das Repo wird read-only nach /repo gemountet; alles Schreibbare nach /tmp.
ENV HOME=/tmp \
    ANSIBLE_LOCAL_TEMP=/tmp/.ansible-tmp \
    ANSIBLE_COLLECTIONS_PATH=/usr/share/ansible/collections
WORKDIR /repo
