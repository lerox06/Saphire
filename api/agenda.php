<?php
/**
 * API Agenda
 * Académie Sterling - Système RH
 * Accessible : DRH, CE, CEA, IA, IA_DASEN
 */

require_once '../config.php';


header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

if (!has_role(['DRH', 'CE', 'CEA', 'IA', 'IA_DASEN'])) {
    json_response(['success' => false, 'message' => 'Accès non autorisé'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — récupérer les événements ────────────────────────────
if ($method === 'GET') {
    try {
        $user_id = $_SESSION['user_id'];
        
        // Récupérer les événements créés OU auxquels on participe
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                e.*,
                c.nom AS createur_nom,
                c.prenom AS createur_prenom,
                (SELECT COUNT(*) FROM agenda_participants WHERE id_evenement = e.id_evenement) AS nb_participants
            FROM agenda_evenements e
            JOIN utilisateurs c ON e.id_createur = c.id_utilisateur
            LEFT JOIN agenda_participants p ON e.id_evenement = p.id_evenement
            WHERE e.id_createur = ? OR p.id_utilisateur = ?
            ORDER BY e.date_debut ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $events = $stmt->fetchAll();
        
        // Pour chaque événement, récupérer les participants
        foreach ($events as &$event) {
            $stmt_p = $pdo->prepare("
                SELECT u.id_utilisateur, u.nom, u.prenom, u.role
                FROM agenda_participants ap
                JOIN utilisateurs u ON ap.id_utilisateur = u.id_utilisateur
                WHERE ap.id_evenement = ?
            ");
            $stmt_p->execute([$event['id_evenement']]);
            $event['participants'] = $stmt_p->fetchAll();
        }
        
        json_response(['success' => true, 'data' => $events]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── POST — créer un événement ──────────────────────────────────
if ($method === 'POST' && !isset($_POST['_method'])) {
    $titre = clean_input($_POST['titre'] ?? '');
    $desc  = clean_input($_POST['description'] ?? '');
    $debut = $_POST['date_debut'] ?? '';
    $fin   = $_POST['date_fin'] ?? '';
    $lieu  = clean_input($_POST['lieu'] ?? '');
    
    if (empty($titre) || empty($debut) || empty($fin)) {
        json_response(['success' => false, 'message' => 'Titre, date début et date fin requis'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO agenda_evenements
                (titre, description, date_debut, date_fin, lieu, id_createur)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$titre, $desc, $debut, $fin, $lieu, $_SESSION['user_id']]);
        $id = $pdo->lastInsertId();
        
        // Ajouter les participants si fournis
        if (!empty($_POST['participants'])) {
            $participants = json_decode($_POST['participants'], true);
            if (is_array($participants)) {
                $stmt_p = $pdo->prepare("INSERT INTO agenda_participants (id_evenement, id_utilisateur) VALUES (?, ?)");
                foreach ($participants as $id_user) {
                    $stmt_p->execute([$id, intval($id_user)]);
                }
            }
        }
        
        log_activity($pdo, $_SESSION['user_id'], 'Événement créé', 'agenda_evenements', $id);
        json_response(['success' => true, 'message' => 'Événement créé', 'id' => $id], 201);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── PUT — modifier un événement ────────────────────────────────
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'PUT') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        // Vérifier que c'est le créateur
        $stmt = $pdo->prepare("SELECT id_createur FROM agenda_evenements WHERE id_evenement = ?");
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        
        if (!$event || $event['id_createur'] != $_SESSION['user_id']) {
            json_response(['success' => false, 'message' => 'Seul le créateur peut modifier cet événement'], 403);
        }
        
        $updates = [];
        $params  = [];
        
        if (isset($_POST['titre'])) {
            $updates[] = "titre = ?";
            $params[]  = clean_input($_POST['titre']);
        }
        if (isset($_POST['description'])) {
            $updates[] = "description = ?";
            $params[]  = clean_input($_POST['description']);
        }
        if (isset($_POST['date_debut'])) {
            $updates[] = "date_debut = ?";
            $params[]  = $_POST['date_debut'];
        }
        if (isset($_POST['date_fin'])) {
            $updates[] = "date_fin = ?";
            $params[]  = $_POST['date_fin'];
        }
        if (isset($_POST['lieu'])) {
            $updates[] = "lieu = ?";
            $params[]  = clean_input($_POST['lieu']);
        }
        
        if (empty($updates)) {
            json_response(['success' => false, 'message' => 'Rien à modifier'], 400);
        }
        
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE agenda_evenements SET " . implode(", ", $updates) . " WHERE id_evenement = ?");
        $stmt->execute($params);
        
        // Gérer les participants
        if (isset($_POST['participants'])) {
            // Supprimer les anciens
            $pdo->prepare("DELETE FROM agenda_participants WHERE id_evenement = ?")->execute([$id]);
            // Ajouter les nouveaux
            $participants = json_decode($_POST['participants'], true);
            if (is_array($participants)) {
                $stmt_p = $pdo->prepare("INSERT INTO agenda_participants (id_evenement, id_utilisateur) VALUES (?, ?)");
                foreach ($participants as $id_user) {
                    $stmt_p->execute([$id, intval($id_user)]);
                }
            }
        }
        
        log_activity($pdo, $_SESSION['user_id'], 'Événement modifié', 'agenda_evenements', $id);
        json_response(['success' => true, 'message' => 'Événement mis à jour']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

// ── DELETE ─────────────────────────────────────────────────────
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    
    try {
        // Vérifier que c'est le créateur
        $stmt = $pdo->prepare("SELECT id_createur FROM agenda_evenements WHERE id_evenement = ?");
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        
        if (!$event || $event['id_createur'] != $_SESSION['user_id']) {
            json_response(['success' => false, 'message' => 'Seul le créateur peut supprimer cet événement'], 403);
        }
        
        $stmt = $pdo->prepare("DELETE FROM agenda_evenements WHERE id_evenement = ?");
        $stmt->execute([$id]);
        
        log_activity($pdo, $_SESSION['user_id'], 'Événement supprimé', 'agenda_evenements', $id);
        json_response(['success' => true, 'message' => 'Événement supprimé']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
