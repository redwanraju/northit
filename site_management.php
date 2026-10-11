<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }
header('Content-Type: text/html; charset=UTF-8');

// ============================================
// PRODUCTION READY CONFIGURATION
// ============================================
define('BASE_URL', 'https://ricevaly.bd/');
define('UPLOAD_DIR', 'uploads/site_products/');
define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/' . UPLOAD_DIR);
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// Image helper function
function getImageUrl($imagePath) {
    if (empty($imagePath)) return '';
    
    // If already has full URL, return as is
    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        return $imagePath;
    }
    
    // Clean the path - remove any duplicate 'uploads/site_products/'
    $cleanPath = str_replace(['uploads/site_products/', 'uploads/site_products'], '', $imagePath);
    $cleanPath = ltrim($cleanPath, '/');
    
    // If it's just a filename, add the upload directory
    if (!strpos($cleanPath, '/')) {
        return BASE_URL . UPLOAD_DIR . $cleanPath;
    }
    
    return BASE_URL . $cleanPath;
}

function sanitizeFileName($filename) {
    // Remove any path information
    $filename = basename($filename);
    // Remove special characters
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
    // Remove multiple dots
    $filename = preg_replace('/\.+/', '.', $filename);
    return $filename;
}

function generateUniqueFileName($originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $name = pathinfo($originalName, PATHINFO_FILENAME);
    $name = sanitizeFileName($name);
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    return $timestamp . '_' . $random . '.' . $ext;
}


// Product image optimizer: validates the actual image, resizes oversized uploads,
// and stores JPG/PNG/WebP as WebP. Animated GIFs are preserved as-is.
function saveOptimizedProductImage(array $file, string $context = 'Product image'): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new Exception($context . ': upload failed');

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_FILE_SIZE) {
        throw new Exception($context . ': file too large (max ' . round(MAX_FILE_SIZE / 1024 / 1024, 1) . 'MB)');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new Exception($context . ': invalid upload');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) throw new Exception($context . ': invalid image type');

    $info = @getimagesize($tmp);
    if ($info === false) throw new Exception($context . ': invalid/corrupt image');
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
        throw new Exception($context . ': unsupported image dimensions');
    }

    if (!is_dir(UPLOAD_PATH) && !mkdir(UPLOAD_PATH, 0755, true) && !is_dir(UPLOAD_PATH)) {
        throw new Exception($context . ': upload directory is not writable');
    }

    // Keep GIF untouched so an animated GIF is never flattened accidentally.
    if ($mime === 'image/gif' || !extension_loaded('gd') || !function_exists('imagewebp')) {
        $name = generateUniqueFileName('product.' . $allowed[$mime]);
        if (!move_uploaded_file($tmp, UPLOAD_PATH . $name)) throw new Exception($context . ': failed to save image');
        @chmod(UPLOAD_PATH . $name, 0644);
        return $name;
    }

    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $src = @imagecreatefrompng($tmp); break;
        case 'image/webp': $src = @imagecreatefromwebp($tmp); break;
        default: $src = false;
    }
    if (!$src) throw new Exception($context . ': unable to process image');

    // Correct common JPEG camera orientation when EXIF support is available.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $orientation = (int)($exif['Orientation'] ?? 1);
        if ($orientation === 3) $src = imagerotate($src, 180, 0);
        elseif ($orientation === 6) $src = imagerotate($src, -90, 0);
        elseif ($orientation === 8) $src = imagerotate($src, 90, 0);
        $width = imagesx($src); $height = imagesy($src);
    }

    $maxDimension = 1200;
    $scale = min(1, $maxDimension / max($width, $height));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));

    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dst) { imagedestroy($src); throw new Exception($context . ': unable to allocate image buffer'); }
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);

    if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
        imagedestroy($src); imagedestroy($dst);
        throw new Exception($context . ': resize failed');
    }

    $name = time() . '_' . bin2hex(random_bytes(8)) . '.webp';
    $path = UPLOAD_PATH . $name;
    $saved = imagewebp($dst, $path, 82);
    imagedestroy($src); imagedestroy($dst);
    if (!$saved || !is_file($path)) throw new Exception($context . ': WebP save failed');
    @chmod($path, 0644);
    return $name;
}




// Existing product image optimizer: creates a WebP copy for already-uploaded local
// product images. The original file is never deleted. A dated backup copy is also
// kept before the database path is switched to the optimized file.
function optimizeExistingProductImage(string $storedPath): array {
    $storedPath = trim($storedPath);
    if ($storedPath === '') return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'empty'];
    if (preg_match('~^https?://~i', $storedPath)) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'external'];

    $clean = str_replace(['uploads/site_products/', 'uploads/site_products'], '', $storedPath);
    $clean = ltrim($clean, '/');
    $name = basename($clean);
    if ($name === '' || $name !== $clean) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'unsafe-path'];

    $source = UPLOAD_PATH . $name;
    if (!is_file($source) || !is_readable($source)) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'missing'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($source);
    if ($mime === 'image/gif') return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'gif-preserved'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'unsupported'];
    if (!extension_loaded('gd') || !function_exists('imagewebp')) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'gd-webp-unavailable'];

    $info = @getimagesize($source);
    if ($info === false) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'invalid'];
    $width = (int)($info[0] ?? 0); $height = (int)($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'dimensions'];

    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($source); break;
        case 'image/png':  $src = @imagecreatefrompng($source); break;
        case 'image/webp': $src = @imagecreatefromwebp($source); break;
        default: $src = false;
    }
    if (!$src) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'decode'];

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        $orientation = (int)($exif['Orientation'] ?? 1);
        if ($orientation === 3) $src = imagerotate($src, 180, 0);
        elseif ($orientation === 6) $src = imagerotate($src, -90, 0);
        elseif ($orientation === 8) $src = imagerotate($src, 90, 0);
        $width = imagesx($src); $height = imagesy($src);
    }

    $maxDimension = 1200;
    $scale = min(1, $maxDimension / max($width, $height));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dst) { imagedestroy($src); return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'memory']; }
    imagealphablending($dst, false); imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
    if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
        imagedestroy($src); imagedestroy($dst);
        return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'resize'];
    }

    $backupDir = UPLOAD_PATH . '_optimizer_backup/' . date('Ymd') . '/';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        imagedestroy($src); imagedestroy($dst);
        return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'backup-dir'];
    }
    $backup = $backupDir . $name;
    if (!is_file($backup) && !@copy($source, $backup)) {
        imagedestroy($src); imagedestroy($dst);
        return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'backup-copy'];
    }

    $newName = pathinfo($name, PATHINFO_FILENAME) . '_opt_' . substr(hash('sha256', $name . '|' . filesize($source)), 0, 12) . '.webp';
    $target = UPLOAD_PATH . $newName;
    $saved = imagewebp($dst, $target, 82);
    imagedestroy($src); imagedestroy($dst);
    if (!$saved || !is_file($target)) return ['status' => 'skip', 'path' => $storedPath, 'reason' => 'webp-save'];
    @chmod($target, 0644);

    return [
        'status' => 'optimized', 'path' => $newName,
        'before' => (int)filesize($source), 'after' => (int)filesize($target)
    ];
}



// Generic safe optimizer for already-uploaded local images used outside the product gallery.
// It never deletes the source. A dated backup is created before the DB path is changed.
function optimizeExistingLocalAsset(string $storedPath, string $absoluteDir, string $storedPrefix, int $maxDimension, int $quality): array {
    $storedPath=trim($storedPath); if($storedPath==='') return ['status'=>'skip','path'=>$storedPath,'reason'=>'empty'];
    if(preg_match('~^https?://~i',$storedPath)) return ['status'=>'skip','path'=>$storedPath,'reason'=>'external'];
    $urlPath=(string)(parse_url($storedPath,PHP_URL_PATH)??$storedPath); $name=basename($urlPath);
    if($name==='' || str_contains($name,'_opt_')) return ['status'=>'skip','path'=>$storedPath,'reason'=>'already-optimized'];
    $dir=rtrim($absoluteDir,'/\\').'/'; $source=$dir.$name;
    if(!is_file($source)||!is_readable($source)) return ['status'=>'skip','path'=>$storedPath,'reason'=>'missing'];
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($source);
    if($mime==='image/gif') return ['status'=>'skip','path'=>$storedPath,'reason'=>'gif-preserved'];
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!extension_loaded('gd')||!function_exists('imagewebp')) return ['status'=>'skip','path'=>$storedPath,'reason'=>'unsupported'];
    $info=@getimagesize($source); if($info===false) return ['status'=>'skip','path'=>$storedPath,'reason'=>'invalid'];
    $w=(int)$info[0];$h=(int)$info[1]; if($w<1||$h<1||$w>10000||$h>10000) return ['status'=>'skip','path'=>$storedPath,'reason'=>'dimensions'];
    if($mime==='image/jpeg')$src=@imagecreatefromjpeg($source);elseif($mime==='image/png')$src=@imagecreatefrompng($source);else$src=@imagecreatefromwebp($source);
    if(!$src) return ['status'=>'skip','path'=>$storedPath,'reason'=>'decode'];
    if($mime==='image/jpeg'&&function_exists('exif_read_data')){$exif=@exif_read_data($source);$o=(int)($exif['Orientation']??1);if($o===3)$src=imagerotate($src,180,0);elseif($o===6)$src=imagerotate($src,-90,0);elseif($o===8)$src=imagerotate($src,90,0);$w=imagesx($src);$h=imagesy($src);}
    $scale=min(1,$maxDimension/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
    $dst=imagecreatetruecolor($nw,$nh);if(!$dst){imagedestroy($src);return ['status'=>'skip','path'=>$storedPath,'reason'=>'memory'];}
    imagealphablending($dst,false);imagesavealpha($dst,true);$tr=imagecolorallocatealpha($dst,0,0,0,127);imagefilledrectangle($dst,0,0,$nw,$nh,$tr);
    if(!imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h)){imagedestroy($src);imagedestroy($dst);return ['status'=>'skip','path'=>$storedPath,'reason'=>'resize'];}
    $backupDir=$dir.'_optimizer_backup/'.date('Ymd').'/'; if(!is_dir($backupDir)&&!mkdir($backupDir,0755,true)&&!is_dir($backupDir)){imagedestroy($src);imagedestroy($dst);return ['status'=>'skip','path'=>$storedPath,'reason'=>'backup-dir'];}
    if(!is_file($backupDir.$name)&&!@copy($source,$backupDir.$name)){imagedestroy($src);imagedestroy($dst);return ['status'=>'skip','path'=>$storedPath,'reason'=>'backup-copy'];}
    $newName=pathinfo($name,PATHINFO_FILENAME).'_opt_'.substr(hash('sha256',$name.'|'.filesize($source)),0,12).'.webp';$target=$dir.$newName;
    $ok=imagewebp($dst,$target,max(1,min(100,$quality)));imagedestroy($src);imagedestroy($dst);if(!$ok||!is_file($target))return ['status'=>'skip','path'=>$storedPath,'reason'=>'webp-save'];@chmod($target,0644);
    return ['status'=>'optimized','path'=>$storedPrefix.$newName,'before'=>(int)filesize($source),'after'=>(int)filesize($target)];
}

// Shared optimizer for website-admin images. JPG/PNG/WebP are resized and
// converted to WebP; GIF is preserved to avoid destroying animation.
function saveOptimizedAdminAsset(array $file, string $absoluteDir, string $relativeDir, string $prefix, int $maxDimension, int $quality, int $maxBytes): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new Exception('Image upload failed');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) throw new Exception('Image exceeds the allowed upload size');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new Exception('Invalid image upload');
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = (string)$finfo->file($tmp);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) throw new Exception('Invalid image file');
    $info = @getimagesize($tmp); $w=(int)$info[0]; $h=(int)$info[1];
    if ($w<1 || $h<1 || $w>10000 || $h>10000) throw new Exception('Unsupported image dimensions');
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0755,true) && !is_dir($absoluteDir)) throw new Exception('Unable to create upload directory');
    $relativeDir = rtrim($relativeDir,'/').'/'; $absoluteDir=rtrim($absoluteDir,'/\\').'/';
    if ($mime==='image/gif' || !extension_loaded('gd') || !function_exists('imagewebp')) {
        $name=$prefix.bin2hex(random_bytes(10)).'.'.$allowed[$mime];
        if(!move_uploaded_file($tmp,$absoluteDir.$name)) throw new Exception('Unable to save uploaded image');
        @chmod($absoluteDir.$name,0644); return $relativeDir.$name;
    }
    if($mime==='image/jpeg') $src=@imagecreatefromjpeg($tmp); elseif($mime==='image/png') $src=@imagecreatefrompng($tmp); else $src=@imagecreatefromwebp($tmp);
    if(!$src) throw new Exception('Unable to process image');
    if($mime==='image/jpeg' && function_exists('exif_read_data')){
        $exif=@exif_read_data($tmp); $o=(int)($exif['Orientation']??1);
        if($o===3)$src=imagerotate($src,180,0); elseif($o===6)$src=imagerotate($src,-90,0); elseif($o===8)$src=imagerotate($src,90,0);
        $w=imagesx($src);$h=imagesy($src);
    }
    $scale=min(1,$maxDimension/max($w,$h)); $nw=max(1,(int)round($w*$scale)); $nh=max(1,(int)round($h*$scale));
    $dst=imagecreatetruecolor($nw,$nh); if(!$dst){imagedestroy($src);throw new Exception('Unable to allocate image buffer');}
    imagealphablending($dst,false);imagesavealpha($dst,true);$tr=imagecolorallocatealpha($dst,0,0,0,127);imagefilledrectangle($dst,0,0,$nw,$nh,$tr);
    if(!imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h)){imagedestroy($src);imagedestroy($dst);throw new Exception('Image resize failed');}
    $name=$prefix.bin2hex(random_bytes(10)).'.webp'; $target=$absoluteDir.$name;
    $ok=imagewebp($dst,$target,max(1,min(100,$quality))); imagedestroy($src);imagedestroy($dst);
    if(!$ok || !is_file($target)) throw new Exception('Unable to save optimized image'); @chmod($target,0644);
    return $relativeDir.$name;
}



// Logo-safe upload: preserve alpha by storing PNG/WebP logos as transparent PNG.
// JPEG logos remain JPEG-derived WebP because JPEG has no alpha channel to preserve.
function saveLogoAssetPreserveTransparency(array $file, string $absoluteDir, string $relativeDir, string $prefix, int $maxDimension = 800, int $maxBytes = MAX_FILE_SIZE): string {
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE); if($error!==UPLOAD_ERR_OK) throw new Exception('Logo upload failed');
    $size=(int)($file['size']??0); if($size<=0||$size>$maxBytes) throw new Exception('Logo exceeds the allowed upload size');
    $tmp=(string)($file['tmp_name']??''); if($tmp===''||!is_uploaded_file($tmp)) throw new Exception('Invalid logo upload');
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($tmp); $info=@getimagesize($tmp);
    if($info===false||!in_array($mime,['image/png','image/webp','image/jpeg','image/gif'],true)) throw new Exception('Invalid logo image');
    if(!is_dir($absoluteDir)&&!mkdir($absoluteDir,0755,true)&&!is_dir($absoluteDir)) throw new Exception('Unable to create logo directory');
    $absoluteDir=rtrim($absoluteDir,'/\\').'/'; $relativeDir=rtrim($relativeDir,'/').'/';
    if($mime==='image/gif'||!extension_loaded('gd')){ $ext=$mime==='image/gif'?'gif':($mime==='image/jpeg'?'jpg':($mime==='image/webp'?'webp':'png')); $name=$prefix.bin2hex(random_bytes(10)).'.'.$ext; if(!move_uploaded_file($tmp,$absoluteDir.$name))throw new Exception('Unable to save logo'); @chmod($absoluteDir.$name,0644); return $relativeDir.$name; }
    if($mime==='image/png')$src=@imagecreatefrompng($tmp); elseif($mime==='image/webp')$src=@imagecreatefromwebp($tmp); else $src=@imagecreatefromjpeg($tmp); if(!$src)throw new Exception('Unable to process logo');
    $w=imagesx($src);$h=imagesy($src);$scale=min(1,$maxDimension/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
    $dst=imagecreatetruecolor($nw,$nh); imagealphablending($dst,false); imagesavealpha($dst,true); $transparent=imagecolorallocatealpha($dst,0,0,0,127); imagefilledrectangle($dst,0,0,$nw,$nh,$transparent);
    imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h); imagedestroy($src);
    if($mime==='image/jpeg'){ $name=$prefix.bin2hex(random_bytes(10)).'.webp'; $ok=function_exists('imagewebp')&&imagewebp($dst,$absoluteDir.$name,84); }
    else { $name=$prefix.bin2hex(random_bytes(10)).'.png'; $ok=imagepng($dst,$absoluteDir.$name,6); }
    imagedestroy($dst); if(!$ok||!is_file($absoluteDir.$name))throw new Exception('Unable to save optimized logo'); @chmod($absoluteDir.$name,0644); return $relativeDir.$name;
}

// Category images: small square/listing assets, max 800px.
function handleCategoryImageUpload(string $current = ''): string {
    if (!isset($_FILES['category_image']) || empty($_FILES['category_image']['name'])) return $current;
    $dir=rtrim($_SERVER['DOCUMENT_ROOT'],'/').'/pos/site/images/';
    $saved=saveOptimizedAdminAsset($_FILES['category_image'],$dir,'','cat_',800,82,2*1024*1024);
    return basename($saved);
}

// Generic public asset URL helper for website-admin uploads.
function getPublicAssetUrl($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^(https?:)?//~i', $path)) return $path;
    return BASE_URL . ltrim($path, '/');
}
function adminJsonResponse(array $payload, int $statusCode = 200): void { http_response_code($statusCode); header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function normalizeAdminDateTime($value): ?string { $value=trim((string)$value); if($value==='')return null; $timestamp=strtotime($value); if($timestamp===false)throw new Exception('Invalid date/time value'); return date('Y-m-d H:i:s',$timestamp); }
function normalizeAdminHexColor($value, string $default): string { $value=trim((string)$value); return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?strtolower($value):$default; }
function normalizeAdminLinkUrl($value, bool $allowEmpty = true): string { $value=trim((string)$value); if($value===''){if($allowEmpty)return '';throw new Exception('URL is required');} if(str_starts_with($value,'#')||str_starts_with($value,'/'))return $value; if(preg_match('~^(mailto:|tel:)~i',$value))return $value; if(preg_match('~^https?://~i',$value)&&filter_var($value,FILTER_VALIDATE_URL))return $value; throw new Exception('Invalid URL: '.$value); }
function deleteAdminManagedUpload($path): void { $path=trim((string)$path); if($path===''||!str_starts_with($path,'uploads/site_admin/'))return; $relative=ltrim(str_replace(['../','..\\'],'',$path),'/'); $fullPath=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/\\').'/'.$relative; if(is_file($fullPath))@unlink($fullPath); }
function handleAdminImageUpload(string $field, string $folder, string $currentPath = ''): string {
    if(!isset($_FILES[$field])||!is_array($_FILES[$field]))return $currentPath; $file=$_FILES[$field]; $error=(int)($file['error']??UPLOAD_ERR_NO_FILE); if($error===UPLOAD_ERR_NO_FILE)return $currentPath;
    $safeFolder=preg_replace('/[^a-zA-Z0-9_-]/','',$folder)?:'misc';
    $relativeDir='uploads/site_admin/'.$safeFolder.'/'; $absoluteDir=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/\\').'/'.$relativeDir;
    // Tailored dimensions: logos stay compact; hero/offer images retain enough detail for wide displays.
    $maxDimension=1600; $quality=82;
    if(in_array($safeFolder,['footer','partners'],true)){$maxDimension=800;$quality=84;}
    elseif($safeFolder==='hero'){$maxDimension=1920;$quality=82;}
    elseif($safeFolder==='offers'){$maxDimension=1600;$quality=82;}
    $relativePath=in_array($safeFolder,['footer','partners'],true) ? saveLogoAssetPreserveTransparency($file,$absoluteDir,$relativeDir,$safeFolder.'_',800,MAX_FILE_SIZE) : saveOptimizedAdminAsset($file,$absoluteDir,$relativeDir,$safeFolder.'_', $maxDimension,$quality,MAX_FILE_SIZE);
    if($currentPath!==''&&$currentPath!==$relativePath)deleteAdminManagedUpload($currentPath);
    return $relativePath;
}

function normalizeInformationLinks($raw): string {
    if (is_array($raw)) {
        $links = $raw;
    } else {
        $raw = trim((string)$raw);
        $links = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($links)) throw new Exception('Information links format is invalid');
    }

    $clean = [];
    foreach ($links as $index => $link) {
        if (!is_array($link)) continue;
        $label = trim((string)($link['label'] ?? ''));
        $url = trim((string)($link['url'] ?? ''));
        $url = normalizeAdminLinkUrl($url === '' ? '#' : $url, false);
        if ($label === '') continue;
        $clean[] = [
            'label' => function_exists('mb_substr') ? mb_substr($label, 0, 150) : substr($label, 0, 150),
            'url' => function_exists('mb_substr') ? mb_substr($url === '' ? '#' : $url, 0, 500) : substr($url === '' ? '#' : $url, 0, 500),
            'sort' => max(0, (int)($link['sort'] ?? ($index + 1)))
        ];
    }
    usort($clean, fn($a, $b) => ($a['sort'] <=> $b['sort']));
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Product rich-text description sanitizer. Keeps the formatting required by the
// admin editor, but applies a strict tag/attribute/style allowlist before storage/output.
function sanitizeProductDescriptionHtml($html): string {
    $html = trim((string)$html);
    if ($html === '') return '';

    // Drop active/embedded content with its contents before parsing the remaining fragment.
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link|base|svg|math)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link|base|svg|math)\b[^>]*/?>#is', '', $html);

    // DOMDocument gives us reliable attribute-level sanitization. Keep a conservative
    // fallback for servers where the DOM extension is unavailable.
    if (!class_exists('DOMDocument')) {
        $allowed = '<p><br><div><span><strong><b><em><i><u><s><strike><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><a><img><table><thead><tbody><tfoot><tr><th><td><hr><font>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*(["\']).*?\1/is', '', $html);
        $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*[^\s>]+/is', '', $html);
        $html = preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data)\s*:[^"\']*\2/is', '', $html);
        return $html;
    }

    $allowedTags = [
        'p'=>1,'br'=>1,'div'=>1,'span'=>1,'strong'=>1,'b'=>1,'em'=>1,'i'=>1,'u'=>1,
        's'=>1,'strike'=>1,'ul'=>1,'ol'=>1,'li'=>1,'h1'=>1,'h2'=>1,'h3'=>1,'h4'=>1,
        'h5'=>1,'h6'=>1,'blockquote'=>1,'pre'=>1,'code'=>1,'a'=>1,'img'=>1,'table'=>1,
        'thead'=>1,'tbody'=>1,'tfoot'=>1,'tr'=>1,'th'=>1,'td'=>1,'hr'=>1,'font'=>1
    ];
    $allowedAttrs = [
        '*'     => ['style','title'],
        'a'     => ['href','target','rel','title'],
        'img'   => ['src','alt','title','width','height'],
        'th'    => ['colspan','rowspan','style','title'],
        'td'    => ['colspan','rowspan','style','title'],
        'font'  => ['face','size','color','style','title'],
    ];

    $sanitizeUrl = static function(string $value, bool $isImage = false): string {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') return '';
        // Remove ASCII control/whitespace obfuscation from the scheme area.
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $value);
        if (preg_match('/^(?:javascript|vbscript|data|file):/i', $probe)) return '';
        if ($isImage) {
            return preg_match('~^(?:https?://|/)~i', $value) ? $value : '';
        }
        return preg_match('~^(?:https?://|mailto:|tel:|/|#)~i', $value) ? $value : '';
    };

    $sanitizeStyle = static function(string $style): string {
        $clean = [];
        foreach (explode(';', $style) as $decl) {
            if (strpos($decl, ':') === false) continue;
            [$prop, $value] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $value = trim($value);
            if ($value === '' || preg_match('/(?:expression\s*\(|url\s*\(|javascript\s*:|vbscript\s*:|data\s*:)/i', $value)) continue;

            $ok = false;
            if ($prop === 'text-align') {
                $ok = in_array(strtolower($value), ['left','right','center','justify'], true);
            } elseif ($prop === 'font-weight') {
                $ok = (bool)preg_match('/^(?:normal|bold|[1-9]00)$/i', $value);
            } elseif ($prop === 'font-style') {
                $ok = in_array(strtolower($value), ['normal','italic','oblique'], true);
            } elseif ($prop === 'text-decoration') {
                $ok = (bool)preg_match('/^(?:none|underline|line-through|underline\s+line-through|line-through\s+underline)$/i', $value);
            } elseif ($prop === 'color' || $prop === 'background-color') {
                $ok = (bool)preg_match('/^(?:#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\)|hsla?\([0-9.,%\s]+\)|[a-z]{3,20})$/i', $value);
            } elseif ($prop === 'font-family') {
                $ok = (bool)preg_match('/^[a-z0-9\s,\-\'\"]{1,120}$/i', $value);
            } elseif ($prop === 'font-size') {
                if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(px|pt|em|rem|%)$/i', $value, $m)) {
                    $n = (float)$m[1]; $unit = strtolower($m[2]);
                    $ok = ($unit === 'px' && $n >= 8 && $n <= 72)
                       || ($unit === 'pt' && $n >= 6 && $n <= 54)
                       || (($unit === 'em' || $unit === 'rem') && $n >= 0.5 && $n <= 4)
                       || ($unit === '%' && $n >= 50 && $n <= 300);
                }
            }
            if ($ok) $clean[] = $prop . ': ' . $value;
        }
        return implode('; ', $clean);
    };

    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $wrapped = '<div id="rv-sanitize-root">' . $html . '</div>';
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return '';

    $root = $doc->getElementById('rv-sanitize-root');
    if (!$root) return '';

    $walk = function(DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $sanitizeUrl, $sanitizeStyle): void {
        // Snapshot child list because nodes may be unwrapped/removed while traversing.
        $children = [];
        foreach ($node->childNodes as $child) $children[] = $child;
        foreach ($children as $child) {
            if (!($child instanceof DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (!isset($allowedTags[$tag])) {
                // Unknown non-active markup is unwrapped so its text/allowed descendants survive.
                $parent = $child->parentNode;
                if ($parent) {
                    while ($child->firstChild) $parent->insertBefore($child->firstChild, $child);
                    $parent->removeChild($child);
                }
                continue;
            }

            $allowedForTag = array_unique(array_merge($allowedAttrs['*'] ?? [], $allowedAttrs[$tag] ?? []));
            $attrNames = [];
            foreach ($child->attributes as $attr) $attrNames[] = $attr->name;
            foreach ($attrNames as $name) {
                $lower = strtolower($name);
                if (str_starts_with($lower, 'on') || !in_array($lower, $allowedForTag, true)) {
                    $child->removeAttribute($name);
                    continue;
                }
                $value = $child->getAttribute($name);
                if ($lower === 'href') {
                    $safe = $sanitizeUrl($value, false);
                    if ($safe === '') $child->removeAttribute($name); else $child->setAttribute('href', $safe);
                } elseif ($lower === 'src') {
                    $safe = $sanitizeUrl($value, true);
                    if ($safe === '') $child->removeAttribute($name); else $child->setAttribute('src', $safe);
                } elseif ($lower === 'style') {
                    $safe = $sanitizeStyle($value);
                    if ($safe === '') $child->removeAttribute($name); else $child->setAttribute('style', $safe);
                } elseif ($lower === 'target') {
                    if (!in_array($value, ['_blank','_self'], true)) $child->removeAttribute($name);
                } elseif ($lower === 'rel') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                } elseif ($lower === 'width' || $lower === 'height') {
                    if (!preg_match('/^\d{1,4}$/', $value) || (int)$value < 1 || (int)$value > 4000) $child->removeAttribute($name);
                } elseif ($lower === 'colspan' || $lower === 'rowspan') {
                    if (!preg_match('/^\d{1,2}$/', $value) || (int)$value < 1 || (int)$value > 20) $child->removeAttribute($name);
                } elseif ($tag === 'font' && $lower === 'size') {
                    if (!preg_match('/^[1-7]$/', $value)) $child->removeAttribute($name);
                } elseif ($tag === 'font' && $lower === 'face') {
                    if (!preg_match('/^[a-z0-9\s,\-\'\"]{1,120}$/i', $value)) $child->removeAttribute($name);
                } elseif ($tag === 'font' && $lower === 'color') {
                    if (!preg_match('/^(?:#[0-9a-f]{3,8}|[a-z]{3,20})$/i', $value)) $child->removeAttribute($name);
                }
            }

            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            $walk($child);
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

// Database connection
// Database connection - compatible with /pos/site/site_management.php and /pos/site_management.php
$dbCandidates = [
    __DIR__ . '/../../config/db.php', // /pos/site/site_management.php
    __DIR__ . '/../config/db.php',    // /pos/site_management.php
    rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/config/db.php'
];

$dbLoaded = false;
foreach ($dbCandidates as $dbFile) {
    if ($dbFile && is_file($dbFile)) {
        require_once $dbFile;
        $dbLoaded = true;
        break;
    }
}

if (!$dbLoaded) {
    http_response_code(500);
    die('Database configuration file not found. Expected /config/db.php');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection was not initialized by config/db.php');
}

// Force full Unicode/Bangla support for every database read/write in this admin page.
if (!mysqli_set_charset($conn, 'utf8mb4')) {
    throw new RuntimeException('Failed to set database charset to utf8mb4: ' . mysqli_error($conn));
}
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// Validate Site Category -> Site Subcategory -> Site Child Category relation.
function validateCategoryHierarchy(mysqli $conn, ?int $categoryId, ?int $subcategoryId, ?int $childcategoryId, string $prefix = ''): void {
    $label = $prefix !== '' ? $prefix . ': ' : '';

    if (!$categoryId) {
        throw new Exception($label . 'Site Category is required');
    }

    $stmt = $conn->prepare("SELECT id FROM site_categories WHERE id = ? AND status = 1 LIMIT 1");
    if (!$stmt) {
        throw new Exception($label . 'Failed to validate site category');
    }
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $categoryExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$categoryExists) {
        throw new Exception($label . 'Invalid site category selected');
    }

    if ($subcategoryId !== null) {
        $stmt = $conn->prepare("SELECT id FROM site_subcategories WHERE id = ? AND site_category_id = ? AND status = 1 LIMIT 1");
        if (!$stmt) {
            throw new Exception($label . 'Failed to validate subcategory');
        }
        $stmt->bind_param('ii', $subcategoryId, $categoryId);
        $stmt->execute();
        $subcategoryExists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$subcategoryExists) {
            throw new Exception($label . 'Invalid subcategory selected');
        }
    }

    if ($childcategoryId !== null) {
        if ($subcategoryId === null) {
            throw new Exception($label . 'Select a subcategory before selecting a child category');
        }

        $stmt = $conn->prepare("SELECT id FROM site_childcategories WHERE id = ? AND site_category_id = ? AND subcategory_id = ? AND status = 1 LIMIT 1");
        if (!$stmt) {
            throw new Exception($label . 'Failed to validate child category');
        }
        $stmt->bind_param('iii', $childcategoryId, $categoryId, $subcategoryId);
        $stmt->execute();
        $childExists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$childExists) {
            throw new Exception($label . 'Invalid child category selected');
        }
    }
}

function validateSiteParentCategory(mysqli $conn, int $categoryId): void {
    $stmt = $conn->prepare("SELECT id FROM site_categories WHERE id = ? AND status = 1 LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to validate site category');
    }
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        throw new Exception('Invalid site category selected');
    }
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $mutatingActions = [
        'save_site_category', 'delete_site_category',
        'save_site_subcategory', 'delete_site_subcategory',
        'save_site_childcategory', 'delete_site_childcategory',
        'bulk_save_products', 'save_product', 'delete_product', 'optimize_existing_product_images', 'optimize_all_existing_images',
        'update_section', 'bulk_assign_section',
        'save_footer_settings',
        'save_partner', 'delete_partner', 'save_partner_section',
        'update_site_review_status', 'delete_site_review',
        'save_hero_slider', 'delete_hero_slider',
        'save_offer_banner', 'delete_offer_banner',
        'save_breaking_news', 'delete_breaking_news',
        'save_shipping_method', 'delete_shipping_method'
    ];
    if (in_array((string)$action, $mutatingActions, true)) {
        siteAdminRequireCsrf();
    }

    // ===== GET ACTIVE SITE CATEGORIES FOR WEBSITE DROPDOWNS =====
    if ($action === 'get_categories') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $stmt = $conn->prepare("\n                SELECT id, category_name AS name\n                FROM site_categories\n                WHERE status = 1\n                  AND category_name IS NOT NULL\n                  AND TRIM(category_name) <> ''\n                ORDER BY sort_order ASC, category_name ASC\n            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare site categories query');
            }
            if (!$stmt->execute()) {
                throw new Exception('Failed to load site categories');
            }
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = ['id' => (int)$row['id'], 'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }

    // ===== SAVE SITE CATEGORY =====
    if ($action === 'save_site_category') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $name = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';
            $status = isset($_POST['status']) ? (intval($_POST['status']) ? 1 : 0) : 1;
            $sortOrder = isset($_POST['sort_order']) ? max(0, intval($_POST['sort_order'])) : 0;
            $image = handleCategoryImageUpload((string)($_POST['old_category_image'] ?? ''));

            if ($name === '') throw new Exception('Site Category name is required');

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE site_categories SET category_name = ?, category_image = ?, status = ?, sort_order = ? WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare site category update');
                $stmt->bind_param('ssiii', $name, $image, $status, $sortOrder, $id);
                if (!$stmt->execute()) throw new Exception('Failed to update site category: ' . $stmt->error);
                if ($stmt->affected_rows === 0) {
                    $check = $conn->prepare("SELECT id FROM site_categories WHERE id = ? LIMIT 1");
                    $check->bind_param('i', $id);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();
                    if (!$exists) throw new Exception('Site Category not found');
                }
                $stmt->close();
                $message = 'Site Category updated successfully';
            } else {
                $stmt = $conn->prepare("INSERT INTO site_categories (category_name, category_image, status, sort_order) VALUES (?, ?, ?, ?)");
                if (!$stmt) throw new Exception('Failed to prepare site category insert');
                $stmt->bind_param('ssii', $name, $image, $status, $sortOrder);
                if (!$stmt->execute()) throw new Exception('Failed to add site category: ' . $stmt->error);
                $stmt->close();
                $message = 'Site Category added successfully';
            }

            echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ===== DELETE SITE CATEGORY =====
    if ($action === 'delete_site_category') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) throw new Exception('Invalid site category');

            $stmt = $conn->prepare("SELECT\n                (SELECT COUNT(*) FROM site_subcategories WHERE site_category_id = ?) +\n                (SELECT COUNT(*) FROM site_childcategories WHERE site_category_id = ?) +\n                (SELECT COUNT(*) FROM site_products WHERE site_category_id = ?) AS usage_count");
            if (!$stmt) throw new Exception('Failed to check site category usage');
            $stmt->bind_param('iii', $id, $id, $id);
            $stmt->execute();
            $usage = (int)($stmt->get_result()->fetch_assoc()['usage_count'] ?? 0);
            $stmt->close();
            if ($usage > 0) {
                throw new Exception('Cannot delete site category because it is in use');
            }

            $stmt = $conn->prepare("DELETE FROM site_categories WHERE id = ?");
            if (!$stmt) throw new Exception('Failed to prepare site category delete');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete site category');
            if ($stmt->affected_rows === 0) throw new Exception('Site Category not found');
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Site Category deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ===== GET SITE SUBCATEGORIES BY MAIN CATEGORY =====
    if ($action === 'get_site_subcategories') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $categoryId = isset($_POST['site_category_id']) ? intval($_POST['site_category_id']) : 0;
            if ($categoryId <= 0) {
                echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $includeInactive = !empty($_POST['include_inactive']);
            $sql = "SELECT id, subcategory_name AS name
                    FROM site_subcategories
                    WHERE site_category_id = ?";
            if (!$includeInactive) {
                $sql .= " AND status = 1";
            }
            $sql .= " ORDER BY sort_order ASC, subcategory_name ASC";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare subcategory query');
            }
            $stmt->bind_param('i', $categoryId);
            if (!$stmt->execute()) {
                throw new Exception('Failed to load subcategories');
            }
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = ['id' => (int)$row['id'], 'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }

    // ===== GET SITE CHILD CATEGORIES BY CATEGORY + SUBCATEGORY =====
    if ($action === 'get_site_childcategories') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $categoryId = isset($_POST['site_category_id']) ? intval($_POST['site_category_id']) : 0;
            $subcategoryId = isset($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : 0;
            if ($categoryId <= 0 || $subcategoryId <= 0) {
                echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT id, childcategory_name AS name
                FROM site_childcategories
                WHERE site_category_id = ?
                  AND subcategory_id = ?
                  AND status = 1
                ORDER BY sort_order ASC, childcategory_name ASC
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare child category query');
            }
            $stmt->bind_param('ii', $categoryId, $subcategoryId);
            if (!$stmt->execute()) {
                throw new Exception('Failed to load child categories');
            }
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = ['id' => (int)$row['id'], 'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }

    // ===== SITE CATEGORY SETUP DATA =====
    if ($action === 'get_site_category_setup') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $categories = [];
            $result = $conn->query("SELECT id, category_name AS name, category_image, status, sort_order FROM site_categories ORDER BY sort_order ASC, category_name ASC");
            if (!$result) throw new Exception('Failed to load site categories');
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }

            $subcategories = [];
            $sql = "SELECT s.id, s.site_category_id, s.subcategory_name, s.status, s.sort_order, c.category_name\n                    FROM site_subcategories s\n                    LEFT JOIN site_categories c ON c.id = s.site_category_id\n                    ORDER BY c.category_name ASC, s.sort_order ASC, s.subcategory_name ASC";
            $result = $conn->query($sql);
            if (!$result) throw new Exception('Failed to load site subcategories');
            while ($row = $result->fetch_assoc()) {
                $subcategories[] = $row;
            }

            $childcategories = [];
            $sql = "SELECT ch.id, ch.site_category_id, ch.subcategory_id, ch.childcategory_name, ch.status, ch.sort_order,\n                           c.category_name, s.subcategory_name\n                    FROM site_childcategories ch\n                    LEFT JOIN site_categories c ON c.id = ch.site_category_id\n                    LEFT JOIN site_subcategories s ON s.id = ch.subcategory_id\n                    ORDER BY c.category_name ASC, s.subcategory_name ASC, ch.sort_order ASC, ch.childcategory_name ASC";
            $result = $conn->query($sql);
            if (!$result) throw new Exception('Failed to load site child categories');
            while ($row = $result->fetch_assoc()) {
                $childcategories[] = $row;
            }

            echo json_encode([
                'success' => true,
                'categories' => $categories,
                'subcategories' => $subcategories,
                'childcategories' => $childcategories
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ===== SAVE SITE SUBCATEGORY =====
    if ($action === 'save_site_subcategory') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $categoryId = isset($_POST['site_category_id']) ? intval($_POST['site_category_id']) : 0;
            $name = isset($_POST['subcategory_name']) ? trim($_POST['subcategory_name']) : '';
            $status = isset($_POST['status']) ? (intval($_POST['status']) ? 1 : 0) : 1;
            $sortOrder = isset($_POST['sort_order']) ? max(0, intval($_POST['sort_order'])) : 0;

            if ($categoryId <= 0) throw new Exception('Site Category is required');
            if ($name === '') throw new Exception('Subcategory name is required');
            validateSiteParentCategory($conn, $categoryId);

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT site_category_id FROM site_subcategories WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$old) throw new Exception('Subcategory not found');

                if ((int)$old['site_category_id'] !== $categoryId) {
                    $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM site_childcategories WHERE subcategory_id = ?) + (SELECT COUNT(*) FROM site_products WHERE subcategory_id = ?) AS usage_count");
                    $stmt->bind_param('ii', $id, $id);
                    $stmt->execute();
                    $usage = (int)($stmt->get_result()->fetch_assoc()['usage_count'] ?? 0);
                    $stmt->close();
                    if ($usage > 0) {
                        throw new Exception('Cannot change parent category because this subcategory is already in use');
                    }
                }

                $stmt = $conn->prepare("UPDATE site_subcategories SET site_category_id = ?, subcategory_name = ?, status = ?, sort_order = ? WHERE id = ?");
                $stmt->bind_param('isiii', $categoryId, $name, $status, $sortOrder, $id);
                if (!$stmt->execute()) throw new Exception('Failed to update subcategory: ' . $stmt->error);
                $stmt->close();
                $message = 'Subcategory updated successfully';
            } else {
                $stmt = $conn->prepare("INSERT INTO site_subcategories (site_category_id, subcategory_name, status, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isii', $categoryId, $name, $status, $sortOrder);
                if (!$stmt->execute()) throw new Exception('Failed to add subcategory: ' . $stmt->error);
                $stmt->close();
                $message = 'Subcategory added successfully';
            }

            echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ===== DELETE SITE SUBCATEGORY =====
    if ($action === 'delete_site_subcategory') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) throw new Exception('Invalid subcategory');

            $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM site_childcategories WHERE subcategory_id = ?) + (SELECT COUNT(*) FROM site_products WHERE subcategory_id = ?) AS usage_count");
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
            $usage = (int)($stmt->get_result()->fetch_assoc()['usage_count'] ?? 0);
            $stmt->close();
            if ($usage > 0) {
                throw new Exception('Cannot delete subcategory because it is in use');
            }

            $stmt = $conn->prepare("DELETE FROM site_subcategories WHERE id = ?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete subcategory');
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Subcategory deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ===== SAVE SITE CHILD CATEGORY =====
    if ($action === 'save_site_childcategory') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $categoryId = isset($_POST['site_category_id']) ? intval($_POST['site_category_id']) : 0;
            $subcategoryId = isset($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : 0;
            $name = isset($_POST['childcategory_name']) ? trim($_POST['childcategory_name']) : '';
            $status = isset($_POST['status']) ? (intval($_POST['status']) ? 1 : 0) : 1;
            $sortOrder = isset($_POST['sort_order']) ? max(0, intval($_POST['sort_order'])) : 0;

            if ($categoryId <= 0) throw new Exception('Site Category is required');
            if ($subcategoryId <= 0) throw new Exception('Subcategory is required');
            if ($name === '') throw new Exception('Child category name is required');
            validateSiteParentCategory($conn, $categoryId);

            $stmt = $conn->prepare("SELECT id FROM site_subcategories WHERE id = ? AND site_category_id = ? LIMIT 1");
            $stmt->bind_param('ii', $subcategoryId, $categoryId);
            $stmt->execute();
            $subExists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$subExists) throw new Exception('Selected subcategory does not belong to the selected category');

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT site_category_id, subcategory_id FROM site_childcategories WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$old) throw new Exception('Child category not found');

                if ((int)$old['site_category_id'] !== $categoryId || (int)$old['subcategory_id'] !== $subcategoryId) {
                    $stmt = $conn->prepare("SELECT COUNT(*) AS usage_count FROM site_products WHERE childcategory_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $usage = (int)($stmt->get_result()->fetch_assoc()['usage_count'] ?? 0);
                    $stmt->close();
                    if ($usage > 0) {
                        throw new Exception('Cannot change parent because this child category is already in use');
                    }
                }

                $stmt = $conn->prepare("UPDATE site_childcategories SET site_category_id = ?, subcategory_id = ?, childcategory_name = ?, status = ?, sort_order = ? WHERE id = ?");
                $stmt->bind_param('iisiii', $categoryId, $subcategoryId, $name, $status, $sortOrder, $id);
                if (!$stmt->execute()) throw new Exception('Failed to update child category: ' . $stmt->error);
                $stmt->close();
                $message = 'Child category updated successfully';
            } else {
                $stmt = $conn->prepare("INSERT INTO site_childcategories (site_category_id, subcategory_id, childcategory_name, status, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('iisii', $categoryId, $subcategoryId, $name, $status, $sortOrder);
                if (!$stmt->execute()) throw new Exception('Failed to add child category: ' . $stmt->error);
                $stmt->close();
                $message = 'Child category added successfully';
            }

            echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ===== DELETE SITE CHILD CATEGORY =====
    if ($action === 'delete_site_childcategory') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) throw new Exception('Invalid child category');

            $stmt = $conn->prepare("SELECT COUNT(*) AS usage_count FROM site_products WHERE childcategory_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $usage = (int)($stmt->get_result()->fetch_assoc()['usage_count'] ?? 0);
            $stmt->close();
            if ($usage > 0) {
                throw new Exception('Cannot delete child category because it is in use');
            }

            $stmt = $conn->prepare("DELETE FROM site_childcategories WHERE id = ?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete child category');
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Child category deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ===== GET BRANDS FOR DROPDOWN =====
    if ($action === 'get_brands') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $stmt = $conn->prepare("
                SELECT id, brand_name AS name
                FROM brands
                WHERE status = 1 AND brand_name IS NOT NULL AND brand_name <> ''
                ORDER BY brand_name ASC
            ");
            if (!$stmt || !$stmt->execute()) {
                throw new Exception('Failed to load brands');
            }
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = ['id' => (int)$row['id'], 'name' => htmlspecialchars($row['name'])];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }



    // ===== OPTIMIZE ALL EXISTING WEBSITE IMAGES (SAFE BATCHES) =====
    if ($action === 'optimize_all_existing_images') {
        header('Content-Type: application/json; charset=utf-8'); @set_time_limit(60);
        try {
            $stage=(string)($_POST['stage']??'products'); $cursor=max(0,(int)($_POST['cursor']??0)); $limit=12;
            $stages=['products','categories','hero','offers','footer','partners','dashboard'];
            if(!in_array($stage,$stages,true)) throw new Exception('Invalid optimizer stage');
            $processed=0;$optimized=0;$skipped=0;$savedBytes=0;$lastId=$cursor;
            $docRoot=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/\\');
            $apply=function(string $value,string $dir,string $prefix,int $max,int $quality) use (&$optimized,&$skipped,&$savedBytes){$r=optimizeExistingLocalAsset($value,$dir,$prefix,$max,$quality);if(($r['status']??'')==='optimized'){$optimized++;$savedBytes+=max(0,(int)$r['before']-(int)$r['after']);}else{$skipped++;}return $r;};
            if($stage==='products'){
                $stmt=$conn->prepare("SELECT id,site_images FROM site_products WHERE id>? AND site_images IS NOT NULL AND TRIM(site_images)<>'' ORDER BY id ASC LIMIT ?");$stmt->bind_param('ii',$cursor,$limit);$stmt->execute();$rs=$stmt->get_result();
                while($row=$rs->fetch_assoc()){$id=(int)$row['id'];$lastId=$id;$processed++;$parts=array_values(array_filter(array_map('trim',explode(',',(string)$row['site_images']))));$new=[];$changed=false;foreach($parts as $part){$r=optimizeExistingProductImage($part);$new[]=(string)$r['path'];if(($r['status']??'')==='optimized'){$changed=true;$optimized++;$savedBytes+=max(0,(int)$r['before']-(int)$r['after']);}else{$skipped++;}}if($changed){$v=implode(',',$new);$up=$conn->prepare('UPDATE site_products SET site_images=? WHERE id=?');$up->bind_param('si',$v,$id);$up->execute();$up->close();}}$stmt->close();
                $q=$conn->prepare("SELECT COUNT(*) c FROM site_products WHERE id>? AND site_images IS NOT NULL AND TRIM(site_images)<>''");$q->bind_param('i',$lastId);$q->execute();$remaining=(int)($q->get_result()->fetch_assoc()['c']??0);$q->close();
            } else {
                $configs=[
                  'categories'=>['site_categories','category_image',$docRoot.'/pos/site/images/','',800,82],
                  'hero'=>['hero_sliders','__hero__',$docRoot.'/uploads/site_admin/hero/','uploads/site_admin/hero/',1920,82],
                  'offers'=>['site_offer_banners','image',$docRoot.'/uploads/site_admin/offers/','uploads/site_admin/offers/',1600,82],
                  'footer'=>['site_footer_settings','logo_image',$docRoot.'/uploads/site_admin/footer/','uploads/site_admin/footer/',800,84],
                  'partners'=>['site_partners','logo_image',$docRoot.'/uploads/site_admin/partners/','uploads/site_admin/partners/',800,84],
                  'dashboard'=>['customer_dashboard_banners','image',__DIR__.'/uploads/customer_dashboard_banners/','uploads/customer_dashboard_banners/',1920,82]
                ];
                [$table,$col,$dir,$prefix,$max,$quality]=$configs[$stage];
                $select=$stage==='hero'?"SELECT id,image,mobile_image FROM hero_sliders WHERE id>? ORDER BY id ASC LIMIT ?":"SELECT id,$col FROM $table WHERE id>? AND $col IS NOT NULL AND TRIM($col)<>'' ORDER BY id ASC LIMIT ?";
                $stmt=$conn->prepare($select);$stmt->bind_param('ii',$cursor,$limit);$stmt->execute();$rs=$stmt->get_result();
                while($row=$rs->fetch_assoc()){$id=(int)$row['id'];$lastId=$id;$processed++;
                    if($stage==='hero'){$r1=$apply((string)($row['image']??''),$dir,$prefix,$max,$quality);$r2=$apply((string)($row['mobile_image']??''),$dir,$prefix,$max,$quality);$v1=(string)$r1['path'];$v2=(string)$r2['path'];if(($r1['status']??'')==='optimized'||($r2['status']??'')==='optimized'){$up=$conn->prepare('UPDATE hero_sliders SET image=?,mobile_image=? WHERE id=?');$up->bind_param('ssi',$v1,$v2,$id);$up->execute();$up->close();}}
                    else{if(in_array($stage,['footer','partners'],true)){$skipped++;continue;}$r=$apply((string)$row[$col],$dir,$prefix,$max,$quality);if(($r['status']??'')==='optimized'){$v=(string)$r['path'];$up=$conn->prepare("UPDATE $table SET $col=? WHERE id=?");$up->bind_param('si',$v,$id);$up->execute();$up->close();}}
                }$stmt->close();
                $countSql=$stage==='hero'?"SELECT COUNT(*) c FROM hero_sliders WHERE id>?":"SELECT COUNT(*) c FROM $table WHERE id>? AND $col IS NOT NULL AND TRIM($col)<>''";$q=$conn->prepare($countSql);$q->bind_param('i',$lastId);$q->execute();$remaining=(int)($q->get_result()->fetch_assoc()['c']??0);$q->close();
            }
            $idx=array_search($stage,$stages,true);$nextStage=($remaining===0 && $idx<count($stages)-1)?$stages[$idx+1]:$stage;$done=($remaining===0 && $idx===count($stages)-1);
            echo json_encode(['success'=>true,'stage'=>$stage,'next_stage'=>$nextStage,'processed'=>$processed,'optimized'=>$optimized,'skipped'=>$skipped,'saved_bytes'=>$savedBytes,'cursor'=>$lastId,'remaining'=>$remaining,'done'=>$done],JSON_UNESCAPED_UNICODE);exit;
        } catch(Throwable $e){error_log('All existing image optimizer: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Optimization stopped safely: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
    }

    // Get single product data for edit
    if ($action == 'get_product') {
        try {
            $id = intval($_POST['id']);
            $stmt = $conn->prepare("SELECT * FROM site_products WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            
            // Clean image paths for frontend
            if (!empty($data['site_images'])) {
                $images = explode(',', $data['site_images']);
                $cleanImages = array_map(function($img) {
                    return basename(trim($img));
                }, $images);
                $data['site_images'] = implode(',', $cleanImages);
            }
            
            header('Content-Type: application/json');
            echo json_encode($data);
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    // ===== BULK CREATE PRODUCTS =====
    if ($action == 'bulk_save_products') {
        try {
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }

            $products_json = isset($_POST['products']) ? $_POST['products'] : '';
            $products = json_decode($products_json, true);

            if (!$products || !is_array($products) || empty($products)) {
                throw new Exception('No product data received');
            }

            $success_count = 0;
            $failed_count = 0;
            $errors = [];
            $uploaded_files = isset($_FILES['site_images']) ? $_FILES['site_images'] : null;

            $conn->begin_transaction();

            foreach ($products as $index => $product_data) {
                try {
                    $rowLabel = 'Row ' . ($index + 1);
                    $product_name = trim($product_data['product_name'] ?? '');
                    if ($product_name === '') {
                        throw new Exception($rowLabel . ': Product name is required');
                    }

                    $site_category_id = !empty($product_data['site_category_id']) ? intval($product_data['site_category_id']) : null;
                    $subcategory_id = !empty($product_data['subcategory_id']) ? intval($product_data['subcategory_id']) : null;
                    $childcategory_id = !empty($product_data['childcategory_id']) ? intval($product_data['childcategory_id']) : null;
                    $brand_id = !empty($product_data['brand_id']) ? intval($product_data['brand_id']) : null;
                    $unit = !empty($product_data['unit']) ? trim($product_data['unit']) : null;
                    $purchase_price = isset($product_data['purchase_price']) ? max(0, floatval($product_data['purchase_price'])) : 0;
                    $site_price = isset($product_data['site_price']) ? max(0, floatval($product_data['site_price'])) : 0;
                    $site_old_price = isset($product_data['site_old_price']) && $product_data['site_old_price'] !== '' && $product_data['site_old_price'] !== null
                        ? max(0, floatval($product_data['site_old_price']))
                        : null;
                    $site_stock = isset($product_data['site_stock']) ? max(0, intval($product_data['site_stock'])) : 0;
                    $reorder_level = isset($product_data['reorder_level']) ? max(0, intval($product_data['reorder_level'])) : 0;
                    $site_description = sanitizeProductDescriptionHtml($product_data['site_description'] ?? '');

                    if ($site_price <= 0) {
                        throw new Exception($rowLabel . ': Valid selling price is required');
                    }

                    validateCategoryHierarchy($conn, $site_category_id, $subcategory_id, $childcategory_id, $rowLabel);

                    $site_images = '';
                    if ($uploaded_files && isset($uploaded_files['name'][$index]) && !empty($uploaded_files['name'][$index])) {
                        if ($uploaded_files['error'][$index] === UPLOAD_ERR_OK) {
                            $site_images = saveOptimizedProductImage([
                                'name' => $uploaded_files['name'][$index],
                                'tmp_name' => $uploaded_files['tmp_name'][$index],
                                'error' => $uploaded_files['error'][$index],
                                'size' => $uploaded_files['size'][$index],
                            ], $rowLabel . ' image');
                        }
                    }

                    $sql = "INSERT INTO site_products (
                        product_name, site_category_id, subcategory_id, childcategory_id, brand_id, unit,
                        purchase_price, site_price, site_old_price, site_stock, reorder_level,
                        site_description, site_images
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception($rowLabel . ': ' . $conn->error);
                    }

                    $stmt->bind_param(
                        'siiiisdddiiss',
                        $product_name, $site_category_id, $subcategory_id, $childcategory_id, $brand_id, $unit,
                        $purchase_price, $site_price, $site_old_price, $site_stock, $reorder_level,
                        $site_description, $site_images
                    );

                    if ($stmt->execute()) {
                        $success_count++;
                    } else {
                        throw new Exception($rowLabel . ': ' . $stmt->error);
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = $e->getMessage();
                }
            }

            $conn->commit();

            $message = "$success_count products added successfully";
            if ($failed_count > 0) {
                $message .= ", $failed_count failed.";
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'success_count' => $success_count,
                'failed_count' => $failed_count,
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // ===== CREATE OR UPDATE SINGLE PRODUCT =====
    if ($action == 'save_product') {
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
            $site_category_id = !empty($_POST['site_category_id']) ? intval($_POST['site_category_id']) : null;
            $subcategory_id = !empty($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : null;
            $childcategory_id = !empty($_POST['childcategory_id']) ? intval($_POST['childcategory_id']) : null;
            $brand_id = !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null;
            $unit = !empty($_POST['unit']) ? trim($_POST['unit']) : null;
            $purchase_price = isset($_POST['purchase_price']) ? max(0, floatval($_POST['purchase_price'])) : 0;
            $site_price = isset($_POST['site_price']) ? max(0, floatval($_POST['site_price'])) : 0;
            $site_old_price = isset($_POST['site_old_price']) && $_POST['site_old_price'] !== ''
                ? max(0, floatval($_POST['site_old_price']))
                : null;
            $site_stock = isset($_POST['site_stock']) ? max(0, intval($_POST['site_stock'])) : 0;
            $reorder_level = isset($_POST['reorder_level']) ? max(0, intval($_POST['reorder_level'])) : 0;
            $site_description = sanitizeProductDescriptionHtml($_POST['site_description'] ?? '');

            if ($product_name === '') {
                throw new Exception('Product name is required');
            }
            if ($site_price <= 0) {
                throw new Exception('Valid selling price is required');
            }

            validateCategoryHierarchy($conn, $site_category_id, $subcategory_id, $childcategory_id);

            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }

            $existing_images = [];
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT site_images FROM site_products WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_data = $result->fetch_assoc();
                $stmt->close();

                if (!empty($existing_data['site_images'])) {
                    $existing_images = explode(',', $existing_data['site_images']);
                    $existing_images = array_filter($existing_images, static function($img) {
                        return trim($img) !== '';
                    });
                    $existing_images = array_map(static function($img) {
                        return basename(trim($img));
                    }, $existing_images);
                }
            }

            $new_images = [];
            if (isset($_FILES['site_images']) && !empty($_FILES['site_images']['name'][0])) {
                foreach ($_FILES['site_images']['tmp_name'] as $key => $tmp_name) {
                    if (empty($tmp_name) || $_FILES['site_images']['error'][$key] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $new_images[] = saveOptimizedProductImage([
                        'name' => $_FILES['site_images']['name'][$key],
                        'tmp_name' => $tmp_name,
                        'error' => $_FILES['site_images']['error'][$key],
                        'size' => $_FILES['site_images']['size'][$key],
                    ], 'Product image ' . ($key + 1));
                }
            }

            $all_images = array_merge($existing_images, $new_images);
            $site_images = !empty($all_images) ? implode(',', $all_images) : '';

            if ($id > 0) {
                $sql = "UPDATE site_products SET
                        product_name = ?,
                        site_category_id = ?,
                        subcategory_id = ?,
                        childcategory_id = ?,
                        brand_id = ?,
                        unit = ?,
                        purchase_price = ?,
                        site_price = ?,
                        site_old_price = ?,
                        site_stock = ?,
                        reorder_level = ?,
                        site_description = ?,
                        site_images = ?
                        WHERE id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }

                $stmt->bind_param(
                    'siiiisdddiissi',
                    $product_name, $site_category_id, $subcategory_id, $childcategory_id, $brand_id, $unit,
                    $purchase_price, $site_price, $site_old_price, $site_stock, $reorder_level,
                    $site_description, $site_images, $id
                );

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
                } else {
                    throw new Exception('Update failed: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                $sql = "INSERT INTO site_products (
                        product_name, site_category_id, subcategory_id, childcategory_id, brand_id, unit,
                        purchase_price, site_price, site_old_price, site_stock, reorder_level,
                        site_description, site_images
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }

                $stmt->bind_param(
                    'siiiisdddiiss',
                    $product_name, $site_category_id, $subcategory_id, $childcategory_id, $brand_id, $unit,
                    $purchase_price, $site_price, $site_old_price, $site_stock, $reorder_level,
                    $site_description, $site_images
                );

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Product added successfully']);
                } else {
                    throw new Exception('Insert failed: ' . $stmt->error);
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ===== DELETE PRODUCT =====
    if ($action == 'delete_product') {
        try {
            $id = intval($_POST['id']);
            
            // Get images to delete
            $stmt = $conn->prepare("SELECT site_images FROM site_products WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            $stmt->close();
            
            // Delete images from server
            if (!empty($data['site_images'])) {
                $images = explode(',', $data['site_images']);
                foreach ($images as $img) {
                    $img = basename(trim($img));
                    $file_path = UPLOAD_PATH . $img;
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
            }
            
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM site_products WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
            } else {
                throw new Exception('Delete failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ===== UPDATE SECTION ASSIGNMENT =====
    if ($action == 'update_section') {
        try {
            $product_id = intval($_POST['product_id']);
            $section_type = $_POST['section_type'];
            $value = intval($_POST['value']);
            
            $column_map = [
                'featured' => 'is_featured',
                'new' => 'is_new',
                'top_selling' => 'is_top_selling',
                'status' => 'site_status',
                'visibility' => 'site_visible'
            ];
            
            if (!isset($column_map[$section_type])) {
                throw new Exception('Invalid section type');
            }
            
            $column = $column_map[$section_type];
            $sql = "UPDATE site_products SET $column = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $value, $product_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Section updated successfully']);
            } else {
                throw new Exception('Update failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ===== GET ALL PRODUCTS FOR SECTION MANAGEMENT =====
    if ($action == 'get_all_products_for_section') {
        try {
            $filter_type = isset($_POST['filter_type']) ? $_POST['filter_type'] : 'all';
            $site_category_id = isset($_POST['site_category_id']) ? intval($_POST['site_category_id']) : 0;
            $brand_id = isset($_POST['brand_id']) ? intval($_POST['brand_id']) : 0;
            
            $sql = "SELECT p.*, c.category_name, b.brand_name 
                    FROM site_products p
                    LEFT JOIN site_categories c ON p.site_category_id = c.id
                    LEFT JOIN brands b ON p.brand_id = b.id
                    WHERE 1=1";
            
            if ($filter_type != 'all' && in_array($filter_type, ['featured', 'new', 'top_selling'])) {
                $column_map = [
                    'featured' => 'is_featured',
                    'new' => 'is_new',
                    'top_selling' => 'is_top_selling'
                ];
                $sql .= " AND p." . $column_map[$filter_type] . " = 1";
            }
            
            if ($site_category_id > 0) {
                $sql .= " AND p.site_category_id = $site_category_id";
            }
            
            if ($brand_id > 0) {
                $sql .= " AND p.brand_id = $brand_id";
            }
            
            $sql .= " ORDER BY p.id DESC";
            
            $result = $conn->query($sql);
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // ===== BULK ASSIGN SECTION =====
    if ($action == 'bulk_assign_section') {
        try {
            $product_ids = json_decode($_POST['product_ids'], true);
            $section_type = $_POST['section_type'];
            $value = intval($_POST['value']);
            
            $column_map = [
                'featured' => 'is_featured',
                'new' => 'is_new',
                'top_selling' => 'is_top_selling'
            ];
            
            if (!isset($column_map[$section_type])) {
                throw new Exception('Invalid section type');
            }
            
            $column = $column_map[$section_type];
            $ids = array_map('intval', $product_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE site_products SET $column = ? WHERE id IN ($placeholders)";
            
            $stmt = $conn->prepare($sql);
            $types = 'i' . str_repeat('i', count($ids));
            $params = array_merge([$value], $ids);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => count($ids) . ' products updated successfully']);
            } else {
                throw new Exception('Update failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================================
    // SHIPPING CHARGE / DELIVERY METHODS
    // ============================================================
    if ($action === 'get_shipping_methods_management') {
        try {
            $methods = [];
            $stmt = $conn->prepare("SELECT id, shop_id, name, description, cost, free_above, minimum_order, estimated_days, sort_order, status
                FROM shipping_methods
                WHERE shop_id = 1 OR shop_id IS NULL
                ORDER BY sort_order ASC, id ASC");
            if (!$stmt) throw new Exception('Failed to prepare shipping methods query');
            if (!$stmt->execute()) throw new Exception('Failed to load shipping methods');
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['sort_order'] = (int)($row['sort_order'] ?? 0);
                $row['status'] = (int)($row['status'] ?? 0);
                $row['cost'] = (float)($row['cost'] ?? 0);
                $row['minimum_order'] = (float)($row['minimum_order'] ?? 0);
                $row['free_above'] = $row['free_above'] === null ? null : (float)$row['free_above'];
                $methods[] = $row;
            }
            $stmt->close();
            adminJsonResponse(['success' => true, 'data' => $methods]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_shipping_method') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $cost = max(0, (float)($_POST['cost'] ?? 0));
            $freeAboveInput = trim((string)($_POST['free_above'] ?? ''));
            $freeAbove = $freeAboveInput === '' ? '' : (string)max(0, (float)$freeAboveInput);
            $minimumOrder = max(0, (float)($_POST['minimum_order'] ?? 0));
            $estimatedDays = trim((string)($_POST['estimated_days'] ?? ''));
            $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));
            $status = !empty($_POST['status']) ? 1 : 0;

            if ($name === '') throw new Exception('Shipping method name is required');
            if (function_exists('mb_strlen') && mb_strlen($name) > 150) throw new Exception('Shipping method name is too long');

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE shipping_methods
                    SET shop_id=1, name=?, description=?, cost=?, free_above=NULLIF(?, ''), minimum_order=?, estimated_days=?, sort_order=?, status=?
                    WHERE id=? AND (shop_id=1 OR shop_id IS NULL)");
                if (!$stmt) throw new Exception('Failed to prepare shipping method update');
                $stmt->bind_param('ssdsdsiii', $name, $description, $cost, $freeAbove, $minimumOrder, $estimatedDays, $sortOrder, $status, $id);
                if (!$stmt->execute()) throw new Exception('Failed to update shipping method: ' . $stmt->error);
                if ($stmt->affected_rows === 0) {
                    $check = $conn->prepare("SELECT id FROM shipping_methods WHERE id=? AND (shop_id=1 OR shop_id IS NULL) LIMIT 1");
                    if (!$check) throw new Exception('Failed to verify shipping method');
                    $check->bind_param('i', $id);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();
                    if (!$exists) throw new Exception('Shipping method not found');
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO shipping_methods
                    (shop_id, name, description, cost, free_above, minimum_order, estimated_days, sort_order, status)
                    VALUES (1, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?)");
                if (!$stmt) throw new Exception('Failed to prepare shipping method insert');
                $stmt->bind_param('ssdsdsii', $name, $description, $cost, $freeAbove, $minimumOrder, $estimatedDays, $sortOrder, $status);
                if (!$stmt->execute()) throw new Exception('Failed to add shipping method: ' . $stmt->error);
                $stmt->close();
            }

            adminJsonResponse(['success' => true, 'message' => 'Shipping charge saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_shipping_method') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid shipping method');

            $usageCount = 0;
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE shipping_method_id=?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $usageCount = (int)(($stmt->get_result()->fetch_assoc()['total'] ?? 0));
                $stmt->close();
            }
            if ($usageCount > 0) {
                throw new Exception('This shipping method is already used by ' . $usageCount . ' order(s). Set it Inactive instead of deleting.');
            }

            $stmt = $conn->prepare("DELETE FROM shipping_methods WHERE id=? AND (shop_id=1 OR shop_id IS NULL)");
            if (!$stmt) throw new Exception('Failed to prepare shipping method delete');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete shipping method: ' . $stmt->error);
            if ($stmt->affected_rows < 1) throw new Exception('Shipping method not found');
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Shipping method deleted successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // FOOTER SETTINGS
    // ============================================================
    if ($action === 'get_footer_settings') {
        try {
            $result = $conn->query("SELECT * FROM site_footer_settings WHERE id = 1 LIMIT 1");
            $row = $result ? ($result->fetch_assoc() ?: []) : [];
            $links = [];
            if (!empty($row['information_links'])) {
                $decoded = json_decode($row['information_links'], true);
                if (is_array($decoded)) $links = $decoded;
            }
            $row['information_links_array'] = $links;
            $row['logo_url'] = getPublicAssetUrl($row['logo_image'] ?? '');
            adminJsonResponse(['success' => true, 'data' => $row]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_footer_settings') {
        try {
            $existing = [];
            $res = $conn->query("SELECT * FROM site_footer_settings WHERE id = 1 LIMIT 1");
            if ($res) $existing = $res->fetch_assoc() ?: [];

            $currentLogo = (string)($existing['logo_image'] ?? '');
            if (!empty($_POST['remove_logo'])) {
                deleteAdminManagedUpload($currentLogo);
                $currentLogo = '';
            }
            $logo = handleAdminImageUpload('footer_logo', 'footer', $currentLogo);
            $brandName = trim((string)($_POST['brand_name'] ?? 'Ricevaly'));
            $tagline = trim((string)($_POST['tagline'] ?? ''));
            $aboutText = trim((string)($_POST['about_text'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $informationTitle = trim((string)($_POST['information_title'] ?? 'Information'));
            $informationLinks = normalizeInformationLinks($_POST['information_links'] ?? '[]');
            $status = !empty($_POST['status']) ? 1 : 0;

            if ($brandName === '') throw new Exception('Brand name is required');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');

            $stmt = $conn->prepare("INSERT INTO site_footer_settings
                (id, logo_image, brand_name, tagline, about_text, address, phone, email, information_title, information_links, status)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  logo_image=VALUES(logo_image), brand_name=VALUES(brand_name), tagline=VALUES(tagline),
                  about_text=VALUES(about_text), address=VALUES(address), phone=VALUES(phone), email=VALUES(email),
                  information_title=VALUES(information_title), information_links=VALUES(information_links), status=VALUES(status)");
            if (!$stmt) throw new Exception('Failed to prepare footer settings save');
            $stmt->bind_param('sssssssssi', $logo, $brandName, $tagline, $aboutText, $address, $phone, $email, $informationTitle, $informationLinks, $status);
            if (!$stmt->execute()) throw new Exception('Failed to save footer settings: ' . $stmt->error);
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Footer settings saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // PARTNERS + PARTNER SECTION SETTINGS
    // ============================================================
    if ($action === 'get_partners_management') {
        try {
            $section = [];
            $stmt = $conn->prepare("SELECT section_key, eyebrow_text, title, subtitle, status FROM site_section_settings WHERE section_key = 'partners' LIMIT 1");
            if ($stmt && $stmt->execute()) $section = $stmt->get_result()->fetch_assoc() ?: [];
            if ($stmt) $stmt->close();

            $partners = [];
            $result = $conn->query("SELECT id, partner_name, logo_image, website_url, sort_order, status FROM site_partners ORDER BY sort_order ASC, id ASC");
            while ($result && $row = $result->fetch_assoc()) {
                $row['logo_url'] = getPublicAssetUrl($row['logo_image'] ?? '');
                $partners[] = $row;
            }
            adminJsonResponse(['success' => true, 'section' => $section, 'partners' => $partners]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_partner_section') {
        try {
            $eyebrow = trim((string)($_POST['eyebrow_text'] ?? 'OUR PARTNERS'));
            $title = trim((string)($_POST['title'] ?? 'আমাদের পার্টনার'));
            $subtitle = trim((string)($_POST['subtitle'] ?? ''));
            $status = !empty($_POST['status']) ? 1 : 0;
            if ($title === '') throw new Exception('Section title is required');
            $stmt = $conn->prepare("INSERT INTO site_section_settings (section_key, eyebrow_text, title, subtitle, status)
                VALUES ('partners', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE eyebrow_text=VALUES(eyebrow_text), title=VALUES(title), subtitle=VALUES(subtitle), status=VALUES(status)");
            if (!$stmt) throw new Exception('Failed to prepare partner section save');
            $stmt->bind_param('sssi', $eyebrow, $title, $subtitle, $status);
            if (!$stmt->execute()) throw new Exception('Failed to save partner section: ' . $stmt->error);
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Partner section settings saved']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'save_partner') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $name = trim((string)($_POST['partner_name'] ?? ''));
            $url = normalizeAdminLinkUrl($_POST['website_url'] ?? '', true);
            $sort = max(0, (int)($_POST['sort_order'] ?? 0));
            $status = !empty($_POST['status']) ? 1 : 0;
            if ($name === '') throw new Exception('Partner name is required');

            $currentLogo = '';
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT logo_image FROM site_partners WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id); $stmt->execute();
                $currentLogo = (string)(($stmt->get_result()->fetch_assoc()['logo_image'] ?? ''));
                $stmt->close();
            }
            if (!empty($_POST['remove_logo'])) {
                deleteAdminManagedUpload($currentLogo); $currentLogo = '';
            }
            $logo = handleAdminImageUpload('partner_logo', 'partners', $currentLogo);

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE site_partners SET partner_name=?, logo_image=?, website_url=?, sort_order=?, status=? WHERE id=?");
                $stmt->bind_param('sssiii', $name, $logo, $url, $sort, $status, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO site_partners (partner_name, logo_image, website_url, sort_order, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sssii', $name, $logo, $url, $sort, $status);
            }
            if (!$stmt->execute()) throw new Exception('Failed to save partner: ' . $stmt->error);
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Partner saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_partner') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid partner');
            $stmt = $conn->prepare("SELECT logo_image FROM site_partners WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
            $stmt = $conn->prepare("DELETE FROM site_partners WHERE id=?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete partner');
            $stmt->close();
            deleteAdminManagedUpload($row['logo_image'] ?? '');
            adminJsonResponse(['success' => true, 'message' => 'Partner deleted']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // CUSTOMER LOVE / SITE REVIEWS
    // ============================================================
    if ($action === 'get_site_reviews') {
        try {
            $status = trim((string)($_POST['status'] ?? 'all'));
            $sql = "SELECT r.id, r.customer_id, r.rating, r.review, r.status, r.is_verified_purchase, r.created_at,
                           u.name AS customer_name, u.email AS customer_email
                    FROM reviews r
                    LEFT JOIN users u ON u.id = r.customer_id
                    WHERE r.review_type = 'site'";
            $params = []; $types = '';
            if (in_array($status, ['pending','approved','rejected'], true)) {
                $sql .= " AND r.status = ?"; $params[] = $status; $types .= 's';
            }
            $sql .= " ORDER BY r.created_at DESC, r.id DESC LIMIT 200";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Failed to prepare reviews query');
            if ($params) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $stmt->close();
            adminJsonResponse(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'update_site_review_status') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $status = trim((string)($_POST['status'] ?? ''));
            if ($id <= 0 || !in_array($status, ['pending','approved','rejected'], true)) throw new Exception('Invalid review update');
            $stmt = $conn->prepare("UPDATE reviews SET status=? WHERE id=? AND review_type='site'");
            $stmt->bind_param('si', $status, $id);
            if (!$stmt->execute()) throw new Exception('Failed to update review');
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Review status updated']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_site_review') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid review');
            $stmt = $conn->prepare("DELETE FROM reviews WHERE id=? AND review_type='site'");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete review');
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Review deleted']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // BREAKING NEWS
    // ============================================================
    if ($action === 'get_breaking_news_management') {
        try {
            $rows = [];
            $result = $conn->query("SELECT id, title, sort_order, start_at, end_at, status
                                    FROM breaking_news
                                    ORDER BY sort_order ASC, id DESC");
            while ($result && $row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            adminJsonResponse(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_breaking_news') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $sliderType = (($_POST['slider_type'] ?? 'content') === 'full_image') ? 'full_image' : 'content';
            $title = trim((string)($_POST['title'] ?? ''));
            $sort = max(0, (int)($_POST['sort_order'] ?? 0));
            $startAt = normalizeAdminDateTime($_POST['start_at'] ?? '');
            $endAt = normalizeAdminDateTime($_POST['end_at'] ?? '');
            $status = !empty($_POST['status']) ? 1 : 0;

            if ($title === '') throw new Exception('Breaking news title is required');
            if (mb_strlen($title, 'UTF-8') > 255) throw new Exception('Breaking news title is too long');
            if ($startAt && $endAt && strtotime($endAt) < strtotime($startAt)) {
                throw new Exception('End time cannot be before start time');
            }

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE breaking_news
                                        SET title=?, sort_order=?, start_at=?, end_at=?, status=?
                                        WHERE id=?");
                if (!$stmt) throw new Exception('Failed to prepare breaking news update');
                $stmt->bind_param('sissii', $title, $sort, $startAt, $endAt, $status, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO breaking_news (title, sort_order, start_at, end_at, status)
                                        VALUES (?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception('Failed to prepare breaking news insert');
                $stmt->bind_param('sissi', $title, $sort, $startAt, $endAt, $status);
            }
            if (!$stmt->execute()) throw new Exception('Failed to save breaking news: ' . $stmt->error);
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Breaking news saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_breaking_news') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid breaking news item');
            $stmt = $conn->prepare("DELETE FROM breaking_news WHERE id=?");
            if (!$stmt) throw new Exception('Failed to prepare breaking news delete');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete breaking news');
            if ($stmt->affected_rows < 1) throw new Exception('Breaking news item not found');
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Breaking news deleted']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // HERO SLIDER
    // ============================================================
    if ($action === 'get_hero_sliders') {
        try {
            $rows = [];
            $result = $conn->query("SELECT id, title, subtitle, description, image, mobile_image, button_text, button_url, slider_type,
                                           background_start_color, background_end_color, sort_order, start_at, end_at, status
                                    FROM hero_sliders WHERE shop_id=1 ORDER BY sort_order ASC, id DESC");
            while ($result && $row = $result->fetch_assoc()) {
                $row['image_url'] = getPublicAssetUrl($row['image'] ?? '');
                $row['mobile_image_url'] = getPublicAssetUrl($row['mobile_image'] ?? '');
                $rows[] = $row;
            }
            adminJsonResponse(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_hero_slider') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
			$sliderType = (($_POST['slider_type'] ?? 'content') === 'full_image') 
    ? 'full_image' 
    : 'content';
            $subtitle = trim((string)($_POST['subtitle'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $buttonText = trim((string)($_POST['button_text'] ?? ''));
            $buttonUrl = normalizeAdminLinkUrl($_POST['button_url'] ?? '', true);
            $bgStart = normalizeAdminHexColor($_POST['background_start_color'] ?? '', '#b3e4e6');
            $bgEnd = normalizeAdminHexColor($_POST['background_end_color'] ?? '', '#b5df99');
            $sort = max(0, (int)($_POST['sort_order'] ?? 0));
            $startAt = normalizeAdminDateTime($_POST['start_at'] ?? '');
            $endAt = normalizeAdminDateTime($_POST['end_at'] ?? '');
            $status = !empty($_POST['status']) ? 1 : 0;
            if ($sliderType === 'content' && $title === '') throw new Exception('Hero title is required');
            if ($startAt && $endAt && strtotime($endAt) < strtotime($startAt)) throw new Exception('End time cannot be before start time');

            $currentImage = ''; $currentMobile = '';
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT image, mobile_image FROM hero_sliders WHERE id=? AND shop_id=1 LIMIT 1");
                $stmt->bind_param('i', $id); $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc() ?: [];
                $currentImage = (string)($old['image'] ?? ''); $currentMobile = (string)($old['mobile_image'] ?? '');
                $stmt->close();
            }
            if (!empty($_POST['remove_image'])) { deleteAdminManagedUpload($currentImage); $currentImage=''; }
            if (!empty($_POST['remove_mobile_image'])) { deleteAdminManagedUpload($currentMobile); $currentMobile=''; }
            $image = handleAdminImageUpload('hero_image', 'hero', $currentImage);
            $mobileImage = handleAdminImageUpload('hero_mobile_image', 'hero', $currentMobile);

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE hero_sliders SET title=?, subtitle=?, description=?, image=?, mobile_image=?, button_text=?, button_url=?, slider_type=?,
                    background_start_color=?, background_end_color=?, sort_order=?, start_at=?, end_at=?, status=? WHERE id=? AND shop_id=1");
                $stmt->bind_param('ssssssssssissii', $title, $subtitle, $description, $image, $mobileImage, $buttonText, $buttonUrl, $sliderType, $bgStart, $bgEnd, $sort, $startAt, $endAt, $status, $id);
            } else {
                $shopId = 1;
                $stmt = $conn->prepare("INSERT INTO hero_sliders (shop_id, title, subtitle, description, image, mobile_image, button_text, button_url, slider_type,
                    background_start_color, background_end_color, sort_order, start_at, end_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssssssssissi', $shopId, $title, $subtitle, $description, $image, $mobileImage, $buttonText, $buttonUrl, $sliderType, $bgStart, $bgEnd, $sort, $startAt, $endAt, $status);
            }
            if (!$stmt || !$stmt->execute()) throw new Exception('Failed to save hero slider' . ($stmt ? ': '.$stmt->error : ''));
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Hero slider saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_hero_slider') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid hero slider');
            $stmt = $conn->prepare("SELECT image, mobile_image FROM hero_sliders WHERE id=? AND shop_id=1 LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute(); $old = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
            $stmt = $conn->prepare("DELETE FROM hero_sliders WHERE id=? AND shop_id=1");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete hero slider');
            $stmt->close();
            deleteAdminManagedUpload($old['image'] ?? ''); deleteAdminManagedUpload($old['mobile_image'] ?? '');
            adminJsonResponse(['success' => true, 'message' => 'Hero slider deleted']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // OFFER SLIDER MANAGEMENT
    // ============================================================
    if ($action === 'get_offer_banners') {
        try {
            $rows = [];
            $result = $conn->query("SELECT id, eyebrow_text, title, highlight_text, image, button_text, button_url,
                                           background_start_color, background_end_color, sort_order, start_at, end_at, status,
                                           CASE
                                               WHEN status = 1
                                                AND (start_at IS NULL OR start_at <= NOW())
                                                AND (end_at IS NULL OR end_at >= NOW())
                                               THEN 1 ELSE 0
                                           END AS is_live
                                    FROM site_offer_banners ORDER BY sort_order ASC, id DESC");
            while ($result && $row = $result->fetch_assoc()) {
                $row['image_url'] = getPublicAssetUrl($row['image'] ?? '');
                $rows[] = $row;
            }
            adminJsonResponse(['success' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($action === 'save_offer_banner') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $buttonUrl = normalizeAdminLinkUrl($_POST['button_url'] ?? '', false);

            $currentImage = '';
            $currentSort = 0;
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT image, sort_order FROM site_offer_banners WHERE id=? LIMIT 1");
                if (!$stmt) throw new Exception('Unable to load offer slide');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$current) throw new Exception('Offer slide not found');
                $currentImage = (string)($current['image'] ?? '');
                $currentSort = max(0, (int)($current['sort_order'] ?? 0));
            }

            $image = handleAdminImageUpload('offer_image', 'offers', $currentImage);
            if ($image === '') throw new Exception('Full slide image is required');

            if ($id > 0) {
                // Offer slider is intentionally image-only: the complete image is the slide and the entire slide is clickable.
                $stmt = $conn->prepare("UPDATE site_offer_banners
                    SET eyebrow_text='', title='', highlight_text='', image=?, button_text='', button_url=?,
                        background_start_color='#ffffff', background_end_color='#ffffff',
                        start_at=NULL, end_at=NULL, status=1, sort_order=?
                    WHERE id=?");
                if (!$stmt) throw new Exception('Failed to prepare offer slide update');
                $stmt->bind_param('ssii', $image, $buttonUrl, $currentSort, $id);
            } else {
                $nextSort = 0;
                $sortResult = $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM site_offer_banners");
                if ($sortResult && ($sortRow = $sortResult->fetch_assoc())) $nextSort = max(0, (int)$sortRow['next_sort']);

                $stmt = $conn->prepare("INSERT INTO site_offer_banners
                    (eyebrow_text, title, highlight_text, image, button_text, button_url,
                     background_start_color, background_end_color, sort_order, start_at, end_at, status)
                    VALUES ('', '', '', ?, '', ?, '#ffffff', '#ffffff', ?, NULL, NULL, 1)");
                if (!$stmt) throw new Exception('Failed to prepare offer slide insert');
                $stmt->bind_param('ssi', $image, $buttonUrl, $nextSort);
            }

            if (!$stmt->execute()) throw new Exception('Failed to save offer slide: ' . $stmt->error);
            $stmt->close();
            adminJsonResponse(['success' => true, 'message' => 'Full image offer slide saved successfully']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete_offer_banner') {
        try {
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($id <= 0) throw new Exception('Invalid offer banner');
            $stmt = $conn->prepare("SELECT image FROM site_offer_banners WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute(); $old=$stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
            $stmt = $conn->prepare("DELETE FROM site_offer_banners WHERE id=?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Failed to delete offer banner');
            $stmt->close();
            deleteAdminManagedUpload($old['image'] ?? '');
            adminJsonResponse(['success' => true, 'message' => 'Offer slide deleted']);
        } catch (Throwable $e) {
            adminJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

}

// Fetch all site products with proper image paths
$products_query = "SELECT * FROM site_products ORDER BY id DESC";
$products_result = $conn->query($products_query);

if (!$products_result) {
    die("Query failed: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Site Products — Rice Valy Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0a5b4f">
<meta name="apple-mobile-web-app-capable" content="yes">

<link rel="shortcut icon" href="https://www.ricevaly.com/public/uploads/settings/1759768409-whatsapp-image-2025-10-06-at-8.13.11-pm.webp">

<!-- Bootstrap 5.3 + Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
  --rv-primary:#0a5b4f;
  --rv-primary-2:#0d7a67;
  --rv-accent:#f48721;
  --rv-bg:#f4f6f8;
  --rv-sidebar:#0a5b4f;
  --rv-sidebar-hover:#0d7a67;
  --rv-text:#1f2937;
  --rv-muted:#6b7280;
  --rv-border:#e5e7eb;
  --rv-radius:12px;
  --rv-shadow:0 2px 10px rgba(15,40,35,.06);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;background:var(--rv-bg);color:var(--rv-text);
  font-family:Inter,"Noto Sans Bengali",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  font-size:14px;
}
.rv-layout{display:flex;min-height:100vh}
.rv-sidebar{
  width:250px;flex-shrink:0;background:var(--rv-sidebar);color:#d1fae5;
  position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:1040;
  transition:transform .25s ease;
}
.rv-sidebar::-webkit-scrollbar{width:6px}
.rv-sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:6px}
.rv-sidebar .rv-brand{display:flex;align-items:center;gap:10px;padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.08)}
.rv-sidebar .rv-brand img{height:36px;background:#fff;border-radius:8px;padding:3px}
.rv-sidebar .rv-brand span{font-weight:700;color:#fff;font-size:15px;letter-spacing:.2px}
.rv-sidebar .rv-user{padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:12px}
.rv-sidebar .rv-user img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.3)}
.rv-sidebar .rv-user .name{color:#fff;font-weight:600;font-size:14px}
.rv-sidebar .rv-user .role{color:#a7f3d0;font-size:12px}
.rv-nav{padding:8px 0;list-style:none;margin:0}
.rv-nav .rv-section{padding:14px 20px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#6ee7b7;opacity:.75}
.rv-nav a{
  display:flex;align-items:center;gap:10px;padding:10px 20px;color:#d1fae5;
  text-decoration:none;font-size:14px;border-left:3px solid transparent;transition:.15s;
}
.rv-nav a:hover{background:var(--rv-sidebar-hover);color:#fff}
.rv-nav a.active{background:var(--rv-sidebar-hover);color:#fff;border-left-color:var(--rv-accent);font-weight:600}
.rv-nav a i{font-size:16px;width:20px;text-align:center}
.rv-nav .sub a{padding-left:52px;font-size:13px}
.rv-main{flex:1;margin-left:250px;display:flex;flex-direction:column;min-width:0}
.rv-topbar{
  background:#fff;border-bottom:1px solid var(--rv-border);padding:12px 24px;
  display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:1030;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.rv-topbar .rv-toggle{display:none;background:none;border:none;font-size:22px;color:var(--rv-primary);cursor:pointer}
.rv-topbar h5{margin:0;font-weight:700;color:var(--rv-primary);font-size:16px}
.rv-topbar .rv-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.rv-topbar .rv-actions img{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--rv-border)}
.rv-content{padding:24px;flex:1}
.rv-page-title{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.rv-page-title h4{margin:0;font-weight:700;font-size:20px}
.rv-page-title .rv-toolbar{display:flex;flex-wrap:wrap;gap:8px}
.card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow)}
.card-header{background:#fafbfc;border-bottom:1px solid var(--rv-border);font-weight:600}
.table{background:#fff;margin-bottom:0}
.table thead th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);font-weight:600;border-bottom:1px solid var(--rv-border)}
.table td,.table th{vertical-align:middle;padding:.7rem .75rem}
.rv-thumb{width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--rv-border);background:#fff}
.rv-thumb-lg{width:64px;height:48px;object-fit:contain;border-radius:6px;border:1px solid var(--rv-border);background:#fff}
.btn{border-radius:8px;font-weight:500}
.btn-primary{background:var(--rv-primary);border-color:var(--rv-primary)}
.btn-primary:hover{background:var(--rv-primary-2);border-color:var(--rv-primary-2)}
.btn-xs{padding:2px 8px;font-size:12px;line-height:1.4;border-radius:6px}
.badge-status{font-size:11px;padding:4px 9px;border-radius:20px}
.section-toggle-btn{
  padding:3px 10px;border-radius:20px;border:1px solid transparent;
  font-size:11px;font-weight:500;transition:.15s;cursor:pointer;
  background:#f1f5f9;color:#64748b;
}
.section-toggle-btn:hover{transform:translateY(-1px)}
.section-toggle-btn.active-warning{background:#fef3c7;color:#b45309;border-color:#fde68a}
.section-toggle-btn.active-info{background:#e0f2fe;color:#0369a1;border-color:#bae6fd}
.section-toggle-btn.active-success{background:#dcfce7;color:#15803d;border-color:#bbf7d0}
.section-toggle-btn.active-primary{background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe}
.section-toggle-btn.active-danger{background:#fee2e2;color:#b91c1c;border-color:#fecaca}
.dropzone-area{
  border:2px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;
  cursor:pointer;transition:.2s;background:#fafbfc;
}
.dropzone-area:hover{border-color:var(--rv-primary);background:#f0fdfa}
.dropzone-area i{font-size:32px;color:var(--rv-primary);display:block;margin-bottom:8px}
.image-preview-container{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.image-preview-item{position:relative;width:80px;height:80px;border:1px solid var(--rv-border);border-radius:8px;overflow:hidden;background:#f8fafc}
.image-preview-item img{width:100%;height:100%;object-fit:cover}
.image-preview-item .remove-img{
  position:absolute;top:3px;right:3px;background:#ef4444;color:#fff;border:none;
  border-radius:50%;width:20px;height:20px;font-size:13px;line-height:1;cursor:pointer;
}
.bulk-product-row{position:relative;background:#f8fafc;border:1px solid var(--rv-border);border-radius:10px;padding:16px;margin-bottom:12px}
.bulk-product-row .remove-row{position:absolute;top:8px;right:10px;background:none;border:none;color:#ef4444;font-size:20px;cursor:pointer}
.rv-rich-editor{border:1px solid var(--rv-border);border-radius:10px;background:#fff;overflow:hidden}
.rv-rich-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:4px;padding:6px;border-bottom:1px solid var(--rv-border);background:#f8fafc}
.rv-rich-toolbar .btn{min-width:30px;padding:3px 7px;font-size:12px;background:#fff;border:1px solid var(--rv-border);color:#334155}
.rv-rich-toolbar .btn:hover,.rv-rich-toolbar .btn.active{background:#e2e8f0}
.rv-rich-toolbar .form-select{font-size:12px;padding:2px 6px;height:28px}
.rv-rich-body{min-height:140px;max-height:340px;overflow-y:auto;padding:12px;outline:none;line-height:1.6;font-size:15px}
.rv-rich-body:empty:before{content:attr(data-placeholder);color:#94a3b8;pointer-events:none}
.rv-rich-body table{width:100%;border-collapse:collapse;margin:8px 0}
.rv-rich-body th,.rv-rich-body td{border:1px solid #cbd5e1;padding:5px 7px}
.rv-rich-editor.rv-editor-fullscreen{position:fixed;inset:15px;z-index:20000;display:flex;flex-direction:column;box-shadow:0 8px 35px rgba(0,0,0,.25)}
.rv-rich-editor.rv-editor-fullscreen .rv-rich-body{flex:1;max-height:none}
.rv-color-wrap{position:relative;display:inline-flex}
.rv-color-wrap .rv-color-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.admin-offer-preview{position:relative;width:100%;aspect-ratio:16/7;min-height:260px;overflow:hidden;border-radius:12px;border:1px solid var(--rv-border);background:#111}
.admin-offer-preview-slide{position:absolute;inset:0;opacity:0;transform:translateX(18px);pointer-events:none;transition:.35s}
.admin-offer-preview-slide.active{opacity:1;transform:none;pointer-events:auto}
.admin-offer-preview-link{display:block;width:100%;height:100%}
.admin-offer-preview-link img{width:100%;height:100%;object-fit:cover;display:block}
.admin-offer-preview-arrow{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;border-radius:50%;background:rgba(255,255,255,.94);color:var(--rv-primary);font-size:22px;z-index:5}
.admin-offer-preview-arrow.prev{left:14px}
.admin-offer-preview-arrow.next{right:14px}
.admin-offer-preview-dots{position:absolute;left:50%;bottom:14px;transform:translateX(-50%);display:flex;gap:7px;z-index:6}
.admin-offer-preview-dots button{width:9px;height:9px;border:0;border-radius:50%;background:rgba(255,255,255,.78)}
.admin-offer-preview-dots button.active{background:var(--rv-accent);transform:scale(1.2)}
.admin-offer-preview-empty{min-height:260px;display:grid;place-items:center;text-align:center;color:#94a3b8}
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:20000;display:flex;align-items:center;justify-content:center}
.loading-overlay .spinner-border{width:3rem;height:3rem;color:var(--rv-primary)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
.modal-content{border-radius:14px;border:0;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-header{border-bottom:1px solid var(--rv-border);padding:14px 20px}
.modal-title{font-weight:700;font-size:16px;color:var(--rv-primary)}
.modal-footer{border-top:1px solid var(--rv-border);padding:12px 20px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
}
</style>
</head>
<body>

<div class="rv-layout">

  <!-- Sidebar -->
  <aside class="rv-sidebar" id="rvSidebar">
    <div class="rv-brand">
      <img src="https://www.ricevaly.com/public/uploads/settings/1759901568-whatsapp-image-2025-10-07-at-10.42.40-pm.webp" alt="logo">
      <span>Rice Valy Admin</span>
    </div>
    <div class="rv-user">
      <img src="https://www.ricevaly.com/public/backEnd/assets/images/users/user-1.jpg" alt="user">
      <div>
        <div class="name">Admin</div>
        <div class="role">Admin Head</div>
      </div>
    </div>

    <ul class="rv-nav">
      <li class="rv-section">Main</li>
      <li><a href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>

      <li class="rv-section">Orders</li>
      <li>
        <a data-bs-toggle="collapse" href="#navOrders" role="button" aria-expanded="false">
          <i class="bi bi-cart3"></i> Orders <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse sub" id="navOrders">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=all&source=online"><i class="bi bi-list-ul"></i> All Orders</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=pending&source=online"><i class="bi bi-clock"></i> Pending</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=processing&source=online"><i class="bi bi-gear"></i> Processing</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=on-the-way&source=online"><i class="bi bi-truck"></i> On The Way</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=on-hold&source=online"><i class="bi bi-pause-circle"></i> On Hold</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=in-courier&source=online"><i class="bi bi-box-seam"></i> In Courier</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=completed&source=online"><i class="bi bi-check2-circle"></i> Completed</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=cancelled&source=online"><i class="bi bi-x-circle"></i> Cancelled</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Products</li>
      <li>
        <a data-bs-toggle="collapse" href="#navProducts" role="button" aria-expanded="true" class="active">
          <i class="bi bi-box"></i> Products <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse show sub" id="navProducts">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-pencil-square"></i> Product Manage</a></li>
            <li><a href="javascript:void(0)" id="siteProductsMenu"><i class="bi bi-box-seam"></i> Site Products</a></li>
            <li><a href="javascript:void(0)" id="categorySetupMenu"><i class="bi bi-diagram-3"></i> Category Setup</a></li>
            <li><a href="javascript:void(0)" id="sectionManagementMenu"><i class="bi bi-grid-3x3-gap"></i> Section Management</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Users</li>
      <li>
        <a data-bs-toggle="collapse" href="#navUsers" role="button" aria-expanded="false">
          <i class="bi bi-people"></i> Users <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse sub" id="navUsers">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/registered_users.php"><i class="bi bi-person-lines-fill"></i> Registered Users</a></li>
            <li><a href="referral_management.php"><i class="bi bi-gift"></i> Credit &amp; Referral</a></li>
            <li><a href="https://ricevaly.bd/pos/site/online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>
            <li><a href="javascript:void(0)" id="customerReviewsMenu"><i class="bi bi-chat-dots"></i> Customer Reviews</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Marketing</li>
      <li><a href="customer_dashboard_banners.php"><i class="bi bi-image"></i> Dashboard Banner</a></li>

      <li class="rv-section">Site Setting</li>
      <li><a href="javascript:void(0)" id="shippingChargeMenu"><i class="bi bi-truck"></i> Shipping Charge</a></li>
      <li><a href="javascript:void(0)" id="footerSettingsMenu"><i class="bi bi-layout-text-window"></i> Footer Settings</a></li>
      <li><a href="javascript:void(0)" id="partnersMenu"><i class="bi bi-briefcase"></i> Partners</a></li>

      <li class="rv-section">Banner &amp; Ads</li>
      <li><a href="javascript:void(0)" id="breakingNewsMenu"><i class="bi bi-broadcast"></i> Breaking News</a></li>
      <li><a href="javascript:void(0)" id="heroSliderMenu"><i class="bi bi-images"></i> Hero Slider</a></li>
      <li><a href="javascript:void(0)" id="offerBannerMenu"><i class="bi bi-tag"></i> Offer Slider</a></li>

      <li class="rv-section">Reports</li>
      <li><a href="today_online_overview.php"><i class="bi bi-activity"></i> Today Online Overview</a></li>
      <li><a href="https://ricevaly.bd/pos/site/online_business_report.php"><i class="bi bi-bar-chart"></i> Online Business Report</a></li>
    </ul>
  </aside>

  <div class="rv-backdrop" id="rvBackdrop"></div>

  <!-- Main -->
  <div class="rv-main">
    <header class="rv-topbar">
      <button class="rv-toggle" id="rvToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <h5>Site Products Management</h5>
      <div class="rv-actions">
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <img src="https://www.ricevaly.com/public/uploads/users/1707537536-faveicon.webp" alt="user">
            <span class="ms-2 d-none d-sm-inline fw-semibold">Admin</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header">Welcome, Admin</h6></li>
            <li><a class="dropdown-item" href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
            <li><a class="dropdown-item" href="referral_management.php"><i class="bi bi-gift me-2"></i> Credit &amp; Referral</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="document.getElementById('logout-form').submit();return false;"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="rv-content">
      <div class="rv-page-title">
        <h4>Site Products</h4>
        <div class="rv-toolbar">
          <button class="btn btn-primary btn-sm" id="addNewBtn"><i class="bi bi-plus-lg"></i> Add New</button>
          <button class="btn btn-info btn-sm text-white" id="bulkUploadBtn"><i class="bi bi-cloud-arrow-up"></i> Bulk Upload</button>
          <button class="btn btn-outline-success btn-sm" id="optimizeExistingImagesBtn" type="button" title="Optimize existing images"><i class="bi bi-magic"></i> Optimize Images</button>
          <button class="btn btn-warning btn-sm" id="siteCategorySetupBtn"><i class="bi bi-diagram-3"></i> Category Setup</button>
          <button class="btn btn-success btn-sm" id="sectionManagementBtn"><i class="bi bi-grid-3x3-gap"></i> Section Management</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0" id="productsTable">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Product</th>
                  <th>Price</th>
                  <th>Stock</th>
                  <th>Sections</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($products_result && $products_result->num_rows > 0): ?>
                <?php while ($row = $products_result->fetch_assoc()): ?>
                  <tr id="row_<?php echo $row['id']; ?>">
                    <td><?php echo $row['id']; ?></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?php
                          if (!empty($row['site_images'])) {
                            $images = explode(',', $row['site_images']);
                            $firstImage = basename(trim($images[0]));
                            if (!empty($firstImage)):
                        ?>
                          <img src="<?php echo getImageUrl($firstImage); ?>" class="rv-thumb" alt="">
                        <?php endif; } ?>
                        <strong><?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?></strong>
                      </div>
                    </td>
                    <td>৳ <?php echo number_format((float)$row['site_price'], 2); ?></td>
                    <td><?php echo (int)$row['site_stock']; ?></td>
                    <td>
                      <div class="d-flex flex-wrap gap-1 mb-1">
                        <button class="section-toggle-btn <?php echo $row['is_featured'] ? 'active-warning' : ''; ?>" data-id="<?php echo $row['id']; ?>" data-section="featured">⭐ Featured</button>
                        <button class="section-toggle-btn <?php echo $row['is_new'] ? 'active-info' : ''; ?>" data-id="<?php echo $row['id']; ?>" data-section="new">🆕 New</button>
                        <button class="section-toggle-btn <?php echo $row['is_top_selling'] ? 'active-success' : ''; ?>" data-id="<?php echo $row['id']; ?>" data-section="top_selling">🔥 Top</button>
                      </div>
                      <div class="d-flex flex-wrap gap-1">
                        <button class="section-toggle-btn status-toggle <?php echo $row['site_status'] ? 'active-success' : 'active-danger'; ?>" data-id="<?php echo $row['id']; ?>" data-section="status"><?php echo $row['site_status'] ? '🟢 Active' : '🔴 Inactive'; ?></button>
                        <button class="section-toggle-btn <?php echo $row['site_visible'] ? 'active-primary' : ''; ?>" data-id="<?php echo $row['id']; ?>" data-section="visibility">👁️ <?php echo $row['site_visible'] ? 'Visible' : 'Hidden'; ?></button>
                      </div>
                    </td>
                    <td>
                      <span class="badge <?php echo ($row['site_status'] && $row['site_visible']) ? 'bg-success' : 'bg-danger'; ?> badge-status">
                        <?php echo ($row['site_status'] && $row['site_visible']) ? 'Active' : 'Inactive'; ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-warning edit-btn" data-id="<?php echo $row['id']; ?>"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $row['id']; ?>" data-name="<?php echo htmlspecialchars($row['product_name'] ?? 'Product'); ?>"><i class="bi bi-trash"></i></button>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">No site products found. Click "Add New" to create one.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>

    <footer class="text-center text-muted py-3 small border-top bg-white">
      © <?php echo date('Y'); ?> Rice Valy — <a href="https://digitalsoftwarebd.com" target="_blank" class="text-decoration-none">Designed by DSB</a>
    </footer>
  </div>
</div>

<form id="logout-form" action="/admin/logout" method="POST" style="display:none;">
  <input type="hidden" name="_token" value="Tzrj0tXolfHXDKSJ8dFPoXmwrjtVbwt6bkEtEaYb">
</form>

<!-- ============================================================ -->
<!-- SINGLE PRODUCT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle"><i class="bi bi-box-seam me-2"></i> Add Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="productForm" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="id" id="editId" value="0">
          <input type="hidden" name="action" value="save_product">

          <div class="mb-3">
            <label class="form-label">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="product_name" id="product_name" class="form-control" required maxlength="255">
          </div>

          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Site Category <span class="text-danger">*</span></label>
              <select name="site_category_id" id="site_category_id" class="form-select" required>
                <option value="">Select Site Category</option>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Subcategory</label>
              <select name="subcategory_id" id="subcategory_id" class="form-select" disabled>
                <option value="">Select Subcategory</option>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Child Category</label>
              <select name="childcategory_id" id="childcategory_id" class="form-select" disabled>
                <option value="">Select Child Category</option>
              </select>
            </div>
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Brand</label>
              <select name="brand_id" id="brand_id" class="form-select">
                <option value="">Select Brand</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Unit <span class="text-danger">*</span></label>
              <select name="unit" id="unit" class="form-select" required>
                <option value="">Select Unit</option>
                <option value="dozan">Dozan</option>
                <option value="bosta">Bosta</option>
                <option value="kg">Kg</option>
                <option value="gram">Gram</option>
                <option value="liter">Liter</option>
                <option value="ml">ML</option>
                <option value="pcs">Pcs</option>
                <option value="pack">Pack</option>
                <option value="box">Box</option>
                <option value="bundle">Bundle</option>
              </select>
            </div>
          </div>

          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Purchase Price</label>
              <input type="number" step="0.01" min="0" name="purchase_price" id="purchase_price" class="form-control" value="0">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Selling Price <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0" name="site_price" id="site_price" class="form-control" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Old Price</label>
              <input type="number" step="0.01" min="0" name="site_old_price" id="site_old_price" class="form-control">
            </div>
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Stock</label>
              <input type="number" min="0" name="site_stock" id="site_stock" class="form-control" value="0">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Reorder Level</label>
              <input type="number" min="0" name="reorder_level" id="reorder_level" class="form-control" value="0">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Full Description</label>
            <div class="rv-rich-editor" id="siteDescriptionEditor">
              <div class="rv-rich-toolbar" role="toolbar">
                <select class="form-select form-select-sm" id="rvFormatBlock" style="width:auto" title="Paragraph style">
                  <option value="p">Normal</option>
                  <option value="h2">Heading 2</option>
                  <option value="h3">Heading 3</option>
                  <option value="h4">Heading 4</option>
                  <option value="blockquote">Quote</option>
                </select>
                <button type="button" class="btn btn-sm" data-editor-cmd="bold" title="Bold"><strong>B</strong></button>
                <button type="button" class="btn btn-sm" data-editor-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" class="btn btn-sm" data-editor-cmd="italic" title="Italic"><em>I</em></button>
                <select class="form-select form-select-sm" id="rvFontName" style="width:auto" title="Font family">
                  <option value="Arial">Arial</option>
                  <option value="sans-serif" selected>sans-serif</option>
                  <option value="Georgia">Georgia</option>
                  <option value="Tahoma">Tahoma</option>
                  <option value="Times New Roman">Times New Roman</option>
                  <option value="Verdana">Verdana</option>
                  <option value="Trebuchet MS">Trebuchet MS</option>
                  <option value="Courier New">Courier New</option>
                </select>
                <select class="form-select form-select-sm" id="rvFontSize" style="width:auto" title="Font size">
                  <option value="12">12px</option>
                  <option value="14">14px</option>
                  <option value="16" selected>16px</option>
                  <option value="18">18px</option>
                  <option value="20">20px</option>
                  <option value="24">24px</option>
                  <option value="28">28px</option>
                  <option value="32">32px</option>
                  <option value="36">36px</option>
                  <option value="48">48px</option>
                </select>
                <span class="rv-color-wrap" title="Text color">
                  <button type="button" class="btn btn-sm"><strong id="rvTextColorPreview" style="color:#000000;">A</strong></button>
                  <input type="color" class="rv-color-input" id="rvTextColor" value="#000000" aria-label="Text color">
                </span>
                <span class="rv-color-wrap" title="Highlight color">
                  <button type="button" class="btn btn-sm"><strong id="rvHighlightPreview" style="background:#fff200;padding:0 2px;">A</strong></button>
                  <input type="color" class="rv-color-input" id="rvHighlightColor" value="#fff200" aria-label="Highlight color">
                </span>
                <button type="button" class="btn btn-sm" data-editor-cmd="insertUnorderedList" title="Bullet list">☷</button>
                <button type="button" class="btn btn-sm" data-editor-cmd="insertOrderedList" title="Numbered list">⅓</button>
                <select class="form-select form-select-sm" id="rvTextAlign" style="width:auto" title="Text alignment">
                  <option value="">Align</option>
                  <option value="justifyLeft">Left</option>
                  <option value="justifyCenter">Center</option>
                  <option value="justifyRight">Right</option>
                  <option value="justifyFull">Justify</option>
                </select>
                <button type="button" class="btn btn-sm" id="rvInsertTable" title="Insert table">▦</button>
                <button type="button" class="btn btn-sm" id="rvCreateLink" title="Insert link">🔗</button>
                <button type="button" class="btn btn-sm" id="rvInsertImage" title="Insert image URL">▧</button>
                <button type="button" class="btn btn-sm" id="rvInsertVideo" title="Insert video link">▶</button>
                <button type="button" class="btn btn-sm" data-editor-cmd="removeFormat" title="Clear formatting">×</button>
                <button type="button" class="btn btn-sm" id="rvEditorFullscreen" title="Fullscreen">⛶</button>
                <button type="button" class="btn btn-sm" id="rvEditorHelp" title="Help">?</button>
              </div>
              <div id="site_description_editor" class="rv-rich-body" contenteditable="true" data-placeholder="Enter Your Text Here" spellcheck="true"></div>
            </div>
            <textarea name="site_description" id="site_description" class="d-none" aria-hidden="true"></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Product Images</label>
            <div class="dropzone-area" id="dropzoneArea">
              <i class="bi bi-cloud-upload"></i>
              <p class="mb-1 fw-semibold">Click here to upload images</p>
              <small class="text-muted">Supports: JPG, PNG, WebP (Max 2MB each)</small>
            </div>
            <input type="file" name="site_images[]" id="site_images" multiple accept="image/*" style="display:none;">
            <div class="image-preview-container" id="imagePreviewContainer"></div>
            <div class="text-muted small mt-1">First image will be used as thumbnail.</div>
          </div>

          <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Note:</strong> After saving the product, you can manage its sections (Featured, New, Top Selling) and visibility from the <strong>Section Management</strong> button or directly from the table buttons.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="saveBtn"><i class="bi bi-save me-1"></i> Save Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- BULK UPLOAD MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cloud-arrow-up me-2"></i> Bulk Upload Products</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="bulkUploadForm" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="action" value="bulk_save_products">
          <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i>
            Add multiple products at once. Each row represents one product.
          </div>
          <div class="text-end mb-3">
            <span class="badge bg-primary me-2" id="productCount">0 products</span>
            <button type="button" class="btn btn-sm btn-success" id="addProductRow"><i class="bi bi-plus-lg"></i> Add Row</button>
            <button type="button" class="btn btn-sm btn-danger" id="removeAllRows"><i class="bi bi-trash"></i> Remove All</button>
          </div>
          <div id="bulkProductsContainer"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success" id="bulkSaveBtn"><i class="bi bi-check2-all"></i> Save All Products</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SITE CATEGORY SETUP MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="siteCategorySetupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i> Site Category Setup</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2">Website categories are managed independently from POS. Manage Site Categories first, then assign Subcategories and Child Categories.</div>
        <div class="row g-3">
          <div class="col-12">
            <div class="card border mb-0">
              <div class="card-header"><strong>Site Categories</strong></div>
              <div class="card-body">
                <form id="siteCategoryForm" class="mb-3">
                  <input type="hidden" id="siteCategoryId" name="id" value="0">
                  <div class="row g-2">
                    <div class="col-md-5"><label class="form-label">Site Category Name</label><input type="text" class="form-control" id="siteCategoryName" name="category_name" maxlength="150" required></div>
                    <div class="col-md-3"><label class="form-label">Category Image</label><input type="file" class="form-control" id="siteCategoryImage" name="category_image" accept="image/jpeg,image/png,image/webp"><div class="mt-2"><img id="siteCategoryImagePreview" src="" style="display:none;width:55px;height:55px;object-fit:cover;border-radius:8px;border:1px solid #ddd;"></div></div>
                    <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" id="siteCategoryStatus" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    <div class="col-md-2"><label class="form-label">Sort Order</label><input type="number" min="0" class="form-control" id="siteCategorySort" name="sort_order" value="0"></div>
                    <div class="col-md-3 d-flex align-items-end gap-2"><button type="submit" class="btn btn-primary flex-fill">Save</button><button type="button" class="btn btn-light" id="resetSiteCategoryForm">Reset</button></div>
                  </div>
                </form>
                <div class="table-responsive" style="max-height:260px;overflow:auto;">
                  <table class="table table-sm table-hover align-middle">
                    <thead class="table-light"><tr><th>Image</th><th>Site Category</th><th>Sort</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="siteCategoryTableBody"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card border mb-0">
              <div class="card-header"><strong>Subcategories</strong></div>
              <div class="card-body">
                <form id="siteSubcategoryForm" class="mb-3">
                  <input type="hidden" id="siteSubcategoryId" name="id" value="0">
                  <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Site Category</label><select class="form-select" id="siteSubcategoryCategory" name="site_category_id" required></select></div>
                    <div class="col-md-6"><label class="form-label">Subcategory Name</label><input type="text" class="form-control" id="siteSubcategoryName" name="subcategory_name" maxlength="150" required></div>
                    <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" id="siteSubcategoryStatus" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    <div class="col-md-4"><label class="form-label">Sort Order</label><input type="number" min="0" class="form-control" id="siteSubcategorySort" name="sort_order" value="0"></div>
                    <div class="col-md-4 d-flex align-items-end gap-2"><button type="submit" class="btn btn-primary flex-fill">Save</button><button type="button" class="btn btn-light" id="resetSiteSubcategoryForm">Reset</button></div>
                  </div>
                </form>
                <div class="table-responsive" style="max-height:330px;overflow:auto;">
                  <table class="table table-sm table-hover align-middle">
                    <thead class="table-light"><tr><th>Site Category</th><th>Subcategory</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="siteSubcategoryTableBody"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card border mb-0">
              <div class="card-header"><strong>Child Categories</strong></div>
              <div class="card-body">
                <form id="siteChildcategoryForm" class="mb-3">
                  <input type="hidden" id="siteChildcategoryId" name="id" value="0">
                  <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Site Category</label><select class="form-select" id="siteChildcategoryCategory" name="site_category_id" required></select></div>
                    <div class="col-md-6"><label class="form-label">Subcategory</label><select class="form-select" id="siteChildcategorySubcategory" name="subcategory_id" required disabled><option value="">Select Subcategory</option></select></div>
                    <div class="col-md-6"><label class="form-label">Child Category Name</label><input type="text" class="form-control" id="siteChildcategoryName" name="childcategory_name" maxlength="150" required></div>
                    <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" id="siteChildcategoryStatus" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    <div class="col-md-2"><label class="form-label">Sort</label><input type="number" min="0" class="form-control" id="siteChildcategorySort" name="sort_order" value="0"></div>
                    <div class="col-md-2 d-flex align-items-end gap-1"><button type="submit" class="btn btn-primary flex-fill">Save</button><button type="button" class="btn btn-light" id="resetSiteChildcategoryForm">Reset</button></div>
                  </div>
                </form>
                <div class="table-responsive" style="max-height:330px;overflow:auto;">
                  <table class="table table-sm table-hover align-middle">
                    <thead class="table-light"><tr><th>Site Category</th><th>Subcategory</th><th>Child</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="siteChildcategoryTableBody"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SECTION MANAGEMENT MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="sectionManagementModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-grid-3x3-gap me-2"></i> Section Management</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-3">
          <div class="col-md-3"><label class="form-label">Site Category</label><select id="sectionFilterCategory" class="form-select"><option value="">All Site Categories</option></select></div>
          <div class="col-md-3"><label class="form-label">Brand</label><select id="sectionFilterBrand" class="form-select"><option value="">All Brands</option></select></div>
          <div class="col-md-3"><label class="form-label">Section Type</label><select id="sectionFilterType" class="form-select"><option value="all">📦 All Products</option><option value="featured">⭐ Featured</option><option value="new">🆕 New</option><option value="top_selling">🔥 Top Selling</option></select></div>
          <div class="col-md-3"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100" id="applySectionFilter"><i class="bi bi-funnel"></i> Apply Filter</button></div>
        </div>
        <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap">
          <span id="selectedCount" class="fw-semibold">0 products selected</span>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-warning btn-sm" id="assignFeatured"><i class="bi bi-star"></i> Add to Featured</button>
            <button class="btn btn-info btn-sm text-white" id="assignNew"><i class="bi bi-stars"></i> Add to New</button>
            <button class="btn btn-success btn-sm" id="assignTopSelling"><i class="bi bi-fire"></i> Add to Top Selling</button>
            <button class="btn btn-danger btn-sm" id="removeFromSections"><i class="bi bi-x-lg"></i> Remove from All</button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-bordered" id="sectionProductsTable">
            <thead class="table-light">
              <tr>
                <th width="50"><input type="checkbox" id="selectAllProducts"></th>
                <th>Product</th><th>Site Category</th><th>Brand</th><th>Price</th><th>Stock</th><th>Current Sections</th>
              </tr>
            </thead>
            <tbody id="sectionProductsBody">
              <tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SHIPPING CHARGE MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="shippingChargeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-truck me-2"></i> Shipping Charge Management</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3"><div class="card-body">
          <h6 class="mb-3">Add / Edit Delivery Method</h6>
          <form id="shippingChargeForm" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="0">
            <div class="col-md-3"><label class="form-label">Method Name</label><input class="form-control" name="name" placeholder="Dhaka City Delivery" required></div>
            <div class="col-md-3"><label class="form-label">Description</label><input class="form-control" name="description" placeholder="Home delivery inside Dhaka"></div>
            <div class="col-md-2"><label class="form-label">Charge (৳)</label><input class="form-control" type="number" name="cost" min="0" step="0.01" value="0" required></div>
            <div class="col-md-2"><label class="form-label">Free Above (৳)</label><input class="form-control" type="number" name="free_above" min="0" step="0.01" placeholder="Blank = no free delivery"></div>
            <div class="col-md-2"><label class="form-label">Minimum Order (৳)</label><input class="form-control" type="number" name="minimum_order" min="0" step="0.01" value="0"></div>
            <div class="col-md-3"><label class="form-label">Estimated Delivery</label><input class="form-control" name="estimated_days" placeholder="1–3 দিন"></div>
            <div class="col-md-2"><label class="form-label">Sort Order</label><input class="form-control" type="number" name="sort_order" min="0" value="0"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-5 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save Shipping Charge</button><button class="btn btn-outline-secondary" id="resetShippingChargeBtn" type="button">Reset</button></div>
          </form>
          <div class="alert alert-light border mt-3 mb-0 py-2 small">
            <strong>Rule:</strong> If order total reaches <em>Free Above</em>, delivery charge becomes ৳0. If the order is below <em>Minimum Order</em>, this delivery method is unavailable at checkout.
          </div>
        </div></div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>Method</th><th>Charge</th><th>Free Above</th><th>Minimum Order</th><th>Estimated</th><th>Sort</th><th>Status</th><th width="140">Action</th></tr></thead>
            <tbody id="shippingMethodsTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- FOOTER SETTINGS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="footerSettingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-layout-text-window me-2"></i> Footer Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="footerSettingsForm" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Footer Logo</label>
              <input type="file" class="form-control" name="footer_logo" accept="image/*">
              <div id="footerLogoPreview" class="mt-2"></div>
              <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> <span class="form-check-label">Remove current logo</span></label>
            </div>
            <div class="col-md-4"><label class="form-label">Brand Name</label><input class="form-control" name="brand_name" required></div>
            <div class="col-md-4"><label class="form-label">Tagline</label><input class="form-control" name="tagline"></div>
            <div class="col-12"><label class="form-label">About Text</label><textarea class="form-control" name="about_text" rows="3"></textarea></div>
            <div class="col-md-4"><label class="form-label">Address</label><input class="form-control" name="address"></div>
            <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
            <div class="col-md-8"><label class="form-label">Information Heading</label><input class="form-control" name="information_title"></div>
            <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
          </div>
          <hr>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Information Links</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addFooterLinkBtn"><i class="bi bi-plus-lg"></i> Add Link</button>
          </div>
          <div id="footerLinksContainer"></div>
          <input type="hidden" name="information_links" id="footerInformationLinksJson" value="[]">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Footer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- PARTNERS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="partnersModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-briefcase me-2"></i> Partner Management</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3"><div class="card-body">
          <h6>Partner Section Heading</h6>
          <form id="partnerSectionForm" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Eyebrow</label><input class="form-control" name="eyebrow_text" value="OUR PARTNERS"></div>
            <div class="col-md-4"><label class="form-label">Title</label><input class="form-control" name="title" value="আমাদের পার্টনার" required></div>
            <div class="col-md-3"><label class="form-label">Subtitle</label><input class="form-control" name="subtitle"></div>
            <div class="col-md-1"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">On</option><option value="0">Off</option></select></div>
            <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Save</button></div>
          </form>
        </div></div>
        <div class="card mb-3"><div class="card-body">
          <h6>Add / Edit Partner</h6>
          <form id="partnerForm" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="0">
            <div class="col-md-3"><label class="form-label">Partner Name</label><input class="form-control" name="partner_name" required></div>
            <div class="col-md-3"><label class="form-label">Website URL</label><input class="form-control" name="website_url" placeholder="https://..."></div>
            <div class="col-md-2"><label class="form-label">Logo</label><input class="form-control" type="file" name="partner_logo" accept="image/*"></div>
            <div class="col-md-1"><label class="form-label">Sort</label><input class="form-control" type="number" name="sort_order" min="0" value="0"></div>
            <div class="col-md-1"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-2 d-flex gap-1"><button class="btn btn-success flex-fill" type="submit">Save</button><button class="btn btn-outline-secondary" id="resetPartnerBtn" type="button">Reset</button></div>
            <div class="col-12"><div id="partnerLogoPreview"></div><label class="form-check d-none" id="partnerRemoveLogoWrap"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> Remove current logo</label></div>
          </form>
        </div></div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>Logo</th><th>Partner</th><th>Website</th><th>Sort</th><th>Status</th><th width="130">Action</th></tr></thead>
            <tbody id="partnersTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- CUSTOMER REVIEWS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="customerReviewsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i> Customer Reviews</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-3">
          <div class="col-md-4">
            <label class="form-label">Filter Status</label>
            <select class="form-select" id="siteReviewStatusFilter">
              <option value="all">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>Customer</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th><th width="220">Action</th></tr></thead>
            <tbody id="siteReviewsTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- BREAKING NEWS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="breakingNewsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-broadcast me-2"></i> Breaking News Management</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3"><div class="card-body">
          <form id="breakingNewsForm" class="row g-2">
            <input type="hidden" name="id" value="0">
            <div class="col-md-8"><label class="form-label">News Text</label><input class="form-control" name="title" maxlength="255" placeholder="বাংলা বা English breaking news লিখুন" required></div>
            <div class="col-md-2"><label class="form-label">Sort Order</label><input class="form-control" type="number" min="0" name="sort_order" value="0"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-4"><label class="form-label">Start At</label><input class="form-control" type="datetime-local" name="start_at"></div>
            <div class="col-md-4"><label class="form-label">End At</label><input class="form-control" type="datetime-local" name="end_at"></div>
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save</button><button class="btn btn-outline-secondary" id="resetBreakingNewsBtn" type="button">Reset</button></div>
          </form>
        </div></div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>News</th><th>Schedule</th><th>Sort</th><th>Status</th><th width="150">Action</th></tr></thead>
            <tbody id="breakingNewsTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- HERO SLIDER MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="heroSliderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-images me-2"></i> Hero Slider</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3"><div class="card-body">
          <form id="heroSliderForm" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="id" value="0">
            <div class="col-md-3"><label class="form-label">Slider Type</label><select class="form-select" name="slider_type" id="heroSliderType"><option value="content">Content Slider</option><option value="full_image">Full Image Banner</option></select></div>
            <div class="col-md-4 hero-content-field"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
            <div class="col-md-4"><label class="form-label">Tag / Subtitle</label><input class="form-control" name="subtitle"></div>
            <div class="col-md-4"><label class="form-label">Button Text</label><input class="form-control" name="button_text"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Button URL</label><input class="form-control" name="button_url" placeholder="#products"></div>
            <div class="col-md-4"><label class="form-label">Desktop Image</label><input class="form-control" type="file" name="hero_image" accept="image/*"></div>
            <div class="col-md-4"><label class="form-label">Mobile Image</label><input class="form-control" type="file" name="hero_mobile_image" accept="image/*"></div>
            <div class="col-md-2"><label class="form-label">BG Start</label><input class="form-control form-control-color w-100" type="color" name="background_start_color" value="#b3e4e6"></div>
            <div class="col-md-2"><label class="form-label">BG End</label><input class="form-control form-control-color w-100" type="color" name="background_end_color" value="#b5df99"></div>
            <div class="col-md-2"><label class="form-label">Sort</label><input class="form-control" type="number" name="sort_order" min="0" value="0"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-2"><label class="form-label">Start At</label><input class="form-control" type="datetime-local" name="start_at"></div>
            <div class="col-md-2"><label class="form-label">End At</label><input class="form-control" type="datetime-local" name="end_at"></div>
            <div class="col-12 d-flex justify-content-between flex-wrap gap-2">
              <div id="heroImagePreview" class="d-flex gap-2"></div>
              <div>
                <label class="form-check form-check-inline d-none" id="heroRemoveImageWrap"><input class="form-check-input" type="checkbox" name="remove_image" value="1"> Remove desktop</label>
                <label class="form-check form-check-inline d-none" id="heroRemoveMobileImageWrap"><input class="form-check-input" type="checkbox" name="remove_mobile_image" value="1"> Remove mobile</label>
              </div>
            </div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Slide</button><button class="btn btn-outline-secondary" id="resetHeroSliderBtn" type="button">Reset</button></div>
          </form>
        </div></div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>Image</th><th>Title</th><th>Schedule</th><th>Sort</th><th>Status</th><th width="130">Action</th></tr></thead>
            <tbody id="heroSlidersTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- OFFER SLIDER MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="offerBannerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tag me-2"></i> Offer Slider Management</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div>
            <strong>Full Image Offer Slider</strong>
            <div class="small text-muted">একটি সম্পূর্ণ banner image upload করুন এবং destination link দিন। Website-এ পুরো image-টাই slide হবে এবং image-এর যেকোনো জায়গায় click করলে link open হবে।</div>
          </div>
          <button type="button" class="btn btn-success btn-sm" id="addNewOfferSlideBtn"><i class="bi bi-plus-lg"></i> Add New Slide</button>
        </div>
        <div class="card mb-3">
          <div class="card-body">
            <form id="offerBannerForm" enctype="multipart/form-data">
              <input type="hidden" name="id" value="0">
              <div class="row g-3 align-items-start">
                <div class="col-lg-7">
                  <label class="form-label">Full Slide Image</label>
                  <input class="form-control" type="file" name="offer_image" accept="image/jpeg,image/png,image/webp,image/gif">
                  <div class="small text-muted mt-2">Professional banner image ব্যবহার করুন। Text, offer amount, button artwork—সব image-এর ভিতরেই design করা থাকবে। Max 2MB.</div>
                </div>
                <div class="col-lg-5">
                  <label class="form-label fw-bold">Slide Link</label>
                  <input class="form-control" name="button_url" placeholder="যেমন: /site_category.php?id=2 অথবা https://..." required>
                  <div class="small text-muted mt-2">পুরো slide-টি এই link-এ clickable থাকবে। Internal path, #anchor অথবা full https URL ব্যবহার করতে পারবেন।</div>
                </div>
                <div class="col-12"><div id="offerImagePreview"></div></div>
                <div class="col-12 d-flex gap-2 flex-wrap">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Full Image Slide</button>
                  <button class="btn btn-outline-secondary" id="resetOfferBannerBtn" type="button">Reset</button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <div class="card mb-3">
          <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div><strong>Live Slider Preview</strong><div class="small text-muted">Full image + clickable link preview</div></div>
            <span class="badge bg-success" id="offerLiveCount">0 live slides</span>
          </div>
          <div class="card-body">
            <div class="admin-offer-preview" id="offerSliderPreview">
              <div class="admin-offer-preview-empty">No offer slide available.</div>
            </div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead><tr><th>Slide #</th><th>Full Image</th><th>Link</th><th>Status</th><th width="140">Action</th></tr></thead>
            <tbody id="offerBannersTableBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SCRIPTS -->
<!-- ============================================================ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/2.1.2/sweetalert.min.js"></script>

<script>
if (window.jQuery) {
  jQuery.ajaxSetup({
    headers: { 'X-CSRF-Token': <?= json_encode(siteAdminCsrfToken(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?> }
  });
}
</script>

<script>
/* ============ SIDEBAR TOGGLE ============ */
document.addEventListener('DOMContentLoaded', function(){
  const sb=document.getElementById('rvSidebar');
  const bd=document.getElementById('rvBackdrop');
  const tg=document.getElementById('rvToggle');
  function close(){sb.classList.remove('open');bd.classList.remove('show');}
  tg&&tg.addEventListener('click',function(){sb.classList.toggle('open');bd.classList.toggle('show');});
  bd&&bd.addEventListener('click',close);
});
</script>

<script>
/* ============================================
   CONFIG
   ============================================ */
var CONFIG = {
  BASE_URL: '<?php echo BASE_URL; ?>',
  UPLOAD_DIR: '<?php echo UPLOAD_DIR; ?>',
  MAX_FILE_SIZE: <?php echo MAX_FILE_SIZE; ?>,
  ALLOWED_EXTENSIONS: <?php echo json_encode(ALLOWED_EXTENSIONS); ?>
};

function getImageUrl(path){
  if(!path) return '';
  if(path.startsWith('http://')||path.startsWith('https://')) return path;
  var clean = path.replace(/^uploads\/site_products\//,'').replace(/^uploads\/site_products/,'');
  clean = clean.replace(/^\/+/,'');
  return CONFIG.BASE_URL + CONFIG.UPLOAD_DIR + clean;
}

toastr.options = {
  closeButton:true, progressBar:true, positionClass:"toast-top-right",
  timeOut:3000, extendedTimeOut:1000, preventDuplicates:true
};

/* ============ LOADING ============ */
function showLoading(){
  if($('#loadingOverlay').length===0){
    $('body').append('<div id="loadingOverlay" class="loading-overlay"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
  }
  $('#loadingOverlay').fadeIn(200);
}
function hideLoading(){ $('#loadingOverlay').fadeOut(200); }

function escapeHtml(value){ return $('<div>').text(value==null?'':String(value)).html(); }

/* ============================================
   RICH TEXT EDITOR
   ============================================ */
var rvEditorRange = null;
function rvGetEditor(){ return document.getElementById('site_description_editor'); }
function rvSaveSelection(){
  var editor=rvGetEditor(); var sel=window.getSelection?window.getSelection():null;
  if(!editor||!sel||!sel.rangeCount) return;
  var range=sel.getRangeAt(0);
  if(editor.contains(range.commonAncestorContainer)) rvEditorRange=range.cloneRange();
}
function rvRestoreSelection(){
  var editor=rvGetEditor(); if(!editor) return;
  editor.focus();
  if(rvEditorRange&&window.getSelection){ var sel=window.getSelection(); sel.removeAllRanges(); sel.addRange(rvEditorRange); }
}
function rvSyncDescriptionToTextarea(){
  var editor=rvGetEditor(); if(!editor) return;
  $('#site_description').val(editor.innerHTML);
}
function rvSetDescriptionHtml(html){
  var editor=rvGetEditor(); if(!editor) return;
  $('#siteDescriptionEditor').removeClass('rv-code-mode');
  editor.innerHTML=html||'';
  $('#site_description').val(html||'');
  rvEditorRange=null;
}
function rvExec(command,value){
  rvRestoreSelection();
  try{ document.execCommand(command,false,value==null?null:value); }catch(e){}
  rvSaveSelection(); rvSyncDescriptionToTextarea();
}
$(document).on('mouseup keyup focus input','#site_description_editor',function(){ rvSaveSelection(); rvSyncDescriptionToTextarea(); });
$(document).on('mousedown','[data-editor-cmd], #rvInsertTable, #rvCreateLink, #rvInsertImage, #rvInsertVideo, #rvEditorFullscreen, #rvEditorHelp',function(e){ if(!$(this).is('select')) e.preventDefault(); });
$(document).on('click','[data-editor-cmd]',function(){ rvExec($(this).data('editor-cmd')); });
$('#rvFormatBlock').on('change',function(){ var tag=$(this).val()||'p'; rvExec('formatBlock','<'+tag+'>'); });
$('#rvFontName').on('change',function(){ rvExec('fontName',$(this).val()); });
$('#rvFontSize').on('change',function(){
  var px=parseInt($(this).val(),10); if(!px||px<8||px>72) return;
  rvRestoreSelection();
  try{
    document.execCommand('styleWithCSS',false,true);
    document.execCommand('fontSize',false,'7');
    var editor=rvGetEditor();
    if(editor){
      $(editor).find('font[size="7"]').each(function(){
        var $font=$(this);
        var $span=$('<span></span>').css('font-size',px+'px').html($font.html());
        var face=$font.attr('face'); if(face) $span.css('font-family',face);
        $font.replaceWith($span);
      });
    }
  }catch(e){}
  rvSaveSelection(); rvSyncDescriptionToTextarea();
});
$('#rvTextColor').on('input change',function(){
  var color=$(this).val(); $('#rvTextColorPreview').css('color',color);
  rvRestoreSelection();
  try{ document.execCommand('styleWithCSS',false,true); document.execCommand('foreColor',false,color); }catch(e){}
  rvSaveSelection(); rvSyncDescriptionToTextarea();
});
$('#rvHighlightColor').on('input change',function(){
  var color=$(this).val(); $('#rvHighlightPreview').css('background-color',color);
  rvRestoreSelection();
  try{ document.execCommand('styleWithCSS',false,true); document.execCommand('hiliteColor',false,color); }catch(e){}
  rvSaveSelection(); rvSyncDescriptionToTextarea();
});
$('#rvTextAlign').on('change',function(){ var cmd=$(this).val(); if(cmd) rvExec(cmd); $(this).val(''); });
$('#rvCreateLink').on('click',function(){
  var url=window.prompt('Enter link URL (https://...)'); if(!url) return;
  if(!/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url)) url='https://'+url;
  rvExec('createLink',url);
});
$('#rvInsertImage').on('click',function(){
  var url=window.prompt('Enter image URL (https://...)'); if(!url) return;
  if(!/^https?:\/\//i.test(url)){ toastr.warning('Please use a full http/https image URL.'); return; }
  rvExec('insertImage',url);
});
$('#rvInsertVideo').on('click',function(){
  var url=window.prompt('Enter video URL'); if(!url) return;
  if(!/^https?:\/\//i.test(url)){ toastr.warning('Please use a full http/https video URL.'); return; }
  rvRestoreSelection();
  document.execCommand('insertHTML',false,'<p><a href="'+$('<div>').text(url).html()+'" target="_blank" rel="noopener">▶ Watch video</a></p>');
  rvSyncDescriptionToTextarea();
});
$('#rvInsertTable').on('click',function(){
  var rows=parseInt(window.prompt('Number of rows','2'),10);
  var cols=parseInt(window.prompt('Number of columns','2'),10);
  if(!rows||!cols||rows<1||cols<1||rows>12||cols>8){ toastr.warning('Use 1-12 rows and 1-8 columns.'); return; }
  var html='<table><tbody>';
  for(var r=0;r<rows;r++){ html+='<tr>'; for(var c=0;c<cols;c++) html+='<td>&nbsp;</td>'; html+='</tr>'; }
  html+='</tbody></table><p><br></p>';
  rvRestoreSelection(); document.execCommand('insertHTML',false,html); rvSyncDescriptionToTextarea();
});
$('#rvEditorFullscreen').on('click',function(){
  $('#siteDescriptionEditor').toggleClass('rv-editor-fullscreen');
  $('body').toggleClass('overflow-hidden',$('#siteDescriptionEditor').hasClass('rv-editor-fullscreen'));
});
$('#rvEditorHelp').on('click',function(){
  toastr.info('Select text, then use Bold/Underline/Italic, font family, font size, text color, highlight color, lists, alignment, table, link, image or video tools.');
});

/* ============================================
   DROPDOWNS / HIERARCHY
   ============================================ */
function resetChildSelect(select,placeholder){
  select.empty().append($('<option>',{value:'',text:placeholder})).prop('disabled',true);
}
function loadSiteSubcategories(categoryId,select,selectedValue){
  resetChildSelect(select,'Select Subcategory');
  if(!categoryId) return $.Deferred().resolve().promise();
  return $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_subcategories',site_category_id:categoryId},dataType:'json'})
    .done(function(response){
      if(response&&response.success){
        $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); });
        select.prop('disabled',false);
        if(selectedValue!=null&&selectedValue!=='') select.val(String(selectedValue));
      } else toastr.error((response&&response.message)||'Failed to load subcategories');
    }).fail(function(){ toastr.error('Failed to load subcategories'); });
}
function loadSiteChildcategories(categoryId,subcategoryId,select,selectedValue){
  resetChildSelect(select,'Select Child Category');
  if(!categoryId||!subcategoryId) return $.Deferred().resolve().promise();
  return $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_childcategories',site_category_id:categoryId,subcategory_id:subcategoryId},dataType:'json'})
    .done(function(response){
      if(response&&response.success){
        $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); });
        select.prop('disabled',false);
        if(selectedValue!=null&&selectedValue!=='') select.val(String(selectedValue));
      } else toastr.error((response&&response.message)||'Failed to load child categories');
    }).fail(function(){ toastr.error('Failed to load child categories'); });
}
function loadDropdowns(){
  resetChildSelect($('#subcategory_id'),'Select Subcategory');
  resetChildSelect($('#childcategory_id'),'Select Child Category');
  var categoryRequest=$.ajax({url:window.location.pathname,type:'POST',data:{action:'get_categories'},dataType:'json'});
  var brandRequest=$.ajax({url:window.location.pathname,type:'POST',data:{action:'get_brands'},dataType:'json'});
  categoryRequest.done(function(response){
    var select=$('#site_category_id'); select.empty().append($('<option>',{value:'',text:'Select Site Category'}));
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }).fail(function(){ toastr.error('Failed to load categories'); });
  brandRequest.done(function(response){
    var select=$('#brand_id'); select.empty().append($('<option>',{value:'',text:'Select Brand'}));
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }).fail(function(){ toastr.error('Failed to load brands'); });
  return $.when(categoryRequest,brandRequest);
}
$('#site_category_id').on('change',function(){
  var categoryId=$(this).val();
  resetChildSelect($('#childcategory_id'),'Select Child Category');
  loadSiteSubcategories(categoryId,$('#subcategory_id'),null);
});
$('#subcategory_id').on('change',function(){
  var categoryId=$('#site_category_id').val();
  var subcategoryId=$(this).val();
  loadSiteChildcategories(categoryId,subcategoryId,$('#childcategory_id'),null);
});

/* ============================================
   SINGLE PRODUCT - ADD / EDIT / DELETE / SAVE
   ============================================ */
function openAddModal(){
  $('#modalTitle').html('<i class="bi bi-box-seam me-2"></i> Add Product');
  $('#productForm')[0].reset();
  rvSetDescriptionHtml('');
  $('#editId').val(0);
  $('#imagePreviewContainer').empty();
  loadDropdowns().always(function(){ $('#productModal').modal('show'); });
}
$('#addNewBtn').click(openAddModal);
$('#siteProductsMenu').click(function(e){ e.preventDefault(); openAddModal(); });

$('#productForm').submit(function(e){
  e.preventDefault();
  var productName=$('#product_name').val().trim();
  var categoryId=$('#site_category_id').val();
  var unit=$('#unit').val();
  var price=$('#site_price').val();
  if(!productName){ toastr.error('Product name is required'); $('#product_name').focus(); return false; }
  if(!categoryId){ toastr.error('Site Category is required'); $('#site_category_id').focus(); return false; }
  if(!unit){ toastr.error('Unit is required'); $('#unit').focus(); return false; }
  if(!price||parseFloat(price)<=0){ toastr.error('Valid selling price is required'); $('#site_price').focus(); return false; }

  rvSyncDescriptionToTextarea();
  var formData=new FormData(this);
  showLoading();
  $.ajax({
    url:window.location.pathname,type:'POST',data:formData,contentType:false,processData:false,dataType:'json',
    beforeSend:function(){ $('#saveBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...'); },
    success:function(response){
      if(response.success){ toastr.success(response.message); $('#productModal').modal('hide'); setTimeout(function(){ location.reload(); },800); }
      else toastr.error(response.message||'Error occurred while saving.');
    },
    error:function(xhr){ var errorMsg='Error occurred while saving.'; try{var r=JSON.parse(xhr.responseText); if(r.message)errorMsg=r.message;}catch(e){} toastr.error(errorMsg); },
    complete:function(){ $('#saveBtn').prop('disabled',false).html('<i class="bi bi-save me-1"></i> Save Product'); hideLoading(); }
  });
});

$(document).on('click','.edit-btn',function(){
  var id=$(this).data('id');
  $('#modalTitle').html('<i class="bi bi-pencil me-2"></i> Edit Product');
  $('#editId').val(id);
  showLoading();
  loadDropdowns().done(function(){
    $.ajax({
      url:window.location.pathname,type:'POST',data:{action:'get_product',id:id},dataType:'json',
      success:function(data){
        if(!data||data.error){ toastr.error(data.error||'Invalid product response'); return; }
        $('#product_name').val(data.product_name||'');
        $('#site_category_id').val(data.site_category_id!=null?String(data.site_category_id):'');
        $('#brand_id').val(data.brand_id!=null?String(data.brand_id):'');
        $('#unit').val(data.unit||'');
        $('#purchase_price').val(data.purchase_price!=null?data.purchase_price:'0');
        $('#site_price').val(data.site_price||'');
        $('#site_old_price').val(data.site_old_price||'');
        $('#site_stock').val(data.site_stock!=null?data.site_stock:'0');
        $('#reorder_level').val(data.reorder_level!=null?data.reorder_level:'0');
        rvSetDescriptionHtml(data.site_description||'');

        var categoryId=data.site_category_id!=null?String(data.site_category_id):'';
        var subcategoryId=data.subcategory_id!=null?String(data.subcategory_id):'';
        var childcategoryId=data.childcategory_id!=null?String(data.childcategory_id):'';

        if(categoryId){
          loadSiteSubcategories(categoryId,$('#subcategory_id'),subcategoryId).always(function(){
            if(subcategoryId) loadSiteChildcategories(categoryId,subcategoryId,$('#childcategory_id'),childcategoryId);
            else resetChildSelect($('#childcategory_id'),'Select Child Category');
          });
        } else {
          resetChildSelect($('#subcategory_id'),'Select Subcategory');
          resetChildSelect($('#childcategory_id'),'Select Child Category');
        }

        $('#imagePreviewContainer').empty();
        if(data.site_images){
          var images=data.site_images.split(',').map(function(img){ return img.trim(); });
          $.each(images,function(_,img){ if(img) addImagePreview(img); });
        }
        $('#productModal').modal('show');
        hideLoading();
      },
      error:function(){ toastr.error('Failed to load product data'); hideLoading(); }
    });
  });
});

$(document).on('click','.delete-btn',function(){
  var id=$(this).data('id'); var name=$(this).data('name');
  swal({ title:'Are you sure?', text:'Delete product "'+name+'"? This action cannot be undone.', icon:'warning', buttons:['Cancel','Delete'], dangerMode:true })
    .then((willDelete)=>{
      if(willDelete){
        showLoading();
        $.ajax({ url:window.location.pathname, type:'POST', data:{action:'delete_product',id:id}, dataType:'json',
          success:function(response){
            if(response.success){ toastr.success(response.message); $('#row_'+id).fadeOut(300,function(){ $(this).remove(); }); }
            else toastr.error(response.message);
          },
          error:function(){ toastr.error('Failed to delete product'); },
          complete:function(){ hideLoading(); }
        });
      }
    });
});

/* ============ IMAGE UPLOAD (Single Product) ============ */
$(document).ready(function(){
  $('#dropzoneArea').on('click',function(e){ e.preventDefault(); e.stopPropagation(); $('#site_images').trigger('click'); });
  $('#site_images').on('click',function(e){ e.stopPropagation(); });
  $('#site_images').on('change',function(e){
    e.preventDefault();
    var files=this.files;
    for(var i=0;i<files.length;i++){
      var file=files[i];
      var ext=file.name.split('.').pop().toLowerCase();
      if(!CONFIG.ALLOWED_EXTENSIONS.includes(ext)){ toastr.warning(file.name+' is not allowed.'); this.value=''; return; }
      if(file.size>CONFIG.MAX_FILE_SIZE){ toastr.warning(file.name+' is too large. Max 2MB.'); this.value=''; return; }
    }
    $('#imagePreviewContainer').empty();
    for(var i=0;i<files.length;i++){
      var file=files[i];
      if(file.type.startsWith('image/')){
        var reader=new FileReader();
        reader.onload=function(e){ addImagePreview(e.target.result,true); };
        reader.readAsDataURL(file);
      }
    }
  });
  $('#dropzoneArea').on('dragover',function(e){ e.preventDefault(); $(this).css('border-color','#0a5b4f').css('background','#f0fdfa'); });
  $('#dropzoneArea').on('dragleave',function(e){ e.preventDefault(); $(this).css('border-color','#cbd5e1').css('background',''); });
  $('#dropzoneArea').on('drop',function(e){
    e.preventDefault();
    $(this).css('border-color','#cbd5e1').css('background','');
    var files=e.originalEvent.dataTransfer.files;
    if(files.length>0){ $('#site_images')[0].files=files; $('#site_images').trigger('change'); }
  });
});
function addImagePreview(src,isDataUrl){
  isDataUrl=isDataUrl||false;
  var container=$('#imagePreviewContainer');
  var div=$('<div>').addClass('image-preview-item');
  var img=$('<img>').attr('src',isDataUrl?src:getImageUrl(src));
  var btn=$('<button type="button">').addClass('remove-img').html('×').click(function(){
    $(this).closest('.image-preview-item').remove();
  });
  div.append(img,btn); container.append(div);
}

/* ============================================
   BULK UPLOAD
   ============================================ */
var bulkRowCounter=0;
$('#bulkUploadBtn').click(function(){
  $('#bulkProductsContainer').empty(); bulkRowCounter=0;
  for(var i=0;i<3;i++) addBulkProductRow();
  updateProductCount();
  $('#bulkUploadModal').modal('show');
});
function addBulkProductRow(){
  var rowIndex=bulkRowCounter++;
  var html=`
    <div class="bulk-product-row" data-index="${rowIndex}">
      <button type="button" class="remove-row" onclick="removeBulkRow(this)">×</button>
      <div class="row">
        <div class="col-md-12 mb-2"><label class="form-label">Product Name <span class="text-danger">*</span></label><input type="text" class="form-control bulk-product-name" placeholder="Enter product name" required maxlength="255"></div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-2"><label class="form-label">Site Category <span class="text-danger">*</span></label><select class="form-select bulk-product-category" required><option value="">Select Site Category</option></select></div>
        <div class="col-md-4 mb-2"><label class="form-label">Subcategory</label><select class="form-select bulk-product-subcategory" disabled><option value="">Select Subcategory</option></select></div>
        <div class="col-md-4 mb-2"><label class="form-label">Child Category</label><select class="form-select bulk-product-childcategory" disabled><option value="">Select Child Category</option></select></div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-2"><label class="form-label">Brand</label><select class="form-select bulk-product-brand"><option value="">Select Brand</option></select></div>
        <div class="col-md-6 mb-2"><label class="form-label">Unit</label><select class="form-select bulk-product-unit">
          <option value="">Select Unit</option>
          <option value="dozan">Dozan</option><option value="bosta">Bosta</option><option value="kg">Kg</option>
          <option value="gram">Gram</option><option value="liter">Liter</option><option value="ml">ML</option>
          <option value="pcs">Pcs</option><option value="pack">Pack</option><option value="box">Box</option><option value="bundle">Bundle</option>
        </select></div>
      </div>
      <div class="row">
        <div class="col-md-4 mb-2"><label class="form-label">Purchase Price</label><input type="number" step="0.01" min="0" class="form-control bulk-product-purchase-price" value="0"></div>
        <div class="col-md-4 mb-2"><label class="form-label">Selling Price <span class="text-danger">*</span></label><input type="number" step="0.01" min="0" class="form-control bulk-product-price" required></div>
        <div class="col-md-4 mb-2"><label class="form-label">Old Price</label><input type="number" step="0.01" min="0" class="form-control bulk-product-old-price"></div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-2"><label class="form-label">Stock</label><input type="number" min="0" class="form-control bulk-product-stock" value="0"></div>
        <div class="col-md-6 mb-2"><label class="form-label">Reorder Level</label><input type="number" min="0" class="form-control bulk-product-reorder-level" value="0"></div>
      </div>
      <div class="row">
        <div class="col-md-12 mb-2"><label class="form-label">Full Description</label><textarea class="form-control bulk-product-description" rows="2"></textarea></div>
      </div>
      <div class="row">
        <div class="col-md-12 mb-2"><label class="form-label">Product Image</label><input type="file" class="form-control bulk-product-image" accept="image/*"><small class="text-muted">Max 2MB. Allowed: JPG, PNG, WebP</small></div>
      </div>
    </div>`;
  $('#bulkProductsContainer').append(html);
  loadBulkDropdowns(rowIndex);
  updateProductCount();
}
function removeBulkRow(btn){
  var row=$(btn).closest('.bulk-product-row');
  if($('#bulkProductsContainer .bulk-product-row').length>1){ row.remove(); updateProductCount(); }
  else toastr.warning('At least one product row is required.');
}
function updateProductCount(){
  var count=$('#bulkProductsContainer .bulk-product-row').length;
  $('#productCount').text(count+' product'+(count>1?'s':''));
}
function resetBulkChildSelect(select,placeholder){ select.empty().append($('<option>',{value:'',text:placeholder})).prop('disabled',true); }
function loadBulkSubcategories(categoryId,select){
  resetBulkChildSelect(select,'Select Subcategory');
  if(!categoryId) return;
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_subcategories',site_category_id:categoryId},dataType:'json',success:function(response){
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); select.prop('disabled',false); }
    else toastr.error((response&&response.message)||'Failed to load subcategories');
  },error:function(){ toastr.error('Failed to load subcategories'); }});
}
function loadBulkChildcategories(categoryId,subcategoryId,select){
  resetBulkChildSelect(select,'Select Child Category');
  if(!categoryId||!subcategoryId) return;
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_childcategories',site_category_id:categoryId,subcategory_id:subcategoryId},dataType:'json',success:function(response){
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); select.prop('disabled',false); }
    else toastr.error((response&&response.message)||'Failed to load child categories');
  },error:function(){ toastr.error('Failed to load child categories'); }});
}
function loadBulkDropdowns(rowIndex){
  var row=$('#bulkProductsContainer .bulk-product-row[data-index="'+rowIndex+'"]');
  resetBulkChildSelect(row.find('.bulk-product-subcategory'),'Select Subcategory');
  resetBulkChildSelect(row.find('.bulk-product-childcategory'),'Select Child Category');
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_categories'},dataType:'json',success:function(response){
    var select=row.find('.bulk-product-category'); select.empty().append($('<option>',{value:'',text:'Select Site Category'}));
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }});
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_brands'},dataType:'json',success:function(response){
    var select=row.find('.bulk-product-brand'); select.empty().append($('<option>',{value:'',text:'Select Brand'}));
    if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }});
}
$(document).on('change','.bulk-product-category',function(){
  var row=$(this).closest('.bulk-product-row');
  resetBulkChildSelect(row.find('.bulk-product-childcategory'),'Select Child Category');
  loadBulkSubcategories($(this).val(),row.find('.bulk-product-subcategory'));
});
$(document).on('change','.bulk-product-subcategory',function(){
  var row=$(this).closest('.bulk-product-row');
  loadBulkChildcategories(row.find('.bulk-product-category').val(),$(this).val(),row.find('.bulk-product-childcategory'));
});
$('#addProductRow').click(function(){ addBulkProductRow(); });
$('#removeAllRows').click(function(){
  if($('#bulkProductsContainer .bulk-product-row').length>1){
    swal({title:'Remove all?',text:'This will remove all product rows.',icon:'warning',buttons:['Cancel','Yes, Remove All'],dangerMode:true}).then(function(willRemove){
      if(willRemove){ $('#bulkProductsContainer').empty(); addBulkProductRow(); updateProductCount(); toastr.info('All rows removed.'); }
    });
  } else toastr.warning('At least one product row is required.');
});
$('#bulkUploadForm').submit(function(e){
  e.preventDefault();
  var products=[]; var hasError=false;
  $('#bulkProductsContainer .bulk-product-row').each(function(index){
    var name=$(this).find('.bulk-product-name').val().trim();
    var price=$(this).find('.bulk-product-price').val();
    var purchasePrice=$(this).find('.bulk-product-purchase-price').val()||0;
    var oldPrice=$(this).find('.bulk-product-old-price').val()||'';
    var category=$(this).find('.bulk-product-category').val();
    var subcategory=$(this).find('.bulk-product-subcategory').val();
    var childcategory=$(this).find('.bulk-product-childcategory').val();
    var brand=$(this).find('.bulk-product-brand').val();
    var unit=$(this).find('.bulk-product-unit').val();
    var stock=$(this).find('.bulk-product-stock').val()||0;
    var reorderLevel=$(this).find('.bulk-product-reorder-level').val()||0;
    var description=$(this).find('.bulk-product-description').val()||'';
    if(!name){ toastr.error('Row '+(index+1)+': Product name is required.'); hasError=true; return false; }
    if(!category){ toastr.error('Row '+(index+1)+': Site Category is required.'); hasError=true; return false; }
    if(!price||parseFloat(price)<=0){ toastr.error('Row '+(index+1)+': Valid selling price is required.'); hasError=true; return false; }
    products.push({ product_name:name, site_category_id:category||null, subcategory_id:subcategory||null, childcategory_id:childcategory||null, brand_id:brand||null, unit:unit||null,
      purchase_price:parseFloat(purchasePrice)||0, site_price:parseFloat(price)||0, site_old_price:oldPrice?parseFloat(oldPrice):null,
      site_stock:parseInt(stock,10)||0, reorder_level:parseInt(reorderLevel,10)||0, site_description:description });
  });
  if(hasError||products.length===0) return;
  var formData=new FormData();
  formData.append('action','bulk_save_products');
  formData.append('products',JSON.stringify(products));
  $('#bulkProductsContainer .bulk-product-row').each(function(index){
    var fileInput=$(this).find('.bulk-product-image')[0];
    if(fileInput&&fileInput.files&&fileInput.files.length>0) formData.append('site_images[]',fileInput.files[0]);
    else formData.append('site_images[]','');
  });
  showLoading();
  $.ajax({url:window.location.pathname,type:'POST',data:formData,contentType:false,processData:false,dataType:'json',
    beforeSend:function(){ $('#bulkSaveBtn').prop('disabled',true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...'); },
    success:function(response){
      if(response.success){
        toastr.success(response.message);
        if(response.failed_count>0&&response.errors){ $.each(response.errors,function(_,err){ toastr.warning(err); }); }
        $('#bulkUploadModal').modal('hide');
        setTimeout(function(){ location.reload(); },1000);
      } else toastr.error(response.message||'Error occurred while saving.');
    },
    error:function(xhr){ var msg='Error occurred while saving products.'; try{var r=JSON.parse(xhr.responseText); if(r.message)msg=r.message;}catch(e){} toastr.error(msg); },
    complete:function(){ $('#bulkSaveBtn').prop('disabled',false).html('<i class="bi bi-check2-all"></i> Save All Products'); hideLoading(); }
  });
});

/* ============================================
   OPTIMIZE ALL EXISTING IMAGES
   ============================================ */
$('#optimizeExistingImagesBtn').on('click',function(){
  var btn=$(this);
  if(!confirm('Optimize ALL existing Product, Category, Hero, Offer, Footer/Partner Logo and Customer Dashboard Banner images? Originals will NOT be deleted; dated backups will be kept.')) return;
  var stage='products',cursor=0,totalProcessed=0,totalOptimized=0,totalSkipped=0,totalSaved=0;
  btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span> Optimizing all...');
  function humanBytes(b){ if(b<1024)return b+' B'; if(b<1048576)return(b/1024).toFixed(1)+' KB'; return(b/1048576).toFixed(2)+' MB'; }
  function runBatch(){
    $.ajax({url:window.location.href,type:'POST',dataType:'json',data:{action:'optimize_all_existing_images',stage:stage,cursor:cursor},
      success:function(res){
        if(!res||!res.success){ toastr.error((res&&res.message)?res.message:'Optimization failed safely.'); finish(); return; }
        totalProcessed+=parseInt(res.processed||0,10);
        totalOptimized+=parseInt(res.optimized||0,10);
        totalSkipped+=parseInt(res.skipped||0,10);
        totalSaved+=parseInt(res.saved_bytes||0,10);
        btn.html('<span class="spinner-border spinner-border-sm me-1"></span> '+stage+': '+totalProcessed+' checked');
        if(res.done){ toastr.success('All existing images completed: '+totalOptimized+' optimized; approx. '+humanBytes(totalSaved)+' reduced.'); finish(); return; }
        if(parseInt(res.remaining||0,10)>0) cursor=parseInt(res.cursor||cursor,10);
        else { stage=res.next_stage||stage; cursor=0; }
        setTimeout(runBatch,150);
      },
      error:function(xhr){ var msg='Optimization stopped safely.'; if(xhr.responseJSON&&xhr.responseJSON.message)msg=xhr.responseJSON.message; toastr.error(msg); finish(); }
    });
  }
  function finish(){ btn.prop('disabled',false).html('<i class="bi bi-magic"></i> Optimize Images'); }
  runBatch();
});

/* ============================================
   CATEGORY SETUP
   ============================================ */
function resetSiteCategoryForm(){ $('#siteCategoryId').val('0'); $('#siteCategoryName').val(''); $('#siteCategoryStatus').val('1'); $('#siteCategorySort').val('0'); $('#siteCategoryImage').val(''); $('#siteCategoryImagePreview').hide().attr('src',''); }
function resetSiteSubcategoryForm(){ $('#siteSubcategoryId').val('0'); $('#siteSubcategoryName').val(''); $('#siteSubcategoryStatus').val('1'); $('#siteSubcategorySort').val('0'); }
function resetSiteChildcategoryForm(){ $('#siteChildcategoryId').val('0'); $('#siteChildcategoryName').val(''); $('#siteChildcategoryStatus').val('1'); $('#siteChildcategorySort').val('0'); $('#siteChildcategorySubcategory').empty().append('<option value="">Select Subcategory</option>').prop('disabled',true); }
function loadSetupSubcategories(categoryId,selectedId){
  var select=$('#siteChildcategorySubcategory');
  select.empty().append('<option value="">Select Subcategory</option>').prop('disabled',true);
  if(!categoryId) return $.Deferred().resolve().promise();
  return $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_subcategories',site_category_id:categoryId,include_inactive:selectedId?1:0},dataType:'json'})
    .done(function(response){
      if(response&&response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); select.prop('disabled',false); if(selectedId) select.val(String(selectedId)); }
    });
}
function loadSiteCategorySetup(){
  return $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_site_category_setup'},dataType:'json'})
    .done(function(response){
      if(!response||!response.success){ toastr.error((response&&response.message)||'Failed to load category setup'); return; }
      var categoryBody=$('#siteCategoryTableBody').empty();
      $.each(response.categories||[],function(_,item){
        var statusBadge=parseInt(item.status,10)===1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>';
        categoryBody.append('<tr>'+
          '<td><img src="/pos/site/images/'+escapeHtml(item.category_image||'')+'" onerror="this.style.display=\'none\'" style="width:45px;height:45px;object-fit:cover;border-radius:8px;border:1px solid #ddd;"></td>'+
          '<td>'+escapeHtml(item.name||'')+'</td>'+
          '<td>'+escapeHtml(item.sort_order||0)+'</td>'+
          '<td>'+statusBadge+'</td>'+
          '<td class="text-nowrap">'+
            '<button type="button" class="btn btn-xs btn-warning edit-site-category" data-id="'+item.id+'" data-name="'+escapeHtml(item.name||'')+'" data-status="'+item.status+'" data-sort="'+item.sort_order+'"><i class="bi bi-pencil"></i></button> '+
            '<button type="button" class="btn btn-xs btn-danger delete-site-category" data-id="'+item.id+'"><i class="bi bi-trash"></i></button>'+
          '</td></tr>');
      });
      if(!response.categories||response.categories.length===0) categoryBody.append('<tr><td colspan="5" class="text-center text-muted">No site categories yet.</td></tr>');

      var subCategorySelect=$('#siteSubcategoryCategory').empty().append('<option value="">Select Site Category</option>');
      var childCategorySelect=$('#siteChildcategoryCategory').empty().append('<option value="">Select Site Category</option>');
      $.each(response.categories||[],function(_,item){
        subCategorySelect.append($('<option>',{value:item.id,text:item.name}));
        childCategorySelect.append($('<option>',{value:item.id,text:item.name}));
      });

      var subBody=$('#siteSubcategoryTableBody').empty();
      $.each(response.subcategories||[],function(_,item){
        var statusBadge=parseInt(item.status,10)===1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>';
        subBody.append('<tr>'+
          '<td>'+escapeHtml(item.category_name||'')+'</td>'+
          '<td>'+escapeHtml(item.subcategory_name||'')+'</td>'+
          '<td>'+statusBadge+'</td>'+
          '<td class="text-nowrap">'+
            '<button type="button" class="btn btn-xs btn-warning edit-site-subcategory" data-id="'+item.id+'" data-category="'+item.site_category_id+'" data-name="'+escapeHtml(item.subcategory_name||'')+'" data-status="'+item.status+'" data-sort="'+item.sort_order+'"><i class="bi bi-pencil"></i></button> '+
            '<button type="button" class="btn btn-xs btn-danger delete-site-subcategory" data-id="'+item.id+'"><i class="bi bi-trash"></i></button>'+
          '</td></tr>');
      });
      if(!response.subcategories||response.subcategories.length===0) subBody.append('<tr><td colspan="4" class="text-center text-muted">No subcategories yet.</td></tr>');

      var childBody=$('#siteChildcategoryTableBody').empty();
      $.each(response.childcategories||[],function(_,item){
        var statusBadge=parseInt(item.status,10)===1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>';
        childBody.append('<tr>'+
          '<td>'+escapeHtml(item.category_name||'')+'</td>'+
          '<td>'+escapeHtml(item.subcategory_name||'')+'</td>'+
          '<td>'+escapeHtml(item.childcategory_name||'')+'</td>'+
          '<td>'+statusBadge+'</td>'+
          '<td class="text-nowrap">'+
            '<button type="button" class="btn btn-xs btn-warning edit-site-childcategory" data-id="'+item.id+'" data-category="'+item.site_category_id+'" data-subcategory="'+item.subcategory_id+'" data-name="'+escapeHtml(item.childcategory_name||'')+'" data-status="'+item.status+'" data-sort="'+item.sort_order+'"><i class="bi bi-pencil"></i></button> '+
            '<button type="button" class="btn btn-xs btn-danger delete-site-childcategory" data-id="'+item.id+'"><i class="bi bi-trash"></i></button>'+
          '</td></tr>');
      });
      if(!response.childcategories||response.childcategories.length===0) childBody.append('<tr><td colspan="5" class="text-center text-muted">No child categories yet.</td></tr>');
    }).fail(function(){ toastr.error('Failed to load category setup'); });
}
$('#siteCategorySetupBtn').click(function(){
  resetSiteCategoryForm(); resetSiteSubcategoryForm(); resetSiteChildcategoryForm();
  showLoading();
  loadSiteCategorySetup().always(function(){ hideLoading(); $('#siteCategorySetupModal').modal('show'); });
});
$('#siteChildcategoryCategory').on('change',function(){ loadSetupSubcategories($(this).val(),null); });
$('#resetSiteCategoryForm').click(resetSiteCategoryForm);
$('#resetSiteSubcategoryForm').click(resetSiteSubcategoryForm);
$('#resetSiteChildcategoryForm').click(resetSiteChildcategoryForm);
$('#siteCategoryImage').on('change',function(){
  var file=this.files&&this.files[0];
  var preview=$('#siteCategoryImagePreview');
  if(!file){ preview.hide().attr('src',''); return; }
  var reader=new FileReader();
  reader.onload=function(e){ preview.attr('src',e.target.result).show(); };
  reader.readAsDataURL(file);
});
$('#siteCategoryForm').submit(function(e){
  e.preventDefault();
  var fd=new FormData(this); fd.append('action','save_site_category');
  var btn=$(this).find('button[type=submit]'); btn.prop('disabled',true).text('Saving...');
  $.ajax({url:window.location.pathname,type:'POST',data:fd,processData:false,contentType:false,dataType:'json',success:function(response){
    if(response.success){ toastr.success(response.message); resetSiteCategoryForm(); loadSiteCategorySetup(); loadDropdowns(); loadFilterDropdowns(); }
    else toastr.error(response.message||'Failed to save site category');
  },error:function(){ toastr.error('Failed to save site category'); },complete:function(){ btn.prop('disabled',false).text('Save'); }});
});
$('#siteSubcategoryForm').submit(function(e){
  e.preventDefault();
  var data=$(this).serializeArray(); data.push({name:'action',value:'save_site_subcategory'});
  $.post(window.location.pathname,$.param(data),function(response){
    if(response.success){ toastr.success(response.message); resetSiteSubcategoryForm(); loadSiteCategorySetup(); }
    else toastr.error(response.message||'Failed to save subcategory');
  },'json').fail(function(){ toastr.error('Failed to save subcategory'); });
});
$('#siteChildcategoryForm').submit(function(e){
  e.preventDefault();
  var data=$(this).serializeArray(); data.push({name:'action',value:'save_site_childcategory'});
  $.post(window.location.pathname,$.param(data),function(response){
    if(response.success){ toastr.success(response.message); resetSiteChildcategoryForm(); loadSiteCategorySetup(); }
    else toastr.error(response.message||'Failed to save child category');
  },'json').fail(function(){ toastr.error('Failed to save child category'); });
});
$(document).on('click','.edit-site-category',function(){ $('#siteCategoryId').val($(this).data('id')); $('#siteCategoryName').val($(this).data('name')); $('#siteCategoryStatus').val(String($(this).data('status'))); $('#siteCategorySort').val($(this).data('sort')); });
$(document).on('click','.edit-site-subcategory',function(){ $('#siteSubcategoryId').val($(this).data('id')); $('#siteSubcategoryCategory').val(String($(this).data('category'))); $('#siteSubcategoryName').val($(this).data('name')); $('#siteSubcategoryStatus').val(String($(this).data('status'))); $('#siteSubcategorySort').val($(this).data('sort')); });
$(document).on('click','.edit-site-childcategory',function(){
  var btn=$(this); var categoryId=String(btn.data('category'));
  $('#siteChildcategoryId').val(btn.data('id')); $('#siteChildcategoryCategory').val(categoryId);
  $('#siteChildcategoryName').val(btn.data('name')); $('#siteChildcategoryStatus').val(String(btn.data('status'))); $('#siteChildcategorySort').val(btn.data('sort'));
  loadSetupSubcategories(categoryId,btn.data('subcategory'));
});
$(document).on('click','.delete-site-category',function(){
  var id=$(this).data('id');
  swal({title:'Delete site category?',text:'Only unused site categories can be deleted.',icon:'warning',buttons:['Cancel','Delete'],dangerMode:true}).then(function(ok){
    if(!ok) return;
    $.post(window.location.pathname,{action:'delete_site_category',id:id},function(response){
      if(response.success){ toastr.success(response.message); loadSiteCategorySetup(); loadDropdowns(); loadFilterDropdowns(); }
      else toastr.error(response.message||'Failed to delete site category');
    },'json');
  });
});
$(document).on('click','.delete-site-subcategory',function(){
  var id=$(this).data('id');
  swal({title:'Delete subcategory?',text:'Only unused subcategories can be deleted.',icon:'warning',buttons:['Cancel','Delete'],dangerMode:true}).then(function(ok){
    if(!ok) return;
    $.post(window.location.pathname,{action:'delete_site_subcategory',id:id},function(response){
      if(response.success){ toastr.success(response.message); loadSiteCategorySetup(); }
      else toastr.error(response.message||'Failed to delete subcategory');
    },'json');
  });
});
$(document).on('click','.delete-site-childcategory',function(){
  var id=$(this).data('id');
  swal({title:'Delete child category?',text:'Only unused child categories can be deleted.',icon:'warning',buttons:['Cancel','Delete'],dangerMode:true}).then(function(ok){
    if(!ok) return;
    $.post(window.location.pathname,{action:'delete_site_childcategory',id:id},function(response){
      if(response.success){ toastr.success(response.message); loadSiteCategorySetup(); }
      else toastr.error(response.message||'Failed to delete child category');
    },'json');
  });
});

/* ============================================
   SECTION MANAGEMENT
   ============================================ */
function loadFilterDropdowns(){
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_categories'},dataType:'json',success:function(response){
    var select=$('#sectionFilterCategory'); select.empty().append($('<option>',{value:'',text:'All Site Categories'}));
    if(response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }});
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_brands'},dataType:'json',success:function(response){
    var select=$('#sectionFilterBrand'); select.empty().append($('<option>',{value:'',text:'All Brands'}));
    if(response.success){ $.each(response.data||[],function(_,item){ select.append($('<option>',{value:item.id,text:item.name})); }); }
  }});
}
function loadSectionProducts(filterType,categoryId,brandId){
  filterType=filterType||$('#sectionFilterType').val()||'all';
  categoryId=categoryId||$('#sectionFilterCategory').val()||'';
  brandId=brandId||$('#sectionFilterBrand').val()||'';
  var $tbody=$('#sectionProductsBody');
  $tbody.html('<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></td></tr>');
  showLoading();
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'get_all_products_for_section',filter_type:filterType,site_category_id:categoryId,brand_id:brandId},dataType:'json',
    success:function(response){
      if(response.success&&response.data.length>0){
        var html='';
        $.each(response.data,function(index,product){
          var image=product.site_images?getImageUrl(product.site_images.split(',')[0].trim()):'https://via.placeholder.com/50/cccccc/000000?text=No+Image';
          var sections=[];
          if(product.is_featured) sections.push('<span class="badge bg-warning">⭐ Featured</span>');
          if(product.is_new) sections.push('<span class="badge bg-info">🆕 New</span>');
          if(product.is_top_selling) sections.push('<span class="badge bg-success">🔥 Top Selling</span>');
          if(sections.length===0) sections.push('<span class="badge bg-secondary">None</span>');
          var statusBadge=(product.site_status&&product.site_visible)?'<span class="badge bg-success">Active</span>':'<span class="badge bg-danger">Inactive</span>';
          html+='<tr>'+
            '<td><input type="checkbox" class="product-select" data-id="'+product.id+'"></td>'+
            '<td><div class="d-flex align-items-center gap-2"><img src="'+image+'" class="rv-thumb"><strong>'+escapeHtml(product.product_name)+'</strong></div></td>'+
            '<td>'+escapeHtml(product.category_name||'—')+'</td>'+
            '<td>'+escapeHtml(product.brand_name||'—')+'</td>'+
            '<td>৳ '+parseFloat(product.site_price).toFixed(2)+'</td>'+
            '<td>'+product.site_stock+'</td>'+
            '<td>'+sections.join(' ')+' '+statusBadge+'</td>'+
          '</tr>';
        });
        $tbody.html(html); updateSelectedCount();
      } else {
        $tbody.html('<tr><td colspan="7" class="text-center py-4"><i class="bi bi-box-seam" style="font-size:48px;color:#ddd;"></i><p class="mt-3 text-muted">No products found.</p></td></tr>');
      }
    },
    error:function(){ $tbody.html('<tr><td colspan="7" class="text-center py-4 text-danger">Failed to load products.</td></tr>'); },
    complete:function(){ hideLoading(); }
  });
}
function updateSelectedCount(){ var count=$('.product-select:checked').length; $('#selectedCount').text(count+' product'+(count!==1?'s':'')+' selected'); }
$(document).on('change','#selectAllProducts',function(){ $('.product-select').prop('checked',$(this).is(':checked')); updateSelectedCount(); });
$(document).on('change','.product-select',function(){ updateSelectedCount(); });
$('#applySectionFilter').click(function(){ loadSectionProducts(); });
$('#sectionFilterType').on('change',function(){ loadSectionProducts(); });

function bulkAssignSection(sectionType){
  var selectedIds=[]; $('.product-select:checked').each(function(){ selectedIds.push($(this).data('id')); });
  if(selectedIds.length===0){ toastr.warning('Please select at least one product.'); return; }
  var sectionNames={featured:'Featured',new:'New',top_selling:'Top Selling'};
  var iconMap={featured:'⭐',new:'🆕',top_selling:'🔥'};
  swal({title:iconMap[sectionType]+' Add to '+sectionNames[sectionType]+'?',text:selectedIds.length+' product(s) will be added.',icon:'question',buttons:['Cancel','Yes, Add'],dangerMode:false}).then(function(willAdd){
    if(willAdd){
      showLoading();
      $.ajax({url:window.location.pathname,type:'POST',data:{action:'bulk_assign_section',product_ids:JSON.stringify(selectedIds),section_type:sectionType,value:1},dataType:'json',
        success:function(response){
          if(response.success){
            toastr.success(response.message); loadSectionProducts();
            $.each(selectedIds,function(_,id){ var $row=$('#row_'+id); var $btn=$row.find('[data-section="'+sectionType+'"]'); if($btn.length) updateButtonState($btn,sectionType,1); });
          } else toastr.error(response.message);
        },
        error:function(){ toastr.error('Failed to assign section.'); },
        complete:function(){ hideLoading(); }
      });
    }
  });
}
$('#assignFeatured').click(function(){ bulkAssignSection('featured'); });
$('#assignNew').click(function(){ bulkAssignSection('new'); });
$('#assignTopSelling').click(function(){ bulkAssignSection('top_selling'); });
$('#removeFromSections').click(function(){
  var selectedIds=[]; $('.product-select:checked').each(function(){ selectedIds.push($(this).data('id')); });
  if(selectedIds.length===0){ toastr.warning('Please select at least one product.'); return; }
  swal({title:'❌ Remove from all sections?',text:selectedIds.length+' product(s) will be removed.',icon:'warning',buttons:['Cancel','Yes, Remove'],dangerMode:true}).then(function(willRemove){
    if(willRemove){
      showLoading();
      var sections=['featured','new','top_selling'], promises=[];
      $.each(sections,function(_,sectionType){
        promises.push($.ajax({url:window.location.pathname,type:'POST',data:{action:'bulk_assign_section',product_ids:JSON.stringify(selectedIds),section_type:sectionType,value:0},dataType:'json'}));
      });
      $.when.apply($,promises).done(function(){
        toastr.success('Products removed from all sections.'); loadSectionProducts();
        $.each(selectedIds,function(_,id){ var $row=$('#row_'+id); ['featured','new','top_selling'].forEach(function(sectionType){ var $btn=$row.find('[data-section="'+sectionType+'"]'); if($btn.length) updateButtonState($btn,sectionType,0); }); });
      }).fail(function(){ toastr.error('Failed to remove from sections.'); }).always(function(){ hideLoading(); });
    }
  });
});
$(document).on('click','.section-toggle-btn',function(){
  var $btn=$(this); var productId=$btn.data('id'); var section=$btn.data('section');
  var isActive=$btn.hasClass('active-warning')||$btn.hasClass('active-info')||$btn.hasClass('active-success')||$btn.hasClass('active-primary')||$btn.hasClass('active-danger');
  var newValue=isActive?0:1;
  updateButtonState($btn,section,newValue);
  showLoading();
  $.ajax({url:window.location.pathname,type:'POST',data:{action:'update_section',product_id:productId,section_type:section,value:newValue},dataType:'json',
    success:function(response){
      if(response.success){ toastr.success(response.message); if(section==='status'||section==='visibility') updateStatusBadge(productId); }
      else { toastr.error(response.message); updateButtonState($btn,section,isActive?1:0); }
    },
    error:function(){ toastr.error('Failed to update section'); updateButtonState($btn,section,isActive?1:0); },
    complete:function(){ hideLoading(); }
  });
});
function updateButtonState($btn,section,value){
  var map={
    featured:{on:'active-warning',labelOn:'⭐ Featured',labelOff:'⭐ Featured'},
    new:{on:'active-info',labelOn:'🆕 New',labelOff:'🆕 New'},
    top_selling:{on:'active-success',labelOn:'🔥 Top',labelOff:'🔥 Top'},
    status:{on:'active-success',labelOn:'🟢 Active',labelOff:'🔴 Inactive',offClass:'active-danger'},
    visibility:{on:'active-primary',labelOn:'👁️ Visible',labelOff:'👁️ Hidden'}
  };
  var cfg=map[section]; if(!cfg) return;
  $btn.removeClass('active-warning active-info active-success active-primary active-danger');
  if(value) $btn.addClass(cfg.on).text(cfg.labelOn);
  else { if(cfg.offClass) $btn.addClass(cfg.offClass); $btn.text(cfg.labelOff); }
}
function updateStatusBadge(productId){
  var $row=$('#row_'+productId);
  var $statusBadge=$row.find('.badge-status');
  var $statusBtn=$row.find('.status-toggle');
  var $visibilityBtn=$row.find('[data-section="visibility"]');
  var isActive=$statusBtn.hasClass('active-success');
  var isVisible=$visibilityBtn.hasClass('active-primary');
  if(isActive&&isVisible) $statusBadge.removeClass('bg-danger').addClass('bg-success').text('Active');
  else $statusBadge.removeClass('bg-success').addClass('bg-danger').text('Inactive');
}
$('#sectionManagementBtn').click(function(){ $('#sectionManagementModal').modal('show'); loadFilterDropdowns(); loadSectionProducts('all'); });

/* ============================================
   DYNAMIC MODALS - shared helper
   ============================================ */
function ajaxAction(action,data,done){
  data=data||{}; data.action=action;
  $.ajax({url:window.location.pathname,type:'POST',data:data,dataType:'json'})
    .done(function(res){ if(res&&res.success) done&&done(res); else toastr.error((res&&res.message)||'Request failed'); })
    .fail(function(xhr){ var msg='Request failed'; try{var r=JSON.parse(xhr.responseText); if(r.message)msg=r.message;}catch(e){} toastr.error(msg); });
}
function submitDynamicForm($form,action,onSuccess){
  var fd=new FormData($form[0]); fd.append('action',action);
  showLoading();
  $.ajax({url:window.location.pathname,type:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(function(res){ if(res.success){ toastr.success(res.message||'Saved'); onSuccess&&onSuccess(res); } else toastr.error(res.message||'Save failed'); })
    .fail(function(xhr){ var msg='Save failed'; try{var r=JSON.parse(xhr.responseText); if(r.message)msg=r.message;}catch(e){} toastr.error(msg); })
    .always(hideLoading);
}
function imageThumb(url,alt){ return url?'<img src="'+escapeHtml(url)+'" alt="'+escapeHtml(alt||'')+'" class="rv-thumb-lg">':'<span class="text-muted">No image</span>'; }
function toDateTimeLocal(value){ if(!value) return ''; return String(value).replace(' ','T').slice(0,16); }

/* ============================================
   SHIPPING CHARGE
   ============================================ */
var shippingMethodCache={};
function formatMoneyAdmin(value){ var n=parseFloat(value||0); return '৳'+(isNaN(n)?'0':n.toLocaleString(undefined,{maximumFractionDigits:2})); }
function resetShippingChargeForm(){
  var f=$('#shippingChargeForm'); f[0].reset();
  f.find('[name=id]').val('0'); f.find('[name=cost]').val('0'); f.find('[name=free_above]').val('');
  f.find('[name=minimum_order]').val('0'); f.find('[name=sort_order]').val('0'); f.find('[name=status]').val('1');
}
function loadShippingMethodsManagement(){
  ajaxAction('get_shipping_methods_management',{},function(res){
    shippingMethodCache={}; var html='';
    (res.data||[]).forEach(function(x){
      shippingMethodCache[x.id]=x;
      html+='<tr>'+
        '<td><strong>'+escapeHtml(x.name||'')+'</strong><br><small class="text-muted">'+escapeHtml(x.description||'')+'</small></td>'+
        '<td>'+formatMoneyAdmin(x.cost)+'</td>'+
        '<td>'+(x.free_above===null||x.free_above===''?'<span class="text-muted">—</span>':formatMoneyAdmin(x.free_above))+'</td>'+
        '<td>'+formatMoneyAdmin(x.minimum_order)+'</td>'+
        '<td>'+escapeHtml(x.estimated_days||'')+'</td>'+
        '<td>'+escapeHtml(x.sort_order)+'</td>'+
        '<td>'+(String(x.status)==='1'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>')+'</td>'+
        '<td><button class="btn btn-sm btn-primary edit-shipping-method" data-id="'+x.id+'">Edit</button> <button class="btn btn-sm btn-danger delete-shipping-method" data-id="'+x.id+'">Delete</button></td>'+
      '</tr>';
    });
    $('#shippingMethodsTableBody').html(html||'<tr><td colspan="8" class="text-center text-muted">No shipping methods found.</td></tr>');
  });
}
$('#shippingChargeMenu').on('click',function(){ resetShippingChargeForm(); $('#shippingChargeModal').modal('show'); loadShippingMethodsManagement(); });
$('#shippingChargeForm').on('submit',function(e){ e.preventDefault(); submitDynamicForm($(this),'save_shipping_method',function(){ resetShippingChargeForm(); loadShippingMethodsManagement(); }); });
$('#resetShippingChargeBtn').on('click',resetShippingChargeForm);
$(document).on('click','.edit-shipping-method',function(){
  var x=shippingMethodCache[$(this).data('id')]; if(!x) return;
  var f=$('#shippingChargeForm');
  f.find('[name=id]').val(x.id); f.find('[name=name]').val(x.name||''); f.find('[name=description]').val(x.description||'');
  f.find('[name=cost]').val(x.cost||0); f.find('[name=free_above]').val(x.free_above===null?'':x.free_above);
  f.find('[name=minimum_order]').val(x.minimum_order||0); f.find('[name=estimated_days]').val(x.estimated_days||'');
  f.find('[name=sort_order]').val(x.sort_order||0); f.find('[name=status]').val(String(x.status));
});
$(document).on('click','.delete-shipping-method',function(){
  var id=$(this).data('id');
  if(!confirm('Delete this shipping method?')) return;
  ajaxAction('delete_shipping_method',{id:id},function(r){ toastr.success(r.message); resetShippingChargeForm(); loadShippingMethodsManagement(); });
});

/* ============================================
   FOOTER SETTINGS
   ============================================ */
function addFooterLinkRow(link){
  link=link||{label:'',url:'#',sort:($('#footerLinksContainer .footer-link-row').length+1)};
  $('#footerLinksContainer').append('<div class="row g-2 mb-2 footer-link-row">'+
    '<div class="col-md-5"><input class="form-control footer-link-label" placeholder="Link label" value="'+escapeHtml(link.label||'')+'"></div>'+
    '<div class="col-md-5"><input class="form-control footer-link-url" placeholder="/about or https://..." value="'+escapeHtml(link.url||'#')+'"></div>'+
    '<div class="col-md-1"><input class="form-control footer-link-sort" type="number" min="0" value="'+escapeHtml(link.sort||0)+'"></div>'+
    '<div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-footer-link">×</button></div></div>');
}
function serializeFooterLinks(){
  var links=[];
  $('#footerLinksContainer .footer-link-row').each(function(){
    var label=$(this).find('.footer-link-label').val().trim();
    if(label) links.push({label:label,url:$(this).find('.footer-link-url').val().trim()||'#',sort:parseInt($(this).find('.footer-link-sort').val()||0,10)});
  });
  return links;
}
function loadFooterSettings(){
  ajaxAction('get_footer_settings',{},function(res){
    var d=res.data||{}, f=$('#footerSettingsForm');
    f.find('[name=brand_name]').val(d.brand_name||'Ricevaly'); f.find('[name=tagline]').val(d.tagline||'');
    f.find('[name=about_text]').val(d.about_text||''); f.find('[name=address]').val(d.address||'');
    f.find('[name=phone]').val(d.phone||''); f.find('[name=email]').val(d.email||'');
    f.find('[name=information_title]').val(d.information_title||'Information');
    f.find('[name=status]').val(String(d.status==0?0:1)); f.find('[name=remove_logo]').prop('checked',false);
    $('#footerLogoPreview').html(imageThumb(d.logo_url||'','Footer logo'));
    $('#footerLinksContainer').empty();
    var links=d.information_links_array||[];
    if(!links.length) addFooterLinkRow(); else links.forEach(addFooterLinkRow);
  });
}
$('#footerSettingsMenu').on('click',function(){ $('#footerSettingsModal').modal('show'); loadFooterSettings(); });
$('#addFooterLinkBtn').on('click',function(){ addFooterLinkRow(); });
$(document).on('click','.remove-footer-link',function(){ $(this).closest('.footer-link-row').remove(); });
$('#footerSettingsForm').on('submit',function(e){ e.preventDefault(); $('#footerInformationLinksJson').val(JSON.stringify(serializeFooterLinks())); submitDynamicForm($(this),'save_footer_settings',loadFooterSettings); });

/* ============================================
   PARTNERS
   ============================================ */
var partnerCache={};
function resetPartnerForm(){
  var f=$('#partnerForm'); f[0].reset();
  f.find('[name=id]').val('0'); f.find('[name=sort_order]').val('0'); f.find('[name=status]').val('1');
  $('#partnerLogoPreview').empty();
  $('#partnerRemoveLogoWrap').addClass('d-none').find('input').prop('checked',false);
}
function loadPartnersManagement(){
  ajaxAction('get_partners_management',{},function(res){
    var s=res.section||{}, sf=$('#partnerSectionForm');
    sf.find('[name=eyebrow_text]').val(s.eyebrow_text||'OUR PARTNERS');
    sf.find('[name=title]').val(s.title||'আমাদের পার্টনার');
    sf.find('[name=subtitle]').val(s.subtitle||''); sf.find('[name=status]').val(String(s.status==0?0:1));
    partnerCache={}; var html='';
    (res.partners||[]).forEach(function(x){
      partnerCache[x.id]=x;
      html+='<tr><td>'+imageThumb(x.logo_url,x.partner_name)+'</td><td>'+escapeHtml(x.partner_name)+'</td><td>'+escapeHtml(x.website_url||'')+'</td><td>'+escapeHtml(x.sort_order)+'</td><td>'+(String(x.status)==='1'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>')+'</td><td><button class="btn btn-sm btn-primary edit-partner" data-id="'+x.id+'">Edit</button> <button class="btn btn-sm btn-danger delete-partner" data-id="'+x.id+'">Delete</button></td></tr>';
    });
    $('#partnersTableBody').html(html||'<tr><td colspan="6" class="text-center text-muted">No partners found.</td></tr>');
  });
}
$('#partnersMenu').on('click',function(){ resetPartnerForm(); $('#partnersModal').modal('show'); loadPartnersManagement(); });
$('#partnerSectionForm').on('submit',function(e){ e.preventDefault(); var d=$(this).serializeArray(),obj={}; d.forEach(function(x){ obj[x.name]=x.value; }); ajaxAction('save_partner_section',obj,function(r){ toastr.success(r.message); loadPartnersManagement(); }); });
$('#partnerForm').on('submit',function(e){ e.preventDefault(); submitDynamicForm($(this),'save_partner',function(){ resetPartnerForm(); loadPartnersManagement(); }); });
$('#resetPartnerBtn').on('click',resetPartnerForm);
$(document).on('click','.edit-partner',function(){
  var x=partnerCache[$(this).data('id')]; if(!x) return;
  var f=$('#partnerForm');
  f.find('[name=id]').val(x.id); f.find('[name=partner_name]').val(x.partner_name);
  f.find('[name=website_url]').val(x.website_url||''); f.find('[name=sort_order]').val(x.sort_order||0);
  f.find('[name=status]').val(String(x.status));
  $('#partnerLogoPreview').html(imageThumb(x.logo_url,x.partner_name));
  $('#partnerRemoveLogoWrap').toggleClass('d-none',!x.logo_url).find('input').prop('checked',false);
});
$(document).on('click','.delete-partner',function(){
  var id=$(this).data('id'); if(!confirm('Delete this partner?')) return;
  ajaxAction('delete_partner',{id:id},function(r){ toastr.success(r.message); resetPartnerForm(); loadPartnersManagement(); });
});

/* ============================================
   CUSTOMER REVIEWS
   ============================================ */
function loadSiteReviews(){
  ajaxAction('get_site_reviews',{status:$('#siteReviewStatusFilter').val()||'all'},function(res){
    var html='';
    (res.data||[]).forEach(function(x){
      var stars='★'.repeat(Math.max(0,Math.min(5,parseInt(x.rating||0,10))))+'☆'.repeat(Math.max(0,5-parseInt(x.rating||0,10)));
      html+='<tr><td><strong>'+escapeHtml(x.customer_name||'Ricevaly Customer')+'</strong><br><small>'+escapeHtml(x.customer_email||'')+'</small></td><td class="text-warning">'+stars+'</td><td style="min-width:280px">'+escapeHtml(x.review||'')+'</td><td><span class="badge '+(x.status==='approved'?'bg-success':(x.status==='rejected'?'bg-danger':'bg-warning'))+'">'+escapeHtml(x.status)+'</span></td><td>'+escapeHtml(x.created_at||'')+'</td><td><button class="btn btn-sm btn-success review-status" data-id="'+x.id+'" data-status="approved">Approve</button> <button class="btn btn-sm btn-warning review-status" data-id="'+x.id+'" data-status="rejected">Reject</button> <button class="btn btn-sm btn-danger delete-site-review" data-id="'+x.id+'">Delete</button></td></tr>';
    });
    $('#siteReviewsTableBody').html(html||'<tr><td colspan="6" class="text-center text-muted">No site reviews found.</td></tr>');
  });
}
$('#customerReviewsMenu').on('click',function(){ $('#customerReviewsModal').modal('show'); loadSiteReviews(); });
$('#siteReviewStatusFilter').on('change',loadSiteReviews);
$(document).on('click','.review-status',function(){ ajaxAction('update_site_review_status',{id:$(this).data('id'),status:$(this).data('status')},function(r){ toastr.success(r.message); loadSiteReviews(); }); });
$(document).on('click','.delete-site-review',function(){ if(!confirm('Delete this customer review?')) return; ajaxAction('delete_site_review',{id:$(this).data('id')},function(r){ toastr.success(r.message); loadSiteReviews(); }); });

/* ============================================
   BREAKING NEWS
   ============================================ */
var breakingNewsCache={};
function resetBreakingNewsForm(){ var f=$('#breakingNewsForm'); f[0].reset(); f.find('[name=id]').val('0'); f.find('[name=sort_order]').val('0'); f.find('[name=status]').val('1'); f.find('[name=start_at],[name=end_at]').val(''); }
function loadBreakingNewsManagement(){
  ajaxAction('get_breaking_news_management',{},function(res){
    breakingNewsCache={}; var html='';
    (res.data||[]).forEach(function(x){
      breakingNewsCache[x.id]=x;
      html+='<tr>'+
        '<td style="min-width:300px"><strong>'+escapeHtml(x.title||'')+'</strong></td>'+
        '<td><small>'+escapeHtml(x.start_at||'Always')+' → '+escapeHtml(x.end_at||'Always')+'</small></td>'+
        '<td>'+escapeHtml(x.sort_order||0)+'</td>'+
        '<td>'+(String(x.status)==='1'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>')+'</td>'+
        '<td><button class="btn btn-sm btn-primary edit-breaking-news" data-id="'+x.id+'">Edit</button> <button class="btn btn-sm btn-danger delete-breaking-news" data-id="'+x.id+'">Delete</button></td>'+
      '</tr>';
    });
    $('#breakingNewsTableBody').html(html||'<tr><td colspan="5" class="text-center text-muted">No breaking news found.</td></tr>');
  });
}
$('#breakingNewsMenu').on('click',function(){ resetBreakingNewsForm(); $('#breakingNewsModal').modal('show'); loadBreakingNewsManagement(); });
$('#breakingNewsForm').on('submit',function(e){ e.preventDefault(); submitDynamicForm($(this),'save_breaking_news',function(){ resetBreakingNewsForm(); loadBreakingNewsManagement(); }); });
$('#resetBreakingNewsBtn').on('click',resetBreakingNewsForm);
$(document).on('click','.edit-breaking-news',function(){
  var x=breakingNewsCache[$(this).data('id')]; if(!x) return;
  var f=$('#breakingNewsForm');
  f.find('[name=id]').val(x.id); f.find('[name=title]').val(x.title||'');
  f.find('[name=sort_order]').val(x.sort_order||0); f.find('[name=status]').val(String(x.status));
  f.find('[name=start_at]').val(toDateTimeLocal(x.start_at)); f.find('[name=end_at]').val(toDateTimeLocal(x.end_at));
});
$(document).on('click','.delete-breaking-news',function(){
  var id=$(this).data('id'); if(!confirm('Delete this breaking news item?')) return;
  ajaxAction('delete_breaking_news',{id:id},function(r){ toastr.success(r.message); resetBreakingNewsForm(); loadBreakingNewsManagement(); });
});

/* ============================================
   HERO SLIDER
   ============================================ */
var heroCache={};
function toggleHeroSliderMode(){
  var full=$('#heroSliderType').val()==='full_image';
  $('.hero-content-field').toggleClass('d-none',full);
  if(full) $('#heroSliderForm [name=title]').prop('required',false);
  else $('#heroSliderForm [name=title]').prop('required',true);
}
$('#heroSliderType').on('change',toggleHeroSliderMode);
function resetHeroSliderForm(){
  var f=$('#heroSliderForm'); f[0].reset(); f.find('[name=id]').val('0');
  f.find('[name=background_start_color]').val('#b3e4e6'); f.find('[name=background_end_color]').val('#b5df99');
  f.find('[name=sort_order]').val('0'); f.find('[name=status]').val('1');
  $('#heroImagePreview').empty();
  $('#heroRemoveImageWrap,#heroRemoveMobileImageWrap').addClass('d-none').find('input').prop('checked',false);
}
function loadHeroSliders(){
  ajaxAction('get_hero_sliders',{},function(res){
    heroCache={}; var html='';
    (res.data||[]).forEach(function(x){
      heroCache[x.id]=x;
      html+='<tr><td>'+imageThumb(x.image_url,x.title)+'</td><td><strong>'+escapeHtml(x.title)+'</strong><br><small>'+escapeHtml(x.subtitle||'')+'</small></td><td><small>'+escapeHtml(x.start_at||'Always')+' → '+escapeHtml(x.end_at||'Always')+'</small></td><td>'+escapeHtml(x.sort_order)+'</td><td>'+(String(x.status)==='1'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>')+'</td><td><button class="btn btn-sm btn-primary edit-hero" data-id="'+x.id+'">Edit</button> <button class="btn btn-sm btn-danger delete-hero" data-id="'+x.id+'">Delete</button></td></tr>';
    });
    $('#heroSlidersTableBody').html(html||'<tr><td colspan="6" class="text-center text-muted">No hero slides found.</td></tr>');
  });
}
$('#heroSliderMenu').on('click',function(){ resetHeroSliderForm(); $('#heroSliderModal').modal('show'); loadHeroSliders(); });
$('#heroSliderForm').on('submit',function(e){ e.preventDefault(); submitDynamicForm($(this),'save_hero_slider',function(){ resetHeroSliderForm(); loadHeroSliders(); }); });
$('#resetHeroSliderBtn').on('click',resetHeroSliderForm);
$(document).on('click','.edit-hero',function(){
  var x=heroCache[$(this).data('id')]; if(!x) return;
  var f=$('#heroSliderForm');
  f.find('[name=id]').val(x.id); f.find('[name=title]').val(x.title||'');
  f.find('[name=subtitle]').val(x.subtitle||''); f.find('[name=description]').val(x.description||'');
  f.find('[name=button_text]').val(x.button_text||''); f.find('[name=button_url]').val(x.button_url||'');
  f.find('[name=background_start_color]').val(x.background_start_color||'#b3e4e6');
  f.find('[name=background_end_color]').val(x.background_end_color||'#b5df99');
  f.find('[name=sort_order]').val(x.sort_order||0); f.find('[name=status]').val(String(x.status));
  f.find('[name=start_at]').val(toDateTimeLocal(x.start_at)); f.find('[name=end_at]').val(toDateTimeLocal(x.end_at));
  $('#heroImagePreview').html('<div>Desktop: '+imageThumb(x.image_url,x.title)+'</div><div>Mobile: '+imageThumb(x.mobile_image_url,x.title)+'</div>');
  $('#heroRemoveImageWrap').toggleClass('d-none',!x.image_url);
  $('#heroRemoveMobileImageWrap').toggleClass('d-none',!x.mobile_image_url);
});
$(document).on('click','.delete-hero',function(){ if(!confirm('Delete this hero slide?')) return; ajaxAction('delete_hero_slider',{id:$(this).data('id')},function(r){ toastr.success(r.message); resetHeroSliderForm(); loadHeroSliders(); }); });

/* ============================================
   OFFER SLIDER
   ============================================ */
var offerCache={}, offerPreviewRows=[], offerPreviewIndex=0, offerPreviewTimer=null;
function renderOfferFormImage(url){ if(!url){ $('#offerImagePreview').empty(); return; } $('#offerImagePreview').html('<div class="small text-muted mb-1">Current / selected image</div><img src="'+escapeHtml(url)+'" style="width:100%;max-width:460px;aspect-ratio:16/7;object-fit:cover;border-radius:10px;border:1px solid #e2e8e5;">'); }
function resetOfferBannerForm(){ var f=$('#offerBannerForm'); f[0].reset(); f.find('[name=id]').val('0'); f.find('[name=button_url]').val(''); $('#offerImagePreview').empty(); }
function offerStateBadge(x){ if(String(x.status)!=='1') return '<span class="badge bg-secondary">Inactive</span>'; return '<span class="badge bg-success">Live</span>'; }
function stopOfferPreviewAuto(){ if(offerPreviewTimer){ clearInterval(offerPreviewTimer); offerPreviewTimer=null; } }
function showOfferPreview(index){
  if(!offerPreviewRows.length) return;
  offerPreviewIndex=(index+offerPreviewRows.length)%offerPreviewRows.length;
  $('#offerSliderPreview .admin-offer-preview-slide').removeClass('active').eq(offerPreviewIndex).addClass('active');
  $('#offerSliderPreview .admin-offer-preview-dots button').removeClass('active').eq(offerPreviewIndex).addClass('active');
}
function startOfferPreviewAuto(){ stopOfferPreviewAuto(); if(offerPreviewRows.length>1) offerPreviewTimer=setInterval(function(){ showOfferPreview(offerPreviewIndex+1); },5000); }
function renderOfferSliderPreview(rows){
  offerPreviewRows=(rows||[]).filter(function(x){ return String(x.status)==='1'&&!!x.image_url; });
  offerPreviewIndex=0;
  var box=$('#offerSliderPreview');
  $('#offerLiveCount').text(offerPreviewRows.length+' live slide'+(offerPreviewRows.length===1?'':'s'));
  stopOfferPreviewAuto();
  if(!offerPreviewRows.length){ box.html('<div class="admin-offer-preview-empty"><div><strong>No full-image offer slides.</strong><br><span class="small">Image + link save করলে এখানে preview দেখা যাবে।</span></div></div>'); return; }
  var html='';
  offerPreviewRows.forEach(function(x,i){
    var link=escapeHtml(x.button_url||'#');
    html+='<div class="admin-offer-preview-slide '+(i===0?'active':'')+'"><a class="admin-offer-preview-link" href="'+link+'" target="_blank" rel="noopener"><img src="'+escapeHtml(x.image_url)+'" alt="Offer slide '+(i+1)+'"></a></div>';
  });
  if(offerPreviewRows.length>1){
    html+='<button type="button" class="admin-offer-preview-arrow prev" id="offerPreviewPrev">‹</button>';
    html+='<button type="button" class="admin-offer-preview-arrow next" id="offerPreviewNext">›</button>';
    html+='<div class="admin-offer-preview-dots">';
    offerPreviewRows.forEach(function(x,i){ html+='<button type="button" data-offer-preview-index="'+i+'" class="'+(i===0?'active':'')+'"></button>'; });
    html+='</div>';
  }
  box.html(html); startOfferPreviewAuto();
}
function loadOfferBanners(){
  ajaxAction('get_offer_banners',{},function(res){
    offerCache={}; var rows=res.data||[]; var html='';
    rows.forEach(function(x,index){
      offerCache[x.id]=x;
      html+='<tr><td><strong>'+(index+1)+'</strong></td><td>'+imageThumb(x.image_url,'Offer slide '+(index+1))+'</td><td><code class="text-wrap">'+escapeHtml(x.button_url||'#')+'</code></td><td>'+offerStateBadge(x)+'</td><td><button class="btn btn-sm btn-primary edit-offer" data-id="'+x.id+'">Edit</button> <button class="btn btn-sm btn-danger delete-offer" data-id="'+x.id+'">Delete</button></td></tr>';
    });
    $('#offerBannersTableBody').html(html||'<tr><td colspan="5" class="text-center text-muted">No offer slides found.</td></tr>');
    renderOfferSliderPreview(rows);
  });
}
$('#offerBannerMenu').on('click',function(){ resetOfferBannerForm(); $('#offerBannerModal').modal('show'); loadOfferBanners(); });
$('#addNewOfferSlideBtn').on('click',function(){ resetOfferBannerForm(); $('#offerBannerForm [name=offer_image]').trigger('focus'); });
$('#offerBannerForm').on('submit',function(e){ e.preventDefault(); submitDynamicForm($(this),'save_offer_banner',function(){ resetOfferBannerForm(); loadOfferBanners(); }); });
$('#resetOfferBannerBtn').on('click',resetOfferBannerForm);
$('#offerBannerForm [name=offer_image]').on('change',function(){
  var file=this.files&&this.files[0]; if(!file) return;
  var reader=new FileReader();
  reader.onload=function(e){ renderOfferFormImage(e.target.result); };
  reader.readAsDataURL(file);
});
$(document).on('click','#offerPreviewPrev',function(){ showOfferPreview(offerPreviewIndex-1); startOfferPreviewAuto(); });
$(document).on('click','#offerPreviewNext',function(){ showOfferPreview(offerPreviewIndex+1); startOfferPreviewAuto(); });
$(document).on('click','[data-offer-preview-index]',function(){ showOfferPreview(Number($(this).data('offer-preview-index'))||0); startOfferPreviewAuto(); });
$('#offerSliderPreview').on('mouseenter',stopOfferPreviewAuto).on('mouseleave',startOfferPreviewAuto);
$(document).on('click','.edit-offer',function(){
  var x=offerCache[$(this).data('id')]; if(!x) return;
  var f=$('#offerBannerForm'); f[0].reset();
  f.find('[name=id]').val(x.id); f.find('[name=button_url]').val(x.button_url||'');
  renderOfferFormImage(x.image_url||'');
});
$(document).on('click','.delete-offer',function(){ if(!confirm('Delete this full-image offer slide?')) return; ajaxAction('delete_offer_banner',{id:$(this).data('id')},function(r){ toastr.success(r.message); resetOfferBannerForm(); loadOfferBanners(); }); });

/* ============================================
   SIDEBAR → MODAL SHORTCUTS
   ============================================ */
$('#categorySetupMenu').on('click',function(){ $('#siteCategorySetupBtn').trigger('click'); });
$('#sectionManagementMenu').on('click',function(){ $('#sectionManagementBtn').trigger('click'); });

/* ============================================
   MODAL RESET
   ============================================ */
$('#productModal').on('hidden.bs.modal',function(){
  $('#productForm')[0].reset();
  rvSetDescriptionHtml('');
  $('#siteDescriptionEditor').removeClass('rv-editor-fullscreen');
  $('body').removeClass('overflow-hidden');
  $('#editId').val(0);
  $('#imagePreviewContainer').empty();
});
$('#bulkUploadModal').on('hidden.bs.modal',function(){ $('#bulkUploadForm')[0].reset(); });

/* ============================================
   PAGE LOAD
   ============================================ */
$(document).ready(function(){
  loadDropdowns();
  toggleHeroSliderMode();
});
</script>
</body>
</html>