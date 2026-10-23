#!/usr/bin/env bash
# What Joomla does when something that a backup leaves out, or a restore must change, is wrong (docs/PHASE6.md section 8). Uses the containers of up.sh.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
c="${1:-ts-joomla-spike-6}"
w=/var/www/html
probe() { docker exec "$c" php /tmp/probe.php | grep -- '->' | sed 's/^/    /'; }
run() { docker exec "$c" sh -c "$1"; }

echo "## baseline"; probe

echo "## A. tmp directory missing"
run "mv $w/tmp $w/tmp.off"; probe; run "mv $w/tmp.off $w/tmp"

echo "## B. cache and administrator/cache missing"
run "mv $w/cache $w/cache.off; mv $w/administrator/cache $w/administrator/cache.off"; probe
echo "   recreated by Joomla? $(run "ls -d $w/cache $w/administrator/cache 2>&1 | tr '\n' ' '")"
run "rm -rf $w/cache $w/administrator/cache; mv $w/cache.off $w/cache; mv $w/administrator/cache.off $w/administrator/cache"

echo "## C. log_path and tmp_path point to directories that do not exist"
run "cp -p $w/configuration.php /tmp/configuration.keep; chmod u+w $w/configuration.php; sed -i \"s#'/var/www/html/tmp'#'/nonexistent/tmp'#; s#'/var/www/html/administrator/logs'#'/nonexistent/logs'#\" $w/configuration.php"
probe
echo "   log written elsewhere? $(run "ls $w/administrator/logs $w/tmp 2>&1 | tr '\n' ' '")"
run "cp -p /tmp/configuration.keep $w/configuration.php; stat -c '   mode back to %a' $w/configuration.php"

echo "## D. live_site set to another address"
run "chmod u+w $w/configuration.php; sed -i \"s#public \\\$live_site = '';#public \\\$live_site = 'https://moved.example';#\" $w/configuration.php"
docker exec "$c" php -r '$b = file_get_contents("http://localhost/"); preg_match_all("~(?:href|src)=\"([^\"]*moved\.example[^\"]*)\"~", $b, $m); echo "    links with the live_site: ", count($m[1]), "\n"; preg_match("~<base[^>]*>~", $b, $x); echo "    ", $x[0] ?? "no base tag", "\n";'
run "cp -p /tmp/configuration.keep $w/configuration.php"

echo "## E. sessions, search index and action logs emptied"
db=$(run "grep -o \"public \\\$db = '[^']*'\" $w/configuration.php | cut -d\"'\" -f2")
for t in session finder_links finder_links_terms finder_terms finder_tokens finder_tokens_aggregate finder_taxonomy_map finder_logging action_logs updates; do
    docker exec threadsafe-dev-db-1 mariadb -uroot -pprestashop "$db" -e "TRUNCATE TABLE joom_$t" 2>&1 | grep -v Warning
done
probe
