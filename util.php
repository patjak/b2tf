<?php

class Util {
	public static function get_line($prompt) {
		echo $prompt;
		return stream_get_line(STDIN, 1024, PHP_EOL);
	}

	public static function ask_from_array($array, $str, $print = FALSE) {
		if ($print === TRUE) {
			$i = 1;
			foreach ($array as $item) {
				msg($i++.")\t".$item);
			}
		}

		$entry = "";
		while ($entry == "") {
			$no = (int)Util::get_line($str." (1-".count($array)."): ");
			if (isset($array[$no - 1]))
				$entry = $array[$no - 1];
		}

		return $entry;
	}

	public static function ask($str, $options, $default) {
		$entry = "";
		while ($entry == "") {
			$val = Util::get_line($str);
			$val = strtolower($val);
			if (in_array($val, $options))
				$entry = $val;
			if ($val == "")
				return $default;
		}

		return $entry;
	}

	public static function pause() {
		self::get_line("--- press enter to continue ---");
	}
};

?>
