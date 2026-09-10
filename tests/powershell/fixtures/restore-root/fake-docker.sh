#!/bin/sh
printf '%s\n' "$*" >> "$VS_FAKE_DOCKER_LOG"
exit 1
