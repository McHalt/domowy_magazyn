<?php

require_once 'vendor/autoload.php';

$config = new \Models\Config(["filename" => "send_notifs.conf"]);
\App\Pages\Main::$preventView = true;
$main = new \App\Pages\Main();

$client = new \GuzzleHttp\Client();

$body = new stdClass();
$body->app_id = $config->OneSignalAppId;
$body->included_segments = ["All"];
$body->headings = new stdClass();
$body->headings->en = "Zbliża się koniec ważności produktów";
$body->contents = new stdClass();
$body->contents->en = implode("\n", $main->data->warnings);

$client->request('POST', 'https://onesignal.com/api/v1/notifications', [
	'body' => json_encode($body),
	'headers' => [
		'Accept' => 'application/json',
		'Authorization' => 'Basic ' . $config->RestApiKey,
		'Content-Type' => 'application/json',
	],
]);

$body->headings->en = "Produkty utraciły swoją ważność";
$body->contents->en = implode("\n", $main->data->errors);

$client->request('POST', 'https://onesignal.com/api/v1/notifications', [
	'body' => json_encode($body),
	'headers' => [
		'Accept' => 'application/json',
		'Authorization' => 'Basic ' . $config->RestApiKey,
		'Content-Type' => 'application/json',
	],
]);