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

// Should only be used from check_for_duplicate()
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

// Check git repo for patches matching the contents of the provided patch
function check_for_duplicate($p, $git, &$match)
{
	unset($res);
	$git->cmd("git rev-list --no-merges -n2 --oneline HEAD --grep \"".
		  addslashes($p->subject)."\"", $res);

	foreach ($res as $commit) {
		$commit = explode(" ", $commit)[0];
		$dup = new Patch();
		$dup->parse_from_git($commit, $git);

		if ($p->commit_id == $dup->commit_id)
			continue;
		else
			break;
	}

	if ($p->commit_id == $dup->commit_id) {
		$match = FALSE;
		return FALSE;
	}

	unset($res);
	$git->cmd("git diff ".$p->commit_id."~1..".$p->commit_id, $res, $status);
	$diff1 = __strip_diff($res);

	unset($res);
	$git->cmd("git diff ".$dup->commit_id."~1..".$dup->commit_id, $res, $status);
	$diff2 = __strip_diff($res);

	if (strcmp($diff1, $diff2) == 0) {
		$match = TRUE; // Contents match
	} else {
		msg($p->commit_id.": ".strlen($diff1));
		msg($dup->commit_id.": ".strlen($diff2));
		file_put_contents("/tmp/1", $diff1);
		file_put_contents("/tmp/2", $diff2);
		passthru("diff -Naur /tmp/1 /tmp/2");
		passthru("rm /tmp/1");
		passthru("rm /tmp/2");
		$match = FALSE; // Contents doesn't match
	}

	return $dup;
}

function insert_and_sequence_patch($suse_repo_path, $file_dst, $file_src, &$out)
{
	passthru("cp ".$file_src." ".$suse_repo_path."/patches.suse/", $res);
	if ($res != 0)
		fatal("ERROR: failed to copy file to kernel-source repo");

	passthru("cd ".$suse_repo_path." && ./scripts/git_sort/series_insert.py patches.suse/".$file_dst, $res);
	if ($res != 0) {
		error("SKIP: Failed to insert patch into series");
		passthru("rm ".$suse_repo_path."/patches.suse/".$file_dst);
		return FALSE;
	}

	unset($output);
	exec("cd ".$suse_repo_path." && ./scripts/sequence-patch.sh --rapid 2>&1", $output, $res);

	$out = $output;
	return $res;
}

function cmd_suse_fixes($argv, $opts)
{
	$work_dir = realpath(get_opt("work-dir", $opts));
	$suse_repo_path = get_opt("suse-repo-path", $opts);

	if (isset($opts['refs']))
		$refs = get_opt("refs", $opts);
	else
		$refs = "git-fixes";

	if (isset($opts['fixes-file']))
		$fixes_file = realpath(get_opt("fixes-file", $opts));
	else
		$fixes_file = FALSE;

	if (isset($opts['skip-review']))
		$skip_review = TRUE;
	else
		$skip_review = FALSE;

	$signoff = get_opt("signoff", $opts);

	$git_dir = realpath(get_opt("git-dir", $opts));
	$git = new GitRepo();
	$git->set_dir($git_dir);


	// Check that user is on the right branch
	$out = "";
	exec("cd ".$suse_repo_path." && git branch --show-current", $out, $res);

	if ($res != 0)
		fatal("Couldn't get current branch from ".$suse_repo_path);

	$original_branch = $out[0];
	$line = Util::get_line("Branch from ".$original_branch." (or stay on current)? [Y/n]? ");
	$line = trim(strtolower($line));
	if (isset($line[0]) && $line[0] != "y") {
		msg("Continuing on current branch");
		$branch_name = $original_branch;
	} else {

		$branch_name = $original_branch."-git-fixes-".date("Y-m-d");

		exec("cd ".$suse_repo_path." && git checkout -b ".$branch_name, $out, $res);
		if ($res != 0)
			fatal("Failed to create and checkout branch: ".$branch_name);
	}

	if ($fixes_file !== FALSE) {
		$file = file_get_contents($fixes_file);
		if ($file === FALSE)
			fatal("Failed to open fixes file: ".$fixes_file);
		$lines = explode(PHP_EOL, $file);

		if (isset($opts['file-type'])) {
			$filetype = get_opt("file-type", $opts);
		} else {
			$file_types = array("from-email", "from-script");
			$filetype = Util::ask_from_array($file_types, "Select file-type:", TRUE);
		}

		// Figure out which release we want to work on
		if ($filetype == "from-email") {
			if (isset($opts['release']))
				$release = get_opt("release", $opts);
			else
				$release = figure_out_release($lines);

			$release = str_replace(" ", "-", $release);
			$release = str_replace(".", "-", $release);
			$patch_dir = $work_dir."/patches-".$release;

			$patches = parse_fixes_from_email($lines, $release, $git);

		} else if ($filetype == "from-script") {
			$patch_dir = $work_dir."/patches";

			$patches = parse_fixes_from_script($lines, $git);
		}
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

		$patch_dir = $work_dir."/patches";
	}

	// Prepare the filesystem
	exec("mkdir ".$patch_dir." 2> /dev/null");

	$reasons = array("comment fixes", "documentation fixes", "not applicable", "other");

	$suse_backports = get_suse_backports($suse_repo_path);
	$suse_blacklists = get_suse_blacklists($suse_repo_path);

	$actually_backported = 0;
	$alt_commits = array();

	foreach ($patches as $hash) {
		$p = new Patch();
		$p->parse_from_git($hash, $git);

		green("\nBackporting: ".$hash." ".$p->subject);

		if (in_array($p->commit_id, $suse_backports)) {
			error("SKIP: Patch is already backported");
			continue;
		}

		if (in_array($p->commit_id, $suse_blacklists)) {
			error("SKIP: Patch is blacklisted");
			continue;
		}

		if (!$skip_review) {
			passthru("cd ".$git->get_dir()." && git show ".$hash." | vim -M -");
			// Clear some noise from vim
			echo "\033[F                                                                      ";
			echo "\033[F                                                                      ";
			echo "\r";

			$backport = Util::ask("Backport patch? ([Y]es/[b]lacklist/[s]kip/[a]bort): ", array("y", "n", "s", "a"), "y");

			if ($backport == "a")
				break;

			if ($backport == "s")
				continue;

			if ($backport == "b") {
				for ($i = 0; $i < count($reasons); $i++)
					msg(($i + 1).") ".$reasons[$i]);

				$reason = Util::ask_from_array($reasons, "Blacklist reason ");
				if ($reason == "other")
					$reason = Util::get_line("Reason: ");

				exec("echo \"".$p->commit_id." # ".$reason."\" >> ".$work_dir."/blacklist.conf");
				error("Blacklisting: ".$p->commit_id." # ".$reason);
				echo "\n";
				continue;
			}
		}

		unset($res);
		$git->cmd("git format-patch --no-renames --keep-subject -o ".$patch_dir." ".$hash."~1..".$hash, $res, $output);

		if ($output != 0)
			fatal("git format-patch failed");

		// Remove the xxxx- prefix from patch names
		$file = $res[0];
		$file_dst = substr($file, strlen($patch_dir) + 6);
		exec("mv ".$file." ".$patch_dir."/".$file_dst);
		$file = $patch_dir."/".$file_dst;

		msg("Inserting tags...");
		$mainline = $p->get_mainline_tag($git);
		if ($mainline === FALSE)
			fatal("Failed to get mainline tag for commit: ".$hash);

		$tags = array(	"Git-commit: ".$p->commit_id,
				"Patch-mainline: ".$mainline,
				"References: ".$refs);

		insert_tags_in_patch($file, $tags, $signoff);

		// The patch is now prepared for kernel-source
		// Try applying it into the kernel-source tree

		if (file_exists($suse_repo_path."/patches.suse/".$file_dst)) {
			msg("Patch-file already exists but commit id doesn't. Must be an Alt-commit.");
			$dup = check_for_duplicate($p, $git, $match);
			if ($match !== TRUE) {
				error("Found non-exact duplicate in ".$dup->commit_id.".");
				$ask = Util::ask("(A)dd alt-commit or (s)kip: ", array("a", "s"), "a");

				// Skip
				if ($ask == "s")
					continue;
			}

			passthru("cd ".$suse_repo_path." && ./scripts/patch-tag --Add Alt-commit=".$p->commit_id." patches.suse/".$file_dst, $res_alt);
			if ($res_alt != 0) {
				error("Failed to add alt-commit tag to file");
				continue;
			}

			Util::pause();
			passthru("cd ".$suse_repo_path." && ./scripts/log", $status);
			$actually_backported++;

			continue;
		}

		msg("Inserting and sequencing patch...");
		$res = insert_and_sequence_patch($suse_repo_path, $file_dst, $file, $out);
		if ($res === FALSE)
			continue;

		if ($res != 0) {
			$str = trim($out[count($out) - 3]);
			if ($str == "! = Reverting the patch fixes this failure.") {
				// Undo the insert
				passthru("rm ".$suse_repo_path."/patches.suse/".$file_dst);
				passthru("cd ".$suse_repo_path." && git restore series.conf");

				// Find original commit
				unset($out);
				exec("cd ".$suse_repo_path."/patches.suse && grep -l \"".$p->subject."\" *.patch", $out, $res_alt);


				// If we only found one match, we most likely have found the alt-commit, so add the tag
				if (count($out) == 1) {
					$match = $out[0];
					green("Adding alt-commit");
					passthru("cd ".$suse_repo_path." && ./scripts/patch-tag --Add Alt-commit=".$p->commit_id." patches.suse/".$match, $res_alt);
					if ($res_alt != 0)
						error("Failed to add alt-commit tag");
					Util::pause();
					passthru("cd ".$suse_repo_path." && ./scripts/log", $status);
					$actually_backported++;
				}

				continue;
			} else {
				msg("Checking for duplicates...");
				$dup = check_for_duplicate($p, $git, $match);
				if ($dup !== FALSE) {
					msg("Found: ".$dup->commit_id.": ".$dup->subject);
					if ($match == TRUE)
						green("Contents match!");
					else
						error("Warning: contents doesn't match");
				}

				while ($res != 0) {

					msg("FAIL: Patch doesn't apply");

					if ($dup === FALSE)
						$line = Util::get_line("(R)etry or (s)kip: ");
					else
						$line = Util::get_line("(R)etry, (s)kip or (b)lacklist duplicate: ");

					$line = trim(strtolower($line));
					if ($line == "s")
						break;

					if ($line == "b" && $dup !== FALSE) {
						$bl_file = file_get_contents($suse_repo_path."/blacklist.conf");
						if ($bl_file === FALSE) {
							error("Failed to read blacklist.conf");
							break;
						}

						$bl_file .= $p->commit_id." # Duplicate of ".$dup->commit_id.": ".$dup->subject."\n";
						if (file_put_contents($suse_repo_path."/blacklist.conf", $bl_file) === FALSE) {
							error("Failed to write to blacklist.conf");
							break;
						}

						unset($res_oneliner);
						$git->cmd("git log --oneline -n1 ".$p->commit_id, $res_oneliner, $status);
						$oneliner = trim($res_oneliner[0]);
						passthru("cd ".$suse_repo_path." && git add blacklist.conf", $status);
						passthru("cd ".$suse_repo_path." && git commit -m \"blacklist.conf: ".$oneliner."\"", $status);
						$actually_backported++;
						break;
					} else {
						msg("Sequencing patches...");
						unset($out);
						exec("cd ".$suse_repo_path." && ./scripts/sequence-patch.sh --rapid 2>&1", $out, $res);
					}
				}
			}

			if ($res != 0) {
				passthru("rm ".$suse_repo_path."/patches.suse/".$file_dst);
				passthru("cd ".$suse_repo_path." && git restore series.conf");
				continue;
			}
			info("Patch applied successfully!");
		}

		passthru("cd ".$suse_repo_path." && git add patches.suse/".$file_dst);
		Util::pause();
		passthru("cd ".$suse_repo_path." && ./scripts/log", $res);

		if ($res != 0) {
			error("SKIP: Patch couldn't be commited");
			passthru("rm ".$suse_repo_path."/patches.suse/".$file_dst);
			passthru("cd ".$suse_repo_path." && git restore series.conf");
			continue;
		}

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
