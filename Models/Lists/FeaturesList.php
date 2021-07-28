<?php


namespace Models\Lists;


use Models\Db;
use Models\Objects\Feature;

class FeaturesList extends Base
{
	public string $elementsClass = "Feature";
	public string $table = "features";
	
	public function toArray(): array
	{
		$arr = [];
		foreach ($this->objects as $item) {
			$arr[$item->name] = $item->value;
		}
		return $arr;
	}
	
	public function fromArray(array $features)
	{
		$keys = array_map('htmlspecialchars', array_keys($features));
		$qry = "
			SELECT *
			FROM features
			WHERE name IN ('" . implode("','", $keys) . "')
		";
		foreach (Db::query($qry) as $item) {
			$f = new Feature(['id' => $item['id']]);
			$f->value = $features[$item['name']];
			$this->objects[] = $f;
		}
	}
}