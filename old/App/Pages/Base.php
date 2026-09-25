<?php


namespace App\Pages;


use Models\Data;

abstract class Base extends \App\Base
{
	public Data $data;
	public static bool $preventView = false;
	
	public function __construct()
	{
		$this->data = new Data();
		$this->prepareData();
		if (!static::$preventView) {
			$this->render($this->data);
		}
	}
	
	public abstract function prepareData();
}