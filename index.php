<?php
declare(strict_types=1);

const STORAGE = __DIR__ . '/storage';
const DB_FILE = STORAGE . '/auth.sqlite';
const TOKEN_LIFETIME = 36000;

if (!is_dir(STORAGE) && !mkdir(STORAGE, 0750, true) && !is_dir(STORAGE)) {
    http_response_code(500);
    exit('Storage konnte nicht erstellt werden.');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('cag');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000');
    return $db;
}

function schema(): void
{
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS users (
 id TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT,
 admin INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
 must_password INTEGER NOT NULL DEFAULT 0, mfa_required INTEGER NOT NULL DEFAULT 0,
 totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, last_counter INTEGER,
 source TEXT NOT NULL DEFAULT 'local', ldap_dn TEXT, icon TEXT
);
CREATE TABLE IF NOT EXISTS tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE);
CREATE TABLE IF NOT EXISTS groups (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT REFERENCES tenants(id) ON DELETE SET NULL, UNIQUE(name,tenant_id));
CREATE TABLE IF NOT EXISTS roles (id TEXT PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE);
CREATE TABLE IF NOT EXISTS systems (id TEXT PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE, url TEXT NOT NULL, internal INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS memberships (user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE, group_id TEXT NOT NULL REFERENCES groups(id) ON DELETE CASCADE, PRIMARY KEY(user_id,group_id));
CREATE TABLE IF NOT EXISTS assignments (
 id TEXT PRIMARY KEY, user_id TEXT REFERENCES users(id) ON DELETE CASCADE,
 group_id TEXT REFERENCES groups(id) ON DELETE CASCADE,
 tenant_id TEXT REFERENCES tenants(id) ON DELETE CASCADE,
 system_id TEXT NOT NULL REFERENCES systems(id) ON DELETE CASCADE,
 role_id TEXT REFERENCES roles(id) ON DELETE CASCADE,
 CHECK(user_id IS NOT NULL OR group_id IS NOT NULL)
);
CREATE TABLE IF NOT EXISTS directories (
 id TEXT PRIMARY KEY, provider TEXT NOT NULL, name TEXT NOT NULL, uri TEXT NOT NULL,
 base_dn TEXT NOT NULL, bind_dn TEXT, bind_secret TEXT, user_filter TEXT NOT NULL,
 start_tls INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS token_keys (kid TEXT PRIMARY KEY, public_key TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL);
SQL);
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(12)); }
function one(string $sql, array $params = []): ?array { $s = db()->prepare($sql); $s->execute($params); return $s->fetch() ?: null; }
function all(string $sql, array $params = []): array { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function run(string $sql, array $params = []): void { $s = db()->prepare($sql); $s->execute($params); }
function redirect(string $url): never { header('Location: ' . $url, true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function checkCsrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sitzung abgelaufen.'); }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function setting(string $key, string $default = ''): string { return one('SELECT value FROM settings WHERE key=?', [$key])['value'] ?? $default; }
function setSetting(string $key, string $value): void { run('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,$value]); }
function currentUser(): ?array { return empty($_SESSION['uid']) ? null : one('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['uid']]); }
function requireUser(): array { $u = currentUser(); if (!$u) redirect('?'); return $u; }
function requireAdmin(): array { $u = requireUser(); if (!$u['admin']) throw new RuntimeException('Keine Administratorrechte.'); return $u; }
function passwordHash(string $password): string { return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT); }
function audit(string $action): void { run('INSERT INTO audit(created_at,actor,action) VALUES(?,?,?)', [time(), $_SESSION['uid'] ?? 'system', $action]); }

function base32Encode(string $raw): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    foreach (str_split($raw) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits,5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk,5,'0'))];
    return $out;
}
function base32Decode(string $secret): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    foreach (str_split($secret) as $char) $bits .= str_pad(decbin((int)strpos($alphabet,$char)),5,'0',STR_PAD_LEFT);
    foreach (str_split($bits,8) as $chunk) if (strlen($chunk)===8) $out .= chr(bindec($chunk));
    return $out;
}
function totp(string $secret, int $counter): string
{
    $hash=hash_hmac('sha1',pack('N*',0).pack('N*',$counter),base32Decode($secret),true);
    $offset=ord($hash[19])&15;
    $number=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255);
    return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
}
function verifyTotp(array $user, string $code): bool
{
    $counter=intdiv(time(),30);
    foreach ([-1,0,1] as $window) {
        $value=$counter+$window;
        if (hash_equals(totp((string)$user['totp_secret'],$value),$code) && ($user['last_counter']===null || $value>(int)$user['last_counter'])) {
            run('UPDATE users SET last_counter=? WHERE id=?',[$value,$user['id']]); return true;
        }
    }
    return false;
}
function qrData(string $value): string
{
    $library='/usr/share/phpqrcode/qrlib.php';
    if (!is_file($library)) return '';
    require_once $library;
    ob_start(); QRcode::png($value,null,QR_ECLEVEL_M,6,2); $png=ob_get_clean();
    return is_string($png) && $png!=='' ? 'data:image/png;base64,'.base64_encode($png) : '';
}
function secretKey(): string
{
    $file=STORAGE.'/.key';
    if (!is_file($file)) { file_put_contents($file,base64_encode(random_bytes(32)),LOCK_EX); chmod($file,0600); }
    return base64_decode((string)file_get_contents($file),true);
}
function encryptSecret(string $plain): string { $nonce=random_bytes(24); return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,secretKey())); }
function decryptSecret(string $value): string
{
    if ($value==='') return '';
    $raw=base64_decode($value,true); if ($raw===false || strlen($raw)<=24) return '';
    $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),secretKey());
    return $plain===false ? '' : $plain;
}
function ldapLogin(string $username, string $password): ?array
{
    if (!extension_loaded('ldap') || $password==='') return null;
    foreach (all('SELECT * FROM directories WHERE active=1') as $source) {
        $ldap=ldap_connect($source['uri']); if (!$ldap) continue;
        ldap_set_option($ldap,LDAP_OPT_PROTOCOL_VERSION,3); ldap_set_option($ldap,LDAP_OPT_REFERRALS,0); ldap_set_option($ldap,LDAP_OPT_NETWORK_TIMEOUT,5);
        if ($source['start_tls'] && !ldap_start_tls($ldap)) continue;
        if (!ldap_bind($ldap,$source['bind_dn'] ?: null,decryptSecret($source['bind_secret']) ?: null)) continue;
        $filter=str_replace('{username}',ldap_escape($username,'',LDAP_ESCAPE_FILTER),$source['user_filter']);
        $search=ldap_search($ldap,$source['base_dn'],$filter,['dn','memberOf']); if (!$search) continue;
        $entries=ldap_get_entries($ldap,$search); if (($entries['count']??0)!==1 || !ldap_bind($ldap,$entries[0]['dn'],$password)) continue;
        $groups=[]; if (isset($entries[0]['memberof'])) for ($i=0;$i<$entries[0]['memberof']['count'];$i++) if (preg_match('/^CN=([^,]+)/i',$entries[0]['memberof'][$i],$m)) $groups[]=$m[1];
        ldap_unbind($ldap); return ['dn'=>$entries[0]['dn'],'groups'=>$groups];
    }
    return null;
}
function provisionDirectoryUser(string $username, array $info): array
{
    $user=one('SELECT * FROM users WHERE username=? COLLATE NOCASE',[$username]);
    if (!$user) { $uid=id('usr'); run("INSERT INTO users(id,username,source,ldap_dn) VALUES(?,?,'ldap',?)",[$uid,$username,$info['dn']]); $user=one('SELECT * FROM users WHERE id=?',[$uid]); }
    else run("UPDATE users SET source='ldap',ldap_dn=?,active=1 WHERE id=?",[$info['dn'],$user['id']]);
    foreach ($info['groups'] as $name) {
        $group=one('SELECT * FROM groups WHERE name=? AND tenant_id IS NULL',[$name]);
        if (!$group) { $gid=id('grp'); run('INSERT INTO groups(id,name,tenant_id) VALUES(?,?,NULL)',[$gid,$name]); } else $gid=$group['id'];
        run('INSERT OR IGNORE INTO memberships(user_id,group_id) VALUES(?,?)',[$user['id'],$gid]);
    }
    return one('SELECT * FROM users WHERE id=?',[$user['id']]);
}

schema();
$error='';

if (isset($_GET['key'])) {
    $key=one('SELECT * FROM token_keys WHERE kid=? AND expires_at>?',[(string)$_GET['key'],time()]);
    if (!$key) { http_response_code(404); exit; }
    header('Content-Type: application/x-pem-file'); echo $key['public_key']; exit;
}
if (isset($_GET['jwks'])) {
    $keys=[];
    foreach (all('SELECT * FROM token_keys WHERE expires_at>?',[time()]) as $row) {
        $detail=openssl_pkey_get_details(openssl_pkey_get_public($row['public_key']));
        $b64=fn(string $v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
        $keys[]=['kty'=>'RSA','alg'=>'RS256','kid'=>$row['kid'],'n'=>$b64($detail['rsa']['n']),'e'=>$b64($detail['rsa']['e']),'created_at'=>$row['created_at'],'expires_at'=>$row['expires_at']];
    }
    header('Content-Type: application/json'); echo json_encode(['keys'=>$keys]); exit;
}

try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        checkCsrf(); $action=(string)($_POST['action']??'');
        if ($action==='setup' && !one('SELECT id FROM users LIMIT 1')) {
            if (strlen((string)$_POST['password'])<10) throw new RuntimeException('Passwort mindestens 10 Zeichen.');
            db()->beginTransaction(); $uid=id('usr'); $sid=id('sys'); $rid=id('role');
            run('INSERT INTO users(id,username,password_hash,admin) VALUES(?,?,?,1)',[$uid,trim($_POST['username']),passwordHash($_POST['password'])]);
            run('INSERT INTO systems(id,name,url,internal) VALUES(?,?,?,1)',[$sid,'CAG',rtrim($_POST['url'],'/').'/']);
            run('INSERT INTO roles(id,name) VALUES(?,?)',[$rid,'admin']);
            run('INSERT INTO assignments(id,user_id,system_id,role_id) VALUES(?,?,?,?)',[id('asg'),$uid,$sid,$rid]);
            setSetting('base_url',rtrim($_POST['url'],'/')); db()->commit(); redirect('?');
        }
        if ($action==='login') {
            $name=trim($_POST['username']); $password=(string)$_POST['password'];
            $user=one('SELECT * FROM users WHERE username=? COLLATE NOCASE AND active=1',[$name]);
            $ok=$user && $user['source']==='local' && password_verify($password,$user['password_hash']??'');
            if (!$ok && ($directory=ldapLogin($name,$password))) { $user=provisionDirectoryUser($name,$directory); $ok=true; }
            if (!$ok) throw new RuntimeException('Anmeldung fehlgeschlagen.');
            $_SESSION['pending']=$user['id']; $_SESSION['sys']=$_POST['sys']??'';
            if ($user['mfa_required']&&!$user['totp_enabled']) { $_SESSION['secret']=base32Encode(random_bytes(20)); redirect('?step=mfa-setup'); }
            if ($user['totp_enabled']) redirect('?step=mfa');
            $_SESSION['uid']=$user['id']; unset($_SESSION['pending']); redirect('?after=1');
        }
        if ($action==='mfa') {
            $user=one('SELECT * FROM users WHERE id=?',[$_SESSION['pending']??'']);
            if (!$user || !verifyTotp($user,(string)$_POST['code'])) throw new RuntimeException('Code ungültig.');
            $_SESSION['uid']=$user['id']; unset($_SESSION['pending']); redirect('?after=1');
        }
        if ($action==='mfa-setup') {
            $user=one('SELECT * FROM users WHERE id=?',[$_SESSION['pending']??'']); $secret=$_SESSION['secret']??'';
            if (!$user || !hash_equals(totp($secret,intdiv(time(),30)),(string)$_POST['code'])) throw new RuntimeException('Code ungültig.');
            run('UPDATE users SET totp_secret=?,totp_enabled=1 WHERE id=?',[$secret,$user['id']]);
            $_SESSION['uid']=$user['id']; unset($_SESSION['pending'],$_SESSION['secret']); redirect('?after=1');
        }
        if ($action==='logout') { session_destroy(); redirect('?'); }
        $user=requireUser();
        if ($action==='password') {
            if ($user['source']!=='local' || !password_verify($_POST['old'],$user['password_hash']??'')) throw new RuntimeException('Aktuelles Passwort falsch.');
            if (strlen((string)$_POST['new'])<10) throw new RuntimeException('Passwort mindestens 10 Zeichen.');
            run('UPDATE users SET password_hash=?,must_password=0 WHERE id=?',[passwordHash($_POST['new']),$user['id']]); flash('Passwort geändert.'); redirect('?mode=user');
        }
        requireAdmin();
        if ($action==='save_user') {
            $uid=$_POST['id']??''; $admin=isset($_POST['admin'])?1:0;
            if ($uid) {
                $old=one('SELECT * FROM users WHERE id=?',[$uid]);
                if ($old['admin']&&!$admin&&(int)one('SELECT COUNT(*) c FROM users WHERE admin=1 AND active=1')['c']<=1) throw new RuntimeException('Der letzte Admin bleibt Admin.');
                run('UPDATE users SET username=?,admin=?,mfa_required=?,must_password=? WHERE id=?',[trim($_POST['username']),$admin,isset($_POST['mfa']),isset($_POST['must']),$uid]);
                if (($_POST['password']??'')!=='') run("UPDATE users SET password_hash=?,source='local' WHERE id=?",[passwordHash($_POST['password']),$uid]);
            } else {
                if (strlen((string)$_POST['password'])<10) throw new RuntimeException('Passwort mindestens 10 Zeichen.');
                run('INSERT INTO users(id,username,password_hash,admin,mfa_required,must_password) VALUES(?,?,?,?,?,?)',[id('usr'),trim($_POST['username']),passwordHash($_POST['password']),$admin,isset($_POST['mfa']),isset($_POST['must'])]);
            }
            flash('Benutzer gespeichert.'); redirect('?mode=admin');
        }
        if ($action==='save_object') {
            $type=(string)$_POST['type']; $name=trim($_POST['name']);
            if ($type==='tenant') run('INSERT INTO tenants(id,name) VALUES(?,?)',[id('ten'),$name]);
            elseif ($type==='group') run('INSERT INTO groups(id,name,tenant_id) VALUES(?,?,?)',[id('grp'),$name,$_POST['tenant_id']?:null]);
            elseif ($type==='role') run('INSERT INTO roles(id,name) VALUES(?,?)',[id('rol'),$name]);
            elseif ($type==='system') run('INSERT INTO systems(id,name,url) VALUES(?,?,?)',[id('sys'),$name,$_POST['url']]);
            else throw new RuntimeException('Ungültiger Typ.');
            flash('Eintrag gespeichert.'); redirect('?mode=admin');
        }
        if ($action==='membership') { run('INSERT OR IGNORE INTO memberships(user_id,group_id) VALUES(?,?)',[$_POST['user_id'],$_POST['group_id']]); flash('Mitgliedschaft gespeichert.'); redirect('?mode=admin'); }
        if ($action==='assignment') {
            $userId=$_POST['user_id']?:null; $groupId=$_POST['group_id']?:null;
            if (!$userId&&!$groupId) throw new RuntimeException('Benutzer oder Gruppe wählen.');
            run('INSERT INTO assignments(id,user_id,group_id,tenant_id,system_id,role_id) VALUES(?,?,?,?,?,?)',[id('asg'),$userId,$groupId,$_POST['tenant_id']?:null,$_POST['system_id'],$_POST['role_id']?:null]);
            flash('Berechtigung gespeichert.'); redirect('?mode=admin&tenant='.urlencode($_POST['tenant_id']??'').'&group='.urlencode($_POST['group_id']??''));
        }
        if ($action==='directory') {
            $secret=($_POST['bind_password']??'')!==''?encryptSecret($_POST['bind_password']):'';
            run('INSERT INTO directories(id,provider,name,uri,base_dn,bind_dn,bind_secret,user_filter,start_tls,active) VALUES(?,?,?,?,?,?,?,?,?,1)',[id('dir'),$_POST['provider'],trim($_POST['name']),trim($_POST['uri']),trim($_POST['base_dn']),trim($_POST['bind_dn']),$secret,trim($_POST['filter']),isset($_POST['tls'])]);
            flash('Verzeichnis gespeichert.'); redirect('?mode=admin');
        }
    }
} catch (Throwable $exception) {
    if (db()->inTransaction()) db()->rollBack();
    $error=$exception->getMessage();
}

if (isset($_GET['after'])) {
    $user=requireUser();
    if ($user['must_password']) redirect('?mode=user');
    $systemName=$_SESSION['sys']??''; unset($_SESSION['sys']);
    if ($systemName!=='') {
        $system=one('SELECT * FROM systems WHERE name=? COLLATE NOCASE AND active=1 AND internal=0',[$systemName]);
        if (!$system) throw new RuntimeException('System nicht gefunden.');
        $groups=all('SELECT g.id,g.name FROM groups g JOIN memberships m ON m.group_id=g.id WHERE m.user_id=?',[$user['id']]);
        $ids=array_column($groups,'id'); $params=[$system['id'],$user['id']];
        $sql="SELECT DISTINCT r.name role,t.name tenant FROM assignments a LEFT JOIN roles r ON r.id=a.role_id LEFT JOIN tenants t ON t.id=a.tenant_id WHERE a.system_id=? AND (a.user_id=?";
        if ($ids) { $sql.=' OR a.group_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=array_merge($params,$ids); }
        $sql.=')'; $rows=all($sql,$params); $at=time(); $exp=$at+TOKEN_LIFETIME; $kid='tok_'.bin2hex(random_bytes(16));
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($key,$private); $detail=openssl_pkey_get_details($key);
        run('DELETE FROM token_keys WHERE expires_at<=?',[time()]); run('INSERT INTO token_keys VALUES(?,?,?,?)',[$kid,$detail['key'],$at,$exp]);
        $claims=['iss'=>setting('base_url'),'sub'=>$user['id'],'aud'=>$system['name'],'iat'=>$at,'created_at'=>$at,'exp'=>$exp,'expires_at'=>$exp,'system'=>$system['name'],'username'=>$user['username'],'groups'=>array_column($groups,'name'),'roles'=>array_values(array_unique(array_filter(array_column($rows,'role')))),'tenants'=>array_values(array_unique(array_filter(array_column($rows,'tenant'))))];
        $b64=fn(string $v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'='); $head=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>$kid])); $body=$b64(json_encode($claims)); openssl_sign($head.'.'.$body,$signature,$private,OPENSSL_ALGO_SHA256); $token=$head.'.'.$body.'.'.$b64($signature);
        redirect($system['url'].(str_contains($system['url'],'?')?'&':'?').'token='.rawurlencode($token));
    }
    redirect('?mode='.($user['admin']?'admin':'user'));
}

$first=!one('SELECT id FROM users LIMIT 1');
$user=currentUser();
$step=$_GET['step']??'';
$requestedSystem=$_GET['sys']??'';

function pageStart(string $title): void
{
    global $error;
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>';
    echo <<<'CSS'
:root{color-scheme:dark;--bg:#0a0d12;--panel:#121720;--panel2:#171e28;--line:#293340;--text:#eef2f7;--muted:#919cab;--accent:#69adfa;--danger:#ef929d;--success:#68d4a0}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}button,input,select{font:inherit}button{min-height:38px;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);cursor:pointer}button:hover{border-color:#465568}.primary{background:var(--accent);border-color:var(--accent);color:#07111c;font-weight:700}.danger{color:var(--danger)}input,select{width:100%;min-height:40px;margin-top:5px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:#0d1219;color:var(--text)}input[type=checkbox]{width:auto;min-height:0;margin:0 7px 0 0}label{display:block;color:var(--muted)}.muted{color:var(--muted)}.login{min-height:100vh;display:grid;place-items:center;padding:20px}.login-card{width:min(400px,100%);padding:24px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.login-card h1{margin:0 0 18px}.login-card label{margin:12px 0}.login-card button{width:100%}.error{padding:10px 12px;margin-bottom:12px;border:1px solid #6c3940;border-radius:8px;background:#28161a;color:#ffc0c7}.app{width:min(1240px,100%);margin:auto;padding:0 22px 30px}.top{height:72px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);margin-bottom:22px}.brand{font-size:17px;font-weight:750}.top-actions{display:flex;align-items:center;gap:9px}.switch{display:flex;padding:3px;border:1px solid var(--line);border-radius:9px;background:#0d1219}.switch a{padding:7px 11px;border-radius:6px;color:var(--muted);text-decoration:none}.switch a.active{background:var(--panel2);color:var(--text)}.card{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:16px}.toolbar{display:flex;align-items:end;gap:10px;margin-bottom:14px}.toolbar label{min-width:210px}.toolbar select{margin:0}.spacer{flex:1}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.panel-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.panel-title h2{margin:0;font-size:16px}.list{display:grid;gap:7px}.row{display:grid;grid-template-columns:minmax(120px,1fr) minmax(110px,1fr) minmax(90px,.75fr) minmax(90px,.75fr);gap:9px;align-items:center;padding:10px;border:1px solid var(--line);border-radius:8px;background:#0d131b}.row small{display:block;color:var(--muted)}.chip{display:inline-flex;padding:4px 8px;border:1px solid var(--line);border-radius:999px;font-size:11px}.empty{padding:18px;text-align:center;color:var(--muted)}.entity-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.entity{padding:12px;border:1px solid var(--line);border-radius:8px;background:#0d131b}.entity strong,.entity small{display:block}.entity small{color:var(--muted);margin-top:3px}.flash{margin-bottom:14px}.user-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}dialog{width:min(560px,94vw);padding:0;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text)}dialog::backdrop{background:#000b}.modal-head{display:flex;justify-content:space-between;align-items:center;padding:17px;border-bottom:1px solid var(--line)}.modal-head h2{margin:0}.modal-body{display:grid;gap:11px;padding:17px}.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.modal-actions{display:flex;justify-content:flex-end;gap:8px}.checks{display:grid;gap:8px;padding:11px;border:1px solid var(--line);border-radius:8px;background:#0d131b}.checks label{color:var(--text)}.tabs{display:flex;gap:6px}.tabs button.active{background:var(--accent);color:#07111c}.directory-list{display:grid;gap:8px}.directory{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--line);border-radius:8px}.directory span{flex:1}.copy-note{color:var(--success);font-size:12px}@media(max-width:800px){.grid{grid-template-columns:1fr}.entity-grid{grid-template-columns:1fr 1fr}.toolbar{align-items:stretch;flex-direction:column}.toolbar label{min-width:0;width:100%}.spacer{display:none}.row{grid-template-columns:1fr 1fr}.top{height:auto;padding:14px 0;gap:12px;align-items:flex-start;flex-direction:column}.top-actions{width:100%;flex-wrap:wrap}.modal-grid{grid-template-columns:1fr}}@media(max-width:520px){.app{padding:0 12px 20px}.entity-grid{grid-template-columns:1fr}.switch{width:100%}.switch a{flex:1;text-align:center}}
CSS;
    echo '</style></head><body>';
    if ($error!=='') echo '<div class="error">'.h($error).'</div>';
}

if (!$user) {
    pageStart('Central Auth');
    echo '<main class="login"><section class="login-card"><h1>'.($first?'Ersteinrichtung':'Anmelden').'</h1>';
    if ($step==='mfa-setup') {
        $pending=one('SELECT * FROM users WHERE id=?',[$_SESSION['pending']??'']); $secret=$_SESSION['secret']??'';
        $uri='otpauth://totp/'.rawurlencode('CentralAuth:'.$pending['username']).'?secret='.$secret.'&issuer=CentralAuth'; $qr=qrData($uri);
        if ($qr) echo '<img src="'.h($qr).'" alt="TOTP QR-Code" style="display:block;width:220px;margin:0 auto 12px;background:white;padding:8px">';
        echo '<label>Schlüssel<input id="totp-secret" readonly value="'.h($secret).'"></label><button type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'totp-secret\').value)">Kopieren</button><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="mfa-setup"><label>Code<input name="code" pattern="[0-9]{6}" required></label><button class="primary">Bestätigen</button></form>';
    } elseif ($step==='mfa') {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="mfa"><label>Code<input name="code" pattern="[0-9]{6}" autofocus required></label><button class="primary">Anmelden</button></form>';
    } elseif ($first) {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="setup"><label>Benutzername<input name="username" required></label><label>Passwort<input type="password" name="password" required></label><label>Adresse<input type="url" name="url" value="http://localhost/" required></label><button class="primary">Anlegen</button></form>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="login"><input type="hidden" name="sys" value="'.h($requestedSystem).'"><label>Benutzername<input name="username" autofocus required></label><label>Passwort<input type="password" name="password" required></label><button class="primary">Anmelden</button></form>';
    }
    echo '</section></main></body></html>'; exit;
}

$mode=($_GET['mode']??($user['admin']?'admin':'user'))==='admin'&&$user['admin']?'admin':'user';
if ($user['must_password']) $mode='user';
$tenants=all('SELECT * FROM tenants ORDER BY name');
$groups=all('SELECT g.*,t.name tenant_name FROM groups g LEFT JOIN tenants t ON t.id=g.tenant_id ORDER BY t.name,g.name');
$roles=all('SELECT * FROM roles ORDER BY name');
$systems=all('SELECT * FROM systems WHERE internal=0 ORDER BY name');
$users=all('SELECT * FROM users ORDER BY username');

pageStart('Central Auth');
echo '<main class="app"><header class="top"><div class="brand">Central Auth</div><div class="top-actions">';
if ($user['admin']) echo '<nav class="switch"><a href="?mode=user" class="'.($mode==='user'?'active':'').'">Benutzer</a><a href="?mode=admin" class="'.($mode==='admin'?'active':'').'">Admin</a></nav>';
echo '<strong>'.h($user['username']).'</strong><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="logout"><button>Abmelden</button></form></div></header>';
if ($message=$_SESSION['flash']??'') { echo '<div class="card flash">'.h($message).'</div>'; unset($_SESSION['flash']); }

if ($mode==='user') {
    echo '<div class="user-header"><h1>Konto</h1></div><div class="grid">';
    if ($user['source']==='local') echo '<form method="post" class="card modal-body"><div class="panel-title"><h2>'.($user['must_password']?'Passwort ändern':'Passwort').'</h2></div><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="password"><label>Aktuelles Passwort<input type="password" name="old" required></label><label>Neues Passwort<input type="password" name="new" minlength="10" required></label><button class="primary">Speichern</button></form>';
    echo '<section class="card"><div class="panel-title"><h2>Zugriffe</h2></div><div class="list">';
    $myAssignments=all("SELECT DISTINCT s.name system_name,r.name role_name,t.name tenant_name,g.name group_name FROM assignments a JOIN systems s ON s.id=a.system_id LEFT JOIN roles r ON r.id=a.role_id LEFT JOIN tenants t ON t.id=a.tenant_id LEFT JOIN groups g ON g.id=a.group_id LEFT JOIN memberships m ON m.group_id=a.group_id WHERE a.user_id=? OR m.user_id=?",[$user['id'],$user['id']]);
    if (!$myAssignments) echo '<div class="empty">Keine Freigaben</div>';
    foreach ($myAssignments as $row) echo '<div class="row"><strong>'.h($row['system_name']).'</strong><span>'.h($row['role_name']?:'–').'</span><span>'.h($row['tenant_name']?:'Kein Tenant').'</span><span>'.h($row['group_name']?:'Direkt').'</span></div>';
    echo '</div></section></div>';
} else {
    $tenantFilter=(string)($_GET['tenant']??''); $groupFilter=(string)($_GET['group']??'');
    $availableGroups=array_values(array_filter($groups,fn($g)=>$tenantFilter==='' || $g['tenant_id']===$tenantFilter || $g['tenant_id']===null));
    $where=['s.internal=0']; $params=[];
    if ($tenantFilter==='none') $where[]='a.tenant_id IS NULL'; elseif ($tenantFilter!=='') { $where[]='a.tenant_id=?'; $params[]=$tenantFilter; }
    if ($groupFilter==='none') $where[]='a.group_id IS NULL'; elseif ($groupFilter!=='') { $where[]='a.group_id=?'; $params[]=$groupFilter; }
    $assignments=all("SELECT a.*,u.username,g.name group_name,s.name system_name,r.name role_name,t.name tenant_name FROM assignments a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN groups g ON g.id=a.group_id JOIN systems s ON s.id=a.system_id LEFT JOIN roles r ON r.id=a.role_id LEFT JOIN tenants t ON t.id=a.tenant_id WHERE ".implode(' AND ',$where).' ORDER BY t.name,g.name,u.username,s.name',$params);
    echo '<div class="toolbar"><label>Tenant<select onchange="applyFilter()" id="tenant-filter"><option value="">Alle</option><option value="none" '.($tenantFilter==='none'?'selected':'').'>Kein Tenant</option>';
    foreach($tenants as $tenant) echo '<option value="'.$tenant['id'].'" '.($tenantFilter===$tenant['id']?'selected':'').'>'.h($tenant['name']).'</option>';
    echo '</select></label><label>Gruppe<select onchange="applyFilter()" id="group-filter"><option value="">Alle</option><option value="none" '.($groupFilter==='none'?'selected':'').'>Keine Gruppe</option>';
    foreach($availableGroups as $group) echo '<option value="'.$group['id'].'" '.($groupFilter===$group['id']?'selected':'').'>'.h($group['name']).'</option>';
    echo '</select></label><div class="spacer"></div><button onclick="openModal(\'directory-modal\')">AD / LDAP</button><button onclick="openModal(\'object-modal\')">Stammdaten</button><button class="primary" onclick="openModal(\'assignment-modal\')">+ Zuweisung</button></div>';
    echo '<section class="card"><div class="panel-title"><h2>Zuweisungen</h2><span class="chip">'.count($assignments).'</span></div><div class="list">';
    if (!$assignments) echo '<div class="empty">Keine Zuweisungen</div>';
    foreach($assignments as $row) echo '<div class="row"><div><strong>'.h($row['username']?:$row['group_name']).'</strong><small>'.($row['username']?'Benutzer':'Gruppe').'</small></div><strong>'.h($row['system_name']).'</strong><span class="chip">'.h($row['role_name']?:'–').'</span><span>'.h($row['tenant_name']?:'Kein Tenant').'</span></div>';
    echo '</div></section><div class="entity-grid"><div class="entity"><strong>Benutzer</strong><small>'.count($users).'</small></div><div class="entity"><strong>Gruppen</strong><small>'.count($groups).'</small></div><div class="entity"><strong>Systeme</strong><small>'.count($systems).'</small></div><div class="entity"><strong>Rollen</strong><small>'.count($roles).'</small></div></div>';
}

function options(array $items,string $value='id',string $label='name'): string { $html=''; foreach($items as $item)$html.='<option value="'.h($item[$value]).'">'.h($item[$label]).'</option>'; return $html; }
?>

<dialog id="assignment-modal">
  <div class="modal-head"><h2>Zuweisung</h2><button onclick="closeModal('assignment-modal')">×</button></div>
  <form method="post" class="modal-body">
    <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="assignment">
    <div class="modal-grid">
      <label>Tenant<select name="tenant_id" id="assign-tenant" onchange="filterAssignmentGroups()"><option value="">Kein Tenant</option><?=options($tenants)?></select></label>
      <label>System<select name="system_id" required><?=options($systems)?></select></label>
      <label>Gruppe<select name="group_id" id="assign-group"><option value="">Keine Gruppe</option><?php foreach($groups as $group):?><option value="<?=h($group['id'])?>" data-tenant="<?=h($group['tenant_id']??'')?>"><?=h($group['name'])?></option><?php endforeach;?></select></label>
      <label>Rolle<select name="role_id"><option value="">Keine Rolle</option><?=options($roles)?></select></label>
      <label>Benutzer<select name="user_id"><option value="">Kein Benutzer</option><?php foreach($users as $item):?><option value="<?=h($item['id'])?>"><?=h($item['username'])?></option><?php endforeach;?></select></label>
    </div>
    <div class="modal-actions"><button type="button" onclick="closeModal('assignment-modal')">Abbrechen</button><button class="primary">Speichern</button></div>
  </form>
</dialog>

<dialog id="object-modal">
  <div class="modal-head"><h2>Stammdaten</h2><button onclick="closeModal('object-modal')">×</button></div>
  <div class="modal-body"><div class="tabs"><button class="active" onclick="selectObjectType('tenant',this)">Tenant</button><button onclick="selectObjectType('system',this)">System</button><button onclick="selectObjectType('group',this)">Gruppe</button><button onclick="selectObjectType('role',this)">Rolle</button><button onclick="selectObjectType('user',this)">User</button></div>
  <form method="post" id="object-form" class="modal-body" style="padding:0"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_object"><input type="hidden" name="type" value="tenant"><label>Name<input name="name" required></label><label id="object-url" hidden>URL<input type="url" name="url"></label><label id="object-tenant" hidden>Tenant<select name="tenant_id"><option value="">Kein Tenant</option><?=options($tenants)?></select></label><button class="primary">Speichern</button></form>
  <button onclick="closeModal('object-modal')">Schließen</button></div>
</dialog>

<dialog id="directory-modal">
  <div class="modal-head"><h2>AD / LDAP</h2><button onclick="closeModal('directory-modal')">×</button></div>
  <form method="post" class="modal-body"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="directory"><label>Typ<select name="provider"><option value="ad">Active Directory</option><option value="ldap">LDAP</option></select></label><label>Name<input name="name" required></label><label>URI<input name="uri" placeholder="ldap://server:389" required></label><label>Base DN<input name="base_dn" required></label><label>Bind DN<input name="bind_dn"></label><label>Bind-Passwort<input type="password" name="bind_password"></label><label>Filter<input name="filter" value="(sAMAccountName={username})" required></label><label><input type="checkbox" name="tls" checked> StartTLS</label><div class="modal-actions"><button type="button" onclick="closeModal('directory-modal')">Abbrechen</button><button class="primary">Speichern</button></div></form>
</dialog>

<dialog id="user-modal">
  <div class="modal-head"><h2>User</h2><button onclick="closeModal('user-modal')">×</button></div>
  <form method="post" class="modal-body"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_user"><input type="hidden" name="id"><label>Benutzername<input name="username" required></label><label>Passwort<input name="password" type="password"></label><div class="checks"><label><input type="checkbox" name="admin"> Admin</label><label><input type="checkbox" name="mfa"> TOTP-Pflicht</label><label><input type="checkbox" name="must"> Passwortwechsel</label></div><button type="button" onclick="generatePassword(this)">Passwort erzeugen & kopieren</button><div class="copy-note"></div><div class="modal-actions"><button type="button" onclick="closeModal('user-modal')">Abbrechen</button><button class="primary">Speichern</button></div></form>
</dialog>

<script>
function openModal(id){document.getElementById(id).showModal()}
function closeModal(id){document.getElementById(id).close()}
function applyFilter(){const tenant=document.getElementById('tenant-filter').value;const group=document.getElementById('group-filter').value;location.href='?mode=admin&tenant='+encodeURIComponent(tenant)+'&group='+encodeURIComponent(group)}
function filterAssignmentGroups(){const tenant=document.getElementById('assign-tenant').value;document.querySelectorAll('#assign-group option[data-tenant]').forEach(option=>{option.hidden=option.dataset.tenant!==''&&option.dataset.tenant!==tenant});document.getElementById('assign-group').value=''}
function selectObjectType(type,button){document.querySelectorAll('#object-modal .tabs button').forEach(x=>x.classList.remove('active'));button.classList.add('active');if(type==='user'){closeModal('object-modal');openModal('user-modal');return}const form=document.getElementById('object-form');form.type.value=type;document.getElementById('object-url').hidden=type!=='system';document.getElementById('object-tenant').hidden=type!=='group'}
async function generatePassword(button){const chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@$%+-_';const values=new Uint32Array(16);crypto.getRandomValues(values);const password=Array.from(values,x=>chars[x%chars.length]).join('');const input=button.form.password;input.type='text';input.value=password;try{await navigator.clipboard.writeText(password);button.form.querySelector('.copy-note').textContent='Kopiert'}catch{button.form.querySelector('.copy-note').textContent='Erzeugt'}}
</script>
</main></body></html>
