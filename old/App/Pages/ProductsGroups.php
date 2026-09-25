<?php

namespace App\Pages;

use Models\Lists\ProductsGroupsList;

class ProductsGroups extends Base
{
	
	public function prepareData()
	{
		$this->data->groups = (new ProductsGroupsList())->objects;
	}
}