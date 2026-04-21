<?php
header('Content-Type: text/plain');
$file = __DIR__ . '/match_debug.txt';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo "No log found.";
}
