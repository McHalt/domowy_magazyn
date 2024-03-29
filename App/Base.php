<?php


namespace App;
use Models\Data;
use Models\Tool;


abstract class Base
{
	private string $customView;
	
	private function isViewExists($view)
	{
		$path = 'Views' . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . Tool::getBasename(get_called_class()) . DIRECTORY_SEPARATOR . $view . '.php';
		if (!file_exists(Tool::getBasePath() . DIRECTORY_SEPARATOR . $path)) {
			return false;
		} 
		if (!class_exists('\\Views\\Pages\\' . Tool::getBasename(get_called_class()) . '\\' . $view)) {
			return false;
		}
		return true;
	}
	
	public function setCustomView(string $view)
	{
		if (!$this->isViewExists($view)) {
			trigger_error("View $view NOT exists for class " . Tool::getBasename(get_called_class()) . "!", E_USER_WARNING);
			return;
		}
		$this->customView = $view;
	}	
	
	public function render(Data $data)
	{
		$view = $this->customView ?? Tool::getBasename(get_called_class());
		if (defined('API_REQ') && constant('API_REQ') == 1) {
			$view .= 'Api';
		}
		if (!$this->isViewExists($view)) {
			if (defined('API_REQ') && constant('API_REQ') == 1) {
				$viewClassName = '\\Views\\Pages\\ApiBase';
			} else {
				trigger_error("View $view NOT exists for class " . Tool::getBasename(get_called_class()) . "!", E_USER_WARNING);
				return;
			}
		}
		if (empty($viewClassName)) {
			$viewClassName = '\\Views\\Pages\\' . Tool::getBasename(get_called_class()) . '\\' . $view;
		}
		/**
		 * @var \Views\Base $viewObj
		 */
		$viewObj = new $viewClassName;
		$viewObj->render($data);
	}
}