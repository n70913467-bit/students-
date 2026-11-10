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
    // Keep API request bodies bounded to avoid oversized JSON payloads exhausting memory.
    $maxBytes = 1024 * 1024;
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) respond(413, ['error' => 'Request body is too large']);
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > $maxBytes) respond(413, ['error' => 'Request body is too large']);
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['error' => 'Invalid JSON body']);
    return $data;
}
function text_field(mixed $value, int $maxBytes, string $label, bool $required = false): string {
    if (!is_string($value) && !is_numeric($value)) respond(422, ['error' => $label . ' must be text']);
    $text = trim((string)$value);
    if ($required && $text === '') respond(422, ['error' => $label . ' is required']);
    if (strlen($text) > $maxBytes) respond(422, ['error' => $label . ' is too long (maximum ' . $maxBytes . ' bytes)']);
    return $text;
}
function optional_date_field(mixed $value, string $label): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value)) respond(422, ['error' => $label . ' must be a YYYY-MM-DD date']);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        respond(422, ['error' => $label . ' must be a valid YYYY-MM-DD date']);
    }
    return $value;
}
function password_field(mixed $value): string {
    if (!is_string($value)) respond(422, ['error' => 'Password must be text']);
    if (strlen($value) > 1024) respond(422, ['error' => 'Password is too long (maximum 1024 bytes)']);
    return $value;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) respond(500, ['error' => 'PDO_SQLite extension is required']);
    $path = getenv('PROJECT_DASHBOARD_DB') ?: __DIR__ . '/storage/dashboard.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        respond(500, ['error' => 'Could not create database directory']);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (is_file($path)) @chmod($path, 0600);
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
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projects_status_updated ON projects(status,updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_members_user_project ON project_members(user_id,project_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_project_status ON tasks(project_id,status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_project_created ON activity_logs(project_id,created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approvals_project_status ON approval_requests(project_id,status)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_key TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_key_time ON login_attempts(attempt_key,attempted_at)');
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
function password_algorithm(): string|int {
    return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)
        ? PASSWORD_ARGON2ID
        : PASSWORD_DEFAULT;
}
function login_attempt_key(string $email): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower($email) . '|' . $ip);
}
function log_activity(int $projectId, ?int $taskId, int $actorId, string $type, string $message): void {
    $s = db()->prepare('INSERT INTO activity_logs(project_id,task_id,actor_id,event_type,message) VALUES(?,?,?,?,?)');
    $s->execute([$projectId,$taskId,$actorId,$type,$message]);
    db()->prepare('UPDATE projects SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$projectId]);
}
function project_access(int $projectId, array $u): string {
    $exists = db()->prepare('SELECT 1 FROM projects WHERE id=?');
    $exists->execute([$projectId]);
    if (!$exists->fetchColumn()) respond(404, ['error' => 'Project not found']);
    if ($u['role'] === 'admin') return 'manager';
    $s = db()->prepare('SELECT member_role FROM project_members WHERE project_id=? AND user_id=?');
    $s->execute([$projectId,$u['id']]);
    $role = $s->fetchColumn();
    if (!$role) respond(404, ['error' => 'Project not found']);
    return (string)$role;
}
function project_write_access(int $projectId, array $u): void {
    $membership = project_access($projectId, $u);
    if ($u['role'] !== 'admin' && $membership === 'viewer') respond(403, ['error' => 'Project viewer access is read-only']);
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('PROJECT_DASHBOARD_COOKIE_SECURE') === '1';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
if ($secure) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
session_set_cookie_params(['lifetime'=>0,'httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/']);
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
    $setupKey = getenv('PROJECT_DASHBOARD_SETUP_KEY');
    if ($setupKey === false || $setupKey === '') {
        respond(503, ['error' => 'First-admin setup is not configured']);
    }
    if (!hash_equals($setupKey, (string)($body['setup_key'] ?? ''))) {
        respond(403, ['error' => 'A valid first-admin setup key is required']);
    }
    $name=text_field($body['name']??'',120,'Name',true);$email=strtolower(text_field($body['email']??'',254,'Email',true));$password=password_field($body['password']??'');
    if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) respond(422,['error'=>'First admin requires a name, valid email, and password of at least 12 characters']);
    $pdo->exec('BEGIN IMMEDIATE');
    if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0){$pdo->exec('ROLLBACK');respond(403,['error'=>'Registration is closed; ask an admin to create an account']);}
    try{
        $s=$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
        $s->execute([$name,$email,password_hash($password,password_algorithm())]);
        $newUserId=(int)$pdo->lastInsertId();$pdo->exec('COMMIT');
    }catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable $ignored){}respond(409,['error'=>'Could not create admin account']);}
    session_regenerate_id(true);$_SESSION['user_id']=$newUserId;$_SESSION['csrf']=bin2hex(random_bytes(32));
    respond(201,['user'=>current_user(),'csrf_token'=>$_SESSION['csrf']]);
}
if ($method === 'POST' && $route === '/auth/login') {
    $email=strtolower(text_field($body['email']??'',254,'Email'));$password=password_field($body['password']??'');
    $attemptKey=login_attempt_key($email);
    $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now','-1 day')")->execute();
    $rate=$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE attempt_key=? AND attempted_at >= datetime('now','-15 minutes')");
    $rate->execute([$attemptKey]);
    if((int)$rate->fetchColumn()>=10)respond(429,['error'=>'Too many login attempts. Try again in 15 minutes']);
    $s=$pdo->prepare('SELECT * FROM users WHERE email=? AND is_active=1');$s->execute([$email]);$u=$s->fetch();
    if(!$u || !password_verify($password,$u['password_hash'])){
        $pdo->prepare('INSERT INTO login_attempts(attempt_key) VALUES(?)')->execute([$attemptKey]);
        respond(401,['error'=>'Email or password is incorrect']);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$attemptKey]);
    if(password_needs_rehash($u['password_hash'],password_algorithm())){
        $hash=password_hash($password,password_algorithm());
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash,$u['id']]);
    }
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
if($method==='GET' && $route==='/users'){
    if($u['role']!=='admin')respond(403,['error'=>'Only admins can list users']);
    $users=$pdo->query('SELECT id,name,email,role,is_active FROM users ORDER BY name')->fetchAll();
    respond(200,['users'=>$users]);
}
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
    if ($u['role'] !== 'admin') respond(403, ['error' => 'Only admins can create projects']);
    $name=text_field($body['name']??'',120,'Project name',true);$status=(string)($body['status']??'planned');
    if($name==='' || !in_array($status,['planned','running','on_hold','stopped'],true))respond(422,['error'=>'Valid project name and status are required']);
    $s=$pdo->prepare('INSERT INTO projects(name,description,status,owner_id,next_action) VALUES(?,?,?,?,?)');
    $description=text_field($body['description']??'',5000,'Project description');$nextAction=text_field($body['next_action']??'',5000,'Next action');$s->execute([$name,$description,$status,$u['id'],$nextAction]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_members(project_id,user_id,member_role) VALUES(?,?,'manager')")->execute([$id,$u['id']]);
    log_activity($id,null,(int)$u['id'],'project_created','Project created');respond(201,['id'=>$id]);
}
if (preg_match('#^/projects/(\d+)$#',$route,$m)) {
    $id=(int)$m[1];$membership=project_access($id,$u);
    if($method==='GET'){$s=$pdo->prepare('SELECT * FROM projects WHERE id=?');$s->execute([$id]);$p=$s->fetch();$t=$pdo->prepare('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at DESC');$t->execute([$id]);$a=$pdo->prepare('SELECT * FROM activity_logs WHERE project_id=? ORDER BY created_at DESC LIMIT 100');$a->execute([$id]);respond(200,['project'=>$p,'tasks'=>$t->fetchAll(),'activity'=>$a->fetchAll(),'access'=>project_access($id,$u)]);}
    if($method==='PATCH'){
        if($u['role']!=='admin' && $membership!=='manager')respond(403,['error'=>'Only admins or project managers can edit project details']);$allowed=['name','description','status','next_action'];$sets=[];$vals=[];
        foreach($allowed as $k){
            if(!array_key_exists($k,$body))continue;
            $value=$body[$k];
            if($k==='name'){$value=text_field($value,120,'Project name',true);} if($k==='description'){$value=text_field($value,5000,'Project description');} if($k==='next_action'){$value=text_field($value,5000,'Next action');}
            if($k==='status' && !in_array($value,['planned','running','on_hold','stopped'],true))respond(422,['error'=>'Invalid project status']);
            $sets[]="$k=?";$vals[]=$value;
        }
        if(!$sets)respond(422,['error'=>'No editable fields supplied']);
        $vals[]=$id;$pdo->prepare('UPDATE projects SET '.implode(',',$sets).',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute($vals);
        log_activity($id,null,(int)$u['id'],'project_updated','Project details updated');respond(200,['ok'=>true]);
    }
}
if (preg_match('#^/projects/(\d+)/members$#',$route,$m) && $method==='GET') {
    $pid=(int)$m[1];$membership=project_access($pid,$u);
    if($u['role']!=='admin' && $membership!=='manager')respond(403,['error'=>'Only admins or project managers can list members']);
    $s=$pdo->prepare('SELECT u.id,u.name,u.email,u.role,pm.member_role FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name');
    $s->execute([$pid]);respond(200,['members'=>$s->fetchAll()]);
}
if (preg_match('#^/projects/(\d+)/activities$#',$route,$m) && $method==='POST') {
    $pid=(int)$m[1];project_write_access($pid,$u);$message=text_field($body['message']??'',5000,'Activity message',true);
    log_activity($pid,null,(int)$u['id'],'note_added',$message);respond(201,['ok'=>true]);
}
if (preg_match('#^/projects/(\d+)/tasks$#',$route,$m)) {
    $pid=(int)$m[1];project_access($pid,$u);
    if($method==='GET'){$s=$pdo->prepare('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at DESC');$s->execute([$pid]);respond(200,['tasks'=>$s->fetchAll()]);}
    if($method==='POST'){project_write_access($pid,$u);$title=text_field($body['title']??'',120,'Task title',true);$priority=(string)($body['priority']??'medium');if(!in_array($priority,['low','medium','high','urgent'],true))respond(422,['error'=>'Invalid priority']);$description=text_field($body['description']??'',5000,'Task description');$dueDate=optional_date_field($body['due_date']??null,'Due date');$s=$pdo->prepare('INSERT INTO tasks(project_id,title,description,priority,due_date) VALUES(?,?,?,?,?)');$s->execute([$pid,$title,$description,$priority,$dueDate]);$tid=(int)$pdo->lastInsertId();log_activity($pid,$tid,(int)$u['id'],'task_created','Task added: '.$title);respond(201,['id'=>$tid]);}
}
if (preg_match('#^/tasks/(\d+)$#',$route,$m) && $method==='PATCH') {
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);$task=$s->fetch();if(!$task)respond(404,['error'=>'Task not found']);project_write_access((int)$task['project_id'],$u);
    $status=(string)($body['status']??$task['status']);if(!in_array($status,['todo','in_progress','blocked','done'],true))respond(422,['error'=>'Invalid task status']);
    $priority=(string)($body['priority']??$task['priority']);if(!in_array($priority,['low','medium','high','urgent'],true))respond(422,['error'=>'Invalid priority']);
    $title=text_field($body['title']??$task['title'],120,'Task title',true);
    $s=$pdo->prepare('UPDATE tasks SET title=?,status=?,priority=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$title,$status,$priority,$status==='done'?date('c'):null,$id]);log_activity((int)$task['project_id'],$id,(int)$u['id'],'task_updated','Task updated: '.$title.' ('.$status.')');respond(200,['ok'=>true]);
}
if ($method==='POST' && $route==='/users') {
    if ($u['role']!=='admin') respond(403,['error'=>'Only admins can create users']);
    $name=text_field($body['name']??'',120,'Name',true);$email=strtolower(text_field($body['email']??'',254,'Email',true));$password=password_field($body['password']??'');$role=(string)($body['role']??'member');
    if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || !in_array($role,['admin','member','viewer'],true)) respond(422,['error'=>'Name, valid email, 12-character password, and valid role are required']);
    try{$s=$pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');$s->execute([$name,$email,password_hash($password,password_algorithm()),$role]);}
    catch(Throwable $e){respond(409,['error'=>'Could not create user; email may already exist']);}
    respond(201,['id'=>(int)$pdo->lastInsertId()]);
}
if (preg_match('#^/projects/(\d+)/members$#',$route,$m) && $method==='POST') {
    $pid=(int)$m[1];$membership=project_access($pid,$u);
    if($u['role']!=='admin' && $membership!=='manager') respond(403,['error'=>'Only admins or project managers can add members']);
    $userId=(int)($body['user_id']??0);$memberRole=(string)($body['member_role']??'contributor');
    if($userId<1 || !in_array($memberRole,['manager','contributor','viewer'],true)) respond(422,['error'=>'Valid user_id and member_role are required']);
    if($u['role']!=='admin' && $memberRole==='manager') respond(403,['error'=>'Only admins can assign project manager access']);
    $check=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$check->execute([$userId]);if(!$check->fetchColumn()) respond(404,['error'=>'Active user not found']);
    $pdo->prepare('INSERT INTO project_members(project_id,user_id,member_role) VALUES(?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET member_role=excluded.member_role')->execute([$pid,$userId,$memberRole]);
    log_activity($pid,null,(int)$u['id'],'member_added','Project member added or updated');respond(201,['ok'=>true]);
}
if (preg_match('#^/projects/(\d+)/approvals$#',$route,$m) && $method==='GET') {
    $pid=(int)$m[1];project_access($pid,$u);$s=$pdo->prepare('SELECT * FROM approval_requests WHERE project_id=? ORDER BY requested_at DESC');$s->execute([$pid]);respond(200,['approvals'=>$s->fetchAll()]);
}
if (preg_match('#^/projects/(\d+)$#',$route,$m) && $method==='DELETE') {
    if($u['role']!=='admin') respond(403,['error'=>'Only admins can delete projects']);
    $id=(int)$m[1];$s=$pdo->prepare('DELETE FROM projects WHERE id=?');$s->execute([$id]);if(!$s->rowCount())respond(404,['error'=>'Project not found']);respond(200,['ok'=>true]);
}
if (preg_match('#^/tasks/(\d+)$#',$route,$m) && $method==='DELETE') {
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);$task=$s->fetch();if(!$task)respond(404,['error'=>'Task not found']);
    project_write_access((int)$task['project_id'],$u);log_activity((int)$task['project_id'],$id,(int)$u['id'],'task_deleted','Task deleted: '.$task['title']);
    $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);respond(200,['ok'=>true]);
}
if (preg_match('#^/projects/(\d+)/approvals$#',$route,$m) && $method==='POST') {
    $pid=(int)$m[1];project_write_access($pid,$u);$title=text_field($body['title']??'',160,'Approval title',true);$approverId=isset($body['approver_id'])?(int)$body['approver_id']:null;if($approverId!==null){$check=$pdo->prepare('SELECT 1 FROM users u LEFT JOIN project_members pm ON pm.user_id=u.id AND pm.project_id=? WHERE u.id=? AND u.is_active=1 AND (u.role=\'admin\' OR (pm.user_id IS NOT NULL AND pm.member_role IN (\'manager\',\'contributor\')))');$check->execute([$pid,$approverId]);if(!$check->fetchColumn())respond(422,['error'=>'Approver must be an active member of this project or an admin']);}$details=text_field($body['details']??'',5000,'Approval details');$s=$pdo->prepare('INSERT INTO approval_requests(project_id,requested_by,approver_id,title,details) VALUES(?,?,?,?,?)');$s->execute([$pid,$u['id'],$approverId,$title,$details]);$id=(int)$pdo->lastInsertId();log_activity($pid,null,(int)$u['id'],'approval_requested','Approval requested: '.$title);respond(201,['id'=>$id]);
}
if (preg_match('#^/approvals/(\d+)$#',$route,$m) && $method==='PATCH') {
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM approval_requests WHERE id=?');$s->execute([$id]);$a=$s->fetch();if(!$a)respond(404,['error'=>'Approval not found']);project_write_access((int)$a['project_id'],$u);if($u['role']!=='admin' && (int)$a['approver_id']!==(int)$u['id'])respond(403,['error'=>'Only an admin or assigned approver can decide']);$status=(string)($body['status']??'');if(!in_array($status,['approved','rejected','cancelled'],true))respond(422,['error'=>'Invalid approval decision']);$pdo->prepare('UPDATE approval_requests SET status=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$id]);log_activity((int)$a['project_id'],null,(int)$u['id'],'approval_decided','Approval '.$status.': '.$a['title']);respond(200,['ok'=>true]);
}
respond(404,['error'=>'API route not found']);
