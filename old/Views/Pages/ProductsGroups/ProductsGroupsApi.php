<?php

namespace Views\Pages\ProductsGroups;

use Models\Data;

class ProductsGroupsApi extends \Views\Pages\ApiBase
{
	public function render(Data $data)
	{
		$groups = [];
		foreach ($data->groups as $object) {
			$groups[] = [
				'id' => $object->id,
				'name' => $object->name
			];
		}
		echo json_encode([
			'title' => 'Lista grup produktów',
			'groups' => $groups
		]);
	}
}