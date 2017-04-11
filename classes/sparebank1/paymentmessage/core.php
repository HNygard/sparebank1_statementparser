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
    $value = lineConcat($line_num, $item_num_start, $item_num_stop, $lines);
    if ($value !== $text) {
	throwException('did not equal ['.$text.'] after concating from ['.$item_num_start.'] to ['.$item_num_stop.']. It was ['.$value.']', $line_num, $item_num_start, $lines);
    }
}

function lineConcat($line_num, $item_num_start, $item_num_stop, $lines) {
	return concat($item_num_start, $item_num_stop, $lines[$line_num]);
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
			(pdf2textwrapper::$pdf_creator === 'HP Exstream Version 7.0.605'
			|| pdf2textwrapper::$pdf_creator === 'HP Exstream Version 8.0.319 64-bit')
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
		
		$end_of_file = false;
		$end_of_payment_overviews = false;
		while(!$end_of_file) {
			$i = $this->detectNewDocument ($i, $lines);
			if ($i >= count($lines)) {
				// -> Reached the end of the file.
				break;
			}
			echo '- '.chr(10);
			$paymentMessage = new Sparebank1PaymentMessage();
			$this->currentDocument->addPaymentMessage($paymentMessage);

			$payment_msg_date = assertAndGetDate($i++, 0, $lines);
			$payment_amount = assertAndGetAmount($i++, 0, $lines);
			echo 'Payment msg date .... : ' . $payment_msg_date . chr(10);
			echo 'Payment amount ...... : ' . $payment_amount . chr(10);
			$paymentMessage->payment_msg_date = $payment_msg_date;
			$paymentMessage->payment_amount = $payment_amount;

			$payment_message = array();
			// Definition of stone age payment:
			// Somebody fills out an invoice by hand, takes it to the bank, which scans it
			// => Can't read much from it
			if (isset($lines[$i+1][8]) && concat(0, 8, $lines[$i+1]) === 'Frakonto:') {
				echo '=> STONE AGE PAYMENT DETECTED.' . chr(10);
				echo '   Unable to read data from it.' . chr(10);
				$paymentMessage->payment_bank_ref = implode(' ', $lines[$i++]);
				$i++;
				$paymentMessage->payment_from_bank_account = $lines[$i++][0];
				echo 'Payment bank ref .... : ' . $paymentMessage->payment_bank_ref . chr(10);
				echo 'Payment from bank account .. : ' . $paymentMessage->payment_from_bank_account . chr(10);
				$payment_message[] = 'STONE AGE PAYMENT DETECTED.';
				$payment_message[] = 'Please look directly at the PDF for payment info.';
			}
			else {
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
				$paymentMessage->payment_value_date = $payment_value_date;

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
				$paymentMessage->payment_bank_ref = $payment_bank_ref;
				$paymentMessage->payment_from = implode(chr(10), $payment_from);
				$paymentMessage->payment_to = implode(chr(10), $payment_to);
				$i++;

				assertLineConcat($i, 0, 8, $lines, 'Frakonto:');
				$payment_from_bank_account = (isset($lines[$i][9]) ? $lines[$i][9] : 'Not set');
				$i++;
				echo 'Payment from bank account .. : ' . $payment_from_bank_account . chr(10);
				$paymentMessage->payment_from_bank_account = $payment_from_bank_account;
			}

			// :: Payment message
			if (isset($lines[$i]) && $lines[$i][0] === 'Beløpet gjelder:') {
				// I've seen 1 to 3 lines here. So let's look for date + amount in the two next fields
				assertLineEquals($i++, 0, $lines, 'Beløpet gjelder:');
				while (true) {
					$payment_message[] = $lines[$i++][0];
					if (!isset($lines[$i])) {
						// -> End of file
						$end_of_file = true;
						break;
					}
					if (isDate($lines[$i][0]) && isAmount($lines[$i+1][0])) {
						// -> The next payment is coming up
						break;
					}
					if ($this->isStartOfNewDocument($i, $lines)) {
						// -> New document is coming up
						break;
					}
					if (strpos($lines[$i][0], 'Kontoutskrift nr. ') !== false) {
						// -> Next is account statement, move along
						$end_of_file = true;
						$end_of_payment_overviews = true;
						break;
					}
					if (strpos($lines[$i][0], 'Innbetalingsoversikt for konto:') !== false) {
						// -> The next page with payment overview, move along

						// :: Checking values on the next page, in case we missed someting in the parsing
						$new_page_bank_account_number = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Innbetalingsoversikt for konto: ');


						// Desember 2016 format. Contains headings first on a new page.
						if (lineConcat($i, 0, 44, $lines) == 'Bokførtdato:Beløp:Betalar:Mottakar:Referanse:') {
							assertLineConcat($i++, 0, 44, $lines, 'Bokførtdato:Beløp:Betalar:Mottakar:Referanse:');
						}
						if (lineConcat($i, 0, 44, $lines) == 'Bokførtdato:Beløp:Betaler:Mottaker:Referanse:') {
							assertLineConcat($i++, 0, 44, $lines, 'Bokførtdato:Beløp:Betaler:Mottaker:Referanse:');
						}

						assertLineEquals($i, 0, $lines, 'Dato:');
						$new_page_date = assertAndGetDate($i, 1, $lines);
						$new_page_number = (int)assertLineStartsWithAndGetValue($i, 2, $lines, 'Sidenr. ');
						$new_page_customer_id = $lines[$i++][3];
						if($new_page_bank_account_number !== $this->currentDocument->bank_account_number) {
							throw new Exception('Current bank account number [' . $this->currentDocument->bank_account_number .'] '.
								'does not match account number on the next page ['. $new_page_bank_account_number .'].');
						}
						if($new_page_number !== $this->currentDocument->page_number + 1) {
							throw new Exception('Current page number [' . $this->currentDocument->page_number .'] + 1 '.
								'does not match page number on next page ['. $new_page_number .'].');
						}
						if($new_page_date !== $this->currentDocument->document_date) {
							throw new Exception('Current document date [' . $this->currentDocument->document_date .'] '.
								'does not match document date on the next page ['. $new_page_date .'].');
						}
						$this->currentDocument->page_number = $new_page_number;
	
						$new_page_customer_name = $lines[$i][0];
						$lines[$i][1] = str_replace('epost :', 'epost:', strtolower($lines[$i][1]));
						assertLineEquals($i, 1, $lines, ', epost:');
						$new_page_customer_email = substr($lines[$i++][2], strlen(', '));
						if($new_page_customer_name !== $this->currentDocument->customer_name) {
							throw new Exception('Current customer name [' . $this->currentDocument->customer_name .'] '.
								'does not match customer name on the next page ['. $new_page_customer_name .'].');
						}
						if($new_page_customer_email !== $this->currentDocument->customer_email) {
							throw new Exception('Current customer email [' . $this->currentDocument->customer_email .'] '.
								'does not match customer email on the next page ['. $new_page_customer_email .'].');
						}
						break;
					}
				}
			}
			else {
				// -> No payment message, check for end of file
				if (count($lines) <= $i+1) {
					// -> End of file
					$end_of_file = true;
				}
			}
			echo 'Payment message: '.chr(10) . '    '.implode(chr(10) . '    ', $payment_message).chr(10);
			$paymentMessage->payment_message = implode(chr(10), $payment_message);
			if(count($payment_message) > 3) {
				throw new Exception('Found more than 3 lines in payment message. Something might be wrong.');
			}
		}

		if($end_of_payment_overviews) {
			echo '==> DETECTED END OF PAYMENT OVERVIEWS (Account statement is next).'.chr(10) .
				'Assuming there are now more overviews in this PDF.'.chr(10);
			return;
		}

		// :: Output the rest of the file for debugging
		$file_eaten = true;
		for(;$i < count($lines); $i++) {
			echo ' ----- ' . $i . ' ----- '.chr(10);
			var_dump($lines[$i]);
			var_dump(pdf2textwrapper::$table_pos[$i]);
			$file_eaten = false;
		}
		if(!$file_eaten) {
			throw new Exception('Did not eat the whole file.');
		}
	}
	private function isStartOfNewDocument ($i, $lines) {
		return substr($lines[$i][0], 0, strlen('Retur: ')) === 'Retur: ' && isDate($lines[$i+1][1]);
	}

	private function detectNewDocument ($i, $lines) {
		if (!isset($lines[$i])) {
			// -> Reached end of file
			return $i;
		}
		if ($lines[$i][0] === 'Fortsetter på neste side') {
			// -> This text is first in a new document. Just continue past it.
			$i++;
		}
		$new_document_with_same_header = isset($this->currentDocument) 
			&& (isset($lines[$i][1]) && isDate($lines[$i][1]))
			&& ($lines[$i+1][0] === $this->currentDocument->bank_address);
		if (!$this->isStartOfNewDocument($i, $lines)
			&& !$new_document_with_same_header
		) {
			return $i;
		}

		if(!$new_document_with_same_header) {
			$bank_name = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Retur: ');
		}
		else {
			$bank_name = 'Not set';
		}
		assertLineEquals($i, 0, $lines, 'Dato');
		$document_date = assertAndGetDate($i++, 1, $lines);
		$bank_address = $lines[$i++][0];

		echo 'Your bank ....... : ' . $bank_name . $bank_address . chr(10);
		echo 'Document date ... : ' . $document_date . chr(10);

		if(substr($lines[$i][0], 0, strlen('Sidenr. ')) == 'Sidenr. ') {
			$page_number = (int)substr($lines[$i++][0], strlen('Sidenr. '));
			echo ':: Page ' . $page_number . chr(10);
		}
				
		// :: Customer information in header
		if (!is_numeric($lines[$i][0])) {
			throwException('was not numeric. It was ['.$lines[$i][0].']', $i, 0, $lines);
		}
		$customer_id = $lines[$i++][0];
		// Next up should be the name and address this was sent to. In my case name and email.
		$customer_name = $lines[$i][0];
		$lines[$i][1] = str_replace(' :', ':', strtolower($lines[$i][1]));
		assertLineEquals($i, 1, $lines, 'epost:'); // I guess this is not the case for documents sent in snail mail
		if (filter_var($lines[$i][2], FILTER_VALIDATE_EMAIL) === false) {
			throwException('was not an email. It was ['.$lines[$i][2].']', $i, 2, $lines);
		}
		$customer_email = $lines[$i++][2];
		// I think $lines[$i][3] is a branch code. Don't care.
		echo 'Customer id ..... : ' . $customer_id . chr(10);
		echo 'Customer name ... : ' . $customer_name . chr(10);
		echo 'Customer email .. : ' . $customer_email . chr(10);

		if (strpos($lines[$i][0], 'Organisasjon') !== false) {
			// -> Old "Organisasjonsnr. NO 912345678" format
			$bank_orgnumber = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Organisasjonsnr. ');
		}
		else {
			// -> Switched to "Org.nr." and added "Foretaksregistert" in 2013
			//    E.g. "Org.nr. NO 912345678 Foretaksregisteret"
			$bank_orgnumber = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Org.nr. ');
		}
		echo 'Bank org.nr. .... : ' . $bank_orgnumber . chr(10);

		// :: Find account owner
		// If account owner is the same as the customer, it will not be in it's own field
		if (strpos($lines[$i][0], 'Kontoeier er:') !== false) {
			// -> Account owner != Customer getting this PDF
			$bankaccount_owner = assertLineStartsWithAndGetValue($i, 0, $lines, 'Kontoeier er: ') . $lines[$i++][1];
			echo chr(10);
			echo 'Bank account owner .... : ' . $bankaccount_owner . chr(10);
		}
		else {
			$bankaccount_owner = $customer_name;
		}

		$another_detect_new_document = false;
		if(isset($lines[$i][23]) && concat(0, 23, $lines[$i]) === 'Belastningsoppgavekonto:') {
			echo '=> Payment receipt detected.'.chr(10);
			$this->currentDocument = new Sparebank1PaymentReceiptDocument();
			$bankaccount_number = $lines[$i][24];
			// :: Collect all the lines in this document
			$content = array();
			for(;$i < count($lines); $i++) {
				$content[] = implode(' ', $lines[$i]);
				if($lines[$i][0] === 'For teknisk support kontakt Nets brukerstedsservice på 08989.') {
					// -> Only handling "Belastningsoppgave" from Nets. This is the last line be for payment overviews.
					$i++;
					break;
				}
			}
			echo 'Content: '.chr(10) . '    '.implode(chr(10) . '    ', $content).chr(10);
			$this->currentDocument->content = implode(chr(10), $content);
			$another_detect_new_document = true;
		}
		else if ($lines[$i][0] === 'Ikke utført oppdrag') {
			echo '=> Rejected payment detected.'.chr(10);
			$this->currentDocument = new Sparebank1RejectedPaymentDocument();
			// :: Collect all the lines in this document
			$content = array();
			for(;$i < count($lines); $i++) {
				$content[] = implode(' ', $lines[$i]);
				if($lines[$i][0] === 'eller ved å ta kontakt med banken.') {
					// -> This is the last line in the rejected payment overview.
					$i++;
					break;
				}
			}
			echo 'Content: '.chr(10) . '    '.implode(chr(10) . '    ', $content).chr(10);
			$this->currentDocument->content = implode(chr(10), $content);
			$another_detect_new_document = true;
		}
		else if($lines[$i][0] === 'Endringer i prislisten') {
			echo '=> Price change document detected.'.chr(10);
			$this->currentDocument = new Sparebank1PriceChangeDocument();
			// :: Collect all the lines in this document
			$content = array();
			for(;$i < count($lines); $i++) {
				$content[] = implode(' ', $lines[$i]);
				if($lines[$i][0] === 'Med vennlig hilsen') {
					// -> This is the second last line in price change document. Next line i name of the bank.
					$i++;
					$content[] = implode(' ', $lines[$i++]);
					break;
				}
			}
			echo 'Content: '.chr(10) . '    '.implode(chr(10) . '    ', $content).chr(10);
			$this->currentDocument->content = implode(chr(10), $content);
			$another_detect_new_document = true;
		}
		else if($lines[$i][0] === 'Årsoppgåve') {
			echo '=> Yearly statement document detected.'.chr(10);
			$this->currentDocument = new Sparebank1YearlyStatementDocument();
			// :: Collect all the lines in this document
			$content = array();
			for(;$i < count($lines); $i++) {
				$content[] = implode(' ', $lines[$i]);
				if(strpos($lines[$i][0], 'konto for tilbakebetaling av skatt') !== false) {
					// -> This is the last line in document.
					//    "Dykkar konto XXXX.XX.XXXXX er oppgitt som ein mogleg konto for tilbakebetaling av skatt"
					$i++;
					break;
				}
			}
			echo 'Content: '.chr(10) . '    '.implode(chr(10) . '    ', $content).chr(10);
			$this->currentDocument->content = implode(chr(10), $content);
			$another_detect_new_document = true;
		}
		else {
			$this->currentDocument = new Sparebank1PaymentOverviewDocument();
			$bankaccount_number = assertLineStartsWithAndGetValue($i++, 0, $lines, 'Innbetalingsoversikt for konto: ');
			echo 'Bank account number ... : ' . $bankaccount_number . chr(10);
			
			// Detect payments by looking at the header
			if (lineConcat($i, 0, 16, $lines) == 'Betalar:Mottakar:') {
				$this->currentDocument->payment_overview_doc_version = 'pre-2016';
				assertLineConcat($i++, 0, 16, $lines, 'Betalar:Mottakar:');
			}
			else {
				// -> Introduced around december 2016. Using a table.
				$this->currentDocument->payment_overview_doc_version = '2016';
				assertLineConcat($i++, 0, 44, $lines, 'Bokførtdato:Beløp:Betalar:Mottakar:Referanse:');
			}
		}

		
		$this->parsedPdf->addDocument($this->currentDocument);
		$this->currentDocument->bank_name = $bank_name;
		$this->currentDocument->bank_address = $bank_address;
		$this->currentDocument->document_date = $document_date;
		$this->currentDocument->customer_id = $customer_id;
		$this->currentDocument->customer_name = $customer_name;
		$this->currentDocument->customer_email = $customer_email;
		$this->currentDocument->bank_org_number = $bank_orgnumber;
		$this->currentDocument->bank_account_owner = $bankaccount_owner;
		if (isset($bankaccount_number)) {
			$this->currentDocument->bank_account_number = $bankaccount_number;
		}
		$this->currentDocument->page_number = $page_number;
		
		if($another_detect_new_document) {
			return $this->detectNewDocument($i, $lines);
		}
		return $i;
	}
	
	/**
	 * @return  Sparebank1Pdf
	 */
	public function getParsedPdf()
	{
		if(!$this->imported) {
			throw new Exception('PDF file is not imported');
		}
		
		return $this->parsedPdf;
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
