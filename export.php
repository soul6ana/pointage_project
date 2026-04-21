<?php
// export.php — Export Excel par mois avec filtrage
session_start();
require_once 'config.php';
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$role     = $_SESSION['admin_role'] ?? 'subadmin';
$admin_id = $_SESSION['admin_id'];

// Filtres
$filtre_mois = $_GET['mois']    ?? (isset($_GET['date']) ? substr($_GET['date'], 0, 7) : date('Y-m'));   // ex: 2026-04
$filtre_emp  = $_GET['employe'] ?? '';
$filtre_code = trim($_GET['code']  ?? '');
$filtre_nom  = trim($_GET['nom_f'] ?? '');

// Employés visibles selon rôle
if ($role === 'superadmin') {
    $employes_ids = null; // tous
} else {
    $s = $pdo->prepare("SELECT id FROM employes WHERE actif=1 AND created_by_admin=?");
    $s->execute([$admin_id]);
    $employes_ids = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id');
    if (empty($employes_ids)) {
        // Rien à exporter
        header('Content-Type: text/html');
        die('<p style="font-family:sans-serif;padding:20px">Aucun employé trouvé.</p>');
    }
}

// Construire requête
$annee = substr($filtre_mois, 0, 4);
$mois  = substr($filtre_mois, 5, 2);

$sql    = "SELECT p.*, e.nom, e.prenom, e.code_employe, e.poste
           FROM pointages p JOIN employes e ON p.employe_id = e.id
           WHERE YEAR(p.date_pointage) = ? AND MONTH(p.date_pointage) = ?";
$params = [$annee, $mois];

if ($employes_ids !== null) {
    $pl     = implode(',', array_fill(0, count($employes_ids), '?'));
    $sql   .= " AND p.employe_id IN ($pl)";
    $params = array_merge($params, $employes_ids);
}
if ($filtre_emp  !== '') { $sql .= " AND p.employe_id = ?";           $params[] = $filtre_emp; }
if ($filtre_code !== '') { $sql .= " AND e.code_employe LIKE ?";      $params[] = '%'.$filtre_code.'%'; }
if ($filtre_nom  !== '') { $sql .= " AND (e.nom LIKE ? OR e.prenom LIKE ?)"; $params[] = '%'.$filtre_nom.'%'; $params[] = '%'.$filtre_nom.'%'; }
$sql .= " ORDER BY e.nom, p.date_pointage ASC, p.heure ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pointages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Noms des mois en français
$noms_mois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$nom_mois  = $noms_mois[(int)$mois] . ' ' . $annee;

// ===== CRÉER EXCEL =====
$spreadsheet = new Spreadsheet();

// Grouper par semaine pour créer des onglets
$semaines = [];
foreach ($pointages as $p) {
    $sem = 'Semaine ' . date('W', strtotime($p['date_pointage']));
    $semaines[$sem][] = $p;
}

// Si aucune donnée, créer quand même un onglet vide
if (empty($semaines)) {
    $semaines['Aucune donnée'] = [];
}

$premier = true;
foreach ($semaines as $titre_sem => $lignes) {
    if ($premier) {
        $sheet = $spreadsheet->getActiveSheet();
        $premier = false;
    } else {
        $sheet = $spreadsheet->createSheet();
    }
    $sheet->setTitle($titre_sem);

    // Titre
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', 'Rapport Pointage — ' . $nom_mois . ' — ' . $titre_sem);
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1a1a2e']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // En-têtes
    $cols = ['A'=>'Code','B'=>'Nom','C'=>'Prénom','D'=>'Poste','E'=>'Type','F'=>'Heure','G'=>'Date','H'=>'Vérification','I'=>'Localisation'];
    foreach ($cols as $col => $titre) {
        $sheet->setCellValue($col.'2', $titre);
    }
    $sheet->getStyle('A2:I2')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D9E75']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(20);

    // Données
    $row = 3;
    foreach ($lignes as $p) {
        $type_label = $p['type'] === 'arrivee' ? 'Arrivée' : 'Départ';
        $gps = ($p['latitude'] && $p['longitude']) ? $p['latitude'].', '.$p['longitude'] : 'Non disponible';

        $sheet->setCellValue('A'.$row, $p['code_employe']);
        $sheet->setCellValue('B'.$row, $p['nom']);
        $sheet->setCellValue('C'.$row, $p['prenom']);
        $sheet->setCellValue('D'.$row, $p['poste'] ?? '');
        $sheet->setCellValue('E'.$row, $type_label);
        $sheet->setCellValue('F'.$row, date('H:i', strtotime($p['heure'])));
        $sheet->setCellValue('G'.$row, date('d/m/Y', strtotime($p['date_pointage'])));
        $sheet->setCellValue('H'.$row, ($p['verification_method'] ?? 'webauthn') === 'otp_fallback' ? 'OTP manager' : 'Empreinte');
        $sheet->setCellValue('I'.$row, $gps);

        $bg = ($row % 2 === 0) ? 'F7F9FC' : 'FFFFFF';
        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        ]);
        $couleur = $p['type'] === 'arrivee' ? '0F6E56' : 'A32D2D';
        $sheet->getStyle("E{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($couleur));
        $sheet->getStyle("E{$row}")->getFont()->setBold(true);
        $row++;
    }

    // Bordures
    if ($row > 2) {
        $sheet->getStyle("A2:I".($row-1))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);
    }

    // Total
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", "Total : " . count($lignes) . " pointage(s)");
    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
        'font' => ['bold' => true, 'italic' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4F8']],
    ]);

    // Largeurs
    foreach (['A'=>12,'B'=>18,'C'=>18,'D'=>20,'E'=>12,'F'=>10,'G'=>14,'H'=>16,'I'=>35] as $c=>$w) {
        $sheet->getColumnDimension($c)->setWidth($w);
    }
    // Centrer
    if ($row > 3) {
        $sheet->getStyle("A3:H".($row-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}

// Premier onglet actif
$spreadsheet->setActiveSheetIndex(0);

// Télécharger
$nom_fichier = 'pointages_' . str_replace('-', '_', $filtre_mois) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nom_fichier . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;