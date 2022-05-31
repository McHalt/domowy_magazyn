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
		$vars = array_map('htmlspecialchars', array_merge($_GET, $_POST)); //@todo docelowo do wyjebania $_GET, kto to widział wtf
		if (empty($vars['ean'])) {
			return;
		}
		$product = new Product(['loadVia' => 'ean', 'ean' => $vars['ean']]);
		if (!empty($product->id) && empty($vars['qty'])) {
			$this->data->product = $product;
			$this->setCustomView('EanExists');
			return;
		} else if (empty($product->id) && empty($vars['qty'])) {
			$this->data->product = $product;
			return;
		}
		$features = [];
		foreach ($vars as $key => $value) {
			if (empty($value)) {
				continue;
			}
			if (strpos($key, 'feature_') !== false) {
				$features[str_replace('feature_', '', $key)] = $value;
			}
		}
		$product->save([
			'qty' => $vars['qty'], 
			'expiration_date' => $vars['expiration_date'], 
			'cost' => $vars['cost'],
			'features' => $features
		]);
		header('Location: /');
		exit;
	}
}