<?php

namespace Views\Pages\AddProduct;

use Models\Data;

class EanExistsApi extends \Views\Pages\ApiBase
{
    public function render(Data $data)
    {
		echo json_encode(["status" => "EanExists"]);
    }
}