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
    if ( empty($info) ) $dockerHealthy = appdataCleanupNgDockerEngineReachable($DockerClient);
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
		if ( ! is_array($o['Config']) ) continue;

		foreach ($o['Config'] as $volumeArray) {
			if ( ! isset($volumeArray['@attributes']) ) {
				continue;
			}
			if ( $volumeArray['@attributes']['Type'] !== "Path" )
				continue;
			$tplSeg = appdataCleanupNgOwnerSegment($volumeArray['value']);
			if ( $tplSeg !== "" ) $templateSegs[$tplSeg] = true;
			$volumeList[0] = $volumeArray['value'].":".$volumeArray['@attributes']['Target'];
			if ( findAppdata($volumeList) ) {
				$temp['Name'] = $o['Name'];
				$temp['HostDir'] = $volumeArray['value'];
				$availableVolumes[$volumeArray['value']] = $temp;
				# an app's OWN appdata is its /config mount
				if ( strpos(strtolower((string)$volumeArray['@attributes']['Target']),"/config") === 0 ) {
					$seg = appdataCleanupNgOwnerSegment($volumeArray['value']);
					if ( $seg !== "" && ! isset($ownedBy[$seg]) ) $ownedBy[$seg] = $o['Name'];
				}
			}
		}
	}

  # canonical form so cache/user and trailing-slash differences compare reliably
  $inUse = array();
  foreach ($info as $installedDocker) {
    if ( ! is_array($installedDocker['Volumes']) ) continue;
    foreach ($installedDocker['Volumes'] as $volume) {
      $host = explode(":",$volume);
      $c = appdataCleanupNgCanon($host[0]);
      if ( $c !== "" && $c !== "/" ) $inUse[$c] = true;
    }
  }

  # match folder-itself or anything inside it, but a broad parent mount (e.g. a backup tool mounting all of /mnt/user/appdata) must NOT exclude every candidate
  foreach ($availableVolumes as $key => $volume) {
    $cand = appdataCleanupNgCanon($volume['HostDir']);
    foreach ($inUse as $u => $unused) {
      if ( $cand === $u || strpos($u."/",$cand."/") === 0 ) {
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
  $composeUncertain = false;
  $composeProtected = appdataCleanupNgComposeReferencedPaths($composeUncertain);
  if ( ! empty($composeProtected) ) {
    $composeSet = array_flip($composeProtected);
    foreach ( $availableVolumes as $key => $volume ) {
      $u = str_replace("/mnt/cache/","/mnt/user/",$volume['HostDir']);
      $c = str_replace("/mnt/user/","/mnt/cache/",$volume['HostDir']);
      if ( isset($composeSet[$volume['HostDir']]) || isset($composeSet[$u]) || isset($composeSet[$c]) ) {
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
  if ( getPost("fsscan","no") === "yes" ) {
    # a container that bind-mounts an entire appdata root makes every child "in use"; offering template-less children then is unsafe
    $rootMounted = false;
    foreach ( appdataCleanupNgAppdataRoots() as $r ) {
      $rc = appdataCleanupNgCanon($r);
      foreach ( $inUse as $u => $unused ) {
        if ( $u === $rc || strpos($rc."/",$u."/") === 0 ) { $rootMounted = true; break 2; }
      }
    }
    if ( $composeUncertain ) {
      $fsScanSkipped = "A Docker Compose stack references an unresolved \${VAR} host path, so the scan can't tell which folders are in use. Define it in the project .env to enable the scan.";
    } else if ( $rootMounted ) {
      $fsScanSkipped = "A running container bind-mounts an entire appdata root, so every folder is in use. The filesystem scan is disabled to avoid offering in-use folders.";
    } else {
      $coveredSegs = $templateSegs;
      foreach ( $inUse as $u => $unused ) { $s = appdataCleanupNgOwnerSegment($u); if ( $s !== "" ) $coveredSegs[$s] = true; }
      foreach ( $composeProtected as $p ) { $s = appdataCleanupNgOwnerSegment($p); if ( $s !== "" ) $coveredSegs[$s] = true; }
      $fsOrphans = appdataCleanupNgFilesystemOrphans($coveredSegs);
      foreach ( $fsOrphans as $key => $volume ) {
        if ( appdataCleanupNgIsIgnored($volume['HostDir']) || ! appdataCleanupNgPathWithinAppdata($volume['HostDir']) ) unset($fsOrphans[$key]);
      }
    }
    if ( $fsScanSkipped !== "" ) appdataCleanupNgLog("fsscan skipped (fail closed): ".$fsScanSkipped,LOG_WARNING);
  }

  # log one line per scan so "nothing showed up" is diagnosable without UI noise
  appdataCleanupNgLog(sprintf("scan - templates=%d, docker_containers=%d, compose_protected=%d, offered=%d%s",
    count($all_files), count($info), count($composeProtected), count($availableVolumes),
    is_dir("/var/lib/docker/tmp") ? "" : " [docker service not running]"));

  $renderRow = function($volume,$noTemplate=false,$mounted=false) {
    $sizeLabel = appdataCleanupNgFormatBytes(appdataCleanupNgFolderSizeBytes($volume['HostDir']));
    $zfs = appdataCleanupNgResolveZfsDataset($volume['HostDir']);
    $badges = "";
    if ( $zfs !== "" ) $badges .= "<span class='acng-zfs' title='ZFS dataset: ".htmlspecialchars($zfs,ENT_QUOTES)."'>ZFS</span>";
    if ( $mounted ) $badges .= "<span class='acng-notpl' title='A running container bind-mounts a parent of this folder - it may still be in use'>in use by mount</span>";
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
    echo "<div class='acng-empty'>&#9888; ".htmlspecialchars($fsScanSkipped)."</div>";
  }
  if ( empty($availableVolumes) && empty($fsOrphans) ) {
    echo "<div class='acng-empty'>No orphaned appdata folders found.</div>";
  } else {
    echo "<div class='acng-list'>";
    foreach ($availableVolumes as $volume) {
      # badge (don't hide) a real orphan that also sits under a broad in-use bind mount, so the user is warned it may be live
      $mounted = false;
      $cand = appdataCleanupNgCanon($volume['HostDir']);
      foreach ($inUse as $u => $unused) {
        if ( $u !== $cand && strpos($cand."/",$u."/") === 0 ) { $mounted = true; break; }
      }
      echo $renderRow($volume,false,$mounted);
    }
    if ( ! empty($fsOrphans) ) {
      echo "<div class='acng-section'>&#9888; No saved template &mdash; found by filesystem scan. Verify carefully before deleting.</div>";
      foreach ($fsOrphans as $volume) echo $renderRow($volume,true);
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
    echo "docker not running"; break;
  }
  $dcDel = new DockerClient();
  if ( empty($dcDel->getDockerContainers()) && ! appdataCleanupNgDockerEngineReachable($dcDel) ) {
    appdataCleanupNgLog("deleteAppdata refused: docker engine unreachable (can't confirm orphan status)",LOG_WARNING);
    echo "docker unreachable"; break;
  }
  $refused = array();
  foreach ($paths as $path) {
    $path = (string)$path;
    if ( $path === "" ) continue;
    if ( ! appdataCleanupNgPathWithinAppdata($path) ) {
      $refused[] = $path." (outside appdata)";
      continue;
    }
    # ZFS dataset: must be destroyed, never rm -rf (which empties a mounted dataset)
    $dataset = appdataCleanupNgResolveZfsDataset($path);
    if ( $dataset !== "" ) {
      if ( ! $zfsEnabled ) {
        $refused[] = $path." (ZFS dataset; enable ZFS deletion)";
        continue;
      }
      # re-confine the resolved physical target (mirror the rm branch at 304-312): a symlink in
      # appdata whose target is an external ZFS dataset must NOT be zfs-destroyed
      $realZ = @realpath(str_replace("/mnt/cache/","/mnt/user/",$path));
      if ( $realZ === false || ! appdataCleanupNgPathWithinAppdata($realZ) ) {
        $refused[] = $path." (ZFS dataset resolves outside appdata)";
        continue;
      }
      $r = appdataCleanupNgZfsDestroy($dataset);
      if ( $r['ok'] ) {
        appdataCleanupNgLog("zfs destroy".($r['recursive'] ? " -r" : "")." ".$dataset,LOG_INFO);
      } else {
        appdataCleanupNgLog("zfs destroy failed for ".$dataset.": ".$r['message'],LOG_WARNING);
        $refused[] = $path." (zfs destroy failed)";
      }
      continue;
    }
    # never rm -rf across a mount boundary that isn't a recognized dataset
    if ( appdataCleanupNgIsMountPoint($path) ) {
      $refused[] = $path." (mount point, not a known dataset)";
      continue;
    }
    $userPath = str_replace("/mnt/cache/","/mnt/user/",$path);
    # resolve symlinks and re-confine the PHYSICAL target before deleting: the guards above run on the submitted
    # string, but a symlinked path component would otherwise let rm -rf act outside appdata (verified: GNU rm
    # follows an intermediate symlinked component and a trailing-slash leaf symlink). rm exactly what we validated.
    $real = @realpath($userPath);
    if ( $real === false ) {
      $refused[] = $path." (not found)";
      continue;
    }
    if ( ! appdataCleanupNgPathWithinAppdata($real) || appdataCleanupNgIsMountPoint($real) ) {
      $refused[] = $path." (resolves outside appdata or across a mount)";
      continue;
    }
    exec ("rm -rf ".escapeshellarg($real));
  }
  if ( ! empty($refused) ) {
    appdataCleanupNgLog("refused/failed delete: ".implode(", ",$refused),LOG_WARNING);
  }
  echo "deleted";
  break;

case "captureDiagnostics":
  echo appdataCleanupNgBuildDiagnostics();
  break;

case "ignorePath":
  appdataCleanupNgAddIgnore(getPost("path",""));
  echo "ok";
  break;

case "unignorePath":
  appdataCleanupNgRemoveIgnore(getPost("path",""));
  echo "ok";
  break;

case "deleteTemplates":
  $files = ( isset($_POST['files']) && is_array($_POST['files']) ) ? $_POST['files'] : explode("*",(string)getPost("files",""));
  # staleness can only be judged from the live container list; if we can't read it, refuse (fail closed) rather than
  # treat an empty list as "everything is stale" and delete the saved config of a running app
  if ( ! is_dir("/var/lib/docker/tmp") ) {
    appdataCleanupNgLog("deleteTemplates refused: docker service not running (can't confirm staleness)",LOG_WARNING);
    echo "docker not running"; break;
  }
  $dc = new DockerClient();
  $info = $dc->getDockerContainers();
  if ( empty($info) && ! appdataCleanupNgDockerEngineReachable($dc) ) {
    appdataCleanupNgLog("deleteTemplates refused: docker engine unreachable (can't confirm staleness)",LOG_WARNING);
    echo "docker unreachable"; break;
  }
  $installedNames = array();
  foreach ( (array)$info as $c ) if ( ! empty($c['Name']) ) $installedNames[] = $c['Name'];
  $staleFiles = array();
  foreach ( appdataCleanupNgStaleTemplates($installedNames) as $t ) $staleFiles[$t['file']] = true;
  $n = 0; $refused = 0;
  foreach ( $files as $f ) {
    $f = (string)$f;
    if ( ! isset($staleFiles[$f]) ) { $refused++; continue; }   # not a currently-stale template -> refuse
    if ( appdataCleanupNgDeleteTemplate($f) ) $n++;
  }
  appdataCleanupNgLog("deleted ".$n." stale template(s)".($refused ? ", refused ".$refused." non-stale" : ""),LOG_INFO);
  echo "deleted ".$n;
  break;


}
?>
