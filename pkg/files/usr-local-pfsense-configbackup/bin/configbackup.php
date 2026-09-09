#!/usr/local/bin/php -f
<?php
/*
 * configbackup.php
 *
 * CLI driver for the pfSense-pkg-configbackup package. Called by cron for
 * "ingest" (ACB hijack engine) and "backup" (native engine), and by the GUI
 * pages for restore/backup-now. Also usable by hand from a shell.
 *
 * Usage: configbackup.php <command> [args]
 *   ingest                 ingest staged ACB backup pairs into our database
 *   backup [reason]        take a backup of the current config.xml (skips
 *                          identical consecutive backups - cron path)
 *   backupforce [reason]   like backup, but always stores (manual path)
 *   restore <id>           restore a stored backup (takes a safety backup first!)
 *   list [n]               list the newest n (default 20) backups
 *   prune                  apply retention now
 *   cron-apply             (re)install cron entries per current settings
 *   cron-remove            remove our cron entries (deinstall path)
 *   status                 show engines, backend and row count
 */

require_once('config.inc');
require_once('/usr/local/pfsense-configbackup/share/configbackup_lib.php');

function cb_out($s) {
	echo $s . "\n";
}

$cmd = isset($argv[1]) ? $argv[1] : '';
$arg = isset($argv[2]) ? $argv[2] : '';

switch ($cmd) {
	case 'ingest':
		list($n, $msg) = cb_ingest_acb();
		if ($n > 0 || $msg !== '') {
			cb_out(($n > 0 ? "ingested {$n} backup(s)." : 'Nothing ingested.') .
				(($msg !== '') ? ' ' . $msg : ''));
		}
		break;

	case 'backup':
	case 'backupforce':
		list($id, $msg) = cb_backup_native($arg !== '' ? $arg : 'Manual CLI backup', $cmd === 'backupforce');
		if ($id) {
			cb_out(($cmd === 'backup' ? "Backup ok" : "Stored backup") . " #{$id}." .
				(($msg !== '') ? ' ' . $msg : ''));
		} else {
			cb_out('Backup failed: ' . $msg);
			exit(1);
		}
		break;

	case 'restore':
		if ($arg === '' || !ctype_digit($arg)) {
			cb_out('Usage: configbackup.php restore <id>');
			exit(1);
		}
		list($ok, $msg) = cb_restore($arg);
		cb_out($msg);
		exit($ok ? 0 : 1);
		break;

	case 'list':
		$limit = ctype_digit($arg) ? (int)$arg : 20;
		foreach (cb_store()->list_rows($limit) as $row) {
			cb_out(sprintf('#%d  %s  %-6s  %7s  %s  %s',
				$row['id'], cb_fmt_ts($row['ts']), $row['engine'],
				cb_fmt_size($row['size']), substr($row['sha256'], 0, 12), $row['reason']));
		}
		break;

	case 'prune':
		cb_out('Pruned ' . cb_prune() . ' row(s).');
		break;

	case 'cron-apply':
		cb_cron_apply();
		cb_out('Cron entries applied.');
		break;

	case 'cron-remove':
		cb_cron_remove();
		cb_out('Cron entries removed (built-in ACB uploader restored if enabled).');
		break;

	case 'status':
		cb_out('ACB hijack engine: ' . (cb_engine_acb() ? 'enabled' : 'disabled'));
		cb_out('Native engine:      ' . (cb_engine_native() ? 'enabled' : 'disabled'));
		cb_out('Backend:            ' . cb_backend_label());
		cb_out('Retention:          keep last ' . (int)cb_cfg('retention', CB_RETENTION_DEFAULT));
		cb_out('Stored backups:     ' . cb_store()->count());
		break;

	default:
		cb_out('Usage: configbackup.php <ingest|backup|backupforce|restore|list|prune|cron-apply|cron-remove|status> [args]');
		exit(1);
}
