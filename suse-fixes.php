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

function load_fixes_file($fixes_file, $work_dir, $git)
{
	$file = file_get_contents($fixes_file);
	if ($file === FALSE)
		fatal("Failed to open fixes file: ".$fixes_file);
	$lines = explode(PHP_EOL, $file);

	if (isset($opts['file-type'])) {
		$filetype = get_opt("file-type");
	} else {
		$file_types = array("from-email", "from-script", "simple");
		$filetype = Util::ask_from_array($file_types, "Select file-type:", TRUE);
	}

	// Figure out which release we want to work on
	if ($filetype == "from-email") {
		if (isset($opts['release']))
			$release = get_opt("release");
		else
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
		$dup->parse_from_git($commit_id, $git);

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
		} else {
			msg($p->commit_id.": ".strlen($diff1));
			msg($dup->commit_id.": ".strlen($diff2));
			file_put_contents("/tmp/1", $diff1);
			file_put_contents("/tmp/2", $diff2);
			passthru("diff -Naur /tmp/1 /tmp/2");
			passthru("rm /tmp/1");
			passthru("rm /tmp/2");

			error("Found non-exact duplicate for ".$p->commit_id." in ".$dup->commit_id);
		}

		$ask = Util::ask("(A)ccept duplicate or (s)kip: ", array("a", "s"), "a");

		if ($ask == "a")
			return $dup;
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

	return $res[0];
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
	exec("cd ".$suse_repo_path." && ./scripts/sequence-patch.sh --rapid 2>&1", $output, $res);

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
	$reasons = array("comment fixes", "documentation fixes", "not applicable", "other");

	if ($reason == "") {
		for ($i = 0; $i < count($reasons); $i++)
			msg(($i + 1).") ".$reasons[$i]);

		$reason = Util::ask_from_array($reasons, "Blacklist reason ");
		if ($reason == "other")
			$reason = Util::get_line("Reason: ");
	}

	exec("echo \"".$p->commit_id." # ".$reason."\" >> ".$suse_repo_path."/blacklist.conf");
	msg("Blacklisting: ".$p->commit_id." # ".$reason);

	$git->cmd("git log --oneline -n1 ".$p->commit_id, $oneline, $res);
	if ($res != 0) {
		error("Failed to get oneline log of commit id: ".$p->commit_id);
		return;
	}
	$oneline = addslashes($oneline[0]);

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
	$opts = Globals::$options;
	$work_dir = realpath(get_opt("work-dir"));
	$suse_repo_path = get_opt("suse-repo-path");

	if (isset($opts['refs']))
		$refs = get_opt("refs");
	else
		$refs = "git-fixes";

	if (isset($opts['fixes-file']))
		$fixes_file = realpath(get_opt("fixes-file"));
	else
		$fixes_file = FALSE;

	if (isset($opts['skip-review']))
		$skip_review = TRUE;
	else
		$skip_review = FALSE;

	// Skip all patches that doesn't immediately applies
	if (isset($opts['skip-fails']))
		$skip_fails = TRUE;
	else
		$skip_fails = FALSE;

	// Ignore everything that is not alt-commits
	if (isset($opts['only-alt-commits']))
		$only_alt_commits = TRUE;
	else
		$only_alt_commits = FALSE;

	if (isset($opts['repo-tag']))
		$repo_tag = get_opt("repo-tag");

	$signoff = get_opt("signoff");

	$git_dir = realpath(get_opt("git-dir"));
	$git = new GitRepo();
	$git->set_dir($git_dir);

	// Check that user is on the right branch
	$out = "";
	exec("cd ".$suse_repo_path." && git branch --show-current", $out, $res);

	if ($res != 0)
		fatal("Couldn't get current branch from ".$suse_repo_path);

	$original_branch = $out[0];
	$line = Util::get_line("Enter name for new branch (leave empty to stay on ".$original_branch."): ");
	$line = trim(strtolower($line));
	if ($line == "") {
		msg("Continuing on current branch");
		$branch_name = $original_branch;
	} else {
		$branch_name = $original_branch."-".$line;

		exec("cd ".$suse_repo_path." && git checkout -b ".$branch_name, $out, $res);
		if ($res != 0)
			fatal("Failed to create and checkout branch: ".$branch_name);
	}

	// Find the kernel version in the suse kernel-source repo
	$kernel_version = get_kernel_version($suse_repo_path);
	msg("SUSE repo kernel version: ".$kernel_version);
	if ($kernel_version === FALSE)
		fatal("Failed to get SUSE repo kernel version");

	if ($fixes_file !== FALSE) {
		$patches = load_fixes_file($fixes_file, $work_dir, $git);
	} else {
		msg("No fixes file specified.");
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

	msg("Found ".count($patches)." patches");

	$i = 0;
	foreach ($patches as $hash) {
		$i++;
		$p = new Patch();
		$p->parse_from_git($hash, $git);

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
		if (isset($repo_tag)) {
			$mainline = "Queued in subsystem maintainer repo";
		} else {
			$mainline = $p->get_mainline_tag($git, $version_list);
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

		if (isset($repo_tag))
			$tags[] = "Git-repo: ".$repo_tag;

		suse_insert_file($file_src, $file_dst);
		insert_tags_in_patch($file_dst, $tags, $signoff);

		$res = suse_insert_patch($suse_repo_path, $file_dst);
		$res += suse_sequence_patch($suse_repo_path, $out);

		// If we're working on a non-mainline repo ($repo_tag is set) we cannot do any clever tricks so skip this part
		if (isset($repo_tag)) {
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

					msg("The patch is an Alt-commit");
					Util::pause();
					passthru("cd ".$suse_repo_path." && ./scripts/log", $status);
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
			view_commit($hash, $git);
			$backport = Util::ask("Backport patch? (Y)es, (b)lacklist, (s)kip or (a)bort: ", array("y", "b", "s", "a"), "y");

			if ($backport == "a") {
				undo_insert_and_sequence_patch($suse_repo_path, $filename);
				break;
			}

			if ($backport == "s") {
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
			$ask = Util::ask("(R)etry, (s)kip, (b)lacklist, (v)iew again or (a)bort: ", array("r", "s", "b", "v", "a"), "r");

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

		if ($ask == "b")
			continue;

		if ($ask == "a")
			break;

		if ($res != 0) {
			undo_insert_and_sequence_patch($suse_repo_path, $filename);
			continue;
		}

		green("Patch applied successfully");
		Util::pause();
		passthru("cd ".$suse_repo_path." && git add patches.suse/".$filename, $res);
		passthru("cd ".$suse_repo_path." && ./scripts/log", $res);

		$actually_backported++;
	}

	if ($actually_backported == 0) {
		if ($original_branch != $branch_name) {
			msg("\nNothing got backported. Removing the emtpy branch we created.");
			passthru("cd ".$suse_repo_path." && git checkout ".$original_branch, $res);
			if ($res != 0)
				fatal("Failed to return to original branch");

			passthru("cd ".$suse_repo_path." && git branch -d ".$branch_name, $res);

			if ($res != 0)
				fatal("Failed to remove backport branch (should be empty but is not)");
		} else {
			msg("\nNothing got backported. Staying on current branch.");
		}
	} else {
		green("\nBackport of ".$actually_backported." patches finished successfully");
	}
}

?>
