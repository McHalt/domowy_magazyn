<?php


namespace Models\Objects;


use Models\Db;

abstract class Base extends \Models\Base
{
	public string $table;
	public string $loadVia = "id";
	public bool $stop = false;
	
	public function __construct(array $inputs = [])
	{
		if (empty($inputs[$this->loadVia])) {
			$this->stop = true;
			return;
		}
		$result = Db::query("SELECT * FROM $this->table WHERE $this->loadVia = '" . $inputs[$this->loadVia] . "'");
		if (count($result) > 1) {
			trigger_error(
				"I don't know which object (" . basename(get_called_class()) . ") to load,
				 because it is more than 1 where  $this->loadVia = '" . $inputs[$this->loadVia] . "'"
				, E_USER_WARNING
			);
			return;
		} else if (count($result) < 1) {
			$this->stop = true;
			return;
		}
		foreach ($result[0] as $key => $value) {
			$this->$key = $value;
		}
	}
}