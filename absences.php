<?php
/**
 * API Gestion des Absences des Professeurs
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les absences
if ($method === 'GET' && !isset($_GET['stats'])) {
    try {
        $where_clauses = [];
        $params = [];
        
        // Si CE/CEA, filtrer par établissement
        if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }
        
        // Filtrage par établissement (DRH)
        if (isset($_GET['id_etablissement']) && has_role('DRH')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        // Filtrage par professeur
        if (isset($_GET['id_professeur'])) {
            $where_clauses[] = "a.id_professeur = ?";
            $params[] = $_GET['id_professeur'];
        }
        
        // Filtrage par motif
        if (isset($_GET['id_motif'])) {
            $where_clauses[] = "a.id_motif = ?";
            $params[] = $_GET['id_motif'];
        }
        
        // Filtrage par année en cours par défaut
        $annee = $_GET['annee'] ?? date('Y');
        $where_clauses[] = "(YEAR(a.date_debut) = ? OR YEAR(a.date_fin) = ?)";
        $params[] = $annee;
        $params[] = $annee;
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   p.nom as prof_nom, 
                   p.prenom as prof_prenom,
                   p.discipline_enseignee,
                   e.nom_etablissement,
                   m.libelle as motif_libelle,
                   m.couleur as motif_couleur,
                   u.nom as saisie_nom,
                   u.prenom as saisie_prenom
            FROM absences a
            JOIN professeurs p ON a.id_professeur = p.id_professeur
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            JOIN motifs_absence m ON a.id_motif = m.id_motif
            LEFT JOIN utilisateurs u ON a.id_saisi_par = u.id_utilisateur
            $where_sql
            ORDER BY a.date_debut DESC
        ");
        $stmt->execute($params);
        $absences = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $absences]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// GET - Statistiques
if ($method === 'GET' && isset($_GET['stats'])) {
    try {
        $where_clauses = [];
        $params = [];
        
        if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }
        
        if (isset($_GET['id_etablissement']) && has_role('DRH')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        $annee = $_GET['annee'] ?? date('Y');
        $where_clauses[] = "(YEAR(a.date_debut) = ? OR YEAR(a.date_fin) = ?)";
        $params[] = $annee;
        $params[] = $annee;
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        // Stats globales
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_absences,
                SUM(a.nombre_jours) as total_jours,
                AVG(a.nombre_jours) as moyenne_jours
            FROM absences a
            JOIN professeurs p ON a.id_professeur = p.id_professeur
            $where_sql
        ");
        $stmt->execute($params);
        $stats_globales = $stmt->fetch();
        
        // Stats par motif
        $stmt = $pdo->prepare("
            SELECT 
                m.libelle,
                m.couleur,
                COUNT(*) as nb_absences,
                SUM(a.nombre_jours) as nb_jours
            FROM absences a
            JOIN professeurs p ON a.id_professeur = p.id_professeur
            JOIN motifs_absence m ON a.id_motif = m.id_motif
            $where_sql
            GROUP BY m.id_motif, m.libelle, m.couleur
            ORDER BY nb_jours DESC
        ");
        $stmt->execute($params);
        $stats_par_motif = $stmt->fetchAll();
        
        json_response([
            'success' => true,
            'data' => [
                'globales' => $stats_globales,
                'par_motif' => $stats_par_motif
            ]
        ]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// POST - Créer une absence
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    $required = ['id_professeur', 'id_motif', 'date_debut', 'date_fin'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            json_response(['success' => false, 'message' => "Le champ $field est requis"], 400);
        }
    }
    
    // Vérifier que le professeur appartient à l'établissement (sauf DRH)
    if (has_role(['CE', 'CEA'])) {
        $stmt = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
        $stmt->execute([$_POST['id_professeur']]);
        $prof_etab = $stmt->fetchColumn();
        
        if ($prof_etab != $_SESSION['id_etablissement']) {
            json_response(['success' => false, 'message' => 'Professeur non autorisé'], 403);
        }
    }
    
    // Vérifier que date_fin >= date_debut
    if ($_POST['date_fin'] < $_POST['date_debut']) {
        json_response(['success' => false, 'message' => 'La date de fin doit être après la date de début'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO absences (id_professeur, id_motif, date_debut, date_fin, commentaire, justificatif_fourni, id_saisi_par)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['id_professeur'],
            $_POST['id_motif'],
            $_POST['date_debut'],
            $_POST['date_fin'],
            clean_input($_POST['commentaire'] ?? ''),
            isset($_POST['justificatif_fourni']) ? 1 : 0,
            $_SESSION['user_id']
        ]);
        
        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Création absence', 'absences', $id);
        
        json_response(['success' => true, 'message' => 'Absence enregistrée avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier une absence
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    // Vérifier les droits
    if (has_role(['CE', 'CEA'])) {
        $stmt = $pdo->prepare("
            SELECT p.id_etablissement 
            FROM absences a 
            JOIN professeurs p ON a.id_professeur = p.id_professeur 
            WHERE a.id_absence = ?
        ");
        $stmt->execute([$_POST['id']]);
        $etab = $stmt->fetchColumn();
        
        if ($etab != $_SESSION['id_etablissement']) {
            json_response(['success' => false, 'message' => 'Non autorisé'], 403);
        }
    }
    
    try {
        $updates = [];
        $params = [];
        
        $fields = ['id_motif', 'date_debut', 'date_fin', 'commentaire', 'justificatif_fourni'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = $field === 'commentaire' ? clean_input($_POST[$field]) : $_POST[$field];
            }
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à modifier'], 400);
        }
        
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare("
            UPDATE absences 
            SET " . implode(", ", $updates) . "
            WHERE id_absence = ?
        ");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification absence', 'absences', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Absence modifiée avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// DELETE - Supprimer une absence
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    // Vérifier les droits
    if (has_role(['CE', 'CEA'])) {
        $stmt = $pdo->prepare("
            SELECT p.id_etablissement 
            FROM absences a 
            JOIN professeurs p ON a.id_professeur = p.id_professeur 
            WHERE a.id_absence = ?
        ");
        $stmt->execute([$_POST['id']]);
        $etab = $stmt->fetchColumn();
        
        if ($etab != $_SESSION['id_etablissement']) {
            json_response(['success' => false, 'message' => 'Non autorisé'], 403);
        }
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM absences WHERE id_absence = ?");
        $stmt->execute([$_POST['id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Suppression absence', 'absences', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Absence supprimée avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
