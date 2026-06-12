<?php
// db.php
$host = 'localhost';
$db   = 'kultiqhc_gpdb';
$user = 'kultiqhc_gpdb';
$pass = 'gAntani66;6!';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Errore di connessione: " . $e->getMessage()]);
    exit;
}
?>
