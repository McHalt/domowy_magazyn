<?php

namespace Models\Lists;

class ProductsGroupsList extends Base
{
	public string $table = 'products_groups';
	public string $elementsClass = 'ProductsGroup';
	
	public function toArray(): array
	{
		$arr = [];
		foreach ($this->objects as $item) {
			$arr[$item->id] = $item->name;
		}
		return $arr;
	}
}