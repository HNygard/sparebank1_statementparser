<?php

class sb1helper
{
	/**
	 * Convert a string with kroner to ører in integer
	 *
	 * @param   string   Amount, format: 1.234,93
	 * @return  integer  Ører
	 */
	static function stringKroner_to_intOerer($amount)
	{
		/*$amount =
			(int)
			(str_replace(',', '.',
			str_replace(' ', '',
			str_replace('.', '', $td[2])))*100);*/
		
		//$amount = $td[2]; // (string) "1.234,93"
		$amount = str_replace('.', '', $amount); // (string) "1234,93"
		$amount = str_replace(' ', '', $amount); //
		$amount = str_replace(',', '.', $amount); // (string) "1234.93"
		
		// Making integer
		$tmp = explode('.', $amount, 2);
		if(count($tmp) == 2)
			$amount = (int)(
					((int)$tmp[0]*100)+
					$tmp[1]
				);
		else
			$amount = (int)$tmp[0];
		//$amount = $amount*100; // (float) 123493
		//$amount = (int)$amount; // (int) 123493
		
		return $amount;
	}
	
	/**
	 * Convert a string with date to unix time
	 *
	 * @param   string   Date, format: 3112 or 31.12.2011
	 * @param   integer  Year, optional. Needed if format is 3112
	 * @return  integer  Unix time
	 */
	static function convert_stringDate_to_intUnixtime ($date, $year = null)
	{
		if(strlen($date) == strlen('31.12.2011'))
		{
			$parts = explode('.', $date);
			if(count($parts) == 3)
			{
				return mktime (0, 0, 0, $parts[1], $parts[0], $parts[2]);
			}
		}
		elseif(
			strlen($date) == strlen('3112') &&
			!is_null($year)
		)
		{
			return mktime (0, 0, 0, substr($date, 2, 2), substr($date, 0, 2), $year);
		}
		throw new Kohana_Exception ('Unknown date format: :date', array(':date' => $date));
	}
	
	/**
	 * Get the date including the year
	 * 
	 * If the transaction is done on a friday, saturday or sunday the date
	 * on the recite will differ from payment_date.
	 *
	 * @param  String  Date with format "dd.mm"
	 * @return String  Date with format "dd.mm.YYYY"
	 */
	public static function getDateWithYear ($tmp, $unixtime_paymentdate)
	{
		// Adding year
		if(
			($tmp == '31.12' || $tmp == '30.12' || $tmp == '29.12') &&
			date('m', $unixtime_paymentdate) == '01'
		)
		{
			$tmp = $tmp.'.'.(date('Y', $unixtime_paymentdate)-1);
		}
		else
		{
			$tmp = $tmp.'.'.date('Y', $unixtime_paymentdate);
		}
		return $tmp;
	}

	static function replace_firstpart_if_found ($description, $search, $replace_with)
	{
		if(substr($description, 0, strlen($search)) == $search)
		{
			$description = $replace_with.substr($description, strlen($search));
		}
		return $description;
	}
}
