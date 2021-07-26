<?php


namespace Models\Objects;


class Feature extends Base
{
	public string $table = "features";
	public int $id;
	public string $name;
	public string $value;
}