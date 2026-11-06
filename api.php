<?php
declare(strict_types=1);

/**
 * Project Control Dashboard API
 * PHP 8.1+ with PDO_SQLite. Same-origin session authentication.
 * No payment functionality is implemented or supported.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['error' => 'Invalid JSON body']);
    return $data;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) respond(500, ['error' => 'PDO_SQLite extension is required']);
    $path = getenv('PROJECT_DASHBOARD_DB') ?: __DIR__ . '/storage/dashboard.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        respond(500, ['error' => 'Could not create database directory']);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('admin','member','viewer')),
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','running','on_hold','stopped')),
        owner_id INTEGER REFERENCES users(id), next_action TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_members (
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        member_role TEXT NOT NULL DEFAULT 'contributor' CHECK(member_role IN ('manager','contributor','viewer')),
        PRIMARY KEY(project_id,user_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','in_progress','blocked','done')),
        priority TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('low','medium','high','urgent')),
        assignee_id INTEGER REFERENCES users(id), due_date TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL, actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        event_type TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS approval_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        requested_by INTEGER NOT NULL REFERENCES users(id), approver_id INTEGER REFERENCES users(id),
        title TEXT NOT NULL, details TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled')),
        requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at TEXT
    )");
    return $pdo;
}
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $s = db()->prepare('SELECT id,name,email,role FROM users WHERE id=? AND is_active=1');
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}
function require_user(): array {
    $u = current_user();
    if (!$u) respond(401, ['error' => 'Login required']);
    return $u;
}
function require_write(array $u): void {
    if ($u['role'] === 'viewer') respond(403, ['error' => 'Viewer role is read-only']);
}
function log_activity(int $projectId, ?int $taskId, int $actorId, string $type, string $message): void {
    $s = db()->prepare('INSERT INTO activity_logs(project_id,task_id,actor_id,event_type,message) VALUES(?,?,?,?,?)');
    $s->execute([$projectId,$taskId,$actorId,$type,$message]);
    db()->prepare('UPDATE projects SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$projectId]);
}
function project_access(int $projectId, array $u): void {
    if ($u['role'] === 'admin') return;
    $s = db()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
    $s->execute([$projectId,$u['id']]);
    if (!$s->fetchColumn()) respond(404, ['error' => 'Project not found']);
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/']);
session_start();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = isset($_GET['route'])
    ? '/' . trim((string)$_GET['route'], '/')
    : (function () use ($path): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
        if (str_starts_with($path, $script . '/')) return '/' . ltrim(substr($path, strlen($script)), '/');
        if (str_starts_with($path, '/api/')) return '/' . substr($path, 5);
        return '/' . ltrim(substr($path, strlen(rtrim(dirname($script), '/'))), '/');
    })();
$body = in_array($method, ['POST','PATCH','PUT','DELETE'], true) ? input() : [];
$pdo = db();

if ($method === 'GET' && $route === '/health') respond(200, ['ok'=>true,'service'=>'project-control-api']);
if ($method === 'POST' && $route === '/auth/register') {
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) respond(403, ['error'=>'Registration is closed; ask an admin to create an account']);
    $name=trim((string)($body['name']??'')); $email=strtolower(trim((string)($body['email']??''))); $password=(string)($body['password']??'');
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) respond(422,['error'=>'First admin requires a name, valid email, and password of at least 12 characters']);
    $s=$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
    try{$s->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);}catch(Throwable $e){respond(409,['error'=>'Could not create admin account']);}
    session_regenerate_id(true); $_SESSION['user_id']=(int)$pdo->lastInsertId(); $_SESSION['csrf']=bin2hex(random_bytes(32));
    respond(201,['user'=>current_user(),'csrf_token'=>$_SESSION['csrf']]);
}
if ($method === 'POST' && $route === '/auth/login') {
    $email=strtolower(trim((string)($body['email']??''))); $password=(string)($body['password']??'');
    $s=$pdo->prepare('SELECT * FROM users WHERE email=? AND is_active=1');$s->execute([$email]);$u=$s->fetch();
    if(!$u || !password_verify($password,$u['password_hash'])) respond(401,['error'=>'Email or password is incorrect']);
    session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
    respond(200,['user'=>current_user(),'csrf_token'=>$_SESSION['csrf']]);
}
if ($method === 'POST' && $route === '/auth/logout') {
    require_user();
    if (!hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) respond(419,['error'=>'CSRF token mismatch']);
    $_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']);}
    session_destroy(); respond(200,['ok'=>true]);
}
if ($method === 'GET' && $route === '/auth/me') {
    $u=require_user(); respond(200,['user'=>$u,'csrf_token'=>$_SESSION['csrf']??null]);
}
$u=require_user();
if (in_array($method,['POST','PATCH','PUT','DELETE'],true)) {
    if (!hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) respond(419,['error'=>'CSRF token mismatch']);
    require_write($u);
}

if ($method==='GET' && $route==='/projects') {
    $status=$_GET['status']??'';$q=trim((string)($_GET['q']??''));
    $sql="SELECT p.*,u.name AS owner_name,(SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.status!='done') AS pending_tasks,(SELECT MAX(a.created_at) FROM activity_logs a WHERE a.project_id=p.id) AS last_activity FROM projects p LEFT JOIN users u ON u.id=p.owner_id";
    $params=[];if($u['role']!=='admin'){$sql.=" JOIN project_members pm ON pm.project_id=p.id AND pm.user_id=?";$params[]=$u['id'];}
    $where=[];if($status!==''){$where[]='p.status=?';$params[]=$status;}if($q!==''){$where[]='p.name LIKE ?';$params[]='%'.$q.'%';}
    if($where)$sql.=' WHERE '.implode(' AND ',$where);$sql.=' ORDER BY p.updated_at DESC';
    $s=$pdo->prepare($sql);$s->execute($params);respond(200,['projects'=>$s->fetchAll()]);
}
if ($method==='POST' && $route==='/projects') {
    $name=trim((string)($body['name']??''));$status=(string)($body['status']??'planned');
    if($name==='' || !in_array($status,['planned','running','on_hold','stopped'],true))respond(422,['error'=>'Valid project name and status are required']);
    $s=$pdo->prepare('INSERT INTO projects(name,description,status,owner_id,next_action) VALUES(?,?,?,?,?)');
    $s->execute([$name,trim((string)($body['description']??'')),$status,$u['id'],trim((string)($body['next_action']??''))]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_members(project_id,user_id,member_role) VALUES(?,?,'manager')")->execute([$id,$u['id']]);
    log_activity($id,null,(int)$u['id'],'project_created','Project created');respond(201,['id'=>$id]);
}
if (preg_match('#^/projects/(\d+)$#',$route,$m)) {
    $id=(int)$m[1];project_access($id,$u);
    if($method==='GET'){$s=$pdo->prepare('SELECT * FROM projects WHERE id=?');$s->execute([$id]);$p=$s->fetch();$t=$pdo->prepare('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at DESC');$t->execute([$id]);$a=$pdo->prepare('SELECT * FROM activity_logs WHERE project_id=? ORDER BY created_at DESC LIMIT 100');$a->execute([$id]);respond(200,['project'=>$p,'tasks'=>$t->fetchAll(),'activity'=>$a->fetchAll()]);}
    if($method==='PATCH'){$allowed=['name','description','status','next_action'];$sets=[];$vals=[];foreach($allowed as $k){if(array_key_exists($k,$body)){$sets[]="$k=?";$vals[]=$body[$k];}}if(!$sets)respond(422,['error'=>'No editable fields supplied']);$vals[]=$id;$pdo->prepare('UPDATE projects SET '.implode(',',$sets).',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute($vals);log_activity($id,null,(int)$u['id'],'project_updated','Project details updated');respond(200,['ok'=>true]);}
}
if (preg_match('#^/projects/(\d+)/tasks$#',$route,$m)) {
    $pid=(int)$m[1];project_access($pid,$u);
    if($method==='GET'){$s=$pdo->prepare('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at DESC');$s->execute([$pid]);respond(200,['tasks'=>$s->fetchAll()]);}
    if($method==='POST'){$title=trim((string)($body['title']??''));if($title==='')respond(422,['error'=>'Task title is required']);$priority=(string)($body['priority']??'medium');if(!in_array($priority,['low','medium','high','urgent'],true))respond(422,['error'=>'Invalid priority']);$s=$pdo->prepare('INSERT INTO tasks(project_id,title,description,priority,due_date) VALUES(?,?,?,?,?)');$s->execute([$pid,$title,trim((string)($body['description']??'')),$priority,$body['due_date']??null]);$tid=(int)$pdo->lastInsertId();log_activity($pid,$tid,(int)$u['id'],'task_created','Task added: '.$title);respond(201,['id'=>$tid]);}
}
if (preg_match('#^/tasks/(\d+)$#',$route,$m) && $method==='PATCH') {
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);$task=$s->fetch();if(!$task)respond(404,['error'=>'Task not found']);project_access((int)$task['project_id'],$u);
    $status=(string)($body['status']??$task['status']);if(!in_array($status,['todo','in_progress','blocked','done'],true))respond(422,['error'=>'Invalid task status']);
    $priority=(string)($body['priority']??$task['priority']);if(!in_array($priority,['low','medium','high','urgent'],true))respond(422,['error'=>'Invalid priority']);
    $title=trim((string)($body['title']??$task['title']));if($title==='')respond(422,['error'=>'Task title is required']);
    $s=$pdo->prepare('UPDATE tasks SET title=?,status=?,priority=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$title,$status,$priority,$status==='done'?date('c'):null,$id]);log_activity((int)$task['project_id'],$id,(int)$u['id'],'task_updated','Task updated: '.$title.' ('.$status.')');respond(200,['ok'=>true]);
}
if (preg_match('#^/projects/(\d+)/approvals$#',$route,$m) && $method==='POST') {
    $pid=(int)$m[1];project_access($pid,$u);$title=trim((string)($body['title']??''));if($title==='')respond(422,['error'=>'Approval title is required']);$s=$pdo->prepare('INSERT INTO approval_requests(project_id,requested_by,approver_id,title,details) VALUES(?,?,?,?,?)');$s->execute([$pid,$u['id'],$body['approver_id']??null,$title,trim((string)($body['details']??''))]);$id=(int)$pdo->lastInsertId();log_activity($pid,null,(int)$u['id'],'approval_requested','Approval requested: '.$title);respond(201,['id'=>$id]);
}
if (preg_match('#^/approvals/(\d+)$#',$route,$m) && $method==='PATCH') {
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM approval_requests WHERE id=?');$s->execute([$id]);$a=$s->fetch();if(!$a)respond(404,['error'=>'Approval not found']);project_access((int)$a['project_id'],$u);if($u['role']!=='admin' && (int)$a['approver_id']!==(int)$u['id'])respond(403,['error'=>'Only an admin or assigned approver can decide']);$status=(string)($body['status']??'');if(!in_array($status,['approved','rejected','cancelled'],true))respond(422,['error'=>'Invalid approval decision']);$pdo->prepare('UPDATE approval_requests SET status=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$id]);log_activity((int)$a['project_id'],null,(int)$u['id'],'approval_decided','Approval '.$status.': '.$a['title']);respond(200,['ok'=>true]);
}
respond(404,['error'=>'API route not found']);
