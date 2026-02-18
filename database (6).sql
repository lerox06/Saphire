-- Base de données pour le Système RH de l'Académie Sterling
-- À importer dans phpMyAdmin

CREATE DATABASE IF NOT EXISTS academie_sterling CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE academie_sterling;

-- Table des établissements
CREATE TABLE etablissements (
    id_etablissement INT AUTO_INCREMENT PRIMARY KEY,
    nom_etablissement VARCHAR(255) NOT NULL,
    adresse TEXT,
    telephone VARCHAR(20),
    email VARCHAR(100),
    couleur_notation VARCHAR(7) DEFAULT '#FFFFCC',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des utilisateurs (CE, CEA, DRH, PROFESSEUR)
CREATE TABLE utilisateurs (
    id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role ENUM('CE', 'CEA', 'DRH', 'GESTIONNAIRE', 'PROFESSEUR') NOT NULL,
    id_etablissement INT,
    id_professeur INT NULL,
    actif TINYINT(1) DEFAULT 1,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion TIMESTAMP NULL,
    FOREIGN KEY (id_etablissement) REFERENCES etablissements(id_etablissement) ON DELETE SET NULL,
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des professeurs
CREATE TABLE professeurs (
    id_professeur INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    date_naissance DATE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    echelon VARCHAR(10),
    pseudo_discord VARCHAR(100),
    discipline_enseignee VARCHAR(100) NOT NULL,
    id_etablissement INT NOT NULL,
    statut ENUM('actif', 'inactif', 'suspendu') DEFAULT 'actif',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_etablissement) REFERENCES etablissements(id_etablissement) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des notations hebdomadaires
CREATE TABLE notations_hebdomadaires (
    id_notation INT AUTO_INCREMENT PRIMARY KEY,
    id_professeur INT NOT NULL,
    semaine VARCHAR(10) NOT NULL, -- Format: 2024-W01
    annee INT NOT NULL,
    note_appels VARCHAR(10) DEFAULT '0',
    note_cdt VARCHAR(10) DEFAULT '0',
    note_totale VARCHAR(10),
    commentaire TEXT,
    id_evaluateur INT,
    date_notation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE,
    FOREIGN KEY (id_evaluateur) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL,
    UNIQUE KEY unique_notation (id_professeur, semaine, annee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des demandes de formation
CREATE TABLE demandes_formations (
    id_demande INT AUTO_INCREMENT PRIMARY KEY,
    id_professeur INT NOT NULL,
    type_formation VARCHAR(255) NOT NULL,
    description TEXT,
    justification TEXT,
    statut ENUM('en_attente', 'approuvee', 'refusee', 'completee') DEFAULT 'en_attente',
    id_demandeur INT NOT NULL,
    id_validateur INT,
    date_demande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_reponse TIMESTAMP NULL,
    commentaire_drh TEXT,
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE,
    FOREIGN KEY (id_demandeur) REFERENCES utilisateurs(id_utilisateur) ON DELETE CASCADE,
    FOREIGN KEY (id_validateur) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des demandes d'inspection
CREATE TABLE demandes_inspections (
    id_inspection INT AUTO_INCREMENT PRIMARY KEY,
    id_professeur INT NOT NULL,
    motif TEXT NOT NULL,
    date_demandee DATE,
    statut ENUM('en_attente', 'planifiee', 'completee', 'annulee') DEFAULT 'en_attente',
    id_demandeur INT NOT NULL,
    id_inspecteur INT,
    date_demande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_inspection TIMESTAMP NULL,
    rapport TEXT,
    note_inspection DECIMAL(4,2),
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE,
    FOREIGN KEY (id_demandeur) REFERENCES utilisateurs(id_utilisateur) ON DELETE CASCADE,
    FOREIGN KEY (id_inspecteur) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des appels quotidiens (% d'appels non faits par jour)
CREATE TABLE appels_quotidiens (
    id_appel INT AUTO_INCREMENT PRIMARY KEY,
    id_etablissement INT NOT NULL,
    date_jour DATE NOT NULL,
    jour_semaine ENUM('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche') NOT NULL,
    pourcentage_non_fait DECIMAL(5,2) DEFAULT 0 CHECK (pourcentage_non_fait >= 0 AND pourcentage_non_fait <= 100),
    id_saisie_par INT,
    date_saisie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_etablissement) REFERENCES etablissements(id_etablissement) ON DELETE CASCADE,
    FOREIGN KEY (id_saisie_par) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL,
    UNIQUE KEY unique_appel_jour (id_etablissement, date_jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des motifs d'absences (gérés par le DRH)
CREATE TABLE motifs_absences (
    id_motif INT AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(100) NOT NULL,
    type ENUM('conge', 'maladie', 'formation', 'autre') NOT NULL,
    justification_requise TINYINT(1) DEFAULT 1,
    actif TINYINT(1) DEFAULT 1,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des absences des professeurs
CREATE TABLE absences_professeurs (
    id_absence INT AUTO_INCREMENT PRIMARY KEY,
    id_professeur INT NOT NULL,
    id_motif INT NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    nombre_jours INT GENERATED ALWAYS AS (DATEDIFF(date_fin, date_debut) + 1) STORED,
    justificatif_fourni TINYINT(1) DEFAULT 0,
    commentaire TEXT,
    id_declare_par INT,
    date_declaration TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE,
    FOREIGN KEY (id_motif) REFERENCES motifs_absences(id_motif),
    FOREIGN KEY (id_declare_par) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL,
    INDEX idx_professeur (id_professeur),
    INDEX idx_dates (date_debut, date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Données par défaut pour les motifs d'absences
INSERT INTO motifs_absences (libelle, type, justification_requise) VALUES
('Congé payé', 'conge', 0),
('Congé sans solde', 'conge', 1),
('Maladie', 'maladie', 1),
('Maladie longue durée', 'maladie', 1),
('Formation professionnelle', 'formation', 0),
('Formation externe', 'formation', 1),
('Congé maternité', 'conge', 1),
('Congé paternité', 'conge', 1),
('Absence non justifiée', 'autre', 1),
('Grève', 'autre', 0);

-- Table des logs d'activité
CREATE TABLE logs_activite (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur INT,
    action VARCHAR(255) NOT NULL,
    table_cible VARCHAR(100),
    id_cible INT,
    details TEXT,
    date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des motifs d'absence (gérés par le DRH)
CREATE TABLE motifs_absence (
    id_motif INT AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    couleur VARCHAR(7) DEFAULT '#666666',
    actif TINYINT(1) DEFAULT 1,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des absences des professeurs
CREATE TABLE absences (
    id_absence INT AUTO_INCREMENT PRIMARY KEY,
    id_professeur INT NOT NULL,
    id_motif INT NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    nombre_jours INT GENERATED ALWAYS AS (DATEDIFF(date_fin, date_debut) + 1) STORED,
    commentaire TEXT,
    justificatif_fourni TINYINT(1) DEFAULT 0,
    id_saisi_par INT,
    date_saisie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_professeur) REFERENCES professeurs(id_professeur) ON DELETE CASCADE,
    FOREIGN KEY (id_motif) REFERENCES motifs_absence(id_motif) ON DELETE RESTRICT,
    FOREIGN KEY (id_saisi_par) REFERENCES utilisateurs(id_utilisateur) ON DELETE SET NULL,
    INDEX idx_professeur (id_professeur),
    INDEX idx_dates (date_debut, date_fin),
    INDEX idx_motif (id_motif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertion des motifs par défaut
INSERT INTO motifs_absence (libelle, description, couleur) VALUES
('Maladie', 'Absence pour raison médicale', '#E1000F'),
('Congé', 'Congé payé ou RTT', '#000091'),
('Formation', 'Formation professionnelle', '#18753C'),
('Congé maternité/paternité', 'Congé parental', '#FF9940'),
('Autre', 'Autre motif', '#666666');

-- Insertion de données de test

-- Établissements
INSERT INTO etablissements (nom_etablissement, adresse, telephone, email) VALUES
('Lycée Sterling - Paris', '15 Avenue des Champs-Élysées, 75008 Paris', '01 23 45 67 89', 'paris@sterling.edu'),
('Collège Sterling - Lyon', '42 Rue de la République, 69002 Lyon', '04 78 90 12 34', 'lyon@sterling.edu'),
('Lycée Sterling - Marseille', '7 Boulevard Longchamp, 13001 Marseille', '04 91 23 45 67', 'marseille@sterling.edu');

-- Utilisateurs (mot de passe: "password123")
-- IMPORTANT: Après l'importation, exécutez le fichier init_passwords.php pour initialiser les mots de passe
-- Seul le DRH est actif au départ - il pourra créer les autres comptes
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, id_etablissement, actif) VALUES
('Bernard', 'Philippe', 'drh@ac-sterling.fr', 'temp_password', 'DRH', NULL, 1);

-- Professeurs de test
INSERT INTO professeurs (nom, prenom, date_naissance, email, echelon, pseudo_discord, discipline_enseignee, id_etablissement) VALUES
('Moreau', 'Claire', '1985-03-15', 'claire.moreau@sterling.edu', '7', 'ClaireM#1234', 'Mathématiques', 1),
('Petit', 'Thomas', '1990-07-22', 'thomas.petit@sterling.edu', '5', 'ThomasP#5678', 'Physique-Chimie', 1),
('Roux', 'Isabelle', '1988-11-30', 'isabelle.roux@sterling.edu', '6', 'IsabelleR#9012', 'Français', 1),
('Fournier', 'Marc', '1982-05-10', 'marc.fournier@sterling.edu', '8', 'MarcF#3456', 'Histoire-Géographie', 2),
('Girard', 'Nathalie', '1992-09-18', 'nathalie.girard@sterling.edu', '4', 'NathalieG#7890', 'Anglais', 2);

-- Index pour optimiser les recherches
CREATE INDEX idx_prof_etablissement ON professeurs(id_etablissement);
CREATE INDEX idx_notation_prof ON notations_hebdomadaires(id_professeur);
CREATE INDEX idx_notation_semaine ON notations_hebdomadaires(semaine, annee);
CREATE INDEX idx_demande_formation_prof ON demandes_formations(id_professeur);
CREATE INDEX idx_demande_inspection_prof ON demandes_inspections(id_professeur);
CREATE INDEX idx_logs_utilisateur ON logs_activite(id_utilisateur);
