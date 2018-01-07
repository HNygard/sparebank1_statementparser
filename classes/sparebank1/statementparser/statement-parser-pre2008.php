<?php
require_once __DIR__ . '/statement-parser-common.php';

class sparebank1_statementparser_pre2008 extends sparebank1_statementparser_common {
    public static function parseAndReadJan2008Pdf($accountTranslations) {
        if (!count(pdf2textwrapper::$table)) {
            throw new Exception('PDF parser failed. Unable to read any lines from pre jan 2008 pdf.');
        }

        $next_is_fee = false;
        $next_is_transactions = false;

        $last_account = null;

        $accounts = array();
        $table = array();
        $table_width = array();
        $last_height = -1;
        $is_bank_account_statement_with_reference_numbers = false;
        foreach (pdf2textwrapper::$table as $td_id => $td) {

            if (
                ($td[0] == 'Kontoutskrift' && $td[1] == 'nr.')
                || ($td[0] == 'Korrigert' && $td[1] == 'kontoutskrift' && $td[2] == 'nr.')
            ) {
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
                $next_line = implode(pdf2textwrapper::$table[$td_id + 1], ' ');

                preg_match('/(?:Korrigert kontoutskrift|Kontoutskrift) nr. (.*) for konto (.*) i perioden (.*) - (.*) IBAN(.*)/',
                    $this_line . ' - ' . $next_line, $parts1);
                preg_match('/(?:Korrigert kontoutskrift|Kontoutskrift) nr. (.*) for konto (.*) i perioden (.*)-(.*)/',
                    $this_line, $parts2);
                preg_match('/(?:Korrigert kontoutskrift|Kontoutskrift) nr. (.*) for konto (.*) i perioden (.*) - (.*)/',
                    $this_line . ' - ' . $next_line, $parts3);
                if (count($parts1) == 6) {
                    $parts = $parts1;
                }
                elseif (count($parts2) == 5) {
                    $parts = $parts2;
                }
                elseif (count($parts3) == 5) {
                    $parts = $parts3;
                }
                else {
                    throw new Exception('Not able to retrieve account info.');
                }

                $accountstatement_num = $parts[1]; // 2
                $account_num = $parts[2]; // 1234.12.12345
                $accountstatement_start = sb1helper::convert_stringDate_to_intUnixtime
                ($parts[3]); // 01.02.2011
                $parts = explode(' ', $parts[4], 2); // 28.02.2011 Alltid Pluss 18-34
                $accountstatement_end = sb1helper::convert_stringDate_to_intUnixtime
                ($parts[0]); // 28.02.2011
                $account_type = $parts[1]; // Alltid Pluss 18-34

                $last_account = $account_num . '_' . $accountstatement_start;
                if (isset($accountTranslations[$account_num])) {
                    $last_account_id = $accountTranslations[$account_num];
                }
                else {
                    $last_account_id = -1;
                }

                // If account spans over several pages, the heading repeats
                if (!isset($accounts[$last_account])) {
                    $accounts[$last_account] = array(
                        'accountstatement_num' => $accountstatement_num,
                        'account_id' => $last_account_id,
                        'account_num' => $account_num,
                        'accountstatement_start' => $accountstatement_start,
                        'accountstatement_end' => $accountstatement_end,
                        'account_type' => mb_convert_encoding($account_type, 'UTF-8', 'Windows-1252'),
                        'transactions' => array(),
                        'control_amount' => 0,
                    );
                }
                $next_is_fee = false;
            }

            // Saldo i Dykkar favør, nynorsk
            // Saldo i Deres favør, bokmål
            // Saldo i vår favør, bokmål
            elseif (substr(implode($td), 0, strlen('SaldoiD')) == 'SaldoiD'
                || substr(implode($td), 0, strlen('Saldoiv')) == 'Saldoiv'
            ) {

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
                $next_is_transactions = false;

                // Saldo i Dykkar favør, nynorsk
                // Saldo i Deres favør, bokmål
                $balance_out_is_positive = substr(implode($td), 0, strlen('SaldoiD')) == 'SaldoiD';

                // Balance out is the 4th element
                $balance_out = sb1helper::stringKroner_to_intOerer($td[4]);
                if (!$balance_out_is_positive) {
                    // -> Negative balance
                    $balance_out = -$balance_out;
                }
                $accounts[$last_account]['accountstatement_balance_out'] = $balance_out;

                // :: Parse the transations we have collected
                //
                // - The thrid last  cell per line is the interest date
                // - The second last cell per line is the amount
                // - The last        cell per line is the payment date
                // Except the first line, which is balance from last bank statement
                foreach ($table as $key => $value) {
                    // The rows in this table might be:
                    // - Balance in
                    // - Heading be for fee (the next transations are fees)
                    // - Ignore "Overført frå forrige side"
                    // - Transactions

                    if (
                        // Nynorsk
                        (substr(implode($value), 0, strlen('Kostnadervedbrukavbanktenester:')) == 'Kostnadervedbrukavbanktenester:')
                        // Bokmål
                        || (substr(implode($value), 0, strlen('Kostnadervedbrukavbanktjenester:')) == 'Kostnadervedbrukavbanktjenester:')
                    ) {
                        $next_is_fee = true;
                    }

                    // ?: "Overført frå forrige side"?
                    elseif (preg_match('/Overf.rtfr.forrigeside/', implode($value))) {
                        // Ignore, not a transaction
                    }

                    else {
                        // -> Transactions
                        $is_balance_from_last_month = substr(implode($value), 0, strlen('Saldofr')) == 'Saldofr';

                        if ($is_balance_from_last_month) {
                            $key_amount = count($value) - 1;
                        }
                        else {
                            // If last column is not a date (e.g. 2401). Might be one or two columns with ref.
                            // Examples:
                            // - 170000000 * 17000000
                            // -             17000000
                            // - 170000000   17000000
                            if ($is_bank_account_statement_with_reference_numbers && strlen($value[count($value) - 1]) != 4) {
                                unset($value[count($value) - 1]);
                                if ($value[count($value) - 1] == '*') {
                                    // -> Remove *
                                    unset($value[count($value) - 1]);
                                }
                                if (strlen($value[count($value) - 1]) != 4) {
                                    // -> Second column of ref
                                    unset($value[count($value) - 1]);
                                }
                            }

                            $key_payment_date = count($value) - 1;
                            $key_amount = count($value) - 2;
                            $key_interest_date = count($value) - 3;
                            $payment_date = $value[$key_payment_date];
                            $interest_date = $value[$key_interest_date];
                            unset($value[$key_payment_date]);
                            unset($value[$key_interest_date]);

                            self::$lasttransactions_interest_date = sb1helper::convert_stringDate_to_intUnixtime
                            ($interest_date, date('Y', $accounts[$last_account]['accountstatement_end']));
                            self::$lasttransactions_payment_date = sb1helper::convert_stringDate_to_intUnixtime
                            ($payment_date, date('Y', $accounts[$last_account]['accountstatement_end']));
                        }

                        $amount = $value[$key_amount];

                        unset($value[$key_amount]);

                        $amount = sb1helper::stringKroner_to_intOerer($amount);
                        $pos_amount = $table_width[$key][$key_amount];
                        /*
                            Observed values for $pos_amount - without reference number:
                            486.7, 3 digits positive
                            474.2, 4 digits positive
                            416.9, 2 digits positive

                            421.9, 2 digits negative
                            411.8, 3 digits negative
                            404.4, 4 digits negative

                            Observed values for $pos_amount - with reference number:
                            359.3, 5 digits positive  (in  12 345,00)
                            364.3, 4 digits positive  (in   1 234,00)

                            311.8, 1 digits negative  (out      1,00)
                            294.2, 4 digits negative  (out  1 234,00)
                         */
                        if (
                            (!$is_bank_account_statement_with_reference_numbers && $pos_amount < 422)
                            || ($is_bank_account_statement_with_reference_numbers && $pos_amount < 312)
                        ) {
                            $amount = -$amount;
                        }

                        self::$lasttransactions_description = implode(' ', $value);
                        self::$lasttransactions_type = '';

                        if ($is_balance_from_last_month) {
                            $accounts[$last_account]['accountstatement_balance_in'] = $amount;
                            $accounts[$last_account]['control_amount']
                                += $accounts[$last_account]['accountstatement_balance_in'];
                            continue;
                        }


                        self::parseLastDescription($next_is_fee);

                        $accounts[$last_account]['control_amount'] += $amount;
                        $accounts[$last_account]['transactions'][] = array(
                            'bankaccount_id' => $last_account_id,
                            'description' => mb_convert_encoding(self::$lasttransactions_description, 'UTF-8', 'Windows-1252'),
                            'interest_date' => self::$lasttransactions_interest_date,
                            'amount' => ($amount / 100),
                            'payment_date' => self::$lasttransactions_payment_date,
                            'type' => self::$lasttransactions_type,
                        );
                    }
                }
            }

            elseif (substr(implode($td), 0, strlen('ForklaringRentedatoUtavkontoInn')) == 'ForklaringRentedatoUtavkontoInn') {
                $next_is_transactions = true;
                if ($td[count($td) - 1] == 'Referanse') {
                    $is_bank_account_statement_with_reference_numbers = true;
                }
            }

            elseif (preg_match('/Dato[0-9][0-9]\.[0-9][0-9]\.[0-9][0-9][0-9][0-9]Sidenr/', implode($td))) {
                $next_is_transactions = false; // Don't start parsing transactions until the heading has been parsed
                $last_height = -1;    // Start the count again
            }

            elseif ($next_is_transactions) {
                // All transactions are on one line in this format

                // Parse this line based on height in PDF
                // Transactions can be multi line in the description field
                foreach ($td as $key => $value) {
                    $position_width = pdf2textwrapper::$table_pos[$td_id][1][$key];
                    $position_height = pdf2textwrapper::$table_pos[$td_id][2][$key];

                    //echo '<tr><td colspan="4">PARSE TRANSACTION: '.$position_width .' - '.$position_height.': '.$value.'</td></tr>';

                    if (!isset($table[$position_height])) {
                        $table      [$position_height] = array();
                        $table_width[$position_height] = array();
                    }

                    if ($last_height != -1 && $last_height < $position_height) {
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
        return $accounts;
    }
}