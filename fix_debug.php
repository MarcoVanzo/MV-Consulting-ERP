<?php
$content = file_get_contents('api/Controllers/GoogleAuthController.php');
$content = str_replace('/../../uploads/match_debug.txt', '/../match_debug.txt', $content);
file_put_contents('api/Controllers/GoogleAuthController.php', $content);
