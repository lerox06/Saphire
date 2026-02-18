<?php
/**
 * API Gestion des Professeurs
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les professeurs
if ($method === 'GET') {
    try {
        $where_clauses = [];
        $params = [];
        
        // Si CE/CEA, filtrer par établissement
        if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }
        
        // Filtrage par établissement pour DRH
        if (isset($_GET['id_etablissement']) && has_role('DRH')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        // Filtrage par ID
        if (isset($_GET['id'])) {
            $where_clauses[] = "p.id_professeur = ?";
            $params[] = $_GET['id'];
        }
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $stmt = $pdo->prepare("
            SELECT p.*, e.nom_etablissement,
                   (SELECT AVG(note_totale) FROM notations_hebdomadaires 
                    WHERE id_professeur = p.id_professeur AND annee = YEAR(NOW())) as moyenne_annuelle
            FROM professeurs p
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            $where_sql
            ORDER BY p.nom, p.prenom
        ");
        $stmt->execute($params);
        $professeurs = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $professeurs]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// POST - Créer un professeur
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    $required = ['nom', 'prenom', 'date_naissance', 'email', 'discipline_enseignee'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            json_response(['success' => false, 'message' => "Le champ $field est requis"], 400);
        }
    }
    
    // Déterminer l'établissement
    $id_etablissement = has_role('DRH') && isset($_POST['id_etablissement']) 
        ? $_POST['id_etablissement'] 
        : $_SESSION['id_etablissement'];
    
    if (empty($id_etablissement)) {
        json_response(['success' => false, 'message' => 'Établissement requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO professeurs (nom, prenom, date_naissance, email, echelon, pseudo_discord, discipline_enseignee, id_etablissement)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            clean_input($_POST['nom']),
            clean_input($_POST['prenom']),
            clean_input($_POST['date_naissance']),
            clean_input($_POST['email']),
            clean_input($_POST['echelon'] ?? null),
            clean_input($_POST['pseudo_discord'] ?? null),
            clean_input($_POST['discipline_enseignee']),
            $id_etablissement
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Création professeur', 'professeurs', $id);
        
        json_response(['success' => true, 'message' => 'Professeur créé avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Cet email existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier un professeur
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        // Vérifier les droits d'accès
        if (has_role(['CE', 'CEA'])) {
            $stmt = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
            $stmt->execute([$_POST['id']]);
            $prof = $stmt->fetch();
            
            if (!$prof || $prof['id_etablissement'] != $_SESSION['id_etablissement']) {
                json_response(['success' => false, 'message' => 'Non autorisé'], 403);
            }
        }
        
        $updates = [];
        $params = [];
        
        $fields = ['nom', 'prenom', 'date_naissance', 'email', 'echelon', 'pseudo_discord', 'discipline_enseignee', 'statut'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = clean_input($_POST[$field]);
            }
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à modifier'], 400);
        }
        
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare("
            UPDATE professeurs 
            SET " . implode(", ", $updates) . "
            WHERE id_professeur = ?
        ");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification professeur', 'professeurs', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Professeur modifié avec succès']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Cet email existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// DELETE - Supprimer un professeur
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        // Vérifier les droits d'accès
        if (has_role(['CE', 'CEA'])) {
            $stmt = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
            $stmt->execute([$_POST['id']]);
            $prof = $stmt->fetch();
            
            if (!$prof || $prof['id_etablissement'] != $_SESSION['id_etablissement']) {
                json_response(['success' => false, 'message' => 'Non autorisé'], 403);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM professeurs WHERE id_professeur = ?");
        $stmt->execute([$_POST['id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Suppression professeur', 'professeurs', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Professeur supprimé avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
