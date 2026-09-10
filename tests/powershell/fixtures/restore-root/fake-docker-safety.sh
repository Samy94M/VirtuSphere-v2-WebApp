#!/bin/sh
printf '%s\n' "$*" >> "$VS_FAKE_DOCKER_LOG"

mode=${VS_FAKE_DOCKER_MODE:-}
state=${VS_FAKE_DOCKER_STATE:?}
mkdir -p "$state"
net_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
mysql_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
smoke_id=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc

read_saved() {
  cat "$state/$1" 2>/dev/null || true
}

save_create_args() {
  kind=$1
  shift
  name=""
  label=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name)
        shift
        name=$1
        ;;
      --label)
        shift
        case "$1" in
          virtusphere.qa.probe=*) label=${1#*=} ;;
        esac
        ;;
    esac
    shift
  done
  printf '%s' "$name" > "$state/$kind-name"
  printf '%s' "$label" > "$state/$kind-label"
}

case "$1 $2" in
  'image inspect')
    exit 0
    ;;
  'network inspect')
    target=${3:-}
    if [ "$mode" = collision ] && [ ! -f "$state/network-created" ]; then
      printf '%s\n' "$net_id"
      exit 0
    fi
    [ -f "$state/network-created" ] || exit 1
    [ "$target" = "$net_id" ] || [ "$target" = "$(read_saved network-name)" ] || exit 1
    printf '%s|%s|virtusphere-qa|%s\n' "$net_id" "$(read_saved network-name)" "$(read_saved network-label)"
    exit 0
    ;;
  'network create')
    shift 2
    for value in "$@"; do network_name=$value; done
    save_create_args network "$@"
    printf '%s' "$network_name" > "$state/network-name"
    touch "$state/network-created"
    printf '%s\n' "$net_id"
    exit 0
    ;;
  'network rm')
    [ "${3:-}" = "$net_id" ] || exit 96
    touch "$state/network-removed"
    exit 0
    ;;
  'container inspect')
    target=${3:-}
    if [ -f "$state/mysql-created" ] && [ "$target" = "$(read_saved mysql-name)" ]; then exit 0; fi
    if [ -f "$state/smoke-created" ] && [ "$target" = "$(read_saved smoke-name)" ]; then exit 0; fi
    exit 1
    ;;
esac

case "$1" in
  compose)
    exit 0
    ;;
  create)
    created_name=""
    name_follows=0
    for argument in "$@"; do
      if [ "$name_follows" -eq 1 ]; then created_name=$argument; break; fi
      [ "$argument" != --name ] || name_follows=1
    done
    case "$created_name" in
      vs-restore-mysql-*) kind=mysql; id=$mysql_id ;;
      vs-restore-web-*) kind=smoke; id=$smoke_id ;;
      *) exit 96 ;;
    esac
    save_create_args "$kind" "$@"
    touch "$state/$kind-created"
    printf '%s\n' "$id"
    exit 0
    ;;
  inspect)
    id=${2:-}
    if [ "$id" = "$mysql_id" ]; then kind=mysql
    elif [ "$id" = "$smoke_id" ]; then kind=smoke
    else exit 96
    fi
    count=$(read_saved "$kind-inspects")
    count=${count:-0}
    count=$((count + 1))
    printf '%s' "$count" > "$state/$kind-inspects"
    label=$(read_saved "$kind-label")
    [ "$mode" != identity-mismatch ] || [ "$count" -eq 1 ] || label=foreign-run
    printf '%s|/%s|virtusphere-qa|%s\n' "$id" "$(read_saved "$kind-name")" "$label"
    exit 0
    ;;
  start)
    id=${2:-}
    if [ "$id" = "$mysql_id" ] && [ "$mode" = start-failure ]; then exit 42; fi
    if [ "$id" = "$mysql_id" ] && [ "$mode" = signal ]; then
      kill -TERM "$PPID"
      sleep 1
      exit 42
    fi
    exit 0
    ;;
  exec)
    case "$mode" in
      partial-dump)
        if [ "${3:-}" = true ]; then exit 0; fi
        if [ "${3:-}" = sh ]; then head -c 32768 /dev/urandom; exit 42; fi
        exit 96
        ;;
      backup-signal)
        if [ "${3:-}" = true ]; then exit 0; fi
        if [ "${3:-}" = sh ]; then
          head -c 32768 /dev/urandom
          kill -TERM "$PPID"
          sleep 1
          exit 42
        fi
        exit 96
        ;;
    esac
    case "$*" in
      *information_schema.tables*) printf '10\n' ;;
      *'SELECT 1'*) printf '1\n' ;;
      *'-N -e'*) printf '1\n' ;;
      *) cat >/dev/null; printf 'fingerprint\n' ;;
    esac
    exit 0
    ;;
  run)
    case "$*" in
      *'base64_encode(random_bytes(32))'*) printf 'ZmFrZS13cm9uZy1rZXk=\n' ;;
      *--check*) printf 'pending=0\n' ;;
    esac
    exit 0
    ;;
  rm)
    [ "${2:-}" = -f ] || exit 96
    [ "$mode" != cleanup-rm-failure ] || exit 42
    case "${3:-}" in
      "$mysql_id") touch "$state/mysql-removed" ;;
      "$smoke_id") touch "$state/smoke-removed" ;;
      *) exit 96 ;;
    esac
    exit 0
    ;;
esac

exit 97
