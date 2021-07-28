<?php


namespace App\Pages;


use Models\Lists\ProductsList;

class Main extends Base
{
	
	public function prepareData()
	{
		$time1Day = strtotime('+1 day');
		$time3Days = strtotime('+3 days');
		$time7Days = strtotime('+7 days');
		foreach ((new ProductsList())->objects as $product) {
			foreach ($product->activeProducts as $singleProduct) {
				$time = strtotime($singleProduct['expirationDate']);
				if (time() > $time) {
					$this->data->errors[] = "Produkt " . $product->features['name'] . " skończył swoją ważność! (" . $singleProduct['expirationDate'] . ")";
				} else if ($time1Day > $time) {
					$this->data->warnings[] = "Produkt " . $product->features['name'] . " kończy ważność za mniej niż 1 dzień! (" . $singleProduct['expirationDate'] . ")";
				} else if ($time3Days > $time) {
					$this->data->warnings[] = "Produkt " . $product->features['name'] . " kończy ważność za mniej niż 3 dni! (" . $singleProduct['expirationDate'] . ")";
				} else if ($time7Days > $time) {
					$this->data->warnings[] = "Produkt " . $product->features['name'] . " kończy ważność za mniej niż 7 dni	! (" . $singleProduct['expirationDate'] . ")";
				}
			}
		}
	}
}