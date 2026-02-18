<?php
/**
 * API Gestion des Sanctions
 * Académie Sterling - Système RH
 * Accessible : CE, CEA, DRH
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

// Professeurs : lecture seule de leurs propres sanctions
$is_prof = has_role('PROFESSEUR');

if (!has_role(['CE', 'CEA', 'DRH']) && !$is_prof) {
    json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
}

// Si prof : bloquer toute écriture
if ($is_prof && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Lecture seule pour les professeurs'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — liste des sanctions ─────────────────────────────────
if ($method === 'GET') {
    try {
        $where  = [];
        $params = [];

        // PROFESSEUR : uniquement ses propres sanctions
        if (has_role('PROFESSEUR')) {
            if (empty($_SESSION['id_professeur'])) {
                json_response(['success' => true, 'data' => []]);
            }
            $where[]  = "s.id_professeur = ?";
            $params[] = intval($_SESSION['id_professeur']);
        }

        // Filtre par professeur (fiche individuelle — CE/DRH)
        if (!has_role('PROFESSEUR') && !empty($_GET['id_professeur'])) {
            $where[]  = "s.id_professeur = ?";
            $params[] = intval($_GET['id_professeur']);
        }

        // CE/CEA : uniquement leur établissement
        if (has_role(['CE', 'CEA'])) {
            $where[]  = "p.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }

        // DRH : filtre optionnel par établissement
        if (has_role('DRH') && !empty($_GET['id_etablissement'])) {
            $where[]  = "p.id_etablissement = ?";
            $params[] = intval($_GET['id_etablissement']);
        }

        // Filtre par statut
        if (!empty($_GET['statut'])) {
            $where[]  = "s.statut = ?";
            $params[] = $_GET['statut'];
        }

        $sql_where = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $pdo->prepare("
            SELECT
                s.*,
                p.nom        AS prof_nom,
                p.prenom     AS prof_prenom,
                p.discipline_enseignee,
                e.nom_etablissement,
                u.nom        AS emetteur_nom,
                u.prenom     AS emetteur_prenom,
                u.role       AS emetteur_role
            FROM sanctions s
            JOIN professeurs  p ON s.id_professeur = p.id_professeur
            JOIN etablissements e ON p.id_etablissement = e.id_etablissement
            JOIN utilisateurs  u ON s.id_emetteur   = u.id_utilisateur
            $sql_where
            ORDER BY s.date_sanction DESC, s.date_creation DESC
        ");
        $stmt->execute($params);

        json_response(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── POST — créer une sanction ─────────────────────────────────
if ($method === 'POST' && !isset($_POST['_method'])) {
    $id_prof     = intval($_POST['id_professeur'] ?? 0);
    $type        = clean_input($_POST['type_sanction']  ?? '');
    $motif       = clean_input($_POST['motif']          ?? '');
    $description = clean_input($_POST['description']    ?? '');
    $date_sanction = $_POST['date_sanction'] ?? date('Y-m-d');

    if (!$id_prof || !$type || !$motif) {
        json_response(['success' => false, 'message' => 'Professeur, type et motif requis'], 400);
    }

    $types_valides = ['avertissement','blame','mise_a_pied','convocation','autre'];
    if (!in_array($type, $types_valides)) {
        json_response(['success' => false, 'message' => 'Type de sanction invalide'], 400);
    }

    try {
        // CE/CEA : vérifier appartenance établissement
        if (has_role(['CE', 'CEA'])) {
            $chk = $pdo->prepare("SELECT id_etablissement FROM professeurs WHERE id_professeur = ?");
            $chk->execute([$id_prof]);
            $prof = $chk->fetch();
            if (!$prof || $prof['id_etablissement'] != $_SESSION['id_etablissement']) {
                json_response(['success' => false, 'message' => 'Professeur non trouvé dans votre établissement'], 403);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO sanctions
                (id_professeur, type_sanction, motif, description, date_sanction, id_emetteur, statut)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$id_prof, $type, $motif, $description, $date_sanction, $_SESSION['user_id']]);

        $id = $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'Sanction émise', 'sanctions', $id);

        json_response(['success' => true, 'message' => 'Sanction enregistrée', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── PUT — modifier statut / ajouter commentaire ───────────────
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }

    try {
        $updates = [];
        $params  = [];

        if (isset($_POST['statut'])) {
            $statuts = ['active','levee','archivee'];
            if (!in_array($_POST['statut'], $statuts)) {
                json_response(['success' => false, 'message' => 'Statut invalide'], 400);
            }
            $updates[] = "statut = ?";
            $params[]  = $_POST['statut'];
        }

        if (isset($_POST['date_levee'])) {
            $updates[] = "date_levee = ?";
            $params[]  = !empty($_POST['date_levee']) ? $_POST['date_levee'] : null;
        }

        if (isset($_POST['commentaire'])) {
            $updates[] = "commentaire = ?";
            $params[]  = clean_input($_POST['commentaire']);
        }

        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Rien à modifier'], 400);
        }

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE sanctions SET " . implode(", ", $updates) . " WHERE id_sanction = ?");
        $stmt->execute($params);

        log_activity($pdo, $_SESSION['user_id'], 'Sanction modifiée', 'sanctions', $id);
        json_response(['success' => true, 'message' => 'Sanction mise à jour']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    // Seul DRH peut supprimer définitivement
    if (!has_role('DRH')) {
        json_response(['success' => false, 'message' => 'Suppression réservée au DRH'], 403);
    }
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM sanctions WHERE id_sanction = ?");
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], 'Sanction supprimée', 'sanctions', $id);
        json_response(['success' => true, 'message' => 'Sanction supprimée']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
