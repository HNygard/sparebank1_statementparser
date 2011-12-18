<?php

/**
 * PDF2Text from http://www.webcheatsheet.com/php/reading_clean_text_from_pdf.php
 * 
 * Wrapped into pdf2text class by Hallvard Nygard
 * Also modified getDirtyTexts to save tables in a array
 * 
 * @author Unknown / Webcheatsheet.com
 * @see http://www.webcheatsheet.com/php/reading_clean_text_from_pdf.php
 */

class pdf2textwrapper
{
	public static $table;
	public static $table_pos;
	public static $debugging = false;
	public static $pdf_author;
	public static $pdf_creationdate;
	public static $pdf_creator;
	public static $pdf_producer;
static function decodeAsciiHex($input) {
    $output = "";

    $isOdd = true;
    $isComment = false;

    for($i = 0, $codeHigh = -1; $i < strlen($input) && $input[$i] != '>'; $i++) {
        $c = $input[$i];

        if($isComment) {
            if ($c == '\r' || $c == '\n')
                $isComment = false;
            continue;
        }

        switch($c) {
            case '\0': case '\t': case '\r': case '\f': case '\n': case ' ': break;
            case '%': 
                $isComment = true;
            break;

            default:
                $code = hexdec($c);
                if($code === 0 && $c != '0')
                    return "";

                if($isOdd)
                    $codeHigh = $code;
                else
                    $output .= chr($codeHigh * 16 + $code);

                $isOdd = !$isOdd;
            break;
        }
    }

    if($input[$i] != '>')
        return "";

    if($isOdd)
        $output .= chr($codeHigh * 16);

    return $output;
}
static function decodeAscii85($input) {
    $output = "";

    $isComment = false;
    $ords = array();
    
    for($i = 0, $state = 0; $i < strlen($input) && $input[$i] != '~'; $i++) {
        $c = $input[$i];

        if($isComment) {
            if ($c == '\r' || $c == '\n')
                $isComment = false;
            continue;
        }

        if ($c == '\0' || $c == '\t' || $c == '\r' || $c == '\f' || $c == '\n' || $c == ' ')
            continue;
        if ($c == '%') {
            $isComment = true;
            continue;
        }
        if ($c == 'z' && $state === 0) {
            $output .= str_repeat(chr(0), 4);
            continue;
        }
        if ($c < '!' || $c > 'u')
            return "";

        $code = ord($input[$i]) & 0xff;
        $ords[$state++] = $code - ord('!');

        if ($state == 5) {
            $state = 0;
            for ($sum = 0, $j = 0; $j < 5; $j++)
                $sum = $sum * 85 + $ords[$j];
            for ($j = 3; $j >= 0; $j--)
                $output .= chr($sum >> ($j * 8));
        }
    }
    if ($state === 1)
        return "";
    elseif ($state > 1) {
        for ($i = 0, $sum = 0; $i < $state; $i++)
            $sum += ($ords[$i] + ($i == $state - 1)) * pow(85, 4 - $i);
        for ($i = 0; $i < $state - 1; $i++)
            $ouput .= chr($sum >> ((3 - $i) * 8));
    }

    return $output;
}
static function decodeFlate($input) {
    return @gzuncompress($input);
}

static function getObjectOptions($object) {
    $options = array();
    if (preg_match("#<<(.*)>>#ismU", $object, $options)) {
        $options = explode("/", $options[1]);
        @array_shift($options);

        $o = array();
        for ($j = 0; $j < @count($options); $j++) {
            $options[$j] = preg_replace("#\s+#", " ", trim($options[$j]));
            if (strpos($options[$j], " ") !== false) {
                $parts = explode(" ", $options[$j]);
                $o[$parts[0]] = $parts[1];
            } else
                $o[$options[$j]] = true;
        }
        $options = $o;
        unset($o);
    }

    return $options;
}
static function getDecodedStream($stream, $options) {
    $data = "";
    if (empty($options["Filter"])) {
        $data = $stream;
    }
    else {
        $length = !empty($options["Length"]) ? $options["Length"] : strlen($stream);
        $_stream = substr($stream, 0, $length);

        foreach ($options as $key => $value) {
            if ($key == "ASCIIHexDecode" || $key == 'AHx') // AHx added by Hallvard Nygard
                $_stream = pdf2textwrapper::decodeAsciiHex($_stream);
            if ($key == "ASCII85Decode" || $key == 'A85') // A85 added by Hallvard Nygard
                $_stream = pdf2textwrapper::decodeAscii85($_stream);
            
            // ?: Is the filter FlateDecode?
            if ($key == "FlateDecode" || $key == 'Fl' || $key == 'FlateDecode]') {
		// Fl & "FlateDecode]" added by Hallvard Nygard
                // Added "FlateDecode]" since I don't want to fix the reg ex
                $_stream = pdf2textwrapper::decodeFlate($_stream);
            }
        }
        $data = $_stream;
    }
    return $data;
}

/**
 * Modified by Hallvard Nygard
 */
static function getDirtyTexts(&$texts, $textContainers) {
    for ($j = 0; $j < count($textContainers); $j++) {
        if (preg_match_all("#\[(.*)\]\s*TJ#ismU", $textContainers[$j], $parts))
            $texts = array_merge($texts, @$parts[1]);
        elseif(preg_match_all("#Td\s*(\(.*\))\s*Tj#ismU", $textContainers[$j], $parts))
	{
		pdf2textwrapper::$table[] = pdf2textwrapper::fixEscape(pdf2textwrapper::stripParentheses($parts[1]));
		$texts = array_merge($texts, @$parts[1]);
		
		preg_match_all('#([0-9]*)\s([0-9]*)\sTd\s\(.*\)\sTj#ismU', $textContainers[$j], $parts2);
		pdf2textwrapper::$table_pos[] = $parts2;
	}
    }
}
static function stripParentheses($strip)
{
	if(is_array($strip))
	{
		foreach($strip as $a => $b)
		{
			$strip[$a] = pdf2textwrapper::stripParentheses($b);
		}
		return $strip;
	}
	else
	{
		if(substr($strip, 0, 1) == '(' && substr($strip, strlen($strip)-1, 1) == ')')
			return substr($strip, 1, strlen($strip)-2);
		else
			return $strip; // Nothing to strip away
	}
}
static function fixEscape($strip)
{
	if(is_array($strip))
	{
		foreach($strip as $a => $b)
		{
			$strip[$a] = pdf2textwrapper::fixEscape($b);
		}
		return $strip;
	}
	else
	{
		return 
			str_replace('\\(', '(',
			str_replace('\\)', ')',
			str_replace('\\370', 'ø',
			str_replace('\\345', 'å',
			str_replace('\\233', 'ø',
			str_replace('\\346', 'æ',
			str_replace('\\305', 'Å',
			str_replace('\\326', 'ö',
			str_replace('\\323', 'ø',
			str_replace('\\330', 'Ø',
				$strip
			))))))))));
	}
}
static function getCharTransformations(&$transformations, $stream) {
    preg_match_all("#([0-9]+)\s+beginbfchar(.*)endbfchar#ismU", $stream, $chars, PREG_SET_ORDER);
    preg_match_all("#([0-9]+)\s+beginbfrange(.*)endbfrange#ismU", $stream, $ranges, PREG_SET_ORDER);

    for ($j = 0; $j < count($chars); $j++) {
        $count = $chars[$j][1];
        $current = explode("\n", trim($chars[$j][2]));
        for ($k = 0; $k < $count && $k < count($current); $k++) {
            if (preg_match("#<([0-9a-f]{2,4})>\s+<([0-9a-f]{4,512})>#is", trim($current[$k]), $map))
                $transformations[str_pad($map[1], 4, "0")] = $map[2];
        }
    }
    for ($j = 0; $j < count($ranges); $j++) {
        $count = $ranges[$j][1];
        $current = explode("\n", trim($ranges[$j][2]));
        for ($k = 0; $k < $count && $k < count($current); $k++) {
            if (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+<([0-9a-f]{4})>#is", trim($current[$k]), $map)) {
                $from = hexdec($map[1]);
                $to = hexdec($map[2]);
                $_from = hexdec($map[3]);

                for ($m = $from, $n = 0; $m <= $to; $m++, $n++)
                    $transformations[sprintf("%04X", $m)] = sprintf("%04X", $_from + $n);
            } elseif (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+\[(.*)\]#ismU", trim($current[$k]), $map)) {
                $from = hexdec($map[1]);
                $to = hexdec($map[2]);
                $parts = preg_split("#\s+#", trim($map[3]));
                
                for ($m = $from, $n = 0; $m <= $to && $n < count($parts); $m++, $n++)
                    $transformations[sprintf("%04X", $m)] = sprintf("%04X", hexdec($parts[$n]));
            }
        }
    }
}
static function getTextUsingTransformations($texts, $transformations) {
    $document = "";
    for ($i = 0; $i < count($texts); $i++) {
        $isHex = false;
        $isPlain = false;

        $hex = "";
        $plain = "";
        for ($j = 0; $j < strlen($texts[$i]); $j++) {
            $c = $texts[$i][$j];
            switch($c) {
                case "<":
                    $hex = "";
                    $isHex = true;
                break;
                case ">":
                    $hexs = str_split($hex, 4);
                    for ($k = 0; $k < count($hexs); $k++) {
                        $chex = str_pad($hexs[$k], 4, "0");
                        if (isset($transformations[$chex]))
                            $chex = $transformations[$chex];
                        $document .= html_entity_decode("&#x".$chex.";");
                    }
                    $isHex = false;
                break;
                case "(":
                    $plain = "";
                    $isPlain = true;
                break;
                case ")":
                    $document .= $plain;
                    $isPlain = false;
                break;
                case "\\":
                    $c2 = $texts[$i][$j + 1];
                    if (in_array($c2, array("\\", "(", ")"))) $plain .= $c2;
                    elseif ($c2 == "n") $plain .= '\n';
                    elseif ($c2 == "r") $plain .= '\r';
                    elseif ($c2 == "t") $plain .= '\t';
                    elseif ($c2 == "b") $plain .= '\b';
                    elseif ($c2 == "f") $plain .= '\f';
                    elseif ($c2 >= '0' && $c2 <= '9') {
                        $oct = preg_replace("#[^0-9]#", "", substr($texts[$i], $j + 1, 3));
                        $j += strlen($oct) - 1;
                        $plain .= html_entity_decode("&#".octdec($oct).";");
                    }
                    $j++;
                break;

                default:
                    if ($isHex)
                        $hex .= $c;
                    if ($isPlain)
                        $plain .= $c;
                break;
            }
        }
        $document .= "\n";
    }

    return $document;
}

static function pdf2text($filename) {
	$infile = @file_get_contents($filename, FILE_BINARY);
	return pdf2text_fromstring($infile);
}
static function pdf2text_fromstring($infile)
{
	pdf2textwrapper::$table = array();
	pdf2textwrapper::$table_pos = array();
	if (empty($infile))
		return "";

	$transformations = array();
	$texts = array();

	preg_match_all("#obj(.*)endobj#ismU", $infile, $objects);
	$objects = @$objects[1];

	for ($i = 0; $i < count($objects); $i++) {
		$currentObject = $objects[$i];

		if (preg_match("#stream(.*)endstream#ismU", $currentObject, $stream)) {
			$stream = ltrim($stream[1]);

			$options = pdf2textwrapper::getObjectOptions($currentObject);
			if (!(empty($options["Length1"]) && empty($options["Type"]) && empty($options["Subtype"])))
				continue;

			$data = pdf2textwrapper::getDecodedStream($stream, $options);
			if (strlen($data)) {
				
				// Remove \r from data. This could be fixed in the reg ex below
				$data = str_replace("\r", '', $data);
				
				if (preg_match_all("#BT\n(.*)ET\n#ismU", $data, $textContainers)) {
					$textContainers = @$textContainers[1];
					pdf2textwrapper::getDirtyTexts($texts, $textContainers);
				} else {
					pdf2textwrapper::getCharTransformations($transformations, $data);
				}
			}
		}
	}
	
	// :: Get information about author, creator, etc from PDF
	preg_match_all('#Author.\((.*)\)#ismU',       $infile, $author);
	preg_match_all('#CreationDate.\((.*)\)#ismU', $infile, $creationDate);
	preg_match_all('#Creator.\((.*)\)#ismU',      $infile, $creator);
	preg_match_all('#Producer.\((.*)\)#ismU',     $infile, $producer);
	if(isset($author[1][0]))       { self::$pdf_author       = $author[1][0]; }
	if(isset($creationDate[1][0])) { self::$pdf_creationdate = $creationDate[1][0]; }
	if(isset($creator[1][0]))      { self::$pdf_creator      = $creator[1][0]; }
	if(isset($producer[1][0]))     { self::$pdf_producer     = $producer[1][0]; }

	return pdf2textwrapper::getTextUsingTransformations($texts, $transformations);
}

}
