<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$message=''; $error='';
$lockFile=__DIR__.'/setup.lock';
if(is_file($lockFile)) $error='Installation is already completed. Remove setup.lock only if you intentionally need to reinstall this copy.';

if(!$error && $_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $shop=clean_string($_POST['shop_name']??'',150);
    $name=clean_string($_POST['name']??'',120);
    $email=strtolower(clean_string($_POST['email']??'',190));
    $pass=(string)($_POST['password']??'');
    $currency=clean_string($_POST['currency']??'Rs.',10) ?: 'Rs.';
    $phone=clean_string($_POST['phone']??'',40);
    $address=clean_string($_POST['address']??'',255);
    if($shop===''||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10) throw new RuntimeException('Enter the shop name, admin name, valid email and a password of at least 10 characters.');

    // Create the database when the configured MySQL account has permission to do so.
    global $config;
    $serverDsn='mysql:host='.$config['db_host'].';charset=utf8mb4';
    $server=new PDO($serverDsn,$config['db_user'],$config['db_pass'],[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $dbName=(string)$config['db_name'];
    if(!preg_match('/^[A-Za-z0-9_]+$/',$dbName)) throw new RuntimeException('Invalid database name in config.local.php.');
    $server->exec('CREATE DATABASE IF NOT EXISTS `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$dbName.';charset=utf8mb4',$config['db_user'],$config['db_pass'],[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES=>false,
    ]);

    // Install schema statements without requiring phpMyAdmin.
    $sql=file_get_contents(__DIR__.'/schema.sql');
    if($sql===false) throw new RuntimeException('schema.sql could not be read.');
    $sql=preg_replace('/^CREATE DATABASE.*?;\s*/ims','',$sql,1);
    $sql=preg_replace('/^USE\s+[^;]+;\s*/im','',$sql,1);
    $sql=preg_replace('/--[^\n]*\n/','\n',$sql);
    $statements=preg_split('/;\s*(?=CREATE TABLE|$)/i',$sql);
    foreach($statements as $stmt){$stmt=trim($stmt);if($stmt!=='')$pdo->exec($stmt);}

    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT COUNT(*) FROM users');$st->execute();
    if((int)$st->fetchColumn()>0) throw new RuntimeException('This database already contains users. Use the existing login instead of running setup again.');
    $st=$pdo->prepare('INSERT INTO shops(name,currency,phone,address,plan,status) VALUES(?,?,?,? ,"starter","active")');
    $st->execute([$shop,$currency,$phone?:null,$address?:null]);
    $shopId=(int)$pdo->lastInsertId();
    $st=$pdo->prepare('INSERT INTO users(shop_id,name,email,password_hash,role,active) VALUES(?,?,?,?,"shop_admin",1)');
    $st->execute([$shopId,$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO shop_sequences(shop_id,next_number) VALUES(?,1)')->execute([$shopId]);
    $pdo->prepare('INSERT INTO subscriptions(shop_id,plan,status,started_at) VALUES(?,"starter","active",CURDATE())')->execute([$shopId]);
    $pdo->commit();
    file_put_contents($lockFile,'Installed '.date('c').PHP_EOL,LOCK_EX);
    $message='Installation complete. Your Shop Admin is ready. Delete setup.php from the server for extra safety, then open index.php.';
  }catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
    $error=$e->getMessage();
  }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rafay POS Installer</title><style>body{font-family:system-ui;background:#f4f6f9;padding:30px}.box{max-width:620px;margin:auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 10px 30px #0001}input{width:100%;padding:12px;margin:7px 0 15px;box-sizing:border-box;border:1px solid #ddd;border-radius:8px}button{width:100%;padding:13px;background:#111827;color:#fff;border:0;border-radius:8px;font-weight:700}.ok{color:#166534}.err{color:#b91c1c}.muted{color:#667085}</style></head><body><div class="box"><h1>Rafay POS Installer</h1><p class="muted">This creates one completely independent POS installation for one shop.</p><?php if($message):?><p class="ok"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if($error):?><p class="err"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if(!$message):?><form method="post"><label>Shop name</label><input name="shop_name" required maxlength="150" placeholder="My Shop"><label>Admin name</label><input name="name" required maxlength="120" placeholder="Shop Owner"><label>Admin email</label><input type="email" name="email" required maxlength="190" autocomplete="username"><label>Admin password</label><input type="password" name="password" minlength="10" required autocomplete="new-password"><label>Currency</label><input name="currency" maxlength="10" value="Rs."><label>Phone</label><input name="phone" maxlength="40"><label>Address</label><input name="address" maxlength="255"><button>Install Rafay POS</button></form><?php endif;?><p class="muted"><b>Hosting:</b> PHP 8.1+ with PDO MySQL/MariaDB. The MySQL account must be allowed to create the configured database during installation.</p></div></body></html>
