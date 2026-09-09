<?php
/*
 * configbackup_lib.php
 *
 * Shared engine/storage library for the pfSense-pkg-configbackup package.
 * Loaded by the CLI tool (bin/configbackup.php) and the GUI pages
 * (www/packages/configbackup/*.php). Requires config.inc to be loaded
 * (it is loaded on demand here when missing).
 *
 * Storage model: every backup row stores the config.xml encrypted with the
 * package encryption password (openssl aes-256-cbc + pbkdf2 via pfSense's
 * encrypt_data(), gzip applied first, base64 text blob). Plaintext never
 * touches the database. Rows are uniform, so restore/download has one code
 * path for both engines.
 *
 * Engines:
 *   - "acb"     : hijacks pfSense AutoConfigBackup staging. The built-in
 *                 acbupload.php cron entry is removed and replaced by our
 *                 every-minute ingest that pulls the staged
 *                 /cf/conf/acb/*.form + *.data pairs into our database
 *                 BEFORE deleting them (the built-in deletes them
 *                 unconditionally, which loses backups during a cloud
 *                 outage). Requires ACB to be enabled (it stages the files).
 *   - "native"  : independent gzip+encrypt backup of config.xml on its own
 *                 cron schedule.
 *
 * Backends: sqlite (recommended), mysql (via the mysql CLI - pfSense PHP has
 * no pdo_mysql), offbox (sqlite + copy of each backup to a remote target
 * with scp/rsync).
 */

if (!function_exists('config_get_path')) {
	require_once('config.inc');
}

if (!defined('CB_NAME')) {
	define('CB_NAME', 'configbackup');
	define('CB_BASE', '/usr/local/pfsense-configbackup');
	define('CB_LIB', CB_BASE . '/share/configbackup_lib.php');
	define('CB_BIN', CB_BASE . '/bin/configbackup.php');
	define('CB_DB_DIR', '/var/db/configbackup');
	define('CB_DB_PATH', CB_DB_DIR . '/configbackup.sqlite');
	define('CB_TABLE', 'configbackup');
	/* The built-in ACB upload cron job (services/acb.inc setup_ACB()). */
	define('CB_ACBCMD', '/usr/bin/nice -n20 /usr/local/bin/php /usr/local/sbin/acbupload.php');
	/* Our replacement: ingest staged ACB pairs every minute. */
	define('CB_INGESTCMD', '/usr/local/bin/php ' . CB_BIN . ' ingest');
	/* Independent scheduled backup. */
	define('CB_BACKUPCMD', '/usr/local/bin/php ' . CB_BIN . ' backup');
	/* Weekly restore self-test. */
	define('CB_VERIFYCMD', '/usr/local/bin/php ' . CB_BIN . ' verify');
	/* Defaults. */
	define('CB_RETENTION_DEFAULT', 120);
}

/* restore_rrddata()/restore_sshdata()/restore_xmldatafile() live in the www
 * include set, not in /etc/inc. */
if (!function_exists('restore_rrddata')) {
	@include_once('/usr/local/pfSense/include/www/backup.inc');
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function cb_settings() {
	$s = config_get_path('installedpackages/' . CB_NAME . '/settings', array());
	return is_array($s) ? $s : array();
}

function cb_cfg($key, $default = '') {
	$s = cb_settings();
	if (!array_key_exists($key, $s)) {
		return $default;
	}
	$v = $s[$key];
	if (is_array($v)) {
		return $default;
	}
	if ($v === null || $v === '') {
		return $default;
	}
	return $v;
}

function cb_engine_acb() {
	return cb_cfg('engine_acb') === 'yes';
}

function cb_engine_native() {
	return cb_cfg('engine_native') === 'yes';
}

function cb_natpw() {
	return (string)cb_cfg('natpw');
}

/* ------------------------------------------------------------------ */
/* Storage: SQLite                                                     */
/* ------------------------------------------------------------------ */

class CBStoreSqlite {
	private $db;

	public function __construct() {
		if (!is_dir(CB_DB_DIR)) {
			@mkdir(CB_DB_DIR, 0700, true);
		}
		$this->db = new SQLite3(CB_DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		$this->db->busyTimeout(5000);
		$this->db->exec('PRAGMA journal_mode=WAL');
		$this->db->exec('CREATE TABLE IF NOT EXISTS backups (' .
			'id INTEGER PRIMARY KEY AUTOINCREMENT, ' .
			'ts INTEGER NOT NULL, ' .
			'engine TEXT NOT NULL, ' .
			'reason TEXT DEFAULT \'\', ' .
			'version TEXT DEFAULT \'\', ' .
			'size INTEGER NOT NULL, ' .
			'sha256 TEXT NOT NULL, ' .
			'data TEXT NOT NULL)');
		$this->db->exec('CREATE INDEX IF NOT EXISTS idx_backups_ts ON backups(ts)');
	}

	public function insert($ts, $engine, $reason, $version, $size, $sha256, $data) {
		$stmt = $this->db->prepare('INSERT INTO backups ' .
			'(ts, engine, reason, version, size, sha256, data) ' .
			'VALUES (?, ?, ?, ?, ?, ?, ?)');
		$stmt->bindValue(1, (int)$ts, SQLITE3_INTEGER);
		$stmt->bindValue(2, (string)$engine, SQLITE3_TEXT);
		$stmt->bindValue(3, (string)$reason, SQLITE3_TEXT);
		$stmt->bindValue(4, (string)$version, SQLITE3_TEXT);
		$stmt->bindValue(5, (int)$size, SQLITE3_INTEGER);
		$stmt->bindValue(6, (string)$sha256, SQLITE3_TEXT);
		$stmt->bindValue(7, (string)$data, SQLITE3_TEXT);
		$result = $stmt->execute();
		if ($result === false) {
			return false;
		}
		$id = $this->db->lastInsertRowID();
		$result->finalize();
		return $id;
	}

	/* Metadata only (no blob), newest first. */
	public function list_rows($limit = 0) {
		$sql = 'SELECT id, ts, engine, reason, version, size, sha256 ' .
			'FROM backups ORDER BY ts DESC, id DESC';
		if ($limit > 0) {
			$sql .= ' LIMIT ' . (int)$limit;
		}
		$rows = array();
		$result = $this->db->query($sql);
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$rows[] = $row;
		}
		$result->finalize();
		return $rows;
	}

	public function get($id) {
		$stmt = $this->db->prepare('SELECT id, ts, engine, reason, version, size, sha256, data ' .
			'FROM backups WHERE id = ?');
		$stmt->bindValue(1, (int)$id, SQLITE3_INTEGER);
		$result = $stmt->execute();
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$result->finalize();
		return ($row === false) ? null : $row;
	}

	public function latest() {
		$rows = $this->list_rows(1);
		return empty($rows) ? null : $rows[0];
	}

	public function sha_exists($engine, $sha256) {
		$stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM backups WHERE engine = ? AND sha256 = ?');
		$stmt->bindValue(1, (string)$engine, SQLITE3_TEXT);
		$stmt->bindValue(2, (string)$sha256, SQLITE3_TEXT);
		$result = $stmt->execute();
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$result->finalize();
		return !empty($row['c']);
	}

	public function count() {
		$result = $this->db->query('SELECT COUNT(*) AS c FROM backups');
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$result->finalize();
		return (int)($row['c'] ?? 0);
	}

	/* Keep the newest $keep rows. Returns number of rows pruned. */
	public function prune($keep) {
		$stmt = $this->db->prepare('DELETE FROM backups WHERE id NOT IN ' .
			'(SELECT id FROM backups ORDER BY ts DESC, id DESC LIMIT ?)');
		$stmt->bindValue(1, (int)$keep, SQLITE3_INTEGER);
		$stmt->execute();
		return $this->db->changes();
	}
}

/* ------------------------------------------------------------------ */
/* Storage: MySQL (via the mysql CLI - pfSense PHP lacks pdo_mysql)     */
/* ------------------------------------------------------------------ */

class CBStoreMysql {
	private $cmdbase;
	private $database;
	private $schema_ok = false;

	public function __construct() {
		$host = cb_cfg('mysql_host', 'localhost');
		$port = cb_cfg('mysql_port', '3306');
		$user = cb_cfg('mysql_user', 'root');
		$this->database = cb_cfg('mysql_db', CB_NAME);
		$this->cmdbase = '/usr/local/bin/mysql --host=' . escapeshellarg($host) .
			' --port=' . escapeshellarg($port) .
			' --user=' . escapeshellarg($user) .
			' ' . escapeshellarg($this->database);
	}

	/* All values are passed as 0xhex literals, so nothing can break out of
	 * the SQL. SQL is fed on stdin (blobs exceed the single-argument limit).
	 * The password travels via the MYSQL_PWD environment variable, never on
	 * the command line. */
	private function run($sql) {
		if (!$this->schema_ok) {
			$this->ensure_schema();
		}
		$env = array(
			'PATH' => '/usr/local/bin:/usr/bin:/bin',
			'HOME' => '/root',
			'MYSQL_PWD' => (string)cb_cfg('mysql_password'),
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$proc = proc_open($this->cmdbase . ' -N -B', $descriptors, $pipes, null, $env);
		if (!is_resource($proc)) {
			log_error('configbackup: cannot start mysql client');
			return false;
		}
		fwrite($pipes[0], $sql);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$rc = proc_close($proc);
		if ($rc != 0) {
			log_error('configbackup: mysql error: ' . trim((string)$stderr));
			return false;
		}
		return (string)$stdout;
	}

	private function ensure_schema() {
		$this->schema_ok = true;
		$this->run('CREATE TABLE IF NOT EXISTS ' . CB_TABLE . ' (' .
			'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
			'ts BIGINT UNSIGNED NOT NULL, ' .
			'engine VARCHAR(16) NOT NULL, ' .
			'reason VARCHAR(1024) DEFAULT \'\', ' .
			'version VARCHAR(64) DEFAULT \'\', ' .
			'size BIGINT UNSIGNED NOT NULL, ' .
			'sha256 CHAR(64) NOT NULL, ' .
			'data LONGTEXT NOT NULL, ' .
			'KEY ts (ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	private static function hexstr($s) {
		return '0x' . bin2hex((string)$s);
	}

	private static function unhexstr($s) {
		return @pack('H*', trim((string)$s));
	}

	public function insert($ts, $engine, $reason, $version, $size, $sha256, $data) {
		$sql = 'INSERT INTO ' . CB_TABLE . ' (ts, engine, reason, version, size, sha256, data) VALUES (' .
			(int)$ts . ',' . self::hexstr($engine) . ',' . self::hexstr($reason) . ',' .
			self::hexstr($version) . ',' . (int)$size . ',' . self::hexstr($sha256) . ',' .
			self::hexstr($data) . ');SELECT LAST_INSERT_ID();';
		$out = $this->run($sql);
		if ($out === false) {
			return false;
		}
		$lines = array_filter(explode("\n", trim((string)$out)), 'strlen');
		$last = trim((string)end($lines));
		return ($last !== '' && ctype_digit($last)) ? (int)$last : false;
	}

	public function list_rows($limit = 0) {
		$sql = 'SELECT id, ts, engine, HEX(reason), version, size, sha256 FROM ' . CB_TABLE .
			' ORDER BY ts DESC, id DESC';
		if ($limit > 0) {
			$sql .= ' LIMIT ' . (int)$limit;
		}
		$sql .= ';';
		$out = $this->run($sql);
		if ($out === false) {
			return array();
		}
		$rows = array();
		foreach (explode("\n", trim($out)) as $line) {
			if (trim($line) === '') {
				continue;
			}
			$f = explode("\t", $line);
			if (count($f) < 7) {
				continue;
			}
			$rows[] = array(
				'id' => (int)$f[0],
				'ts' => (int)$f[1],
				'engine' => $f[2],
				'reason' => self::unhexstr($f[3]),
				'version' => $f[4],
				'size' => (int)$f[5],
				'sha256' => $f[6],
			);
		}
		return $rows;
	}

	public function get($id) {
		$sql = 'SELECT id, ts, engine, HEX(reason), version, size, sha256, HEX(data) FROM ' . CB_TABLE .
			' WHERE id = ' . (int)$id . ';';
		$out = $this->run($sql);
		if ($out === false) {
			return null;
		}
		$line = trim((string)$out);
		if ($line === '') {
			return null;
		}
		$f = explode("\t", $line);
		if (count($f) < 8) {
			return null;
		}
		return array(
			'id' => (int)$f[0],
			'ts' => (int)$f[1],
			'engine' => $f[2],
			'reason' => self::unhexstr($f[3]),
			'version' => $f[4],
			'size' => (int)$f[5],
			'sha256' => $f[6],
			'data' => self::unhexstr($f[7]),
		);
	}

	public function latest() {
		$rows = $this->list_rows(1);
		return empty($rows) ? null : $rows[0];
	}

	public function sha_exists($engine, $sha256) {
		$sql = 'SELECT COUNT(*) FROM ' . CB_TABLE . ' WHERE engine=' . self::hexstr($engine) .
			' AND sha256=' . self::hexstr($sha256) . ';';
		$out = trim((string)$this->run($sql));
		return ($out !== '' && $out !== '0');
	}

	public function count() {
		$out = trim((string)$this->run('SELECT COUNT(*) FROM ' . CB_TABLE . ';'));
		return ctype_digit($out) ? (int)$out : 0;
	}

	public function prune($keep) {
		$sql = 'DELETE FROM ' . CB_TABLE . ' WHERE id NOT IN ' .
			'(SELECT id FROM (SELECT id FROM ' . CB_TABLE .
			' ORDER BY ts DESC, id DESC LIMIT ' . (int)$keep . ') AS keepset);';
		$this->run($sql);
		return 0;
	}
}

function cb_store() {
	static $store = null;
	if ($store === null) {
		$store = (cb_cfg('backend', 'sqlite') === 'mysql') ?
			new CBStoreMysql() : new CBStoreSqlite();
	}
	return $store;
}

/* Single-writer lock. Cron (every minute) and manual runs can overlap;
 * without this lock both would check-and-insert the same staged pair.
 * Returns true when the lock was acquired (call cb_unlock() when done),
 * false when another run is already in flight (caller exits quietly). */
function cb_lock() {
	if (!is_dir(CB_DB_DIR)) {
		@mkdir(CB_DB_DIR, 0700, true);
	}
	$fp = fopen(CB_DB_DIR . '/.lock', 'c');
	if (!$fp) {
		/* Without a lock file we must not risk double inserts. */
		return false;
	}
	if (!flock($fp, LOCK_EX | LOCK_NB)) {
		fclose($fp);
		return false;
	}
	$GLOBALS['cb_lock_fp'] = $fp;
	return true;
}

function cb_unlock() {
	if (!empty($GLOBALS['cb_lock_fp'])) {
		flock($GLOBALS['cb_lock_fp'], LOCK_UN);
		fclose($GLOBALS['cb_lock_fp']);
		$GLOBALS['cb_lock_fp'] = null;
	}
}

/* ------------------------------------------------------------------ */
/* Crypto                                                              */
/* ------------------------------------------------------------------ */

/* Package blob = base64(openssl(gzip(config.xml))). Reuses pfSense's own
 * crypt_data() machinery (aes-256-cbc, -pbkdf2, -md sha256). */
function cb_encrypt_store($plain) {
	$gz = gzencode($plain);
	if ($gz === false) {
		return '';
	}
	return encrypt_data($gz, cb_natpw());
}

function cb_decrypt_blob($blob) {
	/* decrypt_data() takes its first argument by reference - the blob must
	 * be a variable, not an expression. */
	$b = (string)$blob;
	$gz = decrypt_data($b, cb_natpw());
	if ($gz === '' || $gz === null) {
		return null;
	}
	$plain = @gzdecode($gz);
	return ($plain === false) ? null : $plain;
}

/* ------------------------------------------------------------------ */
/* Off-box copy                                                        */
/* ------------------------------------------------------------------ */

/* Push one stored backup to the off-box target. Requires passwordless
 * (key-based) ssh to the target - an admin-provided "user@host:/path"
 * string. $plain (plaintext config.xml, when the caller has it) enables the
 * optional XML copies selected by the offbox_xml setting:
 *   none  - only the encrypted .cbk blob
 *   enc   - additionally the XML in the standard encrypted tagfile format
 *           (same package password, decryptable with openssl on any machine)
 *   plain - additionally the raw config.xml (WARNING: plaintext secrets)
 *   both  - enc + plain
 * Every push is verified (remote file size must match); on mismatch the
 * push is retried once and the broken remote file is removed on final
 * failure. After a successful push run, files beyond the retention setting
 * are pruned from the target directory (our naming pattern only). Failures
 * are logged, never fatal. */
function cb_offbox_push($row, $plain = null) {
	if (cb_cfg('backend') !== 'offbox') {
		return;
	}
	$target = trim((string)cb_cfg('offbox_target'));
	if ($target === '' || strpos($target, ':') === false) {
		log_error('configbackup: offbox backend selected but offbox_target is missing or invalid');
		return;
	}
	list($userhost, $rpath) = cb_offbox_split_target($target);
	if ($userhost === '' || $rpath === '') {
		log_error('configbackup: offbox_target invalid: ' . $target);
		return;
	}
	$mode = cb_cfg('offbox_mode', 'scp');
	$safe_engine = preg_replace('/[^a-z]/', '', (string)$row['engine']);
	$base = 'configbackup-' . date('Ymd-His', (int)$row['ts']) . '-' . $safe_engine .
		'-' . (int)$row['id'];

	$files = array($base . '.cbk' => (string)$row['data']);
	$xmlmode = cb_cfg('offbox_xml', 'none');
	if ($plain !== null && ($xmlmode === 'enc' || $xmlmode === 'both')) {
		$enc = encrypt_data($plain, cb_natpw());
		tagfile_reformat($enc, $enc, 'config.xml');
		$files[$base . '.xml.enc'] = $enc;
	}
	if ($plain !== null && ($xmlmode === 'plain' || $xmlmode === 'both')) {
		$files[$base . '.xml'] = $plain;
	}

	$failures = array();
	$ok = array();
	foreach ($files as $name => $content) {
		$tmp = g_get('tmp_path') . '/' . $name;
		if (@file_put_contents($tmp, $content) === false) {
			log_error('configbackup: cannot write offbox staging file ' . $tmp);
			continue;
		}
		@chmod($tmp, 0600);
		$size = strlen($content);
		if ($size === 0) {
			/* Never push empty content - the verify below would compare
			 * 0 == 0 and mask a broken source. */
			@unlink($tmp);
			$failures[] = $name . ' (empty content, not pushed)';
			continue;
		}
		$done = false;
		$rsize = null;
		/* Two attempts: the target mount may be flipped by other backup
		 * software mid-transfer (scp can then exit 0 with a truncated or
		 * empty remote file), so verify the remote size, then re-verify
		 * after a settle delay - the flip can zero a file seconds after a
		 * completed transfer. */
		for ($attempt = 0; $attempt < 2 && !$done; $attempt++) {
			if ($attempt > 0) {
				sleep(3);
			}
			if ($mode === 'rsync' && is_executable('/usr/local/bin/rsync')) {
				$cmd = '/usr/local/bin/rsync -a --chmod=F600 ' . escapeshellarg($tmp) . ' ' .
					escapeshellarg($target . '/');
			} else {
				$cmd = '/usr/bin/scp -p -q -o BatchMode=yes -o ConnectTimeout=10 ' .
					escapeshellarg($tmp) . ' ' .
					escapeshellarg($userhost . ':' . $rpath . '/' . $name);
			}
			exec($cmd . ' 2>&1', $out, $rc);
			unset($out);
			if ($rc != 0) {
				continue;
			}
			$rsize = cb_offbox_remote_size($userhost, $rpath . '/' . $name);
			if ($rsize !== null && $rsize === $size) {
				sleep(2);
				$rsize = cb_offbox_remote_size($userhost, $rpath . '/' . $name);
				if ($rsize !== null && $rsize === $size) {
					$done = true;
				}
			}
		}
		@unlink($tmp);
		if ($done) {
			$ok[] = $name;
		} else {
			$failures[] = $name . ' (expected ' . $size . ' bytes, got ' .
				var_export($rsize, true) . ')';
			/* A truncated remote file is worse than none. */
			cb_offbox_remote_delete($userhost, $rpath . '/' . $name);
		}
	}
	if ($failures) {
		log_error('configbackup: offbox copy FAILED (' . implode('; ', $failures) . ')');
		cb_notify('offbox', 'off-box copy FAILED: ' . implode('; ', $failures));
	}
	if ($ok) {
		log_error('configbackup: offbox copy ok (' . implode(', ', $ok) . ')');
	}

	$retention = (int)cb_cfg('retention', CB_RETENTION_DEFAULT);
	if ($retention >= 1) {
		cb_offbox_prune($userhost, $rpath, $retention);
	}
}

/* Split "user@host:/path" (first colon; the path part never needs one). */
function cb_offbox_split_target($target) {
	$pos = strpos($target, ':');
	return array(substr($target, 0, $pos), rtrim(substr($target, $pos + 1), '/'));
}

/* Run a command on the off-box host. Returns array(rc, output-lines). */
function cb_offbox_ssh($userhost, $remotecmd) {
	$out = array();
	$rc = 1;
	exec('/usr/bin/ssh -o BatchMode=yes -o ConnectTimeout=5 ' .
		escapeshellarg($userhost) . ' ' . escapeshellarg($remotecmd) . ' 2>&1', $out, $rc);
	return array($rc, $out);
}

function cb_offbox_remote_size($userhost, $path) {
	list($rc, $out) = cb_offbox_ssh($userhost,
		'wc -c < ' . escapeshellarg($path));
	if ($rc != 0 || empty($out)) {
		return null;
	}
	$v = trim((string)$out[0]);
	return ctype_digit($v) ? (int)$v : null;
}

function cb_offbox_remote_delete($userhost, $path) {
	cb_offbox_ssh($userhost, 'rm -f ' . escapeshellarg($path));
}

/* Keep only the newest $retention backup groups (by our file-id) in the
 * target directory. Only touches files matching our exact naming pattern. */
function cb_offbox_prune($userhost, $rpath, $retention) {
	list($rc, $out) = cb_offbox_ssh($userhost, 'ls -1 ' . escapeshellarg($rpath));
	if ($rc != 0) {
		return;
	}
	$groups = array();
	foreach ($out as $line) {
		if (preg_match('/^configbackup-\d{8}-\d{6}-[a-z]+-(\d+)\.(cbk|xml|xml\.enc)$/', trim($line), $m)) {
			$groups[(int)$m[1]][] = trim($line);
		}
	}
	if (count($groups) <= $retention) {
		return;
	}
	krsort($groups);
	$dead_ids = array_slice(array_keys($groups), $retention);
	$n = 0;
	foreach ($dead_ids as $id) {
		foreach ($groups[$id] as $f) {
			cb_offbox_remote_delete($userhost, $rpath . '/' . $f);
			$n++;
		}
	}
	if ($n > 0) {
		log_error('configbackup: offbox pruned ' . $n . ' old file(s)');
	}
}

/* ------------------------------------------------------------------ */
/* Retention                                                           */
/* ------------------------------------------------------------------ */

function cb_prune() {
	$keep = (int)cb_cfg('retention', CB_RETENTION_DEFAULT);
	if ($keep < 1) {
		$keep = CB_RETENTION_DEFAULT;
	}
	return cb_store()->prune($keep);
}

/* ------------------------------------------------------------------ */
/* Notifications (failures only, throttled)                            */
/* ------------------------------------------------------------------ */

/* Send a failure notification through pfSense's configured email channel.
 * Throttled to one message per category per hour so a broken push target
 * (e.g. during an outage) cannot flood the inbox. */
function cb_notify($category, $message) {
	if (cb_cfg('notify_failures', 'yes') !== 'yes') {
		return;
	}
	$statefile = CB_DB_DIR . '/.notify-state';
	$state = json_decode((string)@file_get_contents($statefile), true);
	if (!is_array($state)) {
		$state = array();
	}
	$now = time();
	if (isset($state[$category]) && ($now - (int)$state[$category]) < 3600) {
		return;
	}
	$state[$category] = $now;
	@file_put_contents($statefile, json_encode($state), LOCK_EX);
	if (function_exists('notify_via_smtp')) {
		notify_via_smtp('Config Backup (' . $category . '): ' . $message, true);
	} else {
		log_error('configbackup: notification unavailable: ' . $message);
	}
}

/* ------------------------------------------------------------------ */
/* Engines                                                             */
/* ------------------------------------------------------------------ */

/* Ingest every staged AutoConfigBackup pair into our database, then delete
 * the staged files. This replaces the built-in acbupload.php run. The
 * database insert happens BEFORE the unlink (the built-in deletes the files
 * unconditionally, losing the backup when the ACB cloud is unreachable). */
function cb_ingest_acb() {
	cb_cron_assert();
	if (!cb_engine_acb()) {
		return array(0, 'ACB ingest engine is disabled');
	}
	if (!cb_lock()) {
		return array(0, 'Another ingest/backup run is in flight');
	}
	list($n, $msg) = cb_ingest_acb_locked();
	cb_unlock();
	return array($n, $msg);
}

function cb_ingest_acb_locked() {
	$acbpw = (string)config_get_path('system/acb/encryption_password', '');
	if ($acbpw === '') {
		return array(0, 'No ACB encryption password configured (Services > AutoConfigBackup > Settings)');
	}
	if (cb_natpw() === '') {
		return array(0, 'No package encryption password configured (Diagnostics > Config Backup > Settings)');
	}
	$dir = g_get('acbbackuppath');
	if (!is_dir($dir)) {
		return array(0, 'No ACB staging directory present');
	}
	$forms = glob($dir . '*.form');
	if (!is_array($forms)) {
		$forms = array();
	}
	if (empty($forms)) {
		return array(0, '');
	}
	/* Oldest first, mirroring the built-in uploader. */
	usort($forms, function ($a, $b) {
		return filemtime($a) - filemtime($b);
	});
	$store = cb_store();
	$n = 0;
	$msgs = array();
	foreach ($forms as $form) {
		$datafile = preg_replace('/\.form$/', '.data', $form);
		if (!file_exists($datafile)) {
			$msgs[] = 'orphan .form (no .data): ' . basename($form);
			continue;
		}
		$meta = json_decode(@file_get_contents($form), true);
		if (!is_array($meta)) {
			$meta = array();
		}
		$raw = @file_get_contents($datafile);
		if ($raw === false || $raw === '') {
			$msgs[] = 'unreadable .data: ' . basename($datafile);
			continue;
		}
		$b64 = '';
		if (!tagfile_deformat($raw, $b64, 'config.xml')) {
			$msgs[] = 'unparseable .data: ' . basename($datafile);
			continue;
		}
		$plain = decrypt_data($b64, $acbpw);
		if ($plain === '' || $plain === null) {
			$msgs[] = 'ACB decrypt failed (wrong ACB password?): ' . basename($datafile);
			continue;
		}
		$sha = hash('sha256', $plain);
		if (!empty($meta['sha256_hash']) && $meta['sha256_hash'] !== $sha) {
			$msgs[] = 'sha256 mismatch, skipping: ' . basename($datafile);
			continue;
		}
		$ts = filemtime($form);
		if (!$ts) {
			$ts = time();
		}
		$reason = (string)($meta['reason'] ?? '');
		if ($reason === '') {
			$reason = 'Staged ACB backup';
		}
		$version = (string)($meta['version'] ?? g_get('product_version'));
		if ($store->sha_exists('acb', $sha)) {
			$msgs[] = 'duplicate skipped: ' . basename($datafile);
		} else {
			$blob = cb_encrypt_store($plain);
			$id = $store->insert($ts, 'acb', $reason, $version, strlen($plain), $sha, $blob);
			if ($id) {
				$n++;
				$msgs[] = 'ingested #' . $id;
				if (cb_cfg('backend') === 'offbox') {
					cb_offbox_push(array(
						'id' => $id,
						'ts' => $ts,
						'engine' => 'acb',
						'data' => $blob,
					), $plain);
				}
			} else {
				$msgs[] = 'store insert FAILED, keeping staged files: ' . basename($datafile);
				continue; /* do not unlink - the data would be lost */
			}
		}
		unlink_if_exists($datafile);
		@unlink($form);
	}
	foreach ($msgs as $m) {
		log_error('configbackup: ' . $m);
	}
	cb_prune();
	return array($n, implode('; ', $msgs));
}

/* Take a backup of the current config.xml into the store. $force disables
 * the unchanged-config dedupe (used for manual backups and the pre-restore
 * safety backup). */
function cb_backup_native($reason = '', $force = false) {
	if (cb_natpw() === '') {
		return array(0, 'No package encryption password configured (Diagnostics > Config Backup > Settings)');
	}
	if (!cb_engine_native() && !$force) {
		return array(0, 'Native engine is disabled');
	}
	if (!cb_lock()) {
		return array(0, 'Another ingest/backup run is in flight');
	}
	list($id, $msg) = cb_backup_native_locked($reason, $force);
	cb_unlock();
	return array($id, $msg);
}

function cb_backup_native_locked($reason = '', $force = false) {
	$plain = @file_get_contents('/cf/conf/config.xml');
	if ($plain === false || $plain === '') {
		return array(0, 'Could not read /cf/conf/config.xml');
	}
	$sha = hash('sha256', $plain);
	$store = cb_store();
	if (!$force) {
		$latest = $store->latest();
		if ($latest && $latest['sha256'] === $sha) {
			return array((int)$latest['id'],
				'Config unchanged since backup #' . (int)$latest['id'] . ' - skipped');
		}
	}
	$ts = time();
	$blob = cb_encrypt_store($plain);
	$id = $store->insert($ts, 'native',
		($reason !== '') ? $reason : 'Scheduled config backup',
		(string)g_get('product_version'), strlen($plain), $sha, $blob);
	if (!$id) {
		return array(0, 'Failed to store backup row');
	}
	log_error('configbackup: stored native backup #' . $id);
	cb_prune();
	if (cb_cfg('backend') === 'offbox') {
		cb_offbox_push(array(
			'id' => $id,
			'ts' => $ts,
			'engine' => 'native',
			'data' => $blob,
		), $plain);
	}
	return array((int)$id, '');
}

/* ------------------------------------------------------------------ */
/* Restore self-test                                                   */
/* ------------------------------------------------------------------ */

/* Decrypt a stored backup and compare the sha256 of the plaintext with the
 * stored hash - proves the row is restorable without touching the system.
 * $id = 0 checks the newest row. Failures are notified (throttled). */
function cb_verify($id = 0) {
	$store = cb_store();
	if ($id > 0) {
		$row = $store->get((int)$id);
	} else {
		/* Newest row including the blob. */
		$rows = $store->list_rows(1);
		$row = empty($rows) ? null : $store->get((int)$rows[0]['id']);
	}
	if (!$row) {
		return array(false, 'no backup row found');
	}
	$plain = cb_decrypt_blob($row['data']);
	if ($plain === null) {
		cb_notify('verify', "decryption of backup #{$row['id']} FAILED (has the package encryption password changed since it was taken?)");
		return array(false, "backup #{$row['id']}: decryption FAILED");
	}
	$sha = hash('sha256', $plain);
	if ($sha !== $row['sha256']) {
		cb_notify('verify', "backup #{$row['id']} FAILED integrity check (sha256 mismatch) - stored data is corrupt");
		return array(false, "backup #{$row['id']}: sha256 MISMATCH (stored data corrupt)");
	}
	return array(true, "backup #{$row['id']} verified: decrypts cleanly, sha256 matches ({$row['size']} bytes, {$row['engine']} engine)");
}

/* ------------------------------------------------------------------ */
/* Cron management                                                     */
/* ------------------------------------------------------------------ */

/* Apply the cron entries that match the current settings. Note that
 * install_cron_job() with $active=false REMOVES an entry entirely. */
function cb_cron_apply() {
	if (cb_engine_native()) {
		$minute = (string)cb_cfg('natminute', '15');
		if (!preg_match('/^(\*|\d{1,2})$/', $minute)) {
			$minute = '15';
		}
		$freq = (int)cb_cfg('natfreq', 6);
		if ($freq < 1 || $freq > 24) {
			$freq = 6;
		}
		$hour = '*/' . $freq;
		install_cron_job(CB_BACKUPCMD, true, $minute, $hour);
	} else {
		install_cron_job(CB_BACKUPCMD, false);
	}
	if (cb_engine_acb()) {
		/* Hijack: remove the built-in uploader, install our ingest. */
		install_cron_job(CB_ACBCMD, false);
		install_cron_job(CB_INGESTCMD, true, '*');
	} else {
		install_cron_job(CB_INGESTCMD, false);
		if (config_path_enabled('system/acb/enable')) {
			/* Hand the upload cron back to the built-in ACB. */
			install_cron_job(CB_ACBCMD, true, '*');
		} else {
			install_cron_job(CB_ACBCMD, false);
		}
	}
	/* Weekly restore self-test: Sundays 04:17. */
	if (cb_cfg('verify_weekly', 'yes') === 'yes') {
		install_cron_job(CB_VERIFYCMD, true, '17', '4', '*', '*', '0');
	} else {
		install_cron_job(CB_VERIFYCMD, false);
	}
}

/* Self-healing, called on every ingest run: saving ACB settings in the GUI
 * re-installs the built-in upload cron (setup_ACB()), so re-assert the
 * hijack whenever the built-in entry reappears. */
function cb_cron_assert() {
	if (!cb_engine_acb()) {
		return;
	}
	$found_builtin = false;
	$found_ours = false;
	foreach (config_get_path('cron/item', array()) as $item) {
		$cmd = isset($item['command']) ? (string)$item['command'] : '';
		if (strpos($cmd, '/usr/local/sbin/acbupload.php') !== false) {
			$found_builtin = true;
		}
		if (strpos($cmd, 'pfsense-configbackup/bin/configbackup.php ingest') !== false) {
			$found_ours = true;
		}
	}
	if ($found_builtin) {
		install_cron_job(CB_ACBCMD, false);
		log_error('configbackup: re-asserted ACB upload cron hijack (setup_ACB() had re-installed it)');
	}
	if (!$found_ours) {
		install_cron_job(CB_INGESTCMD, true, '*');
		log_error('configbackup: restored missing ingest cron entry');
	}
}

/* Called on package deinstallation: remove our entries and restore the
 * built-in ACB uploader if ACB is enabled. */
function cb_cron_remove() {
	install_cron_job(CB_BACKUPCMD, false);
	install_cron_job(CB_INGESTCMD, false);
	install_cron_job(CB_VERIFYCMD, false);
	if (config_path_enabled('system/acb/enable')) {
		install_cron_job(CB_ACBCMD, true, '*');
	}
}

/* ------------------------------------------------------------------ */
/* Restore                                                             */
/* ------------------------------------------------------------------ */

/* Restore a stored backup. Safety first: a fresh backup of the CURRENT
 * configuration is stored before anything is touched. The restore follows
 * the same code path as Diagnostics > Backup & Restore (backup.inc execPost
 * full-configuration branch: config_install + package repo preservation +
 * dirty-restore marker + rrd/ssh extra-data extraction + console
 * configure). A reboot is required after a full restore. */
function cb_restore($id) {
	$id = (int)$id;
	$row = cb_store()->get($id);
	if (!$row) {
		return array(false, "Backup #{$id} not found");
	}
	$plain = cb_decrypt_blob($row['data']);
	if ($plain === null) {
		cb_notify('restore', "decryption of backup #{$id} failed on restore - has the package encryption password changed since this backup was taken?");
		return array(false, 'Decryption failed - has the package encryption password changed since this backup was taken?');
	}
	list($sid, $serr) = cb_backup_native('Pre-restore safety backup (before restore of #' . $id . ')', true);
	if (!$sid) {
		cb_notify('restore', 'safety backup failed, restore aborted: ' . $serr);
		return array(false, 'Safety backup failed, restore aborted: ' . $serr);
	}
	if (!stristr($plain, '<' . g_get('xml_rootobj') . '>')) {
		return array(false, 'Stored data is not a valid pfSense configuration (missing root tag)');
	}
	$tmp = tempnam(g_get('tmp_path'), 'cbrestore');
	file_put_contents($tmp, $plain);
	$rc = function_exists('config_install') ? config_install($tmp) : 1;
	@unlink($tmp);
	if ($rc != 0) {
		cb_notify('restore', "config_install() failed during restore of backup #{$id}");
		return array(false, 'config_install() failed - configuration not restored');
	}

	/* Mirror the diag_backup.php full-restore follow-ups. */
	$pkg_repo_conf_path = null;
	if (config_path_enabled('system', 'pkg_repo_conf_path')) {
		$pkg_repo_conf_path = config_get_path('system/pkg_repo_conf_path');
	}
	mark_subsystem_dirty('restore');
	@touch('/conf/needs_package_sync');
	unlink_if_exists(g_get('tmp_path') . '/config.cache');
	config_read_file(true);
	if ($pkg_repo_conf_path) {
		config_set_path('system/pkg_repo_conf_path', $pkg_repo_conf_path);
		write_config('Config Backup: preserving pkg repository setting after restore');
		/* pkg-utils.inc is not part of the default CLI include chain. */
		if (!function_exists('pkg_update')) {
			require_once('pkg-utils.inc');
		}
		pkg_update(true);
	}
	foreach (array('/boot/loader.conf', '/boot/loader.conf.local') as $lc) {
		if (!file_exists($lc)) {
			continue;
		}
		$loaderconf = file_get_contents($lc);
		if (strpos($loaderconf, 'console="comconsole') !== false ||
			strpos($loaderconf, 'boot_serial="YES') !== false) {
			config_set_path('system/enableserial', true);
			write_config('Config Backup: restore serial console enabling in configuration');
		}
	}
	$conf_change = false;
	if (config_get_path('rrddata')) {
		restore_rrddata();
		config_del_path('rrddata');
		$conf_change = true;
	}
	if (config_get_path('sshdata')) {
		restore_sshdata();
		config_del_path('sshdata');
		$conf_change = true;
	}
	foreach (g_get('backuppath', array()) as $bk => $path) {
		if (!empty(config_get_path("{$bk}/{$bk}data"))) {
			restore_xmldatafile($bk);
			config_del_path("{$bk}/{$bk}data");
			$conf_change = true;
		}
	}
	if ($conf_change) {
		write_config('Config Backup: unset RRD and extra data from configuration after restore');
		unlink_if_exists(g_get('tmp_path') . '/config.cache');
		convert_config();
	}
	console_configure();
	/* The restored configuration may predate this package: re-assert our
	 * cron entries based on the now-active settings. */
	cb_cron_apply();
	write_config('Configuration restored from Config Backup #' . $id);

	if (is_interface_mismatch() || is_interface_vlan_mismatch()) {
		@touch('/var/run/interface_mismatch_reboot_needed');
		return array(true, 'Restored (safety backup #' . $sid . '). Interface assignment mismatch detected - assign interfaces, then reboot.');
	}
	return array(true, 'Restored (safety backup #' . $sid . '). Reboot the firewall to complete the restore - packages resync on boot.');
}

/* ------------------------------------------------------------------ */
/* Presentational helpers (shared by CLI and GUI)                      */
/* ------------------------------------------------------------------ */

function cb_fmt_size($bytes) {
	$bytes = (int)$bytes;
	if ($bytes >= 1048576) {
		return sprintf('%.1f MiB', $bytes / 1048576);
	}
	if ($bytes >= 1024) {
		return sprintf('%.1f KiB', $bytes / 1024);
	}
	return $bytes . ' B';
}

function cb_fmt_ts($ts) {
	return date('Y-m-d H:i:s', (int)$ts);
}

function cb_backend_label() {
	switch (cb_cfg('backend', 'sqlite')) {
		case 'mysql':
			return 'MySQL (' . cb_cfg('mysql_host', 'localhost') . '/' . cb_cfg('mysql_db', CB_NAME) . ')';
		case 'offbox':
			return 'SQLite + off-box copy (' . cb_cfg('offbox_target') . ')';
		default:
			return 'SQLite (' . CB_DB_PATH . ')';
	}
}
