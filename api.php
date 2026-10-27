<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function guest_list(array $state): array
{
    return array_values($state['guests']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

start_session();
if (!is_logged_in()) {
    respond(['error' => 'Nicht angemeldet'], 401);
}
session_write_close();

$action = (string)($_GET['action'] ?? '');
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Eigener Header als einfacher CSRF-Schutz: Formulare fremder Seiten können ihn nicht setzen
    if (($_SERVER['HTTP_X_EINLASS'] ?? '') !== '1') {
        respond(['error' => 'Ungültige Anfrage'], 400);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
} elseif ($action !== 'list' && $action !== 'export') {
    respond(['error' => 'Nur POST erlaubt'], 405);
}

try {
    switch ($action) {
        case 'list':
            respond(with_state(function (array &$state) {
                return ['guests' => guest_list($state)];
            }));

        case 'checkin':
        case 'undo':
            $id = (string)($input['id'] ?? '');
            [$status, $result] = with_state(function (array &$state) use ($action, $id) {
                if (!isset($state['guests'][$id])) {
                    return [404, ['error' => 'Gast nicht gefunden']];
                }
                $g = $state['guests'][$id];
                if ($action === 'checkin' && $g['paid']) {
                    return [409, ['error' => 'already', 'guest' => $g, 'guests' => guest_list($state)]];
                }
                $g['paid'] = $action === 'checkin';
                $g['paidAt'] = $g['paid'] ? time() : null;
                $state['guests'][$id] = $g;
                log_action($action, $g);
                return [200, ['guest' => $g, 'guests' => guest_list($state)]];
            });
            respond($result, $status);

        case 'add':
            $raw = (string)($input['name'] ?? '');
            if (mb_strlen(clean_name($raw)) < 2) {
                respond(['error' => 'Bitte einen Namen eingeben'], 400);
            }
            respond(with_state(function (array &$state) use ($raw) {
                $guest = new_guest($raw, true);
                $existed = isset($state['guests'][$guest['id']]);
                if (!$existed) {
                    $state['guests'][$guest['id']] = $guest;
                    log_action('add', $guest);
                }
                return [
                    'guest'   => $state['guests'][$guest['id']],
                    'existed' => $existed,
                    'guests'  => guest_list($state),
                ];
            }));

        case 'export':
            $guests = with_state(function (array &$state) {
                return guest_list($state);
            });
            usort($guests, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="einlass-' . date('Y-m-d-Hi') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // damit Excel die Umlaute erkennt
            fputcsv($out, ['Name', 'Bezahlt', 'Uhrzeit', 'Nachgetragen'], ';', '"', '');
            foreach ($guests as $g) {
                fputcsv($out, [
                    $g['name'],
                    $g['paid'] ? 'ja' : 'nein',
                    $g['paidAt'] ? date('H:i', $g['paidAt']) : '',
                    $g['added'] ? 'ja' : '',
                ], ';', '"', '');
            }
            exit;

        default:
            respond(['error' => 'Unbekannte Aktion'], 400);
    }
} catch (Throwable $e) {
    respond(['error' => $e->getMessage()], 500);
}
