<?php
/**
 * API Gestion des Notations Hebdomadaires
 * Académie Sterling - Système RH
 * MISE À JOUR : Support "Abs" + Calcul note_totale
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// Fonction pour calculer la note totale
function calculer_note_totale($note_appels, $note_cdt) {
    if (strtolower($note_appels) === 'abs' || strtolower($note_cdt) === 'abs') {
        return 'abs';
    }
    if (empty($note_appels) && empty($note_cdt)) {
        return '0';
    }
    $appels = is_numeric($note_appels) ? floatval($note_appels) : 0;
    $cdt = is_numeric($note_cdt) ? floatval($note_cdt) : 0;
    return strval($appels + $cdt);
}

function valider_note($note) {
    if (strtolower($note) === 'abs') return true;
    if (!is_numeric($note)) return false;
    $val = floatval($note);
    return $val >= 0 && $val <= 10;
}

// GET - Récupérer les notations
if ($method === 'GET') {
    try {
        $where_clauses = [];
        $params = [];
        
        if (isset($_GET['id_professeur'])) {
            $where_clauses[] = "n.id_professeur = ?";
            $params[] = $_GET['id_professeur'];
        }
        if (isset($_GET['semaine'])) {
            $where_clauses[] = "n.semaine = ?";
            $params[] = $_GET['semaine'];
        }
        if (isset($_GET['annee'])) {
            $where_clauses[] = "n.annee = ?";
            $params[] = $_GET['annee'];
        } else {
            $where_clauses[] = "n.annee = ?";
            $params[] = date('Y');
        }
        if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }
        if (isset($_GET['id_etablissement']) && has_role('DRH')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_GET['id_etablissement'];
        }
        
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $stmt = $pdo->prepare("
            SELECT n.*, 
                   p.nom as prof_nom, 
                   p.prenom as prof_prenom,
                   p.discipline_enseignee,
                   e.nom_etablissement,
                   e.couleur_notation,
                   u.nom as eval_nom,
                   u.prenom as eval_prenom
            FROM notations_hebdomadaires n
            JOIN professeurs p ON n.id_professeur = p.id_professeur
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            LEFT JOIN utilisateurs u ON n.id_evaluateur = u.id_utilisateur
            $where_sql
            ORDER BY n.semaine DESC, p.nom, p.prenom
        ");
        $stmt->execute($params);
        $notations = $stmt->fetchAll();
        
        json_response(['success' => true, 'data' => $notations]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// POST - Créer/Mettre à jour notation
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    $required = ['id_professeur', 'semaine', 'annee', 'note_appels', 'note_cdt'];
    foreach ($required as $field) {
        if (!isset($_POST[$field])) {
            json_response(['success' => false, 'message' => "Le champ $field est requis"], 400);
        }
    }
    
    if (!valider_note($_POST['note_appels'])) {
        json_response(['success' => false, 'message' => 'Note appels invalide (0-10 ou "Abs")'], 400);
    }
    if (!valider_note($_POST['note_cdt'])) {
        json_response(['success' => false, 'message' => 'Note CDT invalide (0-10 ou "Abs")'], 400);
    }
    
    $note_totale = calculer_note_totale($_POST['note_appels'], $_POST['note_cdt']);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notations_hebdomadaires 
            (id_professeur, semaine, annee, note_appels, note_cdt, note_totale, commentaire, id_evaluateur)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                note_appels = VALUES(note_appels),
                note_cdt = VALUES(note_cdt),
                note_totale = VALUES(note_totale),
                commentaire = VALUES(commentaire),
                date_notation = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $_POST['id_professeur'],
            $_POST['semaine'],
            $_POST['annee'],
            $_POST['note_appels'],
            $_POST['note_cdt'],
            $note_totale,
            clean_input($_POST['commentaire'] ?? ''),
            $_SESSION['user_id']
        ]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Notation professeur', 'notations_hebdomadaires', $_POST['id_professeur']);
        
        json_response(['success' => true, 'message' => 'Notation enregistrée avec succès', 'note_totale' => $note_totale], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// PUT - Modifier une notation
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        $updates = [];
        $params = [];
        
        if (isset($_POST['note_appels'])) {
            if (!valider_note($_POST['note_appels'])) {
                json_response(['success' => false, 'message' => 'Note appels invalide'], 400);
            }
            $updates[] = "note_appels = ?";
            $params[] = $_POST['note_appels'];
        }
        if (isset($_POST['note_cdt'])) {
            if (!valider_note($_POST['note_cdt'])) {
                json_response(['success' => false, 'message' => 'Note CDT invalide'], 400);
            }
            $updates[] = "note_cdt = ?";
            $params[] = $_POST['note_cdt'];
        }
        
        if (isset($_POST['note_appels']) || isset($_POST['note_cdt'])) {
            $stmt = $pdo->prepare("SELECT note_appels, note_cdt FROM notations_hebdomadaires WHERE id_notation = ?");
            $stmt->execute([$_POST['id']]);
            $current = $stmt->fetch();
            
            $new_appels = $_POST['note_appels'] ?? $current['note_appels'];
            $new_cdt = $_POST['note_cdt'] ?? $current['note_cdt'];
            $note_totale = calculer_note_totale($new_appels, $new_cdt);
            
            $updates[] = "note_totale = ?";
            $params[] = $note_totale;
        }
        
        if (isset($_POST['commentaire'])) {
            $updates[] = "commentaire = ?";
            $params[] = clean_input($_POST['commentaire']);
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à modifier'], 400);
        }
        
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare("UPDATE notations_hebdomadaires SET " . implode(", ", $updates) . " WHERE id_notation = ?");
        $stmt->execute($params);
        
        log_activity($pdo, $_SESSION['user_id'], 'Modification notation', 'notations_hebdomadaires', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Notation modifiée avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

// DELETE - Supprimer une notation
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM notations_hebdomadaires WHERE id_notation = ?");
        $stmt->execute([$_POST['id']]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Suppression notation', 'notations_hebdomadaires', $_POST['id']);
        
        json_response(['success' => true, 'message' => 'Notation supprimée avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur'], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
