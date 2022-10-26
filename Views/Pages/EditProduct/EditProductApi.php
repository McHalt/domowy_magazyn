<?php

namespace Views\Pages\EditProduct;

use Models\Data;

class EditProductApi extends \Views\Pages\ApiBase
{
    public function render(Data $data)
    {
        echo json_encode([
			"status" => "EanNotExists",
			"allPossibleFeatures" => $data->product->allPossibleFeatures
		]);
    }
}