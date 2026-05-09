<?php
session_start();
require_once __DIR__ . '/functions.php';

function is_logged_in(){ return isset($_SESSION['u']) && $_SESSION['u']==='admin'; }

function try_login($u,$p){
  $c = cfg();
  if ($u !== ($c['admin_user'] ?? 'admin')) return false;

  $hash = (string)($c['admin_pass_hash'] ?? '');
  if ($hash !== '' && password_verify($p, $hash)) {
    $_SESSION['u']='admin';
    return true;
  }

  // Fallback für ältere Installationen mit Klartext-Passwort in config.php
  if (isset($c['admin_pass']) && hash_equals((string)$c['admin_pass'], (string)$p)) {
    $_SESSION['u']='admin';
    return true;
  }

  return false;
}

function logout(){
  $_SESSION = [];
  if(ini_get('session.use_cookies')){
    $par = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $par['path'], $par['domain'], $par['secure'], $par['httponly']);
  }
  session_destroy();
}
