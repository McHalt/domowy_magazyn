<?php


namespace Views\Pages\Products;


use Models\Data;
use Models\Lists\FeaturesList;
use Models\Objects\Product;
use Views\Pages\ApiBase;

class ProductsApi extends ApiBase
{
	public string $title = "Lista produktów";
	
	public function render(Data $data)
	{
		$productsData = [];
		/**
		 * @var Product $product
		 */
		foreach ($data->products ?? [] as $product) {
			$productsData[] = [
				'id' => $product->id,
				'ean' => $product->ean,
				'qty' => $product->qty,
				'name' => $product->features['name'],
				'producer' => $product->features['producer']
			];
		}
		$dataArr = [
			'title' => $this->title,
			'products' => $productsData
		];
		echo json_encode($dataArr);
	}
}