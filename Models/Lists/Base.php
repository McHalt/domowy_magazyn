<?php


namespace Models\Lists;


use Models\Db;

abstract class Base extends \Models\Base
{
	public string $elementsClass;
	public string $table;
	public array $objects = [];
	public string $columnToLoadObj = "id";
	public string $additionalSql = '';
	
	public function __construct(array $inputs = [])
	{
		if (!empty($inputs['additionalSql'])) {
			$this->additionalSql = $inputs['additionalSql'];
		}
		$elementsClass = "\\Models\\Objects\\" . $this->elementsClass;
		foreach (Db::query("SELECT * FROM $this->table $this->additionalSql") as $item) {
			if (empty($item[$this->columnToLoadObj])) {
				continue;
			}
			$element = new $elementsClass([$this->columnToLoadObj => $item[$this->columnToLoadObj]]);
			foreach ($item as $key => $val) {
				if (property_exists($element, $key)) {
					$element->$key = $val; 
				}
			}
			$this->objects[] = $element;
		}
	}
}