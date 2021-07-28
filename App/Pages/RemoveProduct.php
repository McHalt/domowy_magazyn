<?php


namespace App\Pages;


use Models\Objects\Product;

class RemoveProduct extends Base
{
	public function __construct()
	{
		if (empty($_GET['ean'])) {
			$this->setCustomView('InsertEan');
		}
		parent::__construct();
	}
	
	public function prepareData()
	{
		if (empty($_GET['ean'])) {
			return;
		}
		$product = new Product(['loadVia' => 'ean', 'ean' => $_GET['ean']]);
		if (empty($product->id)) {
			$this->data->errors[] = "Nie ma takiego produktu";
			return;
		}
		if (!count($product->activeProducts)) {
			$this->data->errors[] = "Nie ma takiego produktu na stanie";
			return;
		}
		if (empty($_GET['date'])) {
			$this->loadPossibleExpirationsDates($product);
			return;
		}
		$product->setAsUnactive($_GET['date']);
		header('Location: /');
		exit;
	}
	
	public function loadPossibleExpirationsDates(Product $product)
	{
		$possibleExpirationDates = [];
		foreach ($product->activeProducts as $p) {
			$possibleExpirationDates[] = $p['expirationDate'];
		}
		$possibleExpirationDates = array_unique($possibleExpirationDates);
		if (count($possibleExpirationDates) == 1) {
			$product->setAsUnactive($possibleExpirationDates[0]);
			header('Location: /');
			exit;
		}
		$this->data->expirationDates = $possibleExpirationDates;
		$this->data->product = $product;
	}
}