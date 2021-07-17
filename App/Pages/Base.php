<?php


namespace App\Pages;


use Models\Data;

abstract class Base extends \App\Base
{
	public function __construct()
	{
		$this->render(new Data());
	}
}