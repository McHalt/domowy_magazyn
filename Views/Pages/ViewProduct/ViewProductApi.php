<?php

namespace Views\Pages\ViewProduct;

use Models\Data;
use Models\Objects\Product;

class ViewProductApi extends \Views\Pages\ApiBase
{
	public string $title = "Podgląd produktu";
	
	public function render(Data $data)
	{
		/**
		 * @var Product $p
		 */
		$p = $data->product;
		$productData = [
			'id' => $p->id,
			'ean' => $p->ean,
			'qty' => $p->qty,
			'name' => $p->features['name'],
			'producer' => $p->features['producer'],
			'lastCost' => $p->lastCost,
			'lowestCost' => $p->lowestCost,
			'activeProducts' => $p->activeProducts
		];
		
		if (!empty($_GET['id'])) {
			$productData['allPossibleFeatures'] = $p->allPossibleFeatures;
			$productData['features'] = $p->features;
		}
		
		echo json_encode($productData);
	}
}