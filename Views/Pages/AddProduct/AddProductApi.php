<?php

namespace Views\Pages\AddProduct;

use Models\Data;

class AddProductApi extends \Views\Pages\ApiBase
{
    public function render(Data $data)
    {
        echo json_encode([
			"status" => "EanNotExists",
			"allPossibleFeatures" => $data->product->allPossibleFeatures
		]);
    }
}