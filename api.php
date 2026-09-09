<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    $action = $_GET['action'] ?? 'bootstrap';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!same_origin()) json_response(['error' => 'Cross-origin request blocked'], 403);

    if ($action === 'login' && $method === 'POST') {
        $d = input_json();
        $email = strtolower(clean_string($d['email'] ?? '', 190));
        $password = (string)($d['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') json_response(['error'=>'Valid email and password are required'], 422);
        if (login_rate_limited($email)) json_response(['error'=>'Too many failed login attempts. Try again in 15 minutes.'], 429);
        $st = db()->prepare('SELECT u.*, s.name shop_name, s.status shop_status FROM users u LEFT JOIN shops s ON s.id=u.shop_id WHERE u.email=? LIMIT 1');
        $st->execute([$email]); $u = $st->fetch();
        if (!$u || !(int)$u['active'] || !password_verify($password, $u['password_hash'])) { record_login($email, false); usleep(250000); json_response(['error'=>'Invalid login credentials'], 401); }
        if ($u['role'] !== 'super_admin' && $u['shop_status'] !== 'active') json_response(['error'=>'This shop is suspended'], 403);
        record_login($email, true);
        session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['user'] = ['id'=>(int)$u['id'],'shop_id'=>$u['shop_id'] ? (int)$u['shop_id'] : null,'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'shop_name'=>$u['shop_name']];
        if ($u['role'] === 'super_admin') unset($_SESSION['active_shop_id']); else $_SESSION['active_shop_id'] = (int)$u['shop_id'];
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$u['id']]);
        audit($u['shop_id'] ? (int)$u['shop_id'] : null, (int)$u['id'], 'login');
        json_response(['user'=>$_SESSION['user'],'csrf'=>csrf_token()]);
    }
    if ($action === 'logout') { if (!empty($_SESSION['user'])) audit($_SESSION['user']['shop_id'] ?? null, (int)$_SESSION['user']['id'], 'logout'); $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']); } session_destroy(); json_response(['ok'=>true]); }
    if ($action === 'me') json_response(['user'=>$_SESSION['user'] ?? null,'csrf'=>csrf_token()]);

    $user = require_login();
    if ($method === 'POST') require_csrf();
    $pdo = db();

    if ($action === 'shops') {
        require_role(['super_admin']);
        $rows = $pdo->query('SELECT id,name,slogan,currency,phone,address,status,plan,plan_expires_at,created_at FROM shops ORDER BY id DESC')->fetchAll();
        json_response(['shops'=>$rows]);
    }
    if ($action === 'create_shop' && $method === 'POST') {
        require_role(['super_admin']); $d=input_json();
        $name=clean_string($d['name']??'',150); $email=strtolower(clean_string($d['email']??'',190)); $password=(string)($d['password']??'');
        $plan=in_array($d['plan']??'starter',['starter','professional','business','custom'],true)?$d['plan']:'starter';
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10) json_response(['error'=>'Shop name, valid admin email and password (10+ characters) are required'],422);
        $pdo->beginTransaction();
        try {
            $st=$pdo->prepare('INSERT INTO shops(name,slogan,currency,phone,address,plan,plan_expires_at) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$name,clean_string($d['slogan']??'',255),clean_string($d['currency']??'Rs.',10),clean_string($d['phone']??'',40),clean_string($d['address']??'',255),$plan,clean_string($d['plan_expires_at']??'',10) ?: null]);
            $shopId=(int)$pdo->lastInsertId();
            $st=$pdo->prepare('INSERT INTO users(shop_id,name,email,password_hash,role) VALUES(?,?,?,?,?)');
            $st->execute([$shopId,clean_string($d['admin_name']??'Shop Admin',120),$email,password_hash($password,PASSWORD_DEFAULT),'shop_admin']);
            $pdo->prepare('INSERT INTO shop_sequences(shop_id,next_number) VALUES(?,1)')->execute([$shopId]);
            $pdo->prepare('INSERT INTO subscriptions(shop_id,plan,status,started_at,expires_at) VALUES(?,?,"active",CURDATE(),?)')->execute([$shopId,$plan,clean_string($d['plan_expires_at']??'',10) ?: null]);
            $pdo->commit(); audit(null,(int)$user['id'],'create_shop','shop',$shopId,['name'=>$name,'plan'=>$plan]); json_response(['ok'=>true,'shop_id'=>$shopId]);
        } catch(Throwable $e) { $pdo->rollBack(); if ((int)$e->getCode()===23000) json_response(['error'=>'That email or shop SKU already exists.'],409); throw $e; }
    }
    if ($action === 'update_shop' && $method === 'POST') {
        require_role(['super_admin']); $d=input_json(); $id=(int)($d['id']??0); if($id<1) json_response(['error'=>'Invalid shop'],422);
        $status=in_array($d['status']??'active',['active','suspended'],true)?$d['status']:'active'; $plan=in_array($d['plan']??'starter',['starter','professional','business','custom'],true)?$d['plan']:'starter';
        $st=$pdo->prepare('UPDATE shops SET name=?,slogan=?,currency=?,phone=?,address=?,status=?,plan=?,plan_expires_at=? WHERE id=?');
        $st->execute([clean_string($d['name']??'',150),clean_string($d['slogan']??'',255),clean_string($d['currency']??'Rs.',10),clean_string($d['phone']??'',40),clean_string($d['address']??'',255),$status,$plan,clean_string($d['plan_expires_at']??'',10)?:null,$id]);
        $pdo->prepare('UPDATE subscriptions SET plan=?,expires_at=? WHERE shop_id=? AND status IN ("trial","active","past_due")')->execute([$plan,clean_string($d['plan_expires_at']??'',10)?:null,$id]);
        audit(null,(int)$user['id'],'update_shop','shop',$id,['status'=>$status,'plan'=>$plan]); json_response(['ok'=>true]);
    }
    if ($action === 'set_shop' && $method === 'POST') {
        require_role(['super_admin']); $id=(int)(input_json()['shop_id']??0); $st=$pdo->prepare('SELECT id,name,status FROM shops WHERE id=?'); $st->execute([$id]); $s=$st->fetch(); if(!$s) json_response(['error'=>'Shop not found'],404); if($s['status']!=='active') json_response(['error'=>'Suspended shops cannot be opened'],403); $_SESSION['active_shop_id']=$id; audit($id,(int)$user['id'],'switch_shop','shop',$id); json_response(['ok'=>true,'shop_id'=>$id]);
    }
    if ($action === 'shop') {
        $id=tenant_id($user); $st=$pdo->prepare('SELECT id,name,slogan,currency,phone,address,status,plan,plan_expires_at FROM shops WHERE id=?'); $st->execute([$id]); $shop=$st->fetch(); if(!$shop||$shop['status']!=='active') json_response(['error'=>'Shop unavailable'],403); json_response(['shop'=>$shop]);
    }
    if ($action === 'users') {
        $shopId=tenant_id($user); if($user['role']==='cashier') json_response(['users'=>[]]);
        $st=$pdo->prepare('SELECT id,name,email,role,active,last_login_at,created_at FROM users WHERE shop_id=? ORDER BY id DESC'); $st->execute([$shopId]); json_response(['users'=>$st->fetchAll()]);
    }
    if ($action === 'save_user' && $method === 'POST') {
        require_role(['super_admin','shop_admin']); $shopId=tenant_id($user); $d=input_json(); $id=(int)($d['id']??0); $name=clean_string($d['name']??'',120); $email=strtolower(clean_string($d['email']??'',190)); $role=in_array($d['role']??'cashier',['shop_admin','cashier'],true)?$d['role']:'cashier'; $password=(string)($d['password']??''); $active=!empty($d['active']);
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Name and valid email are required'],422); if($role==='shop_admin'&&$user['role']!=='super_admin'&&$user['role']!=='shop_admin') json_response(['error'=>'Permission denied'],403);
        try {
            if($id){$st=$pdo->prepare('SELECT role FROM users WHERE id=? AND shop_id=?');$st->execute([$id,$shopId]);$old=$st->fetch();if(!$old)json_response(['error'=>'User not found'],404); if($id===(int)$user['id']&&$role!=='shop_admin') json_response(['error'=>'You cannot remove your own admin role'],422); if($password!==''){if(strlen($password)<10)json_response(['error'=>'Password must be at least 10 characters'],422);$st=$pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=?,password_hash=? WHERE id=? AND shop_id=?');$st->execute([$name,$email,$role,$active?1:0,password_hash($password,PASSWORD_DEFAULT),$id,$shopId]);}else{$st=$pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=? WHERE id=? AND shop_id=?');$st->execute([$name,$email,$role,$active?1:0,$id,$shopId]);}}
            else{if(strlen($password)<10)json_response(['error'=>'Password must be at least 10 characters'],422);$st=$pdo->prepare('INSERT INTO users(shop_id,name,email,password_hash,role,active) VALUES(?,?,?,?,?,?)');$st->execute([$shopId,$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,1]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if($e->getCode()==='23000')json_response(['error'=>'That email is already in use.'],409);throw $e;}
        audit($shopId,(int)$user['id'],$id?'update_user':'create_user','user',$id,['role'=>$role]); json_response(['ok'=>true]);
    }
    if ($action === 'change_password' && $method === 'POST') {
        $d=input_json();$old=(string)($d['current_password']??'');$new=(string)($d['new_password']??'');if(strlen($new)<10)json_response(['error'=>'New password must be at least 10 characters'],422);$st=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$st->execute([(int)$user['id']]);$hash=$st->fetchColumn();if(!$hash||!password_verify($old,$hash))json_response(['error'=>'Current password is incorrect'],422);$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),(int)$user['id']]);audit($user['shop_id']??null,(int)$user['id'],'change_password','user',(int)$user['id']);json_response(['ok'=>true]);
    }
    if ($action === 'products') {
        $id=tenant_id($user); $st=$pdo->prepare('SELECT id,sku,name,category,description,cost_price,selling_price,stock,low_stock_threshold,active FROM products WHERE shop_id=? ORDER BY active DESC,name'); $st->execute([$id]); json_response(['products'=>$st->fetchAll()]);
    }
    if ($action === 'save_product' && $method === 'POST') {
        require_role(['super_admin','shop_admin']); $shopId=tenant_id($user); $d=input_json(); $id=(int)($d['id']??0); $name=clean_string($d['name']??'',180); $sku=clean_string($d['sku']??'',80); $category=clean_string($d['category']??'',120); $desc=clean_string($d['description']??'',500); $cost=money($d['cost_price']??0); $price=money($d['selling_price']??0); $stock=(int)($d['stock']??0); $low=(int)($d['low_stock_threshold']??5); $active=!empty($d['active']);
        if($name===''||$price<0||$cost<0||$stock<0||$low<0||$stock>2147483647)json_response(['error'=>'Enter valid non-negative product values'],422);
        try {
            if($id){$st=$pdo->prepare('SELECT stock FROM products WHERE id=? AND shop_id=?');$st->execute([$id,$shopId]);$old=$st->fetchColumn();if($old===false)json_response(['error'=>'Product not found'],404);$st=$pdo->prepare('UPDATE products SET sku=?,name=?,category=?,description=?,cost_price=?,selling_price=?,stock=?,low_stock_threshold=?,active=? WHERE id=? AND shop_id=?');$st->execute([$sku?:null,$name,$category,$desc,$cost,$price,$stock,$low,$active?1:0,$id,$shopId]);$diff=$stock-(int)$old;if($diff!==0)$pdo->prepare('INSERT INTO inventory_movements(shop_id,product_id,user_id,movement_type,quantity_change,stock_after,note) VALUES(?,?,?,?,?,?,?)')->execute([$shopId,$id,$user['id'],'adjustment',$diff,$stock,'Manual stock edit']);}
            else{$st=$pdo->prepare('INSERT INTO products(shop_id,sku,name,category,description,cost_price,selling_price,stock,low_stock_threshold,active) VALUES(?,?,?,?,?,?,?,?,?,?)');$st->execute([$shopId,$sku?:null,$name,$category,$desc,$cost,$price,$stock,$low,$active?1:0]);$id=(int)$pdo->lastInsertId();if($stock>0)$pdo->prepare('INSERT INTO inventory_movements(shop_id,product_id,user_id,movement_type,quantity_change,stock_after,note) VALUES(?,?,?,?,?,?,?)')->execute([$shopId,$id,$user['id'],'opening',$stock,$stock,'Opening stock']);}
        }catch(PDOException $e){if($e->getCode()==='23000')json_response(['error'=>'That SKU already exists in this shop.'],409);throw $e;}
        audit($shopId,(int)$user['id'],'save_product','product',$id);json_response(['ok'=>true]);
    }
    if ($action === 'delete_product' && $method === 'POST') { require_role(['super_admin','shop_admin']);$shopId=tenant_id($user);$id=(int)(input_json()['id']??0);$pdo->prepare('UPDATE products SET active=0 WHERE id=? AND shop_id=?')->execute([$id,$shopId]);audit($shopId,(int)$user['id'],'archive_product','product',$id);json_response(['ok'=>true]); }
    if ($action === 'restore_product' && $method === 'POST') { require_role(['super_admin','shop_admin']);$shopId=tenant_id($user);$id=(int)(input_json()['id']??0);$pdo->prepare('UPDATE products SET active=1 WHERE id=? AND shop_id=?')->execute([$id,$shopId]);audit($shopId,(int)$user['id'],'restore_product','product',$id);json_response(['ok'=>true]); }

    if ($action === 'customers') { $shopId=tenant_id($user);$st=$pdo->prepare('SELECT id,name,phone,address,created_at FROM customers WHERE shop_id=? ORDER BY name');$st->execute([$shopId]);json_response(['customers'=>$st->fetchAll()]); }
    if ($action === 'invoices') { $shopId=tenant_id($user);$limit=min(500,max(1,(int)($_GET['limit']??200)));$st=$pdo->prepare("SELECT i.id,i.invoice_no,i.subtotal,i.discount,i.tax,i.total,i.payment_method,i.amount_received,i.change_due,i.status,i.created_at,c.name customer_name,c.phone customer_phone FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.shop_id=? ORDER BY i.id DESC LIMIT $limit");$st->execute([$shopId]);json_response(['invoices'=>$st->fetchAll()]); }
    if ($action === 'invoice') { $shopId=tenant_id($user);$id=(int)($_GET['id']??0);$st=$pdo->prepare('SELECT i.*,c.name customer_name,c.phone customer_phone,c.address customer_address,s.name shop_name,s.slogan,s.currency FROM invoices i JOIN shops s ON s.id=i.shop_id LEFT JOIN customers c ON c.id=i.customer_id WHERE i.id=? AND i.shop_id=?');$st->execute([$id,$shopId]);$inv=$st->fetch();if(!$inv)json_response(['error'=>'Invoice not found'],404);$st=$pdo->prepare('SELECT product_id,product_name,sku,quantity,unit_price,cost_price,line_total FROM invoice_items WHERE invoice_id=? ORDER BY id');$st->execute([$id]);$inv['items']=$st->fetchAll();json_response(['invoice'=>$inv]); }
    if ($action === 'sale' && $method === 'POST') {
        $shopId=tenant_id($user);$d=input_json();$items=$d['items']??[];if(!is_array($items)||count($items)<1||count($items)>200)json_response(['error'=>'Cart is empty or too large'],422);
        $discount=money($d['discount']??0);$tax=money($d['tax']??0);$payment=in_array($d['payment_method']??'cash',['cash','card','bank','other'],true)?$d['payment_method']:'cash';$customer=$d['customer']??[];$name=clean_string($customer['name']??'Walk-in Customer',180)?:'Walk-in Customer';$phone=clean_string($customer['phone']??'',40);$address=clean_string($customer['address']??'',255);
        $pdo->beginTransaction();
        try {
            $customerId=null;
            if($phone!=='' || $name!=='Walk-in Customer'){
                if($phone!==''){$st=$pdo->prepare('SELECT id FROM customers WHERE shop_id=? AND phone=? LIMIT 1');$st->execute([$shopId,$phone]);$customerId=$st->fetchColumn();}
                if($customerId){$pdo->prepare('UPDATE customers SET name=?,address=? WHERE id=? AND shop_id=?')->execute([$name,$address,$customerId,$shopId]);}
                else{$pdo->prepare('INSERT INTO customers(shop_id,name,phone,address) VALUES(?,?,?,?)')->execute([$shopId,$name,$phone?:null,$address]);$customerId=(int)$pdo->lastInsertId();}
            }
            $subtotal=0;$locked=[];$seen=[];
            foreach($items as $row){$pid=(int)($row['product_id']??0);$qty=(int)($row['quantity']??0);if($pid<1||$qty<1||$qty>100000)throw new RuntimeException('Invalid cart item');if(isset($seen[$pid]))throw new RuntimeException('Duplicate product in cart');$seen[$pid]=true;$st=$pdo->prepare('SELECT id,sku,name,cost_price,selling_price,stock FROM products WHERE id=? AND shop_id=? AND active=1 FOR UPDATE');$st->execute([$pid,$shopId]);$p=$st->fetch();if(!$p)throw new RuntimeException('Product not found');if((int)$p['stock']<$qty)throw new RuntimeException('Insufficient stock for '.$p['name']);$line=round((float)$p['selling_price']*$qty,2);$subtotal+= $line;$locked[]=['p'=>$p,'qty'=>$qty,'line'=>$line];}
            $discount=min($discount,round($subtotal,2));$tax=money($tax);$total=round(max(0,$subtotal-$discount+$tax),2);$received=money($d['amount_received']??0);if($payment==='cash'&&$received+0.0001<$total)throw new RuntimeException('Amount received is less than total');if($payment!=='cash')$received=$total;$change=round(max(0,$received-$total),2);
            $st=$pdo->prepare('SELECT next_number FROM shop_sequences WHERE shop_id=? FOR UPDATE');$st->execute([$shopId]);$next=(int)$st->fetchColumn();if($next<1){$next=1;$pdo->prepare('UPDATE shop_sequences SET next_number=2 WHERE shop_id=?')->execute([$shopId]);}else{$pdo->prepare('UPDATE shop_sequences SET next_number=next_number+1 WHERE shop_id=?')->execute([$shopId]);}$invoiceNo='INV-'.date('Y').'-'.str_pad((string)$next,7,'0',STR_PAD_LEFT);
            $st=$pdo->prepare('INSERT INTO invoices(shop_id,invoice_no,customer_id,cashier_id,subtotal,discount,tax,total,payment_method,amount_received,change_due) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$st->execute([$shopId,$invoiceNo,$customerId,$user['id'],$subtotal,$discount,$tax,$total,$payment,$received,$change]);$invoiceId=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO invoice_items(invoice_id,product_id,product_name,sku,quantity,unit_price,cost_price,line_total) VALUES(?,?,?,?,?,?,?,?)');$upd=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND shop_id=?');$mov=$pdo->prepare('INSERT INTO inventory_movements(shop_id,product_id,user_id,movement_type,quantity_change,stock_after,reference_id,note) VALUES(?,?,?,?,?,?,?,?)');
            foreach($locked as $x){$p=$x['p'];$newStock=(int)$p['stock']-$x['qty'];$ins->execute([$invoiceId,$p['id'],$p['name'],$p['sku'],$x['qty'],$p['selling_price'],$p['cost_price'],$x['line']]);$upd->execute([$x['qty'],$p['id'],$shopId]);$mov->execute([$shopId,$p['id'],$user['id'],'sale',-$x['qty'],$newStock,$invoiceId,'Invoice '.$invoiceNo]);}
            $pdo->commit();audit($shopId,(int)$user['id'],'create_sale','invoice',$invoiceId,['invoice_no'=>$invoiceNo,'total'=>$total]);json_response(['ok'=>true,'invoice_id'=>$invoiceId,'invoice_no'=>$invoiceNo]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>$e->getMessage()],422);}
    }
    if (in_array($action,['cancel_invoice','refund_invoice'],true)&&$method==='POST') {
        require_role(['super_admin','shop_admin']);$shopId=tenant_id($user);$d=input_json();$id=(int)($d['id']??0);$reason=clean_string($d['reason']??'',255);$target=$action==='cancel_invoice'?'cancelled':'refunded';$required=$target==='cancelled'?'paid':'paid';
        $pdo->beginTransaction();try{$st=$pdo->prepare('SELECT status,invoice_no FROM invoices WHERE id=? AND shop_id=? FOR UPDATE');$st->execute([$id,$shopId]);$inv=$st->fetch();if(!$inv)throw new RuntimeException('Invoice not found');if($inv['status']!==$required)throw new RuntimeException('This invoice cannot be '.$target);$st=$pdo->prepare('SELECT product_id,quantity FROM invoice_items WHERE invoice_id=?');$st->execute([$id]);$items=$st->fetchAll();$upd=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=? AND shop_id=?');$mov=$pdo->prepare('INSERT INTO inventory_movements(shop_id,product_id,user_id,movement_type,quantity_change,stock_after,reference_id,note) VALUES(?,?,?,?,?,?,?,?)');foreach($items as $it){if(!$it['product_id'])continue;$lock=$pdo->prepare('SELECT stock FROM products WHERE id=? AND shop_id=? FOR UPDATE');$lock->execute([(int)$it['product_id'],$shopId]);$current=$lock->fetchColumn();if($current===false)continue;$new=(int)$current+(int)$it['quantity'];$upd->execute([(int)$it['quantity'],(int)$it['product_id'],$shopId]);$mov->execute([$shopId,(int)$it['product_id'],$user['id'],$target==='cancelled'?'cancel':'refund',(int)$it['quantity'],$new,$id,ucfirst($target).' '.$inv['invoice_no']]);}$col=$target==='cancelled'?'cancellation_reason':'refund_reason';$pdo->prepare("UPDATE invoices SET status=?, $col=? WHERE id=? AND shop_id=?")->execute([$target,$reason?:null,$id,$shopId]);$pdo->commit();audit($shopId,(int)$user['id'],$action,'invoice',$id,['reason'=>$reason]);json_response(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>$e->getMessage()],422);}
    }
    if ($action === 'dashboard') {
        $shopId=tenant_id($user);$st=$pdo->prepare("SELECT COUNT(*) invoices,COALESCE(SUM(total),0) revenue,COALESCE(SUM(discount),0) discounts FROM invoices WHERE shop_id=? AND status='paid' AND created_at>=CURDATE()");$st->execute([$shopId]);$today=$st->fetch();$st=$pdo->prepare("SELECT COUNT(*) invoices,COALESCE(SUM(total),0) revenue FROM invoices WHERE shop_id=? AND status='paid' AND created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)");$st->execute([$shopId]);$week=$st->fetch();$st=$pdo->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND active=1');$st->execute([$shopId]);$products=(int)$st->fetchColumn();$st=$pdo->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND active=1 AND stock<=low_stock_threshold');$st->execute([$shopId]);$low=(int)$st->fetchColumn();$st=$pdo->prepare("SELECT payment_method,COUNT(*) count,COALESCE(SUM(total),0) total FROM invoices WHERE shop_id=? AND status='paid' AND created_at>=CURDATE() GROUP BY payment_method ORDER BY total DESC");$st->execute([$shopId]);$payments=$st->fetchAll();json_response(['today'=>$today,'week'=>$week,'products'=>$products,'low_stock'=>$low,'payments'=>$payments]);
    }
    if ($action === 'reports') {
        $shopId=tenant_id($user);$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['from']??'')?$_GET['from']:date('Y-m-d',strtotime('-29 days'));$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['to']??'')?$_GET['to']:date('Y-m-d');
        if($from>$to)[$from,$to]=[$to,$from];$st=$pdo->prepare("SELECT COUNT(*) invoices,COALESCE(SUM(total),0) revenue,COALESCE(SUM(discount),0) discounts,COALESCE(SUM(tax),0) tax FROM invoices WHERE shop_id=? AND status='paid' AND DATE(created_at) BETWEEN ? AND ?");$st->execute([$shopId,$from,$to]);$summary=$st->fetch();$st=$pdo->prepare("SELECT ii.product_name,COALESCE(SUM(ii.quantity),0) units,COALESCE(SUM(ii.line_total),0) sales,COALESCE(SUM((ii.unit_price-ii.cost_price)*ii.quantity),0) gross_profit FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.shop_id=? AND i.status='paid' AND DATE(i.created_at) BETWEEN ? AND ? GROUP BY ii.product_id,ii.product_name ORDER BY units DESC LIMIT 20");$st->execute([$shopId,$from,$to]);$top=$st->fetchAll();$st=$pdo->prepare("SELECT payment_method,COUNT(*) invoices,COALESCE(SUM(total),0) total FROM invoices WHERE shop_id=? AND status='paid' AND DATE(created_at) BETWEEN ? AND ? GROUP BY payment_method");$st->execute([$shopId,$from,$to]);$payments=$st->fetchAll();json_response(['from'=>$from,'to'=>$to,'summary'=>$summary,'top_products'=>$top,'payments'=>$payments]);
    }
    if ($action === 'audit') { require_role(['super_admin','shop_admin']);$shopId=tenant_id($user);$limit=min(300,max(1,(int)($_GET['limit']??100)));$st=$pdo->prepare("SELECT a.id,a.action,a.entity_type,a.entity_id,a.details,a.ip_address,a.created_at,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.shop_id=? ORDER BY a.id DESC LIMIT $limit");$st->execute([$shopId]);json_response(['logs'=>$st->fetchAll()]); }
    if ($action === 'backup' && $method === 'GET') {
        require_role(['super_admin','shop_admin']);
        header('Content-Type: application/sql; charset=utf-8'); header('Content-Disposition: attachment; filename="rafay-pos-backup-'.date('Ymd-His').'.sql"'); header('Cache-Control: no-store');
        $tables=['shops','users','login_attempts','products','inventory_movements','customers','invoices','invoice_items','shop_sequences','audit_logs','subscriptions'];echo "-- Rafay POS database backup\nSET FOREIGN_KEY_CHECKS=0;\n";
        foreach($tables as $table){echo "\n-- $table\nTRUNCATE TABLE `$table`;\n";$rows=$pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $row){$cols=array_map(fn($c)=>"`$c`",array_keys($row));$vals=array_map(function($v)use($pdo){return $v===null?'NULL':$pdo->quote((string)$v);},array_values($row));echo 'INSERT INTO `'.$table.'` ('.implode(',',$cols).') VALUES ('.implode(',',$vals).');'."\n";}}
        echo "\nSET FOREIGN_KEY_CHECKS=1;\n";exit;
    }
    json_response(['error'=>'Unknown action'],404);
} catch(Throwable $e) { error_log($e->__toString()); json_response(['error'=>'Server error. Check the server error log.'],500); }
