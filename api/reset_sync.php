<?php
$file = __DIR__ . '/../.google_sync_token.txt';
if (file_exists($file)) {
    unlink($file);
    echo "DELETED TOKEN: $file\n";
} else {
    echo "NO TOKEN AT: $file\n";
}
// delete the debug log too just to be fresh
$dlog = __DIR__ . '/match_debug.txt';
if (file_exists($dlog)) {
    unlink($dlog);
    echo "DELETED DEBUG LOG\n";
}
