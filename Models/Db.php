<?php


namespace Models;


class Db extends Base
{
	private static \PDO $pdo;
	private static string $configPath;
	private static Config $config;
	
	private static function connect()
	{
		if (!empty(static::$pdo)) {
			trigger_error('Connection already exists');
			return;
		}
		$c = self::getDbConfig();
		if (
			empty($c->host)
			|| empty($c->name)
			|| empty($c->user)
			|| empty($c->pass)
		) {
			trigger_error('Empty at least one of required properties to connect to db?!', E_USER_ERROR);
		}
		
		try {
			static::$pdo = new \PDO("mysql:host=$c->host;dbname=$c->name", $c->user, $c->pass);
		} catch (PDOException $e) {
			trigger_error($e->getMessage(), E_USER_ERROR);
		}
	}
	
	private static function getDbConfig(): Config
	{
		if (!empty(static::$config)) {
			return static::$config;
		}
		if (!file_exists(static::getDbConfigPath())) {
			trigger_error('Db config file NOT exists!', E_USER_ERROR);
		}
		return static::$config = new Config(['filename' => basename(static::getDbConfigPath())]);
	}
	
	private static function getDbConfigPath(): string
	{
		if (!empty(static::$configPath)) {
			return static::$configPath;
		}
		return static::$configPath = Tool::getBasePath() . '/Configs/db.conf';
	}
	
	public static function query($query)
	{
		if (!isset(static::$pdo)) {
			static::connect();
		}
		$stmt = static::$pdo->query($query);
		if($stmt) {
			return $stmt->fetchAll(\PDO::FETCH_ASSOC);
		}
		return false;
	}
	
	public static function exec($query) 
	{
		if (!isset(static::$pdo)) {
			static::connect();
		}
		static::$pdo->exec($query);
	}
	
	public static function getLastInsertId(){
		return static::$pdo->lastInsertId();
	}
	
	public function __construct(array $inputs = [])
	{
		
	}
}