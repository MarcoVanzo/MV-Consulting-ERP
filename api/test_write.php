<?php
$f = __DIR__ . '/../uploads/test.txt';
if (file_put_contents($f, "TEST")) {
    echo "OK: $f";
} else {
    echo "FAIL: $f";
}
