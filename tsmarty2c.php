#!/usr/bin/env php
<?php
// smarty open tag
$ldq = preg_quote('{');

// smarty close tag
$rdq = preg_quote('}');

// smarty command
$cmd = preg_quote('t');

// extensions of smarty files, used when going through a directory
$extensions = array('tpl');

// we msgcat found strings from each file.
// need header for each temporary .pot file to be merged.
// https://help.launchpad.net/Translations/YourProject/PartialPOExport
define('MSGID_HEADER', 'msgid ""
msgstr "Content-Type: text/plain; charset=UTF-8\n"

');

// "fix" string - strip slashes, escape and convert new lines to \n
function fs($str) {
	$str = stripslashes($str);
	$str = str_replace('"', '\"', $str);
	$str = str_replace("\n", '\n', $str);
	return $str;
}

function lineno_from_offset($content, $offset) {
	return substr_count($content, "\n", 0, $offset) + 1;
}

function msgmerge($outfile, $data) {
	// skip empty
	if (empty($data)) {
		return;
	}

	// write new data to tmp file
	$tmp = tempnam(TMPDIR, 'tsmarty2c');
	file_put_contents($tmp, $data);

	// temp file for result cat
	$tmp2 = tempnam(TMPDIR, 'tsmarty2c');
	passthru('msgcat -o '.escapeshellarg($tmp2).' '.escapeshellarg($outfile).' '.escapeshellarg($tmp), $rc);
	unlink($tmp);

	if ($rc) {
		fwrite(STDERR, "msgcat failed with $rc\n");
		exit($rc);
	}

	// rename if output was produced
	if (file_exists($tmp2)) {
		rename($tmp2, $outfile);
	}
}

// rips gettext strings from $file and prints them in C format
function do_file($outfile, $file) {
	$content = file_get_contents($file);

	if (empty($content)) {
		return;
	}

	global $ldq, $rdq, $cmd;

	preg_match_all(
		"/{$ldq}\s*({$cmd})\s*([^{$rdq}]*){$rdq}+([^{$ldq}]*){$ldq}\/\\1{$rdq}/",
		$content,
		$matches,
		PREG_OFFSET_CAPTURE
	);

	$msgids = array();
	$msgids_plural = array();
	for ($i = 0; $i < count($matches[0]); $i++) {
		if (preg_match('/plural\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
			$msgid = $matches[3][$i][0];
			$msgids_plural[$msgid] = $match[1];
		} else {
			$msgid = $matches[3][$i][0];
		}

		$lineno = lineno_from_offset($content, $matches[2][$i][1]);
		$msgids[$msgid][] = "$file:$lineno";
	}

	ob_start();
	echo MSGID_HEADER;
	foreach ($msgids as $msgid => $files) {
		echo "#: ", join(' ', $files), "\n";
		if (isset($msgids_plural[$msgid])) {
			echo 'msgid "'.fs($msgid).'"', "\n";
			echo 'msgid_plural "'.fs($msgids_plural[$msgid]).'"', "\n";
			echo 'msgstr[0] ""', "\n";
			echo 'msgstr[1] ""', "\n";
		} else {
			echo 'msgid "'.fs($msgid).'"', "\n";
			echo 'msgstr ""', "\n";
		}
		echo "\n";
	}

	$out = ob_get_contents();
	ob_end_clean();
	msgmerge($outfile, $out);
}

// go through a directory
function do_dir($outfile, $dir) {
	$d = dir($dir);

	while (false !== ($entry = $d->read())) {
		if ($entry == '.' || $entry == '..') {
			continue;
		}

		$entry = $dir.'/'.$entry;

		if (is_dir($entry)) { // if a directory, go through it
			do_dir($outfile, $entry);
		} else { // if file, parse only if extension is matched
			$pi = pathinfo($entry);

			if (isset($pi['extension']) && in_array($pi['extension'], $GLOBALS['extensions'])) {
				do_file($outfile, $entry);
			}
		}
	}

	$d->close();
}

if ('cli' != php_sapi_name()) {
	error_log("ERROR: This program is for command line mode only.");
	exit(1);
}

define('PROGRAM', basename(array_shift($argv)));
define('TMPDIR', sys_get_temp_dir());
$opt = getopt('o:');
$outfile = isset($opt['o']) ? $opt['o'] : tempnam(TMPDIR, 'tsmarty2c');

// remove -o FILENAME from $argv.
if (isset($opt['o'])) {
	foreach ($argv as $i => $v) {
		if ($v != '-o') {
			continue;
		}

		unset($argv[$i]);
		unset($argv[$i + 1]);
		break;
	}
}

// initialize output
file_put_contents($outfile, MSGID_HEADER);

// process dirs/files
foreach ($argv as $arg) {
	if (is_dir($arg)) {
		do_dir($outfile, $arg);
	} else {
		do_file($outfile, $arg);
	}
}

// output and cleanup
if (!isset($opt['o'])) {
	echo file_get_contents($outfile);
	unlink($outfile);
}
