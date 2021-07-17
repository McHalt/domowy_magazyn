<?php


namespace Models;


class Data extends Base
{
	public function __get($name): string
	{
		return '';
	}
	
	public function getArrayEquivalent(): array
	{
		return [];
	}
}