<?php


namespace Views\Pages;

use Models\Data;

class ApiBase extends Base
{
	public string $title = "Lista produktów";
	
	public function render(Data $data)
	{
		echo "Not implemented";
		trigger_error("Not implemented method", E_USER_WARNING);
	}
}