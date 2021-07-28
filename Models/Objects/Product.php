<?php


namespace Models\Objects;


use Models\Db;
use Models\Lists\FeaturesList;

class Product extends Base
{
	public string $table = 'products';
	public int $id;
	public int $qty;
	public int $ean;
	public float $lowestCost;
	public float $lastCost;
	public array $activeProducts = [];
	public FeaturesList $featuresList;
	public array $features;
	public array $properties2Save = ['id', 'ean'];
	public array $allPossibleFeatures = [];
	
	public function __construct(array $inputs = [])
	{
		foreach (Db::query("SELECT * FROM features") as $item) {
			$this->allPossibleFeatures[$item['id']] = $item['name_pl'];
		}
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
		$this->featuresList = new FeaturesList(['additionalSql' => $qry]);
		$this->features = $this->featuresList->toArray();
	}
	
	public function save(array $additionalData = [])
	{
		$additionalData['cost'] = str_replace(",", ".", $additionalData['cost']);
		if (
			empty($additionalData['cost']) 
			|| empty($additionalData['expiration_date']) 
			|| !is_numeric($additionalData['cost'])
			|| !is_numeric($additionalData['qty'])
		) {
			return;
		}
		$additionalData['cost'] = (int)(((float)$additionalData['cost'])*100);
		parent::save();
		foreach(range(1, $additionalData['qty']) as $i) {
			$qry = "
				INSERT INTO products_history
				(`products_id`, `cost`, `active`, `date_added`, `expiration_date`)
				VALUES
				('$this->id', '" . $additionalData['cost'] . "', 1, DATE(NOW()), '" . $additionalData['expiration_date'] . "')
			";
			Db::exec($qry);
		}
		if (!empty($additionalData['features'])) {
			$values = [];
			foreach ($additionalData['features'] as $id => $value) {
				$values[] = "('$this->id', '$id', '$value')";
			}
			$qry = "
				INSERT INTO products_to_features
				(`products_id`, `features_id`, `value`)
				VALUES
				" . implode(",", $values) . "
			";
			Db::exec($qry);
		}
	}
}