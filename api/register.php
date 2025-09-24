<?php
require_once __DIR__.'/../auth.php';
// Allow both admin and operator to issue certificates
require_login();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../rate_limit.php';
rate_limit('register');

// Expect JSON: {cid, v:3, h, date, valid_until, extra_info?, org_code?} – v3 only
$raw = isset($GLOBALS['__TEST_JSON_BODY']) ? $GLOBALS['__TEST_JSON_BODY'] : file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }

if(!function_exists('val_str')){
function val_str($a,$k,$max){
  if (!isset($a[$k])) return null;
  $v = trim((string)$a[$k]);
  if ($v==='') return null;
  if (strlen($v) > $max) $v = substr($v,0,$max);
  return $v;
}
}
$cid = val_str($payload,'cid',64);
$v   = (int)($payload['v'] ?? 3);
$h   = val_str($payload,'h',64);
$extra  = val_str($payload,'extra_info',255);
$date   = val_str($payload,'date',10); // issued_date YYYY-MM-DD
$validUntil = val_str($payload,'valid_until',10); // YYYY-MM-DD or sentinel
$orgCodeProvided = val_str($payload,'org_code',64);
// Optional template selection
$templateId = isset($payload['template_id']) ? (int)$payload['template_id'] : null;
// Load config for sentinel
$cfg = require __DIR__.'/../config.php';
$sentinel = $cfg['infinite_sentinel'] ?? '4000-01-01';

if (!$cid || !$h || strlen($h)!==64 || !ctype_xdigit($h)) {
  http_response_code(422); echo json_encode(['error'=>'invalid_fields']); exit;
}
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { $date=null; }
// v3 only
if ($v !== 3) { http_response_code(422); echo json_encode(['error'=>'unsupported_version']); exit; }
if(!$validUntil){ $validUntil = $sentinel; }
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$validUntil)) { http_response_code(422); echo json_encode(['error'=>'bad_valid_until']); exit; }
// basic logical check: if not sentinel and earlier than issued_date
if($validUntil !== $sentinel && $date && strcmp($validUntil,$date) < 0){ http_response_code(422); echo json_encode(['error'=>'expiry_before_issue']); exit; }

// Determine effective org_id (operators must have one; admins may be global/null → use default org if provided code matches)
$effectiveOrgId = null;
try {
  // Detect tokens.org_id column presence
  static $tokensHasOrg = null;
  if($tokensHasOrg === null){
    try { $chk=$pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'org_id'"); $tokensHasOrg = ($chk && $chk->rowCount()===1); } catch(Throwable $e){ $tokensHasOrg=false; }
  }
  // Load default org code from config
  $defaultOrgCode = $cfg['org_code'] ?? null; // $cfg from config include in header context not present here, reload config
  if(!isset($cfg)) { $cfg = require __DIR__.'/../config.php'; $defaultOrgCode = $cfg['org_code'] ?? null; }
  $sessionOrgId = current_org_id();
  if($sessionOrgId){
    $effectiveOrgId = $sessionOrgId;
  }
  // If admin without org_id but provided org_code, attempt to map
  if(!$effectiveOrgId && $orgCodeProvided){
    $selOrg = $pdo->prepare('SELECT id FROM organizations WHERE code=? AND is_active=1');
    $selOrg->execute([$orgCodeProvided]);
    $rowOrgId = $selOrg->fetchColumn();
    if($rowOrgId){ $effectiveOrgId = (int)$rowOrgId; }
  }
  // If still null and default exists, fallback to default org id for backward compatibility
  if(!$effectiveOrgId && $defaultOrgCode){
    $selDef = $pdo->prepare('SELECT id FROM organizations WHERE code=?');
    $selDef->execute([$defaultOrgCode]);
    $effectiveOrgId = ($selDef->fetchColumn()) ? (int)$selDef->fetchColumn() : null;
  }
  // Validation: if provided org_code does not match resolved org id (when both known) reject
  if($orgCodeProvided && $effectiveOrgId){
    $chkMatch = $pdo->prepare('SELECT 1 FROM organizations WHERE id=? AND code=?');
    $chkMatch->execute([$effectiveOrgId,$orgCodeProvided]);
    if(!$chkMatch->fetch()){
      http_response_code(422); echo json_encode(['error'=>'org_mismatch']); exit;
    }
  }
  // Validate template_id if provided: must exist, belong to effective org (or admin/global rules), and be active
  $tplOrgId = null;
  if($templateId){
    try {
      // Ensure templates table exists
      $chkT = $pdo->query("SHOW TABLES LIKE 'templates'");
      if($chkT && $chkT->fetch()){
        $stTpl = $pdo->prepare('SELECT id, org_id, status FROM templates WHERE id = ?');
        $stTpl->execute([$templateId]);
        $tpl = $stTpl->fetch(PDO::FETCH_ASSOC);
        if(!$tpl){ http_response_code(422); echo json_encode(['error'=>'template_not_found']); exit; }
        $tplOrgId = isset($tpl['org_id']) ? (int)$tpl['org_id'] : null;
        $status = strtolower((string)$tpl['status']);
        if($status !== 'active'){ http_response_code(422); echo json_encode(['error'=>'template_inactive']); exit; }
        // If both effectiveOrgId and tplOrgId are known, they must match
        if($effectiveOrgId && $tplOrgId && $tplOrgId !== $effectiveOrgId){ http_response_code(422); echo json_encode(['error'=>'template_wrong_org']); exit; }
        // If effective org not yet resolved but template has org_id, adopt it
        if(!$effectiveOrgId && $tplOrgId){ $effectiveOrgId = $tplOrgId; }
      } else {
        // No templates table -> cannot accept template_id
        $templateId = null;
      }
    } catch(Throwable $e){ $templateId = null; }
  }

  // Insert token (with org_id and template_id if columns exist)
  if($tokensHasOrg){
    // Detect template_id column
    static $tokensHasTpl = null; if($tokensHasTpl===null){ try { $chk=$pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'template_id'"); $tokensHasTpl = ($chk && $chk->rowCount()===1); } catch(Throwable $e){ $tokensHasTpl=false; } }
    if($tokensHasTpl){
      $st = $pdo->prepare("INSERT INTO tokens (cid, version, org_id, template_id, h, extra_info, issued_date, valid_until) VALUES (?,?,?,?,?,?,?,?)");
      $st->execute([$cid,$v,$effectiveOrgId,$templateId,$h,$extra,$date,$validUntil]);
    } else {
      $st = $pdo->prepare("INSERT INTO tokens (cid, version, org_id, h, extra_info, issued_date, valid_until) VALUES (?,?,?,?,?,?,?)");
      $st->execute([$cid,$v,$effectiveOrgId,$h,$extra,$date,$validUntil]);
    }
  } else {
    $st = $pdo->prepare("INSERT INTO tokens (cid, version, h, extra_info, issued_date, valid_until) VALUES (?,?,?,?,?,?)");
    $st->execute([$cid,$v,$h,$extra,$date,$validUntil]);
  }
  $tokenId = $pdo->lastInsertId();
  // Audit: creation event (no PII)
  try {
    if (isset($_SESSION['admin_id'])) {
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,admin_id,admin_user) VALUES (?,?,?,?)");
      $log->execute([$cid,'create',$_SESSION['admin_id'] ?? null,$_SESSION['admin_user'] ?? null]);
    } else {
      // Fallback if session naming differs
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type) VALUES (?,?)");
      $log->execute([$cid,'create']);
    }
  } catch (PDOException $le) { /* ignore audit failure */ }
  echo json_encode(['ok'=>true,'id'=>$tokenId]);
} catch (PDOException $e) {
  if ($e->getCode()==='23000') { http_response_code(409); echo json_encode(['error'=>'conflict']); } else { throw $e; }
}
