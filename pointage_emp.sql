-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : mar. 21 avr. 2026 à 21:31
-- Version du serveur : 9.1.0
-- Version de PHP : 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `pointage_emp`
--

-- --------------------------------------------------------

--
-- Structure de la table `admins`
--

DROP TABLE IF EXISTS `admins`;
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_complet` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `role` enum('superadmin','subadmin') COLLATE utf8mb4_unicode_ci DEFAULT 'superadmin',
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `nom_complet`, `created_at`, `role`, `created_by`) VALUES
(1, 'admin', '$2y$10$nt2p4x4jlKDg3x9ozo9HL.jQWBDhNGDCWAKpBsR83Xdx7eNGVL6ZS', 'Administrateur', '2026-04-07 15:14:00', 'superadmin', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `employes`
--

DROP TABLE IF EXISTS `employes`;
CREATE TABLE IF NOT EXISTS `employes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_employe` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `poste` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `webauthn_credential` text COLLATE utf8mb4_unicode_ci,
  `webauthn_credential_id` text COLLATE utf8mb4_unicode_ci,
  `created_by_admin` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_employe` (`code_employe`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `employes`
--

INSERT INTO `employes` (`id`, `code_employe`, `nom`, `prenom`, `poste`, `email`, `telephone`, `actif`, `date_creation`, `webauthn_credential`, `webauthn_credential_id`, `created_by_admin`) VALUES
(1, 'EMP001', 'Benali', 'Karim', 'Technicien', NULL, NULL, 1, '2026-04-07 15:14:04', NULL, NULL, NULL),
(2, 'EMP002', 'Hadj', 'Samira', 'Comptable', NULL, NULL, 1, '2026-04-07 15:14:04', NULL, NULL, NULL),
(3, 'EMP003', 'Merah', 'Youcef', 'Commercial', NULL, NULL, 1, '2026-04-07 15:14:04', NULL, NULL, NULL),
(4, '0025', 'Ndeyde', 'Ebeoumar', 'Directeur', 'ndeyde@gmail.com', '36979099', 1, '2026-04-08 00:03:15', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `otp_fallback_requests`
--

DROP TABLE IF EXISTS `otp_fallback_requests`;
CREATE TABLE IF NOT EXISTS `otp_fallback_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employe_id` int NOT NULL,
  `type` enum('arrivee','depart') COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `requested_latitude` decimal(10,7) DEFAULT NULL,
  `requested_longitude` decimal(10,7) DEFAULT NULL,
  `requested_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected','used','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by_admin_id` int DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `decision_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employe_id` (`employe_id`),
  KEY `status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `pointages`
--

DROP TABLE IF EXISTS `pointages`;
CREATE TABLE IF NOT EXISTS `pointages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employe_id` int NOT NULL,
  `type` enum('arrivee','depart') COLLATE utf8mb4_unicode_ci NOT NULL,
  `heure` datetime NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `adresse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_method` enum('webauthn','otp_fallback') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'webauthn',
  `otp_request_id` int DEFAULT NULL,
  `date_pointage` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `employe_id` (`employe_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `pointages`
--

INSERT INTO `pointages` (`id`, `employe_id`, `type`, `heure`, `latitude`, `longitude`, `adresse`, `verification_method`, `otp_request_id`, `date_pointage`) VALUES
(1, 1, 'arrivee', '2026-04-07 13:33:05', NULL, NULL, NULL, 'webauthn', NULL, '2026-04-07'),
(2, 2, 'depart', '2026-04-07 21:59:56', 36.7534503, 3.4727516, 'Centre commerciale El Yasmine, Rue Gare, Cité Ibn Khaldoun (1200 lgts), Promotion immo, Aliliguia, Boumerdès, Daïra Boumerdès, Boumerdès, 35000, Algérie', 'webauthn', NULL, '2026-04-07'),
(3, 2, 'arrivee', '2026-04-07 23:12:17', 36.7534503, 3.4727516, 'Centre commerciale El Yasmine, Rue Gare, Cité Ibn Khaldoun (1200 lgts), Promotion immo, Aliliguia, Boumerdès, Daïra Boumerdès, Boumerdès, 35000, Algérie', 'webauthn', NULL, '2026-04-07'),
(4, 4, 'arrivee', '2026-04-16 19:54:05', 36.6965900, 4.0537680, 'Chouhada, Laâzib n Ahmed, Tizi Ouzou, Daïra Tizi Ouzou, Tizi Ouzou, 15000, Algérie', 'webauthn', NULL, '2026-04-16');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
