<?php


namespace App;


use App\Pages\Main;
use App\Pages\ViewProduct;
use Models\Objects\Product;

class Router extends Base
{
	public function getCurrentPageName(): string
	{
		$page = ($_GET['p'] ?? '') ?: 'Main';
		$page = ucfirst(strtolower($page));
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