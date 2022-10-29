<?php


namespace Models\Objects;


use Models\Db;
use Models\Lists\FeaturesList;
use Models\Lists\ProductsGroupsList;

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
	public ProductsGroupsList $productsGroupsList;
	public array $productsGroups = [];
	public array $properties2Save = ['id', 'ean'];
	public array $allPossibleFeatures = [];
	public array $allPossibleGroups = [];
	
	public function __construct(array $inputs = [])
	{
		foreach (Db::query("SELECT * FROM features") as $item) {
			$this->allPossibleFeatures[$item['name']] = $item['name_pl'];
		}
		foreach (Db::query("SELECT * FROM products_groups") as $item) {
			$this->allPossibleGroups[$item['id']] = $item['name'];
		}
		parent::__construct($inputs);
		if ($this->stop == true) {
			return;
		}
		$qry = "
			SELECT
			  	COUNT(*) AS qty
			FROM products_history
			WHERE
				products_id = $this->id
				AND active = 1
		";
		$item = Db::query($qry)[0] ?? null;
		if (($item['qty'] ?? null) !== null) {
			$this->qty = $item['qty'];
		}
		$qry = "
			SELECT
				MIN(cost)/100 AS lowestCost
			FROM products_history
			WHERE
				products_id = $this->id
		";
		$item = Db::query($qry)[0] ?? null;
		if (($item['lowestCost'] ?? null) !== null) {
			$this->lowestCost = $item['lowestCost'];
		}
		$qry = "
			SELECT 
				cost/100 AS lastCost
			FROM products_history
			WHERE
				products_id = $this->id
			ORDER BY id DESC
		";
		$item = Db::query($qry)[0] ?? null;
		if (($item['lastCost'] ?? null) !== null) {
			$this->lastCost = $item['lastCost'];
		}
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
		$qry = "pg
				INNER JOIN products_to_products_groups ptpg
    			ON 
    				pg.id = ptpg.products_group_id 
    				AND product_id = $this->id";
		$this->productsGroupsList = new ProductsGroupsList(['additionalSql' => $qry]);
		$this->productsGroups = $this->productsGroupsList->toArray();
	}
	
	public function save(array $additionalData = [])
	{
		$additionalData['cost'] = str_replace(",", ".", $additionalData['cost'] ?? '');
		if (
			(
				empty($additionalData['cost']) 
				|| !is_numeric($additionalData['cost'])
				|| !is_numeric($additionalData['qty'])
			)
			&& empty($_GET['forceSave'])
		) {
			return;
		}
		$additionalData['cost'] = (int)(((float)$additionalData['cost'])*100);
		parent::save();
		if (!empty($additionalData['qty'])) {
			$expiration_date = $additionalData['expiration_date'] ? ("'" . $additionalData['expiration_date'] . "'") : 'NULL';
			foreach (range(1, $additionalData['qty']) as $i) {
				$qry = "
					INSERT INTO products_history
					(`products_id`, `cost`, `active`, `date_added`, `expiration_date`)
					VALUES
					('$this->id', '" . $additionalData['cost'] . "', 1, DATE(NOW()), $expiration_date)
				";
				Db::exec($qry);
			}
		}
		if (!empty($additionalData['features'])) {
			Db::exec("
				DELETE FROM
			   	products_to_features
				WHERE products_id = " . $this->id);
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
	
	public function setAsUnactive($date, $limit = 1) {
		$expirationDate = $date ? "= '$date'" : "IS NULL";
		$ids = [];
		foreach (Db::query("
			SELECT id
			FROM products_history
			WHERE products_id = $this->id
		  	AND active = 1
			AND expiration_date $expirationDate
			LIMIT $limit
		") as $id) {
			$ids[] = array_pop($id);
		}
		Db::exec("
			UPDATE products_history
			SET active = 0
			WHERE id IN (" . implode(",", $ids) . ")
		");
	}
}