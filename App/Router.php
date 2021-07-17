<?php


namespace App;


use App\Pages\Main;

class Router extends Base
{
	public function __construct()
	{
		new Main();
	}
}