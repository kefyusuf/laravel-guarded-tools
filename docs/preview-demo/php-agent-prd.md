# PHP Agent — Product Requirements Document

**Sürüm:** 0.1 · **Tarih:** 5 Ekim 2026 · **Durum:** İnceleme / brainstorm taslağı  
**Çalışma adı:** Agent Studio (marka ve Composer paket adı henüz kararlaştırılmadı)  
**Eşlik eden dosya:** `agent-studio-demo.html`  
**Hedef:** Laravel-first, framework-independent PHP agent entegrasyon paketi.

> Bu belge konuşmadaki ürün fikirlerini uygulanabilir bir taslağa dönüştürür. Konuşmada önerilmiş her özellik onaylanmış gereksinim değildir. MVP, V1 ve sonraki dönem birbirinden ayrılmıştır. Gerçek backend henüz uygulanmadı. HTML, deterministik frontend simülasyonudur. Teknoloji ve model sürümleri, lisanslar, benchmark ve pazar iddiaları bu çalışma kapsamında dış kaynaklarla doğrulanmadı.

## 1. Ürün özeti

Mevcut bir PHP uygulamasına, uygulamanın izin verdiği işlemler üzerinden veriye erişen, yerel bir modelle çalışabilen ve yanıtın kaynağını izlenebilir tutan bir agent eklemek istiyoruz. İlk kullanım read-only sorular: “Geçen ay kaç sipariş verdik?” veya “İade politikasını açıkla.” Uygulama kendi verisinin ve yetkilendirmesinin sahibidir. Agent kendi konuşma, memory, knowledge index ve execution evidence verisini ayrı tutar.

Uygulama mimarisine uyum sağlayan katman tool implementasyonları, application port’ları ve connector adapter’larıdır. Agent döngüsü Laravel modeli, PDO, REST veya müşteriye özel repository bilmez. Laravel kullanım deneyimini kolaylaştırır; framework core’un zorunlu bağımlılığı olmaz.

Panel bu sınırları görünür kılan bir inceleme aracıdır. Ürünün çekirdeği PHP API ve isteğe bağlı HTTP adapter üzerinden headless kalır. Panelin üretim ürünü olarak kapsamı, erişim modeli ve dağıtımı ayrı bir karar olacaktır.

## 2. Problem ve değer hipotezi

Bir chat modeli uygulamanın verisini, business rule’larını ve erişim izinlerini kendiliğinden bilmez. Ham SQL erişimi vermek, her uygulama için özel prompt yazmak veya tüm iş kurallarını modele bırakmak veri erişimini belirsizleştirir. Ayrıca model yanıtının hangi kayıttan üretildiği ve başarısız tool’un boş veri sanılıp sanılmadığı anlaşılmaz.

Değer hipotezimiz: geliştiricinin mevcut service/repository katmanına küçük bir read-only tool bağlaması, modelden bağımsız execution kontrolleri ve source trace ile ilk güvenilir cevaba ulaşmasını kolaylaştırır. Bu hipotez henüz müşteri görüşmesi veya ücretli pilot ile doğrulanmış değildir. Yerel çalışmak veri aktarımını azaltabilir; tek başına güvenlik, doğruluk veya mevzuat uyumu garantisi değildir.

## 3. Hedef kullanıcılar ve ilk pilot

| Kullanıcı | İhtiyaç | Başarı ölçüsü |
|---|---|---|
| Laravel geliştiricisi | Mevcut service’i agent capability’ye dönüştürmek | Bir örneği takip ederek ilk tool’u kaydetmek |
| Uygulama sahibi / operasyon kullanıcısı | İzinli veriden anlaşılır cevap almak | Kaynaklı cevap ve açık hata durumu |
| Teknik inceleyici | Erişim, veri kaynağı ve hata zincirini görmek | Policy kararı ve tool run trace |

**Önerilen ilk pilot:** tek Laravel uygulamasında `orders.summary`; bir kurulum, bir doğrulanmış kullanıcı, tek izin, tek veri kaynağı. `content.search` ve knowledge soru-cevap ikinci doğrulama senaryosudur. Generic PHP, Symfony, ERP ve microservice uyumluluğu mimari hedef olarak korunur; her kombinasyon ilk sürümde hazır ürün vaadi değildir.

## 4. Hedefler ve hedef dışı alanlar

1. Mevcut PHP uygulamasına küçük entegrasyon yüzeyi ile agent eklemek.
2. Tool availability ve execution authorization kararlarını modelden bağımsız vermek.
3. Scope dışı veri erişimini tool ve retrieval seviyesinde engellemek.
4. Model veya backend erişilemezliğini boş veri veya gerçek cevap gibi sunmamak.
5. Çalışma bütçesi, canonical result ve provenance üretmek.
6. Model sağlayıcısını core’dan ayırmak; ilk sürümde yalnızca bir gerçek gateway implementasyonu yeterlidir.
7. Memory öğrenmesini kontrollü ve geri izlenebilir yapmak.

**Şimdi yapılmayacaklar:** builder mode, automatic schema discovery, kendiliğinden tool üretip production’a aktive etme, arbitrary SQL agent, fine-tuning, multi-agent orchestration, kompleks workflow engine, distributed vector DB, servis discovery, weighted balancing, model marketplace, region routing ve tenant başına endpoint provisioning.

## 5. Kapsam matrisi

| Alan | MVP: ilk çalışan döngü | V1: tamamlayıcı kapsam | Sonrası |
|---|---|---|---|
| Core | Context, registry, bounded loop, canonical result | Capability metadata, context assembly | Karmaşık workflow |
| Tools | Bir read-only application tool | Tool tanımlama helper / scaffolding | Write ve approval workflows |
| Policy | Exact host + doğrulanmış kimlik + izin + explicit scope | Ortak exposure/execution karar modelleri | Gelişmiş service/audience matcher |
| Model | Fake gateway + bir local gateway | Ayrı embedding gateway | Yeni sağlayıcı adapter’ları |
| Storage | SQLite conversation + tool run | Memory, knowledge, embeddings, feedback | Alternatif datastore |
| Retrieval | Gerekmez | Scope-filtered, versioned semantic retrieval | Büyük ölçek vector backend |
| Connector | Local Laravel application port | HTTP + bounded priority failover | Region, discovery, load balancing |
| Memory | Geçici conversation | Candidate → validated → active, expiry | Gelişmiş promotion otomasyonu |
| HTTP | PHP API üzerinden örnek | Authenticated headless HTTP adapter | Bağımsız hosting ürünü |
| UI | Bu offline demo | Optional developer panel kararı açık | Production management console |
| Model lifecycle | Haricen çalışan runtime’a bağlan | Manifest / compatibility kaydı, health | Otomatik install / upgrade / rollback |

**Kapsam düzeltmesi önerisi:** Konuşmada V1’e eklenmesi önerilen tüm alt sistemleri ilk milestone’a koymak doğru olmaz. Scope/authorization/error/budget core güvenlik sınırları ilk döngüde yer alır. Semantic retrieval ve memory promotion, ilk pilotta gereksinim doğrulanınca V1 tamamlayıcı aşamalarında uygulanır. Bu ayrım henüz ürün sahibi tarafından dondurulmuş bir karar değildir.

## 6. Ana kullanıcı yolculukları

### 6.1 Kurulum

Geliştirici paketi yükler, Laravel provider/config’i ekler, ayrı agent datastore konumunu seçer, migration’ı explicit çalıştırır. Haricen çalışan local runtime adresini ve model kimliğini yapılandırır. Sağlık ve capability kontrolü başarısızsa setup hazır sayılmaz. Secret değerleri config/secret provider’da kalır. Örnek tool kaydedilir, fake gateway ile akış test edilir, ardından aynı senaryo gerçek model ile değerlendirilir.

Panel simülasyonu bu adımları tek formda gösterir; PHP/Composer/SQLite kurmaz ve runtime’a bağlanmaz. `Generic PHP` ve farklı runtime seçenekleri ürün varyasyonunu anlatır, hazır adapter desteğini ispatlamaz.

### 6.2 Agent tanımlama

Geliştirici ad, görev talimatı, explicit tool ID listesi ve execution bütçelerini belirler. Tool listesi izin vermez; request context içindeki yetki ayrıca kontrol edilir. Modelin tool seçimi sadece öneridir. Runtime unavailable tool çağrısını reddeder. Agent talimatı müşterinin authorization sistemi üzerinde yetki genişletemez.

### 6.3 Tool entegrasyonu

Tool adı, açıklama, input/output schema, effect sınıfı ve implementasyon kaydedilir. Tool mevcut application port’unu çağırır. Laravel attribute veya artisan scaffold yalnızca convenience layer olur. Core için bir Tool contract yeterlidir. UI’de form doldurmak gerçek business logic veya PHP class üretmiş sayılmaz.

### 6.4 Connector bağlama

Bir application port için local adapter veya HTTP adapter seçilir. `request_host`, agent’a gelen isteğin host’udur. `endpoint`, connector’ın ulaşacağı backend’dir. Ayrı alanlardır. Çoklu endpoint aynı scope, yetki, veri sözleşmesi ve veri anlamını sunmalıdır; birbirinden farklı veri depoları otomatik yedek kabul edilmez.

### 6.5 Kontrollü öğrenme

Bir gözlem Candidate olur, kaynağı ve scope’u kaydedilir. Yetkili reviewer doğrular ve ayrı promotion ile ACTIVE yapar. Kullanıcı tercihi global şirket kuralı olamaz. Confidence sayısı promotion yetkisi vermez. Yeni kural eskiyi supersede edebilir; eski kayıt normal retrieval’dan çıkarılır. Rejection, expiry ve supersession reason saklanır.

### 6.6 Çalıştırma ve inceleme

Kimlik doğrulanır → trusted context oluşturulur → görünür tools filtrelenir → context bütçesi hesaplanır → model önerir → argümanlar doğrulanır → execution policy yeniden kontrol edilir → connector çağrılır → sonuç normalize edilir → kaynaklı cevap üretilir → trace kaydedilir. Model yanıt vermese bile policy failure kaydı incelemeye açık kalır.

## 7. Fonksiyonel gereksinimler ve kabul kriterleri

| ID | Gereksinim | Kabul kriteri | Aşama |
|---|---|---|---|
| FR-01 | Trusted ExecutionContext | Kimlik/scope/body çelişkisinde sunucu kimliği esas alınır; anonymous varsayılan deny | MVP |
| FR-02 | Explicit ToolRegistry | Duplicate ID startup hatası; kayıtlı olmayan tool çalışmaz | MVP |
| FR-03 | Exposure | Agent allowlist + enabled + exact host + permission filtrelemesi modele verilen listede uygulanır | MVP |
| FR-04 | Execution policy | Görünür olsa bile çağrı anında güncel kimlik, izin ve scope kontrol edilir | MVP |
| FR-05 | Schema validation | Eksik, tip hatalı ve unknown argüman connector çağrısından önce reddedilir | MVP |
| FR-06 | READ effect | V1 read dışı effect reject edilir; HTTP yöntemi tek başına read garantisi sayılmaz | MVP |
| FR-07 | Budgets | Tool sayısı, inference turu, deadline, result bytes üst sınırında yeni iş başlatılmaz | MVP |
| FR-08 | Canonical result | Empty success, failure ve unavailable farklı status taşır | MVP |
| FR-09 | Provenance | Answer/run/tool/result/source/time arasında referanslar saklanır | MVP |
| FR-10 | FakeModelGateway | Deterministik tool suggestion, failure ve loop fixture’ları çalışır | MVP |
| FR-11 | Local ModelGateway | Bir doğrulanmış runtime/model kombinasyonunda tool-call evaluation geçer | MVP |
| FR-12 | SQLite isolation | Agent datastore ve müşteri DB lifecycle’ı ayrıdır; explicit migrations kullanılır | MVP |
| FR-13 | HTTP connector | Configure edilmiş endpoint ve credential referansı dışında çağrı yapamaz | V1 |
| FR-14 | Failover | Auth/schema/validation failure’da failover yok; retryable transport failure’da bounded alternate endpoint | V1 |
| FR-15 | Knowledge ingestion | Source revision/checksum, chunk revision ve owner/scope metadata saklanır | V1 |
| FR-16 | Scoped retrieval | Yetkisiz adaylar ranking/context assembly’den önce elenir | V1 |
| FR-17 | Embedding version | Model/revision/dimension/normalization değişiminde karışık index sorgulanmaz | V1 |
| FR-18 | Memory lifecycle | Candidate/validated normal retrieval’da yok; promotion actor/time/evidence saklanır | V1 |
| FR-19 | Headless endpoint | Authenticated PHP/HTTP çağrıları aynı application service ve policy yolunu kullanır | V1 |
| FR-20 | Feedback | Feedback önce sinyaldir; otomatik truth veya tool yetkisi olmaz | V1 |
| FR-21 | Context assembly | System/policy için ayrılan alan korunur, source içerik kısaltması görünür olur | V1 |

## 8. Domain ve public contract haritası

Bu tablo kavramsal boundary’leri gösterir; her satır ayrı Composer paketi veya interface olmak zorunda değildir. İlk real adapter ve test ihtiyacı olmadan isimleri public API olarak dondurmamalıyız.

| Kavram / contract adayı | Sorumluluk | Bilmeyeceği şey |
|---|---|---|
| AgentRuntime | Bounded inference / tool execution döngüsü | Eloquent, HTTP client detayları |
| ExecutionContext | Verified principal, scope, permissions, conversation/run identity | Auth facade, raw JWT/secret |
| Tool / ToolDefinition | Capability kimliği, şema ve application operation | Failover, endpoint credentials |
| ToolRegistry | Explicit kayıt ve lookup | Otomatik schema keşfi |
| ExposurePolicy | Context içinde modele gösterilecek tool’lar | Authorization’ın tek kaynağı olmak |
| ExecutionPolicy | Çağrı anında allow/deny + reason | Modelin gerekçesine güvenmek |
| ToolResult | Status, data, pagination, warnings, provenance | Serbest exception metnini model context’ine atmak |
| Application port | Müşteri domain operasyonu | Agent prompt’u |
| HTTP / local adapter | Port’u backend’e bağlamak | Agent reasoning |
| ModelGateway | Generation ve canonical tool suggestions | Tool’u doğrudan çalıştırmak |
| ModelCapabilities | Negotiated/verified protocol özellikleri | Model adı üzerinden yetenek varsaymak |
| EmbeddingGateway | Model/revision kimlikli embedding | Chat modeline bağımlı olmak |
| ConversationStore / RunStore | İlgili lifecycle kayıtları | Müşteri DB şemasını sahiplenmek |
| MemoryStore / KnowledgeStore | Scope ve revision içeren kayıtlar | Her kaydı global memory saymak |
| SemanticRetriever | İzinli/uyumlu index üzerinde retrieval | Yetkisiz top-k’yı sonradan süzmek |
| ContextAssembler | Trusted instructions ve untrusted data ayrımı, token budget | Authorization kararını prompt’a bırakmak |
| Credential provider boundary | Adapter için credential çözmek | Modele secret taşımak |

**Önerilen repository yapısı:** tek repo; `src/Core`, `src/Application`, `src/Infrastructure/SQLite`, `src/Infrastructure/Inference`, `src/Infrastructure/Http`, `src/Adapters/Laravel`; `tests/Unit`, `tests/Contract`, `tests/Evaluation`, `examples/laravel`. Başlangıçta çoklu package yayınlamak zorunlu değildir. Core Illuminate import etmez; Laravel adapter core’a bağımlıdır. Standalone uygulama ayrı dağıtım hedefi olarak sonraya bırakılabilir; generic PHP compatibility küçük composition example ile gösterilir.

## 9. Tool, policy ve routing semantiği

Tool isimleri kontrollü namespace: `orders.summary`, `content.search`. Metadata `READ` effect ve input/output schema içerir. Allowlist explicit ID’lerden oluşur; wildcard ve arbitrary regex MVP dışıdır.

Exposure, execution ve routing farklı sorumluluklardır. Exposure UI/model görünürlüğüdür; execution gerçek güvenlik kararıdır. Routing hangi backend’in çağrılacağıdır. Exposure filtrelenmiş olsa bile direkt veya eski tool çağrısı execution kontrolünden geçer.

Policy birleşimi: farklı boyutlar AND; host listesi içinde OR; required permission listesi içindeki izinler ALL. Eksik/bilinmeyen context deny edilir. Bu kurallar immutable test fixture ile doğrulanır. Kullanıcıdan gelen permissions/service identity trusted sayılmaz.

Host, trusted proxy ayarları ve izin verilen host listesi üzerinden normalize edilir. Host header veya forwarded header tek başına authentication değildir. Model host ya da endpoint seçemez. Gelen host kısıtı agent’ın dışarıdaki order API’sini aynı isimli host üzerinden çağırmasını gerektirmez.

Service credential ile HTTP erişiminde uygulama kullanıcısının yetkisi kaybolmaz. Connector privileged service identity ile çağırsa bile scope filtresi application port/backend üzerinde uygulanır. “Agent kontrol etti” gerekçesiyle tenant filtresi kaldırılmaz. User delegation ve mTLS identity ayrı gelecek kararlarıdır.

Priority failover yalnızca READ ve equivalence kontrolü yapılmış endpoint seti için düşünülür. İlk bağlantı hatası, timeout veya tanımlı retryable server failure alternate denemesine yol açabilir. 401/403, schema mismatch ve iş kuralı hatası yeni endpoint’le aşılmaz. Maksimum iki attempt MVP sonrası başlangıç önerisidir; total deadline her attempt’i kapsar. Retry de tool sonucunun hata durumunu görünür kılmalıdır. Write retry V1’de yoktur.

## 10. ExecutionContext ve scope

Context için aday alanlar: `principalId`, `principalType`, `permissions`, `scope`, `conversationId`, `runId`, `requestHost`, trusted `serviceId` / `audience` (gerektiğinde), correlation ID ve deadline. Core framework-independent value object’ler kullanır. Role isimlerini permissions yerine yorumlayan genel bir authorization engine şart değildir; application adapter izinleri çözer.

Scope seviyeleri system, tenant, organization, team, user ve conversation olarak konuşuldu. MVP explicit installation/application + user/tenant scope’a ihtiyaç duyduğu kadarını destekler. Scope hiyerarşisinde otomatik inheritance yoktur: `tenant:42` kaydı her user’a sırf alt seviyede diye açılmaz. Visibility, owner ve ACL birlikte değerlendirilir. Scope ID’leri model input’undan alınmaz; validated request context’ten çıkarılır. Kullanıcı scope değiştiremez; demo dropdown’u yalnızca deny örneğini göstermek içindir.

## 11. Memory ve knowledge modeli

| Veri | Sahibi / amaç | Lifecycle |
|---|---|---|
| Conversation | Mevcut konuşma kayıtları | Retention ve deletion policy |
| Working state | Aktif run geçici durumu | Run sonunda temizlenir |
| Semantic memory | Tercih / doğrulanmış observation | Candidate → validated → active |
| Knowledge | Uygulama sahibinin belge ve kuralları | Ingested → approved/active → superseded |
| Feedback | Yanıt değerlendirmesi | Signal; truth’a dönüşmez |
| Tool history | Execution evidence | Retention; normal memory değildir |

Ortak metadata: ID, kind, scope, visibility, source/source revision, owner, status, confidence (opsiyonel), valid_from, expires_at, created_at, approval actor/evidence/time, superseded_by. Source veya authority olmadan “aktif şirket kuralı” oluşturulmaz. Şirket kuralı çelişkisi yalnızca similarity veya yüksek confidence ile çözülemez; reviewer kararı gerekir.

Expiry bir yaşam döngüsü kararıdır; expired memory retrieval’dan çıkar ama audit verisi retention gereği tutulabilir. Kullanıcı verisi silme talebi metin, embedding, cache ve summary türevlerini kapsamalıdır. Silme ve retention politikaları production’a çıkmadan önce netleştirilmeli; audit ihtiyaçları sınırsız PII saklama gerekçesi değildir.

## 12. Semantic retrieval ve embedding index

Generation ve embedding modelleri bağımsızdır. Index identity en az model ID/revision, dimensions, preprocessing/chunking version ve normalization/metric içerir. Aynı boyut, aynı embedding space demek değildir. Model değişiminde reindex gerekir; index uyumsuzluğu açık hata olur.

V1 küçük veri setinde SQLite metadata + saklanan vektörler üzerinde izinli adayları filtreleyip exact similarity değerlendirmesi yapabilir. Vector extension zorunluluğu ve dependency seçimi benchmark sonrası ADR ile belirlenir. “SQLite embeddings” ifadesi native approximate vector engine vaat etmez.

Reindex yeni index revision oluşturur; başarısız reindex mevcut aktif revision’ı bozamaz. Revision swap doğrulanmış tam index üzerinden yapılır. Search query embedding ile index revision eşleşmelidir. Source değişince ilgili chunk ve embedding geçersizleşir. Silinmiş ve revoked içerik yeni run context’ine alınmaz.

## 13. Prompt ve context sınırları

System instructions, tool schemas, authenticated policy/context, application-owned approved knowledge, untrusted retrieved content ve kullanıcı mesajı ayrıştırılır. Content ne kadar güvenilir kaynaktan gelirse gelsin tool yetkisi veya system instruction belirleyemez. Prompt injection’a karşı yalnızca delimiter veya “ignore instructions” cümlesi yeterli değildir; policy, schema ve connector kontrolleri sunucu üzerinde uygulanır.

ContextAssembler toplam token budget’ını model capability/profile’dan alır, output reservation ayırır, system/policy alanını korur ve tool result truncation’ını metadata olarak kaydeder. Konuşmada önerilen yüzde dağılımları bağlayıcı değildir. MVP kısa konuşma ve bounded recent messages ile başlayabilir. Summarization sonraki ihtiyaçtır; model-generated summary source message range ve revision taşır, şirket kuralı olmaz.

## 14. Model gateway ve lifecycle

MVP’de bir local runtime ve bir model kombinasyonu seçilip gerçek evaluation yapılır. UI’nin Ollama/LM Studio/llama.cpp menüsü bunların aynı tool-calling semantics’e sahip olduğunu kanıtlamaz. Runtime protocol sürümü, tool serialization, model chat template, context limit ve structured output davranışı doğrulanmalıdır.

Native tool calling ve canonical JSON fallback iki ayrı implementasyon maliyetidir. İlk pilot için native protocol şartı koymak daha küçük olabilir. JSON fallback kullanılacaksa server-side parse/schema/policy aynen geçerlidir; çıktı düz metin olduğundan code execution yapılmaz. Fallback kararı açık ADR adayıdır.

ModelManifest aday alanlar: model ID/revision, source, artifact hash, license reference, size, runtime compatibility ve tested profile. Weight’ler Composer veya git repo’ya gömülmez. Lisans/redistribution şartları seçilen model bazında release öncesi doğrulanır. İlk aşamada paket runtime process lifecycle yönetmez; haricen çalışan endpoint’e bağlanır. Model indirme, auto-upgrade, rollback ve marketplace MVP dışıdır.

## 15. Failure ve execution budget

Örnek hata sınıfları: Unauthenticated, ScopeDenied, ToolNotAvailable, ToolDenied, InvalidToolArguments, ModelUnavailable, ModelTimeout, ToolTimeout, ConnectorUnavailable, ContextTooLarge, SemanticSearchUnavailable, BudgetExceeded, ResultTooLarge, IndexVersionMismatch. Kullanıcı mesajı güvenli ve sade; teknik trace ayrı olmalıdır. Secret, raw upstream body ve SQL hata metni model context’ine otomatik aktarılmaz.

Default başlangıç önerisi: 8 tool çağrısı, 5 inference turu, 30 saniye total deadline, 1 MiB raw result üst sınırı. Bunlar benchmark sonucu değil; güvenli başlangıç değerleridir. Local model için 30 saniye yeterli olmayabilir; gerçek hardware profilinde yeniden ölçülür. Token budget ayrıca zorunludur; byte limit token limit yerine geçmez. Failed attempt’ler ve retry’lar bütçeyi tüketir. Ulaşılmış deadline yalnızca UI mesajı değildir; mümkün olduğunda transport cancellation uygulanır.

Model “0 sipariş” ile “sipariş servisi erişilemiyor” ayrımını korumalıdır. Bilgi eksikse provenance desteklemeyen sayısal sonuç üretilemez. Partial retrieval / truncated result açık warning verir. Agent loop ile connector retry sayıları birbirinden bağımsız, total budget altında bounded olmalıdır.

## 16. Veri şeması taslağı

| Kayıt | Ana alanlar | İlişki |
|---|---|---|
| conversations | id, owner, scope, timestamps | messages |
| messages | id, conversation_id, role, content/ref, source refs | runs / answers |
| agent_runs | id, context snapshot/ref, status, error, deadline, counters | tool_runs, events |
| tool_runs | id, run_id, tool revision, args ref, result ref, source, status | connector attempts |
| connector_attempts | tool_run_id, endpoint ID, attempt, duration, error class | provenance |
| memories | id, scope, owner, kind, lifecycle, source revision | promotions / supersession |
| knowledge_documents | id, owner, scope, source, revision, checksum | chunks |
| knowledge_chunks | id, document revision, content/ref | embeddings |
| embedding_indexes | id, profile, revision, dimensions, status | embeddings |
| embeddings | chunk/memory ID, index revision, vector | scoped retrieval |
| feedback | message/run ID, actor, signal, note | review |

Başlangıçta gereken tablolar milestone bazında eklenir. Sekiz veya on iki tabloyu ilk gün oluşturmak zorunlu değildir. Agent/model/tool runtime configuration’ın DB’ye mi application config’e mi ait olacağı açık karardır; dinamik panel yazımı MVP’de gerekli değildir. Execution evidence schema version taşır; sensitive payload retention configurable olur.

## 17. API taslağı

**PHP API:** application service `run(agentId, message, verifiedContext)` → canonical `AgentResult`. Actual class names ve signatures contract review’dan sonra dondurulur.

**HTTP V1 adayı:** `POST /api/agent/runs` authenticated endpoint; body `agent_id`, `conversation_id`, `message` içerir. Permissions, service identity ve scope body’den trusted alınmaz. Response `run_id`, `status`, `answer`, source references ve warnings döndürür. `GET /api/agent/runs/{id}` owner/scope authorization uygular. Streaming ve background job poll zorunlu MVP gereksinimi değildir.

HTTP admin config API, model install API ve production panel auth bu taslakta uygulanmış değildir. Read-only business tool kapsamı, agent conversation/audit metadata yazımını yasaklamaz; bu iki side effect ayrıdır.

## 18. Non-functional gereksinimler

- **Dependency sınırı:** Core Illuminate, Laravel auth facade veya müşteri DB modeline bağımlı olmaz.
- **Determinism:** Policy, schema, routing ve lifecycle testleri gerçek model gerektirmez.
- **Concurrency:** SQLite transaction süresi kısa tutulur; network inference açık transaction içinde yapılmaz. Busy timeout, journal mode, backup ve recovery load test ile seçilir. Sabit “50 concurrent request” kapasite iddiası yoktur.
- **Isolation:** Long-lived worker’da principal/context ve scoped caches request’ler arasında sızmaz.
- **Security:** Endpoint allowlist, redirect policy, SSRF ve credential forwarding adapter seviyesinde ele alınır. Internal endpoint desteği için explicit deployment allowlist gerekir; her private adresi otomatik yasaklamak microservice kullanımını bozabilir.
- **Privacy:** Logs redacted; minimum gerekli içerik, configurable retention ve türev veri deletion yolu.
- **Accessibility:** Optional panel klavye kullanımı, visible focus, form labels, okunur error states ve responsive layout sağlar.
- **Performance:** Latency ve first-answer süresi seçilen runtime/hardware üzerinde ölçülür; benchmark olmadan SLO ilan edilmez.
- **Distribution:** Kurulum tekrar edilebilir; package/model/runtime compatibility kaydı tutulur. PHP/Laravel minimum sürümleri dependency incelemesi sonrası kararlaştırılır.

## 19. Observability ve provenance

Minimum olaylar: agent.started/completed/failed, model.requested/responded/failed, tool.selected/started/completed/failed/denied, connector.attempted/failed, memory.retrieved/promoted. Run ID ve correlation ID her olayı bağlar. Tool definition revision, connector identity, endpoint identity, timestamp ve error class saklanır. Model chain-of-thought toplamak gereksinim değildir.

Yanıt kaynak zinciri: answer → agent run → tool run / knowledge chunk revision → source. Kaynakları saklamak modelin tüm cümlelerinin doğruluğunu kanıtlamaz; evaluation hâlâ gereklidir. Provenance varlığı ile claim doğrulaması birbirinden ayrıdır.

## 20. ADR adayları

Bunlar onaylanmış ADR değil, inceleme için karar önerileridir.

| ADR | Öneri | Alternatif / tradeoff | Durum |
|---|---|---|---|
| A-001 | Laravel-first, pure PHP core | Laravel-only daha hızlı; taşınabilirlik azalır | Öneri |
| A-002 | Tool → application port → adapter ayrımı | Generic connector framework ilk aşamada gereksiz olabilir | Öneri |
| A-003 | Exposure ve execution ayrı; ortak predicate reuse | Aynı rule’ların iki yerde drift riski testle kapatılmalı | Öneri |
| A-004 | SQLite agent datastore ayrı | Müşteri DB’yi reuse operasyonu kolaylaştırabilir; coupling artar | Öneri |
| A-005 | Read-only V1, deny default | Write approval/idempotency maliyetini ertelemek | Öneri |
| A-006 | MVP bir native tool-calling profile | Canonical JSON fallback provider uyumunu genişletir; eval maliyeti artar | Açık |
| A-007 | Versioned embedding + scoped retrieval | Semantic retrieval’ı pilot sonrası ertelemek | Öneri |
| A-008 | Reviewed memory promotion | Otomatik confidence promotion güvenilir değil | Öneri |
| A-009 | Haricen çalışan runtime; manifest boundary | Bundled runtime onboarding’i kolaylaştırır; packaging büyür | Öneri |
| A-010 | Panel optional devtool; headless core | Production admin console ayrı ürün yükü getirir | Açık |
| A-011 | Bounded read failover, equivalent endpoints | Tek endpoint ilk pilotu basitleştirir | V1 sonrası MVP |
| A-012 | Başlangıç tek repo / package | Erken multi-package version management maliyeti | Öneri |

## 21. Milestone yol haritası

| Milestone | Çıktı | Dependency | Tamamlanma kanıtı |
|---|---|---|---|
| M0 — İnceleme | Bu PRD + demo + ilk pilot seçimi | Yok | Açık kararlar kaydı ve kesilmiş MVP scope |
| M1 — Deterministik çekirdek | Context, tool registry, policy, schema, budgets, fake model | M0 | Allow/deny/invalid schema/loop tests; zero unauthorized connector calls |
| M2 — İlk gerçek cevap | Laravel example, orders.summary, SQLite run kayıtları, bir local gateway | M1 | Gerçek fixture DB + gerçek model + kaynaklı answer; permission deny |
| M3 — HTTP entegrasyonu | Bir HTTP connector, equivalence definition, timeout / bounded failover | M2 | Controlled transport failure tests ve scoped backend integration |
| M4 — Knowledge retrieval | Ingestion, embedding profile, index revision, scoped retrieval | M2; ihtiyaca göre M3’ten bağımsız | Cross-scope leakage 0; model swap mismatch; reindex integrity |
| M5 — Kontrollü memory | Candidate/promotion/expiry/supersession, feedback signals | M4 | Candidate exclusion ve reviewer audit |
| M6 — V1 pilot release | HTTP adapter, docs, eval corpus, retention/recovery, version matrix | Gerekli M3–M5 | Repeatable install + real pilot acceptance |

Tahmini takvim yerine exit criteria kullanıyoruz. M2 pilotu ihtiyacı karşılıyorsa M3–M5’e geçmeden kullanıcı geri bildirimi alınır. Her milestone ek public contract veya abstraction için gerçek kullanım kanıtı gerektirir. Demo panelin tamamı production panel geliştirme taahhüdü değildir.

## 22. Verification ve evaluation planı

| Sınıf | Senaryo | Beklenen |
|---|---|---|
| Deterministik | Host doğru, izin yok | ToolDenied; connector çağrısı yok |
| Deterministik | Host spoof / untrusted forwarded header | Trusted host normalization dışı request reject |
| Deterministik | Model gizlenmiş tool’u önerir | ToolNotAvailable |
| Deterministik | Agent allowlist dışında tool | Deny |
| Deterministik | Required arg eksik / unknown property | InvalidToolArguments |
| Deterministik | Tool çağrısı loop limitine ulaşır | BudgetExceeded; yeni çağrı yok |
| Deterministik | Tenant A context, Tenant B record | Tool ve retrieval data leakage yok |
| Deterministik | Candidate / expired / superseded memory | Retrieval dışı |
| Contract | Fake ve real gateway canonical output | Aynı DTO/error semantics |
| Contract | HTTP timeout ve backup | Retryable failure sınırlı failover; 403 failover yok |
| Integration | SQLite restore ve schema upgrade | Kayıt bütünlüğü, explicit migration |
| Integration | Worker ardışık farklı principal | Context/cache leakage yok |
| Retrieval | Embedding aynı dimension farklı model | IndexVersionMismatch |
| Retrieval | Reindex yarıda kesilir | Eski aktif index geçerli |
| Eval | “Geçen ay kaç sipariş?” | orders.summary, doğru period, kaynaklı gerçek sayı |
| Eval | Tool result unavailable | Sayı uydurma yok, açık hata |
| Eval | Retrieved injection text | Yetki genişlemesi veya forbidden tool execution yok |
| Eval | Türkçe belirsiz dönem | Clarification veya açık dönem varsayımı |

Başlangıç eval corpus’u önerisi: 20–30 Türkçe task, izinli/izinsiz soru, ambiguity, unavailable servis ve injected content örnekleri. Model/version/template değişiminde aynı corpus rerun edilir. Tool seçimi başarısı, argument validity, claim-source agreement, latency ve refusals ölçülür. Güvenlik invariant’ları %100 deterministic geçmelidir; model selection doğruluğu için başarı eşiği pilot verisi ile belirlenir.

## 23. Başarı kriterleri ve ürün doğrulaması

MVP release gate: bir uygulamada repeatable install; tek read tool ile doğru scope altında gerçek kaynaktan cevap; invalid call’larda sıfır backend invocation; model unavailable durumunda uydurma sonuç yok; run evidence tekrar incelenebilir. Setup süresi ve toplam latency ölçülür, varsayılan hedef uydurulmaz.

Pilot görüşmesi şu üç soruyu cevaplamalı: Hangi tekrar eden işi çözüyoruz? Mevcut rapor ekranı/API’ye göre ne kazandırıyoruz? Geliştirici bir tool yazmaya ve kullanıcı bu cevaba güvenmeye istekli mi? İki gerçek pilot olmadan generalized builder veya connector platformuna geçmeyelim. Fiyatlandırma ve open-source / commercial ayrımı açık ürün kararıdır.

## 24. Riskler ve azaltma

| Risk | Etki | İlk azaltma |
|---|---|---|
| Scope genişlemesi | Yayına çıkamamak | M2’de tek tool loop; her yeni özellik için pilot kanıtı |
| Yerel model tool selection zayıflığı | Yanlış argüman / cevap | Bir doğrulanmış profile, eval ve server schema |
| Service credential çok yetkili | Cross-tenant veri sızıntısı | Backend/application port scope enforcement |
| Prompt injection | Forbidden action önerisi | READ sınırı, server policy, endpoint allowlist |
| Memory poisoning | Yanlış kalıcı bilgi | Source/reviewer/promotion lifecycle |
| Vector profile karışması | Yanlış retrieval | Versioned index ve fail-closed mismatch |
| SQLite contention | Run kaydı aksaması | Kısa transactions, load profile, explicit capacity ölçümü |
| Trace’de PII/secrets | Gizlilik ihlali | Redaction, retention ve deletion |
| Production UI’nin yanlış anlaşılması | Güvenlik ve ürün beklentisi | Demo etiketi, headless core, optional panel kararı |
| HTTP failover veri eşdeğerliği yok | Yanlış sayı / scope | Equivalent endpoint set contract |

## 25. Demo dosyası kullanım ve sınırları

`agent-studio-demo.html` dosyasını indirip masaüstü tarayıcıda aç. İnternet, CDN, font download veya API key gerekmez. İlk olarak Kurulum → demo kurulumu tamamla; sonra Playground → simülasyonu çalıştır. Browser localStorage kullanılabiliyorsa kayıtlar aynı browser/origin altında tutulur; file:// davranışı browser’a göre değişebilir. JSON dışa aktar, taşınabilir inceleme snapshot’ı üretir. Import bu sürümde yoktur. Sıfırla yerel demo state’ini siler.

| Ekran | Çalışan demo davranışı | Üretimde uygulanmayan kısım |
|---|---|---|
| Kurulum | Profil kaydı ve ready state | Composer, SQLite migration, model health |
| Agent’lar | Ad, talimat, tools, çağrı limit kaydı | Gerçek inference loop |
| Tool’lar | Namespace / schema kaydı, toggle | PHP tool class üretme ve çalıştırma |
| Connector’lar | HTTPS URL tanımı / local driver | Network, SSRF enforcement, gerçek credential |
| Memory | Candidate → validated → active / rejection / expiry | Server authorization ve gerçek semantic store |
| Playground | Deterministik host/identity/permission/scope checks | JWT doğrulama, gerçek inference, HTTP |
| History | Yerel JSON execution trace | Durable SQLite audit |
| Export | Demo config ve run snapshot indirme | Production config deployment |

Model mesajı yorumlanmaz: Playground’daki tool dropdown’u fake model seçimini belirler. Yeni tool için generic fixture dönmesi gerçek business implementasyonu değildir. Seed sales sayısı (128 / 84.250 TL) tamamen örnektir. READ-only tool demoda bile şirketin gerçek verisine erişmez. Knowledge/memory selection exact scope/status/expiry filtresidir; similarity, embeddings ve reindex yoktur. Input schema checker küçük bir alt kümeyi destekler; tam JSON Schema standard validator değildir. Budget UI ve kaydı kavramı gösterir; gerçek token/time loop kontrolünü kanıtlamaz.

**İnceleme senaryoları:**
1. Kurulumu tamamla, orders.summary ile allow sonucu ve trace’i gör.
2. Host’u blog olarak değiştir; orders.summary unavailable olsun.
3. İzni kaldır; ToolDenied sonucu ve sıfır data gör.
4. Scope’u tenant:99 yap; ScopeDenied gör.
5. Primary unavailable seç; ikinci endpoint provenance’ını gör.
6. Tüm endpoint’ler unavailable seç; boş rapor yerine açık error gör.
7. Required period alanını kaldır; InvalidToolArguments gör.
8. Candidate memory ekle; yalnızca onay/active sonrası retrieval ID listesinde gör.
9. Yeni connector ve tool oluştur; yeni agent’ın explicit tool listesi ile dene.
10. JSON export ile snapshot’ı ikinci modelle incele.

## 26. Açık kararlar

- İlk kullanıcı/problem orders.summary mi, blog knowledge mı? Tek pilot seçilmeli.
- Semantic retrieval pilot için şart mı, V1 sonuna mı kalmalı?
- ConnectorDefinition gerçekten public core contract mı, ilk HTTP adapter config’i yeterli mi?
- ModelGateway native tool calling şartı mı, JSON fallback aynı sürümde mi?
- Agent/tool config application-owned code mu, DB üzerinden runtime editable mı?
- Tool output schema ve input schema için hangi validator ve desteklenen draft kullanılacak?
- PHP/Laravel minimum sürümleri ve birinci runtime/model profile hangisi?
- Memory reviewer authority uygulamadan nasıl sağlanacak?
- Headless HTTP endpoint uygulama adapter’ı mı, bağımsız servis dağıtımı mı?
- Panel optional developer tool olarak kalacak mı? Production auth/config management ayrı scope mu?
- Source claim doğrulaması ve evaluation release eşikleri ne olacak?
- Open-source / ücretli destek / hosted ürün ayrımı pilot sonrası nasıl kararlaştırılacak?

## 27. Claude / başka model için review prompt’u

Aşağıdaki metni PRD ve HTML ile birlikte ver:

> Bu PRD ve offline HTML demo’yu incele. Laravel-first, pure PHP core üzerinde mevcut uygulamaya read-only agent capability eklemek istiyoruz. Panel simülasyondur; gerçek backend mevcut değil. Mimariyi genişletmeden önce ilk değer üreten döngüyü küçültmemize yardım et.
>
> 1. Ürün değerini ve ilk müşteri varsayımını eleştir; kesin olmayan iddiaları işaretle.
> 2. MVP’den çıkarılması gereken en fazla beş özelliği gerekçeleriyle seç.
> 3. Tool exposure, execution authorization ve connector routing ayrımında güvenlik veya responsibility hatası bul.
> 4. Scope, service credential, prompt injection, memory promotion ve embedding versioning için somut failure senaryoları ver.
> 5. Public contract adaylarını “şimdi gerekli / ilk adapter sonrası / ertelenebilir” olarak sınıflandır.
> 6. Native tool calling şartı ile canonical JSON fallback maliyetini karşılaştır; bir ilk pilot öner.
> 7. Demo’daki simülasyon ile PRD’nin gerçek ürün taahhütleri arasındaki yanlış beklentileri belirle.
> 8. Yeni platform özellikleri önermek yerine M1–M2 için en küçük implementation sırasını ve exit criteria öner.
> 9. Görüşlerini bulgu → neden → öneri → doğrulama biçiminde yaz. P0/P1/P2 öncelik ve varsayım/kanıt ayrımı yap.
> 10. Son olarak bu projeyi durdurmamızı veya pivot etmemizi gerektirecek üç pilot bulgusu belirt.
>
> Model/runtime lisansı, sürüm, performans veya rakip pazar iddiası yapıyorsan güncel primary source ile doğrula; doğrulayamıyorsan açık varsayım de. İnceleme sonunda kapsamı büyütmeden revize edilmiş MVP öner. Kod yazmadan önce bu kararları değerlendir.

## 28. Review sonucu kayıt şablonu

| Bulgu ID | Reviewer | PRD alanı | Kanıt / failure senaryosu | Öneri | Karar | Gerekçe | Milestone etkisi |
|---|---|---|---|---|---|---|---|
| R-001 | | | | | Kabul / red / ertelendi | | |

İki modelin aynı görüşe katılması doğruluk kanıtı değildir. Somut failure, pilot ihtiyacı ve uygulanabilir verification kararın dayanağı olmalıdır. İnceleme sonunda yeni feature listesi yerine daraltılmış scope, açık ADR kararları ve tek next work unit çıkarılır.

## 29. Bu teslimin doğrulama kaydı

5 Ekim 2026: Offline HTML, Playwright ile yerel Chromium’da `file://` üzerinden çalıştırıldı. Ana tarayıcı aracı localhost adresini engellediği için yerel Chromium fallback kullanıldı. Gerçek model/network/SQLite testi yapılmadı.

Geçen kontroller: setup kaydı; successful tool fixture; yanlış host → ToolNotAvailable; eksik permission → ToolDenied; tenant:99 → ScopeDenied; primary unavailable → backup provenance; all unavailable → ConnectorUnavailable ve null data; eksik argüman → InvalidToolArguments; memory validated/active transitions; connector/tool/agent creation; reload sonrası local state; export butonu. Dokuz ekran 390×844 viewport’ta document overflow açısından kontrol edildi. Desktop viewport 1536×1024 idi. Page-level JavaScript exception bulunmadı.

Görsel konsept ve desktop/mobile screenshot `view_image` ile incelendi. Karşılaştırılan noktalar: white surface / cool gray background / emerald action palette; sidebar ve iki kolonlu çalışma alanı; heading/body/control typography; üç count alanı; tool table ve dört adımlı onboarding; simülasyon notice. Sidebar marka satırı kırılması ve mobil grid minimum genişlik taşması düzeltildi. Başlıktaki copy ve nav sırası korundu. Bilinçli demo ekleri: Sıfırla butonu, Kuruluma git kısayolu, tool açıklamaları ve daha açık simülasyon metni. Konsept bir görsel yönlendirmedir; birebir pixel-identical replika iddiası yoktur. Küçük mobil viewport’ta nav ve geniş tablo kendi alanlarında yatay kaydırılır. Model gateway, server-side auth, total deadline ve token budget doğrulaması bu frontend tesliminin kanıtı değildir.
