<?php

namespace Views\Pages\ViewGroup;

use Models\Data;

class ViewGroupApi extends \Views\Pages\ApiBase
{
	public function render(Data $data)
	{
		$products = [];
		$activeQty = 0;
		foreach ($data->products as $product) {
			$products[] = [
				'id' => $product->id,
				'ean' => $product->ean,
				'name' => $product->features['name'],
				'producer' => $product->features['producer'],
				'qty' => $product->qty
			];
			$activeQty += $product->qty;
		}
		echo json_encode([
			'id' => $data->group->id,
			'name' => $data->group->name,
			'products' => $products,
			'activeQty' => $activeQty
		]);
	}
}