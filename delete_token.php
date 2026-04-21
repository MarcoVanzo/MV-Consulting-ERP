<?php
$file = __DIR__ . '/.google_sync_token.txt';
if (file_exists($file)) unlink($file);
$file2 = __DIR__ . '/api/.google_sync_token.txt';
if (file_exists($file2)) unlink($file2);
echo "Tokens deleted.";
