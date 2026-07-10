<?php
if (!date_default_timezone_set(date_default_timezone_get())) {
    date_default_timezone_set('Europe/Istanbul');
}

function translate_scada($tag, $description = "") {
    $tag = trim($tag);
    $description = trim($description);
    
    $exact_tags = [
        "Tm2_P1_F_OP" => "TM2 Terfi İstasyonu Pompa 1 Arızası",
        "Tm2_P2_F_OP" => "TM2 Terfi İstasyonu Pompa 2 Arızası",
        "Tm2_P3_F_OP" => "TM2 Terfi İstasyonu Pompa 3 Arızası",
        "Tm1_P1_F_OP" => "TM1 Terfi İstasyonu Pompa 1 Arızası",
        "Tm1_P2_F_OP" => "TM1 Terfi İstasyonu Pompa 2 Arızası",
        "Tm1_P3_F_OP" => "TM1 Terfi İstasyonu Pompa 3 Arızası",
        "Tm3_P1_F_OP" => "TM3 Terfi İstasyonu Pompa 1 Arızası",
        "Tm3_P2_F_OP" => "TM3 Terfi İstasyonu Pompa 2 Arızası",
        "Tm3_P3_F_OP" => "TM3 Terfi İstasyonu Pompa 3 Arızası",
        
        "MS02_1_01_K_ARIZ" => "MS02 Grit Yağ Giderici Köprü Arızası",
        "MS02_1_01_RUN"    => "MS02 Grit Yağ Giderici Çalışıyor",
        "MS02_1_01_STOP"   => "MS02 Grit Yağ Giderici Durdu"
    ];

    if (isset($exact_tags[$tag])) {
        return [
            "tag" => $tag,
            "display_tag" => $exact_tags[$tag],
            "display_desc" => $exact_tags[$tag]
        ];
    }
    
    $dictionary = [
        "LIFTING PUMP" => "Terfi Pompası",
        "LIFT PUMP" => "Terfi Pompası",
        "FEED PUMP" => "Besleme Pompası",
        "SLUDGE PUMP" => "Çamur Pompası",
        "DOSING PUMP" => "Dozaj Pompası",
        "DRAINAGE PUMP" => "Drenaj Pompası",
        "BOOSTER PUMP" => "Hidrofor Pompası",
        "PUMP" => "Pompası",
        "BLOWER" => "Blower / Körük",
        "VALVE" => "Vana",
        "CONVEYOR" => "Konveyör / Taşıyıcı",
        "DECANTER" => "Dekantör",
        "CENTRIFUGE" => "Santrifüj",
        "SCREEN" => "Izgara",
        "BAR SCREEN" => "Kaba/İnce Izgara",
        "COARSE SCREEN" => "Kaba Izgara",
        "FINE SCREEN" => "İnce Izgara",
        "MIXER" => "Mikser / Karıştırıcı",
        "AGITATOR" => "Karıştırıcı",
        "COMPRESSOR" => "Kompresör",
        "GENERATOR" => "Jeneratör",
        "ACTUATOR" => "Aktüatör",
        
        "GRIT AND GREASE" => "Kum ve Yağ",
        "GRIT & GREASE" => "Kum ve Yağ",
        "REMOVAL" => "Giderici",
        "REMOV" => "Giderici",
        "INLET" => "Giriş",
        "OUTLET" => "Çıkış",
        "AERATION" => "Havalandırma",
        "SEDIMENTATION" => "Çöktürme",
        "CLARIFIER" => "Son Çöktürme",
        "DISINFECTION" => "Dezenfeksiyon",
        "SLUDGE DEWATERING" => "Çamur Susuzlaştırma",
        "TM2" => "TM2 Terfi",
        "TM1" => "TM1 Terfi",
        "TM3" => "TM3 Terfi",
        
        "EMERGENCY STOP" => "Acil Durdurma Basılı",
        "EMERGENCY" => "Acil Durum",
        "OVERLOAD" => "Aşırı Yük / Termik",
        "COMMUNICATION" => "Haberleşme Hatası",
        "COMM FAIL" => "Haberleşme Hatası",
        "COMM ERROR" => "Haberleşme Hatası",
        "COMM" => "Haberleşme",
        "OFFLINE" => "Çevrimdışı",
        "NO COMM" => "Haberleşme Yok",
        "POWER FAIL" => "Enerji Kesintisi",
        "PHASE FAIL" => "Faz Hatası",
        "FAULT" => "Arızası",
        "TRIP" => "Termik Atması (Trip)",
        "FAILURE" => "Arızası",
        "FAIL" => "Arızası",
        "ERROR" => "Hatası",
        "ALARM" => "Alarmı",
        "WARNING" => "Uyarısı",
        
        "HIGH LEVEL" => "Yüksek Seviye",
        "LOW LEVEL" => "Düşük Seviye",
        "HIGH-HIGH" => "Çok Yüksek",
        "LOW-LOW" => "Çok Düşük",
        "HIGH" => "Yüksek",
        "LOW" => "Düşük",
        "LEVEL" => "Seviye",
        "FLOW" => "Akış / Debi",
        "PRESSURE" => "Basınç",
        "TEMPERATURE" => "Sıcaklık",
        "TEMP" => "Sıcaklık",
        "LIMIT SWITCH" => "Sınır Anahtarı",
        "OPEN LIMIT" => "Açık Sınır Hatası",
        "CLOSE LIMIT" => "Kapalı Sınır Hatası",
        "RUNNING" => "Çalışıyor",
        "STOPPED" => "Durdu",
        "STOP" => "Durdu",
    ];

    $translated_desc = $description;
    if (!empty($translated_desc)) {
        $translated_desc = mb_strupper($translated_desc, 'UTF-8');
        
        foreach ($dictionary as $eng => $tur) {
            $eng_upper = mb_strupper($eng, 'UTF-8');
            $translated_desc = str_replace($eng_upper, $tur, $translated_desc);
        }
        
        $translated_desc = preg_replace('/\s+/', ' ', $translated_desc);
        $translated_desc = trim($translated_desc);
    } else {
        $translated_desc = "Detay Belirtilmemiş Arıza";
    }

    $translated_tag = $tag;
    $translated_tag = str_replace('_', ' ', $translated_tag);
    $translated_tag = mb_strupper($translated_tag, 'UTF-8');

    foreach ($dictionary as $eng => $tur) {
        $eng_upper = mb_strupper($eng, 'UTF-8');
        $translated_tag = preg_replace('/\b' . preg_quote($eng_upper, '/') . '\b/', $tur, $translated_tag);
    }

    $cleanups = [
        " F OP" => " Arızası",
        " F" => " Arızası",
        " OP" => "",
        " ARIZ" => " Arızası",
        " RUN" => " Çalışıyor",
        " STOP" => " Durdu",
        " TRIP" => " Termik Atması"
    ];
    foreach ($cleanups as $key => $val) {
        $translated_tag = str_replace($key, $val, $translated_tag);
    }

    $translated_tag = preg_replace('/\s+/', ' ', $translated_tag);
    $translated_tag = trim($translated_tag);

    return [
        "tag" => $tag,
        "display_tag" => $translated_tag,
        "display_desc" => $translated_desc
    ];
}

if (!function_exists('mb_strupper')) {
    function mb_strupper($str) {
        $str = str_replace(['i', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['İ', 'I', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'], $str);
        return strtoupper($str);
    }
}
?>
