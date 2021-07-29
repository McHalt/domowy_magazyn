<?php


namespace App\Pages;


use Models\Lists\ProductsList;

class Products extends Base
{
	public ProductsList $list;
	
	public function prepareData()
	{
		$this->list = new ProductsList();
		$p = [];
		foreach ($this->list->objects as $key => $obj) {
			$p[$key] = $obj->qty;
		}
		arsort($p);
		$this->data->products = [];
		foreach ($p as $key => $qty) {
			$this->data->products[] = $this->list->objects[$key];
		}
	}
}