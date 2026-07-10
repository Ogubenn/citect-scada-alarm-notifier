# 1. Adım: SCADA Loglarını Web Veritabanına Yazma ve İzleme Paneli (PHP)

Bu adımda, eski SCADA bilgisayarınıza hiçbir ek kütüphane kurmadan, tüm sistemi **Kebirhost paylaşımlı hosting sunucunuz** üzerinde çalışacak şekilde yapılandırıyoruz.

---

* **`scada_takip.py`:** SCADA bilgisayarında çalışıp alarmları web sitenize gönderen hafif Python kodu.
* **`alarm-al.php`:** Kebirhost sunucunuzda çalışan, Python'dan gelen alarmları alıp veritabanına kaydeden API kodu.
* **`index.php`:** Web tarayıcınızdan (veya telefondan) girip alarmları görebileceğiniz filtreli, sıralamalı ve yenileme butonlu modern web arayüzü (Panel).

---

## 2. Kurulum Adımları

### A) Kebirhost Sunucunuzda (Web Sunucusu)
1. Kebirhost müşteri panelinize giriş yapıp **Dosya Yöneticisi'ni (File Manager)** açın.
2. **`public_html`** klasörünün (web sitenizin ana klasörü) içine girin.
3. İsterseniz burada `scada` adında yeni bir klasör oluşturun (örneğin: `public_html/scada`), ya da direkt ana dizine atın.
4. Bilgisayarınızdaki **`alarm-al.php`** ve **`index.php`** dosyalarını bu klasörün içine yükleyin (Upload).

> [!NOTE]
> Tablo oluşturma sorgusu otomatik olarak kodun içine entegre edilmiştir. Dosyaları yükleyip ilk kez `index.php` sayfasını tarayıcınızda açtığınızda veritabanı tablonuz (`scada_alarms`) **otomatik olarak kendiliğinden kurulacaktır.**

---

### B) SCADA Bilgisayarında (Python İstemcisi)
1. **`AlarmSystem/scada_takip.py`** dosyasını Not Defteri ile açın.
2. En üstteki `SUNUCU_URL` satırını kontrol edin:
   `SUNUCU_URL = "https://scadaalarm.siteniz.com/alarm-al.php"`
3. Dosyayı kaydedin.
4. SCADA bilgisayarında komut satırını (`cmd`) açıp gözcüyü başlatın:
   ```bash
   python scada_takip.py
   ```

---

## 3. Alarmları İzleme ve Yenileme
Artık internete bağlı olan herhangi bir telefondan, tabletten veya bilgisayardan tarayıcınızı açıp alarmları canlı olarak izleyebilirsiniz:
* **Adres:** `https://scadaalarm.siteniz.com/index.php`

Açılan modern arayüzde:
* **Yenile Butonu:** Sayfayı yenilemeden veritabanından en son alarmları anlık çeker.
* **Arama Çubuğu:** Sadece belirli bir cihaz tag'ini (`dekan`, `blower`, `MS02` vb.) yazarak alarmları anında filtreler.
* **Sıralama:** Kolon başlıklarına (ID, Tag, Saat vb.) tıklayarak listeyi a-z veya z-a şeklinde sıralar.

