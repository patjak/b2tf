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

	/**
	 * Read an option provided by the user
	 *
	 * Options are first read from the command line.
	 * Secondly from the commits.log file
	 * Lastly from the users b2tf.xml config file
	 * Otherwise we return FALSE
	 *
	 * @param $opt	Name of the option to retrieve
	 * @param $required The option must be found or a fatal error occurs
	 */
	public static function get($opt, $required = TRUE) {

		// Check the command line
		if (isset(self::$options[$opt])) {
			$val = self::$options[$opt];
			debug("Option from command line: ".$opt." = ".$val);
			return $val;
		}

		// Try the commit log if it's loaded
		if (Globals::$log !== FALSE) {
			$val = Globals::$log->get_head_tag($opt);
			if ($val !== FALSE) {
				debug("Option from commit log: ".$opt." = ".$val);
				return $val;
			}
		}

		// As a last resort, look in the users config file
		$params = Storage::get_parameters();
		foreach ($params as $param) {
			if ($param->name == $opt) {
				$val = $param->value;
				debug("Option from ~/.b2tf.xml: ".$opt." = ".$val);
				return $val;
			}
		}

		// Handle defaults
		if ($opt == "work-dir" || $opt == "git-dir") {
			debug("Option from default value: ".$opt." = ".$val);
			return realpath("./");
		}

		if ($required)
			fatal("Couldn't get required option: ".$opt);

		return FALSE;
	}
};

?>
