<?php

function figure_out_release($lines)
{
	// Find all releases and count patches
	$releases = array();
	foreach ($lines as $line) {
		if (strpos($line, "Needed in ") === FALSE)
			continue;

		$rel = substr($line, strlen("Needed in ") + 1);

		// Remove "as fix for..."
		$pos = strpos($rel, " as fix for ");
		if ($pos !== FALSE)
			$rel = substr($rel, 0, $pos);

		isset($releases[$rel]) ? $releases[$rel]++ : $releases[$rel] = 1;
	}

	ksort($releases);
	$i = 1;
	foreach($releases as $r => $count) {
		msg($i++.")\t".$r." (".$count." patches)");
	}

	if (count($releases) == 0)
		fatal("No releases found. Did you select the wrong file-type?");

	// ask_from_array needs an number indexed array
	$rel_array = array();
	foreach ($releases as $key => $val)
		$rel_array[] = $key;

	$release = Util::ask_from_array($rel_array, "Select release:");

	return $release;
}

function parse_fixes_from_email($lines, $release, $git)
{
	$patches = array();
	for ($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];

		if ($line == "")
			continue;
		if ($line[0] == " " || $line[0] == "\t")
			continue;

		// Check if hash is valid
		$hash = explode(" ", $line)[0];
		$git->cmd("git log --oneline -n1 ".$hash, $res, $status);

		if ($status != 0)
			fatal("Unknown hash: ".$hash);

		while (TRUE) {
			$tag = "Needed in";
			$line = trim($lines[++$i]);
			if (strpos($line, "Needed in ") === false)
				break;

			$rel = substr($line, strlen($tag) + 1);

			// Remove "as fix for..."
			$pos = strpos($rel, " as fix for ");
			if ($pos !== FALSE)
				$rel = substr($rel, 0, $pos);

			if ($release == $rel)
				$patches[] = $hash;
		}
	}

	return $patches;
}

function parse_fixes_from_script($lines, $git)
{
	$patches = array();
	foreach ($lines as $line) {
		if ($line == "")
			continue;
		if (trim($line)[0] == "#")
			continue;

		if ($line[0] == "\t" || strpos($line, "        ") == 0) {
			$hash = explode(" ", trim($line))[0];

			// Check if hash is valid
			$res = NULL;
			$git->cmd("git log --oneline -n1 ".$hash, $res, $status);

			if ($status != 0)
				fatal("Unknown hash: ".$hash);
			else
				$patches[] = $hash;

		}
	}

	return $patches;
}

function parse_fixes_simple($lines, $git)
{
	$patches = array();

	foreach ($lines as $line) {
		$hashes = explode(" ", $line);
		foreach ($hashes as $hash) {
			if ($hash == "")
				continue;

			// Check if hash is valid and get the full sha
			$res = NULL;
			$git->cmd("git rev-parse ".$hash, $res, $status);

			if ($status != 0)
				debug("Unknown hash: ".$hash);
			else
				$patches[] = trim($res[0]);
		}
	}

	return $patches;
}

function parse_cvs_url($url, $git)
{
	$file = file_get_contents($url);
	if ($file === FALSE)
		fatal("URL could not be opened: ".$url);

	$hashes = array();

	$lines = explode(PHP_EOL, $file);
	foreach ($lines as $line) {
		$hash = explode(",", $line)[0];
		if (strlen($hash) != 40)
			continue;

		$res = NULL;
		$git->cmd("git log --oneline -n1 ".$hash, $res, $status);

		if ($status != 0)
			debug("Unknown hash: ".$hash);
		else
			$hashes[] = $hash;

	}

	return $hashes;
}

function load_fixes_file($fixes_file, $work_dir, $git)
{
	$file = file_get_contents($fixes_file);
	if ($file === FALSE)
		fatal("Failed to open fixes file: ".$fixes_file);
	$lines = explode(PHP_EOL, $file);

	$filetype = Options::get("file-type", FALSE);
	if ($filetype === FALSE) {
		$file_types = array("from-email", "from-script", "simple");
		$filetype = Util::ask_from_array($file_types, "Select file-type:", TRUE);
	}

	// Figure out which release we want to work on
	if ($filetype == "from-email") {
		if (isset($opts['release']))
		$release = Options::get("release");
		if ($release === FALSE)
			$release = figure_out_release($lines);

		$release = str_replace(" ", "-", $release);
		$release = str_replace(".", "-", $release);
		$patches = parse_fixes_from_email($lines, $release, $git);

	} else if ($filetype == "from-script") {
		$patches = parse_fixes_from_script($lines, $git);
	} else if ($filetype == "simple") {
		$patches = parse_fixes_simple($lines, $git);
	}

	return $patches;
}

// Should only be used from check_for_duplicate() or check_for_alt_commits()
function __strip_diff($diff)
{
	$out = "";
	foreach ($diff as $line) {
		// Strip index lines
		$str = "index ";
		if (strncmp($str, $line, strlen($str)) == 0)
			continue;

		// Strip line numbers
		if (strncmp("@@ ", $line, 3) == 0)
			$line = substr($line, strpos($line, " @@ ") + 4);

		$out .= $line.PHP_EOL;
	}

	return $out;
}

function check_for_matches($p, $cache, $git)
{
	$matches = array();
	foreach ($cache as $commit_id => $subject) {
		if ($subject == $p->subject && $commit_id != $p->commit_id) {
			debug($subject." == ".$p->subject);
			$matches[] = $commit_id;
		}
	}

	foreach ($matches as $commit_id) {
		$commit_id = explode(" ", $commit_id)[0];
		$dup = new Patch();
		$dup->parse_from_git($commit_id);

		if ($p->commit_id == $dup->commit_id)
			continue;

		unset($res);
		$git->cmd("git diff ".$p->commit_id."~1..".$p->commit_id, $res, $status);
		$diff1 = __strip_diff($res);

		unset($res);
		$git->cmd("git diff ".$dup->commit_id."~1..".$dup->commit_id, $res, $status);
		$diff2 = __strip_diff($res);

		if (strcmp($diff1, $diff2) == 0) {
			green("Found exact duplicate for ".$p->commit_id." in ".$dup->commit_id);
			return $dup;
		} else {
			msg($p->commit_id.": ".strlen($diff1));
			msg($dup->commit_id.": ".strlen($diff2));
			file_put_contents("/tmp/1", $diff1);
			file_put_contents("/tmp/2", $diff2);
			passthru("diff -Naur /tmp/1 /tmp/2");
			passthru("rm /tmp/1");
			passthru("rm /tmp/2");

			error("Found non-exact duplicate for ".$p->commit_id." in ".$dup->commit_id);

			$ask = Util::ask("(A)ccept duplicate or (s)kip: ", array("a", "s"), "a");

			if ($ask == "a")
				return $dup;
		}
	}

	return FALSE;
}

// Check suse repo for commits matching the contents of the provided patch
function check_for_alt_commits($p, $suse_repo_path, $git)
{
	debug("Checking for Alt-commits");

	$cache = array();

	unset($files);
	exec("find ".$suse_repo_path."/patches.suse/ | grep -v \"~\"", $files);

	$subject_str = "Subject: ";
	$git_commit_str = "Git-commit: ";
	$alt_commit_str = "Alt-commit: ";
	$no_fix_str = "No-fix: ";

	foreach ($files as $file) {
		if (is_dir($file))
			continue;

		$c = file_get_contents($file);
		$c = explode(PHP_EOL, $c);

		$ids = array();
		$subject = FALSE;

		// Collect subject for all Git-commit and Alt-commit tags
		for ($i = 0; $i < count($c); $i++) {
			$line = $c[$i];

			if (strncasecmp($subject_str, $line, strlen($subject_str)) == 0) {
				$subject = substr($line, strlen($subject_str));

				// Check for multi-line subjects
				if (isset($c[$i + 1][0]) && $c[$i + 1][0] == " ")
						$subject .= $c[$i + 1];

				// Strip any prepending [PATCH] tag
				$subject = trim($subject);
				if (strncmp($subject, "[PATCH]", strlen("[PATCH]")) == 0)
					$subject = substr($subject, strlen("[PATCH]"));

				$subject = trim($subject);
			}

			if (strncasecmp($git_commit_str, $line, strlen($git_commit_str)) == 0)
				$ids[] = substr($line, strlen($git_commit_str));

			if (strncasecmp($alt_commit_str, $line, strlen($alt_commit_str)) == 0)
				$ids[] = substr($line, strlen($alt_commit_str));

			if (strncasecmp($no_fix_str, $line, strlen($no_fix_str)) == 0)
				$ids[] = substr($line, strlen($no_fix_str));

			if (trim($line) == "")
				break;
		}

		foreach ($ids as $id)
			$cache[$id] = $subject;
	}

	return check_for_matches($p, $cache, $git);
}

// Check git repo for commits matching the contents of the provided patch
function check_for_duplicate($p, $git, $backports, $kernel_version)
{
	// To speed things up we create a commit_id to subject array
	static $cache = array();

	if (count($cache) == 0) {
		msg("Creating cache for duplicate checks...");

		unset($res);
		$git->cmd("git log --no-merges --pretty=\"%H %s\" v".$kernel_version."", $res);
		foreach ($res as $line) {
			$line = explode(" ", $line);

			// Fun fact: there's actually a commit from 2005 with no subject
			// 7b7abfe3dd81d659a0889f88965168f7eef8c5c6
			if (isset($line[1])) {
				$commit_id = $line[0];
				unset($line[0]);
				$subject = implode(" ", $line);
				$cache[$commit_id] = $subject;
			}
		}

		msg("Cached ".count($cache)." commits");
	}

	debug("Checking for duplicates...");

	return check_for_matches($p, $cache, $git);
}

function get_suse_patch_filename($suse_repo_path, $commit_id)
{
	exec("cd ".$suse_repo_path."/patches.suse && ".
	     "grep -Rl \"Git-commit: ".$commit_id."\" | grep -v \"~\"", $res);

	if (isset($res[0]))
		return $res[0];

	// We failed to find it so try the Alt-commit tag
	exec("cd ".$suse_repo_path."/patches.suse && ".
	     "grep -Rl \"Alt-commit: ".$commit_id."\" | grep -v \"~\"", $res);

	if (isset($res[0]))
		return $res[0];
	else
		return FALSE;
}

function suse_insert_file($file_src, $file_dst)
{
	debug("Copying file ".$file_src." to ".$file_dst);
	passthru("cp ".$file_src." ".$file_dst, $res);
	if ($res != 0)
		fatal("ERROR: failed to copy file to kernel-source repo");

	return $res;
}

function suse_insert_patch($suse_repo_path, $file_dst)
{
	debug("Inserting patch...");
	passthru("cd ".$suse_repo_path." && ./scripts/git_sort/series_insert.py patches.suse/".basename($file_dst), $res);
	if ($res != 0) {
		error("SKIP: Failed to insert patch into series");
		passthru("rm ".$suse_repo_path."/patches.suse/".basename($file_dst));
	}

	return $res;
}

function suse_sequence_patch($suse_repo_path, &$out)
{
	debug("Sequencing patch...");
	unset($output);
	exec("cd ".$suse_repo_path." && ./scripts/sequence-patch.sh --dir=/dev/shm/tmp --rapid 2>&1", $output, $res);

	$out = $output;

	if ($res != 0) {
		$hunk_ok = 0;
		$hunk_fail = 0;
		foreach($output as $line) {
			$line = explode(" ", trim($line));
			if ($line[0] == "Hunk" && $line[2] == "OK")
				$hunk_ok++;

			if ($line[0] == "Hunk" && $line[2] == "FAILED")
				$hunk_fail++;
		}

		if ($hunk_fail == 0)
			green("Hunks failed: none");
		else
			error("Hunks failed: ".$hunk_fail."/".($hunk_ok + $hunk_fail));
	}

	// exec("rm -Rf /dev/shm/tmp");

	return $res;
}

function undo_insert_and_sequence_patch($suse_repo_path, $filename)
{
	debug("Undoing insert and sequence patch");
	debug("Removing patches.suse/".$filename);
	passthru("rm ".$suse_repo_path."/patches.suse/".$filename);
	debug("Restoring series.conf");
	passthru("cd ".$suse_repo_path." && git restore series.conf");
}

// Let the user review the patch
function view_commit($commit_id, $git)
{
	passthru("cd ".$git->get_dir()." && tig show ".$commit_id);
}

// Find a filename for the patch that doesn't already exist in patches.suse/
function find_valid_filename($filename, $path)
{
	$filename_tmp = $filename;
	$i = 1;
	while (file_exists($path."/patches.suse/".$filename_tmp))
		$filename_tmp = sprintf("%04d", $i++)."-".$filename;

	if ($filename != $filename_tmp)
		debug("Patch filename got changed to ".$filename_tmp);

	$filename = $filename_tmp;

	debug("Found valid name: ".$filename);
	return $filename;
}

function suse_blacklist_patch($p, $suse_repo_path, $git, $reason = "")
{

	// Blacklist all patches with provided reason
	$blacklist_all = Options::get("blacklist-all", FALSE);

	$reasons = array("comment fixes", "documentation fixes", "not applicable", "Will be unblacklisted by DRM backport", "other");

	// If no reason was passed to this function, check if one was passed as an Option
	if ($reason == "") {
		if (isset(Options::$options['reason']))
			$reason = Options::get("reason");
		else
			$reason = "";
	}

	// If it's still empty, try asking the user for a reason
	if ($reason == "") {
		for ($i = 0; $i < count($reasons); $i++)
			msg(($i + 1).") ".$reasons[$i]);

		$reason = Util::ask_from_array($reasons, "Blacklist reason ");
		if ($reason == "other")
			$reason = Util::get_line("Reason: ");
	}

	if (isset(Options::$options['refs'])) {
		$refs = Options::get("refs");
		if ($refs != "git-fixes")
			$reason = $refs.": ".$reason;
	}

	// Sometimes the blacklist.conf file isn't ended with a new line and we must fix that
	$bl_file = file_get_contents($suse_repo_path."/blacklist.conf");
	if (substr($bl_file, -1) != PHP_EOL)
		$eol = PHP_EOL;
	else
		$eol = "";

	exec("echo \"".$eol.$p->commit_id." # ".$reason."\" >> ".$suse_repo_path."/blacklist.conf");
	msg("Blacklisting: ".$p->commit_id." # ".$reason);

	$git->cmd("git log --oneline -n1 ".$p->commit_id, $oneline, $res);
	if ($res != 0) {
		error("Failed to get oneline log of commit id: ".$p->commit_id);
		return;
	}

	$oneline = str_replace("\"", "\\\"", $oneline[0], $count);
	if ($count > 0) {
		msg("Check that the following subject is correct");
		info($oneline);
		Util::pause();
	}

	// If we're blacklisting all patches we commit them all at once so skip this step
	if ($blacklist_all)
		return 0;

	exec("cd ".$suse_repo_path." && git add blacklist.conf && git commit -m \"blacklist.conf: ".$oneline."\"", $out, $res);

	if ($res != 0)
		error("Failed to commit to blacklist.conf");

	return $res;
}

function get_kernel_version($suse_repo_path)
{
	$file = file_get_contents($suse_repo_path."/rpm/config.sh");

	$lines = explode(PHP_EOL, $file);
	$version = FALSE;

	foreach ($lines as $line) {
		$str = "SRCVERSION=";
		if (strncmp($str, $line, strlen($str)) == 0)
			$version = explode("=", $line)[1];
	}

	return $version;
}

/**
 * Process should look like this:
 * - Check if commit is already backported
 * - Check if commit is blacklisted
 * - Find a filename that doesn't collide in kernel-source/patches.suse
 * - Add fake tags to patch and quickly assess if it applies
 * - If it applies, add real tags and commit
 * - If it doesn't apply, check for Alt-commit from rapidquilt result
 * - - Ask to commit, Retry or Skip
 * - If it doesn't apply check for duplicate
 * - If duplicate found, ask for blacklist, retry or skip
 * - If patch just fails, ask for retry or skip
 **/

function cmd_suse_fixes($argv)
{
	$opts = Options::$options;
	$work_dir = Options::get("work-dir");
	$suse_repo_path = Options::get("suse-repo-path");

	// Special case where we want to fall back to "git-fixes" if
	// no ref is explicitly specified on the command line
	if (isset(Options::$options['refs']))
		$refs = Options::get("refs");
	else
		$refs = "git-fixes";

	$fixes_file = Options::get("fixes-file", FALSE);
	$fixes_url = Options::get("fixes-url", FALSE);
	$skip_review = Options::get("skip-review", FALSE);
	$branch = Options::get("branch", FALSE);

	// Blacklist all patches with provided reason
	$blacklist_all = Options::get("blacklist-all", FALSE);

	// Skip all patches that doesn't immediately applies
	$skip_fails = Options::get("skip-fails", FALSE);

	// Ignore everything that is not alt-commits
	$only_alt_commits = Options::get("only-alt-commits", FALSE);
	if ($only_alt_commits)
		msg("Only backporting Alt-commits");

	$repo_tag = Options::get("repo-tag", FALSE);
	$refs = Options::get("refs", FALSE);

	$signature = Options::get("signature");

	$git_dir = Options::get("git-dir");
	$git = new GitRepo();
	$git->set_dir($git_dir);

	// Check that user is on the right branch
	$out = "";
	exec("cd ".$suse_repo_path." && git branch --show-current", $out, $res);

	if ($res != 0)
		fatal("Couldn't get current branch from ".$suse_repo_path);

	if ($branch === FALSE) {
		$original_branch = $out[0];
		$line = Util::get_line("Enter name for new branch (leave empty to stay on ".$original_branch."): ");
		$line = trim(strtolower($line));
		if ($line == "") {
			$branch_name = $original_branch;
		} else {
			$branch_name = $original_branch."-".$line;

			exec("cd ".$suse_repo_path." && git checkout -b ".$branch_name, $out, $res);
			if ($res != 0)
				fatal("Failed to create and checkout branch: ".$branch_name);
		}
	} else {
		// If a branch is specified, we assume it has already been created by user
		$branch_name = $branch;
		exec("cd ".$suse_repo_path." && git checkout ".$branch_name, $out, $res);
		if ($res != 0)
			fatal("Failed to checkout branch: ".$branch_name);
	}

	$patches = array();
	$hash = Options::get("hash", FALSE);
	$hash = explode(" ", $hash);
	foreach ($hash as $h) {
		trim($h);
		if ($h != "")
			$patches[] = $h;
	}

	// Find the kernel version in the suse kernel-source repo
	$kernel_version = get_kernel_version($suse_repo_path);
	msg("SUSE repo kernel version: ".$kernel_version);
	if ($kernel_version === FALSE)
		fatal("Failed to get SUSE repo kernel version");

	if ($fixes_file !== FALSE) {
		$patches = load_fixes_file($fixes_file, $work_dir, $git);
	} else if ($fixes_url !== FALSE) {
		$patches = parse_cvs_url($fixes_url, $git);
	} else if (count($patches) == 0) {
		msg("No commits specified.");
		$hashes = Util::get_line("Enter hashes manually (separated by spaces): ");
		$hashes = explode(" ", $hashes);

		$patches = array();

		foreach ($hashes as $hash) {
			$res = NULL;
			$git->cmd("git log --oneline -n1 ".$hash, $res, $status);

			if ($status != 0)
				fatal("Unknown hash: ".$hash);
			else
				$patches[] = $hash;
		}

	}

	// Prepare the filesystem
	$patch_dir = $work_dir."/patches";
	exec("mkdir ".$patch_dir." 2> /dev/null");

	$suse_backports = get_suse_backports($suse_repo_path);
	$suse_blacklists = get_suse_blacklists($suse_repo_path);

	$actually_backported = 0;

	$i = 0;
	foreach ($patches as $hash) {
		$i++;
		$p = new Patch();
		$p->parse_from_git($hash);

		msg("\nBackporting (".$i."/".count($patches)."):");
		green($hash." ".$p->subject);

		if (in_array($p->commit_id, $suse_backports)) {
			error("SKIP: Patch is already backported");
			continue;
		}

		if (in_array($p->commit_id, $suse_blacklists)) {
			error("SKIP: Patch is blacklisted");
			continue;
		}

		if ($blacklist_all) {
			$res = suse_blacklist_patch($p, $suse_repo_path, $git);
			$actually_backported++;
			continue;
		}

		unset($res);
		$git->cmd("git format-patch --no-renames --keep-subject -o ".$patch_dir." ".$hash."~1..".$hash, $res, $output);

		if ($output != 0)
			fatal("git format-patch failed");

		// Remove the xxxx- prefix from patch names
		$filename = substr(basename($res[0]), 5);
		exec("mv ".$res[0]." ".$patch_dir."/".$filename);
		$file_src = $patch_dir."/".$filename;

		// Find a non-colliding filename
		$filename = find_valid_filename($filename, $suse_repo_path);
		$file_dst = $suse_repo_path."/patches.suse/".$filename;

		// Add proper tags to the patch
		debug("Inserting real tags...");
		if ($repo_tag !== FALSE) {
			$mainline = "Queued in subsystem maintainer repo";
		} else {
			$mainline = $p->get_mainline_tag($version_list);
			if ($mainline === FALSE) {
				error("SKIP: Failed to get mainline tag for commit: ".$hash);
				continue;
			}

			debug("Patch version (".$mainline." <= v".$kernel_version.")");
			if (in_array("v".$kernel_version, $version_list)) {
				error("SKIP: Patch is already in base kernel version (".$mainline." < ".$kernel_version.")");
				continue;
			}
		}

		$tags = array(	"Git-commit: ".$p->commit_id,
				"Patch-mainline: ".$mainline,
				"References: ".$refs);

		if ($repo_tag !== FALSE)
			$tags[] = "Git-repo: ".$repo_tag;

		suse_insert_file($file_src, $file_dst);
		insert_tags_in_patch($file_dst, $tags, $signature);

		$res = suse_insert_patch($suse_repo_path, $file_dst);
		$res += suse_sequence_patch($suse_repo_path, $out);

		// If we're working on a non-mainline repo ($repo_tag is set) we cannot do any clever tricks so skip this part
		if ($repo_tag !== FALSE) {
			if ($res == 0)
				msg("Patch will apply without modifications");
			else
				msg("The patch failed to apply");
		} else if ($res != 0) {
			$failed_patch = "";
			$hunk_ok = 0;
			$hunk_fail = 0;
			foreach ($out as $line) {
				$failed_line = explode(" ", trim($line));
				if (count($failed_line) == 3 && $failed_line[0] == "Patch" && $failed_line[2] == "FAILED") {
					$failed_patch = $failed_line[1];

					if ($failed_patch != "patches.suse/".$filename) {
						msg("A refresh will be required for: ".$failed_patch);
						break;
					}
				}
			}

			if ($failed_patch == "patches.suse/".$filename) {
				$dup = check_for_alt_commits($p, $suse_repo_path, $git);
				if ($dup !== FALSE) {
					undo_insert_and_sequence_patch($suse_repo_path, $filename);
					// We found an acceptable match
					$suse_patch_file = get_suse_patch_filename($suse_repo_path, $dup->commit_id);
					insert_tags_in_patch($suse_repo_path."/patches.suse/".$suse_patch_file, array("Alt-commit: ".$p->commit_id));

					$references = $refs === FALSE ? "" : "(".$refs.")";
					$msg = "Refresh patches.suse/".$suse_patch_file." ".$references."\n\nAlt-commit";
					file_put_contents("/tmp/commit-msg", $msg);

					passthru("cd ".$suse_repo_path." && ".
						 "git add series.conf && ".
						 "git add patches.suse/".$suse_patch_file." && ".
						 "git commit -F /tmp/commit-msg", $code);

					if ($code != 0)
						fatal("Failed to commit alt-commit");

					$actually_backported++;
					continue;
				}

				if ($only_alt_commits) {
					undo_insert_and_sequence_patch($suse_repo_path, $filename);
					msg("Not an Alt-commit (skipping)");
					continue;
				}

				$dup = check_for_duplicate($p, $git, $suse_backports, $kernel_version);

				if ($dup !== FALSE) {
					$ask = Util::ask("(B)lacklist duplicate, (s)kip or (a)bort: ", array("b", "s", "a"), "b");

					if ($ask == "a")
						break;

					if ($ask == "s")
						continue;

					if ($ask == "b") {
						undo_insert_and_sequence_patch($suse_repo_path, $filename);
						$res = suse_blacklist_patch($p, $suse_repo_path, $git, "Duplicate of ".$dup->commit_id.": ".$dup->subject);
						$actually_backported++;
						continue;
					}
				}
			}

			msg("The patch failed to apply");
			if ($skip_fails) {
				error("SKIP: Skipping fails");
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				continue;
			}
		} else {
			if ($only_alt_commits) {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				msg("Not an Alt-commit (skipping)");
				continue;
			} else {
				msg("Patch will apply without modifications");
			}
		}

		if (!$skip_review) {

			$backport = "v";

			while ($backport == "v") {
				$backport = Util::ask("Backport patch? (Y)es, (n)o, (b)lacklist, (v)iew commit or (a)bort: ", array("y", "b", "n", "v", "a"), "y");
				if ($backport == "v")
					view_commit($hash, $git);
			}

			if ($backport == "a") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				break;
			}

			if ($backport == "n") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				continue;
			}

			if ($backport == "b") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				$res = suse_blacklist_patch($p, $suse_repo_path, $git);
				$actually_backported++;
				continue;
			}
		}

		$res += suse_sequence_patch($suse_repo_path, $out);

		while ($res != 0) {
			error("Failed to sequence the patch");
			$ask = Util::ask("(R)etry, (s)kip, (b)lacklist, (v)iew patch or (a)bort: ", array("r", "s", "b", "v", "a"), "r");

			if ($ask == "v") {
				view_commit($hash, $git);
				continue;
			}

			if ($ask == "s")
				break;

			if ($ask == "b") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				$res = suse_blacklist_patch($p, $suse_repo_path, $git);
				$actually_backported++;
				break;
			}

			if ($ask == "a") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				break;
			}

			$res = suse_sequence_patch($suse_repo_path, $out);
		}

		if (isset($ask) && $ask == "b")
			continue;

		if (isset($ask) && $ask == "a")
			break;

		if ($res != 0) {
			undo_insert_and_sequence_patch($suse_repo_path, $filename);
			continue;
		}

		green("Patch applied successfully");
		$ask = Util::ask("Review final patch? (Y)es or (n)o: ", array("y", "n"), "y");
		if ($ask == "y")
			passthru("tig < ".$suse_repo_path."/patches.suse/".$filename);

		msg("Committing...");
		passthru("cd ".$suse_repo_path." && git add patches.suse/".$filename, $res);
		passthru("cd ".$suse_repo_path." && ./scripts/log", $res);

		$actually_backported++;
	}

	if ($actually_backported > 0 && $blacklist_all)
		passthru("cd ".$suse_repo_path." && ./scripts/log", $res);

	if ($actually_backported == 0)
		msg("\nNothing got backported.");
	else
		green("\nBackport of ".$actually_backported." patches finished successfully");
}

function remove_blacklist_entry($commit_id)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$blacklist_file = file_get_contents($suse_repo_path."/blacklist.conf");

	$blacklist = explode(PHP_EOL, $blacklist_file);
	for ($i = 0; $i < count($blacklist); $i++) {
		$b = trim($blacklist[$i]);
		$b_id = explode(" ", $b)[0];
		if ($b_id == $commit_id) {
			unset($blacklist[$i]);
			break;
		}
	}

	$blacklist = implode(PHP_EOL, $blacklist);
	file_put_contents($suse_repo_path."/blacklist.conf", $blacklist);
}

function cmd_suse_blacklists_to_alt_commit($argv)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$git = Globals::$git; 
	$blacklist_file = file_get_contents($suse_repo_path."/blacklist.conf");

	$blacklist = explode(PHP_EOL, $blacklist_file);

	$num_skip = 0;
	$line_count = 1;
	$num_lines = count($blacklist);

	msg("");

	foreach ($blacklist as $line) {
		$words = explode(" ", $line);

		$id = $words[0];

		// Search for lines with multiple commit ids
		for ($i = 1; $i < count($words); $i++) {
			$word = $words[$i];

			$word = str_replace(":", "", $word);
			if (strlen($word) == 40) {
				// Check if it's an alt-commit
				$filename = get_suse_patch_filename($suse_repo_path, $word);
				if ($filename === FALSE) {
					debug("SKIP: Filename not found for: ".$word);
					$num_skip++;
					continue;
				}

				$p = new Patch();
				$p->parse_from_git($id);

				$dup = check_for_alt_commits($p, $suse_repo_path, $git);
				if ($dup === FALSE) {
					debug("SKIP: ".$line);
					$num_skip++;
					continue;
				}

				msg("\n".$id." ".$word.": ".$filename);
				insert_tags_in_patch($suse_repo_path."/patches.suse/".$filename, array("Alt-commit: ".$id));
				remove_blacklist_entry($id);
				passthru($suse_repo_path."/scripts/log");
			}
		}

		msg("\rProgress: ".$line_count++."/".$num_lines." : ", FALSE);
	}
}

function cve_backport_branch($branch, $hash, $refs)
{
	rebase_cve_branch($branch);
	// kernel-source repo is checked out in $branch-cves after above rebase

	$num_behind = check_if_cve_is_behind($branch);
	if ($num_behind > 0)
		fatal("Your cve branch is ".$num_behind." patches behind. Rebase must have failed");

	$num_ahead = check_if_cve_is_ahead($branch);
	msg("Your CVE branch is ".$num_ahead." patches ahead of ".$branch);

	passthru("b2tf suse-fixes --refs=\"".$refs."\" --hash ".$hash." --branch ".$branch."-cves", $code);
}

function check_if_commit_is_handled($branch, $hash, &$status)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";

	unset($output);
	exec($pre."git checkout ".$branch." 2> /dev/null", $output, $code);
	if ($code != 0)
		return FALSE;

	$backports = get_suse_backports($suse_repo_path);
	$blacklists = get_suse_blacklists($suse_repo_path);

	$status = "";
	if (in_array($hash, $backports)) {
		$status = " (done)";
		return TRUE;
	}

	if (in_array($hash, $blacklists)) {
		$status = " (blacklisted)";
		return TRUE;
	}

	return FALSE;
}

// Makes sure the branch is updated and it's -cves counterpart is rebased on top of it
function rebase_cve_branch($branch)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";

	// Update the branch
	passthru($pre."git fetch origin ".$branch.":".$branch, $code);
	if ($code != 0)
		fatal("Failed to fetch branch ".$branch);

	// Create cve branch if it doesn't exist
	exec($pre."git rev-parse --verify ".$branch."-cves", $output, $code);
	if ($code == 128)
		exec($pre."git branch ".$branch."-cves ".$branch);

	// If cve branch is not behind, do nothing
	if (check_if_cve_is_behind($branch) == 0)
		return;

	unset($output);
	exec($pre."git checkout ".$branch."-cves", $output, $code);
	if ($code != 0)
		fatal("Failed to checkout branch ".$branch."-cves");

	passthru($pre."git pull --rebase . ".$branch, $code);
	if ($code != 0) {
		info("\nMake sure the rebase completed successfully before continuing!");
		Util::pause();
	}
}

function check_if_cve_is_behind($branch)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";

	unset($output);
	exec($pre."git rev-list --left-right --count ".$branch."-cves..".$branch, $output, $code);
	if ($code != 0)
		fatal("Failed to check if CVE branch is behind");

	$num_behind = trim(explode("\t", $output[0])[1]);
	return $num_behind;
}

function check_if_cve_is_ahead($branch, $for_next = FALSE)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";

	// Check if -cves branch is ahead
	// FIXME hardcoded to pjakobsson
	unset($output);
	if ($for_next)
		exec($pre."git rev-list --left-right --count origin/users/pjakobsson/".$branch."/for-next..".$branch."-cves", $output, $code);
	else
		exec($pre."git rev-list --left-right --count ".$branch."..".$branch."-cves", $output, $code);

	if ($code != 0)
		fatal("Failed to check if CVE branch is ahead");

	$num_ahead = trim(explode("\t", $output[0])[1]);

	return $num_ahead;
}

function cmd_suse_cve_update($argv)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";

	// Find all -cves branches
	unset($output);
	exec($pre."git branch | grep \"\\-cves\"", $output, $code);
	if ($code != 0)
		fatal("Failed to search for -cves branches");

	$branches = array();
	foreach ($output as $line) {
		$branch = substr($line, 2, -5);
		$branches[] = $branch;
	}

	foreach ($branches as $branch)
		rebase_cve_branch($branch);

	$result_str = "";
	foreach ($branches as $branch) {
		$num_ahead = check_if_cve_is_ahead($branch);
		if ($num_ahead > 0)
			$result_str .= $branch."-cves is ".$num_ahead." patches ahead ";
		else
			continue;

		// Check if for-next branch exists for this branch. If not we assume it needs pushing
		exec($pre."git ls-remote --exit-code --heads origin users/pjakobsson/".$branch."/for-next", $res, $code);
		if ($code == 0)
			$num_ahead = check_if_cve_is_ahead($branch, TRUE);

		$push_needed = FALSE;
		if ($num_ahead > 0) {
			$result_str .= "(needs pushing to for-next)\n";
			$push_needed = TRUE;
		} else {
			$result_str .= "(waiting to be merged)\n";
		}
	}

	msg("");
	if ($result_str == "") {
		info("Everything is up-to-date. Nothing needs pushing.");
		return;
	} else {
		info($result_str);
	}

	if (!$push_needed)
		return;

	$ask = Util::ask("Push branches (y/N)? ", array("y", "n"), "n");
	if ($ask == "y") {
		foreach ($branches as $branch) {

			// Check if for-next branch exists for this branch. If not we assume it needs pushing
			exec($pre."git ls-remote --exit-code --heads origin users/pjakobsson/".$branch."/for-next", $res, $code);
			if ($code == 0)
				$num_ahead = check_if_cve_is_ahead($branch, TRUE);
			else
				$num_ahead = 1;

			if ($num_ahead > 0) {
				// FIXME: Hardcoded to pjakobsson
				$ask = Util::ask("Push ".$branch."-cves:users/pjakobsson/".$branch."/for-next (Y/n)? ", array("y", "n"), "y");

				if ($ask == "y") {
					passthru($pre."git push origin ".$branch."-cves:users/pjakobsson/".$branch."/for-next", $code);
					if ($code != 0)
						fatal("Failed to push: ".$branch."-cves:users/pjakobsson/".$branch."/for-next");
				}
			}
		}
	}
}

function cmd_suse_cve($argv)
{
	$suse_repo_path = Options::get("suse-repo-path");
	$pre = "cd ".$suse_repo_path." && ";
	$cve = Options::get("cve");
	$git = Globals::$git;

	unset($output);
	exec($pre."./scripts/cve_tools/cve2metadata.sh ".$cve." 2>&1", $output, $code);

	// Sometimes cve2metadata fails so make sure we check that
	if (strpos($output[0], "cannot be resolved to a CVE") === FALSE) {
		$metadata = explode(" ", $output[0]);
		$hash = array_shift($metadata);
		$score = array_shift($metadata);
		$score = explode(":", $score)[1];
		$refs = implode(" ", $metadata);

		$patch = new Patch();
		$patch->parse_from_git($hash);
		if ($patch === FALSE)
			fatal("Failed to find commit in upstream repo (".$git->dir.")");

		msg("Commit:\t\t".$hash);
		msg("Subject:\t".$patch->subject);
		if ($score >= 7)
			error("Score:\t\t".$score);
		else
			msg("Score:\t\t".$score);
		msg("References:\t".$refs);

		$bsc_id = explode("bsc#", $refs);
		if (isset($bsc_id[1])) {
			$bsc_id = explode(" ", $bsc_id[1])[0];
			msg("Link:\t\thttps://bugzilla.suse.com/show_bug.cgi?id=".$bsc_id);
		}
	} else {
		fatal("cve2metadata failed to find the CVE");
		$refs = FALSE;
	}

	unset($output);
	exec($pre."./scripts/check-kernel-fix ".$cve, $output, $code);

	if ($code != 0) {
		error("Failed to run check-kernel-fix scripts");

		$params = Util::get_line("Enter additional parameters: ");
		unset($output);
		exec($pre."./scripts/check-kernel-fix ".$params." ".$cve, $output, $code);
	}
	if ($code != 0)
		fatal("Still not working. Aborting!");

	$output = implode(PHP_EOL, $output);
	if (str_contains($output, "NO ACTION NEEDED")) {
		msg("NO ACTION NEEDED: All relevant branches contain the fix!");
		return;
	}

	$output = explode("ACTION NEEDED!\n", $output);
	$actions = explode(PHP_EOL, $output[1]);

	$hashes = array();
	$branches = array();
	foreach ($actions as $a) {
		if (trim($a) == "")
			continue;

		$branch = explode(": ", $a);
		if (count($branch) !== 3)
			continue;
		$branch = $branch[0];

		$hash = explode("MANUAL: ", $a);
		if (count($hash) !== 2)
			continue;
		$hash = $hash[1];

		foreach (explode(" ", $hash) as $str) {
			if (strlen($str) == 40) {
				$hash = $str;
				break;
			}
		}
		if (strlen($hash) != 40)
			fatal("Couldn't find commit id for branch ".$branch);

		$hashes[$branch] = $hash;
		$branches[] = $branch;
	}
	asort($branches);
	$branches[] = "Quit";

	// We must reindex the array
	$branches = array_values($branches);

	msg("\nPossibly affected branches:");

	do {
		// Get current branch so we can restore it after all checks
		unset($output);
		exec($pre."git rev-parse --abbrev-ref HEAD", $output, $code);
		$old_branch = $output[0];

		$i = 1;
		foreach ($branches as $branch) {
			$status = "";
			if ($branch != "Quit") {
				check_if_commit_is_handled($branch."-cves", $hashes[$branch], $status);
			}

			msg($i++.") ".str_pad($branch, 20, " ").$status);
		}

		exec($pre."git checkout ".$old_branch." 2>&1");

		$branch = Util::ask_from_array($branches, "Select branch for backporting: ", FALSE);
		if ($branch != "Quit")
			cve_backport_branch($branch, $hashes[$branch], $refs);
	} while ($branch != "Quit");
}

?>
