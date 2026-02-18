<?php
/**
 * API Export PDF Rapport d'Inspection Optimisé
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
    $stmt = $pdo->prepare("
        SELECT i.*,
               p.nom AS prof_nom,
               p.prenom AS prof_prenom,
               p.discipline_enseignee,
               e.nom_etablissement,
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

    if (has_role('IA') && $insp['id_etablissement'] != $_SESSION['id_etablissement']) {
        die('Accès non autorisé');
    }

    if ($insp['statut'] !== 'realisee' || empty($insp['commentaire'])) {
        die('Aucun rapport disponible pour cette inspection');
    }

    $type_labels = [
        'pedagogique' => 'Pédagogique',
        'administrative' => 'Administrative',
        'autre' => 'Autre'
    ];
    $type = $type_labels[$insp['type_inspection']] ?? $insp['type_inspection'];

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { size: A4; margin: 15mm; }
            body { font-family: Arial, sans-serif; font-size: 10.5pt; color: #333; line-height: 1.5; margin: 0; }
            
            /* Empêche la coupure des sections entre deux pages */
            .section { 
                page-break-inside: avoid; 
                margin-bottom: 15px; 
                padding: 12px; 
                background: #F9F9F9; 
                border-left: 4px solid #000091; 
            }

            .header {
                border-bottom: 3px solid #E1000F;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }
            .logo-text {
                text-align: center;
                font-size: 18pt;
                font-weight: bold;
                color: #000091;
                text-transform: uppercase;
            }
            .subtitle {
                text-align: center;
                font-size: 9pt;
                color: #666;
                text-transform: uppercase;
            }
            h1 {
                color: #E1000F;
                font-size: 14pt;
                text-align: center;
                margin: 15px 0;
                text-transform: uppercase;
                border-bottom: 2px solid #000091;
                padding-bottom: 5px;
            }
            .section-title {
                font-size: 11pt;
                font-weight: bold;
                color: #000091;
                margin-bottom: 8px;
                text-transform: uppercase;
            }
            .info-grid {
                display: table;
                width: 100%;
            }
            .info-row { display: table-row; }
            .info-label {
                display: table-cell;
                font-weight: bold;
                color: #666;
                width: 140px;
                padding-bottom: 4px;
            }
            .info-value { display: table-cell; padding-bottom: 4px; }
            
            .rapport-content {
                background: white;
                padding: 15px;
                border: 1px solid #DDD;
                white-space: pre-wrap;
                font-size: 10pt;
                line-height: 1.6;
            }
            .footer {
                position: running(footer);
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #E5E5E5;
                font-size: 8pt;
                color: #999;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo-text">Académie Sterling</div>
            <div class="subtitle">Rapport d\'Inspection</div>
        </div>

        <h1>Rapport d\'Inspection ' . htmlspecialchars($type) . '</h1>

        <div class="section">
            <div class="section-title">Informations de l\'Enseignant</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Nom :</div>
                    <div class="info-value">' . htmlspecialchars($insp['prof_nom']) . ' ' . htmlspecialchars($insp['prof_prenom']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Discipline :</div>
                    <div class="info-value">' . htmlspecialchars($insp['discipline_enseignee']) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Établissement :</div>
                    <div class="info-value">' . htmlspecialchars($insp['nom_etablissement']) . '</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Détails de l\'Inspection</div>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Type :</div>
                    <div class="info-value">' . htmlspecialchars($type) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date demande :</div>
                    <div class="info-value">' . date('d/m/Y', strtotime($insp['date_demande'])) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date inspection :</div>
                    <div class="info-value">' . ($insp['date_programmee'] ? date('d/m/Y', strtotime($insp['date_programmee'])) : 'Non programmée') . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Demandé par :</div>
                    <div class="info-value">' . htmlspecialchars($insp['demandeur_prenom']) . ' ' . htmlspecialchars($insp['demandeur_nom']) . '</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Description de la Demande</div>
            <div style="padding: 8px; background: white; border: 1px solid #DDD; font-size: 10pt;">
                ' . nl2br(htmlspecialchars($insp['description'] ?? 'Aucune description')) . '
            </div>
        </div>

        <div class="section">
            <div class="section-title">Rapport d\'Inspection</div>
            <div class="rapport-content">' . nl2br(htmlspecialchars($insp['commentaire'])) . '</div>
        </div>

        <div class="footer">
            Document généré le ' . date('d/m/Y à H:i') . ' — Académie Sterling — Système RH
        </div>
    </body>
    </html>';

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;

} catch (PDOException $e) {
    die('Erreur : ' . $e->getMessage());
}
?>