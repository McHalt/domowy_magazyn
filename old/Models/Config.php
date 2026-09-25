<?php


namespace Models;


class Config extends Base
{
	private string $path;
	
	public function __construct(array $inputs = [])
	{
		if (empty($inputs['filename'])) {
			trigger_error("Cannot create Config because of empty \$inputs['filename'] index", E_USER_ERROR);
		}
		$this->path = Tool::getBasePath() . '/Configs/' . $inputs['filename'];
		$this->setProperties();
	}
	
	private function getFileContent(): string
	{
		if (!file_exists($this->path)) {
			trigger_error("Config file $this->path NOT exists?!", E_USER_ERROR);
		}
		
		return file_get_contents($this->path);
	}
	
	private function setProperties()
	{
		foreach (explode(PHP_EOL, $this->getFileContent()) as $line) {
			list($property, $value) = array_pad(array_map('trim', explode(":", $line)), 2, 0);
			if (empty($property) || empty($value)) {
				continue;
			}
			$this->$property = $value;
		}
	}
}