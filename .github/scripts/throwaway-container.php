<?php
// Tests that wipe fixture roots: exit 2 unless in a container where no mount sits at, above or inside one of $roots.
function acngTestRefuseUnlessThrowaway($roots) {
  if ( ! is_file("/.dockerenv") ) { fwrite(STDERR, "refusing to run outside a container: this test writes ".implode(", ",$roots)."\n"); exit(2); }
  $table = @file("/proc/self/mountinfo",FILE_IGNORE_NEW_LINES);
  if ( $table === false ) { fwrite(STDERR, "refusing to run: the mount table can't be read\n"); exit(2); }
  foreach ( $table as $line ) {
    $mount = stripcslashes(explode(" ",$line)[4] ?? "/");   # mountinfo escapes a space as \040
    if ( $mount === "/" ) continue;
    foreach ( $roots as $root ) {
      if ( $mount === $root || strpos($root,$mount."/") === 0 || strpos($mount,$root."/") === 0 ) { fwrite(STDERR, "refusing to run: $mount is a mount, and this test wipes $root\n"); exit(2); }
    }
  }
}
