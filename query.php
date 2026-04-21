<?php
try {
    $pdo = new PDO("mysql:host=mvsporttravelcom.mydb-aruba.it;dbname=Swp1841802-prod", "Swp1841802", "@iy%0qEawkxX");
    $stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='Swp1841802-prod'");
    $found = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (strpos(strtolower($row['COLUMN_NAME']), 'apwaw') !== false || strpos(strtolower($row['COLUMN_NAME']), 'password') !== false) {
            echo "Found: " . $row['TABLE_NAME'] . " -> " . $row['COLUMN_NAME'] . "\n";
            $found = true;
        }
    }
    if (!$found) echo "No apwaw or password column found.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
