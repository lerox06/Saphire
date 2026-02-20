<?php
/**
 * API Export PDF Rapport d'Inspection - CORRIGÉ RENDU HTML
 * Académie Sterling - Système RH
 */

require_once '../config.php';

if (!is_logged_in()) {
    die('Non autorisé');
}

if (!has_role(['IA', 'IA_DASEN', 'DRH'])) {
    die('Accès non autorisé');
}

$id_inspection = intval($_GET['id'] ?? 0);
if (!$id_inspection) {
    die('ID inspection requis');
}

try {
    // Récupérer l'inspection
    $stmt = $pdo->prepare("
        SELECT i.*,
               p.nom AS prof_nom, 
               p.prenom AS prof_prenom,
               p.discipline_enseignee,
               e.nom_etablissement,
               e.id_etablissement as prof_etab_id,
               u.nom AS demandeur_nom,
               u.prenom AS demandeur_prenom
        FROM demandes_inspections i
        JOIN professeurs p ON i.id_professeur = p.id_professeur
        JOIN etablissements e ON p.id_etablissement = e.id_etablissement
        JOIN utilisateurs u ON i.id_demandeur = u.id_utilisateur
        WHERE i.id_inspection = ?
    ");
    $stmt->execute([$id_inspection]);
    $insp = $stmt->fetch();

    if (!$insp) {
        die('Inspection non trouvée');
    }

    // IA : vérifier établissement
    if (has_role('IA') && $insp['prof_etab_id'] != $_SESSION['id_etablissement']) {
        die('Accès non autorisé');
    }

    if ($insp['statut'] !== 'realisee' || empty($insp['commentaire'])) {
        die('Aucun rapport disponible pour cette inspection');
    }

    // Préparation des variables
    $type_labels = [
        'pedagogique' => 'Pédagogique',
        'administrative' => 'Administrative',
        'autre' => 'Autre'
    ];
    $type = htmlspecialchars($type_labels[$insp['type_inspection']] ?? $insp['type_inspection']);
    
    $prof_nom = htmlspecialchars($insp['prof_nom'] . ' ' . $insp['prof_prenom']);
    $discipline = htmlspecialchars($insp['discipline_enseignee']);
    $etablissement = htmlspecialchars($insp['nom_etablissement']);
    $demandeur = htmlspecialchars($insp['demandeur_prenom'] . ' ' . $insp['demandeur_nom']);
    $date_demande = date('d/m/Y', strtotime($insp['date_demande']));
    $date_insp = $insp['date_programmee'] ? date('d/m/Y', strtotime($insp['date_programmee'])) : 'Non programmée';
    $description = nl2br(htmlspecialchars($insp['description'] ?? 'Aucune description'));
    $date_generation = date('d/m/Y à H:i');

    /**
     * CORRECTION DU RENDU : 
     * Si le texte s'affiche avec des balises <h2>...</h2> visibles, 
     * c'est qu'il est encodé en entités HTML. On le décode ici.
     */
    $commentaire_html = html_entity_decode($insp['commentaire'], ENT_QUOTES, 'UTF-8');

    // Générer le HTML
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: A4; margin: 20mm; }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #333; line-height: 1.6; }
        .header {
            border-bottom: 3px solid #E1000F;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .logo {
            text-align: center;
            margin-bottom: 15px;
        }
        .logo-text {
            font-size: 20pt;
            font-weight: bold;
            color: #000091;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .subtitle {
            text-align: center;
            font-size: 10pt;
            color: #666;
            text-transform: uppercase;
        }
        h1 {
            color: #E1000F;
            font-size: 16pt;
            text-align: center;
            margin: 30px 0 20px;
            text-transform: uppercase;
            border-bottom: 2px solid #000091;
            padding-bottom: 10px;
        }
        .section {
            margin-bottom: 25px;
            padding: 15px;
            background: #F9F9F9;
            border-left: 4px solid #000091;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 12pt;
            font-weight: bold;
            color: #000091;
            margin-bottom: 12px;
            text-transform: uppercase;
        }
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        .info-row { display: table-row; }
        .info-label {
            display: table-cell;
            width: 150px;
            font-weight: bold;
            color: #666;
            padding: 4px 0;
        }
        .info-value { display: table-cell; padding: 4px 0; }
        
        .rapport-content {
            background: white;
            padding: 20px;
            border: 1px solid #DDD;
            line-height: 1.6;
        }
        /* Styles Quill pour le rendu interne */
        .rapport-content h1, .rapport-content h2, .rapport-content h3 { color: #000091; margin-top: 15px; margin-bottom: 10px; }
        .rapport-content h1 { font-size: 18pt; }
        .rapport-content h2 { font-size: 15pt; border-bottom: 1px solid #EEE; padding-bottom: 5px; }
        .rapport-content h3 { font-size: 13pt; }
        .rapport-content p { margin: 10px 0; }
        .rapport-content ul, .rapport-content ol { padding-left: 25px; }
        .rapport-content li { margin-bottom: 5px; }
        .rapport-content blockquote { 
            border-left: 4px solid #CCC; 
            padding-left: 15px; 
            font-style: italic; 
            color: #666; 
            margin: 15px 0;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 2px solid #E5E5E5;
            font-size: 9pt;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <div class="logo-text">Académie Sterling</div>
            <div class="subtitle">Rapport d'Inspection</div>
        </div>
    </div>

    <h1>Rapport d'Inspection {$type}</h1>

    <div class="section">
        <div class="section-title">Informations de l'Enseignant</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nom :</div>
                <div class="info-value">{$prof_nom}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Discipline :</div>
                <div class="info-value">{$discipline}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Établissement :</div>
                <div class="info-value">{$etablissement}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Détails de l'Inspection</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Type :</div>
                <div class="info-value">{$type}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Date demande :</div>
                <div class="info-value">{$date_demande}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Date inspection :</div>
                <div class="info-value">{$date_insp}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Demandé par :</div>
                <div class="info-value">{$demandeur}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Description de la Demande</div>
        <div style="padding: 10px; background: white; border: 1px solid #DDD;">
            {$description}
        </div>
    </div>

    <div class="section">
        <div class="section-title">Rapport d'Inspection</div>
        <div class="rapport-content">
            {$commentaire_html}
        </div>
    </div>

    <div class="footer">
        Document généré le {$date_generation} — Académie Sterling — Système RH
    </div>
</body>
</html>
HTML;

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;

} catch (PDOException $e) {
    die('Erreur : ' . $e->getMessage());
}