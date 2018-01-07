<?php

require_once __DIR__ . '/statement-parser-exstream-pdf.php';
require_once __DIR__ . '/statement-parser-pre2008.php';

class sparebank1_statementparser_core
{
	protected $accounts;
	protected $transactions = array();
	protected $transactions_new = 0;
	protected $transactions_not_imported = 0;
	protected $transactions_already_imported = 0;
	
	protected $imported = false;

	/**
	 * Imports the PDF file from a string to internal variables
	 *
	 * @param   $infile String Content of a PDF. Can be loaded with file_get_contents('filename', FILE_BINARY)
	 * @return  Sparebank1_statementparser_core  Returns
     * @throws Exception If an error was detected in the parser.
	 */
	function importPDF ($infile) {
        // Creator "Exstream Dialogue Version 5.0.051" should work
        // Creator "HP Exstream Version 7.0.605" should work
        // Creator "M2PD API Version 3.0, build(some date)" should work. Used up to jan 2008.

        pdf2textwrapper::pdf2text_fromstring($infile); // Returns the text, but we are using the table

        if (
            pdf2textwrapper::$pdf_author == 'Registered to: EDB DRFT' &&
            (
                pdf2textwrapper::$pdf_creator == 'Exstream Dialogue Version 5.0.051' ||
                pdf2textwrapper::$pdf_creator == 'HP Exstream Version 7.0.605' ||
                pdf2textwrapper::$pdf_creator == 'HP Exstream Version 8.0.319 64-bit'
            )
        ) {
            // Parse and read Exstream PDF
            $this->accounts = sparebank1_statementparser_exstream_pdf::parseAndReadExstreamPdf($this->accountsTranslation);
        }
        elseif (
            (
                pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8p by GenText, inc.' || // 04.2005 and earlier
                pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8q by GenText, inc.'    // 05.2005 and later
            ) &&
            pdf2textwrapper::$pdf_author == 'GenText, inc.' &&
            substr(pdf2textwrapper::$pdf_creator, 0, strlen('M2PD API Version 3.0, build')) == 'M2PD API Version 3.0, build'
        ) {
            // Parse and read "M2PD API Version 3.0" (used up to jan 2008)
            $this->accounts = sparebank1_statementparser_pre2008::parseAndReadJan2008Pdf($this->accountsTranslation);
        }
        else {
            throw new Exception('Unknown/unsupported PDF creator.' .
                ' Creator:  ' . pdf2textwrapper::$pdf_creator .
                ' Author:   ' . pdf2textwrapper::$pdf_author .
                ' Producer: ' . pdf2textwrapper::$pdf_producer
            );
        }

        // Checking if the PDF is successfully parsed
        foreach ($this->accounts as $account) {
            // Checking if all parameters have been found
            if (!isset($account['accountstatement_balance_in'])) {
                throw new Exception('PDF parser failed. Can not find accountstatement_balance_in.');
            }
            if (!isset($account['accountstatement_balance_out'])) {
                throw new Exception('PDF parser failed. Can not find accountstatement_balance_out.');
            }
            if (!isset($account['accountstatement_start'])) {
                throw new Exception('PDF parser failed. Can not find accountstatement_start.');
            }
            if (!isset($account['accountstatement_end'])) {
                throw new Exception('PDF parser failed. Can not find accountstatement_end.');
            }

            // Checking if the found amount is the same as the control amount found on account statement
            // If not, the file is corrupt or parser has made a mistake
            if (round($account['control_amount'], 2) != $account['accountstatement_balance_out']) {
                var_dump($account);
                throw new Exception('PDF parser failed. Controlamount is not correct. ' .
                    'Controlamount, calculated: ' . $account['control_amount'] . '. ' .
                    'Balance out should be: ' . $account['accountstatement_balance_out'] . '.');
            }
        }

        // Great success!
        $this->imported = true;

        return $this;
    }


	
	/**
	 * Returns array with accounts found in the file. Can be multiple accounts per file.
	 *
	 * @return  array
	 */
	public function getAccounts() {
		$this->isImportedOrThrowException();
		return $this->accounts;
	}
	
	public function isImportedOrThrowException () {
		if(!$this->imported) {
            throw new Exception('PDF file is not imported');
        }
	}

    /**
     * Returns transactions from given account in CSV format
     *
     * @param   array   Account with transactions
     * @return string CSV
     * @throws Exception On missing transactions.
     */
	public static function getCSV($account) {
		if(!isset($account['transactions'])) {
            throw new Exception('CSV exporter failed. Given account has no transaction variable');
        }
		
		$csv = 'Date;Description;Amount'.chr(10);
		foreach($account['transactions'] as $transaction)
		{
			$csv .=
				date('d.m.Y', $transaction['payment_date']).';'.
				'"'.$transaction['description'].'";'.
				$transaction['amount'].chr(10);
		}
		return trim($csv);
	}

	private $accountsTranslation = array();
	
	/**
	 * Set a table for translating account names to database ids 
	 *
	 * Key is account "name" (e.g. 1234.21.1234)
	 * 
	 * @param  int[]  $accounts
	 */
	public function setAccountTranslation($accounts) {
		$this->accountsTranslation = $accounts;
	}
}
