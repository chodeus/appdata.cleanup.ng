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
  "a bind on another pool" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - /mnt/nvme/appdata/poolapp:/config\n"), array("poolapp"), false),
  "a bind on an array disk" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - /mnt/disk1/appdata/diskapp:/config\n"), array("diskapp"), false),
  "a long-syntax bind led by source" => array(array(
    "$projects/p/indirect" => $appdata, "$appdata/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - source: ./longapp\n        target: /config\n        type: bind\n",
  ), array("longapp"), false),
  "the env file's folder" => array(array(
    "$projects/p/envpath" => "$appdata/secrets/stack.env", "$appdata/secrets/stack.env" => "TZ=UTC\n", "$projects/p/docker-compose.yml" => "services: {}\n",
  ), array("secrets"), false),
  "an extra compose file's folder" => array(array(
    "$projects/p/docker-compose.yml" => "services: {}\n", "$projects/p/extra_compose_files" => "$appdata/shared/extra.yml\n", "$appdata/shared/extra.yml" => "services: {}\n",
  ), array("shared"), false),
  "an env_file above the folder" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    env_file:\n      - ../shared.env\n    volumes:\n      - /mnt/user/appdata/envf:/config\n"), array("envf"), false),
  "a commented pool bind" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - type: bind\n        source: /mnt/nvme/appdata/poolc   # main data\n        target: /config\n"), array("poolc"), false),
  "a commented relative bind" => array(array(
    "$projects/p/indirect" => $appdata, "$appdata/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - type: bind\n        source: ./relc   # config\n        target: /config\n",
  ), array("relc"), false),
  "a bind through /mnt/user0" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - /mnt/user0/appdata/arrapp:/config\n"), array("arrapp"), false),
  "a one-line volume list" => array(array(
    "$projects/p/indirect" => $appdata, "$appdata/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes: [\"./flowapp:/config\", \"./second:/x\"]\n",
  ), array("flowapp", "second"), false),
  "an apostrophe in a quoted path" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \"/mnt/nvme/appdata/Bob's app:/config\"\n"), array("Bob's app"), false),
  "a quoted path with a hash" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \"/mnt/nvme/appdata/Plex #2:/config\"   # second server\n"), array("Plex #2"), false),
  "Windows line endings" => array(array(
    "$projects/p/indirect" => $appdata, "$appdata/docker-compose.yml" => "services:\r\n  app:\r\n    image: x\r\n    volumes:\r\n      - type: bind\r\n        source: ./crlf\r\n        target: /config\r\n      - /mnt/nvme/appdata/crlf2:/x\r\n",
  ), array("crlf", "crlf2"), false),
  "a one-line list bind on another pool" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes: [\"/mnt/nvme/appdata/flowabs:/config\", /mnt/nvme/appdata/flowabs2:/y]\n"), array("flowabs", "flowabs2"), false),
  "a one-line map bind on another pool" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - {type: bind, source: /mnt/nvme/appdata/flowmap, target: /x}\n"), array("flowmap"), false),
  "an unset variable with :? in a short bind" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \${NOPE:?set it}/x:/config\n"), array(), true),
  "a :? variable that is set" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \${SETV:?missing}/qset:/config\n", "$projects/p/.env" => "SETV=/mnt/user/appdata\n"), array("qset"), false),
  "a :+ alternative" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - \${SETV:+/mnt/nvme/appdata/plusalt}:/config\n", "$projects/p/.env" => "SETV=1\n"), array("plusalt"), false),
  "an unset variable in a one-line list" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes: [\"\${NOPE}/flowvar:/config\"]\n"), array(), true),
  "an unset variable in a one-line map" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - {type: bind, source: \${NOPE}/x, target: /x}\n"), array(), true),
  "an unset variable led by - source" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - source: \${APPDIR}\n        target: /config\n        type: bind\n"), array(), true),
  "a missing compose file" => array(array("$projects/p/indirect" => "$appdata/gone/prod.yml", "$projects/p/indirect_mode" => "file"), array(), true),
  "a missing compose folder" => array(array("$projects/p/indirect" => "$appdata/gone"), array(), true),
  "file mode naming a folder" => array(array("$projects/p/indirect" => "$appdata/home", "$projects/p/indirect_mode" => "file", "$appdata/home/docker-compose.yml" => "services: {}\n"), array(), true),
  "an unknown mode" => array(array("$projects/p/indirect" => "$appdata/home", "$projects/p/indirect_mode" => "weird", "$appdata/home/docker-compose.yml" => "services: {}\n"), array(), true),
  "a broken target kept for repair" => array(array("$projects/p/indirect.invalid" => "$appdata/old", "$projects/p/docker-compose.yml" => "services: {}\n"), array(), true),
  "a ../ bind" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    image: x\n    volumes:\n      - ../elsewhere:/config\n"), array(), true),
  "include" => array(array("$projects/p/docker-compose.yml" => "include:\n  - other.yml\nservices: {}\n"), array(), true),
  "extends" => array(array("$projects/p/docker-compose.yml" => "services:\n  app:\n    extends:\n      file: base.yml\n      service: app\n"), array(), true),
);
foreach ( $cases as $name => $case ) {
  list($files, $folders, $uncertainWanted) = $case;
  wipe("/boot/config/plugins/compose.manager"); wipe($appdata); wipe("/mnt/user/settings"); wipe("/mnt/user/stacks");
  @mkdir($projects . "/p", 0755, true);
  @mkdir($appdata . "/home", 0755, true);
  foreach ( $files as $path => $body ) put($path, $body);
  $uncertain = null;
  $protected = appdataCleanupNgComposeReferencedPaths($uncertain);
  foreach ( $protected as $path ) check("$name: protects a folder, not a file: $path", ! is_file($path));
  check("$name: uncertain", $uncertain === $uncertainWanted, json_encode(array("uncertain" => $uncertain, "protected" => $protected)));
  foreach ( $folders as $folder ) check("$name: protects $folder", in_array("$appdata/$folder", $protected, true), json_encode($protected));
}

// A compose source reached through a symlink protects the real folder, and its relative binds
wipe("/boot/config/plugins/compose.manager"); wipe($appdata); wipe("/mnt/user/stacks");
@mkdir("$projects/p", 0755, true);
put("$appdata/linked/docker-compose.yml", "services:\n  app:\n    image: x\n    volumes:\n      - ./config:/config\n");
@mkdir("/mnt/user/stacks", 0755, true);
symlink("$appdata/linked", "/mnt/user/stacks/linked");
put("$projects/p/indirect", "/mnt/user/stacks/linked");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("a symlinked compose source", $uncertain === false && in_array("$appdata/linked", $protected, true), json_encode(array($uncertain, $protected)));

// A relative envpath is relative to the project folder, wherever PHP happens to run
wipe("/boot/config/plugins/compose.manager"); wipe("/tmp/acng-cwd");
put("/tmp/acng-cwd/stack.env", "APPDIR=/mnt/user/appdata/wrong\n");
put("$projects/p/stack.env", "APPDIR=/mnt/user/appdata/right\n");
put("$projects/p/envpath", "stack.env");
put("$projects/p/docker-compose.yml", "services:\n  app:\n    image: x\n    volumes:\n      - \${APPDIR}:/config\n");
$cwd = getcwd(); chdir("/tmp/acng-cwd");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
chdir($cwd);
check("a relative envpath", in_array("$appdata/right", $protected, true) && ! in_array("$appdata/wrong", $protected, true), json_encode($protected));

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

// A list that is there but can't be read is never replaced: a nobody process changes a root-only list in a world-writable folder
file_put_contents($ignore, "$appdata/keepme\n");
chmod($ignore, 0600); chmod(dirname($ignore), 0777); chmod(dirname($ignore) . "/ignore.lock", 0666);
$child = proc_open(array(PHP_BINARY, "-r", "posix_setgid(65534); posix_setuid(65534); require " . var_export($helpers, true) . "; var_export(appdataCleanupNgAddIgnore('$appdata/new'));"), array(1 => array("pipe", "w")), $pipes);
$said = stream_get_contents($pipes[1]);
proc_close($child);
clearstatcache();
check("ignore: an unreadable list is left as it was", $said === "false" && file_get_contents($ignore) === "$appdata/keepme\n", $said . " " . json_encode(file_get_contents($ignore)));
chmod(dirname($ignore), 0755);

if ( $fail ) exit(1);
echo "compose protection and ignore list tests passed (" . count($cases) . " compose cases)\n";
