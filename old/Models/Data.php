<?php


namespace Models;


class Data extends Base
{
	public array $warnings = [];
	public array $errors = [];
	public function __get($name): string
	{
		return '';
	}
	
	public function getArrayEquivalent(): array
	{
		return json_decode(json_encode(get_object_vars($this)), true);
	}
	
	public function __construct(array $inputs = [])
	{
		
	}
}