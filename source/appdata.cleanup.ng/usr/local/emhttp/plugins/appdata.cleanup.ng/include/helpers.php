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
  if ( $list === null ) $list = appdataCleanupNgReadIgnoreList() ?? array();
  return $list;
}

# null when the list is there but can't be read, so a change never writes an empty list over it
function appdataCleanupNgReadIgnoreList() {
  $list = array();
  $f = appdataCleanupNgIgnoreFile();
  if ( ! is_file($f) ) return $list;
  $lines = @file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if ( $lines === false ) return null;
  foreach ( $lines as $line ) {
    $c = appdataCleanupNgCanon($line);
    if ( $c !== "" ) $list[$c] = true;
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
  $ok = false;
  if ( $list !== null ) {
    $change($list);
    $body = empty($list) ? "" : implode("\n",array_keys($list))."\n";
    $tmp = $f.".tmp".getmypid();
    $ok = @file_put_contents($tmp,$body) === strlen($body) && @rename($tmp,$f);
    if ( ! $ok ) @unlink($tmp);
  }
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
# one line for a log or a refusal: PHP's syslog() makes each line of a message its own record
function appdataCleanupNgOneLine($text) {
  return preg_replace('/[\x00-\x1f\x7f]+/'," ",(string)$text);
}
function appdataCleanupNgLog($message,$priority=LOG_INFO) {
  openlog("appdata.cleanup.ng",LOG_PID,LOG_USER);
  syslog($priority,appdataCleanupNgOneLine($message));
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

# one env file line as [name, value]; null for a blank line, a comment or a line without "="
function appdataCleanupNgEnvLine($line) {
  $line = trim($line);
  if ( $line === "" || $line[0] === "#" ) return null;
  if ( strpos($line,"export ") === 0 ) $line = trim(substr($line,7));
  $eq = strpos($line,"=");
  if ( $eq === false ) return null;
  return array(trim(substr($line,0,$eq)),appdataCleanupNgEnvValue(ltrim(substr($line,$eq+1))));
}

# an env value as compose reads it: what its quotes hold (escapes undone in double quotes), else up to a " #" comment
function appdataCleanupNgEnvValue($v) {
  if ( preg_match("/^'([^']*)'/",$v,$m) ) return $m[1];
  if ( preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/s',$v,$m) ) {
    return preg_replace_callback('/\\\\(.)/s',function($e) {
      $escapes = array("n" => "\n","t" => "\t","\\" => "\\",'"' => '"','$' => '$');
      return $escapes[$e[1]] ?? $e[0];
    },$m[1]);
  }
  $cut = strpos($v," #");
  return rtrim($cut === false ? $v : substr($v,0,$cut));
}

# add the variables an env file sets to those $env doesn't set yet; in the file the last one wins, as compose reads it.
# false when the file is there but can't be read
function appdataCleanupNgParseEnvFile($file,&$env) {
  if ( ! is_file($file) ) return true;
  $lines = @file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if ( $lines === false ) return false;
  $own = array();
  foreach ( $lines as $line ) {
    $pair = appdataCleanupNgEnvLine($line);
    if ( $pair !== null && $pair[0] !== "" ) $own[$pair[0]] = $pair[1];
  }
  $env += $own;
  return true;
}

# one ${VAR...} or $VAR as compose expands it; an unset ${VAR}, ${VAR:?err} or ${VAR?err} stays as written
function appdataCleanupNgExpandOne($m,$env) {
  $name = ( $m[1] ?? "" ) !== "" ? $m[1] : ( $m[4] ?? "" );
  $op = $m[2] ?? "";
  $arg = $m[3] ?? "";
  $set = isset($env[$name]) && $env[$name] !== "";
  if ( $op === ":+" || $op === "+" ) return ( $op === "+" ? isset($env[$name]) : $set ) ? $arg : "";
  if ( $set ) return $env[$name];
  return ( ( $op === ":-" || $op === "-" ) && $arg !== "" ) ? $arg : $m[0];
}
function appdataCleanupNgExpandEnv($text,$env) {
  for ( $pass = 0; $pass < 2; $pass++ ) {
    $text = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?+])([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',function($m) use ($env) { return appdataCleanupNgExpandOne($m,$env); },$text);
  }
  return $text;
}

# a project's Compose Manager settings files, trimmed, "" for one that isn't there; null when one is there but can't be read
function appdataCleanupNgProjectMeta($proj) {
  $meta = array();
  foreach ( array("indirect","indirect_mode","envpath","extra_compose_files") as $name ) {
    $text = is_file($proj."/".$name) ? @file_get_contents($proj."/".$name) : "";
    if ( $text === false ) return null;
    $meta[$name] = trim($text);
  }
  return $meta;
}

# where a project's compose files live: [source folder, the compose file indirect names, if it names one];
# null when indirect can't be used, so callers fail closed
function appdataCleanupNgComposeSource($proj,$meta) {
  if ( file_exists($proj."/indirect.invalid") ) return null;   # a broken target kept for repair
  if ( ! file_exists($proj."/indirect") && ! is_link($proj."/indirect") ) return array($proj,array());
  $target = rtrim($meta['indirect'],"/");
  $mode = strtolower($meta['indirect_mode']);
  if ( $target === "" || $target[0] !== "/" || ! in_array($mode,array("","file","folder"),true) ) return null;
  # newer Compose Manager versions can point indirect at one compose file instead of a folder
  if ( $mode !== "file" && ! is_file($target) ) return ( is_dir($target) && is_readable($target) ) ? array($target,array()) : null;
  return ( is_file($target) && is_readable($target) ) ? array(dirname($target),array($target)) : null;
}

# the one env file compose gets: envpath (absolute, or relative to the project folder), else the stack's default
function appdataCleanupNgComposeEnvFile($proj,$source,$envPath) {
  if ( $envPath !== "" ) {
    $candidate = $envPath[0] === "/" ? $envPath : $proj."/".$envPath;
    if ( is_file($candidate) ) return $candidate;
  }
  $default = ( is_file($proj."/git.json") && is_file($proj."/.env") ) ? $proj."/.env" : $source."/.env";
  return is_file($default) ? $default : "";
}

# compose files beyond the default names: COMPOSE_FILE in the env file (relative to it), extra_compose_files (relative to the source)
function appdataCleanupNgComposeExtraFiles($source,$envFile,$composeEnv,$extraList) {
  $files = array();
  foreach ( preg_split('/[:;]/',(string)($composeEnv['COMPOSE_FILE'] ?? "")) as $extra ) {
    $extra = trim($extra);
    if ( $extra !== "" ) $files[] = $extra[0] === "/" ? $extra : dirname($envFile)."/".$extra;
  }
  foreach ( preg_split('/\R/',$extraList) as $extra ) {
    $extra = trim($extra);
    if ( $extra !== "" && $extra[0] !== "#" ) $files[] = $extra[0] === "/" ? $extra : $source."/".$extra;
  }
  return $files;
}

# [compose source folder, compose files, variable sets, env file] as Compose Manager runs the project; null when its
# compose location, a settings file or an env file can't be read, so callers fail closed
function appdataCleanupNgComposeProject($proj) {
  $meta = appdataCleanupNgProjectMeta($proj);
  if ( $meta === null ) return null;
  $located = appdataCleanupNgComposeSource($proj,$meta);
  if ( $located === null ) return null;
  list($source,$files) = $located;
  # cover modern (compose.yaml) and legacy (docker-compose.yml) names + overrides, in source and project dirs
  foreach ( array("compose","docker-compose","compose.override","docker-compose.override") as $n ) {
    foreach ( array("yaml","yml") as $ext ) $files[] = $source."/".$n.".".$ext;
  }
  foreach ( array("compose.override","docker-compose.override") as $n ) {
    foreach ( array("yaml","yml") as $ext ) $files[] = $proj."/".$n.".".$ext;
  }
  $envFile = appdataCleanupNgComposeEnvFile($proj,$source,$meta['envpath']);
  $envs = appdataCleanupNgComposeEnvs($envFile,$source,$proj);
  if ( $envs === null ) return null;
  $files = array_merge($files,appdataCleanupNgComposeExtraFiles($source,$envFile,$envs[0],$meta['extra_compose_files']));
  return array(rtrim($source,"/"),array_values(array_unique($files)),$envs,$envFile);
}

# the variable sets to read a stack's files with: the env file compose gets, alone (compose reads no other), and merged with
# the stack's other .env files in case it gets another; null when one is there but can't be read
function appdataCleanupNgComposeEnvs($envFile,$source,$proj) {
  $own = array();
  if ( ! appdataCleanupNgParseEnvFile($envFile,$own) ) return null;
  $merged = $own;
  foreach ( array($source."/.env",$proj."/.env") as $e ) if ( ! appdataCleanupNgParseEnvFile($e,$merged) ) return null;
  return $merged === $own ? array($own) : array($own,$merged);
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
    # compose v1 has no `config --format json`: leave it to the pattern reader
    if ( $rc === 0 && ! preg_match('/^docker-compose version 1\./m',implode("\n",$o)) ) { $cmd = $try; break; }
  }
  return $cmd;
}

function appdataCleanupNgCanUnshareNetwork() {
  static $ok = null;
  if ( $ok === null ) { $o = array(); $rc = 1; @exec("unshare -n true 2>/dev/null",$o,$rc); $ok = $rc === 0; }
  return $ok;
}

# run compose with $env set, without network where unshare allows and for at most 20 s: [exit code, stdout, stderr]
function appdataCleanupNgComposeRun($argv,$env) {
  $line = "env ".implode(" ",array_map("escapeshellarg",$env))." ".( appdataCleanupNgCanUnshareNetwork() ? "unshare -n " : "" )
        . "timeout 20 ".implode(" ",array_map("escapeshellarg",$argv));
  $errFile = tempnam(sys_get_temp_dir(),"acng-compose");
  $out = array(); $rc = 1;
  @exec($line." 2>".escapeshellarg($errFile),$out,$rc);
  $err = (string)@file_get_contents($errFile);
  @unlink($errFile);
  return array($rc,implode("\n",$out),$err);
}

# $env plus each variable compose reported unset, set to $marker; null when the names can't be read
function appdataCleanupNgComposeMarkUnset($err,$env,$marker) {
  # logged as msg="The \"TZ\" variable is not set..." by recent compose, unescaped by older ones
  if ( ! preg_match_all('/\\\\?"([A-Za-z_][A-Za-z0-9_]*)\\\\?" variable is not set/',$err,$unset) ) return null;
  foreach ( array_unique($unset[1]) as $name ) $env[] = $name."=".$marker;
  return $env;
}

function appdataCleanupNgComposeMarkerInPaths($config,$marker) {
  $paths = appdataCleanupNgComposeConfigPaths($config);
  foreach ( array_merge($paths['mounts'],$paths['files'],$paths['mentions']) as $path ) if ( strpos($path,$marker) !== false ) return true;
  return false;
}

# one `compose config` run: the decoded stack, null when this compose lacks one of the flags, false when it can't be read
function appdataCleanupNgComposeConfigAttempt($argv) {
  # remote include loaders off: a scan must never fetch anything. Every profile, so a service that only starts with one is read too.
  $env = array("COMPOSE_EXPERIMENTAL_GIT_REMOTE=false","COMPOSE_EXPERIMENTAL_OCI_REMOTE=false","COMPOSE_PROFILES=*");
  list($rc,$out,$err) = appdataCleanupNgComposeRun($argv,$env);
  if ( $rc !== 0 ) return stripos($err,"unknown flag") !== false ? null : false;
  # an unset variable is left blank: set each to a marker and ask again; only a marker inside a path is a problem
  $marker = stripos($err,"is not set") !== false ? "acng-unset-".getmypid() : "";
  if ( $marker !== "" ) {
    $env = appdataCleanupNgComposeMarkUnset($err,$env,$marker);
    if ( $env === null ) return false;
    list($rc,$out) = appdataCleanupNgComposeRun($argv,$env);
    if ( $rc !== 0 ) return false;
  }
  $config = json_decode($out,true);
  if ( ! is_array($config) ) return false;
  return ( $marker !== "" && appdataCleanupNgComposeMarkerInPaths($config,$marker) ) ? false : $config;
}

# without --no-env-resolution compose folds env_file into environment, so those files (also in includes) can't be found
function appdataCleanupNgComposeNamesEnvFiles($files) {
  foreach ( $files as $f ) if ( preg_match('/\b(env_file|include|extends)\b/',(string)@file_get_contents($f)) ) return true;
  return false;
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
  # older compose lacks --no-env-resolution (which keeps env_file paths); without --format json it can't be used at all
  foreach ( array(array("--no-env-resolution","--format","json"),array("--format","json")) as $attempt => $flags ) {
    $config = appdataCleanupNgComposeConfigAttempt(array_merge($args,array("config"),$flags));
    if ( $config === null ) continue;
    return ( $attempt === 1 && appdataCleanupNgComposeNamesEnvFiles($used) ) ? false : $config;
  }
  return null;
}

# $array[$key] as a list, like (array)($array[$key] ?? array())
function appdataCleanupNgListAt($array,$key) {
  return isset($array[$key]) ? (array)$array[$key] : array();
}

# bind sources of one service in a compose config
function appdataCleanupNgComposeServiceMounts($service) {
  $mounts = array();
  foreach ( appdataCleanupNgListAt($service,'volumes') as $v ) if ( is_array($v) && ($v['type'] ?? "") === "bind" ) $mounts[] = $v['source'] ?? "";
  return $mounts;
}

# env files and build paths of one service in a compose config
function appdataCleanupNgComposeServiceFiles($service) {
  $files = array();
  foreach ( appdataCleanupNgListAt($service,'env_file') as $e ) $files[] = is_array($e) ? ($e['path'] ?? "") : $e;
  $build = is_array($service['build'] ?? null) ? $service['build'] : array();
  array_push($files,$build['context'] ?? "",$build['dockerfile'] ?? "");
  return array_merge($files,array_values(appdataCleanupNgListAt($build,'additional_contexts')));
}

# every string in a compose config naming a /mnt path, container-side targets aside
function appdataCleanupNgComposeMentions($node,&$mentions) {
  foreach ( (array)$node as $key => $value ) {
    if ( $key === "target" && ! is_array($value) ) continue;   # a container-side path; a service may be named target
    if ( is_array($value) ) appdataCleanupNgComposeMentions($value,$mentions);
    else if ( is_string($value) && preg_match_all('#/mnt/[^\s"\':;,=]+#',$value,$m) ) foreach ( $m[0] as $hit ) $mentions[] = $hit;
  }
}

function appdataCleanupNgAbsolutePaths($list) {
  return array_values(array_unique(array_filter(array_map("strval",$list),function($p) { return $p !== "" && $p[0] === "/"; })));
}

# Host paths in a compose config: 'mounts' (bind sources, named-volume devices), 'files' (env files, build contexts,
# secret and config files) and 'mentions' (any other string naming a /mnt path, such as an environment value)
function appdataCleanupNgComposeConfigPaths($config) {
  $mounts = array(); $files = array(); $mentions = array();
  foreach ( appdataCleanupNgListAt($config,'services') as $service ) {
    if ( ! is_array($service) ) continue;
    $mounts = array_merge($mounts,appdataCleanupNgComposeServiceMounts($service));
    $files = array_merge($files,appdataCleanupNgComposeServiceFiles($service));
  }
  foreach ( appdataCleanupNgListAt($config,'volumes') as $volume ) if ( is_array($volume) ) $mounts[] = $volume['driver_opts']['device'] ?? "";
  foreach ( array("secrets","configs") as $kind ) {
    foreach ( appdataCleanupNgListAt($config,$kind) as $item ) if ( is_array($item) ) $files[] = $item['file'] ?? "";
  }
  appdataCleanupNgComposeMentions($config,$mentions);
  return array("mounts" => appdataCleanupNgAbsolutePaths($mounts), "files" => appdataCleanupNgAbsolutePaths($files), "mentions" => appdataCleanupNgAbsolutePaths($mentions));
}

# existing paths a compose file's text names, with variables filled in; relative ones against the file's folder
function appdataCleanupNgComposeExistingNames($file,$env) {
  $text = appdataCleanupNgExpandEnv(str_replace("\r","",(string)@file_get_contents($file)),$env);
  preg_match_all('#"([^"\n]+)"|\'([^\'\n]+)\'|([^\s"\'\[\]{},:=\#]+)#',$text,$m,PREG_SET_ORDER);
  $paths = array();
  foreach ( $m as $hit ) {
    $name = implode("",array_slice($hit,1));   # only the alternative that matched is non-empty
    if ( $name === "" || appdataCleanupNgHasControlChars($name) ) continue;
    $path = $name[0] === "/" ? $name : dirname($file)."/".$name;
    if ( file_exists($path) ) $paths[] = $path;
  }
  return $paths;
}

# Existing paths the compose files name (include and extends targets, an include's env_file, anything else), with
# variables filled in, following any yaml file found. compose's output never lists them; extras only protect more.
function appdataCleanupNgComposeNamedPaths($files,$env) {
  $seen = array();
  $found = array();
  $queue = array_values($files);
  while ( $queue && count($seen) < 100 ) {
    $f = array_shift($queue);
    if ( isset($seen[$f]) ) continue;
    $seen[$f] = true;
    foreach ( appdataCleanupNgComposeExistingNames($f,$env) as $path ) {
      if ( isset($found[$path]) ) continue;
      $found[$path] = true;
      if ( is_file($path) && preg_match('#\.ya?ml$#',$path) ) $queue[] = $path;
    }
  }
  return array_keys($found);
}

# a path that holds a whole appdata root (a bind of /mnt/user, or of appdata itself): every folder under it is in use
function appdataCleanupNgCoversAppdataRoot($path) {
  $path = preg_replace('#^/mnt/user0(?=/|$)#','/mnt/user',(string)$path);
  if ( $path !== "" && trim($path,"/") === "" ) return true;   # / itself, which the path views leave out
  foreach ( appdataCleanupNgPathViews($path) as $view ) {
    foreach ( appdataCleanupNgAppdataRoots() as $root ) if ( appdataCleanupNgPathUnder(appdataCleanupNgCanon($root),$view) ) return true;
  }
  return false;
}

# a bind host that can be mapped to a folder: no control characters, no ../
function appdataCleanupNgTrustedHost($host) {
  return ! appdataCleanupNgHasControlChars($host) && ! preg_match('#(^|/)\.\.(/|$)#',$host);
}

# a compose file's text ready to scan: variables filled in, Windows line endings and trailing comments gone; false if unreadable
function appdataCleanupNgComposeText($file,$env) {
  $contents = @file_get_contents($file);
  if ( $contents === false ) return false;
  $contents = appdataCleanupNgExpandEnv(str_replace("\r","",$contents),$env);   # a Windows line ending is not part of a path
  # a trailing YAML comment ("- /x:/y   # note") is not part of the path; a " #" inside quotes is
  return preg_replace_callback('/^([^\n]*?)[ \t]+#[^\n]*$/m',function($m) {
    return ( substr_count($m[1],'"') % 2 === 0 && substr_count($m[1],"'") % 2 === 0 ) ? $m[1] : $m[0];
  },$contents);
}

# a key whose value is a host path: a long-syntax bind's source, a named volume's device, a secret's or config's file
function appdataCleanupNgComposeHostKey() {
  return '["\']?(?:source|device|file)["\']?[ \t]*:';
}

# true when a compose file's binds can't all be known: an unresolved ${VAR} in a bind host (short "- ${VAR}/x:/y", where
# [^\n:=] skips "KEY=val" items, or long "source: ${VAR}", also in one-line lists and maps), or an include or extends
function appdataCleanupNgComposeTextUnreadable($contents) {
  return preg_match('#(?:^[ \t]*-|[\[,])[ \t]*["\']?[^\n:=,\]]*\$(?:\{[^}\n]*\}|[A-Za-z_])[^\n:]*:/#m',$contents)
    || preg_match('#(?:^[ \t]*(?:-[ \t]*)?|[{,][ \t]*)'.appdataCleanupNgComposeHostKey().'[ \t]*["\']?[^\n,}]*\$[A-Za-z_{]#m',$contents)
    || preg_match('#^[ \t]*["\']?(?:include|extends)["\']?[ \t]*:#m',$contents);   # their files are not read here
}

# absolute bind hosts: "- /x:/y", "source: /x", also in one-line lists and maps; a quoted one may hold the other quote
function appdataCleanupNgComposeAbsoluteHosts($contents) {
  $key = appdataCleanupNgComposeHostKey();
  $hosts = array();
  if ( preg_match_all('#(?:^[ \t]*(?:-[ \t]*(?:'.$key.')?|'.$key.')|[\[,][ \t]*|[{,][ \t]*'.$key.')[ \t]*(?:"(/[^\n:"]*)|\'(/[^\n:\']*)|(/[^\n:"\',\]}]*))#m',$contents,$found,PREG_SET_ORDER) ) {
    foreach ( $found as $h ) $hosts[] = ($h[1] ?? "").($h[2] ?? "").($h[3] ?? "");
  }
  return $hosts;
}

# relative bind hosts: only binds ("- ./x:/y", "source: ./x", "{source: ./x}"), never env_file items
function appdataCleanupNgComposeRelativeHosts($contents) {
  $key = appdataCleanupNgComposeHostKey();
  $rel = array();
  if ( preg_match_all('#(?:^[ \t]*-|[\[,])[ \t]*["\']?(\.\.?(?:/[^\n:"\',\]]*)?):#m',$contents,$short) ) $rel = $short[1];
  if ( preg_match_all('#^[ \t]*(?:-[ \t]*)?'.$key.'[ \t]*["\']?(\.\.?(?:/[^\n"\']*)?)["\']?[ \t]*$#m',$contents,$long) ) $rel = array_merge($rel,$long[1]);
  if ( preg_match_all('#[{,][ \t]*'.$key.'[ \t]*(?:"(\.\.?(?:/[^\n"]*)?)"|\'(\.\.?(?:/[^\n\']*)?)\'|(\.\.?(?:/[^\n"\',}]*)?)[,}])#m',$contents,$flow,PREG_SET_ORDER) ) {
    foreach ( $flow as $h ) $rel[] = ($h[1] ?? "").($h[2] ?? "").($h[3] ?? "");
  }
  return $rel;
}

# protect the folders a compose file's bind hosts name: absolute ones on any pool or disk, relative ones against the
# compose source, noting one that holds a whole appdata root; false when a host can't be trusted
function appdataCleanupNgComposeProtectHosts($contents,$bases,&$protected,&$rootBound) {
  $trusted = true;
  foreach ( appdataCleanupNgComposeAbsoluteHosts($contents) as $raw ) {
    $host = rtrim($raw,"/");
    if ( ! appdataCleanupNgTrustedHost($host) ) { $trusted = false; continue; }
    if ( appdataCleanupNgCoversAppdataRoot($raw) ) $rootBound = true;
    appdataCleanupNgProtectFolderOf($protected,$host);
  }
  foreach ( appdataCleanupNgComposeRelativeHosts($contents) as $raw ) {
    $host = rtrim($raw,"/");
    if ( ! appdataCleanupNgTrustedHost($host) ) { $trusted = false; continue; }
    foreach ( $bases as $b ) {
      if ( appdataCleanupNgCoversAppdataRoot($b.substr($host,1)) ) $rootBound = true;
      appdataCleanupNgProtectFolderOf($protected,$b.substr($host,1));
    }
  }
  return $trusted;
}

# tolerate redundant separators and "." segments in a bind path: a literal root match would miss them
function appdataCleanupNgComposeRootPattern($roots) {
  $sep = "/+(?:\\./+)*";
  $escaped = array();
  foreach ( $roots as $r ) {
    $parts = array_map(function($seg) { return preg_quote($seg,"#"); },explode("/",trim($r,"/")));
    $escaped[] = $sep.implode($sep,$parts);
  }
  return "#(".implode("|",$escaped).")".$sep."([^\\s:'\"\\\\]+)#";
}

# protect the folders an appdata root path anywhere in the text names, and, as a fail-safe, those an unresolved
# ${var}/<name> names when <name> exists under a root; false when a match can't be trusted
function appdataCleanupNgComposeProtectRootMatches($contents,$pattern,$roots,&$protected) {
  $trusted = true;
  if ( preg_match_all($pattern,$contents,$matches,PREG_SET_ORDER) ) {
    foreach ( $matches as $hit ) {
      if ( ! appdataCleanupNgTrustedHost($hit[2]) ) { $trusted = false; continue; }
      $firstSeg = strtok($hit[2],"/");         # appdata folder name under the root
      if ( $firstSeg !== false && $firstSeg !== "" ) foreach ( appdataCleanupNgPathViews($hit[1]."/".$firstSeg) as $c ) $protected[$c] = true;
    }
  }
  if ( preg_match_all('#\$(?:\{[A-Za-z_][A-Za-z0-9_]*(?::?[-?+][^}]*)?\}|[A-Za-z_][A-Za-z0-9_]*)/([A-Za-z0-9][A-Za-z0-9._-]*)#',$contents,$uns,PREG_SET_ORDER) ) {
    foreach ( $uns as $uh ) {
      foreach ( $roots as $r ) if ( @is_dir($r."/".$uh[1]) ) foreach ( appdataCleanupNgPathViews($r."/".$uh[1]) as $c ) $protected[$c] = true;
    }
  }
  return $trusted;
}

# protect what a stack's compose files bind, read by pattern with each variable set; false when any of it can't be read or trusted
function appdataCleanupNgComposeProtectByPattern($files,$envs,$bases,&$protected,&$rootBound) {
  $roots = appdataCleanupNgAppdataRoots();
  $pattern = appdataCleanupNgComposeRootPattern($roots);
  $trusted = true;
  foreach ( array_filter($files,"is_file") as $f ) {
    foreach ( $envs as $env ) {
      $contents = appdataCleanupNgComposeText($f,$env);
      if ( $contents === false ) { $trusted = false; continue; }   # an existing compose file we couldn't read
      $trusted = ! appdataCleanupNgComposeTextUnreadable($contents) && $trusted;
      $trusted = appdataCleanupNgComposeProtectHosts($contents,$bases,$protected,$rootBound) && $trusted;
      $trusted = appdataCleanupNgComposeProtectRootMatches($contents,$pattern,$roots,$protected) && $trusted;
    }
  }
  return $trusted;
}

# protect what compose itself reports a stack uses; false when a reported path can't be trusted
function appdataCleanupNgComposeProtectConfig($config,$files,$envs,&$protected,&$rootBound) {
  $trusted = true;
  $paths = appdataCleanupNgComposeConfigPaths($config);
  foreach ( array_merge($paths['mounts'],$paths['files'],$paths['mentions']) as $path ) {
    if ( appdataCleanupNgHasControlChars($path) ) { $trusted = false; continue; }
    appdataCleanupNgProtectFolderOf($protected,$path);
  }
  foreach ( $paths['mounts'] as $path ) if ( appdataCleanupNgCoversAppdataRoot($path) ) $rootBound = true;
  foreach ( $envs as $env ) {
    foreach ( appdataCleanupNgComposeNamedPaths(array_filter($files,"is_file"),$env) as $path ) appdataCleanupNgProtectFolderOf($protected,$path);
  }
  return $trusted;
}

function appdataCleanupNgComposeReferencedPaths(&$uncertain = null, &$rootBound = null) {
  $rootBound = false;   # set true when a stack binds a whole appdata root: callers then skip the filesystem scan
  $uncertain = false;   # set true when a stack can't be fully read -> callers must fail closed
  $projectsDir = "/boot/config/plugins/compose.manager/projects";
  if ( ! is_dir($projectsDir) || ! appdataCleanupNgAppdataRoots() ) return array();
  $protected = array();
  foreach ( (array)glob($projectsDir."/*",GLOB_ONLYDIR) as $proj ) {
    $project = appdataCleanupNgComposeProject($proj);
    if ( $project === null ) { $uncertain = true; continue; }
    list($base,$files,$envs,$envFile) = $project;
    $bases = array_unique(array_filter(array($base,(string)@realpath($base))));
    # the stack's own folder, and the folders of its env file and compose files: the stack breaks without them
    foreach ( array_merge($bases,array_filter(array_merge(array($envFile),$files),"is_file")) as $used ) appdataCleanupNgProtectFolderOf($protected,$used);
    # compose itself reads the stack as it runs it; the patterns are for when there is no compose CLI, or it failed
    $config = appdataCleanupNgComposeConfig($base,$files,$envFile);
    $trusted = is_array($config) ? appdataCleanupNgComposeProtectConfig($config,$files,$envs,$protected,$rootBound)
      : appdataCleanupNgComposeProtectByPattern($files,$envs,$bases,$protected,$rootBound);
    if ( $config === false || ! $trusted ) $uncertain = true;
  }
  return array_keys($protected);
}

?>