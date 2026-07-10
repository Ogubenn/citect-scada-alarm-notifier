# SCADA Telefon Arama Sistemi

Bu proje, Vijeo Citect SCADA sisteminde oluşan aktif arızaları izleyen ve nöbetçi operatörleri cep telefonlarından sesli olarak arayarak arıza bildiren bir otomasyon sistemidir.

## Proje Amacı
Arıtma tesisleri gibi kritik altyapılarda internet kopmaları veya elektrik kesintileri yaşansa dahi, SCADA sistemindeki alarmları kaçırmadan ilgili operatörlere anında sesli bildirim (Twilio API entegrasyonu ile) ulaştırmak.

## Öne Çıkan Özellikler
*   **Canlı Takip:** SCADA PC'deki alarm loglarını gerçek zamanlı izleme.
*   **Akıllı Nöbet Sistemi:** Operatörler için haftalık nöbet günleri ve saat aralıkları tanımlama.
*   **Operatör Cooldown:** Peş peşe gelen alarmlarda operatörün tekrar tekrar aranmasını engelleyen 10 dakikalık koruma.
*   **Çevrimdışı Kuyruk:** İnternet koptuğunda alarmları yerelde biriktirip internet geri geldiğinde toplu olarak iletme.
*   **Zaman Filtresi:** SCADA PC saati ile alarm saati uyuşmayan (10 dakikadan eski) geçmiş alarmları es geçme.
*   **Web Log İzleme:** SCADA bilgisayarındaki arka plan çalışma loglarını doğrudan web panelinden izleyebilme.

## Nasıl Çalışır?
1.  **SCADA Gözcüsü (`scada_takip.py`):** SCADA bilgisayarında arka planda gizlice çalışır ve alarm log dosyasını izler. Yeni alarm algıladığında web sunucusuna iletir.
2.  **Web API (`alarm-al.php`):** SCADA PC'den gelen alarmları doğrular, veritabanına kaydeder ve Twilio üzerinden nöbetçi operatörü arar.
3.  **Yönetim Paneli (`index.php`):** Nöbet listesini ayarladığınız, alarm geçmişini izlediğiniz ve gözcü loglarını canlı okuduğunuz modern web arayüzüdür.
