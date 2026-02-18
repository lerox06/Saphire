<?php
/**
 * API Export PDF Notation Hebdomadaire
 * Académie Sterling - Système RH
 * Style : Identique au tableau Excel
 */

require_once '../config.php';

if (!is_logged_in()) {
    die('Non autorisé');
}

// Récupérer les paramètres
$semaine = $_GET['semaine'] ?? '';
$id_etablissement = $_GET['id_etablissement'] ?? null;
$annee = $_GET['annee'] ?? date('Y');

if (empty($semaine)) {
    die('Semaine requise');
}

// Vérifier les droits
if (has_role(['CE', 'CEA']) && isset($_SESSION['id_etablissement'])) {
    $id_etablissement = $_SESSION['id_etablissement'];
}

try {
    // Récupérer les données
    $where = "WHERE n.semaine = ? AND n.annee = ?";
    $params = [$semaine, $annee];
    
    if ($id_etablissement) {
        $where .= " AND p.id_etablissement = ?";
        $params[] = $id_etablissement;
    }
    
    $stmt = $pdo->prepare("
        SELECT n.*, 
               p.nom as prof_nom, 
               p.prenom as prof_prenom,
               e.nom_etablissement,
               e.couleur_notation
        FROM notations_hebdomadaires n
        JOIN professeurs p ON n.id_professeur = p.id_professeur
        JOIN etablissements e ON p.id_etablissement = e.id_etablissement
        $where
        ORDER BY p.nom, p.prenom
    ");
    $stmt->execute($params);
    $notations = $stmt->fetchAll();
    
    if (empty($notations)) {
        die('Aucune notation trouvée');
    }
    
    $couleur = $notations[0]['couleur_notation'];
    $etablissement = $notations[0]['nom_etablissement'];
    
    // Extraire le numéro de semaine
    // Extraire le numéro de semaine (format YYYY-Www)
    $num_semaine = preg_replace('/^\d{4}-W0?/', '', $semaine);
    
    // Créer le HTML pour le PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                size: A4 landscape;
                margin: 15mm;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 11pt;
            }
            h1 {
                text-align: center;
                background: ' . $couleur . ';
                padding: 15px;
                border: 2px solid #000;
                font-size: 18pt;
                margin-bottom: 30px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th {
                background: ' . $couleur . ';
                border: 1px solid #000;
                padding: 8px;
                text-align: center;
                font-weight: bold;
            }
            td {
                border: 1px solid #000;
                padding: 8px;
                text-align: center;
            }
            td:first-child {
                text-align: left;
            }
            .note-cell {
                background: ' . $couleur . ';
                font-weight: bold;
            }
            .abs {
                color: #999;
                font-style: italic;
            }
        </style>
    </head>
    <body>
        <h1>Notation Professorale Semaine n°' . htmlspecialchars($num_semaine) . '</h1>
        <h3 style="text-align: center; margin-bottom: 20px;">' . htmlspecialchars($etablissement) . '</h3>
        <table>
            <thead>
                <tr>
                    <th>Enseignant</th>
                    <th>Appels</th>
                    <th>CDT</th>
                    <th>Bonus</th>
                    <th>Note</th>
                    <th>Commentaires</th>
                    <th>Donné lieu à</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($notations as $notation) {
        $enseignant = htmlspecialchars($notation['prof_nom'] . ' ' . $notation['prof_prenom']);
        $appels = strtolower($notation['note_appels']) === 'abs' ? '<span class="abs">Abs</span>' : htmlspecialchars($notation['note_appels']);
        $cdt = strtolower($notation['note_cdt']) === 'abs' ? '<span class="abs">Abs</span>' : htmlspecialchars($notation['note_cdt']);
        $note_totale = strtolower($notation['note_totale']) === 'abs' ? '<span class="abs">abs</span>' : htmlspecialchars($notation['note_totale']);
        $commentaire = htmlspecialchars($notation['commentaire'] ?? '');
        
        $html .= '
                <tr>
                    <td>' . $enseignant . '</td>
                    <td>' . $appels . '</td>
                    <td>' . $cdt . '</td>
                    <td></td>
                    <td class="note-cell">' . $note_totale . '</td>
                    <td>' . $commentaire . '</td>
                    <td></td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </body>
    </html>';
    
    // Utiliser DomPDF ou mPDF pour générer le PDF
    // Pour l'instant, on retourne le HTML (vous devrez installer une librairie PDF)
    
    // Option 1: Retourner le HTML pour impression navigateur
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    
    // Option 2: Si vous installez DomPDF ou mPDF, décommentez :
    /*
    require_once '../vendor/autoload.php';
    use Dompdf\Dompdf;
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("notation_semaine_{$num_semaine}.pdf", ["Attachment" => true]);
    */
    
} catch (PDOException $e) {
    die('Erreur: ' . $e->getMessage());
}
?>
