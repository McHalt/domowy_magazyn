<?php


namespace App\Pages;


use Models\Objects\Product;

class AddProduct extends Base
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
		if (!empty($product->id) && empty($_GET['qty'])) {
			$this->data->product = $product;
			$this->setCustomView('EanExists');
			return;
		} else if (empty($product->id) && empty($_GET['qty'])) {
			$this->data->product = $product;
			return;
		}
		$_GET = array_map('htmlspecialchars', $_GET);
		$features = [];
		foreach ($_GET as $key => $value) {
			if (empty($value)) {
				continue;
			}
			if (strpos($key, 'feature_') !== false) {
				$features[str_replace('feature_', '', $key)] = $value;
			}
		}
		$product = new Product();
		$product->ean = $_GET['ean'];
		$product->save([
			'qty' => $_GET['qty'], 
			'expiration_date' => $_GET['expiration_date'], 
			'cost' => $_GET['cost'],
			'features' => $features
		]);
		header('Location: /');
		exit;
	}
}