<?php

namespace App\Pages;

use Models\Objects\ProductsGroup;

class ViewGroup extends Base
{
	
	public function prepareData()
	{
		if (empty($_GET['id'])) {
			return;
		}
		
		$this->data->group = new ProductsGroup(['id' => $_GET['id']]);
		if (empty($this->data->group->name)) {
			return;
		}
		
		$this->data->group->loadProductsList();
		$this->data->products = $this->data->group->products->objects;
	}
}