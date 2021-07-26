<?php


namespace App\Pages;


use Models\Lists\ProductsList;

class Products extends Base
{
	public ProductsList $list;
	
	public function prepareData()
	{
		$this->list = new ProductsList();
		$this->data->products = $this->list->objects;
	}
}