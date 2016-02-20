#!/usr/bin/php
<?php

/**
 * Loop through all files in current folder
 * @author hallvard.nygard@gmail.com
 */

$test_mode = false;

// $_SERVER['argv'] = array(
//                       'scriptname',
//                       'argument 1',
//                    );

unset($_SERVER['argv'][0]); // Remove scriptname
foreach($_SERVER['argv'] as $parameter) {
	
	if($parameter == '-t') {
		// -> Going into test mode
		$test_mode = true;
	}
}


$files = array();
$dir = opendir($_SERVER['PWD']);
while($name = readdir($dir)) {
	if(!is_dir($_SERVER['PWD'].'/'.$name)) {
		$exit_code = 0;
		if($test_mode) {
			passthru(__DIR__.'/sb1paymentmsg_pdf_to_csv.php -t "'.$name.'"', $exit_code);
		}
		else {
			passthru(__DIR__.'/sb1paymentmsg_pdf_to_csv.php "'.$name.'"', $exit_code);
		}
		if($exit_code > 0) {
			echo '------------------'.chr(10);
			echo 'Exit code ['.$exit_code.'] from ['.$name.']'.chr(10);
			exit($exit_code);
		}
	}
}


