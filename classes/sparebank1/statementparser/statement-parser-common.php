<?php

class sparebank1_statementparser_common {
    protected static $lasttransactions_description;
    protected static $lasttransactions_type;
    protected static $lasttransactions_interest_date = null;
    protected static $lasttransactions_payment_date = null;

    static function parseLastDescription($is_fee) {

        // Checking description for transaction type
        // If found, add to type_pdf and match format for CSV

        $pdf_transaction_type_search = array();
        // Varer => VARER
        $pdf_transaction_type_search['Varer '] = 'VARER';
        // Lønn => LØNN
        $pdf_transaction_type_search['Lønn '] = 'LØNN';
        // Minibank => MINIBANK
        $pdf_transaction_type_search['Minibank '] = 'MINIBANK';
        // Avtalegiro => AVTALEGIRO
        $pdf_transaction_type_search['Avtalegiro '] = 'AVTALEGIRO';
        // Overføring => Overførsel
        $pdf_transaction_type_search['Overføring '] = 'OVERFØRSEL';
        // Overførsel => OVERFØRSEL
        $pdf_transaction_type_search['Overførsel '] = 'OVERFØRSEL';
        // Valuta => VALUTA
        $pdf_transaction_type_search['Valuta '] = 'VALUTA';
        // Nettbank til: => NETTBANK TIL
        $pdf_transaction_type_search['Nettbank til:'] = 'NETTBANK TIL';
        // Nettbank til: => NETTBANK FRA
        $pdf_transaction_type_search['Nettbank fra:'] = 'NETTBANK FRA';
        // Giro Fra: => GIRO FRA
        $pdf_transaction_type_search['Giro Fra:'] = 'GIRO FRA';
        // Nettgiro til: => NETTGIRO TIL
        $pdf_transaction_type_search['Nettgiro til:'] = 'NETTGIRO TIL';
        // Nettgiro Til: => NETTGIRO TIL
        $pdf_transaction_type_search['Nettgiro Til:'] = 'NETTGIRO TIL';
        // Nettgiro fra: => NETTGIRO FRA
        $pdf_transaction_type_search['Nettgiro fra:'] = 'NETTGIRO FRA';
        // Nettgiro Fra: => NETTGIRO FRA
        $pdf_transaction_type_search['Nettgiro Fra:'] = 'NETTGIRO FRA';
        // Telegiro fra: => TELEGIRO FRA
        $pdf_transaction_type_search['Telegiro fra:'] = 'TELEGIRO FRA';
        // Telegiro Til: => TELEGIRO TIL
        $pdf_transaction_type_search['Telegiro Til:'] = 'TELEGIRO TIL';
        // Mobilbank fra: => MOBILBANK FRA
        $pdf_transaction_type_search['Mobilbank fra: '] = 'MOBILBANK FRA';
        // Innskudd Fra: => INNSKUDD FRA
        $pdf_transaction_type_search['Innskudd Fra:'] = 'INNSKUDD';
        // Innskudd => INNSKUDD
        $pdf_transaction_type_search['Innskudd automat'] = 'INNSKUDD';
        // Bedrterm overf. Fra: => BEDRTERM OVERFØRSEL
        $pdf_transaction_type_search['Bedrterm overf. Fra:'] = 'BEDRTERM OVERFØRSEL';
        foreach ($pdf_transaction_type_search as $search => $transaction_type) {
            self::$lasttransactions_description = self::remove_firstpart_if_found_and_set_type_pdf(
                self::$lasttransactions_description,
                $search,
                $transaction_type);
        }
        if (substr(self::$lasttransactions_description, 0, 1) == '*' && is_numeric(substr(self::$lasttransactions_description, 1, 4))) // *1234 = VISA VARE
        {
        }
        if ($is_fee) {
            // 1 Kontohold => Kontohold
            self::$lasttransactions_description = str_replace('1 Kontohold', 'Kontohold', self::$lasttransactions_description);
            self::$lasttransactions_type = 'GEBYR';
        }

        if (self::$lasttransactions_type == 'VARER') {
            self::$lasttransactions_description = trim(substr(self::$lasttransactions_description, 5));
        }

        if (
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
            if ($betalt_pos !== false) {
                // -> Found "Betalt: "
                $date_tmp = substr(
                    self::$lasttransactions_description,
                    $betalt_pos + strlen('Betalt: ')
                );
                if (substr($date_tmp, 6) >= 90) // year 1990-1999
                {
                    $date_tmp = substr($date_tmp, 0, 6) . '19' . substr($date_tmp, 6);
                }
                else // year 2000-2099
                {
                    $date_tmp = substr($date_tmp, 0, 6) . '20' . substr($date_tmp, 6);
                }
                self::$lasttransactions_payment_date = sb1helper::convert_stringDate_to_intUnixtime($date_tmp);
                self::$lasttransactions_description = trim(substr(self::$lasttransactions_description, 0, $betalt_pos));
            }
        }
    }

    static function remove_firstpart_if_found_and_set_type_pdf($description, $search, $type_if_matched) {
        if (substr($description, 0, strlen($search)) == $search) {
            $description = trim(substr($description, strlen($search)));
            self::$lasttransactions_type = $type_if_matched;
        }
        return $description;
    }
}