<?php require_once 'config.php'; ?>
<?php
if (!is_logged_in()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $o = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
  $h = $_SERVER['HTTP_HOST'] ?? '';
  if ($o === '' || stripos($o, $h) === false) { http_response_code(403); exit('Forbidden'); }
}

$action = $_GET['action'] ?? 'dashboard';
$msg = '';
if (isset($_GET['msg']) && $_GET['msg']==='saved') $msg = 'Saved.';
if (isset($_GET['msg']) && $_GET['msg']==='published') $msg = 'Site published.';

// ---------- role enforcement (viewer read-only, editor content-only) ----------
function panel_role() {
  $u = function_exists('current_admin') ? current_admin() : 'admin';
  if ($u === ADMIN_USER) return 'admin';
  if (function_exists('is_staff') && is_staff()) return 'staff';
  foreach (load_users() as $usr) { if (($usr['username'] ?? '') === $u) return $usr['role'] ?? 'admin'; }
  return 'admin';
}
$panel_role = panel_role();
$mut_get_keys = ['fix_slugs','snapshot','restore_snap','purge','publish_run','monrun','twofa_off','twofa_new'];
$is_mut_get = false;
foreach ($_GET as $gk => $gv) { if (strpos($gk, 'del_') === 0 || in_array($gk, $mut_get_keys, true)) { $is_mut_get = true; break; } }
if ($panel_role === 'viewer' && ($_SERVER['REQUEST_METHOD'] === 'POST' || $is_mut_get)) { http_response_code(403); exit('Read-only role: viewers cannot modify.'); }
if ($panel_role === 'editor') {
  $editor_block_post = ['save_user','restore_backup','save_settings','save_notify','save_options'];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') { foreach ($editor_block_post as $bk) { if (isset($_POST[$bk])) { http_response_code(403); exit('Not allowed for editor role.'); } } }
  if (isset($_GET['del_user']) || $action === 'users' || isset($_GET['snapshot']) || isset($_GET['restore_snap'])) { http_response_code(403); exit('Not allowed for editor role.'); }
}

// ---------- admin write lock: serializes concurrent panel saves ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $is_mut_get) {
  $__admin_lock = @fopen(SITE_DIR.'/admin/data/.lock_admin','c');
  if ($__admin_lock) flock($__admin_lock, LOCK_EX); // held till request end
}

// ---------- destructive-GET referer gate: kills CSRF on del_*/restore/snapshot links ----------
if ($is_mut_get) {
  $rf = $_SERVER['HTTP_REFERER'] ?? ''; $hst = $_SERVER['HTTP_HOST'] ?? '';
  if ($rf === '' || stripos($rf, $hst) === false) { http_response_code(403); exit('Forbidden.'); }
}

// ---------- daily auto-snapshot (first admin visit of the day, keeps 7) ----------
$auto_snap_dir = SITE_DIR . '/admin/data/snapshots';
if (!is_dir($auto_snap_dir)) mkdir($auto_snap_dir, 0755, true);
$auto_today = $auto_snap_dir . '/auto_' . date('Ymd');
if (!is_dir($auto_today)) {
  mkdir($auto_today, 0755, true);
  foreach (['products.json','brands.json','categories.json'] as $jf) { $sf = SITE_DIR . '/admin/data/' . $jf; if (is_file($sf)) copy($sf, $auto_today . '/' . $jf); }
  $week_ago = $auto_snap_dir . '/auto_' . date('Ymd', strtotime('-7 days'));
  foreach (glob($auto_snap_dir . '/auto_*') ?: [] as $ad) { if ($ad < $week_ago && is_dir($ad)) { foreach (glob($ad . '/*') ?: [] as $f) if (is_file($f)) unlink($f); rmdir($ad); } }
}

// ---------- shared chrome (defined in config.php) ----------
if (isset($_POST['save_quote'])) {
  $quotes=load_json(SITE_DIR.'/admin/data/quotes.json');
  $qid=(int)($_POST['qid']??-1);
  if(isset($quotes[$qid])){
    $old=$quotes[$qid];
    $quotes[$qid]['name']=trim($_POST['q_name']??$old['name']);
    $quotes[$qid]['company']=trim($_POST['q_company']??'');
    $quotes[$qid]['email']=trim($_POST['q_email']??'');
    $quotes[$qid]['phone']=trim($_POST['q_phone']??'');
    $quotes[$qid]['note']=trim($_POST['q_note']??'');
    $quotes[$qid]['status']=trim($_POST['q_status']??'viewed');
    // items qty + price override
    foreach(($_POST['qty']??[]) as $slug=>$qv){
      $qv=intval($qv);
      foreach($quotes[$qid]['items'] as &$it){ if(($it['slug']??'')===$slug){ $it['qty']=$qv>0?$qv:1; } }
    }
    foreach(($_POST['price']??[]) as $slug=>$pv){
      $pv=trim($pv);
      foreach($quotes[$qid]['items'] as &$it){ if(($it['slug']??'')===$slug){ $it['price']=$pv; } }
    }
    // add new product
    if(!empty($_POST['add_slug'])){
      $add=trim($_POST['add_slug']); $aq=intval($_POST['add_qty']??1);
      $found=false; foreach($quotes[$qid]['items'] as &$it) if($it['slug']===$add){ $it['qty']+=$aq; $found=true; }
      if(!$found) $quotes[$qid]['items'][]=['slug'=>$add,'qty'=>$aq,'name'=>$_POST['add_name']??$add];
    }
    // remove item
    if(isset($_POST['remove_slug'])){
      $rs=$_POST['remove_slug']; $quotes[$qid]['items']=array_values(array_filter($quotes[$qid]['items'], fn($x)=>($x['slug']??'')!==$rs));
    }
    // recalc total from current product prices
    $products=normalize_products_full(load_products()); $map=[]; foreach($products as $p) $map[product_slug($p)]=$p;
    $tot=0; $has=false; foreach($quotes[$qid]['items'] as $it){ $p=$map[$it['slug']]??null; if($p){ $pr=floatval(preg_replace('/[^0-9.]/','',$p['price']??'')); if($pr){$has=true;$tot+=$pr*intval($it['qty']);}}}
    $quotes[$qid]['total']=$tot; $quotes[$qid]['has_price']=$has;
    $user=current_admin();
    $detail='edited quote '.($old['id']??'').' (status: '.($old['status']??'new').' → '.$quotes[$qid]['status'].')';
    $quotes[$qid]['logs'][]=['at'=>date('Y-m-d H:i:s'),'user'=>$user,'action'=>$detail];
    $quotes[$qid]['handler']=$user;
    save_json(SITE_DIR.'/admin/data/quotes.json',$quotes);
  }
  header('Location: index.php?action=edit_quote&id='.$qid); exit;
}
// Employees: delete
if(isset($_GET['del_employee'])){
  $idx=intval($_GET['del_employee']);
  $es=load_json(SITE_DIR.'/admin/data/employees.json');
  if(isset($es[$idx])){ array_splice($es,$idx,1); save_json(SITE_DIR.'/admin/data/employees.json',$es); }
  header('Location: index.php?action=employees&msg=deleted'); exit;
}
if(isset($_POST['save_employee'])){
  $emp_id = $_POST['emp_id'] ?? '';
  $emp_name = trim($_POST['emp_name']??'');
  $emp_phone = trim($_POST['emp_phone']??'');
  $emp_email = trim($_POST['emp_email']??'');
  $emp_job = trim($_POST['emp_job']??'other');
  $emp_user = trim($_POST['emp_user']??'');
  $emp_pass = $_POST['emp_pass']??'';
  $allowed=['driver','manager','accountant','warehouse','preparer','sales','delivery','cashier','other'];
  if(!in_array($emp_job,$allowed)) $emp_job='other';
  $emps = load_json(SITE_DIR.'/admin/data/employees.json');
  if($emp_name===''){
    header('Location: index.php?action=employees&msg=error_name'); exit;
  }
  if($emp_id===''||$emp_id===null){
    $nid = count($emps) ? max(array_map(fn($x)=>intval($x['id']??0),$emps))+1 : 1;
    $emps[] = ['id'=>$nid,'name'=>$emp_name,'phone'=>$emp_phone,'email'=>$emp_email,'job'=>$emp_job,'username'=>$emp_user,'pass'=>$emp_pass?password_hash($emp_pass,PASSWORD_DEFAULT):'','created'=>date('Y-m-d H:i:s')];
  } else {
    $idx=intval($emp_id);
    if(isset($emps[$idx])){
      $emps[$idx]['name']=$emp_name; $emps[$idx]['phone']=$emp_phone; $emps[$idx]['email']=$emp_email; $emps[$idx]['job']=$emp_job; $emps[$idx]['username']=$emp_user;
      if($emp_pass!=='') $emps[$idx]['pass']=password_hash($emp_pass,PASSWORD_DEFAULT);
    }
  }
  save_json(SITE_DIR.'/admin/data/employees.json',$emps);
  header('Location: index.php?action=employees&msg=saved'); exit;
}
// Visitors: delete
if(isset($_GET['del_visitor'])){
  $idx=intval($_GET['del_visitor']);
  $vs=load_visitors();
  if(isset($vs[$idx])){ array_splice($vs,$idx,1); save_visitors($vs); }
  header('Location: index.php?action=visitors&msg=deleted'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Brands
  if (isset($_POST['save_brand'])) {
    $brands = load_brands();
    $name = trim($_POST['name']); $cat = trim($_POST['category'] ?? '');
    $id = $_POST['id'] ?? null;
    if ($name === '') { $msg='Brand name required.'; }
    else {
      if ($id === '' || $id === null) { $num = next_num($brands,'brand-'); $img = save_upload('image',IMG_DIR,'brand-',$num) ?: ''; }
      else { $b = $brands[$id]; $num = preg_replace('/\D/','',$b['image']); $img = $b['image']; if($f=save_upload('image',IMG_DIR,'brand-',$num)) $img=$f; }
      $rec = ['image'=>$img,'name'=>$name,'category'=>$cat,'slug'=>slugify($name)];
      if ($id === '' || $id === null) $brands[] = $rec; else $brands[$id] = $rec;
      save_brands($brands); auto_publish(); header('Location: index.php?action=brands&msg=saved'); exit;
    }
  }
  // Products
  if (isset($_POST['save_product'])) {
    $products = normalize_products(load_products());
    $name=trim($_POST['name']); $brand=$_POST['brand']??''; $cat=$_POST['category']??'';
    $origin=trim($_POST['origin']??''); $desc=trim($_POST['desc']??'');
    $price=trim($_POST['price']??''); $stock=trim($_POST['stock']??'');
    $featured=isset($_POST['featured'])?1:0;
    $id=$_POST['id'] ?? null;
    if ($name===''||$cat==='') { $msg='Name and category required.'; }
    else {
      if ($id===''||$id===null){ $num=next_prod_num($products); $img=save_upload('image',IMG_DIR,'prod-',$num)?:''; $order=count($products); $mx=0; foreach($products as $pp) $mx=max($mx,(int)($pp['id']??0)); $new_id=$mx+1; }
      else { $p=$products[$id]; $num=preg_replace('/\D/','',$p['image']); if(!$num) $num=$p['id']??$id; $img=$p['image']; $old_img=$img; if($f=save_upload('image',IMG_DIR,'prod-',$num)){$img=$f; if($old_img && $old_img!==$img && strpos($old_img,'/')===false && file_exists(IMG_DIR.'/'.$old_img)) @unlink(IMG_DIR.'/'.$old_img); } $order=$p['order']??$id; $new_id=$p['id']??$id; }
      if (empty($img) && !empty($_POST['pick_image'])) { $img='rushed/'.basename($_POST['pick_image']); }
      $seo_title=trim($_POST['seo_title']??''); $seo_desc=trim($_POST['seo_desc']??'');
      if($seo_title==='') $seo_title=$name.' — '.($brand?ucfirst($brand).' | ':'').'7 Boys® | Rubu Al Quds';
      if($seo_desc===''){ $base=$desc?:$name.' from '.($brand?ucfirst($brand):'7 Boys').($origin?' — '.$origin:'').'.'; $seo_desc=mb_substr($base,0,155); if(mb_strlen($base)>155) $seo_desc=rtrim($seo_desc).'…'; $seo_desc.=' Premium import & distribution since 1966. Al-Abdali, Amman.'; $seo_desc=mb_substr($seo_desc,0,160); }
      $expiry=trim($_POST['expiry_date']??''); if($expiry==='') $expiry=null;
      $offer=trim($_POST['offer_price']??''); if($offer==='') $offer=null;
      $rec=['id'=>$new_id,'image'=>$img,'name'=>$name,'brand'=>$brand,'cat'=>$cat,'origin'=>$origin,'desc'=>$desc,'price'=>$price,'stock'=>$stock,'featured'=>$featured,'order'=>$order,'seo_title'=>$seo_title,'seo_desc'=>$seo_desc,'expiry_date'=>$expiry,'offer_price'=>$offer];
      if ($id===''||$id===null)$products[]=$rec; else $products[$id]=$rec;
      save_products($products); auto_publish(); header('Location: index.php?action=products&msg=saved'); exit;
    }
  }
  // Duplicate product
  if (isset($_POST['dup_product'])) {
    $products = normalize_products(load_products());
    $i = (int)$_POST['dup_product'];
    if (isset($products[$i])) {
      $copy = $products[$i];
      $copy['name'] = $copy['name'] . ' (copy)';
      $copy['order'] = count($products);
      $products[] = $copy;
      save_products($products); auto_publish();
      header('Location: index.php?action=products&msg=saved'); exit;
    }
  }
  // Save product order (manual sorting)
  if (isset($_POST['save_order'])) {
    $products = normalize_products(load_products());
    if (isset($_POST['order']) && is_array($_POST['order'])) {
      foreach ($_POST['order'] as $idx => $val) {
        if (isset($products[$idx])) $products[$idx]['order'] = (int)$val;
      }
      // sort by order then reindex
      usort($products, fn($a,$b)=> ($a['order']??0) <=> ($b['order']??0));
      save_products($products); auto_publish();
      header('Location: index.php?action=products&msg=saved'); exit;
    }
  }
  // Toggle featured
  if (isset($_POST['toggle_featured'])) {
    $products = normalize_products(load_products());
    $i = (int)$_POST['toggle_featured'];
    if (isset($products[$i])) { $products[$i]['featured'] = empty($products[$i]['featured'])?1:0; save_products($products); auto_publish(); }
    header('Location: index.php?action=products'); exit;
  }
  // Categories (main + sub)
  if (isset($_POST['save_cat'])) {
    $cats=load_cats(); $name=trim($_POST['name']); $id=$_POST['id']??null; $parent=trim($_POST['parent']??'');
    if($name===''){$msg='Category name required.';}
    else {
      $slug=slugify($name);
      if(is_string($id)&&strpos($id,'sub:')===0){ $pt=explode(':',$id); $pi=(int)($pt[1]??-1); $si=(int)($pt[2]??-1); if(isset($cats[$pi]['sub'][$si])) $cats[$pi]['sub'][$si]=['slug'=>$slug,'name'=>$name]; }
      elseif($parent!==''){ $pi=(int)$parent; if(isset($cats[$pi])){ if(empty($cats[$pi]['sub'])) $cats[$pi]['sub']=[]; $cats[$pi]['sub'][]=['slug'=>$slug,'name'=>$name]; } else $cats[]=['slug'=>$slug,'name'=>$name]; }
      elseif($id===''||$id===null) $cats[]=['slug'=>$slug,'name'=>$name];
      else { $i=(int)$id; if(isset($cats[$i])){ $keepsub=$cats[$i]['sub']??null; $cats[$i]=['slug'=>$slug,'name'=>$name]; if($keepsub) $cats[$i]['sub']=$keepsub; } }
      save_cats($cats); auto_publish(); header('Location: index.php?action=cats&msg=saved'); exit;
    }
  }
  // Settings
  if (isset($_POST['save_settings'])) {
    $s=['phone'=>trim($_POST['phone']??''),'email'=>trim($_POST['email']??''),'address'=>trim($_POST['address']??''),'facebook'=>trim($_POST['facebook']??''),'instagram'=>trim($_POST['instagram']??''),'whatsapp'=>trim($_POST['whatsapp']??'')];
    save_settings($s); auto_publish(); header('Location: index.php?action=settings&msg=saved'); exit;
  }
  if (isset($_POST['save_notify'])) {
    $emails = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $_POST['n_emails'] ?? ''))));
    $wa = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $_POST['n_wa'] ?? ''))));
    $cfg = ['emails'=>$emails, 'wa'=>$wa,
      'events'=>['register'=>!empty($_POST['ev_register']),'quote'=>!empty($_POST['ev_quote']),'contact'=>!empty($_POST['ev_contact']),'monitor'=>!empty($_POST['ev_monitor'])],
      'um'=>['instance'=>trim($_POST['um_instance'] ?? ''),'token'=>trim($_POST['um_token'] ?? '')]];
    save_notify_cfg($cfg); header('Location: index.php?action=notify&msg=saved'); exit;
  }
  if (isset($_POST['test_notify'])) {
    $r = notify_admin('monitor', '[7boysjo] Test notification', 'Test from Notifications panel at ' . date('Y-m-d H:i:s') . '. If you got this, alerts work.');
    header('Location: index.php?action=notify&msg=' . urlencode('Test sent: ' . $r['mail'] . ' email, ' . $r['wa'] . ' WhatsApp')); exit;
  }
  // Homepage content
  if (isset($_POST['save_home'])) {
    $home=['hero_title'=>trim($_POST['hero_title']??''),'hero_sub'=>trim($_POST['hero_sub']??''),'about'=>trim($_POST['about']??''),'why'=>trim($_POST['why']??'')];
    $stats=[]; for($i=0;$i<4;$i++){ $n=trim($_POST['stat_num'][$i]??''); $l=trim($_POST['stat_lbl'][$i]??''); if($n!==''||$l!=='') $stats[]=['num'=>$n,'lbl'=>$l]; }
    $home['stats']=$stats;
    $tl=[]; for($i=0;$i<8;$i++){ $y=trim($_POST['tl_yr'][$i]??''); $h=trim($_POST['tl_h'][$i]??''); $p=trim($_POST['tl_p'][$i]??''); if($y!==''||$h!=='') $tl[]=['yr'=>$y,'h'=>$h,'p'=>$p]; }
    $home['timeline']=$tl;
    save_json(SITE_DIR.'/admin/data/home.json',$home);
    auto_publish(); header('Location: index.php?action=home&msg=saved'); exit;
  }
  // Users: 2FA enable (confirm code) / disable
  if (isset($_POST['twofa_confirm'])) {
    $tu = trim($_POST['twofa_user'] ?? '');
    $tc = preg_replace('/[^0-9]/', '', (string)($_POST['twofa_code'] ?? ''));
    $pend = $_SESSION['twofa_pending'] ?? null;
    if ($tu !== '' && $pend && ($pend['user'] ?? '') === $tu && function_exists('totp_verify') && totp_verify($pend['secret'], $tc)) {
      $codes = [];
      $ab = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      for ($i = 0; $i < 10; $i++) { $c = ''; for ($k = 0; $k < 8; $k++) $c .= $ab[random_int(0, strlen($ab)-1)]; $codes[] = $c; }
      $all = function_exists('twofa_all') ? twofa_all() : [];
      $all[$tu] = ['secret' => $pend['secret'], 'backup' => array_map(function($c){ return password_hash($c, PASSWORD_DEFAULT); }, $codes), 'at' => date('Y-m-d H:i:s')];
      save_json(SITE_DIR . '/admin/data/twofa.json', $all);
      unset($_SESSION['twofa_pending']);
      $_SESSION['twofa_codes'] = ['user' => $tu, 'codes' => $codes];
      header('Location: index.php?action=users&msg=' . urlencode('2FA enabled for ' . $tu)); exit;
    } else { $msg = 'Invalid code — 2FA not enabled.'; }
  }
  // Users (manage)
  if (isset($_POST['save_user'])) {
    $users = load_users();
    $uname = trim($_POST['username']);
    $upass = $_POST['password'] ?? '';
    $urole = in_array(($_POST['role'] ?? 'admin'), ['admin','editor','viewer']) ? $_POST['role'] : 'admin';
    $id = $_POST['id'] ?? null;
    if ($uname === '') { $msg='Username required.'; }
    else {
      if ($id === '' || $id === null) {
        if ($upass === '') { $msg='Password required for new user.'; }
        elseif (strlen($upass) < 8) { $msg='Password must be at least 8 characters.'; }
        else { $users[] = ['username'=>$uname,'pass'=>password_hash($upass, PASSWORD_DEFAULT),'role'=>$urole]; }
      } else {
        $users[$id]['username'] = $uname;
        if ($upass !== '' && strlen($upass) < 8) { $msg='Password must be at least 8 characters.'; }
        elseif ($upass !== '') $users[$id]['pass'] = password_hash($upass, PASSWORD_DEFAULT);
        $users[$id]['role'] = $urole;
      }
      if ($msg === '') { save_users($users); auto_publish(); header('Location: index.php?action=users&msg=saved'); exit; }
    }
  }
  // Media: bulk upload
  if (isset($_POST['bulk_upload'])) {
    if (!empty($_FILES['bulk']['name'])) {
      if (!is_dir(IMG_DIR . '/rushed')) mkdir(IMG_DIR . '/rushed', 0755, true);
      foreach ($_FILES['bulk']['name'] as $k => $nm) {
        if ($_FILES['bulk']['error'][$k] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
        $ext = in_array($ext,['jpg','jpeg'])?'jpg':($ext==='png'?'png':($ext==='webp'?'webp':'jpg'));
        $dest = IMG_DIR . '/rushed/' . preg_replace('/[^a-z0-9._-]/i','-',basename($nm,'.'.$ext)).'.'.$ext;
        if (!move_uploaded_file($_FILES['bulk']['tmp_name'][$k], $dest)) { error_log('bulk upload failed: '.$nm); }
      }
      $msg='Images uploaded.';
    }
  }
  // Media: delete image
  if (isset($_POST['del_image'])) {
    $fn = basename($_POST['del_image']);
    $path = IMG_DIR . '/rushed/' . $fn;
    if (file_exists($path)) { unlink($path); $msg='Image deleted.'; }
  }
  // Media: rename image
  if (isset($_POST['rename_image'])) {
    $old = basename($_POST['old_name']);
    $new = preg_replace('/[^a-z0-9._-]/i','-',basename($_POST['new_name']));
    if ($old && $new && $old !== $new && file_exists(IMG_DIR.'/rushed/'.$old)) {
      $ext = pathinfo($old, PATHINFO_EXTENSION);
      if (!preg_match('/\.'.$ext.'$/i',$new)) $new .= '.'.$ext;
      if (!file_exists(IMG_DIR.'/rushed/'.$new)) { rename(IMG_DIR.'/rushed/'.$old, IMG_DIR.'/rushed/'.$new); $msg='Renamed.'; }
    }
  }
  // Auto-match images to products
  if (isset($_POST['auto_match'])) {
    $products = normalize_products(load_products());
    $brands = load_brands();
    $matched = 0;
    // build brand name lookup
    $bnames = [];
    foreach ($brands as $b) $bnames[strtolower($b['slug'])] = strtolower($b['name']);
    // available rushed images
    $imgs = glob(IMG_DIR.'/rushed/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    foreach ($products as &$p) {
      $brandSlug = strtolower($p['brand'] ?? '');
      $brandName = $bnames[$brandSlug] ?? '';
      $pname = strtolower($p['name']);
      foreach ($imgs as $img) {
        $bn = strtolower(pathinfo($img, PATHINFO_FILENAME));
        if ($brandName && strpos($bn, $brandName) !== false) {
          $rel = 'rushed/'.basename($img);
          if (($p['image'] ?? '') !== $rel) { $p['image'] = $rel; $matched++; }
          break;
        }
      }
    }
    unset($p);
    save_products($products); auto_publish();
    $msg = "Auto-matched $matched product image(s) by brand.";
  }
  // Backup upload (restore)
  if (isset($_POST['restore_backup'])) {
    if (!empty($_FILES['backup']['tmp_name']) && ($_FILES['backup']['size'] ?? 0) < 20971520) {
      $data = json_decode(file_get_contents($_FILES['backup']['tmp_name']), true);
      if (is_array($data) && isset($data['products'])) {
        save_products($data['products']);
        if (isset($data['brands'])) save_brands($data['brands']);
        if (isset($data['cats'])) save_cats($data['cats']);
        auto_publish();
        header('Location: index.php?action=backup&msg=saved'); exit;
      } else { $msg='Invalid backup file.'; }
    }
  }
  // CSV import
  if (isset($_POST['clear_preview'])) { unset($_SESSION['csv_preview']); header('Location: index.php?action=products'); exit; }
  if (isset($_POST['preview_csv'])) {
    if (!empty($_FILES['csv']['tmp_name'])) {
      $raw = file_get_contents($_FILES['csv']['tmp_name']);
      // handle xlsx? if file is zip, try to detect - for now CSV only, XLSX needs PhpSpreadsheet
      $rows = array_map('str_getcsv', explode("\n", $raw));
      // remove empty rows
      $rows = array_filter($rows, fn($r)=> count(array_filter($r, fn($c)=> trim($c)!==''))>0);
      $rows = array_values($rows);
      $header = array_map('trim', array_shift($rows));
      // store preview in session
      $_SESSION['csv_preview'] = ['header'=>$header, 'rows'=>$rows];
      header('Location: index.php?action=products&preview=1'); exit;
    }
  }
  if (isset($_POST['confirm_import'])) {
    $preview = $_SESSION['csv_preview'] ?? null;
    if ($preview) {
      $header=$preview['header']; $rows=$preview['rows'];
      $mode=$_POST['import_mode']??'add';
      $products = normalize_products(load_products());
      // map name -> index
      $byName=[]; foreach($products as $idx=>$pr) $byName[strtolower(trim($pr['name']??''))]=$idx;
      $added=0; $updated=0; $skipped=0;
      foreach ($rows as $row) {
        if (empty($row) || empty($row[0])) { $skipped++; continue; }
        $rec=[]; foreach ($header as $i=>$h) $rec[$h]=trim($row[$i]??'');
        if (empty($rec['name']) || empty($rec['cat'])) { $skipped++; continue; }
        // normalize
        $rec['name']=trim($rec['name']); $rec['cat']=trim($rec['cat']); $rec['brand']=trim($rec['brand']??'');
        $rec['origin']=trim($rec['origin']??''); $rec['desc']=trim($rec['desc']??''); $rec['price']=trim($rec['price']??'');
        $rec['stock']=trim($rec['stock']??''); $rec['channel']=trim($rec['channel']??''); $rec['image']=trim($rec['image']??'');
        $rec['featured']=(!empty($rec['featured']) && $rec['featured']!=='0')?1:0;
        if (empty($rec['image'])) { $num=next_prod_num($products); $rec['image']='prod-'.$num.'.jpg'; }
        // check image exists, else try rushed/
        if (!empty($rec['image']) && strpos($rec['image'],'/')===false && !file_exists(IMG_DIR.'/'.$rec['image']) && file_exists(IMG_DIR.'/rushed/'.$rec['image'])) $rec['image']='rushed/'.$rec['image'];
        $key=strtolower($rec['name']);
        if (isset($byName[$key]) && $mode!=='add_only') {
          $idx=$byName[$key];
          // update existing, keep order and image if new empty
          $old=$products[$idx];
          $rec['order']=$old['order']??$idx;
          if(empty($rec['image'])) $rec['image']=$old['image'];
          $products[$idx]=array_merge($old,$rec);
          $updated++;
        } else {
          $rec['order']=count($products);
          $products[]=$rec; $added++; $byName[$key]=count($products)-1;
        }
      }
      save_products($products); auto_publish();
      unset($_SESSION['csv_preview']);
      header('Location: index.php?action=products&msg='.urlencode("Import done: $added added, $updated updated, $skipped skipped")); exit;
    }
  }
  if (isset($_POST['import_csv'])) {
    if (!empty($_FILES['csv']['tmp_name'])) {
      $rows = array_map('str_getcsv', file($_FILES['csv']['tmp_name']));
      $header = array_map('trim', array_shift($rows));
      $products = normalize_products(load_products());
      $added = 0;
      foreach ($rows as $row) {
        if (empty($row) || empty($row[0])) continue;
        $rec = [];
        foreach ($header as $i => $h) $rec[$h] = $row[$i] ?? '';
        if (empty($rec['name']) || empty($rec['cat'])) continue;
        if (empty($rec['image'])) { $num=next_prod_num($products); $rec['image']='prod-'.$num.'.jpg'; }
        $rec['featured'] = (!empty($rec['featured']) && $rec['featured']!=='0')?1:0;
        $rec['order'] = count($products);
        $products[] = $rec; $added++;
      }
      save_products($products); auto_publish();
      $msg = "Imported $added product(s).";
    }
  }
  // Site options (ext.json: whatsapp, certs, faq, map, channels, cookie)
  if (isset($_POST['save_options'])) {
    $ext = load_ext();
    $ext['whatsapp'] = trim($_POST['whatsapp'] ?? '');
    $ext['map_embed'] = trim($_POST['map_embed'] ?? '');
    $ext['cookie_text'] = trim($_POST['cookie_text'] ?? '');
    $certs = []; foreach (($_POST['cert'] ?? []) as $c) { $c = trim($c); if ($c) $certs[] = $c; }
    $ext['certs'] = $certs;
    $faq = []; foreach (($_POST['faq_q'] ?? []) as $k => $q) { $q=trim($q); $a=trim($_POST['faq_a'][$k] ?? ''); if ($q) $faq[] = ['q'=>$q,'a'=>$a]; }
    $ext['faq'] = $faq;
    $chans = []; foreach (($_POST['chan_key'] ?? []) as $k => $ck) { $ck=trim($ck); $cv=trim($_POST['chan_val'][$k] ?? ''); if ($ck && $cv) $chans[$ck] = $cv; }
    $ext['channels'] = $chans ?: ['horeca'=>'HORECA','retail'=>'Retail','wholesale'=>'Wholesale'];
    save_ext($ext); auto_publish(); header('Location: index.php?action=options&msg=saved'); exit;
  }
}

// ---------- DELETE HANDLERS (GET) — OUTSIDE POST block ----------
if (isset($_GET['del_brand'])) { $brands=load_brands(); $i=(int)$_GET['del_brand']; if(isset($brands[$i])){ $img=$brands[$i]['image']??''; if($img && strpos($img,'/')===false && file_exists(IMG_DIR.'/'.$img)) unlink(IMG_DIR.'/'.$img); array_splice($brands,$i,1); save_brands($brands); auto_publish(); } header('Location: index.php?action=brands'); exit; }
if (isset($_GET['del_product'])) { $products=normalize_products(load_products()); $i=(int)$_GET['del_product']; if(isset($products[$i])){ $img=$products[$i]['image']??''; if($img && strpos($img,'/')===false && file_exists(IMG_DIR.'/'.$img)) unlink(IMG_DIR.'/'.$img); array_splice($products,$i,1); save_products($products); auto_publish(); } header('Location: index.php?action=products'); exit; }
if (isset($_POST['bulk_action']) && isset($_POST['bulk_ids'])) {
  $products=normalize_products(load_products());
  $ids=array_map('intval', explode(',', $_POST['bulk_ids']));
  rsort($ids);
  $act=$_POST['bulk_action'];
  if($act==='delete'){ rsort($ids); $deleted=0; foreach($ids as $i) if(isset($products[$i])){ $img=$products[$i]['image']??''; if($img && strpos($img,'/')===false && preg_match('/^prod-/', $img) && file_exists(IMG_DIR.'/'.$img)) @unlink(IMG_DIR.'/'.$img); array_splice($products,$i,1); $deleted++; } save_products($products); auto_publish(); header('Location: index.php?action=products&msg='.urlencode('Bulk deleted '.$deleted)); exit; }
  if($act==='feature'){ foreach($ids as $i) if(isset($products[$i])) $products[$i]['featured']=1; save_products($products); auto_publish(); header('Location: index.php?action=products&msg='.urlencode('Bulk featured')); exit; }
  if($act==='unfeature'){ foreach($ids as $i) if(isset($products[$i])) $products[$i]['featured']=0; save_products($products); auto_publish(); header('Location: index.php?action=products&msg='.urlencode('Bulk unfeatured')); exit; }
  if(in_array($act,['cat','brand','origin','channel','price'])){
    $val=trim($_POST['bulk_val']??'');
    foreach($ids as $i) if(isset($products[$i])){ if($act==='cat') $products[$i]['cat']=$val; if($act==='brand') $products[$i]['brand']=$val; if($act==='origin') $products[$i]['origin']=$val; if($act==='channel') $products[$i]['channel']=$val; if($act==='price') $products[$i]['price']=$val; }
    save_products($products); auto_publish(); header('Location: index.php?action=products&msg='.urlencode('Bulk updated')); exit;
  }
}
if (isset($_POST['quick_cat'])) {
  $products=normalize_products(load_products()); $i=(int)$_POST['quick_cat']; $val=trim($_POST['val']??'');
  if(isset($products[$i])){ $products[$i]['cat']=$val; $products[$i]['category']=$val; save_products($products); echo json_encode(['ok'=>true]); exit; }
  echo json_encode(['ok'=>false]); exit;
}
if (isset($_POST['quick_origin'])) {
  $products=normalize_products(load_products()); $i=(int)$_POST['quick_origin']; $val=trim($_POST['val']??'');
  if(isset($products[$i])){ $products[$i]['origin']=$val; save_products($products); echo json_encode(['ok'=>true]); exit; }
  echo json_encode(['ok'=>false]); exit;
}
if (isset($_POST['quick_price'])) {
  $products=normalize_products(load_products()); $i=(int)$_POST['quick_price']; $val=trim($_POST['val']??'');
  if(isset($products[$i])){ $products[$i]['price']=$val; save_products($products); auto_publish(); }
  header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
}
// Categories: bulk delete
if (isset($_POST['bulk_del_cats'])) {
  $cats=load_cats(); $ids=array_map('intval', explode(',', $_POST['bulk_del_cats']));
  rsort($ids); foreach($ids as $i) if(isset($cats[$i])) array_splice($cats,$i,1);
  save_cats($cats); auto_publish(); header('Location: index.php?action=cats&msg='.urlencode('Deleted '.count($ids).' categories')); exit;
}
if (isset($_POST['bulk_del_brands'])) {
  $brands=load_brands(); $ids=array_map('intval', explode(',', $_POST['bulk_del_brands']));
  rsort($ids); foreach($ids as $i) if(isset($brands[$i])){ $img=$brands[$i]['image']??''; if($img && strpos($img,'/')===false && file_exists(IMG_DIR.'/'.$img)) @unlink(IMG_DIR.'/'.$img); array_splice($brands,$i,1); }
  save_brands($brands); auto_publish(); header('Location: index.php?action=brands&msg='.urlencode('Deleted '.count($ids).' brands')); exit;
}
if (isset($_POST['bulk_del_messages'])) {
  $msgs=load_json(SITE_DIR.'/admin/data/messages.json'); $ids=array_map('intval', explode(',', $_POST['bulk_del_messages']));
  rsort($ids); foreach($ids as $i) if(isset($msgs[$i])) array_splice($msgs,$i,1);
  save_json(SITE_DIR.'/admin/data/messages.json',$msgs); header('Location: index.php?action=messages&msg='.urlencode('Deleted '.count($ids).' messages')); exit;
}
if (isset($_POST['bulk_del_quotes'])) {
  $quotes=load_json(SITE_DIR.'/admin/data/quotes.json'); $ids=array_map('intval', explode(',', $_POST['bulk_del_quotes']));
  rsort($ids); foreach($ids as $i) if(isset($quotes[$i])) array_splice($quotes,$i,1);
  save_json(SITE_DIR.'/admin/data/quotes.json',$quotes); header('Location: index.php?action=quotes&msg='.urlencode('Deleted '.count($ids).' quotes')); exit;
}
if (isset($_POST['bulk_del_visitors'])) {
  $vs=load_visitors(); $ids=array_map('intval', explode(',', $_POST['bulk_del_visitors']));
  rsort($ids); foreach($ids as $i) if(isset($vs[$i])) array_splice($vs,$i,1);
  save_visitors($vs); header('Location: index.php?action=visitors&msg='.urlencode('Deleted '.count($ids).' clients')); exit;
}
if (isset($_POST['bulk_del_employees'])) {
  $es=load_json(SITE_DIR.'/admin/data/employees.json'); $ids=array_map('intval', explode(',', $_POST['bulk_del_employees']));
  rsort($ids); foreach($ids as $i) if(isset($es[$i])) array_splice($es,$i,1);
  save_json(SITE_DIR.'/admin/data/employees.json',$es); header('Location: index.php?action=employees&msg='.urlencode('Deleted '.count($ids).' employees')); exit;
}
if (isset($_GET['del_cat'])) { $cats=load_cats(); $i=(int)$_GET['del_cat']; if(isset($cats[$i])){array_splice($cats,$i,1); save_cats($cats); auto_publish();} header('Location: index.php?action=cats'); exit; }
if (isset($_GET['del_sub'])) { $cats=load_cats(); $pt=explode(':',$_GET['del_sub']); $pi=(int)($pt[0]??-1); $si=(int)($pt[1]??-1); if(isset($cats[$pi]['sub'][$si])){array_splice($cats[$pi]['sub'],$si,1); if(empty($cats[$pi]['sub'])) unset($cats[$pi]['sub']); save_cats($cats); auto_publish();} header('Location: index.php?action=cats'); exit; }
if (isset($_GET['del_msg'])) { $msgs=load_json(SITE_DIR.'/admin/data/messages.json'); $i=(int)$_GET['del_msg']; if(isset($msgs[$i])){array_splice($msgs,$i,1); save_json(SITE_DIR.'/admin/data/messages.json',$msgs);} header('Location: index.php?action=messages'); exit; }
if (isset($_GET['mark_read'])) { $msgs=load_json(SITE_DIR.'/admin/data/messages.json'); $i=(int)$_GET['mark_read']; if(isset($msgs[$i])){$msgs[$i]['read']=true; save_json(SITE_DIR.'/admin/data/messages.json',$msgs);} header('Location: index.php?action=messages'); exit; }
if (isset($_GET['del_quote'])) { $quotes=load_json(SITE_DIR.'/admin/data/quotes.json'); $i=(int)$_GET['del_quote']; if(isset($quotes[$i])){array_splice($quotes,$i,1); save_json(SITE_DIR.'/admin/data/quotes.json',$quotes);} header('Location: index.php?action=quotes'); exit; }
if (isset($_GET['view_quote'])) { $quotes=load_json(SITE_DIR.'/admin/data/quotes.json'); $i=(int)$_GET['view_quote']; if(isset($quotes[$i])){ $quotes[$i]['logs'][]=['at'=>date('Y-m-d H:i:s'),'user'=>current_admin(),'action'=>'viewed quote '.($quotes[$i]['id']??'')]; if(empty($quotes[$i]['status'])||$quotes[$i]['status']==='new') $quotes[$i]['status']='viewed'; save_json(SITE_DIR.'/admin/data/quotes.json',$quotes); } header('Location: index.php?action=edit_quote&id='.$i); exit; }
if (isset($_GET['del_user'])) { $users=load_users(); $i=(int)$_GET['del_user']; if(isset($users[$i])){array_splice($users,$i,1); save_users($users);} header('Location: index.php?action=users'); exit; }
if (isset($_GET['twofa_off'])) {
  $tu = trim((string)$_GET['twofa_off']);
  if ($tu !== '' && function_exists('twofa_all')) { $all = twofa_all(); if (isset($all[$tu])) { unset($all[$tu]); save_json(SITE_DIR . '/admin/data/twofa.json', $all); } }
  header('Location: index.php?action=users&msg=saved'); exit;
}

// edit loaders
$edit_brand=null; if(isset($_GET['edit_brand'])){$edit_brand=load_brands()[(int)$_GET['edit_brand']]??null;}
$edit_product=null; if(isset($_GET['edit_product'])){$edit_product=normalize_products(load_products())[(int)$_GET['edit_product']]??null;}
$edit_cat=null; if(isset($_GET['edit_cat'])){$edit_cat=load_cats()[(int)$_GET['edit_cat']]??null;}
$edit_user=null; if(isset($_GET['edit_user'])){$edit_user=load_users()[(int)$_GET['edit_user']]??null;}
$twofa_setup=null;
if (isset($_GET['twofa_new']) && function_exists('totp_new_secret')) {
  $twofa_setup = ['user' => trim((string)$_GET['twofa_new']), 'secret' => totp_new_secret()];
  $_SESSION['twofa_pending'] = $twofa_setup;
}

$brands=load_brands(); $brands_alpha=$brands; usort($brands_alpha,function($a,$b){ return strcasecmp((string)($a['name']??''),(string)($b['name']??'')); }); $products=normalize_products(load_products()); $cats=load_cats(); $settings=load_settings();
$home=load_json(SITE_DIR.'/admin/data/home.json');
$msgs=load_json(SITE_DIR.'/admin/data/messages.json');
$quotes_data=load_json(SITE_DIR.'/admin/data/quotes.json');
$users=load_users();

$stats = $home['stats'] ?? [['num'=>'1966','lbl'=>'Established'],['num'=>'+100','lbl'=>'Countries'],['num'=>'+500','lbl'=>'Brands'],['num'=>'3','lbl'=>'Generations']];
$timeline = $home['timeline'] ?? [];

$nav = '<a href="/categories/beverages.html">Beverages</a> | <a href="/categories/sweets.html">Sweets &amp; Confectionery</a> | <a href="/categories/gourmet.html">Gourmet &amp; Deli</a> | <a href="/categories/staples.html">Staples &amp; Basics</a> | <a href="/brands.html">🏷️ Brands</a> | <a href="/contact.php">Contact</a>';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>7 Boys® Admin</title>
<style>
*{box-sizing:border-box;} body{font-family:system-ui;margin:0;background:#eef1ee;color:#222;}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:220px;background:#053d20;color:#fff;padding:20px 0;overflow-y:auto;}
.sidebar h2{font-family:Georgia,serif;text-align:center;margin:0 0 20px;font-size:20px;}
.sidebar a{display:block;color:#cfe;padding:11px 22px;text-decoration:none;font-size:14px;border-left:3px solid transparent;}
.sidebar a:hover,.sidebar a.active{background:#0a5230;border-left-color:#c9a23f;}
.sidebar .logout{margin-top:20px;color:#c9a23f;}
.menu-btn{display:none;position:fixed;top:12px;left:12px;z-index:200;background:#053d20;color:#fff;border:1px solid #c9a23f;border-radius:8px;padding:9px 13px;font-size:16px;cursor:pointer;}
.topsearch input{min-width:0;}
.mobilebar{display:none;}
.filter-toggle{display:none;}
.ctoggle{display:none;}
@media(max-width:860px){.ctoggle{display:block;width:100%;background:#fff;border:1px solid #c9a23f;color:#053d20;border-radius:8px;padding:11px;font-size:14px;font-weight:600;cursor:pointer;margin-bottom:12px;}#brandFormWrap{display:none;}#brandFormWrap.open{display:block;}#brandToolbarHead{display:none !important;}#brandToolbarHead.open{display:flex !important;flex-direction:column;align-items:stretch;}#brandToolbarHead.open>*{width:100% !important;max-width:none !important;}}
@media(max-width:860px){.mobilebar{display:flex;position:fixed;top:0;left:0;right:0;z-index:200;background:#053d20;color:#fff;align-items:center;gap:10px;padding:10px 12px;box-shadow:0 2px 12px rgba(0,0,0,.25);}.mobilebar .menu-btn{display:block;position:static;}.mobilebar b{font-family:Georgia,serif;font-size:16px;font-weight:400;}#prodToolbar{display:none;}#prodToolbar.open{display:flex;}.filter-toggle{display:block;width:100%;background:#fff;border:1px solid #c9a23f;color:#053d20;border-radius:8px;padding:11px;font-size:14px;font-weight:600;cursor:pointer;margin-bottom:12px;}}
@media(max-width:860px){.sidebar{transform:translateX(-100%);transition:transform .25s;width:250px;z-index:150;padding-top:64px;}.sidebar.open{transform:none;box-shadow:4px 0 30px rgba(0,0,0,.35);}.menu-btn{display:block;}.main{margin-left:0 !important;padding:70px 12px 40px !important;}h1{font-size:22px;}.dash-grid{grid-template-columns:1fr 1fr !important;gap:10px;}.dash-card{padding:12px;}.dash-card .num{font-size:1.4em;}.dash-actions .btn-dash{flex:1 1 100%;text-align:center;padding:12px;}.dash-actions{display:flex;flex-wrap:wrap;gap:8px;}.toolbar{flex-direction:column;align-items:stretch;}.toolbar input[type=text],.toolbar select,.toolbar button,.toolbar a{width:100% !important;max-width:none !important;flex:none !important;box-sizing:border-box;}.dropzone{padding:16px 12px;}table{display:block;overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}form input[type=text],form input[type=password],form input[type=number],form input[type=file],form textarea,form select{width:100%;font-size:16px;}button{font-size:15px;}}
.main{margin-left:220px;padding:30px 40px;}
h1{font-family:Georgia,serif;color:#053d20;}
.msg{background:#e8f5e9;color:#1b5e20;padding:10px 14px;border-radius:8px;margin-bottom:16px;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;}
.card{background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);}
.card .thumb{width:100%;height:120px;object-fit:contain;background:#eef;}
.card .b{padding:8px 10px;font-size:13px;} .card b{color:#053d20;}
.card .acts{margin-top:6px;} .card .acts a{font-size:12px;color:#053d20;margin-right:8px;}
form{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.08);max-width:680px;}
label{display:block;font-size:13px;color:#555;margin:12px 0 4px;} input[type=text],input[type=email],input[type=number],textarea,select{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;font-size:14px;} textarea{height:80px;}
button{margin-top:16px;background:#053d20;color:#fff;border:0;padding:11px 20px;border-radius:8px;font-size:15px;cursor:pointer;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;margin-top:16px;}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:13px;}
th{background:#053d20;color:#fff;}
.dropzone{border:2px dashed #c0a040;border-radius:12px;padding:24px;text-align:center;color:#8a8270;cursor:pointer;background:#fffdf7;transition:.2s;}
.dropzone.drag{border-color:#053d20;background:#eef7f0;}
.dropzone img{max-width:120px;max-height:120px;margin-top:10px;border-radius:8px;}
.toolbar{display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.toolbar input[type=text]{max-width:240px;}
.toolbar select{max-width:190px;width:auto;}
.unread{font-weight:700;color:#b71c1c;}
.msgrow{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.msgrow .meta{font-size:12px;color:#8a8270;margin-bottom:6px;}
.badge{display:inline-block;background:#c9a23f;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px;}
.feat{display:inline-block;background:#053d20;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;}
.brand-head{display:flex;align-items:center;gap:10px;padding:16px 16px 14px;}.brand-head img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:11px;padding:3px;flex:none;}.brand-head span{color:#fff;font-size:19px;font-weight:800;letter-spacing:.01em;}</style></head>
<body>
<div class="mobilebar"><button class="menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button><img src="/assets/img/logo-header.png" alt="" style="width:26px;height:26px;object-fit:contain;background:#fff;border-radius:7px;padding:2px;vertical-align:-8px;margin-right:6px;"><b>7 Boys® Admin</b></div>
<div class="sidebar">
<div class="brand-head"><img src="/assets/img/logo-header.png" alt="7 Boys"><span>7 Boys®</span></div>
<a href="index.php?action=dashboard" class="<?= $action==='dashboard'?'active':'' ?>">🏠 Dashboard</a>
<a href="index.php?action=brands" class="<?= $action==='brands'?'active':'' ?>">Brands</a>
<a href="index.php?action=products" class="<?= $action==='products'?'active':'' ?>">📦 Products</a>
<a href="index.php?action=cats" class="<?= $action==='cats'?'active':'' ?>">🗂️ Categories</a>
<a href="catalog.php" class="">📚 Catalog</a>
<a href="index.php?action=home" class="<?= $action==='home'?'active':'' ?>">🖼️ Homepage</a>
<a href="index.php?action=media" class="<?= $action==='media'?'active':'' ?>">🎞️ Media</a>
<a href="index.php?action=messages" class="<?= $action==='messages'?'active':'' ?>">✉️ Messages<?php $unread=count(array_filter($msgs,fn($m)=>(($m['read']??false)!==true))); if($unread): ?><span class="badge"><?= $unread ?></span><?php endif; ?></a>
<a href="index.php?action=quotes" class="<?= $action==='quotes'?'active':'' ?>">🧾 Quotes<?php $qc=count($quotes_data ?? []); if($qc): ?><span class="badge"><?= $qc ?></span><?php endif; ?></a>
<a href="index.php?action=backup" class="<?= $action==='backup'?'active':'' ?>">💾 Backup</a>
<a href="index.php?action=options" class="<?= $action==='options'?'active':'' ?>">🧩 Site Options</a>
<a href="index.php?action=visitors" class="<?= $action==='visitors'?'active':'' ?>">👥 Clients<?php $vc=count(load_visitors()); if($vc): ?><span class="badge"><?= $vc ?></span><?php endif; ?></a>
<a href="index.php?action=employees" class="<?= $action==='employees'?'active':'' ?>">🧑‍💼 Employees<?php $ec=count(load_json(SITE_DIR.'/admin/data/employees.json')); if($ec): ?><span class="badge"><?= $ec ?></span><?php endif; ?></a>
<a href="index.php?action=users" class="<?= $action==='users'?'active':'' ?>">👤 Users</a>
<a href="index.php?action=notify" class="<?= $action==='notify'?'active':'' ?>">🔔 Notifications</a>
<a href="index.php?action=settings" class="<?= $action==='settings'?'active':'' ?>">⚙️ Settings</a>
<a href="index.php?action=publish" class="<?= $action==='publish'?'active':'' ?>">🚀 Publish Site</a>
<a href="index.php?action=health" class="<?= $action==='health'?'active':'' ?>">🩺 Health</a>
<a href="index.php?action=audit" class="<?= $action==='audit'?'active':'' ?>">📜 Audit Log</a>
<a href="login.php?logout=1" class="logout">🚪 Logout</a>
</div>
<div class="main">
<form method="get" action="index.php" class="topsearch" style="margin:0 0 12px;display:flex;gap:8px;"><input type="hidden" name="action" value="search"><input type="text" name="q" value="<?= esc($_GET['q'] ?? '') ?>" placeholder="🔍 Search products, brands, categories…" style="flex:1;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;"><button style="background:#053d20;color:#fff;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;">Search</button></form>
<?php if($msg) echo "<div class='msg'>".esc($msg)."</div>"; ?>
<style>
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap: 16px; margin: 24px 0; }
.dash-card { background: #fff; border: 1px solid var(--border,#e5e1d8); border-radius: 10px; padding: 18px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
.dash-card .num { font-size: 2em; font-weight: 700; color: #053d20; }
.dash-card .lbl { color: var(--muted-foreground,#7a7267); font-size: .85em; margin-top: 4px; }
.dash-card a { color: inherit; text-decoration: none; }
.dash-card a:hover .num { color: #2e6b3e; }
.recent-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.recent-table th, .recent-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border,#eee); font-size: .85em; }
.recent-table th { color: var(--muted-foreground,#7a7267); font-weight: 600; }
.dash-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 16px 0; }
.btn-dash { background: #2e6b3e; color: #fff; border: none; padding: 9px 18px; border-radius: 7px; font-size: .9em; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-dash:hover { background: #255e2e; }
.btn-dash.outline { background: transparent; color: #2e6b3e; border: 1px solid #2e6b3e; }
.btn-dash.outline:hover { background: rgba(46,107,62,.06); }
</style>
<?php if($action==='dashboard'): ?>
<?php
// ---- Pro business overview (dark luxe, real data only — no demo numbers) ----
$d_hour = (int)date('G');
$d_greet = $d_hour < 12 ? 'Good morning' : ($d_hour < 18 ? 'Good afternoon' : 'Good evening');
$d_admin = function_exists('current_admin') ? (string)current_admin() : 'Admin';
$d_visitors = load_visitors();
if (!is_array($d_visitors)) $d_visitors = [];
$d_quotes = is_array($quotes_data) ? array_values($quotes_data) : [];
$d_n_products = count($products);
$d_n_brands = count($brands);
$d_n_cats = count($cats);
$d_n_quotes = count($d_quotes);
$d_n_clients = count($d_visitors);
$d_pending = 0;
foreach ($d_quotes as $dq) { if (($dq['status'] ?? 'new') === 'new') $d_pending++; }
$d_ym = date('Y-m');
$d_new_month = 0;
foreach ($d_visitors as $dv) { $ca = (string)($dv['created_at'] ?? $dv['created'] ?? ''); if (strlen($ca) >= 7 && substr($ca,0,7) === $d_ym) $d_new_month++; }
$d_months = [];
for ($mi = 5; $mi >= 0; $mi--) { $k = date('Y-m', strtotime('-'.$mi.' months')); $d_months[$k] = ['lbl' => date('M', strtotime('-'.$mi.' months')), 'quotes' => 0, 'clients' => 0]; }
foreach ($d_quotes as $dq) { $ca = (string)($dq['created_at'] ?? $dq['created'] ?? $dq['date'] ?? ''); $k = strlen($ca) >= 7 ? substr($ca,0,7) : ''; if (isset($d_months[$k])) $d_months[$k]['quotes']++; }
foreach ($d_visitors as $dv) { $ca = (string)($dv['created_at'] ?? $dv['created'] ?? ''); $k = strlen($ca) >= 7 ? substr($ca,0,7) : ''; if (isset($d_months[$k])) $d_months[$k]['clients']++; }
$d_qmax = 1;
foreach ($d_months as $dm) { $d_qmax = max($d_qmax, (int)$dm['quotes'], (int)$dm['clients']); }
foreach ([1,2,3,4,5,6,8,10,12,15,20,30,40,50,75,100,150,200,300,500] as $nn) { if ($d_qmax <= $nn) { $d_qmax = $nn; break; } }
if ($d_qmax <= 4) { $d_ticks = range(0, $d_qmax); } else { $d_ticks = [0, (int)round($d_qmax/3), (int)round(2*$d_qmax/3), $d_qmax]; }
$d_sub2par = [];
foreach (($cats ?? []) as $cc) { foreach (($cc['sub'] ?? []) as $ss) $d_sub2par[$ss['slug'] ?? ''] = $cc['slug'] ?? ''; }
$d_names = [];
foreach (($cats ?? []) as $cc) $d_names[$cc['slug'] ?? ''] = $cc['name'] ?? ($cc['slug'] ?? '');
$d_counts = [];
foreach (($products ?? []) as $pp) { $csl = $pp['cat'] ?? ''; $par = $d_sub2par[$csl] ?? $csl; if ($par === '') $par = '(none)'; $d_counts[$par] = ($d_counts[$par] ?? 0) + 1; }
arsort($d_counts);
$d_ch_total = array_sum($d_counts);
$d_recent_q = array_slice(array_reverse($d_quotes), 0, 5);
$d_noimg = 0; foreach (($products ?? []) as $p) { $im = trim($p['image'] ?? ''); if ($im === '' || $im === 'placeholder.png') $d_noimg++; }
$d_seen = []; $d_dupes = 0; foreach (($products ?? []) as $p) { $k = strtolower(trim($p['name'] ?? '')); if ($k === '') continue; if (isset($d_seen[$k])) $d_dupes++; else $d_seen[$k] = 1; }
$d_used = []; foreach (($products ?? []) as $p) $d_used[$p['brand'] ?? ''] = true;
$d_empty_brands = 0; foreach (($brands ?? []) as $b) { if (empty($d_used[$b['slug'] ?? ''])) $d_empty_brands++; }
$d_unread = 0; foreach (($msgs ?? []) as $m) { if (($m['read'] ?? false) !== true) $d_unread++; }
$d_mon = @json_decode(@file_get_contents(SITE_DIR.'/admin/data/monitor.json'), true);
$d_mon_ok = !empty($d_mon) && !empty($d_mon['ok']);
$d_mon_at = !empty($d_mon['at']) ? substr((string)$d_mon['at'], 0, 16) : '';
?>
<style>
.lux-wrap{color:#f0ece0;}
.lux-top{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:4px 0 18px;}
.lux-top .hi{font-size:13px;color:#5c7064;}
.lux-top h2{font-family:Georgia,serif;font-size:26px;margin:2px 0 4px;color:#0e2a1f;font-weight:400;}
.lux-top .sub{font-size:13px;color:#5c7064;}
.lux-pills{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.lux-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#0e2a1f;background:#fff;border:1px solid rgba(201,162,63,.55);border-radius:999px;padding:7px 13px;box-shadow:0 1px 3px rgba(14,42,31,.08);}
.lux-pill.ok{border-color:rgba(46,160,90,.6);color:#1c7a45;}
.lux-pill.bad{border-color:rgba(220,80,80,.6);color:#b23b3b;}
.lux-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px;}
.lux-kpi{background:linear-gradient(160deg,#0e3524,#081f15);border:1px solid rgba(201,162,63,.28);border-radius:14px;padding:16px;display:flex;gap:12px;align-items:flex-start;text-decoration:none;color:inherit;transition:transform .12s,border-color .12s;}
.lux-kpi:hover{border-color:rgba(201,162,63,.6);transform:translateY(-1px);}
.lux-ic{width:42px;height:42px;flex:none;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;background:rgba(201,162,63,.14);border:1px solid rgba(201,162,63,.25);}
.lux-kpi .v{font-size:24px;font-weight:700;color:#fff;line-height:1.1;}
.lux-kpi .t{font-size:12.5px;color:#cfc9b8;margin-top:2px;}
.lux-kpi .d{font-size:11.5px;margin-top:5px;color:#8fd0a6;}
.lux-kpi .d.warn{color:#e8c96a;}
.lux-row{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px;}
.lux-card{background:linear-gradient(160deg,#0d3222,#081f15);border:1px solid rgba(201,162,63,.28);border-radius:14px;padding:18px;min-width:0;}
.lux-card h3{margin:0 0 2px;font-size:15px;color:#fff;font-weight:600;}
.lux-card .cap{font-size:12px;color:#a9bcb0;margin-bottom:12px;}
.lux-card .head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;}
.lux-link{font-size:12px;color:#e8c96a;text-decoration:none;white-space:nowrap;}
.lux-link:hover{text-decoration:underline;}
.lux-legend{display:flex;gap:14px;font-size:12px;color:#cfc9b8;margin:2px 0 8px;}
.lux-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px;vertical-align:baseline;}
.lux-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.lux-table th{text-align:left;color:#a9bcb0;font-weight:600;padding:8px 10px;border-bottom:1px solid rgba(201,162,63,.25);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;}
.lux-table td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.06);color:#e8e2d2;}
.lux-table tr:last-child td{border-bottom:none;}
.lux-st{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:3px 10px;}
.lux-st.new{background:rgba(201,162,63,.18);color:#e8c96a;border:1px solid rgba(201,162,63,.4);}
.lux-st.viewed{background:rgba(120,160,220,.15);color:#a9c4ef;border:1px solid rgba(120,160,220,.4);}
.lux-st.done{background:rgba(46,160,90,.15);color:#8fd0a6;border:1px solid rgba(46,160,90,.45);}
.lux-empty{text-align:center;padding:26px 12px;color:#a9bcb0;font-size:13px;}
.lux-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
.lux-btn{background:#c9a23f;color:#0a2117;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;}
.lux-btn:hover{background:#dcb84f;}
.lux-btn.ghost{background:transparent;color:#e8c96a;border:1px solid rgba(201,162,63,.5);}
.lux-btn.ghost:hover{background:rgba(201,162,63,.1);}
.lux-att{display:flex;align-items:center;gap:9px;font-size:12.5px;text-decoration:none;color:#e8e2d2;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.lux-att:last-child{border-bottom:none;}
.lux-att .n{margin-left:auto;font-weight:700;color:#fff;background:rgba(255,255,255,.08);border-radius:999px;padding:1px 9px;font-size:11.5px;}
.lux-att .n.red{background:rgba(200,60,60,.25);color:#f3b8b8;}
.lux-topcat{display:flex;align-items:center;gap:10px;padding:7px 0;font-size:12.5px;text-decoration:none;color:#e8e2d2;border-bottom:1px solid rgba(255,255,255,.05);}
.lux-topcat:last-child{border-bottom:none;}
.lux-topcat .bar{flex:1;height:8px;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden;min-width:40px;}
.lux-topcat .bar i{display:block;height:8px;border-radius:6px;background:linear-gradient(90deg,#8a6d1f,#c9a23f);}
.lux-topcat .pc{color:#a9bcb0;font-size:11.5px;width:38px;text-align:right;}
.lux-topcat b{width:30px;text-align:right;color:#fff;}
.lux-donut-flex{display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
.lux-dleg{flex:1;min-width:170px;}
.lux-dleg a{display:flex;align-items:center;gap:8px;font-size:12.5px;text-decoration:none;padding:4px 0;color:#e8e2d2;}
.lux-dleg a:hover span.lb{color:#e8c96a;}
@media(max-width:900px){.lux-kpis{grid-template-columns:1fr 1fr;}.lux-row{grid-template-columns:1fr;}.lux-top h2{font-size:21px;}}
</style>
<div class="lux-wrap">
<div class="lux-top">
  <div>
    <div class="hi"><?= esc($d_greet) ?>, <?= esc($d_admin) ?></div>
    <h2>Here&rsquo;s your business overview</h2>
    <div class="sub">Track your catalog, quotes and growth in real time.</div>
  </div>
  <div class="lux-pills">
    <span class="lux-pill">&#x1F4C5; Last 6 months</span>
    <?php if (!empty($d_mon)): ?><span class="lux-pill <?php echo $d_mon_ok ? 'ok' : 'bad'; ?>"><?php echo $d_mon_ok ? '&#x2714; Site OK' : '&#x2716; Check needed'; ?><?php echo $d_mon_at !== '' ? ' &middot; '.esc($d_mon_at) : ''; ?></span><?php endif; ?>
  </div>
</div>
<div class="lux-kpis">
  <a class="lux-kpi" href="index.php?action=products"><div class="lux-ic">&#x1F4E6;</div><div><div class="v"><?= $d_n_products ?></div><div class="t">Total Products</div><div class="d">across <?= $d_n_brands ?> brands</div></div></a>
  <a class="lux-kpi" href="index.php?action=brands"><div class="lux-ic">&#x1F3F7;&#xFE0F;</div><div><div class="v"><?= $d_n_brands ?></div><div class="t">Total Brands</div><div class="d">in <?= $d_n_cats ?> categories</div></div></a>
  <a class="lux-kpi" href="index.php?action=quotes"><div class="lux-ic">&#x1F9FE;</div><div><div class="v"><?= $d_n_quotes ?></div><div class="t">Total Quotes</div><?php if ($d_n_quotes > 0): ?><div class="d<?= $d_pending > 0 ? ' warn' : '' ?>"><?= $d_pending ?> pending</div><?php else: ?><div class="d warn">no quotes yet</div><?php endif; ?></div></a>
  <a class="lux-kpi" href="index.php?action=visitors"><div class="lux-ic">&#x1F465;</div><div><div class="v"><?= $d_n_clients ?></div><div class="t">Total Clients</div><?php if ($d_new_month > 0): ?><div class="d">+<?= $d_new_month ?> this month</div><?php else: ?><div class="d">registered accounts</div><?php endif; ?></div></a>
</div>
<div class="lux-row">
  <div class="lux-card">
    <div class="head"><div><h3>Activity Overview</h3><div class="cap">Quotes &amp; new clients per month &mdash; live data</div></div></div>
    <div class="lux-legend"><span><span class="lux-dot" style="background:#c9a23f"></span>Quotes</span><span><span class="lux-dot" style="background:#5fae7f"></span>New clients</span></div>
<?php
$d_W = 620; $d_H = 230; $d_pL = 36; $d_pR = 12; $d_pT = 14; $d_pB = 26;
$d_n = count($d_months);
$d_stepX = ($d_W - $d_pL - $d_pR) / max(1, $d_n - 1);
$d_y = function($v) use ($d_H, $d_pT, $d_pB, $d_qmax) { return $d_pT + ($d_H - $d_pT - $d_pB) * (1 - $v / max(1, $d_qmax)); };
$d_qpts = []; $d_cpts = []; $d_labs = []; $i = 0;
foreach ($d_months as $dm) { $x = $d_pL + $i * $d_stepX; $d_qpts[] = [$x, $d_y((int)$dm['quotes'])]; $d_cpts[] = [$x, $d_y((int)$dm['clients'])]; $d_labs[] = ['x' => $x, 't' => $dm['lbl'], 'q' => (int)$dm['quotes'], 'c' => (int)$dm['clients']]; $i++; }
$d_area = 'M '.round($d_qpts[0][0],1).','.round($d_qpts[0][1],1);
for ($j = 1; $j < $d_n; $j++) $d_area .= ' L '.round($d_qpts[$j][0],1).','.round($d_qpts[$j][1],1);
$d_area .= ' L '.round($d_qpts[$d_n-1][0],1).','.round($d_y(0),1).' L '.round($d_qpts[0][0],1).','.round($d_y(0),1).' Z';
$d_qline = 'M '.round($d_qpts[0][0],1).','.round($d_qpts[0][1],1);
for ($j = 1; $j < $d_n; $j++) $d_qline .= ' L '.round($d_qpts[$j][0],1).','.round($d_qpts[$j][1],1);
$d_cline = 'M '.round($d_cpts[0][0],1).','.round($d_cpts[0][1],1);
for ($j = 1; $j < $d_n; $j++) $d_cline .= ' L '.round($d_cpts[$j][0],1).','.round($d_cpts[$j][1],1);
?>
    <svg viewBox="0 0 <?= $d_W ?> <?= $d_H ?>" style="width:100%;height:auto;display:block;" role="img" aria-label="Activity chart">
      <defs><linearGradient id="luxGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#c9a23f" stop-opacity=".45"/><stop offset="1" stop-color="#c9a23f" stop-opacity=".03"/></linearGradient></defs>
      <?php foreach ($d_ticks as $tk): $yy = $d_y($tk); ?>
      <line x1="<?= $d_pL ?>" y1="<?= round($yy,1) ?>" x2="<?= $d_W - $d_pR ?>" y2="<?= round($yy,1) ?>" stroke="rgba(255,255,255,.09)" stroke-width="1"/>
      <text x="<?= $d_pL - 7 ?>" y="<?= round($yy + 4,1) ?>" text-anchor="end" font-size="10" fill="#8fa398"><?= $tk ?></text>
      <?php endforeach; ?>
      <path d="<?= $d_area ?>" fill="url(#luxGold)"/>
      <path d="<?= $d_qline ?>" fill="none" stroke="#c9a23f" stroke-width="2.5" stroke-linejoin="round"/>
      <path d="<?= $d_cline ?>" fill="none" stroke="#5fae7f" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>
      <?php foreach ($d_labs as $li): ?>
      <text x="<?= round($li['x'],1) ?>" y="<?= $d_H - 8 ?>" text-anchor="middle" font-size="10.5" fill="#8fa398"><?= esc($li['t']) ?></text>
      <?php endforeach; ?>
      <?php $li2 = 0; foreach ($d_months as $dm): ?>
      <circle cx="<?= round($d_qpts[$li2][0],1) ?>" cy="<?= round($d_qpts[$li2][1],1) ?>" r="4" fill="#0d3222" stroke="#c9a23f" stroke-width="2.5"><title><?= esc($d_labs[$li2]['t']) ?>: <?= (int)$dm['quotes'] ?> quotes, <?= (int)$dm['clients'] ?> new clients</title></circle>
      <?php $li2++; endforeach; ?>
    </svg>
  </div>
  <div class="lux-card">
    <div class="head"><div><h3>Catalog by Category</h3><div class="cap"><?= $d_ch_total ?> products live</div></div><a class="lux-link" href="index.php?action=cats">View all</a></div>
<?php if ($d_ch_total > 0): $d_c = 2 * M_PI * 70; $d_off = 0; $d_pi = 0; $d_pal = ['#c9a23f','#2e8b57','#e0a100','#66a380','#8a6d2f','#b7d3c2','#3f9e63','#d4af37']; ?>
    <div class="lux-donut-flex">
    <svg width="150" height="150" viewBox="0 0 190 190">
      <circle cx="95" cy="95" r="70" fill="none" stroke="#143a29" stroke-width="26"/>
      <?php foreach ($d_counts as $csl => $cnt): if ($cnt <= 0) continue; $f = $cnt / max(1, $d_ch_total); $col = $d_pal[$d_pi % count($d_pal)]; $d_pi++; ?>
      <circle cx="95" cy="95" r="70" fill="none" stroke="<?= $col ?>" stroke-width="26" stroke-dasharray="<?= round($f*$d_c,1) ?> <?= round($d_c,1) ?>" stroke-dashoffset="<?= round(-$d_off,1) ?>" transform="rotate(-90 95 95)"/>
      <?php $d_off += $f * $d_c; endforeach; ?>
      <text x="95" y="92" text-anchor="middle" font-size="26" font-weight="700" fill="#ffffff"><?= $d_ch_total ?></text>
      <text x="95" y="112" text-anchor="middle" font-size="11" fill="#8fa398">products</text>
    </svg>
    <div class="lux-dleg">
      <?php $d_pi = 0; foreach ($d_counts as $csl => $cnt): $col = $d_pal[$d_pi % count($d_pal)]; $d_pi++; $pc = round($cnt / max(1,$d_ch_total) * 100); ?>
      <a href="category.php?cat=<?= esc($csl) ?>"><span class="lux-dot" style="background:<?= $col ?>"></span><span class="lb" style="flex:1;"><?= esc($d_names[$csl] ?? $csl) ?></span><b style="color:#fff;"><?= $pc ?>%</b></a>
      <?php endforeach; ?>
    </div>
    </div>
<?php else: ?><div class="lux-empty">No products yet.</div><?php endif; ?>
  </div>
</div>
<div class="lux-row">
  <div class="lux-card">
    <div class="head"><div><h3>Recent Quotes</h3><div class="cap">Latest quote requests from customers</div></div><a class="lux-link" href="index.php?action=quotes">View all</a></div>
    <?php if (empty($d_recent_q)): ?>
    <div class="lux-empty">No quotes yet &mdash; share your quote page to get the first order.</div>
    <?php else: ?>
    <table class="lux-table">
      <tr><th>Quote</th><th>Customer</th><th>Items</th><th>Status</th><th>Date</th></tr>
      <?php foreach ($d_recent_q as $ri => $rq): $st = strtolower((string)($rq['status'] ?? 'new')); $stc = $st === 'new' ? 'new' : (($st === 'viewed') ? 'viewed' : 'done'); $its = $rq['items'] ?? []; $nic = is_array($its) ? count($its) : 0; ?>
      <tr>
        <td><b style="color:#fff;">#<?= esc((string)($rq['id'] ?? '-')) ?></b></td>
        <td><?= esc((string)($rq['name'] ?? $rq['customer'] ?? $rq['company'] ?? '-')) ?></td>
        <td><?= $nic > 0 ? $nic : '&mdash;' ?></td>
        <td><span class="lux-st <?= $stc ?>"><?= esc(ucfirst($st)) ?></span></td>
        <td style="color:#a9bcb0;"><?= esc(substr((string)($rq['created_at'] ?? $rq['created'] ?? $rq['date'] ?? '-'), 0, 10)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <div>
    <div class="lux-card" style="margin-bottom:14px;">
      <div class="head"><div><h3>Top Categories</h3><div class="cap">By product count</div></div><a class="lux-link" href="index.php?action=cats">View all</a></div>
      <?php $d_top = array_slice($d_counts, 0, 5, true); $d_mx = max(1, max($d_counts ?: [1])); foreach ($d_top as $csl => $cnt): $pc = round($cnt / max(1, $d_ch_total) * 100); ?>
      <a class="lux-topcat" href="category.php?cat=<?= esc($csl) ?>"><span style="width:110px;flex:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= esc($d_names[$csl] ?? $csl) ?></span><span class="bar"><i style="width:<?= round($cnt / $d_mx * 100) ?>%"></i></span><b><?= $cnt ?></b><span class="pc"><?= $pc ?>%</span></a>
      <?php endforeach; ?>
    </div>
    <div class="lux-card">
      <div class="head"><div><h3>Needs Attention</h3><div class="cap">Items waiting for you</div></div></div>
<?php
$d_att = [
  ['lbl' => 'Products w/o image', 'url' => 'index.php?action=products', 'n' => $d_noimg],
  ['lbl' => 'Duplicate names', 'url' => 'index.php?action=products', 'n' => $d_dupes],
  ['lbl' => 'Brands w/o products', 'url' => 'index.php?action=brands', 'n' => $d_empty_brands],
  ['lbl' => 'Pending quotes', 'url' => 'index.php?action=quotes', 'n' => $d_pending],
  ['lbl' => 'Unread messages', 'url' => 'index.php?action=messages', 'n' => $d_unread],
];
$d_att_total = $d_noimg + $d_dupes + $d_empty_brands + $d_pending + $d_unread;
?>
      <?php if ($d_att_total <= 0): ?><div class="lux-empty" style="color:#8fd0a6;font-weight:700;">&#x2714; All clear &mdash; nothing needs you.</div><?php endif; ?>
      <?php foreach ($d_att as $ai): ?>
      <a class="lux-att" href="<?= $ai['url'] ?>"><span class="lux-dot" style="background:<?= $ai['n'] > 0 ? '#d05050' : '#2ea05a' ?>"></span><?= esc($ai['lbl']) ?><span class="n<?= $ai['n'] > 0 ? ' red' : '' ?>"><?= $ai['n'] ?></span></a>
      <?php endforeach; ?>
      <div class="lux-actions" style="margin-top:12px;">
        <a href="index.php?action=products" class="lux-btn">+ Add Product</a>
        <a href="index.php?action=publish" class="lux-btn ghost">Publish Now</a>
        <a href="index.php?action=monrun" class="lux-btn ghost">Run Check</a>
        <a href="index.php?action=backup" class="lux-btn ghost">Backup</a>
        <a href="index.php?action=csv_export" class="lux-btn ghost">Export CSV</a>
        <a href="index.php?action=purge" class="lux-btn ghost" onclick="return confirm('Purge CDN cache? This refreshes all visitors\' cached CSS.');">Purge Cache</a>
      </div>
    </div>
  </div>
</div>
</div>
<?php elseif($action==='brands'): ?>
<h1 id="brandsTop">Brands</h1><?php if($edit_brand): ?><script>window.addEventListener("DOMContentLoaded",function(){document.getElementById("brandsTop").scrollIntoView({behavior:"smooth"});});</script><?php endif; ?>
<a href="index.php?action=brands" style="color:#053d20;" onclick="if(window.innerWidth<=860){var w=document.getElementById('brandFormWrap');w.classList.toggle('open');this.textContent=w.classList.contains('open')?'✕ Close':'+ Add Brand';window.scrollTo(0,0);return false;}">+ Add Brand</a>
<div id="brandFormWrap"<?= !empty($edit_brand) ? ' class="open"' : '' ?>>
<?php if($edit_brand): ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= (int)$_GET['edit_brand'] ?>">
<label>Brand name</label><input type="text" name="name" value="<?= esc($edit_brand['name']) ?>" required>
<label>Category</label><select name="category"><option value="">—</option><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option <?= ($c['slug']===$edit_brand['category'])?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option <?= ($s['slug']===$edit_brand['category'])?'selected':'' ?> value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option <?= ($c['slug']===$edit_brand['category'])?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<label>Logo (replace)</label><input type="file" name="image" accept="image/*">
<button name="save_brand">Update</button></form>
<?php else: ?>
<form method="post" enctype="multipart/form-data">
<label>Brand name</label><input type="text" name="name" required>
<label>Category</label><select name="category"><option value="">—</option><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option  value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<label>Logo *</label><input type="file" name="image" accept="image/*" required>
<button name="save_brand">Save Brand</button></form>
<?php endif; ?>
</div>
<div style="margin-top:20px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<button class="ctoggle" onclick="var t=document.getElementById('brandToolbarHead');t.classList.toggle('open');this.textContent=t.classList.contains('open')?'✕ Hide tools':'🔍 Show tools'">🔍 Show tools</button>
<div id="brandToolbarHead" style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<input type="text" id="brandSearch" placeholder="Search brands..." onkeyup="filterBrands()" style="flex:1;min-width:180px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<select id="brandCatFilter" onchange="filterBrands()" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?= esc($c['slug']) ?>"><?= esc($c['name']) ?></option><?php endforeach; ?></select>
<span style="font-size:12px;color:#6b7280"><span id="brandCount"><?= count($brands) ?></span> brands</span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllBrands(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllBrands(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteBrands()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDeleteBtn">Delete Selected (<span id="selCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px" id="brandsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb"><tr><th style="padding:8px 12px;text-align:left;width:32px"><input type="checkbox" id="selectAll" onchange="selectAllBrands(this.checked)"></th><th style="padding:8px 12px;text-align:left;width:48px">Logo</th><th style="padding:8px 12px;text-align:left">Brand</th><th style="padding:8px 12px;text-align:left">Category</th><th style="padding:8px 12px;text-align:center">Products</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php 
$brandCounts = [];
foreach($products as $p){ $br = $p['brand']??''; $brandCounts[$br] = ($brandCounts[$br]??0)+1; }
foreach($brands as $i=>$b): 
  $cat = $b['category']??''; 
  $cnt = $brandCounts[$b['slug']]??0;
?>
<tr data-name="<?= esc(strtolower($b['name'])) ?>" data-cat="<?= esc($cat) ?>" style="border-bottom:1px solid #f3f4f6">
<td style="padding:6px 12px"><input type="checkbox" class="brandCheck" value="<?= $i ?>" onchange="updateBulkBtn()"></td>
<td style="padding:6px 12px"><?php if(!empty($b['image']) && file_exists(SITE_DIR . '/assets/img/' . $b['image'])): ?><img src="../assets/img/<?= esc($b['image']) ?>" style="width:36px;height:36px;object-fit:contain;border:1px solid #eee;border-radius:6px;background:#fff" onerror="this.style.display='none'"><?php else: ?><div style="width:36px;height:36px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#6b7280"><?= esc(strtoupper(substr($b['name'],0,1))) ?></div><?php endif; ?></td>
<td style="padding:6px 12px"><b><?= esc($b['name']) ?></b><div style="font-size:11px;color:#9ca3af"><?= esc($b['slug']) ?></div></td>
<td style="padding:6px 12px"><span style="background:#f3f4f6;padding:2px 8px;border-radius:999px;font-size:11px"><?= esc($cat?:'—') ?></span></td>
<td style="padding:6px 12px;text-align:center"><span style="background:#ecfdf5;color:#065f46;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600"><?= $cnt ?></span></td>
<td style="padding:6px 12px;text-align:right"><a href="index.php?action=brands&edit_brand=<?= $i ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=brands&del_brand=<?= $i ?>" onclick="return confirm('Delete <?= esc($b['name']) ?>?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<script>
function filterBrands(){
  var q=document.getElementById('brandSearch').value.toLowerCase();
  var cat=document.getElementById('brandCatFilter').value;
  var rows=document.querySelectorAll('#brandsTable tbody tr');
  var visible=0;
  rows.forEach(function(r){
    var name=r.getAttribute('data-name');
    var c=r.getAttribute('data-cat');
    var show=(q===''||name.indexOf(q)>=0) && (cat===''||c===cat);
    r.style.display=show?'':'none';
    if(show) visible++;
  });
  document.getElementById('brandCount').textContent=visible;
}
function selectAllBrands(checked){
  document.querySelectorAll('.brandCheck').forEach(function(cb){
    if(cb.closest('tr').style.display!=='none') cb.checked=checked;
  });
  document.getElementById('selectAll').checked=checked;
  updateBulkBtn();
}
function updateBulkBtn(){
  var sel=document.querySelectorAll('.brandCheck:checked').length;
  document.getElementById('selCount').textContent=sel;
  document.getElementById('bulkDeleteBtn').style.display=sel>0?'':'none';
}
function bulkDeleteBrands(){
  var ids=Array.from(document.querySelectorAll('.brandCheck:checked')).map(function(cb){return cb.value;});
  if(!ids.length) return;
  if(!confirm('Delete '+ids.length+' brands?')) return;
  var i=0;
  function next(){
    if(i>=ids.length){ location.reload(); return; }
    fetch('index.php?action=brands&del_brand='+ids[i],{method:'GET'}).then(function(){ i++; next(); });
  }
  next();
}
</script>

<?php elseif($action==='products'): ?>
<h1 id="productsTop">Products</h1><?php if($edit_product): ?><script>window.addEventListener("DOMContentLoaded",function(){document.getElementById("productsTop").scrollIntoView({behavior:"smooth"});});</script><?php endif; ?>
<?php
$q = trim($_GET['q'] ?? '');
$fc = trim($_GET['filter_cat'] ?? '');
$fb = trim($_GET['filter_brand'] ?? '');
$fo = trim($_GET['filter_origin'] ?? '');
$fp = trim($_GET['filter_price'] ?? '');
$ff = trim($_GET['filter_feat'] ?? '');
$fh = trim($_GET['filter_chan'] ?? '');
$sort = trim($_GET['sort'] ?? '');
$pg = max(1, intval($_GET['pg'] ?? 1));
$filtered = $products;
if ($q !== '') $filtered = array_filter($filtered, fn($p)=> stripos($p['name']??'', $q)!==false || stripos($p['brand']??'', $q)!==false || stripos($p['origin']??'', $q)!==false);
if ($fc !== '') $filtered = array_filter($filtered, fn($p)=> ($p['cat']??'')===$fc);
if ($fb !== '') $filtered = array_filter($filtered, fn($p)=> ($p['brand']??'')===$fb);
if ($fo !== '') $filtered = array_filter($filtered, fn($p)=> ($p['origin']??'')===$fo);
if ($fp === 'with') $filtered = array_filter($filtered, fn($p)=> !empty(trim($p['price']??'')));
if ($fp === 'without') $filtered = array_filter($filtered, fn($p)=> empty(trim($p['price']??'')));
if ($ff === 'yes') $filtered = array_filter($filtered, fn($p)=> !empty($p['featured']));
if ($ff === 'no') $filtered = array_filter($filtered, fn($p)=> empty($p['featured']));
if ($fh !== '') $filtered = array_filter($filtered, fn($p)=> ($p['channel']??'')===$fh);
$filtered = array_values($filtered);
// sort by order
if($sort==='name') usort($filtered, fn($a,$b)=> strcmp($a['name']??'',$b['name']??''));
else if($sort==='price') usort($filtered, fn($a,$b)=> floatval(preg_replace('/[^0-9.]/','',$a['price']??'')) <=> floatval(preg_replace('/[^0-9.]/','',$b['price']??'')));
else usort($filtered, fn($a,$b)=> ($a['order']??0) <=> ($b['order']??0));
// pagination
$totalFiltered = count($filtered);
$perPage = 50;
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if($pg > $totalPages) $pg = $totalPages;
$paged = array_slice($filtered, ($pg-1)*$perPage, $perPage);
// map paged items to original index for edit/delete
$origIndexMap = [];
foreach($paged as $pf){ foreach($products as $oi=>$op){ if(($op['name']??'')===($pf['name']??'') && ($op['cat']??'')===($pf['cat']??'') && ($op['image']??'')===($pf['image']??'')){ $found=false; foreach($origIndexMap as $v) if($v==$oi) $found=true; if(!$found){ $origIndexMap[]=$oi; break; } } } }
// build query string helper for pagination links
function prod_qs($overrides=[]){
  $p=['action'=>'products','q'=>$_GET['q']??'','filter_cat'=>$_GET['filter_cat']??'','filter_brand'=>$_GET['filter_brand']??'','filter_origin'=>$_GET['filter_origin']??'','filter_price'=>$_GET['filter_price']??'','filter_feat'=>$_GET['filter_feat']??'','filter_chan'=>$_GET['filter_chan']??'','sort'=>$_GET['sort']??''];
  foreach($overrides as $k=>$v) $p[$k]=$v;
  $p=array_filter($p, fn($v)=> $v!=='' && $v!==null);
  return http_build_query($p);
}
?>
<div style="position:sticky;top:0;z-index:5;background:#eef1ee;padding:10px 0 12px;border-bottom:1px solid #e5e7eb;margin-bottom:12px">
<button class="filter-toggle" onclick="var t=document.getElementById('prodToolbar');t.classList.toggle('open');this.textContent=t.classList.contains('open')?'✕ Hide filters':'🔍 Show filters'">🔍 Show filters</button>
<form method="get" id="prodToolbar" class="toolbar" style="flex-wrap:wrap;margin-bottom:0">
<input type="hidden" name="action" value="products">
<input type="text" name="q" placeholder="Search name / brand / origin…" value="<?= esc($q) ?>" style="min-width:160px">
<select name="filter_cat"><option value="">All categories</option><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option <?= $fc===$c['slug']?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option <?= $fc===$s['slug']?'selected':'' ?> value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option <?= $fc===$c['slug']?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<select name="filter_brand"><option value="">All brands</option><?php foreach($brands_alpha as $b): ?><option <?= $fb===$b['slug']?'selected':'' ?> value="<?= $b['slug'] ?>"><?= esc($b['name']) ?></option><?php endforeach; ?></select>
<select name="filter_origin"><option value="">All origins</option><?php $origins=array_unique(array_filter(array_map(fn($x)=>$x['origin']??'', $products))); sort($origins); foreach($origins as $o): ?><option <?= $fo===$o?'selected':'' ?> value="<?= esc($o) ?>"><?= esc($o) ?></option><?php endforeach; ?></select>
<select name="filter_price"><option value="">Any price</option><option <?= $fp==='with'?'selected':'' ?> value="with">With price</option><option <?= $fp==='without'?'selected':'' ?> value="without">No price</option></select>
<select name="filter_feat"><option value="">Any featured</option><option <?= $ff==='yes'?'selected':'' ?> value="yes">Featured ★</option><option <?= $ff==='no'?'selected':'' ?> value="no">Not featured</option></select>
<select name="filter_chan"><option value="">Any channel</option><?php foreach(($ext['channels']??[]) as $ck=>$cv): ?><option <?= $fh===$ck?'selected':'' ?> value="<?= esc($ck) ?>"><?= esc($cv) ?></option><?php endforeach; ?></select>
<select name="sort"><option value="">Sort: Order</option><option <?= $sort==='name'?'selected':'' ?> value="name">Name A-Z</option><option <?= $sort==='price'?'selected':'' ?> value="price">Price</option></select>
<button type="submit" style="margin-top:0;">Filter</button>
<a href="index.php?action=products" style="color:#053d20;">Reset</a>
<span style="color:#6b7280;font-size:13px;font-weight:600"><?= $totalFiltered ?> / <?= count($products) ?> products<?php if($totalFiltered>0): ?> · page <?= $pg ?>/<?= $totalPages ?><?php endif; ?></span>
</form>
</div>
<?php if(isset($_GET['preview']) && isset($_SESSION['csv_preview'])): 
  $pv=$_SESSION['csv_preview']; $hdr=$pv['header']; $rows=$pv['rows'];
  $products_all=normalize_products(load_products()); $byName=[]; foreach($products_all as $pr) $byName[strtolower(trim($pr['name']??''))] = true;
  $newCnt=0; $updCnt=0; foreach($rows as $r){ $nm=strtolower(trim($r[array_search('name',$hdr)]??'')); if($nm==='') continue; if(isset($byName[$nm])) $updCnt++; else $newCnt++; }
?>
<div style="background:#fff;border:1.5px solid #0e4a2a;border-radius:12px;padding:16px;margin-bottom:16px">
<h3 style="color:#0e4a2a;margin:0 0 8px">📋 Import Preview — <?= count($rows) ?> rows (<?= $newCnt ?> new, <?= $updCnt ?> update)</h3>
<p style="font-size:12px;color:#888">Columns: <?= esc(implode(', ', $hdr)) ?> — will match by <b>name</b></p>
<div style="max-height:300px;overflow:auto;border:1px solid #eee;border-radius:8px;margin:10px 0">
<table style="margin:0;font-size:12px"><tr><?php foreach($hdr as $h): ?><th><?= esc($h) ?></th><?php endforeach; ?><th>Status</th></tr>
<?php foreach(array_slice($rows,0,20) as $r): $nm=strtolower(trim($r[array_search('name',$hdr)]??'')); $st=isset($byName[$nm])?'Update':'New'; $bg=$st==='New'?'#e8f5e9':'#fff3e0'; ?>
<tr style="background:<?= $bg ?>"><td><?= esc(implode('</td><td>', array_map(fn($x)=>substr($x,0,40), $r))) ?></td><td><span style="background:<?= $st==='New'?'#2e6b3e':'#ef6c00' ?>;color:#fff;padding:2px 7px;border-radius:999px;font-size:11px"><?= $st ?></span></td></tr>
<?php endforeach; ?>
<?php if(count($rows)>20): ?><tr><td colspan="<?= count($hdr)+1 ?>" style="text-align:center;color:#888">… and <?= count($rows)-20 ?> more rows</td></tr><?php endif; ?>
</table>
</div>
<form method="post" style="display:flex;gap:10px;align-items:center;background:transparent;box-shadow:none;padding:0">
<label style="margin:0"><input type="radio" name="import_mode" value="upsert" checked> Add new + update existing</label>
<label style="margin:0"><input type="radio" name="import_mode" value="add_only"> Add new only (skip existing)</label>
<button name="confirm_import" style="background:#0e4a2a;margin:0">✅ Confirm Import</button>
<a href="index.php?action=products" style="color:#b71c1c" onclick="fetch('index.php?action=products',{method:'POST',body:'clear_preview=1'})">Cancel</a>
</form>
</div>
<?php endif; ?>
<div style="background:#faf8f2;border:1px solid #e8e2d0;border-radius:10px;padding:12px;margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
<form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="file" name="csv" accept=".csv,.xlsx" required style="padding:6px">
<button name="preview_csv" style="margin:0;padding:8px 14px">👁️ Preview Import</button>
</form>
<span style="font-size:11px;color:#888">CSV columns: name, brand, cat, origin, desc, price, stock, featured, image, channel — or download <a href="csv_export.php">current CSV</a></span>
</div>
<div id="bulkBar" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
<span style="font-size:12px;color:#6b7280">Bulk edit selected:</span>
<select id="bulkAction"><option value="">Bulk action…</option><option value="delete">Delete</option><option value="feature">Feature ★</option><option value="unfeature">Unfeature</option><option value="cat">Move to category</option><option value="brand">Change brand</option><option value="origin">Change origin</option><option value="channel">Change channel</option><option value="price">Set price</option></select>
<input id="bulkVal" placeholder="value" style="display:none;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;min-width:140px">
<select id="bulkValCat" style="display:none;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px"><option value="">Choose…</option><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option  value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<select id="bulkValBrand" style="display:none;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px"><option value="">Choose…</option><?php foreach($brands_alpha as $b): ?><option value="<?= $b['slug'] ?>"><?= esc($b['name']) ?></option><?php endforeach; ?></select>
<button onclick="doBulk()" style="background:#053d20;color:#fff;margin:0;padding:7px 14px;border-radius:6px;border:0">Apply to selected (<span id="bulkCount">0</span>)</button>
<button onclick="clearSel()" style="background:#fff;color:#6b7280;border:1px solid #d1d5db;margin:0;padding:7px 10px;border-radius:6px">Clear</button>
<span style="font-size:11px;color:#9ca3af">Tip: use checkboxes in table above</span>
</div>
<?php if($edit_product): ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= (int)$_GET['edit_product'] ?>">
<div class="dropzone" id="dz-edit" onclick="document.getElementById('file-edit').click()">
  <?php if(!empty($edit_product['image'])): ?><img src="../assets/img/<?= esc($edit_product['image']) ?>" id="prev-edit"><?php endif; ?>
  <div>Drag & drop image here, or click to choose (leave empty to keep current)</div>
  <input type="file" id="file-edit" name="image" accept="image/*" style="display:none;" onchange="previewDZ(this,'prev-edit')">
</div>
<label>— OR pick from uploaded photos —</label>
<select name="pick_image">
<option value="">— choose existing image —</option>
<?php foreach(glob(IMG_DIR.'/rushed/*.{jpg,jpeg,png,webp}',GLOB_BRACE) as $f): $n=basename($f); ?>
<option value="<?= $n ?>" <?= ($edit_product['image']==='rushed/'.$n)?'selected':'' ?>><?= $n ?></option>
<?php endforeach; ?>
</select>
<label>Name</label><input type="text" name="name" value="<?= esc($edit_product['name']) ?>" required>
<label>Brand</label><select name="brand"><option value="">—</option><?php foreach($brands_alpha as $b): ?><option <?= ($b['slug']===$edit_product['brand'])?'selected':'' ?> value="<?= esc($b['slug']) ?>"><?= esc($b['name']) ?></option><?php endforeach; ?></select>
<label>Category</label><select name="category" required><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option <?= ($c['slug']===$edit_product['cat'])?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option <?= ($s['slug']===$edit_product['cat'])?'selected':'' ?> value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option <?= ($c['slug']===$edit_product['cat'])?'selected':'' ?> value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<label>Origin</label><input type="text" name="origin" value="<?= esc($edit_product['origin']??'') ?>">
<label>Description</label><textarea name="desc"><?= esc($edit_product['desc']??'') ?></textarea>
<label>Price (optional)</label><input type="text" name="price" value="<?= esc($edit_product['price']??'') ?>" placeholder="e.g. 1.25 JD">
<label>Stock / Availability (optional)</label><input type="text" name="stock" value="<?= esc($edit_product['stock']??'') ?>" placeholder="e.g. In stock">
<label><input type="checkbox" name="featured" <?= (!empty($edit_product['featured']))?'checked':'' ?>> Featured (show on homepage)</label>
<label>SEO Title (optional)</label><input type="text" name="seo_title" value="<?= esc($edit_product['seo_title']??'') ?>">
<label>SEO Description (optional)</label><textarea name="seo_desc"><?= esc($edit_product['seo_desc']??'') ?></textarea>
<label>Channel</label><select name="channel"><option value="">—</option><?php foreach(($ext['channels']??[]) as $ck=>$cv): ?><option <?= ($edit_product['channel']??'')===$ck?'selected':'' ?> value="<?= $ck ?>"><?= esc($cv) ?></option><?php endforeach; ?></select>
<button name="save_product">Update</button></form>
<?php else: ?>
<form method="post" enctype="multipart/form-data">
<div class="dropzone" id="dz-new" onclick="document.getElementById('file-new').click()">
  <div>Drag & drop product image here, or click to choose</div>
  <input type="file" id="file-new" name="image" accept="image/*" required style="display:none;" onchange="previewDZ(this,'prev-new')">
  <img id="prev-new" style="display:none;max-width:120px;max-height:120px;margin-top:10px;border-radius:8px;">
</div>
<label>Name</label><input type="text" name="name" required>
<label>Brand</label><select name="brand"><option value="">—</option><?php foreach($brands_alpha as $b): ?><option value="<?= esc($b['slug']) ?>"><?= esc($b['name']) ?></option><?php endforeach; ?></select>
<label>Category</label><select name="category" required><?php foreach($cats as $c): if(!empty($c['sub'])): ?><optgroup label="<?= esc($c['name']) ?>"><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?> (all)</option><?php foreach($c['sub'] as $s): ?><option  value="<?= $s['slug'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?></optgroup><?php else: ?><option  value="<?= $c['slug'] ?>"><?= esc($c['name']) ?></option><?php endif; endforeach; ?></select>
<label>Origin</label><input type="text" name="origin">
<label>Description</label><textarea name="desc"></textarea>
<label>Price (optional)</label><input type="text" name="price" placeholder="e.g. 1.25 JD">
<label>Stock / Availability (optional)</label><input type="text" name="stock" placeholder="e.g. In stock">
<label><input type="checkbox" name="featured"> Featured (show on homepage)</label>
<label>SEO Title (optional)</label><input type="text" name="seo_title">
<label>SEO Description (optional)</label><textarea name="seo_desc"></textarea>
<label>Channel</label><select name="channel"><option value="">—</option><?php foreach(($ext['channels']??[]) as $ck=>$cv): ?><option value="<?= $ck ?>"><?= esc($cv) ?></option><?php endforeach; ?></select>
<button name="save_product">Save Product</button></form>
<?php endif; ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;margin-top:12px">
<div style="padding:10px 14px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<span style="font-size:12px;color:#6b7280"><b><?= $totalFiltered ?></b> products<?php if($totalFiltered!==count($products)): ?> filtered<?php endif; ?> · showing <?= count($paged) ?> (page <?= $pg ?>/<?= $totalPages ?>)</span>
<div style="margin-left:auto;display:flex;gap:6px;align-items:center">
<button type="button" onclick="selectAllProd(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllProd(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkFeature(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">★ Feature</button>
<button type="button" onclick="bulkFeature(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">☆ Unfeature</button>
<button type="button" onclick="bulkDeleteProd()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelProdBtn">Delete (<span id="prodSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="productsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1"><tr><th style="padding:8px 12px;text-align:left;width:32px"><input type="checkbox" id="prodSelectAll" onchange="selectAllProd(this.checked)"></th><th style="padding:8px 12px;text-align:left;width:48px">Image</th><th style="padding:8px 12px;text-align:left">Name</th><th style="padding:8px 12px;text-align:left">Brand</th><th style="padding:8px 12px;text-align:left">Category</th><th style="padding:8px 12px;text-align:left">Origin</th><th style="padding:8px 12px;text-align:left">Price / Stock</th><th style="padding:8px 12px;text-align:center">★</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($paged as $pIdx=>$p): $oi=$origIndexMap[$pIdx]??$pIdx; ?>
<tr style="border-bottom:1px solid #f3f4f6">
<td style="padding:6px 12px"><input type="checkbox" class="prodCheck" value="<?= $oi ?>" onchange="updateProdBulk()"></td>
<td style="padding:6px 12px"><?php if(!empty($p['image']) && file_exists(SITE_DIR.'/assets/img/'.$p['image'])): ?><img src="../assets/img/<?= esc($p['image']) ?>" style="width:40px;height:40px;object-fit:contain;border:1px solid #eee;border-radius:6px;background:#fff" onerror="this.style.display='none'"><?php else: ?><div style="width:40px;height:40px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af">no img</div><?php endif; ?></td>
<td style="padding:6px 12px"><b style="color:#053d20"><?= esc($p['name']) ?></b><div style="font-size:11px;color:#9ca3af"><?= esc(substr($p['desc']??'',0,48)) ?></div></td>
<td style="padding:6px 12px"><span style="background:#f3f4f6;padding:2px 7px;border-radius:999px;font-size:11px"><?= esc($p['brand']?:'—') ?></span></td>
<td style="padding:6px 12px"><span onclick="quickCat(this)" data-i="<?= $oi ?>" style="cursor:pointer;border-bottom:1px dashed #9ca3af;background:#eef7f0;color:#053d20;padding:2px 7px;border-radius:999px;font-size:11px"><?= esc($p['cat']??'—') ?></span></td>
<td style="padding:6px 12px;font-size:12px"><span onclick="quickOrigin(this)" data-i="<?= $oi ?>" style="cursor:pointer;border-bottom:1px dashed #9ca3af"><?= esc($p['origin']?:'—') ?></span></td>
<td style="padding:6px 12px"><span class="qp" data-i="<?= $oi ?>" onclick="quickPrice(this)" style="cursor:pointer;border-bottom:1px dashed #9ca3af;font-weight:600"><?= esc($p['price']?:'no price') ?></span><?php if(!empty($p['stock'])): ?><div style="font-size:11px;color:#6b7280"><?= esc($p['stock']) ?></div><?php endif; ?></td>
<td style="padding:6px 12px;text-align:center"><?= !empty($p['featured'])?'<span style="color:#c9a23f;font-size:16px">★</span>':'<span style="color:#d1d5db">☆</span>' ?></td>
<td style="padding:6px 12px;text-align:right;white-space:nowrap"><a href="index.php?action=products&edit_product=<?= $oi ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=products&del_product=<?= $oi ?>" onclick="return confirm('Delete <?= esc(addslashes($p['name'])) ?>?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a> <form method="post" style="display:inline" onsubmit="return confirm('Duplicate?')"><input type="hidden" name="dup_product" value="<?= $oi ?>"><button type="submit" style="padding:4px 8px;border:1px solid #d1d5db;background:#f9fafb;color:#053d20;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600">⎘ Copy</button></form></td>
</tr>
<?php endforeach; ?>
<?php if(empty($paged)): ?><tr><td colspan="9" style="padding:24px;text-align:center;color:#9ca3af">No products match the filters. <a href="index.php?action=products" style="color:#053d20">Reset filters</a></td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php if($totalPages>1): ?>
<div style="padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;background:#f9fafb">
<?php if($pg>1): ?><a href="index.php?<?= prod_qs(['pg'=>$pg-1]) ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">‹ Prev</a><?php endif; ?>
<?php for($p=1;$p<=$totalPages;$p++): if($totalPages>12 && abs($p-$pg)>2 && $p!=1 && $p!=$totalPages){ if($p==2||$p==$totalPages-1) echo '<span style="padding:6px">…</span>'; continue; } ?>
<a href="index.php?<?= prod_qs(['pg'=>$p]) ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$pg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a>
<?php endfor; ?>
<?php if($pg<$totalPages): ?><a href="index.php?<?= prod_qs(['pg'=>$pg+1]) ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next ›</a><?php endif; ?>
<span style="font-size:12px;color:#6b7280;margin-left:8px"><?= $totalFiltered ?> total · <?= $totalPages ?> pages · 50/page</span>
</div>
<?php endif; ?>
</div>
<script>
let sel=new Set();
function selectAllProd(checked){
  document.querySelectorAll('.prodCheck').forEach(function(cb){ cb.checked=checked; });
  var m=document.getElementById('prodSelectAll'); if(m) m.checked=checked;
  updateProdBulk();
}
function updateProdBulk(){
  sel.clear();
  document.querySelectorAll('.prodCheck:checked').forEach(c=>sel.add(c.value));
  var cnt=document.getElementById('prodSelCount'); if(cnt) cnt.textContent=sel.size;
  var bc=document.getElementById('bulkCount'); if(bc) bc.textContent=sel.size;
  var btn=document.getElementById('bulkDelProdBtn'); if(btn) btn.style.display=sel.size>0?'':'none';
}
function bulkFeature(on){
  if(sel.size===0) return alert('Select products first (use checkboxes in table)');
  var act=on?'feature':'unfeature';
  var f=document.createElement('form'); f.method='post'; f.innerHTML='<input name="bulk_ids" value="'+Array.from(sel).join(',')+'"><input name="bulk_action" value="'+act+'">';
  document.body.appendChild(f); f.submit();
}
function bulkDeleteProd(){
  if(sel.size===0) return;
  if(!confirm('Delete '+sel.size+' products?')) return;
  var f=document.createElement('form'); f.method='post'; f.innerHTML='<input name="bulk_ids" value="'+Array.from(sel).join(',')+'"><input name="bulk_action" value="delete">';
  document.body.appendChild(f); f.submit();
}
function clearSel(){ document.querySelectorAll('.prodCheck').forEach(c=>c.checked=false); var m=document.getElementById('prodSelectAll'); if(m) m.checked=false; updateProdBulk(); }
document.getElementById('bulkAction')?.addEventListener('change', e=>{
  const v=e.target.value;
  document.getElementById('bulkVal').style.display=(v==='price'||v==='origin'||v==='channel')?'inline-block':'none';
  document.getElementById('bulkValCat').style.display=v==='cat'?'inline-block':'none';
  document.getElementById('bulkValBrand').style.display=v==='brand'?'inline-block':'none';
});
function doBulk(){
  const act=document.getElementById('bulkAction').value;
  if(!act||sel.size===0) return alert('Choose action and select products (checkboxes in table)');
  let val='';
  if(act==='cat') val=document.getElementById('bulkValCat').value;
  else if(act==='brand') val=document.getElementById('bulkValBrand').value;
  else if(['price','origin','channel'].includes(act)) val=document.getElementById('bulkVal').value;
  if(['cat','brand','origin','channel','price'].includes(act) && !val) return alert('Enter value');
  if(act==='delete' && !confirm('Delete '+sel.size+' products?')) return;
  const f=document.createElement('form'); f.method='post'; f.innerHTML='<input name="bulk_ids" value="'+Array.from(sel).join(',')+'"><input name="bulk_action" value="'+act+'"><input name="bulk_val" value="'+val.replace(/"/g,'&quot;')+'">';
  document.body.appendChild(f); f.submit();
}
function quickPrice(el){
  const i=el.dataset.i;
  const cur=el.textContent==='no price'?'':el.textContent;
  const nv=prompt('Price for product:', cur);
  if(nv===null) return;
  fetch('index.php?action=products',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'quick_price='+i+'&val='+encodeURIComponent(nv)})
    .then(r=>r.json()).then(()=>{ el.textContent=nv||'no price'; el.style.color='#2e6b3e'; });
}
function quickCat(el){
  const i=el.dataset.i;
  const cur=el.textContent.trim();
<?php $qcatsl=[]; foreach($cats as $cc){$qcatsl[]=$cc['slug']; foreach(($cc['sub']??[]) as $ss) $qcatsl[]=$ss['slug'];} ?>
  const cats=<?= json_encode(array_values($qcatsl)) ?>;
  const opts=cats.map(c=>c===cur?'* '+c:c).join('\n');
  const nv=prompt('Category (choose or type):\n'+opts, cur);
  if(nv===null) return;
  const v=nv.replace(/^\* /,'').trim();
  fetch('index.php?action=products',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'quick_cat='+i+'&val='+encodeURIComponent(v)})
    .then(r=>r.json()).then(()=>{ el.textContent=v||'—'; el.style.background='#dcfce7'; setTimeout(()=>el.style.background='#eef7f0',1000); });
}
function quickOrigin(el){
  const i=el.dataset.i;
  const cur=el.textContent.trim()==='—'?'':el.textContent.trim();
  const nv=prompt('Origin / Country:', cur);
  if(nv===null) return;
  fetch('index.php?action=products',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'quick_origin='+i+'&val='+encodeURIComponent(nv)})
    .then(r=>r.json()).then(()=>{ el.textContent=nv||'—'; el.style.color='#2e6b3e'; });
}
</script>

<?php elseif($action==='cats'): ?>
<h1 id="catsTop">Categories</h1><?php if($edit_cat): ?><script>window.addEventListener("DOMContentLoaded",function(){document.getElementById("catsTop").scrollIntoView({behavior:"smooth"});});</script><?php endif; ?>
<?php
$catCounts=[]; foreach($products as $pp){ $cc=$pp['cat']??''; $catCounts[$cc]=($catCounts[$cc]??0)+1; }
// handle inline edit
$edit_cat_idx=-1; $edit_sub_pi=-1; $edit_sub_si=-1;
if(isset($_GET['edit_cat'])){ $ec=$_GET['edit_cat']; if(strpos($ec,':')!==false){ $pt=explode(':',$ec); $edit_sub_pi=(int)($pt[0]??-1); $edit_sub_si=(int)($pt[1]??-1); } else $edit_cat_idx=(int)$ec; }
$edit_sub=null; if($edit_sub_pi>=0&&$edit_sub_si>=0){ $edit_sub=$cats[$edit_sub_pi]['sub'][$edit_sub_si]??null; if(!$edit_sub){$edit_sub_pi=-1;$edit_sub_si=-1;} }
?>
<?php if($edit_sub): ?>
<form method="post" style="max-width:520px">
<input type="hidden" name="id" value="sub:<?= $edit_sub_pi ?>:<?= $edit_sub_si ?>">
<label>Sub-category name</label><input type="text" name="name" value="<?= esc($edit_sub['name']) ?>" required>
<div style="font-size:12px;color:#6b7280;margin:6px 0">Parent: <b><?= esc($cats[$edit_sub_pi]['name']) ?></b></div>
<button name="save_cat">Update Sub-category</button> <a href="index.php?action=cats" style="margin-left:8px;color:#6b7280">Cancel</a>
</form>
<?php elseif($edit_cat_idx>=0 && isset($cats[$edit_cat_idx])): ?>
<form method="post" style="max-width:520px">
<input type="hidden" name="id" value="<?= $edit_cat_idx ?>">
<label>Category name</label><input type="text" name="name" value="<?= esc($cats[$edit_cat_idx]['name']) ?>" required>
<button name="save_cat">Update Category</button> <a href="index.php?action=cats" style="margin-left:8px;color:#6b7280">Cancel</a>
</form>
<?php else: ?>
<form method="post" style="max-width:520px">
<label>Category name</label><input type="text" name="name" required placeholder="e.g. Beverages">
<label>Parent category <span style="color:#9ca3af;font-weight:400">(leave empty for a main category)</span></label>
<select name="parent"><option value="">-- Main category --</option><?php foreach($cats as $pi=>$pc): ?><option value="<?= $pi ?>"><?= esc($pc['name']) ?></option><?php endforeach; ?></select>
<button name="save_cat" style="margin-top:8px">Add Category</button>
</form>
<div style="font-size:12px;color:#6b7280;margin-top:6px">To add a sub-category, type its name then choose its parent. Leave the parent empty to add a main category.</div>
<?php endif; ?>
<div style="margin-top:20px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<input type="text" id="catSearch" placeholder="Search categories..." onkeyup="filterCats()" style="flex:1;min-width:180px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<span style="font-size:12px;color:#6b7280"><span id="catCount"><?= count($cats) ?></span> categories</span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllCats(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllCats(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteCats()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelCatsBtn">Delete Selected (<span id="catSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="catsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb"><tr><th style="padding:8px 12px;text-align:left;width:32px"><input type="checkbox" id="catSelectAll" onchange="selectAllCats(this.checked)"></th><th style="padding:8px 12px;text-align:left">Category</th><th style="padding:8px 12px;text-align:left">Slug</th><th style="padding:8px 12px;text-align:center">Products</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($cats as $i=>$c): $ccnt=$catCounts[$c['slug']]??0; $subcnt=0; foreach(($c['sub']??[]) as $st) $subcnt+=($catCounts[$st['slug']]??0); ?>
<tr data-name="<?= esc(strtolower($c['name'])) ?>" style="border-bottom:1px solid #f3f4f6;background:#f9fafb">
<td style="padding:8px 12px"><input type="checkbox" class="catCheck" value="<?= $i ?>" onchange="updateCatBulk()"></td>
<td style="padding:8px 12px"><b style="color:#053d20"><?= esc($c['name']) ?></b><?php if(!empty($c['sub'])): ?> <span style="font-size:11px;color:#6b7280">· <?= count($c['sub']) ?> subs</span><?php endif; ?></td>
<td style="padding:8px 12px"><code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:11px"><?= esc($c['slug']) ?></code></td>
<td style="padding:8px 12px;text-align:center"><span style="background:<?= ($ccnt+$subcnt)>0?'#ecfdf5;color:#065f46':'#f3f4f6;color:#9ca3af' ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600"><?= $ccnt+$subcnt ?></span></td>
<td style="padding:8px 12px;text-align:right"><a href="index.php?action=cats&edit_cat=<?= $i ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=cats&del_cat=<?= $i ?>" onclick="return confirm('Delete <?= esc(addslashes($c['name'])) ?> and its sub-categories?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php foreach(($c['sub']??[]) as $si=>$s): $scnt=$catCounts[$s['slug']]??0; ?>
<tr data-name="<?= esc(strtolower($s['name'].' '.$c['name'])) ?>" style="border-bottom:1px solid #f3f4f6">
<td style="padding:8px 12px"></td>
<td style="padding:8px 12px"><span style="display:inline-block;width:22px;color:#9ca3af">--</span><?= esc($s['name']) ?> <span style="font-size:11px;color:#9ca3af">· under <?= esc($c['name']) ?></span></td>
<td style="padding:8px 12px"><code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:11px"><?= esc($s['slug']) ?></code></td>
<td style="padding:8px 12px;text-align:center"><span style="background:<?= $scnt>0?'#ecfdf5;color:#065f46':'#f3f4f6;color:#9ca3af' ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600"><?= $scnt ?></span></td>
<td style="padding:8px 12px;text-align:right"><a href="index.php?action=cats&edit_cat=<?= $i ?>:<?= $si ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=cats&del_sub=<?= $i ?>:<?= $si ?>" onclick="return confirm('Delete sub-category <?= esc(addslashes($s['name'])) ?>?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if(empty($cats)): ?><tr><td colspan="5" style="padding:24px;text-align:center;color:#9ca3af">No categories yet</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</div>
<script>
function filterCats(){
  var q=(document.getElementById('catSearch').value||'').toLowerCase();
  var rows=document.querySelectorAll('#catsTable tbody tr');
  var vis=0;
  rows.forEach(function(r){
    var name=r.getAttribute('data-name')||'';
    var show=!q||name.indexOf(q)>=0;
    r.style.display=show?'':'none';
    if(show) vis++;
  });
  document.getElementById('catCount').textContent=vis;
}
function selectAllCats(checked){
  document.querySelectorAll('.catCheck').forEach(function(cb){ if(cb.closest('tr').style.display!=='none') cb.checked=checked; });
  var m=document.getElementById('catSelectAll'); if(m) m.checked=checked;
  updateCatBulk();
}
function updateCatBulk(){
  var sel=document.querySelectorAll('.catCheck:checked').length;
  document.getElementById('catSelCount').textContent=sel;
  document.getElementById('bulkDelCatsBtn').style.display=sel>0?'':'none';
}
function bulkDeleteCats(){
  var ids=Array.from(document.querySelectorAll('.catCheck:checked')).map(function(cb){return cb.value;});
  if(!ids.length) return;
  if(!confirm('Delete '+ids.length+' categories?')) return;
  var f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="bulk_del_cats" value="'+ids.join(',')+'">'; document.body.appendChild(f); f.submit();
}
</script>

<?php elseif($action==='home'): ?>
<h1>Homepage Content</h1>
<form method="post">
<label>Hero title</label><input type="text" name="hero_title" value="<?= esc($home['hero_title']??'') ?>">
<label>Hero subtitle</label><textarea name="hero_sub"><?= esc($home['hero_sub']??'') ?></textarea>
<label>About text</label><textarea name="about"><?= esc($home['about']??'') ?></textarea>
<label>Why Us text (separate with |)</label><textarea name="why"><?= esc($home['why']??'') ?></textarea>
<h3 style="color:#053d20;margin-top:24px;">Stats</h3>
<?php for($i=0;$i<4;$i++): ?>
<label>Stat <?= $i+1 ?> number</label><input type="text" name="stat_num[<?= $i ?>]" value="<?= esc($stats[$i]['num']??'') ?>">
<label>Stat <?= $i+1 ?> label</label><input type="text" name="stat_lbl[<?= $i ?>]" value="<?= esc($stats[$i]['lbl']??'') ?>">
<?php endfor; ?>
<h3 style="color:#053d20;margin-top:24px;">Timeline</h3>
<?php for($i=0;$i<8;$i++): ?>
<label>Year <?= $i+1 ?></label><input type="text" name="tl_yr[<?= $i ?>]" value="<?= esc($timeline[$i]['yr']??'') ?>">
<label>Title <?= $i+1 ?></label><input type="text" name="tl_h[<?= $i ?>]" value="<?= esc($timeline[$i]['h']??'') ?>">
<label>Paragraph <?= $i+1 ?></label><textarea name="tl_p[<?= $i ?>]"><?= esc($timeline[$i]['p']??'') ?></textarea>
<?php endfor; ?>
<button name="save_home">Save</button></form>

<?php elseif($action==='media'): ?>
<h1>Media Library</h1>
<form method="post" enctype="multipart/form-data" style="margin-bottom:20px;">
<label>Bulk upload images (select multiple)</label>
<input type="file" name="bulk[]" accept="image/*" multiple required>
<button name="bulk_upload">Upload</button>
</form>
<form method="post" style="margin-bottom:24px;">
<button name="auto_match" style="background:#c9a23f;">⚡ Auto-match images to products by brand</button>
</form>
<?php
// ---- unused images scan ----
$orph_msg = '';
if (isset($_POST['del_orphans']) && !empty($_POST['orphan']) && is_array($_POST['orphan'])) {
  $del_n = 0; $del_kb = 0; $img_real = realpath(IMG_DIR);
  foreach ($_POST['orphan'] as $of) {
    $ob = basename((string)$of);
    if ($ob === '' || in_array($ob, ['logo.png','placeholder.png','favicon.ico'], true)) continue;
    foreach (array_merge(glob(IMG_DIR . '/' . $ob) ?: [], glob(IMG_DIR . '/rushed/' . $ob) ?: [], glob(IMG_DIR . '/brands/*/' . $ob) ?: []) as $gf) {
      $rp = realpath($gf);
      if ($rp && $img_real && strpos($rp, $img_real) === 0 && is_file($rp)) { $del_kb += filesize($rp); unlink($rp); $del_n++; }
    }
  }
  $orph_msg = "Deleted $del_n file(s) (" . round($del_kb/1024) . " KB).";
  @file_put_contents(SITE_DIR . '/admin/data/audit.log', date('Y-m-d H:i:s') . ' | ' . current_admin() . ' | del_orphans | ' . $del_n . " files\n", FILE_APPEND);
}
$orphans = []; $orphans_kb = 0; $orphans_scanned = false;
if (isset($_GET['scan_orphans'])) {
  $orphans_scanned = true;
  $used = [];
  foreach (($products ?? []) as $pp) { $im = trim($pp['image'] ?? ''); if ($im !== '') { $used[$im] = true; $used[basename($im)] = true; $used[pathinfo($im, PATHINFO_FILENAME)] = true; } }
  foreach (($brands ?? []) as $bb) { $lg = trim($bb['logo'] ?? ''); if ($lg !== '') { $used[$lg] = true; $used[basename($lg)] = true; } }
  foreach (['home.json','header_config.json','ext.json','settings.json'] as $jf) {
    $jd = function_exists('load_json') ? load_json(SITE_DIR . '/admin/data/' . $jf) : null;
    if (is_array($jd)) array_walk_recursive($jd, function($v) use (&$used){ if (is_string($v) && preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', trim($v))) { $used[trim($v)] = true; $used[basename(trim($v))] = true; } });
  }
  $safe = ['logo.png','placeholder.png','favicon.ico'];
  $files = array_merge(glob(IMG_DIR.'/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: [], glob(IMG_DIR.'/rushed/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: [], glob(IMG_DIR.'/brands/*/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: []);
  foreach ($files as $ff) {
    $bn = basename($ff); $rel = str_replace(IMG_DIR.'/', '', $ff);
    if (in_array($bn, $safe, true)) continue;
    if (isset($used[$bn]) || isset($used[$rel]) || isset($used[pathinfo($bn, PATHINFO_FILENAME)])) continue;
    $twin = preg_replace('/\.webp$/', '', $bn);
    if ($twin !== $bn && (isset($used[$twin]) || isset($used[pathinfo($twin, PATHINFO_FILENAME)]))) continue;
    $orphans[] = ['rel' => $rel, 'kb' => round(filesize($ff)/1024)];
    $orphans_kb += filesize($ff);
  }
  usort($orphans, function($a,$b){ return $b['kb'] <=> $a['kb']; });
  $orphans = array_slice($orphans, 0, 300);
}
?>
<h3 style="color:#053d20;margin-top:8px;">Unused images</h3>
<?php if ($orph_msg) echo "<div class='msg'>" . esc($orph_msg) . "</div>"; ?>
<p><a href="index.php?action=media&scan_orphans=1" style="background:#fff;color:#2e6b3e;border:1px solid #2e6b3e;padding:9px 18px;border-radius:7px;text-decoration:none;display:inline-block;">🧹 Scan unused images</a></p>
<?php if ($orphans_scanned): ?>
<?php if (!$orphans): ?><p style="color:#2e6b3e;font-weight:600;">✔ No unused images — library is clean.</p><?php else: ?>
<p style="color:#555;"><?= count($orphans) ?> unused file(s), <?= round($orphans_kb/1024) ?> KB total. Tick and delete — files are removed permanently.</p>
<form method="post">
<table class="recent-table"><tr><th style="width:32px"><input type="checkbox" onclick="document.querySelectorAll('.orphCheck').forEach(function(c){c.checked=this.checked},this)"></th><th>File</th><th>Size</th></tr>
<?php foreach ($orphans as $o): ?><tr><td><input type="checkbox" class="orphCheck" name="orphan[]" value="<?= esc($o['rel']) ?>"></td><td style="font-size:12px;"><?= esc($o['rel']) ?></td><td><?= $o['kb'] ?> KB</td></tr><?php endforeach; ?>
</table>
<p style="margin-top:12px;"><button name="del_orphans" onclick="return confirm('Permanently delete selected files?');" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:10px 20px;cursor:pointer;">Delete selected</button></p>
</form>
<?php endif; ?>
<?php endif; ?>
<?php
$mediaQ = trim($_GET['media_q'] ?? '');
$mediaPg = max(1, intval($_GET['mpg'] ?? 1));
$allMedia = glob(IMG_DIR.'/rushed/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
if($mediaQ!=='') $allMedia = array_values(array_filter($allMedia, fn($f)=> stripos(basename($f), $mediaQ)!==false));
$totalMedia = count($allMedia);
$mediaPerPage = 48;
$mediaPages = max(1, (int)ceil($totalMedia/$mediaPerPage));
if($mediaPg>$mediaPages) $mediaPg=$mediaPages;
$mediaPaged = array_slice($allMedia, ($mediaPg-1)*$mediaPerPage, $mediaPerPage);
?>
<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;margin-bottom:16px">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<form method="get" style="display:flex;gap:8px;align-items:center;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="hidden" name="action" value="media">
<input type="text" name="media_q" value="<?= esc($mediaQ) ?>" placeholder="Search images..." style="min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<button type="submit" style="margin:0;padding:8px 14px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:13px">Search</button>
<a href="index.php?action=media" style="color:#053d20;font-size:13px">Reset</a>
</form>
<span style="font-size:12px;color:#6b7280"><b><?= $totalMedia ?></b> images<?php if($totalMedia>0): ?> · page <?= $mediaPg ?>/<?= $mediaPages ?><?php endif; ?></span>
</div>
<div style="padding:12px">
<div class="media-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;">
<?php foreach($mediaPaged as $f): $n=basename($f); ?>
<div style="background:#f9fafb;border:1px solid #e5e7eb;padding:6px;border-radius:8px;text-align:center;font-size:11px;">
<img src="../assets/img/rushed/<?= $n ?>" style="width:100%;height:90px;object-fit:contain;">
<div style="margin:4px 0;"><?= $n ?></div>
<form method="post" style="display:flex;gap:4px;">
<input type="hidden" name="old_name" value="<?= $n ?>">
<input type="text" name="new_name" placeholder="rename" style="width:70px;padding:4px;font-size:11px;">
<button type="submit" style="padding:4px 8px;font-size:11px;margin:0;">Rename</button>
</form>
<form method="post" onsubmit="return confirm('Delete <?= $n ?>?')">
<input type="hidden" name="del_image" value="<?= $n ?>">
<button type="submit" style="padding:4px 8px;font-size:11px;margin:4px 0 0;background:#b71c1c;">Delete</button>
</form>
</div>
<?php endforeach; ?>
</div>
<?php if($mediaPages>1): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:16px">
<?php if($mediaPg>1): ?><a href="index.php?action=media&media_q=<?= urlencode($mediaQ) ?>&mpg=<?= $mediaPg-1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Prev</a><?php endif; ?>
<?php for($p=1;$p<=$mediaPages;$p++): ?><a href="index.php?action=media&media_q=<?= urlencode($mediaQ) ?>&mpg=<?= $p ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$mediaPg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a><?php endfor; ?>
<?php if($mediaPg<$mediaPages): ?><a href="index.php?action=media&media_q=<?= urlencode($mediaQ) ?>&mpg=<?= $mediaPg+1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>

<?php elseif($action==='messages'): ?>
<?php
$msgQ = trim($_GET['mq'] ?? '');
$msgFilter = trim($_GET['mf'] ?? ''); // unread / read
$msgsFiltered = $msgs;
if($msgQ!=='') $msgsFiltered = array_filter($msgsFiltered, fn($m)=> stripos($m['name']??'', $msgQ)!==false || stripos($m['email']??'', $msgQ)!==false || stripos($m['msg']??'', $msgQ)!==false);
if($msgFilter==='unread') $msgsFiltered = array_filter($msgsFiltered, fn($m)=> ($m['read']??false)!==true);
if($msgFilter==='read') $msgsFiltered = array_filter($msgsFiltered, fn($m)=> ($m['read']??false)===true);
$msgsFiltered = array_values($msgsFiltered);
// paginate: 50 per page
$msgPg = max(1, intval($_GET['mpg']??1));
$msgPerPage = 50;
$msgTotal = count($msgsFiltered);
$msgPages = max(1, (int)ceil($msgTotal/$msgPerPage));
if($msgPg>$msgPages) $msgPg=$msgPages;
$msgsPaged = array_slice(array_reverse($msgsFiltered), ($msgPg-1)*$msgPerPage, $msgPerPage);
// need to map paged index back to original index for actions
?>
<h1>Messages (<?= count($msgs) ?>)</h1>
<?php if(empty($msgs)): ?><p style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;color:#9ca3af">No messages yet.</p>
<?php else: ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="hidden" name="action" value="messages">
<input type="text" name="mq" value="<?= esc($msgQ) ?>" placeholder="Search name / email / message..." style="min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<select name="mf" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px"><option value="">All</option><option <?= $msgFilter==='unread'?'selected':'' ?> value="unread">Unread ●</option><option <?= $msgFilter==='read'?'selected':'' ?> value="read">Read</option></select>
<button type="submit" style="margin:0;padding:8px 14px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:13px">Filter</button>
<a href="index.php?action=messages" style="color:#053d20;font-size:13px">Reset</a>
</form>
<span style="font-size:12px;color:#6b7280"><b><?= $msgTotal ?></b> messages<?php if($msgTotal>0): ?> · page <?= $msgPg ?>/<?= $msgPages ?><?php endif; ?></span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllMsgs(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllMsgs(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteMsgs()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelMsgsBtn">Delete (<span id="msgSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="msgsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1"><tr><th style="padding:8px 12px;width:32px"><input type="checkbox" id="msgSelectAll" onchange="selectAllMsgs(this.checked)"></th><th style="padding:8px 12px;text-align:left">From</th><th style="padding:8px 12px;text-align:left">Message</th><th style="padding:8px 12px;text-align:left">Date</th><th style="padding:8px 12px;text-align:center">Status</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($msgsPaged as $m): 
  // find original index
  $oi=-1; foreach($msgs as $k=>$orig) if(($orig['at']??'')===($m['at']??'') && ($orig['email']??'')===($m['email']??'')) { $oi=$k; break; }
  if($oi<0) continue;
?>
<tr style="border-bottom:1px solid #f3f4f6;<?= ($m['read']??false)!==true?'background:#fffbeb':'' ?>">
<td style="padding:8px 12px"><input type="checkbox" class="msgCheck" value="<?= $oi ?>" onchange="updateMsgBulk()"></td>
<td style="padding:8px 12px"><b><?= esc($m['name']??'-') ?></b><div style="font-size:11px;color:#6b7280"><?= esc($m['email']??'') ?></div></td>
<td style="padding:8px 12px;max-width:320px"><div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= esc(substr($m['msg']??'',0,120)) ?></div></td>
<td style="padding:8px 12px;font-size:12px;color:#6b7280"><?= esc($m['at']??'-') ?></td>
<td style="padding:8px 12px;text-align:center"><?= ($m['read']??false)===true?'<span style="background:#ecfdf5;color:#065f46;padding:2px 8px;border-radius:999px;font-size:11px">Read</span>':'<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:11px">● NEW</span>' ?></td>
<td style="padding:8px 12px;text-align:right;white-space:nowrap"><a href="index.php?action=messages&mark_read=<?= $oi ?>" style="padding:4px 8px;background:#f3f4f6;border-radius:6px;text-decoration:none;font-size:11px;color:#053d20">Mark read</a> <a href="index.php?action=messages&del_msg=<?= $oi ?>" onclick="return confirm('Delete?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($msgsPaged)): ?><tr><td colspan="6" style="padding:24px;text-align:center;color:#9ca3af">No messages match filters</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php if($msgPages>1): ?>
<div style="padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#f9fafb">
<?php if($msgPg>1): ?><a href="index.php?action=messages&mq=<?= urlencode($msgQ) ?>&mf=<?= esc($msgFilter) ?>&mpg=<?= $msgPg-1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">‹ Prev</a><?php endif; ?>
<?php for($p=1;$p<=$msgPages;$p++): ?><a href="index.php?action=messages&mq=<?= urlencode($msgQ) ?>&mf=<?= esc($msgFilter) ?>&mpg=<?= $p ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$msgPg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a><?php endfor; ?>
<?php if($msgPg<$msgPages): ?><a href="index.php?action=messages&mq=<?= urlencode($msgQ) ?>&mf=<?= esc($msgFilter) ?>&mpg=<?= $msgPg+1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next ›</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<script>
function selectAllMsgs(c){ document.querySelectorAll('.msgCheck').forEach(function(cb){ cb.checked=c; }); var m=document.getElementById('msgSelectAll'); if(m) m.checked=c; updateMsgBulk(); }
function updateMsgBulk(){ var n=document.querySelectorAll('.msgCheck:checked').length; document.getElementById('msgSelCount').textContent=n; document.getElementById('bulkDelMsgsBtn').style.display=n>0?'':'none'; }
function bulkDeleteMsgs(){ var ids=Array.from(document.querySelectorAll('.msgCheck:checked')).map(function(cb){return cb.value;}); if(!ids.length) return; if(!confirm('Delete '+ids.length+' messages?')) return; var f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="bulk_del_messages" value="'+ids.join(',')+'">'; document.body.appendChild(f); f.submit(); }
</script>
<?php endif; ?>

<?php elseif($action==='edit_quote'): ?>
<?php $qid=(int)($_GET['id']??-1); $quotes_data_all=load_json(SITE_DIR.'/admin/data/quotes.json'); $q=$quotes_data_all[$qid]??null; if(!$q){ echo '<p>Quote not found</p>'; } else { ?>
<h1>Edit Quote <?= esc($q['id']) ?> <small style="font-weight:400;color:#888">— <?= esc($q['status']??'new') ?> · <?= esc($q['at']) ?></small></h1>
<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
<div>
<form method="post">
<input type="hidden" name="qid" value="<?= $qid ?>">
<h3 style="color:#0e4a2a">Customer</h3>
<label>Name</label><input type="text" name="q_name" value="<?= esc($q['name']) ?>">
<label>Company</label><input type="text" name="q_company" value="<?= esc($q['company']??'') ?>">
<label>Email</label><input type="text" name="q_email" value="<?= esc($q['email']) ?>">
<label>Phone</label><input type="text" name="q_phone" value="<?= esc($q['phone']) ?>">
<label>Note</label><textarea name="q_note"><?= esc($q['note']??'') ?></textarea>
<label>Status</label><select name="q_status"><option value="new" <?= ($q['status']??'')==='new'?'selected':'' ?>>New</option><option value="viewed" <?= ($q['status']??'')==='viewed'?'selected':'' ?>>Viewed</option><option value="confirmed" <?= ($q['status']??'')==='confirmed'?'selected':'' ?>>Confirmed</option><option value="cancelled" <?= ($q['status']??'')==='cancelled'?'selected':'' ?>>Cancelled</option></select>
<h3 style="color:#0e4a2a;margin-top:18px">Items</h3>
<table><tr><th>Product</th><th>Qty</th><th>Price (JOD)</th><th></th></tr>
<?php foreach(($q['items']??[]) as $it): ?>
<tr><td><?= esc($it['name']??$it['slug']) ?><br><small style="color:#888"><?= esc($it['slug']) ?></small></td>
<td><input type="number" name="qty[<?= esc($it['slug']) ?>]" value="<?= esc($it['qty']) ?>" min="1" style="width:70px"></td>
<td><?php $pp=''; foreach(normalize_products_full(load_products()) as $ppd) if(product_slug($ppd)===$it['slug']) {$pp=$ppd['price']??''; break;} ?><input type="text" name="price[<?= esc($it['slug']) ?>]" value="<?= esc($it['price']??$pp) ?>" placeholder="<?= esc($pp?:'1.25 JOD') ?>" style="width:110px"></td>
<td><button name="remove_slug" value="<?= esc($it['slug']) ?>" onclick="return confirm('Remove?')" style="background:#b71c1c;padding:6px 10px;font-size:12px">Remove</button></td></tr>
<?php endforeach; ?>
</table>
<div style="display:flex;gap:8px;margin-top:10px;align-items:end">
<input type="text" name="add_slug" placeholder="slug e.g. coca-cola-original-1" style="flex:1">
<input type="number" name="add_qty" value="1" min="1" style="width:80px">
<input type="text" name="add_name" placeholder="name (optional)" style="flex:1">
<button name="save_quote" value="1">Add / Save</button>
</div>
<p style="font-size:11px;color:#888;margin-top:6px">Tip: Find slug from product URL /product.php?slug=...</p>
<button name="save_quote" style="margin-top:16px;width:100%">💾 Save Changes</button>
</form>
</div>
<div>
<div class="msgrow"><h3 style="margin:0 0 8px;color:#0e4a2a">Log</h3>
<?php if(empty($q['logs'])): ?><p style="color:#888;font-size:13px">No logs yet</p><?php endif; ?>
<?php foreach(array_reverse($q['logs']??[]) as $lg): ?>
<div style="border-bottom:1px solid #eee;padding:8px 0;font-size:12px"><b><?= esc($lg['user']) ?></b> — <?= esc($lg['action']) ?><br><small style="color:#888"><?= esc($lg['at']) ?></small></div>
<?php endforeach; ?>
<?php if(!empty($q['handler'])): ?><p style="font-size:12px;margin-top:8px">Handler: <b><?= esc($q['handler']) ?></b></p><?php endif; ?>
<a href="/quote_print.php?id=<?= esc($q['id']) ?>&t=<?= esc($q['token'] ?? '') ?>" target="_blank" style="display:block;text-align:center;background:#0e4a2a;color:#fff;padding:10px;border-radius:8px;text-decoration:none;margin-top:12px">👁️ View PDF</a>
<a href="index.php?action=quotes" style="display:block;text-align:center;margin-top:8px;color:#0e4a2a">← Back to Quotes</a>
</div>
</div>
</div>
<?php } ?>
<?php elseif($action==='quotes'): ?>
<?php
$qQ = trim($_GET['qq'] ?? '');
$qStatus = trim($_GET['qs'] ?? '');
$quotesFiltered = $quotes_data;
if($qQ!=='') $quotesFiltered = array_filter($quotesFiltered, fn($qq)=> stripos($qq['name']??'', $qQ)!==false || stripos($qq['email']??'', $qQ)!==false || stripos($qq['company']??'', $qQ)!==false || stripos($qq['id']??'', $qQ)!==false);
if($qStatus!=='') $quotesFiltered = array_filter($quotesFiltered, fn($qq)=> ($qq['status']??'new')===$qStatus);
$quotesFiltered = array_values($quotesFiltered);
$qPg = max(1, intval($_GET['qpg']??1));
$qPerPage = 20;
$qTotal = count($quotesFiltered);
$qPages = max(1, (int)ceil($qTotal/$qPerPage));
if($qPg>$qPages) $qPg=$qPages;
$qPaged = array_slice(array_reverse($quotesFiltered), ($qPg-1)*$qPerPage, $qPerPage);
?>
<h1>Quotes (<?= count($quotes_data) ?>)</h1>
<?php if(empty($quotes_data)): ?><p style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;color:#9ca3af">No quotes yet. Quotes appear when customers submit the quote form.</p>
<?php else: ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="hidden" name="action" value="quotes">
<input type="text" name="qq" value="<?= esc($qQ) ?>" placeholder="Search id / name / email..." style="min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<select name="qs" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px"><option value="">All statuses</option><option <?= $qStatus==='new'?'selected':'' ?> value="new">New</option><option <?= $qStatus==='viewed'?'selected':'' ?> value="viewed">Viewed</option><option <?= $qStatus==='confirmed'?'selected':'' ?> value="confirmed">Confirmed</option><option <?= $qStatus==='cancelled'?'selected':'' ?> value="cancelled">Cancelled</option></select>
<button type="submit" style="margin:0;padding:8px 14px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:13px">Filter</button>
<a href="index.php?action=quotes" style="color:#053d20;font-size:13px">Reset</a>
</form>
<span style="font-size:12px;color:#6b7280"><b><?= $qTotal ?></b> quotes<?php if($qTotal>0): ?> · page <?= $qPg ?>/<?= $qPages ?><?php endif; ?></span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllQuotes(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllQuotes(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteQuotes()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelQuotesBtn">Delete (<span id="quoteSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="quotesTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1"><tr><th style="padding:8px 12px;width:32px"><input type="checkbox" id="quoteSelectAll" onchange="selectAllQuotes(this.checked)"></th><th style="padding:8px 12px;text-align:left">Quote</th><th style="padding:8px 12px;text-align:left">Customer</th><th style="padding:8px 12px;text-align:left">Contact</th><th style="padding:8px 12px;text-align:center">Status</th><th style="padding:8px 12px;text-align:right">Total</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($qPaged as $q): 
  $oi=-1; foreach($quotes_data as $k=>$orig) if(($orig['id']??'')===($q['id']??'')) { $oi=$k; break; }
  if($oi<0) continue;
  $statusColors=['new'=>'#fef3c7;color:#92400e','viewed'=>'#e0f2fe;color:#075985','confirmed'=>'#ecfdf5;color:#065f46','cancelled'=>'#fee2e2;color:#991b1b'];
  $sc=$statusColors[$q['status']??'new']??'#f3f4f6;color:#6b7280';
?>
<tr style="border-bottom:1px solid #f3f4f6">
<td style="padding:8px 12px"><input type="checkbox" class="quoteCheck" value="<?= $oi ?>" onchange="updateQuoteBulk()"></td>
<td style="padding:8px 12px"><b style="color:#053d20"><?= esc($q['id']??'-') ?></b><div style="font-size:11px;color:#9ca3af"><?= esc($q['at']??'') ?> · <?= count($q['items']??[]) ?> items</div></td>
<td style="padding:8px 12px"><b><?= esc($q['name']??'-') ?></b><?php if(!empty($q['company'])): ?><div style="font-size:11px;color:#6b7280"><?= esc($q['company']) ?></div><?php endif; ?></td>
<td style="padding:8px 12px;font-size:12px"><a href="mailto:<?= esc($q['email']??'') ?>"><?= esc($q['email']??'-') ?></a><div style="color:#6b7280"><?= esc($q['phone']??'') ?></div></td>
<td style="padding:8px 12px;text-align:center"><span style="background:<?= explode(';',$sc)[0] ?>;<?= explode(';',$sc)[1] ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600"><?= esc($q['status']??'new') ?></span></td>
<td style="padding:8px 12px;text-align:right;font-weight:700"><?= !empty($q['total'])?number_format(floatval($q['total']),2).' JOD':'—' ?></td>
<td style="padding:8px 12px;text-align:right;white-space:nowrap"><a href="index.php?action=edit_quote&id=<?= $oi ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="/quote_print.php?id=<?= esc($q['id']) ?>&t=<?= esc($q['token'] ?? '') ?>" target="_blank" style="padding:4px 8px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;font-size:11px;color:#053d20">PDF</a> <a href="index.php?action=quotes&del_quote=<?= $oi ?>" onclick="return confirm('Delete <?= esc(addslashes($q['id']??'')) ?>?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Del</a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($qPaged)): ?><tr><td colspan="7" style="padding:24px;text-align:center;color:#9ca3af">No quotes match filters</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php if($qPages>1): ?>
<div style="padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#f9fafb">
<?php if($qPg>1): ?><a href="index.php?action=quotes&qq=<?= urlencode($qQ) ?>&qs=<?= esc($qStatus) ?>&qpg=<?= $qPg-1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">‹ Prev</a><?php endif; ?>
<?php for($p=1;$p<=$qPages;$p++): ?><a href="index.php?action=quotes&qq=<?= urlencode($qQ) ?>&qs=<?= esc($qStatus) ?>&qpg=<?= $p ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$qPg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a><?php endfor; ?>
<?php if($qPg<$qPages): ?><a href="index.php?action=quotes&qq=<?= urlencode($qQ) ?>&qs=<?= esc($qStatus) ?>&qpg=<?= $qPg+1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next ›</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<script>
function selectAllQuotes(c){ document.querySelectorAll('.quoteCheck').forEach(function(cb){ cb.checked=c; }); var m=document.getElementById('quoteSelectAll'); if(m) m.checked=c; updateQuoteBulk(); }
function updateQuoteBulk(){ var n=document.querySelectorAll('.quoteCheck:checked').length; document.getElementById('quoteSelCount').textContent=n; document.getElementById('bulkDelQuotesBtn').style.display=n>0?'':'none'; }
function bulkDeleteQuotes(){ var ids=Array.from(document.querySelectorAll('.quoteCheck:checked')).map(function(cb){return cb.value;}); if(!ids.length) return; if(!confirm('Delete '+ids.length+' quotes?')) return; var f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="bulk_del_quotes" value="'+ids.join(',')+'">'; document.body.appendChild(f); f.submit(); }
</script>
<?php endif; ?>

<?php elseif($action==='backup'): ?>
<h1>Backup & Restore</h1>
<p><a href="backup.php?download=1" style="background:#053d20;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;">↓ Download full backup (JSON)</a></p>
<p><a href="csv_export.php" style="background:#0a5230;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;margin-left:8px;">↓ Export products CSV</a></p>
<form method="post" enctype="multipart/form-data" style="margin-top:20px;">
<label>Import products from CSV</label><input type="file" name="csv" accept=".csv" required>
<button name="import_csv">Import</button></form>
<p style="color:#8a8270;font-size:12px;">CSV columns: name, brand, cat, origin, desc, price, stock, featured, image, seo_title, seo_desc</p>
<form method="post" enctype="multipart/form-data" style="margin-top:20px;">
<label>Restore from backup file</label><input type="file" name="backup" accept=".json" required>
<button name="restore_backup">Restore</button></form>
<p style="color:#8a8270;font-size:13px;margin-top:10px;">Restoring replaces products, brands and categories with the backup's content.</p>
<?php
$snap_dir = SITE_DIR . '/admin/data/snapshots';
if (!is_dir($snap_dir)) mkdir($snap_dir, 0755, true);
if (isset($_GET['snapshot'])) {
  $sn = date('Ymd_Hi') . '_' . bin2hex(random_bytes(3));
  $sd = $snap_dir . '/' . $sn; mkdir($sd, 0755, true);
  foreach (['products.json','brands.json','categories.json'] as $jf) { $sf = SITE_DIR . '/admin/data/' . $jf; if (is_file($sf)) copy($sf, $sd . '/' . $jf); }
  $all_snaps = glob($snap_dir . '/*'); sort($all_snaps);
  while (count($all_snaps) > 10) { $old = array_shift($all_snaps); foreach (glob($old . '/*') as $f) unlink($f); rmdir($old); }
  $msg = 'Snapshot ' . $sn . ' saved (last 10 kept).';
}
if (isset($_GET['restore_snap'])) {
  $rs = basename($_GET['restore_snap']); $rd = $snap_dir . '/' . $rs;
  if (is_dir($rd) && is_file($rd . '/products.json')) {
    $rp = json_decode(file_get_contents($rd . '/products.json'), true);
    if (is_array($rp)) save_products($rp);
    if (is_file($rd . '/brands.json')) { $rb = json_decode(file_get_contents($rd . '/brands.json'), true); if (is_array($rb)) save_brands($rb); }
    if (is_file($rd . '/categories.json')) { $rc = json_decode(file_get_contents($rd . '/categories.json'), true); if (is_array($rc)) save_cats($rc); }
    auto_publish();
    $msg = 'Restored snapshot ' . $rs . '.';
  } else { $msg = 'Snapshot not found.'; }
}
$snap_list = glob($snap_dir . '/*'); rsort($snap_list);
?>
<h3 style="color:#053d20;margin-top:28px;">Server Snapshots (auto-kept: last 10)</h3>
<?php if($msg) echo "<div class='msg'>".esc($msg)."</div>"; ?>
<p><a href="index.php?action=backup&snapshot=1" class="btn-dash" onclick="return confirm('Save a server snapshot now?');">📸 Save snapshot now</a></p>
<?php if (!$snap_list): ?><p style="color:#888">No snapshots yet.</p><?php else: ?>
<table class="recent-table"><tr><th>Snapshot</th><th style="text-align:right;min-width:140px">Actions</th></tr>
<?php foreach ($snap_list as $sp): $snm = basename($sp); ?>
<tr><td><?= strpos($snm,'auto_')===0 ? '🤖 ' : '📸 ' ?><b><?= esc($snm) ?></b> <span style="color:#888;font-size:12px">(<?= is_file($sp.'/products.json') ? esc(round(filesize($sp.'/products.json')/1024).' KB products') : 'empty' ?>)</span></td>
<td style="text-align:right"><a href="index.php?action=backup&restore_snap=<?= esc($snm) ?>" onclick="return confirm('Restore <?= esc($snm) ?>? Current data will be replaced.');" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Restore</a></td></tr>
<?php endforeach; ?>
</table><?php endif; ?>

<?php elseif($action==='visitors'): ?>
<h1>Registered Clients</h1>
<p style="color:#7a7267">Every client that signs up via <code>/register.php</code> appears here instantly.</p>
<?php $visitors = load_visitors(); 
$vQ = trim($_GET['vq'] ?? '');
$vType = trim($_GET['vt'] ?? '');
$vFiltered = $visitors;
if($vQ!=='') $vFiltered = array_filter($vFiltered, fn($vv)=> stripos($vv['name']??'', $vQ)!==false || stripos($vv['email']??'', $vQ)!==false || stripos($vv['company']??'', $vQ)!==false || stripos($vv['phone']??'', $vQ)!==false);
if($vType!=='') $vFiltered = array_filter($vFiltered, fn($vv)=> ($vv['customer_type']??'')===$vType);
$vFiltered = array_values($vFiltered);
$vPg = max(1, intval($_GET['vpg']??1));
$vPerPage = 50;
$vTotal = count($vFiltered);
$vPages = max(1, (int)ceil($vTotal/$vPerPage));
if($vPg>$vPages) $vPg=$vPages;
$vPaged = array_slice($vFiltered, ($vPg-1)*$vPerPage, $vPerPage);
?>
<?php if(empty($visitors)): ?>
<p style="padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;color:#9ca3af">No clients yet — when someone registers at <a href="/register.php">/register.php</a> they will show here.</p>
<?php else: ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="hidden" name="action" value="visitors">
<input type="text" name="vq" value="<?= esc($vQ) ?>" placeholder="Search name / email / company..." style="min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<select name="vt" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px"><option value="">All types</option><option <?= $vType==='individual'?'selected':'' ?> value="individual">Individual</option><option <?= $vType==='business'?'selected':'' ?> value="business">Business</option></select>
<button type="submit" style="margin:0;padding:8px 14px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:13px">Filter</button>
<a href="index.php?action=visitors" style="color:#053d20;font-size:13px">Reset</a>
</form>
<span style="font-size:12px;color:#6b7280"><b><?= $vTotal ?></b> clients<?php if($vTotal>0): ?> · page <?= $vPg ?>/<?= $vPages ?><?php endif; ?></span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllVisitors(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllVisitors(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteVisitors()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelVisitorsBtn">Delete (<span id="visitorSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="visitorsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1"><tr><th style="padding:8px 12px;width:32px"><input type="checkbox" id="visitorSelectAll" onchange="selectAllVisitors(this.checked)"></th><th style="padding:8px 12px;text-align:left">#</th><th style="padding:8px 12px;text-align:left">Type</th><th style="padding:8px 12px;text-align:left">Name</th><th style="padding:8px 12px;text-align:left">Company</th><th style="padding:8px 12px;text-align:left">Email</th><th style="padding:8px 12px;text-align:left">Phone</th><th style="padding:8px 12px;text-align:left">Registered</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($vPaged as $v): 
  $oi=-1; foreach($visitors as $k=>$orig) if(($orig['email']??'')===($v['email']??'') && ($orig['name']??'')===($v['name']??'')) { $oi=$k; break; }
  if($oi<0) continue;
?>
<tr style="border-bottom:1px solid #f3f4f6">
  <td style="padding:8px 12px"><input type="checkbox" class="visitorCheck" value="<?= $oi ?>" onchange="updateVisitorBulk()"></td>
  <td style="padding:8px 12px;color:#9ca3af"><?= $oi+1 ?></td>
  <td style="padding:8px 12px"><span style="background:<?= ($v['customer_type']??'')==='business'?'#053d20':'#6b7280' ?>;color:#fff;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap"><?= esc(ucfirst($v['customer_type']??($v['company']?'business':'individual'))) ?></span></td>
  <td style="padding:8px 12px;font-weight:600"><?= esc($v['name']??'-') ?></td>
  <td style="padding:8px 12px"><?= esc($v['company']??'—') ?></td>
  <td style="padding:8px 12px"><a href="mailto:<?= esc($v['email']??'') ?>" style="color:#053d20"><?= esc($v['email']??'-') ?></a></td>
  <td style="padding:8px 12px"><a href="tel:<?= esc($v['phone']??'') ?>"><?= esc($v['phone']??'-') ?></a></td>
  <td style="padding:8px 12px;white-space:nowrap;font-size:12px;color:#6b7280"><?= esc($v['created_at']??$v['created']??'-') ?></td>
  <td style="padding:8px 12px;text-align:right"><a href="index.php?action=visitors&del_visitor=<?= $oi ?>" onclick="return confirm('Delete this client?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($vPaged)): ?><tr><td colspan="9" style="padding:24px;text-align:center;color:#9ca3af">No clients match filters</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php if($vPages>1): ?>
<div style="padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#f9fafb">
<?php if($vPg>1): ?><a href="index.php?action=visitors&vq=<?= urlencode($vQ) ?>&vt=<?= esc($vType) ?>&vpg=<?= $vPg-1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">‹ Prev</a><?php endif; ?>
<?php for($p=1;$p<=$vPages;$p++): ?><a href="index.php?action=visitors&vq=<?= urlencode($vQ) ?>&vt=<?= esc($vType) ?>&vpg=<?= $p ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$vPg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a><?php endfor; ?>
<?php if($vPg<$vPages): ?><a href="index.php?action=visitors&vq=<?= urlencode($vQ) ?>&vt=<?= esc($vType) ?>&vpg=<?= $vPg+1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next ›</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<p style="margin-top:12px;color:#7a7267;font-size:.85em">Total: <?= $vTotal ?> / <?= count($visitors) ?> clients · <a href="index.php?action=csv_visitors" style="color:#053d20">Export CSV</a></p>
<script>
function selectAllVisitors(c){ document.querySelectorAll('.visitorCheck').forEach(function(cb){ cb.checked=c; }); var m=document.getElementById('visitorSelectAll'); if(m) m.checked=c; updateVisitorBulk(); }
function updateVisitorBulk(){ var n=document.querySelectorAll('.visitorCheck:checked').length; document.getElementById('visitorSelCount').textContent=n; document.getElementById('bulkDelVisitorsBtn').style.display=n>0?'':'none'; }
function bulkDeleteVisitors(){ var ids=Array.from(document.querySelectorAll('.visitorCheck:checked')).map(function(cb){return cb.value;}); if(!ids.length) return; if(!confirm('Delete '+ids.length+' clients?')) return; var f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="bulk_del_visitors" value="'+ids.join(',')+'">'; document.body.appendChild(f); f.submit(); }
</script>
<?php endif; ?>

<?php elseif($action==='employees'): ?>
<?php $emps = load_json(SITE_DIR.'/admin/data/employees.json'); $edit_emp = null; if(isset($_GET['edit_emp'])){ $ei=intval($_GET['edit_emp']); if(isset($emps[$ei])) $edit_emp=$emps[$ei]; } ?>
<h1>Employees</h1>
<p style="color:#8a8270;font-size:13px;">Add your staff here. Choose their job role — driver / manager / accountant / warehouse / preparer / sales / etc. You add them manually, staff will later login with their username.</p>
<?php if($edit_emp): ?>
<form method="post" style="background:#fff;border:1px solid #e5e1d8;border-radius:12px;padding:18px;max-width:520px">
<input type="hidden" name="emp_id" value="<?= intval($_GET['edit_emp']) ?>">
<label>Full Name *</label><input type="text" name="emp_name" value="<?= esc($edit_emp['name']) ?>" required>
<label>Phone</label><input type="text" name="emp_phone" value="<?= esc($edit_emp['phone']??'') ?>" placeholder="079...">
<label>Email</label><input type="email" name="emp_email" value="<?= esc($edit_emp['email']??'') ?>" placeholder="emp@example.com">
<label>Job Role *</label><select name="emp_job" required>
  <option value="driver" <?= ($edit_emp['job']??'')==='driver'?'selected':'' ?>>🚚 سائق - Driver</option>
  <option value="manager" <?= ($edit_emp['job']??'')==='manager'?'selected':'' ?>>👔 مدير - Manager</option>
  <option value="accountant" <?= ($edit_emp['job']??'')==='accountant'?'selected':'' ?>>🧮 محاسب - Accountant</option>
  <option value="warehouse" <?= ($edit_emp['job']??'')==='warehouse'?'selected':'' ?>>📦 أمين مستودع - Warehouse</option>
  <option value="preparer" <?= ($edit_emp['job']??'')==='preparer'?'selected':'' ?>>📋 مجهز طلبيات - Preparer</option>
  <option value="sales" <?= ($edit_emp['job']??'')==='sales'?'selected':'' ?>>🤝 مندوب مبيعات - Sales</option>
  <option value="delivery" <?= ($edit_emp['job']??'')==='delivery'?'selected':'' ?>>🛵 توصيل - Delivery</option>
  <option value="cashier" <?= ($edit_emp['job']??'')==='cashier'?'selected':'' ?>>💰 كاشير - Cashier</option>
  <option value="other" <?= ($edit_emp['job']??'')==='other'?'selected':'' ?>>🏢 أخرى - Other</option>
</select>
<label>Username (for staff login)</label><input type="text" name="emp_user" value="<?= esc($edit_emp['username']??'') ?>" placeholder="e.g. ahmad.driver">
<label>New Password (leave blank to keep)</label><input type="text" name="emp_pass" placeholder="••••••••">
<button name="save_employee" style="margin-top:10px">Update Employee</button> <a href="index.php?action=employees" style="margin-left:8px;color:#888">Cancel</a>
</form>
<?php else: ?>
<form method="post" style="background:#fff;border:1px solid #e5e1d8;border-radius:12px;padding:18px;max-width:520px">
<label>Full Name *</label><input type="text" name="emp_name" required placeholder="Ahmad Qasim">
<label>Phone</label><input type="text" name="emp_phone" placeholder="079...">
<label>Email</label><input type="email" name="emp_email" placeholder="emp@example.com">
<label>Job Role *</label><select name="emp_job" required>
  <option value="preparer">📋 مجهز طلبيات - Preparer</option>
  <option value="driver">🚚 سائق - Driver</option>
  <option value="manager">👔 مدير - Manager</option>
  <option value="accountant">🧮 محاسب - Accountant</option>
  <option value="warehouse">📦 أمين مستودع - Warehouse</option>
  <option value="sales">🤝 مندوب مبيعات - Sales</option>
  <option value="delivery">🛵 توصيل - Delivery</option>
  <option value="cashier">💰 كاشير - Cashier</option>
  <option value="other">🏢 أخرى - Other</option>
</select>
<label>Username (for staff login)</label><input type="text" name="emp_user" placeholder="e.g. ahmad.driver">
<label>Password</label><input type="text" name="emp_pass" placeholder="••••••••">
<button name="save_employee" style="margin-top:10px">Add Employee</button>
</form>
<?php endif; ?>
<?php if(!empty($emps)): 
$empQ = trim($_GET['eq'] ?? '');
$empRole = trim($_GET['er'] ?? '');
$empsFiltered = $emps;
if($empQ!=='') $empsFiltered = array_filter($empsFiltered, fn($ee)=> stripos($ee['name']??'', $empQ)!==false || stripos($ee['username']??'', $empQ)!==false || stripos($ee['phone']??'', $empQ)!==false);
if($empRole!=='') $empsFiltered = array_filter($empsFiltered, fn($ee)=> ($ee['job']??'')===$empRole);
$empsFiltered = array_values($empsFiltered);
$empPg = max(1, intval($_GET['epg']??1));
$empPerPage = 50;
$empTotal = count($empsFiltered);
$empPages = max(1, (int)ceil($empTotal/$empPerPage));
if($empPg>$empPages) $empPg=$empPages;
$empsPaged = array_slice($empsFiltered, ($empPg-1)*$empPerPage, $empPerPage);
?>
<div style="margin-top:20px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f9fafb">
<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent;box-shadow:none;padding:0;margin:0">
<input type="hidden" name="action" value="employees">
<input type="text" name="eq" value="<?= esc($empQ) ?>" placeholder="Search name / username / phone..." style="min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<select name="er" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px"><option value="">All roles</option><option <?= $empRole==='driver'?'selected':'' ?> value="driver">Driver</option><option <?= $empRole==='manager'?'selected':'' ?> value="manager">Manager</option><option <?= $empRole==='accountant'?'selected':'' ?> value="accountant">Accountant</option><option <?= $empRole==='warehouse'?'selected':'' ?> value="warehouse">Warehouse</option><option <?= $empRole==='preparer'?'selected':'' ?> value="preparer">Preparer</option><option <?= $empRole==='sales'?'selected':'' ?> value="sales">Sales</option><option <?= $empRole==='delivery'?'selected':'' ?> value="delivery">Delivery</option><option <?= $empRole==='cashier'?'selected':'' ?> value="cashier">Cashier</option><option <?= $empRole==='other'?'selected':'' ?> value="other">Other</option></select>
<button type="submit" style="margin:0;padding:8px 14px;background:#053d20;color:#fff;border:0;border-radius:8px;font-size:13px">Filter</button>
<a href="index.php?action=employees" style="color:#053d20;font-size:13px">Reset</a>
</form>
<span style="font-size:12px;color:#6b7280"><b><?= $empTotal ?></b> employees<?php if($empTotal>0): ?> / page <?= $empPg ?>/<?= $empPages ?><?php endif; ?></span>
<div style="margin-left:auto;display:flex;gap:6px">
<button type="button" onclick="selectAllEmps(true)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Select All</button>
<button type="button" onclick="selectAllEmps(false)" style="padding:6px 10px;border:1px solid #d1d5db;background:#fff;color:#111;border-radius:6px;font-size:12px;cursor:pointer">Clear</button>
<button type="button" onclick="bulkDeleteEmps()" style="padding:6px 12px;background:#dc2626;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer;display:none" id="bulkDelEmpsBtn">Delete (<span id="empSelCount">0</span>)</button>
</div>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="empsTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb;z-index:1"><tr><th style="padding:8px 12px;width:32px"><input type="checkbox" id="empSelectAll" onchange="selectAllEmps(this.checked)"></th><th style="padding:8px 12px;text-align:left">#</th><th style="padding:8px 12px;text-align:left">Name</th><th style="padding:8px 12px;text-align:left">Role</th><th style="padding:8px 12px;text-align:left">Phone</th><th style="padding:8px 12px;text-align:left">Username</th><th style="padding:8px 12px;text-align:left">Created</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($empsPaged as $e): 
  $oi=-1; foreach($emps as $k=>$orig) if(($orig['name']??'')===($e['name']??'') && ($orig['username']??'')===($e['username']??'')) { $oi=$k; break; }
  if($oi<0) continue;
?>
<tr style="border-bottom:1px solid #f3f4f6">
  <td style="padding:8px 12px"><input type="checkbox" class="empCheck" value="<?= $oi ?>" onchange="updateEmpBulk()"></td>
  <td style="padding:8px 12px;color:#9ca3af"><?= $oi+1 ?></td>
  <td style="padding:8px 12px;font-weight:600;color:#053d20"><?= esc($e['name']) ?></td>
  <td style="padding:8px 12px"><span style="background:#053d20;color:#fff;padding:4px 10px;border-radius:999px;font-size:11px;white-space:nowrap"><?php $labels=['driver'=>'Driver','manager'=>'Manager','accountant'=>'Accountant','warehouse'=>'Warehouse','preparer'=>'Preparer','sales'=>'Sales','delivery'=>'Delivery','cashier'=>'Cashier','other'=>'Other']; echo esc($labels[$e['job']??'other']??$e['job']); ?></span></td>
  <td style="padding:8px 12px"><?= esc($e['phone']??'-') ?></td>
  <td style="padding:8px 12px"><code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:11px"><?= esc($e['username']??'-') ?></code></td>
  <td style="padding:8px 12px;font-size:12px;color:#6b7280"><?= esc($e['created']??'-') ?></td>
  <td style="padding:8px 12px;text-align:right;white-space:nowrap"><a href="index.php?action=employees&edit_emp=<?= $oi ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=employees&del_employee=<?= $oi ?>" onclick="return confirm('Delete employee?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if($empPages>1): ?>
<div style="padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#f9fafb">
<?php if($empPg>1): ?><a href="index.php?action=employees&eq=<?= urlencode($empQ) ?>&er=<?= esc($empRole) ?>&epg=<?= $empPg-1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Prev</a><?php endif; ?>
<?php for($p=1;$p<=$empPages;$p++): ?><a href="index.php?action=employees&eq=<?= urlencode($empQ) ?>&er=<?= esc($empRole) ?>&epg=<?= $p ?>" style="padding:6px 10px;border-radius:6px;text-decoration:none;font-size:12px;<?= $p===$empPg?'background:#053d20;color:#fff':'border:1px solid #d1d5db;background:#fff;color:#053d20' ?>"><?= $p ?></a><?php endfor; ?>
<?php if($empPg<$empPages): ?><a href="index.php?action=employees&eq=<?= urlencode($empQ) ?>&er=<?= esc($empRole) ?>&epg=<?= $empPg+1 ?>" style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;text-decoration:none;color:#053d20;font-size:12px">Next</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<script>
function selectAllEmps(c){ document.querySelectorAll('.empCheck').forEach(function(cb){ cb.checked=c; }); var m=document.getElementById('empSelectAll'); if(m) m.checked=c; updateEmpBulk(); }
function updateEmpBulk(){ var n=document.querySelectorAll('.empCheck:checked').length; document.getElementById('empSelCount').textContent=n; document.getElementById('bulkDelEmpsBtn').style.display=n>0?'':'none'; }
function bulkDeleteEmps(){ var ids=Array.from(document.querySelectorAll('.empCheck:checked')).map(function(cb){return cb.value;}); if(!ids.length) return; if(!confirm('Delete '+ids.length+' employees?')) return; var f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="bulk_del_employees" value="'+ids.join(',')+'">'; document.body.appendChild(f); f.submit(); }
</script>
<?php else: ?>
<p style="margin-top:12px;color:#9ca3af;font-size:13px">No employees match filters</p>
<?php endif; ?>

<?php elseif($action==='users'): ?>
<h1>Users</h1>
<p style="color:#8a8270;font-size:13px;">Manage panel login accounts. Roles: <b>admin</b> full access · <b>editor</b> content only · <b>viewer</b> read-only.</p>
<?php $att = function_exists('get_attempts') ? get_attempts() : ['count'=>0]; $is_locked = function_exists('is_locked_out') ? is_locked_out() : false; ?>
<div class="dash-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,220px))">
  <div class="dash-card" style="border-color:<?= $is_locked ? '#c0392b' : '#2e6b3e' ?>"><div class="num"><?= $is_locked ? '🔒' : intval($att['count'] ?? 0) ?></div><div class="lbl"><?= $is_locked ? 'Logins locked (brute-force)' : 'Failed login attempts' ?></div></div>
  <?php if ($is_locked && function_exists('lock_remaining')): ?><div class="dash-card"><div class="num" style="font-size:1.2em"><?= ceil(lock_remaining()/60) ?>m</div><div class="lbl">Lock remaining</div></div><?php endif; ?>
</div>
<?php
$tfa = function_exists('twofa_all') ? twofa_all() : [];
$tfa_names = [ADMIN_USER];
foreach (($users ?? []) as $uu) { $un = $uu['username'] ?? ''; if ($un !== '') $tfa_names[] = $un; }
$tfa_names = array_values(array_unique($tfa_names));
?>
<h3 style="color:#053d20;margin-top:24px;">Two-factor authentication (TOTP)</h3>
<p style="color:#8a8270;font-size:13px;">Links each account to an authenticator app (Google Authenticator, Authy, 1Password). After the password, login asks for a 6-digit code. 10 one-time backup codes are issued at setup for emergencies.</p>
<?php if (!empty($_SESSION['twofa_codes'])): $tc = $_SESSION['twofa_codes']; unset($_SESSION['twofa_codes']); ?>
<div class="msg">2FA enabled for <?= esc($tc['user']) ?>. Save these backup codes NOW — each works once, they will never be shown again:<br><b><?= esc(implode(' · ', $tc['codes'])) ?></b></div>
<?php endif; ?>
<?php if (!empty($twofa_setup)): ?>
<div style="background:#fff;border:1px solid #c9a23f;border-radius:12px;padding:16px 18px;margin-bottom:12px;">
<h3 style="margin:0 0 8px;color:#053d20;">Link <?= esc($twofa_setup['user']) ?></h3>
<p style="font-size:13px;color:#555;margin:0 0 6px;">1. In your authenticator app choose <b>Enter a setup key</b> (manual entry).<br>2. Account: <b>7Boys-<?= esc($twofa_setup['user']) ?></b> — Key: <b style="font-size:15px;letter-spacing:1px;"><?= esc($twofa_setup['secret']) ?></b><br>3. Enter the 6-digit code below to confirm:</p>
<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
<input type="hidden" name="twofa_user" value="<?= esc($twofa_setup['user']) ?>">
<input type="text" name="twofa_code" placeholder="6-digit code" required inputmode="numeric" maxlength="6" style="max-width:180px;">
<button name="twofa_confirm">Confirm &amp; enable</button>
</form>
</div>
<?php endif; ?>
<table class="recent-table"><tr><th>Account</th><th>Status</th><th style="text-align:right;">2FA</th></tr>
<?php foreach ($tfa_names as $tn): $on = isset($tfa[$tn]); ?>
<tr><td><b><?= esc($tn) ?></b><?= $tn === ADMIN_USER ? ' <span style="color:#888;font-size:11px;">(main admin)</span>' : '' ?></td>
<td><?= $on ? '<span style="background:#e8f3ec;color:#1f5a38;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700">ON</span>' : '<span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700">OFF</span>' ?></td>
<td style="text-align:right;"><?php if ($on): ?><a href="index.php?action=users&twofa_off=<?= esc(urlencode($tn)) ?>" onclick="return confirm('Disable 2FA for <?= esc($tn) ?>?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Disable</a><?php else: ?><a href="index.php?action=users&twofa_new=<?= esc(urlencode($tn)) ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Enable</a><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
<?php if($edit_user): ?>
<form method="post">
<input type="hidden" name="id" value="<?= (int)$_GET['edit_user'] ?>">
<label>Username</label><input type="text" name="username" value="<?= esc($edit_user['username']) ?>" required>
<label>Role</label><select name="role"><option value="admin"<?= ($edit_user['role'] ?? 'admin')==='admin'?' selected':'' ?>>admin — full access</option><option value="editor"<?= ($edit_user['role'] ?? '')==='editor'?' selected':'' ?>>editor — content only</option><option value="viewer"<?= ($edit_user['role'] ?? '')==='viewer'?' selected':'' ?>>viewer — read-only</option></select>
<label>New password (leave blank to keep)</label><input type="text" name="password">
<button name="save_user">Update</button></form>
<?php else: ?>
<form method="post">
<label>Username</label><input type="text" name="username" required>
<label>Password</label><input type="text" name="password" required>
<label>Role</label><select name="role"><option value="admin">admin — full access</option><option value="editor">editor — content only</option><option value="viewer">viewer — read-only</option></select>
<button name="save_user">Add User</button></form>
<?php endif; ?>
<div style="margin-top:20px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
<div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;align-items:center;background:#f9fafb">
<input type="text" id="userSearch" placeholder="Search users..." onkeyup="filterUsers()" style="flex:1;min-width:180px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
<span style="font-size:12px;color:#6b7280"><span id="userCount"><?= count($users) ?></span> users</span>
</div>
<div style="max-height:60vh;overflow:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px;margin:0" id="usersTable">
<thead style="position:sticky;top:0;background:#f9fafb;border-bottom:2px solid #e5e7eb"><tr><th style="padding:8px 12px;text-align:left">#</th><th style="padding:8px 12px;text-align:left">Username</th><th style="padding:8px 12px;text-align:left">Role</th><th style="padding:8px 12px;text-align:left">Last login</th><th style="padding:8px 12px;text-align:right;min-width:140px">Actions</th></tr></thead>
<tbody>
<?php foreach($users as $i=>$u): ?>
<tr data-name="<?= esc(strtolower($u['username'])) ?>" style="border-bottom:1px solid #f3f4f6">
<td style="padding:8px 12px;color:#9ca3af"><?= $i+1 ?></td>
<td style="padding:8px 12px"><b style="color:#053d20"><?= esc($u['username']) ?></b></td>
<td style="padding:8px 12px"><span style="background:#eef2f7;color:#334155;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700"><?= esc($u['role'] ?? 'admin') ?></span></td>
<td style="padding:8px 12px;color:#6b7280;font-size:12px"><?= esc($u['last_login'] ?? '—') ?></td>
<td style="padding:8px 12px;text-align:right"><a href="index.php?action=users&edit_user=<?= $i ?>" style="padding:4px 8px;background:#053d20;color:#fff;border-radius:6px;text-decoration:none;font-size:11px">Edit</a> <a href="index.php?action=users&del_user=<?= $i ?>" onclick="return confirm('Delete?')" style="padding:4px 8px;border:1px solid #fecaca;color:#dc2626;border-radius:6px;text-decoration:none;font-size:11px">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($users)): ?><tr><td colspan="3" style="padding:24px;text-align:center;color:#9ca3af">Default login: admin / Admin@2026</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</div>
<script>
function filterUsers(){
  var q=(document.getElementById('userSearch').value||'').toLowerCase();
  var rows=document.querySelectorAll('#usersTable tbody tr');
  var vis=0;
  rows.forEach(function(r){
    var n=r.getAttribute('data-name')||'';
    var show=!q||n.indexOf(q)>=0;
    r.style.display=show?'':'none';
    if(show) vis++;
  });
  document.getElementById('userCount').textContent=vis;
}
</script>

<?php elseif($action==='settings'): ?>
<h1>Settings</h1>
<form method="post">
<label>Phone</label><input type="text" name="phone" value="<?= esc($settings['phone']) ?>">
<label>Email</label><input type="text" name="email" value="<?= esc($settings['email']) ?>">
<label>Address</label><input type="text" name="address" value="<?= esc($settings['address']) ?>">
<label>Facebook URL</label><input type="text" name="facebook" value="<?= esc($settings['facebook']) ?>">
<label>Instagram URL</label><input type="text" name="instagram" value="<?= esc($settings['instagram']) ?>">
<label>WhatsApp</label><input type="text" name="whatsapp" value="<?= esc($settings['whatsapp']) ?>">
<button name="save_settings">Save</button></form>
<?php elseif($action==='notify'): ?>
<?php $ncfg = notify_cfg(); ?>
<h1>Notifications</h1>
<p style="color:#8a8270;font-size:13px;">Every new registration, quote, contact message and site issue is sent to <b>all</b> addresses and numbers below at the same time.</p>
<form method="post">
<label>Alert emails (one per line)</label><textarea name="n_emails" rows="4" placeholder="wael@7boys.com.jo"><?= esc(implode(chr(10), $ncfg['emails'])) ?></textarea>
<label>WhatsApp numbers (country code + number, one per line)</label><textarea name="n_wa" rows="3" placeholder="962795816444"><?= esc(implode(chr(10), $ncfg['wa'])) ?></textarea>
<label style="display:block;margin:8px 0;"><input type="checkbox" name="ev_register" value="1"<?= !empty($ncfg['events']['register']) ? ' checked' : '' ?>> New registrations</label>
<label style="display:block;margin:8px 0;"><input type="checkbox" name="ev_quote" value="1"<?= !empty($ncfg['events']['quote']) ? ' checked' : '' ?>> New quote requests</label>
<label style="display:block;margin:8px 0;"><input type="checkbox" name="ev_contact" value="1"<?= !empty($ncfg['events']['contact']) ? ' checked' : '' ?>> New contact messages</label>
<label style="display:block;margin:8px 0;"><input type="checkbox" name="ev_monitor" value="1"<?= !empty($ncfg['events']['monitor']) ? ' checked' : '' ?>> Site down / recovered</label>
<h3 style="color:#053d20;margin-top:20px;">WhatsApp sending (UltraMsg — optional)</h3>
<p style="color:#8a8270;font-size:13px;">Emails work immediately. For WhatsApp alerts connect a free UltraMsg instance, then paste its ID + token here. Without it, WhatsApp alerts stay off and nothing breaks.</p>
<label>UltraMsg instance ID</label><input type="text" name="um_instance" value="<?= esc($ncfg['um']['instance'] ?? '') ?>" placeholder="instance12345">
<label>UltraMsg token</label><input type="text" name="um_token" value="<?= esc($ncfg['um']['token'] ?? '') ?>" placeholder="paste token">
<div style="display:flex;gap:10px;margin-top:12px;"><button name="save_notify">Save</button><button name="test_notify" style="background:#fff;color:#2e6b3e;border:1px solid #2e6b3e;">Send test to all</button></div></form>

<?php elseif($action==='options'): ?>
<h1>Site Options</h1>
<p style="color:#8a8270;font-size:13px;">Control WhatsApp, certifications, FAQ, channels and the cookie notice. Saved values appear across the public site.</p>
<form method="post">
<label>WhatsApp number (with country code, digits only)</label><input type="text" name="whatsapp" value="<?= esc($ext['whatsapp'] ?? '') ?>" placeholder="962795816444">
<label>Cookie notice text</label><input type="text" name="cookie_text" value="<?= esc($ext['cookie_text'] ?? '') ?>">
<label>Google Maps embed code (optional, &lt;iframe&gt;…&lt;/iframe&gt;)</label><textarea name="map_embed" placeholder="<iframe src='https://maps.google.com/...'></iframe>"><?= esc($ext['map_embed'] ?? '') ?></textarea>
<h3 style="color:#053d20;margin-top:24px;">Certifications</h3>
<?php foreach(($ext['certs'] ?? []) as $c): ?>
<label>Certification</label><input type="text" name="cert[]" value="<?= esc($c) ?>">
<?php endforeach; ?>
<label>Certification</label><input type="text" name="cert[]" placeholder="e.g. Halal Certified">
<h3 style="color:#053d20;margin-top:24px;">FAQ</h3>
<?php foreach(($ext['faq'] ?? []) as $f): ?>
<label>Question</label><input type="text" name="faq_q[]" value="<?= esc($f['q'] ?? '') ?>">
<label>Answer</label><textarea name="faq_a[]"><?= esc($f['a'] ?? '') ?></textarea>
<?php endforeach; ?>
<label>Question</label><input type="text" name="faq_q[]" placeholder="Question?">
<label>Answer</label><textarea name="faq_a[]" placeholder="Answer"></textarea>
<h3 style="color:#053d20;margin-top:24px;">Sales Channels</h3>
<?php foreach(($ext['channels'] ?? []) as $ck=>$cv): ?>
<label>Channel key / label</label><div style="display:flex;gap:8px;"><input type="text" name="chan_key[]" value="<?= esc($ck) ?>" placeholder="key" style="width:40%"><input type="text" name="chan_val[]" value="<?= esc($cv) ?>" placeholder="Label"></div>
<?php endforeach; ?>
<label>Channel key / label</label><div style="display:flex;gap:8px;"><input type="text" name="chan_key[]" placeholder="key" style="width:40%"><input type="text" name="chan_val[]" placeholder="Label"></div>
<button name="save_options">Save Options</button></form>

<?php elseif($action==='health'): ?>
<?php
if (isset($_GET['fix_slugs'])) {
  $fp = load_products(); $fixed = 0;
  foreach ($fp as &$x) { if (trim($x['slug'] ?? '') === '' && trim($x['name'] ?? '') !== '') { $x['slug'] = slugify($x['name']) . '-' . ($x['id'] ?? uniqid()); $fixed++; } }
  unset($x);
  if ($fixed) { save_products($fp); $products = $fp; auto_publish(); }
  $msg = $fixed ? "Fixed $fixed empty slug(s)." : 'No empty slugs found.';
}
$hp = $products ?? []; $hb = $brands ?? [];
$hp_noimg = array_values(array_filter($hp, function($p){ $im = trim($p['image'] ?? ''); return $im === '' || $im === 'placeholder.png'; }));
$hp_noslug = array_values(array_filter($hp, function($p){ return trim($p['slug'] ?? '') === ''; }));
$hp_seen = []; $hp_dupes = [];
foreach ($hp as $idx => $p) { $k = strtolower(trim($p['name'] ?? '')); if ($k === '') continue; if (isset($hp_seen[$k])) $hp_dupes[$k][] = $idx; elseif (!isset($hp_dupes[$k])) $hp_seen[$k] = $idx; else $hp_dupes[$k][] = $idx; }
$hp_dupes = array_filter($hp_dupes, function($v){ return is_array($v); });
$hp_used = []; foreach ($hp as $p) $hp_used[$p['brand'] ?? ''] = true;
$hp_empty_brands = array_values(array_filter($hb, function($b) use ($hp_used){ return empty($hp_used[$b['slug'] ?? '']); }));
$hp_out = array_values(array_filter($hp, function($p){ $s = trim((string)($p['stock'] ?? '')); return $s === '' || intval($s) <= 0; }));
$hp_low = array_values(array_filter($hp, function($p){ $s = intval($p['stock'] ?? 0); return $s >= 1 && $s <= 5; }));
$hp_noseo = array_values(array_filter($hp, function($p){ return trim($p['seo_title'] ?? '') === '' || trim($p['seo_desc'] ?? '') === ''; }));
$hp_smap = 0; $hp_smap_file = SITE_DIR . '/sitemap.xml';
if (is_file($hp_smap_file)) $hp_smap = substr_count(file_get_contents($hp_smap_file), 'product.php');
?>
<h1>Catalog Health</h1>
<?php if($msg) echo "<div class='msg'>".esc($msg)."</div>"; ?>
<?php
$hl_total = count($hp);
$hl_seg = [
  ['lbl'=>'Missing image','n'=>count($hp_noimg),'col'=>'#c0392b'],
  ['lbl'=>'Duplicate names','n'=>count($hp_dupes),'col'=>'#e67e22'],
  ['lbl'=>'Empty slugs','n'=>count($hp_noslug),'col'=>'#e0a100'],
  ['lbl'=>'Out of stock','n'=>count($hp_out),'col'=>'#7f1d1d'],
  ['lbl'=>'Low stock (≤5)','n'=>count($hp_low),'col'=>'#c9a23f'],
  ['lbl'=>'Missing SEO','n'=>count($hp_noseo),'col'=>'#66a380'],
];
$hl_sum = array_sum(array_column($hl_seg,'n'));
$hl_ok = max(0, $hl_total - min($hl_total, $hl_sum));
$hl_den = max(1, $hl_total);
$hl_c = 2 * M_PI * 70; $hl_off = 0;
?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 18px;display:flex;gap:24px;align-items:center;justify-content:center;flex-wrap:wrap;margin:24px 0;">
<svg width="190" height="190" viewBox="0 0 190 190">
<circle cx="95" cy="95" r="70" fill="none" stroke="#eef1ee" stroke-width="26"/>
<?php if($hl_ok>0): $f=$hl_ok/$hl_den; ?>
<circle cx="95" cy="95" r="70" fill="none" stroke="#0a5230" stroke-width="26" stroke-dasharray="<?= round($f*$hl_c,1) ?> <?= round($hl_c,1) ?>" stroke-dashoffset="<?= round(-$hl_off,1) ?>" transform="rotate(-90 95 95)"/>
<?php $hl_off += $f*$hl_c; endif; ?>
<?php foreach($hl_seg as $sg): if($sg['n']<=0) continue; $f=$sg['n']/$hl_den; ?>
<circle cx="95" cy="95" r="70" fill="none" stroke="<?= $sg['col'] ?>" stroke-width="26" stroke-dasharray="<?= round($f*$hl_c,1) ?> <?= round($hl_c,1) ?>" stroke-dashoffset="<?= round(-$hl_off,1) ?>" transform="rotate(-90 95 95)"/>
<?php $hl_off += $f*$hl_c; endforeach; ?>
<text x="95" y="92" text-anchor="middle" font-size="26" font-weight="700" fill="#053d20"><?= $hl_total ?></text>
<text x="95" y="112" text-anchor="middle" font-size="11" fill="#888">products</text>
</svg>
<div style="min-width:200px;flex:1;max-width:360px;">
<div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;"><span style="width:12px;height:12px;border-radius:50%;background:#0a5230;flex:none;"></span><span style="flex:1;color:#2e6b3e;">Healthy</span><b style="color:#111;"><?= $hl_ok ?></b></div>
<?php foreach($hl_seg as $sg): ?>
<div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;"><span style="width:12px;height:12px;border-radius:50%;background:<?= $sg['n']>0 ? $sg['col'] : '#ddd' ?>;flex:none;"></span><span style="flex:1;color:#2e6b3e;"><?= esc($sg['lbl']) ?></span><b style="color:<?= $sg['n']>0 ? '#b71c1c' : '#111' ?>;"><?= $sg['n'] ?></b></div>
<?php endforeach; ?>
<div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;border-top:1px solid #eee;padding-top:8px;"><span style="width:12px;height:12px;border-radius:50%;background:<?= $hp_smap===count($hp) ? '#0a5230' : '#c0392b' ?>;flex:none;"></span><span style="flex:1;color:#2e6b3e;">Sitemap URLs / products</span><b style="color:#111;"><?= $hp_smap ?>/<?= count($hp) ?></b></div>
<div style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;"><span style="width:12px;height:12px;border-radius:50%;background:<?= count($hp_empty_brands)>0 ? '#c9a23f' : '#0a5230' ?>;flex:none;"></span><span style="flex:1;color:#2e6b3e;">Empty brands</span><b style="color:#111;"><?= count($hp_empty_brands) ?></b></div>
</div>
</div>
<div class="dash-actions">
  <?php if (count($hp_noslug)): ?><a href="index.php?action=health&fix_slugs=1" class="btn-dash" onclick="return confirm('Auto-generate <?= count($hp_noslug) ?> missing slug(s)?');">Fix <?= count($hp_noslug) ?> empty slugs</a><?php endif; ?>
  <a href="csv_export.php" class="btn-dash outline">Export CSV</a>
  <a href="index.php?action=products" class="btn-dash outline">Open Products</a>
</div>
<?php if ($hp_dupes): ?>
<h3 style="color:#053d20;">Duplicate product names (keep first, delete rest from Products)</h3>
<table class="recent-table"><tr><th>Name</th><th>Copies</th></tr>
<?php $di=0; foreach ($hp_dupes as $dn => $rows): if ($di++>=50) break; ?>
<tr><td><?= esc($dn) ?></td><td><?= count($rows)+1 ?></td></tr>
<?php endforeach; ?></table>
<?php if (count($hp_dupes)>50): ?><p style="color:#888">…and <?= count($hp_dupes)-50 ?> more.</p><?php endif; ?>
<?php endif; ?>
<?php if ($hp_noimg): ?>
<h3 style="color:#053d20;">Products without image (first 50)</h3>
<table class="recent-table"><tr><th>Name</th><th>Brand</th></tr>
<?php $ni=0; foreach ($hp_noimg as $p): if ($ni++>=50) break; ?>
<tr><td><?= esc($p['name'] ?? '-') ?></td><td><?= esc($p['brand'] ?? '-') ?></td></tr>
<?php endforeach; ?></table>
<?php if (count($hp_noimg)>50): ?><p style="color:#888">…and <?= count($hp_noimg)-50 ?> more.</p><?php endif; ?>
<?php endif; ?>
<?php if ($hp_empty_brands): ?>
<details style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;margin-top:14px;"><summary style="cursor:pointer;color:#053d20;font-weight:700;">Brands with no products (<?= count($hp_empty_brands) ?>) — click to expand</summary>
<p style="color:#555"><?= esc(implode(', ', array_map(function($b){ return $b['name'] ?? $b['slug']; }, $hp_empty_brands))) ?></p></details>
<?php endif; ?>
<?php if ($hp_out || $hp_low): ?>
<details style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;margin-top:14px;"><summary style="cursor:pointer;color:#053d20;font-weight:700;">Stock alerts (out + low) — <?= count($hp_out)+count($hp_low) ?> items, click to expand</summary><div style="max-height:380px;overflow-y:auto;">
<table class="recent-table"><tr><th>Name</th><th>Stock</th><th>Status</th></tr>
<?php $si=0; foreach (array_merge($hp_out, $hp_low) as $p): if ($si++>=50) break; $sv = trim((string)($p['stock'] ?? '')); $is_out = ($sv === '' || intval($sv) <= 0); ?>
<tr><td><?= esc($p['name'] ?? '-') ?></td><td><?= esc($sv === '' ? '—' : $sv) ?></td><td><span style="background:<?= $is_out ? '#fde2e2;color:#b71c1c' : '#fef3c7;color:#92400e' ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700"><?= $is_out ? 'OUT' : 'LOW' ?></span></td></tr>
<?php endforeach; ?>
</table>
</div></details><?php if (count($hp_out)+count($hp_low)>50): ?><p style="color:#888">…and <?= count($hp_out)+count($hp_low)-50 ?> more.</p><?php endif; ?>
<?php endif; ?>
<?php if ($hp_noseo): ?>
<h3 style="color:#053d20;">Products missing SEO title/description (first 50 of <?= count($hp_noseo) ?>)</h3>
<table class="recent-table"><tr><th>Name</th><th>Missing</th></tr>
<?php $oi=0; foreach ($hp_noseo as $p): if ($oi++>=50) break; ?>
<tr><td><?= esc($p['name'] ?? '-') ?></td><td style="color:#888"><?= trim($p['seo_title'] ?? '') === '' ? 'title ' : '' ?><?= trim($p['seo_desc'] ?? '') === '' ? 'desc' : '' ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($hp_smap !== count($hp)): ?>
<p style="background:#fde2e2;color:#7f1d1d;padding:10px 14px;border-radius:8px;margin-top:16px;">⚠️ Sitemap has <?= $hp_smap ?> product URLs but catalog has <?= count($hp) ?> products. Open <a href="index.php?action=publish">Publish Site</a> and publish to regenerate.</p>
<?php endif; ?>

<?php elseif($action==='search'): ?>
<?php
$sq = strtolower(trim($_GET['q'] ?? ''));
$sp = $sb = $sc = [];
if ($sq !== '') {
  $sp = array_values(array_filter($products ?? [], function($p) use ($sq){ return stripos(($p['name'] ?? '').' '.($p['brand'] ?? '').' '.($p['cat'] ?? ''), $sq) !== false; }));
  $sb = array_values(array_filter($brands ?? [], function($b) use ($sq){ return stripos(($b['name'] ?? '').' '.($b['slug'] ?? ''), $sq) !== false; }));
  $sc = array_values(array_filter($cats ?? [], function($c) use ($sq){ return stripos(($c['name'] ?? '').' '.($c['slug'] ?? ''), $sq) !== false; }));
}
?>
<h1>Search <?= $sq !== '' ? '“'.esc($_GET['q']).'”' : '' ?></h1>
<?php if ($sq === ''): ?><p style="color:#888">Type in the search box above.</p><?php else: ?>
<h3 style="color:#053d20;">Products (<?= count($sp) ?>)</h3>
<?php if (!$sp): ?><p style="color:#888">No products match.</p><?php else: ?>
<table class="recent-table"><tr><th>Name</th><th>Brand</th><th>Category</th></tr>
<?php foreach (array_slice($sp, 0, 50) as $p): ?><tr><td><?= esc($p['name'] ?? '-') ?></td><td><?= esc($p['brand'] ?? '-') ?></td><td><?= esc($p['cat'] ?? '-') ?></td></tr><?php endforeach; ?>
</table><?php if (count($sp)>50): ?><p style="color:#888">…and <?= count($sp)-50 ?> more.</p><?php endif; ?><?php endif; ?>
<h3 style="color:#053d20;">Brands (<?= count($sb) ?>)</h3>
<?php if ($sb): ?><p style="color:#555"><?= esc(implode(', ', array_map(function($b){ return $b['name'] ?? $b['slug']; }, $sb))) ?></p><?php else: ?><p style="color:#888">No brands match.</p><?php endif; ?>
<h3 style="color:#053d20;">Categories (<?= count($sc) ?>)</h3>
<?php if ($sc): ?><p style="color:#555"><?= esc(implode(', ', array_map(function($c){ return $c['name'] ?? $c['slug']; }, $sc))) ?></p><?php else: ?><p style="color:#888">No categories match.</p><?php endif; ?>
<?php endif; ?>

<?php elseif($action==='audit'): ?>
<h1>Audit Log</h1>
<p style="color:#8a8270;font-size:13px;">Every save, publish and delete recorded here — newest first.</p>
<?php $al_file = SITE_DIR . '/admin/data/audit.log'; $al_lines = is_file($al_file) ? file($al_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []; $al_lines = array_slice(array_reverse($al_lines ? $al_lines : []), 0, 200); ?>
<?php if (!$al_lines): ?><p style="color:#888">Log is empty.</p><?php else: ?>
<table class="recent-table"><tr><th>#</th><th>Entry</th></tr>
<?php foreach (array_slice($al_lines,0,30) as $i => $ln): ?><tr><td><?= count($al_lines)-$i ?></td><td><?= esc($ln) ?></td></tr><?php endforeach; ?>
</table>
<?php if (count($al_lines)>30): ?>
<details style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;margin-top:14px;"><summary style="cursor:pointer;color:#053d20;font-weight:700;">Older entries — <?= count($al_lines)-30 ?> more, click to expand</summary><div style="max-height:380px;overflow-y:auto;"><table class="recent-table"><tr><th>#</th><th>Entry</th></tr>
<?php foreach (array_slice($al_lines,30) as $j => $ln): ?><tr><td><?= count($al_lines)-30-$j ?></td><td><?= esc($ln) ?></td></tr><?php endforeach; ?>
</table></div></details>
<?php endif; ?>
<?php if (count($al_lines)>=200): ?><p style="color:#888">Showing latest 200 entries.</p><?php endif; ?><?php endif; ?>

<?php elseif($action==='publish_run'):
  auto_publish();
  header('Location: index.php?action=publish&msg=' . urlencode('Site published successfully.')); exit;
?>
<?php elseif($action==='monrun'): ?>
<?php
  define('ADMIN_AREA', 1);
  require_once __DIR__ . '/monitor.php';
  $mr = mon_run();
  $okc = count(array_filter($mr['checks'], function ($c) { return !empty($c['ok']); }));
  $totc = count($mr['checks']);
  header('Location: index.php?action=dashboard&msg=' . urlencode($mr['ok'] ? "All $totc checks green" : "ISSUE: $okc/$totc green - email sent")); exit;
?>
<?php elseif($action==='purge'): ?>
<?php
  // Smart purge: new SW cache version + clear file caches + republish
  $new_ver = bump_sw();
  foreach (glob(SITE_DIR . '/admin/data/cache_*.json') as $cf) @unlink($cf);
  auto_publish();
  header('Location: index.php?action=dashboard&msg=' . urlencode('Cache purged — CDN refreshed (v' . $new_ver . ')')); exit;
?>
<?php elseif($action==='header_control'): ?>
<?php
  require_once __DIR__ . '/header_control.php';
  render_header_control($brands, $cats, $ext);
  ?>
<?php elseif($action==='publish'): ?>
<h1>Publish Site</h1>
<p>This generates the public website from your brands, products, categories and settings. Saving any item now auto-publishes, so this is only needed to force a rebuild.</p>
<a href="index.php?action=publish_run" style="background:#053d20;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;">→ Publish Now</a>
<p style="color:#8a8270;margin-top:12px;">A sitemap.xml and robots.txt are generated automatically on every publish.</p>
<?php endif; ?>
</div>
<script>
function previewDZ(input, prevId){
  var prev = document.getElementById(prevId);
  if(input.files && input.files[0]){
    var r = new FileReader();
    r.onload = function(e){ prev.src=e.target.result; prev.style.display='block'; };
    r.readAsDataURL(input.files[0]);
  }
}
['dz-new','dz-edit'].forEach(function(id){
  var dz=document.getElementById(id); if(!dz) return;
  dz.addEventListener('dragover',function(e){e.preventDefault();dz.classList.add('drag');});
  dz.addEventListener('dragleave',function(){dz.classList.remove('drag');});
  dz.addEventListener('drop',function(e){e.preventDefault();dz.classList.remove('drag');var dt=e.dataTransfer;if(dt.files.length){document.getElementById(id==='dz-new'?'file-new':'file-edit').files=dt.files;previewDZ(document.getElementById(id==='dz-new'?'file-new':'file-edit'), id==='dz-new'?'prev-new':'prev-edit');}});
});
</script>
</body></html>
