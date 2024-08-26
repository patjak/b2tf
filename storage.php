<?php

class Storage {
	public static $storage;
	public static $path;

	public static function load() {
		if (!file_exists(self::$path))
			return false;

		self::$storage = simplexml_load_file(self::$path);

		if (self::$storage === FALSE)
			fatal("Failed to load XML from ".self::$path);

		return self::$storage;
	}

	// To get nice indentation we convert to DOM before saving
	private static function make_xml_great_again($xml) {
		$dom = new DOMDocument("1.0");
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;

		$dom->loadXML($xml);

		return($dom->saveXML());
	}

	public static function save() {
		$xml = Storage::make_xml_great_again(self::$storage->asXML());

		if (file_put_contents(self::$path, $xml) === false) {
			echo "Unable to save storage file: ".self::$path."\n";
			exit(1);
		}
	}

	public static function get_parameters() {
		$params = array();
		foreach (self::$storage->parameter as $param)
			$params[] = $param;

		return $params;
	}

	public static function get_path()
	{
		return Settings::$settings->path[0];
	}
}

?>
