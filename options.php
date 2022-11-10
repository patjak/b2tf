<?php

class Options {
	static	$options = array();

	// Since the argument parsing in PHP is garbage we must write our own
	// We save everything that doesn't get parsed and return it (commands, etc)

	public static function parse($argv, $opts) {
		$num = count($argv);
		for ($i = 1; $i < $num; $i++) {
			$arg = $argv[$i];

			if (strpos($arg, "--") != 0)
				continue;

			foreach ($opts as $opt) {
				if ($opt[-1] == ":") {
					// Parse options that have a parameter

					$opt = substr($opt,0, -1);
					$val = Util::parse_after("--".$opt, $arg);
					if ($val === FALSE)
						continue;

					if ($val == "")  {
						self::$options[$opt] = $argv[$i + 1];
						unset($argv[$i]);
						unset($argv[$i + 1]);
						$i++;
						break;
					}
					if ($val[0] == "=") {
						$val = substr($val, 1);
						self::$options[$opt] = $val;
						unset($argv[$i]);
						break;
					}
				} else {
					// Parse options without parameters

					if (strcmp($arg, "--".$opt))
						continue;

					self::$options[$opt] = TRUE;
					unset($argv[$i]);
				}
			}
		}

		// Re-index and return what is left
		return array_values($argv);
	}

	public static function get($opt) {
		if (isset(self::$options[$opt]))
			return self::$options[$opt];
		else
			return FALSE;
	}
};

?>
