<?php
/**
 * API Gestion des Demandes de Formation
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les demandes de formation
if ($method === 'GET') {
    try {
        $where_clauses = [];
        $params = [];
        
        // Filtrage par statut
        if (isset($_GET['statut'])) {
            $where_clauses[] = "df.statut = ?";
            $params[] = $_GET['statut'];
        }
        
        // Si CE/CEA, filtrer par établissement
        if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }
        
        // Si DRH, peut filtrer par établissement
        if (isset($_GET['id_etablissement']) && has_role('DRH')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $stmt = $pdo->prepare("
            SELECT df.*, 
                   p.nom as prof_nom, p.prenom as prof_prenom, p.discipline_enseignee,
                   e.nom_etablissement,
                   u1.nom as demandeur_nom, u1.prenom as demandeur_prenom,
                   u2.nom as validateur_nom, u2.prenom as validateur_prenom
            FROM demandes_formations df
            JOIN professeurs p ON df.id_professeur = p.id_professeur
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            JOIN utilisateurs u1 ON df.id_demandeur = u1.id_utilisateur
            LEFT JOIN utilisateurs u2 ON df.id_validateur = u2.id_utilisateur
            $where_sql
            ORDER BY df.date_demande DESC
        ");
        $stmt->execute($params);
        $formations = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $formations]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// POST - Créer une demande de formation
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    $required = ['id_professeur', 'type_formation', 'description'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            json_response(['success' => false, 'message' => "Le champ $field est requis"], 400);
        }
    }
    
    // Vérifier que le professeur appartient à l'établissement
    try {
        $stmt = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
        $stmt->execute([$_POST['id_professeur']]);
        $prof = $stmt->fetch();
        
        if (!$prof || $prof['id_etablissement'] != $_SESSION['id_etablissement']) {
            json_response(['success' => false, 'message' => 'Professeur non trouvé dans votre établissement'], 403);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO demandes_formations (id_professeur, type_formation, description, justification, id_demandeur)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['id_professeur'],
            clean_input($_POST['type_formation']),
            clean_input($_POST['description']),
            clean_input($_POST['justification'] ?? ''),
            $_SESSION['user_id']
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Demande de formation', 'demandes_formations', $id);
        
        json_response(['success' => true, 'message' => 'Demande de formation créée avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Traiter une demande de formation (DRH uniquement)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Seul le DRH peut traiter les demandes'], 403);
    }
    
    if (empty($_POST['id']) || empty($_POST['statut'])) {
        json_response(['success' => false, 'message' => 'ID et statut requis'], 400);
    }
    
    $statuts_valides = ['approuvee', 'refusee', 'completee'];
    if (!in_array($_POST['statut'], $statuts_valides)) {
        json_response(['success' => false, 'message' => 'Statut invalide'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE demandes_formations 
            SET statut = ?, 
                commentaire_drh = ?,
                id_validateur = ?,
                date_reponse = NOW()
            WHERE id_demande = ?
        ");
        $stmt->execute([
            $_POST['statut'],
            clean_input($_POST['commentaire_drh'] ?? ''),
            $_SESSION['user_id'],
            $_POST['id']
        ]);
        
        log_activity($pdo, $_SESSION['user_id'], "Traitement demande formation: {$_POST['statut']}", 'demandes_formations', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Demande traitée avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
