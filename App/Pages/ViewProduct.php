<?php


namespace App\Pages;


use Models\Objects\Product;

class ViewProduct extends Base
{
	public Product $product;
	public function __construct()
	{
		$this->product = new Product(["id" => $_GET['id'] ?? null]);
		if (!isset($this->product->id)) {
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