<?php
/*
 * settings.php
 *
 * Settings page for the Config Backup package. Values are stored in
 * config.xml (installedpackages/configbackup/settings). Secrets are entered
 * masked; an empty field on save keeps the stored value and secrets are
 * never echoed back to the browser.
 *
 * Engines (both may be enabled):
 *   - ACB hijack ingest: removes pfSense's built-in acbupload.php cron entry
 *     and replaces it with our own every-minute ingest of the staged
 *     /cf/conf/acb/*.form + *.data pairs into the package database. Requires
 *     AutoConfigBackup to be enabled (it stages the backups).
 *   - Independent engine: gzip+encrypt backup of config.xml on its own
 *     schedule, no dependency on ACB.
 */
require_once('guiconfig.inc');
require_once('/usr/local/pfsense-configbackup/share/configbackup_lib.php');

$pgtitle = array(gettext('Diagnostics'), gettext('Config Backup'), gettext('Settings'));
$pglinks = array('', '@self', '@self');

$input_errors = array();
$savemsg = '';

if ($_POST['save']) {
	/* Validate */
	$retention = (int)$_POST['retention'];
	if ($retention < 1) {
		$input_errors[] = gettext('Retention must be at least 1.');
	}
	$natfreq = (int)$_POST['natfreq'];
	if ($natfreq < 1 || $natfreq > 24) {
		$input_errors[] = gettext('Backup interval must be between 1 and 24 hours.');
	}
	$natminute = trim((string)$_POST['natminute']);
	if (!preg_match('/^(\*|\d{1,2})$/', $natminute) ||
		($natminute !== '*' && ((int)$natminute < 0 || (int)$natminute > 59))) {
		$input_errors[] = gettext('Minute must be 0-59.');
	}
	if (!empty($_POST['mysql_host']) && ($_POST['backend'] === 'mysql') &&
		(empty($_POST['mysql_user']) || empty($_POST['mysql_db']))) {
		$input_errors[] = gettext('MySQL user and database are required for the MySQL backend.');
	}
	if ($_POST['backend'] === 'offbox' && !empty($_POST['offbox_target']) &&
		strpos($_POST['offbox_target'], ':') === false) {
		$input_errors[] = gettext('Off-box target must look like user@host:/path (the :/ is required).');
	}

	if (!$input_errors) {
		config_set_path('installedpackages/' . CB_NAME . '/settings/engine_acb',
			isset($_POST['engine_acb']) ? 'yes' : '');
		config_set_path('installedpackages/' . CB_NAME . '/settings/engine_native',
			isset($_POST['engine_native']) ? 'yes' : '');
		config_set_path('installedpackages/' . CB_NAME . '/settings/natfreq', $natfreq);
		config_set_path('installedpackages/' . CB_NAME . '/settings/natminute', $natminute);
		config_set_path('installedpackages/' . CB_NAME . '/settings/retention', $retention);
		config_set_path('installedpackages/' . CB_NAME . '/settings/backend', $_POST['backend']);
		config_set_path('installedpackages/' . CB_NAME . '/settings/mysql_host', trim($_POST['mysql_host']));
		config_set_path('installedpackages/' . CB_NAME . '/settings/mysql_port', trim($_POST['mysql_port']));
		config_set_path('installedpackages/' . CB_NAME . '/settings/mysql_user', trim($_POST['mysql_user']));
		config_set_path('installedpackages/' . CB_NAME . '/settings/mysql_db', trim($_POST['mysql_db']));
		config_set_path('installedpackages/' . CB_NAME . '/settings/offbox_target', trim($_POST['offbox_target']));
		config_set_path('installedpackages/' . CB_NAME . '/settings/offbox_mode', $_POST['offbox_mode']);
		/* Secrets: empty input keeps the stored value. */
		if ($_POST['natpw'] !== '') {
			config_set_path('installedpackages/' . CB_NAME . '/settings/natpw', $_POST['natpw']);
		}
		if ($_POST['mysql_password'] !== '') {
			config_set_path('installedpackages/' . CB_NAME . '/settings/mysql_password', $_POST['mysql_password']);
		}

		$savemsg = gettext('Settings saved. Cron entries updated:');
		write_config('Config Backup settings updated');
		cb_cron_apply();
	}
}

$pconfig = cb_settings();

function cb_password_attrs($key) {
	global $pconfig;
	/* Secrets are never echoed back; the placeholder says whether one is set.
	 * Empty input on save keeps the stored value. */
	return array(
		'autocomplete' => 'new-password',
		'placeholder' => empty($pconfig[$key]) ? gettext('Not set') : gettext('Stored - leave empty to keep'),
	);
}

$section = new Form_Section('Engines');
$section->addInput(new Form_Checkbox(
	'engine_acb',
	'ACB hijack ingest',
	'Ingest pfSense staged AutoConfigBackup backups into this package database instead of uploading them to the ACB cloud. Requires ACB to be enabled (Services > AutoConfigBackup > Settings). The built-in upload cron entry is removed and self-healing keeps it removed when ACB settings are saved.',
	cb_engine_acb()
));
$section->addInput(new Form_Checkbox(
	'engine_native',
	'Independent engine',
	'Take encrypted backups of config.xml on the schedule below. No dependency on AutoConfigBackup.',
	cb_engine_native()
));
$section->addInput(new Form_Select(
	'natfreq',
	'Independent engine interval',
	(int)cb_cfg('natfreq', 6),
	array('1' => 'Every hour', '2' => 'Every 2 hours', '4' => 'Every 4 hours',
		'6' => 'Every 6 hours', '12' => 'Every 12 hours', '24' => 'Every 24 hours')
));
$section->addInput(new Form_Input(
	'natminute',
	'Backup minute',
	'text',
	cb_cfg('natminute', '15')
))->setHelp('Minute of the hour the independent engine runs (0-59).');
$form = new Form();
$form->add($section);

$section = new Form_Section('Storage');
$section->addInput(new Form_Select(
	'backend',
	'Backend',
	cb_cfg('backend', 'sqlite'),
	array('sqlite' => 'SQLite (recommended)', 'mysql' => 'MySQL (via mysql CLI)', 'offbox' => 'SQLite + off-box copy')
))->setHelp('SQLite stores backups in /var/db/configbackup/configbackup.sqlite. MySQL shells out to the /usr/local/bin/mysql client (pfSense PHP has no pdo_mysql). Off-box copies every new backup to a remote target with scp/rsync (key-based ssh required).');
$section->addInput(new Form_Input(
	'mysql_host',
	'MySQL host',
	'text',
	cb_cfg('mysql_host')
));
$section->addInput(new Form_Input(
	'mysql_port',
	'MySQL port',
	'text',
	cb_cfg('mysql_port', '3306')
));
$section->addInput(new Form_Input(
	'mysql_user',
	'MySQL user',
	'text',
	cb_cfg('mysql_user')
));
$section->addInput(new Form_Input(
	'mysql_password',
	'MySQL password',
	'password',
	'',
	cb_password_attrs('mysql_password')
))->setHelp('Leave empty to keep the stored password.');
$section->addInput(new Form_Input(
	'mysql_db',
	'MySQL database',
	'text',
	cb_cfg('mysql_db', CB_NAME)
));
$section->addInput(new Form_Select(
	'offbox_mode',
	'Off-box copy method',
	cb_cfg('offbox_mode', 'scp'),
	array('scp' => 'scp', 'rsync' => 'rsync')
));
$section->addInput(new Form_Input(
	'offbox_target',
	'Off-box target',
	'text',
	cb_cfg('offbox_target')
))->setHelp('user@host:/path - key-based ssh authentication must already work for root on this firewall.');
$form->add($section);

$section = new Form_Section('Retention and encryption');
$section->addInput(new Form_Input(
	'retention',
	'Retention (backups to keep)',
	'number',
	(int)cb_cfg('retention', CB_RETENTION_DEFAULT),
	['min' => 1]
))->setHelp('Older backups are pruned automatically after every ingest/backup.');
$section->addInput(new Form_Input(
	'natpw',
	'Package encryption password',
	'password',
	'',
	cb_password_attrs('natpw')
))->setHelp('Encrypts every stored backup (including ACB ingest rows, which are re-encrypted after verification). Leave empty to keep the stored password. If this password is lost, stored backups cannot be decrypted.');
$form->add($section);

display_top_tabs($tab_array = array(
	array(gettext('Config Backup'), false, '/packages/configbackup/index.php'),
	array(gettext('Settings'), true, '/packages/configbackup/settings.php'),
));

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
print $form;
include('foot.inc');
