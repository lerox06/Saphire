<?php
/**
 * API Gestion des Demandes d'Inspection
 * Académie Sterling - Système RH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Récupérer les demandes d'inspection
if ($method === 'GET') {
    try {
        $where_clauses = [];
        $params = [];

        // Filtrage par statut
        if (!empty($_GET['statut'])) {
            $where_clauses[] = "di.statut = ?";
            $params[] = $_GET['statut'];
        }

        // CE/CEA : uniquement leur établissement
        if (has_role(['CE', 'CEA'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }

        // DRH : peut filtrer par établissement
        if (has_role('DRH') && !empty($_GET['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = intval($_GET['id_etablissement']);
        }

        // IA : uniquement leur établissement
        if (has_role('IA')) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }

        // IA_DASEN : tous les établissements (peut filtrer optionnellement)
        if (has_role('IA_DASEN') && !empty($_GET['id_etablissement'])) {
            $where_clauses[] = "p.id_etablissement = ?";
            $params[] = intval($_GET['id_etablissement']);
        }

        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

        $stmt = $pdo->prepare("
            SELECT di.*,
                   p.nom as prof_nom, p.prenom as prof_prenom, p.discipline_enseignee,
                   e.nom_etablissement,
                   u.nom as demandeur_nom, u.prenom as demandeur_prenom
            FROM demandes_inspections di
            JOIN professeurs p ON di.id_professeur = p.id_professeur
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            JOIN utilisateurs u ON di.id_demandeur = u.id_utilisateur
            $where_sql
            ORDER BY di.date_demande DESC
        ");
        $stmt->execute($params);
        $inspections = $stmt->fetchAll();

        json_response(['success' => true, 'data' => $inspections]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// POST - Créer une demande d'inspection (CE/CEA/DRH)
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA', 'DRH', 'IA', 'IA_DASEN'])) {
        json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
    }

    if (empty($_POST['id_professeur']) || empty($_POST['type_inspection'])) {
        json_response(['success' => false, 'message' => 'Professeur et type d\'inspection requis'], 400);
    }

    try {
        // CE/CEA : vérifier que le prof est dans leur établissement
        if (has_role(['CE', 'CEA'])) {
            $stmt = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
            $stmt->execute([intval($_POST['id_professeur'])]);
            $prof = $stmt->fetch();
            if (!$prof || $prof['id_etablissement'] != $_SESSION['id_etablissement']) {
                json_response(['success' => false, 'message' => 'Professeur non trouvé dans votre établissement'], 403);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO demandes_inspections
                (id_professeur, type_inspection, description, date_programmee, id_demandeur, statut)
            VALUES (?, ?, ?, ?, ?, 'en_attente')
        ");
        $stmt->execute([
            intval($_POST['id_professeur']),
            clean_input($_POST['type_inspection']),
            clean_input($_POST['description'] ?? ''),
            !empty($_POST['date_programmee']) ? $_POST['date_programmee'] : null,
            $_SESSION['user_id']
        ]);

        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Demande d\'inspection', 'demandes_inspections', $id);

        json_response(['success' => true, 'message' => 'Demande d\'inspection créée avec succès', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// PUT - Modifier le statut d'une inspection (CE/CEA/DRH)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    if (!has_role(['CE', 'CEA', 'DRH', 'IA', 'IA_DASEN'])) {
        json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
    }

    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }

    try {
        $updates = [];
        $params  = [];

        if (isset($_POST['statut'])) {
            $statuts_valides = ['en_attente', 'programmee', 'realisee', 'annulee'];
            if (!in_array($_POST['statut'], $statuts_valides)) {
                json_response(['success' => false, 'message' => 'Statut invalide'], 400);
            }
            $updates[] = "statut = ?";
            $params[]  = $_POST['statut'];
        }

        if (isset($_POST['date_programmee'])) {
            $updates[] = "date_programmee = ?";
            $params[]  = !empty($_POST['date_programmee']) ? $_POST['date_programmee'] : null;
        }

        if (isset($_POST['commentaire'])) {
            $updates[] = "commentaire = ?";
            $params[]  = clean_input($_POST['commentaire']);
        }

        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Aucune donnée à mettre à jour'], 400);
        }

        $params[] = intval($_POST['id']);
        $stmt = $pdo->prepare("UPDATE demandes_inspections SET " . implode(", ", $updates) . " WHERE id_inspection = ?");
        $stmt->execute($params);

        log_activity($pdo, $_SESSION['user_id'], 'Traitement inspection', 'demandes_inspections', $_POST['id']);
        json_response(['success' => true, 'message' => 'Inspection mise à jour avec succès']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// DELETE - Supprimer une inspection (DRH uniquement)
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (!has_role(['CE', 'CEA', 'DRH', 'IA', 'IA_DASEN'])) {
        json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
    }

    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM demandes_inspections WHERE id_inspection = ?");
        $stmt->execute([intval($_POST['id'])]);
        log_activity($pdo, $_SESSION['user_id'], 'Suppression inspection', 'demandes_inspections', $_POST['id']);
        json_response(['success' => true, 'message' => 'Inspection supprimée']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
