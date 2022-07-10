<?php


namespace Views\Pages;


use Models\Data;
use Models\Tool;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

abstract class Base extends \Views\Base
{
	public string $title;
	protected ?string $templatesPathSubDir = 'Pages';
	
	public function __construct()
	{
		parent::__construct();
		$this->templatesPath .= DIRECTORY_SEPARATOR . 'Pages';
	}
	
	public function render(Data $data)
	{
		if (empty($this->templateName)) {
			if (Tool::getBasename(get_called_class()) == Tool::getBasename(dirname(str_replace('\\', '/', get_called_class())))) {
				$this->templateName = Tool::getBasename(get_called_class()); 
			} else {
				$this->templateName = Tool::getBasename(dirname(str_replace('\\', '/', get_called_class()))) . '_' . Tool::getBasename(get_called_class());
			}
		}
		try {
			echo
				$this->twig->render('head.twig', ['title' => $this->title]) .
				$this->twig->render($this->templateName . '.twig', $data->getArrayEquivalent()) .
				$this->twig->render('footer.twig');
		} catch (LoaderError | SyntaxError | RuntimeError $e) {
			trigger_error($e->getMessage(), E_USER_WARNING);
		}
	}
}