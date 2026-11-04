<?php
// Compose reads the stacks: real `docker compose config`, through the real helpers, on the real paths they read.
// It writes /boot/config and /mnt/user/appdata, so it runs only in a throwaway container, given a compose binary:
// docker run ... -e ACNG_TEST_COMPOSE=<path to docker-compose> php:8.2-cli php .github/scripts/compose-config.test.php
if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes /boot/config and /mnt/user/appdata\n"); exit(2); }
$compose = realpath((string)getenv("ACNG_TEST_COMPOSE"));
if ( $compose === false ) { fwrite(STDERR, "ACNG_TEST_COMPOSE must name a docker compose binary\n"); exit(2); }
$helpers = $argv[1] ?? __DIR__ . "/../../source/appdata.cleanup.ng/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php";
$helpers = realpath($helpers);
@mkdir("/opt/acng-compose", 0755, true);
@copy($compose, "/opt/acng-compose/docker-compose");
chmod("/opt/acng-compose/docker-compose", 0755);
$basePath = getenv("PATH");
putenv("PATH=/opt/acng-compose:$basePath");
require $helpers;

$fail = 0;
function check($name, $ok, $detail = "") {
  global $fail;
  if ( ! $ok ) { $fail++; echo "FAIL: $name $detail\n"; }
}
function put($path, $body) { @mkdir(dirname($path), 0755, true); file_put_contents($path, $body); }
function wipe($dir) { if ( is_dir($dir) || is_link($dir) ) exec("rm -rf " . escapeshellarg($dir)); }
function fresh() {
  wipe("/boot/config/plugins/compose.manager"); wipe("/mnt/user/appdata"); wipe("/mnt/user/settings");
  @mkdir("/boot/config/plugins/compose.manager/projects/p", 0755, true);
  @mkdir("/mnt/user/appdata", 0755, true);
}
$P = "/boot/config/plugins/compose.manager/projects/p";
$A = "/mnt/user/appdata";

check("the real compose CLI is found", appdataCleanupNgComposeCommand() !== array());

// Everything compose resolves that a pattern can't: include, extends, anchors, relative paths, named-volume devices,
// env files, build contexts, secrets and configs. The stack's compose file sits at the appdata root.
fresh();
put("$P/indirect", $A);
put("$A/docker-compose.yml", <<<'YAML'
include:
  - inc/inc.yml
x-common: &common
  volumes:
    - /mnt/user/appdata/anchored:/anch
services:
  anch:
    <<: *common
    image: alpine
  app:
    image: alpine
    env_file:
      - ./envdir/app.env
    build:
      context: /mnt/user/appdata/buildctx
    volumes:
      - ./relapp:/rel
      - type: bind
        source: /mnt/cache/appdata/longbind
        target: /lb
      - named:/data
    secrets:
      - s1
    configs:
      - c1
  ext:
    extends:
      file: basedir/base.yml
      service: basesvc
volumes:
  named:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /mnt/user/appdata/device
secrets:
  s1:
    file: /mnt/user/appdata/secrets/s1.txt
configs:
  c1:
    file: ./confdir/c1.conf
YAML);
put("$A/inc/inc.yml", "services:\n  inc:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/included:/i\n");
put("$A/basedir/base.yml", "services:\n  basesvc:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/extended:/e\n");
put("$A/envdir/app.env", "A=1\n");
put("$A/confdir/c1.conf", "x\n");
put("$A/secrets/s1.txt", "x\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("a stack compose reads in full: not uncertain", $uncertain === false, json_encode($protected));
foreach ( array("anchored", "buildctx", "relapp", "longbind", "device", "secrets", "confdir", "envdir", "included", "extended", "inc", "basedir") as $folder ) {
  check("a stack compose reads in full: protects $folder", in_array("$A/$folder", $protected, true), json_encode($protected));
}

// A variable from the envpath file names the bind
fresh();
put("$P/envpath", "/mnt/user/settings/stack.env");
put("/mnt/user/settings/stack.env", "APPDIR=/mnt/user/appdata/fromenv\n");
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    volumes:\n      - \${APPDIR}:/config\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("envpath: protects the folder it names", $uncertain === false && in_array("$A/fromenv", $protected, true), json_encode(array($uncertain, $protected)));

// Compose can't read it: fail closed
$unreadable = array(
  "an unset variable" => "services:\n  app:\n    image: alpine\n    volumes:\n      - \${NOPE}/x:/config\n",
  "invalid yaml" => "services:\n  app:\n    image: [alpine\n",
  "a remote include" => "include:\n  - https://github.com/docker/awesome-compose.git\nservices:\n  app:\n    image: alpine\n",
);
foreach ( $unreadable as $name => $yaml ) {
  fresh();
  put("$P/docker-compose.yml", $yaml);
  $uncertain = null;
  appdataCleanupNgComposeReferencedPaths($uncertain);
  check("$name: uncertain", $uncertain === true);
}

// A project with no compose file runs nothing
fresh();
$uncertain = null;
check("no compose file: nothing protected", appdataCleanupNgComposeReferencedPaths($uncertain) === array() && $uncertain === false);

// Each case below runs in its own PHP, with its own PATH (the compose CLI is looked up once per process)
$fake = "/opt/acng-fake";
@mkdir($fake, 0755, true);
file_put_contents("$fake/docker-compose", <<<'SH'
#!/bin/sh
[ "$1" = "version" ] && exit 0
json='{"services":{"app":{"volumes":[{"type":"bind","source":"/mnt/user/appdata/fakebind","target":"/c"}]}}}'
case "$FAKE_MODE" in
  old) for a in "$@"; do [ "$a" = "--no-env-resolution" ] && { echo "unknown flag: --no-env-resolution" >&2; exit 1; }; done; echo "$json" ;;
  ancient) echo "unknown flag: --format" >&2; exit 1 ;;
  garbage) echo "not json" ;;
  timeout) exit 124 ;;
  warn) echo 'The "X" variable is not set. Defaulting to a blank string.' >&2; echo "$json" ;;
esac
SH);
chmod("$fake/docker-compose", 0755);
$probe = "/tmp/acng-probe.php";
file_put_contents($probe, '<?php require ' . var_export($helpers, true) . '; $u = null; $p = appdataCleanupNgComposeReferencedPaths($u); echo json_encode(array($u, $p));');
function child($path, $mode = "") {
  $p = proc_open(array(PHP_BINARY, "/tmp/acng-probe.php"), array(1 => array("pipe", "w")), $pipes, null, array("PATH" => $path, "FAKE_MODE" => $mode));
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  proc_close($p);
  return json_decode($out, true);
}
fresh();
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/fallback:/config\n      - /mnt/user/appdata/fakebind:/c\n");
list($u, $p) = child($basePath);
check("no compose CLI: the patterns still read it", $u === false && in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
list($u, $p) = child("$fake:$basePath", "old");
check("older compose: retried without --no-env-resolution", $u === false && in_array("$A/fakebind", $p, true) && ! in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
list($u, $p) = child("$fake:$basePath", "ancient");
check("compose without --format json: the patterns read it", $u === false && in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
foreach ( array("garbage", "timeout", "warn") as $mode ) {
  list($u, $p) = child("$fake:$basePath", $mode);
  check("compose answer '$mode': uncertain", $u === true, json_encode(array($u, $p)));
}

if ( $fail ) exit(1);
echo "compose config tests passed\n";
