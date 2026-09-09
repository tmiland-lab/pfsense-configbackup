<?php
/*
 * index.php
 *
 * Config Backup status page: list stored backups, take a manual backup,
 * restore, and download (package-encrypted or decrypted XML). Backups live
 * in the package's own database (SQLite or MySQL) - independent of the ACB
 * cloud service.
 */
require_once('guiconfig.inc');
require_once('/usr/local/pfsense-configbackup/share/configbackup_lib.php');

$pgtitle = array(gettext('Diagnostics'), gettext('Config Backup'));
$pglinks = array('', '@self');

$host = config_get_path('system/hostname') . '.' . config_get_path('system/domain');

function cb_download_name($row, $ext) {
	global $host;
	return "config-{$host}-" . date('YmdHis', (int)$row['ts']) . '-' . $row['engine'] . '-' . (int)$row['id'] . $ext;
}

$input_errors = array();
$savemsg = '';

if ($_POST) {
	if (isset($_POST['backupnow'])) {
		list($id, $err) = cb_backup_native('Manual backup (web UI)', true);
		if ($id) {
			$savemsg = gettext('Backup taken and stored as #' . $id . '.');
		} else {
			$input_errors[] = gettext($err);
		}
	} elseif ($_POST['action'] === 'restore') {
		$id = (int)$_POST['id'];
		if ($_POST['confirm'] !== 'yes') {
			$input_errors[] = gettext('Restore must be confirmed.');
		} else {
			list($ok, $msg) = cb_restore($id);
			if ($ok) {
				$savemsg = gettext($msg);
			} else {
				$input_errors[] = gettext($msg);
			}
		}
	} elseif ($_POST['action'] === 'downloadenc' || $_POST['action'] === 'downloaddec') {
		$row = cb_store()->get((int)$_POST['id']);
		if (!$row) {
			$input_errors[] = gettext('Backup not found.');
		} elseif ($_POST['action'] === 'downloadenc') {
			send_user_download('data', (string)$row['data'], cb_download_name($row, '.cbk'),
				'application/octet-stream');
		} else {
			$plain = cb_decrypt_blob($row['data']);
			if ($plain === null) {
				$input_errors[] = gettext('Decryption failed - has the package encryption password changed?');
			} else {
				send_user_download('data', $plain, cb_download_name($row, '.xml'), 'text/xml');
			}
		}
	}
}

display_top_tabs($tab_array = array(
	array(gettext('Config Backup'), true, '/packages/configbackup/index.php'),
	array(gettext('Settings'), false, '/packages/configbackup/settings.php'),
));

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
if (is_subsystem_dirty('restore')) {
	print_info_box(gettext('The configuration has been restored. ' .
		'The firewall should be rebooted to complete the restore - packages will resync on boot.') .
		'<br/><form action="diag_reboot.php" method="post">' .
		'<input type="hidden" name="Submit" value="Yes" />' .
		'<button type="submit" class="btn btn-danger">' . gettext('Reboot now') . '</button></form>', 'warning');
}

$engines = array();
$engines[] = gettext('ACB hijack ingest') . ': ' . (cb_engine_acb() ? gettext('enabled') : gettext('disabled'));
$engines[] = gettext('Independent engine') . ': ' . (cb_engine_native() ? gettext('enabled') : gettext('disabled'));
if (cb_engine_acb() && config_get_path('system/acb/encryption_password') === null) {
	$engines[] = gettext('WARNING: ACB ingest is enabled but no ACB encryption password is set (Services > AutoConfigBackup > Settings).');
}
if (cb_natpw() === '') {
	$engines[] = gettext('WARNING: no package encryption password set (Settings page). Nothing can be stored until it is configured.');
}

$form = new Form(false);
$section = new Form_Section('Status');
$section->addInput(new Form_StaticText(
	gettext('Engines'),
	implode('<br/>', $engines)
));
$section->addInput(new Form_StaticText(
	gettext('Storage'),
	gettext('Backend') . ': ' . cb_backend_label() . '<br/>' .
		gettext('Backups stored') . ': ' . cb_store()->count() . ' / ' .
		gettext('retention') . ' ' . (int)cb_cfg('retention', CB_RETENTION_DEFAULT)
));
$form->add($section);
$section = new Form_Section('Manual backup');
$section->addInput(new Form_Button(
	'backupnow',
	gettext('Backup now'),
	null,
	'fa-archive'
))->setHelp(gettext('Takes a backup of the current configuration into the package database right away.'));
$form->add($section);
print $form;

$rows = cb_store()->list_rows(200);
$total = cb_store()->count();
?>
<form method="post">
	<input type="hidden" name="action" value="noop" />
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?= gettext('Stored backups') ?></h2></div>
		<div class="panel-body"><?= sprintf(gettext('Showing the %1$s newest of %2$s stored backups.'), count($rows), $total) ?></div>
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed">
				<thead>
					<tr>
						<th><?= gettext('Date') ?></th>
						<th><?= gettext('Engine') ?></th>
						<th><?= gettext('Reason') ?></th>
						<th><?= gettext('pfSense version') ?></th>
						<th><?= gettext('Size') ?></th>
						<th><?= gettext('SHA256') ?></th>
						<th><?= gettext('Actions') ?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $row): ?>
					<tr>
						<td><?= cb_fmt_ts($row['ts']) ?></td>
						<td><?= htmlspecialchars($row['engine']) ?></td>
						<td><?= htmlspecialchars($row['reason']) ?></td>
						<td><?= htmlspecialchars($row['version']) ?></td>
						<td><?= cb_fmt_size($row['size']) ?></td>
						<td><code><?= htmlspecialchars(substr($row['sha256'], 0, 12)) ?></code></td>
						<td>
							<form method="post" class="configbackup-inlineform">
								<input type="hidden" name="action" value="restore" />
								<input type="hidden" name="id" value="<?= (int)$row['id'] ?>" />
								<input type="hidden" name="confirm" value="yes" />
								<button type="submit" class="btn btn-xs btn-danger configbackup-restore"
									onclick="return confirm('<?= gettext('Restore this backup? A safety backup of the current configuration is taken first. A reboot is required afterwards.') ?>')">
									<?= gettext('Restore') ?></button>
							</form>
							<form method="post" class="configbackup-inlineform">
								<input type="hidden" name="action" value="downloaddec" />
								<input type="hidden" name="id" value="<?= (int)$row['id'] ?>" />
								<button type="submit" class="btn btn-xs btn-default"><?= gettext('XML') ?></button>
							</form>
							<form method="post" class="configbackup-inlineform">
								<input type="hidden" name="action" value="downloadenc" />
								<input type="hidden" name="id" value="<?= (int)$row['id'] ?>" />
								<button type="submit" class="btn btn-xs btn-default"><?= gettext('.cbk') ?></button>
							</form>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty($rows)): ?>
					<tr><td colspan="7"><?= gettext('No backups stored yet.') ?></td></tr>
<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</form>

<script type="text/javascript">
//<![CDATA[
	/* Keep theme-agnostic styling: render the per-row forms on one line. */
	$('.configbackup-inlineform').css('display', 'inline-block').css('margin-left', '4px');
//]]>
</script>

<?php include('foot.inc'); ?>
