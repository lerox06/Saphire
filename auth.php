<?php
/**
 * Système d'authentification
 * Académie Sterling - Système RH
 */

require_once 'config.php';

// Gestion de la connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        json_response(['success' => false, 'message' => 'Email et mot de passe requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, e.nom_etablissement 
            FROM utilisateurs u
            LEFT JOIN etablissements e ON u.id_etablissement = e.id_etablissement
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['mot_de_passe'])) {
            // Mise à jour de la dernière connexion
            $stmt = $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id_utilisateur = ?");
            $stmt->execute([$user['id_utilisateur']]);
            
            // Création de la session
            $_SESSION['user_id'] = $user['id_utilisateur'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nom'] = $user['nom'];
            $_SESSION['prenom'] = $user['prenom'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['id_etablissement'] = $user['id_etablissement'];
            $_SESSION['nom_etablissement'] = $user['nom_etablissement'];
            $_SESSION['id_professeur'] = $user['id_professeur']; // Pour les professeurs
            
            log_activity($pdo, $user['id_utilisateur'], 'Connexion', 'utilisateurs', $user['id_utilisateur']);
            
            json_response([
                'success' => true,
                'message' => 'Connexion réussie',
                'user' => [
                    'id' => $user['id_utilisateur'],
                    'role' => $user['role'],
                    'nom' => $user['nom'],
                    'prenom' => $user['prenom'],
                    'etablissement' => $user['nom_etablissement'],
                    'id_professeur' => $user['id_professeur']
                ]
            ]);
        } else {
            json_response(['success' => false, 'message' => 'Email ou mot de passe incorrect'], 401);
        }
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// Gestion de la déconnexion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (isset($_SESSION['user_id'])) {
        log_activity($pdo, $_SESSION['user_id'], 'Déconnexion', 'utilisateurs', $_SESSION['user_id']);
    }
    
    session_destroy();
    json_response(['success' => true, 'message' => 'Déconnexion réussie']);
}

// Vérification de session
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_session') {
    if (is_logged_in()) {
        json_response([
            'success' => true,
            'logged_in' => true,
            'role' => $_SESSION['role'],
            'user' => [
                'id' => $_SESSION['user_id'],
                'role' => $_SESSION['role'],
                'nom' => $_SESSION['nom'],
                'prenom' => $_SESSION['prenom'],
                'etablissement' => $_SESSION['nom_etablissement'] ?? null,
                'id_etablissement' => $_SESSION['id_etablissement'] ?? null,
                'id_professeur' => $_SESSION['id_professeur'] ?? null
            ],
            'nom' => $_SESSION['nom'],
            'prenom' => $_SESSION['prenom'],
            'id_etablissement' => $_SESSION['id_etablissement'] ?? null,
            'id_professeur' => $_SESSION['id_professeur'] ?? null
        ]);
    } else {
        json_response(['success' => true, 'logged_in' => false]);
    }
}

// Changement de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!is_logged_in()) {
        json_response(['success' => false, 'message' => 'Non connecté'], 401);
    }
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        json_response(['success' => false, 'message' => 'Tous les champs sont requis']);
    }
    
    if ($new_password !== $confirm_password) {
        json_response(['success' => false, 'message' => 'Les nouveaux mots de passe ne correspondent pas']);
    }
    
    if (strlen($new_password) < 8) {
        json_response(['success' => false, 'message' => 'Le mot de passe doit contenir au moins 8 caractères']);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($current_password, $user['mot_de_passe'])) {
            json_response(['success' => false, 'message' => 'Mot de passe actuel incorrect']);
        }
        
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id_utilisateur = ?");
        $stmt->execute([$new_hash, $_SESSION['user_id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Changement de mot de passe', 'utilisateurs', $_SESSION['user_id']);
        
        json_response(['success' => true, 'message' => 'Mot de passe modifié avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}
?>
