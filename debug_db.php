<?php
$conn = new mysqli('127.0.0.1','root','','dbbarangaymanagement');
if ($conn->connect_error) {
    echo "CONNERR: " . $conn->connect_error . PHP_EOL;
    exit(1);
}
$res = $conn->query('SELECT COUNT(*) AS c FROM residents');
if (!$res) {
    echo "ERR: " . $conn->error . PHP_EOL;
    exit(1);
}
$row = $res->fetch_assoc();
echo "COUNT:" . $row['c'] . PHP_EOL;
$recent = $conn->query('SELECT id,resident_number,first_name,last_name,email,date_registered FROM residents ORDER BY id DESC LIMIT 5');
if ($recent) {
    while ($r = $recent->fetch_assoc()) {
        echo implode(' | ', [$r['id'],$r['resident_number'],$r['first_name'],$r['last_name'],$r['email'],$r['date_registered']]) . PHP_EOL;
    }
}
echo PHP_EOL . "--- TABLE COLUMNS ---" . PHP_EOL;
$cols = $conn->query('SHOW COLUMNS FROM residents');
if ($cols) {
    while ($c = $cols->fetch_assoc()) {
        echo $c['Field'] . "\t" . $c['Type'] . "\t" . $c['Null'] . "\t" . $c['Key'] . PHP_EOL;
    }
} else {
    echo "SHOW COLUMNS ERROR: " . $conn->error . PHP_EOL;
}

echo PHP_EOL . "--- CREATE TABLE ---" . PHP_EOL;
$ct = $conn->query('SHOW CREATE TABLE residents');
if ($ct) {
    $r = $ct->fetch_assoc();
    echo $r['Create Table'] . PHP_EOL;
} else {
    echo "SHOW CREATE ERROR: " . $conn->error . PHP_EOL;
}
