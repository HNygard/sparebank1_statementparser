<?php

class sparebank1_statementparser_common {
    protected static $lasttransactions_description;
    protected static $lasttransactions_type;
    protected static $lasttransactions_interest_date;
    protected static $lasttransactions_payment_date;

    static function parseLastDescription($is_fee) {

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
            $description = substr($description, strlen($search));
            self::$lasttransactions_type = $type_if_matched;
        }
        return $description;
    }
}