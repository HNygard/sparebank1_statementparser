<?php defined('SYSPATH') or die('No direct script access.');

require __DIR__ . '/classes.php';

function assertLineStartsWith($line_num, $item_num, $lines, $text) {
    $value = $lines[$line_num][$item_num];
    $value = substr($value, 0, strlen($text));
    if ($value !== $text) {
	throwException('did not start with ['.$text.']. It was ['.$value.']', $line_num, $item_num, $lines);
    }
}
function assertLineStartsWithAndGetValue($line_num, $item_num, $lines, $text) {
	assertLineStartsWith($line_num, $item_num, $lines, $text);
	return substr($lines[$line_num][$item_num], strlen($text));
}
function assertLineEquals($line_num, $item_num, $lines, $text) {
    $value = $lines[$line_num][$item_num];
    if ($value !== $text) {
	throwException('did not equal ['.$text.']. It was ['.$value.']', $line_num, $item_num, $lines);
    }
}
function assertLineConcat($line_num, $item_num_start, $item_num_stop, $lines, $text) {
    $value = concat($item_num_start, $item_num_stop, $lines[$line_num]);
    if ($value !== $text) {
	throwException('did not equal ['.$text.'] after concating from ['.$item_num_start.'] to ['.$item_num_stop.']. It was ['.$value.']', $line_num, $item_num_start, $lines);
    }
}
function concat($item_num_start, $item_num_end, $line) {
	$return = '';
	for ($i = $item_num_start; $i <= $item_num_end; $i++) {
		$return .= $line[$i];
	}
	return $return;
}
function isDate($value) {
	return strlen($value) === strlen('dd.mm.YYYY')
		&& is_numeric(substr($value, 0, 1))
		&& is_numeric(substr($value, 1, 1))
		&&            substr($value, 2, 1) === '.'
		&& is_numeric(substr($value, 3, 1))
		&& is_numeric(substr($value, 4, 1))
		&&            substr($value, 5, 1) === '.'
		&& is_numeric(substr($value, 6, 1))
		&& is_numeric(substr($value, 7, 1))
		&& is_numeric(substr($value, 8, 1))
		&& is_numeric(substr($value, 9, 1));
}
/**
 * Get a date from the lines and assert it is dd.mm.YYYY
 */
function assertAndGetDate($line_num, $item_num, $lines) {
    $value = $lines[$line_num][$item_num];
    if (!isDate($value)) {
	throwException('was not a date (dd.mm.YYYY). It was ['.$value.']', $line_num, $item_num, $lines);
    }
    return $value;
}
function isAmount($value) {
	$mixed_value = str_replace('.', '', $value);
	$mixed_value2 = str_replace(',', '.', $mixed_value);
	return (
		// All number should be 2 decimals. Assert for the decimal char
		substr($mixed_value, strlen($mixed_value) - 3, 1) === ','
		// By now, this should only be a number in format 1234.12
		&& is_numeric($mixed_value2)
	);
}
/**
 * Get a amount and assert it is valid with . as thousand sep and , as decimal (1.234,12)
 */
function assertAndGetAmount($line_num, $item_num, $lines) {
    $value = $lines[$line_num][$item_num];
    $mixed_value = str_replace('.', '', $value);
    $mixed_value2 = str_replace(',', '.', $mixed_value);
    if (!isAmount($value)) {
	throwException('was not an amount with 2 decimals (1.234,12). It was ['.$value.']', $line_num, $item_num, $lines);
    }
    return $mixed_value2;
}

function throwException($text, $line_num, $item_num, $lines) {
	throw new Exception('Line ['.$line_num.'] item ['.$item_num.'] ' . $text . '. The line: '.print_r($lines[$line_num], true));
}

class sparebank1_paymentmessage_core
{
	protected $parsedPdf;
	protected $transactions = array();
	protected $transactions_new = 0;
	protected $transactions_not_imported = 0;
	protected $transactions_already_imported = 0;
	
	protected $imported = false;
	
	private static $lasttransactions_description;
	private static $lasttransactions_type;
	private static $lasttransactions_interest_date;
	private static $lasttransactions_payment_date;
	
	/**
	 * Imports the PDF file from a string to internal variables
	 *
	 * @param   String                           file_get_contents('filename', FILE_BINARY)
	 * @return  Sparebank1_statementparser_core  Returns 
	 */
	function importPDF ($infile)
	{
		// Creator "Exstream Dialogue Version 5.0.051" should work
		// Creator "HP Exstream Version 7.0.605" should work
		// Creator "M2PD API Version 3.0, build(some date)" should work. Used up to jan 2008.
		
		pdf2textwrapper::pdf2text_fromstring($infile); // Returns the text, but we are using the table
		
		$this->parsedPdf = new Sparebank1Pdf();
		if(
			pdf2textwrapper::$pdf_author === 'Registered to: EDB DRFT' &&
			pdf2textwrapper::$pdf_creator === 'HP Exstream Version 7.0.605'
		){
			$this->parsePdf($infile);
		}/*
		elseif (
			(
				pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8p by GenText, inc.' || // 04.2005 and earlier
				pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8q by GenText, inc.'    // 05.2005 and later
			) &&
			pdf2textwrapper::$pdf_author   == 'GenText, inc.' &&
			substr(pdf2textwrapper::$pdf_creator, 0, strlen('M2PD API Version 3.0, build')) == 'M2PD API Version 3.0, build'
		) {
			// Parse and read "M2PD API Version 3.0" (used up to jan 2008)
			$this->parseAndReadJan2008Pdf($infile);
		}*/
		else {
			throw new Exception('Unknown/unsupported PDF creator.'.
				' Creator:  '.pdf2textwrapper::$pdf_creator .
				' Author:   '.pdf2textwrapper::$pdf_author  .
				' Producer: '.pdf2textwrapper::$pdf_producer
				);
		}

		// Great success!
		$this->imported = true;
		
		return $this;
	}

	private $currentDocument;

	/**
	 * Creator:  HP Exstream Version 7.0.605 Author:   Registered to: EDB DRFT Producer:
	 */
	private function parsePdf($infile) {

		$lines = pdf2textwrapper::$table;
		$i = 0;
		$i = $this->detectNewDocument ($i, $lines);

		$bankaccount_number = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Innbetalingsoversikt for konto: ');
		echo 'Bank account number ... : ' . $bankaccount_number . chr(10);
		
		// Detect payments by looking at the header
		assertLineConcat($i++, 0, 16, $lines, 'Betalar:Mottakar:');
		$end_of_file = false;
		while(!$end_of_file) {
			echo '- '.chr(10);
			$payment_date = assertAndGetDate($i++, 0, $lines);
			$payment_amount = assertAndGetAmount($i++, 0, $lines);
			echo 'Payment date ........ : ' . $payment_date . chr(10);
			echo 'Payment amount ...... : ' . $payment_amount . chr(10);

			// Payment from address might be splitted into two $lines
			// The first might contain 1-3 items

			// 0 = Pos 1 - Payment from name
			// 1 = Pos 1 - Payment from street address
			// 2 = Pos 1 - Payment from postal code and place
			// 3 = Pos 2 - Payment to name
			// 4 = Pos 2 - Payment to street address
			$payment_from = array();
			$payment_to = array();

			// pdf2textwrapper::$table_pos[$i][0][2] = 656 42 Td (Payment to name) Tj
			//                                       = 656 84 Td (Payment to name) Tj
			$payment_to_starts_with = pdf2textwrapper::$table_pos[$i][0][1];
			$payment_to_starts_with = substr($payment_to_starts_with, strpos($payment_to_starts_with, 'Td (') + strlen('Td ('));
			$payment_to_starts_with = str_replace(') Tj', '', $payment_to_starts_with);
			$payment_to_starts_with = pdf2textwrapper::fixEscape($payment_to_starts_with);
			$payment_to_detected = false;
			for($j = 0; $j < count($lines[$i]); $j++) {
				if($lines[$i][$j] == $payment_to_starts_with) {
					$payment_to_detected = true;
				}
				if($payment_to_detected) {
					$payment_to[] = $lines[$i][$j];
				}
				else {
					$payment_from[] = $lines[$i][$j];
				}
			}
			$i++;
			

			assertLineConcat($i, 0, 10, $lines, 'Valutadato:');
			$payment_value_date = assertAndGetDate($i, 11, $lines);
			echo 'Payment value date .. : ' . $payment_value_date . chr(10);

			// 4 options here:
			// - 12 = payment from postal code and place
			//   13 = payment to postal code and place
			//   14 = payment reference number
			// - 12 = payment from postal code and place
			//   13 = payment reference number
			// - 12 = payment to postal code and place
			//   13 = payment reference number
			// - 12 = payment reference number
			// => Check position of 12
			if (pdf2textwrapper::$table_pos[$i][1][12] === '197') {
				// -> We have payment from on pos 12
				$payment_from[] = $lines[$i][12];
				if (count($lines[$i]) == 14) {
					$payment_bank_ref = $lines[$i][13];
				}
				else {			
					$payment_to[] = $lines[$i][13];
					$payment_bank_ref = $lines[$i][14];
				}
			}
			else {
				// -> No payment from on pos 12
				if (count($lines[$i]) == 13) {
					$payment_bank_ref = $lines[$i][12];
				}
				else {			
					$payment_to[] = $lines[$i][12];
					$payment_bank_ref = $lines[$i][13];
				}
			}
			$payment_bank_ref = trim($payment_bank_ref);
			echo 'Payment bank ref .... : ' . $payment_bank_ref . chr(10);
			echo 'Payment from: '.chr(10) . '    '.implode(chr(10) . '    ', $payment_from).chr(10);
			echo 'Payment to: '.chr(10) . '    '.implode(chr(10) . '    ', $payment_to).chr(10);
			$i++;

			assertLineConcat($i, 0, 8, $lines, 'Frakonto:');
			$payment_from_bank_account = $lines[$i++][9];
			echo 'Payment from bank account .. : ' . $payment_from_bank_account . chr(10);

			// :: Payment message
			$payment_message = array();
			if ($lines[$i][0] === 'Beløpet gjelder:') {
				// I've seen 1 to 3 lines here. So let's look for date + amount in the two next fields
				assertLineEquals($i++, 0, $lines, 'Beløpet gjelder:');
				while (true) {
					$payment_message[] = $lines[$i++][0];
					if (count($lines) <= $i+1) {
						// -> End of file
						$end_of_file = true;
						break;
					}
					if (isDate($lines[$i][0]) && isAmount($lines[$i+1][0])) {
						// -> The next payment is coming up
						break;
					}
				}
			}
			echo 'Payment message: '.chr(10) . '    '.implode(chr(10) . '    ', $payment_message).chr(10);
			if(count($payment_message) > 3) {
				throw new Exception('Found more than 3 lines in payment message. Something might be wrong.');
			}
		}

		// :: Output the rest of the file for debugging
		for(;$i < count($lines); $i++) {
			echo ' ----- ' . $i . ' ----- '.chr(10);
			var_dump($lines[$i]);
			var_dump(pdf2textwrapper::$table_pos[$i]);
		}
	}

	private function detectNewDocument ($i, $lines) {

		$bank_name = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Retur: ');
		assertLineEquals($i, 0, $lines, 'Dato');
		$document_date = assertAndGetDate($i++, 1, $lines);
		$bank_address = $lines[$i++][0];
		
		$this->currentDocument = new Sparebank1Document();
		$this->parsedPdf->addDocument($this->currentDocument);

		echo 'Your bank ....... : ' . $bank_name . $bank_address . chr(10);
		echo 'Document date ... : ' . $document_date . chr(10);
		$this->currentDocument->bank_name = $bank_name . $bank_address;
		$this->currentDocument->document_date = $document_date;

		$i = $this->detectNewPage($i, $lines);
		
		// :: Customer information in header
		if (!is_numeric($lines[$i][0])) {
			throwException('was not numeric. It was ['.$lines[$i][0].']', $i, 0, $lines);
		}
		$customer_id = $lines[$i++][0];
		// Next up should be the name and address this was sent to. In my case name and email.
		$customer_name = $lines[$i][0];
		assertLineEquals($i, 1, $lines, 'EPOST :'); // I guess this is not the case for documents sent in snail mail
		if (filter_var($lines[$i][2], FILTER_VALIDATE_EMAIL) === false) {
			throwException('was not an email. It was ['.$lines[$i][2].']', $i, 2, $lines);
		}
		$customer_email = $lines[$i++][2];
		// I think $lines[$i][3] is a branch code. Don't care.
		echo 'Customer id ..... : ' . $customer_id . chr(10);
		echo 'Customer name ... : ' . $customer_name . chr(10);
		echo 'Customer email .. : ' . $customer_email . chr(10);
		$this->currentDocument->customer_id = $customer_id;
		$this->currentDocument->customer_name = $customer_name;
		$this->currentDocument->customer_email = $customer_email;

		$bank_orgnumber = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Org.nr. ');
		echo 'Bank org.nr. .... : ' . $bank_orgnumber . chr(10);
		$this->currentDocument->bank_org_number = $bank_orgnumber;

		$bankaccount_owner = assertLineStartsWithAndGetValue($i, 0, $lines, 'Kontoeier er: ') . $lines[$i++][1];
		echo chr(10);
		echo 'Bank account owner .... : ' . $bankaccount_owner . chr(10);
		$this->currentDocument->bank_account_owner = $bankaccount_owner;
		var_dump($this->parsedPdf);
		return $i;
	}

	private function detectNewPage ($i, $lines) {
		if(substr($lines[$i][0], 0, strlen('Sidenr. ')) == 'Sidenr. ') {
			$page_number = substr($lines[$i++][0], strlen('Sidenr. '));
			echo ':: Page ' . $page_number . chr(10);
			$this->currentDocument->page_number = $page_number;
		}
		return $i;
	}
	
	/**
	 * Returns array with payments found in the file
	 *
	 * @return  Sb1Payment[]
	 */
	public function getPayments()
	{
		if(!$this->imported) {
			throw new Exception('PDF file is not imported');
		}
		
		return $this->payments;
	}
	
	/**
	 * Returns transactions from given account in CSV format
	 *
	 * @param   array   Account with transactions
	 * @return  string  CSV
	 */
	public static function getCSV($account)
	{
		if(!isset($account['transactions']))
			throw new Exception('CSV exporter failed. Given account has no transaction variable');
		
		$csv = 'Date;Description;Amount'.chr(10);
		foreach($account['transactions'] as $transaction)
		{
			$type = '';
			if($transaction['type'] != '')
				$type = $transaction['type'];
			$csv .= 
				date('d.m.Y', $transaction['payment_date']).';'.
				'"'.$transaction['description'].'";'.
				$transaction['amount'].chr(10);
		}
		return trim($csv);
	}
}
