<?php
require_once __DIR__ . '/statement-parser-common.php';

class sparebank1_statementparser_exstream_pdf extends sparebank1_statementparser_common {

    /**
     * Parse and read data from a Extream PDF
     *
     * Can parse data from:
     * Author = "Registered to: EDB DRFT"
     * Creator = "Exstream Dialogue Version 5.0.051" OR "HP Exstream Version 7.0.605"
     */
    public static function parseAndReadExstreamPdf($accountTranslations) {

        if (!count(pdf2textwrapper::$table)) {
            throw new Exception('PDF parser failed. Unable to read any lines from post jan 2008 PDF.');
        }

        $next_is_balance_in = false;
        $next_is_balance_out = false;
        $next_is_fee = false;
        $next_is_transactions = false;

        $accounts = array();
        $last_account = null;
        $the_table = pdf2textwrapper::$table;
        foreach ($the_table as $td_id => $td) {
            if (!is_array($td)) {
                continue;
            }

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
                        is_numeric(substr($td[6], 1)) // *123123123
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
            ) {
                $td_tmp = $td;
                $td = array();
                $td[0] = $td_tmp[0] . ' ' . $td_tmp[1];
                $td[1] = $td_tmp[2];
                $td[2] = $td_tmp[3];
                $td[3] = $td_tmp[4];
                if (isset($td_tmp[5])) {
                    $td[4] = $td_tmp[5];
                }
                if (isset($td_tmp[6])) {
                    $td[5] = $td_tmp[6];
                }
            }

            if (
                // Line with
                // Example data: Kontoutskrift nr. 2 for konto 1234.12.12345 i perioden 01.02.2011 - 28.02.2011 Alltid Pluss 18-34
                count($td) == 1 &&
                (substr($td[0], 0, strlen('Kontoutskrift nr. ')) == 'Kontoutskrift nr. '
                    || substr($td[0], 0, strlen('Korrigert kontoutskrift nr. ')) == 'Korrigert kontoutskrift nr. ')
            ) {
                preg_match('/(?:Korrigert kontoutskrift|Kontoutskrift) nr. (.*) for konto (.*) i perioden (.*) - (.*)/', $td[0], $parts);
                if (count($parts) == 5) {
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
                            'account_type' => $account_type,
                            'transactions' => array(),
                            'control_amount' => 0,
                        );
                    }

                    $next_is_fee = false;
                    $next_is_transactions = true;
                }
            }
            elseif (
                // Checking for a row with transaction
                (
                    count($td) == 4 || // Personal accounts
                    (
                        // Business accounts
                        count($td) == 6 &&
                        is_numeric($td[4]) && // 123321123
                        is_numeric(substr($td[5], 1)) // *123123123
                    ) ||
                    (
                        // Business accounts
                        count($td) == 5 &&
                        is_numeric(str_replace('*', '', $td[4])) // 123321123 or *123321123
                    )
                ) &&
                is_numeric($td[1]) && strlen($td[1]) == 4 && // ddmm, interest_date
                is_numeric(
                    str_replace(',', '.',
                        str_replace(' ', '',
                            str_replace('.', '', $td[2])))) && // amount
                is_numeric($td[3]) && strlen($td[3]) == 4// ddmm, payment_date
            ) {
                $amount = sb1helper::stringKroner_to_intOerer($td[2]);

                $pos_amount = pdf2textwrapper::$table_pos[$td_id][1][2];
                $pos_payment_date = pdf2textwrapper::$table_pos[$td_id][1][3];

                // If pos_amount is less than 365, the money goes out of the account
                // If pos_amount is more than 365, the money goes into the account
                if ($pos_amount <
                    (
                        365 + // pos if 0,00 goes out of the account
                        19 // margin
                    )
                ) {
                    $amount = -$amount;
                }

                self::$lasttransactions_interest_date = sb1helper::convert_stringDate_to_intUnixtime
                ($td[1], date('Y', $accounts[$last_account]['accountstatement_end']));
                self::$lasttransactions_payment_date = sb1helper::convert_stringDate_to_intUnixtime
                ($td[3], date('Y', $accounts[$last_account]['accountstatement_end']));

                self::$lasttransactions_description = $td[0];
                self::$lasttransactions_type = '';

                self::parseLastDescription($next_is_fee);

                $accounts[$last_account]['control_amount'] += $amount;
                $accounts[$last_account]['transactions'][] = array(
                    'bankaccount_id' => $last_account_id,
                    'description' => self::$lasttransactions_description,
                    'interest_date' => self::$lasttransactions_interest_date,
                    'amount' => ($amount / 100),
                    'payment_date' => self::$lasttransactions_payment_date,
                    'type' => self::$lasttransactions_type,
                );
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
            elseif (
                // Saldo frå kontoutskrift dd.mm.yyyy
                (
                    count($td) == 4 &&
                    trim($td[0]) == 'Saldo' &&
                    (trim($td[1]) == 'frå' || trim($td[1]) == 'fra') && // Nynorsk and bokmål
                    trim($td[2]) == 'kontoutskrift'
                ) || (count($td) == 3 && trim($td[0]) == 'Saldo' && trim($td[1]) == 'frå' && substr(trim($td[2]), 0, strlen('kontoutskrift')) == 'kontoutskrift')
            ) {
                $next_is_balance_in = true;
            }
            elseif (
                // Balance in on this account statement
            $next_is_balance_in
            ) {
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

                    - 1835, 2 digits negative
                    - 1854, 1 digits negative

                    => Using from 1850 to 1950 as negative

                    Business accounts:
                    - 1640, 5 digits positive, "Saldo frå kontoutskrift 31.08.2016", "10.009,00"
                    - 1456, 1 digits negative, "Saldo frå kontoutskrift 31.08.2016", "9,00"

                    => Using from 1000 to 1456 as negative
                 */
                $balance_in = sb1helper::stringKroner_to_intOerer($td[0]);
                $pos_amount = pdf2textwrapper::$table_pos[$td_id][1][0];
                if (
                    ($pos_amount >= 1835 && $pos_amount <= 1950)
                    || ($pos_amount >= 1000 && $pos_amount <= 1456)
                ) {
                    $balance_in = -$balance_in;
                }
                $accounts[$last_account]['accountstatement_balance_in'] = $balance_in;
                $accounts[$last_account]['control_amount'] += $accounts[$last_account]['accountstatement_balance_in'];
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
            elseif (
                // Saldo i Dykkar favør
                (count($td) == 17 && implode($td) == 'SaldoiDykkarfavør') || // Nynorsk
                (count($td) == 16 && implode($td) == 'SaldoiDeresfavør')     // Bokmål
            ) {
                // -> Balance out is positive
                $next_is_balance_out = true;
                $next_is_transactions = false;
                $balance_out_is_positive = true;
            }
            elseif (
                // Saldo i vår favør
            (count($td) == 14 && implode($td) == 'Saldoivårfavør')
            ) {
                // -> Balance out is negative
                $next_is_balance_out = true;
                $next_is_transactions = false;
                $balance_out_is_positive = false;
            }
            elseif (
                // Balance out on this account statement
            $next_is_balance_out
            ) {
                $balance_out = sb1helper::stringKroner_to_intOerer($td[0]);
                if (!$balance_out_is_positive) {
                    // -> Negative balance
                    $balance_out = -$balance_out;
                }
                $accounts[$last_account]['accountstatement_balance_out'] = $balance_out;
                $next_is_balance_out = false;
            }
            elseif (
                implode($td) == 'Kostnadervedbrukavbanktjenester:' ||  // Bokmål
                implode($td) == 'Kostnadervedbrukavbanktenester:'      // Nynorsk
            ) {
                // The next detected transactions, if any, is fees
                $next_is_fee = true;
            }
        }
        return $accounts;
    }
}