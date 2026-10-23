# Joomla fixtures

`configuration-5.4.php` and `configuration-6.2.php` are the `configuration.php` that the official Docker images `joomla:5.4-php8.3-apache` and
`joomla:6-php8.4-apache` write at installation (Joomla 5.4.8 and 6.2.0, `tools/spikes/joomla/up.sh`), with the site name, the database user, name and
password and the `secret` replaced by test values. Everything else is as Joomla wrote it: tab indentation, line feeds, mode 0444 in the image.
