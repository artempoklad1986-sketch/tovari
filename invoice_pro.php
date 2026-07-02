<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * @name        INVOICE PRO
 * @icon        📄
 * @description Счета · Акты · УПД · PDF · QR · Клиент 360° · Шаблоны
 * @version     6.0
 * @sidebar     true
 * @color       #0ea5e9
 */

// ================================================================
// ПОДКЛЮЧАЕМ ВСПОМОГАТЕЛЬНЫЕ ФАЙЛЫ
// ================================================================
$_invDir = __DIR__ . '/invoice_pro/';
// Вместо require_once — вставляем код прямо здесь

// ═══ из qr.php ═══
function invGenQR(string $data, int $size = 120): string {
    return 'https://api.qrserver.com/v1/create-qr-code/'
        . '?size=' . $size . 'x' . $size
        . '&ecc=M'
        . '&data=' . rawurlencode($data);
}

function invGenSBPString(array $inv, array $settings): string {
    $acc    = $settings['seller_bank_acc'] ?? '';
    $bik    = $settings['seller_bank_bik'] ?? '';
    // ✅ Берём именно название организации, не тип
    $name   = $settings['seller_name']     ?? '';
    $inn    = $settings['seller_inn']      ?? '';
    $kpp    = $settings['seller_kpp']      ?? '';
    $total  = (float)($inv['total']        ?? 0);
    $number = $inv['number']               ?? '';
    $date   = $inv['date']                 ?? '';
    $sumKop = (int)round($total * 100);

    // ✅ Назначение платежа — берём из счёта или генерируем
    $purpose = !empty($inv['payment_purpose'])
        ? $inv['payment_purpose']
        : 'Оплата по сч. №' . $number . ' от ' . $date;

    if (mb_strlen($purpose) > 210)
        $purpose = mb_substr($purpose, 0, 207) . '...';

    // ✅ Только обязательные поля СБП — пустые не включаем
    $parts = ['ST00012'];
    if ($name) $parts[] = 'Name='        . $name;
    if ($acc)  $parts[] = 'PersonalAcc=' . $acc;
    if ($bik)  $parts[] = 'BIC='         . $bik;
    if ($inn)  $parts[] = 'PayeeINN='    . $inn;
    if ($kpp)  $parts[] = 'KPP='         . $kpp;
    if ($sumKop > 0) $parts[] = 'Sum='   . $sumKop;
    if ($purpose)    $parts[] = 'Purpose='. $purpose;

    return implode('|', $parts);
}

function invNormalizePhone(string $phone): string {
    $raw = preg_replace('/\D/', '', $phone);
    if (strlen($raw) === 10) $raw = '7' . $raw;
    if (strlen($raw) === 11 && $raw[0] === '8') $raw = '7' . substr($raw, 1);
    if (strlen($raw) === 11 && $raw[0] === '7') return '+' . $raw;
    return $phone;
}

/**
 * Поиск клиента: ИНН > Телефон > Email > Точное имя
 */
function invFindClient(array &$db, array $buyer): ?array {
    // 1. Приоритет: ИНН (самый надёжный для юрлиц)
    $inn = trim($buyer['inn'] ?? '');
    if ($inn) {
        foreach ($db['clients'] ?? [] as $c) {
            if (!empty($c['inn']) && trim($c['inn']) === $inn) {
                return $c;
            }
        }
    }

    // 2. Приоритет: Телефон (нормализованный)
    $phone = !empty($buyer['phone']) ? invNormalizePhone($buyer['phone']) : '';
    if ($phone) {
        foreach ($db['clients'] ?? [] as $c) {
            if (!empty($c['phone']) && invNormalizePhone($c['phone']) === $phone) {
                return $c;
            }
        }
    }

    // 3. Приоритет: Email
    $email = trim($buyer['email'] ?? '');
    if ($email) {
        foreach ($db['clients'] ?? [] as $c) {
            if (!empty($c['email']) && mb_strtolower(trim($c['email'])) === mb_strtolower($email)) {
                return $c;
            }
        }
    }

    // 4. Последний приоритет: ТОЧНОЕ совпадение имени (без частичного поиска!)
    $name = trim($buyer['name'] ?? '');
    if ($name) {
        $cName = mb_strtolower($name);
        foreach ($db['clients'] ?? [] as $c) {
            if (!empty($c['name']) && mb_strtolower(trim($c['name'])) === $cName) {
                return $c;
            }
        }
    }

    return null; // Не нашли
}

/**
 * Синхронизация с приоритетами
 */
function invSyncClient(array &$db, array $buyer, $existingClientId = null): ?string {
    if (empty($buyer['name']) && empty($buyer['phone']) && empty($buyer['inn'])) {
        return null;
    }
    
    // Если ID уже передан из JS — используем только его (защита от дублей)
    if ($existingClientId) {
        foreach ($db['clients'] ?? [] as $c) {
            if ((string)$c['id'] === (string)$existingClientId) { 
                return (string)$existingClientId; 
            }
        }
    }
    
    // Ищем по приоритетам (ИНН > Телефон > Email > Точное имя)
    $found = invFindClient($db, $buyer);
    if ($found) {
        // Если нашли — возвращаем его ID (новый клиент НЕ создаётся!)
        return (string)$found['id'];
    }

    // Если не нашли — создаём нового клиента
    $cid = (string)(int)(microtime(true) * 1000);
    $typeMap = [
        'company'    => 'Юридическое лицо',
        'ip'         => 'ИП',
        'individual' => 'Физическое лицо',
    ];
    $clientType = $typeMap[$buyer['type'] ?? 'company'] ?? 'Юридическое лицо';
    
    $client = [
        'id'        => $cid,
        'name'      => trim($buyer['name']     ?? ''),
        'phone'     => !empty($buyer['phone']) ? invNormalizePhone($buyer['phone']) : '',
        'email'     => trim($buyer['email']    ?? ''),
        'type'      => $clientType,
        'bizcat'    => 'Клиент из счёта',
        'address'   => trim($buyer['address']  ?? ''),
        'inn'       => trim($buyer['inn']       ?? ''),
        'kpp'       => trim($buyer['kpp']       ?? ''),
        'ogrn'      => trim($buyer['ogrn']      ?? ''),
        'director'  => trim($buyer['director']  ?? ''),
        'bank_name' => trim($buyer['bank_name'] ?? ''),
        'bank_acc'  => trim($buyer['bank_acc']  ?? ''),
        'bank_bik'  => trim($buyer['bank_bik']  ?? ''),
        'bank_ks'   => trim($buyer['bank_kor']  ?? ''),
        'discount'  => 0,
        'notes'     => '',
        'tags'      => ['счёт'],
        'crm_status'=> 'new',
        'avatar_url'=> '',
        'vk_user_id'=> null,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ];
    if (!isset($db['clients'])) $db['clients'] = [];
    array_unshift($db['clients'], $client);
    writeDB($db);
    return $cid;
}

function invGetClientRequisites(array &$db, $clientId): ?array {
    foreach ($db['clients'] ?? [] as $c) {
        if ((string)$c['id'] === (string)$clientId) {
            return [
                'name'     => $c['name']     ?? '',
                'phone'    => $c['phone']    ?? '',
                'email'    => $c['email']    ?? '',
                'inn'      => $c['inn']      ?? '',
                'kpp'      => $c['kpp']      ?? '',
                'ogrn'     => $c['ogrn']     ?? '',
                'address'  => $c['address']  ?? '',
                'director' => $c['director'] ?? '',
                'bank_name'=> $c['bank_name']?? '',
                'bank_acc' => $c['bank_acc'] ?? '',
                'bank_bik' => $c['bank_bik'] ?? '',
                'bank_kor' => $c['bank_ks']  ?? '',
                'discount' => $c['discount'] ?? 0,
            ];
        }
    }
    return null;
}

// ================================================================
// ПУТИ
// ================================================================
// СТАЛО (верно — сохраняет в /public_html/data/invoices/):
define('INV_DIR',    __DIR__ . '/../../data/invoices/');
define('INV_FILE',   INV_DIR . 'invoices_data.json');
define('INV_UPLOAD', __DIR__ . '/../../public/invoices/');
define('INV_UPLOAD_URL', '/public/invoices/');
// ================================================================
// ФАЙЛОВАЯ БД СЧЕТОВ
// ================================================================
function invRead() {
    if (!file_exists(INV_FILE)) {
        $d = ['invoices' => [], 'closing_docs' => [], 'templates' => [], 'ad_banners' => []];
        if (!is_dir(INV_DIR)) mkdir(INV_DIR, 0755, true);
        file_put_contents(INV_FILE, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        return $d;
    }
    $d = json_decode(file_get_contents(INV_FILE), true);
    if (!is_array($d)) $d = [];
    if (!isset($d['invoices']))     $d['invoices']     = [];
    if (!isset($d['closing_docs'])) $d['closing_docs'] = [];
    if (!isset($d['templates']))    $d['templates']    = [];
    if (!isset($d['ad_banners']))   $d['ad_banners']   = [];
    return $d;
}

function invWrite(array $d) {
    if (!is_dir(INV_DIR)) mkdir(INV_DIR, 0755, true);
    $tmp = INV_FILE . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, INV_FILE);
}

// ================================================================
// ИНИЦИАЛИЗАЦИЯ В ОБЩЕЙ БД
// ================================================================
if (!isset($moduleDB['invoice_pro'])) {
    $s = $moduleDB['settings'] ?? [];
    $moduleDB['invoice_pro'] = [
        'index'    => [],
        'settings' => [
            'enabled'              => true,
            'seller_name'          => $s['company']        ?? '',
            'seller_inn'           => $s['inn']            ?? '',
            'seller_kpp'           => $s['kpp']            ?? '',
            'seller_ogrn'          => $s['ogrn']           ?? '',
            'seller_address'       => $s['address']        ?? '',
            'seller_phone'         => $s['phone']          ?? '',
            'seller_email'         => $s['email']          ?? '',
            'seller_bank_name'     => $s['bankName']       ?? '',
            'seller_bank_bik'      => $s['bik']            ?? '',
            'seller_bank_acc'      => $s['bankAcc']        ?? '',
            'seller_bank_kor'      => $s['korAcc']         ?? '',
            'signatory_name'       => $s['signatory']      ?? '',
            'signatory_title'      => $s['signatoryTitle'] ?? 'Директор',
            'accountant_name'      => '',
            'stamp_url'            => '',
            'signature_url'        => '',
            'logo_url'             => '',
            'default_vat'          => 'none',
            'vat_rate'             => 20,
            'default_currency'     => '₽',
            'next_number'          => 1,
            'number_prefix'        => '',
            'number_suffix'        => '',
            'default_payment_days' => 3,
            'contract_text'        => "Оплата данного счёта означает согласие с условиями поставки товара.\nУведомление об оплате обязательно, в противном случае не гарантируется наличие товара на складе.",
            'overdue_alert_days'   => 3,
            'smtp_from'            => $s['email'] ?? '',
            'theme_color'          => '#0ea5e9',
            'show_logo_in_doc'     => true,
            'show_stamp'           => true,
            'show_qr'              => true,
            // Рекламные блоки
            'ad_top_enabled'       => false,
            'ad_top_type'          => 'text',   // image | text
            'ad_top_image_url'     => '',
            'ad_top_text'          => '',
            'ad_top_link'          => '',
            'ad_bottom_enabled'    => false,
            'ad_bottom_type'       => 'text',
            'ad_bottom_image_url'  => '',
            'ad_bottom_text'       => 'Спасибо за оплату! При следующем заказе скидка 5%.',
            'ad_bottom_link'       => '',
            // Синхронизация с клиентами
            'auto_sync_clients'    => true,
        ],
    ];
    writeDB($moduleDB);
}

// Дополняем настройки новыми полями если их нет (миграция)
$_invDefaults = [
    'show_qr'           => true,
    'ad_top_enabled'    => false, 'ad_top_type'   => 'text',
    'ad_top_image_url'  => '',    'ad_top_text'   => '',  'ad_top_link'  => '',
    'ad_bottom_enabled' => false, 'ad_bottom_type'=> 'text',
    'ad_bottom_image_url'=> '',   'ad_bottom_text'=> 'Спасибо за оплату!',
    'ad_bottom_link'    => '',
    'auto_sync_clients' => true,
];
foreach ($_invDefaults as $_k => $_v) {
    if (!isset($moduleDB['invoice_pro']['settings'][$_k])) {
        $moduleDB['invoice_pro']['settings'][$_k] = $_v;
    }
}

$settings = $moduleDB['invoice_pro']['settings'] ?? [];
$index    = $moduleDB['invoice_pro']['index']    ?? [];

if (!($settings['enabled'] ?? true)
    && !in_array($moduleAction, ['get_settings', 'save_settings'])) {
    echo json_encode(['ok' => false, 'error' => 'Модуль отключён']);
    exit;
}

// ================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ================================================================
function invMoney($n) {
    return number_format((float)$n, 2, ',', ' ');
}

function invGenNumber(array $s) {
    $pfx  = $s['number_prefix'] ?? '';
    $sfx  = $s['number_suffix'] ?? '';
    $next = (int)($s['next_number'] ?? 1);
    return $pfx . str_pad((string)$next, 4, '0', STR_PAD_LEFT) . $sfx;
}

function invCalc(array $items, $vatType, $vatRate, $discount = 0, $discountType = 'none') {
    $sub = 0.0;
    foreach ($items as $it) {
        $sub += round((float)($it['price'] ?? 0) * (float)($it['qty'] ?? 1), 2);
    }
    // Применяем скидку
    $discountAmt = 0.0;
    if ($discountType === 'percent' && $discount > 0) {
        $discountAmt = round($sub * $discount / 100, 2);
        $sub = $sub - $discountAmt;
    } elseif ($discountType === 'fixed' && $discount > 0) {
        $discountAmt = min((float)$discount, $sub);
        $sub = $sub - $discountAmt;
    }
    $vat = 0.0; $total = $sub;
    if ($vatType === 'on_top') {
        $vat   = round($sub * $vatRate / 100, 2);
        $total = $sub + $vat;
    } elseif ($vatType === 'included') {
        $vat = round($sub * $vatRate / (100 + $vatRate), 2);
    }
    return [
        'subtotal'     => $sub,
        'vat'          => $vat,
        'total'        => $total,
        'discount_amt' => $discountAmt,
    ];
}

function invUpIndex(array &$db, array $inv) {
    $idx   = &$db['invoice_pro']['index'];
    $found = false;
    $row   = [
        'id'               => $inv['id'],
        'number'           => $inv['number'],
        'date'             => $inv['date'],
        'due_date'         => $inv['due_date']        ?? '',
        'client_name'      => $inv['buyer']['name']   ?? '',
        'client_phone'     => $inv['buyer']['phone']  ?? '',
        'client_email'     => $inv['buyer']['email']  ?? '',
        'client_id'        => $inv['client_id']       ?? null,
        'total'            => $inv['total'],
        'status'           => $inv['status'],
        'updatedAt'        => $inv['updatedAt'],
        'createdAt'        => $inv['createdAt'],
        'order_id'         => $inv['order_id']        ?? null,
        'has_closing_docs' => !empty($inv['has_closing_docs']),
        'manager'          => $inv['manager']         ?? '',
    ];
    foreach ($idx as &$i) {
        if ($i['id'] === $inv['id']) { $i = $row; $found = true; break; }
    }
    unset($i);
    if (!$found) $idx[] = $row;
    writeDB($db);
}

function invRmIndex(array &$db, $id) {
    $db['invoice_pro']['index'] = array_values(
        array_filter($db['invoice_pro']['index'], fn($i) => $i['id'] !== $id)
    );
    writeDB($db);
}

function invCheckOverdue(array $inv) {
    if (!in_array($inv['status'] ?? '', ['draft', 'sent'])) return $inv;
    $due = $inv['due_date'] ?? '';
    if (!$due) return $inv;
    if (strtotime($due) < mktime(0, 0, 0)) {
        $inv['status']    = 'overdue';
        $inv['updatedAt'] = date('c');
    }
    return $inv;
}

function invSaveHtml($id, $html) {
    if (!is_dir(INV_UPLOAD)) mkdir(INV_UPLOAD, 0755, true);
    $name  = 'inv_' . preg_replace('/[^a-z0-9_\-]/i', '', $id) . '.html';
    file_put_contents(INV_UPLOAD . $name, $html, LOCK_EX);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/data/uploads/invoices/' . $name;
}

function invSendEmail($to, $subject, $body, array $settings) {
    if (!$to) return false;
    $from    = $settings['smtp_from'] ?? ($settings['seller_email'] ?? '');
    $name    = $settings['seller_name'] ?? '';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>',
        'Reply-To: ' . $from,
    ]);
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function invNum2Str($num) {
    $n     = number_format((float)$num, 2, '.', '');
    $parts = explode('.', $n);
    $rub   = (int)$parts[0];
    $kop   = (int)$parts[1];
    $n1    = ['','один','два','три','четыре','пять','шесть','семь','восемь','девять'];
    $n1f   = ['','одна','две','три','четыре','пять','шесть','семь','восемь','девять'];
    $n2    = ['','десять','двадцать','тридцать','сорок','пятьдесят','шестьдесят','семьдесят','восемьдесят','девяносто'];
    $n3    = ['','сто','двести','триста','четыреста','пятьсот','шестьсот','семьсот','восемьсот','девятьсот'];
    $n11   = ['','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать','шестнадцать','семнадцать','восемнадцать','девятнадцать'];
    $mil   = [
        ['','тысяча','тысячи','тысяч', true],
        ['','миллион','миллиона','миллионов', false],
        ['','миллиард','миллиарда','миллиардов', false],
    ];
    $wordNum = function($v, $fem) use ($n1,$n1f,$n2,$n3,$n11) {
        if ($v === 0) return '';
        $r = '';
        $h = (int)floor($v / 100);
        if ($h) $r .= $n3[$h] . ' ';
        $t = (int)floor(($v % 100) / 10);
        $o = $v % 10;
        if ($t === 1 && $o > 0) { $r .= $n11[$o] . ' '; }
        else {
            if ($t) $r .= $n2[$t] . ' ';
            if ($o) $r .= ($fem ? $n1f : $n1)[$o] . ' ';
        }
        return trim($r);
    };
    $plural = function($n, $f) {
        $n  = abs($n) % 100;
        $n1x = $n % 10;
        if ($n > 10 && $n < 20) return $f[3];
        if ($n1x === 1) return $f[1];
        if ($n1x >= 2 && $n1x <= 4) return $f[2];
        return $f[3];
    };
    $grps = [
        (int)floor($rub / 1000000000),
        (int)floor(($rub % 1000000000) / 1000000),
        (int)floor(($rub % 1000000) / 1000),
        $rub % 1000,
    ];
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $g = $grps[$i];
        if (!$g) continue;
        $m     = $mil[2 - $i];
        $out[] = $wordNum($g, $m[4]) . ' ' . $plural($g, $m);
    }
    $out[] = $wordNum($grps[3], false);
    $result = trim(implode(' ', array_filter($out)));
    if (!$result) $result = 'ноль';
    $rPlural = $plural($rub, ['','рубль','рубля','рублей']);
    $kPlural = $plural($kop, ['','копейка','копейки','копеек']);
    return mb_strtoupper(mb_substr($result, 0, 1)) . mb_substr($result, 1)
        . ' ' . $rPlural . ' ' . str_pad((string)$kop, 2, '0', STR_PAD_LEFT) . ' ' . $kPlural;
}

// ================================================================
// РЕНДЕР ДОКУМЕНТОВ — диспетчер по типу
// ================================================================
function invRenderDoc(array $inv, array $settings, string $type = 'invoice'): string {
    switch ($type) {
        case 'act':      return invRenderAct($inv, $settings);
        case 'delivery': return invRenderDelivery($inv, $settings);
        case 'upd':      return invRenderUPD($inv, $settings);
        default:         return invRenderInvoice($inv, $settings);
    }
}

// ================================================================
// СЧЁТ НА ОПЛАТУ — оригинальный шаблон
// ================================================================
function invRenderInvoice(array $inv, array $settings): string {
    $s   = $inv['seller']  ?? [];
    $b   = $inv['buyer']   ?? [];
    $items  = $inv['items']    ?? [];
    $cur    = $inv['currency'] ?? '₽';
    $vt     = $inv['vat_type'] ?? 'none';
    $vr     = (float)($inv['vat_rate'] ?? 20);

    $stamp  = $settings['stamp_url']     ?? '';
    $sign   = $settings['signature_url'] ?? '';
    $logo   = $settings['logo_url']      ?? '';
    $sigName  = $settings['signatory_name']  ?? '';
    $sigTitle = $settings['signatory_title'] ?? 'Директор';
    $accName  = $settings['accountant_name'] ?? $sigName;
    $color    = $settings['theme_color']     ?? '#0ea5e9';
    $showQR   = (bool)($settings['show_qr']  ?? true);
    $showStamp = (bool)($settings['show_stamp'] ?? true);
    $showLogo  = (bool)($settings['show_logo_in_doc'] ?? true);

    $adTopEnabled = (bool)($settings['ad_top_enabled'] ?? false);
    $adTopType    = $settings['ad_top_type']      ?? 'text';
    $adTopImgUrl  = $settings['ad_top_image_url'] ?? '';
    $adTopText    = $settings['ad_top_text']       ?? '';
    $adTopLink    = $settings['ad_top_link']       ?? '';
    $adBotEnabled = (bool)($settings['ad_bottom_enabled'] ?? false);
    $adBotType    = $settings['ad_bottom_type']      ?? 'text';
    $adBotImgUrl  = $settings['ad_bottom_image_url'] ?? '';
    $adBotText    = $settings['ad_bottom_text']       ?? '';
    $adBotLink    = $settings['ad_bottom_link']       ?? '';

    $months = ['','января','февраля','марта','апреля','мая','июня',
               'июля','августа','сентября','октября','ноября','декабря'];
    $fmtDate = function($d) use ($months) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m))
            return '«'.$m[1].'» '.$months[(int)$m[2]].' '.$m[3].' г.';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m))
            return '«'.$m[3].'» '.$months[(int)$m[2]].' '.$m[1].' г.';
        return $d;
    };

    $rows = '';
    $i    = 1;
    foreach ($items as $it) {
        $price = (float)($it['price'] ?? 0);
        $qty   = (float)($it['qty']   ?? 1);
        $sm    = round($price * $qty, 2);
        $itVat = '—';
        if ($vt === 'on_top')       $itVat = invMoney(round($sm * $vr / 100, 2));
        elseif ($vt === 'included') $itVat = invMoney(round($sm * $vr / (100 + $vr), 2));
        $itTotal = $vt === 'on_top'
            ? invMoney($sm + round($sm * $vr / 100, 2))
            : invMoney($sm);
        $rows .= '<tr>
        <td class="c">'.$i.'</td>
        <td class="l">'.htmlspecialchars($it['name'] ?? '').'</td>
        <td class="c">'.htmlspecialchars($it['unit'] ?? 'шт').'</td>
        <td class="c">'.htmlspecialchars((string)$qty).'</td>
        <td class="r">'.invMoney($price).'</td>
        <td class="r">'.invMoney($sm).'</td>
        <td class="c">'.($vt !== 'none' ? $vr.'%' : '—').'</td>
        <td class="r">'.$itVat.'</td>
        <td class="r bold">'.$itTotal.'</td>
        </tr>';
        $i++;
    }
    $itemCount  = count($items);
    $sub        = (float)($inv['subtotal']    ?? 0);
    $vatAmt     = (float)($inv['vat_amount']  ?? 0);
    $total      = (float)($inv['total']       ?? 0);
    $discountAmt= (float)($inv['discount_amt']?? 0);

    $bankBlock = '
    <table class="bank-block">
    <tr>
        <td colspan="2" class="bank-name">'.htmlspecialchars($s['bank_name'] ?? '').'</td>
        <td class="bank-label">БИК</td>
        <td class="bank-val">'.htmlspecialchars($s['bank_bik'] ?? '').'</td>
    </tr>
    <tr>
        <td colspan="2" class="bank-sub">Банк получателя</td>
        <td class="bank-label">Сч. №</td>
        <td class="bank-val">'.htmlspecialchars($s['bank_kor'] ?? '').'</td>
    </tr>
    <tr>
        <td class="bank-inn">ИНН&nbsp;'.htmlspecialchars($s['inn'] ?? '').'</td>
        <td class="bank-kpp">КПП&nbsp;'.htmlspecialchars($s['kpp'] ?? '').'</td>
        <td class="bank-label">Сч. №</td>
        <td class="bank-val">'.htmlspecialchars($s['bank_acc'] ?? '').'</td>
    </tr>
    <tr>
        <td colspan="2" class="bank-rcpt-name">'.htmlspecialchars($s['name'] ?? '').'</td>
        <td colspan="2" class="bank-rcpt-label">Получатель</td>
    </tr>
    </table>';

    $purpose = $inv['payment_purpose']
        ?? ('Оплата по счёту № '.($inv['number'] ?? '').' от '.($inv['date'] ?? ''));
    $purposeBlock = '<div class="purpose-block">Назначение платежа: <b>"'
        .htmlspecialchars($purpose).'"</b></div>';

   $qrHtml = '';
if ($showQR) {
    $qrStr    = invGenSBPString($inv, $settings);
    $qrStrJs  = json_encode($qrStr);
    $qrHtml   = '
    <div class="qr-block">
        <div class="qr-label">QR для оплаты (СБП)</div>
        <canvas id="inv-qr-' . $inv['id'] . '" width="90" height="90"
            style="display:block;margin:4px auto;"></canvas>
        <div class="qr-sub">Наведите камеру<br>и оплатите онлайн</div>
    </div>
    <script>
    (function(){
        var canvasId = "inv-qr-' . $inv['id'] . '";
        var qrData   = ' . $qrStrJs . ';
        function renderQR() {
            var el = document.getElementById(canvasId);
            if (!el) return;
            QRCode.toCanvas(el, qrData, {
                width: 90, margin: 1,
                color: { dark: "#000000", light: "#ffffff" }
            }, function(err){ if(err) console.warn("QR err:", err); });
        }
        if (typeof QRCode !== "undefined") {
            renderQR();
        } else {
            var s  = document.createElement("script");
            s.src  = "https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js";
            s.onload = renderQR;
            s.onerror = function() {
                // Фолбэк — внешний сервис если CDN недоступен
                var el  = document.getElementById(canvasId);
                var img = document.createElement("img");
                img.src = "https://api.qrserver.com/v1/create-qr-code/?size=90x90&ecc=M&data="
                    + encodeURIComponent(qrData);
                img.width  = 90;
                img.height = 90;
                img.style.display = "block";
                if (el && el.parentNode) el.parentNode.replaceChild(img, el);
            };
            document.head.appendChild(s);
        }
    })();
    </script>';
}

    $sellerInfo = '<b>'.htmlspecialchars($s['name'] ?? '').'</b>'
        .(!empty($s['inn'])     ? ', ИНН&nbsp;'.htmlspecialchars($s['inn'])      : '')
        .(!empty($s['kpp'])     ? ', КПП&nbsp;'.htmlspecialchars($s['kpp'])      : '')
        .(!empty($s['address']) ? ', '.htmlspecialchars($s['address'])            : '')
        .(!empty($s['phone'])   ? ', тел.:&nbsp;'.htmlspecialchars($s['phone'])  : '')
        .(!empty($s['bank_acc'])
            ? ', р/с&nbsp;'.htmlspecialchars($s['bank_acc'])
              .(!empty($s['bank_name']) ? ' в '.htmlspecialchars($s['bank_name']) : '')
              .(!empty($s['bank_bik'])  ? ', БИК&nbsp;'.htmlspecialchars($s['bank_bik']) : '')
            : '');

    $buyerInfo = '<b>'.htmlspecialchars($b['name'] ?? '').'</b>'
        .(!empty($b['inn'])     ? ', ИНН&nbsp;'.htmlspecialchars($b['inn'])     : '')
        .(!empty($b['kpp'])     ? ', КПП&nbsp;'.htmlspecialchars($b['kpp'])     : '')
        .(!empty($b['address']) ? ', '.htmlspecialchars($b['address'])           : '')
        .(!empty($b['phone'])   ? ', тел.:&nbsp;'.htmlspecialchars($b['phone']) : '');

    $totalsHtml = '<table class="totals-tbl">';
    $totalsHtml .= '<tr><td>Итого без НДС:</td><td>'.invMoney($sub).'</td></tr>';
    if ($discountAmt > 0)
        $totalsHtml .= '<tr><td>Скидка:</td><td>−'.invMoney($discountAmt).'</td></tr>';
    if ($vt !== 'none') {
        $vatLabel = $vt === 'on_top' ? 'НДС '.$vr.'% сверху:' : 'В т.ч. НДС '.$vr.'%:';
        $totalsHtml .= '<tr><td>'.$vatLabel.'</td><td>'.invMoney($vatAmt).'</td></tr>';
    } else {
        $totalsHtml .= '<tr><td>Без налога (НДС):</td><td>—</td></tr>';
    }
    $totalsHtml .= '<tr class="total-row"><td>Всего к оплате:</td><td>'
        .invMoney($total).'&nbsp;'.htmlspecialchars($cur).'</td></tr>';
    $totalsHtml .= '</table>';

    $stampHtml = '';
    if ($showStamp) {
        $stampHtml = $stamp
            ? '<img src="'.htmlspecialchars($stamp).'" class="stamp-img" alt="Печать">'
            : '<div class="stamp-placeholder">М.П.</div>';
    }
    $signHtml = $sign
        ? '<img src="'.htmlspecialchars($sign).'" class="sign-img" alt="Подпись">'
        : '';
    $logoHtml = ($logo && $showLogo)
        ? '<img src="'.htmlspecialchars($logo).'" class="logo-img" alt="Логотип">'
        : '';

    $adTopHtml = '';
    if ($adTopEnabled) {
        $inner = '';
        if ($adTopType === 'image' && $adTopImgUrl)
            $inner = '<img src="'.htmlspecialchars($adTopImgUrl).'"
                style="max-width:100%;max-height:80px;object-fit:contain;" alt="">';
        elseif ($adTopText)
            $inner = '<div style="font-size:11px;color:#1e3a5f;line-height:1.5;">'.nl2br(htmlspecialchars($adTopText)).'</div>';
        if ($inner) {
            $wrap = $adTopLink
                ? '<a href="'.htmlspecialchars($adTopLink).'" target="_blank" style="text-decoration:none;">'.$inner.'</a>'
                : $inner;
            $adTopHtml = '<div class="ad-block ad-top">'.$wrap.'</div>';
        }
    }
    $adBotHtml = '';
    if ($adBotEnabled) {
        $inner = '';
        if ($adBotType === 'image' && $adBotImgUrl)
            $inner = '<img src="'.htmlspecialchars($adBotImgUrl).'"
                style="max-width:100%;max-height:80px;object-fit:contain;" alt="">';
        elseif ($adBotText)
            $inner = '<div style="font-size:11px;color:#1e3a5f;line-height:1.5;">'.nl2br(htmlspecialchars($adBotText)).'</div>';
        if ($inner) {
            $wrap = $adBotLink
                ? '<a href="'.htmlspecialchars($adBotLink).'" target="_blank" style="text-decoration:none;">'.$inner.'</a>'
                : $inner;
            $adBotHtml = '<div class="ad-block ad-bot">'.$wrap.'</div>';
        }
    }

    $contractText = $inv['contract_text'] ?? '';
    $notes        = $inv['notes']         ?? '';

    return '<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8">
<title>Счёт на оплату № '.htmlspecialchars($inv['number'] ?? '').'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff;}
.wrap{max-width:210mm;margin:0 auto;padding:8mm 14mm 12mm 18mm;}
.bank-block{width:100%;border-collapse:collapse;border:1.5px solid #333;font-size:10px;margin-bottom:4px;}
.bank-block td{border:1px solid #555;padding:3px 7px;vertical-align:middle;}
.bank-name{font-weight:700;width:55%;}
.bank-sub{font-size:9px;color:#555;}
.bank-inn,.bank-kpp{white-space:nowrap;}
.bank-label{white-space:nowrap;font-size:9px;color:#555;}
.bank-val{font-weight:700;white-space:nowrap;}
.bank-rcpt-name{font-weight:700;}
.bank-rcpt-label{font-size:9px;color:#555;}
.purpose-block{border:1px solid #888;padding:4px 8px;margin-bottom:10px;font-size:10px;background:#fafafa;}
.doc-title{font-size:14px;font-weight:800;text-align:center;margin:10px 0 6px;color:'.$color.';}
.doc-underline{border-bottom:2px solid '.$color.';margin-bottom:10px;}
.parties-tbl{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:10px;}
.parties-tbl td{padding:3px 5px;vertical-align:top;line-height:1.6;}
.parties-label{width:110px;color:#555;font-weight:600;white-space:nowrap;}
table.items{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px;}
table.items th{background:#f0f0f0;border:1px solid #666;padding:4px 3px;text-align:center;font-size:9px;font-weight:700;}
table.items td{border:1px solid #888;padding:3px 4px;vertical-align:middle;}
table.items tr:nth-child(even) td{background:#fafafa;}
.l{text-align:left;}.c{text-align:center;}.r{text-align:right;}.bold{font-weight:700;}
.totals-wrap{display:flex;justify-content:flex-end;gap:16px;margin-bottom:8px;align-items:flex-start;}
.totals-tbl{border-collapse:collapse;font-size:10px;min-width:260px;}
.totals-tbl td{padding:3px 6px;border:1px solid #ccc;}
.totals-tbl td:first-child{text-align:right;color:#444;}
.totals-tbl td:last-child{text-align:right;font-weight:600;white-space:nowrap;}
.total-row td{border:2px solid #444!important;font-weight:800!important;font-size:12px!important;background:#f5f5f5;}
.amount-words{font-size:10px;margin-bottom:14px;line-height:1.7;padding:6px 0;border-top:1px solid #ccc;border-bottom:1px solid #ccc;}
.qr-block{text-align:center;border:1px solid #ddd;padding:6px;border-radius:6px;min-width:110px;}
.qr-label{font-size:8px;font-weight:700;color:#0ea5e9;margin-bottom:3px;}
.qr-sub{font-size:7px;color:#888;margin-top:3px;line-height:1.4;}
.contract-block{font-size:9px;color:#444;border-top:1px solid #ccc;margin-top:14px;padding-top:7px;line-height:1.7;}
.sign-block{display:flex;gap:20px;margin-top:24px;align-items:flex-end;flex-wrap:wrap;}
.sign-col{flex:1;min-width:180px;}
.sign-title{font-size:10px;font-weight:700;margin-bottom:8px;}
.sign-stamp-row{display:flex;align-items:flex-end;gap:12px;}
.sign-line{display:inline-block;border-bottom:1px solid #333;width:150px;vertical-align:bottom;}
.sign-name{font-size:9px;color:#555;margin-top:3px;}
.stamp-img{max-height:88px;max-width:108px;opacity:.9;}
.stamp-placeholder{width:84px;height:84px;border:2px dashed #bbb;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#aaa;font-size:9px;}
.sign-img{max-height:30px;max-width:115px;vertical-align:bottom;}
.logo-img{max-height:48px;max-width:150px;}
.ad-block{padding:8px 12px;margin:4px 0 8px;border-radius:4px;text-align:center;}
.ad-top{background:#eaf6ff;border:1px solid #bae6fd;}
.ad-bot{background:#f0fdf4;border:1px solid #bbf7d0;}
.no-print{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;
    margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:12px;}
@page{size:A4;margin:8mm 12mm 12mm 18mm;}
@media print{.no-print{display:none!important;}.wrap{padding:0;}body{font-size:10px;}}
</style>
</head><body>
<div class="wrap">
<div class="no-print">
  <span style="font-weight:700;">📄 Счёт на оплату № '.htmlspecialchars($inv['number'] ?? '').'</span>
  <button onclick="window.print()" style="margin-left:auto;background:'.$color.';color:#fff;
    border:none;border-radius:6px;padding:7px 18px;cursor:pointer;font-size:12px;font-weight:700;">
    🖨️ Печать / PDF</button>
  <button onclick="window.close()" style="background:#f1f5f9;border:1px solid #e2e8f0;
    border-radius:6px;padding:7px 14px;cursor:pointer;font-size:12px;">✕</button>
</div>
'.$adTopHtml.'
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:7px;">
  <div>'.$logoHtml.'</div>
  <div style="text-align:right;font-size:9px;color:#555;line-height:1.8;">
    '.(!empty($s['phone']) ? 'Тел.: '.htmlspecialchars($s['phone']).'<br>' : '').'
    '.(!empty($s['email']) ? htmlspecialchars($s['email']) : '').'
  </div>
</div>
'.$bankBlock.'
'.$purposeBlock.'
<div class="doc-title">Счёт на оплату № '.htmlspecialchars($inv['number'] ?? '').'
    от '.$fmtDate($inv['date'] ?? '').'</div>
<div class="doc-underline"></div>
<table class="parties-tbl">
<tr>
  <td class="parties-label">Поставщик (Исполнитель):</td>
  <td>'.$sellerInfo.'</td>
</tr>
<tr><td colspan="2" style="height:5px;border:none;"></td></tr>
<tr>
  <td class="parties-label">Покупатель (Заказчик):</td>
  <td>'.$buyerInfo.'</td>
</tr>
'.(!empty($inv['order_id'])
    ? '<tr><td class="parties-label">Основание:</td><td>Заказ № '.htmlspecialchars($inv['order_id']).'</td></tr>'
    : '').'
</table>
<table class="items">
<thead><tr>
  <th style="width:26px;">№</th>
  <th style="text-align:left;">Товары (работы, услуги)</th>
  <th style="width:36px;">Ед.</th>
  <th style="width:44px;">Кол-во</th>
  <th style="width:72px;">Цена,&nbsp;'.htmlspecialchars($cur).'</th>
  <th style="width:78px;">Сумма,&nbsp;'.htmlspecialchars($cur).'</th>
  <th style="width:36px;">НДС&nbsp;%</th>
  <th style="width:72px;">Сумма НДС</th>
  <th style="width:78px;">Итого с НДС</th>
</tr></thead>
<tbody>'.$rows.'</tbody>
</table>
<div class="totals-wrap">
  '.$qrHtml.'
  <div>'.$totalsHtml.'</div>
</div>
<div class="amount-words">
  <span style="color:#555;">Всего наименований '.$itemCount.', на сумму
    <b>'.invMoney($total).' '.htmlspecialchars($cur).'</b></span><br>
  <b>'.invNum2Str($total).'</b>
</div>
'.($contractText
    ? '<div class="contract-block">'.nl2br(htmlspecialchars($contractText))
      .(!empty($notes) ? '<br><b>Примечание:</b>&nbsp;'.htmlspecialchars($notes) : '')
      .'</div>'
    : (!empty($notes)
        ? '<div class="contract-block"><b>Примечание:</b>&nbsp;'.htmlspecialchars($notes).'</div>'
        : '')).'
<div class="sign-block">
  <div class="sign-col">
    <div class="sign-title">'.htmlspecialchars($sigTitle).'</div>
    <div class="sign-stamp-row">
      '.($showStamp ? $stampHtml : '').'
      <div>
        <div style="display:flex;align-items:flex-end;gap:8px;margin-bottom:4px;">
          '.$signHtml.'
          <span class="sign-line"></span>
        </div>
        <div class="sign-name">'.htmlspecialchars($sigTitle).'&nbsp;&nbsp;'.htmlspecialchars($sigName).'</div>
      </div>
    </div>
  </div>
  <div class="sign-col">
    <div class="sign-title">Главный бухгалтер</div>
    <div style="margin-top:32px;">
      <span class="sign-line"></span>
      <div class="sign-name">'.htmlspecialchars($accName).'</div>
    </div>
  </div>
</div>
'.$adBotHtml.'
</div></body></html>';
}

// ================================================================
// АКТ ВЫПОЛНЕННЫХ РАБОТ
// ================================================================
function invRenderAct(array $inv, array $settings): string {
    $s        = $inv['seller'] ?? [];
    $b        = $inv['buyer']  ?? [];
    $items    = $inv['items']  ?? [];
    $cur      = $inv['currency'] ?? '₽';
    $vt       = $inv['vat_type'] ?? 'none';
    $vr       = (float)($inv['vat_rate'] ?? 20);
    $color    = $settings['theme_color']     ?? '#0ea5e9';
    $sigName  = $settings['signatory_name']  ?? '';
    $sigTitle = $settings['signatory_title'] ?? 'Директор';
    $accName  = $settings['accountant_name'] ?? $sigName;
    $stamp    = $settings['stamp_url']       ?? '';
    $sign     = $settings['signature_url']   ?? '';
    $logo     = $settings['logo_url']        ?? '';
    $showLogo = (bool)($settings['show_logo_in_doc'] ?? true);

    $months  = ['','января','февраля','марта','апреля','мая','июня',
                'июля','августа','сентября','октября','ноября','декабря'];
    $fmtDate = function($d) use ($months) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m))
            return '«'.$m[1].'» '.$months[(int)$m[2]].' '.$m[3].' г.';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m))
            return '«'.$m[3].'» '.$months[(int)$m[2]].' '.$m[1].' г.';
        return $d;
    };

    $sub    = (float)($inv['subtotal']   ?? 0);
    $vatAmt = (float)($inv['vat_amount'] ?? 0);
    $total  = (float)($inv['total']      ?? 0);

    $rows = '';
    $i = 1;
    foreach ($items as $it) {
        $price = (float)($it['price'] ?? 0);
        $qty   = (float)($it['qty']   ?? 1);
        $sm    = round($price * $qty, 2);
        $itVat = $vt === 'none' ? 'Без НДС'
            : ($vt === 'on_top'
                ? invMoney(round($sm * $vr / 100, 2))
                : invMoney(round($sm * $vr / (100 + $vr), 2)));
        $rows .= '<tr>
        <td class="c">'.$i.'</td>
        <td class="l">'.htmlspecialchars($it['name'] ?? '').'</td>
        <td class="c">'.htmlspecialchars($it['unit'] ?? 'шт').'</td>
        <td class="c">'.htmlspecialchars((string)$qty).'</td>
        <td class="r">'.invMoney($price).'</td>
        <td class="r bold">'.invMoney($sm).'</td>
        <td class="c">'.($vt !== 'none' ? $vr.'%' : '—').'</td>
        <td class="r">'.$itVat.'</td>
        </tr>';
        $i++;
    }

    $logoHtml  = ($logo && $showLogo)
        ? '<img src="'.htmlspecialchars($logo).'" style="max-height:48px;max-width:150px;" alt="Лого">'
        : '';
    $stampHtml = $stamp
        ? '<img src="'.htmlspecialchars($stamp).'" style="max-height:88px;max-width:108px;opacity:.9;" alt="Печать">'
        : '<div style="width:84px;height:84px;border:2px dashed #bbb;border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;color:#aaa;font-size:9px;">М.П.</div>';
    $signHtml  = $sign
        ? '<img src="'.htmlspecialchars($sign).'" style="max-height:30px;max-width:115px;vertical-align:bottom;" alt="Подпись">'
        : '';

    return '<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Акт № '.htmlspecialchars($inv['number'] ?? '').'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;font-size:11px;color:#111;background:#fff;}
.wrap{max-width:210mm;margin:0 auto;padding:10mm 15mm 15mm 20mm;}
.doc-title{font-size:15px;font-weight:800;text-align:center;margin:8px 0 4px;color:'.$color.';}
.doc-sub{text-align:center;font-size:11px;color:#444;margin-bottom:14px;}
.divider{border:none;border-top:2px solid '.$color.';margin:10px 0;}
.parties{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:10px;}
.parties td{padding:4px 6px;vertical-align:top;line-height:1.7;}
.parties-label{width:130px;font-weight:700;color:#333;white-space:nowrap;}
table.items{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px;}
table.items th{background:#f0f0f0;border:1px solid #666;padding:5px 4px;
    text-align:center;font-size:9px;font-weight:700;}
table.items td{border:1px solid #888;padding:4px 5px;vertical-align:middle;}
table.items tr:nth-child(even) td{background:#fafafa;}
.l{text-align:left;}.c{text-align:center;}.r{text-align:right;}.bold{font-weight:700;}
.totals{margin-top:10px;display:flex;justify-content:flex-end;}
.totals-box{min-width:280px;border:1px solid #ccc;border-radius:4px;overflow:hidden;}
.totals-row{display:flex;justify-content:space-between;padding:5px 10px;
    border-bottom:1px solid #eee;font-size:11px;}
.totals-row.last{border-bottom:none;font-weight:800;font-size:13px;
    background:#f5f5f5;border-top:2px solid #333;}
.amount-words{margin:12px 0;padding:8px 10px;border:1px solid #ddd;
    background:#fafafa;font-size:10px;line-height:1.8;}
.sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:20px;}
.sign-title{font-size:10px;font-weight:700;margin-bottom:20px;color:#333;
    text-transform:uppercase;letter-spacing:.5px;}
.sign-row{display:flex;align-items:flex-end;gap:10px;margin-bottom:6px;}
.sign-line{flex:1;border-bottom:1px solid #333;}
.sign-name{font-size:9px;color:#555;text-align:center;margin-top:3px;}
.no-print{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;
    margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:12px;}
@page{size:A4;margin:10mm 12mm 15mm 20mm;}
@media print{.no-print{display:none!important;}.wrap{padding:0;}}
</style>
</head><body>
<div class="wrap">

<div class="no-print">
    <span style="font-weight:700;">📝 Акт № '.htmlspecialchars($inv['number'] ?? '').'</span>
    <button onclick="window.print()" style="margin-left:auto;background:'.$color.';color:#fff;
        border:none;border-radius:6px;padding:7px 18px;cursor:pointer;font-size:12px;font-weight:700;">
        🖨️ Печать / PDF</button>
    <button onclick="window.close()" style="background:#f1f5f9;border:1px solid #e2e8f0;
        border-radius:6px;padding:7px 14px;cursor:pointer;font-size:12px;">✕</button>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
    <div>'.$logoHtml.'</div>
    <div style="text-align:right;font-size:9px;color:#555;line-height:1.8;">
        '.(!empty($s['phone']) ? 'Тел.: '.htmlspecialchars($s['phone']).'<br>' : '').'
        '.(!empty($s['email']) ? htmlspecialchars($s['email']) : '').'
    </div>
</div>

<div class="doc-title">АКТ ВЫПОЛНЕННЫХ РАБОТ (ОКАЗАНИЯ УСЛУГ)<br>№ '.htmlspecialchars($inv['number'] ?? '').'</div>
<div class="doc-sub">'.$fmtDate($inv['date'] ?? '').'</div>
<hr class="divider">

<table class="parties">
<tr>
    <td class="parties-label">Исполнитель:</td>
    <td><b>'.htmlspecialchars($s['name'] ?? '').'</b>
        '.(!empty($s['inn'])     ? ', ИНН&nbsp;'.htmlspecialchars($s['inn'])     : '').'
        '.(!empty($s['kpp'])     ? ', КПП&nbsp;'.htmlspecialchars($s['kpp'])     : '').'
        '.(!empty($s['address']) ? '<br>'.htmlspecialchars($s['address'])         : '').'
        '.(!empty($s['phone'])   ? '<br>Тел.:&nbsp;'.htmlspecialchars($s['phone']): '').'
    </td>
</tr>
<tr>
    <td class="parties-label">Заказчик:</td>
    <td><b>'.htmlspecialchars($b['name'] ?? '').'</b>
        '.(!empty($b['inn'])     ? ', ИНН&nbsp;'.htmlspecialchars($b['inn'])     : '').'
        '.(!empty($b['kpp'])     ? ', КПП&nbsp;'.htmlspecialchars($b['kpp'])     : '').'
        '.(!empty($b['address']) ? '<br>'.htmlspecialchars($b['address'])         : '').'
    </td>
</tr>
<tr>
    <td class="parties-label">Основание:</td>
    <td>Счёт № '.htmlspecialchars($inv['number'] ?? '').' от '.$fmtDate($inv['date'] ?? '').'
        '.(!empty($inv['order_id']) ? ', Заказ № '.htmlspecialchars($inv['order_id']) : '').'
    </td>
</tr>
</table>

<div style="font-size:11px;margin-bottom:10px;">
    Исполнитель сдал, а Заказчик принял следующие работы (услуги):
</div>

<table class="items">
<thead><tr>
    <th style="width:28px;">№</th>
    <th style="text-align:left;">Наименование работ (услуг)</th>
    <th style="width:36px;">Ед.</th>
    <th style="width:50px;">Кол-во</th>
    <th style="width:76px;">Цена, '.htmlspecialchars($cur).'</th>
    <th style="width:82px;">Сумма, '.htmlspecialchars($cur).'</th>
    <th style="width:40px;">НДС %</th>
    <th style="width:76px;">Сумма НДС</th>
</tr></thead>
<tbody>'.$rows.'</tbody>
</table>

<div class="totals">
    <div class="totals-box">
        <div class="totals-row">
            <span>Итого без НДС:</span>
            <span>'.invMoney($sub).' '.htmlspecialchars($cur).'</span>
        </div>
        '.($vt !== 'none'
            ? '<div class="totals-row"><span>'.($vt==='on_top'?'НДС '.$vr.'% сверху:':'В т.ч. НДС '.$vr.'%:').'</span>
               <span>'.invMoney($vatAmt).' '.htmlspecialchars($cur).'</span></div>'
            : '<div class="totals-row"><span>НДС:</span><span>Без налога</span></div>').'
        <div class="totals-row last">
            <span>ИТОГО к оплате:</span>
            <span>'.invMoney($total).' '.htmlspecialchars($cur).'</span>
        </div>
    </div>
</div>

<div class="amount-words">
    Всего наименований '.count($items).', на сумму <b>'.invMoney($total).' '.htmlspecialchars($cur).'</b><br>
    <b>'.invNum2Str($total).'</b>
</div>

<div style="font-size:11px;margin:14px 0;padding:10px;border:1px solid #ddd;
    background:#fafafa;border-radius:4px;">
    Вышеперечисленные работы (услуги) выполнены в полном объёме, в установленные сроки.
    Стороны претензий друг к другу не имеют.
</div>

<div class="sign-grid">
    <div>
        <div class="sign-title">Исполнитель</div>
        <div style="font-size:10px;margin-bottom:16px;color:#333;">
            '.htmlspecialchars($s['name'] ?? '').'
            '.(!empty($s['inn']) ? '<br>ИНН&nbsp;'.htmlspecialchars($s['inn']) : '').'
        </div>
        <div class="sign-row">
            <span style="font-size:10px;white-space:nowrap;">'.htmlspecialchars($sigTitle).':</span>
            '.$signHtml.'
            <span class="sign-line"></span>
        </div>
        <div class="sign-name">'.htmlspecialchars($sigName).'</div>
        <div style="margin-top:14px;">
            <div class="sign-row">
                <span style="font-size:10px;white-space:nowrap;">Гл. бухгалтер:</span>
                <span class="sign-line"></span>
            </div>
            <div class="sign-name">'.htmlspecialchars($accName).'</div>
        </div>
        <div style="margin-top:16px;">'.$stampHtml.'</div>
    </div>
    <div>
        <div class="sign-title">Заказчик</div>
        <div style="font-size:10px;margin-bottom:16px;color:#333;">
            '.htmlspecialchars($b['name'] ?? '').'
            '.(!empty($b['inn']) ? '<br>ИНН&nbsp;'.htmlspecialchars($b['inn']) : '').'
        </div>
        <div class="sign-row">
            <span style="font-size:10px;white-space:nowrap;">Руководитель:</span>
            <span class="sign-line"></span>
        </div>
        <div class="sign-name">'.htmlspecialchars($b['director'] ?? '').'</div>
        <div style="margin-top:14px;">
            <div class="sign-row">
                <span style="font-size:10px;white-space:nowrap;">Гл. бухгалтер:</span>
                <span class="sign-line"></span>
            </div>
        </div>
        <div style="margin-top:16px;">
            <div style="width:84px;height:84px;border:2px dashed #bbb;border-radius:50%;
                display:inline-flex;align-items:center;justify-content:center;
                color:#aaa;font-size:9px;">М.П.</div>
        </div>
    </div>
</div>

</div></body></html>';
}

// ================================================================
// ТОВАРНАЯ НАКЛАДНАЯ (ТОРГ-12)
// ================================================================
function invRenderDelivery(array $inv, array $settings): string {
    $s        = $inv['seller'] ?? [];
    $b        = $inv['buyer']  ?? [];
    $items    = $inv['items']  ?? [];
    $cur      = $inv['currency'] ?? '₽';
    $vt       = $inv['vat_type'] ?? 'none';
    $vr       = (float)($inv['vat_rate'] ?? 20);
    $color    = $settings['theme_color']     ?? '#0ea5e9';
    $sigName  = $settings['signatory_name']  ?? '';
    $sigTitle = $settings['signatory_title'] ?? 'Директор';
    $accName  = $settings['accountant_name'] ?? $sigName;
    $stamp    = $settings['stamp_url']       ?? '';
    $sign     = $settings['signature_url']   ?? '';

    $months  = ['','января','февраля','марта','апреля','мая','июня',
                'июля','августа','сентября','октября','ноября','декабря'];
    $fmtDate = function($d) use ($months) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m))
            return $m[1].'.'.$m[2].'.'.$m[3];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m))
            return $m[3].'.'.$m[2].'.'.$m[1];
        return $d;
    };

    $sub    = (float)($inv['subtotal']   ?? 0);
    $vatAmt = (float)($inv['vat_amount'] ?? 0);
    $total  = (float)($inv['total']      ?? 0);
    $totalQty = array_sum(array_column($items, 'qty'));

    $rows = '';
    $i = 1;
    foreach ($items as $it) {
        $price = (float)($it['price'] ?? 0);
        $qty   = (float)($it['qty']   ?? 1);
        $sm    = round($price * $qty, 2);
        $itVat = $vt === 'none' ? '—'
            : ($vt === 'on_top'
                ? invMoney(round($sm * $vr / 100, 2))
                : invMoney(round($sm * $vr / (100 + $vr), 2)));
        $smWithVat = $vt === 'on_top'
            ? invMoney($sm + round($sm * $vr / 100, 2))
            : invMoney($sm);
        $rows .= '<tr>
        <td class="c">'.$i.'</td>
        <td class="l">'.htmlspecialchars($it['name'] ?? '').'</td>
        <td class="c" style="color:#888;font-size:8px;">—</td>
        <td class="c">'.htmlspecialchars($it['unit'] ?? 'шт').'</td>
        <td class="c">'.htmlspecialchars((string)$qty).'</td>
        <td class="r">'.invMoney($price).'</td>
        <td class="r">'.invMoney($sm).'</td>
        <td class="c">'.($vt !== 'none' ? $vr.'%' : '—').'</td>
        <td class="r">'.$itVat.'</td>
        <td class="r bold">'.$smWithVat.'</td>
        </tr>';
        $i++;
    }

    $stampHtml = $stamp
        ? '<img src="'.htmlspecialchars($stamp).'" style="max-height:70px;max-width:90px;opacity:.9;">'
        : '<div style="width:72px;height:72px;border:2px dashed #bbb;border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;
            color:#aaa;font-size:9px;">М.П.</div>';
    $signHtml = $sign
        ? '<img src="'.htmlspecialchars($sign).'" style="max-height:26px;max-width:100px;vertical-align:bottom;">'
        : '';

    return '<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Накладная № '.htmlspecialchars($inv['number'] ?? '').'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;font-size:9px;color:#111;background:#fff;}
.wrap{max-width:297mm;margin:0 auto;padding:6mm 8mm 10mm 12mm;}
.header{display:flex;justify-content:space-between;align-items:flex-start;
    border:1.5px solid #333;padding:8px 12px;margin-bottom:8px;}
.doc-title{font-size:13px;font-weight:800;color:'.$color.';margin-bottom:4px;}
.form-ref{font-size:8px;color:#888;text-align:right;line-height:1.6;}
.party-box{border:1px solid #999;padding:5px 8px;margin-bottom:5px;font-size:9px;line-height:1.6;}
.party-label{font-size:8px;font-weight:700;text-transform:uppercase;color:#666;margin-bottom:2px;}
table.items{width:100%;border-collapse:collapse;margin:8px 0;font-size:8px;}
table.items th{background:#f0f0f0;border:1px solid #666;padding:3px 2px;
    text-align:center;font-size:7px;font-weight:700;line-height:1.4;}
table.items td{border:1px solid #999;padding:2px 3px;vertical-align:middle;}
table.items tfoot td{font-weight:700;background:#f5f5f5;}
.l{text-align:left;}.c{text-align:center;}.r{text-align:right;}.bold{font-weight:700;}
.amount-words{margin:6px 0;padding:5px 8px;border:1px solid #ddd;
    background:#fafafa;font-size:9px;line-height:1.8;}
.sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
.sign-title{font-weight:700;margin-bottom:10px;font-size:9px;text-transform:uppercase;}
.sign-row{display:flex;align-items:flex-end;gap:6px;margin-bottom:6px;font-size:9px;}
.sign-line{flex:1;border-bottom:1px solid #333;}
.no-print{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;
    margin-bottom:10px;display:flex;align-items:center;gap:10px;font-size:12px;}
@page{size:A4 landscape;margin:6mm 8mm 10mm 12mm;}
@media print{.no-print{display:none!important;}.wrap{padding:0;}}
</style>
</head><body>
<div class="wrap">

<div class="no-print">
    <span style="font-weight:700;">🚚 Накладная № '.htmlspecialchars($inv['number'] ?? '').'</span>
    <button onclick="window.print()" style="margin-left:auto;background:'.$color.';color:#fff;
        border:none;border-radius:6px;padding:7px 18px;cursor:pointer;font-size:12px;font-weight:700;">
        🖨️ Печать / PDF</button>
    <button onclick="window.close()" style="background:#f1f5f9;border:1px solid #e2e8f0;
        border-radius:6px;padding:7px 14px;cursor:pointer;font-size:12px;">✕</button>
</div>

<div class="header">
    <div>
        <div class="doc-title">ТОВАРНАЯ НАКЛАДНАЯ № '.htmlspecialchars($inv['number'] ?? '').'</div>
        <div style="font-size:9px;">от '.$fmtDate($inv['date'] ?? '').'</div>
        '.(!empty($inv['order_id'])
            ? '<div style="font-size:9px;color:#555;margin-top:3px;">Основание: Заказ № '.htmlspecialchars($inv['order_id']).'</div>'
            : '').'
    </div>
    <div class="form-ref">
        Унифицированная форма № ТОРГ-12<br>
        Утверждена постановлением<br>
        Госкомстата России от 25.12.98 № 132
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
    <div>
        <div class="party-box">
            <div class="party-label">Грузоотправитель / Поставщик</div>
            <b>'.htmlspecialchars($s['name'] ?? '').'</b><br>
            '.(!empty($s['inn'])     ? 'ИНН&nbsp;'.htmlspecialchars($s['inn'])     : '').'
            '.(!empty($s['kpp'])     ? ' / КПП&nbsp;'.htmlspecialchars($s['kpp'])  : '').'<br>
            '.(!empty($s['address']) ? htmlspecialchars($s['address']).'<br>'       : '').'
            '.(!empty($s['phone'])   ? 'Тел.:&nbsp;'.htmlspecialchars($s['phone']) : '').'
        </div>
        <div class="party-box">
            <div class="party-label">Основание</div>
            Счёт № '.htmlspecialchars($inv['number'] ?? '').' от '.$fmtDate($inv['date'] ?? '').'
        </div>
    </div>
    <div>
        <div class="party-box">
            <div class="party-label">Грузополучатель / Покупатель</div>
            <b>'.htmlspecialchars($b['name'] ?? '').'</b><br>
            '.(!empty($b['inn'])     ? 'ИНН&nbsp;'.htmlspecialchars($b['inn'])    : '').'
            '.(!empty($b['kpp'])     ? ' / КПП&nbsp;'.htmlspecialchars($b['kpp']) : '').'<br>
            '.(!empty($b['address']) ? htmlspecialchars($b['address'])             : '').'
        </div>
        <div class="party-box">
            <div class="party-label">Плательщик</div>
            <b>'.htmlspecialchars($b['name'] ?? '').'</b>
            '.(!empty($b['inn']) ? ' ИНН&nbsp;'.htmlspecialchars($b['inn']) : '').'
        </div>
    </div>
</div>

<table class="items">
<thead><tr>
    <th style="width:24px;">№</th>
    <th style="text-align:left;">Наименование товара</th>
    <th style="width:38px;">Код</th>
    <th style="width:30px;">Ед.</th>
    <th style="width:42px;">Кол-во</th>
    <th style="width:66px;">Цена, '.htmlspecialchars($cur).'</th>
    <th style="width:72px;">Сумма без НДС</th>
    <th style="width:34px;">НДС %</th>
    <th style="width:66px;">НДС сумма</th>
    <th style="width:72px;">Итого с НДС</th>
</tr></thead>
<tbody>'.$rows.'</tbody>
<tfoot><tr>
    <td colspan="4" class="r" style="padding:4px;">Итого:</td>
    <td class="c">'.htmlspecialchars((string)$totalQty).'</td>
    <td></td>
    <td class="r">'.invMoney($sub).'</td>
    <td></td>
    <td class="r">'.invMoney($vatAmt).'</td>
    <td class="r bold">'.invMoney($total).'</td>
</tr></tfoot>
</table>

<div class="amount-words">
    Всего отпущено '.count($items).' наименований, на сумму
    <b>'.invMoney($total).' '.htmlspecialchars($cur).'</b><br>
    <b>'.invNum2Str($total).'</b>
</div>

<div class="sign-grid">
    <div>
        <div class="sign-title">Отпуск разрешил / Исполнитель</div>
        <div class="sign-row">
            <span>'.htmlspecialchars($sigTitle).':</span>
            '.$signHtml.'
            <span class="sign-line"></span>
        </div>
        <div style="font-size:8px;color:#555;margin-bottom:8px;">'.htmlspecialchars($sigName).'</div>
        <div class="sign-row">
            <span>Гл. бухгалтер:</span>
            <span class="sign-line"></span>
        </div>
        <div style="font-size:8px;color:#555;margin-bottom:8px;">'.htmlspecialchars($accName).'</div>
        <div class="sign-row">
            <span>Отпуск произвёл:</span>
            <span class="sign-line"></span>
        </div>
        <div style="margin-top:10px;">'.$stampHtml.'</div>
    </div>
    <div>
        <div class="sign-title">Груз получил / Покупатель</div>
        <div class="sign-row">
            <span>Доверенность №</span>
            <span class="sign-line"></span>
            <span>от</span>
            <span class="sign-line"></span>
        </div>
        <div class="sign-row" style="margin-top:8px;">
            <span>Должность:</span>
            <span class="sign-line"></span>
        </div>
        <div class="sign-row" style="margin-top:8px;">
            <span>Получил:</span>
            <span class="sign-line"></span>
        </div>
        <div style="font-size:8px;color:#555;margin-bottom:8px;">'.htmlspecialchars($b['director'] ?? '').'</div>
        <div style="margin-top:10px;">
            <div style="width:72px;height:72px;border:2px dashed #bbb;border-radius:50%;
                display:inline-flex;align-items:center;justify-content:center;
                color:#aaa;font-size:9px;">М.П.</div>
        </div>
    </div>
</div>

</div></body></html>';
}

// ================================================================
// УПД — УНИВЕРСАЛЬНЫЙ ПЕРЕДАТОЧНЫЙ ДОКУМЕНТ
// ================================================================
function invRenderUPD(array $inv, array $settings): string {
    $s        = $inv['seller'] ?? [];
    $b        = $inv['buyer']  ?? [];
    $items    = $inv['items']  ?? [];
    $cur      = $inv['currency'] ?? '₽';
    $vt       = $inv['vat_type'] ?? 'none';
    $vr       = (float)($inv['vat_rate'] ?? 20);
    $color    = $settings['theme_color']     ?? '#0ea5e9';
    $sigName  = $settings['signatory_name']  ?? '';
    $sigTitle = $settings['signatory_title'] ?? 'Директор';
    $accName  = $settings['accountant_name'] ?? $sigName;
    $stamp    = $settings['stamp_url']       ?? '';
    $sign     = $settings['signature_url']   ?? '';

    $fmtDate = function($d) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) return $m[1].'.'.$m[2].'.'.$m[3];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m))   return $m[3].'.'.$m[2].'.'.$m[1];
        return $d;
    };

    $sub    = (float)($inv['subtotal']   ?? 0);
    $vatAmt = (float)($inv['vat_amount'] ?? 0);
    $total  = (float)($inv['total']      ?? 0);

    $rows = '';
    $i = 1;
    foreach ($items as $it) {
        $price = (float)($it['price'] ?? 0);
        $qty   = (float)($it['qty']   ?? 1);
        $sm    = round($price * $qty, 2);
        $vatRate = $vt === 'none' ? 'Без НДС' : $vr.'%';
        $vatSum  = $vt === 'none' ? '—'
            : ($vt === 'on_top'
                ? invMoney(round($sm * $vr / 100, 2))
                : invMoney(round($sm * $vr / (100 + $vr), 2)));
        $smWithVat = $vt === 'on_top'
            ? invMoney($sm + round($sm * $vr / 100, 2))
            : invMoney($sm);
        $priceWithVat = $vt === 'on_top'
            ? invMoney($price + round($price * $vr / 100, 2))
            : invMoney($price);
        $rows .= '<tr>
        <td class="c">'.$i.'</td>
        <td class="l">'.htmlspecialchars($it['name'] ?? '').'</td>
        <td class="c" style="color:#888;">—</td>
        <td class="c">'.htmlspecialchars($it['unit'] ?? 'шт').'</td>
        <td class="c" style="color:#888;">796</td>
        <td class="c">'.htmlspecialchars((string)$qty).'</td>
        <td class="r">'.$priceWithVat.'</td>
        <td class="r">'.invMoney($sm).'</td>
        <td class="c">'.$vatRate.'</td>
        <td class="r">'.$vatSum.'</td>
        <td class="r bold">'.$smWithVat.'</td>
        </tr>';
        $i++;
    }

    $stampHtml = $stamp
        ? '<img src="'.htmlspecialchars($stamp).'" style="max-height:68px;max-width:88px;opacity:.9;">'
        : '<div style="width:68px;height:68px;border:2px dashed #bbb;border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;
            color:#aaa;font-size:8px;">М.П.</div>';
    $signHtml = $sign
        ? '<img src="'.htmlspecialchars($sign).'" style="max-height:24px;max-width:90px;vertical-align:bottom;">'
        : '';

    return '<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>УПД № '.htmlspecialchars($inv['number'] ?? '').'</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;font-size:8px;color:#111;background:#fff;}
.wrap{max-width:297mm;margin:0 auto;padding:5mm 7mm 8mm 10mm;}
.outer{border:2px solid #333;}
.hdr{display:grid;grid-template-columns:1fr 300px;border-bottom:1px solid #333;}
.hdr-left{padding:7px 10px;border-right:1px solid #333;}
.hdr-right{padding:7px 10px;}
.doc-title{font-size:12px;font-weight:800;color:'.$color.';margin-bottom:3px;}
.status-badge{display:inline-block;padding:2px 8px;border:1px solid '.$color.';
    border-radius:3px;font-size:9px;font-weight:700;color:'.$color.';margin-top:6px;}
.sf-title{font-size:10px;font-weight:800;text-align:center;margin-bottom:5px;}
.sf-cell{border:1px solid #bbb;padding:2px 4px;font-size:8px;line-height:1.5;}
.sf-label{font-size:7px;color:#888;}
.parties{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #333;}
.party{padding:5px 8px;font-size:8px;line-height:1.6;}
.party:first-child{border-right:1px solid #333;}
.party-label{font-size:7px;font-weight:700;text-transform:uppercase;color:#666;margin-bottom:2px;}
table.items{width:100%;border-collapse:collapse;font-size:7px;}
table.items th{background:#f0f0f0;border:1px solid #777;padding:2px 2px;
    text-align:center;font-size:6.5px;font-weight:700;line-height:1.3;}
table.items td{border:1px solid #aaa;padding:2px 2px;vertical-align:middle;}
table.items tfoot td{font-weight:700;background:#f5f5f5;}
.l{text-align:left;}.c{text-align:center;}.r{text-align:right;}.bold{font-weight:700;}
.signs{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #333;}
.sign-cell{padding:7px 10px;font-size:8px;}
.sign-cell:first-child{border-right:1px solid #333;}
.sign-title{font-weight:700;margin-bottom:7px;font-size:8px;text-transform:uppercase;}
.sign-row{display:flex;align-items:flex-end;gap:5px;margin-bottom:5px;}
.sign-line{flex:1;border-bottom:1px solid #333;}
.no-print{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;
    margin-bottom:8px;display:flex;align-items:center;gap:10px;font-size:12px;}
@page{size:A4 landscape;margin:5mm 7mm 8mm 10mm;}
@media print{.no-print{display:none!important;}.wrap{padding:0;}}
</style>
</head><body>
<div class="wrap">

<div class="no-print">
    <span style="font-weight:700;">📋 УПД № '.htmlspecialchars($inv['number'] ?? '').'</span>
    <button onclick="window.print()" style="margin-left:auto;background:'.$color.';color:#fff;
        border:none;border-radius:6px;padding:7px 18px;cursor:pointer;font-size:12px;font-weight:700;">
        🖨️ Печать / PDF</button>
    <button onclick="window.close()" style="background:#f1f5f9;border:1px solid #e2e8f0;
        border-radius:6px;padding:7px 14px;cursor:pointer;font-size:12px;">✕</button>
</div>

<div class="outer">

<div class="hdr">
    <div class="hdr-left">
        <div class="doc-title">УНИВЕРСАЛЬНЫЙ ПЕРЕДАТОЧНЫЙ ДОКУМЕНТ</div>
        <div style="font-size:8px;color:#555;margin-top:3px;">
            Счёт-фактура и передаточный документ (акт) в одном документе
        </div>
        <div class="status-badge">Статус 1: Счёт-фактура и передаточный документ</div>
    </div>
    <div class="hdr-right">
        <div class="sf-title">СЧЁТ-ФАКТУРА № '.htmlspecialchars($inv['number'] ?? '').'</div>
        <table style="width:100%;border-collapse:collapse;">
        <tr>
            <td class="sf-cell" style="width:50%;">
                <div class="sf-label">Дата</div>'.$fmtDate($inv['date'] ?? '').'
            </td>
            <td class="sf-cell">
                <div class="sf-label">Исправление</div>—
            </td>
        </tr>
        <tr>
            <td class="sf-cell" colspan="2">
                <div class="sf-label">Продавец</div>
                <b>'.htmlspecialchars($s['name'] ?? '').'</b>
            </td>
        </tr>
        <tr>
            <td class="sf-cell">
                <div class="sf-label">ИНН продавца</div>'.htmlspecialchars($s['inn'] ?? '—').'
            </td>
            <td class="sf-cell">
                <div class="sf-label">КПП продавца</div>'.htmlspecialchars($s['kpp'] ?? '—').'
            </td>
        </tr>
        <tr>
            <td class="sf-cell" colspan="2">
                <div class="sf-label">Покупатель</div>
                <b>'.htmlspecialchars($b['name'] ?? '').'</b>
            </td>
        </tr>
        <tr>
            <td class="sf-cell">
                <div class="sf-label">ИНН покупателя</div>'.htmlspecialchars($b['inn'] ?? '—').'
            </td>
            <td class="sf-cell">
                <div class="sf-label">КПП покупателя</div>'.htmlspecialchars($b['kpp'] ?? '—').'
            </td>
        </tr>
        <tr>
            <td class="sf-cell" colspan="2">
                <div class="sf-label">Валюта / Код</div>Российский рубль, 643
            </td>
        </tr>
        </table>
    </div>
</div>

<div class="parties">
    <div class="party">
        <div class="party-label">Поставщик / Исполнитель / Грузоотправитель</div>
        <b>'.htmlspecialchars($s['name'] ?? '').'</b><br>
        '.(!empty($s['inn'])     ? 'ИНН&nbsp;'.htmlspecialchars($s['inn'])    : '').'
        '.(!empty($s['kpp'])     ? ' КПП&nbsp;'.htmlspecialchars($s['kpp'])   : '').'<br>
        '.(!empty($s['address']) ? htmlspecialchars($s['address']).'<br>'      : '').'
        '.(!empty($s['bank_acc'])
            ? 'р/с&nbsp;'.htmlspecialchars($s['bank_acc'])
              .(!empty($s['bank_name']) ? ' в '.htmlspecialchars($s['bank_name']) : '')
              .(!empty($s['bank_bik'])  ? ', БИК&nbsp;'.htmlspecialchars($s['bank_bik']) : '')
            : '').'
    </div>
    <div class="party">
        <div class="party-label">Покупатель / Заказчик / Грузополучатель</div>
        <b>'.htmlspecialchars($b['name'] ?? '').'</b><br>
        '.(!empty($b['inn'])     ? 'ИНН&nbsp;'.htmlspecialchars($b['inn'])    : '').'
        '.(!empty($b['kpp'])     ? ' КПП&nbsp;'.htmlspecialchars($b['kpp'])   : '').'<br>
        '.(!empty($b['address']) ? htmlspecialchars($b['address']).'<br>'      : '').'
        '.(!empty($b['director'])? 'Рук.:&nbsp;'.htmlspecialchars($b['director']) : '').'
    </div>
</div>

<table class="items">
<thead><tr>
    <th style="width:22px;">№</th>
    <th style="text-align:left;">Наименование</th>
    <th style="width:32px;">Код</th>
    <th style="width:28px;">Ед.</th>
    <th style="width:34px;">ОКЕИ</th>
    <th style="width:40px;">Кол-во</th>
    <th style="width:62px;">Цена с НДС</th>
    <th style="width:68px;">Стоимость без НДС</th>
    <th style="width:38px;">Ставка НДС</th>
    <th style="width:62px;">Сумма НДС</th>
    <th style="width:68px;">Стоимость с НДС</th>
</tr></thead>
<tbody>'.$rows.'</tbody>
<tfoot><tr>
    <td colspan="7" class="r" style="padding:3px 4px;">Итого:</td>
    <td class="r">'.invMoney($sub).'</td>
    <td></td>
    <td class="r">'.invMoney($vatAmt).'</td>
    <td class="r bold">'.invMoney($total).'</td>
</tr></tfoot>
</table>

<div style="padding:5px 8px;font-size:8px;border-top:1px solid #eee;">
    Всего к оплате: <b>'.invMoney($total).' '.htmlspecialchars($cur).'</b>
    &nbsp;&nbsp;'.invNum2Str($total).'
</div>

<div class="signs">
    <div class="sign-cell">
        <div class="sign-title">Подпись продавца / исполнителя</div>
        <div class="sign-row">
            <span>'.htmlspecialchars($sigTitle).':</span>
            '.$signHtml.'
            <span class="sign-line"></span>
            <span>'.htmlspecialchars($sigName).'</span>
        </div>
        <div class="sign-row">
            <span>Гл. бухгалтер:</span>
            <span class="sign-line"></span>
            <span>'.htmlspecialchars($accName).'</span>
        </div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
            '.$stampHtml.'
            <div>
                <div style="font-size:8px;color:#666;">Дата отгрузки (передачи):</div>
                <div style="border-bottom:1px solid #333;width:100px;margin-top:4px;">
                    '.$fmtDate($inv['date'] ?? '').'</div>
            </div>
        </div>
    </div>
    <div class="sign-cell">
        <div class="sign-title">Подпись покупателя / заказчика</div>
        <div class="sign-row">
            <span>Руководитель:</span>
            <span class="sign-line"></span>
            <span>'.htmlspecialchars($b['director'] ?? '').'</span>
        </div>
        <div class="sign-row">
            <span>Гл. бухгалтер:</span>
            <span class="sign-line"></span>
        </div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
            <div style="width:68px;height:68px;border:2px dashed #bbb;border-radius:50%;
                display:inline-flex;align-items:center;justify-content:center;
                color:#aaa;font-size:8px;">М.П.</div>
            <div>
                <div style="font-size:8px;color:#666;">Дата приёмки:</div>
                <div style="border-bottom:1px solid #333;width:100px;margin-top:4px;">&nbsp;</div>
            </div>
        </div>
        <div style="margin-top:8px;padding:4px 6px;border:1px solid #ddd;
            border-radius:3px;background:#fafafa;font-size:8px;">
            Товар / результат работ принял:<br>
            <b>'.htmlspecialchars($b['name'] ?? '').'</b>
        </div>
    </div>
</div>

</div>

<div style="margin-top:6px;font-size:7px;color:#aaa;text-align:center;">
    УПД составлен в соответствии с Приложением № 1 к письму ФНС России от 21.10.2013 № ММВ-20-3/96@
</div>

</div></body></html>';
}

// ================================================================
// РОУТЕР
// ================================================================
switch ($moduleAction) {

case 'list': {
    $invData = invRead();
    $invoices = $invData['invoices'] ?? [];

    // Применяем фильтры
    $search = mb_strtolower($moduleBody['search'] ?? $moduleParams['search'] ?? '');
    $tag    = $moduleBody['tag']    ?? $moduleParams['tag']    ?? '';
    $status = $moduleBody['status'] ?? $moduleParams['status'] ?? '';

    if ($search) {
        $invoices = array_values(array_filter($invoices, function($i) use ($search) {
            return mb_strpos(mb_strtolower($i['number'] ?? ''), $search) !== false
                || mb_strpos(mb_strtolower($i['buyer']['name'] ?? ''), $search) !== false
                || mb_strpos(mb_strtolower($i['buyer']['phone'] ?? ''), $search) !== false
                || mb_strpos(mb_strtolower($i['buyer']['email'] ?? ''), $search) !== false;
        }));
    }

    if ($status) {
        $invoices = array_values(array_filter($invoices, function($i) use ($status) {
            return ($i['status'] ?? 'draft') === $status;
        }));
    }

    // Проверяем просроченные
    foreach ($invoices as &$inv) {
        $inv = invCheckOverdue($inv);
    }
    unset($inv);

    $total = count($invoices);
    $limit = (int)($moduleBody['limit'] ?? $moduleParams['limit'] ?? 50);
    $offset = (int)($moduleBody['offset'] ?? $moduleParams['offset'] ?? 0);

    if ($limit > 0) {
        $invoices = array_slice($invoices, $offset, $limit);
    }

    echo json_encode(['ok' => true, 'data' => array_values($invoices), 'total' => $total]);
    break;
}

    $total   = count($clients);
    $clients = array_values($clients);
    if ($limit > 0) $clients = array_slice($clients, 0, $limit);

    echo json_encode(['ok' => true, 'data' => $clients, 'total' => $total]);
    break;

    // ── ПОЛУЧИТЬ ОДИН СЧЁТ ────────────────────────────────────────
    case 'get': {
        $id      = $moduleParams['id'] ?? ($moduleBody['id'] ?? '');
        $invData = invRead();
        if (empty($invData['invoices'][$id])) {
            echo json_encode(['ok' => false, 'error' => 'Счёт не найден']);
            break;
        }
        $inv = invCheckOverdue($invData['invoices'][$id]);
        $inv['_closing_docs'] = array_values(
            array_filter($invData['closing_docs'], fn($d) => ($d['invoice_id'] ?? '') === $id)
        );
        echo json_encode(['ok' => true, 'data' => $inv]);
        break;
    }

    // ── СОХРАНИТЬ / СОЗДАТЬ СЧЁТ ──────────────────────────────────
    case 'save': {
        $isEdit   = !empty($moduleBody['id']);
        $invData  = invRead();
        $existInv = ($isEdit && isset($invData['invoices'][$moduleBody['id']]))
            ? $invData['invoices'][$moduleBody['id']]
            : [];

        // Идемпотентность для новых счетов
        $hash = '';
        if (!$isEdit) {
            $buyerName = $moduleBody['buyer_name'] ?? '';
            $date_h    = $moduleBody['date']       ?? '';
            $items_h   = $moduleBody['items']      ?? [];
            $hash = md5($buyerName . '|' . $date_h . '|' . implode(',', array_column($items_h, 'name')));
            foreach ($invData['invoices'] as $existing) {
                if (($existing['_hash'] ?? '') === $hash
                    && (time() - strtotime($existing['createdAt'] ?? '0')) < 30) {
                    echo json_encode(['ok' => true, 'data' => $existing]);
                    exit;
                }
            }
        }

        $id      = $isEdit ? $moduleBody['id'] : ('inv_' . time() . '_' . rand(100, 999));
        $items   = $moduleBody['items']         ?? [];
        $vatType = $moduleBody['vat_type']       ?? ($settings['default_vat']  ?? 'none');
        $vatRate = (float)($moduleBody['vat_rate'] ?? ($settings['vat_rate'] ?? 20));
        $discountVal  = (float)($moduleBody['discount']      ?? 0);
        $discountType = $moduleBody['discount_type']         ?? 'none';
        $calc    = invCalc($items, $vatType, $vatRate, $discountVal, $discountType);

        $defDays = (int)($settings['default_payment_days'] ?? 3);
        $dueRaw  = $moduleBody['due_date'] ?? date('Y-m-d', strtotime('+' . $defDays . ' days'));

        $dateRaw = $moduleBody['date'] ?? date('d.m.Y');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
            $dateRaw = date('d.m.Y', strtotime($dateRaw));
        }

        $inv = [
            'id'            => $id,
            'number'        => $moduleBody['number']
                               ?? ($isEdit ? ($existInv['number'] ?? '') : invGenNumber($settings)),
            'date'          => $dateRaw,
            'due_date'      => $dueRaw,
            'status'        => $moduleBody['status']
                               ?? ($isEdit ? ($existInv['status'] ?? 'draft') : 'draft'),
            'client_id'     => $moduleBody['client_id'] ?? ($existInv['client_id'] ?? null),
            'manager'       => $moduleBody['manager']   ?? ($existInv['manager']   ?? ''),
            'seller' => [
                'name'     => $moduleBody['seller_name']      ?? ($settings['seller_name']      ?? ''),
                'inn'      => $moduleBody['seller_inn']       ?? ($settings['seller_inn']       ?? ''),
                'kpp'      => $moduleBody['seller_kpp']       ?? ($settings['seller_kpp']       ?? ''),
                'ogrn'     => $moduleBody['seller_ogrn']      ?? ($settings['seller_ogrn']      ?? ''),
                'address'  => $moduleBody['seller_address']   ?? ($settings['seller_address']   ?? ''),
                'phone'    => $moduleBody['seller_phone']     ?? ($settings['seller_phone']     ?? ''),
                'email'    => $moduleBody['seller_email']     ?? ($settings['seller_email']     ?? ''),
                'bank_name'=> $moduleBody['seller_bank_name'] ?? ($settings['seller_bank_name'] ?? ''),
                'bank_bik' => $moduleBody['seller_bank_bik']  ?? ($settings['seller_bank_bik']  ?? ''),
                'bank_acc' => $moduleBody['seller_bank_acc']  ?? ($settings['seller_bank_acc']  ?? ''),
                'bank_kor' => $moduleBody['seller_bank_kor']  ?? ($settings['seller_bank_kor']  ?? ''),
            ],
            'buyer' => [
                'name'     => $moduleBody['buyer_name']     ?? '',
                'type'     => $moduleBody['buyer_type']     ?? 'company',
                'inn'      => $moduleBody['buyer_inn']      ?? '',
                'kpp'      => $moduleBody['buyer_kpp']      ?? '',
                'ogrn'     => $moduleBody['buyer_ogrn']     ?? '',
                'address'  => $moduleBody['buyer_address']  ?? '',
                'phone'    => $moduleBody['buyer_phone']    ?? '',
                'email'    => $moduleBody['buyer_email']    ?? '',
                'bank_name'=> $moduleBody['buyer_bank_name']?? '',
                'bank_bik' => $moduleBody['buyer_bank_bik'] ?? '',
                'bank_acc' => $moduleBody['buyer_bank_acc'] ?? '',
                'bank_kor' => $moduleBody['buyer_bank_kor'] ?? '',
                'director' => $moduleBody['buyer_director'] ?? '',
            ],
            'items'         => $items,
            'vat_type'      => $vatType,
            'vat_rate'      => $vatRate,
            'discount'      => $discountVal,
            'discount_type' => $discountType,
            'discount_amt'  => $calc['discount_amt'],
            'subtotal'      => $calc['subtotal'],
            'vat_amount'    => $calc['vat'],
            'total'         => $calc['total'],
            'total_words'   => invNum2Str($calc['total']),
            'currency'      => $moduleBody['currency']      ?? ($settings['default_currency'] ?? '₽'),
            'contract_text' => $moduleBody['contract_text'] ?? ($settings['contract_text'] ?? ''),
            'notes'         => $moduleBody['notes']         ?? '',
            'payment_purpose'=> $moduleBody['payment_purpose'] ?? '',
            'order_id'      => $moduleBody['order_id']      ?? ($existInv['order_id'] ?? null),
            'createdAt'     => $isEdit ? ($existInv['createdAt'] ?? date('c')) : date('c'),
            'updatedAt'     => date('c'),
            '_hash'         => $hash,
        ];

        if (!$isEdit) {
            $settings['next_number'] = ((int)($settings['next_number'] ?? 1)) + 1;
            $moduleDB['invoice_pro']['settings'] = $settings;
            writeDB($moduleDB);
        }
        $invData['invoices'][$id] = $inv;
        invWrite($invData);
        $moduleDB = readDB();
        invUpIndex($moduleDB, $inv);

        // Автосинхронизация с базой клиентов
        if ($settings['auto_sync_clients'] ?? true) {
            invSyncClient($moduleDB, $inv['buyer'], $inv['client_id']);
        }

        echo json_encode(['ok' => true, 'data' => $inv]);
        break;
    }

    // ── УДАЛИТЬ ───────────────────────────────────────────────────
    case 'delete': {
        $id = $moduleBody['id'] ?? ($moduleParams['id'] ?? '');
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Нет ID']); break; }
        $invData = invRead();
        unset($invData['invoices'][$id]);
        $invData['closing_docs'] = array_values(
            array_filter($invData['closing_docs'], fn($d) => ($d['invoice_id'] ?? '') !== $id)
        );
        invWrite($invData);
        $moduleDB = readDB();
        invRmIndex($moduleDB, $id);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── ИЗМЕНИТЬ СТАТУС ───────────────────────────────────────────
    case 'status': {
        $id = $moduleBody['id']     ?? '';
        $st = $moduleBody['status'] ?? '';
        if (!$id || !in_array($st, ['draft','sent','paid','cancelled','overdue'])) {
            echo json_encode(['ok' => false, 'error' => 'Неверный статус']);
            break;
        }
        $invData = invRead();
        if (empty($invData['invoices'][$id])) {
            echo json_encode(['ok' => false, 'error' => 'Не найден']);
            break;
        }
        $invData['invoices'][$id]['status']    = $st;
        $invData['invoices'][$id]['updatedAt'] = date('c');
        if ($st === 'paid') {
            $invData['invoices'][$id]['paidAt'] = date('c');
            $invData['invoices'][$id]['paid_amount'] = $moduleBody['paid_amount']
                ?? $invData['invoices'][$id]['total'];
        }
        invWrite($invData);
        $moduleDB = readDB();
        invUpIndex($moduleDB, $invData['invoices'][$id]);
        echo json_encode(['ok' => true, 'data' => $invData['invoices'][$id]]);
        break;
    }

    // ── ДОБАВИТЬ КОММЕНТАРИЙ ──────────────────────────────────────
    case 'add_comment': {
        $id   = $moduleBody['id']   ?? '';
        $text = $moduleBody['text'] ?? '';
        $user = $moduleBody['user'] ?? 'Менеджер';
        if (!$id || !$text) { echo json_encode(['ok'=>false,'error'=>'Нет данных']); break; }
        $invData = invRead();
        if (empty($invData['invoices'][$id])) {
            echo json_encode(['ok'=>false,'error'=>'Не найден']); break;
        }
        if (!isset($invData['invoices'][$id]['comments'])) {
            $invData['invoices'][$id]['comments'] = [];
        }
        $comment = [
            'id'   => 'c_' . time() . '_' . rand(100,999),
            'text' => $text,
            'user' => $user,
            'date' => date('c'),
        ];
        $invData['invoices'][$id]['comments'][] = $comment;
        $invData['invoices'][$id]['updatedAt']  = date('c');
        invWrite($invData);
        echo json_encode(['ok' => true, 'data' => $comment]);
        break;
    }

   case 'generate_pdf': {
    $id   = $moduleParams['id']       ?? ($moduleBody['id']       ?? '');
    $type = $moduleParams['doc_type'] ?? ($moduleBody['doc_type'] ?? 'invoice');
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Нет ID']); break; }
    $invData = invRead();
    if (empty($invData['invoices'][$id])) {
        echo json_encode(['ok' => false, 'error' => 'Не найден']);
        break;
    }
    // ✅ Возвращаем URL на публичный endpoint без авторизации
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url   = $proto . '://' . $host . '/api/api.php?action=invoice_html'
           . '&id=' . urlencode($id)
           . '&doc_type=' . urlencode($type);
    echo json_encode(['ok' => true, 'url' => $url]);
    break;
}

    // ── СОЗДАТЬ ЗАКРЫВАЮЩИЙ ДОКУМЕНТ ──────────────────────────────
    case 'create_closing_doc': {
        $invId = $moduleBody['invoice_id'] ?? '';
        $type  = $moduleBody['doc_type']   ?? '';
        if (!$invId || !in_array($type, ['act','delivery','upd'])) {
            echo json_encode(['ok' => false, 'error' => 'Неверный тип']);
            break;
        }
        $invData = invRead();
        if (empty($invData['invoices'][$invId])) {
            echo json_encode(['ok' => false, 'error' => 'Счёт не найден']);
            break;
        }
        $inv    = $invData['invoices'][$invId];
        $docId  = $type . '_' . time() . '_' . rand(100, 999);
        $docNum = 1;
        foreach ($invData['closing_docs'] as $d) {
            if (($d['invoice_id'] ?? '') === $invId) $docNum++;
        }
        $doc = [
            'id'         => $docId,
            'invoice_id' => $invId,
            'type'       => $type,
            'number'     => $inv['number'] . '/' . $docNum,
            'date'       => date('d.m.Y'),
            'items'      => $inv['items'],
            'total'      => $inv['total'],
            'subtotal'   => $inv['subtotal'],
            'vat_amount' => $inv['vat_amount'],
            'vat_type'   => $inv['vat_type'],
            'vat_rate'   => $inv['vat_rate'],
            'seller'     => $inv['seller'],
            'buyer'      => $inv['buyer'],
            'currency'   => $inv['currency'],
            'createdAt'  => date('c'),
        ];
        $invData['closing_docs'][] = $doc;
        $invData['invoices'][$invId]['has_closing_docs'] = true;
        invWrite($invData);

        $docInv           = $inv;
        $docInv['number'] = $doc['number'];
        $docInv['date']   = $doc['date'];
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $doc['url'] = $proto . '://' . $host
        . '/api/api.php?action=invoice_html'
        . '&id=' . urlencode($docId)
        . '&doc_type=' . urlencode($type);

        $moduleDB = readDB();
        invUpIndex($moduleDB, $invData['invoices'][$invId]);
        echo json_encode(['ok' => true, 'data' => $doc, 'url' => $url]);
        break;
    }

    // ── ПОЛУЧИТЬ ЗАКРЫВАЮЩИЕ ДОКУМЕНТЫ ───────────────────────────
    case 'get_closing_docs': {
        $invId   = $moduleParams['invoice_id'] ?? '';
        $invData = invRead();
        $docs    = array_values(
            array_filter($invData['closing_docs'], fn($d) => ($d['invoice_id'] ?? '') === $invId)
        );
        echo json_encode(['ok' => true, 'data' => $docs]);
        break;
    }

    // ── ЗАГРУЗИТЬ ИЗОБРАЖЕНИЕ (ЛОГО, ПЕЧАТЬ, ПОДПИСЬ, РЕКЛАМА) ───
   case 'upload_image': {
    // ✅ Читаем field из moduleBody ИЛИ из $_POST (для FormData через fetch)
    $field = $moduleBody['field'] ?? ($_POST['field'] ?? '');

    $allowed = ['stamp','signature','logo','ad_top_image','ad_bottom_image'];
    if (!in_array($field, $allowed)) {
        echo json_encode(['ok'=>false,'error'=>'Неверное поле: ' . $field]);
        break;
    }
        if (empty($_FILES['file']['tmp_name'])) {
            echo json_encode(['ok'=>false,'error'=>'Файл не загружен']);
            break;
        }
        $file = $_FILES['file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
            echo json_encode(['ok'=>false,'error'=>'Недопустимый формат: ' . $ext]);
            break;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['ok'=>false,'error'=>'Файл больше 5MB']);
            break;
        }
        if (!is_dir(INV_UPLOAD)) mkdir(INV_UPLOAD, 0755, true);
        $fname = $field . '_' . time() . '.' . $ext;
        $dest  = INV_UPLOAD . $fname;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo json_encode(['ok'=>false,'error'=>'Ошибка сохранения файла']);
            break;
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url   = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
               . '/data/uploads/invoices/' . $fname;
        // Сохраняем URL в настройки
        $settingKey = $field . '_url';
        $settings[$settingKey] = $url;
        $moduleDB['invoice_pro']['settings'] = $settings;
        writeDB($moduleDB);
        echo json_encode(['ok' => true, 'url' => $url]);
        break;
    }
    
       // ── АНАЛИТИКА ─────────────────────────────────────────────────
    case 'analytics': {
        $invData    = invRead();
        $month      = $moduleParams['month'] ?? date('Y-m');
        $funnel     = ['draft'=>0,'sent'=>0,'paid'=>0,'cancelled'=>0,'overdue'=>0];
        $monthTotal = 0.0;
        $monthPaid  = 0.0;
        $days       = [];
        $topClients = [];
        $topItems   = [];

        foreach ($invData['invoices'] as $inv) {
            $inv       = invCheckOverdue($inv);
            $d         = $inv['date']   ?? '';
            $st        = $inv['status'] ?? 'draft';
            $ym        = '';
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) {
                $ym = $m[3] . '-' . $m[2];
            } else {
                $ym = substr($d, 0, 7);
            }
            if (isset($funnel[$st])) $funnel[$st]++;
            $invTotal = (float)($inv['total'] ?? 0);
            if ($ym === $month) {
                $monthTotal += $invTotal;
                if ($st === 'paid') $monthPaid += $invTotal;
            }
            $dk = $d;
            if (!isset($days[$dk])) $days[$dk] = ['sent' => 0, 'paid' => 0];
            if ($st === 'paid') $days[$dk]['paid'] += $invTotal;
            else                $days[$dk]['sent'] += $invTotal;
            $cn = $inv['buyer']['name'] ?? '—';
            if (!isset($topClients[$cn])) $topClients[$cn] = ['name'=>$cn,'total'=>0,'count'=>0];
            $topClients[$cn]['total'] += $invTotal;
            $topClients[$cn]['count']++;
            // Топ позиций
            foreach ($inv['items'] ?? [] as $it) {
                $iName = $it['name'] ?? '—';
                if (!isset($topItems[$iName])) $topItems[$iName] = ['name'=>$iName,'qty'=>0,'total'=>0,'count'=>0];
                $topItems[$iName]['qty']   += (float)($it['qty']   ?? 1);
                $topItems[$iName]['total'] += round((float)($it['price']??0)*(float)($it['qty']??1),2);
                $topItems[$iName]['count']++;
            }
        }

        ksort($days);
        $heat = [];
        foreach ($days as $dk => $dv) {
            $heat[] = ['date'=>$dk,'sent'=>round($dv['sent']),'paid'=>round($dv['paid'])];
        }
        usort($topClients, fn($a, $b) => $b['total'] <=> $a['total']);
        usort($topItems,   fn($a, $b) => $b['total'] <=> $a['total']);

        $dayNum   = (int)date('d');
        $forecast = $dayNum > 0 ? round($monthPaid / $dayNum * 30, 2) : 0;

        $alerts    = [];
        $alertDays = (int)($settings['overdue_alert_days'] ?? 3);
        foreach ($index as $i) {
            if (($i['status'] ?? '') === 'sent' && !empty($i['due_date'])) {
                $diff = (strtotime($i['due_date']) - time()) / 86400;
                if ($diff <= $alertDays) {
                    $alerts[] = [
                        'id'        => $i['id'],
                        'number'    => $i['number'],
                        'client'    => $i['client_name'] ?? '',
                        'due'       => $i['due_date'],
                        'total'     => $i['total']       ?? 0,
                        'days_left' => (int)ceil($diff),
                    ];
                }
            }
        }
        echo json_encode([
            'ok'             => true,
            'funnel'         => $funnel,
            'heatmap'        => $heat,
            'month_total'    => round($monthTotal, 2),
            'month_paid'     => round($monthPaid, 2),
            'forecast'       => $forecast,
            'month'          => $month,
            'top_clients'    => array_values(array_slice($topClients, 0, 5)),
            'top_items'      => array_values(array_slice($topItems,   0, 5)),
            'overdue_alerts' => $alerts,
        ]);
        break;
    }

    // ── СЧЕТА КЛИЕНТА ─────────────────────────────────────────────
    case 'client_invoices': {
        $phone = $moduleParams['phone'] ?? ($moduleBody['phone'] ?? '');
        $name  = $moduleParams['name']  ?? ($moduleBody['name']  ?? '');
        $cid   = $moduleParams['client_id'] ?? ($moduleBody['client_id'] ?? '');
        $out   = [];
        foreach ($index as $i) {
            if (($cid && ($i['client_id'] ?? '') == $cid)
                || (!empty($phone) && ($i['client_phone'] ?? '') === $phone)
                || (!empty($name)  && mb_stripos($i['client_name'] ?? '', $name) !== false)) {
                $out[] = $i;
            }
        }
        $total = 0.0; $paid = 0.0;
        foreach ($out as $i) {
            $t      = (float)($i['total'] ?? 0);
            $total += $t;
            if (($i['status'] ?? '') === 'paid') $paid += $t;
        }
        echo json_encode([
            'ok'    => true,
            'data'  => $out,
            'stats' => ['count' => count($out), 'total' => $total, 'paid' => $paid],
        ]);
        break;
    }

    // ── ДУБЛИРОВАТЬ СЧЁТ ─────────────────────────────────────────
    case 'duplicate': {
        $id = $moduleBody['id'] ?? '';
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Нет ID']); break; }
        $invData = invRead();
        if (empty($invData['invoices'][$id])) {
            echo json_encode(['ok' => false, 'error' => 'Не найден']);
            break;
        }
        $orig  = $invData['invoices'][$id];
        $newId = 'inv_' . time() . '_' . rand(100, 999);
        $new   = $orig;
        $new['id']               = $newId;
        $new['number']           = invGenNumber($settings);
        $new['status']           = 'draft';
        $new['date']             = date('d.m.Y');
        $new['due_date']         = date('Y-m-d', strtotime('+' . (int)($settings['default_payment_days'] ?? 3) . ' days'));
        $new['createdAt']        = date('c');
        $new['updatedAt']        = date('c');
        $new['has_closing_docs'] = false;
        $new['order_id']         = null;
        $new['_hash']            = '';
        $new['comments']         = [];
        unset($new['paidAt'], $new['paid_amount']);
        $invData['invoices'][$newId] = $new;
        invWrite($invData);
        $settings['next_number'] = ((int)($settings['next_number'] ?? 1)) + 1;
        $moduleDB['invoice_pro']['settings'] = $settings;
        writeDB($moduleDB);
        $moduleDB = readDB();
        invUpIndex($moduleDB, $new);
        echo json_encode(['ok' => true, 'data' => $new]);
        break;
    }

    // ── ОТПРАВИТЬ EMAIL ───────────────────────────────────────────
    case 'send_email': {
        $id    = $moduleBody['id']    ?? '';
        $email = $moduleBody['email'] ?? '';
        if (!$id || !$email) {
            echo json_encode(['ok' => false, 'error' => 'Нет данных']);
            break;
        }
        $invData = invRead();
        if (empty($invData['invoices'][$id])) {
            echo json_encode(['ok' => false, 'error' => 'Не найден']);
            break;
        }
        $inv     = $invData['invoices'][$id];
        $html    = invRenderDoc($inv, $settings);
        $url     = invSaveHtml($id . '_invoice', $html);
        $subject = 'Счёт № ' . $inv['number'] . ' от ' . $inv['date'];
        $color   = $settings['theme_color'] ?? '#0ea5e9';
        $body    = '<p>Здравствуйте!</p>'
            . '<p>Направляем Вам счёт № <b>' . $inv['number'] . '</b> от ' . $inv['date']
            . ' на сумму <b>' . invMoney((float)$inv['total']) . ' ' . ($inv['currency'] ?? '₽') . '</b>.</p>'
            . '<p><a href="' . $url . '" style="background:' . $color . ';color:#fff;padding:10px 20px;'
            . 'border-radius:6px;text-decoration:none;font-weight:bold;">📄 Открыть счёт</a></p>'
            . '<p style="margin-top:16px;color:#555;">С уважением,<br>'
            . htmlspecialchars($settings['seller_name'] ?? '') . '</p>';
        $sent = invSendEmail($email, $subject, $body, $settings);
        if ($sent && ($inv['status'] ?? '') === 'draft') {
            $invData['invoices'][$id]['status']    = 'sent';
            $invData['invoices'][$id]['updatedAt'] = date('c');
            invWrite($invData);
            $moduleDB = readDB();
            invUpIndex($moduleDB, $invData['invoices'][$id]);
        }
        echo json_encode(['ok' => $sent, 'url' => $url]);
        break;
    }

    // ── ШАБЛОНЫ ПОЗИЦИЙ ───────────────────────────────────────────
    case 'save_template': {
        $name  = trim($moduleBody['name']  ?? '');
        $items = $moduleBody['items'] ?? [];
        if (!$name || !$items) {
            echo json_encode(['ok' => false, 'error' => 'Нет данных']);
            break;
        }
        $invData = invRead();
        $tplId   = 'tpl_' . time();
        $invData['templates'][] = [
            'id'        => $tplId,
            'name'      => $name,
            'items'     => $items,
            'createdAt' => date('c'),
        ];
        invWrite($invData);
        echo json_encode(['ok' => true, 'id' => $tplId]);
        break;
    }

    case 'get_templates': {
        $invData = invRead();
        echo json_encode(['ok' => true, 'data' => $invData['templates'] ?? []]);
        break;
    }

    case 'delete_template': {
        $tplId   = $moduleBody['id'] ?? '';
        $invData = invRead();
        $invData['templates'] = array_values(
            array_filter($invData['templates'] ?? [], fn($t) => $t['id'] !== $tplId)
        );
        invWrite($invData);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── РЕКЛАМНЫЕ БАННЕРЫ ─────────────────────────────────────────
    case 'save_ad_banner': {
        $pos  = $moduleBody['position'] ?? ''; // top | bottom
        $type = $moduleBody['type']     ?? 'text';
        $text = $moduleBody['text']     ?? '';
        $link = $moduleBody['link']     ?? '';
        $img  = $moduleBody['image_url']?? '';
        $on   = (bool)($moduleBody['enabled'] ?? false);
        if (!in_array($pos, ['top','bottom'])) {
            echo json_encode(['ok'=>false,'error'=>'Позиция: top или bottom']);
            break;
        }
        $settings['ad_' . $pos . '_enabled']   = $on;
        $settings['ad_' . $pos . '_type']      = $type;
        $settings['ad_' . $pos . '_text']      = $text;
        $settings['ad_' . $pos . '_link']      = $link;
        if ($img) $settings['ad_' . $pos . '_image_url'] = $img;
        $moduleDB['invoice_pro']['settings'] = $settings;
        writeDB($moduleDB);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── СЛЕДУЮЩИЙ НОМЕР ───────────────────────────────────────────
    case 'next_number':
        echo json_encode([
            'ok'     => true,
            'number' => invGenNumber($settings),
            'next'   => (int)($settings['next_number'] ?? 1),
        ]);
        break;

    // ── ПОЛУЧИТЬ НАСТРОЙКИ ────────────────────────────────────────
    case 'get_settings':
        echo json_encode(['ok' => true, 'data' => $settings]);
        break;

    // ── СОХРАНИТЬ НАСТРОЙКИ ───────────────────────────────────────
case 'save_settings': {
    // Получаем текущие настройки
    $settings = $moduleDB['invoice_pro']['settings'] ?? [];
    
    // Список разрешенных полей
    $allowed = [
        'enabled', 'seller_name', 'seller_inn', 'seller_kpp', 'seller_ogrn', 
        'seller_address', 'seller_phone', 'seller_email', 
        'seller_bank_name', 'seller_bank_bik', 'seller_bank_acc', 'seller_bank_kor',
        'signatory_name', 'signatory_title', 'accountant_name',
        'stamp_url', 'signature_url', 'logo_url',
        'default_vat', 'vat_rate', 'default_currency', 'next_number',
        'number_prefix', 'number_suffix', 'default_payment_days',
        'contract_text', 'overdue_alert_days', 'smtp_from',
        'theme_color', 'show_logo_in_doc', 'show_stamp', 'show_qr',
        'ad_top_enabled', 'ad_top_type', 'ad_top_image_url', 'ad_top_text', 'ad_top_link',
        'ad_bottom_enabled', 'ad_bottom_type', 'ad_bottom_image_url', 'ad_bottom_text', 'ad_bottom_link',
        'auto_sync_clients',
    ];
    
    // Обновляем только разрешенные поля
    $updated = false;
    foreach ($allowed as $k) {
        if (array_key_exists($k, $moduleBody)) {
            // Обработка булевых значений
            if (in_array($k, ['enabled', 'show_logo_in_doc', 'show_stamp', 'show_qr', 
                              'ad_top_enabled', 'ad_bottom_enabled', 'auto_sync_clients'])) {
                $settings[$k] = filter_var($moduleBody[$k], FILTER_VALIDATE_BOOLEAN);
            } else {
                $settings[$k] = $moduleBody[$k];
            }
            $updated = true;
        }
    }
    
    if (!$updated) {
        echo json_encode(['ok' => false, 'error' => 'Нет данных для обновления']);
        break;
    }
    
    // Сохраняем настройки
    $moduleDB['invoice_pro']['settings'] = $settings;
    
    // ✅ ПРОВЕРЯЕМ: записываем в файл
    $writeResult = writeDB($moduleDB);
    
    // Проверяем, что запись прошла успешно
    if ($writeResult === false) {
        echo json_encode(['ok' => false, 'error' => 'Ошибка записи в файл']);
        break;
    }
    
    // ✅ ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: читаем обратно
    $testRead = invRead();
    $savedSettings = $testRead['invoice_pro']['settings'] ?? [];
    
    echo json_encode([
        'ok' => true, 
        'data' => $savedSettings,
        'message' => 'Настройки сохранены'
    ]);
    break;
}

    default:
        echo json_encode(['ok' => false, 'error' => 'Неизвестное действие: ' . $moduleAction]);
}
?>
<!--MODULE_JS_START-->
<script>
(function () {
'use strict';
/* ═══════════════════════════════════════════════════════════
   INVOICE PRO v6.0
   ✅ Загрузка изображений — исправлена (через /admin/api.php?action=upload)
   ✅ Синхронизация с clients_pro — точный парсинг по phone/inn/email
   ✅ Дедупликация клиентов с предупреждением
   ✅ Правильный бланк счёта с QR-кодом
   ✅ Рекламные блоки верх/низ
   ✅ Скидка (% или сумма) в форме
   ✅ Назначение платежа
   ✅ Комментарии к счёту
   ✅ Обновление реквизитов из CRM
   ✅ Пагинация списка
   ✅ Топ позиций в аналитике
   ✅ Дебаунс поиска клиентов (300мс)
   ✅ Кэш клиентов
   ✅ Защита от двойного сохранения
   ✅ MutationObserver вместо setInterval
═══════════════════════════════════════════════════════════ */

// ── УТИЛИТЫ ──────────────────────────────────────────────
const esc = t => (t ?? '').toString()
    .replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const fmt = n => (typeof n === 'number' ? n : parseFloat(n ?? 0))
    .toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2});
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
function notify(msg, type) {
    if (window.notify && window.notify !== notify) { window.notify(msg, type||'info'); return; }
    console.log('[INV6]', type, msg);
}
const api = (action, body) => CRM.api('invoice_pro', action, body || {});
const gv  = id => { const e = document.getElementById(id); return e ? e.value : ''; };

// ── КЭШИ ─────────────────────────────────────────────────
const _clientCache = new Map();
const _clientById  = new Map();

// ── ИКОНКИ ───────────────────────────────────────────────
const I = {
    invoice:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
    plus:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`,
    search: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`,
    edit:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
    trash:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>`,
    eye:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
    pdf:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`,
    copy:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
    send:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`,
    check:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`,
    chart:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`,
    cog:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
    back:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>`,
    mail:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>`,
    user:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    alert:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    star:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
    history:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>`,
    phone:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 3.09 4.18 2 2 0 0 1 5.08 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L9.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>`,
    upload: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>`,
    tpl:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>`,
    spark:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>`,
    msg:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`,
    refresh:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>`,
    tag:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>`,
    percent:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>`,
};
const ico = (k, s) =>
    `<svg width="${s||16}" height="${s||16}" style="vertical-align:middle;flex-shrink:0;">${I[k]||''}</svg>`;

// ── СТАТУСЫ ───────────────────────────────────────────────
const ST = {
    draft:     {l:'Черновик',  bg:'#1e293b', bc:'#334155', c:'#94a3b8', dot:'#64748b'},
    sent:      {l:'Отправлен', bg:'#0c3549', bc:'#0e4d6a', c:'#38bdf8', dot:'#0ea5e9'},
    paid:      {l:'Оплачен',   bg:'#0f2d1a', bc:'#166534', c:'#4ade80', dot:'#22c55e'},
    overdue:   {l:'Просрочен', bg:'#2d1010', bc:'#7f1d1d', c:'#fca5a5', dot:'#ef4444'},
    cancelled: {l:'Отменён',   bg:'#1a1a1a', bc:'#334155', c:'#475569', dot:'#334155'},
};
const badge = s => {
    const t = ST[s] || ST.draft;
    return `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px 3px 8px;
        border-radius:20px;font-size:11px;font-weight:700;
        background:${t.bg};color:${t.c};border:1px solid ${t.bc};">
        <span style="width:7px;height:7px;border-radius:50%;background:${t.dot};"></span>${t.l}</span>`;
};

// ── СОСТОЯНИЕ ─────────────────────────────────────────────
const S = {
    settings:{}, invoices:[], filtered:[],
    view:'kanban', page:'dash', saving:false,
    accentColor:'#0ea5e9',
    page_offset: 0, page_limit: 50, total: 0,
};

// ── CSS ───────────────────────────────────────────────────
if (!document.getElementById('ip6css')) {
    const style = document.createElement('style');
    style.id = 'ip6css';
    style.textContent = `
    :root{--ip-accent:#0ea5e9;--ip-accent-dark:#0284c7;}
    .ip-card{background:var(--bg-card,#1e293b);border:1px solid var(--border,#334155);
        border-radius:14px;padding:16px;transition:box-shadow .2s;}
    .ip-sec{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.9px;
        color:#64748b;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
    .ip-in{width:100%;padding:8px 11px;border-radius:9px;
        border:1px solid var(--border,#334155);
        background:var(--bg-dark,#0f172a);color:var(--text,#f1f5f9);font-size:13px;
        box-sizing:border-box;transition:border-color .15s,box-shadow .15s;font-family:inherit;}
    .ip-in:focus{outline:none;border-color:var(--ip-accent,#0ea5e9);
        box-shadow:0 0 0 3px rgba(14,165,233,.15);}
    .ip-in::placeholder{color:#475569;}
    .ip-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;
        border:1px solid transparent;cursor:pointer;font-size:12px;font-weight:600;
        transition:all .15s;white-space:nowrap;font-family:inherit;line-height:1.2;}
    .ip-btn:active{transform:scale(.97);}
    .ip-btn-p{background:var(--ip-accent,#0ea5e9);color:#fff;
        border-color:var(--ip-accent-dark,#0284c7);box-shadow:0 2px 10px rgba(14,165,233,.3);}
    .ip-btn-p:hover{background:var(--ip-accent-dark,#0284c7);}
    .ip-btn-s{background:rgba(255,255,255,.06);color:var(--text,#f1f5f9);
        border-color:var(--border,#334155);}
    .ip-btn-s:hover{background:rgba(255,255,255,.11);}
    .ip-btn-d{background:rgba(239,68,68,.12);color:#f87171;border-color:rgba(239,68,68,.25);}
    .ip-btn-d:hover{background:rgba(239,68,68,.22);}
    .ip-btn-g{background:rgba(34,197,94,.12);color:#4ade80;border-color:rgba(34,197,94,.25);}
    .ip-btn-g:hover{background:rgba(34,197,94,.22);}
    .ip-btn-sm{padding:6px 12px;font-size:11px;border-radius:8px;}
    .ip-btn-xs{padding:4px 8px;font-size:10px;border-radius:6px;}
    .ip-tbl{width:100%;border-collapse:collapse;font-size:12px;}
    .ip-tbl th{padding:9px 12px;text-align:left;font-size:10px;font-weight:700;
        text-transform:uppercase;letter-spacing:.5px;color:#64748b;
        border-bottom:1px solid var(--border,#334155);}
    .ip-tbl td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
    .ip-tbl tr:hover td{background:rgba(255,255,255,.03);}
    .ip-col{background:rgba(14,165,233,.02);border:1px solid var(--border,#334155);
        border-radius:14px;overflow:hidden;}
    .ip-inv-card{background:var(--bg-card,#1e293b);border:1px solid var(--border,#334155);
        border-radius:12px;padding:13px;cursor:pointer;transition:all .18s;margin-bottom:8px;
        position:relative;overflow:hidden;}
    .ip-inv-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;
        background:var(--ip-accent,#0ea5e9);opacity:0;transition:opacity .18s;}
    .ip-inv-card:hover{transform:translateY(-2px);
        box-shadow:0 8px 28px rgba(0,0,0,.35);border-color:rgba(14,165,233,.35);}
    .ip-inv-card:hover::before{opacity:1;}
    .ip-modal{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99998;
        display:flex;align-items:center;justify-content:center;padding:16px;
        backdrop-filter:blur(4px);}
    .ip-modal-box{background:var(--bg-card,#1e293b);border:1px solid var(--border,#334155);
        border-radius:18px;box-shadow:0 32px 100px rgba(0,0,0,.7);width:100%;max-height:92vh;
        display:flex;flex-direction:column;overflow:hidden;}
    .ip-modal-head{display:flex;align-items:center;justify-content:space-between;
        padding:16px 20px;border-bottom:1px solid var(--border,#334155);flex-shrink:0;}
    .ip-modal-body{overflow-y:auto;padding:20px;flex:1;}
    .ip-modal-foot{padding:14px 20px;border-top:1px solid var(--border,#334155);
        display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;}
    .ip-close{background:rgba(255,255,255,.06);border:none;color:#94a3b8;
        width:30px;height:30px;border-radius:8px;cursor:pointer;
        display:flex;align-items:center;justify-content:center;font-size:14px;}
    .ip-close:hover{background:rgba(239,68,68,.2);color:#f87171;}
    .ip-row td{padding:0;}
    .ip-row input{background:transparent;border:none;color:var(--text,#f1f5f9);
        font-size:12px;padding:7px 8px;width:100%;outline:none;font-family:inherit;}
    .ip-row input:focus{background:rgba(14,165,233,.08);border-radius:5px;}
    .ip-hint{font-size:11px;color:#475569;margin-top:3px;}
    .ip-kpi{background:rgba(255,255,255,.03);border:1px solid var(--border,#334155);
        border-radius:12px;padding:14px 16px;transition:all .2s;}
    .ip-kpi:hover{background:rgba(255,255,255,.05);border-color:rgba(14,165,233,.3);}
    .ip-alert{display:flex;align-items:center;gap:10px;padding:10px 14px;
        background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);
        border-radius:10px;margin-bottom:6px;font-size:12px;}
    @keyframes ip-spin{to{transform:rotate(360deg)}}
    @keyframes ip-fade-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
    .ip-spin{animation:ip-spin .8s linear infinite;display:inline-block;}
    .ip-fade-in{animation:ip-fade-in .25s ease;}
    .ip-hcell{border-radius:5px;cursor:default;transition:transform .12s;}
    .ip-hcell:hover{transform:scale(1.25);z-index:2;position:relative;}
    .ip-tab{padding:8px 16px;border:none;background:transparent;color:#64748b;
        cursor:pointer;font-size:12px;font-weight:600;
        border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap;}
    .ip-tab.active{color:var(--ip-accent,#0ea5e9);border-bottom-color:var(--ip-accent,#0ea5e9);}
    .ip-sep{height:1px;background:var(--border,#334155);margin:14px 0;}
    .ip-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .ip-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
    .ip-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
    @media(max-width:640px){.ip-grid2,.ip-grid3,.ip-grid4{grid-template-columns:1fr;}}
    .ip-search-wrap{position:relative;flex:1;min-width:200px;}
.ip-hints {
    display: block !important; /* Принудительно показываем подсказки */
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
    background: var(--bg-card, #1e293b);
    border: 1px solid var(--border, #334155);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0,0,0,.5);
    z-index: 999999;
    max-height: 260px;
    overflow-y: auto;
}
.ip-hints:empty { display: none !important; }
    .ip-hint-row{padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04);}
    .ip-hint-row:hover{background:rgba(255,255,255,.07);}
    .ip-hint-row:last-child{border-bottom:none;}
    .ip-progress{height:6px;border-radius:3px;background:rgba(255,255,255,.06);overflow:hidden;margin-top:6px;}
    .ip-progress-bar{height:100%;border-radius:3px;
        background:linear-gradient(90deg,var(--ip-accent,#0ea5e9),#a78bfa);transition:width .6s ease;}
    .ip-dupe-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
        border-radius:10px;padding:10px 14px;margin:8px 0;font-size:12px;}
    .ip-upload-zone{border:2px dashed var(--border,#334155);border-radius:12px;
        overflow:hidden;display:flex;align-items:center;justify-content:center;
        background:rgba(255,255,255,.02);cursor:pointer;transition:border-color .15s;}
    .ip-upload-zone:hover{border-color:var(--ip-accent,#0ea5e9);}
    `;
    document.head.appendChild(style);
}

function setAccent(color) {
    S.accentColor = color || '#0ea5e9';
    document.documentElement.style.setProperty('--ip-accent', S.accentColor);
}

const getPage = () => document.getElementById('page-invoice_pro') || document.getElementById('module-page');
const render  = html => {
    const p = getPage();
    if (p) { p.innerHTML = html; p.classList.add('ip-fade-in'); }
};

// ══════════════════════════════════════════════════════════
//  ДАШБОРД
// ══════════════════════════════════════════════════════════
function renderDash() {
    S.page = 'dash';
    render(`
    <div style="padding:20px;max-width:1440px;">
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:24px;flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;font-size:21px;font-weight:800;display:flex;align-items:center;gap:9px;">
                ${ico('invoice',22)} Счета PRO
                <span style="display:inline-flex;align-items:center;padding:2px 7px;border-radius:10px;
                    font-size:9px;font-weight:800;background:rgba(167,139,250,.15);
                    color:#c4b5fd;border:1px solid rgba(167,139,250,.25);">v6.0</span>
            </h2>
            <div style="font-size:11px;color:#64748b;margin-top:3px;">
                Счета · Акты · УПД · QR · Клиент 360° · Шаблоны</div>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.analytics()">
                ${ico('chart',13)} Аналитика</button>
            <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.settings()">
                ${ico('cog',13)} Настройки</button>
            <button class="ip-btn ip-btn-p" onclick="IP.newInvoice()">
                ${ico('plus',15)} Новый счёт</button>
        </div>
    </div>
    <div id="ip-alerts" style="margin-bottom:16px;"></div>
    <div id="ip-kpi" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
        gap:12px;margin-bottom:24px;"></div>
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
        <div class="ip-search-wrap">
            <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);
                color:#475569;pointer-events:none;">${ico('search',14)}</span>
            <input class="ip-in" id="ipSrch" placeholder="Поиск по номеру, клиенту, телефону, email..."
                style="padding-left:36px;" oninput="IP.filter()">
        </div>
        <select class="ip-in" id="ipStFilter" style="max-width:155px;flex-shrink:0;" onchange="IP.filter()">
            <option value="">Все статусы</option>
            ${Object.entries(ST).map(([k,v])=>`<option value="${k}">${v.l}</option>`).join('')}
        </select>
        <div style="display:flex;gap:3px;background:rgba(255,255,255,.04);
            border-radius:10px;padding:3px;border:1px solid var(--border,#334155);flex-shrink:0;">
            <button id="ipBtnKb" class="ip-btn ip-btn-p ip-btn-xs"
                onclick="IP.setView('kanban')">⚏ Канбан</button>
            <button id="ipBtnTb" class="ip-btn ip-btn-s ip-btn-xs"
                onclick="IP.setView('table')">☰ Таблица</button>
        </div>
    </div>
    <div id="ipKanban" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;"></div>
    <div id="ipTable" style="display:none;"></div>
    <div id="ipPager" style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;"></div>
    </div>`);

    Promise.all([
        api('list', {limit:S.page_limit, offset:S.page_offset}),
        api('get_settings'),
        api('analytics', {month: new Date().toISOString().slice(0,7)}),
    ]).then(([listRes, settRes, analRes]) => {
        S.settings  = settRes?.data || {};
        setAccent(S.settings.theme_color || '#0ea5e9');
        S.invoices  = listRes?.data  || [];
        S.total     = listRes?.total || S.invoices.length;
        S.filtered  = S.invoices.slice();
        renderKPI(S.invoices);
        renderAlerts(analRes?.overdue_alerts || []);
        renderKanban(S.filtered);
        renderPager();
    });
}

function renderKPI(invs) {
    const el = document.getElementById('ip-kpi'); if (!el) return;
    const total   = invs.reduce((s,i)=>s+parseFloat(i.total||0),0);
    const paid    = invs.filter(i=>i.status==='paid').reduce((s,i)=>s+parseFloat(i.total||0),0);
    const overdue = invs.filter(i=>i.status==='overdue').length;
    const wait    = invs.filter(i=>i.status==='sent').reduce((s,i)=>s+parseFloat(i.total||0),0);
    const draft   = invs.filter(i=>i.status==='draft').length;
    const conv    = total>0?Math.round(paid/total*100):0;
    const kpis = [
        {l:'Всего счетов',  v:invs.length,         c:'#38bdf8', pct:null},
        {l:'Сумма',         v:fmt(total)+' ₽',      c:'#a78bfa', pct:null},
        {l:'Оплачено',      v:fmt(paid)+' ₽',       c:'#4ade80', pct:conv},
        {l:'Ждёт оплаты',   v:fmt(wait)+' ₽',       c:'#fb923c', pct:null},
        {l:'Просрочено',    v:overdue+' шт.',        c:'#f87171', pct:null},
        {l:'Черновики',     v:draft+' шт.',          c:'#64748b', pct:null},
    ];
    el.innerHTML = kpis.map(p=>`<div class="ip-kpi">
        <div style="font-size:10px;color:#64748b;text-transform:uppercase;
            letter-spacing:.5px;margin-bottom:6px;">${p.l}</div>
        <div style="font-size:20px;font-weight:800;color:${p.c};">${p.v}</div>
        ${p.pct!==null?`<div class="ip-progress"><div class="ip-progress-bar"
            style="width:${p.pct}%;"></div></div>
            <div style="font-size:10px;color:#64748b;margin-top:3px;">Конверсия ${p.pct}%</div>`:''}
    </div>`).join('');
}

function renderAlerts(alerts) {
    const el = document.getElementById('ip-alerts');
    if (!el||!alerts.length) return;
    el.innerHTML = alerts.slice(0,3).map(a=>`
    <div class="ip-alert">
        <span style="color:#f87171;flex-shrink:0;">${ico('alert',15)}</span>
        <div style="flex:1;min-width:0;">
            <b>№${esc(a.number)}</b>
            <span style="color:#94a3b8;"> · ${esc(a.client)}</span>
        </div>
        <span style="color:${a.days_left<0?'#f87171':'#fb923c'};white-space:nowrap;font-weight:700;">
            ${a.days_left<0?'⚠ Просрочен на '+Math.abs(a.days_left)+' дн.':'⏰ Через '+a.days_left+' дн.'}</span>
        <span style="color:#64748b;white-space:nowrap;">${fmt(a.total)} ₽</span>
        <button class="ip-btn ip-btn-s ip-btn-xs" onclick="IP.view('${a.id}')">Открыть</button>
    </div>`).join('');
}

function renderPager() {
    const el = document.getElementById('ipPager'); if (!el) return;
    const pages = Math.ceil(S.total / S.page_limit);
    if (pages <= 1) { el.innerHTML=''; return; }
    const cur = Math.floor(S.page_offset / S.page_limit);
    let html = '';
    if (cur > 0) html += `<button class="ip-btn ip-btn-s ip-btn-xs"
        onclick="IP._goPage(${cur-1})">‹ Назад</button>`;
    html += `<span style="font-size:12px;color:#64748b;padding:6px 10px;">
        ${cur+1} / ${pages} (${S.total} счетов)</span>`;
    if (cur < pages-1) html += `<button class="ip-btn ip-btn-s ip-btn-xs"
        onclick="IP._goPage(${cur+1})">Вперёд ›</button>`;
    el.innerHTML = html;
}

function renderKanban(invs) {
    const el = document.getElementById('ipKanban'); if (!el) return;
    const groups = {};
    Object.keys(ST).forEach(k=>{groups[k]=[];});
    invs.forEach(i=>{const s=i.status||'draft';if(groups[s])groups[s].push(i);});
    el.innerHTML = Object.entries(ST).map(([st,conf])=>{
        const items = groups[st]||[];
        return `<div class="ip-col">
        <div style="padding:10px 14px;border-bottom:1px solid var(--border,#334155);
            background:${conf.bg};display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:11px;font-weight:800;text-transform:uppercase;
                letter-spacing:.6px;color:${conf.c};display:flex;align-items:center;gap:6px;">
                <span style="width:8px;height:8px;border-radius:50%;background:${conf.dot};
                    box-shadow:0 0 6px ${conf.dot}40;"></span>${conf.l}</span>
            <span style="background:rgba(255,255,255,.09);color:#fff;border-radius:10px;
                padding:2px 9px;font-size:10px;font-weight:700;">${items.length}</span>
        </div>
        <div style="padding:10px;max-height:580px;overflow-y:auto;
            scrollbar-width:thin;scrollbar-color:#334155 transparent;">
        ${items.length?items.map(inv=>`
        <div class="ip-inv-card" onclick="IP.view('${inv.id}')">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:5px;">
                <span style="font-weight:800;font-size:13px;color:var(--ip-accent,#0ea5e9);">
                    №${esc(inv.number)}</span>
                <span style="font-size:10px;color:#475569;">${esc(inv.date)}</span>
            </div>
            <div style="font-size:12px;font-weight:600;margin-bottom:7px;
                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
                color:#e2e8f0;">${esc(inv.client_name||'—')}</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:7px;">
                ${fmt(inv.total)} ₽</div>
            ${inv.due_date?`<div style="font-size:10px;color:#475569;margin-bottom:6px;">
                ⏱ Срок: ${esc(inv.due_date)}</div>`:''}
            ${inv.has_closing_docs?`<div style="font-size:10px;color:#a78bfa;margin-bottom:6px;">
                📑 Есть документы</div>`:''}
            <div style="display:flex;gap:4px;flex-wrap:wrap;" onclick="event.stopPropagation()">
                <button class="ip-btn ip-btn-s ip-btn-xs"
                    onclick="IP.pdfMenu('${inv.id}',this)">${ico('pdf',11)} PDF</button>
                <button class="ip-btn ip-btn-s ip-btn-xs"
                    onclick="IP.edit('${inv.id}')">${ico('edit',11)}</button>
                <button class="ip-btn ip-btn-d ip-btn-xs"
                    onclick="IP.del('${inv.id}')">${ico('trash',11)}</button>
            </div>
        </div>`).join('')
        :`<div style="text-align:center;padding:24px 12px;font-size:11px;
            color:#475569;border:1px dashed rgba(255,255,255,.07);
            border-radius:10px;line-height:1.8;">Нет счетов<br>
            <button class="ip-btn ip-btn-s ip-btn-xs" style="margin-top:8px;"
                onclick="IP.newInvoice()">${ico('plus',10)} Создать</button>
          </div>`}
        </div></div>`;
    }).join('');
}

function renderTable(invs) {
    const el = document.getElementById('ipTable'); if (!el) return;
    if (!invs.length) {
        el.innerHTML = `<div style="text-align:center;padding:60px;color:#64748b;">
            ${ico('invoice',36)}<br><br>
            <div style="font-size:14px;margin-bottom:14px;">Счетов не найдено</div>
            <button class="ip-btn ip-btn-p" onclick="IP.newInvoice()">
                ${ico('plus',14)} Создать первый счёт</button>
        </div>`;
        return;
    }
    el.innerHTML = `<div style="overflow-x:auto;border-radius:14px;border:1px solid var(--border,#334155);">
    <table class="ip-tbl">
    <thead><tr>
        <th>Номер</th><th>Дата</th><th>Клиент</th>
        <th style="text-align:right;">Сумма</th>
        <th>Статус</th><th>Срок</th>
        <th style="text-align:center;">Действия</th>
    </tr></thead>
    <tbody>${invs.map(inv=>`<tr style="cursor:pointer;" onclick="IP.view('${inv.id}')">
        <td><span style="color:var(--ip-accent,#0ea5e9);font-weight:800;font-size:13px;">
            №${esc(inv.number)}</span></td>
        <td style="color:#64748b;white-space:nowrap;">${esc(inv.date)}</td>
        <td>
            <div style="font-weight:600;">${esc(inv.client_name||'—')}</div>
            ${inv.client_phone?`<div style="font-size:10px;color:#64748b;">${esc(inv.client_phone)}</div>`:''}
        </td>
        <td style="text-align:right;font-weight:800;white-space:nowrap;">${fmt(inv.total)} ₽</td>
        <td>${badge(inv.status)}</td>
        <td style="font-size:11px;color:${inv.status==='overdue'?'#f87171':'#64748b'};">
            ${esc(inv.due_date||'—')}</td>
        <td style="text-align:center;white-space:nowrap;" onclick="event.stopPropagation()">
            <button class="ip-btn ip-btn-s ip-btn-xs" onclick="IP.view('${inv.id}')">${ico('eye',11)}</button>
            <button class="ip-btn ip-btn-s ip-btn-xs" onclick="IP.edit('${inv.id}')">${ico('edit',11)}</button>
            <button class="ip-btn ip-btn-s ip-btn-xs" onclick="IP.pdfMenu('${inv.id}',this)">${ico('pdf',11)}</button>
            <button class="ip-btn ip-btn-s ip-btn-xs" onclick="IP.dup('${inv.id}')">${ico('copy',11)}</button>
            <button class="ip-btn ip-btn-d ip-btn-xs" onclick="IP.del('${inv.id}')">${ico('trash',11)}</button>
        </td>
    </tr>`).join('')}
    </tbody></table></div>`;
}

// ══════════════════════════════════════════════════════════
//  ПРОСМОТР СЧЁТА
// ══════════════════════════════════════════════════════════
function renderView(id) {
    api('get',{id}).then(r=>{
        if (!r?.data){notify('Счёт не найден','error');return;}
        const d = r.data;
        S.page = 'view';
        const items = (d.items||[]).map((it,i)=>{
            const sm  = parseFloat(it.price||0)*parseFloat(it.qty||1);
            const vt  = d.vat_type||'none';
            const vr  = parseFloat(d.vat_rate||0);
            const itVat = vt==='none'?'—':vt==='on_top'?fmt(sm*vr/100):fmt(sm*vr/(100+vr));
            return `<tr>
            <td style="padding:8px;text-align:center;color:#64748b;">${i+1}</td>
            <td style="padding:8px;font-weight:500;">${esc(it.name||'')}</td>
            <td style="padding:8px;text-align:center;">${esc(it.unit||'шт')}</td>
            <td style="padding:8px;text-align:center;">${it.qty||1}</td>
            <td style="padding:8px;text-align:right;">${fmt(it.price||0)}</td>
            <td style="padding:8px;text-align:right;font-weight:700;">${fmt(sm)}</td>
            <td style="padding:8px;text-align:center;color:#64748b;">${vt!=='none'?vr+'%':'—'}</td>
            <td style="padding:8px;text-align:right;">${itVat}</td>
            </tr>`;
        }).join('');
        const vatLabel = d.vat_type==='on_top'?'НДС '+d.vat_rate+'% сверху: '+fmt(d.vat_amount)+' ₽'
            :d.vat_type==='included'?'В т.ч. НДС '+d.vat_rate+'%: '+fmt(d.vat_amount)+' ₽'
            :'Без НДС';
        const stConf = ST[d.status]||ST.draft;

        render(`
        <div style="padding:20px;max-width:1100px;">
        <!-- ШАПКА -->
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
            <button class="ip-btn ip-btn-s" onclick="IP.back()">${ico('back',14)} Назад</button>
            <div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <h2 style="margin:0;font-size:19px;font-weight:800;">Счёт №${esc(d.number)}</h2>
                    ${badge(d.status)}
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:3px;">
                    от ${esc(d.date)}
                    ${d.due_date?' · Срок: '+esc(d.due_date):''}
                    ${d.paidAt?' · Оплачен: '+new Date(d.paidAt).toLocaleDateString('ru-RU'):''}
                </div>
            </div>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
                <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.edit('${id}')">
                    ${ico('edit',13)} Ред.</button>
                <button class="ip-btn ip-btn-p ip-btn-sm" onclick="IP.pdfMenu('${id}',this)">
                    ${ico('pdf',13)} PDF</button>
                <button class="ip-btn ip-btn-s ip-btn-sm"
                    onclick="IP.emailModal('${id}','${esc(d.buyer?.email||'')}')">
                    ${ico('mail',13)} Email</button>
                ${d.client_id?`<button class="ip-btn ip-btn-s ip-btn-sm"
                    onclick="IP._refreshBuyer('${id}','${esc(d.client_id)}')">
                    ${ico('refresh',13)} Реквизиты</button>`:''}
                <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.dup('${id}')">
                    ${ico('copy',13)}</button>
                <button class="ip-btn ip-btn-d ip-btn-sm" onclick="IP.del('${id}')">
                    ${ico('trash',13)}</button>
            </div>
        </div>
        <!-- ПРОГРЕСС СТАТУСА -->
        <div style="display:flex;gap:0;margin-bottom:24px;overflow:hidden;border-radius:10px;
            border:1px solid var(--border,#334155);">
            ${['draft','sent','paid'].map(s=>{
                const tc=ST[s]; const isActive=d.status===s;
                const isPast=['draft','sent','paid'].indexOf(d.status)>['draft','sent','paid'].indexOf(s);
                return `<div style="flex:1;padding:8px 10px;text-align:center;font-size:11px;
                    font-weight:700;background:${isActive?tc.bg:'transparent'};
                    color:${isActive?tc.c:'#334155'};border-right:1px solid var(--border,#334155);">
                    <span style="opacity:${isActive||isPast?1:.4};">${tc.l}</span></div>`;
            }).join('')}
        </div>
        <!-- УПРАВЛЕНИЕ СТАТУСОМ -->
        <div style="display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap;align-items:center;">
            ${d.status!=='paid'?`<button class="ip-btn ip-btn-g ip-btn-sm"
                onclick="IP.setStatus('${id}','paid')">${ico('check',13)} Отметить оплаченным</button>`:''}
            ${d.status==='draft'?`<button class="ip-btn ip-btn-p ip-btn-sm"
                onclick="IP.setStatus('${id}','sent')">${ico('send',13)} Отправлен клиенту</button>`:''}
            ${d.status!=='cancelled'&&d.status!=='paid'?`<button class="ip-btn ip-btn-d ip-btn-sm"
                onclick="confirm('Отменить счёт?')&&IP.setStatus('${id}','cancelled')">
                Отменить</button>`:''}
            <!-- Закрывающие документы -->
            <div style="position:relative;display:inline-block;">
                <button class="ip-btn ip-btn-s ip-btn-sm"
                    onclick="var m=document.getElementById('closingMenu${id}');
                        m.style.display=m.style.display==='block'?'none':'block'">
                    📑 Закрывающие ▾</button>
                <div id="closingMenu${id}" style="display:none;position:absolute;left:0;top:100%;
                    z-index:50;background:var(--bg-card,#1e293b);border:1px solid var(--border,#334155);
                    border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.55);
                    min-width:230px;padding:6px 0;margin-top:4px;">
                    ${[['act','📝 Акт выполненных работ'],['delivery','🚚 Товарная накладная'],['upd','📋 УПД']].map(t=>`
                    <div style="padding:10px 16px;cursor:pointer;font-size:13px;"
                        onmouseover="this.style.background='rgba(255,255,255,.07)'"
                        onmouseout="this.style.background=''"
                        onclick="document.getElementById('closingMenu${id}').style.display='none';
                            IP.createClosing('${id}','${t[0]}')">${t[1]}</div>`
                    ).join('')}
                </div>
            </div>
            ${(d.buyer?.phone||d.buyer?.name)?`<button class="ip-btn ip-btn-s ip-btn-sm"
                onclick="IP.client360('${esc(d.buyer?.phone||'')}','${esc(d.buyer?.name||'')}')">
                ${ico('user',13)} Клиент 360°</button>`:''}
        </div>
        <!-- РЕКВИЗИТЫ -->
        <div class="ip-grid2" style="margin-bottom:18px;">
        ${[{p:d.seller||{},label:'📤 Продавец / Исполнитель'},{p:d.buyer||{},label:'📥 Покупатель / Заказчик'}]
        .map(item=>{const p=item.p;return `<div class="ip-card">
            <div class="ip-sec">${item.label}</div>
            <div style="font-weight:800;font-size:15px;margin-bottom:7px;color:#f1f5f9;">
                ${esc(p.name||'—')}</div>
            <div style="font-size:12px;line-height:2;color:#94a3b8;">
                ${p.inn?`<div><span style="color:#cbd5e1;font-weight:600;">ИНН</span> ${esc(p.inn)}${p.kpp?` <span style="color:#cbd5e1;font-weight:600;">КПП</span> ${esc(p.kpp)}`:''}</div>`:''}
                ${p.ogrn?`<div><span style="color:#cbd5e1;font-weight:600;">ОГРН</span> ${esc(p.ogrn)}</div>`:''}
                ${p.address?`<div style="color:#94a3b8;">${esc(p.address)}</div>`:''}
                ${p.phone?`<div>${ico('phone',11)} ${esc(p.phone)}</div>`:''}
                ${p.bank_acc?`<div style="margin-top:4px;padding-top:4px;border-top:1px solid rgba(255,255,255,.05);">
                    <span style="color:#cbd5e1;font-weight:600;">Р/с</span> ${esc(p.bank_acc)}<br>
                    ${p.bank_name?`в <b style="color:#e2e8f0;">${esc(p.bank_name)}</b>`:''}
                    ${p.bank_bik?` <span style="color:#cbd5e1;font-weight:600;">БИК</span> ${esc(p.bank_bik)}`:''}
                    </div>`:''}
            </div></div>`;}).join('')}
        </div>
        <!-- ПОЗИЦИИ -->
        <div class="ip-card" style="margin-bottom:18px;overflow-x:auto;">
            <div class="ip-sec">${ico('invoice',12)} Состав счёта</div>
            <table class="ip-tbl">
            <thead><tr>
                <th style="width:32px;text-align:center;">№</th>
                <th>Наименование</th>
                <th style="text-align:center;">Ед.</th>
                <th style="text-align:center;">Кол.</th>
                <th style="text-align:right;">Цена, ₽</th>
                <th style="text-align:right;">Сумма, ₽</th>
                <th style="text-align:center;">НДС</th>
                <th style="text-align:right;">Сумма НДС</th>
            </tr></thead>
            <tbody>${items}</tbody>
            </table>
            ${d.discount_amt>0?`<div style="text-align:right;font-size:12px;color:#fb923c;margin-top:8px;">
                Скидка: −${fmt(d.discount_amt)} ₽</div>`:''}
            <div style="text-align:right;margin-top:14px;padding-top:14px;
                border-top:1px solid rgba(255,255,255,.06);">
                <div style="font-size:12px;color:#64748b;margin-bottom:3px;">${vatLabel}</div>
                <div style="font-size:24px;font-weight:900;color:${stConf.c};">
                    ${fmt(d.total)} ${d.currency||'₽'}</div>
                <div style="font-size:11px;color:#475569;margin-top:4px;font-style:italic;">
                    ${esc(d.total_words||'')}</div>
            </div>
        </div>
        ${d.payment_purpose?`<div class="ip-card" style="margin-bottom:12px;">
            <div class="ip-sec">📋 Назначение платежа</div>
            <div style="font-size:13px;">${esc(d.payment_purpose)}</div>
        </div>`:''}
        ${d.contract_text?`<div class="ip-card" style="margin-bottom:12px;">
            <div class="ip-sec">📋 Условия оплаты</div>
            <div style="font-size:12px;color:#94a3b8;line-height:1.8;">
                ${esc(d.contract_text).replace(/\n/g,'<br>')}</div>
        </div>`:''}
        ${d.notes?`<div class="ip-card" style="margin-bottom:12px;
            border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.05);">
            <div class="ip-sec">💬 Примечание</div>
            <div style="font-size:13px;">${esc(d.notes)}</div>
        </div>`:''}
        <div id="ipClosing"></div>
        <!-- КОММЕНТАРИИ -->
        <div class="ip-card" style="margin-top:16px;">
            <div class="ip-sec">${ico('msg',12)} Внутренние комментарии</div>
            <div id="ipComments" style="margin-bottom:12px;">
            ${(d.comments||[]).length?
                (d.comments||[]).map(c=>`
                <div style="padding:9px 12px;background:rgba(255,255,255,.03);
                    border-radius:8px;margin-bottom:6px;border-left:3px solid var(--ip-accent,#0ea5e9);">
                    <div style="font-size:12px;">${esc(c.text)}</div>
                    <div style="font-size:10px;color:#64748b;margin-top:4px;">
                        ${esc(c.user)} · ${new Date(c.date).toLocaleString('ru-RU')}</div>
                </div>`).join('')
                :'<div style="color:#475569;font-size:12px;padding:8px 0;">Нет комментариев</div>'}
            </div>
            <div style="display:flex;gap:8px;">
                <input class="ip-in" id="ipNewComment" placeholder="Добавить комментарий..."
                    style="flex:1;" onkeydown="if(event.key==='Enter')IP._addComment('${id}')">
                <button class="ip-btn ip-btn-p ip-btn-sm"
                    onclick="IP._addComment('${id}')">${ico('plus',12)} Добавить</button>
            </div>
        </div>
        </div>`);
        loadClosingDocs(id);
    });
}

function loadClosingDocs(invId) {
    api('get_closing_docs',{invoice_id:invId}).then(r=>{
        const el = document.getElementById('ipClosing');
        if (!el||!r?.data?.length) return;
        const typeMap={act:'Акт ВР',delivery:'Накладная',upd:'УПД'};
        el.innerHTML = `<div class="ip-card" style="margin-bottom:12px;">
        <div class="ip-sec">📑 Закрывающие документы</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
        ${r.data.map(d=>`
        <div style="display:flex;align-items:center;justify-content:space-between;
            padding:11px 14px;background:rgba(255,255,255,.03);border-radius:10px;
            border-left:3px solid var(--ip-accent,#0ea5e9);">
            <div>
                <div style="font-weight:700;font-size:13px;">
                    ${typeMap[d.type]||d.type} №${esc(d.number)}
                    <span style="font-size:11px;color:#64748b;font-weight:400;">
                        от ${esc(d.date)}</span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    ${fmt(d.total)} ₽</div>
            </div>
            ${d.url?`<a href="${d.url}" target="_blank"
               class="ip-btn ip-btn-s ip-btn-sm">${ico('pdf',12)} Открыть</a>`:''}
        </div>`).join('')}
        </div></div>`;
    });
}

// ══════════════════════════════════════════════════════════
//  ФОРМА СОЗДАНИЯ / РЕДАКТИРОВАНИЯ
// ══════════════════════════════════════════════════════════
function renderForm(editId) {
    S.page   = 'form';
    S.saving = false;
    window._ipClientId = null;
    const isEdit = !!editId;

    Promise.all([
        isEdit ? api('get',{id:editId}) : api('next_number'),
        api('get_settings'),
        api('get_templates'),
    ]).then(([p1, settRes, tplRes]) => {
        const inv      = p1?.data || null;
        const nextNum  = p1?.number || '';
        S.settings     = settRes?.data || {};
        const st       = S.settings;
        const templates = tplRes?.data || [];

        const toISO = d => {
            if (!d) return new Date().toISOString().split('T')[0];
            if (/^\d{2}\.\d{2}\.\d{4}$/.test(d)) {
                const [dd,mm,yy]=d.split('.');return `${yy}-${mm}-${dd}`;
            }
            return d;
        };
        const defDue = () => {
            const d=new Date();
            d.setDate(d.getDate()+parseInt(st.default_payment_days||3));
            return d.toISOString().split('T')[0];
        };

        render(`
        <div style="padding:20px;max-width:1060px;">
        <!-- ШАПКА ФОРМЫ -->
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:22px;flex-wrap:wrap;">
            <button class="ip-btn ip-btn-s" onclick="IP.back()">${ico('back',14)} Отмена</button>
            <div>
                <h2 style="margin:0;font-size:18px;font-weight:800;">
                    ${isEdit?'✏️ Редактирование':'✨ Новый'} счёт</h2>
                ${isEdit?`<div style="font-size:11px;color:#64748b;margin-top:2px;">
                    №${esc(inv?.number||'')} · ${esc(inv?.date||'')}</div>`:''}
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
                <button class="ip-btn ip-btn-s" id="ipSaveDraft"
                    onclick="IP.doSave('${editId||''}','draft')">💾 Черновик</button>
                <button class="ip-btn ip-btn-p" id="ipSaveSend"
                    onclick="IP.doSave('${editId||''}','${isEdit&&inv?.status!=='draft'?inv?.status||'draft':'draft'}')">
                    ${ico('check',13)} Сохранить</button>
            </div>
        </div>

        <!-- СТРОКА 1: Номер + Дата + Скидка/НДС -->
        <div class="ip-grid3" style="margin-bottom:16px;">
            <div class="ip-card">
                <div class="ip-sec">📋 Реквизиты счёта</div>
                <label class="ip-hint" style="display:block;margin-bottom:5px;">Номер счёта</label>
                <input class="ip-in" id="fNum"
                    value="${esc(isEdit?(inv?.number||''):(nextNum||''))}"
                    placeholder="0001" style="margin-bottom:10px;">
                <div class="ip-grid2">
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Дата</label>
                        <input type="date" class="ip-in" id="fDate"
                            value="${toISO(isEdit&&inv?inv.date:null)}">
                    </div>
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Срок оплаты</label>
                        <select class="ip-in" id="fDueSel" onchange="IP._onDueSel(this)"
                            style="margin-bottom:6px;">
                            <option value="0">По факту</option>
                            <option value="3">3 дня</option>
                            <option value="5">5 дней</option>
                            <option value="7">7 дней</option>
                            <option value="14">14 дней</option>
                            <option value="30">30 дней</option>
                            <option value="custom">Дата...</option>
                        </select>
                        <input type="date" class="ip-in" id="fDue"
                            value="${isEdit&&inv?(inv.due_date||defDue()):defDue()}"
                            style="display:none;">
                    </div>
                </div>
            </div>
            <div class="ip-card">
                <div class="ip-sec">💰 НДС и валюта</div>
                <label class="ip-hint" style="display:block;margin-bottom:4px;">Тип НДС</label>
                <select class="ip-in" id="fVat" onchange="IP.recalc()" style="margin-bottom:10px;">
                    ${[['none','Без НДС'],['included','В том числе НДС'],['on_top','НДС сверху']].map(v=>
                        `<option value="${v[0]}" ${(isEdit&&inv?inv.vat_type:st.default_vat||'none')===v[0]?'selected':''}>${v[1]}</option>`
                    ).join('')}
                </select>
                <div class="ip-grid2">
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Ставка НДС %</label>
                        <input type="number" class="ip-in" id="fVatRate"
                            value="${isEdit&&inv?parseFloat(inv.vat_rate||20):parseFloat(st.vat_rate||20)}"
                            oninput="IP.recalc()">
                    </div>
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Валюта</label>
                        <select class="ip-in" id="fCur">
                            ${['₽','$','€','¥'].map(c=>
                                `<option ${(isEdit&&inv?inv.currency:st.default_currency||'₽')===c?'selected':''}>${c}</option>`
                            ).join('')}
                        </select>
                    </div>
                </div>
            </div>
            <div class="ip-card">
                <div class="ip-sec">${ico('percent',12)} Скидка и примечание</div>
                <div class="ip-grid2" style="margin-bottom:10px;">
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Тип скидки</label>
                        <select class="ip-in" id="fDiscountType" onchange="IP.recalc()">
                            <option value="none" ${(isEdit&&inv?inv.discount_type:'none')==='none'?'selected':''}>Нет</option>
                            <option value="percent" ${(isEdit&&inv?inv.discount_type:'')==='percent'?'selected':''}>Процент %</option>
                            <option value="fixed" ${(isEdit&&inv?inv.discount_type:'')==='fixed'?'selected':''}>Сумма ₽</option>
                        </select>
                    </div>
                    <div>
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Значение</label>
                        <input type="number" class="ip-in" id="fDiscount"
                            value="${isEdit&&inv?parseFloat(inv.discount||0):0}"
                            min="0" step="any" oninput="IP.recalc()">
                    </div>
                </div>
                <label class="ip-hint" style="display:block;margin-bottom:4px;">Примечание</label>
                <textarea class="ip-in" id="fNotes" rows="3" style="resize:none;"
                    placeholder="Доп. информация...">${esc(isEdit&&inv?inv.notes||'':'')}</textarea>
            </div>
        </div>

        <!-- ПОКУПАТЕЛЬ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">👤 Покупатель (контрагент)</div>
            <div id="ipDupeWarn"></div>
            <!-- ПОИСК КЛИЕНТА (дебаунс 300мс) -->
            <div style="position:relative;margin-bottom:16px;">
                <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);
                    color:#475569;pointer-events:none;">${ico('search',13)}</span>
                <input class="ip-in" id="fClientSrch"
                    placeholder="🔍 Начните вводить имя или телефон для поиска из базы клиентов..."
                    style="padding-left:34px;"
oninput="if(this.value.replace(/\\D/g,'').length>=4) IP._debouncedClientSearch(this.value)"
                    value="${esc(isEdit&&inv?.buyer?inv.buyer.name||'':'')}">
                <div id="fClientHints" class="ip-hints"></div>
            </div>
            <div class="ip-grid2" style="margin-bottom:12px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Название / ФИО *</label>
                    <input class="ip-in" id="bName"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.name||'':'')}"
                        placeholder="ООО «Ромашка» / Иванов И.И."
                        oninput="IP._debouncedDupeCheck(this.value)">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Тип контрагента</label>
                    <select class="ip-in" id="bType">
                        ${[['company','Юридическое лицо'],['ip','ИП'],['individual','Физ. лицо / Самозанятый']].map(v=>
                            `<option value="${v[0]}" ${(isEdit&&inv?.buyer?inv.buyer.type||'company':'company')===v[0]?'selected':''}>${v[1]}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <div class="ip-grid3" style="margin-bottom:12px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">ИНН</label>
                    <div style="display:flex;gap:6px;">
                        <input class="ip-in" id="bInn"
                            value="${esc(isEdit&&inv?.buyer?inv.buyer.inn||'':'')}"
                            placeholder="7700000000"
                            oninput="IP._debouncedDupeCheck(document.getElementById('bName').value)">
                        <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.dadataFill()"
                            title="Заполнить по ИНН (DaData)" style="flex-shrink:0;">
                            ${ico('spark',11)} ИНН</button>
                    </div>
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">КПП</label>
                    <input class="ip-in" id="bKpp"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.kpp||'':'')}" placeholder="770901001">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">ОГРН</label>
                    <input class="ip-in" id="bOgrn"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.ogrn||'':'')}" placeholder="1027700132195">
                </div>
            </div>
            <div class="ip-grid2" style="margin-bottom:12px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Юридический адрес</label>
                    <input class="ip-in" id="bAddr"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.address||'':'')}"
                        placeholder="г. Москва, ул. Ленина, 1">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Руководитель</label>
                    <input class="ip-in" id="bDir"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.director||'':'')}"
                        placeholder="Генеральный директор Иванов И.И.">
                </div>
            </div>
            <div class="ip-grid3" style="margin-bottom:12px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Телефон</label>
                    <input class="ip-in" id="bPhone"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.phone||'':'')}"
                        placeholder="+7 (___) ___-__-__"
                        oninput="IP._debouncedClientSearch(this.value)">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Email</label>
                    <input class="ip-in" id="bEmail" type="email"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.email||'':'')}"
                        placeholder="email@company.ru">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Расчётный счёт</label>
                    <input class="ip-in" id="bBankAcc"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.bank_acc||'':'')}"
                        placeholder="40702810000000000000">
                </div>
            </div>
            <div class="ip-grid3">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Банк</label>
                    <input class="ip-in" id="bBankName"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.bank_name||'':'')}"
                        placeholder="АО «Сбербанк»">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">БИК</label>
                    <input class="ip-in" id="bBankBik"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.bank_bik||'':'')}"
                        placeholder="044525225">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Корр. счёт</label>
                    <input class="ip-in" id="bBankKor"
                        value="${esc(isEdit&&inv?.buyer?inv.buyer.bank_kor||'':'')}"
                        placeholder="30101810400000000225">
                </div>
            </div>
        </div>

        <!-- ПОЗИЦИИ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;
                margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                <div class="ip-sec" style="margin:0;">${ico('invoice',12)} Товары / Работы / Услуги</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    ${templates.length?`<div style="position:relative;">
                        <button class="ip-btn ip-btn-s ip-btn-sm"
                            onclick="var m=document.getElementById('tplMenu');
                                m.style.display=m.style.display==='block'?'none':'block'">
                            ${ico('tpl',12)} Шаблон ▾</button>
                        <div id="tplMenu" style="display:none;position:absolute;right:0;
                            top:100%;z-index:50;background:var(--bg-card,#1e293b);
                            border:1px solid var(--border,#334155);border-radius:12px;
                            box-shadow:0 12px 40px rgba(0,0,0,.55);min-width:220px;
                            padding:6px 0;margin-top:4px;">
                            ${templates.map(t=>`<div style="padding:9px 16px;cursor:pointer;font-size:12px;
                                display:flex;align-items:center;justify-content:space-between;"
                                onmouseover="this.style.background='rgba(255,255,255,.06)'"
                                onmouseout="this.style.background=''"
                                onclick="document.getElementById('tplMenu').style.display='none';
                                    IP._loadTemplate('${esc(t.id)}')">
                                <span>${esc(t.name)}</span>
                                <span style="font-size:10px;color:#64748b;">${(t.items||[]).length} поз.</span>
                            </div>`).join('')}
                        </div>
                    </div>`:''}
                    <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP._saveTpl()">
                        ${ico('copy',12)} Сохранить шаблон</button>
                    <button class="ip-btn ip-btn-p ip-btn-sm" onclick="IP.addRow()">
                        ${ico('plus',12)} Добавить</button>
                </div>
            </div>
            <div style="overflow-x:auto;">
            <table class="ip-tbl" id="ipItemsTable">
            <thead><tr>
                <th style="width:32px;text-align:center;">№</th>
                <th>Наименование товара / работы / услуги</th>
                <th style="width:60px;">Ед. изм.</th>
                <th style="width:70px;">Кол-во</th>
                <th style="width:105px;">Цена, ₽</th>
                <th style="width:115px;">Сумма, ₽</th>
                <th style="width:32px;"></th>
            </tr></thead>
            <tbody id="ipItemsBody"></tbody>
            </table>
            </div>
            <!-- Итоги -->
            <div style="display:flex;justify-content:flex-end;margin-top:14px;
                padding-top:14px;border-top:1px solid rgba(255,255,255,.06);">
                <div style="min-width:260px;">
                    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
                        <span style="color:#64748b;">Итого без скидки:</span>
                        <strong id="pSubRaw">0,00</strong>
                    </div>
                    <div id="pDiscRow" style="display:none;justify-content:space-between;
                        padding:5px 0;font-size:12px;color:#fb923c;">
                        <span>Скидка:</span>
                        <strong id="pDisc">0,00</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
                        <span style="color:#64748b;">Итого без НДС:</span>
                        <strong id="pSub">0,00</strong>
                    </div>
                    <div id="pVatRow" style="display:none;justify-content:space-between;
                        padding:5px 0;font-size:12px;">
                        <span style="color:#64748b;">НДС:</span>
                        <strong id="pVat">0,00</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:8px 0;border-top:2px solid var(--border,#334155);margin-top:4px;">
                        <span style="font-size:13px;font-weight:700;">ИТОГО:</span>
                        <strong id="pTotal"
                            style="font-size:20px;color:var(--ip-accent,#0ea5e9);">0,00</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- НАЗНАЧЕНИЕ ПЛАТЕЖА + УСЛОВИЯ -->
        <div class="ip-grid2" style="margin-bottom:16px;">
            <div class="ip-card">
                <div class="ip-sec">📋 Назначение платежа</div>
                <input class="ip-in" id="fPurpose"
                    value="${esc(isEdit&&inv?inv.payment_purpose||'':'')}"
                    placeholder="Оплата по счёту №... от ...">
                <div class="ip-hint">Автозаполнится при сохранении если пусто</div>
            </div>
            <div class="ip-card">
                <div class="ip-sec">📄 Условия оплаты</div>
                <textarea class="ip-in" id="fContract" rows="3" style="resize:vertical;">
${esc(isEdit&&inv?inv.contract_text||'':st.contract_text||'')}</textarea>
            </div>
        </div>
        </div>`);

        // Заполняем строки позиций
        if (isEdit && inv?.items?.length) {
            inv.items.forEach(it => addItemRow(it));
        } else {
            addItemRow(null);
        }
        recalc();

        // Устанавливаем client_id если редактирование
        if (isEdit && inv?.client_id) {
            window._ipClientId = inv.client_id;
        }

        // Быстрый счёт из заказа
        if (!isEdit && window._quickInvoice) {
            const qi = window._quickInvoice;
            if (qi.clientName) { const e=document.getElementById('bName'); if(e) e.value=qi.clientName; }
            if (qi.phone)      { const e=document.getElementById('bPhone'); if(e) e.value=qi.phone; }
            if (qi.items?.length) {
                const tbody=document.getElementById('ipItemsBody');
                if(tbody) tbody.innerHTML='';
                qi.items.forEach(it=>addItemRow(it));
            }
            recalc();
            window._quickInvoice = null;
        }
    });
    
        // ✅ Аварийный запуск поиска прямо в момент рендера формы
    setTimeout(function() {
        var input = document.getElementById('fClientSrch');
        if (input && !input.dataset.csi) {
            input.dataset.csi = '1';
            input.removeAttribute('oninput');
            input.removeAttribute('onkeydown');
            var parent = input.parentNode;
            parent.style.position = 'relative';
            var box = document.getElementById('myCustomClientList');
            if (!box) {
                box = document.createElement('div');
                box.id = 'myCustomClientList';
                box.style.cssText = 'display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);background:#1e293b;border:1px solid #334155;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.7);z-index:999999;max-height:280px;overflow-y:auto;padding:6px 0;';
                parent.appendChild(box);
            }
            var timer = null;
            input.addEventListener('input', function() {
                clearTimeout(timer);
                var q = this.value.trim();
                if (q.length < 1) { box.style.display = 'none'; box.innerHTML = ''; return; }
                box.innerHTML = '<div style="padding:16px;text-align:center;color:#64748b;">🔍 Поиск...</div>';
                box.style.display = 'block';
                timer = setTimeout(function() {
                    CRM.api('clients_pro', 'list', { search: q, limit: 8 })
                        .then(function(res) {
                            var clients = res.data || [];
                            if (!clients.length) { 
                                box.innerHTML = '<div style="padding:16px;text-align:center;color:#64748b;">Клиенты не найдены</div>'; 
                                return; 
                            }
                            var html = '';
                            clients.forEach(function(c) {
                                var avatar = c.vk_avatar || c.avatar_url || '';
                                var initial = (c.name || '?').charAt(0).toUpperCase();
                                var sum = (c._metrics && c._metrics.total) ? ' ' + Number(c._metrics.total).toLocaleString('ru-RU') + ' ₽' : '';
                                html += '<div class="row" style="padding:10px 16px;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.04);display:flex;align-items:center;gap:12px;transition:background 0.1s;" data-id="' + String(c.id) + '">' +
                                    (avatar ? '<img src="' + avatar + '" style="width:36px;height:36px;border-radius:10px;flex-shrink:0;object-fit:cover;">' : '<div style="width:36px;height:36px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#7c3aed,#06b6d4);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;">' + initial + '</div>') +
                                    '<div style="flex:1;min-width:0;"><div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (c.name || 'Без имени') + '</div><div style="font-size:11px;color:#94a3b8;margin-top:2px;">' + (c.phone || '') + (c.inn ? ' · ИНН ' + c.inn : '') + '</div></div>' +
                                    (sum ? '<div style="font-size:11px;color:#4ade80;white-space:nowrap;flex-shrink:0;">' + sum + '</div>' : '') +
                                    '</div>';
                            });
                            box.innerHTML = html;
                            box.querySelectorAll('.row').forEach(function(row) {
                                row.addEventListener('click', function(e) {
                                    var cid = this.dataset.id;
                                    var found = clients.find(function(x) { return String(x.id) === String(cid); });
                                    if (found) {
                                        var f = function(id) { return document.getElementById(id); };
                                        if (f('bName')) f('bName').value = found.name || '';
                                        if (f('bPhone')) f('bPhone').value = found.phone || '';
                                        if (f('bEmail')) f('bEmail').value = found.email || '';
                                        if (f('bInn')) f('bInn').value = found.inn || '';
                                        if (f('bKpp')) f('bKpp').value = found.kpp || '';
                                        if (f('bOgrn')) f('bOgrn').value = found.ogrn || '';
                                        if (f('bAddr')) f('bAddr').value = found.address || '';
                                        if (f('bDir')) f('bDir').value = found.director || '';
                                        if (f('bBankName')) f('bBankName').value = found.bank_name || '';
                                        if (f('bBankBik')) f('bBankBik').value = found.bank_bik || '';
                                        if (f('bBankAcc')) f('bBankAcc').value = found.bank_acc || '';
                                        if (f('bBankKor')) f('bBankKor').value = found.bank_ks || '';
                                        var te = f('bType');
                                        if (te) {
                                            var tm = {'Юридическое лицо':'company','ИП':'ip','Физическое лицо':'individual'};
                                            te.value = tm[found.type] || 'company';
                                        }
                                        window._ipClientId = String(found.id);
                                        box.style.display = 'none';
                                        box.innerHTML = '';
                                        input.value = found.name || '';
                                        if (typeof notify === 'function') notify('✅ Клиент выбран: ' + found.name, 'success');
                                    }
                                });
                            });
                        })
                        .catch(function() {
                            box.innerHTML = '<div style="padding:16px;text-align:center;color:#f87171;">Ошибка загрузки</div>';
                        });
                }, 250);
            });
        }
    }, 300);
    
}

function addItemRow(data) {
    const tbody = document.getElementById('ipItemsBody'); if (!tbody) return;
    const num = tbody.children.length + 1;
    const tr  = document.createElement('tr');
    tr.className = 'ip-row';
    tr.style.borderBottom = '1px solid rgba(255,255,255,.05)';
    tr.innerHTML = `
    <td style="padding:6px 8px;text-align:center;color:#64748b;font-size:11px;
        width:32px;" class="ip-row-num">${num}</td>
    <td><input type="text" class="it-name"
        placeholder="Наименование товара/услуги..."
        value="${data?esc(data.name||''):''}"></td>
    <td style="width:60px;"><input type="text" class="it-unit"
        value="${data?esc(data.unit||'шт'):'шт'}" style="text-align:center;"></td>
    <td style="width:70px;"><input type="number" class="it-qty"
        value="${data?parseFloat(data.qty)||1:1}"
        min="0.001" step="any" style="text-align:center;"></td>
    <td style="width:105px;"><input type="number" class="it-price"
        value="${data?parseFloat(data.price)||'':''}"
        min="0" step="any" style="text-align:right;" placeholder="0.00"></td>
    <td style="padding:8px 10px;text-align:right;font-weight:700;font-size:13px;
        color:var(--ip-accent,#0ea5e9);width:115px;" class="it-sum">0,00</td>
    <td style="padding:4px 6px;text-align:center;width:32px;">
        <button class="ip-btn ip-btn-d ip-btn-xs" style="padding:4px 7px;"
            onclick="this.closest('tr').remove();IP._renumRows();IP.recalc();">×</button>
    </td>`;
    tr.querySelectorAll('input').forEach(inp=>inp.addEventListener('input', recalc));
    tbody.appendChild(tr);
    recalc();
    if (!data) {
        const n = tr.querySelector('.it-name');
        if (n) setTimeout(()=>n.focus(),50);
    }
}

function recalc() {
    let rawSub = 0;
    document.querySelectorAll('#ipItemsBody tr').forEach(tr=>{
        const q = parseFloat(tr.querySelector('.it-qty')?.value||0)||0;
        const p = parseFloat(tr.querySelector('.it-price')?.value||0)||0;
        const s = Math.round(q*p*100)/100;
        rawSub += s;
        const se = tr.querySelector('.it-sum');
        if (se) se.textContent = s.toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2});
    });
    const discType = gv('fDiscountType') || 'none';
    const discVal  = parseFloat(gv('fDiscount')) || 0;
    let discAmt = 0;
    let sub = rawSub;
    if (discType==='percent'&&discVal>0) {
        discAmt = Math.round(rawSub*discVal/100*100)/100;
        sub = rawSub - discAmt;
    } else if (discType==='fixed'&&discVal>0) {
        discAmt = Math.min(discVal, rawSub);
        sub = rawSub - discAmt;
    }
    const vt  = gv('fVat')||'none';
    const vr  = parseFloat(gv('fVatRate'))||0;
    let vat=0, total=sub;
    if (vt==='on_top') {
        vat   = Math.round(sub*vr/100*100)/100;
        total = sub+vat;
    } else if (vt==='included') {
        vat = Math.round(sub*vr/(100+vr)*100)/100;
    }
    const setEl = (id, v) => {
        const e = document.getElementById(id);
        if (e) e.textContent = v.toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2});
    };
    setEl('pSubRaw', rawSub);
    setEl('pSub',    sub);
    setEl('pVat',    vat);
    setEl('pTotal',  total);
    setEl('pDisc',   discAmt);
    const discRow = document.getElementById('pDiscRow');
    if (discRow) discRow.style.display = discAmt>0?'flex':'none';
    const vatRow  = document.getElementById('pVatRow');
    if (vatRow)  vatRow.style.display  = vt!=='none'?'flex':'none';
}

// ── Срок оплаты — выбор из списка ────────────────────────
function _onDueSel(sel) {
    const dueFld = document.getElementById('fDue');
    if (!dueFld) return;
    if (sel.value === 'custom') {
        dueFld.style.display = '';
        dueFld.focus();
    } else if (sel.value === '0') {
        dueFld.style.display = 'none';
        dueFld.value = '';
    } else {
        dueFld.style.display = 'none';
        const d = new Date();
        d.setDate(d.getDate() + parseInt(sel.value));
        dueFld.value = d.toISOString().split('T')[0];
    }
}

// ── ПОИСК КЛИЕНТОВ (дебаунс 300мс + кэш) ────────────────
const _debouncedClientSearch = debounce(function(q) {
    const hints = document.getElementById('fClientHints');
    if (!hints) return;

    const trimmed = (q || '').trim();

    // Минимум 2 символа
    if (trimmed.length < 2) {
        hints.style.display = 'none';
        hints.innerHTML = '';
        return;
    }

    // Кэш
    if (_clientCache.has(trimmed)) {
        _renderClientHints(hints, _clientCache.get(trimmed), trimmed);
        return;
    }

    // ✅ Передаём через GET-параметры (moduleParams в PHP)
    CRM.api('clients_pro', 'list', {
        search: trimmed,
        limit:  8,
        _no_metrics: 1,   // сигнал для оптимизации (если поддержится)
    }).then(r => {
        const list = (r?.data || []).slice(0, 8);
        _clientCache.set(trimmed, list);
        list.forEach(c => { if (c.id) _clientById.set(String(c.id), c); });
        _renderClientHints(hints, list, trimmed);
    }).catch(err => {
        console.warn('[INV6] client search error:', err);
        hints.style.display = 'none';
    });
}, 300);

// ── РЕНДЕР ПОДСКАЗОК ─────────────────────────────────────
function _renderClientHints(hints, list, query) {
    if (!list || !list.length) {
        hints.style.display = 'none';
        hints.innerHTML = '';
        return;
    }

    // Подсвечиваем совпадения
    const hl = (str, q) => {
        if (!q || !str) return esc(str || '');
        const idx = str.toLowerCase().indexOf(q.toLowerCase());
        if (idx === -1) return esc(str);
        return esc(str.slice(0, idx))
            + '<mark style="background:rgba(14,165,233,.3);color:inherit;border-radius:2px;">'
            + esc(str.slice(idx, idx + q.length))
            + '</mark>'
            + esc(str.slice(idx + q.length));
    };

    hints.innerHTML = list.map((c, idx) => `
    <div class="ip-hint-row" data-idx="${idx}" style="cursor:pointer;">
        <div style="display:flex;align-items:center;gap:9px;">
            ${c.vk_avatar || c.avatar_url
                ? `<img src="${esc(c.vk_avatar || c.avatar_url)}"
                    style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">`
                : `<div style="width:32px;height:32px;border-radius:8px;flex-shrink:0;
                    background:linear-gradient(135deg,#7c3aed,#06b6d4);
                    display:flex;align-items:center;justify-content:center;
                    font-weight:800;font-size:13px;color:#fff;">
                    ${esc((c.name || '?').charAt(0).toUpperCase())}</div>`}
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;
                    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${hl(c.name || '', query)}</div>
                <div style="font-size:11px;color:#64748b;margin-top:1px;">
                    ${c.phone ? hl(c.phone, query) : ''}
                    ${c.inn   ? `<span style="margin-left:6px;">ИНН&nbsp;${esc(c.inn)}</span>` : ''}
                    ${c.crm_status ? `<span style="margin-left:6px;color:#475569;">·&nbsp;${esc(c.crm_status)}</span>` : ''}
                </div>
            </div>
            ${(c._metrics?.total > 0)
                ? `<div style="font-size:10px;color:#4ade80;white-space:nowrap;flex-shrink:0;">
                    ${fmt(c._metrics.total)} ₽</div>` : ''}
        </div>
    </div>`).join('');

    hints.querySelectorAll('.ip-hint-row').forEach(row => {
        // ✅ mousedown — срабатывает ДО blur
        row.addEventListener('mousedown', e => {
            e.preventDefault();
            const c = list[parseInt(row.dataset.idx)];
            if (c) _fillClientFromObj(c);
            hints.style.display = 'none';
            hints.innerHTML = '';
        });
    });

    hints.style.display = 'block';
}

// НАЙДИТЕ всю функцию:
function _renderClientHints(hints, list) {
    if (!list.length) { hints.style.display = 'none'; return; }
    hints.innerHTML = list.map(c => `
    <div class="ip-hint-row" data-client-id="${esc(String(c.id || ''))}">
        <div style="font-weight:700;font-size:13px;">${esc(c.name || '')}</div>
        <div style="font-size:11px;color:#64748b;margin-top:1px;">
            ${esc(c.phone || '')}${c.inn ? ' · ИНН ' + esc(c.inn) : ''}${c.crm_status ? ' · ' + esc(c.crm_status) : ''}
        </div>
    </div>`).join('');

    hints.querySelectorAll('.ip-hint-row').forEach(row => {
        row.addEventListener('click', () => {
            const cid    = row.dataset.clientId;
            const client = cid ? _clientById.get(cid) : null;
            if (client) _fillClientFromObj(client);
            // ✅ Закрываем список
            hints.style.display = 'none';
            hints.innerHTML = '';
        });
    });
    hints.style.display = 'block';
}

// ЗАМЕНИТЕ на:
function _renderClientHints(hints, list) {
    if (!list || !list.length) {
        hints.style.display = 'none';
        hints.innerHTML = '';
        return;
    }
    hints.innerHTML = list.map((c, idx) => `
    <div class="ip-hint-row" data-idx="${idx}">
        <div style="font-weight:700;font-size:13px;">${esc(c.name || '')}</div>
        <div style="font-size:11px;color:#64748b;margin-top:1px;">
            ${esc(c.phone || '')}${c.inn ? ' · ИНН ' + esc(c.inn) : ''}${c.crm_status ? ' · ' + esc(c.crm_status) : ''}
        </div>
    </div>`).join('');

    hints.querySelectorAll('.ip-hint-row').forEach(row => {
        row.addEventListener('mousedown', e => {
            e.preventDefault();
            const c = list[parseInt(row.dataset.idx)];
            if (c) _fillClientFromObj(c);
            hints.style.display = 'none';
            hints.innerHTML = '';
        });
    });
    hints.style.display = 'block';
}

// Заполнение полей покупателя из объекта клиента
function _fillClientFromObj(c) {
    if (!c) return;
    const sv = (id,v)=>{ const e=document.getElementById(id); if(e&&v!=null) e.value=v; };
    sv('bName',     c.name     ||'');
    sv('bPhone',    c.phone    ||'');
    sv('bEmail',    c.email    ||'');
    sv('bInn',      c.inn      ||'');
    sv('bKpp',      c.kpp      ||'');
    sv('bOgrn',     c.ogrn     ||'');
    sv('bAddr',     c.address  ||'');
    sv('bDir',      c.director ||'');
    sv('bBankName', c.bank_name||'');
    sv('bBankBik',  c.bank_bik ||'');
    sv('bBankAcc',  c.bank_acc ||'');
    sv('bBankKor',  c.bank_ks  || c.bank_kor ||'');
    const te = document.getElementById('bType');
    if (te) {
        const t = c.type||'';
        te.value = (t==='ИП'||t==='ip')?'ip'
            :(t==='Физическое лицо'||t==='individual')?'individual'
            :'company';
    }
    const cs = document.getElementById('fClientSrch');
    if (cs) cs.value = c.name||'';
    window._ipClientId = String(c.id||'');
    recalc();
    notify('✅ Клиент подставлен: '+c.name,'success');
}

// ✅ Закрываем подсказки по клику вне поля — регистрируем ЗДЕСЬ
// (после рендера формы, когда элементы уже есть)
const _outsideClickHandler = function(e) {
    const hints  = document.getElementById('fClientHints');
    const srch   = document.getElementById('fClientSrch');
    const phone  = document.getElementById('bPhone');
    if (!hints) {
        // Форма закрыта — убираем слушатель
        document.removeEventListener('click', _outsideClickHandler, true);
        return;
    }
    if (srch  && srch.contains(e.target))  return;
    if (phone && phone.contains(e.target)) return;
    hints.style.display = 'none';
    hints.innerHTML = '';
};
document.addEventListener('click', _outsideClickHandler, true);

// Проверка дублей при вводе имени
const _debouncedDupeCheck = debounce(function(q) {
    if (q.length < 2) return;
    const inn = gv('bInn');
    // Ищем по тому что уже введено
    CRM.api('clients_pro','list',{search:q,limit:5}).then(r=>{
        const warn = document.getElementById('ipDupeWarn');
        if (!warn) return;
        const dupes = (r?.data||[]).filter(c=>{
            if (window._ipClientId && String(c.id)===String(window._ipClientId)) return false;
            return true;
        }).slice(0,3);
        if (!dupes.length) { warn.innerHTML=''; return; }
        warn.innerHTML = `<div class="ip-dupe-warn">
            <div style="font-weight:700;color:#fb923c;margin-bottom:6px;">
                ⚠️ Найдены похожие клиенты в базе:</div>
            ${dupes.map(c=>`<div style="display:flex;align-items:center;gap:10px;
                padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);">
                <div style="flex:1;font-size:12px;">
                    <b>${esc(c.name)}</b>
                    ${c.phone?'<span style="color:#64748b;"> · '+esc(c.phone)+'</span>':''}
                    ${c.inn?'<span style="color:#64748b;"> · ИНН '+esc(c.inn)+'</span>':''}
                </div>
                <button class="ip-btn ip-btn-s ip-btn-xs"
                    onclick="IP._fillFromDupe('${esc(String(c.id))}')">Использовать</button>
            </div>`).join('')}
        </div>`;
    });
}, 400);

function _fillFromDupe(cid) {
    const c = _clientById.get(String(cid));
    if (c) { _fillClientFromObj(c); document.getElementById('ipDupeWarn').innerHTML=''; return; }
    CRM.api('clients_pro','get',{id:cid}).then(r=>{
        if (r?.data) { _fillClientFromObj(r.data); document.getElementById('ipDupeWarn').innerHTML=''; }
    });
}

// DaData по ИНН
// ── DaData по ИНН (ИСПРАВЛЕННАЯ: проверяет дубли перед заполнением) ──
function dadataFill() {
    const inn = gv('bInn');
    if (inn.length < 10) { notify('Введите ИНН (от 10 цифр)', 'error'); return; }
    
    const btn = document.querySelector('[onclick*="dadataFill"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '⌛ Поиск...'; }

    // 1. Сначала ищем клиента в своей базе по ИНН (чтобы не создавать дубль!)
    CRM.api('clients_pro', 'list', { search: inn, limit: 1 })
        .then(function(res) {
            const found = (res?.data || [])[0];
            
            // Если нашли клиента по ИНН — сразу подставляем его ID и не идём в DaData
            if (found) {
                _fillClientFromObj(found);
                window._ipClientId = String(found.id);
                if (btn) { btn.disabled = false; btn.innerHTML = ico('spark',11) + ' ИНН'; }
                notify('✅ Клиент найден в базе по ИНН: ' + found.name, 'success');
                return;
            }

            // 2. Если в базе не найден — тогда идём в DaData
            CRM.api('clients_pro', 'dadata_find', { query: inn })
                .then(function(r) {
                    if (btn) { btn.disabled = false; btn.innerHTML = ico('spark',11) + ' ИНН'; }
                    if (!r?.ok || !r.data) { 
                        notify('Не найдено в DaData', 'error'); 
                        return; 
                    }
                    
                    // Заполняем форму данными из DaData
                    _fillClientFromObj(r.data);
                    
                    // НО: после заполнения, снова проверяем по ИНН (на случай если DaData выдал другое имя)
                    CRM.api('clients_pro', 'list', { search: r.data.inn, limit: 1 })
                        .then(function(res2) {
                            const found2 = (res2?.data || [])[0];
                            if (found2) {
                                window._ipClientId = String(found2.id);
                            } else {
                                // Если реально новый клиент — оставляем ID пустым, PHP создаст его
                                window._ipClientId = null;
                            }
                            notify('✅ Реквизиты из DaData заполнены', 'success');
                        });
                });
        });
}

// Шаблоны позиций
function _saveTpl() {
    const items = _collectItems();
    if (!items.length) { notify('Добавьте позиции','error'); return; }
    const name = prompt('Название шаблона:');
    if (!name) return;
    api('save_template',{name,items}).then(r=>{
        if (r?.ok) notify('Шаблон "'+name+'" сохранён','success');
    });
}
function _loadTemplate(tplId) {
    api('get_templates').then(r=>{
        const tpl=(r?.data||[]).find(t=>t.id===tplId);
        if (!tpl) return;
        if (confirm(`Загрузить шаблон "${tpl.name}"? Текущие позиции будут заменены.`)) {
            const tbody=document.getElementById('ipItemsBody');
            if (tbody) tbody.innerHTML='';
            (tpl.items||[]).forEach(it=>addItemRow(it));
            recalc();
            notify('Шаблон загружен','success');
        }
    });
}

function _collectItems() {
    const items=[];
    document.querySelectorAll('#ipItemsBody tr').forEach(tr=>{
        const name=(tr.querySelector('.it-name')?.value||'').trim();
        const price=parseFloat(tr.querySelector('.it-price')?.value||0)||0;
        const qty=parseFloat(tr.querySelector('.it-qty')?.value||0)||0;
        if (name) items.push({
            name, unit:tr.querySelector('.it-unit')?.value||'шт', qty, price,
        });
    });
    return items;
}

// Сохранение — защита флагом S.saving
function doSave(editId, status) {
    if (S.saving) return;
    const items = _collectItems();
    if (!items.length) { notify('Добавьте хотя бы 1 позицию','error'); return; }
    const buyerName = gv('bName').trim();
    if (!buyerName) { notify('Укажите покупателя','error'); return; }
    S.saving = true;
    const btnS=document.getElementById('ipSaveSend');
    const btnD=document.getElementById('ipSaveDraft');
    if (btnS) { btnS.disabled=true; btnS.innerHTML='⌛ Сохранение...'; }
    if (btnD) { btnD.disabled=true; }

    // Срок оплаты: из input или из select
    const dueSel = gv('fDueSel');
    let dueVal = gv('fDue');
    if (dueSel==='0') dueVal = '';

    const body = {
        ...(editId?{id:editId}:{}),
        number:          gv('fNum'),
        date:            gv('fDate'),
        due_date:        dueVal,
        status,
        vat_type:        gv('fVat'),
        vat_rate:        parseFloat(gv('fVatRate'))||20,
        currency:        gv('fCur')||'₽',
        discount:        parseFloat(gv('fDiscount'))||0,
        discount_type:   gv('fDiscountType')||'none',
        buyer_name:      gv('bName'),
        buyer_type:      gv('bType'),
        buyer_inn:       gv('bInn'),
        buyer_kpp:       gv('bKpp'),
        buyer_ogrn:      gv('bOgrn'),
        buyer_address:   gv('bAddr'),
        buyer_phone:     gv('bPhone'),
        buyer_email:     gv('bEmail'),
        buyer_bank_name: gv('bBankName'),
        buyer_bank_bik:  gv('bBankBik'),
        buyer_bank_acc:  gv('bBankAcc'),
        buyer_bank_kor:  gv('bBankKor'),
        buyer_director:  gv('bDir'),
        client_id:       window._ipClientId||null,
        items,
        notes:           gv('fNotes'),
        contract_text:   gv('fContract'),
        payment_purpose: gv('fPurpose'),
    };
    // Реквизиты продавца из настроек
    const ss=S.settings;
    ['name','inn','kpp','ogrn','address','phone','email',
     'bank_name','bank_bik','bank_acc','bank_kor'].forEach(k=>{
        body['seller_'+k] = ss['seller_'+k]||'';
    });

    const reset = () => {
        S.saving=false;
        if (btnS) { btnS.disabled=false; btnS.innerHTML=ico('check',13)+' Сохранить'; }
        if (btnD) { btnD.disabled=false; btnD.innerHTML='💾 Черновик'; }
    };
    api('save',body).then(r=>{
        reset();
        if (!r?.ok) { notify(r?.error||'Ошибка сохранения','error'); return; }
        notify(editId?'✅ Счёт обновлён':'✅ Счёт создан','success');
        window._ipClientId=null;
        renderView(r.data.id);
    }).catch(e=>{ reset(); notify('Ошибка: '+e,'error'); });
}

// Обновить реквизиты покупателя из CRM
function _refreshBuyer(invId, clientId) {
    CRM.api('clients_pro','get',{id:clientId}).then(r=>{
        const c = r?.data;
        if (!c) { notify('Клиент не найден','error'); return; }
        if (!confirm('Обновить реквизиты покупателя из базы CRM?\n'+c.name)) return;
        api('save',{
            id:         invId,
            buyer_name:     c.name     ||'',
            buyer_phone:    c.phone    ||'',
            buyer_email:    c.email    ||'',
            buyer_inn:      c.inn      ||'',
            buyer_kpp:      c.kpp      ||'',
            buyer_ogrn:     c.ogrn     ||'',
            buyer_address:  c.address  ||'',
            buyer_director: c.director ||'',
            buyer_bank_name:c.bank_name||'',
            buyer_bank_bik: c.bank_bik ||'',
            buyer_bank_acc: c.bank_acc ||'',
            buyer_bank_kor: c.bank_ks  ||'',
        }).then(r2=>{
            if (r2?.ok) { notify('✅ Реквизиты обновлены','success'); renderView(invId); }
        });
    });
}

// Добавить комментарий
function _addComment(invId) {
    const text = gv('ipNewComment').trim();
    if (!text) return;
    api('add_comment',{id:invId,text}).then(r=>{
        if (r?.ok) {
            const inp = document.getElementById('ipNewComment');
            if (inp) inp.value='';
            renderView(invId);
        }
    });
}

// ══════════════════════════════════════════════════════════
//  АНАЛИТИКА
// ══════════════════════════════════════════════════════════
function renderAnalytics() {
    S.page = 'analytics';
    render(`
    <div style="padding:20px;max-width:1200px;">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:22px;flex-wrap:wrap;">
        <button class="ip-btn ip-btn-s" onclick="IP.back()">${ico('back',14)} Назад</button>
        <h2 style="margin:0;font-size:18px;font-weight:800;">
            ${ico('chart',18)} Аналитика счетов</h2>
        <input type="month" class="ip-in" id="analMon"
            value="${new Date().toISOString().slice(0,7)}"
            style="max-width:170px;margin-left:auto;"
            onchange="IP._loadAnal()">
    </div>
    <div id="analContent">
        <div style="text-align:center;padding:50px;color:#64748b;">
            <span class="ip-spin">${ico('chart',32)}</span></div>
    </div></div>`);
    loadAnalytics(new Date().toISOString().slice(0,7));
}

function loadAnalytics(month) {
    api('analytics',{month}).then(r=>{
        const el = document.getElementById('analContent'); if (!el) return;
        const d  = r||{};
        const f  = d.funnel||{};
        const maxF = Math.max(1,...Object.values(f));
        const bar = (label, count, color) => `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:9px;">
            <div style="width:88px;font-size:12px;color:#64748b;flex-shrink:0;">${label}</div>
            <div style="flex:1;background:rgba(255,255,255,.05);border-radius:5px;height:26px;
                overflow:hidden;position:relative;">
                <div style="width:${Math.round(count/maxF*100)}%;height:100%;
                    background:${color};border-radius:5px;transition:width .5s ease;"></div>
                <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);
                    font-size:11px;font-weight:700;color:#fff;mix-blend-mode:luminosity;">${count}</span>
            </div>
        </div>`;
        const hm = d.heatmap||[];
        const maxH = Math.max(1,...hm.map(h=>(h.sent||0)+(h.paid||0)));
        const heatHtml = hm.length
            ?`<div style="display:flex;flex-wrap:wrap;gap:5px;">
              ${hm.map(h=>{
                const v=(h.sent||0)+(h.paid||0);
                const intensity=Math.min(1,v/maxH);
                const bg=intensity===0?'rgba(255,255,255,.04)'
                    :`rgba(14,165,233,${(0.1+intensity*0.85).toFixed(2)})`;
                const day=(h.date||'').split('.')[0]||'';
                return `<div class="ip-hcell"
                    style="width:40px;height:40px;background:${bg};border-radius:6px;
                        display:flex;flex-direction:column;align-items:center;justify-content:center;"
                    title="${h.date}: выставлено ${fmt(h.sent||0)} ₽, оплачено ${fmt(h.paid||0)} ₽">
                    <div style="font-size:10px;font-weight:700;">${day}</div>
                    <div style="font-size:8px;opacity:.7;">${v>0?Math.round(v/1000)+'к':'·'}</div>
                </div>`;
              }).join('')}</div>`
            :'<div style="color:#64748b;font-size:12px;padding:20px;">Нет данных</div>';
        const paidPct = d.month_total>0?Math.round(d.month_paid/d.month_total*100):0;
        const top = (d.top_clients||[]).map((c,i)=>
            `<div style="display:flex;align-items:center;gap:10px;padding:9px 0;
                border-bottom:1px solid rgba(255,255,255,.04);">
                <div style="width:26px;height:26px;border-radius:8px;
                    background:rgba(14,165,233,.15);color:#0ea5e9;
                    display:flex;align-items:center;justify-content:center;
                    font-weight:800;font-size:11px;flex-shrink:0;">${i+1}</div>
                <div style="flex:1;font-size:13px;font-weight:600;
                    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(c.name)}</div>
                <div style="font-size:11px;color:#64748b;flex-shrink:0;">${c.count} сч.</div>
                <div style="font-weight:800;color:#4ade80;flex-shrink:0;">${fmt(c.total)} ₽</div>
            </div>`
        ).join('')||'<div style="color:#64748b;font-size:12px;padding:20px;">Нет данных</div>';
        const topItems = (d.top_items||[]).map((it,i)=>
            `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;
                border-bottom:1px solid rgba(255,255,255,.04);">
                <div style="width:22px;height:22px;border-radius:6px;
                    background:rgba(167,139,250,.15);color:#a78bfa;
                    display:flex;align-items:center;justify-content:center;
                    font-size:10px;font-weight:800;flex-shrink:0;">${i+1}</div>
                <div style="flex:1;font-size:12px;overflow:hidden;text-overflow:ellipsis;
                    white-space:nowrap;">${esc(it.name)}</div>
                <div style="font-size:11px;color:#64748b;flex-shrink:0;">${it.count} раз</div>
                <div style="font-weight:700;color:#a78bfa;flex-shrink:0;">${fmt(it.total)} ₽</div>
            </div>`
        ).join('')||'<div style="color:#64748b;font-size:12px;padding:20px;">Нет данных</div>';

        el.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div class="ip-card">
                <div class="ip-sec">${ico('chart',12)} Воронка счетов</div>
                ${bar('Черновики',  f.draft    ||0,'#64748b')}
                ${bar('Отправлены', f.sent     ||0,'#0ea5e9')}
                ${bar('Оплачены',   f.paid     ||0,'#22c55e')}
                ${bar('Просрочены', f.overdue  ||0,'#ef4444')}
                ${bar('Отменены',   f.cancelled||0,'#334155')}
            </div>
            <div class="ip-card">
                <div class="ip-sec">${ico('spark',12)} Прогноз месяца</div>
                <div style="font-size:30px;font-weight:900;color:#4ade80;margin-bottom:12px;line-height:1;">
                    ${fmt(d.forecast||0)} ₽</div>
                <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;">
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:#64748b;">Оплачено</span>
                        <strong style="color:#4ade80;">${fmt(d.month_paid||0)} ₽</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:#64748b;">Выставлено</span>
                        <strong>${fmt(d.month_total||0)} ₽</strong>
                    </div>
                    <div class="ip-progress"><div class="ip-progress-bar" style="width:${paidPct}%;"></div></div>
                    <div style="font-size:11px;color:#64748b;">
                        Конверсия: <b style="color:#f1f5f9;">${paidPct}%</b></div>
                </div>
            </div>
        </div>
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">📅 Активность по дням</div>
            ${heatHtml}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="ip-card">
                <div class="ip-sec">${ico('star',12)} Топ клиентов</div>
                ${top}
            </div>
            <div class="ip-card">
                <div class="ip-sec">${ico('tag',12)} Топ позиций</div>
                ${topItems}
            </div>
        </div>`;
    });
}

// ══════════════════════════════════════════════════════════
//  КЛИЕНТ 360°
// ══════════════════════════════════════════════════════════
function renderClient360(phone, name) {
    S.page = 'c360';
    render(`
    <div style="padding:20px;max-width:1060px;">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:22px;flex-wrap:wrap;">
        <button class="ip-btn ip-btn-s" onclick="IP.back()">${ico('back',14)} Назад</button>
        <h2 style="margin:0;font-size:18px;font-weight:800;">${ico('user',18)} Клиент 360°</h2>
    </div>
    <div class="ip-card" style="margin-bottom:22px;
        background:linear-gradient(135deg,rgba(14,165,233,.08),rgba(167,139,250,.08));">
        <div style="display:flex;gap:16px;align-items:center;">
            <div style="width:60px;height:60px;border-radius:16px;
                background:linear-gradient(135deg,var(--ip-accent,#0ea5e9),#a78bfa);
                display:flex;align-items:center;justify-content:center;
                font-size:24px;font-weight:900;color:#fff;flex-shrink:0;">
                ${(name||'?').charAt(0).toUpperCase()}
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:21px;font-weight:800;overflow:hidden;
                    text-overflow:ellipsis;white-space:nowrap;">${esc(name||'Клиент')}</div>
                <div style="font-size:12px;color:#64748b;margin-top:3px;">${esc(phone||'')}</div>
            </div>
            <button class="ip-btn ip-btn-p"
                onclick="IP.newForClient('${esc(phone||'')}','${esc(name||'')}')">
                ${ico('plus',13)} Выставить счёт</button>
        </div>
    </div>
    <div style="display:flex;gap:0;border-bottom:1px solid var(--border,#334155);
        margin-bottom:18px;overflow-x:auto;">
        <button class="ip-tab active" id="c360t-invoices"
            onclick="IP._c360tab('invoices','${esc(phone||'')}','${esc(name||'')}')">
            ${ico('invoice',13)} Счета</button>
        <button class="ip-tab" id="c360t-orders"
            onclick="IP._c360tab('orders','${esc(phone||'')}','${esc(name||'')}')">
            ${ico('history',13)} Заказы</button>
        <button class="ip-tab" id="c360t-profile"
            onclick="IP._c360tab('profile','${esc(phone||'')}','${esc(name||'')}')">
            ${ico('user',13)} Профиль</button>
    </div>
    <div id="c360body">
        <div style="text-align:center;padding:40px;">
            <span class="ip-spin">${ico('invoice',28)}</span></div>
    </div></div>`);
    _c360LoadInvoices(phone, name);
}

function _c360tab(tab, phone, name) {
    document.querySelectorAll('.ip-tab').forEach(b=>b.classList.remove('active'));
    const btn = document.getElementById('c360t-'+tab);
    if (btn) btn.classList.add('active');
    if (tab==='invoices')     _c360LoadInvoices(phone, name);
    else if (tab==='orders')  _c360LoadOrders(phone, name);
    else if (tab==='profile') _c360LoadProfile(phone, name);
}

function _c360LoadInvoices(phone, name) {
    api('client_invoices',{phone,name}).then(r=>{
        const el  = document.getElementById('c360body'); if (!el) return;
        const list = r?.data||[];
        const stats = r?.stats||{};
        const convRate = stats.total>0?Math.round(stats.paid/stats.total*100):0;
        const kpiHtml = `<div style="display:grid;grid-template-columns:repeat(4,1fr);
            gap:10px;margin-bottom:18px;">
        ${[{l:'Счетов',v:stats.count||0,c:'#38bdf8'},
           {l:'Выставлено',v:fmt(stats.total||0)+' ₽',c:'#a78bfa'},
           {l:'Оплачено',v:fmt(stats.paid||0)+' ₽',c:'#4ade80'},
           {l:'Конверсия',v:convRate+'%',c:'#fb923c'},
        ].map(p=>`<div class="ip-kpi">
            <div style="font-size:10px;color:#64748b;margin-bottom:5px;">${p.l}</div>
            <div style="font-size:18px;font-weight:800;color:${p.c};">${p.v}</div>
        </div>`).join('')}</div>`;
        if (!list.length) {
            el.innerHTML=kpiHtml+`<div style="text-align:center;padding:50px;color:#64748b;">
                ${ico('invoice',36)}<br><br>
                <div style="font-size:14px;margin-bottom:14px;">Счетов ещё нет</div>
                <button class="ip-btn ip-btn-p"
                    onclick="IP.newForClient('${esc(phone||'')}','${esc(name||'')}')">
                    ${ico('plus',14)} Выставить первый счёт</button>
            </div>`;
            return;
        }
        el.innerHTML = kpiHtml+`<div class="ip-card">
        <div class="ip-sec">${ico('history',12)} История счетов</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
        ${list.map(inv=>`
        <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;
            background:rgba(255,255,255,.03);border-radius:10px;
            border-left:3px solid ${(ST[inv.status]||ST.draft).dot};">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;">
                    №${esc(inv.number)}
                    <span style="font-size:11px;color:#64748b;font-weight:400;margin-left:8px;">
                        от ${esc(inv.date)}</span>
                </div>
                <div style="margin-top:3px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    ${badge(inv.status)}
                    ${inv.has_closing_docs?'<span style="font-size:10px;color:#a78bfa;">📑 документы</span>':''}
                </div>
            </div>
            <div style="font-size:16px;font-weight:800;flex-shrink:0;">${fmt(inv.total)} ₽</div>
            <div style="display:flex;gap:5px;flex-shrink:0;">
                <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.view('${inv.id}')">
                    ${ico('eye',12)} Открыть</button>
                <button class="ip-btn ip-btn-s ip-btn-sm" onclick="IP.pdfMenu('${inv.id}',this)">
                    ${ico('pdf',12)}</button>
            </div>
        </div>`).join('')}
        </div></div>`;
    });
}

function _c360LoadOrders(phone, name) {
    const el=document.getElementById('c360body'); if(!el) return;
    el.innerHTML=`<div style="text-align:center;padding:30px;">
        <span class="ip-spin">${ico('history',24)}</span></div>`;
    CRM.api('clients_pro','client_orders',{phone,name}).then(r=>{
        const orders=r?.data||[];
        if (!orders.length) {
            el.innerHTML='<div style="text-align:center;padding:40px;color:#64748b;">Заказов нет</div>';
            return;
        }
        const dotColors={new:'#0ea5e9',work:'#fb923c',ready:'#a78bfa',done:'#22c55e',cancel:'#ef4444'};
        el.innerHTML=`<div class="ip-card">
        <div class="ip-sec">${ico('history',12)} История заказов</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
        ${orders.map(o=>`
        <div style="display:flex;align-items:center;gap:12px;padding:11px 14px;
            background:rgba(255,255,255,.03);border-radius:10px;
            border-left:3px solid ${dotColors[o.status]||'#334155'};">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;">${esc(o.num||o.id||'')}</div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    ${esc(o.serviceLabel||o.service||'—')}
                    ${o.date?' · '+new Date(o.date).toLocaleDateString('ru-RU'):''}
                </div>
            </div>
            <div style="font-weight:700;flex-shrink:0;">${o.total?fmt(parseFloat(o.total))+' ₽':'—'}</div>
            <button class="ip-btn ip-btn-p ip-btn-xs"
                onclick="IP.newFromOrder('${esc(phone)}','${esc(name)}','${esc(o.num||o.id||'')}',this)"
                title="Выставить счёт по заказу">${ico('plus',11)} Счёт</button>
        </div>`).join('')}
        </div></div>`;
    }).catch(()=>{
        el.innerHTML='<div style="color:#f87171;padding:20px;">Ошибка загрузки заказов</div>';
    });
}

function _c360LoadProfile(phone, name) {
    const el=document.getElementById('c360body'); if(!el) return;
    el.innerHTML=`<div style="text-align:center;padding:30px;">
        <span class="ip-spin">${ico('user',24)}</span></div>`;
    CRM.api('clients_pro','list',{search:phone||name}).then(r=>{
        const clients=r?.data||[];
        const c=clients.find(x=>x.phone===phone||x.name===name);
        if (!c) {
            el.innerHTML=`<div style="color:#64748b;padding:30px;text-align:center;">
                Клиент не найден в базе CRM</div>`;
            return;
        }
        const av=c.vk_avatar||c.avatar_url||'';
        const row=(label,val)=>val?`<div style="display:flex;gap:10px;padding:8px 0;
            border-bottom:1px solid rgba(255,255,255,.04);">
            <div style="width:120px;font-size:11px;color:#64748b;flex-shrink:0;">${label}</div>
            <div style="font-size:12px;font-weight:600;">${esc(val)}</div>
            </div>`:''
        el.innerHTML=`<div class="ip-card">
        <div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:18px;">
            ${av?`<img src="${esc(av)}" style="width:68px;height:68px;border-radius:14px;
                object-fit:cover;flex-shrink:0;">`
               :`<div style="width:68px;height:68px;border-radius:14px;flex-shrink:0;
                background:linear-gradient(135deg,var(--ip-accent,#0ea5e9),#a78bfa);
                display:flex;align-items:center;justify-content:center;
                font-size:28px;font-weight:900;color:#fff;">
                ${(c.name||'?').charAt(0).toUpperCase()}</div>`}
            <div style="flex:1;min-width:0;">
                <div style="font-size:19px;font-weight:800;">${esc(c.name||'')}</div>
                <div style="font-size:12px;color:#64748b;margin-top:3px;">
                    ${esc(c.bizcat||'')}${c.type?' · '+esc(c.type):''}</div>
                ${c.crm_status?`<div style="margin-top:6px;">${badge(c.crm_status)}</div>`:''}
            </div>
            <button class="ip-btn ip-btn-s ip-btn-sm" style="flex-shrink:0;"
                onclick="window.CRM?.modules?.clients_pro?.openDetail?.('${c.id}')">
                Открыть профиль</button>
        </div>
        ${row('Телефон',c.phone)}${row('Email',c.email)}${row('Адрес',c.address)}
        ${row('ИНН',c.inn)}${row('КПП',c.kpp)}${row('ОГРН',c.ogrn)}
        ${row('Руководитель',c.director)}
        ${row('Банк',c.bank_name)}${row('Р/с',c.bank_acc)}${row('БИК',c.bank_bik)}
        ${c.discount?`<div style="margin-top:10px;padding:8px 12px;
            background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);
            border-radius:8px;font-size:12px;">
            💰 Постоянный клиент — скидка <b>${c.discount}%</b></div>`:''}
        ${c.notes?`<div style="margin-top:10px;padding:10px 12px;
            background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);
            border-radius:8px;font-size:12px;color:#94a3b8;">${esc(c.notes)}</div>`:''}
        </div>`;
    });
}

// ══════════════════════════════════════════════════════════
//  НАСТРОЙКИ
// ══════════════════════════════════════════════════════════
function renderSettings() {
    S.page = 'settings';
    api('get_settings').then(r=>{
        S.settings=r?.data||{};
        const s=S.settings;
        render(`
        <div style="padding:20px;max-width:820px;">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:22px;flex-wrap:wrap;">
            <button class="ip-btn ip-btn-s" onclick="IP.back()">${ico('back',14)} Назад</button>
            <h2 style="margin:0;font-size:18px;font-weight:800;">${ico('cog',18)} Настройки счетов</h2>
            <button class="ip-btn ip-btn-p" style="margin-left:auto;"
                onclick="IP.doSaveSettings()">${ico('check',13)} Сохранить</button>
        </div>

        <!-- РЕКВИЗИТЫ ОРГАНИЗАЦИИ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">🏢 Ваша организация (продавец)</div>
            <div class="ip-grid2" style="margin-bottom:12px;">
                ${[['sName','Полное название *',s.seller_name||''],
                   ['sInn','ИНН',s.seller_inn||''],
                   ['sKpp','КПП',s.seller_kpp||''],
                   ['sOgrn','ОГРН',s.seller_ogrn||''],
                   ['sPhone','Телефон',s.seller_phone||''],
                   ['sEmail','Email',s.seller_email||''],
                ].map(f=>`<div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">${f[1]}</label>
                    <input class="ip-in" id="${f[0]}" value="${esc(f[2])}" placeholder="${f[1]}">
                </div>`).join('')}
                <div style="grid-column:1/-1;">
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">
                        Юридический адрес</label>
                    <input class="ip-in" id="sAddr" value="${esc(s.seller_address||'')}"
                        placeholder="г. Москва, ул. Примерная, д. 1">
                </div>
            </div>
            <div class="ip-sec">🏦 Банковские реквизиты</div>
            <div class="ip-grid2">
                ${[['sBankName','Наименование банка',s.seller_bank_name||''],
                   ['sBankBik','БИК',s.seller_bank_bik||''],
                   ['sBankAcc','Расчётный счёт',s.seller_bank_acc||''],
                   ['sBankKor','Корреспондентский счёт',s.seller_bank_kor||''],
                ].map(f=>`<div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">${f[1]}</label>
                    <input class="ip-in" id="${f[0]}" value="${esc(f[2])}" placeholder="${f[1]}">
                </div>`).join('')}
            </div>
        </div>

        <!-- ПОДПИСЬ, ПЕЧАТЬ, ЛОГО -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">✍️ Подпись, печать и логотип</div>
            <div class="ip-grid2" style="margin-bottom:14px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">ФИО руководителя</label>
                    <input class="ip-in" id="sSigName" value="${esc(s.signatory_name||'')}"
                        placeholder="Иванов Иван Иванович">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Должность</label>
                    <input class="ip-in" id="sSigTitle" value="${esc(s.signatory_title||'Директор')}"
                        placeholder="Директор">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">ФИО бухгалтера</label>
                    <input class="ip-in" id="sAccName" value="${esc(s.accountant_name||'')}"
                        placeholder="Петрова Мария Ивановна">
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding-top:20px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="sShowQR"
                            ${s.show_qr?'checked':''}
                            style="width:16px;height:16px;cursor:pointer;">
                        <span style="font-size:12px;">QR-код в документе (СБП)</span>
                    </label>
                </div>
            </div>
            <!-- Загрузка изображений — ИСПРАВЛЕНО -->
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                ${[
                    ['stamp',     '🔵 Печать (PNG/SVG)', s.stamp_url||'',     90, 90],
                    ['signature', '✍️ Подпись (PNG)',     s.signature_url||'', 110,50],
                    ['logo',      '🖼️ Логотип',           s.logo_url||'',      120,55],
                ].map(f=>`
                <div style="text-align:center;">
                    <div class="ip-hint" style="margin-bottom:8px;">${f[1]}</div>
                    <div class="ip-upload-zone" id="${f[0]}Prev"
                        style="width:${f[3]}px;height:${f[4]}px;"
                        onclick="document.getElementById('${f[0]}Up').click()">
                        ${f[2]
                            ?`<img src="${esc(f[2])}" style="max-width:100%;max-height:100%;object-fit:contain;">`
                            :`<span style="font-size:10px;color:#475569;text-align:center;">
                              ${ico('upload',16)}<br>Загрузить</span>`}
                    </div>
                    <input type="file" id="${f[0]}Up" accept="image/*"
                        style="display:none;"
                        onchange="IP.uploadImg(this,'${f[0]}')">
                    <button class="ip-btn ip-btn-s ip-btn-xs" style="margin-top:6px;"
                        onclick="document.getElementById('${f[0]}Up').click()">
                        ${ico('upload',10)} Загрузить</button>
                    ${f[2]?`<button class="ip-btn ip-btn-d ip-btn-xs" style="margin-top:6px;margin-left:4px;"
                        onclick="IP._clearImg('${f[0]}')">✕</button>`:''}
                </div>`).join('')}
            </div>
        </div>

        <!-- НУМЕРАЦИЯ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">🔢 Нумерация счетов</div>
            <div class="ip-grid4">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Префикс</label>
                    <input class="ip-in" id="sPfx" value="${esc(s.number_prefix||'')}" placeholder="СЧ-">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Суффикс</label>
                    <input class="ip-in" id="sSfx" value="${esc(s.number_suffix||'')}">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Следующий №</label>
                    <input type="number" class="ip-in" id="sNext"
                        value="${parseInt(s.next_number||1)}" min="1">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Дней на оплату</label>
                    <input type="number" class="ip-in" id="sDays"
                        value="${parseInt(s.default_payment_days||3)}" min="1">
                </div>
            </div>
        </div>

        <!-- НДС И УСЛОВИЯ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">💰 Умолчания и условия</div>
            <div class="ip-grid3" style="margin-bottom:14px;">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">НДС по умолчанию</label>
                    <select class="ip-in" id="sDefVat">
                        ${[['none','Без НДС'],['included','В т.ч. НДС'],['on_top','Сверху']].map(v=>
                            `<option value="${v[0]}" ${s.default_vat===v[0]?'selected':''}>${v[1]}</option>`
                        ).join('')}
                    </select>
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Ставка НДС %</label>
                    <input type="number" class="ip-in" id="sVatRate"
                        value="${parseFloat(s.vat_rate||20)}">
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">
                        Алерт за N дней до просрочки</label>
                    <input type="number" class="ip-in" id="sAlertDays"
                        value="${parseInt(s.overdue_alert_days||3)}">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label class="ip-hint" style="display:block;margin-bottom:4px;">
                    Условия оплаты по умолчанию</label>
                <textarea class="ip-in" id="sContract" rows="3"
                    style="resize:vertical;">${esc(s.contract_text||'')}</textarea>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">
                <input type="checkbox" id="sAutoSync" ${s.auto_sync_clients!==false?'checked':''}
                    style="width:16px;height:16px;cursor:pointer;">
                Автоматически создавать/обновлять клиентов в CRM при сохранении счёта
            </label>
        </div>

        <!-- РЕКЛАМНЫЕ БЛОКИ -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">📢 Рекламные блоки в документе</div>
            ${['top','bottom'].map(pos=>{
                const en  = s['ad_'+pos+'_enabled']  || false;
                const tp  = s['ad_'+pos+'_type']     || 'text';
                const txt = s['ad_'+pos+'_text']     || '';
                const lnk = s['ad_'+pos+'_link']     || '';
                const img = s['ad_'+pos+'_image_url']|| '';
                return `<div style="margin-bottom:16px;padding:14px;
                    background:rgba(255,255,255,.03);border-radius:10px;
                    border:1px solid rgba(255,255,255,.06);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="sAd${pos}En" ${en?'checked':''}
                            style="width:16px;height:16px;cursor:pointer;"
                            onchange="document.getElementById('adBody${pos}').style.display=this.checked?'':'none'">
                        <span style="font-weight:700;font-size:13px;">
                            ${pos==='top'?'📢 Баннер сверху (над банком)':'📢 Баннер снизу (под подписями)'}</span>
                    </label>
                </div>
                <div id="adBody${pos}" style="display:${en?'':'none'};">
                    <div class="ip-grid2" style="margin-bottom:10px;">
                        <div>
                            <label class="ip-hint" style="display:block;margin-bottom:4px;">Тип</label>
                            <select class="ip-in" id="sAd${pos}Type"
                                onchange="document.getElementById('adImg${pos}').style.display=this.value==='image'?'':'none';
                                document.getElementById('adTxt${pos}').style.display=this.value==='text'?'':'none'">
                                <option value="text" ${tp==='text'?'selected':''}>Текст</option>
                                <option value="image" ${tp==='image'?'selected':''}>Изображение</option>
                            </select>
                        </div>
                        <div>
                            <label class="ip-hint" style="display:block;margin-bottom:4px;">Ссылка (необязательно)</label>
                            <input class="ip-in" id="sAd${pos}Link" value="${esc(lnk)}"
                                placeholder="https://...">
                        </div>
                    </div>
                    <div id="adTxt${pos}" style="display:${tp==='text'?'':'none'};margin-bottom:10px;">
                        <label class="ip-hint" style="display:block;margin-bottom:4px;">Текст баннера</label>
                        <textarea class="ip-in" id="sAd${pos}Text" rows="2"
                            style="resize:none;" placeholder="Спасибо за оплату! Скидка 5% на следующий заказ"
                            >${esc(txt)}</textarea>
                    </div>
                    <div id="adImg${pos}" style="display:${tp==='image'?'':'none'};">
                        <label class="ip-hint" style="display:block;margin-bottom:6px;">Изображение баннера</label>
                        <div style="display:flex;gap:12px;align-items:center;">
                            <div class="ip-upload-zone" id="ad${pos}ImgPrev"
                                style="width:200px;height:60px;"
                                onclick="document.getElementById('ad${pos}Up').click()">
                                ${img
                                    ?`<img src="${esc(img)}" style="max-width:100%;max-height:100%;object-fit:contain;">`
                                    :`<span style="font-size:10px;color:#475569;">${ico('upload',14)}&nbsp;Загрузить</span>`}
                            </div>
                            <input type="file" id="ad${pos}Up" accept="image/*"
                                style="display:none;"
                                onchange="IP.uploadImg(this,'ad_${pos}_image')">
                            <button class="ip-btn ip-btn-s ip-btn-sm"
                                onclick="document.getElementById('ad${pos}Up').click()">
                                ${ico('upload',12)} Загрузить</button>
                        </div>
                    </div>
                </div>
                </div>`;
            }).join('')}
        </div>

        <!-- ДИЗАЙН -->
        <div class="ip-card" style="margin-bottom:16px;">
            <div class="ip-sec">🎨 Оформление</div>
            <div class="ip-grid2">
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Цвет акцента</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" class="ip-in" id="sThemeColor"
                            value="${esc(s.theme_color||'#0ea5e9')}"
                            style="max-width:60px;height:38px;padding:3px;"
                            oninput="setAccent(this.value)">
                        <span style="font-size:11px;color:#64748b;">
                            Используется в PDF-документах и интерфейсе</span>
                    </div>
                </div>
                <div>
                    <label class="ip-hint" style="display:block;margin-bottom:4px;">Email отправителя</label>
                    <input class="ip-in" id="sSmtpFrom"
                        value="${esc(s.smtp_from||s.seller_email||'')}"
                        placeholder="invoice@company.ru">
                </div>
            </div>
        </div>
        </div>`);
    });
}

// Сохранить настройки
function doSaveSettings() {
    const s = S.settings; // текущие настройки как fallback

    const payload = {
        seller_name:          gv('sName'),
        seller_inn:           gv('sInn'),
        seller_kpp:           gv('sKpp'),
        seller_ogrn:          gv('sOgrn'),
        seller_address:       gv('sAddr'),
        seller_phone:         gv('sPhone'),
        seller_email:         gv('sEmail'),
        seller_bank_name:     gv('sBankName'),
        seller_bank_bik:      gv('sBankBik'),
        seller_bank_acc:      gv('sBankAcc'),
        seller_bank_kor:      gv('sBankKor'),
        signatory_name:       gv('sSigName'),
        signatory_title:      gv('sSigTitle'),
        accountant_name:      gv('sAccName'),
        number_prefix:        gv('sPfx'),
        number_suffix:        gv('sSfx'),
        next_number:          parseInt(gv('sNext'))      || 1,
        default_payment_days: parseInt(gv('sDays'))      || 3,
        default_vat:          gv('sDefVat'),
        vat_rate:             parseFloat(gv('sVatRate')) || 20,
        overdue_alert_days:   parseInt(gv('sAlertDays')) || 3,
        contract_text:        gv('sContract'),
        smtp_from:            gv('sSmtpFrom'),
        theme_color:          gv('sThemeColor') || '#0ea5e9',
        show_qr:              document.getElementById('sShowQR')?.checked ?? true,
        auto_sync_clients:    document.getElementById('sAutoSync')?.checked ?? true,
        // Рекламные блоки
        ad_top_enabled:       document.getElementById('sAdtopEn')?.checked    ?? false,
        ad_top_type:          gv('sAdtopType')   || 'text',
        ad_top_text:          gv('sAdtopText')   || '',
        ad_top_link:          gv('sAdtopLink')   || '',
        ad_bottom_enabled:    document.getElementById('sAdbottomEn')?.checked ?? false,
        ad_bottom_type:       gv('sAdbottomType')|| 'text',
        ad_bottom_text:       gv('sAdbottomText')|| '',
        ad_bottom_link:       gv('sAdbottomLink')|| '',

        // ✅ ИСПРАВЛЕНИЕ: берём из S._xxxUrl если загружали в эту сессию,
        //    иначе берём текущее значение из S.settings (не затираем пустым)
        stamp_url:             S._stampUrl        || s.stamp_url        || '',
        signature_url:         S._signatureUrl    || s.signature_url    || '',
        logo_url:              S._logoUrl         || s.logo_url         || '',
        ad_top_image_url:      S._ad_top_imageUrl || s.ad_top_image_url || '',
        ad_bottom_image_url:   S._ad_bottom_imageUrl || s.ad_bottom_image_url || '',
    };

    api('save_settings', payload).then(r => {
        if (r?.ok) {
            notify('✅ Настройки сохранены', 'success');
            S.settings = Object.assign(S.settings, payload);
            // Сбрасываем временные URL после успешного сохранения
            S._stampUrl = S._signatureUrl = S._logoUrl = 
            S._ad_top_imageUrl = S._ad_bottom_imageUrl = null;
            setAccent(payload.theme_color);
        } else {
            notify(r?.error || 'Ошибка сохранения', 'error');
        }
    });
}

// ══════════════════════════════════════════════════════════
//  ЗАГРУЗКА ИЗОБРАЖЕНИЙ — ИСПРАВЛЕННАЯ ВЕРСИЯ
//  Использует /admin/api.php?action=upload (ядро API)
//  + затем сохраняет URL через invoice_pro/upload_image
// ══════════════════════════════════════════════════════════
function uploadImg(input, field) {
    if (!input.files?.length) return;
    const file = input.files[0];

    const allowedExt = ['jpg','jpeg','png','gif','webp','svg'];
    const ext = file.name.split('.').pop().toLowerCase();
    if (!allowedExt.includes(ext)) {
        notify('Недопустимый формат: ' + allowedExt.join(', '), 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        notify('Файл больше 5MB', 'error');
        return;
    }

    // Превью через FileReader
    const reader = new FileReader();
    reader.onload = e => {
        const prevId = _getPrevId(field);
        const prev = document.getElementById(prevId);
        if (prev) prev.innerHTML = `<img src="${e.target.result}"
            style="max-width:100%;max-height:100%;object-fit:contain;">`;
    };
    reader.readAsDataURL(file);

    // Спиннер
    const prevId = _getPrevId(field);
    const prevEl = document.getElementById(prevId);
    let origContent = '';
    if (prevEl) {
        origContent = prevEl.innerHTML;
        prevEl.innerHTML = `<span class="ip-spin" style="color:#0ea5e9;">${ico('upload',20)}</span>`;
    }

    // ✅ Загружаем через модульный API (PHP case 'upload_image' умеет принимать файл)
    const formData = new FormData();
    formData.append('file', file);
    formData.append('field', field); // PHP читает из $_POST['field']

    // Определяем URL эндпоинта модуля
    const uploadUrl = '/admin/index.php?module=invoice_pro&action=upload_image';

    fetch(uploadUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.ok || !res.url) {
                if (prevEl) prevEl.innerHTML = origContent;
                notify('Ошибка загрузки: ' + (res.error || '?'), 'error');
                return;
            }
            const url = res.url;

            // Показываем результат
            if (prevEl) prevEl.innerHTML = `<img src="${url}"
                style="max-width:100%;max-height:100%;object-fit:contain;">`;

            // ✅ Сохраняем URL в памяти для последующего doSaveSettings
            const storeKey = '_' + field.replace(/-/g,'_') + 'Url';
            S[storeKey] = url;

            // ✅ Обновляем S.settings чтобы при перерендере не потерялось
            const settingKey = field + '_url';
            if (S.settings) S.settings[settingKey] = url;

            notify('✅ Изображение загружено. Нажмите «Сохранить» для применения.', 'success');
        })
        .catch(err => {
            if (prevEl) prevEl.innerHTML = origContent;
            notify('Ошибка сети: ' + err, 'error');
        });
}

// Вспомогательная функция определения ID превью-блока
function _getPrevId(field) {
    const map = {
        'stamp':           'stampPrev',
        'signature':       'signaturePrev',
        'logo':            'logoPrev',
        'ad_top_image':    'adtopImgPrev',
        'ad_bottom_image': 'adbottomImgPrev',
    };
    return map[field] || (field + 'Prev');
}

// ══════════════════════════════════════════════════════════
//  PDF МЕНЮ
// ══════════════════════════════════════════════════════════
function pdfMenu(id, btn) {
    document.querySelectorAll('.ip-pdf-menu').forEach(m => m.remove());
    const menu = document.createElement('div');
    menu.className = 'ip-pdf-menu';
    const r = btn.getBoundingClientRect();
    Object.assign(menu.style, {
        position:'fixed', top:(r.bottom+5)+'px', left:r.left+'px',
        background:'var(--bg-card,#1e293b)',
        border:'1px solid var(--border,#334155)',
        borderRadius:'12px',
        boxShadow:'0 12px 40px rgba(0,0,0,.6)',
        zIndex:'99999', minWidth:'230px', padding:'6px 0',
    });
    menu.innerHTML = [
        ['invoice',  '📄 Счёт на оплату'],
        ['act',      '📝 Акт выполненных работ'],
        ['delivery', '🚚 Товарная накладная'],
        ['upd',      '📋 УПД'],
    ].map(t => `
    <div style="padding:10px 16px;cursor:pointer;font-size:13px;
        transition:background .1s;display:flex;align-items:center;gap:10px;"
        onmouseover="this.style.background='rgba(255,255,255,.07)'"
        onmouseout="this.style.background=''"
        onclick="this.closest('.ip-pdf-menu').remove();IP.openPdf('${id}','${t[0]}')">
        ${t[1]}</div>`
    ).join('');
    document.body.appendChild(menu);
    setTimeout(() => {
        const closer = e => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closer, true);
            }
        };
        document.addEventListener('click', closer, true);
    }, 50);
}

function openPdf(id, type) {
    const loadingId = 'ip-pdf-loading';
    document.getElementById(loadingId)?.remove();
    const loader = document.createElement('div');
    loader.id = loadingId;
    loader.style.cssText = `
        position:fixed;bottom:20px;right:20px;z-index:99999;
        background:var(--bg-card,#1e293b);
        border:1px solid var(--border,#334155);
        border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600;
        box-shadow:0 8px 32px rgba(0,0,0,.5);
        display:flex;align-items:center;gap:10px;`;
    loader.innerHTML = `<span class="ip-spin">${ico('pdf',16)}</span> Генерация...`;
    document.body.appendChild(loader);

    api('generate_pdf', {id, doc_type: type})
        .then(r => {
            loader.remove();
            if (r?.ok && r.url) {
                window.open(r.url, '_blank');
            } else {
                notify('Ошибка: ' + (r?.error || '?'), 'error');
            }
        })
        .catch(err => {
            loader.remove();
            notify('Ошибка: ' + err, 'error');
        });
}

// ══════════════════════════════════════════════════════════
//  EMAIL МОДАЛКА
// ══════════════════════════════════════════════════════════
function emailModal(id, defEmail) {
    document.getElementById('ipEmailMod')?.remove();
    const m = document.createElement('div');
    m.className = 'ip-modal'; m.id = 'ipEmailMod';
    m.innerHTML = `
    <div class="ip-modal-box" style="max-width:460px;">
    <div class="ip-modal-head">
        <span style="font-weight:800;display:flex;align-items:center;gap:9px;font-size:15px;">
            ${ico('mail',17)} Отправить счёт по Email</span>
        <button class="ip-close"
            onclick="document.getElementById('ipEmailMod').remove()">✕</button>
    </div>
    <div class="ip-modal-body">
        <label class="ip-hint" style="display:block;margin-bottom:6px;">Email получателя *</label>
        <input class="ip-in" id="ipEmailTo" type="email"
            value="${esc(defEmail)}" placeholder="client@company.ru"
            onkeydown="if(event.key==='Enter')IP._doEmail('${id}')">
        <div class="ip-hint" style="margin-top:8px;">
            Клиенту придёт письмо со ссылкой на счёт для просмотра и печати</div>
    </div>
    <div class="ip-modal-foot">
        <button class="ip-btn ip-btn-s"
            onclick="document.getElementById('ipEmailMod').remove()">Отмена</button>
        <button class="ip-btn ip-btn-p" id="ipEmailBtn"
            onclick="IP._doEmail('${id}')">
            ${ico('send',13)} Отправить</button>
    </div></div>`;
    document.body.appendChild(m);
    m.addEventListener('click', e => { if (e.target===m) m.remove(); });
    setTimeout(() => document.getElementById('ipEmailTo')?.focus(), 50);
}

function doEmail(id) {
    const email = gv('ipEmailTo');
    if (!email || !/\S+@\S+\.\S+/.test(email)) {
        notify('Введите корректный email', 'error'); return;
    }
    const btn = document.getElementById('ipEmailBtn');
    if (btn) { btn.disabled=true; btn.innerHTML=ico('send',13)+' Отправка...'; }
    api('send_email', {id, email}).then(r => {
        document.getElementById('ipEmailMod')?.remove();
        if (r?.ok) notify('✅ Счёт отправлен на ' + email, 'success');
        else notify('Ошибка PHP mail() — проверьте настройки сервера', 'error');
    });
}

// ══════════════════════════════════════════════════════════
//  ИНЖЕКТ КНОПКИ "СЧЁТ" В ORDER_PRO
//  MutationObserver — не setInterval, нет утечек памяти
// ══════════════════════════════════════════════════════════
(function initOrderInjector() {
    function inject() {
        document.querySelectorAll('[data-order-id]:not([data-inv-injected])').forEach(el => {
            el.dataset.invInjected = '1';
            const ordId  = el.dataset.orderId    || '';
            const cName  = el.dataset.clientName || el.dataset.client || '';
            const cPhone = el.dataset.phone       || '';
            const btn    = document.createElement('button');
            btn.className = 'ip-btn ip-btn-s ip-btn-xs';
            btn.innerHTML = ico('invoice',11) + ' Счёт';
            btn.style.marginLeft = '5px';
            btn.onclick = e => {
                e.stopPropagation();
                let items = [];
                try {
                    const raw = el.dataset.orderItems
                        || el.closest('[data-order-items]')?.dataset.orderItems;
                    if (raw) items = JSON.parse(raw);
                } catch (_) {}
                window._quickInvoice = {
                    clientName: cName, phone: cPhone,
                    items, order_id: ordId,
                };
                window._ipClientId = el.dataset.clientId || null;
                if (window.CRM) CRM.showModulePage?.('invoice_pro');
                setTimeout(() => renderForm(null), 150);
            };
            el.parentElement?.appendChild(btn);
        });
    }
    const obs = new MutationObserver(inject);
    obs.observe(document.body, {childList: true, subtree: true});
    window._ipOrderObserver = obs;
    inject(); // первичный прогон
}());

// ══════════════════════════════════════════════════════════
//  ПУБЛИЧНЫЙ API window.IP
// ══════════════════════════════════════════════════════════
window.IP = {
    // Навигация
    back()      { renderDash(); },
    newInvoice(){ renderForm(null); },
    view(id)    { renderView(id); },
    edit(id)    { renderForm(id); },
    analytics() { renderAnalytics(); },
    settings()  { renderSettings(); },
    client360(phone, name) { renderClient360(phone, name); },

    // Действия со счётом
    del(id) {
        if (!confirm('Удалить счёт? Это необратимо.')) return;
        api('delete', {id}).then(r => {
            if (r?.ok) { notify('Счёт удалён', 'info'); renderDash(); }
            else notify(r?.error || 'Ошибка удаления', 'error');
        });
    },
    setStatus(id, st) {
        api('status', {id, status: st}).then(r => {
            if (r?.ok) { notify('✅ Статус обновлён', 'success'); renderView(id); }
            else notify(r?.error || 'Ошибка', 'error');
        });
    },
    dup(id) {
        if (!confirm('Дублировать счёт?')) return;
        api('duplicate', {id}).then(r => {
            if (r?.ok) { notify('✅ Счёт продублирован', 'success'); renderView(r.data.id); }
            else notify(r?.error || 'Ошибка дублирования', 'error');
        });
    },
    createClosing(invId, type) {
        api('create_closing_doc', {invoice_id: invId, doc_type: type}).then(r => {
            if (r?.ok) {
                notify('✅ Документ создан', 'success');
                if (r.url) window.open(r.url, '_blank');
                loadClosingDocs(invId);
            } else notify(r?.error || 'Ошибка', 'error');
        });
    },

    // Фильтрация и вид
    filter() {
        const q  = (gv('ipSrch') || '').toLowerCase();
        const st = gv('ipStFilter') || '';
        S.filtered = S.invoices.filter(i =>
            (!q
                || (i.client_name  || '').toLowerCase().includes(q)
                || (i.number       || '').toLowerCase().includes(q)
                || (i.client_phone || '').toLowerCase().includes(q)
                || (i.client_email || '').toLowerCase().includes(q))
            && (!st || i.status === st)
        );
        if (S.view === 'kanban') renderKanban(S.filtered);
        else renderTable(S.filtered);
    },
    setView(v) {
        S.view = v;
        const kb=document.getElementById('ipKanban');
        const tb=document.getElementById('ipTable');
        const bk=document.getElementById('ipBtnKb');
        const bt=document.getElementById('ipBtnTb');
        if (v==='kanban') {
            if (kb) kb.style.display='';
            if (tb) tb.style.display='none';
            if (bk) bk.className='ip-btn ip-btn-p ip-btn-xs';
            if (bt) bt.className='ip-btn ip-btn-s ip-btn-xs';
            renderKanban(S.filtered);
        } else {
            if (kb) kb.style.display='none';
            if (tb) tb.style.display='';
            if (bk) bk.className='ip-btn ip-btn-s ip-btn-xs';
            if (bt) bt.className='ip-btn ip-btn-p ip-btn-xs';
            renderTable(S.filtered);
        }
    },

    // Пагинация
    _goPage(page) {
        S.page_offset = page * S.page_limit;
        renderDash();
    },

    // Форма
    doSave:   (editId, status) => doSave(editId, status),
    recalc:   () => recalc(),
    addRow:   () => addItemRow(null),
    _renumRows() {
        document.querySelectorAll('#ipItemsBody tr').forEach((tr, i) => {
            const n = tr.querySelector('.ip-row-num'); if (n) n.textContent = i+1;
        });
    },
    _onDueSel: sel => _onDueSel(sel),

    // Клиент
    _debouncedClientSearch,
    _debouncedDupeCheck,
    _fillFromDupe: id => _fillFromDupe(id),
    dadataFill:    () => dadataFill(),
    _refreshBuyer: (invId, cid) => _refreshBuyer(invId, cid),
    _addComment:   invId => _addComment(invId),

    // Шаблоны позиций
    _loadTemplate: id => _loadTemplate(id),
    _saveTpl:      () => _saveTpl(),

    // PDF, Email
    pdfMenu:  (id, btn) => pdfMenu(id, btn),
    openPdf:  (id, type)=> openPdf(id, type),
    emailModal:(id, e)  => emailModal(id, e),
    _doEmail: id => doEmail(id),

    // Настройки
    doSaveSettings: () => doSaveSettings(),
    uploadImg:  (input, field) => uploadImg(input, field),
    _clearImg:  field => _clearImg(field),

    // Аналитика
    _loadAnal() {
        const m = gv('analMon') || new Date().toISOString().slice(0,7);
        loadAnalytics(m);
    },

    // Клиент 360°
    _c360tab: (tab, phone, name) => _c360tab(tab, phone, name),
    newForClient(phone, name) {
        window._quickInvoice = {clientName: name, phone};
        renderForm(null);
    },
    newFromOrder(phone, name, orderId) {
        CRM.api('clients_pro','list',{search: phone||name}).then(r=>{
            const c=(r?.data||[]).find(x=>x.phone===phone||x.name===name);
            window._quickInvoice = {clientName: name, phone, order_id: orderId};
            if (c) window._ipClientId = String(c.id||'');
            renderForm(null);
        });
    },
};

// ── РЕГИСТРАЦИЯ МОДУЛЯ ────────────────────────────────────
if (window.CRM?.registerModule) {
    CRM.registerModule({
        id:          'invoice_pro',
        name:        'Счета PRO',
        icon:        '📄',
        description: 'Счета · Акты · УПД · QR · Клиент 360° · Шаблоны',
        version:     '6.0',
        color:       '#0ea5e9',
        render:      () => renderDash(),
    });
}

})();
</script>