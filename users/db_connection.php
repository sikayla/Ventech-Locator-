<?php
// db_connection.php

$host = 'localhost';
$db   = 'ventech_db'; // Your database name
$user = 'root';     // Your database username
$pass = '';         // Your database password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Log the error for debugging (check your PHP error logs)
    error_log("Database connection error: " . $e->getMessage());
    // Display a user-friendly message or redirect
    die("Connection to database failed: Please try again later.");
}
?>