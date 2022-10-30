<?php


namespace App\Pages;


use Models\Db;
use Models\Lists\ProductsGroupsList;
use Models\Objects\Product;

class EditProduct extends Base
{
	public function __construct()
	{
		if (!empty($_GET['id'])) {
			$this->setCustomView('EditExistingProduct');
		} elseif (empty($_GET['ean'])) {
			$this->setCustomView('InsertEan');
		}
		parent::__construct();
	}
	
	public function prepareData()
	{
		$postGroups = $_GET['groups'] ?? [];
		unset($_GET['groups']);
		$vars = array_map('htmlspecialchars', array_merge($_GET, $_POST)); //@todo docelowo do wyjebania $_GET, kto to widział wtf
		if (empty($vars['ean']) && empty($vars['id'])) {
			return;
		}
		if (!empty($vars['id'])) {
			$product = new Product(['id' => $vars['id']]);
		} else {
			$product = new Product(['loadVia' => 'ean', 'ean' => $vars['ean']]);
		}
		$groups = [];
		foreach ((new ProductsGroupsList())->objects as $object) {
			$groups[$object->id] = [
				'id' => $object->id,
				'name' => $object->name
			];
		}
		$this->data->groupsJson = json_encode($groups);
		if (empty($vars['qty']) && empty($vars['forceSave'])) {
			if (!empty($product->id) && !empty($vars['ean'])) {
				$this->data->product = $product;
				$this->setCustomView('EanExists');
				return;
			} else if (empty($product->id) || !empty($vars['id'])) {
				$this->data->product = $product;
				return;
			}
		}
		$features = [];
		foreach ($vars as $key => $value) {
			if (empty($value)) {
				continue;
			}
			if (strpos($key, 'feature_') !== false) {
				$features[str_replace('feature_', '', $key)] = $value;
			}
		}
		foreach (Db::query("
			SELECT id, name
			FROM features
			WHERE name IN('" . implode("','", array_keys($features)) . "')
		") as $row) {
			$features[$row['id']] = $features[$row['name']];
			unset($features[$row['name']]);
		}
		$product->save([
			'qty' => $vars['qty'] ?? '', 
			'expiration_date' => $vars['expiration_date'] ?? '', 
			'cost' => $vars['cost'] ?? '',
			'features' => $features
		]);
		if (!empty($postGroups)) {
			$qry = "
				DELETE FROM products_to_products_groups
				WHERE product_id = " . $product->id;
			Db::exec($qry);
			$groups = [];
			foreach ($postGroups as $group) {
				$groups[] = "(" . $product->id . ", " . $group . ")";
			}
			$qry = "
				INSERT INTO products_to_products_groups
				(product_id, products_group_id)
				VALUES
				" . implode(",", $groups);
			Db::exec($qry);
		}
		header('Location: /');
		exit;
	}
}