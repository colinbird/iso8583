<?php
namespace ISO8583;

use ISO8583\Error\UnpackError;
use ISO8583\Error\PackError;
use ISO8583\Mapper\AlphaNumeric;

class Message
{
	protected $protocol;
	protected $options;

	protected $length;
	protected $mti;
    protected $bitmap;
    protected $fields = [];
	protected $mappers = [
		'a'     => Mapper\AlphaNumeric::class,
		'n'     => Mapper\AlphaNumeric::class,
		's'     => Mapper\AlphaNumeric::class,
		'an'    => Mapper\AlphaNumeric::class,
		'as'    => Mapper\AlphaNumeric::class,
		'ns'    => Mapper\AlphaNumeric::class,
		'ans'   => Mapper\AlphaNumeric::class,
		'b'	    => Mapper\Binary::class,
		'z'	    => Mapper\AlphaNumeric::class
	];

	public function __construct(Protocol $protocol, $options = [])
	{
		$defaults = [
			'lengthPrefix' => null
		];

		$this->options = $options + $defaults;
		$this->protocol = $protocol;
	}

	protected function shrink(&$message, $length)
	{
		$message = substr($message, $length);
	}

	public function pack()
	{
		// Setting MTI
		$mti = $this->mti;

		// Dropping bad fields
		foreach($this->fields as $key=>$val) {
			if (in_array($key, [1, 65])) {
				unset($this->fields[$key]);
			}
		}

		// Populating bitmap
		$bitmap = "";
		$bitmapLength = 64 * (int)(floor(max(array_keys($this->fields)) / 64) + 1);

		$tmpBitmap = "";
		for($i=1; $i <= $bitmapLength; $i++) {
			if (
				$i == 1 && $bitmapLength > 64 ||
				$i == 65 && $bitmapLength > 128 ||
				isset($this->fields[$i])
			) {
				$tmpBitmap .= '1';
			} else {
				$tmpBitmap .= '0';
			}

			if ($i % 64 == 0) {
				for($i=0; $i<64; $i+=4){
        			$bitmap .= sprintf('%01x', base_convert(substr($tmpBitmap, $i, 4), 2, 10));
      			}
			}
		}

		$this->bitmap = $bitmap;

		// Getting field IDS
		ksort($this->fields);

		// Packing fields
		$message = "";
		foreach($this->fields as $id => $data) {
			$fieldData = $this->protocol->getFieldData($id);
			$fieldMapper = $fieldData['type'];

			if (!isset($this->mappers[$fieldMapper])) {
				throw new \Exception('Unknown field mapper for "' . $fieldMapper . '" type');
			}

			$mapper = new $this->mappers[$fieldMapper]($fieldData['length']);

            echo $id . " " . json_encode(($fieldData)) . ' ' . get_class($mapper) . PHP_EOL;


            if (
				($mapper->getLength() > strlen($data) && $mapper->getVariableLength() === 0 ) ||
				$mapper->getLength() < strlen($data)
			) {
				$error = 'FIELD [' . $id . '] should have length: ' . $mapper->getLength() . ' and your message "' . $data . "' is " . strlen($data);
				throw new Error\PackError($error);
			}



			$pack =  $mapper->pack($data, $fieldData['type']);

            $message .= $pack;

            echo "*** LENGTH: " . strlen($message) . " ADDED " . $pack . PHP_EOL;

            echo $message . PHP_EOL;
		}

		// Packing all message
		$message = $mti . $bitmap . $message;
//		if ($this->options['lengthPrefix'] > 0) {
//			$length = strlen($message);
//			$header = $this->asciiToEbcdic()
//			$message = $header . $message;
//		}

		return $message;
	}

	public function unpack($message)
	{
	    $message = strtoupper($message);
		// Getting message length if we have one
		if ($this->options['lengthPrefix'] > 0) {
		    $lengthHex = substr($message, 0, (int)$this->options['lengthPrefix'] * 2);
			$length = hexdec($lengthHex);

            if (strlen($message) != $length * 2) {
                throw new UnpackError('Message length is ' . strlen($message) / 2 . ' and should be ' . $length);
            }

			$this->shrink($message, (int)$this->options['lengthPrefix'] * 2);
		}

		// Parsing MTI
		$this->setMTI(substr($message, 0, 4));
		$this->shrink($message, 4);

		$bitmapHex = substr($message, 0,16);
		$bitmap = base_convert($bitmapHex, 16, 2);
		$bitmap = str_pad($bitmap, 64, '0', STR_PAD_LEFT);

		echo "bitmap hex: $bitmapHex " . PHP_EOL;
        echo "bitmap bin: $bitmap " . PHP_EOL;

        $this->shrink($message, 16);

		$this->bitmap = $bitmap;

		// Parsing fields
		for($i=0; $i < strlen($bitmap); $i++) {
			if ($bitmap[$i] === "1") {
				$fieldNumber = $i + 1;

//				echo "FIELD: " . $fieldNumber . PHP_EOL;

                if ($fieldNumber === 1 || $fieldNumber === 65) {
                    continue;
                }

                $fieldData = $this->protocol->getFieldData($fieldNumber);
                $fieldMapper = $fieldData['type'];

                if (!isset($this->mappers[$fieldMapper])) {
                    throw new \Exception('Unknown field mapper for "' . $fieldMapper . '" type');
                }

//                echo $message . PHP_EOL;

                $mapper = new $this->mappers[$fieldMapper]($fieldData['length']);

                $ascii = false;
                if ($fieldNumber === 44) {
                    $ascii = true;
                }
				$unpacked = $mapper->unpack($message, $fieldData['type'], $ascii);

                echo "We have field " . $fieldNumber . ': "' . $unpacked . '" ' . json_encode($fieldData) . PHP_EOL;


//				echo '$message->setField(' . $fieldNumber . ',"' . $unpacked . '");' . PHP_EOL;
				//echo $unpacked . PHP_EOL . PHP_EOL;

				$this->setField($fieldNumber, $unpacked);
			}
		}
		echo "remaining message: " . $message . PHP_EOL;
        echo "remaining message: " . $this->ebcdicToAscii($message) . PHP_EOL;

    }

	public function getMTI()
	{
		return $this->mti;
	}

	public function setMTI($mti)
	{
		if (!preg_match('/^[0-9]{4}$/', $mti)) {
			throw new Error\UnpackError('Bad MTI field it should be 4 digits string');
		}

		$this->mti = $mti;
	}

	public function set(array $fields)
	{
		$this->fields = $fields;
	}

	public function getFieldsIds()
	{
		$keys = array_keys($this->fields);
		sort($keys);

		return $keys;
	}

	public function getFields()
	{
		ksort($this->fields);

		return $this->fields;
	}

	public function setField($field, $value)
	{
		$this->fields[(int)$field] = $value;
	}

	public function getField($field)
	{
		return isset($this->fields[$field]) ? $this->fields[$field] : null;
	}

	public function getBitmap()
	{
		return $this->bitmap;
	}

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
                $encoded .= '.';
            }
        }

        return $encoded;
    }

}

