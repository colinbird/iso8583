<?php

namespace ISO8583\Mapper;

class AlphaNumeric extends AbstractMapper
{
    const EBCDIC_TO_ASCII = [
        "40" => " ",
        "4A" => "¢",
        "4B" => ".",
        "4C" => "<",
        "4D" => "(",
        "4E" => "+",
        "4F" => "|",
        "5A" => "!",
        "5B" => "$",
        "5C" => "*",
        "5D" => ")",
        "5E" => ",",
        "5F" => "¬",
        "60" => "-",
        "61" => "/",
        "6A" => "¦",
        "6B" => ",",
        "6C" => "%",
        "6D" => "_",
        "6E" => ">",
        "6F" => "?",
        "79" => "`",
        "7A" => ":",
        "7B" => "#",
        "7C" => "@",
        "7D" => "'",
        "7E" => "=",
        "7F" => " '' ",
        "81" => "a",
        "82" => "b",
        "83" => "c",
        "84" => "d",
        "85" => "e",
        "86" => "f",
        "87" => "g",
        "88" => "h",
        "89" => "i",
        "91" => "j",
        "92" => "k",
        "93" => "l",
        "94" => "m",
        "95" => "n",
        "96" => "o",
        "97" => "p",
        "98" => "q",
        "99" => "r",
        "A1" => "~",
        "A2" => "s",
        "A3" => "t",
        "A4" => "u",
        "A5" => "v",
        "A6" => "w",
        "A7" => "x",
        "A8" => "y",
        "A9" => "z",
        "C0" => "{",
        "C1" => "A",
        "C2" => "B",
        "C3" => "C",
        "C4" => "D",
        "C5" => "E",
        "C6" => "F",
        "C7" => "G",
        "C7" => "H",
        "C9" => "I",
        "D0" => "}",
        "D1" => "J",
        "D2" => "K",
        "D3" => "L",
        "D4" => "M",
        "D5" => "N",
        "D6" => "O",
        "D7" => "P",
        "D8" => "Q",
        "D9" => "R",
        "E0" => "\\",
        "E2" => "S",
        "E3" => "T",
        "E4" => "U",
        "E5" => "V",
        "E6" => "W",
        "E7" => "X",
        "E8" => "Y",
        "E9" => "Z",
        "F0" => "0",
        "F1" => "1",
        "F2" => "2",
        "F3" => "3",
        "F4" => "4",
        "F5" => "5",
        "F6" => "6",
        "F7" => "7",
        "F8" => "8",
        "F9" => "9",
        "FF" => "E0"
    ];

    public function pack($message, $format = 'n')
    {
        echo "data      : " . $message . PHP_EOL;
        if ($format == 'n') {
            $packed = $message;
            if (strlen($packed) % 2 !== 0) {
                if ($this->getVariableLength() > 0) {
                    $packed = $packed. 'F';
                } else {
                    $packed = '0' . $packed;
                }
            }
        } else {
            $packed = $this->asciiToEbcdic($message);
        }

        $encodedLenth = "";
        if ($this->getVariableLength() > 0) {
            if ($format == 'n') {
                $length = (int) ceil(strlen($message) / 2);     // numbers are BCD coded
            } else {
                $length = strlen($message);
            }
            $length = str_pad($length, $this->getVariableLength(), '0', STR_PAD_LEFT);
            $encodedLenth = $this->asciiToEbcdic($length);
        }

        echo "with header: " . $encodedLenth . PHP_EOL;
        echo "encoded as : " . $packed . PHP_EOL;

        return $encodedLenth . $packed;
    }

    public function unpack(&$message, $format, $ascii = false)
    {
        echo PHP_EOL;
        $varLength = $this->getVariableLength();
        if ($varLength > 0) {
            $lengthHex = substr($message, 0, $varLength * 2);   // length always in EBCDIC
            $length = (int) $this->ebcdicToAscii($lengthHex) * 2;
            //echo "var field LEN = " . (int) $this->ebcdicToAscii($lengthHex) . PHP_EOL;
        } else {
            if ($format === 'n') {
                $length = $this->getLength(); // BCD
            } else {
                $length = $this->getLength() * 2; // EBCDIC
            }
//            echo "fix field LEN = " . $length . PHP_EOL;
            if ($length % 2 !== 0) {
                // If length is odd, need to pad one nibble for BCD
                $length += 1;
            }
        }
        if ($format == 'n') {       // BCD
            $unpacked = substr($message, $varLength * 2, $length);
//            echo 'N DATA IS : ' . $unpacked . PHP_EOL;
            if ($varLength > 0) {
                // fixed n fields are right padded with F
                $unpacked = (int) str_replace('F', '', $unpacked);
            } else {
                if (strpos($unpacked, 'F') !== false) {
                    die("F inside fixed length numeric");
                }
                $unpacked = $unpacked;
            }
//            echo 'N UNPACKED : ' . $unpacked . PHP_EOL;
        } else {    // EBCDID
            $unpacked = substr($message, $varLength * 2, $length);

            if ($ascii) {
                $unpacked = $this->hex2str($unpacked);
            } else {
                $unpacked = $this->ebcdicToAscii($unpacked);
            }
//            echo 'AN UNPACKED: "' . $unpacked . '"' . PHP_EOL;
        }

//        echo 'UNPACKED : ' . $unpacked . PHP_EOL;

        $message = substr($message, (int) $length + (int) $varLength * 2);

        return $unpacked;
    }

    private function asciiToEbcdic($str) {
        $arr = str_split($str);
        $encoded = "";
        $asciiToEbcdic = array_flip(self::EBCDIC_TO_ASCII);
        foreach ($arr as $char) {
            $encoded .= $asciiToEbcdic[$char];
        }

        return $encoded;
    }

    private function ebcdicToAscii($str) {
        $arr = str_split($str, 2);
        $encoded = "";
        foreach ($arr as $char) {
            if (isset(self::EBCDIC_TO_ASCII[strtoupper($char)])) {
                $encoded .= self::EBCDIC_TO_ASCII[strtoupper($char)];
            } else {
                //echo "missing char : " . $char . PHP_EOL;
            }
        }

        return $encoded;
    }

    function hex2str($hex) {
        $str = '';
        for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
        return $str;
    }
}
