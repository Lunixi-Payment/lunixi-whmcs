<p align="center"><img src="modules/gateways/lunixi/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# WHMCS için Lunixi Payments

[English](README.md) · [Dokümantasyon](https://docs.lunixi.com/tr/sdks/whmcs) · [MIT lisansı](LICENSE)

WHMCS faturalarını `pay.lunixi.com` yönlendirmeli veya gömülü Lunixi formuyla tahsil edin. Çoklu kart, taksit, alternatif ödeme yöntemleri ve sigorta uygunluğu **Lunixi panelinden** yönetilir; WHMCS'de aynı ayarlar tekrar edilmez. Kart numarası/CVV sunucunuza gelmez.

**0.1.0**, WHMCS **8.13 / 9.0 / 9.1** resmi ödeme geçidi/eklenti sözleşmelerini hedefler; **64 bit PHP 8.2+** gerekir. 31 Ekim 2026 tarihinde 8.13.7, 9.0.9 ve 9.1.0 sürümleri doğrulanmıştır. **Lisanslı WHMCS çalıştırma ortamı bulunmadığından yerel platform kabul testi yapılmamıştır.** Çalıştırılan testler gerçek MariaDB/InnoDB ve Illuminate üzerinde, açıkça belirtilen resmi sözleşme test bileşenleriyle yapılır. Canlı kurulumdan önce [lisanslı kabul adımlarını](docs/ACCEPTANCE.md) tamamlayın.

## Kurulum

1. Dosya/veritabanı yedeği alın. WHMCS `SystemURL` adresi HTTPS; mali tablolar InnoDB olmalıdır. PHP Sodium, cURL, JSON ve PDO MySQL uzantılarını ve WHMCS'nin PHP/ionCube uyumunu kontrol edin.
2. Ayrı bir sunucu şifreleme anahtarı üretin:
   ```bash
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```
   Web ve CLI PHP süreçlerine **`LUNIXI_WHMCS_ENCRYPTION_KEY`** ortam değişkeni olarak tanımlayın. Anahtarı web kökü ve veritabanı dışında yedekleyin; güncellemede değiştirmeyin. Kaybı geçmiş kimlik bilgileri/tokenların çözülememesine yol açar.
3. Derlenmiş **`lunixi-whmcs-0.1.0.zip`** içeriğini WHMCS köküne açın. Pakette ödeme geçidi, callback, gömülü ve namespace ayrılmış PHP SDK ile `lunixi_payments` yönetim eklentisi bulunur. Mağaza sunucusunda **Composer gerekmez**. GitHub kaynak arşivi kurulum ZIP'i değildir.
4. **System Settings → Addon Modules** altında Lunixi Payments'i açın ve uygun yönetici rollerini seçin. **Addons → Lunixi Payments** ekranından üye işyeri ID, key ID, özel imzalama anahtarı ve webhook sırrıyla değiştirilemez profil oluşturun. TEST/LIVE ortamı anahtarla aynı olmalıdır; form modu ve ön provizyon tercihini seçin.
5. **Payment Gateways → Lunixi Payments** ayarına profil ID değerini girin. Anahtar giriş alanları yönetim eklentisindedir; özel anahtar/webhook sırrı parola alanıyla alınır, ayrı sunucu anahtarıyla kimliği doğrulanmış şifreli saklanır ve tekrar gösterilmez.
6. Profil yanında gösterilen **tam webhook adresini**, `?profile=ID` dahil, Lunixi'ye kaydedin. Ortam ve ödeme yaşam döngüsü olayları eşleşmelidir.
7. Lisanslı deneme WHMCS'si ve kayıtlı TEST üye işyeriyle kabul matrisini tamamlayın.

API adresi sabit `https://api-gateway.lunixi.com` adresidir. Gerçek TEST/LIVE erişimini anahtar belirler; isteğe uydurma ortam parametresi eklenmez. Gömülü form yalnız resmi `https://cdn.lunixi.com/lunixi-checkout.min.js` dosyasını yükler; CSP izinlerini resmi form gereksinimlerine göre düzenleyin.

## Fatura ve ödeme güvenliği

Ödeme başlatmak için oturum açmış seçili müşteri hesabının **asıl sahibi**, WHMCS CSRF doğrulamalı POST göndermelidir. 0.1.0 sürümünde yetkilendirilmiş ek kullanıcılar ve yönetici taklit oturumları desteklenmez. Tarayıcı tutarı alınmaz; fatura DECIMAL toplamı, uygulanmış kredi ve önceki kısmi ödemeler sunucudan okunur. Float bakiye hesabı, küsuratlı kuruş veya yabancı para birimindeki muhasebe satırları kullanılmaz.

Kalıcı ödeme rezervasyonu ağ çağrısından önce kaydedilir. Yanıt kaybolursa aynı istek/anahtar korunur. Token süresi dolması veya belirsiz sonuç yeni tahsilat başlatmaz. Yönetici ancak kimliği doğrulanmış son başarısızlık ve sıfır tahsilat durumunda geçmişi koruyarak yeni denemeye izin verebilir.

- Yetkilendirme ve kısmi tahsilat faturayı **ödenmemiş** bırakır.
- Tam tahsilat; imzalı webhook ve kimliği doğrulanmış GET sorgusuyla üye işyeri, fatura, ödeme kimliği, para birimi ve tutar eşleşince bir kez kaydedilir.
- Callback/tarayıcı dönüşündeki “başarılı” bilgisi ödeme kanıtı değildir.
- Çoklu kartta tek bacağın tahsilatı yeterli değildir; doğru ana ödeme ve tam, dengeli, uzlaştırılmış toplam gerekir.
- Webhook ham bayt v2 HMAC, zaman, olay kimliği/türü/ortamı, kalıcı tekrar ve aynı kimlikle farklı içerik kontrolünden geçer.
- Fatura sahibi, kredi, tutar, para birimi veya harici kısmi ödeme sonradan değiştiyse otomatik fazla alacak yazılmaz; inceleme gerekir. İptal edilmiş fatura otomatik ödenmez.

Ledger, olay kaydı, WHMCS ödeme/iade satırları ve şifreli bildirim kuyruğu **aynı Capsule bağlantısı/veritabanı işlemi** içinde uygulanır. Doğrudan PDO transaction başlatılmaz. Yerel işlem hatası mali kayıtları ve olay tüketimini birlikte geri alır. Lisanslı ortam testi, yerel yardımcıların bu sınırı değiştirmediğini ayrıca doğrulamalıdır.

Taksit ek bedeli faturanın anaparasına veya müşteri kredisine eklenmez; ayrı, faturaya bağlı olmayan gelir hareketiyle kaydedilir. İadeler önce anapara, sonra ek bedel olarak dağıtılır ve yerel `originalTransaction()` ilişkisiyle asıl tahsilata bağlanır. Ürün/KDV alacak notu veya yeni WHMCS billing note tutar dağılımı uydurulmaz; muhasebe sürecinizle ayrıca yönetin. Bekletilen kısmi tahsilatın iadesi otomatik hatalı kasa kaydı yerine muhasebe incelemesi gerektirir.

## Tahsilat, iade ve iptal

Yönetim eklentisinde faturayı açın. İşlemi ve kesin ondalık tutarı seçip **Kalıcı işlem hazırla** düğmesine basın; iptal tutarı boş olmalıdır. Kaydedilmiş işlem ID/tutarını kontrol edip **Çalıştır / aynı işlemi yeniden dene** düğmesini kullanın.

WHMCS eklenti rol erişimi, **Manage Invoice** yetkisi, yönetici CSRF ve POST gereklidir. Profil oluşturmak ayrıca **Configure Payment Gateways** yetkisini ister. HTTP 2xx tek başına başarı değildir: sonuç gerçek tahsilat/iade farkıyla doğrulanana kadar pending/uncertain kalır. Belirsiz işlem varken yeni ID oluşturulmaz; aynı kalıcı ID yeniden kullanılır.

**Standart WHMCS iade formu kullanılmaz.** Resmi callback parametrelerinde kalıcı iade talep ID'si bulunmadığı için modül güvenli hata döndürüp bu yönetim ekranına yönlendirir. İşlem kimliği asıl tahsilat+tutardan tahmin edilmez; WHMCS'nin callback sonrası ikinci bir iade satırı yazması önlenir.

Yeni profil yalnız **aynı üye işyeri ve TEST/LIVE ortamında** eski işlemler için anahtar/webhook sırrını yeniler. Geçmiş profil kimliği, form tercihleri ve fatura görüntüsü sabit kalır. Farklı işyeri veya ortam eski işlemleri devralamaz. Eski profilleri ve sunucu anahtarını iade/uzlaştırma saklama süresi boyunca koruyun.

## Periyodik uzlaştırma ve hizmet işlemleri

Web süreçleriyle aynı anahtarı kullanarak birkaç dakikada bir çalıştırın:

```bash
php /whmcs/yolu/modules/gateways/lunixi/cron.php
```

Komut faturaları son kontrol zamanına göre adil gruplar halinde uzlaştırır ve commit sonrası e-postaları işler. Çıkış kodlarını ve eklentinin kontrol bekleyen işler panelini izleyin. Gönderim sonucu belirsiz e-posta otomatik tekrar edilmez; gerçek teslimatı kontrol edip işi yönetim ekranından tamamlandı olarak kaydedin.

**Commit sonrası hizmet işlemleri:** standart **ModuleCreate** ve **ModuleUnsuspend** işleri belgelenmiş Local API üzerinden bir kez çalışır. İşçi; doğrulanmış ödenmiş faturayı, müşteri kimliğini, güncel hizmet ve sipariş durumunu kontrol eder. Fraud/iptal/özel sipariş bekletmeleri otomatik aşılmaz. Active hizmet tekrar açılmaz; yalnız Pending/Suspended durumları ilgili komuta izin verir. Kalıcı iş ağ çağrısından önce sahiplenilir; zaman aşımı/çökme sonucunda sending/uncertain kaydı yönetici kontrolünü bekler, harici komut körlemesine tekrarlanmaz. Yenileme/paket değişikliği/özel akışlar, belgelenmemiş komut uydurulmadan yerel WHMCS ekranından kontrol edilir. Yönetim ekranındaki onay düğmesi tamamlanan kontrolü kaydeder; tekrar harici komut göndermez. Diğer eklentilerin yan etkileri, yenileme ve billing note davranışları lisanslı kabul testinde ayrıca doğrulanmalıdır.

Devre dışı bırakma; profilleri, ledger/olayları, işlem ID'lerini ve bildirim geçmişini silmez. Veri silme ayrı ve gözden geçirilmiş bir saklama işlemidir.

## Kaynaktan derleme ve test

Kaynak: [Lunixi-Payment/lunixi-whmcs](https://github.com/Lunixi-Payment/lunixi-whmcs). Kaynak yayını, Marketplace onayı veya kayıt deposu yayını anlamına gelmez.

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
php tools/check-artifact.php
php tools/vendor/bin/phpcs --standard=phpcs.xml
php tools/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
composer audit --working-dir=tools
```

Varsayılan derleme `lunixi/php-sdk:^0.29.3` paketini çözer. Sürüm henüz yayımlanmamışsa incelenmiş bağımsız SDK kaynağını `php tools/build.php /tam/yol/lunixi-php` ile verin. İç kaynak yolu dağıtıma girmez; SDK `Lunixi\WHMCS\Vendor\Lunixi\Sdk` altında ayrıştırılır.

Yalnız **`lunixi_whmcs`** isimli silinebilir test veritabanını ve web/kaynak dışında mode-0600 `host`, `port`, `database`, `username`, `password` alanlı JSON bağlantı dosyasını kullanın:

```bash
LUNIXI_CONTRACT_TEST=1 LUNIXI_TEST_DB_CONFIG=/guvenli/gecici-db.json \
  php tools/vendor/bin/phpunit -c phpunit.xml
```

Test tabloları yeniden oluşturulur. Gerçek veritabanı testleri lisanslı WHMCS testi olarak sunulmaz. Ayrıntılar: [kabul matrisi](docs/ACCEPTANCE.md), [resmi sözleşmeler](docs/CONTRACTS.md), [güvenlik](SECURITY.md), [katkı](CONTRIBUTING.md) ve [sürüm notları](CHANGELOG.md).

Finansal sonucu belirsiz işlemler incelemede tutulur. `FAILED` tek başına yeni denemeye izin vermez; herkese açık ödeme sorgusu kesin sağlayıcı sonuç kanıtını döndürmez. Sonuçlanmamış finansal tekrarlar 23 saat sonra durur; kimliği doğrulanmış GET mutabakatı devam eder. Zaman aşımı, form token süresi veya tarayıcı dönüşü yeni finansal anahtar üretmez.
