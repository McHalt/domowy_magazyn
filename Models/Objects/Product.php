<?php


namespace Models\Objects;


use Models\Db;
use Models\Lists\FeaturesList;

class Product extends Base
{
	public string $table = 'products';
	public int $id;
	public int $qty;
	public float $lowestCost;
	public float $lastCost;
	public array $activeProducts = [];
	public array $features;
	public function __construct(array $inputs = [])
	{
		parent::__construct($inputs);
		if ($this->stop == true) {
			return;
		}
		$qry = "
			SELECT
				MIN(cost)/100 AS lowestCost,
			  	COUNT(*) AS qty
			FROM products_history
			WHERE
				products_id = $this->id
				AND active = 1
		";
		$item = Db::query($qry)[0];
		$this->qty = $item['qty'];
		$this->lowestCost = $item['lowestCost'];
		$qry = "
			SELECT 
				cost/100 AS lastCost
			FROM products_history
			WHERE
				products_id = $this->id
				AND active = 1
			ORDER BY id DESC
		";
		$item = Db::query($qry)[0];
		$this->lastCost = $item['lastCost'];
		foreach (Db::query("SELECT * FROM products_history WHERE products_id = $this->id AND active = 1") as $item) {
			$this->activeProducts[] = [
				'historyId' => $item['id'],
				'cost' => $item['cost']/100,
				'dateAdded' => $item['date_added'],
				'expirationDate' => $item['expiration_date']
			];
		}
		$qry = "INNER JOIN products_to_features ON products_id = $this->id AND features_id = id";
		foreach ((new FeaturesList(['additionalSql' => $qry]))->objects as $item) {
			$this->features[$item->name] = $item->value;
		}
	}
}