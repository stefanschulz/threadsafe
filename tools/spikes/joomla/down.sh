#!/usr/bin/env bash
# Removes what up.sh made: the two containers (with their volumes) and the two databases.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
docker rm -f -v ts-joomla-spike-5 ts-joomla-spike-6 >/dev/null 2>&1
docker exec threadsafe-dev-db-1 mariadb -uroot -p"${DB_ROOT_PASSWORD:-prestashop}" -e 'DROP DATABASE IF EXISTS joomla5; DROP DATABASE IF EXISTS joomla6' 2>/dev/null
echo "removed"
