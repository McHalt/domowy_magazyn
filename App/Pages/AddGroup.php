<?php

namespace App\Pages;

use Models\Objects\ProductsGroup;

class AddGroup extends Base
{
	
	public function prepareData()
	{
		if (!empty($_POST['name'])) {
			$group = new ProductsGroup();
			$group->name = htmlspecialchars($_POST['name']);
			$group->save();
			header('Location: ?p=viewGroup&id=' . $group->id);
			exit;
		}
	}
}