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
    return parse_ini_string(preg_replace('/^#.*\\n/m', "", @file_get_contents($file)),$mode,$scanner_mode);
  }
}
if ( ! function_exists("my_parse_ini_string") ) {
  function my_parse_ini_string($string, $mode=false,$scanner_mode=INI_SCANNER_NORMAL) {
    return parse_ini_string(preg_replace('/^#.*\\n/m', "", $string),$mode,$scanner_mode);
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
  $dockerOptions = @my_parse_ini_file("/boot/config/docker.cfg");
  $defaultShareName = basename($dockerOptions['DOCKER_APP_CONFIG_PATH']);
  $shareName = str_replace("/mnt/user/","",$defaultShareName);
  $shareName = str_replace("/mnt/cache/","",$defaultShareName);
  if ( ! is_file("/boot/config/shares/$shareName.cfg") ) { 
    $shareName = "****";
  }
  if ( is_array($volumes) ) {
    foreach ($volumes as $volume) {
      $temp = explode(":",$volume);
      $testPath = strtolower($temp[1]);
    
      # a /config mount, or any host path inside the appdata share on ANY pool/disk
      # (the canonicalizer collapses /mnt/<pool>/ and /mnt/diskN/ to the /mnt/user view)
      if ( startsWith($testPath,"/config") || appdataCleanupNgPathWithinAppdata($temp[0]) ) {
        $path = $temp[0];
        break;
      }
    }
  }
  return $path;
}

#############################################################
#                                                           #
# Helper function to return an array of directory contents. #
# Returns an empty array if the directory does not exist    #
#                                                           #
#############################################################

function dirContents($path) {
  $dirContents = @scandir($path);
  if ( ! $dirContents ) {
    $dirContents = array();
  }
  return array_diff($dirContents,array(".",".."));
}

#############################################################
#                                                           #
# Resolve the appdata share root(s) from docker.cfg and     #
# confine deletions to within them. Backstop against a      #
# crafted/buggy request deleting anything outside appdata.  #
#                                                           #
#############################################################

function appdataCleanupNgAppdataRoots() {
  $dockerOptions = @my_parse_ini_file("/boot/config/docker.cfg");
  $cfgPath = isset($dockerOptions['DOCKER_APP_CONFIG_PATH']) ? $dockerOptions['DOCKER_APP_CONFIG_PATH'] : "/mnt/user/appdata/";
  $cfgPath = rtrim(preg_replace('#/+#','/',trim((string)$cfgPath)),"/");
  if ( $cfgPath === "" ) $cfgPath = "/mnt/user/appdata";
  $share = basename($cfgPath);
  if ( $share === "" ) $share = "appdata";
  $roots = array($cfgPath,"/mnt/user/$share","/mnt/cache/$share");
  return array_values(array_unique(array_filter($roots,"strlen")));
}

function appdataCleanupNgPathWithinAppdata($path) {
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

#############################################################
#                                                           #
# Canonical path for comparison: collapse //, strip the     #
# trailing slash, and normalize /mnt/cache -> /mnt/user so  #
# template paths and live mounts compare reliably.          #
#                                                           #
#############################################################

function appdataCleanupNgCanon($path) {
  $p = rtrim(preg_replace('#/+#','/',trim((string)$path)),"/");
  # collapse any pool / array-disk mount of a share to its /mnt/user view, so the same
  # appdata folder compares equal whether a template/container references it via the pool
  # (e.g. /mnt/fcache/appdata) or the user share (/mnt/user/appdata). Skip mounts that are
  # genuinely NOT the user share.
  if ( preg_match('#^/mnt/([^/]+)(/.*)?$#',$p,$m) ) {
    $skip = array("user","user0","disks","remotes","rootsharecache","addons");
    if ( ! in_array($m[1],$skip,true) ) {
      $p = "/mnt/user".(isset($m[2]) ? $m[2] : "");
    }
  }
  return $p;
}

# Top-level appdata folder name that owns a path (e.g. .../appdata/kometa/config -> "kometa")
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

#############################################################
#                                                           #
# ZFS dataset awareness. An appdata folder that is an exact #
# ZFS dataset mountpoint must be removed with `zfs destroy`,#
# not rm -rf (which would empty a still-mounted dataset).   #
# A mountpoint that is NOT a known dataset is refused        #
# outright (never rm -rf across a mount boundary).          #
#                                                           #
#############################################################

function appdataCleanupNgZfsAvailable() {
  static $a = null;
  if ( $a !== null ) return $a;
  $o = array(); $rc = 1;
  @exec("command -v zfs 2>/dev/null",$o,$rc);
  $a = ( $rc === 0 );
  return $a;
}

# map of canonical dataset mountpoint -> dataset name
function appdataCleanupNgZfsDatasetMap() {
  static $map = null;
  if ( $map !== null ) return $map;
  $map = array();
  if ( ! appdataCleanupNgZfsAvailable() ) return $map;
  $out = array(); $rc = 1;
  @exec("zfs list -H -o name,mountpoint -t filesystem 2>/dev/null",$out,$rc);
  if ( $rc !== 0 ) return $map;
  foreach ( $out as $line ) {
    $parts = preg_split('/\t+/',rtrim($line,"\n"));
    if ( count($parts) < 2 ) continue;
    $name = trim($parts[0]);
    $mp = appdataCleanupNgCanon($parts[1]);
    if ( $name !== "" && $mp !== "" && $mp[0] === "/" ) $map[$mp] = $name;
  }
  return $map;
}

# dataset name if $path is an EXACT zfs dataset mountpoint, else "" (case-sensitive)
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

# is $path its own mount point (device id differs from its parent)?
function appdataCleanupNgIsMountPoint($path) {
  $rp = @realpath($path);
  if ( $rp === false || ! is_dir($rp) || $rp === "/" ) return false;
  $d = @stat($rp); $p = @stat(dirname($rp));
  return ( is_array($d) && is_array($p) && $d['dev'] !== $p['dev'] );
}

# destroy a dataset, auto-detecting whether -r is required (snapshots/children)
function appdataCleanupNgZfsDestroy($dataset) {
  $ds = trim((string)$dataset);
  if ( $ds === "" ) return array("ok"=>false,"recursive"=>false,"message"=>"missing dataset name");
  $o = array(); $rc = 1;
  @exec("zfs destroy -nvp ".escapeshellarg($ds)." 2>&1",$o,$rc);   # dry-run, non-recursive
  $recursive = ( $rc !== 0 );                                       # needs -r if that failed
  $o = array(); $rc = 1;
  @exec("zfs destroy ".($recursive ? "-r " : "").escapeshellarg($ds)." 2>&1",$o,$rc);
  return array("ok"=>($rc===0),"recursive"=>$recursive,"message"=>trim(implode("\n",$o)));
}

#############################################################
#                                                           #
# Ignore list - folders the user marks to never offer       #
# (e.g. an appdata folder used by a script, not a           #
# container). Persisted on flash, keyed by canonical path.  #
#                                                           #
#############################################################

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

#############################################################
#                                                           #
# Optional filesystem scan: top-level folders physically in #
# the appdata share that are NOT accounted for by any       #
# template, container, or compose stack (covered segments). #
# Less conservative than template-only - opt-in.            #
#                                                           #
#############################################################

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

#############################################################
#                                                           #
# Template cleaner. A "stale" template is a saved Docker    #
# template whose container is no longer installed. Removing #
# it deletes the saved container config only (not the image #
# or any container). Delete is hard-confined to            #
# templates-user/*.xml.                                     #
#                                                           #
#############################################################

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
        if ( strpos(strtolower((string)($v['@attributes']['Target'] ?? '')),"/config") === 0 ) { $seg = $s; $appdata = $v['value']; break; }
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

#############################################################
#                                                           #
# Log to the system log (/var/log/syslog) so messages are   #
# visible in Tools > System Log and captured in the         #
# Tools > Diagnostics bundle a user attaches to support.    #
#                                                           #
#############################################################

function appdataCleanupNgLog($message,$priority=LOG_INFO) {
  openlog("appdata.cleanup.ng",LOG_PID,LOG_USER);
  syslog($priority,(string)$message);
  closelog();
}

#############################################################
#                                                           #
# Build a focused plain-text diagnostics report for support #
# (env, appdata roots, compose state, recent log lines).    #
#                                                           #
#############################################################

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

#############################################################
#                                                           #
# Folder size via du -sb, cached in tmpfs keyed by mtime so #
# repeat scans don't re-walk unchanged orphan folders.      #
#                                                           #
#############################################################

function appdataCleanupNgFolderSizeBytes($path) {
  $real = @realpath($path);
  if ( $real === false || ! is_dir($real) ) return -1;
  $cacheFile = "/var/tmp/appdata.cleanup.ng.sizecache.json";
  $cache = array();
  if ( is_file($cacheFile) ) {
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
  if ( $bytes >= 0 ) {
    $cache[$real] = array((int)$mtime,$bytes);
    @file_put_contents($cacheFile,json_encode($cache),LOCK_EX);
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

#############################################################
#                                                           #
# Appdata folders claimed by docker-compose stacks managed  #
# by Compose Manager. Catches stacks that are 'down'        #
# (no container, no template) so their in-use appdata is    #
# never offered for deletion. Indirect-aware: the real      #
# compose file may live outside the project dir.            #
#                                                           #
#############################################################

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

function appdataCleanupNgComposeReferencedPaths() {
  $projectsDir = "/boot/config/plugins/compose.manager/projects";
  if ( ! is_dir($projectsDir) ) return array();
  $roots = appdataCleanupNgAppdataRoots();
  if ( empty($roots) ) return array();

  $escaped = array();
  foreach ( $roots as $r ) $escaped[] = preg_quote($r,"#");
  $pattern = "#(".implode("|",$escaped).")/([^\\s:'\"\\\\]+)#";

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
      if ( $contents === false || $contents === "" ) continue;
      $contents = appdataCleanupNgExpandEnv($contents,$env);
      if ( preg_match_all($pattern,$contents,$matches,PREG_SET_ORDER) ) {
        foreach ( $matches as $hit ) {
          $firstSeg = strtok($hit[2],"/");         # appdata folder name under the root
          if ( $firstSeg === false || $firstSeg === "" ) continue;
          $full = $hit[1]."/".$firstSeg;
          $protected[str_replace("/mnt/cache/","/mnt/user/",$full)] = true;
          $protected[str_replace("/mnt/user/","/mnt/cache/",$full)] = true;
          $protected[$full] = true;
        }
      }
      # fail-safe: a volume host root is an UNRESOLVED ${var}/$var (e.g. set in the
      # shell, not .env), but its next segment names an EXISTING appdata folder.
      # Protect it conservatively so an in-use folder is never offered for deletion.
      if ( preg_match_all('#\$(?:\{[A-Za-z_][A-Za-z0-9_]*(?::?-[^}]*)?\}|[A-Za-z_][A-Za-z0-9_]*)/([A-Za-z0-9][A-Za-z0-9._-]*)#',$contents,$uns,PREG_SET_ORDER) ) {
        foreach ( $uns as $uh ) {
          $seg = $uh[1];
          foreach ( $roots as $r ) {
            $cand = $r."/".$seg;
            if ( @is_dir($cand) ) {
              $protected[str_replace("/mnt/cache/","/mnt/user/",$cand)] = true;
              $protected[str_replace("/mnt/user/","/mnt/cache/",$cand)] = true;
              $protected[$cand] = true;
            }
          }
        }
      }
    }
  }
  return array_keys($protected);
}

?>