<?php
/**
 * API locale JSON pour le tableau de bord
 * Compatible PHP 5.4
 */

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURATION ---
$GOOGLE_CLIENT_ID = '310662985612-csni7ojrs82j6qtksa0jdfo9pi7955nq.apps.googleusercontent.com';
// Ajoutez ici les emails autorisés.
$ALLOWED_EMAILS = array(
    'jb.bachoc64@gmail.com', 
    'jb.bachoc@pyrenees.com'
);

// Ajoutez ici les domaines autorisés (tous les emails de ce domaine pourront se connecter)
$ALLOWED_DOMAINS = array(
    'pyrenees.com',
    'sudouest.fr'
);

// ADMINS (Seuls ces emails peuvent modifier les données)
$ADMIN_EMAILS = array(
    'jb.bachoc@pyrenees.com',
    'm.brouca@pyrenees.com',
    'jb.bachoc64@gmail.com' // Garder temporairement pour test si besoin, sinon supprimer. Je laisse pour votre test.
);

// Fichier JSON "base de données"
$file = __DIR__ . '/data.json';

// Action : get, save, upsert, getOne, login, check_session
$action = isset($_GET['action']) ? $_GET['action'] : 'check_session';

// --- AUTHENTICATION HELPERS ---

function verify_google_token($id_token, $client_id) {
    // Vérification via l'endpoint public Google (simple pour PHP 5.4 sans librairie)
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $id_token;
    
    // Utilisation de CURL préférée si dispo, sinon file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // PRODUCTION: Verification SSL activée
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $resp = @file_get_contents($url);
    }

    if (!$resp) return false;
    $json = json_decode($resp, true);
    
    // Vérifications basiques
    if (isset($json['aud']) && $json['aud'] === $client_id) {
        return $json;
    }
    return false;
}

// --- ACTIONS PUBLIQUES (Login / Check) ---

if ($action === 'check_session') {
    $logged = isset($_SESSION['user']);
    $isAdmin = $logged && in_array($_SESSION['user']['email'], $ADMIN_EMAILS);
    if ($logged) $_SESSION['user']['isAdmin'] = $isAdmin;
    echo json_encode(array('logged' => $logged, 'user' => $logged ? $_SESSION['user'] : null));
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $token = isset($data['token']) ? $data['token'] : '';

    if (!$token) {
        http_response_code(400); echo json_encode(array('error' => 'Token manquant')); exit;
    }

    $payload = verify_google_token($token, $GOOGLE_CLIENT_ID);
    if (!$payload) {
        http_response_code(401); echo json_encode(array('error' => 'Token invalide')); exit;
    }

    $email = isset($payload['email']) ? $payload['email'] : '';
    
    // Vérification Whitelist (Emails OU Domaines)
    $is_allowed = false;
    $domain = substr(strrchr($email, "@"), 1);

    if (empty($ALLOWED_EMAILS) && empty($ALLOWED_DOMAINS)) {
        $is_allowed = true; 
    } else {
        if (in_array($email, $ALLOWED_EMAILS)) $is_allowed = true;
        if (in_array($domain, $ALLOWED_DOMAINS)) $is_allowed = true;
    }

    if (!$is_allowed) {
        http_response_code(403); echo json_encode(array('error' => 'Email non autorisé (' . $email . ')')); exit;
    }

    // Connexion OK
    $isAdmin = in_array($email, $ADMIN_EMAILS);
    $_SESSION['user'] = array(
        'email' => $email,
        'name' => isset($payload['name']) ? $payload['name'] : 'Utilisateur',
        'picture' => isset($payload['picture']) ? $payload['picture'] : '',
        'isAdmin' => $isAdmin
    );

    echo json_encode(array('status' => 'ok', 'user' => $_SESSION['user']));
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(array('status' => 'ok'));
    exit;
}

// --- PROTECTION DES ACTIONS SUIVANTES ---
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(array('error' => 'Authentification requise'));
    exit;
}

// --- DATABASE FUNCTIONS ---

/**
 * Lecture de la base JSON
 */
function read_db($file) {
    if (!file_exists($file)) {
        return array();
    }

    $content = file_get_contents($file);
    if ($content === false || $content === '') {
        return array();
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return array();
    }

    return $data;
}

/**
 * Écriture de la base JSON
 */
function write_db($file, $data) {
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    return true;
}


/**
 * ACTION : GET (tout le JSON)
 * GET api.php?action=get
 */
if ($action === 'get') {
    $data = read_db($file);
    echo json_encode($data);
    exit;
}


/**
 * ACTION : GET ONE (une seule ligne selon journal+année+mois)
 * GET api.php?action=getOne&journal=republique&year=2025&month=1
 */
if ($action === 'getOne') {
    $journal = isset($_GET['journal']) ? $_GET['journal'] : '';
    $year    = isset($_GET['year'])    ? intval($_GET['year']) : 0;
    $month   = isset($_GET['month'])   ? intval($_GET['month']) : 0;

    if ($journal === '' || $year === 0 || $month === 0) {
        http_response_code(400);
        echo json_encode(array('error' => 'Paramètres manquants pour getOne'));
        exit;
    }

    $data = read_db($file);
    foreach ($data as $row) {
        if (isset($row['journal'], $row['year'], $row['month'])
            && $row['journal'] === $journal
            && intval($row['year']) === $year
            && intval($row['month']) === $month) {

            echo json_encode($row);
            exit;
        }
    }

    // Rien trouvé
    echo json_encode(null);
    exit;
}


/**
 * ACTION : SAVE (remplacer TOUTE la base)
 * POST api.php?action=save
 * body = JSON du tableau complet
 */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user']['isAdmin']) || !$_SESSION['user']['isAdmin']) {
        http_response_code(403); echo json_encode(array('error' => 'Modification non autorisée (Admin requis)')); exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(array('error' => 'JSON envoyé invalide (save)'));
        exit;
    }

    if (!write_db($file, $data)) {
        http_response_code(500);
        echo json_encode(array('error' => 'Impossible d’écrire data.json'));
        exit;
    }

    echo json_encode(array('status' => 'ok', 'mode' => 'save'));
    exit;
}


/**
 * ACTION : UPSERT (mettre à jour ou ajouter UNE ligne)
 *
 * POST api.php?action=upsert
 * body = JSON d’un objet:
 * {
 *   "journal": "republique",
 *   "year": 2025,
 *   "month": 1,
 *   "porte": 123, ...
 * }
 */
if ($action === 'upsert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user']['isAdmin']) || !$_SESSION['user']['isAdmin']) {
        http_response_code(403); echo json_encode(array('error' => 'Modification non autorisée (Admin requis)')); exit;
    }

    $raw    = file_get_contents('php://input');
    $record = json_decode($raw, true);

    if (!is_array($record)) {
        http_response_code(400);
        echo json_encode(array('error' => 'JSON envoyé invalide (upsert)'));
        exit;
    }

    // Vérif minimum des clés d’identification
    if (!isset($record['journal']) || !isset($record['year']) || !isset($record['month'])) {
        http_response_code(400);
        echo json_encode(array('error' => 'journal, year et month sont obligatoires pour upsert'));
        exit;
    }

    $journal = $record['journal'];
    $year    = intval($record['year']);
    $month   = intval($record['month']);

    $data = read_db($file);
    $found = false;

    foreach ($data as $index => $row) {
        if (isset($row['journal'], $row['year'], $row['month'])
            && $row['journal'] === $journal
            && intval($row['year']) === $year
            && intval($row['month']) === $month) {

            // Remplacement
            $data[$index] = $record;
            $found = true;
            break;
        }
    }

    if (!$found) {
        // Ajout
        $data[] = $record;
    }

    if (!write_db($file, $data)) {
        http_response_code(500);
        echo json_encode(array('error' => 'Impossible d’écrire data.json (upsert)'));
        exit;
    }

    echo json_encode(array(
        'status' => 'ok',
        'mode'   => $found ? 'update' : 'insert'
    ));
    exit;
}


// ACTION inconnue
http_response_code(400);
echo json_encode(array('error' => 'action inconnue'));
