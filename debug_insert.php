<?php
$conn = new mysqli('127.0.0.1','root','','dbbarangaymanagement');
if ($conn->connect_error) { die('CONNERR: '.$conn->connect_error); }
$data = [
    'resident_number' => 'RES-TEST-CLI',
    'first_name' => 'Juan',
    'last_name' => 'Dela Cruz',
    'email' => 'juan@example.com'
];
$cols = array_keys($data);
$placeholders = array_fill(0, count($cols), '?');
$types = str_repeat('s', count($cols));
$values = array_values($data);
$sql = 'INSERT INTO residents (' . implode(', ', $cols) . ', date_registered, created_date) VALUES (' . implode(', ', $placeholders) . ', ?, NOW())';
$stmt = $conn->prepare($sql);
if (!$stmt) { echo 'PREPARE_ERR: ' . $conn->error . PHP_EOL; echo "SQL: $sql\n"; exit(1); }
$types .= 's';
$values[] = date('Y-m-d');
$params = array_merge([$types], $values);
$refs = [];
foreach ($params as $k => $v) $refs[$k] = &$params[$k];
call_user_func_array([$stmt, 'bind_param'], $refs);
$exec = $stmt->execute();
if (!$exec) { echo 'EXEC_ERR: ' . $stmt->error . PHP_EOL; }
else { echo 'OK INSERT ID: ' . $stmt->insert_id . PHP_EOL; }
$stmt->close();
