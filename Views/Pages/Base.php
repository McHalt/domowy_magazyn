<?php


namespace Views\Pages;


use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class Base extends \Views\Base
{
	public function __construct()
	{
		parent::__construct();
		$this->templatesPath .= DIRECTORY_SEPARATOR . 'Pages';
	}
}