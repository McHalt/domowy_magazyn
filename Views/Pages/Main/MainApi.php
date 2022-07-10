<?php

namespace Views\Pages\Main;

use Models\Data;

class MainApi extends \Views\Pages\ApiBase
{
	public function render(Data $data)
	{
		echo json_encode([
			"outdated" => $data->errors ?: "none",
			"shortExpirationDates" => $data->warnings ?: "none"
		]);
	}
}