<?php


namespace App\Pages;


use Models\Data;

abstract class Base extends \App\Base
{
	public Data $data;
	
	public function __construct()
	{
		$this->data = new Data();
		$this->prepareData();
		$this->render($this->data);
	}
	
	public abstract function prepareData();
}