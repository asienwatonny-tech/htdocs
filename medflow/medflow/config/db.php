<?php
date_default_timezone_set('Africa/Nairobi');   // PHP clock = East Africa Time

$host = "localhost"; $dbname = "medflow"; $username = "root"; $password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username, $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+03:00'");     // MySQL clock = East Africa Time
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit("Database connection failed. Check your database settings.");
}
$db = $pdo; // lets the rest of the MedFlow code use either name2