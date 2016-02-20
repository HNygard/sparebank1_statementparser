#!/usr/bin/php
<?php

date_default_timezone_set('Europe/Oslo');

define('SYSPATH', '');


$grouped_files = array();
$files = scandir(getcwd());
foreach($files as $file) {
	if (strpos($file, ' - ') === false || strpos($file, '.csv') === false) {
		// -> Not the right file
		continue;
	}
	
	$group_name = substr($file, strrpos($file, ' - '));
	if(!isset($grouped_files[$group_name])) {
		$grouped_files[$group_name] = array('name' => $group_name, 'files' => array());
	}
	$grouped_files[$group_name]['files'][] = $file;
}

var_dump($grouped_files);
if(count($argv) != 2 || $argv[1] != '--run') {
	echo 'No argument given. Halting. Add --run to execute.'.chr(10);
	exit;
}

foreach($grouped_files as $group) {
	$header = false;
	$group['new_lines'] = array();
	foreach($group['files'] as $file) {
		echo 'READING FILE - ' . $file . chr(10);
		$lines = file($file);
		foreach($lines as $key => $line) {
			if($key == 0 && $header) {
				continue;
			}
			$header = true;
			$group['new_lines'][] = trim($line);
		}
	}

	$new_filename = 'combined-'.time().' - ' . $group['name'];
	echo 'WRITING ['. count($group['new_lines']).'] CSV LINES TO ['.$new_filename.']'.chr(10);
	file_put_contents($new_filename, implode(chr(10), $group['new_lines']));
}
