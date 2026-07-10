<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/scada_translator.php';

date_default_timezone_set('Europe/Istanbul');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    require_once __DIR__ . '/config.example.php';
}

$gizli_anahtar = GIZLI_ANAHTAR;
$host = DB_HOST;
$db_user = DB_USER;
$db_pass = DB_PASS;
$db_name = DB_NAME;

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(["durum" => "HATA", "mesaj" => "Veritabanı bağlantı hatası"]);
    exit();
}
$conn->set_charset("utf8mb4");

$table_sql = "CREATE TABLE IF NOT EXISTS scada_alarms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alarm_tag VARCHAR(100) NOT NULL,
    aciklama TEXT,
    alarm_saati VARCHAR(50),
    kayit_tarihi DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($table_sql);

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data) {
    echo json_encode(["durum" => "HATA", "mesaj" => "Geçersiz veri formatı"]);
    exit();
}

$anahtar = $data['anahtar'] ?? '';
$tag = $data['tag'] ?? '';
$aciklama = $data['aciklama'] ?? '';
$saat = $data['saat'] ?? '';

if ($anahtar !== $gizli_anahtar) {
    http_response_code(401);
    echo json_encode(["durum" => "HATA", "mesaj" => "Yetkisiz erişim"]);
    exit();
}

$tip = $data['tip'] ?? 'alarm';
if ($tip === 'sistem_logu') {
    $log_verisi = $data['log_verisi'] ?? '';
    file_put_contents(__DIR__ . '/scada_pc_log.txt', $log_verisi);
    echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Sistem logları güncellendi"]);
    $conn->close();
    exit();
}

$simdi_str = date('Y-m-d H:i:s');
$stmt = $conn->prepare("INSERT INTO scada_alarms (alarm_tag, aciklama, alarm_saati, kayit_tarihi) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $tag, $aciklama, $saat, $simdi_str);

if ($stmt->execute()) {
    $inserted_id = $stmt->insert_id;
    echo json_encode(["durum" => "BAŞARILI", "mesaj" => "Alarm kaydedildi", "id" => $inserted_id]);
    
    if (TWILIO_AKTIF) {
        try {
            $bugun_no = intval(date('N')); 
            $simdi_saat_dk = date('H:i'); 
            
            $result = $conn->query("SELECT * FROM scada_contacts WHERE aranma_durumu = 1");
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
                                
                                $trans = translate_scada($tag, $aciklama);
                                $display_tag = $trans['display_tag'];
                                $display_desc = $trans['display_desc'];
                                
                                $mesaj = "Örnek Arıtma Tesisinde yeni bir arıza oluştu. Arıza kodu: " . $display_tag . ". Açıklama: " . $display_desc . ". Lütfen kontrol ediniz.";
                                
                                $simdi_str = date('Y-m-d H:i:s');
                                $conn->query("UPDATE scada_contacts SET son_aranma_zamani = '$simdi_str' WHERE id = " . intval($row['id']));
                                
                                $arama_sonucu = twilio_sesli_ara($tel, $mesaj);
                                
                                if (!$arama_sonucu) {
                                    $conn->query("UPDATE scada_contacts SET son_aranma_zamani = NULL WHERE id = " . intval($row['id']));
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $ex) {
            error_log("Sesli arama hatası: " . $ex->getMessage());
        }
    }
} else {
    echo json_encode(["durum" => "HATA", "mesaj" => "Kayıt sırasında hata oluştu: " . $stmt->error]);
}

$stmt->close();
$conn->close();

function twilio_sesli_ara($telefon, $mesaj) {
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
?>
