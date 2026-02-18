<?php
// NE METS RIEN ICI (pas de session_start)
require_once '../config.php'; 

// Désactive l'affichage des erreurs pour éviter de polluer le JSON
ini_set('display_errors', 0); 
header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}
// ... reste du code
/**
 * API Gestion des Motifs d'Absence
 * Académie Sterling - Système RH
 * Réservé au DRH uniquement
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les motifs
if ($method === 'GET') {
    try {
        $where = has_role('DRH') ? "" : "WHERE actif = 1";
        
        $stmt = $pdo->prepare("
            SELECT * FROM motifs_absence
            $where
            ORDER BY libelle
        ");
        $stmt->execute();
        $motifs = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $motifs]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// POST - Créer un motif (DRH uniquement)
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Réservé au DRH'], 403);
    }
    
    if (empty($_POST['libelle'])) {
        json_response(['success' => false, 'message' => 'Le libellé est requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO motifs_absence (libelle, description, couleur, actif)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            clean_input($_POST['libelle']),
            clean_input($_POST['description'] ?? ''),
            $_POST['couleur'] ?? '#666666',
            isset($_POST['actif']) ? $_POST['actif'] : 1
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Création motif absence', 'motifs_absence', $id);
        
        json_response(['success' => true, 'message' => 'Motif créé avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Ce libellé existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier un motif (DRH uniquement)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Réservé au DRH'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        $updates = [];
        $params = [];
        
        if (isset($_POST['libelle'])) {
            $updates[] = "libelle = ?";
            $params[] = clean_input($_POST['libelle']);
        }
        if (isset($_POST['description'])) {
            $updates[] = "description = ?";
            $params[] = clean_input($_POST['description']);
        }
        if (isset($_POST['couleur'])) {
            $updates[] = "couleur = ?";
            $params[] = $_POST['couleur'];
        }
        if (isset($_POST['actif'])) {
            $updates[] = "actif = ?";
            $params[] = $_POST['actif'];
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à modifier'], 400);
        }
        
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare("
            UPDATE motifs_absence 
            SET " . implode(", ", $updates) . "
            WHERE id_motif = ?
        ");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification motif absence', 'motifs_absence', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Motif modifié avec succès']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'message' => 'Ce libellé existe déjà'], 400);
        }
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// DELETE - Supprimer un motif (DRH uniquement)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Réservé au DRH'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        // Vérifier si le motif est utilisé
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM absences WHERE id_motif = ?");
        $stmt->execute([$_POST['id']]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            json_response(['success' => false, 'message' => "Ce motif est utilisé par $count absence(s). Désactivez-le plutôt que de le supprimer."], 400);
        }
        
        $stmt = $pdo->prepare("DELETE FROM motifs_absence WHERE id_motif = ?");
        $stmt->execute([$_POST['id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Suppression motif absence', 'motifs_absence', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Motif supprimé avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
