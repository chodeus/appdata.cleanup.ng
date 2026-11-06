<?php
# Self-test for check-plugin-functions.php: each case is one fixture file and the exit code the checker must return.
$checker = $argv[1] ?? __DIR__ . "/check-plugin-functions.php";
$cases = array(
    "defined and called resolves"        => array(0, '<?php function appdataCleanupNgA() {} appdataCleanupNgA();'),
    "missing global is reported"         => array(1, '<?php appdataCleanupNgMissing();'),
    "class method is not a global"       => array(1, '<?php class K { function appdataCleanupNgF() {} } appdataCleanupNgF();'),
    "Foo::class is not a declaration"    => array(0, '<?php class M {} $n = M::class; function appdataCleanupNgH() {} appdataCleanupNgH();'),
    "by-reference declaration counts"    => array(0, '<?php function &appdataCleanupNgG() { static $x; return $x; } appdataCleanupNgG();'),
    "anonymous class args with a closure" => array(1, '<?php $o = new class((function () {})()) { public function appdataCleanupNgE() {} }; appdataCleanupNgE();'),
    "method call is not a global call"   => array(0, '<?php class P { function appdataCleanupNgQ() {} } (new P)->appdataCleanupNgQ(); P::appdataCleanupNgQ();'),
    "string callback is a call"          => array(1, '<?php function appdataCleanupNgA() {} array_map("appdataCleanupNgB", array());'),
    "string callback that resolves"      => array(0, "<?php function appdataCleanupNgA() {} array_map('appdataCleanupNgA', array());"),
    "a sentence naming one is not a call" => array(0, '<?php $s = "appdataCleanupNgGone was renamed";'),
    "short open tag is PHP"              => array(1, '<? appdataCleanupNgMissing(); ?>'),
    // [2] php flags, [3] fixture mode; a null fixture writes no file
    "short tags off is refused"          => array(2, '<?php', "-d short_open_tag=0"),
    "unreadable file is reported"        => array(1, '<?php', null, 0),
    "no files is reported"               => array(1, null),
);
$failed = 0;
foreach ($cases as $name => $case) {
    list($want, $src) = $case;
    $flags = $case[2] ?? "-d short_open_tag=1";
    $dir = sys_get_temp_dir() . "/acng-checker-" . getmypid() . "-" . md5($name);
    $fixture = $dir . "/fixture.php";
    @mkdir($dir);
    if ($src !== null) file_put_contents($fixture, $src);
    if (isset($case[3])) chmod($fixture, $case[3]);
    if (isset($case[3]) && is_readable($fixture)) {
        printf("SKIP  %s (root reads any file)\n", $name);
        unlink($fixture); rmdir($dir);
        continue;
    }
    $out = array(); $rc = 1;
    exec("php " . $flags . " " . escapeshellarg($checker) . " " . escapeshellarg($dir) . " 2>&1", $out, $rc);
    if ($src !== null) unlink($fixture);
    rmdir($dir);
    $ok = ($rc === $want);
    printf("%s  %s (exit %d, want %d)\n", $ok ? "PASS" : "FAIL", $name, $rc, $want);
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
