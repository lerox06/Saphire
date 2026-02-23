<?php
/**
 * Configuration SMTP et Classe Email
 * Académie Sterling - Système RH
 * 
 * CONFIGURATION GOOGLE WORKSPACE / GMAIL
 * 
 * ÉTAPES DE CONFIGURATION :
 * 1. Activer la validation en 2 étapes sur votre compte Google
 * 2. Générer un mot de passe d'application : https://myaccount.google.com/apppasswords
 * 3. Remplacer les valeurs ci-dessous
 */

// ================================================================
// CONFIGURATION SMTP - À PERSONNALISER
// ================================================================

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587); // 587 pour TLS (recommandé), 465 pour SSL
define('SMTP_ENCRYPTION', 'tls'); // 'tls' ou 'ssl'
define('SMTP_USERNAME', 'saphire@ac-sterling.fr'); // ← VOTRE EMAIL GOOGLE WORKSPACE
define('SMTP_PASSWORD', 'tunxdafdhlxblvar'); // ← MOT DE PASSE D'APPLICATION (16 caractères)
define('SMTP_FROM_EMAIL', 'saphire@ac-sterling.fr');
define('SMTP_FROM_NAME', 'Académie Sterling - Système RH');

// ================================================================
// CLASSE EMAIL
// ================================================================

class EmailNotification {
    
    /**
     * Envoyer un email via SMTP
     */
    public static function send($to, $subject, $body, $isHTML = true) {
        // Vérifier que l'email est valide
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Email invalide : $to");
            return false;
        }
        
        // Tentative avec PHPMailer (recommandé)
        if (file_exists(__DIR__ . '/PHPMailer.php')) {
            return self::sendWithPHPMailer($to, $subject, $body, $isHTML);
        }
        
        // Fallback : mail() natif (moins fiable avec Gmail)
        error_log("PHPMailer non trouvé, utilisation de mail() natif");
        $headers = [
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8'
        ];
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    /**
     * Envoyer via PHPMailer (méthode recommandée)
     */
    private static function sendWithPHPMailer($to, $subject, $body, $isHTML) {
        require_once __DIR__ . '/PHPMailer.php';
        require_once __DIR__ . '/SMTP.php';
        require_once __DIR__ . '/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            
            // Options SSL (si problèmes de certificat)
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            // Expéditeur et destinataire
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            
            // Contenu
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            if ($isHTML) {
                $mail->AltBody = strip_tags($body);
            }
            
            $result = $mail->send();
            error_log("Email envoyé avec succès à : $to");
            return $result;
            
        } catch (Exception $e) {
            error_log("Erreur email vers $to : {$mail->ErrorInfo}");
            return false;
        }
    }
    
    // ================================================================
    // TEMPLATE HTML
    // ================================================================
    
    private static function wrapTemplate($title, $content) {
        return '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f6f6f6; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; }
        .header { background: linear-gradient(135deg, #000091, #E1000F); color: #fff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 5px 0 0 0; font-size: 14px; opacity: 0.9; }
        .content { padding: 30px 20px; }
        .content h2 { color: #000091; margin-top: 0; }
        .footer { background: #f6f6f6; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #ddd; }
        .info-box { background: #E3F2FD; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #000091; }
        .alert { background: #FFF3E0; border-left: 4px solid #F57C00; padding: 15px; margin: 20px 0; }
        .danger { background: #FFEBEE; border-left: 4px solid #E1000F; }
        .success { background: #E8F5E9; border-left: 4px solid #18753C; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f6f6f6; font-weight: 700; }
        .divider { border-top: 1px solid #ddd; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 Académie Sterling</h1>
            <p>' . htmlspecialchars($title) . '</p>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <p>Cet email a été envoyé automatiquement par le système RH.</p>
            <p><strong>Merci de ne pas répondre à cet email.</strong></p>
            <p>© ' . date('Y') . ' Académie Sterling</p>
        </div>
    </div>
</body>
</html>';
    }
    
    // ================================================================
    // NOTIFICATIONS
    // ================================================================
    
    /**
     * Absence déclarée → Email au professeur
     */
    public static function notifyAbsence($professeur, $absence) {
        $content = '
            <h2>⚠️ Absence déclarée</h2>
            <p>Bonjour <strong>' . htmlspecialchars($professeur['prenom']) . ' ' . htmlspecialchars($professeur['nom']) . '</strong>,</p>
            <p>Une absence vous concernant a été enregistrée.</p>
            
            <div class="info-box">
                <strong>📅 Date :</strong> ' . date('d/m/Y', strtotime($absence['date_absence'])) . '<br>
                <strong>📋 Motif :</strong> ' . htmlspecialchars($absence['motif']) . '<br>
                <strong>⏱️ Durée :</strong> ' . htmlspecialchars($absence['duree_jours']) . ' jour(s)
            </div>
            
            <div class="alert">
                ⚠️ Si vous avez un justificatif, transmettez-le rapidement à votre établissement.
            </div>
        ';
        
        return self::send($professeur['email'], '⚠️ Absence déclarée', self::wrapTemplate('Notification d\'Absence', $content));
    }
    
    /**
     * Sanction prononcée → Email au professeur + copie hiérarchie
     */
    public static function notifySanction($professeur, $sanction, $emetteur, $responsables = []) {
        $types = [
            'avertissement' => 'Avertissement',
            'blame' => 'Blâme',
            'mise_a_pied' => 'Mise à pied',
            'convocation' => 'Convocation'
        ];
        $type = $types[$sanction['type_sanction']] ?? $sanction['type_sanction'];
        
        $content = '
            <h2>⚠️ Sanction disciplinaire</h2>
            <p>Bonjour <strong>' . htmlspecialchars($professeur['prenom']) . ' ' . htmlspecialchars($professeur['nom']) . '</strong>,</p>
            <p>Une sanction vous a été notifiée.</p>
            
            <div class="info-box danger">
                <strong>Type :</strong> ' . htmlspecialchars($type) . '<br>
                <strong>Date :</strong> ' . date('d/m/Y', strtotime($sanction['date_sanction'])) . '<br>
                <strong>Motif :</strong><br>' . nl2br(htmlspecialchars($sanction['motif'])) . '
            </div>
            
            <div class="alert">
                <strong>Émise par :</strong> ' . htmlspecialchars($emetteur['prenom']) . ' ' . htmlspecialchars($emetteur['nom']) . '
            </div>
        ';
        
        $html = self::wrapTemplate('Sanction disciplinaire', $content);
        $sent = self::send($professeur['email'], '⚠️ Sanction - ' . $type, $html);
        
        // Copie aux responsables
        foreach ($responsables as $r) {
            if (!empty($r['email'])) {
                self::send($r['email'], '[COPIE] Sanction - ' . $professeur['nom'], $html);
            }
        }
        
        return $sent;
    }
    
    /**
     * Notations saisies → Email au professeur
     */
    public static function notifyNotations($professeur, $notation, $etablissement) {
        $epreuves = [
            'Abdominaux' => $notation['abdominaux'],
            'Pompes' => $notation['pompes'],
            'Demi-Cooper' => $notation['demi_cooper'],
            'Souplesse' => $notation['souplesse'],
            'Vitesse' => $notation['vitesse']
        ];
        
        $details = '';
        $sum = 0;
        $count = 0;
        foreach ($epreuves as $label => $note) {
            if ($note > 0) {
                $sum += $note;
                $count++;
                $color = $note >= 15 ? '#18753C' : ($note >= 10 ? '#F57C00' : '#E1000F');
                $details .= '<tr><td>' . $label . '</td><td style="color:' . $color . ';font-weight:bold;">' . $note . '/20</td></tr>';
            }
        }
        
        $moyenne = $count > 0 ? round($sum / $count, 1) : 0;
        $moyColor = $moyenne >= 15 ? '#18753C' : ($moyenne >= 10 ? '#F57C00' : '#E1000F');
        
        $content = '
            <h2>📊 Nouvelles notations</h2>
            <p>Bonjour <strong>' . htmlspecialchars($professeur['prenom']) . ' ' . htmlspecialchars($professeur['nom']) . '</strong>,</p>
            <p>Vos notations ont été saisies.</p>
            
            <div class="info-box">
                <strong>Établissement :</strong> ' . htmlspecialchars($etablissement['nom_etablissement']) . '<br>
                <strong>Semaine :</strong> ' . $notation['semaine'] . '
            </div>
            
            <table><thead><tr><th>Épreuve</th><th>Note</th></tr></thead><tbody>' . $details . '</tbody>
            <tfoot><tr style="background:#E8EAF6;"><td><strong>MOYENNE</strong></td><td style="color:' . $moyColor . ';font-weight:bold;font-size:18px;">' . $moyenne . '/20</td></tr></tfoot>
            </table>
        ';
        
        return self::send($professeur['email'], '📊 Notations - Moyenne ' . $moyenne . '/20', self::wrapTemplate('Nouvelles notations', $content));
    }
}
