<?php


namespace Models;


class Tool extends Base
{
	private static string $basePath;
	
	public static function getBasePath(): string
	{
		if (!empty(static::$basePath)) {
			return static::$basePath;
		}
		
		$path = realpath(__DIR__);
		if (
			strpos($path, 'Models') !== false //todo change to str_contains
			&& file_exists(str_replace('Models', 'App', $path))
			&& file_exists(str_replace('Models', 'Views', $path))
		) {
			return static::$basePath = realpath(str_replace('Models', '', $path));
		}
		
		trigger_error('Cannot get base path!?', E_USER_ERROR);
	}
	
	public static function isEnvironmentDev(): bool 
	{
		if (strpos($_SERVER['SERVER_NAME'], '.local')) {
			return true;
		}
		return false;
	}
	
	public function __construct(array $inputs = [])
	{
		
	}
}