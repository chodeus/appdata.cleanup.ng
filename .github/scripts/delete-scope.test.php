<?php
// What a delete can reach: appdataCleanupNgZfsDestroy() with a fake zfs first on PATH, nested mounts, and datasets.
// It creates folders under /mnt, so it runs only in a throwaway container: docker run ... php:8.2-cli
if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes /mnt\n"); exit(2); }
$helpers = $argv[1] ?? __DIR__ . "/../../source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php";
require $helpers;

$bin = sys_get_temp_dir() . "/acng-zfs-" . getmypid();
@mkdir($bin);
$log = "$bin/destroys.log";
file_put_contents("$bin/zfs", <<<'SH'
#!/bin/sh
for last in "$@"; do :; done
case "$1 $2" in
  "destroy -nvp")
    [ -n "$FAKE_DEPENDENTS" ] && { echo "cannot destroy '$last': filesystem has children"; exit 1; }
    exit 0 ;;
  "list -H")
    [ "$FAKE_LIST_FAIL" = 1 ] && exit 1
    printf '%b' "$FAKE_LIST"
    exit 0 ;;
  destroy*)
    echo "$*" >> "$FAKE_LOG"
    exit 0 ;;
esac
exit 2
SH);
chmod("$bin/zfs", 0755);
putenv("PATH=$bin:" . getenv("PATH"));
putenv("FAKE_LOG=$log");

$fail = 0;
function check($name, $ok, $detail = "") {
  global $fail;
  if ( ! $ok ) { $fail++; echo "FAIL: $name $detail\n"; }
}
// name => [has dependents, `zfs list -r` rows, list fails, expect destroyed with, refusal text]
$self = "cache/appdata/app\tfilesystem\tyes\t/mnt/cache/appdata/app\n";
$cases = array(
  "no dependents"            => array("", $self, 0, "destroy cache/appdata/app", ""),
  "snapshots only"           => array("1", $self, 0, "destroy -r cache/appdata/app", ""),
  "child inside the folder"  => array("1", $self . "cache/appdata/app/db\tfilesystem\tyes\t/mnt/cache/appdata/app/db\n", 0, "destroy -r cache/appdata/app", ""),
  "child under another pool" => array("1", $self . "cache/appdata/app/db\tfilesystem\tyes\t/mnt/backup/appdata/app/db\n", 0, "", "cache/appdata/app/db (mounted at /mnt/backup/appdata/app/db)"),
  "malformed child row"      => array("1", $self . "cache/appdata/app/db\tfilesystem\n", 0, "", "could not be checked"),
  "child mounted elsewhere"  => array("1", $self . "cache/appdata/app/db\tfilesystem\tyes\t/mnt/disk1/db\n", 0, "", "cache/appdata/app/db (mounted at /mnt/disk1/db)"),
  "child not mounted"        => array("1", $self . "cache/appdata/app/old\tfilesystem\tno\tnone\n", 0, "", "cache/appdata/app/old (not mounted)"),
  "child volume"             => array("1", $self . "cache/appdata/app/vm\tvolume\t-\t-\n", 0, "", "cache/appdata/app/vm (a volume)"),
  "listing fails"            => array("1", "", 1, "", "could not be checked"),
  "dataset itself unmounted" => array("1", "cache/appdata/app\tfilesystem\tno\t/mnt/cache/appdata/app\n", 0, "", "could not be checked"),
);
foreach ( $cases as $name => $case ) {
  list($dependents, $list, $listFail, $destroyed, $refusal) = $case;
  @unlink($log);
  putenv("FAKE_DEPENDENTS=$dependents");
  putenv("FAKE_LIST=" . str_replace(array("\t", "\n"), array('\t', '\n'), $list));
  putenv("FAKE_LIST_FAIL=$listFail");
  $r = appdataCleanupNgZfsDestroy("cache/appdata/app");
  $ran = trim((string)@file_get_contents($log));
  check("$name: destroy command", $ran === $destroyed, "ran '" . $ran . "'");
  check("$name: result", $r['ok'] === ($destroyed !== ""), json_encode($r));
  if ( $refusal !== "" ) check("$name: reason", strpos($r['blocked'] ?? "", $refusal) !== false, json_encode($r));
}

// rm -rf crosses into anything mounted inside the folder, in any of its views
$table = "$bin/mountinfo";
file_put_contents($table, implode("\n", array(
  "36 25 0:30 / /mnt/cache rw - zfs cache rw",
  "37 36 0:31 / /mnt/cache/appdata/app rw - zfs cache/appdata/app rw",
  "38 37 0:32 / /mnt/cache/appdata/app/db rw - zfs cache/appdata/app/db rw",
  "39 25 0:33 / /mnt/disk1/appdata/arr/media rw - xfs /dev/md1 rw",
  "40 36 0:34 / /mnt/cache/appdata/application/x rw - zfs cache/appdata/application/x rw",
  "41 36 0:35 / /mnt/cache/appdata/my\\040app/cache rw - zfs cache/appdata/my_app/cache rw",
  "42 36 0:36 / /mnt/cache/appdata/app\\040/db rw - zfs cache/other rw",
)) . "\n");
$mounts = array(
  "a nested dataset"            => array("/mnt/cache/appdata/app", array("/mnt/cache/appdata/app/db")),
  "the array-disk view"         => array("/mnt/user/appdata/arr", array("/mnt/disk1/appdata/arr/media")),
  "a sibling with that prefix"  => array("/mnt/cache/appdata/app/db", array()),
  "an escaped space"            => array("/mnt/cache/appdata/my app", array("/mnt/cache/appdata/my app/cache")),
  "the pool above it"           => array("/mnt/cache/appdata/other", array()),
  "a name ending in a space"    => array("/mnt/cache/appdata/app ", array("/mnt/cache/appdata/app /db")),
  "through /mnt/user, the pool" => array("/mnt/user/appdata/app", array("/mnt/cache/appdata/app", "/mnt/cache/appdata/app/db")),
  "a pool path, not the disk"   => array("/mnt/cache/appdata/arr", array()),
);
foreach ( $mounts as $name => $case ) {
  $got = appdataCleanupNgMountsInside($case[0], $table);
  check("mounts inside, $name", $got === $case[1], json_encode($got));
}
check("mounts inside, prefix is not a parent", appdataCleanupNgMountsInside("/mnt/cache/appdata/app", $table) === array("/mnt/cache/appdata/app/db"), "application/x must not count");
check("mounts inside, unreadable table", appdataCleanupNgMountsInside("/mnt/cache/appdata/app", "$bin/missing") === null);
file_put_contents("$bin/empty", "");
check("mounts inside, empty table", appdataCleanupNgMountsInside("/mnt/cache/appdata/app", "$bin/empty") === null);
file_put_contents("$bin/short", "36 25 0:30 /\n");
check("mounts inside, malformed row", appdataCleanupNgMountsInside("/mnt/cache/appdata/app", "$bin/short") === null);

// The dataset a folder resolves to: its own mountpoint exactly, or through /mnt/user the only one at that folder
putenv("FAKE_LIST=" . implode('\\n', array(
  "cache/appdata/app\\tyes\\t/mnt/cache/appdata/app", "fast/appdata/app\\tyes\\t/mnt/fast/appdata/app",
  "cache/appdata/solo\\tyes\\t/mnt/cache/appdata/solo", "fast/appdata/old\\tno\\t/mnt/fast/appdata/old",
  "cache/appdata/split\\tyes\\t/mnt/cache/appdata/split")) . '\\n');
foreach ( array("cache/appdata/solo", "fast/appdata/old", "cache/appdata/split", "disk1/appdata/split") as $dir ) @mkdir("/mnt/$dir", 0755, true);
putenv("FAKE_LIST_FAIL=0");
$resolves = array(
  "/mnt/cache/appdata/app"  => "cache/appdata/app",
  "/mnt/fast/appdata/app"   => "fast/appdata/app",
  "/mnt/cache/appdata/app " => "",
  "/mnt/user/appdata/app"   => "",
  "/mnt/user/appdata/solo"  => "cache/appdata/solo",
  "/mnt/disk1/appdata/solo" => "",
  "/mnt/user/appdata/old"   => "",   // an unmounted replica holds none of the folder
  "/mnt/fast/appdata/old"   => "",
  "/mnt/user/appdata/split" => "",   // destroying the dataset would leave the disk1 copy
);
foreach ( $resolves as $path => $dataset ) {
  $got = appdataCleanupNgResolveZfsDataset($path);
  check("dataset for '$path'", $got === $dataset, json_encode($got));
}

@unlink($log); @unlink($table); @unlink("$bin/empty"); @unlink("$bin/short"); @unlink("$bin/zfs"); @rmdir($bin);
if ( $fail ) exit(1);
echo "delete scope tests passed (" . (count($cases) + count($mounts) + count($resolves) + 4) . " cases)\n";
