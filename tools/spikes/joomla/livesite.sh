#!/usr/bin/env bash
# What a live_site that names another address does to a Joomla site (docs/PHASE6.md section 8).
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
c="$1"
value="$2"
w=/var/www/html
docker exec "$c" sh -c "cp -p $w/configuration.php /tmp/configuration.keep; chmod u+w $w/configuration.php; sed -i \"s|public \\\$live_site = '';|public \\\$live_site = '$value';|\" $w/configuration.php; grep -n 'live_site' $w/configuration.php | sed 's/^/   config: /'"
docker exec "$c" php -r '
$ctx = stream_context_create(["http" => ["follow_location" => 0, "ignore_errors" => true, "timeout" => 20]]);
foreach (["/", "/administrator/", "/index.php/component/users/login"] as $p) {
    $b = @file_get_contents("http://localhost" . $p, false, $ctx);
    $loc = "";
    foreach ($http_response_header ?? [] as $h) { if (stripos($h, "location:") === 0) { $loc = $h; } }
    echo "   ", $p, " -> ", $http_response_header[0] ?? "none", " ", $loc, "\n";
}
$b = (string) file_get_contents("http://localhost/", false, $ctx);
preg_match_all("~(?:href|src|action)=\"([^\"]*)\"~", $b, $m);
$abs = array_filter($m[1], fn ($u) => str_contains($u, "moved.example"));
echo "   links with the live_site: ", count($abs), " of ", count($m[1]), "; first: ", reset($abs) ?: "-", "\n";
'
docker exec "$c" sh -c "cp -p /tmp/configuration.keep $w/configuration.php; stat -c '   mode restored: %a' $w/configuration.php"
