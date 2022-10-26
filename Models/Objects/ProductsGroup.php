<?php

namespace Models\Objects;

use Models\Lists\ProductsList;

class ProductsGroup extends Base
{
	public string $table = 'products_groups';
	public int $id;
	public string $name;
	public array $properties2Save = ['name'];
	public ProductsList $products;
	
	public function loadProductsList()
	{
		$this->products = new ProductsList([
			'additionalSql' => 'p 
				INNER JOIN products_to_products_groups ptpg
					ON p.id = ptpg.product_id
					AND ptpg.products_group_id = ' . $this->id . '
			'
		]);
	}
}