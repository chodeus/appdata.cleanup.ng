<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2024, Andrew Zawadzki #
#                                                             #
###############################################################

####################################################################################################
#                                                                                                  #
# 2 Functions because unRaid includes comments in .cfg files starting with # in violation of PHP 7 #
#                                                                                                  #
####################################################################################################

if ( ! function_exists("my_parse_ini_file") ) {
  function my_parse_ini_file($file,$mode=false,$scanner_mode=INI_SCANNER_NORMAL) {
    return parse_ini_string(preg_replace('/^#.*(?:\\n|$)/m', "", @file_get_contents($file)),$mode,$scanner_mode);
  }
}
if ( ! function_exists("my_parse_ini_string") ) {
  function my_parse_ini_string($string, $mode=false,$scanner_mode=INI_SCANNER_NORMAL) {
    return parse_ini_string(preg_replace('/^#.*(?:\\n|$)/m', "", $string),$mode,$scanner_mode);
  }
}

##############################################################
#                                                            #
# Searches an array of docker mappings (host:container path) #
# for a container mapping of /config and returns the host    #
# path                                                       #
#                                                            #
##############################################################

function findAppdata($volumes) {
  $path = false;
  if ( is_array($volumes) ) {
    foreach ($volumes as $volume) {
      $temp = explode(":",(string)$volume);
      if ( count($temp) < 2 ) continue;
      # a /config mount, or any host path inside the appdata share on ANY pool/disk
      # (container targets are case-sensitive, so the target is passed through unchanged)
      if ( appdataCleanupNgIsConfigTarget($temp[1]) || appdataCleanupNgPathWithinAppdata($temp[0]) ) {
        $path = $temp[0];
        break;
      }
    }
  }
  return $path;
}

# getDockerContainers() returns [] for both "no containers" and a failed list, so probe the
# list endpoint itself: an empty in-use set is only trustworthy when the request succeeded.
function appdataCleanupNgContainerListTrustworthy($dc) {
  # unverifiable means untrusted: an empty list would otherwise look like "no containers"
  if ( ! is_object($dc) || ! method_exists($dc,"getDockerJSON") ) return false;
  # the by-ref flag is true on success and an error STRING on failure, so compare strictly
  $ok = null; $list = @$dc->getDockerJSON("/containers/json?all=1","GET",$ok);
  return ( $ok === true && is_array($list) );
}

# One definition of path containment; callers state the direction.
# true when $inner is $outer itself or sits underneath it.
function appdataCleanupNgPathUnder($inner,$outer) {
  return ( $inner === $outer || strpos($inner."/",$outer."/") === 0 );
}

# true when $path is at or below any key of $roots
function appdataCleanupNgCoveredBy($path,$roots) {
  foreach ( $roots as $r => $unused ) if ( appdataCleanupNgPathUnder($path,$r) ) return true;
  return false;
}

# Canonical forms of a path as written and as resolved, so a symlink and its target compare equal.
function appdataCleanupNgPathViews($path) {
  $views = array();
  $rp = @realpath((string)$path);
  foreach ( array((string)$path, $rp === false ? "" : $rp) as $p ) {
    $c = appdataCleanupNgCanon($p);
    if ( $c !== "" && $c !== "/" ) $views[$c] = true;
  }
  return array_keys($views);
}

# Canonical host path -> true for every path any container currently mounts, links resolved.
function appdataCleanupNgInUsePaths($containers) {
  $inUse = array();
  foreach ( (array)$containers as $ct ) {
    if ( empty($ct['Volumes']) || ! is_array($ct['Volumes']) ) continue;
    foreach ( $ct['Volumes'] as $volume ) {
      $host = explode(":",(string)$volume);
      foreach ( appdataCleanupNgPathViews($host[0]) as $c ) $inUse[$c] = true;
    }
  }
  return $inUse;
}

# one definition of "unsafe for a path": control characters including DEL
function appdataCleanupNgHasControlChars($s) {
  return (bool)preg_match('/[\x00-\x1f\x7f]/',(string)$s);
}

# "/config" or "/config/..." only: a bare prefix test also matches /config2 and /config-backup
function appdataCleanupNgIsConfigTarget($target) {
  # allowlist /config[/...] after rejecting control characters and traversal on the raw value
  $raw = (string)$target;
  if ( appdataCleanupNgHasControlChars($raw) || preg_match('#(^|/)\.\.?(/|$)#',$raw) ) return false;
  $t = rtrim(trim($raw),"/");
  return (bool)preg_match('#^/config(/|$)#',$t);
}

# appdata share root(s) from docker.cfg; deletions are confined within these as a backstop against a crafted request escaping appdata
function appdataCleanupNgAppdataRoots() {
  $dockerOptions = @my_parse_ini_file("/boot/config/docker.cfg");
  $raw = isset($dockerOptions['DOCKER_APP_CONFIG_PATH']) ? (string)$dockerOptions['DOCKER_APP_CONFIG_PATH'] : "/mnt/user/appdata/";
  # check the RAW value: trim() strips NUL and other control bytes before we could see them
  $bad = appdataCleanupNgHasControlChars($raw);
  $cfgPath = rtrim(preg_replace('#/+#','/',trim($raw)),"/");
  # the value must be an absolute /mnt/<pool>/<path> with no traversal, else use the default
  if ( $bad || preg_match('#(^|/)\.\.?(/|$)#',$cfgPath) || ! preg_match('#^/mnt/([^/]+)/(.+)$#',$cfgPath,$m) ) {
    $cfgPath = "/mnt/user/appdata";
    preg_match('#^/mnt/([^/]+)/(.+)$#',$cfgPath,$m);
  }
  # aliases swap the POOL component only; splicing basename() would point at a different share
  $tail = $m[2];
  $roots = array($cfgPath,"/mnt/user/".$tail,"/mnt/cache/".$tail);
  $roots = array_values(array_unique(array_filter($roots,"strlen")));
  # floor: a confinement root is never "/", "/mnt" or a bare pool root
  $roots = array_values(array_filter($roots,function($r){ return preg_match('#^/mnt/[^/]+/[^/]+#',$r); }));
  return $roots;
}

function appdataCleanupNgPathWithinAppdata($path) {
  # reject control characters and traversal on the raw value: normalization must not get the
  # chance to rewrite a traversal into something that looks like a valid appdata path
  if ( appdataCleanupNgHasControlChars($path) ) return false;
  if ( preg_match('#(^|/)\.\.(/|$)#',(string)$path) ) return false;
  $p = appdataCleanupNgCanon($path);
  if ( $p === "" || $p[0] !== "/" ) return false;
  if ( strpos("/".$p."/","/../") !== false ) return false; # reject traversal
  foreach ( appdataCleanupNgAppdataRoots() as $root ) {
    $r = appdataCleanupNgCanon($root);
    if ( $p === $r ) return false;                          # never the share root itself
    if ( strpos($p."/",$r."/") === 0 ) return true;         # strictly within a root
  }
  return false;
}

function appdataCleanupNgCanon($path) {
  $p = rtrim(preg_replace('#/+#','/',trim((string)$path)),"/");
  # drop "." segments so /mnt/user/./appdata compares equal to /mnt/user/appdata.
  # ".." is deliberately NOT resolved here: traversal is rejected by the callers instead.
  while ( strpos($p,"/./") !== false ) $p = str_replace("/./","/",$p);
  $p = preg_replace('#/\.$#','',$p);
  # collapse any pool/array-disk mount to its /mnt/user view so the same folder compares equal regardless of pool; skip mounts that are genuinely not the user share
  if ( preg_match('#^/mnt/([^/]+)(/.*)?$#',$p,$m) ) {
    $skip = array("user","user0","disks","remotes","rootsharecache","addons",".","..");
    if ( ! in_array($m[1],$skip,true) ) {
      $p = "/mnt/user".(isset($m[2]) ? $m[2] : "");
    }
  }
  return $p;
}

function appdataCleanupNgOwnerSegment($path) {
  $folder = appdataCleanupNgAppdataFolderOf($path);
  return $folder === "" ? "" : basename($folder);
}

# the appdata folder (<root>/<first segment>) a path lies in, canonical; "" when it is outside every root or is a root
function appdataCleanupNgAppdataFolderOf($path) {
  $c = appdataCleanupNgCanon($path);
  foreach ( appdataCleanupNgAppdataRoots() as $root ) {
    $r = appdataCleanupNgCanon($root);
    if ( $r !== "" && strpos($c."/",$r."/") === 0 && strlen($c) > strlen($r) ) {
      $seg = strtok(ltrim(substr($c,strlen($r)),"/"),"/");
      return ( $seg === false || $seg === "" ) ? "" : $r."/".$seg;
    }
  }
  return "";
}

# a folder that is an exact ZFS dataset mountpoint needs `zfs destroy`, not rm -rf (which would empty a still-mounted dataset); a non-dataset mountpoint is refused outright
function appdataCleanupNgZfsAvailable() {
  static $a = null;
  if ( $a === true ) return true;
  $o = array(); $rc = 1;
  @exec("command -v zfs 2>/dev/null",$o,$rc);
  if ( $rc !== 0 ) return false;
  $a = true;
  return true;
}

function appdataCleanupNgZfsDatasetMap() {
  static $map = null;
  if ( $map !== null ) return $map;
  if ( ! appdataCleanupNgZfsAvailable() ) return array();
  $out = array(); $rc = 1;
  @exec("zfs list -H -o name,mountpoint -t filesystem 2>/dev/null",$out,$rc);
  if ( $rc !== 0 ) return array();
  $map = array();
  foreach ( $out as $line ) {
    $parts = preg_split('/\t+/',rtrim($line,"\n"));
    if ( count($parts) < 2 ) continue;
    $name = trim($parts[0]);
    $mp = appdataCleanupNgCanon($parts[1]);
    if ( $name !== "" && $mp !== "" && $mp[0] === "/" ) $map[$mp] = $name;
  }
  return $map;
}

function appdataCleanupNgResolveZfsDataset($path) {
  $map = appdataCleanupNgZfsDatasetMap();
  if ( empty($map) ) return "";
  $variants = array(appdataCleanupNgCanon($path));
  $rp = @realpath($path);
  if ( $rp !== false ) $variants[] = appdataCleanupNgCanon($rp);
  foreach ( $variants as $v ) {
    if ( $v !== "" && isset($map[$v]) ) return $map[$v];
  }
  return "";
}

function appdataCleanupNgIsMountPoint($path) {
  $rp = @realpath($path);
  if ( $rp === false || ! is_dir($rp) || $rp === "/" ) return false;
  $d = @stat($rp); $p = @stat(dirname($rp));
  if ( ! is_array($d) || ! is_array($p) ) return true;   # can't verify the mount boundary -> treat as a mount and refuse (fail closed)
  return ( $d['dev'] !== $p['dev'] );
}

# destroy a dataset, auto-detecting whether -r is required (snapshots/children)
function appdataCleanupNgZfsDestroy($dataset) {
  $ds = trim((string)$dataset);
  if ( $ds === "" ) return array("ok"=>false,"recursive"=>false,"message"=>"missing dataset name");
  $o = array(); $rc = 1;
  @exec("zfs destroy -nvp ".escapeshellarg($ds)." 2>&1",$o,$rc);   # dry-run, non-recursive
  $recursive = false;
  if ( $rc !== 0 ) {
    # only escalate to a recursive wipe when the dataset genuinely has dependents (children/snapshots/clones);
    # any other dry-run failure (busy, permission, pool error) must NOT silently trigger a `-r` subtree destroy
    $err = strtolower(implode("\n",$o));
    if ( strpos($err,"child") !== false || strpos($err,"snapshot") !== false || strpos($err,"dependent") !== false || strpos($err,"clone") !== false ) {
      $recursive = true;
    } else {
      return array("ok"=>false,"recursive"=>false,"message"=>"dry-run failed, not escalating to -r: ".trim(implode("\n",$o)));
    }
  }
  $o = array(); $rc = 1;
  @exec("zfs destroy ".($recursive ? "-r " : "").escapeshellarg($ds)." 2>&1",$o,$rc);
  return array("ok"=>($rc===0),"recursive"=>$recursive,"message"=>trim(implode("\n",$o)));
}

# ignore list: folders the user marks to never offer, persisted on flash keyed by canonical path
function appdataCleanupNgIgnoreFile() {
  return "/boot/config/plugins/appdata.cleanup.ng/ignore.list";
}

function appdataCleanupNgIgnoreList() {
  static $list = null;
  if ( $list === null ) $list = appdataCleanupNgReadIgnoreList();
  return $list;
}

function appdataCleanupNgReadIgnoreList() {
  $list = array();
  $f = appdataCleanupNgIgnoreFile();
  if ( is_file($f) ) {
    foreach ( (array)@file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line ) {
      $c = appdataCleanupNgCanon($line);
      if ( $c !== "" ) $list[$c] = true;
    }
  }
  return $list;
}

function appdataCleanupNgIsIgnored($path) {
  $list = appdataCleanupNgIgnoreList();
  return isset($list[appdataCleanupNgCanon($path)]);
}

# one change at a time, read fresh under the lock, and written whole: a crash leaves the old list or the new one
function appdataCleanupNgUpdateIgnoreList($change) {
  $f = appdataCleanupNgIgnoreFile();
  $dir = dirname($f);
  if ( ! is_dir($dir) ) @mkdir($dir,0755,true);
  $lock = @fopen($dir."/ignore.lock","c");
  if ( $lock === false ) return false;
  if ( ! flock($lock,LOCK_EX) ) { fclose($lock); return false; }
  $list = appdataCleanupNgReadIgnoreList();
  $change($list);
  $body = empty($list) ? "" : implode("\n",array_keys($list))."\n";
  $tmp = $f.".tmp".getmypid();
  $ok = @file_put_contents($tmp,$body) === strlen($body) && @rename($tmp,$f);
  if ( ! $ok ) @unlink($tmp);
  flock($lock,LOCK_UN);
  fclose($lock);
  return $ok;
}

function appdataCleanupNgAddIgnore($path) {
  if ( ! appdataCleanupNgPathWithinAppdata($path) ) return false;
  $c = appdataCleanupNgCanon($path);
  return appdataCleanupNgUpdateIgnoreList(function(&$list) use ($c) { $list[$c] = true; });
}

function appdataCleanupNgRemoveIgnore($path) {
  $c = appdataCleanupNgCanon($path);
  return appdataCleanupNgUpdateIgnoreList(function(&$list) use ($c) { unset($list[$c]); });
}

# opt-in scan: top-level appdata folders not accounted for by any template, container, or compose stack (less conservative than template-only)
function appdataCleanupNgFilesystemOrphans($coveredSegs) {
  $found = array();
  $seen = array();
  foreach ( appdataCleanupNgAppdataRoots() as $root ) {
    if ( ! is_dir($root) ) continue;
    foreach ( (array)@scandir($root) as $e ) {
      if ( $e === "." || $e === ".." || ( isset($e[0]) && $e[0] === "." ) ) continue;
      $path = $root."/".$e;
      if ( ! is_dir($path) ) continue;
      if ( isset($coveredSegs[$e]) ) continue;            # a template/container/compose owns this folder
      $key = appdataCleanupNgCanon($path);
      if ( isset($seen[$key]) ) continue;                 # dedupe cache/user views of the same folder
      $seen[$key] = true;
      $found[$path] = array("Name"=>$e,"HostDir"=>$path,"NoTemplate"=>true);
    }
  }
  return $found;
}

# a "stale" template is a saved Docker template whose container is no longer installed; deleting it removes the saved config only, hard-confined to templates-user/*.xml
function appdataCleanupNgTemplateDir() {
  return "/boot/config/plugins/dockerMan/templates-user";
}

function appdataCleanupNgStaleTemplates($installedNames) {
  if ( ! function_exists("readXmlFile") ) return array();
  $inst = array();
  foreach ( (array)$installedNames as $n ) $inst[strtolower(trim((string)$n))] = true;
  $stale = array();
  foreach ( (array)glob(appdataCleanupNgTemplateDir()."/*.xml") as $f ) {
    $o = @readXmlFile($f);
    if ( ! $o || empty($o['Name']) ) continue;
    $name = (string)$o['Name'];
    if ( isset($inst[strtolower(trim($name))]) ) continue;   # container installed -> not stale
    $seg = ""; $appdata = "";
    if ( isset($o['Config']) && is_array($o['Config']) ) {
      $cfgs = isset($o['Config'][0]) ? $o['Config'] : array($o['Config']);
      foreach ( $cfgs as $v ) {
        if ( ! is_array($v) || ! isset($v['@attributes']) || ( $v['@attributes']['Type'] ?? '' ) !== "Path" ) continue;
        $s = appdataCleanupNgOwnerSegment($v['value'] ?? '');
        if ( $s === "" ) continue;
        if ( appdataCleanupNgIsConfigTarget($v['@attributes']['Target'] ?? '') ) { $seg = $s; $appdata = $v['value']; break; }
        if ( $seg === "" ) { $seg = $s; $appdata = $v['value']; }
      }
    }
    $stale[] = array("name"=>$name,"file"=>$f,"seg"=>$seg,"appdata"=>$appdata);
  }
  return $stale;
}

function appdataCleanupNgDeleteTemplate($file) {
  $real = @realpath(trim((string)$file));
  $dir  = @realpath(appdataCleanupNgTemplateDir());
  if ( $real === false || $dir === false ) return false;
  if ( strpos($real,$dir."/") !== 0 ) return false;    # confined to templates-user
  if ( substr($real,-4) !== ".xml" ) return false;
  return @unlink($real);
}

# log to syslog so messages land in Tools > System Log and the Diagnostics bundle
function appdataCleanupNgLog($message,$priority=LOG_INFO) {
  openlog("appdata.cleanup.ng",LOG_PID,LOG_USER);
  syslog($priority,(string)$message);
  closelog();
}

function appdataCleanupNgBuildDiagnostics() {
  $uv = @parse_ini_file("/etc/unraid-version");
  $plg = @file_get_contents("/boot/config/plugins/appdata.cleanup.ng.plg");
  $ver = ( $plg && preg_match('/<!ENTITY version\s+"([^"]+)"/',$plg,$m) ) ? $m[1] : "unknown";
  $cm = "/boot/config/plugins/compose.manager/projects";
  $prot = appdataCleanupNgComposeReferencedPaths();

  $out = array();
  $out[] = "=== Appdata Cleanup NG diagnostics ===";
  $out[] = "plugin version : ".$ver;
  $out[] = "unraid version : ".(isset($uv['version']) ? $uv['version'] : "?");
  $out[] = "php version    : ".phpversion();
  $out[] = "";
  $out[] = "[appdata]";
  $out[] = "appdata roots  : ".implode(", ",appdataCleanupNgAppdataRoots());
  $out[] = "docker running : ".(is_dir("/var/lib/docker/tmp") ? "yes" : "no");
  $out[] = "templates      : ".count((array)glob("/boot/config/plugins/dockerMan/templates-user/*.xml"));
  $out[] = "";
  $out[] = "[compose]";
  $out[] = "compose manager: ".(is_dir($cm) ? "present" : "not installed");
  $out[] = "protected paths: ".(empty($prot) ? "(none)" : count($prot));
  foreach ( array_slice($prot,0,50) as $p ) $out[] = "  - ".$p;
  $out[] = "";
  $out[] = "[zfs]";
  $out[] = "zfs available  : ".(appdataCleanupNgZfsAvailable() ? "yes" : "no");
  $out[] = "datasets       : ".count(appdataCleanupNgZfsDatasetMap());
  $out[] = "";
  $out[] = "[recent log: appdata.cleanup.ng]";
  $log = array();
  @exec("grep -F 'appdata.cleanup.ng' /var/log/syslog 2>/dev/null | tail -40",$log);
  if ( empty($log) ) {
    $out[] = "  (no recent entries - open the page once to generate a scan line)";
  } else {
    foreach ( $log as $l ) $out[] = "  ".$l;
  }
  return implode("\n",$out)."\n";
}

# Cache lives under /var/lib, whose root-owned 0755 parent no other user can write: the entry
# cannot be pre-created or swapped for a symlink between the checks and the root write.
function appdataCleanupNgSizeCacheFile() {
  $dir = "/var/lib/appdata.cleanup.ng";
  if ( ! file_exists($dir) ) @mkdir($dir,0700);
  clearstatcache(true,$dir);
  # anything unexpected means no cache at all; never chmod a path that failed validation
  if ( ! is_dir($dir) || is_link($dir) || @fileowner($dir) !== 0 || (@fileperms($dir) & 0777) !== 0700 ) return "";
  return $dir."/sizecache.json";
}

# du -sb, cached keyed by mtime so repeat scans don't re-walk unchanged folders
function appdataCleanupNgFolderSizeBytes($path) {
  $real = @realpath($path);
  if ( $real === false || ! is_dir($real) ) return -1;
  $cacheFile = appdataCleanupNgSizeCacheFile();
  $cache = array();
  if ( $cacheFile !== "" && is_file($cacheFile) && ! is_link($cacheFile) ) {
    $decoded = @json_decode(@file_get_contents($cacheFile),true);
    if ( is_array($decoded) ) $cache = $decoded;
  }
  $mtime = @filemtime($real);
  if ( isset($cache[$real]) && is_array($cache[$real]) && (int)$cache[$real][0] === (int)$mtime ) {
    return (int)$cache[$real][1];
  }
  $out = array(); $rc = 1;
  @exec("du -sb ".escapeshellarg($real)." 2>/dev/null",$out,$rc);
  $bytes = ( $rc === 0 && ! empty($out) ) ? (int)strtok(trim($out[0]),"\t ") : -1;
  if ( $bytes >= 0 && $cacheFile !== "" ) {
    $cache[$real] = array((int)$mtime,$bytes);
    # Write a fresh private file and rename over the target: rename replaces a planted
    # symlink instead of writing through it, so there is no check-then-write window.
    $tmp = $cacheFile.".".getmypid().".tmp";
    $json = json_encode($cache);
    $fh = ( $json !== false ) ? @fopen($tmp,"x") : false;
    $ok = ( $fh !== false ) && ( @fwrite($fh,$json) === strlen($json) );
    if ( $fh !== false ) $ok = ( @fclose($fh) && $ok );
    # rename only a complete 0600 file; on any failure the existing cache stays as it was
    if ( ! $ok || ! @chmod($tmp,0600) || ! @rename($tmp,$cacheFile) ) @unlink($tmp);
  }
  return $bytes;
}

function appdataCleanupNgFormatBytes($bytes) {
  if ( $bytes < 0 ) return "";
  $units = array("B","KiB","MiB","GiB","TiB");
  $i = 0; $b = (float)$bytes;
  while ( $b >= 1024 && $i < count($units)-1 ) { $b /= 1024; $i++; }
  return ($i === 0 ? (string)(int)$b : number_format($b,1))." ".$units[$i];
}

# appdata claimed by Compose Manager stacks, incl. 'down' stacks (no container/template); indirect-aware since the real compose file may live outside the project dir
function appdataCleanupNgParseEnvFile($file,&$env) {
  if ( ! is_file($file) ) return;
  foreach ( (array)@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line ) {
    $line = trim($line);
    if ( $line === "" || $line[0] === "#" ) continue;
    if ( strpos($line,"export ") === 0 ) $line = trim(substr($line,7));
    $eq = strpos($line,"=");
    if ( $eq === false ) continue;
    $k = trim(substr($line,0,$eq));
    $v = trim(substr($line,$eq+1));
    if ( strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v,-1) === $v[0] ) $v = substr($v,1,-1);
    if ( $k !== "" && ! isset($env[$k]) ) $env[$k] = $v; # first definition wins
  }
}

function appdataCleanupNgExpandEnv($text,$env) {
  for ( $pass = 0; $pass < 2; $pass++ ) {
    $text = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',function($m) use ($env) {
      $name = ( isset($m[1]) && $m[1] !== "" ) ? $m[1] : ( isset($m[3]) ? $m[3] : "" );
      if ( $name !== "" && isset($env[$name]) && $env[$name] !== "" ) return $env[$name];
      if ( isset($m[2]) && $m[2] !== "" ) return $m[2]; # ${VAR:-default}
      return $m[0];                                     # unresolved: leave as-is
    },$text);
  }
  return $text;
}

# [compose source folder, compose files, env vars, env file] as Compose Manager runs the project (indirect file or folder,
# extra_compose_files, COMPOSE_FILE, envpath); null when its compose location can't be read, so callers fail closed
function appdataCleanupNgComposeProject($proj) {
  if ( file_exists($proj."/indirect.invalid") ) return null;   # a broken target kept for repair
  $source = $proj;
  $files = array();
  if ( file_exists($proj."/indirect") || is_link($proj."/indirect") ) {
    $target = is_file($proj."/indirect") ? rtrim(trim((string)@file_get_contents($proj."/indirect")),"/") : "";
    $mode = is_file($proj."/indirect_mode") ? strtolower(trim((string)@file_get_contents($proj."/indirect_mode"))) : "";
    if ( $target === "" || $target[0] !== "/" || ! in_array($mode,array("","file","folder"),true) ) return null;
    if ( $mode === "file" || is_file($target) ) {
      if ( ! is_file($target) || ! is_readable($target) ) return null;
      $files[] = $target;
      $source = dirname($target);
    } else {
      if ( ! is_dir($target) || ! is_readable($target) ) return null;
      $source = $target;
    }
  }
  # cover modern (compose.yaml) and legacy (docker-compose.yml) names + overrides, in source and project dirs
  foreach ( array("compose","docker-compose","compose.override","docker-compose.override") as $n ) {
    foreach ( array("yaml","yml") as $ext ) $files[] = $source."/".$n.".".$ext;
  }
  foreach ( array("compose.override","docker-compose.override") as $n ) {
    foreach ( array("yaml","yml") as $ext ) $files[] = $proj."/".$n.".".$ext;
  }
  # the one env file compose gets: envpath (absolute, or relative to the project folder), else the default for the stack
  $envFile = "";
  $envPath = is_file($proj."/envpath") ? trim((string)@file_get_contents($proj."/envpath")) : "";
  if ( $envPath !== "" ) {
    $candidate = $envPath[0] === "/" ? $envPath : $proj."/".$envPath;
    if ( is_file($candidate) ) $envFile = $candidate;
  }
  $git = is_file($proj."/git.json");
  $default = ( $git && is_file($proj."/.env") ) ? $proj."/.env" : $source."/.env";
  if ( $envFile === "" && is_file($default) ) $envFile = $default;
  # it wins; the other .env files only resolve what it leaves unset, which can only add protection
  $env = array();
  foreach ( array_unique(array_filter(array($envFile,$source."/.env",$proj."/.env"))) as $e ) appdataCleanupNgParseEnvFile($e,$env);
  # more compose files: COMPOSE_FILE in that env file (relative to it), and extra_compose_files (relative to the source)
  $composeEnv = array();
  if ( $envFile !== "" ) appdataCleanupNgParseEnvFile($envFile,$composeEnv);
  if ( isset($composeEnv['COMPOSE_FILE']) && trim($composeEnv['COMPOSE_FILE']) !== "" ) {
    foreach ( preg_split('/[:;]/',$composeEnv['COMPOSE_FILE']) as $extra ) {
      $extra = trim($extra);
      if ( $extra !== "" ) $files[] = $extra[0] === "/" ? $extra : dirname($envFile)."/".$extra;
    }
  }
  foreach ( (array)( is_file($proj."/extra_compose_files") ? @file($proj."/extra_compose_files",FILE_IGNORE_NEW_LINES) : array() ) as $extra ) {
    $extra = trim($extra);
    if ( $extra !== "" && $extra[0] !== "#" ) $files[] = $extra[0] === "/" ? $extra : $source."/".$extra;
  }
  return array(rtrim($source,"/"),array_values(array_unique($files)),$env,$envFile);
}

# mark the appdata folder a path lies in as protected, through any pool, disk or /mnt/user0 (the array-only view)
function appdataCleanupNgProtectFolderOf(&$protected,$path) {
  $path = preg_replace('#^/mnt/user0(?=/)#','/mnt/user',(string)$path);
  if ( is_file($path) ) $path = dirname($path);
  foreach ( appdataCleanupNgPathViews($path) as $view ) {
    $folder = appdataCleanupNgAppdataFolderOf($view);
    if ( $folder !== "" ) foreach ( appdataCleanupNgPathViews($folder) as $c ) $protected[$c] = true;
  }
}

# how to run the compose CLI that Compose Manager installs; empty when there is none
function appdataCleanupNgComposeCommand() {
  static $cmd = null;
  if ( $cmd !== null ) return $cmd;
  $cmd = array();
  foreach ( array(array("docker","compose"),array("docker-compose")) as $try ) {
    $o = array(); $rc = 1;
    @exec(implode(" ",array_map("escapeshellarg",$try))." version 2>/dev/null",$o,$rc);
    if ( $rc === 0 ) { $cmd = $try; break; }
  }
  return $cmd;
}

function appdataCleanupNgCanUnshareNetwork() {
  static $ok = null;
  if ( $ok === null ) { $o = array(); $rc = 1; @exec("unshare -n true 2>/dev/null",$o,$rc); $ok = $rc === 0; }
  return $ok;
}

# The stack as compose would run it (its decoded `config`): null without a usable compose CLI, false when compose
# can't read it (an error, an unset variable inside a path, no answer in 20 s), so the caller fails closed
function appdataCleanupNgComposeConfig($source,$files,$envFile) {
  $cmd = appdataCleanupNgComposeCommand();
  if ( ! $cmd ) return null;
  $used = array_values(array_filter($files,"is_file"));
  if ( ! $used ) return array();   # no compose file: nothing would run
  $args = array_merge($cmd,array("--project-directory",$source));
  foreach ( $used as $f ) array_push($args,"-f",$f);
  if ( $envFile !== "" ) array_push($args,"--env-file",$envFile);
  # remote include loaders off and, where unshare allows, no network: a scan must never fetch anything.
  # Every profile, so a service that only starts with one is read too.
  $env = array("COMPOSE_EXPERIMENTAL_GIT_REMOTE=false","COMPOSE_EXPERIMENTAL_OCI_REMOTE=false","COMPOSE_PROFILES=*");
  $run = function($flags,$env) use ($args) {
    $line = "env ".implode(" ",array_map("escapeshellarg",$env))." ".( appdataCleanupNgCanUnshareNetwork() ? "unshare -n " : "" )
          . "timeout 20 ".implode(" ",array_map("escapeshellarg",array_merge($args,array("config"),$flags)));
    $errFile = tempnam(sys_get_temp_dir(),"acng-compose");
    $out = array(); $rc = 1;
    @exec($line." 2>".escapeshellarg($errFile),$out,$rc);
    $err = (string)@file_get_contents($errFile);
    @unlink($errFile);
    return array($rc,implode("\n",$out),$err);
  };
  # older compose lacks --no-env-resolution (which keeps env_file paths); without --format json it can't be used at all
  foreach ( array(array("--no-env-resolution","--format","json"),array("--format","json")) as $attempt => $flags ) {
    list($rc,$out,$err) = $run($flags,$env);
    if ( $rc !== 0 && stripos($err,"unknown flag") !== false ) continue;
    if ( $rc !== 0 ) return false;
    # an unset variable is left blank: set each to a marker and ask again; only a marker inside a path is a problem
    $marker = "";
    if ( stripos($err,"is not set") !== false ) {
      # logged as msg="The \"TZ\" variable is not set..." by recent compose, unescaped by older ones
      if ( ! preg_match_all('/\\\\?"([A-Za-z_][A-Za-z0-9_]*)\\\\?" variable is not set/',$err,$unset) ) return false;
      $marker = "acng-unset-".getmypid();
      foreach ( array_unique($unset[1]) as $name ) $env[] = $name."=".$marker;
      list($rc,$out,$err) = $run($flags,$env);
      if ( $rc !== 0 ) return false;
    }
    $config = json_decode($out,true);
    if ( ! is_array($config) ) return false;
    if ( $marker !== "" ) {
      $inPath = false;
      array_walk_recursive($config,function($v) use ($marker,&$inPath) {
        if ( is_string($v) && strpos($v,$marker) !== false && strpos($v,"/") !== false ) $inPath = true;
      });
      if ( $inPath ) return false;
    }
    # without --no-env-resolution compose folds env_file into environment, so those files can't be found
    if ( $attempt === 1 ) foreach ( $used as $f ) if ( stripos((string)@file_get_contents($f),"env_file") !== false ) return false;
    return $config;
  }
  return null;
}

# Host paths in a compose config: 'mounts' (bind sources, named-volume devices), 'files' (env files, build contexts,
# secret and config files) and 'mentions' (any other string naming a /mnt path, such as an environment value)
function appdataCleanupNgComposeConfigPaths($config) {
  $mounts = array(); $files = array(); $mentions = array();
  foreach ( (array)($config['services'] ?? array()) as $service ) {
    if ( ! is_array($service) ) continue;
    foreach ( (array)($service['volumes'] ?? array()) as $v ) if ( is_array($v) && ($v['type'] ?? "") === "bind" ) $mounts[] = $v['source'] ?? "";
    foreach ( (array)($service['env_file'] ?? array()) as $e ) $files[] = is_array($e) ? ($e['path'] ?? "") : $e;
    $build = is_array($service['build'] ?? null) ? $service['build'] : array();
    $files[] = $build['context'] ?? "";
    $files[] = $build['dockerfile'] ?? "";
    foreach ( (array)($build['additional_contexts'] ?? array()) as $c ) $files[] = $c;
  }
  foreach ( (array)($config['volumes'] ?? array()) as $volume ) if ( is_array($volume) ) $mounts[] = $volume['driver_opts']['device'] ?? "";
  foreach ( array("secrets","configs") as $kind ) {
    foreach ( (array)($config[$kind] ?? array()) as $item ) if ( is_array($item) ) $files[] = $item['file'] ?? "";
  }
  # container-side paths (targets) are not host paths
  $walk = function($node) use (&$walk,&$mentions) {
    foreach ( (array)$node as $key => $value ) {
      if ( $key === "target" ) continue;
      if ( is_array($value) ) $walk($value);
      else if ( is_string($value) && preg_match_all('#/mnt/[^\s"\':;,=]+#',$value,$m) ) foreach ( $m[0] as $hit ) $mentions[] = $hit;
    }
  };
  $walk($config);
  $absolute = function($list) { return array_values(array_unique(array_filter(array_map("strval",$list),function($p) { return $p !== "" && $p[0] === "/"; }))); };
  return array("mounts" => $absolute($mounts), "files" => $absolute($files), "mentions" => $absolute($mentions));
}

# Existing paths the compose files name (include and extends targets, an include's env_file, anything else), with
# variables filled in, following any yaml file found. compose's output never lists them; extras only protect more.
function appdataCleanupNgComposeNamedPaths($files,$env) {
  $seen = array();
  $queue = array_values($files);
  $found = array();
  while ( $queue && count($seen) < 100 ) {
    $f = array_shift($queue);
    if ( isset($seen[$f]) ) continue;
    $seen[$f] = true;
    $text = appdataCleanupNgExpandEnv(str_replace("\r","",(string)@file_get_contents($f)),$env);
    preg_match_all('#"([^"\n]+)"|\'([^\'\n]+)\'|([^\s"\'\[\]{},:=\#]+)#',$text,$m,PREG_SET_ORDER);
    foreach ( $m as $hit ) {
      $name = ( $hit[1] ?? "" ) !== "" ? $hit[1] : ( ( $hit[2] ?? "" ) !== "" ? $hit[2] : ( $hit[3] ?? "" ) );
      if ( $name === "" || appdataCleanupNgHasControlChars($name) ) continue;
      $path = $name[0] === "/" ? $name : dirname($f)."/".$name;
      if ( ! file_exists($path) || isset($found[$path]) ) continue;
      $found[$path] = true;
      if ( is_file($path) && preg_match('#\.ya?ml$#',$path) ) $queue[] = $path;
    }
  }
  return array_keys($found);
}

# a path that holds a whole appdata root (a bind of /mnt/user, or of appdata itself): every folder under it is in use
function appdataCleanupNgCoversAppdataRoot($path) {
  foreach ( appdataCleanupNgPathViews(preg_replace('#^/mnt/user0(?=/|$)#','/mnt/user',(string)$path)) as $view ) {
    foreach ( appdataCleanupNgAppdataRoots() as $root ) if ( appdataCleanupNgPathUnder(appdataCleanupNgCanon($root),$view) ) return true;
  }
  return false;
}

function appdataCleanupNgComposeReferencedPaths(&$uncertain = null, &$rootBound = null) {
  $rootBound = false;   # set true when a stack binds a whole appdata root: callers then skip the filesystem scan
  $uncertain = false;   # set true when a compose file can't be read or still has an unresolved ${VAR} bind host -> callers must fail closed
  $projectsDir = "/boot/config/plugins/compose.manager/projects";
  if ( ! is_dir($projectsDir) ) return array();
  $roots = appdataCleanupNgAppdataRoots();
  if ( empty($roots) ) return array();

  # tolerate redundant separators and "." segments in a bind path; a literal root match would
  # miss them and leave the folder silently unprotected
  $sep = "/+(?:\\./+)*";
  $escaped = array();
  foreach ( $roots as $r ) {
    $parts = array_map(function($seg) { return preg_quote($seg,"#"); },explode("/",trim($r,"/")));
    $escaped[] = $sep.implode($sep,$parts);
  }
  $pattern = "#(".implode("|",$escaped).")".$sep."([^\\s:'\"\\\\]+)#";

  $protected = array();
  foreach ( (array)glob($projectsDir."/*",GLOB_ONLYDIR) as $proj ) {
    $project = appdataCleanupNgComposeProject($proj);
    if ( $project === null ) { $uncertain = true; continue; }
    list($base,$files,$env,$envFile) = $project;
    # the stack's own folder, and the folders of its env file and compose files: the stack breaks without them
    $bases = array_unique(array_filter(array($base,(string)@realpath($base))));
    foreach ( array_merge($bases,array_filter(array_merge(array($envFile),$files),"is_file")) as $used ) appdataCleanupNgProtectFolderOf($protected,$used);
    # compose itself reads the stack as it runs it; the patterns below are only for when there is no compose CLI
    $config = appdataCleanupNgComposeConfig($base,$files,$envFile);
    if ( $config === false ) $uncertain = true;   # and the patterns below still protect what they can read
    if ( is_array($config) ) {
      $paths = appdataCleanupNgComposeConfigPaths($config);
      foreach ( array_merge($paths['mounts'],$paths['files'],$paths['mentions']) as $path ) {
        if ( appdataCleanupNgHasControlChars($path) ) { $uncertain = true; continue; }
        appdataCleanupNgProtectFolderOf($protected,$path);
      }
      foreach ( $paths['mounts'] as $path ) if ( appdataCleanupNgCoversAppdataRoot($path) ) $rootBound = true;
      foreach ( appdataCleanupNgComposeNamedPaths(array_filter($files,"is_file"),$env) as $path ) appdataCleanupNgProtectFolderOf($protected,$path);
      continue;
    }
    foreach ( $files as $f ) {
      if ( ! is_file($f) ) continue;
      $contents = @file_get_contents($f);
      if ( $contents === false ) { $uncertain = true; continue; }   # an existing compose file we couldn't read -> protection is incomplete
      if ( $contents === "" ) continue;
      $contents = appdataCleanupNgExpandEnv(str_replace("\r","",$contents),$env);   # a Windows line ending is not part of a path
      # a trailing YAML comment ("- /x:/y   # note") is not part of the path; a " #" inside quotes is
      $contents = preg_replace_callback('/^([^\n]*?)[ \t]+#[^\n]*$/m',function($m) {
        return ( substr_count($m[1],'"') % 2 === 0 && substr_count($m[1],"'") % 2 === 0 ) ? $m[1] : $m[0];
      },$contents);
      # a VOLUME BIND whose host side still has an unresolved ${VAR}/$VAR means we can't know which appdata folder it maps to.
      # scope to bind positions to avoid disabling fsscan on unrelated $VARs: short syntax "- ${VAR}/path:/container"
      # (the [^\n:=] host guard excludes "KEY=val" environment items), and long syntax "source: ${VAR}...",
      # each also inside a one-line list or map ("volumes: [${VAR}/x:/y]", "{type: bind, source: ${VAR}}")
      if ( preg_match('#(?:^[ \t]*-|[\[,])[ \t]*["\']?[^\n:=,\]]*\$\{?[A-Za-z_][^\n:]*:/#m',$contents)
        || preg_match('#(?:^[ \t]*(?:-[ \t]*)?|[{,][ \t]*)source[ \t]*:[ \t]*["\']?[^\n,}]*\$[A-Za-z_{]#m',$contents) ) {
        $uncertain = true;
      }
      # A bind host we cannot trust never matches the root pattern below, so it would be
      # silently unprotected. Check every bind host up front with the shared predicates.
      # a quoted host may hold the other quote ("/x/Bob's app"); a one-line list or map holds binds too
      if ( preg_match_all('#(?:^[ \t]*(?:-[ \t]*(?:source[ \t]*:)?|source[ \t]*:)|[\[,][ \t]*|[{,][ \t]*source[ \t]*:)[ \t]*(?:"(/[^\n:"]*)|\'(/[^\n:\']*)|(/[^\n:"\',\]}]*))#m',$contents,$hosts,PREG_SET_ORDER) ) {
        foreach ( $hosts as $h ) {
          $host = rtrim(($h[1] ?? "").($h[2] ?? "").($h[3] ?? ""),"/");
          if ( appdataCleanupNgHasControlChars($host) || preg_match('#(^|/)\.\.(/|$)#',$host) ) { $uncertain = true; continue; }
          if ( appdataCleanupNgCoversAppdataRoot($host) ) $rootBound = true;
          appdataCleanupNgProtectFolderOf($protected,$host);
        }
      }
      # included or extended files are not read here, so their binds would go unprotected
      if ( preg_match('#^[ \t]*["\']?(?:include|extends)["\']?[ \t]*:#m',$contents) ) $uncertain = true;
      # relative bind hosts resolve against the compose source; only binds ("- ./x:/y", "source: ./x"), not env_file items
      $rel = array();
      if ( preg_match_all('#(?:^[ \t]*-|[\[,])[ \t]*["\']?(\.\.?(?:/[^\n:"\',\]]*)?):#m',$contents,$short) ) $rel = $short[1];
      if ( preg_match_all('#^[ \t]*(?:-[ \t]*)?source[ \t]*:[ \t]*["\']?(\.\.?(?:/[^\n"\']*)?)["\']?[ \t]*$#m',$contents,$long) ) $rel = array_merge($rel,$long[1]);
      foreach ( $rel as $host ) {
        $host = rtrim($host,"/");
        if ( appdataCleanupNgHasControlChars($host) || preg_match('#(^|/)\.\.(/|$)#',$host) ) { $uncertain = true; continue; }
        foreach ( $bases as $b ) {
          if ( appdataCleanupNgCoversAppdataRoot($b.substr($host,1)) ) $rootBound = true;
          appdataCleanupNgProtectFolderOf($protected,$b.substr($host,1));
        }
      }
      if ( preg_match_all($pattern,$contents,$matches,PREG_SET_ORDER) ) {
        foreach ( $matches as $hit ) {
          # belt and braces: the pre-check above already marked such a file uncertain
          if ( appdataCleanupNgHasControlChars($hit[2]) || preg_match('#(^|/)\.\.(/|$)#',$hit[2]) ) {
            $uncertain = true;
            continue;
          }
          $firstSeg = strtok($hit[2],"/");         # appdata folder name under the root
          if ( $firstSeg === false || $firstSeg === "" ) continue;
          $full = $hit[1]."/".$firstSeg;
          foreach ( appdataCleanupNgPathViews($full) as $c ) $protected[$c] = true;
        }
      }
      # fail-safe: host root is an unresolved ${var}/$var but its next segment names an existing appdata folder, so protect it conservatively
      if ( preg_match_all('#\$(?:\{[A-Za-z_][A-Za-z0-9_]*(?::?-[^}]*)?\}|[A-Za-z_][A-Za-z0-9_]*)/([A-Za-z0-9][A-Za-z0-9._-]*)#',$contents,$uns,PREG_SET_ORDER) ) {
        foreach ( $uns as $uh ) {
          $seg = $uh[1];
          foreach ( $roots as $r ) {
            $cand = $r."/".$seg;
            if ( @is_dir($cand) ) {
              foreach ( appdataCleanupNgPathViews($cand) as $c ) $protected[$c] = true;
            }
          }
        }
      }
    }
  }
  return array_keys($protected);
}

?>