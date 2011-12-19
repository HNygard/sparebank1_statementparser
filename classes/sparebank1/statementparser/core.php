<?php defined('SYSPATH') or die('No direct script access.');

class sparebank1_statementparser_core
{
	protected $accounts = array();
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
		
		$this->accounts = array(); // Can be multiple accounts per file
		if(
			pdf2textwrapper::$pdf_author == 'Registered to: EDB DRFT' &&
			(
				pdf2textwrapper::$pdf_creator == 'Exstream Dialogue Version 5.0.051' ||
				pdf2textwrapper::$pdf_creator == 'HP Exstream Version 7.0.605'
			) 
		){
			// Parse and read Exstream PDF
			$this->parseAndReadExstreamPdf();
		}
		elseif (
			(
				pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8p by GenText, inc.' || // 04.2005 and earlier
				pdf2textwrapper::$pdf_producer == 'PDFOUT v3.8q by GenText, inc.'    // 05.2005 and later
			) &&
			pdf2textwrapper::$pdf_author   == 'GenText, inc.' &&
			substr(pdf2textwrapper::$pdf_creator, 0, strlen('M2PD API Version 3.0, build')) == 'M2PD API Version 3.0, build'
		) {
			// Parse and read "M2PD API Version 3.0" (used up to jan 2008)
			$this->parseAndReadJan2008Pdf();
		}
		else {
			throw new Exception('Unknown/unsupported PDF creator.'.
				' Creator: :pdf_creator'.
				' Author: :pdf_author'.
				' Producer: :pdf_producer', 
				array(
					':pdf_author'    => pdf2textwrapper::$pdf_author,
					':pdf_creator'   => pdf2textwrapper::$pdf_creator,
					':pdf_producer'  => pdf2textwrapper::$pdf_producer,
				));
		}
		
		// Checking if the PDF is successfully parsed
		foreach($this->accounts as $account)
		{
			// Checking if all parameters have been found
			if(!isset($account['accountstatement_balance_in']))
				throw new Exception('PDF parser failed. Can not find accountstatement_balance_in.');
			if(!isset($account['accountstatement_balance_out']))
				throw new Exception('PDF parser failed. Can not find accountstatement_balance_out.');
			if(!isset($account['accountstatement_start']))
				throw new Exception('PDF parser failed. Can not find accountstatement_start.');
			if(!isset($account['accountstatement_end']))
				throw new Exception('PDF parser failed. Can not find accountstatement_end.');
			
			// Checking if the found amount is the same as the control amount found on accountstatement
			// If not, the file is corrupt or parser has made a mistake
			if(round($account['control_amount'],2) != $account['accountstatement_balance_out'])
				throw new Exception('PDF parser failed. Controlamount is not correct. '.
					'Controlamount, calculated: :control_amount. '.
					'Balance out should be: :accountstatement_balance_out.', 
					array(
						':control_amount'                => $account['control_amount'],
						':accountstatement_balance_out'  => $account['accountstatement_balance_out'],
					));
		}
		
		// Great success!
		$this->imported = true;
		
		return $this;
	}
	
	/**
	 * Parse and read data from a Extream PDF
	 * 
	 * Can parse data from:
	 * Author = "Registered to: EDB DRFT"
	 * Creator = "Exstream Dialogue Version 5.0.051" OR "HP Exstream Version 7.0.605"
	 */
	private function parseAndReadExstreamPdf() {

		if(!count(pdf2textwrapper::$table)) {
			/*
			// Add:
			//    if(self::$debugging) { echo '<pre>'.$data.'</pre>'; }
			// or something in pdf2textwrapper to debug the current PDF (a failed PDF)
			pdf2textwrapper::$debugging = true;
			pdf2textwrapper::pdf2text_fromstring($infile);
			pdf2textwrapper::$debugging = false;
			/**/

			throw new Exception('PDF parser failed. Unable to read any lines.');
		}
		
		$next_is_balance_in   = false;
		$next_is_balance_out  = false;
		$next_is_fee          = false;
		$next_is_transactions = false;
		
		$last_account = null;
		foreach(pdf2textwrapper::$table as $td_id => $td)
		{
			if(!is_array($td))
				continue;
			
			// Checking and fixing multiline transactions
			if
			(
				$next_is_transactions &&
				(
					count($td) == 5 || // Personal accounts
					(
						// Business accounts
						count($td) == 7 &&
						is_numeric($td[5]) && // 123321123
						is_numeric(substr($td[6],1)) // *123123123
					) ||
					(
						// Business accounts
						count($td) == 6 &&
						is_numeric($td[5]) // 123321123
					)
				) &&
				is_numeric($td[2]) && strlen($td[2]) == 4 && // ddmm, interest_date
				is_numeric(
					str_replace(',', '.',
					str_replace(' ', '',
					str_replace('.', '', $td[3])))) && // amount
				is_numeric($td[4]) && strlen($td[4]) == 4// ddmm, payment_date
			)
			{
				$td_tmp = $td;
				$td = array();
				$td[0] = $td_tmp[0].' '.$td_tmp[1];
				$td[1] = $td_tmp[2];
				$td[2] = $td_tmp[3];
				$td[3] = $td_tmp[4];
				if(isset($td_tmp[5]))
					$td[4] = $td_tmp[5];
				if(isset($td_tmp[6]))
					$td[5] = $td_tmp[6];
			}
			
			if(
				// Line with
				// Example data: Kontoutskrift nr. 2 for konto 1234.12.12345 i perioden 01.02.2011 - 28.02.2011 Alltid Pluss 18-34
				count($td) == 1 &&
				substr($td[0], 0, strlen('Kontoutskrift nr. ')) == 'Kontoutskrift nr. '
			)
			{
				preg_match('/Kontoutskrift nr. (.*) for konto (.*) i perioden (.*) - (.*)/', $td[0], $parts);
				if(count($parts) == 5)
				{
					$accountstatement_num    = $parts[1]; // 2
					$account_num             = $parts[2]; // 1234.12.12345
					$accountstatement_start  = sb1helper::convert_stringDate_to_intUnixtime
					                        ($parts[3]); // 01.02.2011
					$parts  = explode(' ',$parts[4], 2); // 28.02.2011 Alltid Pluss 18-34
					$accountstatement_end    = sb1helper::convert_stringDate_to_intUnixtime 
					                         ($parts[0]); // 28.02.2011
					$account_type            = $parts[1]; // Alltid Pluss 18-34
					
					$last_account = $account_num.'_'.$accountstatement_start;
					$tmp = Sprig::factory('bankaccount', array('num' => $account_num))->load();
					if(!$tmp->loaded())
						$last_account_id = -1;
					else
						$last_account_id = $tmp->id;
					
					// If account spans over several pages, the heading repeats
					if(!isset($this->accounts[$last_account]))
					{
						$this->accounts[$last_account] = array(
							'accountstatement_num'    => $accountstatement_num,
							'account_id'              => $last_account_id,
							'account_num'             => $account_num,
							'accountstatement_start'  => $accountstatement_start,
							'accountstatement_end'    => $accountstatement_end,
							'account_type'            => $account_type,
							'transactions'            => array(),
							'control_amount'          => 0,
						);
						//echo '<tr><td>Account: <b>'.$account_num.'</b></td></tr>';
					}
					
					$next_is_fee = false;
					$next_is_transactions = true;
				}
			}
			elseif(
				// Checking for a row with transaction
				(
					count($td) == 4 || // Personal accounts
					(
						// Business accounts
						count($td) == 6 &&
						is_numeric($td[4]) && // 123321123
						is_numeric(substr($td[5],1)) // *123123123
					) ||
					(
						// Business accounts
						count($td) == 5 &&
						is_numeric($td[4]) // 123321123
					)
				) &&
				is_numeric($td[1]) && strlen($td[1]) == 4 && // ddmm, interest_date
				is_numeric(
					str_replace(',', '.',
					str_replace(' ', '',
					str_replace('.', '', $td[2])))) && // amount
				is_numeric($td[3]) && strlen($td[3]) == 4// ddmm, payment_date
			)
			{
				$amount = sb1helper::stringKroner_to_intOerer($td[2]);
				
				$pos_amount        = pdf2textwrapper::$table_pos[$td_id][1][2];
				$pos_payment_date  = pdf2textwrapper::$table_pos[$td_id][1][3];
				
				// If pos_amount is less than 365, the money goes out of the account
				// If pos_amount is more than 365, the money goes into the account
				if($pos_amount < 
					(
						365+ // pos if 0,00 goes out of the account
						19 // margin
					)
				)
				{
					$amount = -$amount;
				}
				
				self::$lasttransactions_interest_date = sb1helper::convert_stringDate_to_intUnixtime 
					($td[1], date('Y', $this->accounts[$last_account]['accountstatement_end']));
				self::$lasttransactions_payment_date = sb1helper::convert_stringDate_to_intUnixtime 
					($td[3], date('Y', $this->accounts[$last_account]['accountstatement_end']));
				
				self::$lasttransactions_description  = $td[0];
				self::$lasttransactions_type         = '';
				
				self::parseLastDescription($next_is_fee);
				
				$this->accounts[$last_account]['control_amount'] += $amount;
				$this->accounts[$last_account]['transactions'][] = array(
						'bankaccount_id'  => $last_account_id,
						'description'     => self::$lasttransactions_description,
						'interest_date'   => self::$lasttransactions_interest_date,
						'amount'          => ($amount/100),
						'payment_date'    => self::$lasttransactions_payment_date,
						'type'            => self::$lasttransactions_type,
					);
				/*
				echo '<tr>';
				echo '<td>'.self::$lasttransactions_description.'</td>';
				echo '<td>'.date('d.m.Y', self::$lasttransactions_interest_date).'</td>';
				if($amount > 0)
					echo '<td>&nbsp;</td><td>'.($amount/100).'</td>';
				else
					echo '<td>'.($amount/100).'</td><td>&nbsp;</td>';
				echo '<td>'.date('d.m.Y', self::$lasttransactions_payment_date).'</td>';
				
				echo '</tr>';/**/
				/*
				$this->transactions[] = array(
						'bankaccount_id' => $this->bankaccount_id,
						'payment_date'   => utf8::clean($csv[0]),
						'interest_date'  => utf8::clean($csv[2]),
						'description'    => utf8_encode($csv[1]),
						'amount'         => str_replace(',', '.', utf8::clean($csv[3])),
					);*/
			}
			
			/*
			
			## Balance in ##
			
			Example data:
			    [3] => Array
				(
				    [0] => Saldo
				    [1] => frå
				    [2] =>  kontoutskrift
				    [3] => 31.01.2011
				)

			    [4] => Array
				(
				    [0] => 12.345,67
				)
			*/
			elseif(
				// Saldo frå kontoutskrift dd.mm.yyyy
				count($td) == 4 && 
				trim($td[0]) == 'Saldo' &&
				(trim($td[1]) == 'frå' || trim($td[1]) == 'fra') && // Nynorsk and bokmål
				trim($td[2]) == 'kontoutskrift'
			)
			{
				$next_is_balance_in = true;
			}
			elseif(
				// Balance in on this account statement
				$next_is_balance_in
			)
			{
				/*
					Observed values for $pos_amount:
					- 2135, 1 digits positive
					- 2116, 2 digits positive
					- 2097, 3 digits positive
					- 2068, 4 digits positive
					- 2049, 5 digits positive
					- 2030, 6 digits positive
					
					- 1688, 3 digits positive
					- 1659, 4 digits positive
					- 1640, 5 digits positive
					
					- 1854, 1 digits negative
					
					Using from 1850 to 1950 as negative
				 */
				$balance_in = sb1helper::stringKroner_to_intOerer ($td[0]);
				$pos_amount = pdf2textwrapper::$table_pos[$td_id][1][0];
				
				if($pos_amount >= 1850 && $pos_amount <= 1950)
				{
					$balance_in = -$balance_in;
				}
				$this->accounts[$last_account]['accountstatement_balance_in'] = $balance_in;
				$this->accounts[$last_account]['control_amount'] += $this->accounts[$last_account]['accountstatement_balance_in'];
				$next_is_balance_in = false;
			}
			
			/*
			
			## Balance out ##
			
			Example data:
			    [55] => Array
				(
				    [0] => S
				    [1] => a
				    [2] => l
				    [3] => d
				    [4] => o
				    [5] => i
				    [6] => D
				    [7] => y
				    [8] => k
				    [9] => k
				    [10] => a
				    [11] => r
				    [12] => f
				    [13] => a
				    [14] => v
				    [15] => ø
				    [16] => r
				)

			    [56] => Array
				(
				    [0] => 12.345,67
				)
			*/
			elseif(
				// Saldo i Dykkar favør
				(count($td) == 17 && implode($td) == 'SaldoiDykkarfavør') || // Nynorsk
				(count($td) == 16 && implode($td) == 'SaldoiDeresfavør')     // Bokmål
			)
			{
				// -> Balance out is positive
				$next_is_balance_out     = true;
				$next_is_transactions    = false;
				$balance_out_is_positive = true;
			}
			elseif(
				// Saldo i vår favør
				(count($td) == 14 && implode($td) == 'Saldoivårfavør')
			)
			{
				// -> Balance out is negative
				$next_is_balance_out     = true;
				$next_is_transactions    = false;
				$balance_out_is_positive = false;
			}
			elseif(
				// Balance out on this account statement
				$next_is_balance_out
			)
			{
				$balance_out = sb1helper::stringKroner_to_intOerer ($td[0]);
				if(!$balance_out_is_positive) {
					// -> Negative balance
					$balance_out = -$balance_out;
				}
				$this->accounts[$last_account]['accountstatement_balance_out'] = $balance_out;
				$next_is_balance_out = false;
			}
			elseif(
				implode($td) == 'Kostnadervedbrukavbanktjenester:' ||  // Bokmål
				implode($td) == 'Kostnadervedbrukavbanktenester:'      // Nynorsk
			)
			{
				// The next detected transactions, if any, is fees
				$next_is_fee = true;
			}
			
			
			/*
			elseif($last_account && $this->accounts[$last_account]['accountstatement_num'] == '9')
			{
				// Debugging
				echo '<tr><td colspan="4">'.print_r($td, true).'</td></tr>';
			}
			/**/
			/*
			else
			{
				// Debugging
				echo '<tr><td colspan="4">'.implode('', $td).'</td></tr>';
			}
			/**/
		}
	}
	
	private function parseAndReadJan2008Pdf() {

		if(!count(pdf2textwrapper::$table)) {
			/*
			// Add:
			//    if(self::$debugging) { echo '<pre>'.$data.'</pre>'; }
			// or something in pdf2textwrapper to debug the current PDF (a failed PDF)
			pdf2textwrapper::$debugging = true;
			pdf2textwrapper::pdf2text_fromstring($infile);
			pdf2textwrapper::$debugging = false;
			/**/

			throw new Exception('PDF parser failed. Unable to read any lines.');
		}
		
		$next_is_balance_in   = false;
		$next_is_balance_out  = false;
		$next_is_fee          = false;
		$next_is_transactions = false;
		$next_is_heading_part_two    = false;
		$next_is_heading_part_three  = false;
		
		$last_account = null;

		$table = array(); $table_width = array();
		$last_height = -1;
		foreach(pdf2textwrapper::$table as $td_id => $td)
		{
			/*
			// Debugging
			echo '<tr><td colspan="4">'.implode('', $td).'</td></tr>';
			echo '<tr><td colspan="4" style="color: gray;">'.print_r($td, true).'</td></tr>';
			/**/
			
			if($td[0] == 'Kontoutskrift' && $td[1] == 'nr.') {
				/*
					THIS LINE ($td)
						[0] => Kontoutskrift 
						[1] => nr. 
						[2] => 8 
						[3] => for 
						[4] => konto 
						[5] => 1234.56.12345 
						[6] => i 
						[7] => perioden 
						[8] => 01.08.2006 )
					NEXT LINE ($table[$td_id+1])
						[0] => 31.08.2006 
						[1] => Name 
						[2] => Of 
						[3] => Accounttype
						[4] => IBAN: 
						[5] => NO65 
						[6] => 1234 
						[7] => 5612 
						[8] => 345 
						[9] => BIC )
					
					OR if there is not IBAN number:
						[0] => Kontoutskrift 
						[1] => nr. 
						[2] => 1 
						[3] => for 
						[4] => konto 
						[5] => 1234.12.12345 
						[6] => i 
						[7] => perioden 
						[8] => 01.01.2002-31.12.2002 
						[9] => Name 
						[10] => Of 
						[11] => Accounttype
					
					OR this array:
						variant of the first...
				*/
				
				$this_line = implode($td, ' ');
				$next_line = implode(pdf2textwrapper::$table[$td_id+1], ' ');
				
				preg_match('/Kontoutskrift nr. (.*) for konto (.*) i perioden (.*) - (.*) IBAN(.*)/', 
					$this_line.' - '.$next_line, $parts1);
				preg_match('/Kontoutskrift nr. (.*) for konto (.*) i perioden (.*)-(.*)/', 
					$this_line, $parts2);
				preg_match('/Kontoutskrift nr. (.*) for konto (.*) i perioden (.*) - (.*)/', 
					$this_line.' - '.$next_line, $parts3);
				$found = false;
				if(count($parts1) == 6) {
					$parts = $parts1;
					$found = true;
				}
				elseif(count($parts2) == 5) {
					$parts = $parts2;
					$found = true;
				}
				elseif(count($parts3) == 5) {
					$parts = $parts3;
					$found = true;
				}
				else {
					/*
					// Debugging:
					echo '<tr><td colspan="4"><b>Part 1</b> <pre>'.print_r($parts1, true).'</pre></td></tr>';
					echo '<tr><td colspan="4"><b>Part 2</b> <pre>'.print_r($parts2, true).'</pre></td></tr>';
					echo '<tr><td colspan="4"><b>Part 3</b> <pre>'.print_r($parts3, true).'</pre></td></tr>';
					/**/
					throw new Exception('Not able to retrive account info.');
				}
				
				if($found)
				{
					$accountstatement_num    = $parts[1]; // 2
					$account_num             = $parts[2]; // 1234.12.12345
					$accountstatement_start  = sb1helper::convert_stringDate_to_intUnixtime
					                        ($parts[3]); // 01.02.2011
					$parts  = explode(' ',$parts[4], 2); // 28.02.2011 Alltid Pluss 18-34
					$accountstatement_end    = sb1helper::convert_stringDate_to_intUnixtime 
					                         ($parts[0]); // 28.02.2011
					$account_type            = $parts[1]; // Alltid Pluss 18-34
					
					$last_account = $account_num.'_'.$accountstatement_start;
					$tmp = Sprig::factory('bankaccount', array('num' => $account_num))->load();
					if(!$tmp->loaded())
						$last_account_id = -1;
					else
						$last_account_id = $tmp->id;
					
					// If account spans over several pages, the heading repeats
					if(!isset($this->accounts[$last_account]))
					{
						$this->accounts[$last_account] = array(
							'accountstatement_num'    => $accountstatement_num,
							'account_id'              => $last_account_id,
							'account_num'             => $account_num,
							'accountstatement_start'  => $accountstatement_start,
							'accountstatement_end'    => $accountstatement_end,
							'account_type'            => $account_type,
							'transactions'            => array(),
							'control_amount'          => 0,
						);
						//echo '<tr><td>Account: <b>'.$account_num.'</b></td></tr>';
					}
					$next_is_fee               = false;
				}
			}
			
			// Saldo i Dykkar favør, nynorsk
			// Saldo i Deres favør, bokmål
			elseif (substr(implode($td),0,strlen('SaldoiD')) == 'SaldoiD') {

				/*
					Possible variants:
						[0] => Saldo 
						[1] => i 
						[2] => Dykkar 
						[3] => favør 
						[4] => 1.234,56
					OR:
						[0] => Saldo 
						[1] => i 
						[2] => Dykkar 
						[3] => favør 
						[4] => 123,45 
						[5] => Stadfesta 
						[6] => beløp 
						[7] => 123,45
				*/
				$next_is_transactions        = false;

				$balance_out_is_positive = true; // TODO
				
				// Balance out is the 4th element
				$balance_out = sb1helper::stringKroner_to_intOerer ($td[4]);
				if(!$balance_out_is_positive) {
					// -> Negative balance
					$balance_out = -$balance_out;
				}
				$this->accounts[$last_account]['accountstatement_balance_out'] = $balance_out;

				//echo '<tr><td colspan="4"><b>BALANCE OUT</b> '.($balance_out/100).'</td></tr>';
				
				
				// :: Parse the transations we have collected
				//
				// - The thrid last  cell per line is the interest date
				// - The second last cell per line is the amount
				// - The last        cell per line is the payment date
				// Except the first line, which is balance from last bank statement
				foreach($table as $key => $value) {
					// The rows in this table might be:
					// - Balance in
					// - Heading be for fee (the next transations are fees)
					// - Ignore "Overført frå forrige side"
					// - Transactions
					
					if(substr(implode($value),0,strlen('Saldofr')) == 'Saldofr') {
						
						$balance_in_is_positive = true; // TODO
						
						$balance_in = sb1helper::stringKroner_to_intOerer ($value[count($value)-1]);
						if(!$balance_in_is_positive) {
							// -> Negative balance
							$balance_in = -$balance_in;
						}
						$this->accounts[$last_account]['accountstatement_balance_in'] = $balance_in;
						$this->accounts[$last_account]['control_amount'] 
							+= $this->accounts[$last_account]['accountstatement_balance_in'];
					}
					
					elseif(substr(implode($value),0,strlen('Kostnadervedbrukavbanktenester:')) 
						== 'Kostnadervedbrukavbanktenester:') {
						$next_is_fee = true;
					}
					
					// ?: "Overført frå forrige side"?
					elseif(preg_match('/Overf.rtfr.forrigeside/', implode($value))) {
						// Ignore, not a transaction
					}
					
					else {
						// -> Transactions
							
						$key_payment_date   = count($value)-1;
						$key_amount         = count($value)-2;
						$key_interest_date  = count($value)-3;
						
						$payment_date   = $value[$key_payment_date];
						$amount         = $value[$key_amount];
						$interest_date  = $value[$key_interest_date];
						
						unset($value[$key_payment_date]);
						unset($value[$key_amount]);
						unset($value[$key_interest_date]);
						
						$amount = sb1helper::stringKroner_to_intOerer($amount);
						$pos_amount  = $table_width[$key][$key_amount];
						/*
							Observed values for $pos_amount:
							486.7, 3 digits positive
							474.2, 4 digits positive
							416.9, 2 digits positive
							421.9, 2 digits negative
							411.8, 3 digits negative
							404.4, 4 digits negative
						 */
						if($pos_amount < 422) {
							$amount = -$amount;
						}
						
						/*
						// Debugging
						echo
							'<tr>'.
								'<td style="width: 100px;">'.$payment_date.'</td>'.
								'<td style="width: 100px;">'.($amount/100).'</td>'.
								'<td style="width: 100px;">'.$interest_date.'</td>'.
								'<td>'.$pos_amount .' '.implode(' ',$value).'</td>'.
							'</tr>';
						/**/

						self::$lasttransactions_interest_date = sb1helper::convert_stringDate_to_intUnixtime 
							($interest_date, date('Y', $this->accounts[$last_account]['accountstatement_end']));
						self::$lasttransactions_payment_date = sb1helper::convert_stringDate_to_intUnixtime 
							($payment_date, date('Y', $this->accounts[$last_account]['accountstatement_end']));
				
						self::$lasttransactions_description  = implode(' ', $value);
						self::$lasttransactions_type         = '';
				
						self::parseLastDescription($next_is_fee);
				
						$this->accounts[$last_account]['control_amount'] += $amount;
						$this->accounts[$last_account]['transactions'][] = array(
								'bankaccount_id'  => $last_account_id,
								'description'     => self::$lasttransactions_description,
								'interest_date'   => self::$lasttransactions_interest_date,
								'amount'          => ($amount/100),
								'payment_date'    => self::$lasttransactions_payment_date,
								'type'            => self::$lasttransactions_type,
							);
					}
				}
			}
			
			elseif (substr(implode($td),0,strlen('ForklaringRentedatoUtavkontoInn')) == 'ForklaringRentedatoUtavkontoInn') {
				$next_is_transactions        = true;
			}
			
			elseif (preg_match('/Dato[0-9][0-9]\.[0-9][0-9]\.[0-9][0-9][0-9][0-9]Sidenr/', implode($td))) {
				//echo '<tr><td colspan="4"><b>NEW PAGE</b></td></tr>';
				$next_is_transactions = false; // Don't start parsing transactions until the heading has been parsed
				$last_height          = -1;    // Start the count again
			}
			
			elseif ($next_is_transactions) {
				// All transactions are on one line in this format
				
				// Parse this line based on height in PDF
				// Transactions can be multi line in the description field
				foreach($td as $key => $value) {
					$position_width  = pdf2textwrapper::$table_pos[$td_id][1][$key];
					$position_height = pdf2textwrapper::$table_pos[$td_id][2][$key];
					
					//echo '<tr><td colspan="4">PARSE TRANSACTION: '.$position_width .' - '.$position_height.': '.$value.'</td></tr>';
					
					if(!isset($table[$position_height])) {
						$table      [$position_height] = array();
						$table_width[$position_height] = array();
					}
					
					if($last_height != -1 && $last_height < $position_height) {
						// -> The last line was a part of this line
						
						// Merge the last into this line
						$table      [$position_height] = 
							array_merge($table[$position_height], $table[$last_height]);
						$table_width[$position_height] = 
							array_merge($table_width[$position_height], $table_width[$last_height]);
						
						// Unset the partial line
						unset($table      [$last_height]);
						unset($table_width[$last_height]);
					}
					
					$table      [$position_height][] = $value;
					$table_width[$position_height][] = $position_width;
					
					$last_height = $position_height;
				}
			}
		}
	}
	
	static function parseLastDescription ($is_fee) {

		// Checking description for transaction type 
		// If found, add to type_pdf and match format for CSV
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Varer ', 'VARER'); // Varer => VARER:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Lønn ', 'LØNN'); // Lønn => LØNN:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Minibank ', 'MINIBANK'); // Minibank => MINIBANK:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Avtalegiro ', 'AVTALEGIRO'); // Avtalegiro => AVTALEGIRO:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Overføring ', 'OVERFØRSEL'); // Overføring => Overførsel:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Valuta ', 'VALUTA'); // Valuta => VALUTA:
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Nettbank til:', 'NETTBANK TIL'); // Nettbank til: => NETTBANK TIL
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Nettbank fra:', 'NETTBANK FRA'); // Nettbank til: => NETTBANK FRA
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Nettgiro til:', 'NETTGIRO TIL'); // Nettgiro til: => NETTGIRO TIL
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Nettgiro fra:', 'NETTGIRO FRA'); // Nettgiro fra: => NETTGIRO FRA
		self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf
			(self::$lasttransactions_description, 'Telegiro fra:', 'TELEGIRO FRA'); // Telegiro fra: => TELEGIRO FRA
		if(substr(self::$lasttransactions_description, 0, 1) == '*' && is_numeric(substr(self::$lasttransactions_description, 1, 4))) // *1234 = VISA VARE
		{
		}
		if($is_fee)
		{
			// 1 Kontohold => Kontohold
			self::$lasttransactions_description = str_replace('1 Kontohold', 'Kontohold', self::$lasttransactions_description);
			self::$lasttransactions_type = 'GEBYR';
		}
	
		if(self::$lasttransactions_type == 'VARER')
		{
			self::$lasttransactions_description  = trim(substr(self::$lasttransactions_description, 5));
		}
	
		if(
			self::$lasttransactions_type == 'NETTBANK TIL' ||
			self::$lasttransactions_type == 'NETTBANK FRA' ||
			self::$lasttransactions_type == 'NETTGIRO TIL' ||
			self::$lasttransactions_type == 'NETTGIRO FRA' ||
			self::$lasttransactions_type == 'TELEGIRO FRA'
		) {
			// Nettbank til: Some Name Betalt: DD.MM.YY
			// Nettbank fra: Some Name Betalt: 31.12.99
			// Nettgiro til: 1234.56.78901 Betalt: 01.01.11
			// Nettgiro fra: Some Name Betalt: 01.01.11
			// Telegiro fra: Some Name Betalt: 01.01.11
			//
			// Format:
			// TYPE: TEXT Betalt: 15.10.09
			//
			// Nettbank til/Nettbank fra/Nettgiro til are already chopped of
		
			// :? Search for "Betalt: " in the text
			$betalt_pos = strpos(self::$lasttransactions_description, 'Betalt: ');
			if($betalt_pos !== false)
			{
				// -> Found "Betalt: "
				$date_tmp = substr(
						self::$lasttransactions_description, 
						$betalt_pos+strlen('Betalt: ')
					);
				if(substr($date_tmp, 6) >= 90) // year 1990-1999
					$date_tmp = substr($date_tmp, 0, 6).'19'.substr($date_tmp, 6);
				else // year 2000-2099
					$date_tmp = substr($date_tmp, 0, 6).'20'.substr($date_tmp, 6);
				self::$lasttransactions_payment_date  = sb1helper::convert_stringDate_to_intUnixtime($date_tmp);
				self::$lasttransactions_description   = trim(substr(self::$lasttransactions_description, 0, $betalt_pos));
			}
		}
	}
	
	static function remove_firstpart_if_found_and_set_type_pdf ($description, $search, $type_if_matched)
	{
		if(substr($description, 0, strlen($search)) == $search)
		{
			$description = substr($description, strlen($search));
			self::$lasttransactions_type = $type_if_matched;
		}
		return $description;
	}
	
	/**
	 * Returns array with accounts found in the file
	 *
	 * @return  array
	 */
	public function getAccounts()
	{
		$this->isImportedOrThrowException();
		
		return $this->accounts;
	}
	
	public function isImportedOrThrowException ()
	{
		if(!$this->imported)
			throw new Exception('PDF file is not imported');
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
