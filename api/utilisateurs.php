<?php
/**
 * API Gestion des Utilisateurs
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// Route spéciale : reset MDP prof — accessible CE, CEA, DRH
if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_prof_password') {
    if (!has_role('CE') && !has_role('CEA') && !has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
    }

    $id_professeur = intval($_POST['id_professeur'] ?? 0);
    $new_password  = $_POST['new_password'] ?? '';

    if (!$id_professeur || empty($new_password)) {
        json_response(['success' => false, 'message' => 'Paramètres manquants'], 400);
    }
    if (strlen($new_password) < 8) {
        json_response(['success' => false, 'message' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
    }

    try {
        // CE/CEA : vérifier que le prof est dans leur établissement
        if (has_role('CE') || has_role('CEA')) {
            $stmt = $pdo->prepare("SELECT id_professeur FROM professeurs WHERE id_professeur = ? AND id_etablissement = ?");
            $stmt->execute([$id_professeur, $_SESSION['id_etablissement']]);
            if (!$stmt->fetch()) {
                json_response(['success' => false, 'message' => 'Professeur non trouvé dans votre établissement'], 403);
            }
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);

        // Mettre à jour si compte existe
        $stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id_professeur = ? AND role = 'PROFESSEUR'");
        $stmt->execute([$hash, $id_professeur]);

        if ($stmt->rowCount() === 0) {
            // Créer le compte s'il n'existe pas encore
            $stmt2 = $pdo->prepare("SELECT nom, prenom, email, id_etablissement FROM professeurs WHERE id_professeur = ?");
            $stmt2->execute([$id_professeur]);
            $prof = $stmt2->fetch();
            if (!$prof) {
                json_response(['success' => false, 'message' => 'Professeur introuvable'], 404);
            }
            $stmt3 = $pdo->prepare("
                INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, id_etablissement, id_professeur, actif)
                VALUES (?, ?, ?, ?, 'PROFESSEUR', ?, ?, 1)
                ON DUPLICATE KEY UPDATE mot_de_passe = ?, id_professeur = ?
            ");
            $stmt3->execute([
                $prof['nom'], $prof['prenom'], $prof['email'], $hash,
                $prof['id_etablissement'], $id_professeur,
                $hash, $id_professeur
            ]);
        }

        log_activity($pdo, $_SESSION['user_id'], 'Réinitialisation MDP professeur', 'utilisateurs', $id_professeur);
        json_response(['success' => true, 'message' => 'Mot de passe réinitialisé avec succès']);

    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()], 500);
    }
}

// GET : lecture accessible à tous les rôles connectés (pour agenda participants)
// Autres routes : DRH uniquement
if ($method !== 'GET' && !has_role('DRH')) {
    json_response(['success' => false, 'message' => 'Modifications réservées au DRH'], 403);
}

// GET - Récupérer les utilisateurs
if ($method === 'GET') {
    try {
        $where_clauses = [];
        $params = [];
        
        // Filtrage par rôle
        if (isset($_GET['role'])) {
            $where_clauses[] = "u.role = ?";
            $params[] = $_GET['role'];
        }
        
        // Filtrage par établissement
        if (isset($_GET['id_etablissement'])) {
            $where_clauses[] = "u.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        // Filtrage par statut actif
        if (isset($_GET['actif'])) {
            $where_clauses[] = "u.actif = ?";
            $params[] = $_GET['actif'];
        }
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $stmt = $pdo->prepare("
            SELECT u.*, e.nom_etablissement
            FROM utilisateurs u
            LEFT JOIN etablissements e ON u.id_etablissement = e.id_etablissement
            $where_sql
            ORDER BY u.role, u.nom, u.prenom
        ");
        $stmt->execute($params);
        $utilisateurs = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $utilisateurs]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// POST - Créer un utilisateur
if ($method === 'POST' && !isset($_POST['_method'])) {
    $required = ['nom', 'prenom', 'email', 'role'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            json_response(['success' => false, 'message' => "Le champ $field est requis"], 400);
        }
    }
    
    // Vérifier que le rôle est valide
    $roles_valides = ['CE', 'CEA', 'DRH', 'GESTIONNAIRE', 'IA', 'IA_DASEN'];
    if (!in_array($_POST['role'], $roles_valides)) {
        json_response(['success' => false, 'message' => 'Rôle invalide'], 400);
    }
    
    // CE, CEA, IA : établissement requis
    if (in_array($_POST['role'], ['CE', 'CEA', 'IA']) && empty($_POST['id_etablissement'])) {
        json_response(['success' => false, 'message' => 'Un établissement est requis pour ce rôle'], 400);
    }
    
    try {
        // Générer un mot de passe temporaire si non fourni
        $password = !empty($_POST['mot_de_passe']) ? $_POST['mot_de_passe'] : 'Bienvenue2024!';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, id_etablissement, actif)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            clean_input($_POST['nom']),
            clean_input($_POST['prenom']),
            clean_input($_POST['email']),
            $hash,
            $_POST['role'],
            !empty($_POST['id_etablissement']) ? $_POST['id_etablissement'] : null,
            isset($_POST['actif']) ? $_POST['actif'] : 1
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Création utilisateur', 'utilisateurs', $id);
        
        json_response([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'id' => $id,
            'mot_de_passe_temporaire' => $password
        ], 201);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Cet email existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier un utilisateur
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        $updates = [];
        $params = [];
        
        $fields = ['nom', 'prenom', 'email', 'role', 'id_etablissement', 'actif'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = $field === 'id_etablissement' && $_POST[$field] === '' ? null : clean_input($_POST[$field]);
            }
        }
        
        // Changement de mot de passe
        if (!empty($_POST['nouveau_mot_de_passe'])) {
            $updates[] = "mot_de_passe = ?";
            $params[] = password_hash($_POST['nouveau_mot_de_passe'], PASSWORD_DEFAULT);
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à modifier'], 400);
        }
        
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare("
            UPDATE utilisateurs 
            SET " . implode(", ", $updates) . "
            WHERE id_utilisateur = ?
        ");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification utilisateur', 'utilisateurs', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Utilisateur modifié avec succès']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Cet email existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// DELETE - Supprimer un utilisateur
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    // Ne pas supprimer son propre compte
    if ($_POST['id'] == $_SESSION['user_id']) {
        json_response(['success' => false, 'message' => 'Vous ne pouvez pas supprimer votre propre compte'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->execute([$_POST['id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Suppression utilisateur', 'utilisateurs', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Utilisateur supprimé avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
