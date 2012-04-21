#!/usr/bin/php
<?php

define('SYSPATH', '');

require_once __DIR__.'/../classes/pdf2textwrapper.php';
require_once __DIR__.'/../classes/sparebank1/statementparser/core.php';
require_once __DIR__.'/../classes/sb1helper.php';
require_once __DIR__.'/../classes/sb1parser.php';

if(count($argv) != 2) {
	echo 'No argument given.'.chr(10);
	echo './sb1cli.php [path to pdf]'.chr(10);
	exit;
}

$parser = new sb1parser();
$parser->importPDF (file_get_contents($argv[1]));
foreach($parser->getAccounts() as $account) {
	echo $account['account_num'].' '.$account['account_type'].chr(10).
		'    From '.date('d.m.Y', $account['accountstatement_start']).
		' to '.date('d.m.Y', $account['accountstatement_end']). chr(10).
		'    Transactions: '.count($account['transactions']).
		chr(10).chr(10);
}
