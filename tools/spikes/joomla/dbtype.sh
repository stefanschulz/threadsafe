#!/usr/bin/env bash
# Does Joomla run with dbtype pdomysql (the driver of a host that has no mysqli)?
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
for n in 5 6; do
    c=ts-joomla-spike-$n
    echo "Joomla $n"
    docker exec "$c" sh -c "cp -p /var/www/html/configuration.php /tmp/configuration.keep && chmod u+w /var/www/html/configuration.php && sed -i \"s/'mysqli'/'pdomysql'/\" /var/www/html/configuration.php && grep -n 'dbtype' /var/www/html/configuration.php"
    docker exec "$c" php /tmp/probe.php 2>/dev/null | head -2
    docker exec "$c" sh -c "cp -p /tmp/configuration.keep /var/www/html/configuration.php && grep -n 'dbtype' /var/www/html/configuration.php"
done
