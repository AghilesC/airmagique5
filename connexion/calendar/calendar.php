<?php
// Désactiver l'affichage des erreurs pour les requêtes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(0);
}

include_once "../permissions.php";
include_once "../logincheck.php";

// Vérifier si l'utilisateur est connecté et a la permission d'accéder au calendrier
if (!isset($_SESSION['user_id']) || !checkAdminPermission($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Accès non autorisé']);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userFullName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Connexion à la base de données
include "../../config.php";

try {
    $connexion = mysqli_connect($dbhost, $dbuser, $dbpwd, $dbname);
    if (!$connexion) {
        throw new Exception("Erreur de connexion : " . mysqli_connect_error());
    }
    mysqli_set_charset($connexion, "utf8");
} catch (Exception $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Erreur de connexion à la base de données']);
        exit();
    }
    die("Erreur de connexion : " . $e->getMessage());
}

/**
 * Ensure the pivot table used to assign multiple technicians exists.
 *
 * @throws Exception when the table creation fails.
 */
function ensureInterventionTechniciansTable(mysqli $connexion): void
{
    $createTable = "CREATE TABLE IF NOT EXISTS intervention_technicians (
        intervention_id INT NOT NULL,
        technician_id INT NOT NULL,
        PRIMARY KEY (intervention_id, technician_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!mysqli_query($connexion, $createTable)) {
        throw new Exception("Impossible de vérifier la table d'association des techniciens: " . mysqli_error($connexion));
    }
}

/**
 * Retrieve the technicians associated with the provided interventions.
 *
 * @param mysqli $connexion
 * @param array<int> $interventionIds
 *
 * @return array<int, array<int, array<string, mixed>>> Map of intervention id to technician records
 */
function fetchTechniciansForInterventions(mysqli $connexion, array $interventionIds): array
{
    $mapping = [];
    if (empty($interventionIds)) {
        return $mapping;
    }

    $cleanIds = array_map('intval', $interventionIds);
    $cleanIds = array_unique(array_filter($cleanIds, static function ($value) {
        return $value > 0;
    }));

    if (empty($cleanIds)) {
        return $mapping;
    }

    $idList = implode(',', $cleanIds);
    $query = "SELECT it.intervention_id, it.technician_id,
                     a.first_name, a.last_name, a.depot, a.mail, a.phone_number
              FROM intervention_technicians it
              LEFT JOIN account a ON it.technician_id = a.idacount
              WHERE it.intervention_id IN ($idList)
              ORDER BY a.first_name, a.last_name";

    $result = mysqli_query($connexion, $query);
    if (!$result) {
        throw new Exception("Erreur lors de la récupération des techniciens: " . mysqli_error($connexion));
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $interventionId = (int) $row['intervention_id'];
        $technicianId = (int) $row['technician_id'];
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Technician #' . $technicianId;
        }

        if (!isset($mapping[$interventionId])) {
            $mapping[$interventionId] = [];
        }

        $mapping[$interventionId][] = [
            'id' => $technicianId,
            'name' => $fullName,
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'depot' => $row['depot'] ?? '',
            'email' => $row['mail'] ?? '',
            'phone_number' => $row['phone_number'] ?? ''
        ];
    }

    return $mapping;
}

/**
 * Synchronise the technicians assigned to an intervention.
 *
 * @param mysqli $connexion
 * @param int $interventionId
 * @param array<int> $technicianIds
 *
 * @throws Exception when database operations fail
 */
function syncInterventionTechnicians(mysqli $connexion, int $interventionId, array $technicianIds): void
{
    $interventionId = max(0, $interventionId);

    if (!mysqli_query($connexion, "DELETE FROM intervention_technicians WHERE intervention_id = " . $interventionId)) {
        throw new Exception("Erreur lors de la réinitialisation des techniciens assignés: " . mysqli_error($connexion));
    }

    if (empty($technicianIds)) {
        return;
    }

    $insertQuery = "INSERT INTO intervention_technicians (intervention_id, technician_id) VALUES (?, ?)";
    $stmt = mysqli_prepare($connexion, $insertQuery);
    if (!$stmt) {
        throw new Exception("Erreur de préparation de l'insertion des techniciens: " . mysqli_error($connexion));
    }

    foreach ($technicianIds as $techId) {
        $techId = (int) $techId;
        if ($techId <= 0) {
            continue;
        }

        mysqli_stmt_bind_param($stmt, "ii", $interventionId, $techId);
        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_error($connexion);
            mysqli_stmt_close($stmt);
            throw new Exception("Erreur lors de l'enregistrement des techniciens: " . $error);
        }
    }

    mysqli_stmt_close($stmt);
}

/**
 * Format an intervention title by appending technician names when available.
 */
function formatEventTitleWithTechnicians(string $baseTitle, array $technicians): string
{
    $baseTitle = trim($baseTitle);
    if (empty($technicians)) {
        return $baseTitle;
    }

    $names = array_map(static function ($tech) {
        return $tech['name'] ?? '';
    }, $technicians);
    $names = array_filter($names, static function ($name) {
        return trim($name) !== '';
    });

    if (empty($names)) {
        return $baseTitle;
    }

    return $baseTitle . ' — ' . implode(', ', $names);
}

try {
    ensureInterventionTechniciansTable($connexion);
} catch (Exception $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
    die($e->getMessage());
}

// Vérifier que les tables existent
$checkAccount = mysqli_query($connexion, "SHOW TABLES LIKE 'account'");
$checkInterventions = mysqli_query($connexion, "SHOW TABLES LIKE 'interventions'");

if (mysqli_num_rows($checkAccount) == 0 || mysqli_num_rows($checkInterventions) == 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Les tables nécessaires n\'existent pas']);
        exit();
    }
    die("Erreur: Les tables du calendrier n'existent pas. Veuillez exécuter les scripts SQL de création d'abord.");
}

// Gestion des requêtes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {
            case 'get_events':
                $query = "SELECT i.*,
                                CONCAT(a.first_name, ' ', a.last_name) as technician_name,
                                a.idacount as technician_id_ref,
                                a.depot as technician_depot,
                                a.mail as technician_email,
                                a.phone_number as technician_phone
                         FROM interventions i
                         LEFT JOIN account a ON i.technician_id = a.idacount
                         WHERE i.start_datetime >= ? AND i.start_datetime <= ?";
                $stmt = mysqli_prepare($connexion, $query);
                if (!$stmt) {
                    throw new Exception("Erreur de préparation de la requête: " . mysqli_error($connexion));
                }
                $start = $_POST['start'] ?? date('Y-m-01');
                $end = $_POST['end'] ?? date('Y-m-t');
                mysqli_stmt_bind_param($stmt, "ss", $start, $end);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                $rawEvents = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $rawEvents[] = $row;
                }
                mysqli_stmt_close($stmt);

                $technicianMap = fetchTechniciansForInterventions($connexion, array_column($rawEvents, 'id'));

                $events = [];
                foreach ($rawEvents as $row) {
                    $statusColors = [
                        'scheduled' => '#3788d8',
                        'in_progress' => '#ffc107',
                        'completed' => '#28a745',
                        'cancelled' => '#dc3545'
                    ];
                    $priorityColors = [
                        'low' => '#6c757d',
                        'medium' => '#17a2b8',
                        'high' => '#fd7e14',
                        'urgent' => '#dc3545'
                    ];
                    $backgroundColor = $priorityColors[$row['priority']] ?? $statusColors[$row['status']];

                    $interventionId = (int) $row['id'];
                    $assignedTechnicians = $technicianMap[$interventionId] ?? [];
                    $technicianIds = array_map(static function ($tech) {
                        return $tech['id'];
                    }, $assignedTechnicians);
                    $technicianSummary = implode(', ', array_map(static function ($tech) {
                        return $tech['name'];
                    }, $assignedTechnicians));

                    $primaryTechnician = $assignedTechnicians[0] ?? null;
                    $events[] = [
                        'id' => $interventionId,
                        'title' => formatEventTitleWithTechnicians($row['title'], $assignedTechnicians),
                        'start' => $row['start_datetime'],
                        'end' => $row['end_datetime'],
                        'backgroundColor' => $backgroundColor,
                        'borderColor' => $backgroundColor,
                        'extendedProps' => [
                            'description' => $row['description'],
                            'client_name' => $row['client_name'],
                            'client_address' => $row['client_address'],
                            'client_phone' => $row['client_phone'],
                            'technician_id' => $row['technician_id'],
                            'technician_name' => $row['technician_name'],
                            'technician_depot' => $row['technician_depot'],
                            'technician_email' => $row['technician_email'],
                            'technician_phone' => $row['technician_phone'],
                            'status' => $row['status'],
                            'priority' => $row['priority'],
                            'notes' => $row['notes'],
                            'raw_title' => $row['title'],
                            'technicians' => $assignedTechnicians,
                            'technician_ids' => $technicianIds,
                            'technician_summary' => $technicianSummary,
                            'primary_technician' => $primaryTechnician
                        ]
                    ];
                }
                echo json_encode($events);
                break;

            case 'get_technicians':
                $query = "SELECT idacount as id, 
                                CONCAT(first_name, ' ', last_name) as name,
                                first_name, last_name, mail as email,
                                phone_number, depot, validation
                         FROM account 
                         ORDER BY first_name, last_name";
                $result = mysqli_query($connexion, $query);
                if (!$result) {
                    throw new Exception("Erreur de requête: " . mysqli_error($connexion));
                }
                $technicians = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $technicians[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'email' => $row['email'],
                        'phone_number' => $row['phone_number'],
                        'depot' => $row['depot'],
                        'validation' => $row['validation']
                    ];
                }
                error_log("Nombre de techniciens trouvés: " . count($technicians));
                echo json_encode($technicians);
                break;

            case 'save_intervention':
                $title = mysqli_real_escape_string($connexion, $_POST['title'] ?? '');
                $description = mysqli_real_escape_string($connexion, $_POST['description'] ?? '');
                $client_name = mysqli_real_escape_string($connexion, $_POST['client_name'] ?? '');
                $client_address = mysqli_real_escape_string($connexion, $_POST['client_address'] ?? '');
                $client_phone = mysqli_real_escape_string($connexion, $_POST['client_phone'] ?? '');
                $start_datetime = $_POST['start_datetime'] ?? '';
                $end_datetime = $_POST['end_datetime'] ?? '';
                $status = $_POST['status'] ?? 'scheduled';
                $priority = $_POST['priority'] ?? 'medium';
                $notes = mysqli_real_escape_string($connexion, $_POST['notes'] ?? '');

                $technicianIdsInput = $_POST['technician_ids'] ?? [];
                if (!is_array($technicianIdsInput)) {
                    $technicianIdsInput = [$technicianIdsInput];
                }
                $technicianIds = array_values(array_unique(array_filter(array_map(static function ($value) {
                    if ($value === '' || $value === null) {
                        return null;
                    }
                    return (int) $value;
                }, $technicianIdsInput), static function ($value) {
                    return !is_null($value) && $value > 0;
                })));

                if (count($technicianIds) > 5) {
                    throw new Exception("Vous pouvez assigner jusqu'à 5 techniciens par intervention");
                }

                if (empty($title) || empty($start_datetime) || empty($end_datetime)) {
                    throw new Exception("Les champs titre, date de début et date de fin sont requis");
                }

                if (!empty($technicianIds)) {
                    $idList = implode(',', $technicianIds);
                    $techValidationQuery = "SELECT idacount FROM account WHERE idacount IN ($idList)";
                    $techValidationResult = mysqli_query($connexion, $techValidationQuery);
                    if (!$techValidationResult) {
                        throw new Exception("Erreur lors de la validation des techniciens: " . mysqli_error($connexion));
                    }

                    $foundIds = [];
                    while ($techRow = mysqli_fetch_assoc($techValidationResult)) {
                        $foundIds[] = (int) $techRow['idacount'];
                    }

                    sort($foundIds);
                    $sortedRequested = $technicianIds;
                    sort($sortedRequested);

                    if ($sortedRequested !== $foundIds) {
                        throw new Exception("Certains techniciens sélectionnés sont introuvables");
                    }
                }

                $primaryTechnicianId = $technicianIds[0] ?? null;

                if (isset($_POST['intervention_id']) && !empty($_POST['intervention_id'])) {
                    $query = "UPDATE interventions SET
                                title=?, description=?, client_name=?, client_address=?,
                                client_phone=?, technician_id=?, start_datetime=?, end_datetime=?,
                                status=?, priority=?, notes=?
                              WHERE id=?";
                    $stmt = mysqli_prepare($connexion, $query);
                    if (!$stmt) {
                        throw new Exception("Erreur de préparation de la requête de mise à jour");
                    }
                    $interventionId = (int) $_POST['intervention_id'];
                    mysqli_stmt_bind_param($stmt, "sssssisssssi",
                        $title, $description, $client_name, $client_address,
                        $client_phone, $primaryTechnicianId, $start_datetime, $end_datetime,
                        $status, $priority, $notes, $interventionId);
                } else {
                    $query = "INSERT INTO interventions
                             (title, description, client_name, client_address, client_phone,
                              technician_id, start_datetime, end_datetime, status, priority, notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($connexion, $query);
                    if (!$stmt) {
                        throw new Exception("Erreur de préparation de la requête d'insertion");
                    }
                    mysqli_stmt_bind_param($stmt, "sssssisssssi",
                        $title, $description, $client_name, $client_address, $client_phone,
                        $primaryTechnicianId, $start_datetime, $end_datetime, $status, $priority, $notes, $userId);
                    $interventionId = null;
                }

                if (mysqli_stmt_execute($stmt)) {
                    if (empty($interventionId)) {
                        $interventionId = mysqli_insert_id($connexion);
                    }
                    mysqli_stmt_close($stmt);
                    syncInterventionTechnicians($connexion, (int) $interventionId, $technicianIds);
                    echo json_encode(['success' => true]);
                } else {
                    $errorMessage = mysqli_error($connexion);
                    mysqli_stmt_close($stmt);
                    throw new Exception("Erreur lors de l'exécution de la requête: " . $errorMessage);
                }
                break;

            case 'delete_intervention':
                if (empty($_POST['intervention_id'])) {
                    throw new Exception("ID d'intervention manquant");
                }
                $intervention_id = intval($_POST['intervention_id']);
                $query = "DELETE FROM interventions WHERE id = ?";
                $stmt = mysqli_prepare($connexion, $query);
                if (!$stmt) {
                    throw new Exception("Erreur de préparation de la requête de suppression");
                }
                mysqli_stmt_bind_param($stmt, "i", $intervention_id);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $deleteTechStmt = mysqli_prepare($connexion, "DELETE FROM intervention_technicians WHERE intervention_id = ?");
                    if ($deleteTechStmt) {
                        mysqli_stmt_bind_param($deleteTechStmt, "i", $intervention_id);
                        mysqli_stmt_execute($deleteTechStmt);
                        mysqli_stmt_close($deleteTechStmt);
                    }
                    echo json_encode(['success' => true]);
                } else {
                    $deleteError = mysqli_error($connexion);
                    mysqli_stmt_close($stmt);
                    throw new Exception("Erreur lors de la suppression: " . $deleteError);
                }
                break;

            case 'update_event_time':
                if (empty($_POST['intervention_id']) || empty($_POST['start_datetime']) || empty($_POST['end_datetime'])) {
                    throw new Exception("Paramètres manquants pour la mise à jour");
                }
                $intervention_id = intval($_POST['intervention_id']);
                $start_datetime = $_POST['start_datetime'];
                $end_datetime = $_POST['end_datetime'];

                $query = "UPDATE interventions SET start_datetime=?, end_datetime=? WHERE id=?";
                $stmt = mysqli_prepare($connexion, $query);
                if (!$stmt) {
                    throw new Exception("Erreur de préparation de la requête de mise à jour d'horaire");
                }
                mysqli_stmt_bind_param($stmt, "ssi", $start_datetime, $end_datetime, $intervention_id);
                if (mysqli_stmt_execute($stmt)) {
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("Erreur lors de la mise à jour: " . mysqli_error($connexion));
                }
                break;

            default:
                throw new Exception("Action non reconnue");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    mysqli_close($connexion);
    exit();
}

mysqli_close($connexion);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Scheduler - inv.ctiai.com</title>

    <!-- Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- FullCalendar CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/index.global.min.css" rel="stylesheet">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8f9fa; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 1400px; width: 100%; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.15), 0 2px 8px rgba(0,0,0,.08); border: 1px solid #e9ecef; }
        .logo { max-width: 200px; max-height: 120px; margin-bottom: 10px; border-radius: 8px; }
        .page-title { color: #2c3e50; font-size: 32px; font-weight: 600; margin-bottom: 8px; letter-spacing: -0.5px; }
        .page-subtitle { color: #6c757d; font-size: 16px; margin-bottom: 15px; }
        .admin-badge { display: inline-block; background: linear-gradient(135deg, #e82226, #c91e21); color: #fff; padding: 6px 12px; border-radius: 16px; font-size: 12px; font-weight: 600; box-shadow: 0 2px 8px rgba(232,34,38,.3); }

        .toolbar { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.15), 0 2px 8px rgba(0,0,0,.08); border: 1px solid #e9ecef; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .toolbar-left { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }

        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; text-decoration: none; transition: all .3s ease; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: #e82226; color: #fff; }
        .btn-primary:hover { background: #c91e21; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(232,34,38,.3); }
        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-secondary:hover { background: #5a6268; transform: translateY(-1px); }
        .btn-outline { background: transparent; color: #e82226; border: 2px solid #e82226; }
        .btn-outline:hover { background: #e82226; color: #fff; }

        .calendar-container { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.15), 0 2px 8px rgba(0,0,0,.08); border: 1px solid #e9ecef; margin-bottom: 20px; }
        #calendar { max-width: 100%; }

        .priority-icon { position: absolute; top: 2px; right: 2px; font-size: 10px; z-index: 10; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.5); }
        .priority-low .priority-icon { color: #28a745; }
        .priority-medium .priority-icon { color: #ffc107; }
        .priority-high .priority-icon { color: #fd7e14; }
        .priority-urgent .priority-icon { color: #dc3545; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%{opacity:1;} 50%{opacity:.5;} 100%{opacity:1;} }

        .fc-theme-standard .fc-scrollgrid { border: 1px solid #e9ecef; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #e9ecef; }
        .fc-button-primary { background-color: #e82226 !important; border-color: #e82226 !important; font-weight: 600 !important; font-size: 13px !important; padding: 8px 16px !important; border-radius: 8px !important; transition: all .3s ease !important; }
        .fc-button-primary:hover { background-color: #c91e21 !important; border-color: #c91e21 !important; transform: translateY(-1px) !important; box-shadow: 0 4px 12px rgba(232,34,38,.3) !important; }
        .fc-button-primary:disabled { background-color: #e9ecef !important; border-color: #e9ecef !important; transform: none !important; box-shadow: none !important; }
        .fc-button-group .fc-button { margin: 0 2px !important; border-radius: 8px !important; font-weight: 600 !important; letter-spacing: .5px !important; text-transform: uppercase !important; font-size: 12px !important; padding: 10px 18px !important; }
        .fc-button-group { box-shadow: 0 4px 12px rgba(0,0,0,.1) !important; border-radius: 10px !important; overflow: hidden !important; background: #fff !important; padding: 4px !important; }
        .fc-prev-button, .fc-next-button, .fc-today-button { border-radius: 8px !important; font-weight: 600 !important; padding: 10px 16px !important; margin: 0 3px !important; }
        .fc-today-button { background-color: #17a2b8 !important; border-color: #17a2b8 !important; }
        .fc-today-button:hover { background-color: #138496 !important; border-color: #138496 !important; }
        .fc-event { border-radius: 6px; font-weight: 500; font-size: 12px; position: relative; cursor: pointer; }
        .fc .fc-toolbar.fc-header-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
        .fc .fc-toolbar-chunk { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .fc .fc-toolbar-title { font-size: 22px; font-weight: 600; }
        .fc-daygrid-event { white-space: normal !important; }
        .fc-daygrid-event .fc-event-title { white-space: normal !important; }
        .fc .fc-button { white-space: nowrap; }

        /* Form & Modal */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background-color: rgba(0,0,0,.5); animation: fadeIn .3s ease; }
        .modal.show { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content { background: #fff; margin: 5% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.3); animation: slideIn .3s ease; }
        @keyframes slideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; border-radius: 12px 12px 0 0; }
        .modal-title { color: #2c3e50; font-size: 20px; font-weight: 600; margin: 0; }
        .close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all .3s ease; }
        .close:hover { background: #e9ecef; color: #dc3545; }
        .modal-body { padding: 25px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #2c3e50; font-size: 14px; }
        .form-control { width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; font-family: inherit; transition: all .3s ease; background: #fff; }
        .form-control:focus { outline: none; border-color: #e82226; box-shadow: 0 0 0 3px rgba(232,34,38,.1); }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .form-control[multiple] { min-height: 140px; }
        .form-hint { display: block; margin-top: 6px; font-size: 12px; color: #6c757d; }
        .technician-picker { display: flex; flex-direction: column; gap: 10px; }
        .technician-selected-preview { display: flex; flex-wrap: wrap; gap: 8px; min-height: 34px; padding: 4px 0; }
        .technician-selected-preview .technician-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; background: #e7f1ff; color: #0d6efd; font-weight: 600; font-size: 12px; border: 1px solid #cfe2ff; }
        .technician-selected-preview .technician-chip .chip-remove { border: none; background: transparent; color: inherit; font-size: 14px; cursor: pointer; line-height: 1; padding: 0 2px; display: flex; align-items: center; justify-content: center; }
        .technician-selected-preview .technician-chip .chip-remove:hover { color: #0a58ca; }
        .technician-selected-preview .technician-empty { font-size: 13px; color: #6c757d; }
        .technician-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; border: 1px solid #e0e4ea; border-radius: 8px; padding: 10px; max-height: 220px; overflow: auto; background: #fff; }
        .technician-option { display: flex; gap: 10px; align-items: flex-start; padding: 8px; border-radius: 6px; background: #f8f9fa; border: 1px solid transparent; cursor: pointer; transition: background-color .2s ease, border-color .2s ease; }
        .technician-option:hover { background: #eef4ff; border-color: rgba(13, 110, 253, 0.25); }
        .technician-option input[type="checkbox"] { margin-top: 4px; }
        .technician-option .technician-details { display: flex; flex-direction: column; gap: 2px; }
        .technician-option .technician-name { font-weight: 600; color: #2c3e50; font-size: 13px; }
        .technician-option .technician-meta { font-size: 12px; color: #6c757d; }
        @media (max-width: 768px) { .technician-list { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); } }
        @media (max-width: 480px) { .technician-list { grid-template-columns: 1fr; } }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        /* ---- Time controls responsive ---- */
        .time-wrap { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .time-wrap .date-field { flex: 1 1 220px; min-width: 180px; }
        .time-wrap .time-selects { display: flex; gap: 8px; align-items: center; flex: 0 1 auto; }
        .time-wrap .time-selects select.form-control { max-width: 120px; }
        .time-wrap .colon { font-weight: 700; }

        /* Badges */
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-scheduled { background: #cce5ff; color: #0066cc; }
        .status-in_progress { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .priority-badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .priority-low { background: #d4edda; color: #155724; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-high { background: #ffeeba; color: #996600; }
        .priority-urgent { background: #f8d7da; color: #721c24; }

        .modal-footer { padding: 20px 25px; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; gap: 10px; background: #f8f9fa; border-radius: 0 0 12px 12px; }
        .info-section { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .info-section h4 { color: #2c3e50; font-size: 16px; margin-bottom: 10px; font-weight: 600; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px; }
        .info-label { font-weight: 600; color: #6c757d; }
        .info-value { color: #2c3e50; }

        .back-button { text-decoration: none; color: #eb2226; font-size: 24px; font-weight: bold; transition: all .3s ease; display: inline-flex; align-items: center; margin-bottom: 30px; }
        .back-button:hover { color: #d11e21; transform: translateX(-3px); }
        .back-button::before { content: '←'; margin-right: 8px; }

        /* Loading */
        .spinner { border: 3px solid #f3f3f3; border-top: 3px solid #e82226; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; margin-right: 8px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading { opacity: .7; pointer-events: none; }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 8px; }
        .alert-error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }

        /* ------- Responsive ------- */
        @media (max-width: 1024px) {
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 10px; }
            .header { padding: 15px; }
            .page-title { font-size: 24px; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left { justify-content: center; }
            .modal-content { width: 95%; margin: 10% auto; }
            .btn { padding: 12px 16px; width: 100%; justify-content: center; }
            .fc .fc-toolbar.fc-header-toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
            .fc .fc-toolbar-chunk { width: 100%; justify-content: center; }
            .fc .fc-toolbar-title { width: 100%; text-align: center; font-size: 20px; }
            .fc .fc-button { flex: 1 1 auto; }
            .form-control[multiple] { min-height: 120px; }

            /* Time controls: tout en colonne, full width */
            .time-wrap { flex-direction: column; align-items: stretch; gap: 8px; }
            .time-wrap .date-field { flex: 1 1 auto; min-width: 0; }
            .time-wrap .time-selects { width: 100%; justify-content: space-between; }
            .time-wrap .time-selects select.form-control { max-width: none; flex: 1 1 48%; }
            .time-wrap .colon { display: none; }
        }

        @media (max-width: 480px) {
            .calendar-container { padding: 15px; }
            .modal-body { padding: 15px; }
            .modal-header, .modal-footer { padding: 15px; }
            .fc .fc-button { width: 100%; }
            .fc .fc-toolbar-title { font-size: 18px; }
            .form-control[multiple] { min-height: 100px; }

            /* Encore plus confortable pour très petits écrans */
            .time-wrap .time-selects { flex-direction: column; gap: 8px; }
            .time-wrap .time-selects select.form-control { width: 100%; flex: 1 1 auto; }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <img src="../image/airmagique_logo.png" alt="AIR MAGIQUE Logo" class="logo">
            <h1 class="page-title">Intervention Scheduler</h1>
            <p class="page-subtitle">Manage and schedule your technician interventions</p>
            <div class="admin-badge">Administrator: <?php echo htmlspecialchars($userFullName, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <a href="../adminmenu/adminmenu.php" class="back-button">Back to Admin Menu</a>

        <div class="toolbar">
            <div class="toolbar-left">
                <button class="btn btn-primary" onclick="openInterventionModal()">
                    <i class="fas fa-plus"></i> Service Request
                </button>
                <button class="btn btn-outline" onclick="refreshCalendar()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="calendar-container">
            <div id="calendar"></div>
        </div>
    </div>

    <!-- Modal -->
    <div id="interventionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">New Intervention</h2>
                <button class="close" onclick="closeInterventionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="interventionForm">
                    <input type="hidden" id="interventionId" name="intervention_id">
                    <!-- Hidden fields envoyés au backend -->
                    <input type="hidden" id="start_datetime" name="start_datetime" required>
                    <input type="hidden" id="end_datetime" name="end_datetime" required>

                    <div class="form-group">
                        <label for="title">Intervention Title *</label>
                        <input type="text" id="title" name="title" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="info-section">
                        <h4><i class="fas fa-user"></i> Client Information</h4>
                        <div class="form-group">
                            <label for="client_name">Client Name</label>
                            <input type="text" id="client_name" name="client_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="client_address">Address</label>
                            <textarea id="client_address" name="client_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="client_phone">Phone</label>
                            <input type="tel" id="client_phone" name="client_phone" class="form-control">
                        </div>
                    </div>

                    <div class="info-section">
                        <h4><i class="fas fa-calendar"></i> Scheduling</h4>

                        <div class="form-group">
                            <label>Assigned Technicians (max 5)</label>
                            <div id="technicianPicker" class="technician-picker">
                                <div id="technicianSelectedPreview" class="technician-selected-preview" aria-live="polite"></div>
                                <div id="technicianList" class="technician-list" role="group" aria-label="Technicians"></div>
                            </div>
                            <small class="form-hint" id="technicianHelper">Select up to 5 technicians.</small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Date & Time *</label>
                                <div class="time-wrap">
                                    <input type="date" id="start_date" class="form-control date-field" required>
                                    <div class="time-selects">
                                        <select id="start_hour" class="form-control" required></select>
                                        <span class="colon">:</span>
                                        <select id="start_minute" class="form-control" required></select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>End Date & Time *</label>
                                <div class="time-wrap">
                                    <input type="date" id="end_date" class="form-control date-field" required>
                                    <div class="time-selects">
                                        <select id="end_hour" class="form-control" required></select>
                                        <span class="colon">:</span>
                                        <select id="end_minute" class="form-control" required></select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select id="priority" name="priority" class="form-control">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeInterventionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="deleteIntervention()" id="deleteBtn" style="display:none;">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button type="button" class="btn btn-primary" onclick="saveIntervention()" id="saveBtn">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>
    </div>

    <!-- FullCalendar JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/index.global.min.js"></script>

    <script>
        const TECHNICIAN_LIMIT = 5;
        let calendar;
        let technicians = [];
        let technicianSelectionCache = [];
        let currentEditingEvent = null;

        // ====== Time helpers ======
        function pad2(n) { return String(n).padStart(2, '0'); }
        function populateTimeSelects() {
            const hourIds = ['start_hour', 'end_hour'];
            const minuteIds = ['start_minute', 'end_minute'];
            hourIds.forEach(id => {
                const sel = document.getElementById(id);
                sel.innerHTML = '';
                for (let h = 0; h < 24; h++) {
                    const opt = document.createElement('option');
                    opt.value = pad2(h);
                    opt.textContent = pad2(h);
                    sel.appendChild(opt);
                }
            });
            minuteIds.forEach(id => {
                const sel = document.getElementById(id);
                sel.innerHTML = '';
                [0, 15, 30, 45].forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = pad2(m);
                    opt.textContent = pad2(m);
                    sel.appendChild(opt);
                });
            });
        }

        // ====== Date utils ======
        function roundToQuarterHour(date) {
            const d = new Date(date);
            const minutes = d.getMinutes();
            const roundedMinutes = Math.round(minutes / 15) * 15;
            d.setMinutes(roundedMinutes);
            d.setSeconds(0);
            d.setMilliseconds(0);
            return d;
        }
        function toLocalYYYYMMDD(date) {
            const d = new Date(date);
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 10);
        }
        function setTimeControls(dateObj, prefix) {
            const d = new Date(dateObj);
            const rounded = roundToQuarterHour(d);
            document.getElementById(`${prefix}_date`).value = toLocalYYYYMMDD(rounded);
            document.getElementById(`${prefix}_hour`).value = pad2(rounded.getHours());
            document.getElementById(`${prefix}_minute`).value = pad2(rounded.getMinutes());
        }
        function readTimeControls(prefix) {
            const date = document.getElementById(`${prefix}_date`).value;
            const hour = document.getElementById(`${prefix}_hour`).value;
            const minute = document.getElementById(`${prefix}_minute`).value;
            if (!date || hour === '' || minute === '') return '';
            return `${date}T${hour}:${minute}`;
        }
        function formatDateTimeForDB(date) {
            if (!date) return '';
            const d = new Date(date);
            if (Number.isNaN(d.getTime())) return '';
            const year = d.getFullYear();
            const month = pad2(d.getMonth() + 1);
            const day = pad2(d.getDate());
            const hours = pad2(d.getHours());
            const minutes = pad2(d.getMinutes());
            const seconds = pad2(d.getSeconds());
            return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
        }
        function ensureEndAfterStart() {
            const startStr = readTimeControls('start');
            const endStr = readTimeControls('end');
            if (!startStr || !endStr) return;
            const start = new Date(startStr);
            const end = new Date(endStr);
            if (end <= start) {
                const newEnd = new Date(start.getTime() + 60 * 60 * 1000);
                setTimeControls(newEnd, 'end');
            }
        }

        // ====== Init ======
        document.addEventListener('DOMContentLoaded', function() {
            populateTimeSelects();
            ['start_date','start_hour','start_minute','end_date','end_hour','end_minute'].forEach(id=>{
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', ensureEndAfterStart);
            });
            updateTechnicianHelper();
            loadTechnicians();
            initializeCalendar();
        });

        // ====== Technicians ======
        function loadTechnicians() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_technicians'
            })
            .then(response => {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    if (data.success === false) throw new Error(data.error || 'Unknown error');
                    technicians = data;
                    populateTechnicianList(technicianSelectionCache);
                } catch (e) {
                    console.error('JSON parsing error:', e, text);
                    throw new Error('Non-JSON response received from server');
                }
            })
            .catch(error => {
                console.error('Error loading technicians:', error);
                showNotification('Error loading technicians: ' + error.message, 'error');
            });
        }
        function populateTechnicianList(selectedIds = []) {
            const container = document.getElementById('technicianList');
            if (!container) return;

            const idsToSelect = (selectedIds.length ? selectedIds : technicianSelectionCache)
                .map(String)
                .filter(Boolean);

            const normalizedSelection = Array.from(new Set(idsToSelect)).slice(0, TECHNICIAN_LIMIT);

            container.innerHTML = '';

            if (technicians.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'technician-empty';
                empty.textContent = 'No technicians available.';
                container.appendChild(empty);
            } else {
                technicians.forEach(tech => {
                    const option = document.createElement('label');
                    option.className = 'technician-option';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'technician_ids[]';
                    checkbox.value = String(tech.id);
                    checkbox.checked = normalizedSelection.includes(checkbox.value);
                    checkbox.addEventListener('change', handleTechnicianCheckboxChange);

                    const details = document.createElement('div');
                    details.className = 'technician-details';

                    const name = document.createElement('span');
                    name.className = 'technician-name';
                    name.textContent = tech.name || `Technician #${tech.id}`;
                    details.appendChild(name);

                    const metaParts = [];
                    if (tech.depot) metaParts.push(tech.depot);
                    if (tech.phone_number) metaParts.push(tech.phone_number);
                    if (tech.email) metaParts.push(tech.email);
                    if (metaParts.length) {
                        const meta = document.createElement('span');
                        meta.className = 'technician-meta';
                        meta.textContent = metaParts.join(' • ');
                        details.appendChild(meta);
                    }

                    option.appendChild(checkbox);
                    option.appendChild(details);
                    container.appendChild(option);
                });
            }

            technicianSelectionCache = normalizedSelection;
            updateTechnicianHelper();
        }

        function getSelectedTechnicianIds() {
            const container = document.getElementById('technicianList');
            if (!container) {
                return technicianSelectionCache.slice();
            }
            return Array.from(container.querySelectorAll('input[name="technician_ids[]"]:checked')).map(cb => cb.value);
        }

        function resolveTechnicianName(id) {
            const match = technicians.find(tech => String(tech.id) === String(id));
            return match ? (match.name || `Technician #${match.id}`) : `Technician #${id}`;
        }

        function renderTechnicianPreview(selectedIds) {
            const preview = document.getElementById('technicianSelectedPreview');
            if (!preview) return;

            preview.innerHTML = '';
            if (!selectedIds.length) {
                const empty = document.createElement('span');
                empty.className = 'technician-empty';
                empty.textContent = 'No technician selected.';
                preview.appendChild(empty);
                return;
            }

            selectedIds.forEach(id => {
                const chip = document.createElement('span');
                chip.className = 'technician-chip';

                const nameSpan = document.createElement('span');
                nameSpan.textContent = resolveTechnicianName(id);
                chip.appendChild(nameSpan);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'chip-remove';
                removeBtn.innerHTML = '&times;';
                removeBtn.setAttribute('aria-label', `Remove ${nameSpan.textContent}`);
                removeBtn.addEventListener('click', () => {
                    const container = document.getElementById('technicianList');
                    if (!container) return;
                    const checkbox = Array.from(container.querySelectorAll('input[name="technician_ids[]"]'))
                        .find(cb => cb.value === String(id));
                    if (checkbox) {
                        checkbox.checked = false;
                        handleTechnicianCheckboxChange({ target: checkbox });
                    }
                });

                chip.appendChild(removeBtn);
                preview.appendChild(chip);
            });
        }

        function updateTechnicianHelper() {
            const helper = document.getElementById('technicianHelper');
            const selected = getSelectedTechnicianIds();
            if (helper) {
                if (selected.length === 0) {
                    helper.textContent = `Select up to ${TECHNICIAN_LIMIT} technicians.`;
                } else {
                    helper.textContent = `${selected.length} technician${selected.length > 1 ? 's' : ''} selected (max ${TECHNICIAN_LIMIT}).`;
                }
            }
            renderTechnicianPreview(selected);
        }

        function clearTechnicianSelection() {
            const container = document.getElementById('technicianList');
            if (container) {
                container.querySelectorAll('input[name="technician_ids[]"]').forEach(cb => { cb.checked = false; });
            }

            technicianSelectionCache = [];
            updateTechnicianHelper();
        }

        function setSelectedTechnicians(ids) {
            const idStrings = Array.isArray(ids)
                ? Array.from(new Set(ids.map(String))).slice(0, TECHNICIAN_LIMIT)
                : [];
            technicianSelectionCache = idStrings;

            const container = document.getElementById('technicianList');
            if (container) {
                container.querySelectorAll('input[name="technician_ids[]"]').forEach(cb => {
                    cb.checked = idStrings.includes(cb.value);
                });
            }

            updateTechnicianHelper();
        }

        function handleTechnicianCheckboxChange(event) {
            const checkbox = event && event.target ? event.target : null;
            if (!checkbox) return;

            let selected = getSelectedTechnicianIds();
            if (selected.length > TECHNICIAN_LIMIT) {
                checkbox.checked = false;
                selected = getSelectedTechnicianIds();
                showNotification(`Maximum ${TECHNICIAN_LIMIT} technicians can be assigned.`, 'warning');
            }

            technicianSelectionCache = selected;
            updateTechnicianHelper();
        }

        // ====== Calendar ======
        function getPriorityIcon(priority) {
            const icons = {
                'low': 'fas fa-check-circle',
                'medium': 'fas fa-exclamation-circle',
                'high': 'fas fa-exclamation-triangle',
                'urgent': 'fas fa-fire'
            };
            return icons[priority] || 'fas fa-info-circle';
        }
        function getStatusText(status) {
            const statusTexts = {
                'scheduled': 'Scheduled',
                'in_progress': 'In Progress',
                'completed': 'Completed',
                'cancelled': 'Cancelled'
            };
            return statusTexts[status] || status;
        }
        function getPriorityText(priority) {
            const priorityTexts = {
                'low': 'Low',
                'medium': 'Medium',
                'high': 'High',
                'urgent': 'Urgent'
            };
            return priorityTexts[priority] || priority;
        }

        function initializeCalendar() {
            const calendarEl = document.getElementById('calendar');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'en',
                firstDay: 1,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
                height: 'auto',
                editable: true,
                droppable: true,
                selectable: true,
                selectMirror: true,
                dayMaxEvents: true,
                weekends: true,
                events: function(fetchInfo, successCallback, failureCallback) {
                    loadEvents(fetchInfo.startStr, fetchInfo.endStr, successCallback, failureCallback);
                },
                eventClick: function(info) { openInterventionModal(info.event); },
                select: function(info) { openInterventionModal(null, info.startStr, info.endStr); },
                eventDrop: function(info) { handleEventDrop(info); },
                eventResize: function(info) { updateEventTime(info.event); },
                eventDisplay: 'block',
                eventTextColor: '#ffffff',
                eventDidMount: function(info) {
                    const event = info.event;
                    const props = event.extendedProps;
                    const priority = props.priority;

                    if (priority) {
                        const iconClass = getPriorityIcon(priority);
                        const icon = document.createElement('i');
                        icon.className = `priority-icon ${iconClass}`;
                        info.el.classList.add(`priority-${priority}`);
                        const content = info.el.querySelector('.fc-event-main') || info.el.querySelector('.fc-event-title-container') || info.el;
                        content.style.position = 'relative';
                        content.appendChild(icon);
                    }

                    const tooltipLines = [];
                    tooltipLines.push(event.title);
                    if (props.client_name) tooltipLines.push('Client: ' + props.client_name);
                    if (props.technicians && props.technicians.length) {
                        const names = props.technician_summary || props.technicians.map(t => t.name).join(', ');
                        tooltipLines.push('Technician(s): ' + names);
                    } else if (props.technician_name) {
                        tooltipLines.push('Technician: ' + props.technician_name + ' (' + (props.technician_depot || 'No depot') + ')');
                    } else {
                        tooltipLines.push('No technician assigned');
                    }
                    tooltipLines.push('Start: ' + new Date(event.start).toLocaleString('en-US'));
                    tooltipLines.push('End: ' + new Date(event.end).toLocaleString('en-US'));
                    tooltipLines.push('Status: ' + getStatusText(props.status));
                    tooltipLines.push('Priority: ' + getPriorityText(props.priority));
                    info.el.title = tooltipLines.join('\n');
                }
            });
            calendar.render();
        }

        function loadEvents(start, end, successCallback, failureCallback) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_events&start=${start}&end=${end}`
            })
            .then(response => {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success === false) throw new Error(data.error || 'Unknown error');
                successCallback(data);
            })
            .catch(error => {
                console.error('Error loading events:', error);
                showNotification('Error loading events: ' + error.message, 'error');
                failureCallback(error);
            });
        }

        // ====== Modal ======
        function openInterventionModal(event = null, startStr = null, endStr = null) {
            const modal = document.getElementById('interventionModal');
            const form = document.getElementById('interventionForm');
            const title = document.getElementById('modalTitle');
            const deleteBtn = document.getElementById('deleteBtn');

            form.reset();
            currentEditingEvent = event;

            document.getElementById('interventionId').value = '';
            document.getElementById('title').value = '';
            document.getElementById('description').value = '';
            document.getElementById('client_name').value = '';
            document.getElementById('client_address').value = '';
            document.getElementById('client_phone').value = '';
            clearTechnicianSelection();
            document.getElementById('status').value = 'scheduled';
            document.getElementById('priority').value = 'medium';
            document.getElementById('notes').value = '';
            document.getElementById('start_datetime').value = '';
            document.getElementById('end_datetime').value = '';

            if (event) {
                title.textContent = 'Edit Intervention';
                deleteBtn.style.display = 'inline-block';

                const props = event.extendedProps;
                document.getElementById('interventionId').value = event.id;
                document.getElementById('title').value = props.raw_title || event.title || '';
                document.getElementById('description').value = props.description || '';
                document.getElementById('client_name').value = props.client_name || '';
                document.getElementById('client_address').value = props.client_address || '';
                document.getElementById('client_phone').value = props.client_phone || '';
                setSelectedTechnicians(props.technician_ids || []);
                setTimeControls(event.start, 'start');
                setTimeControls(event.end, 'end');
                document.getElementById('status').value = props.status || 'scheduled';
                document.getElementById('priority').value = props.priority || 'medium';
                document.getElementById('notes').value = props.notes || '';
            } else {
                title.textContent = 'New Intervention';
                deleteBtn.style.display = 'none';

                if (startStr && endStr) {
                    setTimeControls(new Date(startStr), 'start');
                    setTimeControls(new Date(endStr), 'end');
                } else {
                    const now = new Date();
                    const startTime = new Date(now.getTime() + 15 * 60 * 1000);
                    const endTime = new Date(startTime.getTime() + 60 * 60 * 1000);
                    setTimeControls(startTime, 'start');
                    setTimeControls(endTime, 'end');
                }
            }

            modal.classList.add('show');
            setTimeout(() => { document.getElementById('title').focus(); }, 100);
        }

        function closeInterventionModal() {
            const modal = document.getElementById('interventionModal');
            modal.classList.remove('show');
            currentEditingEvent = null;
        }

        // ====== CRUD ======
        function saveIntervention() {
            const form = document.getElementById('interventionForm');
            const saveBtn = document.getElementById('saveBtn');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const startLocal = readTimeControls('start');
            const endLocal   = readTimeControls('end');
            if (!startLocal || !endLocal) {
                showNotification('Please select valid start and end date/time', 'error');
                return;
            }

            const startDate = new Date(startLocal);
            const endDate = new Date(endLocal);
            if (endDate <= startDate) {
                showNotification('End date must be after start date', 'error');
                return;
            }

            document.getElementById('start_datetime').value = formatDateTimeForDB(new Date(startLocal));
            document.getElementById('end_datetime').value   = formatDateTimeForDB(new Date(endLocal));

            saveBtn.innerHTML = '<div class="spinner"></div>Saving...';
            saveBtn.disabled = true;

            const formData = new FormData(form);
            formData.append('action', 'save_intervention');

            fetch('', { method: 'POST', body: formData })
            .then(response => {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    closeInterventionModal();
                    setTimeout(() => {
                        calendar.refetchEvents();
                        showNotification('Intervention saved successfully', 'success');
                    }, 100);
                } else {
                    showNotification('Error saving: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error saving: ' + error.message, 'error');
            })
            .finally(() => {
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                saveBtn.disabled = false;
            });
        }

        function deleteIntervention() {
            if (!currentEditingEvent || !confirm('Are you sure you want to delete this intervention?')) return;

            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.innerHTML = '<div class="spinner"></div>Deleting...';
            deleteBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'delete_intervention');
            formData.append('intervention_id', currentEditingEvent.id);

            fetch('', { method: 'POST', body: formData })
            .then(response => {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    closeInterventionModal();
                    setTimeout(() => {
                        calendar.refetchEvents();
                        showNotification('Intervention deleted successfully', 'success');
                    }, 100);
                } else {
                    showNotification('Error deleting: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error deleting: ' + error.message, 'error');
            })
            .finally(() => {
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                deleteBtn.disabled = false;
            });
        }

        function handleEventDrop(info) {
            if (info.view && info.view.type === 'dayGridMonth') {
                preserveEventTimeForMonthDrop(info);
            }
            updateEventTime(info.event);
        }

        function preserveEventTimeForMonthDrop(info) {
            const event = info.event;
            const oldEvent = info.oldEvent;
            if (!event || !event.start || !oldEvent || !oldEvent.start) {
                return;
            }

            const newStart = new Date(event.start.getTime());
            const oldStart = new Date(oldEvent.start.getTime());
            newStart.setHours(oldStart.getHours(), oldStart.getMinutes(), oldStart.getSeconds(), oldStart.getMilliseconds());

            let newEnd = null;
            if (oldEvent.end) {
                const oldEnd = new Date(oldEvent.end.getTime());
                const duration = oldEnd.getTime() - oldStart.getTime();
                if (duration > 0) {
                    newEnd = new Date(newStart.getTime() + duration);
                } else {
                    newEnd = new Date(newStart.getTime());
                }
            } else if (event.end) {
                const currentDuration = event.end.getTime() - event.start.getTime();
                if (!Number.isNaN(currentDuration) && currentDuration > 0) {
                    newEnd = new Date(newStart.getTime() + currentDuration);
                }
            }

            if (!newEnd) {
                newEnd = new Date(newStart.getTime() + 60 * 60 * 1000);
            }

            if (typeof event.setAllDay === 'function' && event.allDay) {
                event.setAllDay(false);
            }

            if (typeof event.setDates === 'function') {
                event.setDates(newStart, newEnd);
            } else {
                event.setStart(newStart);
                event.setEnd(newEnd);
            }
        }

        function updateEventTime(event) {
            const formData = new FormData();
            formData.append('action', 'update_event_time');
            formData.append('intervention_id', event.id);
            formData.append('start_datetime', formatDateTimeForDB(event.start));
            formData.append('end_datetime', formatDateTimeForDB(event.end));

            fetch('', { method: 'POST', body: formData })
            .then(response => {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    setTimeout(() => {
                        calendar.refetchEvents();
                        showNotification('Intervention updated', 'success');
                    }, 100);
                } else {
                    showNotification('Error updating: ' + (data.error || 'Unknown error'), 'error');
                    calendar.refetchEvents();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error updating: ' + error.message, 'error');
                calendar.refetchEvents();
            });
        }

        function refreshCalendar() {
            calendar.refetchEvents();
            loadTechnicians();
            showNotification('Calendar refreshed', 'info');
        }

        // ====== Notifications ======
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed; top: 20px; right: 20px; padding: 15px 20px; border-radius: 8px;
                color: white; font-weight: 600; z-index: 2000; animation: slideInRight .3s ease;
                max-width: 300px; word-wrap: break-word;`;
            const colors = { 'success': '#28a745', 'error': '#dc3545', 'warning': '#ffc107', 'info': '#17a2b8' };
            notification.style.backgroundColor = colors[type] || colors.info;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.style.animation = 'slideOutRight .3s ease';
                setTimeout(() => { if (notification.parentNode) notification.parentNode.removeChild(notification); }, 300);
            }, 3000);
        }

        // Fermer modal au clic à l'extérieur / Échap
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('interventionModal');
            if (event.target === modal) closeInterventionModal();
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeInterventionModal();
        });

        // Animations notifs
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
            @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
