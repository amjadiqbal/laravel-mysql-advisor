#!/usr/bin/env bash
# Runs tests/Integration against real servers in throwaway Docker containers: MySQL 8.4 and MariaDB 11
# (performance_schema on). Each check is seen firing on a seeded schema and clearing once fixed.
#   scripts/integration.sh              both servers
#   SERVERS=mariadb scripts/integration.sh one
#   KEEP=1 scripts/integration.sh       leave the containers running afterwards
set -uo pipefail
cd "$(dirname "$0")/.."

SERVERS=${SERVERS:-"mysql mariadb"}
FAILED=0; RESULTS=()

start() { # name image port extra-args...
  local name=$1 image=$2 port=$3; shift 3
  docker rm -f "$name" >/dev/null 2>&1
  docker run -d --name "$name" -e MYSQL_ROOT_PASSWORD=root -e MARIADB_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=advisor -e MARIADB_DATABASE=advisor -p "$port:3306" "$image" "$@" >/dev/null || return 1
  for _ in $(seq 1 90); do
    php -r 'try { new PDO("mysql:host=127.0.0.1;port='"$port"';dbname=advisor", "root", "root"); exit(0); } catch (Throwable) { exit(1); }' && return 0
    sleep 2
  done
  echo "$name did not start"; return 1
}

for server in $SERVERS; do
  case $server in
    mysql)   name=mla-it-mysql84;   image=mysql:8.4;  port=33184; driver=mysql;   maria="";  extra=() ;;
    mariadb) name=mla-it-mariadb11; image=mariadb:11; port=33211; driver=mariadb; maria=1;   extra=(--performance-schema=ON) ;;
    *) echo "unknown server $server"; exit 2 ;;
  esac
  printf '\n== %s (%s)\n' "$server" "$image"
  if start "$name" "$image" "$port" ${extra[@]+"${extra[@]}"} &&
     MYSQL_ADVISOR_TEST_PORT=$port MYSQL_ADVISOR_TEST_DRIVER=$driver MYSQL_ADVISOR_TEST_MARIADB=$maria vendor/bin/pest --group=integration; then
    RESULTS+=("pass  integration $image")
  else
    RESULTS+=("FAIL  integration $image"); FAILED=1
  fi
  [ -n "${KEEP:-}" ] || docker rm -f "$name" >/dev/null 2>&1
done

printf '\n== Integration summary\n'; printf '%s\n' "${RESULTS[@]}"
exit $FAILED
