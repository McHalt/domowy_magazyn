<?php


namespace App;
use Models\Data;
use Models\Tool;


abstract class Base
{
	private string $customView;
	
	private function isViewExists($view)
	{
		$path = 'Views' . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . basename(get_called_class()) . DIRECTORY_SEPARATOR . $view . '.php';
		if (!file_exists(Tool::getBasePath() . DIRECTORY_SEPARATOR . $path)) {
			return false;
		} 
		if (!class_exists('\\Views\\Pages\\' . basename(get_called_class()) . '\\' . $view)) {
			return false;
		}
		return true;
	}
	
	public function setCustomView(string $view)
	{
		if (!$this->isViewExists($view)) {
			trigger_error("View $view NOT exists for class " . basename(get_called_class()) . "!", E_USER_WARNING);
			return;
		}
		$this->customView = $view;
	}	
	
	public function render(Data $data)
	{
		$view = $this->customView ?? basename(get_called_class());
		if (!$this->isViewExists($view)) {
			trigger_error("View $view NOT exists for class " . basename(get_called_class()) . "!", E_USER_WARNING);
			return;
		}
		$viewClassName = '\\Views\\Pages\\' . basename(get_called_class()) . '\\' . $view;
		/**
		 * @var \Views\Base $viewObj
		 */
		$viewObj = new $viewClassName;
		$viewObj->render($data);
	}
}