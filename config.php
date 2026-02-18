<?php
/**
 * Configuration de la base de données
 * Académie Sterling - Système RH
 */

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'academie_sterling');
define('DB_USER', 'root'); // À modifier selon votre configuration
define('DB_PASS', ''); // À modifier selon votre configuration
define('DB_CHARSET', 'utf8mb4');

// Configuration de session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); 
    session_start();
}

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Connexion PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Fonction pour nettoyer les entrées
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Fonction pour vérifier si l'utilisateur est connecté
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

// Fonction pour vérifier le rôle
function has_role($roles) {
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    return is_logged_in() && in_array($_SESSION['role'], $roles);
}

// Fonction pour rediriger
function redirect($url) {
    header("Location: $url");
    exit();
}

// Fonction pour retourner une réponse JSON
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Fonction pour logger les activités
function log_activity($pdo, $user_id, $action, $table_cible = null, $id_cible = null, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs_activite (id_utilisateur, action, table_cible, id_cible, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $action, $table_cible, $id_cible, $details]);
    } catch (PDOException $e) {
        // Log silencieux en cas d'erreur
        error_log("Erreur log_activity: " . $e->getMessage());
    }
}
?>
