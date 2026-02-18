<?php
/**
 * API Gestion des Établissements
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les établissements
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*,
                   COUNT(DISTINCT p.id_professeur) as nb_professeurs,
                   COUNT(DISTINCT u.id_utilisateur) as nb_responsables
            FROM etablissements e
            LEFT JOIN professeurs p ON e.id_etablissement = p.id_etablissement AND p.statut = 'actif'
            LEFT JOIN utilisateurs u ON e.id_etablissement = u.id_etablissement
            GROUP BY e.id_etablissement
            ORDER BY e.nom_etablissement
        ");
        $stmt->execute();
        $etablissements = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $etablissements]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// POST - Créer un établissement (DRH uniquement)
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Seul le DRH peut créer des établissements'], 403);
    }
    
    if (empty($_POST['nom_etablissement'])) {
        json_response(['success' => false, 'message' => 'Le nom de l\'établissement est requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO etablissements (nom_etablissement, adresse, telephone, email)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            clean_input($_POST['nom_etablissement']),
            clean_input($_POST['adresse'] ?? ''),
            clean_input($_POST['telephone'] ?? ''),
            clean_input($_POST['email'] ?? '')
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Création établissement', 'etablissements', $id);
        
        json_response(['success' => true, 'message' => 'Établissement créé avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier un établissement (DRH uniquement)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Seul le DRH peut modifier des établissements'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        $updates = [];
        $params = [];
        
        $fields = ['nom_etablissement', 'adresse', 'telephone', 'email'];
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
            UPDATE etablissements 
            SET " . implode(", ", $updates) . "
            WHERE id_etablissement = ?
        ");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification établissement', 'etablissements', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Établissement modifié avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// Statistiques d'un établissement (DRH)
if ($method === 'GET' && isset($_GET['stats']) && isset($_GET['id'])) {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    try {
        // Stats générales
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM professeurs WHERE id_etablissement = ? AND statut = 'actif') as nb_professeurs_actifs,
                (SELECT COUNT(*) FROM utilisateurs WHERE id_etablissement = ?) as nb_responsables,
                (SELECT AVG(note_totale) FROM notations_hebdomadaires n 
                 JOIN professeurs p ON n.id_professeur = p.id_professeur 
                 WHERE p.id_etablissement = ? AND n.annee = YEAR(NOW())) as moyenne_notations,
                (SELECT COUNT(*) FROM demandes_formations df
                 JOIN professeurs p ON df.id_professeur = p.id_professeur
                 WHERE p.id_etablissement = ? AND df.statut = 'en_attente') as formations_en_attente,
                (SELECT COUNT(*) FROM demandes_inspections di
                 JOIN professeurs p ON di.id_professeur = p.id_professeur
                 WHERE p.id_etablissement = ? AND di.statut = 'en_attente') as inspections_en_attente
        ");
        $stmt->execute([$_GET['id'], $_GET['id'], $_GET['id'], $_GET['id'], $_GET['id']]);
        $stats = $stmt->fetch();
        
        json_response(['success' => true, 'data' => $stats]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
