<?php
/**
 * Script d'initialisation des mots de passe
 * À exécuter UNE SEULE FOIS après l'installation
 * Accéder à ce fichier via votre navigateur : http://localhost/init_passwords.php
 */

require_once 'config.php';

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Initialisation des Mots de Passe</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            border-bottom: 3px solid #d97706;
            padding-bottom: 10px;
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            background: #dbeafe;
            color: #1e40af;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #1e3a8a;
            color: white;
        }
        .btn {
            background: #d97706;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #c2410c;
        }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔐 Initialisation des Mots de Passe - Académie Sterling</h1>";

if (isset($_POST['init'])) {
    try {
        // Générer le hash pour "password123"
        $password = 'password123';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Mettre à jour le compte DRH
        $stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE email = 'drh@ac-sterling.fr'");
        $stmt->execute([$hash]);
        $updated = $stmt->rowCount();
        
        echo "<div class='success'>";
        echo "<strong>✅ Succès !</strong><br>";
        echo "Le mot de passe a été initialisé avec succès.<br>";
        echo "Compte mis à jour : $updated<br><br>";
        echo "Vous pouvez maintenant vous connecter avec :";
        echo "</div>";
        
        echo "<table>";
        echo "<thead><tr><th>Rôle</th><th>Email</th><th>Mot de passe</th></tr></thead>";
        echo "<tbody>";
        echo "<tr><td>Directeur des Ressources Humaines</td><td>drh@ac-sterling.fr</td><td>password123</td></tr>";
        echo "</tbody></table>";
        
        echo "<div class='info'>";
        echo "<strong>⚠️ Important :</strong><br>";
        echo "1. Supprimez ce fichier (init_passwords.php) après l'initialisation pour des raisons de sécurité<br>";
        echo "2. Changez les mots de passe par défaut dès la première connexion<br>";
        echo "3. Accédez à la page de connexion : <a href='index.html'>index.html</a>";
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div class='error'>";
        echo "<strong>❌ Erreur :</strong><br>";
        echo "Impossible de mettre à jour les mots de passe : " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
} else {
    echo "<div class='info'>";
    echo "<strong>ℹ️ Information :</strong><br>";
    echo "Ce script va initialiser les mots de passe de tous les comptes de test.<br>";
    echo "Le mot de passe sera : <strong>password123</strong><br><br>";
    echo "Cliquez sur le bouton ci-dessous pour continuer.";
    echo "</div>";
    
    echo "<form method='post'>";
    echo "<button type='submit' name='init' class='btn'>🔄 Initialiser les Mots de Passe</button>";
    echo "</form>";
}

echo "</div></body></html>";
?>
