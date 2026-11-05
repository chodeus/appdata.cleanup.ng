<?php
// Compose reads the stacks: real `docker compose config`, through the real helpers, on the real paths they read.
// It writes /boot/config and /mnt/user/appdata, so it runs only in a throwaway container, given a compose binary:
// docker run ... -e ACNG_TEST_COMPOSE=<path to docker-compose> php:8.2-cli php .github/scripts/compose-config.test.php
if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes /boot/config and /mnt/user/appdata\n"); exit(2); }
$compose = (string)getenv("ACNG_TEST_COMPOSE");
if ( ! is_file($compose) ) { fwrite(STDERR, "ACNG_TEST_COMPOSE must name a docker compose binary\n"); exit(2); }
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

// More that only compose resolves: a profiled service, an appdata path in an environment value behind a /mnt/user
// bind, an include named through a variable, an include's own env_file, and build.additional_contexts
fresh();
put("$P/.env", "INCDIR=incv\n");
put("$P/docker-compose.yml", <<<'YAML'
include:
  - path: ${INCDIR}/inc.yml
  - path: incfile/other.yml
    env_file: ../incenv/inc.env
services:
  web:
    image: alpine
    volumes:
      - /mnt/user:/mnt/user
    environment:
      CONFIG_DIR: /mnt/user/appdata/viaenv
    build:
      context: .
      additional_contexts:
        assets: /mnt/user/appdata/extractx
  dbg:
    image: alpine
    profiles: ["debug"]
    volumes:
      - /mnt/user/appdata/profiled:/p
YAML);
put("$P/incv/inc.yml", "services:\n  i1:\n    image: alpine\n");
put("$P/incfile/other.yml", "services:\n  i2:\n    image: alpine\n");
put("/boot/config/plugins/compose.manager/projects/incenv/inc.env", "Z=1\n");
@mkdir("$A/extractx", 0755, true);
$uncertain = null; $rootBound = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain, $rootBound);
check("more compose resolves: not uncertain", $uncertain === false, json_encode($protected));
foreach ( array("profiled", "viaenv", "extractx") as $folder ) check("more compose resolves: protects $folder", in_array("$A/$folder", $protected, true), json_encode($protected));
check("a stack binding /mnt/user covers the appdata root", $rootBound === true);

// The same stack's include and env_file sit in appdata
fresh();
put("$P/indirect", "$A/stackhome");
put("$A/stackhome/docker-compose.yml", "include:\n  - path: \${INCDIR}/inc.yml\n  - path: ../incfile2/other.yml\n    env_file: ../incenv2/inc.env\nservices:\n  w:\n    image: alpine\n");
put("$A/stackhome/.env", "INCDIR=../incv2\n");
put("$A/incv2/inc.yml", "services:\n  i1:\n    image: alpine\n");
put("$A/incfile2/other.yml", "services:\n  i2:\n    image: alpine\n");
put("$A/incenv2/inc.env", "Z=1\n");
$uncertain = null; $rootBound = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain, $rootBound);
foreach ( array("incv2", "incfile2", "incenv2") as $folder ) check("include files in appdata: protects $folder", in_array("$A/$folder", $protected, true), json_encode(array($uncertain, $protected)));
check("a stack without a whole-root bind leaves the scan on", $rootBound === false);

// An include inside an include, and an env file whose path has a space (the /mnt scan stops at the space)
fresh();
put("$P/docker-compose.yml", "include:\n  - /mnt/user/appdata/inca/a.yml\nservices:\n  w:\n    image: alpine\n    env_file:\n      - /mnt/user/appdata/env dir/app.env\n");
put("$A/inca/a.yml", "include:\n  - ../incb/b.yml\nservices:\n  a:\n    image: alpine\n");
put("$A/incb/b.yml", "services:\n  b:\n    image: alpine\n");
put("$A/env dir/app.env", "Z=1\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
foreach ( array("inca", "incb", "env dir") as $folder ) check("nested include and spaced env file: protects $folder", $uncertain === false && in_array("$A/$folder", $protected, true), json_encode(array($uncertain, $protected)));

// An unset variable outside any path doesn't block deletes, and the stack's binds stay protected
fresh();
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    environment:\n      TZ: \${TZ}\n    volumes:\n      - /mnt/user/appdata/tzapp:/config\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("an unset TZ: not uncertain, bind protected", $uncertain === false && in_array("$A/tzapp", $protected, true), json_encode(array($uncertain, $protected)));

// When compose can't read a stack, the patterns still protect the binds they can see
fresh();
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/stillsafe:/config\n    labels: [unclosed\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("compose fails: uncertain, and the visible bind still protected", $uncertain === true && in_array("$A/stillsafe", $protected, true), json_encode(array($uncertain, $protected)));

// A stack binding / (a monitoring stack) covers every root; a URL holding an unset variable is not a path;
// a service may be named target
fresh();
put("$P/docker-compose.yml", "services:\n  mon:\n    image: alpine\n    volumes:\n      - /:/host:ro\n  target:\n    image: alpine\n    environment:\n      DATA: /mnt/user/appdata/targetsvc\n      DB_URL: postgres://app:\${DB_PASS}@db/app\n");
$uncertain = null; $rootBound = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain, $rootBound);
check("a stack binding /: covers the appdata root", $rootBound === true);
check("an unset variable in a URL: not uncertain", $uncertain === false, json_encode($protected));
fresh();
put("$P/docker-compose.yml", "services:\n  target:\n    image: alpine\n    environment:\n      DATA: /mnt/user/appdata/targetsvc\n");
$uncertain = null;
$protected = appdataCleanupNgComposeReferencedPaths($uncertain);
check("a service named target: its path protected", $uncertain === false && in_array("$A/targetsvc", $protected, true), json_encode(array($uncertain, $protected)));

// Compose can't read it: fail closed
$unreadable = array(
  "an unset variable" => "services:\n  app:\n    image: alpine\n    volumes:\n      - \${NOPE}/x:/config\n",
  "an unset variable inside a path" => "services:\n  app:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/\${NOPE}:/config\n",
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
[ "$1" = "version" ] && { [ "$FAKE_MODE" = v1 ] && echo "docker-compose version 1.29.2, build 5becea4c"; exit 0; }
json='{"services":{"app":{"volumes":[{"type":"bind","source":"/mnt/user/appdata/fakebind","target":"/c"}]}}}'
case "$FAKE_MODE" in
  old) for a in "$@"; do [ "$a" = "--no-env-resolution" ] && { echo "unknown flag: --no-env-resolution" >&2; exit 1; }; done; echo "$json" ;;
  ancient) echo "unknown flag: --format" >&2; exit 1 ;;
  v1) echo "Usage: config [options]" >&2; exit 1 ;;
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
file_put_contents("$P/docker-compose.yml", "services:\n  mon:\n    image: alpine\n    volumes:\n      - /:/host:ro\n");
file_put_contents("/tmp/acng-probe-root.php", '<?php require ' . var_export($helpers, true) . '; $u = null; $r = null; appdataCleanupNgComposeReferencedPaths($u, $r); echo json_encode($r);');
$rp = proc_open(array(PHP_BINARY, "/tmp/acng-probe-root.php"), array(1 => array("pipe", "w")), $pipes, null, array("PATH" => $basePath));
$rootOut = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($rp);
check("no compose CLI: a stack binding / covers the appdata root", $rootOut === "true", $rootOut);
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/fallback:/config\n      - /mnt/user/appdata/fakebind:/c\n");
list($u, $p) = child($basePath);
check("no compose CLI: the patterns still read it", $u === false && in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
list($u, $p) = child("$fake:$basePath", "old");
check("older compose: retried without --no-env-resolution", $u === false && in_array("$A/fakebind", $p, true) && ! in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
// A real compose without --no-env-resolution drops env_file, as every compose before v5 does: the named paths find them
$oldReal = "/opt/acng-old";
@mkdir($oldReal, 0755, true);
file_put_contents("$oldReal/docker-compose", "#!/bin/sh\nfor a in \"\$@\"; do [ \"\$a\" = \"--no-env-resolution\" ] && { echo 'unknown flag: --no-env-resolution' >&2; exit 1; }; done\nexec /opt/acng-compose/docker-compose \"\$@\"\n");
chmod("$oldReal/docker-compose", 0755);
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    env_file:\n      - /mnt/user/appdata/oldenv/app.env\n");
put("$A/oldenv/app.env", "A=1\n");
list($u, $p) = child("$oldReal:$basePath");
check("older compose and an env_file: the named path protects it", $u === false && in_array("$A/oldenv", $p, true), json_encode(array($u, $p)));
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    env_file:\n      - /mnt/user/appdata/old env/app.env # spaced\n");
put("$A/old env/app.env", "A=1\n");
list($u, $p) = child("$oldReal:$basePath");
check("older compose and an unquoted env file path with a space: protected", $u === false && in_array("$A/old env", $p, true), json_encode(array($u, $p)));
put("$P/docker-compose.yml", "include:\n  - inc.compose\nservices:\n  app:\n    image: alpine\n");
put("$P/inc.compose", "services:\n  i:\n    image: alpine\n    env_file:\n      - /mnt/user/appdata/hiddenenv/x.env\n");
put("$A/hiddenenv/x.env", "A=1\n");
list($u, $p) = child("$oldReal:$basePath");
check("older compose and an include of any name: its env file protected", $u === false && in_array("$A/hiddenenv", $p, true), json_encode(array($u, $p)));
put("$P/docker-compose.yml", "services:\n  app:\n    image: alpine\n    volumes:\n      - /mnt/user/appdata/fallback:/config\n      - /mnt/user/appdata/fakebind:/c\n");
list($u, $p) = child("$fake:$basePath", "ancient");
check("compose without --format json: the patterns read it", $u === false && in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
list($u, $p) = child("$fake:$basePath", "v1");
check("compose v1: the patterns read it", $u === false && in_array("$A/fallback", $p, true), json_encode(array($u, $p)));
list($u, $p) = child("$fake:$basePath", "warn");
check("compose warns of an unset variable outside any path: still read", $u === false && in_array("$A/fakebind", $p, true), json_encode(array($u, $p)));
foreach ( array("garbage", "timeout") as $mode ) {
  list($u, $p) = child("$fake:$basePath", $mode);
  check("compose answer '$mode': uncertain", $u === true, json_encode(array($u, $p)));
}

if ( $fail ) exit(1);
echo "compose config tests passed\n";
