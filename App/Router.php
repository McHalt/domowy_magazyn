<?php


namespace App;


use App\Pages\Main;
use App\Pages\ViewProduct;
use Models\Objects\Product;

if (\Models\Tool::isEnvironmentDev()) {
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
}

class Router extends Base
{
	public function getCurrentPageName(): string
	{
		$page = ($_GET['p'] ?? '') ?: 'Main';
		$pieces = preg_split('/(?=[A-Z])/',$page);
		$page = implode('', array_map('ucfirst', array_map('strtolower', $pieces)));
		if (is_numeric($page)) {
			$page = "Error$page";
		}
		if (class_exists("\\" . __NAMESPACE__ . "\\Pages\\$page")) {
			return $page;
		} else {
			return "Error404";
		}
	}
	
	public function getCurrentPageClass(): string
	{
		return "\\" . __NAMESPACE__ . "\\Pages\\" . $this->getCurrentPageName();
	}
	
	public function __construct()
	{
		$pageClass = $this->getCurrentPageClass();
		new $pageClass;
	}
}