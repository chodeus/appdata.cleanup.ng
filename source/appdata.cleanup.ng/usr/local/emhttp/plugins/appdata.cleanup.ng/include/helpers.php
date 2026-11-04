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
  return appdataCleanupNgViewKey(trim((string)$path));
}

# a physical path, exactly as named: only repeated and trailing slashes go (names may end in a space)
function appdataCleanupNgPhysical($path) {
  return rtrim(preg_replace('#/+#','/',(string)$path),"/");
}

# the /mnt/user view of a path, without trimming it: one key for the same folder on any pool or disk
function appdataCleanupNgViewKey($path) {
  $p = appdataCleanupNgPhysical($path);
  # drop "." segments so /mnt/user/./appdata compares equal to /mnt/user/appdata.
  # ".." is deliberately NOT resolved here: traversal is rejected by the callers instead.
  while ( strpos($p,"/./") !== false ) $p = str_replace("/./","/",$p);
  $p = preg_replace('#/\.$#','',$p);
  # collapse any pool/array-disk mount to its /mnt/user view so the same folder compares equal regardless of pool; skip mounts that are genuinely not the user share
  if ( preg_match('#^/mnt/([^/]+)(/.*)?$#',$p,$m) ) {
    if ( ! in_array($m[1],appdataCleanupNgNotShareMounts(),true) ) {
      $p = "/mnt/user".(isset($m[2]) ? $m[2] : "");
    }
  }
  return $p;
}

function appdataCleanupNgOwnerSegment($path) {
  $c = appdataCleanupNgCanon($path);
  foreach ( appdataCleanupNgAppdataRoots() as $root ) {
    $r = appdataCleanupNgCanon($root);
    if ( $r !== "" && strpos($c."/",$r."/") === 0 && strlen($c) > strlen($r) ) {
      $seg = strtok(ltrim(substr($c,strlen($r)),"/"),"/");
      return $seg === false ? "" : $seg;
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

# mounted datasets keyed by mountpoint, kept only where the mount table shows them: after zfs rename -u the
# property names a path the dataset isn't mounted at. Empty when the mount table can't be read.
function appdataCleanupNgZfsDatasetMap($mountinfo="/proc/self/mountinfo") {
  static $maps = array();
  if ( isset($maps[$mountinfo]) ) return $maps[$mountinfo];
  $live = appdataCleanupNgZfsAvailable() ? appdataCleanupNgZfsLiveMounts($mountinfo) : null;
  if ( $live === null ) return array();
  $out = array(); $rc = 1;
  @exec("zfs list -H -o name,mounted,mountpoint -t filesystem 2>/dev/null",$out,$rc);
  if ( $rc !== 0 ) return array();
  $map = array();
  foreach ( $out as $line ) {
    $parts = explode("\t",rtrim($line,"\n"),3);
    # an unmounted dataset (a received replica, canmount=noauto) holds none of what is at its mountpoint
    if ( count($parts) < 3 || $parts[1] !== "yes" ) continue;
    $name = trim($parts[0]);
    $mp = appdataCleanupNgPhysical($parts[2]);
    if ( $name !== "" && in_array($mp,$live[$name] ?? array(),true) ) $map[$mp] = $name;
  }
  return $maps[$mountinfo] = $map;
}

# the dataset mounted exactly at this folder; through /mnt/user, the one mounted at it when the folder has no other copy
function appdataCleanupNgResolveZfsDataset($path,$mountinfo="/proc/self/mountinfo") {
  $map = appdataCleanupNgZfsDatasetMap($mountinfo);
  if ( empty($map) ) return "";
  $variants = array(appdataCleanupNgPhysical($path));
  $rp = @realpath($path);
  if ( $rp !== false ) $variants[] = appdataCleanupNgPhysical($rp);
  foreach ( $variants as $v ) {
    if ( $v !== "" && isset($map[$v]) ) return $map[$v];
  }
  foreach ( $variants as $v ) {
    if ( strpos($v,"/mnt/user/") !== 0 ) continue;   # a pool or disk path means that place only
    $hits = array();
    foreach ( $map as $mp => $name ) if ( appdataCleanupNgViewKey($mp) === $v ) $hits[$mp] = $name;
    # destroying the dataset would leave a copy on another pool or disk, so the mount checks refuse it instead
    if ( count($hits) === 1 && count(appdataCleanupNgFolderCopies($v)) === 1 ) return reset($hits);
  }
  return "";
}

# entries under /mnt that are not a pool or array disk holding share folders
function appdataCleanupNgNotShareMounts() {
  return array("user","user0","disks","remotes","rootsharecache","addons",".","..");
}

# where a /mnt/user folder physically is: the same path on each pool and array disk that has it
function appdataCleanupNgFolderCopies($userPath,$mnt="/mnt") {
  $rest = substr($userPath,strlen("/mnt/user"));
  $copies = array();
  foreach ( (array)@scandir($mnt) as $e ) {
    if ( ! in_array($e,appdataCleanupNgNotShareMounts(),true) && is_dir($mnt."/".$e.$rest) ) $copies[] = $mnt."/".$e.$rest;
  }
  return $copies;
}

# filesystems a delete of $dir would reach: mounted at or inside it (a bind of the same filesystem keeps its device),
# through /mnt/user on any pool or disk too; null when the mount table can't be read or parsed
function appdataCleanupNgMountsInside($dir,$mountinfo="/proc/self/mountinfo") {
  $lines = @file($mountinfo,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  $d = appdataCleanupNgPhysical($dir);
  if ( ! $lines || $d === "" ) return null;
  $viaUser = strpos($d,"/mnt/user/") === 0;
  $inside = array();
  foreach ( $lines as $line ) {
    $fields = explode(" ",$line);
    if ( count($fields) < 5 || $fields[4] === "" ) return null;
    $mp = preg_replace_callback('/\\\\([0-7]{3})/',function($m) { return chr(octdec($m[1])); },$fields[4]);
    $at = $viaUser ? appdataCleanupNgViewKey($mp) : appdataCleanupNgPhysical($mp);
    if ( strpos($at."/",$d."/") === 0 ) $inside[] = $mp;
  }
  return $inside;
}

function appdataCleanupNgIsMountPoint($path) {
  $rp = @realpath($path);
  if ( $rp === false || ! is_dir($rp) || $rp === "/" ) return false;
  $d = @stat($rp); $p = @stat(dirname($rp));
  if ( ! is_array($d) || ! is_array($p) ) return true;   # can't verify the mount boundary -> treat as a mount and refuse (fail closed)
  return ( $d['dev'] !== $p['dev'] );
}

# destroy a dataset, auto-detecting whether -r is required (snapshots/children)
function appdataCleanupNgZfsDestroy($dataset,$mountinfo="/proc/self/mountinfo") {
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
      # -r also destroys child datasets, but the in-use checks only covered what is mounted inside this folder
      $outside = appdataCleanupNgZfsChildrenOutside($ds,$mountinfo);
      if ( $outside === null ) return array("ok"=>false,"recursive"=>true,"blocked"=>"its child datasets could not be checked","message"=>"child datasets could not be checked, not escalating to -r");
      if ( $outside ) return array("ok"=>false,"recursive"=>true,"blocked"=>"child datasets outside this folder: ".implode(", ",$outside),"message"=>"child datasets outside the folder: ".implode(", ",$outside));
    } else {
      return array("ok"=>false,"recursive"=>false,"message"=>"dry-run failed, not escalating to -r: ".trim(implode("\n",$o)));
    }
  }
  $o = array(); $rc = 1;
  @exec("zfs destroy ".($recursive ? "-r " : "").escapeshellarg($ds)." 2>&1",$o,$rc);
  return array("ok"=>($rc===0),"recursive"=>$recursive,"message"=>trim(implode("\n",$o)));
}

# where each ZFS dataset is mounted right now (dataset => mount points), from the mount table; null when it can't be read
function appdataCleanupNgZfsLiveMounts($mountinfo="/proc/self/mountinfo") {
  $lines = @file($mountinfo,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if ( ! $lines ) return null;
  $unescape = function($v) { return preg_replace_callback('/\\\\([0-7]{3})/',function($m) { return chr(octdec($m[1])); },$v); };
  $live = array();
  foreach ( $lines as $line ) {
    $sides = explode(" - ",$line,2);
    $before = explode(" ",$sides[0]);
    $after = explode(" ",$sides[1] ?? "");
    if ( count($before) < 5 || count($after) < 2 ) return null;
    if ( $after[0] === "zfs" ) $live[$unescape($after[1])][] = appdataCleanupNgPhysical($unescape($before[4]));
  }
  return $live;
}

# child datasets of $ds whose data is not inside $ds's folder: volumes, unmounted, or mounted elsewhere; null when unreadable.
# Judged by where they are mounted now, not the mountpoint property (zfs rename -u moves the property, not the mount).
function appdataCleanupNgZfsChildrenOutside($ds,$mountinfo="/proc/self/mountinfo") {
  $o = array(); $rc = 1;
  @exec("zfs list -H -r -t filesystem,volume -o name,type,mounted,mountpoint ".escapeshellarg($ds)." 2>/dev/null",$o,$rc);
  if ( $rc !== 0 ) return null;
  $rows = array();
  foreach ( $o as $line ) {
    $p = explode("\t",rtrim($line,"\n"),4);
    if ( count($p) !== 4 ) return null;   # a row we can't read may be the child that matters
    $rows[$p[0]] = $p;
  }
  $live = appdataCleanupNgZfsLiveMounts($mountinfo);
  if ( ! isset($rows[$ds]) || $live === null || count($live[$ds] ?? array()) !== 1 ) return null;
  # physical paths, not canonical: an inherited child sits under its parent, and the /mnt/user view would equate another pool's path
  $folder = $live[$ds][0];
  $outside = array();
  foreach ( $rows as $name => $p ) {
    if ( $name === $ds ) continue;
    $at = $live[$name] ?? array();
    $inside = $p[1] === "filesystem" && $at && $folder !== "" && ! array_filter($at,function($m) use ($folder) { return ! appdataCleanupNgPathUnder($m,$folder); });
    if ( $inside ) continue;
    $where = $p[1] !== "filesystem" ? "a volume" : ( ! $at ? "not mounted" : "mounted at ".implode(", ",$at) );
    $outside[] = $name." (".$where.")";
  }
  return $outside;
}

# ignore list: folders the user marks to never offer, persisted on flash keyed by canonical path
function appdataCleanupNgIgnoreFile() {
  return "/boot/config/plugins/appdata.cleanup.ng/ignore.list";
}

function appdataCleanupNgIgnoreList() {
  static $list = null;
  if ( $list !== null ) return $list;
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

function appdataCleanupNgWriteIgnoreList($list) {
  $f = appdataCleanupNgIgnoreFile();
  $dir = dirname($f);
  if ( ! is_dir($dir) ) @mkdir($dir,0755,true);
  $body = empty($list) ? "" : implode("\n",array_keys($list))."\n";
  @file_put_contents($f,$body,LOCK_EX);
}

function appdataCleanupNgAddIgnore($path) {
  if ( ! appdataCleanupNgPathWithinAppdata($path) ) return false;
  $c = appdataCleanupNgCanon($path);
  $list = appdataCleanupNgIgnoreList();
  if ( isset($list[$c]) ) return true;
  $list[$c] = true;
  appdataCleanupNgWriteIgnoreList($list);
  return true;
}

function appdataCleanupNgRemoveIgnore($path) {
  $c = appdataCleanupNgCanon($path);
  $list = appdataCleanupNgIgnoreList();
  if ( ! isset($list[$c]) ) return true;
  unset($list[$c]);
  appdataCleanupNgWriteIgnoreList($list);
  return true;
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

function appdataCleanupNgComposeReferencedPaths(&$uncertain = null) {
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
    $base = $proj;
    if ( is_file($proj."/indirect") ) {
      $indirect = trim((string)@file_get_contents($proj."/indirect"));
      if ( $indirect !== "" ) $base = rtrim($indirect,"/");
    }
    # docker compose resolves ${VAR} from a .env in the compose-file (working) dir first
    $env = array();
    appdataCleanupNgParseEnvFile($base."/.env",$env);
    appdataCleanupNgParseEnvFile($proj."/.env",$env);
    # cover modern (compose.yaml) and legacy (docker-compose.yml) names + overrides, in base and project dirs
    $files = array(
      $base."/compose.yaml",$base."/compose.yml",
      $base."/docker-compose.yaml",$base."/docker-compose.yml",
      $base."/compose.override.yaml",$base."/compose.override.yml",
      $base."/docker-compose.override.yaml",$base."/docker-compose.override.yml",
      $proj."/compose.override.yaml",$proj."/compose.override.yml",
      $proj."/docker-compose.override.yaml",$proj."/docker-compose.override.yml"
    );
    $files = array_values(array_unique($files));
    foreach ( $files as $f ) {
      if ( ! is_file($f) ) continue;
      $contents = @file_get_contents($f);
      if ( $contents === false ) { $uncertain = true; continue; }   # an existing compose file we couldn't read -> protection is incomplete
      if ( $contents === "" ) continue;
      $contents = appdataCleanupNgExpandEnv($contents,$env);
      # a VOLUME BIND whose host side still has an unresolved ${VAR}/$VAR means we can't know which appdata folder it maps to.
      # scope to bind positions to avoid disabling fsscan on unrelated $VARs: short syntax "- ${VAR}/path:/container"
      # (the [^\n:=] host guard excludes "KEY=val" environment items), and long syntax "source: ${VAR}...".
      if ( preg_match('#^[ \t]*-[ \t]*["\']?[^\n:=]*\$\{?[A-Za-z_][^\n:]*:/#m',$contents)
        || preg_match('#^[ \t]*source[ \t]*:[ \t]*["\']?[^\n]*\$[A-Za-z_{]#m',$contents) ) {
        $uncertain = true;
      }
      # A bind host we cannot trust never matches the root pattern below, so it would be
      # silently unprotected. Check every bind host up front with the shared predicates.
      if ( preg_match_all('#^[ \t]*(?:-|source[ \t]*:)[ \t]*["\']?(/[^\n:"\']*)#m',$contents,$hosts,PREG_SET_ORDER) ) {
        foreach ( $hosts as $h ) {
          $host = rtrim($h[1],"/");
          if ( appdataCleanupNgHasControlChars($host) || preg_match('#(^|/)\.\.(/|$)#',$host) ) {
            $uncertain = true;
            break;
          }
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