<?php


namespace Views;


use Models\Data;
use Models\Tool;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

abstract class Base
{
	protected string $templatesPath;
	protected ?string $templatesPathSubDir = null; 
	protected ?string $templateName = null;
	protected Environment $twig;
	
	public function __construct()
	{
		$templatesPathSubDir = ($this->templatesPathSubDir ? DIRECTORY_SEPARATOR . $this->templatesPathSubDir : '');
		$this->templatesPath = Tool::getBasePath() . DIRECTORY_SEPARATOR . 'Templates';
		$this->twig = new Environment(new FilesystemLoader($this->templatesPath . $templatesPathSubDir), [
			'cache' => $this->templatesPath . DIRECTORY_SEPARATOR . 'Cache'
		]);
	}
	
	public function render(Data $data)
	{
		try {
			echo $this->twig->render($this->templateName ?? basename(get_called_class()) . '.twig', $data->getArrayEquivalent());
		} catch (LoaderError | SyntaxError | RuntimeError $e) {
			trigger_error($e->getMessage(), E_USER_WARNING);
		}
	}
}