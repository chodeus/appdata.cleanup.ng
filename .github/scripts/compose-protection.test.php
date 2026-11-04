<?php
// Compose protection and the ignore list through the real helpers, on the real paths they read.
// It writes /boot/config and /mnt/user/appdata, so it runs only in a throwaway container: docker run ... php:8.2-cli
if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes /boot/config and /mnt/user/appdata\n"); exit(2); }
$helpers = $argv[1] ?? __DIR__ . "/../../source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php";
require $helpers;

$fail = 0;
function check($name, $ok, $detail = "") {
  global $fail;
  if ( ! $ok ) { $fail++; echo "FAIL: $name $detail\n"; }
}
function put($path, $body) { @mkdir(dirname($path), 0755, true); file_put_contents($path, $body); }
function wipe($dir) { if ( is_dir($dir) ) exec("rm -rf " . escapeshellarg($dir)); }

$projects = "/boot/config/plugins/compose.manager/projects";
$appdata = "/mnt/user/appdata";
$bind = function($folder) { return "services:\n  app:\n    image: x\n    volumes:\n      - /mnt/user/appdata/$folder:/config\n"; };

// name => [files to create (path => body), folders that must be protected, expect uncertain]
$cases = array(
  "a plain stack" => array(array("$projects/p/docker-compose.yml" => $bind("plain")), array("plain"), false),
  "a stack folder inside appdata" => array(array(
    "$projects/p/indirect" => "$appdata/home",
    "$appdata/home/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - data:/data\nvolumes:\n  data: {}\n",
  ), array("home"), false),
  "indirect naming a compose file" => array(array(
    "$projects/p/indirect" => "$appdata/filemode/prod.yml", "$projects/p/indirect_mode" => "file",
    "$appdata/filemode/prod.yml" => $bind("fileapp"),
  ), array("fileapp", "filemode"), false),
  "indirect naming a file, no mode" => array(array(
    "$projects/p/indirect" => "$appdata/filemode/prod.yml", "$appdata/filemode/prod.yml" => $bind("fileapp"),
  ), array("fileapp"), false),
  "a relative bind" => array(array(
    "$projects/p/indirect" => $appdata, "$appdata/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - ./relapp:/config\n    env_file:\n      - .env\n",
  ), array("relapp"), false),
  "extra_compose_files" => array(array(
    "$projects/p/docker-compose.yml" => "services: {}\n", "$projects/p/extra_compose_files" => "# more\nextra.yml\n",
    "$projects/p/extra.yml" => $bind("extra"),
  ), array("extra"), false),
  "COMPOSE_FILE in .env" => array(array(
    "$projects/p/docker-compose.yml" => "services: {}\n", "$projects/p/.env" => "COMPOSE_FILE=docker-compose.yml:more.yml\n",
    "$projects/p/more.yml" => $bind("envapp"),
  ), array("envapp"), false),
  "a variable from envpath" => array(array(
    "$projects/p/envpath" => "/mnt/user/settings/stack.env", "/mnt/user/settings/stack.env" => "APPDIR=/mnt/user/appdata/envvar\n",
    "$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \${APPDIR}:/config\n",
  ), array("envvar"), false),
  "a missing compose file" => array(array("$projects/p/indirect" => "$appdata/gone/prod.yml", "$projects/p/indirect_mode" => "file"), array(), true),
  "a missing compose folder" => array(array("$projects/p/indirect" => "$appdata/gone"), array(), true),
  "an unknown mode" => array(array("$projects/p/indirect" => "$appdata/home", "$projects/p/indirect_mode" => "weird", "$appdata/home/docker-compose.yml" => "services: {}\n"), array(), true),
  "a broken target kept for repair" => array(array("$projects/p/indirect.invalid" => "$appdata/old", "$projects/p/docker-compose.yml" => "services: {}\n"), array(), true),
  "a ../ bind" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - ../elsewhere:/config\n"), array(), true),
  "include" => array(array("$projects/p/docker-compose.yml" => "include:\n  - other.yml\nservices: {}\n"), array(), true),
  "extends" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    extends:\n      file: base.yml\n      service: app\n"), array(), true),
);
foreach ( $cases as $name => $case ) {
  list($files, $folders, $uncertainWanted) = $case;
  wipe("/boot/config/plugins/compose.manager"); wipe($appdata); wipe("/mnt/user/settings");
  @mkdir($projects . "/p", 0755, true);
  @mkdir($appdata . "/home", 0755, true);
  foreach ( $files as $path => $body ) put($path, $body);
  $uncertain = null;
  $protected = appdataCleanupNgComposeReferencedPaths($uncertain);
  check("$name: uncertain", $uncertain === $uncertainWanted, json_encode(array("uncertain" => $uncertain, "protected" => $protected)));
  foreach ( $folders as $folder ) check("$name: protects $folder", in_array("$appdata/$folder", $protected, true), json_encode($protected));
}

// The ignore list
$ignore = appdataCleanupNgIgnoreFile();
wipe(dirname($ignore));
@mkdir($appdata, 0755, true);
check("ignore: add", appdataCleanupNgAddIgnore("$appdata/one") && appdataCleanupNgAddIgnore("$appdata/two"));
check("ignore: both kept", file($ignore, FILE_IGNORE_NEW_LINES) === array("$appdata/one", "$appdata/two"), file_get_contents($ignore));
check("ignore: remove", appdataCleanupNgRemoveIgnore("$appdata/one") && file($ignore, FILE_IGNORE_NEW_LINES) === array("$appdata/two"));
appdataCleanupNgIgnoreList();   // cache it, then change the file behind it
file_put_contents($ignore, "$appdata/two\n$appdata/outside-edit\n");
$before = fileinode($ignore);
appdataCleanupNgAddIgnore("$appdata/three");
check("ignore: a change reads the list fresh", file($ignore, FILE_IGNORE_NEW_LINES) === array("$appdata/two", "$appdata/outside-edit", "$appdata/three"), file_get_contents($ignore));
clearstatcache();
check("ignore: written whole, by rename", fileinode($ignore) !== $before && glob($ignore . ".tmp*") === array());
$lock = fopen(dirname($ignore) . "/ignore.lock", "c");
flock($lock, LOCK_EX);
$writer = proc_open(array(PHP_BINARY, "-r", "require " . var_export($helpers, true) . "; appdataCleanupNgAddIgnore('$appdata/four');"), array(), $pipes);
usleep(400000);
check("ignore: a second writer waits for the lock", strpos(file_get_contents($ignore), "four") === false);
flock($lock, LOCK_UN);
proc_close($writer);
check("ignore: and then writes", strpos(file_get_contents($ignore), "$appdata/four") !== false, file_get_contents($ignore));

if ( $fail ) exit(1);
echo "compose protection and ignore list tests passed (" . count($cases) . " compose cases)\n";
