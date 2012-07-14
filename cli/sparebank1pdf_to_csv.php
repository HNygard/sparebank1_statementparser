#!/usr/bin/php
<?php

define('SYSPATH', '');

require_once __DIR__.'/../classes/pdf2textwrapper.php';
require_once __DIR__.'/../classes/sparebank1/statementparser/core.php';
require_once __DIR__.'/../classes/sb1helper.php';
require_once __DIR__.'/../classes/sb1parser.php';

if(count($argv) != 2) {
	echo 'No argument given.'.chr(10);
	echo $_SERVER['PHP_SELF'].' [path to pdf]'.chr(10);
	echo chr(10).'One CSV file per account in the PDF file will be saved to the same location as the PDF file.'.chr(10);
	exit;
}
$file = $argv[1];
echo 'PDF location: '.$file.chr(10).chr(10);

echo 'READING FILE'.chr(10);
$parser = new sb1parser();
$parser->importPDF (file_get_contents($file));
echo ' ---- Finished reading file'.chr(10).chr(10);

echo 'Found the following accounts:'.chr(10);
foreach($parser->getAccounts() as $account) {
	echo $account['account_num'].' '.$account['account_type'].chr(10).
		'    From '.date('d.m.Y', $account['accountstatement_start']).
		' to '.date('d.m.Y', $account['accountstatement_end']). chr(10).
		'    Transactions: '.count($account['transactions']).
		chr(10).chr(10);

	$csv = $parser->getCSV($account);
	$csv_path = dirname($file).'/'.basename($file, '.pdf').' - '.$account['account_num'].'.csv';
	echo 'Saving to CSV: '.$csv_path.chr(10);
	if(file_exists($csv_path)) {
		echo 'ERROR: File already exists'.chr(10);
	}
	else {
		file_put_contents($csv_path, $csv);
	}

}
