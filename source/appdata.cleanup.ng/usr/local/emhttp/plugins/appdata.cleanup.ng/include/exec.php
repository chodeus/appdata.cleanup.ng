<?PHP

###############################################################
#                                                             #
# Community Applications copyright 2015-2024, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php");

switch ($_POST['action']) {

case 'getOrphanAppdata':
	libxml_use_internal_errors(true);
  $all_files = glob("/boot/config/plugins/dockerMan/templates-user/*.xml");
  $dockerRunning = is_dir("/var/lib/docker/tmp");
  $dockerHealthy = true;
  if ( $dockerRunning ) {
    $DockerClient = new DockerClient();
    $info = $DockerClient->getDockerContainers();
    # getDockerContainers() returns [] for BOTH "no containers" and an API failure; disambiguate before trusting it
    if ( empty($info) ) $dockerHealthy = appdataCleanupNgContainerListTrustworthy($DockerClient);
  } else {
    $info = array();
  }

  # fail closed: if Docker is stopped OR running-but-unreachable, the in-use set is untrustworthy -> offer nothing rather than risk a live folder
  if ( ! $dockerRunning || ! $dockerHealthy ) {
    appdataCleanupNgLog("docker service not running or unreachable during scan; offered nothing (fail closed)",LOG_WARNING);
    echo "<div class='acng-empty'>Docker isn't running (or its engine can't be reached), so in-use appdata can't be determined. Nothing is being offered, to avoid deleting folders that are actually in use. Reload once Docker is responsive.</div>";
    break;
  }

  $availableVolumes = array();
  $ownedBy = array();   # owning appdata folder (e.g. "kometa") -> app that owns it via its /config mount
  $templateSegs = array();  # every appdata folder any template references (for the optional filesystem scan)
	foreach ( $all_files as $xmlfile) {
		$o = readXmlFile($xmlfile);
		if ( !$o ) continue;
		foreach ( appdataCleanupNgTemplateConfigs($o) as $volumeArray) {
			if ( ! isset($volumeArray['@attributes']) ) {
				continue;
			}
			if ( ($volumeArray['@attributes']['Type'] ?? "") !== "Path" )
				continue;
			$hostDir = (string)($volumeArray['value'] ?? "");
			$target  = (string)($volumeArray['@attributes']['Target'] ?? "");
			if ( $hostDir === "" || $target === "" ) continue;
			$tplSeg = appdataCleanupNgOwnerSegment($hostDir);
			if ( $tplSeg !== "" ) $templateSegs[$tplSeg] = true;
			$volumeList[0] = $hostDir.":".$target;
			if ( findAppdata($volumeList) ) {
				$temp['Name'] = $o['Name'] ?? "";
				$temp['HostDir'] = $hostDir;
				$availableVolumes[$hostDir] = $temp;
				# an app's OWN appdata is its /config mount
				if ( appdataCleanupNgIsConfigTarget($target) ) {
					$seg = appdataCleanupNgOwnerSegment($hostDir);
					if ( $seg !== "" && ! isset($ownedBy[$seg]) ) $ownedBy[$seg] = $temp['Name'];
				}
			}
		}
	}

  # canonical form so cache/user and trailing-slash differences compare reliably
  # canonical host path -> container name -> raw mounts as configured (keys-as-set); the key set IS the in-use set
  $inUseBy = array();
  foreach ($info as $installedDocker) {
    if ( ! is_array($installedDocker['Volumes']) ) continue;
    foreach ($installedDocker['Volumes'] as $volume) {
      $host = explode(":",$volume);
      # a mount through a symlink also protects the link's target
      foreach ( appdataCleanupNgPathViews($host[0]) as $c ) $inUseBy[$c][(string)$installedDocker['Name']][$host[0]] = true;
    }
  }

  # match folder-itself or anything inside it, but a broad parent mount (e.g. a backup tool mounting all of /mnt/user/appdata) must NOT exclude every candidate
  foreach ($availableVolumes as $key => $volume) {
    $cand = appdataCleanupNgCanon($volume['HostDir']);
    foreach ($inUseBy as $u => $unused) {
      if ( appdataCleanupNgPathUnder($u,$cand) ) {
        unset($availableVolumes[$key]);
        break;
      }
    }
  }

  # drop "borrowed" folders: owned via /config by a different app than the template that surfaced them
  foreach ($availableVolumes as $key => $volume) {
    $seg = appdataCleanupNgOwnerSegment($volume['HostDir']);
    if ( $seg !== "" && isset($ownedBy[$seg]) && $ownedBy[$seg] !== $volume['Name'] ) {
      unset($availableVolumes[$key]);
    }
  }
  
  $temp = $availableVolumes;
  foreach ($availableVolumes as $volume) {
    $userFolder = str_replace("/mnt/cache/","/mnt/user/",$volume['HostDir']);
    
    if ( ! is_dir($userFolder) ) {
      unset($temp[$volume['HostDir']]);
    }
		if ( $userFolder == "/" || $userFolder == "/mnt/" || $userFolder == "/mnt/user/" ) {
			unset($temp[$volume['HostDir']]);
		}
  }
  $availableVolumes = $temp;

  $tempArray = $availableVolumes;
  foreach ( $availableVolumes as $volume ) {
    $flag = false;
    foreach ( $availableVolumes as $testVolume ) {
      if ( $testVolume['HostDir'] == $volume['HostDir'] ) {
        continue; # ie: its the same index in the array;
      }
     $cacheFolder = str_replace("/mnt/user/","/mnt/cache/",$volume['HostDir']);
     $userFolder = str_replace("/mnt/cache/","/mnt/user/",$volume['HostDir']);
      if ( startswith($testVolume['HostDir'],$cacheFolder) || startsWith($testVolume['HostDir'],$userFolder) ) {
        $flag = true;
        break;
      }
    }
    if ( $flag ) {
      unset($tempArray[$volume['HostDir']]);
    }
  }
  $availableVolumes = $tempArray;

  # remove folders claimed by docker-compose stacks (Compose Manager), incl. 'down' stacks
  $composeUncertain = array();
  $composeRootBound = array();
  $composeProtected = appdataCleanupNgComposeReferencedPaths($composeUncertain,$composeRootBound);
  if ( ! empty($composeProtected) ) {
    $composeSet = array_flip($composeProtected);
    foreach ( $availableVolumes as $key => $volume ) {
      if ( appdataCleanupNgCoveredBy(appdataCleanupNgCanon($volume['HostDir']),$composeSet) ) {
        unset($availableVolumes[$key]);
      }
    }
  }

  foreach ( $availableVolumes as $key => $volume ) {
    if ( ! appdataCleanupNgPathWithinAppdata($volume['HostDir']) ) {
      unset($availableVolumes[$key]);
    }
  }

  foreach ( $availableVolumes as $key => $volume ) {
    if ( appdataCleanupNgIsIgnored($volume['HostDir']) ) unset($availableVolumes[$key]);
  }

  # opt-in: filesystem scan for template-less folders not covered by any template, container, or compose stack
  $fsOrphans = array();
  $fsScanSkipped = "";
  $fsScanWarning = "";
  if ( getPost("fsscan","no") === "yes" ) {
    if ( $composeUncertain ) {
      $fsScanSkipped = appdataCleanupNgExplain("","A Docker Compose stack can't be fully read, so the scan can't tell which folders are in use","Fix or remove the stack in Compose Manager",$composeUncertain);
    } else {
      # a container or stack that mounts a whole appdata root can reach every folder: the scan runs, and says which
      $rootMounters = array_unique(array_merge(appdataCleanupNgRootMounters($info),$composeRootBound));
      if ( $rootMounters ) $fsScanWarning = implode(", ",$rootMounters)." can reach every appdata folder through a whole-share mount. Check that none of these folders is theirs.";
      $coveredSegs = $templateSegs;
      foreach ( $inUseBy as $u => $unused ) { $s = appdataCleanupNgOwnerSegment($u); if ( $s !== "" ) $coveredSegs[$s] = true; }
      foreach ( $composeProtected as $p ) { $s = appdataCleanupNgOwnerSegment($p); if ( $s !== "" ) $coveredSegs[$s] = true; }
      $fsOrphans = appdataCleanupNgFilesystemOrphans($coveredSegs);
      foreach ( $fsOrphans as $key => $volume ) {
        if ( appdataCleanupNgIsIgnored($volume['HostDir']) || ! appdataCleanupNgPathWithinAppdata($volume['HostDir']) ) unset($fsOrphans[$key]);
      }
    }
    if ( $fsScanSkipped !== "" ) appdataCleanupNgLog("fsscan skipped (fail closed): ".$fsScanSkipped,LOG_WARNING);
  }

  # log one line per scan so "nothing showed up" is diagnosable without UI noise
  appdataCleanupNgLog(sprintf("scan - templates=%d, docker_containers=%d, compose_protected=%d, offered=%d, fs_offered=%d%s",
    count($all_files), count($info), count($composeProtected), count($availableVolumes), count($fsOrphans),
    is_dir("/var/lib/docker/tmp") ? "" : " [docker service not running]"));

  $renderRow = function($volume,$noTemplate=false,$mountedBy=array()) {
    $sizeLabel = appdataCleanupNgFormatBytes(appdataCleanupNgFolderSizeBytes($volume['HostDir']));
    $zfs = appdataCleanupNgResolveZfsDataset($volume['HostDir']);
    $badges = "";
    if ( $zfs !== "" ) $badges .= "<span class='acng-zfs' title='ZFS dataset: ".htmlspecialchars($zfs,ENT_QUOTES)."'>ZFS</span>";
    if ( ! empty($mountedBy) ) {
      ksort($mountedBy,SORT_STRING);   # SORT_STRING: all-numeric container names become int keys, keep alphabetical intent
      # structured tooltip content; hidden ([hidden] + CSS, aria-hidden so it can't join the checkbox label), shown as a floating tip by the page JS (native title can't be styled)
      $tip = "<span class='acng-tipbox' hidden aria-hidden='true'>"
           . "<span class='acng-tiphead'>A container (running or stopped) or a Compose stack bind-mounts a parent of this folder &mdash; it may still be in use.</span>";
      foreach ($mountedBy as $n => $paths) {
        $paths = array_keys($paths);
        sort($paths,SORT_STRING);
        # ENT_SUBSTITUTE: an invalid-UTF-8 byte in a path must not blank the whole line (bare flags return "")
        $tip .= "<span class='acng-tiprow'><span class='acng-tipname'>".htmlspecialchars((string)$n,ENT_QUOTES|ENT_SUBSTITUTE)."</span>"
              . "<span class='acng-tippaths'>".htmlspecialchars(implode(", ",$paths),ENT_QUOTES|ENT_SUBSTITUTE)."</span></span>";
      }
      $tip .= "</span>";
      $badges .= "<span class='acng-notpl acng-hastip' tabindex='0'>in use by mount".$tip."</span>";
    }
    if ( $noTemplate ) $badges .= "<span class='acng-notpl' title='No saved template references this folder'>no template</span>";
    $h = htmlspecialchars($volume['HostDir'],ENT_QUOTES);
    $zfsAttr = $zfs !== "" ? " data-zfs='1'" : "";
    return "<div class='acng-row'>"
         . "<label class='acng-rowmain'>"
         . "<input type='checkbox' class='appdata' value='".$h."'".$zfsAttr.">"
         . "<span class='acng-app'>".htmlspecialchars($volume['Name']).$badges."</span>"
         . "<span class='acng-path'>".htmlspecialchars($volume['HostDir'])."</span>"
         . "<span class='acng-size'>".htmlspecialchars($sizeLabel)."</span>"
         . "</label>"
         . "<span class='acng-ignore' data-path='".$h."' onclick='ignoreThis(this)' title='Never offer this folder'>ignore</span>"
         . "</div>";
  };

  if ( $fsScanSkipped !== "" ) {
    echo "<div class='acng-empty'>&#9888; ".nl2br(htmlspecialchars($fsScanSkipped))."</div>";
  }
  if ( empty($availableVolumes) && empty($fsOrphans) ) {
    echo "<div class='acng-empty'>No orphaned appdata folders found.</div>";
  } else {
    echo "<div class='acng-list'>";
    # badge (don't hide) a real orphan that also sits under a broad in-use bind mount, so the user is warned it may be live
    $mountedBy = function($hostDir) use ($inUseBy,$composeRootBound) {
      $by = array();   # container or stack name -> parent-mount set that reaches this folder
      $cand = appdataCleanupNgCanon($hostDir);
      foreach ($inUseBy as $u => $containers) {
        if ( $u !== $cand && appdataCleanupNgPathUnder($cand,$u) ) {
          foreach ($containers as $n => $paths) $by[$n] = isset($by[$n]) ? $by[$n] + $paths : $paths;
        }
      }
      # a stack's whole-share bind reaches every appdata folder
      foreach ( $composeRootBound as $stack ) $by[$stack." (Compose stack)"] = array("a whole share" => true);
      return $by;
    };
    foreach ($availableVolumes as $volume) echo $renderRow($volume,false,$mountedBy($volume['HostDir']));
    if ( ! empty($fsOrphans) ) {
      echo "<div class='acng-section'>&#9888; No saved template &mdash; found by filesystem scan. Verify carefully before deleting.</div>";
      if ( $fsScanWarning !== "" ) echo "<div class='acng-section'>&#9888; ".htmlspecialchars($fsScanWarning)."</div>";
      foreach ($fsOrphans as $volume) echo $renderRow($volume,true,$mountedBy($volume['HostDir']));
    }
    echo "</div>";
  }

  $ignored = array_keys(appdataCleanupNgIgnoreList());
  if ( ! empty($ignored) ) {
    echo "<div class='acng-ignored'><div class='acng-sub'>Ignored (never offered):</div>";
    foreach ($ignored as $ip) {
      $h = htmlspecialchars($ip,ENT_QUOTES);
      echo "<div class='acng-irow'><span class='acng-ipath'>".htmlspecialchars($ip)."</span>"
         . "<span class='acng-unignore' data-path='".$h."' onclick='unignoreThis(this)'>remove</span></div>";
    }
    echo "</div>";
  }

  # opt-in: stale templates (saved templates with no installed container)
  if ( getPost("stale","no") === "yes" ) {
    $installedNames = array();
    foreach ( $info as $c ) if ( ! empty($c['Name']) ) $installedNames[] = $c['Name'];
    $stale = appdataCleanupNgStaleTemplates($installedNames);
    if ( empty($stale) ) {
      echo "<div class='acng-templates'><div class='acng-sub'>Stale templates: none (every saved template has an installed container).</div></div>";
    } else {
      echo "<div class='acng-templates'><div class='acng-sub'>&#9888; Stale templates (no installed container) &mdash; deleting removes the saved container config only:</div>";
      foreach ( $stale as $t ) {
        $exists = $t['appdata'] !== "" && is_dir(str_replace("/mnt/cache/","/mnt/user/",$t['appdata']));
        $h = htmlspecialchars($t['file'],ENT_QUOTES);
        echo "<div class='acng-irow'><label class='acng-rowmain'>"
           . "<input type='checkbox' class='staletpl' value='".$h."'>"
           . "<span class='acng-tname'>".htmlspecialchars($t['name'])."</span>"
           . "<span class='acng-ipath'>".htmlspecialchars(basename($t['file'])).($exists ? " &middot; appdata still present" : "")."</span>"
           . "</label></div>";
      }
      echo "<div class='acng-toolbar' style='margin-top:10px'>"
         . "<input type='button' onclick='toggleSelectAllTemplates(this);' value='Select All' id='selectAllTpl'>"
         . "<input type='button' class='acng-tpl-delete' onclick='deleteSelectedTemplates();' value='Delete Selected Templates'>"
         . "</div>";
      echo "</div>";
    }
  }
  break;

case "deleteAppdata":
  # array param avoids the old "*"-separator collision (a folder whose name contains "*" split into two real siblings)
  $paths = ( isset($_POST['paths']) && is_array($_POST['paths']) ) ? $_POST['paths'] : explode("*",(string)getPost("paths",""));
  $zfsEnabled = getPost("zfs","no") === "yes";
  # orphan status is only trustworthy from the live container list; if we can't read it, refuse (fail closed)
  # rather than trust the client's submitted paths while the in-use set is unknowable (mirrors deleteTemplates)
  if ( ! is_dir("/var/lib/docker/tmp") ) {
    appdataCleanupNgLog("deleteAppdata refused: docker service not running (can't confirm orphan status)",LOG_WARNING);
    echo appdataCleanupNgDockerRefusal(false); break;
  }
  $dcDel = new DockerClient();
  $liveDel = $dcDel->getDockerContainers();
  if ( empty($liveDel) && ! appdataCleanupNgContainerListTrustworthy($dcDel) ) {
    appdataCleanupNgLog("deleteAppdata refused: docker engine unreachable (can't confirm orphan status)",LOG_WARNING);
    echo appdataCleanupNgDockerRefusal(true); break;
  }
  # The scan that produced these paths is a snapshot; a container can start before the user
  # confirms. Re-check against the live mounts and the compose set instead of trusting it.
  $inUseNow = appdataCleanupNgInUsePaths($liveDel);
  $composeNow = array();
  $composeUncertainDel = array();
  foreach ( appdataCleanupNgComposeReferencedPaths($composeUncertainDel) as $cp ) $composeNow[$cp] = true;
  # fail closed: a stack that can't be fully read (see appdataCleanupNgComposeReferencedPaths) means the
  # protected set is incomplete, so we cannot prove a stopped stack is not being deleted
  if ( $composeUncertainDel ) {
    appdataCleanupNgLog("deleteAppdata refused: compose protection incomplete (".implode("; ",$composeUncertainDel).")",LOG_WARNING);
    echo appdataCleanupNgExplain("","A Docker Compose stack can't be fully read, so a stopped stack can't be ruled out","Fix or remove the stack in Compose Manager, then try again",$composeUncertainDel);
    break;
  }
  $refused = array();
  $partly = 0;   # folders rm got partway through: they are listed, but not counted as untouched
  foreach ($paths as $path) {
    $path = (string)$path;
    if ( $path === "" ) { $refused[] = appdataCleanupNgExplain("(empty path)","The page sent an empty path","Reload the page and try again"); continue; }
    # a forged value must never reach syslog or the reply verbatim
    if ( appdataCleanupNgHasControlChars($path) ) { $refused[] = appdataCleanupNgExplain("(invalid path)","The page sent a path with control characters","Reload the page and try again"); continue; }
    if ( ! appdataCleanupNgPathWithinAppdata($path) ) {
      $refused[] = appdataCleanupNgExplain($path,"Outside the appdata share, so this plugin won't delete it","Delete it yourself if you're sure nothing needs it");
      continue;
    }
    # resolve symlinks BEFORE any protection check: a link inside appdata would otherwise pass the checks
    # under its own name while rm/zfs acts on its target, so every guard below runs on $real
    $real = @realpath(str_replace("/mnt/cache/","/mnt/user/",$path));
    if ( $real === false ) {
      $refused[] = appdataCleanupNgExplain($path,"Already gone","Reload the page");
      continue;
    }
    if ( ! appdataCleanupNgPathWithinAppdata($real) ) {
      $refused[] = appdataCleanupNgExplain($path,"A link to a folder outside the appdata share, so this plugin won't delete it","Delete the link yourself if you're sure nothing needs it");
      continue;
    }
    # a container or stack may reference the link or its target, so both names are checked; containment is
    # one-direction on purpose (as in the scan): a parent mount such as /mnt/user is badged there, not in-use
    $claimed = false; $live = false;
    foreach ( array_unique(array(appdataCleanupNgCanon($path),appdataCleanupNgCanon($real))) as $c ) {
      if ( appdataCleanupNgCoveredBy($c,$composeNow) ) $claimed = true;
      foreach ( $inUseNow as $u => $unused ) if ( appdataCleanupNgPathUnder($u,$c) ) $live = true;
    }
    if ( $claimed ) { $refused[] = appdataCleanupNgExplain($path,"Used by a Compose stack","Remove the stack, or its mapping, in Compose Manager first"); continue; }
    if ( $live )    { $refused[] = appdataCleanupNgExplain($path,"Mapped into an installed container","Remove the container, or change its mapping, first"); continue; }
    # ZFS dataset: must be destroyed, never rm -rf (which empties a mounted dataset)
    $dataset = appdataCleanupNgResolveZfsDataset($real);
    if ( $dataset !== "" ) {
      if ( ! $zfsEnabled ) {
        $refused[] = appdataCleanupNgExplain($path,"A ZFS dataset, and ZFS dataset deletion is off","Tick \"Allow ZFS dataset deletion\" in the Danger Zone, then try again");
        continue;
      }
      $r = appdataCleanupNgZfsDestroy($dataset);
      if ( $r['ok'] ) {
        appdataCleanupNgLog("zfs destroy".($r['recursive'] ? " -r" : "")." ".$dataset,LOG_INFO);
      } else {
        appdataCleanupNgLog("zfs destroy failed for ".$dataset.": ".$r['message'],LOG_WARNING);
        # mountpoints are anyone's to name: Explain puts each on one line of its own
        if ( isset($r['outside']) ) $refused[] = appdataCleanupNgExplain($path,"Not destroyed: zfs destroy -r would also destroy these child datasets, which are outside this folder",
          "Check them with zfs list -r ".$dataset.", destroy or move them yourself, then try again",$r['outside']);
        else if ( isset($r['blocked']) ) $refused[] = appdataCleanupNgExplain($path,"Not destroyed: ".$r['blocked'],"Try again, or check them with zfs list -r ".$dataset);
        else $refused[] = appdataCleanupNgExplain($path,"Not destroyed: zfs destroy failed","If the dataset is busy, stop what uses it, then try again",array_filter(array($r['message']),"strlen"));
      }
      continue;
    }
    # never rm -rf across a mount boundary that isn't a recognized dataset
    if ( appdataCleanupNgIsMountPoint($real) ) {
      $refused[] = appdataCleanupNgExplain($path,"A mount point (a share, disk or remote mount), not a ZFS dataset this plugin knows","Unmount it first, or delete it yourself");
      continue;
    }
    # rm -rf would also empty a filesystem mounted further inside (a child dataset, a bind mount)
    $inner = appdataCleanupNgMountsInside($real);
    if ( $inner === null || $inner ) {
      $refused[] = $inner === null ? appdataCleanupNgExplain($path,"The mount table can't be read, so a mount inside it can't be ruled out","Try again")
        : appdataCleanupNgExplain($path,"Another filesystem is mounted inside it","Unmount or delete that first, then try again",array($inner[0]));
      continue;
    }
    $rmOut = array(); $rmRc = 1;
    exec("rm -rf ".escapeshellarg($real)." 2>&1",$rmOut,$rmRc);
    if ( $rmRc !== 0 || @file_exists($real) ) {
      # rm names the blocker (busy, permission); one clean line, since it can echo hostile file names
      $partly++;
      $refused[] = appdataCleanupNgExplain($path,"Not fully deleted","A file may be in use or read-only. Check the System Log, then try again",array_slice($rmOut,0,3));
    }
  }
  if ( ! empty($refused) ) {
    appdataCleanupNgLog("refused/failed delete: ".implode(", ",$refused),LOG_WARNING);
    echo "refused ".(count($refused)-$partly)." of ".count($paths).":\n".implode("\n\n",$refused);
    break;
  }
  echo "deleted";
  break;

case "captureDiagnostics":
  echo appdataCleanupNgBuildDiagnostics();
  break;

case "ignorePath":
  echo appdataCleanupNgAddIgnore(getPost("path","")) ? "ok" : "failed";
  break;

case "unignorePath":
  echo appdataCleanupNgRemoveIgnore(getPost("path","")) ? "ok" : "failed";
  break;

case "deleteTemplates":
  $files = ( isset($_POST['files']) && is_array($_POST['files']) ) ? $_POST['files'] : explode("*",(string)getPost("files",""));
  # staleness can only be judged from the live container list; if we can't read it, refuse (fail closed) rather than
  # treat an empty list as "everything is stale" and delete the saved config of a running app
  if ( ! is_dir("/var/lib/docker/tmp") ) {
    appdataCleanupNgLog("deleteTemplates refused: docker service not running (can't confirm staleness)",LOG_WARNING);
    echo appdataCleanupNgDockerRefusal(false); break;
  }
  $dc = new DockerClient();
  $info = $dc->getDockerContainers();
  if ( empty($info) && ! appdataCleanupNgContainerListTrustworthy($dc) ) {
    appdataCleanupNgLog("deleteTemplates refused: docker engine unreachable (can't confirm staleness)",LOG_WARNING);
    echo appdataCleanupNgDockerRefusal(true); break;
  }
  $installedNames = array();
  foreach ( (array)$info as $c ) if ( ! empty($c['Name']) ) $installedNames[] = $c['Name'];
  $staleFiles = array();
  foreach ( appdataCleanupNgStaleTemplates($installedNames) as $t ) $staleFiles[$t['file']] = true;
  $n = 0; $refusedTpl = array();
  foreach ( $files as $f ) {
    $f = (string)$f;
    if ( appdataCleanupNgHasControlChars($f) ) { $refusedTpl[] = appdataCleanupNgExplain("(invalid template path)","The page sent a path with control characters","Reload the page and try again"); continue; }
    if ( ! isset($staleFiles[$f]) ) { $refusedTpl[] = appdataCleanupNgExplain(basename($f),"Not a stale template any more","Reload the page"); continue; }
    if ( appdataCleanupNgDeleteTemplate($f) ) $n++;
    else $refusedTpl[] = appdataCleanupNgExplain(basename($f),"Delete failed","Check the System Log, then try again");
  }
  appdataCleanupNgLog("deleted ".$n." stale template(s)".( $refusedTpl ? ", refused: ".implode(", ",$refusedTpl) : "" ),LOG_INFO);
  if ( ! empty($refusedTpl) ) { echo "refused ".count($refusedTpl)." of ".count($files).":\n".implode("\n\n",$refusedTpl); break; }
  echo "deleted ".$n;
  break;


}
?>
