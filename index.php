<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/scada_translator.php';

date_default_timezone_set('Europe/Istanbul');

session_start();

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    require_once __DIR__ . '/config.example.php';
}

$host = DB_HOST;
$db_user = DB_USER;
$db_pass = DB_PASS;
$db_name = DB_NAME;

try {
    $conn = new mysqli($host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("<div style='padding: 20px; background:
            <strong>Veritabanı Bağlantı Hatası:</strong> " . $e->getMessage() . "
         </div>");
}

$table_sql = "CREATE TABLE IF NOT EXISTS scada_alarms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alarm_tag VARCHAR(100) NOT NULL,
    aciklama TEXT,
    alarm_saati VARCHAR(50),
    kayit_tarihi DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($table_sql);

$contacts_table_sql = "CREATE TABLE IF NOT EXISTS scada_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_soyad VARCHAR(100) NOT NULL,
    telefon VARCHAR(20) NOT NULL,
    aranma_durumu TINYINT(1) DEFAULT 1,
    program TEXT DEFAULT NULL,
    son_aranma_zamani DATETIME DEFAULT NULL,
    kayit_tarihi DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($contacts_table_sql);

$check_cols = $conn->query("SHOW COLUMNS FROM scada_contacts LIKE 'aranma_durumu'");
if ($check_cols && $check_cols->num_rows == 0) {
    $conn->query("ALTER TABLE scada_contacts ADD COLUMN aranma_durumu TINYINT(1) DEFAULT 1");
    $conn->query("ALTER TABLE scada_contacts ADD COLUMN program TEXT DEFAULT NULL");
}

$check_son_aranma = $conn->query("SHOW COLUMNS FROM scada_contacts LIKE 'son_aranma_zamani'");
if ($check_son_aranma && $check_son_aranma->num_rows == 0) {
    $conn->query("ALTER TABLE scada_contacts ADD COLUMN son_aranma_zamani DATETIME DEFAULT NULL");
}

$correct_password = defined('PANEL_SIFRESI') ? PANEL_SIFRESI : "12345";
$login_error = "";

if (isset($_POST['login_password'])) {
    if ($_POST['login_password'] === $correct_password) {
        $_SESSION['scada_logged_in'] = true;
        header("Location: index.php");
        exit();
    } else {
        $login_error = "Hatalı şifre! Lütfen tekrar deneyin.";
    }
}

if (isset($_GET['logout'])) {
    $_SESSION['scada_logged_in'] = false;
    session_destroy();
    header("Location: index.php");
    exit();
}

$is_logged_in = isset($_SESSION['scada_logged_in']) && $_SESSION['scada_logged_in'] === true;

if (isset($_GET['ajax']) || isset($_GET['action'])) {
    if (!$is_logged_in) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(["hata" => "Yetkisiz erişim. Oturum açılmamış."]);
        exit();
    }
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['ajax'] === 'stats') {
            $top_devices = [];
            $res1 = $conn->query("SELECT aciklama, COUNT(*) as count FROM scada_alarms GROUP BY aciklama ORDER BY count DESC LIMIT 5");
            if ($res1) {
                while ($row = $res1->fetch_assoc()) {
                    $trans = translate_scada("", $row['aciklama']);
                    $row['aciklama_original'] = $row['aciklama'];
                    $row['alarm_tag'] = $trans['display_desc'];
                    $top_devices[] = $row;
                }
            }
            
            $daily_trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date_str = date('Y-m-d', strtotime("-$i days"));
                $daily_trend[$date_str] = 0;
            }
            
            $res2 = $conn->query("
                SELECT DATE(kayit_tarihi) as alarm_date, COUNT(*) as count 
                FROM scada_alarms 
                WHERE kayit_tarihi >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                GROUP BY DATE(kayit_tarihi) 
                ORDER BY alarm_date ASC
            ");
            if ($res2) {
                while ($row = $res2->fetch_assoc()) {
                    $date_key = $row['alarm_date'];
                    if (isset($daily_trend[$date_key])) {
                        $daily_trend[$date_key] = intval($row['count']);
                    }
                }
            }
            
            $trend_data = [];
            foreach ($daily_trend as $tarih => $adet) {
                $formatted_date = date('j M', strtotime($tarih));
                $aylar = ["Jan"=>"Oca", "Feb"=>"Şub", "Mar"=>"Mar", "Apr"=>"Nis", "May"=>"May", "Jun"=>"Haz", "Jul"=>"Tem", "Aug"=>"Ağu", "Sep"=>"Eyl", "Oct"=>"Eki", "Nov"=>"Kas", "Dec"=>"Ara"];
                $parts = explode(' ', $formatted_date);
                if (count($parts) == 2 && isset($aylar[$parts[1]])) {
                    $formatted_date = $parts[0] . ' ' . $aylar[$parts[1]];
                }
                
                $trend_data[] = [
                    "tarih" => $formatted_date,
                    "adet" => $adet
                ];
            }
            
            echo json_encode([
                "top_devices" => $top_devices,
                "daily_trend" => $trend_data
            ]);
            exit();
        } else {
            $result = $conn->query("SELECT * FROM scada_alarms ORDER BY id DESC LIMIT 500");
            $data = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $trans = translate_scada($row['alarm_tag'], $row['aciklama']);
                    $row['display_tag'] = $trans['display_tag'];
                    $row['display_desc'] = $trans['display_desc'];
                    $data[] = $row;
                }
            }
            echo json_encode($data);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(["hata" => $e->getMessage()]);
        exit();
    }
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    try {
        if ($action === 'get_contacts') {
            $result = $conn->query("SELECT * FROM scada_contacts ORDER BY id DESC");
            $contacts = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $contacts[] = $row;
                }
            }
            echo json_encode($contacts);
            exit();
        }
        
        if ($action === 'add_contact') {
            $json_data = file_get_contents('php://input');
            $data = json_decode($json_data, true);
            
            $ad_soyad = trim($data['ad_soyad'] ?? '');
            $telefon = trim($data['telefon'] ?? '');
            
            if (empty($ad_soyad) || empty($telefon)) {
                echo json_encode(["hata" => "Ad Soyad ve Telefon alanları boş bırakılamaz."]);
                exit();
            }
            
            $stmt = $conn->prepare("INSERT INTO scada_contacts (ad_soyad, telefon) VALUES (?, ?)");
            $stmt->bind_param("ss", $ad_soyad, $telefon);
            if ($stmt->execute()) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Kişi rehbere kaydedildi.", "id" => $stmt->insert_id]);
            } else {
                echo json_encode(["hata" => "Kayıt hatası: " . $stmt->error]);
            }
            $stmt->close();
            exit();
        }
        
        if ($action === 'delete_contact') {
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(["hata" => "Geçersiz ID."]);
                exit();
            }
            
            $stmt = $conn->prepare("DELETE FROM scada_contacts WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Kişi rehberden silindi."]);
            } else {
                echo json_encode(["hata" => "Silme hatası: " . $stmt->error]);
            }
            $stmt->close();
            exit();
        }

        if ($action === 'update_schedule') {
            $json_data = file_get_contents('php://input');
            $data = json_decode($json_data, true);
            
            $id = intval($data['id'] ?? 0);
            $aranma_durumu = intval($data['aranma_durumu'] ?? 1);
            $program = $data['program'] ?? null;
            
            if ($id <= 0) {
                echo json_encode(["hata" => "Geçersiz ID."]);
                exit();
            }
            
            $program_str = is_array($program) ? json_encode($program) : strval($program);
            
            $stmt = $conn->prepare("UPDATE scada_contacts SET aranma_durumu = ?, program = ? WHERE id = ?");
            $stmt->bind_param("isi", $aranma_durumu, $program_str, $id);
            if ($stmt->execute()) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Çalışma saatleri güncellendi."]);
            } else {
                echo json_encode(["hata" => "Güncelleme hatası: " . $stmt->error]);
            }
            $stmt->close();
            exit();
        }
        
        if ($action === 'get_callable_now') {
            $bugun_no = intval(date('N')); 
            $simdi_saat_dk = date('H:i'); 
            
            $result = $conn->query("SELECT * FROM scada_contacts WHERE aranma_durumu = 1");
            $callable_contacts = [];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $program = json_decode($row['program'] ?? '[]', true);
                    
                    if ($program && isset($program[strval($bugun_no)])) {
                        $gun_prog = $program[strval($bugun_no)];
                        
                        if (isset($gun_prog['aktif']) && $gun_prog['aktif'] === true) {
                            $baslangic = $gun_prog['baslangic'] ?? '00:00';
                            $bitis = $gun_prog['bitis'] ?? '00:00';
                            
                            if ($simdi_saat_dk >= $baslangic && $simdi_saat_dk <= $bitis) {
                                $callable_contacts[] = [
                                    "id" => $row['id'],
                                    "ad_soyad" => $row['ad_soyad'],
                                    "telefon" => $row['telefon'],
                                    "baslangic" => $baslangic,
                                    "bitis" => $bitis
                                ];
                            }
                        }
                    }
                }
            }
            
            echo json_encode([
                "durum" => "BAŞARILI",
                "tarih" => date('Y-m-d H:i:s'),
                "gun" => $bugun_no,
                "saat" => $simdi_saat_dk,
                "aranabilir_operatorler" => $callable_contacts
            ]);
            exit();
        }

        if ($action === 'test_call') {
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(["hata" => "Geçersiz ID."]);
                exit();
            }
            
            $stmt = $conn->prepare("SELECT * FROM scada_contacts WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $contact = $result->fetch_assoc();
            $stmt->close();
            
            if (!$contact) {
                echo json_encode(["hata" => "Operatör bulunamadı."]);
                exit();
            }
            
            $tel = $contact['telefon'];
            $ad_soyad = $contact['ad_soyad'];
            
            $mesaj = "Merhaba " . $ad_soyad . ". Ornek Atiksu Aritma Tesisi SCADA Panelinden deneme aramasi yapilmaktadir. Bu arama sistemin calistigini test etmek icin gonderilmistir.";
            
            $durum = twilio_sesli_ara_test($tel, $mesaj);
            if ($durum) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Test araması başarıyla başlatıldı. Telefonunuz çalmalıdır."]);
            } else {
                echo json_encode(["hata" => "Arama başarısız oldu. Lütfen Twilio bakiyenizi veya numara doğrulamalarınızı kontrol edin."]);
            }
            exit();
        }

        if ($action === 'send_test_alarm') {
            $tag = "TEST_ALARM";
            $aciklama = "SCADA Arayüzünden Gönderilen Test Alarmı";
            $saat = date('H:i:s');
            $simdi_str = date('Y-m-d H:i:s');
            
            $stmt = $conn->prepare("INSERT INTO scada_alarms (alarm_tag, aciklama, alarm_saati, kayit_tarihi) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $tag, $aciklama, $saat, $simdi_str);
            $stmt->execute();
            $stmt->close();
            
            $bugun_no = intval(date('N')); 
            $simdi_saat_dk = date('H:i'); 
            $result = $conn->query("SELECT * FROM scada_contacts WHERE aranma_durumu = 1");
            $arananlar = [];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $program = json_decode($row['program'] ?? '[]', true);
                    
                    if ($program && isset($program[strval($bugun_no)])) {
                        $gun_prog = $program[strval($bugun_no)];
                        
                        if (isset($gun_prog['aktif']) && $gun_prog['aktif'] === true) {
                            $baslangic = $gun_prog['baslangic'] ?? '00:00';
                            $bitis = $gun_prog['bitis'] ?? '00:00';
                            
                            if ($simdi_saat_dk >= $baslangic && $simdi_saat_dk <= $bitis) {
                                $son_aranma = $row['son_aranma_zamani'];
                                if (!empty($son_aranma)) {
                                    $son_aranma_ts = strtotime($son_aranma);
                                    $simdi_ts = time();
                                    if (($simdi_ts - $son_aranma_ts) < 600) {
                                        continue;
                                    }
                                }
                                
                                $tel = $row['telefon'];
                                $ad_soyad = $row['ad_soyad'];
                                $mesaj = "Örnek Atıksu Arıtma Tesisinde test alarmı tetiklendi. Lütfen sistemi kontrol ediniz.";
                                
                                $simdi_str = date('Y-m-d H:i:s');
                                $conn->query("UPDATE scada_contacts SET son_aranma_zamani = '$simdi_str' WHERE id = " . intval($row['id']));
                                
                                $arama_sonucu = twilio_sesli_ara_test($tel, $mesaj);
                                
                                if ($arama_sonucu) {
                                    $arananlar[] = $ad_soyad;
                                } else {
                                    $conn->query("UPDATE scada_contacts SET son_aranma_zamani = NULL WHERE id = " . intval($row['id']));
                                }
                            }
                        }
                    }
                }
            }
            
            if (count($arananlar) > 0) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Test alarmı başarıyla veritabanına kaydedildi ve şu aktif operatörler arandı: " . implode(', ', $arananlar)]);
            } else {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Test alarmı başarıyla veritabanına kaydedildi ancak şu anda nöbetçi olup aranabilecek (cooldown süresi dolmuş) operatör bulunamadı."]);
            }
            exit();
        }

        if ($action === 'clear_all_alarms') {
            if ($conn->query("DELETE FROM scada_alarms")) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Tüm alarm geçmişi başarıyla temizlendi."]);
            } else {
                echo json_encode(["hata" => "Temizleme hatası: " . $conn->error]);
            }
            exit();
        }

        if ($action === 'delete_alarm') {
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(["hata" => "Geçersiz ID."]);
                exit();
            }
            $stmt = $conn->prepare("DELETE FROM scada_alarms WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Alarm kaydı başarıyla silindi."]);
            } else {
                echo json_encode(["hata" => "Silme hatası: " . $stmt->error]);
            }
            $stmt->close();
            exit();
        }

        if ($action === 'get_scada_log') {
            $log_file = __DIR__ . '/scada_pc_log.txt';
            if (file_exists($log_file)) {
                echo json_encode(["durum" => "BAŞARILI", "log" => file_get_contents($log_file)]);
            } else {
                echo json_encode(["durum" => "HATA", "log" => "Henüz log verisi sunucuya ulaşmadı veya log dosyası bulunamadı."]);
            }
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(["hata" => $e->getMessage()]);
        exit();
    }
}

if (!defined('TWILIO_SID')) {
    define('TWILIO_SID', 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
}
if (!defined('TWILIO_TOKEN')) {
    define('TWILIO_TOKEN', 'your_auth_token_here');
}
if (!defined('TWILIO_FROM')) {
    define('TWILIO_FROM', '+12175698793');
}

function twilio_sesli_ara_test($telefon, $mesaj) {
    $telefon = preg_replace('/[^0-9]/', '', $telefon);
    
    if (strlen($telefon) == 10 && substr($telefon, 0, 1) == '5') {
        $telefon = '+90' . $telefon;
    } elseif (strlen($telefon) == 11 && substr($telefon, 0, 2) == '05') {
        $telefon = '+90' . substr($telefon, 1);
    } elseif (strlen($telefon) == 12 && substr($telefon, 0, 2) == '90') {
        $telefon = '+' . $telefon;
    }
    
    $twiml = '<Response><Say language="tr-TR" voice="Polly.Filiz">' . htmlspecialchars($mesaj) . '</Say></Response>';
    $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Calls.json";
    
    $data = array(
        'To' => $telefon,
        'From' => TWILIO_FROM,
        'Twiml' => $twiml
    );
    
    $post_data = http_build_query($data);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, TWILIO_SID . ":" . TWILIO_TOKEN);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code == 201;
}

$total_alarms = 0;
$count_result = $conn->query("SELECT COUNT(*) as total FROM scada_alarms");
if ($count_result) {
    $row = $count_result->fetch_assoc();
    $total_alarms = $row['total'] ?? 0;
}
?>
<?php if (!$is_logged_in): ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Örnek Atıksu Arıtma Tesisi</title>
    <!-- Sekme Çubuğu ve Apple Ana Ekran Logoları -->
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <link rel="apple-touch-icon" href="logo.jpg">
    <!-- Apple Web App Destekleri -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SCADA Panel">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color:
            --panel-bg:
            --text-color:
            --text-muted:
            --primary:
            --primary-glow: rgba(59, 130, 246, 0.4);
            --primary-hover:
            --accent:
            --border-color:
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        .brand-logo {
            width: 12px;
            height: 12px;
            background-color:
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
            margin-bottom: 0.5rem;
        }
        .brand-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            background: linear-gradient(to right,
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        input[type="password"] {
            width: 100%;
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 0.85rem 1.25rem;
            border-radius: 10px;
            font-size: 1rem;
            outline: none;
            transition: all 0.2s;
        }
        input[type="password"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 10px var(--primary-glow);
        }
        .btn-login {
            width: 100%;
            background-color: var(--primary);
            color: var(--text-color);
            border: none;
            padding: 0.85rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
        }
        .btn-login:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }
        .error-msg {
            color: var(--accent);
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="logo.jpg" alt="Logo" style="width: 80px; height: 80px; border-radius: 50%; border: 2px solid var(--border-color); margin-bottom: 1rem; object-fit: cover; box-shadow: 0 0 15px rgba(255,255,255,0.05);">
        <div class="brand-text">ÖRNEK ATIKSU ARITMA TESİSİ</div>
        <form method="POST" action="index.php">
            <div class="form-group">
                <label for="password">Panel Giriş Şifresi</label>
                <input type="password" id="password" name="login_password" placeholder="•••••" required autofocus>
            </div>
            <button type="submit" class="btn-login">Giriş Yap</button>
            <?php if (!empty($login_error)): ?>
                <div class="error-msg"><?php echo $login_error; ?></div>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
<?php exit(); endif; ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Örnek Atıksu Arıtma Tesisi SCADA Panel</title>
    <!-- Sekme Çubuğu ve Apple Ana Ekran Logoları -->
    <link rel="icon" type="image/jpeg" href="logo.jpg">
    <link rel="apple-touch-icon" href="logo.jpg">
    <!-- Apple Web App Destekleri -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SCADA Panel">
    <!-- Modern & Premium Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color:
            --panel-bg:
            --navbar-bg:
            --text-color:
            --text-muted:
            --primary:
            --primary-glow: rgba(59, 130, 246, 0.4);
            --primary-hover:
            --success:
            --success-glow: rgba(16, 185, 129, 0.4);
            --accent:
            --border-color:
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        
        .navbar {
            background-color: var(--navbar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .navbar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .brand-logo-img {
            width: 32px;
            height: 32px;
            object-fit: cover;
            border-radius: 50%;
            border: 1.5px solid var(--border-color);
            box-shadow: 0 0 8px rgba(255, 255, 255, 0.1);
        }

        .brand-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(to right,
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sys-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--success);
            background: rgba(16, 185, 129, 0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        
        .main-content {
            max-width: 1200px;
            width: 100%;
            margin: 2rem auto;
            padding: 0 1.5rem;
            flex: 1;
        }

        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-color: var(--primary);
        }

        .stat-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-family: 'Outfit', sans-serif;
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--text-color);
            line-height: 1;
            margin-top: 0.25rem;
        }

        
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 280px;
        }

        .search-bar {
            width: 100%;
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 0.85rem 1.25rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }

        .search-bar:focus {
            border-color: var(--primary);
            box-shadow: 0 0 10px var(--primary-glow);
        }

        .btn-action {
            background-color: var(--primary);
            color: var(--text-color);
            border: none;
            padding: 0.85rem 1.75rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
        }

        .btn-action:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-action:active {
            transform: translateY(0);
        }

        
        .table-wrapper {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.2);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background-color: rgba(255, 255, 255, 0.015);
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid var(--border-color);
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
        }

        th:hover {
            color: var(--text-color);
        }

        th.sort-asc::after { content: " ▴"; color: var(--primary); }
        th.sort-desc::after { content: " ▾"; color: var(--primary); }

        td {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
            font-weight: 500;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: rgba(255, 255, 255, 0.01);
        }

        
        .badge-tag {
            background: rgba(59, 130, 246, 0.08);
            color:
            border: 1px solid rgba(59, 130, 246, 0.15);
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
        }

        .no-records {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        
        footer {
            background-color: var(--navbar-bg);
            border-top: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
            text-align: center;
            margin-top: auto;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .developer-tag {
            font-weight: 700;
            color: var(--text-color);
            background: linear-gradient(135deg,
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 0.6; }
            50% { transform: scale(1.1); opacity: 1; box-shadow: 0 0 14px var(--success); }
            100% { transform: scale(0.9); opacity: 0.6; }
        }

        
        @media (max-width: 768px) {
            body {
                padding: 0;
            }

            .main-content {
                margin: 1rem auto;
                padding: 0 1rem;
            }

            
            .table-wrapper {
                border: none;
                background: transparent;
                box-shadow: none;
            }

            table, thead, tbody, th, td, tr {
                display: block;
            }

            thead {
                display: none; 
            }

            tr {
                background-color: var(--panel-bg);
                border: 1px solid var(--border-color);
                border-radius: 16px;
                margin-bottom: 1rem;
                padding: 1.25rem;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            td {
                border: none;
                padding: 0.5rem 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 0.9rem;
            }

            td::before {
                content: attr(data-label);
                font-weight: 700;
                color: var(--text-muted);
                font-size: 0.8rem;
                text-transform: uppercase;
            }

            td:first-child {
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 0.75rem;
                margin-bottom: 0.5rem;
                font-weight: 700;
            }

            .badge-tag {
                padding: 0.2rem 0.5rem;
            }

            .footer-content {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            align-items: start;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 1024px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .contacts-card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .contacts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
        }
        
        .contacts-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
        }
        
        .contacts-count {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
        }
        
        .contact-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .contact-form input {
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }
        
        .contact-form input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 8px var(--primary-glow);
        }
        
        .contacts-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }
        
        .contacts-list::-webkit-scrollbar {
            width: 4px;
        }
        .contacts-list::-webkit-scrollbar-thumb {
            background-color: var(--border-color);
            border-radius: 2px;
        }
        
        .contact-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            border-radius: 10px;
            transition: all 0.2s;
        }
        
        .contact-item:hover {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            text-align: left;
        }
        
        .contact-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .contact-phone {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: monospace;
        }
        
        .btn-delete-contact {
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent);
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-delete-contact:hover {
            background: var(--accent);
            color: white;
        }

        
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(8, 12, 20, 0.8); 
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 100%;
            max-width: 550px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
        }

        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close-modal:hover {
            color: var(--accent);
        }

        
        .schedule-row {
            display: grid;
            grid-template-columns: 100px 80px 1fr;
            gap: 1rem;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
        }

        .schedule-row:last-child {
            border-bottom: none;
        }

        .day-label {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .switch-container {
            display: flex;
            align-items: center;
        }

        
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 22px;
        }

        .switch input { 
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color:
            transition: .3s;
            border-radius: 22px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }

        .time-range {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .time-range input[type="time"] {
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s;
        }

        .time-range input[type="time"]:focus {
            border-color: var(--primary);
        }

        .time-separator {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .global-call-switch {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .global-call-label {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            text-align: left;
        }
        
        .global-call-title {
            font-weight: 700;
            font-size: 0.95rem;
        }
        
        .global-call-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        
        .tabs-container {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .tabs-container::-webkit-scrollbar {
            display: none;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.75rem 1.25rem;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        .tab-btn svg {
            transition: transform 0.2s;
        }
        .tab-btn:hover {
            color: var(--text-color);
            background: rgba(255, 255, 255, 0.03);
        }
        .tab-btn:hover svg {
            transform: scale(1.05);
        }
        .tab-btn.active {
            color: var(--primary);
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.05);
        }
        .tab-content {
            display: none;
            animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .tab-content.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-top: 0.5rem;
        }
        @media (max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        .chart-card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            transition: border-color 0.3s;
        }
        .chart-card:hover {
            border-color: rgba(59, 130, 246, 0.2);
        }
        .chart-header {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .chart-header h4 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-color);
        }
        .chart-header p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .chart-container {
            position: relative;
            width: 100%;
            height: 320px;
        }
    </style>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <!-- Üst Menü Barı (Navbar) -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="#" class="brand">
                <img src="logo.jpg" alt="Logo" class="brand-logo-img">
                <span class="brand-text">ÖRNEK ATIKSU ARITMA TESİSİ</span>
            </a>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div class="sys-status">
                    <span class="status-pulse" style="width: 6px; height: 6px; background-color: var(--success); border-radius: 50%;"></span>
                    Sistem Aktif
                </div>
                <a href="index.php?logout=1" class="btn-logout" style="color: var(--accent); text-decoration: none; font-size: 0.85rem; font-weight: 700; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.4rem 0.8rem; border-radius: 20px; transition: all 0.2s;">Güvenli Çıkış</a>
            </div>
        </div>
    </nav>

    <!-- Ana Panel İçeriği -->
    <div class="main-content">
        
        <!-- İstatistik Kartları -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Toplam Alarm Sayısı</span>
                    <span class="stat-value" id="widget-total"><?php echo $total_alarms; ?></span>
                </div>
            </div>
            <div class="stat-card" style="--primary: var(--success);">
                <div class="stat-info">
                    <span class="stat-label">Son Güncelleme</span>
                    <span class="stat-value" id="widget-time" style="font-size: 1.5rem; font-weight: 700; margin-top: 0.5rem;">--:--:--</span>
                </div>
            </div>
        </div>

        <!-- Sekme Butonları -->
        <div class="tabs-container">
            <button class="tab-btn active" data-tab="tab-alarms">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                Alarm Takip & Rehber
            </button>
            <button class="tab-btn" data-tab="tab-analytics">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Analiz & Grafikler
            </button>
            <button class="tab-btn" data-tab="tab-system-logs">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Gözcü Sistem Logları
            </button>
        </div>

        <!-- Sekme İçerikleri -->
        <!-- Sekme 1: Alarm Takip ve Rehber -->
        <div id="tab-alarms" class="tab-content active">
            <div class="main-grid">
                <!-- Sol Taraf: Alarm Takip Paneli -->
                <div class="left-panel">
                    <!-- Arama ve Yenile Kontrolleri -->
                    <div class="controls-row">
                        <div class="search-wrapper">
                            <input type="text" id="search" class="search-bar" placeholder="Arıza kodu veya açıklamada ara...">
                        </div>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <button id="test-alarm-btn" class="btn-action" style="background-color: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--success); box-shadow: none;">Test Alarmı Gönder</button>
                            <button id="clear-all-btn" class="btn-action" style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--accent); box-shadow: none;">Tümünü Temizle</button>
                            <button id="refresh-btn" class="btn-action">Yenile</button>
                        </div>
                    </div>

                    <!-- Alarmların Tablosu/Kartları -->
                    <div class="table-wrapper">
                        <table id="alarm-table">
                            <thead>
                                <tr>
                                    <th onclick="sortTable(0)">ID</th>
                                    <th onclick="sortTable(1)">Alarm Kodu (Tag)</th>
                                    <th onclick="sortTable(2)">Arıza Açıklaması</th>
                                    <th onclick="sortTable(3)">SCADA Saati</th>
                                    <th onclick="sortTable(4)">Sisteme Kayıt Tarihi</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="table-body">
                                <tr>
                                    <td colspan="6" class="no-records">Yükleniyor...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sağ Taraf: Rehber Yönetim Paneli -->
                <div class="right-panel">
                    <div class="contacts-card">
                        <div class="contacts-header">
                            <h3>Arama Rehberi</h3>
                            <span class="contacts-count" id="contacts-count">0 kişi</span>
                        </div>
                        
                        <!-- Rehber Ekleme Formu -->
                        <form id="contact-form" class="contact-form">
                            <input type="text" id="contact-name" placeholder="Ad Soyad" required>
                            <input type="tel" id="contact-phone" placeholder="Telefon (örn: 05xxxxxxxxx)" required>
                            <button type="submit" class="btn-action" style="width: 100%;">Rehbere Ekle</button>
                        </form>
                        
                        <!-- Rehber Listesi -->
                        <div class="contacts-list" id="contacts-list">
                            <!-- AJAX ile listelenecek -->
                            <div class="no-records" style="padding: 1.5rem; font-size: 0.85rem;">Kayıt bulunamadı.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sekme 2: Analiz & Grafikler -->
        <div id="tab-analytics" class="tab-content">
            <div class="charts-grid">
                <!-- Grafik 1 -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h4>En Sık Arıza Yapan 5 Cihaz</h4>
                        <p>SCADA sisteminde en fazla arıza kaydı oluşturan ilk 5 cihazın alarm adetleri.</p>
                    </div>
                    <div class="chart-container">
                        <canvas id="topDevicesChart"></canvas>
                    </div>
                </div>
                <!-- Grafik 2 -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h4>Son 7 Günlük Arıza Trendi</h4>
                        <p>Son 1 hafta boyunca SCADA sistemine kaydedilen günlük arıza sayısı değişimi.</p>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sekme 3: Gözcü Sistem Logları -->
        <div id="tab-system-logs" class="tab-content">
            <div class="chart-card" style="gap: 1rem;">
                <div class="chart-header" style="flex-direction: row; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h4>SCADA PC Gözcü Logları</h4>
                        <p>Python scriptinin arka plandaki çalışma günlükleri (Her 5 dakikada bir güncellenir)</p>
                    </div>
                    <button id="refresh-logs-btn" class="btn-action" style="padding: 0.5rem 1rem; background-color: var(--primary); border-color: var(--primary); color: var(--text-color); box-shadow: none;">Logları Yenile</button>
                </div>
                <div style="background-color:
                    <pre id="system-logs-console" style="margin: 0; font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; color:
                </div>
            </div>
        </div>

    </div>

    <!-- Saat Ayarlama Modalı -->
    <div id="schedule-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="schedule-modal-title">Çalışma Saatlerini Düzenle</h3>
                <button class="close-modal" id="close-modal-btn">&times;</button>
            </div>
            
            <!-- Global Arama Pasiflik Switchi -->
            <div class="global-call-switch">
                <div class="global-call-label">
                    <span class="global-call-title">Aramaları Etkinleştir</span>
                    <span class="global-call-desc">Bu operatör için sesli arama bildirimlerini tamamen açar/kapatır.</span>
                </div>
                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" id="global-call-active" checked>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            
            <form id="schedule-form">
                <input type="hidden" id="schedule-contact-id">
                
                <!-- 7 Gün Satırları -->
                <div class="schedule-days-container">
                    <!-- Pazartesi -->
                    <div class="schedule-row" data-day="1">
                        <span class="day-label">Pazartesi</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Salı -->
                    <div class="schedule-row" data-day="2">
                        <span class="day-label">Salı</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Çarşamba -->
                    <div class="schedule-row" data-day="3">
                        <span class="day-label">Çarşamba</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Perşembe -->
                    <div class="schedule-row" data-day="4">
                        <span class="day-label">Perşembe</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Cuma -->
                    <div class="schedule-row" data-day="5">
                        <span class="day-label">Cuma</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Cumartesi -->
                    <div class="schedule-row" data-day="6">
                        <span class="day-label">Cumartesi</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                    
                    <!-- Pazar -->
                    <div class="schedule-row" data-day="7">
                        <span class="day-label">Pazar</span>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" class="day-active">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="time-range">
                            <input type="time" class="time-start" value="00:00">
                            <span class="time-separator">-</span>
                            <input type="time" class="time-end" value="23:59">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-action" style="width: 100%; margin-top: 1.5rem;">Programı Kaydet</button>
            </form>
        </div>
    </div>

    <!-- Alt Bilgi Alanı (Footer) -->
    <footer>
        <div class="footer-content" style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
            <img src="logo.jpg" alt="Logo" style="width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border-color); object-fit: cover;">
            <span>© 2026 Örnek Atıksu Arıtma Tesisi SCADA Panel</span>
            <span>Developed by <span class="developer-tag">Oğulcan Durkan</span></span>
        </div>
    </footer>

    <script>
        let currentSort = { column: 0, direction: 'desc' };
        let allAlarms = [];
        let allContacts = [];
        let topDevicesChart = null;
        let trendChart = null;

        document.addEventListener("DOMContentLoaded", () => {
            fetchAlarms();
            fetchContacts();
            
            const tabButtons = document.querySelectorAll(".tab-btn");
            const tabContents = document.querySelectorAll(".tab-content");

            tabButtons.forEach(btn => {
                btn.addEventListener("click", () => {
                    const targetTab = btn.getAttribute("data-tab");

                    tabButtons.forEach(b => b.classList.remove("active"));
                    tabContents.forEach(c => c.classList.remove("active"));

                    btn.classList.add("active");
                    document.getElementById(targetTab).classList.add("active");

                    if (targetTab === 'tab-analytics') {
                        loadAndRenderCharts();
                    }
                    if (targetTab === 'tab-system-logs') {
                        fetchSystemLogs();
                    }
                });
            });

            document.getElementById("refresh-btn").addEventListener("click", () => {
                const btn = document.getElementById("refresh-btn");
                btn.disabled = true;
                btn.innerText = 'Yenileniyor...';
                fetchAlarms().finally(() => {
                    btn.disabled = false;
                    btn.innerText = 'Yenile';
                });
            });

            document.getElementById("refresh-logs-btn").addEventListener("click", () => {
                const btn = document.getElementById("refresh-logs-btn");
                btn.disabled = true;
                btn.innerText = 'Yükleniyor...';
                fetchSystemLogs();
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerText = 'Logları Yenile';
                }, 1000);
            });

            document.getElementById("test-alarm-btn").addEventListener("click", () => {
                if (!confirm("Tüm aktif nöbetçi operatörleri arayacak bir deneme test alarmı veritabanına kaydedilsin mi?")) {
                    return;
                }
                const btn = document.getElementById("test-alarm-btn");
                btn.disabled = true;
                btn.innerText = 'Gönderiliyor...';
                fetch("index.php?action=send_test_alarm")
                    .then(response => response.json())
                    .then(data => {
                        if (data.hata) {
                            alert("Hata: " + data.hata);
                        } else {
                            alert(data.mesaj);
                            fetchAlarms();
                        }
                    })
                    .catch(err => {
                        console.error("Test alarm error:", err);
                        alert("Bağlantı hatası oluştu.");
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerText = 'Test Alarmı Gönder';
                    });
            });

            document.getElementById("clear-all-btn").addEventListener("click", () => {
                if (!confirm("Tüm alarm geçmişini silmek istediğinize emin misiniz? Bu işlem geri alınamaz!")) {
                    return;
                }
                const btn = document.getElementById("clear-all-btn");
                btn.disabled = true;
                btn.innerText = 'Temizleniyor...';
                fetch("index.php?action=clear_all_alarms")
                    .then(response => response.json())
                    .then(data => {
                        if (data.hata) {
                            alert("Hata: " + data.hata);
                        } else {
                            alert(data.mesaj);
                            fetchAlarms();
                        }
                    })
                    .catch(err => {
                        console.error("Clear error:", err);
                        alert("Bağlantı hatası oluştu.");
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerText = 'Tümünü Temizle';
                    });
            });

            document.getElementById("search").addEventListener("input", filterAndRenderTable);

            document.getElementById("contact-form").addEventListener("submit", addContact);
        });

        function loadAndRenderCharts() {
            fetch("index.php?ajax=stats")
                .then(response => response.json())
                .then(data => {
                    if (data.hata) {
                        console.error("Stats Error:", data.hata);
                        return;
                    }
                    renderTopDevicesChart(data.top_devices);
                    renderTrendChart(data.daily_trend);
                })
                .catch(error => {
                    console.error("Grafik verisi yükleme hatası:", error);
                });
        }

        function renderTopDevicesChart(devicesData) {
            const canvas = document.getElementById('topDevicesChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            
            const labels = devicesData.map(d => d.alarm_tag);
            const counts = devicesData.map(d => parseInt(d.count));
            
            if (topDevicesChart) {
                topDevicesChart.destroy();
            }
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(59, 130, 246, 0.85)');
            gradient.addColorStop(1, 'rgba(99, 102, 241, 0.15)');
            
            topDevicesChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Arıza Adedi',
                        data: counts,
                        backgroundColor: gradient,
                        borderColor: '#3b82f6',
                        borderWidth: 1.5,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#101624',
                            titleColor: '#f3f4f6',
                            bodyColor: '#9ca3af',
                            borderColor: '#1e293b',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            titleFont: { family: 'Plus Jakarta Sans', weight: 'bold' },
                            bodyFont: { family: 'Plus Jakarta Sans' }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#9ca3af',
                                font: {
                                    family: 'Plus Jakarta Sans',
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#1e293b'
                            },
                            ticks: {
                                color: '#9ca3af',
                                stepSize: 1,
                                font: {
                                    family: 'Plus Jakarta Sans',
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderTrendChart(trendData) {
            const canvas = document.getElementById('trendChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            
            const labels = trendData.map(t => t.tarih);
            const counts = trendData.map(t => t.adet);
            
            if (trendChart) {
                trendChart.destroy();
            }
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
            gradient.addColorStop(1, 'rgba(16, 185, 129, 0.0)');
            
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Günlük Arıza Sayısı',
                        data: counts,
                        fill: true,
                        backgroundColor: gradient,
                        borderColor: '#10b981',
                        borderWidth: 3,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#080c14',
                        pointBorderWidth: 2.5,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.35
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#101624',
                            titleColor: '#f3f4f6',
                            bodyColor: '#9ca3af',
                            borderColor: '#1e293b',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            titleFont: { family: 'Plus Jakarta Sans', weight: 'bold' },
                            bodyFont: { family: 'Plus Jakarta Sans' }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#9ca3af',
                                font: {
                                    family: 'Plus Jakarta Sans',
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#1e293b'
                            },
                            ticks: {
                                color: '#9ca3af',
                                stepSize: 1,
                                font: {
                                    family: 'Plus Jakarta Sans',
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        }
                    }
                }
            });
        }

        function fetchAlarms() {
            return fetch("index.php?ajax=1")
                .then(response => response.json())
                .then(data => {
                    if(data.hata) {
                        throw new Error(data.hata);
                    }
                    allAlarms = data;
                    document.getElementById("widget-total").innerText = data.length;
                    
                    const simdi = new Date();
                    document.getElementById("widget-time").innerText = simdi.toLocaleTimeString();
                    
                    filterAndRenderTable();
                })
                .catch(error => {
                    console.error("Hata:", error);
                    document.getElementById("table-body").innerHTML = 
                        `<tr><td colspan="5" class="no-records" style="color: var(--accent);">Hata: ${error.message}</td></tr>`;
                });
        }

        function fetchContacts() {
            return fetch("index.php?action=get_contacts")
                .then(response => response.json())
                .then(data => {
                    if (data.hata) {
                        throw new Error(data.hata);
                    }
                    allContacts = data;
                    document.getElementById("contacts-count").innerText = `${data.length} kişi`;
                    renderContacts();
                })
                .catch(error => {
                    console.error("Rehber Hatası:", error);
                    document.getElementById("contacts-list").innerHTML = 
                        `<div class="no-records" style="color: var(--accent); padding: 1.5rem; font-size: 0.85rem;">Hata: ${error.message}</div>`;
                });
        }

        function fetchSystemLogs() {
            const consoleEl = document.getElementById("system-logs-console");
            consoleEl.innerText = "Yükleniyor...";
            fetch("index.php?action=get_scada_log")
                .then(response => response.json())
                .then(data => {
                    if (data.durum === 'BAŞARILI') {
                        consoleEl.innerText = data.log;
                        consoleEl.scrollTop = consoleEl.scrollHeight;
                    } else {
                        consoleEl.innerText = data.log || "Log dosyası henüz sunucuda oluşturulmadı.";
                    }
                })
                .catch(err => {
                    consoleEl.innerText = "Bağlantı hatası oluştu. Loglar alınamadı.";
                });
        }

        function renderContacts() {
            const listDiv = document.getElementById("contacts-list");
            if (allContacts.length === 0) {
                listDiv.innerHTML = '<div class="no-records" style="padding: 1.5rem; font-size: 0.85rem;">Kayıt bulunamadı.</div>';
                return;
            }

            listDiv.innerHTML = allContacts.map(c => {
                const callStatusText = parseInt(c.aranma_durumu) === 1 
                    ? '<span style="color:
                    : '<span style="color:
                
                return `
                    <div class="contact-item" style="flex-direction: column; align-items: stretch; gap: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div class="contact-info">
                                <span class="contact-name">${escapeHtml(c.ad_soyad)}</span>
                                <span class="contact-phone">${escapeHtml(c.telefon)}</span>
                                ${callStatusText}
                            </div>
                            <button class="btn-delete-contact" onclick="deleteContact(${c.id})" style="padding: 0.25rem 0.5rem; font-size: 0.7rem;">Sil</button>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid rgba(255,255,255,0.03); padding-top: 0.5rem;">
                            <button class="btn-action" onclick="testCall(${c.id}, event)" style="padding: 0.4rem 0.8rem; font-size: 0.75rem; border-radius: 6px; box-shadow: none; background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: var(--success);">Test Et</button>
                            <button class="btn-action" onclick="openScheduleModal(${c.id})" style="padding: 0.4rem 0.8rem; font-size: 0.75rem; border-radius: 6px; box-shadow: none;">Saat Ayarları</button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function addContact(event) {
            event.preventDefault();
            const nameInput = document.getElementById("contact-name");
            const phoneInput = document.getElementById("contact-phone");
            
            const payload = {
                ad_soyad: nameInput.value,
                telefon: phoneInput.value
            };

            fetch("index.php?action=add_contact", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.hata) {
                    alert("Kişi eklenirken hata oluştu: " + data.hata);
                } else {
                    nameInput.value = "";
                    phoneInput.value = "";
                    fetchContacts();
                }
            })
            .catch(error => {
                console.error("Hata:", error);
                alert("Bağlantı hatası oluştu.");
            });
        }

        function deleteContact(id) {
            if (!confirm("Bu kişiyi rehberden silmek istediğinize emin misiniz?")) {
                return;
            }

            fetch(`index.php?action=delete_contact&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.hata) {
                    alert("Kişi silinirken hata oluştu: " + data.hata);
                } else {
                    fetchContacts();
                }
            })
            .catch(error => {
                console.error("Hata:", error);
                alert("Bağlantı hatası oluştu.");
            });
        }

        function testCall(id, event) {
            const contact = allContacts.find(c => parseInt(c.id) === parseInt(id));
            if (!contact) return;
            
            if (!confirm(`${contact.ad_soyad} isimli operatöre test araması başlatılsın mı?`)) {
                return;
            }

            const btn = event ? event.target : null;
            let origText = "Test Et";
            if (btn && btn.tagName === 'BUTTON') {
                origText = btn.innerText;
                btn.disabled = true;
                btn.innerText = "Aranıyor...";
            }

            fetch(`index.php?action=test_call&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.hata) {
                    alert("Arama hatası: " + data.hata);
                } else {
                    alert(data.mesaj);
                }
            })
            .catch(error => {
                console.error("Hata:", error);
                alert("Bağlantı hatası oluştu.");
            })
            .finally(() => {
                if (btn && btn.tagName === 'BUTTON') {
                    btn.disabled = false;
                    btn.innerText = origText;
                }
            });
        }

        document.getElementById("close-modal-btn").addEventListener("click", closeScheduleModal);
        
        window.addEventListener("click", (event) => {
            const modal = document.getElementById("schedule-modal");
            if (event.target === modal) {
                closeScheduleModal();
            }
        });

        document.getElementById("schedule-form").addEventListener("submit", saveSchedule);

        function openScheduleModal(contactId) {
            const contact = allContacts.find(c => parseInt(c.id) === parseInt(contactId));
            if (!contact) return;

            document.getElementById("schedule-contact-id").value = contact.id;
            document.getElementById("schedule-modal-title").innerText = `${contact.ad_soyad} - Çalışma Programı`;
            
            document.getElementById("global-call-active").checked = parseInt(contact.aranma_durumu) === 1;

            let program = {};
            try {
                program = JSON.parse(contact.program) || {};
            } catch (e) {
                program = {};
            }

            const rows = document.querySelectorAll(".schedule-row");
            rows.forEach(row => {
                const day = row.getAttribute("data-day");
                const dayActiveInput = row.querySelector(".day-active");
                const timeStartInput = row.querySelector(".time-start");
                const timeEndInput = row.querySelector(".time-end");

                const dayData = program[day] || { aktif: false, baslangic: "00:00", bitis: "23:59" };

                dayActiveInput.checked = dayData.aktif === true;
                timeStartInput.value = dayData.baslangic || "00:00";
                timeEndInput.value = dayData.bitis || "23:59";
                
                toggleTimeInputs(row, dayData.aktif === true);

                dayActiveInput.onchange = function() {
                    toggleTimeInputs(row, this.checked);
                };
            });

            document.getElementById("schedule-modal").classList.add("active");
        }

        function closeScheduleModal() {
            document.getElementById("schedule-modal").classList.remove("active");
        }

        function toggleTimeInputs(row, isActive) {
            const timeRangeInputs = row.querySelectorAll(".time-range input");
            timeRangeInputs.forEach(input => {
                input.disabled = !isActive;
                input.style.opacity = isActive ? "1" : "0.3";
            });
        }

        function saveSchedule(event) {
            event.preventDefault();
            const id = document.getElementById("schedule-contact-id").value;
            const aranma_durumu = document.getElementById("global-call-active").checked ? 1 : 0;
            
            const program = {};
            const rows = document.querySelectorAll(".schedule-row");
            rows.forEach(row => {
                const day = row.getAttribute("data-day");
                const dayActive = row.querySelector(".day-active").checked;
                const timeStart = row.querySelector(".time-start").value;
                const timeEnd = row.querySelector(".time-end").value;

                program[day] = {
                    aktif: dayActive,
                    baslangic: timeStart,
                    bitis: timeEnd
                };
            });

            const payload = {
                id: id,
                aranma_durumu: aranma_durumu,
                program: program
            };

            fetch("index.php?action=update_schedule", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.hata) {
                    alert("Program kaydedilirken hata oluştu: " + data.hata);
                } else {
                    closeScheduleModal();
                    fetchContacts();
                }
            })
            .catch(error => {
                console.error("Hata:", error);
                alert("Bağlantı hatası oluştu.");
            });
        }

        function escapeHtml(str) {
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&
        }

        function filterAndRenderTable() {
            const query = document.getElementById("search").value.toLowerCase();
            const tbody = document.getElementById("table-body");
            
            let filtered = allAlarms.filter(item => {
                const tagSearch = (item.display_tag || item.alarm_tag).toLowerCase();
                const descSearch = (item.display_desc || item.aciklama).toLowerCase();
                return (
                    tagSearch.includes(query) ||
                    descSearch.includes(query) ||
                    item.alarm_saati.toLowerCase().includes(query)
                );
            });

            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="no-records">Aranan kritere uygun alarm kaydı bulunamadı.</td></tr>';
                return;
            }

            filtered.sort((a, b) => {
                let key = Object.keys(a)[currentSort.column];
                let valA = a[key];
                let valB = b[key];

                if (currentSort.column === 1) {
                    valA = a.display_tag || a.alarm_tag;
                    valB = b.display_tag || b.alarm_tag;
                } else if (currentSort.column === 2) {
                    valA = a.display_desc || a.aciklama;
                    valB = b.display_desc || b.aciklama;
                }

                if (currentSort.column === 0) {
                    return currentSort.direction === 'asc' ? parseInt(valA) - parseInt(valB) : parseInt(valB) - parseInt(valA);
                }

                valA = valA.toLowerCase();
                valB = valB.toLowerCase();
                if (valA < valB) return currentSort.direction === 'asc' ? -1 : 1;
                if (valA > valB) return currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });

            tbody.innerHTML = filtered.map(row => `
                <tr>
                    <td data-label="ID">${row.id}</td>
                    <td data-label="Alarm Kodu"><span class="badge-tag" title="Orijinal: ${row.alarm_tag}">${row.display_tag || row.alarm_tag}</span></td>
                    <td data-label="Arıza Açıklaması" style="font-weight: 600; color: var(--text-color);" title="Orijinal: ${row.aciklama}">${row.display_desc || row.aciklama}</td>
                    <td data-label="SCADA Saati">${row.alarm_saati}</td>
                    <td data-label="Sisteme Kayıt" style="color: var(--text-muted);">${row.kayit_tarihi}</td>
                    <td data-label="İşlem">
                        <button class="btn-delete-contact" onclick="deleteAlarm(${row.id})" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Sil</button>
                    </td>
                </tr>
            `).join('');
        }

        function sortTable(columnIndex) {
            const ths = document.querySelectorAll("th");
            ths.forEach((th, idx) => {
                if (idx === columnIndex) {
                    if (currentSort.column === columnIndex) {
                        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort.column = columnIndex;
                        currentSort.direction = 'asc';
                    }
                    th.className = currentSort.direction === 'asc' ? 'sort-asc' : 'sort-desc';
                } else {
                    th.className = '';
                }
            });
            filterAndRenderTable();
        }

        function deleteAlarm(id) {
            if (!confirm("Bu alarm kaydını silmek istediğinize emin misiniz?")) {
                return;
            }
            fetch(`index.php?action=delete_alarm&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.hata) {
                    alert("Silme hatası: " + data.hata);
                } else {
                    fetchAlarms();
                }
            })
            .catch(error => {
                console.error("Hata:", error);
                alert("Bağlantı hatası oluştu.");
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
