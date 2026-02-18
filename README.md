# 🎓 SAPHIRE - Système de Gestion RH

## 📋 Présentation
Plateforme web dynamique développée pour centraliser la gestion administrative de l'Académie Sterling. Le système propose des interfaces distinctes selon le rôle de l'utilisateur (Direction, Inspecteurs, Chefs d'Établissement, Professeurs) et intègre des fonctionnalités avancées de rapport et d'export.

---

## 📂 Organisation des Fichiers

### Racine du Projet
- **Dashboards Spécifiques :** `dashboard-drh.php`, `dashboard-ia.php`, `dashboard-ce.php`, `dashboard-prof.php`.
- **Cœur du Système :** `index.php` (Portail), `auth.php` (Gestion des sessions), `config.php` (Connexion BDD).
- **Utilitaires :** `init_passwords.php` (Script de sécurisation des accès), `database.sql`.
- **Assets :** `logo-sterling.png`, `favicon.png`.

### 🚀 API & Services (`/api`)
Le dossier API regroupe toute la logique métier et le traitement des données :
- **Gestion du personnel :** `professeurs.php`, `utilisateurs.php`, `etablissements.php`.
- **Suivi Académique :** `inspections.php`, `notations.php`, `formations.php`.
- **Discipline & Présence :** `sanctions.php`, `absences.php`, `appels.php`.
- **Reporting :** `export_pdf_rapport.php`, `export_pdf_notation.php`.

---

## 🛠️ Stack Technique
- **Langages :** PHP 8.x (Backend), HTML5/CSS3, JavaScript.
- **Base de données :** MySQL (Structure relationnelle).
- **Exports :** Génération de rapports PDF dynamiques.
- **Sécurité :** Authentification centralisée et initialisation sécurisée des mots de passe.

---

## ⚙️ Installation & Administration (Profil Admin/Ops)

### 1. Déploiement de la Base de Données
Importer le fichier `database.sql` pour créer les tables nécessaires (utilisateurs, professeurs, établissements, etc.).

### 2. Configuration Système
Éditer le fichier `config.php` pour lier l'application à votre infrastructure :
```php
$host = 'localhost';
$dbname = 'academie_sterling';
$username = 'votre_user';
$password = 'votre_mdp';
