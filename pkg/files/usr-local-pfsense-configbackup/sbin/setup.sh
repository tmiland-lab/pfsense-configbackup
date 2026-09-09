#!/bin/sh
# pfsense-configbackup package setup: registers/unregisters the menu entry
# in config.xml (the same wiring the GUI installer does for packages
# installed through the web UI) and applies/removes the cron entries.

PKG_BASE="/usr/local/pfsense-configbackup"

register_config() {
  php <<'PHP'
<?php
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$name = 'Config Backup';
$menu = array(
    'name' => $name,
    'tooltiptext' => 'Local configuration backup and restore',
    'section' => 'Diagnostics',
    'url' => '/packages/configbackup/index.php'
);

$menus = config_get_path('installedpackages/menu', []);
$found = false;
foreach ($menus as $k => $m) {
    if (isset($m['name']) && $m['name'] == $menu['name']) {
        $menus[$k] = $menu;
        $found = true;
        break;
    }
}
if (!$found) {
    $menus[] = $menu;
}
config_set_path('installedpackages/menu', $menus);

write_config("Installed pfSense-pkg-configbackup: registered menu");
PHP
}

unregister_config() {
  php <<'PHP'
<?php
require_once("/etc/inc/config.inc");
$config = parse_config(true);

$menus = config_get_path('installedpackages/menu', []);
$out = array();
foreach ($menus as $m) {
    if (isset($m['name']) && $m['name'] == 'Config Backup') {
        continue;
    }
    $out[] = $m;
}
config_set_path('installedpackages/menu', $out);

write_config("Removed pfSense-pkg-configbackup: unregistered menu");
PHP
}

case "$1" in
install)
  mkdir -p /var/db/configbackup
  chmod 700 /var/db/configbackup
  register_config
  # Install cron entries matching the (possibly empty) settings. With no
  # settings yet both engines are off, so this only re-asserts the built-in
  # ACB upload cron when ACB itself is enabled.
  /usr/local/bin/php ${PKG_BASE}/bin/configbackup.php cron-apply
  ;;
deinstall)
  /usr/local/bin/php ${PKG_BASE}/bin/configbackup.php cron-remove
  unregister_config
  # The backup database at /var/db/configbackup is intentionally kept so an
  # uninstall+reinstall does not lose backups.
  ;;
*)
  echo "Usage: $0 install|deinstall"
  exit 1
  ;;
esac

exit 0
