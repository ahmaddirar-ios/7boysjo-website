<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
if($_SERVER["REQUEST_METHOD"]==="OPTIONS"){ http_response_code(204); exit; }
// rate limit 60/min per IP
$__ip=$_SERVER["REMOTE_ADDR"]??"0.0.0.0"; $__rlf=sys_get_temp_dir()."/api_rl_".md5($__ip).".json"; $__now=time(); $__data=["c"=>0,"t"=>$__now]; if(file_exists($__rlf)){ $__d=json_decode(file_get_contents($__rlf),true); if($__d){ $__data=$__d; if($__now-($__data["t"]??0)>60) $__data=["c"=>0,"t"=>$__now]; } } $__data["c"]++; file_put_contents($__rlf, json_encode($__data)); header("X-RateLimit-Limit: 60"); header("X-RateLimit-Remaining: ".max(0,60-$__data["c"])); if($__data["c"]>60){ http_response_code(429); echo json_encode(["error"=>"Rate limit exceeded - 60/min"], JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . "/../../admin/config.php";

$path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
if(strpos($path,"api/v1/")!==false) $path=substr($path, strpos($path,"api/v1/")+7);

function j($d,$code=200){ http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }

if($path==="products" || $path==="products.php"){
  $products = load_products();
  $products = normalize_products_full($products);
  $brand = trim($_GET["brand"]??"");
  $cat = trim($_GET["cat"]??"");
  $q = trim($_GET["q"]??"");
  $limit = intval($_GET["limit"]??0);
  $page = max(1,intval($_GET["page"]??1));
  $per_page = $limit>0 ? min($limit,100) : 20;
  if($brand!=="") $products=array_values(array_filter($products, fn($p)=>($p["brand"]??"")===$brand));
  if($cat!=="") $products=array_values(array_filter($products, fn($p)=>($p["cat"]??"")===$cat));
  if($q!=="") $products=array_values(array_filter($products, fn($p)=>stripos($p["name"]??"",$q)!==false || stripos($p["brand"]??"",$q)!==false));
  $total=count($products);
  $offset=($page-1)*$per_page;
  $products=array_slice($products,$offset,$per_page);
  header("X-Total-Count: $total"); header("X-Page: $page"); header("X-Per-Page: $per_page");
  $out=array_map(fn($p)=>[
    "id"=>$p["id"]??null,
    "slug"=>product_slug($p),
    "name"=>$p["name"]??"",
    "brand"=>$p["brand"]??"",
    "cat"=>$p["cat"]??"",
    "origin"=>$p["origin"]??"",
    "price"=>$p["price"]??"",
    "image"=>$p["image"]??"",
    "featured"=>!empty($p["featured"])
  ], $products);
  j(["count"=>count($out), "total"=>$total, "page"=>$page, "per_page"=>$per_page, "products"=>$out]);
}
if($path==="brands"){
  $brands=load_brands();
  j(["count"=>count($brands), "brands"=>$brands]);
}
if($path==="categories"){
  j(["categories"=>load_cats()]);
}
if($path==="quotes"){
  if($_SERVER["REQUEST_METHOD"]==="GET"){
    $key = $_SERVER["HTTP_X_API_KEY"] ?? $_GET["key"] ?? "";
    $valid = false;
    if(is_logged_in() || is_staff()) $valid=true;
    $ext=load_ext();
    $apiKey=$ext["api_key"]??"";
    if($apiKey && hash_equals($apiKey, $key)) $valid=true;
    if(!$valid) j(["error"=>"Unauthorized - provide X-API-Key or admin login"],401);
    $quotes=load_json(SITE_DIR."/admin/data/quotes.json");
    j(["count"=>count($quotes), "quotes"=>array_reverse($quotes)]);
  }
  if($_SERVER["REQUEST_METHOD"]==="POST"){
    $in=json_decode(file_get_contents("php://input"), true);
    if(!$in) $in=$_POST;
    $name=trim($in["name"]??"");
    $email=trim($in["email"]??"");
    if($name===""||$email==="") j(["error"=>"name and email required"],400);
    $quotes=load_json(SITE_DIR."/admin/data/quotes.json");
    $id="QB-".date("Ymd")."-".strtoupper(substr(md5(uniqid()),0,6));
    $q=["id"=>$id,"visitor_id"=>0,"visitor_email"=>$email,"name"=>$name,"email"=>$email,"company"=>trim($in["company"]??""),"phone"=>trim($in["phone"]??""),"note"=>trim($in["note"]??""),"items"=>$in["items"]??[],"at"=>date("Y-m-d H:i:s"),"status"=>"new"];
    $quotes[]=$q;
    save_json(SITE_DIR."/admin/data/quotes.json",$quotes);
    $msgs=load_json(SITE_DIR."/admin/data/messages.json");
    $msgs[]=["type"=>"quote","quote_id"=>$id,"name"=>$name,"email"=>$email,"company"=>$q["company"],"phone"=>$q["phone"],"msg"=>$q["note"]." | Items: ".json_encode($q["items"]),"at"=>$q["at"],"ip"=>$_SERVER["REMOTE_ADDR"]??"", "read"=>false];
    save_json(SITE_DIR."/admin/data/messages.json",$msgs);
    j(["ok"=>true,"id"=>$id,"quote"=>$q],201);
  }
}
if($path==="" || $path==="docs"){
  j(["name"=>"7Boys API v1","base"=>"https://7boysjo.com/api/v1","endpoints"=>[
    "GET /products?brand=&cat=&q=&limit="=>"public, 152 products",
    "GET /brands"=>"public, 32 brands",
    "GET /categories"=>"public",
    "GET /quotes"=>"auth X-API-Key or admin session",
    "POST /quotes"=>"public, body: {name,email,company,phone,note,items:[{slug,qty}]}"
  ],"auth"=>"Header X-API-Key (see admin settings) or admin cookie"]);
}
j(["error"=>"Not found","path"=>$path,"available"=>["products","brands","categories","quotes","docs"]],404);
