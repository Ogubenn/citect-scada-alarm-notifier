const express = require('express');
const mysql = require('mysql2/promise');

const app = express();
app.use(express.json());

const PORT = 3000;
const fs = require('fs');
const path = require('path');

let config = {
    GIZLI_ANAHTAR: "AritmaTesisGuvenlikKodu123",
    DB_HOST: "localhost",
    DB_USER: "root",
    DB_PASS: "password",
    DB_NAME: "scada_db"
};

const configPath = path.join(__dirname, 'config.json');
const configExamplePath = path.join(__dirname, 'config.json.example');
let activeConfigPath = fs.existsSync(configPath) ? configPath : (fs.existsSync(configExamplePath) ? configExamplePath : null);

if (activeConfigPath) {
    try {
        const raw = fs.readFileSync(activeConfigPath, 'utf8');
        const parsed = JSON.parse(raw);
        config = { ...config, ...parsed };
    } catch (e) {
        console.warn("[UYARI] Yapılandırma dosyası yüklenemedi:", e.message);
    }
}

const GIZLI_ANAHTAR = config.GIZLI_ANAHTAR;

const pool = mysql.createPool({
    host: config.DB_HOST,
    user: config.DB_USER,
    password: config.DB_PASS,
    database: config.DB_NAME,
    port: 3306,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

async function tabloyuHazirla() {
    try {
        const connection = await pool.getConnection();
        await connection.query(`
            CREATE TABLE IF NOT EXISTS scada_alarms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alarm_tag VARCHAR(100) NOT NULL,
                aciklama TEXT,
                alarm_saati VARCHAR(50),
                kayit_tarihi DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        `);
        connection.release();
        console.log("[SİSTEM] MariaDB 'scada_alarms' tablosu hazır.");
    } catch (error) {
        console.error("[HATA] Veritabanı tablosu oluşturulurken hata:", error.message);
    }
}

app.post('/api/alarm-al', async (req, res) => {
    const { anahtar, tag, aciklama, saat } = req.body;

    if (anahtar !== GIZLI_ANAHTAR) {
        console.log("[UYARI] Yetkisiz erişim denemesi!");
        return res.status(401).send("Yetkisiz Erişim");
    }

    console.log(`\nYeni Alarm Geldi: ${tag} - ${aciklama} (Saat: ${saat})`);

    try {
        const [result] = await pool.query(
            `INSERT INTO scada_alarms (alarm_tag, aciklama, alarm_saati) VALUES (?, ?, ?)`,
            [tag, aciklama, saat]
        );
        console.log(`[BAŞARILI] Alarm veritabanına eklendi. Kayıt ID: ${result.insertId}`);
        res.send("Alarm basariyla kaydedildi.");
    } catch (dbError) {
        console.error("[HATA] Veritabanına ekleme hatası:", dbError.message);
        res.status(500).send("Veritabanı hatası");
    }
});

app.listen(PORT, async () => {
    console.log(`Sunucu Başlatıldı. Adres: http://localhost:${PORT}`);
    await tabloyuHazirla();
});
