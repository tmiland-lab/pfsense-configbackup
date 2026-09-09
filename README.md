# pfSense-pkg-configbackup

Configuration backup package for pfSense that keeps backups in **its own
database** — local SQLite, MySQL, or SQLite plus an off-box copy — with a full
GUI for listing, restoring and downloading backups.

Built because the Netgate AutoConfigBackup (ACB) cloud service can be
unreliable: the stock pipeline stages an encrypted backup on disk, uploads it
to acb.netgate.com, and **deletes the staged files no matter the upload
result** — during a service outage every backup is silently lost. This package
hijacks that pipeline (a supported cron-entry replacement, no pfSense source
files are modified) so staged backups are ingested into the package database
instead, and/or runs a fully independent encrypted backup schedule.

## Features

- **ACB hijack ingest engine** — removes the built-in `acbupload.php` cron
  entry and replaces it with an every-minute ingest that verifies and stores
  each staged `/cf/conf/acb/*.form` + `*.data` pair *before* deleting it.
  Self-healing: if saving ACB settings in the GUI re-installs the built-in
  cron entry, the hijack is re-asserted on the next ingest run.
- **Independent engine** — gzip + AES-256 encrypted backups of config.xml on
  its own schedule (1–24 h interval), no dependency on ACB. Both engines may
  run at the same time.
- **Storage backends** — SQLite (recommended, `/var/db/configbackup/
  configbackup.sqlite`), MySQL (via the `mysql` CLI — pfSense PHP has no
  `pdo_mysql`), or SQLite + off-box copy (scp/rsync of every new backup to
  `user@host:/path`; key-based ssh required). Off-box copies can optionally
  include the `config.xml` itself — encrypted with the package password in
  the standard tagfile format (decryptable on any machine with `openssl`),
  plaintext (not recommended — the XML contains secrets), or both.
- **Full GUI** under *Diagnostics → Config Backup*: list backups
  (date/engine/reason/version/size/sha256), backup-now, restore with safety
  backup, download as decrypted XML or package-encrypted `.cbk`, and a
  settings page with masked secrets.
- **Retention** — keep the newest N backups (default 120), pruned after every
  ingest/backup.
- **Safe restores** — before touching anything, the *current* configuration is
  backed up into the same database (a failed restore therefore never loses
  more than one revision). The restore follows the same code path as
  *Diagnostics → Backup & Restore*: `config_install()`, pkg repository
  preservation, package resync on next boot, RRD/SSH extra-data extraction.
  A reboot completes the restore.

## Requirements

- pfSense 2.8.x (developed and tested on 2.8.1-RELEASE)
- PHP with pdo_sqlite/sqlite3 and openssl (stock pfSense has both)
- For the MySQL backend: the `mysql80-client` package (installed as a
  dependency automatically)
- For off-box copies: passwordless (key-based) ssh access from the firewall
  to the target host

## Install

Third-party packages install from the CLI (the GUI *Available Packages* tab
only lists the official repo):

```sh
cat > /usr/local/etc/pkg/repos/configbackup.conf <<'EOF'
configbackup: {
    url: "https://tmiland-lab.github.io/pfsense-configbackup/repo",
    mirror_type: "NONE",
    signature_type: "none",
    enabled: yes
}
EOF
pkg update
pkg install -y -r configbackup pfSense-pkg-configbackup
```

After installation a *Config Backup* entry appears under *Diagnostics*.

## Setup

Open *Diagnostics → Config Backup → Settings*:

1. **Set the package encryption password.** Every stored backup is encrypted
   with it (losing it means losing the backups).
2. **Pick the engines.**
   - *ACB hijack ingest*: enable AutoConfigBackup (with its own encryption
     password) so pfSense keeps staging backups, and let this package ingest
     them. Nothing is uploaded to the ACB cloud while the hijack is active.
   - *Independent engine*: pick an interval; works with ACB disabled.
3. **Pick the storage backend** (SQLite, MySQL, or off-box) and fill in the
   credentials if needed. Secrets are stored in config.xml (root-only
   readable) and are never echoed back to the browser; leave the field empty
   on save to keep the stored value.
4. **Retention** — how many backups to keep (default 120).

Saving settings re-applies all cron entries for the selected engines.

## Restore

On *Diagnostics → Config Backup*, press **Restore** next to a backup. The
package first stores a safety backup of the current configuration, then
installs the selected configuration exactly like the stock backup & restore
page does. Afterwards the firewall must be **rebooted** (packages resync on
boot) — the page shows a reboot button when a restore has been applied. If
the interface assignments in the restored configuration do not match the
hardware, pfSense will ask to reassign interfaces after reboot.

The safety backup is marked *Pre-restore safety backup* in the list, so a bad
restore can be undone by restoring it (after another reboot).

## CLI

```sh
/usr/local/pfsense-configbackup/bin/configbackup.php status
/usr/local/pfsense-configbackup/bin/configbackup.php list [n]
/usr/local/pfsense-configbackup/bin/configbackup.php backup "manual reason"
/usr/local/pfsense-configbackup/bin/configbackup.php restore <id>
/usr/local/pfsense-configbackup/bin/configbackup.php ingest
/usr/local/pfsense-configbackup/bin/configbackup.php prune
```

## How the ACB hijack works

pfSense's AutoConfigBackup pipeline works in two steps: `execacb.php` (on the
ACB schedule) encrypts config.xml and stages a `.form`/`.data` pair under
`/cf/conf/acb/`; `acbupload.php` (every minute) uploads the pairs and deletes
them regardless of the outcome. This package replaces only the second step:
the built-in cron entry is removed and our ingest runs instead — it decrypts
the staged blob with the ACB password, verifies the staged sha256 checksum,
stores the backup (re-encrypted with the package password) and only then
removes the staged files. If ACB settings are saved in the GUI, pfSense
re-installs its own upload cron entry; the next ingest run removes it again
and logs a notice. ACB itself stays in charge of the *staging* schedule.

When the hijack engine is switched off, the built-in uploader is handed back
its cron entry (if ACB is enabled).

## Security notes

- Stored backups are AES-256-CBC encrypted (PBKDF2 key derivation) using
  pfSense's own `encrypt_data()`; plaintext never touches the database.
- The database directory `/var/db/configbackup` is created mode 0700.
- MySQL credentials are passed to the client through the `MYSQL_PWD`
  environment variable (not argv); all SQL values travel as hex literals, so
  backup metadata (which may contain user-written revision descriptions)
  cannot inject SQL.
- Off-box copies are staged in pfSense's temp dir with mode 0600 and
  transferred with `scp -o BatchMode=yes` or rsync; a missing ssh key fails
  the copy loudly in the system log instead of hanging the cron job.

## Uninstall

```sh
pkg remove -y pfSense-pkg-configbackup
```

The package cron entries are removed and the built-in ACB uploader is
restored (when ACB is enabled). The backup database at `/var/db/configbackup`
is intentionally kept, so reinstalling later finds the old backups.

## License

MIT — see [LICENSE](LICENSE).
