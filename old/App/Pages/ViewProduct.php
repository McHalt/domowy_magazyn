<?php


namespace App\Pages;


use Models\Objects\Product;

class ViewProduct extends Base
{
	public Product $product;
	public function __construct()
	{
		if (!empty($_GET['id'])) {
			$this->product = new Product(["id" => $_GET['id'] ?? null]);
		} elseif (!empty($_GET['ean'])) {
			$this->product = new Product(['loadVia' => 'ean', 'ean' => $_GET['ean']]);
		} else {
			return;
		}
		
		parent::__construct();
	}
	
	public function prepareData()
	{
		$this->data->product = clone $this->product;
		unset($this->data->product->loadVia);
		unset($this->data->product->table);
	}
}