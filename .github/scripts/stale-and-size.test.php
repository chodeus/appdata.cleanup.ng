<?php
// Stale templates through Community Applications' shipped template reader, and folder sizes through du and the real cache.
// It writes /boot/config, /mnt/user/appdata and /var/lib/appdata.cleanup.ng, so it runs only in a throwaway container:
// docker run ... -e ACNG_TEST_CA_HELPERS=<CA's include/helpers.php> php:8.2-cli php .github/scripts/stale-and-size.test.php
if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes /boot/config, /mnt/user and /var/lib\n"); exit(2); }
$ca = realpath((string)getenv("ACNG_TEST_CA_HELPERS"));
if ( $ca === false ) { fwrite(STDERR, "ACNG_TEST_CA_HELPERS must name Community Applications' include/helpers.php\n"); exit(2); }
require $ca;
$helpers = realpath($argv[1] ?? __DIR__ . "/../../source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php");
require $helpers;

$fail = 0;
function check($name, $ok, $detail = "") {
  global $fail;
  if ( ! $ok ) { $fail++; echo "FAIL: $name $detail\n"; }
}
function put($path, $body) { @mkdir(dirname($path), 0755, true); file_put_contents($path, $body); }
function wipe($dir) { if ( is_dir($dir) || is_link($dir) ) exec("rm -rf " . escapeshellarg($dir)); }
function paths(...$configs) { return implode("", $configs); }
function cfg($type, $target, $value) { return "  <Config Name=\"x\" Target=\"$target\" Default=\"\" Mode=\"rw\" Description=\"\" Type=\"$type\" Display=\"always\" Required=\"false\" Mask=\"false\">$value</Config>\n"; }
function template($name, $configs) { return "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n" . ( $name === null ? "" : "  <Name>$name</Name>\n" ) . "  <Repository>x/y</Repository>\n$configs</Container>\n"; }

$tpl = "/boot/config/plugins/dockerMan/templates-user";
$A = "/mnt/user/appdata";

// Stale templates: one per template whose container isn't installed, with the appdata folder it would use
wipe($tpl);
put("$tpl/my-Plex.xml", template("Plex", cfg("Path", "/config", "$A/plex")));
put("$tpl/my-solo.xml", template("solo", cfg("Path", "/config", "$A/solo")));
put("$tpl/my-multi.xml", template("multi", paths(cfg("Variable", "TZ", "UTC"), cfg("Path", "/data", "/mnt/user/media"), cfg("Path", "/cache", "$A/multi-cache"), cfg("Path", "/config", "$A/multi"))));
put("$tpl/my-fallback.xml", template("fallback", paths(cfg("Path", "/data", "$A/first/data"), cfg("Path", "/other", "$A/second"))));
put("$tpl/my-nopath.xml", template("nopath", paths(cfg("Variable", "TZ", "UTC"), cfg("Variable", "APPDIR", "$A/varonly"))));
put("$tpl/my-noconfig.xml", template("noconfig", ""));
put("$tpl/my-noname.xml", template(null, cfg("Path", "/config", "$A/noname")));
put("$tpl/my-broken.xml", "<Container><Name>broken</Name>");
$stale = array();
foreach ( appdataCleanupNgStaleTemplates(array(" plex ")) as $t ) $stale[$t['name']] = array($t['seg'], $t['appdata'], basename($t['file']));
$expected = array(
  "fallback" => array("first", "$A/first/data", "my-fallback.xml"),
  "multi" => array("multi", "$A/multi", "my-multi.xml"),
  "noconfig" => array("", "", "my-noconfig.xml"),
  "nopath" => array("", "", "my-nopath.xml"),
  "solo" => array("solo", "$A/solo", "my-solo.xml"),
);
ksort($stale);
check("stale templates: installed, nameless and broken ones left out; a /config path wins, else the first appdata path", $stale === $expected, json_encode($stale));
check("stale templates: a single <Config> arrives as a record", isset(readXmlFile("$tpl/my-solo.xml")['Config']['@attributes']));

// Folder sizes: du's figure, cached by mtime in a private file that a planted link can't redirect
$cacheDir = "/var/lib/appdata.cleanup.ng";
$cache = "$cacheDir/sizecache.json";
wipe($cacheDir); wipe("$A/sized");
put("$A/sized/blob", str_repeat("x", 5000));
$du = (int)strtok(trim((string)shell_exec("du -sb " . escapeshellarg("$A/sized"))), "\t ");
check("size: a missing folder is -1", appdataCleanupNgFolderSizeBytes("$A/gone") === -1);
check("size: a file is -1", appdataCleanupNgFolderSizeBytes("$A/sized/blob") === -1);
$bytes = appdataCleanupNgFolderSizeBytes("$A/sized");
check("size: du's figure", $bytes === $du && $bytes >= 5000, "$bytes vs $du");
$mtime = filemtime("$A/sized");
check("size: cached in a private file", is_file($cache) && (fileperms($cache) & 0777) === 0600 && json_decode(file_get_contents($cache), true) === array("$A/sized" => array($mtime, $du)), @file_get_contents($cache));
file_put_contents($cache, json_encode(array("$A/sized" => array($mtime, 123))));
check("size: the cached figure is used while the mtime matches", appdataCleanupNgFolderSizeBytes("$A/sized") === 123);
touch("$A/sized", $mtime + 60);
check("size: a changed mtime measures again", appdataCleanupNgFolderSizeBytes("$A/sized") === $du);
check("size: and caches the new figure", json_decode(file_get_contents($cache), true)["$A/sized"] === array($mtime + 60, $du), file_get_contents($cache));
symlink("$A/sized", "$A/sizedlink");
appdataCleanupNgFolderSizeBytes("$A/sizedlink");
check("size: keyed by the real path", array_keys(json_decode(file_get_contents($cache), true)) === array("$A/sized"), file_get_contents($cache));
unlink($cache);
file_put_contents("/tmp/victim.json", json_encode(array("$A/sized" => array($mtime + 60, 999))));
symlink("/tmp/victim.json", $cache);
check("size: a planted link is not read", appdataCleanupNgFolderSizeBytes("$A/sized") === $du);
check("size: nor written through", json_decode(file_get_contents("/tmp/victim.json"), true)["$A/sized"][1] === 999 && ! is_link($cache) && is_file($cache));
check("size: no temp file left", glob("$cacheDir/*.tmp") === array());
wipe($cacheDir);
mkdir($cacheDir, 0700);
mkdir($cache);
check("size: a cache that can't be replaced leaves no temp file", appdataCleanupNgFolderSizeBytes("$A/sized") === $du && glob("$cacheDir/*.tmp") === array(), json_encode(glob("$cacheDir/*")));
wipe($cacheDir);
mkdir($cacheDir, 0755);
check("size: still measured when the cache folder isn't private", appdataCleanupNgFolderSizeBytes("$A/sized") === $du);
check("size: but nothing cached there", ! file_exists($cache));


// Two scans at once, each measuring a different folder with a slowed du, in both orders: the cache keeps both
@mkdir("/tmp/acng-slow", 0755);
file_put_contents("/tmp/acng-slow/du", "#!/bin/sh\nsleep 1\nexec " . trim((string)shell_exec("command -v du")) . " \"\$@\"\n");
chmod("/tmp/acng-slow/du", 0755);
put("$A/racea/f", "a"); put("$A/raceb/f", "bb");
foreach ( array(array("racea", "raceb"), array("raceb", "racea")) as $order ) {
  wipe($cacheDir);
  $procs = array();
  foreach ( $order as $i => $folder ) {
    $code = "require " . var_export($helpers, true) . "; usleep(" . ($i * 200000) . "); appdataCleanupNgFolderSizeBytes(" . var_export("$A/$folder", true) . ");";
    $procs[] = proc_open(array("env", "PATH=/tmp/acng-slow:" . getenv("PATH"), PHP_BINARY, "-r", $code), array(), $pipes);
  }
  foreach ( $procs as $proc ) proc_close($proc);
  $keys = array_keys((array)json_decode((string)@file_get_contents($cache), true));
  sort($keys);
  check("size: two scans at once (" . implode(" then ", $order) . ") both stay cached", $keys === array("$A/racea", "$A/raceb"), json_encode($keys));
}

if ( $fail ) exit(1);
echo "stale template and folder size tests passed\n";
