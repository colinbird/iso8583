<?php
namespace ISO8583\Mapper;

abstract class AbstractMapper
{
	protected $length;
	protected $variableLength;

	public function __construct($length)
	{
		$this->variableLength = substr_count($length, '.');
		$this->length = filter_var($length, FILTER_SANITIZE_NUMBER_INT);
	}

	abstract public function pack($message, $format);
	abstract public function unpack(&$message, $format);

	public function getLength()
	{
		return $this->length;
	}

	public function getVariableLength()
	{
		return $this->variableLength;
	}
}
