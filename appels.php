<?php
/**
 * API Gestion des Appels Quotidiens
 * Académie Sterling - Système RH
 *
 * Concept : suivi global journalier par établissement
 * (% d'appels non faits ce jour-là)
 * Stockage dans appels_quotidiens via un prof virtuel id=0
 * ou via une table dédiée — ici on utilise une table pivot simple.
 *
 * SCHÉMA UTILISÉ (table appels_quotidiens telle que définie en BDD) :
 *   id_appel, id_professeur (0 = global étab), date_appel, semaine,
 *   jour_semaine, statut, commentaire, id_declarant, date_saisie
 *
 * MAIS comme la vraie table lie à professeurs via FK,
 * on utilise une astuce : on stocke le % dans "commentaire"
 * et statut = 'present' pour un enregistrement global par (étab+date).
 *
 * => Solution propre : table séparée appels_globaux.
 *    Si elle n'existe pas, cette API la crée automatiquement.
 */

require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Non autorisé'], 401);
}

// Créer la table appels_globaux si elle n'existe pas encore
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `appels_globaux` (
            `id_appel`         INT          NOT NULL AUTO_INCREMENT,
            `id_etablissement` INT          NOT NULL,
            `date_jour`        DATE         NOT NULL,
            `jour_semaine`     VARCHAR(20)  NOT NULL,
            `pourcentage_non_fait` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `id_saisie_par`    INT          DEFAULT NULL,
            `date_saisie`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_modification` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_appel`),
            UNIQUE KEY `uq_appel_etab_date` (`id_etablissement`, `date_jour`),
            KEY `idx_appel_etab` (`id_etablissement`),
            KEY `idx_appel_date` (`date_jour`),
            CONSTRAINT `fk_appel_etab`
                FOREIGN KEY (`id_etablissement`)
                REFERENCES `etablissements` (`id_etablissement`)
                ON DELETE CASCADE,
            CONSTRAINT `fk_appel_saisie`
                FOREIGN KEY (`id_saisie_par`)
                REFERENCES `utilisateurs` (`id_utilisateur`)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Table existe déjà ou erreur non bloquante
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — récupérer les appels d'une période ─────────────────────
if ($method === 'GET') {
    try {
        $where  = [];
        $params = [];

        // CE/CEA : leur établissement uniquement
        if (has_role(['CE', 'CEA'])) {
            $where[]  = "a.id_etablissement = ?";
            $params[] = $_SESSION['id_etablissement'];
        }

        // DRH : peut filtrer par établissement
        if (has_role('DRH') && !empty($_GET['id_etablissement'])) {
            $where[]  = "a.id_etablissement = ?";
            $params[] = intval($_GET['id_etablissement']);
        }

        // Filtre dates
        if (!empty($_GET['date_debut']) && !empty($_GET['date_fin'])) {
            $where[]  = "a.date_jour BETWEEN ? AND ?";
            $params[] = $_GET['date_debut'];
            $params[] = $_GET['date_fin'];
        }

        $sql_where = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $pdo->prepare("
            SELECT a.*,
                   e.nom_etablissement,
                   u.nom  AS saisie_nom,
                   u.prenom AS saisie_prenom
            FROM appels_globaux a
            JOIN etablissements e ON a.id_etablissement = e.id_etablissement
            LEFT JOIN utilisateurs u ON a.id_saisie_par = u.id_utilisateur
            $sql_where
            ORDER BY a.date_jour ASC
        ");
        $stmt->execute($params);

        json_response(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// ── POST — saisir ou mettre à jour un appel ───────────────────────
if ($method === 'POST' && !isset($_POST['_method'])) {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }

    $date_jour = $_POST['date_jour'] ?? '';
    $pourcent  = $_POST['pourcentage_non_fait'] ?? null;

    if (empty($date_jour) || $pourcent === null || $pourcent === '') {
        json_response(['success' => false, 'message' => 'Date et pourcentage requis'], 400);
    }

    $pourcent = floatval($pourcent);
    if ($pourcent < 0 || $pourcent > 100) {
        json_response(['success' => false, 'message' => 'Le pourcentage doit être entre 0 et 100'], 400);
    }

    // Établissement
    if (has_role('DRH') && !empty($_POST['id_etablissement'])) {
        $id_etab = intval($_POST['id_etablissement']);
    } else {
        $id_etab = $_SESSION['id_etablissement'];
    }
    if (!$id_etab) {
        json_response(['success' => false, 'message' => 'Établissement requis'], 400);
    }

    // Nom du jour
    $ts   = strtotime($date_jour);
    $jours = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
    $jour_semaine = $jours[date('N', $ts) - 1];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO appels_globaux
                (id_etablissement, date_jour, jour_semaine, pourcentage_non_fait, id_saisie_par)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                pourcentage_non_fait = VALUES(pourcentage_non_fait),
                id_saisie_par        = VALUES(id_saisie_par),
                date_modification    = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$id_etab, $date_jour, $jour_semaine, $pourcent, $_SESSION['user_id']]);

        log_activity($pdo, $_SESSION['user_id'], 'Saisie appel quotidien', 'appels_globaux', $id_etab);
        json_response(['success' => true, 'message' => 'Appel enregistré avec succès']);

    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

// ── DELETE ────────────────────────────────────────────────────────
if ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
    if (!has_role(['CE', 'CEA', 'DRH'])) {
        json_response(['success' => false, 'message' => 'Non autorisé'], 403);
    }
    if (empty($_POST['id'])) {
        json_response(['success' => false, 'message' => 'ID requis'], 400);
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM appels_globaux WHERE id_appel = ?");
        $stmt->execute([intval($_POST['id'])]);
        json_response(['success' => true, 'message' => 'Appel supprimé']);
    } catch (PDOException $e) {
        json_response(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
    }
}

json_response(['success' => false, 'message' => 'Méthode non autorisée'], 405);
?>
