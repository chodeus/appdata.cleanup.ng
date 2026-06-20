<?PHP

###############################################################
#                                                             #
# Community Applications copyright 2015-2024, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/appdata.cleanup.ng/include/helpers.php");

############################################
############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################
############################################

switch ($_POST['action']) {

#########################################
#                                       #
# Displays the orphaned appdata folders #
#                                       #
#########################################

case 'getOrphanAppdata':
	libxml_use_internal_errors(true);
  $all_files = glob("/boot/config/plugins/dockerMan/templates-user/*.xml");
  if ( is_dir("/var/lib/docker/tmp") ) {
    $DockerClient = new DockerClient();
    $info = $DockerClient->getDockerContainers();
  } else {
    $info = array();
  }

  # Get the list of appdata folders used by all of the my* templates
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
				# an app's OWN appdata is its /config mount; record who owns that folder
				if ( strpos(strtolower((string)$volumeArray['@attributes']['Target']),"/config") === 0 ) {
					$seg = appdataCleanupNgOwnerSegment($volumeArray['value']);
					if ( $seg !== "" && ! isset($ownedBy[$seg]) ) $ownedBy[$seg] = $o['Name'];
				}
			}
		}
	}

  # build the set of paths actually used by installed containers (canonical form
  # handles trailing slashes and cache/user so live mounts compare reliably)
  $inUse = array();
  foreach ($info as $installedDocker) {
    if ( ! is_array($installedDocker['Volumes']) ) continue;
    foreach ($installedDocker['Volumes'] as $volume) {
      $host = explode(":",$volume);
      $c = appdataCleanupNgCanon($host[0]);
      if ( $c !== "" && $c !== "/" ) $inUse[$c] = true;
    }
  }

  # remove a candidate only if a container uses that folder itself OR something
  # inside it (deleting it would harm a live container). A broad parent mount
  # (e.g. a backup tool mounting all of /mnt/user/appdata) must NOT exclude everything.
  foreach ($availableVolumes as $key => $volume) {
    $cand = appdataCleanupNgCanon($volume['HostDir']);
    foreach ($inUse as $u => $unused) {
      if ( $cand === $u || strpos($u."/",$cand."/") === 0 ) {
        unset($availableVolumes[$key]);
        break;
      }
    }
  }

  # remove "borrowed" references: an appdata folder owned (via its own /config mount)
  # by a different app than the template that surfaced it (e.g. notdaps mounting
  # kometa/config) belongs to that owner, not the borrowing app.
  foreach ($availableVolumes as $key => $volume) {
    $seg = appdataCleanupNgOwnerSegment($volume['HostDir']);
    if ( $seg !== "" && isset($ownedBy[$seg]) && $ownedBy[$seg] !== $volume['Name'] ) {
      unset($availableVolumes[$key]);
    }
  }
  
  # remove from list any folders which don't actually exist
  
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

  # remove from list any folders which are equivalent 
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
  $composeProtected = appdataCleanupNgComposeReferencedPaths();
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

  # only offer folders we would actually delete (confined to the appdata share)
  foreach ( $availableVolumes as $key => $volume ) {
    if ( ! appdataCleanupNgPathWithinAppdata($volume['HostDir']) ) {
      unset($availableVolumes[$key]);
    }
  }

  # drop anything the user has chosen to ignore
  foreach ( $availableVolumes as $key => $volume ) {
    if ( appdataCleanupNgIsIgnored($volume['HostDir']) ) unset($availableVolumes[$key]);
  }

  # optional, opt-in: direct filesystem scan for template-less folders not covered
  # by any template, container, or compose stack
  $fsOrphans = array();
  if ( getPost("fsscan","no") === "yes" ) {
    $coveredSegs = $templateSegs;
    foreach ( $inUse as $u => $unused ) { $s = appdataCleanupNgOwnerSegment($u); if ( $s !== "" ) $coveredSegs[$s] = true; }
    foreach ( $composeProtected as $p ) { $s = appdataCleanupNgOwnerSegment($p); if ( $s !== "" ) $coveredSegs[$s] = true; }
    $fsOrphans = appdataCleanupNgFilesystemOrphans($coveredSegs);
    foreach ( $fsOrphans as $key => $volume ) {
      if ( appdataCleanupNgIsIgnored($volume['HostDir']) || ! appdataCleanupNgPathWithinAppdata($volume['HostDir']) ) unset($fsOrphans[$key]);
    }
  }

  # one concise line per scan to the system log so "nothing showed up" is diagnosable
  # via Tools > System Log / Diagnostics (no UI noise)
  appdataCleanupNgLog(sprintf("scan - templates=%d, docker_containers=%d, compose_protected=%d, offered=%d%s",
    count($all_files), count($info), count($composeProtected), count($availableVolumes),
    is_dir("/var/lib/docker/tmp") ? "" : " [docker service not running]"));

  $renderRow = function($volume,$noTemplate=false) {
    $sizeLabel = appdataCleanupNgFormatBytes(appdataCleanupNgFolderSizeBytes($volume['HostDir']));
    $zfs = appdataCleanupNgResolveZfsDataset($volume['HostDir']);
    $badges = "";
    if ( $zfs !== "" ) $badges .= "<span class='acng-zfs' title='ZFS dataset: ".htmlspecialchars($zfs)."'>ZFS</span>";
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

  if ( empty($availableVolumes) && empty($fsOrphans) ) {
    echo "<div class='acng-empty'>No orphaned appdata folders found.</div>";
  } else {
    echo "<div class='acng-list'>";
    foreach ($availableVolumes as $volume) echo $renderRow($volume,false);
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

  # optional, opt-in: stale templates (saved templates with no installed container)
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
  
########################################
#                                      #
# Deletes the selected appdata folders #
#                                      #
########################################

case "deleteAppdata":
  $paths = getPost("paths","no");
  $paths = explode("*",$paths);
  $zfsEnabled = getPost("zfs","no") === "yes";
  $refused = array();
  foreach ($paths as $path) {
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
    exec ("rm -rf ".escapeshellarg($userPath));
  }
  if ( ! empty($refused) ) {
    appdataCleanupNgLog("refused/failed delete: ".implode(", ",$refused),LOG_WARNING);
  }
  echo "deleted";
  break;

########################################
#                                      #
# Plain-text diagnostics for support   #
#                                      #
########################################

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
  $files = explode("*",getPost("files",""));
  $n = 0;
  foreach ( $files as $f ) if ( appdataCleanupNgDeleteTemplate($f) ) $n++;
  appdataCleanupNgLog("deleted ".$n." stale template(s)",LOG_INFO);
  echo "deleted ".$n;
  break;


}
?>
