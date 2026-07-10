import time
import os
import glob
import urllib.request
import json
import ssl

_original_print = print

def print(mesaj, *args, **kwargs):
    if args:
        mesaj_str = str(mesaj) + " " + " ".join(map(str, args))
    else:
        mesaj_str = str(mesaj)
        
    zaman = time.strftime("%Y-%m-%d %H:%M:%S")
    log_satiri = f"[{zaman}] {mesaj_str}"
    
    _original_print(log_satiri, **kwargs)
    
    log_dosya_yolu = os.path.join(os.path.dirname(os.path.abspath(__file__)), "scada_takip_log.txt")
    try:
        if os.path.exists(log_dosya_yolu) and os.path.getsize(log_dosya_yolu) > 1024 * 1024:
            mod = "w"
        else:
            mod = "a"
            
        with open(log_dosya_yolu, mod, encoding="utf-8") as f:
            f.write(log_satiri + "\n")
    except Exception:
        pass

SUNUCU_URL = "http://scadaalarm.example.com/alarm-al.php"
GIZLI_ANAHTAR = "AritmaTesisGuvenlikKodu123"
LOG_KLASORU_VARSAYILAN = ""

config_yolu = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")
if not os.path.exists(config_yolu):
    config_yolu = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json.example")

if os.path.exists(config_yolu):
    try:
        with open(config_yolu, "r", encoding="utf-8") as f:
            config = json.load(f)
            SUNUCU_URL = config.get("SUNUCU_URL", SUNUCU_URL)
            GIZLI_ANAHTAR = config.get("GIZLI_ANAHTAR", GIZLI_ANAHTAR)
            LOG_KLASORU_VARSAYILAN = config.get("LOG_KLASORU", "")
    except Exception as e:
        _original_print(f"[UYARI] Yapılandırma dosyası okunamadı: {e}")

LOG_KLASORU = LOG_KLASORU_VARSAYILAN if (LOG_KLASORU_VARSAYILAN and os.path.exists(LOG_KLASORU_VARSAYILAN)) else r"C:\ProgramData\Schneider Electric\Vijeo Citect 7.50\Data"
if not os.path.exists(LOG_KLASORU):
    LOG_KLASORU = r"C:\ProgramData\AVEVA\Plant SCADA\Data"

if not os.path.exists(LOG_KLASORU):
    kendi_klasor_yolumuz = os.path.dirname(os.path.abspath(__file__))
    if glob.glob(os.path.join(kendi_klasor_yolumuz, "Alarm_LOG.*")):
        LOG_KLASORU = kendi_klasor_yolumuz
    else:
        LOG_KLASORU = os.path.dirname(kendi_klasor_yolumuz)

OFFLINE_QUEUE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "offline_queue.txt")

def saniyeye_cevir(saat_str):
    try:
        saat_str = saat_str.strip()
        parcalar = saat_str.split(':')
        if len(parcalar) == 3:
            h, m, s = map(int, parcalar)
            return h * 3600 + m * 60 + s
    except Exception:
        pass
    return None

def sunucuya_gonder(tag, aciklama, saat):
    payload = json.dumps({
        "anahtar": GIZLI_ANAHTAR,
        "tag": tag,
        "aciklama": aciklama,
        "saat": saat
    }).encode('utf-8')
    
    req = urllib.request.Request(
        SUNUCU_URL,
        data=payload,
        headers={
            'Content-Type': 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
    )
    
    try:
        context = ssl._create_unverified_context()
        with urllib.request.urlopen(req, timeout=5, context=context) as response:
            if response.status == 200:
                print(f"[BAŞARILI] Sunucuya iletildi: {tag} - {aciklama}")
                return True
    except Exception as e:
        print(f"[BAĞLANTI HATASI] Sunucuya erişilemedi: {e}")
    return False

def yerel_kuyruga_yaz(tag, aciklama, saat):
    try:
        with open(OFFLINE_QUEUE_FILE, "a", encoding="utf-8") as f:
            f.write(f"{tag}|{aciklama}|{saat}\n")
        print(f"[ÇEVRİMDIŞI YEDEK] Sunucuya erişilemedi. Alarm yerel kuyruğa alındı: {tag}")
    except Exception as e:
        print(f"[YEREL DOSYA HATASI] Çevrimdışı kuyruğa yazılamadı: {e}")

def yerel_kuyrugu_gonder():
    if not os.path.exists(OFFLINE_QUEUE_FILE):
        return

    if os.path.getsize(OFFLINE_QUEUE_FILE) == 0:
        return

    print("\n[SİSTEM] Sunucu bağlantısı tekrar denetleniyor, kuyruktaki alarmlar gönderiliyor...")
    
    basarili_sayisi = 0
    basarisiz_satirlar = []

    try:
        with open(OFFLINE_QUEUE_FILE, "r", encoding="utf-8") as f:
            satirlar = f.readlines()

        for satir in satirlar:
            satir = satir.strip()
            if not satir:
                continue
            
            parcalar = satir.split('|')
            if len(parcalar) == 3:
                tag, aciklama, saat = parcalar
                if sunucuya_gonder(tag, aciklama, saat):
                    basarili_sayisi += 1
                else:
                    basarisiz_satirlar.append(satir)
            else:
                basarisiz_satirlar.append(satir)

        if basarisiz_satirlar:
            with open(OFFLINE_QUEUE_FILE, "w", encoding="utf-8") as f:
                for line in basarisiz_satirlar:
                    f.write(line + "\n")
            print(f"[BİLGİ] Çevrimdışı kuyruktan {basarili_sayisi} alarm aktarıldı. Hatalı/Gönderilemeyen {len(basarisiz_satirlar)} alarm kuyrukta bekliyor.")
        else:
            os.remove(OFFLINE_QUEUE_FILE)
            print(f"[BAŞARILI] Çevrimdışı kuyruktaki tüm alarmlar ({basarili_sayisi} adet) sunucuya başarıyla gönderildi.")

    except Exception as e:
        print(f"[HATA] Kuyruk gönderme işlemi başarısız: {e}")

def log_sunucuya_gonder():
    log_dosya_yolu = os.path.join(os.path.dirname(os.path.abspath(__file__)), "scada_takip_log.txt")
    if not os.path.exists(log_dosya_yolu):
        return
    
    try:
        with open(log_dosya_yolu, "r", encoding="utf-8") as f:
            satirlar = f.readlines()
        
        son_satirlar = satirlar[-100:]
        log_icerik = "".join(son_satirlar)
        
        payload = json.dumps({
            "anahtar": GIZLI_ANAHTAR,
            "tip": "sistem_logu",
            "log_verisi": log_icerik
        }).encode('utf-8')
        
        req = urllib.request.Request(
            SUNUCU_URL,
            data=payload,
            headers={
                'Content-Type': 'application/json',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            }
        )
        
        context = ssl._create_unverified_context()
        with urllib.request.urlopen(req, timeout=5, context=context) as response:
            if response.status == 200:
                _original_print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] [BAŞARILI] Sistem logları sunucuya senkronize edildi.")
    except Exception as e:
        _original_print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] [HATA] Sistem logları sunucuya gönderilemedi: {e}")

def log_dosyalari_listele():
    desen = os.path.join(LOG_KLASORU, "Alarm_LOG*")
    return glob.glob(desen)

def log_dosyalarini_izle():
    print("====================================================")
    print("      ATIKSU SCADA ALARM AKTARIM GÖZCÜSÜ (V3.0)     ")
    print("     * ÇOKLU DOSYA DİNAMİK TAKİBİ & YEDEKLEME *     ")
    print("====================================================")
    print(f"[INFO] İzlenen Klasör: {LOG_KLASORU}")
    print(f"[INFO] Sunucu Adresi: {SUNUCU_URL}")
    
    yerel_kuyrugu_gonder()
    
    dosya_durumlari = {}
    
    mevcut_dosyalar = log_dosyalari_listele()
    for dosya in mevcut_dosyalar:
        try:
            dosya_durumlari[dosya] = os.path.getsize(dosya)
            print(f"[INFO] Mevcut dosya takibe alındı (Başlangıç konumu: {dosya_durumlari[dosya]} byte): {os.path.basename(dosya)}")
        except Exception as e:
            print(f"[HATA] Dosya boyutu alınamadı ({dosya}): {e}")
            
    son_kontrol_zamani = time.time()
    son_log_gonderim_zamani = 0
    
    while True:
        try:
            simdi = time.time()
            if simdi - son_kontrol_zamani > 5:
                son_kontrol_zamani = simdi
                yerel_kuyrugu_gonder()
                
            if simdi - son_log_gonderim_zamani > 300:
                son_log_gonderim_zamani = simdi
                log_sunucuya_gonder()
            
            guncel_dosyalar = log_dosyalari_listele()
            
            for dosya in guncel_dosyalar:
                if dosya not in dosya_durumlari:
                    dosya_durumlari[dosya] = 0
                    print(f"\n[SİSTEM] Yeni log dosyası tespit edildi: {os.path.basename(dosya)}")
                
                try:
                    mevcut_boyut = os.path.getsize(dosya)
                except OSError:
                    continue
                
                son_konum = dosya_durumlari[dosya]
                
                if mevcut_boyut > son_konum:
                    try:
                        with open(dosya, "r", encoding="windows-1254", errors="ignore") as f:
                            f.seek(son_konum)
                            for satir in f:
                                alarm_metni = satir.strip()
                                if alarm_metni:
                                    if "BufPool" in alarm_metni or "Query" in alarm_metni:
                                        continue
                                    
                                    parcalar = alarm_metni.split(',')
                                    if len(parcalar) >= 3:
                                        tag = parcalar[0].strip()
                                        aciklama = parcalar[1].strip()
                                        saat = parcalar[2].strip()
                                    else:
                                        tag = "DIS_KAYNAK"
                                        aciklama = alarm_metni
                                        saat = time.strftime("%H:%M:%S")
                                    
                                    simdi_saat = time.strftime("%H:%M:%S")
                                    alarm_sn = saniyeye_cevir(saat)
                                    simdi_sn = saniyeye_cevir(simdi_saat)
                                    
                                    if alarm_sn is not None and simdi_sn is not None:
                                        fark = abs(simdi_sn - alarm_sn)
                                        if fark > 43200:
                                            fark = 86400 - fark
                                        
                                        if fark > 600:
                                            print(f"[TARİHSEL ALARM ES GEÇİLDİ] Gecikmis alarm gonderilmedi (Saat farki: {fark} sn): {tag} - {saat}")
                                            continue

                                    if not sunucuya_gonder(tag, aciklama, saat):
                                        yerel_kuyruga_yaz(tag, aciklama, saat)
                            
                            dosya_durumlari[dosya] = f.tell()
                    except Exception as e:
                        print(f"[HATA] Dosya okunurken hata oluştu ({os.path.basename(dosya)}): {e}")
                
                elif mevcut_boyut < son_konum:
                    print(f"\n[SİSTEM] Log dosyası boyutu küçüldü (yeniden yazılıyor olabilir): {os.path.basename(dosya)}")
                    dosya_durumlari[dosya] = 0
            
            time.sleep(1)
            
        except KeyboardInterrupt:
            print("\nGözcü kapatılıyor...")
            break
        except Exception as e:
            print(f"[CRITICAL ERROR] Beklenmeyen sistem hatası: {e}")
            time.sleep(5)

if __name__ == "__main__":
    log_dosyalarini_izle()
