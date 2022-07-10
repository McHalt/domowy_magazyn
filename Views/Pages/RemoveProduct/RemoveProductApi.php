<?php

namespace Views\Pages\RemoveProduct;

use Models\Data;

class RemoveProductApi extends \Views\Pages\ApiBase
{
	public function render(Data $data)
	{
		if (!empty($data->expirationDates) && count($data->expirationDates) != 1) {
			echo json_encode(["status" => "specifyDate", "expirationDates" => $data->expirationDates]);
		} else {
			echo json_encode(["status" => "OK"]);
		}
	}
}