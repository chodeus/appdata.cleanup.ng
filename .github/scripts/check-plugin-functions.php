<?php
# Fails when a plugin function is called but never defined. php -l cannot see this, so a
# rename that misses a call site would otherwise ship as a fatal on that code path.
$root = $argv[1] ?? "source";
$prefix = "appdataCleanupNg";
$defined = $called = array();

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if (!$file->isFile() || !preg_match('/\.(php|page)$/', $file->getFilename())) continue;
    $tokens = @token_get_all(file_get_contents($file->getPathname()));
    if (!is_array($tokens)) continue;

    // index of the previous and next meaningful tokens, skipping whitespace and comments
    $meaningful = array();
    foreach ($tokens as $i => $t) {
        if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
        $meaningful[] = $i;
    }
    $posOf = array_flip($meaningful);

    foreach ($meaningful as $n => $i) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || strpos($t[1], $prefix) !== 0) continue;
        $prev = $n > 0 ? $tokens[$meaningful[$n - 1]] : null;
        $next = isset($meaningful[$n + 1]) ? $tokens[$meaningful[$n + 1]] : null;

        if (is_array($prev) && $prev[0] === T_FUNCTION) { $defined[$t[1]] = true; continue; }
        // a method call ($x->name(), Cls::name()) is not one of ours
        if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON), true)) continue;
        if ($next === "(") $called[$t[1]] = true;
    }
}
$missing = array_diff(array_keys($called), array_keys($defined));
if ($missing) {
    fwrite(STDERR, "called but not defined: " . implode(", ", $missing) . "\n");
    exit(1);
}
printf("%d plugin functions defined, %d called, all resolve\n", count($defined), count($called));
