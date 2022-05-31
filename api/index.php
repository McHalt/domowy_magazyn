<?php

if (file_exists('pwd.php')) {
	include_once "pwd.php";
	
	if (!defined('PWD')) {
		exit;
	}
	
	if (($_GET['pwd'] ?? '') != PWD) {
		exit;
	}
}



$page = $_GET['p'] ?? '';

if (!$page) {
	exit;
}

const API_REQ = 1;

require_once "../vendor/autoload.php";

new \App\Router();