#!/bin/sh
# Builds pfSense-pkg-configbackup and pkg(8) repo metadata.
# Run ON a pfSense host (or matching FreeBSD box): sh pkg/build.sh
# Output: <workdir>/out/packages/<ABI>/{All/*.pkg, meta.*, digests.*}
set -eu

PKGDIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO=$(dirname "$PKGDIR")
WORK=$(mktemp -d /tmp/pfsense-configbackup-build.XXXXXX)
STAGE="$WORK/stage"
META="$WORK/meta"
OUT="/tmp/pfsense-configbackup-repo-out"
rm -rf "$OUT"
mkdir -p "$STAGE" "$META" "$OUT"

PBASE="usr/local/pfsense-configbackup"
WBASE="usr/local/www/packages/configbackup"
mkdir -p "$STAGE/$PBASE/bin" "$STAGE/$PBASE/share" "$STAGE/$PBASE/sbin" "$STAGE/$WBASE"

install -m 0755 "$PKGDIR/files/usr-local-pfsense-configbackup/bin/configbackup.php" \
	"$STAGE/$PBASE/bin/configbackup.php"
install -m 0644 "$PKGDIR/files/usr-local-pfsense-configbackup/share/configbackup_lib.php" \
	"$STAGE/$PBASE/share/configbackup_lib.php"
install -m 0644 "$PKGDIR/files/usr-local-pfsense-configbackup/share/configbackup.xml" \
	"$STAGE/$PBASE/share/configbackup.xml"
install -m 0755 "$PKGDIR/files/usr-local-pfsense-configbackup/sbin/setup.sh" \
	"$STAGE/$PBASE/sbin/setup.sh"
# Stage every www page automatically so new pages cannot be forgotten
for page in "$PKGDIR"/files/usr-local-www-packages-configbackup/*.php; do
	install -m 0644 "$page" "$STAGE/$WBASE/$(basename "$page")"
done

php -l "$STAGE/$PBASE/share/configbackup_lib.php" >/dev/null
php -l "$STAGE/$PBASE/bin/configbackup.php" >/dev/null
php -l "$STAGE/$WBASE/index.php" >/dev/null
php -l "$STAGE/$WBASE/settings.php" >/dev/null

VERSION=$(sed -n 's/.*<version>\([^<]*\)<.*/\1/p' "$STAGE/$PBASE/share/configbackup.xml" | head -1)
ABI=$(pkg config abi)
NAME="pfSense-pkg-configbackup"
ORIGIN="security/pfSense-pkg-configbackup"

cat > "$META/+POST_INSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense-configbackup/sbin/setup.sh install
exit 0
EOF

cat > "$META/+PRE_DEINSTALL" <<'EOF'
#!/bin/sh
/usr/local/pfsense-configbackup/sbin/setup.sh deinstall
exit 0
EOF

chmod 0755 "$META/+POST_INSTALL" "$META/+PRE_DEINSTALL"

MANIFEST=$(php -r '
$stage = $argv[1]; $meta = $argv[2]; $abi = $argv[3];
$version = $argv[4]; $name = $argv[5]; $origin = $argv[6];
$files = array(); $flatsize = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = ltrim(str_replace($stage, "", $f->getPathname()), "/");
    $files["/" . $rel] = hash_file("sha256", $f->getPathname());
    $flatsize += $f->getSize();
}
$deps = array();
foreach (array("mysql80-client") as $dep) {
    $out = shell_exec("/usr/sbin/pkg query -q \"%n|%o|%v\" $dep 2>/dev/null");
    if (!empty(trim($out ?? ""))) {
        $parts = explode("|", trim($out));
        $deps[$parts[0]] = array("origin" => $parts[1], "version" => $parts[2]);
    }
}
$dirs = array();
foreach (array(
    "usr/local/pfsense-configbackup",
    "usr/local/pfsense-configbackup/bin",
    "usr/local/pfsense-configbackup/share",
    "usr/local/pfsense-configbackup/sbin",
    "usr/local/www/packages/configbackup"
) as $d) {
    $dirs["/" . $d] = "y";
}
$manifest = array(
    "name" => $name,
    "origin" => $origin,
    "version" => $version,
    "comment" => "Config Backup - own-database configuration backup for pfSense",
    "desc" => "Keeps encrypted configuration backups in a local (SQLite/MySQL) database with optional off-box copies. Can hijack the AutoConfigBackup staging pipeline (ingest instead of cloud upload - useful during ACB outages) and/or run its own gzip+encrypt schedule. Full GUI: list, restore, download, backup-now, settings.",
    "maintainer" => "kontakt@tmiland.com",
    "www" => "https://github.com/tmiland-lab/pfsense-configbackup",
    "abi" => $abi,
    "arch" => $abi,
    "prefix" => "/",
    "categories" => array("pfSense"),
    "licenses" => array("MIT"),
    "flatsize" => $flatsize,
    "deps" => (object) $deps,
    "files" => (object) $files,
    "directories" => (object) $dirs,
    "scripts" => array(
        "post-install" => "+POST_INSTALL",
        "pre-deinstall" => "+PRE_DEINSTALL"
    )
);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
' "$STAGE" "$META" "$ABI" "$VERSION" "$NAME" "$ORIGIN")

echo "$MANIFEST" > "$META/+MANIFEST"

pkg create -m "$META" -r "$STAGE" -o "$OUT" >/dev/null

PKG_FILE=$(find "$OUT" -name "*.pkg" | head -1)
# Flat repo layout: metadata at the repo root, packages in All/
# (pfSense pkg(8) fetches ${url}/meta.conf).
REPO_OUT="$OUT"
mkdir -p "$REPO_OUT/All"
mv "$PKG_FILE" "$REPO_OUT/All/"
(cd "$REPO_OUT" && pkg repo . >/dev/null)

echo "=== Build complete: $REPO_OUT"
find "$REPO_OUT" -type f | sort
rm -rf "$WORK"
