<?php
/**
 * @name        Клиенты PRO
 * @icon        👥
 * @description CRM клиентов — канбан, история, ВК, Артемий, заказы, документы
 * @version     3.1
 * @sidebar     true
 * @color       #06b6d4
 */

// ============================================================
//  ИНИЦИАЛИЗАЦИЯ СТРУКТУРЫ
// ============================================================
if (!isset($moduleDB['clients_pro'])) {
    $moduleDB['clients_pro'] = [
        'settings' => [
            'enabled'        => true,
            'dadata_api_key' => '',
            'dadata_secret'  => '',
        ],
    ];
    writeDB($moduleDB);
}

// ============================================================
//  КОНСТАНТЫ (Безопасное получение)
// ============================================================
$settings = &$moduleDB['clients_pro']['settings'];

// Если ключи не заданы в базе, они останутся пустыми строками, а не захардкодятся.
define('CP_VK_URL',       'https://srm.itmag.site/bot/vk.php');
define('CP_VK_KEY',       'vk2025notify');
define('CP_ARTEMIY_URL',  'https://srm.itmag.site/bot/artemiy.php');
define('CP_ARTEMIY_KEY',  'artemiy2025notify');
define('CP_DADATA_URL',   'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');
define('CP_DADATA_KEY',   $settings['dadata_api_key'] ?? '');
define('CP_DADATA_SECRET', $settings['dadata_secret'] ?? '');

// ============================================================
//  ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function cp_http(string $url, array $params = [], string $body = ''): array {
    $ch = curl_init($url . ($params ? '?' . http_build_query($params) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => !!$body,
        CURLOPT_POSTFIELDS     => $body ?: null,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?? [];
}

function cp_normalize_phone(string $phone): string {
    $raw = preg_replace('/\D/', '', $phone);
    if (strlen($raw) === 10) $raw = '7' . $raw;
    if (strlen($raw) === 11 && $raw[0] === '8') $raw = '7' . substr($raw, 1);
    if (strlen($raw) === 11 && $raw[0] === '7')  return '+' . $raw;
    return $phone;
}

function cp_get_vk_profile(string $phone): ?array {
    $links     = cp_http(CP_VK_URL, ['key' => CP_VK_KEY, 'action' => 'get_links']);
    $linksData = $links['data'] ?? [];
    $normPhone = cp_normalize_phone($phone);
    $vkUserId  = null;

    foreach ($linksData as $lPhone => $link) {
        if (cp_normalize_phone($lPhone) === $normPhone) {
            $vkUserId = (int)($link['user_id'] ?? 0);
            break;
        }
    }
    if (!$vkUserId) return null;

    $res     = cp_http(CP_VK_URL,
        ['key' => CP_VK_KEY, 'action' => 'get_vk_user'],
        json_encode(['data' => ['user_id' => $vkUserId]])
    );
    $profile = $res['data'] ?? null;
    if ($profile) $profile['vk_user_id'] = $vkUserId;
    return $profile;
}

function cp_vk_lookup(string $vkUrl): ?array {
    $res = cp_http(CP_VK_URL,
        ['key' => CP_VK_KEY, 'action' => 'vk_lookup'],
        json_encode(['data' => ['url' => $vkUrl]])
    );
    return ($res['ok'] ?? false) ? ($res['user'] ?? null) : null;
}

function cp_get_vk_chat(int $vkUserId): array {
    $res = cp_http(CP_VK_URL,
        ['key' => CP_VK_KEY, 'action' => 'get_messages'],
        json_encode(['data' => ['user_id' => $vkUserId]])
    );
    return $res['messages'] ?? [];
}

function cp_find_vk_id(string $phone): int {
    $links = cp_http(CP_VK_URL, ['key' => CP_VK_KEY, 'action' => 'get_links']);
    $norm  = cp_normalize_phone($phone);
    foreach ($links['data'] ?? [] as $lPhone => $link) {
        if (cp_normalize_phone($lPhone) === $norm)
            return (int)($link['user_id'] ?? 0);
    }
    return 0;
}

function cp_get_artemiy_history(string $phone): array {
    $res = cp_http(CP_ARTEMIY_URL,
        ['key' => CP_ARTEMIY_KEY, 'action' => 'get_history'],
        json_encode(['data' => ['phone' => $phone]])
    );
    // Поддерживаем разные форматы ответа
    if (!empty($res['data']))    return is_array($res['data'])    ? $res['data']    : [];
    if (!empty($res['history'])) return is_array($res['history']) ? $res['history'] : [];
    if (!empty($res['items']))   return is_array($res['items'])   ? $res['items']   : [];
    if (isset($res[0])) return $res;
    return [];
}

function cp_dadata_find(string $query): ?array {
    $ch = curl_init(CP_DADATA_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Token ' . CP_DADATA_KEY,
            'X-Secret: ' . CP_DADATA_SECRET,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query, 'count' => 1]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $json = json_decode($res, true);
    return $json['suggestions'][0] ?? null;
}

function cp_get_client_orders(array $db, string $phone, string $name): array {
    $norm   = cp_normalize_phone($phone);
    $cName  = mb_strtolower(trim($name));
    $result = [];
    
    // Оптимизация: не перебираем всю базу, если нет параметров
    if (!$norm && !$cName) return $result;

    foreach ($db['orders'] ?? [] as $o) {
        $oPhone = cp_normalize_phone($o['phone'] ?? '');
        $oName  = mb_strtolower(trim($o['client'] ?? ''));
        
        // Быстрая проверка: совпадение по телефону ИЛИ по имени
        if (($norm && $oPhone === $norm) || ($cName && $oName === $cName)) {
            $result[] = $o;
        }
    }
    usort($result, fn($a,$b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    return $result;
}

function cp_calc_metrics(array $orders): array {
    $total = 0; $debt = 0; $count = count($orders);
    foreach ($orders as $o) {
        $t = (float)($o['total'] ?? 0);
        $p = (float)($o['paid'] ?? $o['prepay'] ?? 0);
        $total += $t;
        $debt  += max(0, $t - $p);
    }
    return [
        'orders' => $count,
        'total'  => $total,
        'debt'   => $debt,
        'avg'    => $count > 0 ? round($total / $count) : 0,
    ];
}

// ============================================================
//  РОУТИНГ
// ============================================================
$opSettings = $moduleDB['clients_pro']['settings'] ?? [];
if (!($opSettings['enabled'] ?? true) &&
    !in_array($moduleAction, ['get_settings','save_settings'])) {
    echo json_encode(['ok' => false, 'error' => 'Модуль отключён']);
    exit;
}

switch ($moduleAction) {

   case 'list':
    $clients = $moduleDB['clients'] ?? [];
    $search  = mb_strtolower($moduleParams['search'] ?? $moduleBody['search'] ?? '');
    $tag     = $moduleParams['tag']    ?? $moduleBody['tag']    ?? '';
    $status  = $moduleParams['status'] ?? $moduleBody['status'] ?? '';

    // ── Фильтрация ──────────────────────────────────────────
    if ($search) {
        $clients = array_values(array_filter($clients, function($c) use ($search) {
            return mb_strpos(mb_strtolower($c['name']  ?? ''), $search) !== false ||
                   mb_strpos(mb_strtolower($c['phone'] ?? ''), $search) !== false ||
                   mb_strpos(mb_strtolower($c['email'] ?? ''), $search) !== false;
        }));
    }
    if ($tag) {
        $clients = array_values(array_filter($clients,
            fn($c) => in_array($tag, $c['tags'] ?? [])
        ));
    }
    if ($status) {
        $clients = array_values(array_filter($clients,
            fn($c) => ($c['crm_status'] ?? 'new') === $status
        ));
    }

    // ── Метрики (заказы) ────────────────────────────────────
    foreach ($clients as &$c) {
        $orders           = cp_get_client_orders($moduleDB, $c['phone'] ?? '', $c['name'] ?? '');
        $c['_metrics']    = cp_calc_metrics($orders);
        $c['_last_order'] = $orders[0] ?? null;
    }
    unset($c);

    // ── ✅ ПАГИНАЦИЯ (добавлено) ────────────────────────────
    $total = count($clients);
    $limit = (int)($moduleParams['limit'] ?? $moduleBody['limit'] ?? 0);
    $offset = (int)($moduleParams['offset'] ?? $moduleBody['offset'] ?? 0);

    if ($limit > 0) {
        $clients = array_slice($clients, $offset, $limit);
    }

    echo json_encode([
        'ok'    => true,
        'data'  => array_values($clients),
        'total' => $total,
    ]);
    break;

    case 'get':
        $id    = $moduleParams['id'] ?? $moduleBody['id'] ?? null;
        $found = null;
        foreach ($moduleDB['clients'] ?? [] as $c) {
            if ((string)$c['id'] === (string)$id) { $found = $c; break; }
        }
        if (!$found) { echo json_encode(['ok' => false, 'error' => 'Не найден']); break; }

        $orders             = cp_get_client_orders($moduleDB, $found['phone'] ?? '', $found['name'] ?? '');
        $found['_metrics']    = cp_calc_metrics($orders);
        $found['_orders']     = $orders;
        $found['_last_order'] = $orders[0] ?? null;

        echo json_encode(['ok' => true, 'data' => $found]);
        break;

    case 'save':
        $isEdit = !empty($moduleBody['id']);
        $cid    = $isEdit ? $moduleBody['id'] : (int)(microtime(true) * 1000);

        $client = [
            'id'             => $cid,
            'name'           => $moduleBody['name']           ?? '',
            'phone'          => cp_normalize_phone($moduleBody['phone'] ?? ''),
            'email'          => $moduleBody['email']          ?? '',
            'type'           => $moduleBody['type']           ?? 'Физическое лицо',
            'bizcat'         => $moduleBody['bizcat']         ?? 'Частный клиент',
            'address'        => $moduleBody['address']        ?? '',
            'inn'            => $moduleBody['inn']            ?? '',
            'ogrn'           => $moduleBody['ogrn']           ?? '',
            'kpp'            => $moduleBody['kpp']            ?? '',
            'okved'          => $moduleBody['okved']          ?? '',
            'director'       => $moduleBody['director']       ?? '',
            'bank_name'      => $moduleBody['bank_name']      ?? '',
            'bank_acc'       => $moduleBody['bank_acc']       ?? '',
            'bank_bik'       => $moduleBody['bank_bik']       ?? '',
            'bank_ks'        => $moduleBody['bank_ks']        ?? '',
            'discount'       => intval($moduleBody['discount'] ?? 0),
            'notes'          => $moduleBody['notes']          ?? '',
            'tags'           => $moduleBody['tags']           ?? [],
            'crm_status'     => $moduleBody['crm_status']     ?? 'new',
            'avatar_url'     => $moduleBody['avatar_url']     ?? '',
            'vk_user_id'     => $moduleBody['vk_user_id']     ?? null,
            'vk_url'         => $moduleBody['vk_url']         ?? '',
            'vk_avatar'      => $moduleBody['vk_avatar']      ?? '',
            // Расширенные поля из ВК
            'vk_city'        => $moduleBody['vk_city']        ?? '',
            'vk_bdate'       => $moduleBody['vk_bdate']       ?? '',
            'vk_sex'         => $moduleBody['vk_sex']         ?? '',
            'vk_about'       => $moduleBody['vk_about']       ?? '',
            'vk_status'      => $moduleBody['vk_status']      ?? '',
            'vk_followers'   => $moduleBody['vk_followers']   ?? 0,
            'vk_last_seen'   => $moduleBody['vk_last_seen']   ?? '',
            'vk_site'        => $moduleBody['vk_site']        ?? '',
            'vk_relation'    => $moduleBody['vk_relation']    ?? '',
            // Банк
            'dadata_data'    => $moduleBody['dadata_data']    ?? null,
            'createdAt'      => $isEdit
                ? ($moduleBody['createdAt'] ?? date('c'))
                : date('c'),
            'updatedAt'      => date('c'),
        ];

        if (!isset($moduleDB['clients'])) $moduleDB['clients'] = [];

        if ($isEdit) {
            $found = false;
            foreach ($moduleDB['clients'] as &$c) {
                if ((string)$c['id'] === (string)$cid) { $c = $client; $found = true; break; }
            }
            unset($c);
            if (!$found) $moduleDB['clients'][] = $client;
        } else {
            array_unshift($moduleDB['clients'], $client);
        }

        writeDB($moduleDB);
        echo json_encode(['ok' => true, 'data' => $client]);
        break;

    case 'delete':
        $id = $_GET['id'] ?? $moduleBody['id'] ?? null;
        
        // 1. Удаляем клиента
        $moduleDB['clients'] = array_values(array_filter(
            $moduleDB['clients'] ?? [],
            fn($c) => (string)$c['id'] !== (string)$id
        ));
        
        // 2. Удаляем заказы этого клиента (очистка от мусора, чтобы не плодить дубли)
        if (isset($moduleDB['orders']) && is_array($moduleDB['orders'])) {
            $moduleDB['orders'] = array_values(array_filter(
                $moduleDB['orders'],
                fn($o) => (string)($o['client_id'] ?? '') !== (string)$id && 
                         (string)($o['client'] ?? '') !== (string)$id
            ));
        }
        
        writeDB($moduleDB);
        echo json_encode(['ok' => true]);
        break;

    case 'status':
        $id        = $moduleBody['id']     ?? null;
        $newStatus = $moduleBody['status'] ?? null;
        $allowed   = ['new','active','rare','vip','angry','problem','friend','inactive'];
        if (!$id || !in_array($newStatus, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Неверный статус']); break;
        }
        $out = null;
        foreach ($moduleDB['clients'] as &$c) {
            if ((string)$c['id'] === (string)$id) {
                $c['crm_status'] = $newStatus;
                $c['updatedAt']  = date('c');
                $out = $c; break;
            }
        }
        unset($c);
        writeDB($moduleDB);
        echo json_encode(['ok' => true, 'data' => $out]);
        break;

    case 'vk_lookup':
        $vkUrl = $moduleBody['url'] ?? $moduleParams['url'] ?? '';
        if (!$vkUrl) { echo json_encode(['ok' => false, 'error' => 'Нет ссылки']); break; }
        $user = cp_vk_lookup($vkUrl);
        echo json_encode(['ok' => !!$user, 'data' => $user]);
        break;

    case 'vk_profile':
        $phone   = $moduleBody['phone'] ?? $moduleParams['phone'] ?? '';
        $profile = $phone ? cp_get_vk_profile($phone) : null;
        echo json_encode(['ok' => true, 'data' => $profile]);
        break;

    case 'vk_chat':
        $vkId = (int)($moduleBody['vk_user_id'] ?? $moduleParams['vk_user_id'] ?? 0);
        if (!$vkId) {
            $phone = $moduleBody['phone'] ?? $moduleParams['phone'] ?? '';
            $vkId  = $phone ? cp_find_vk_id($phone) : 0;
        }
        $messages = $vkId ? cp_get_vk_chat($vkId) : [];
        echo json_encode(['ok' => true, 'data' => $messages, 'vk_user_id' => $vkId]);
        break;

    case 'artemiy_history':
        $phone   = $moduleBody['phone'] ?? $moduleParams['phone'] ?? '';
        $history = $phone ? cp_get_artemiy_history($phone) : [];
        echo json_encode(['ok' => true, 'data' => $history]);
        break;

    case 'client_orders':
        $phone  = $moduleBody['phone'] ?? $moduleParams['phone'] ?? '';
        $name   = $moduleBody['name']  ?? $moduleParams['name']  ?? '';
        $orders = cp_get_client_orders($moduleDB, $phone, $name);
        echo json_encode(['ok' => true, 'data' => $orders]);
        break;

    case 'dadata_find':
        $query = $moduleBody['query'] ?? $moduleParams['query'] ?? '';
        if (!$query) { echo json_encode(['ok' => false, 'error' => 'Нет запроса']); break; }

        $result = cp_dadata_find($query);
        if (!$result) { echo json_encode(['ok' => false, 'error' => 'Не найдено']); break; }

        $d    = $result['data'] ?? [];
        $name = $d['name']['short_with_opf'] ?? $d['name']['full_with_opf'] ?? $result['value'] ?? '';

        $phone = '';
        if (!empty($d['phones'])) {
            $ph    = $d['phones'][0]['data'] ?? [];
            $parts = array_filter([
                $ph['country_code'] ?? '',
                $ph['city_code'] ?? '',
                $ph['number'] ?? '',
            ]);
            $phone = implode('', $parts);
            if ($phone) $phone = '+' . ltrim($phone, '+');
        }

        $email    = $d['emails'][0]['data']['source'] ?? '';
        $director = $d['management']['name'] ?? '';
        $status    = $d['state']['status'] ?? 'ACTIVE';
        $statusMap = [
            'ACTIVE'       => 'Действующая',
            'LIQUIDATING'  => 'Ликвидируется',
            'LIQUIDATED'   => 'Ликвидирована',
            'BANKRUPT'     => 'Банкротство',
            'REORGANIZING' => 'Реорганизация',
        ];

        echo json_encode([
            'ok'   => true,
            'data' => [
                'name'         => $name,
                'full_name'    => $d['name']['full_with_opf'] ?? $name,
                'inn'          => $d['inn']   ?? '',
                'kpp'          => $d['kpp']   ?? '',
                'ogrn'         => $d['ogrn']  ?? '',
                'okved'        => $d['okved'] ?? '',
                'opf'          => $d['opf']['short'] ?? '',
                'address'      => $d['address']['value'] ?? '',
                'phone'        => $phone,
                'email'        => $email,
                'director'     => $director,
                'status'       => $statusMap[$status] ?? $status,
                'status_raw'   => $status,
                'reg_date'     => !empty($d['state']['registration_date'])
                    ? date('d.m.Y', $d['state']['registration_date'] / 1000)
                    : '',
                'employee_count' => $d['employee_count'] ?? null,
                'finance'      => [
                    'income'  => $d['finance']['income']  ?? null,
                    'revenue' => $d['finance']['revenue'] ?? null,
                    'expense' => $d['finance']['expense'] ?? null,
                    'year'    => $d['finance']['year']    ?? null,
                ],
                'branch_count' => $d['branch_count'] ?? 0,
                'type'         => $d['type'] === 'INDIVIDUAL' ? 'ИП' : 'ООО / ЗАО',
                'raw'          => $d,
            ],
        ]);
        break;

    case 'add_note':
        $cid  = $moduleBody['client_id'] ?? null;
        $text = $moduleBody['text']      ?? '';
        if (!$cid || !$text) { echo json_encode(['ok' => false, 'error' => 'Нет данных']); break; }
        foreach ($moduleDB['clients'] as &$c) {
            if ((string)$c['id'] === (string)$cid) {
                if (!isset($c['timeline'])) $c['timeline'] = [];
                $c['timeline'][] = [
                    'id'   => (int)(microtime(true)*1000),
                    'type' => 'note',
                    'text' => $text,
                    'date' => date('c'),
                    'user' => $moduleBody['user'] ?? 'Менеджер',
                ];
                break;
            }
        }
        unset($c);
        writeDB($moduleDB);
        echo json_encode(['ok' => true]);
        break;

    case 'get_settings':
        echo json_encode(['ok' => true, 'data' => $moduleDB['clients_pro']['settings'] ?? []]);
        break;

    case 'save_settings':
        foreach (['enabled','dadata_api_key','dadata_secret'] as $k) {
            if (array_key_exists($k, $moduleBody))
                $moduleDB['clients_pro']['settings'][$k] = $moduleBody[$k];
        }
        writeDB($moduleDB);
        echo json_encode(['ok' => true]);
        break;

    case 'send_message':
        $phone = $moduleBody['phone'] ?? '';
        $text  = $moduleBody['text']  ?? '';
        if (!$phone || !$text) { echo json_encode(['ok' => false, 'error' => 'Нет данных']); break; }
        $res = cp_http(CP_VK_URL,
            ['key' => CP_VK_KEY, 'action' => 'send_to_client'],
            json_encode(['data' => ['phone' => $phone, 'text' => $text]])
        );
        echo json_encode(['ok' => $res['ok'] ?? false, 'error' => $res['error'] ?? '']);
        break;

    case 'send_file':
        $phone    = $moduleBody['phone']     ?? '';
        $text     = $moduleBody['text']      ?? '';
        $fileName = $moduleBody['file_name'] ?? '';
        if (!$phone || !$fileName) { echo json_encode(['ok' => false, 'error' => 'Нет данных']); break; }
        $res = cp_http(CP_VK_URL,
            ['key' => CP_VK_KEY, 'action' => 'send_message_with_file'],
            json_encode(['data' => ['phone' => $phone, 'text' => $text, 'file_name' => $fileName]])
        );
        echo json_encode(['ok' => $res['ok'] ?? false, 'error' => $res['error'] ?? '']);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Неизвестное действие: ' . $moduleAction]);
}
?>
<!--MODULE_JS_START-->
<script>
/* ============================================================
   CLIENTS PRO MODULE v3.1
   Карточки · Канбан · ВК · Артемий · История · Заказы · DaData
   FIX: Удаление клиента, обработка ошибок, ограничение списка
============================================================ */

window._cp = function(method) {
    var args = Array.prototype.slice.call(arguments, 1);
    var mod  = window.CRM && window.CRM.modules && window.CRM.modules['clients_pro'];
    if (!mod) { console.warn('clients_pro not ready'); return; }
    if (typeof mod[method] !== 'function') { console.warn('clients_pro.' + method + ' not found'); return; }
    return mod[method].apply(mod, args);
};

CRM.registerModule({
    id:    'clients_pro',
    name:  'Клиенты PRO',
    icon:  '👥',
    color: '#06b6d4',

    _clients:  [],
    _view:     'cards',
    _editId:   null,
    _detailId: null,
    _settings: { enabled: true, dadata_api_key: '', dadata_secret: '' },

    CRM_STATUSES: ['new','active','rare','vip','angry','problem','friend','inactive'],
    CRM_STATUS_LABELS: {
        new:'Новый', active:'Постоянный', rare:'Редкий', vip:'VIP',
        angry:'Сложный', problem:'Проблемный', friend:'Свой', inactive:'Неактивный',
    },
    CRM_STATUS_COLORS: {
        new:      { bg:'rgba(99,102,241,0.2)',   border:'#6366f1', text:'#a5b4fc' },
        active:   { bg:'rgba(16,185,129,0.2)',   border:'#10b981', text:'#34d399' },
        rare:     { bg:'rgba(6,182,212,0.2)',    border:'#06b6d4', text:'#22d3ee' },
        vip:      { bg:'rgba(245,158,11,0.25)',  border:'#f59e0b', text:'#fbbf24' },
        angry:    { bg:'rgba(239,68,68,0.2)',    border:'#ef4444', text:'#f87171' },
        problem:  { bg:'rgba(239,68,68,0.15)',   border:'#dc2626', text:'#fca5a5' },
        friend:   { bg:'rgba(168,85,247,0.2)',   border:'#a855f7', text:'#d8b4fe' },
        inactive: { bg:'rgba(100,116,139,0.15)', border:'#475569', text:'#94a3b8' },
    },
    TAGS: ['VIP','Корпорат','ИП','Частник','Срочник','Должник','Постоянный','Новый','Заблокирован'],

    // Шаблоны быстрых сообщений
    MSG_TEMPLATES: [
        { label: 'Заказ готов',    text: 'Здравствуйте! Ваш заказ готов и ожидает вас. Можете забрать в любое удобное время.' },
        { label: 'Подтверждение',  text: 'Здравствуйте! Ваш заказ принят в работу. Срок изготовления уточним дополнительно.' },
        { label: 'Напоминание',    text: 'Здравствуйте! Напоминаем, что ваш заказ ожидает получения уже несколько дней.' },
        { label: 'Оплата',         text: 'Здравствуйте! Просьба оплатить задолженность по заказу. Спасибо за понимание.' },
        { label: 'Акция',          text: 'Здравствуйте! У нас действует специальное предложение для постоянных клиентов. Подробности по запросу.' },
    ],

    // ── SVG ИКОНКИ ──────────────────────────────────────────
    _icon: function(name, size) {
        size = size || 16;
        var icons = {
            phone:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 3.09 4.18 2 2 0 0 1 5.08 2h3a2 2 0 0 1 2 1.72c.12.96.36 1.9.71 2.81a2 2 0 0 1-.45 2.11L9.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.71A2 2 0 0 1 22 16.92z"/></svg>',
            mail:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            user:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            order:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="18" rx="2"/><polyline points="8 10 12 14 16 10"/></svg>',
            money:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            edit:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            trash:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
            send:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
            eye:     '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
            copy:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
            vk:      '<svg width="'+size+'" height="'+size+'" viewBox="0 0 24 24" fill="currentColor"><path d="M21.547 7h-3.29a.743.743 0 0 0-.655.392s-1.312 2.416-1.734 3.23C14.734 12.813 14 12.126 14 11.11V7.603A1.104 1.104 0 0 0 12.896 6.5h-2.474a1.982 1.982 0 0 0-1.75.813s1.255-.204 1.255 1.49c0 .42.022 1.626.04 2.64a.73.73 0 0 1-1.272.503 21.54 21.54 0 0 1-2.498-4.543.693.693 0 0 0-.63-.403h-2.99a.508.508 0 0 0-.48.685C3.005 10.175 6.918 18 11.38 18h1.878a.742.742 0 0 0 .742-.742v-1.135a.73.73 0 0 1 1.23-.53l2.247 2.112a1.09 1.09 0 0 0 .746.295h2.953c1.424 0 1.424-.988.647-1.753-.546-.538-2.518-2.617-2.518-2.617a1.02 1.02 0 0 1-.078-1.323c.637-.84 1.68-2.212 2.122-2.8.603-.804 1.697-2.507.197-2.507z"/></svg>',
            chat:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            tag:     '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            plus:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
            refresh: '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
            kanban:  '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="4" height="18" rx="1"/><rect x="10" y="3" width="4" height="11" rx="1"/><rect x="17" y="3" width="4" height="15" rx="1"/></svg>',
            grid:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
            link:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
            history: '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>',
            search:  '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            upload:  '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>',
            settings:'<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            building:'<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M3 9h6"/><path d="M3 15h6"/><path d="M15 9h.01"/><path d="M15 15h.01"/></svg>',
            file:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>',
            image:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            spinner: '<svg width="'+size+'" height="'+size+'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" stroke-linecap="round"/></svg>',
            pin:     '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
            check:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
            bell:    '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            robot:   '<svg width="'+size+'" height="'+size+'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="15" x2="8" y2="17"/><line x1="16" y1="15" x2="16" y2="17"/></svg>',
        };
        return icons[name] || '';
    },

    // ── HTML СТРАНИЦЫ ────────────────────────────────────────
    page: `
    <div class="page-header">
      <div>
        <div class="page-title">База клиентов PRO</div>
        <div class="page-subtitle">CRM · Канбан · ВКонтакте · DaData</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div class="search-bar" style="min-width:200px;">
          <span id="cp_search_icon"></span>
          <input type="text" placeholder="Поиск клиента..."
                 id="cp_search" oninput="_cp('_filterAndRender')">
        </div>
        <select class="form-select" style="width:140px;" id="cp_tag_filter"
                onchange="_cp('_filterAndRender')">
          <option value="">Все теги</option>
        </select>
        <div style="display:flex;gap:4px;background:var(--bg-dark);
                    border-radius:10px;padding:4px;border:1px solid var(--border);">
          <button id="cp_btn_cards"  class="btn btn-primary btn-xs"
                  onclick="_cp('_setView','cards')"  style="min-width:34px;" title="Карточки"></button>
          <button id="cp_btn_kanban" class="btn btn-secondary btn-xs"
                  onclick="_cp('_setView','kanban')" style="min-width:34px;" title="Канбан"></button>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="_cp('showSettings')">
          <span id="cp_settings_icon"></span>
        </button>
        <button class="btn btn-primary btn-sm" onclick="_cp('openClientModal')">
          <span id="cp_plus_icon"></span> Добавить
        </button>
      </div>
    </div>

    <div class="kanban-stats-bar" id="cp_stats_bar">
      <div class="kanban-stat-pill">
        <span class="kanban-stat-dot" style="background:#06b6d4;"></span>
        Всего: <span id="cp_cnt_total"  style="font-weight:700;margin-left:4px;">0</span>
      </div>
      <div class="kanban-stat-pill">
        <span class="kanban-stat-dot" style="background:#10b981;"></span>
        Активных: <span id="cp_cnt_active" style="font-weight:700;margin-left:4px;">0</span>
      </div>
      <div class="kanban-stat-pill">
        <span class="kanban-stat-dot" style="background:#f59e0b;"></span>
        VIP: <span id="cp_cnt_vip" style="font-weight:700;margin-left:4px;">0</span>
      </div>
      <div class="kanban-stat-pill" style="margin-left:auto;color:var(--danger);">
        Долг: <span id="cp_total_debt" style="font-weight:700;margin-left:4px;">0 ₽</span>
      </div>
    </div>

    <div id="cp_cards_view">
      <div id="cp_cards_grid"
           style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
      </div>
    </div>

    <div id="cp_kanban_view" style="display:none;">
      <div class="kanban-board" id="cp_kanban_board"
           style="grid-template-columns:repeat(4,1fr);"></div>
    </div>

    <style>
      /* ── FIX: Чёткие фото на карточках ── */
      .cp-card-photo {
        width: 100%;
        height: 200px;
        object-fit: cover;
        object-position: top center;
        display: block;
        image-rendering: auto;
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
        transform: translateZ(0);
      }
      .cp-kanban-thumb {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        object-fit: cover;
        object-position: top center;
        display: block;
        image-rendering: auto;
        flex-shrink: 0;
      }
      .cp-detail-cover {
        width: 100%;
        height: 130px;
        object-fit: cover;
        object-position: center 30%;
        display: block;
        image-rendering: auto;
        border-radius: 20px 20px 0 0;
      }
      .cp-detail-avatar {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: top center;
        display: block;
        image-rendering: auto;
      }
      .cp-media-thumb {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        display: block;
        image-rendering: auto;
        border-radius: 8px;
        transition: transform 0.2s;
      }
      .cp-media-thumb:hover { transform: scale(1.04); }
      @keyframes cp-spin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
      }
      .cp-spinning { animation: cp-spin 1s linear infinite; }
    </style>
    `,

    // ── ИНИЦИАЛИЗАЦИЯ ────────────────────────────────────────
    render: function() {
        var self = this;
        this._initIcons();
        return Promise.all([
            CRM.api('clients_pro', 'list', { limit: 200 }), // Ограничиваем вывод, чтобы не завис браузер
            CRM.api('clients_pro', 'get_settings'),
        ]).then(function(res) {
            self._clients  = (res[0] && res[0].data) ? res[0].data : [];
            self._settings = (res[1] && res[1].data) ? res[1].data : self._settings;
            self._initTagFilter();
            self._filterAndRender();
        }).catch(function(err) {
            notify('Ошибка загрузки списка клиентов', 'error');
            console.error(err);
        });
    },

    _initIcons: function() {
        var ic = this._icon.bind(this);
        var el = function(id, html) { var e = document.getElementById(id); if (e) e.innerHTML = html; };
        el('cp_search_icon',   ic('search',   14));
        el('cp_settings_icon', ic('settings', 14));
        el('cp_plus_icon',     ic('plus',     14));
        el('cp_btn_cards',     ic('grid',     14));
        el('cp_btn_kanban',    ic('kanban',   14));
    },

    _setView: function(view) {
        this._view = view;
        var cards  = document.getElementById('cp_cards_view');
        var kanban = document.getElementById('cp_kanban_view');
        var btnC   = document.getElementById('cp_btn_cards');
        var btnK   = document.getElementById('cp_btn_kanban');
        if (view === 'cards') {
            if (cards)  cards.style.display  = 'block';
            if (kanban) kanban.style.display = 'none';
            if (btnC)   btnC.className = 'btn btn-primary btn-xs';
            if (btnK)   btnK.className = 'btn btn-secondary btn-xs';
        } else {
            if (cards)  cards.style.display  = 'none';
            if (kanban) kanban.style.display = 'block';
            if (btnC)   btnC.className = 'btn btn-secondary btn-xs';
            if (btnK)   btnK.className = 'btn btn-primary btn-xs';
            this._renderKanban(this._filtered || this._clients);
        }
        if (btnC) btnC.innerHTML = this._icon('grid',   14);
        if (btnK) btnK.innerHTML = this._icon('kanban', 14);
    },

    _initTagFilter: function() {
        var allTags = {};
        this._clients.forEach(function(c) {
            (c.tags || []).forEach(function(t) { allTags[t] = true; });
        });
        var sel = document.getElementById('cp_tag_filter');
        if (!sel) return;
        sel.innerHTML = '<option value="">Все теги</option>' +
            Object.keys(allTags).map(function(t) {
                return '<option value="' + _esc(t) + '">' + _esc(t) + '</option>';
            }).join('');
    },

    _filterAndRender: function() {
        var search = (document.getElementById('cp_search') ? document.getElementById('cp_search').value : '').toLowerCase();
        var tag    = document.getElementById('cp_tag_filter') ? document.getElementById('cp_tag_filter').value : '';
        var filtered = this._clients.filter(function(c) {
            var ms = !search ||
                (c.name  || '').toLowerCase().includes(search) ||
                (c.phone || '').toLowerCase().includes(search) ||
                (c.email || '').toLowerCase().includes(search);
            var mt = !tag || (c.tags || []).includes(tag);
            return ms && mt;
        });
        this._filtered = filtered;
        this._updateStats(filtered);
        if (this._view === 'cards') this._renderCards(filtered);
        else this._renderKanban(filtered);
    },

    _updateStats: function(clients) {
        var total  = clients.length;
        var active = clients.filter(function(c) { return c.crm_status === 'active'; }).length;
        var vip    = clients.filter(function(c) { return c.crm_status === 'vip'; }).length;
        var debt   = clients.reduce(function(s, c) {
            return s + ((c._metrics && c._metrics.debt) ? c._metrics.debt : 0);
        }, 0);
        var cur = CRM.getSettings().currency || '₽';
        var set = function(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; };
        set('cp_cnt_total',  total);
        set('cp_cnt_active', active);
        set('cp_cnt_vip',    vip);
        set('cp_total_debt', debt.toLocaleString('ru-RU') + ' ' + cur);
    },

    // ── КАРТОЧКИ ────────────────────────────────────────────
    _renderCards: function(clients) {
        var self = this;
        var grid = document.getElementById('cp_cards_grid');
        if (!grid) return;
        if (!clients.length) {
            grid.innerHTML =
                '<div class="empty-state card" style="grid-column:1/-1;">' +
                '<div class="icon">' + this._icon('user', 40) + '</div>' +
                '<div class="title">Клиентов не найдено</div>' +
                '<div class="desc">Добавьте первого клиента</div>' +
                '</div>';
            return;
        }
        grid.innerHTML = '';
        // Ограничиваем рендеринг, чтобы не ломать браузер при 1000+ карточках
        var renderList = clients.slice(0, 150);
        renderList.forEach(function(c) { grid.appendChild(self._buildCard(c)); });
        
        // Если клиентов больше 150, выводим заглушку
        if (clients.length > 150) {
            var msg = document.createElement('div');
            msg.style.cssText = 'grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);font-size:0.85rem;';
            msg.textContent = 'Показаны первые 150 записей. Используйте поиск для точной фильтрации.';
            grid.appendChild(msg);
        }
    },

    _buildCard: function(client) {
        var self   = this;
        var st     = client.crm_status || 'new';
        var stConf = this.CRM_STATUS_COLORS[st] || this.CRM_STATUS_COLORS.new;
        var stLbl  = this.CRM_STATUS_LABELS[st]  || st;
        var m      = client._metrics || { orders:0, total:0, debt:0, avg:0 };
        var cur    = CRM.getSettings().currency || '₽';
        var cid    = String(client.id);
        // Приоритет: vk_avatar (большое фото) → avatar_url → градиент
        var av     = client.vk_avatar || client.avatar_url || '';

        // Шапка карточки — фото или градиент
        var headerHtml = '';
        var statusBadge =
            '<div style="position:absolute;top:10px;right:10px;padding:4px 10px;' +
            'border-radius:20px;font-size:0.68rem;font-weight:700;backdrop-filter:blur(8px);' +
            'background:' + stConf.bg + ';border:1px solid ' + stConf.border + ';color:' + stConf.text + ';">' +
            '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;' +
            'background:' + stConf.border + ';margin-right:5px;vertical-align:middle;"></span>' +
            stLbl + '</div>';

        if (av) {
            headerHtml =
                '<div style="position:relative;width:100%;height:200px;overflow:hidden;border-radius:12px 12px 0 0;">' +
                '<img src="' + av + '" alt="' + _esc(client.name) + '" class="cp-card-photo"' +
                ' loading="lazy" decoding="async">' +
                statusBadge +
                (client.vk_user_id
                    ? '<div style="position:absolute;top:10px;left:10px;background:rgba(0,119,255,0.85);' +
                      'backdrop-filter:blur(4px);border-radius:8px;padding:3px 8px;display:flex;' +
                      'align-items:center;gap:4px;color:#fff;font-size:0.65rem;font-weight:700;">' +
                      this._icon('vk', 10) + ' ВКонтакте</div>'
                    : '') +
                '</div>';
        } else {
            var letter = (client.name || '?').charAt(0).toUpperCase();
            var grad   = this._nameGradient(client.name);
            headerHtml =
                '<div style="position:relative;width:100%;height:200px;overflow:hidden;' +
                'border-radius:12px 12px 0 0;background:' + grad + ';' +
                'display:flex;align-items:center;justify-content:center;">' +
                '<span style="font-size:5rem;font-weight:900;color:rgba(255,255,255,0.9);' +
                'text-shadow:0 2px 20px rgba(0,0,0,0.3);">' + letter + '</span>' +
                statusBadge +
                '</div>';
        }

        var tagsHtml = (client.tags || []).slice(0, 3).map(function(tag) {
            return '<span style="background:rgba(124,58,237,0.15);color:var(--accent);' +
                   'border:1px solid rgba(124,58,237,0.25);font-size:0.62rem;font-weight:600;' +
                   'padding:2px 8px;border-radius:20px;">' + _esc(tag) + '</span>';
        }).join('');

        var metricBlock = function(label, value, color) {
            return '<div style="text-align:center;flex:1;">' +
                '<div style="font-size:0.6rem;color:var(--text-muted);text-transform:uppercase;' +
                'letter-spacing:0.5px;margin-bottom:2px;">' + label + '</div>' +
                '<div style="font-size:1rem;font-weight:800;color:' + (color || 'var(--text)') + ';">' +
                value + '</div></div>';
        };

        var div = document.createElement('div');
        div.style.cssText =
            'background:var(--bg-card);border:1px solid var(--border);border-radius:14px;' +
            'overflow:hidden;transition:all 0.25s;cursor:pointer;box-shadow:0 2px 12px rgba(0,0,0,0.15);';

        div.addEventListener('mouseenter', function() {
            div.style.transform   = 'translateY(-4px)';
            div.style.boxShadow   = '0 12px 40px rgba(0,0,0,0.3)';
            div.style.borderColor = 'rgba(6,182,212,0.4)';
        });
        div.addEventListener('mouseleave', function() {
            div.style.transform   = '';
            div.style.boxShadow   = '0 2px 12px rgba(0,0,0,0.15)';
            div.style.borderColor = 'var(--border)';
        });
        div.addEventListener('click', function(e) {
            if (e.target.closest('button,a')) return;
            self.openDetail(client.id);
        });

        div.innerHTML =
            headerHtml +
            '<div style="padding:14px;">' +
            '<div style="font-size:1rem;font-weight:800;margin-bottom:4px;' +
            'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
            _esc(client.name || 'Без имени') + '</div>' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
            '<div style="display:flex;align-items:center;gap:5px;color:var(--text-muted);font-size:0.78rem;">' +
            this._icon('phone', 12) + ' ' + _esc(client.phone || '—') + '</div>' +
            '<div style="font-size:0.65rem;color:var(--text-muted);">' + _esc(client.bizcat || '') + '</div>' +
            '</div>' +
            (tagsHtml ? '<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:10px;">' + tagsHtml + '</div>' : '') +
            '<div style="display:flex;gap:0;padding:8px 0;' +
            'border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:10px;">' +
            metricBlock('ЗАКАЗОВ',   m.orders, 'var(--accent2)') +
            '<div style="width:1px;background:var(--border);"></div>' +
            metricBlock('ПОТРАЧЕНО', m.total ? m.total.toLocaleString('ru-RU') : '0', 'var(--accent3)') +
            '<div style="width:1px;background:var(--border);"></div>' +
            metricBlock('ДОЛГ',
                m.debt > 0 ? m.debt.toLocaleString('ru-RU') : '—',
                m.debt > 0 ? 'var(--danger)' : 'var(--text-muted)') +
            '</div>' +
            '<div style="display:flex;gap:6px;align-items:center;">' +
            (client.phone
                ? '<a href="tel:' + _esc(client.phone) + '" ' +
                  'style="display:flex;align-items:center;justify-content:center;' +
                  'width:32px;height:32px;border-radius:8px;' +
                  'background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);' +
                  'color:var(--accent3);text-decoration:none;" title="Позвонить">' +
                  this._icon('phone', 13) + '</a>'
                : '') +
            '<button style="display:flex;align-items:center;justify-content:center;' +
            'width:32px;height:32px;border-radius:8px;border:1px solid rgba(0,119,255,0.3);' +
            'background:rgba(0,119,255,0.12);color:#6ab4ff;cursor:pointer;" ' +
            'title="Написать" onclick="_cp(\'openMessageModal\',\'' + cid + '\')">' +
            this._icon('chat', 13) + '</button>' +
            (client.phone
                ? '<button style="display:flex;align-items:center;justify-content:center;' +
                  'width:32px;height:32px;border-radius:8px;border:1px solid var(--border);' +
                  'background:var(--bg-dark);color:var(--text-muted);cursor:pointer;" ' +
                  'title="Скопировать телефон" ' +
                  'onclick="navigator.clipboard.writeText(\'' + _esc(client.phone) + '\')' +
                  '.then(function(){notify(\'Телефон скопирован\',\'success\')})">' +
                  this._icon('copy', 13) + '</button>'
                : '') +
            '<button class="btn btn-primary btn-xs" style="margin-left:auto;" ' +
            'onclick="_cp(\'openDetail\',\'' + cid + '\')">' +
            this._icon('eye', 12) + ' Открыть</button>' +
            '</div>' +
            '</div>';

        return div;
    },

    // ── КАНБАН ──────────────────────────────────────────────
    _renderKanban: function(clients) {
        var self  = this;
        var board = document.getElementById('cp_kanban_board');
        if (!board) return;
        var groups = {};
        this.CRM_STATUSES.forEach(function(s) { groups[s] = []; });
        clients.forEach(function(c) {
            var s = c.crm_status || 'new';
            if (groups[s]) groups[s].push(c);
        });
        board.innerHTML = this.CRM_STATUSES.map(function(st) {
            var conf  = self.CRM_STATUS_COLORS[st];
            var label = self.CRM_STATUS_LABELS[st];
            var items = groups[st] || [];
            var cards = items.length
                ? items.map(function(c) {
                    var m   = c._metrics || { orders:0, total:0 };
                    var av  = c.vk_avatar || c.avatar_url || '';
                    var cid = String(c.id);
                    var thumbHtml = av
                        ? '<img src="' + av + '" class="cp-kanban-thumb" loading="lazy" decoding="async" ' +
                          'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">' +
                          '<div style="display:none;width:36px;height:36px;border-radius:8px;' +
                          'background:' + self._nameGradient(c.name) + ';' +
                          'align-items:center;justify-content:center;' +
                          'font-weight:800;font-size:0.9rem;color:#fff;flex-shrink:0;">' +
                          (c.name || '?').charAt(0).toUpperCase() + '</div>'
                        : '<div style="width:36px;height:36px;border-radius:8px;' +
                          'background:' + self._nameGradient(c.name) + ';' +
                          'display:flex;align-items:center;justify-content:center;' +
                          'font-weight:800;font-size:0.9rem;color:#fff;flex-shrink:0;">' +
                          (c.name || '?').charAt(0).toUpperCase() + '</div>';
                    return '<div style="background:rgba(255,255,255,0.04);' +
                        'border:1px solid rgba(255,255,255,0.08);border-radius:12px;' +
                        'padding:10px;cursor:pointer;transition:all 0.2s;margin-bottom:8px;" ' +
                        'onclick="_cp(\'openDetail\',\'' + cid + '\')" ' +
                        'onmouseenter="this.style.borderColor=\'' + conf.border + '\'" ' +
                        'onmouseleave="this.style.borderColor=\'rgba(255,255,255,0.08)\'">' +
                        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">' +
                        thumbHtml +
                        '<div style="flex:1;overflow:hidden;">' +
                        '<div style="font-weight:700;font-size:0.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                        _esc(c.name || 'Без имени') + '</div>' +
                        '<div style="font-size:0.68rem;color:var(--text-muted);">' + _esc(c.phone || '—') + '</div>' +
                        '</div>' +
                        '<button style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(0,119,255,0.3);' +
                        'background:rgba(0,119,255,0.12);color:#6ab4ff;cursor:pointer;flex-shrink:0;' +
                        'display:flex;align-items:center;justify-content:center;" ' +
                        'title="Написать" onclick="event.stopPropagation();_cp(\'openMessageModal\',\'' + cid + '\')">' +
                        self._icon('chat', 10) + '</button>' +
                        '</div>' +
                        '<div style="display:flex;justify-content:space-between;font-size:0.7rem;color:var(--text-muted);">' +
                        '<span>' + self._icon('order', 10) + ' ' + m.orders + ' заказов</span>' +
                        '<span style="color:var(--accent3);">' +
                        (m.total ? m.total.toLocaleString('ru-RU') + ' ₽' : '—') + '</span>' +
                        '</div></div>';
                }).join('')
                : '<div style="text-align:center;padding:20px 10px;color:var(--text-muted);' +
                  'font-size:0.75rem;border:1px dashed var(--border);border-radius:8px;">Нет клиентов</div>';

            return '<div style="background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:14px;overflow:hidden;">' +
                '<div style="padding:10px 12px;border-bottom:1px solid var(--border);background:' + conf.bg + ';">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;">' +
                '<div style="font-weight:700;font-size:0.8rem;color:' + conf.text + ';">' + label + '</div>' +
                '<span style="background:rgba(255,255,255,0.1);color:#fff;border-radius:10px;' +
                'padding:1px 8px;font-size:0.7rem;font-weight:700;">' + items.length + '</span>' +
                '</div></div>' +
                '<div style="padding:10px;max-height:600px;overflow-y:auto;">' + cards + '</div>' +
                '</div>';
        }).join('');
    },

    // ================================================================
    //  ДЕТАЛЬНАЯ КАРТОЧКА — новый дизайн в стиле ВК
    // ================================================================
    openDetail: function(id, tab) {
        var self = this;
        tab      = tab || 'main';
        this._detailId = id;

        var client = this._clients.find(function(c) { return String(c.id) === String(id); });
        if (!client) return;

        document.getElementById('cp_detail_modal') && document.getElementById('cp_detail_modal').remove();

        var st     = client.crm_status || 'new';
        var stConf = this.CRM_STATUS_COLORS[st];
        var stLbl  = this.CRM_STATUS_LABELS[st];
        var m      = client._metrics || { orders:0, total:0, debt:0, avg:0 };
        var cur    = CRM.getSettings().currency || '₽';
        var av     = client.vk_avatar || client.avatar_url || '';
        var cid    = String(client.id);

        var tabs = [
            { id:'main',    label:'Профиль'   },
            { id:'orders',  label:'Заказы'    },
            { id:'vk',      label:'ВКонтакте' },
            { id:'notify',  label:'Артемий'   },
            { id:'dadata',  label:'Реквизиты' },
            { id:'docs',    label:'Документы' },
            { id:'history', label:'История'   },
        ];

        var tabsHtml = tabs.map(function(t) {
            return '<button class="tab ' + (t.id === tab ? 'active' : '') + '" ' +
                'id="cp_tab_btn_' + t.id + '" ' +
                'onclick="_cp(\'_switchDetailTab\',\'' + cid + '\',\'' + t.id + '\')">' +
                t.label + '</button>';
        }).join('');

        // Обложка — если есть фото, делаем размытую полосу; иначе градиент
        var coverHtml = av
            ? '<div style="position:relative;height:130px;overflow:hidden;border-radius:20px 20px 0 0;">' +
              '<img src="' + av + '" class="cp-detail-cover" loading="lazy" decoding="async">' +
              '<div style="position:absolute;inset:0;background:linear-gradient(to bottom,transparent 30%,rgba(0,0,0,0.7));"></div>' +
              '</div>'
            : '<div style="height:130px;border-radius:20px 20px 0 0;background:' + this._nameGradient(client.name) + ';' +
              'position:relative;overflow:hidden;">' +
              '<div style="position:absolute;inset:0;opacity:0.15;background:repeating-linear-gradient(' +
              '45deg,transparent,transparent 10px,rgba(255,255,255,0.05) 10px,rgba(255,255,255,0.05) 20px);"></div>' +
              '</div>';

        // Аватар в шапке
        var avatarHtml = av
            ? '<img src="' + av + '" class="cp-detail-avatar" loading="lazy" decoding="async">'
            : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;' +
              'background:' + this._nameGradient(client.name) + ';font-size:2.2rem;font-weight:900;color:#fff;">' +
              (client.name || '?').charAt(0).toUpperCase() + '</div>';

        // Кнопка привязки ВК в шапке
        var vkBtnHtml = client.vk_user_id
            ? '<a href="' + (client.vk_url || 'https://vk.com/id' + client.vk_user_id) + '" target="_blank" ' +
              'class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:5px;' +
              'background:rgba(0,119,255,0.15);border-color:rgba(0,119,255,0.4);color:#6ab4ff;">' +
              this._icon('vk', 13) + ' ВКонтакте</a>'
            : '<button class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:5px;" ' +
              'onclick="_cp(\'_openVkBindModal\',\'' + cid + '\')" title="Привязать ВКонтакте">' +
              this._icon('vk', 13) + ' Привязать ВК</button>';

        var html =
            '<div class="modal-overlay" id="cp_detail_modal" style="z-index:100000;">' +
            '<div style="background:var(--bg-card);border:1px solid var(--border);' +
            'border-radius:20px;width:min(820px,95vw);max-height:92vh;' +
            'overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,0.6);">' +

            // ─── Обложка + аватар ───────────────────────────────────
            '<div style="position:relative;flex-shrink:0;">' +
            coverHtml +
            // Аватар поверх обложки
            '<div style="position:absolute;bottom:-28px;left:20px;width:80px;height:80px;' +
            'border-radius:18px;overflow:hidden;border:3px solid var(--bg-card);' +
            'box-shadow:0 4px 20px rgba(0,0,0,0.5);">' +
            avatarHtml +
            '</div>' +
            // Статус-бейдж
            '<div style="position:absolute;top:12px;right:48px;padding:4px 12px;border-radius:20px;' +
            'font-size:0.72rem;font-weight:700;background:' + stConf.bg + ';' +
            'border:1px solid ' + stConf.border + ';color:' + stConf.text + ';backdrop-filter:blur(8px);">' +
            '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;' +
            'background:' + stConf.border + ';margin-right:6px;vertical-align:middle;"></span>' + stLbl + '</div>' +
            // Закрыть
            '<button style="position:absolute;top:12px;right:12px;width:28px;height:28px;' +
            'border-radius:8px;border:1px solid rgba(255,255,255,0.2);background:rgba(0,0,0,0.5);' +
            'color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.8rem;" ' +
            'onclick="document.getElementById(\'cp_detail_modal\').remove()">✕</button>' +
            '</div>' +

            // ─── Имя + кнопки ────────────────────────────────────────
            '<div style="padding:36px 20px 0 20px;flex-shrink:0;">' +
            '<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;">' +
            '<div>' +
            '<div style="font-size:1.4rem;font-weight:800;line-height:1.2;">' + _esc(client.name || 'Без имени') + '</div>' +
            '<div style="font-size:0.78rem;color:var(--text-muted);margin-top:3px;">' +
            _esc(client.bizcat || '') + (client.type ? ' · ' + _esc(client.type) : '') +
            (client.inn ? ' · ИНН ' + _esc(client.inn) : '') +
            (client.vk_city || client.vk_status
                ? '<br><span style="font-size:0.72rem;">' +
                  (client.vk_city ? '📍 ' + _esc(client.vk_city) : '') +
                  (client.vk_status ? (client.vk_city ? ' · ' : '') + _esc(client.vk_status) : '') + '</span>'
                : '') +
            '</div>' +
            '</div>' +
            '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
            vkBtnHtml +
            '<button class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:5px;" ' +
            'onclick="_cp(\'openMessageModal\',\'' + cid + '\')">' +
            this._icon('chat', 13) + ' Написать</button>' +
            (client.phone
                ? '<a href="tel:' + _esc(client.phone) + '" class="btn btn-secondary btn-sm" ' +
                  'style="display:flex;align-items:center;gap:5px;text-decoration:none;">' +
                  this._icon('phone', 13) + ' Звонок</a>'
                : '') +
            '<button class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:5px;" ' +
            'onclick="_cp(\'openClientModal\',\'' + cid + '\')">' +
            this._icon('edit', 13) + ' Ред.</button>' +
            '</div></div>' +

            // ─── Метрики ─────────────────────────────────────────────
            '<div style="display:flex;gap:24px;margin-top:14px;padding:12px 0;border-top:1px solid var(--border);">' +
            this._metricPill('Заказов',   m.orders, 'var(--accent2)') +
            this._metricPill('Потрачено', m.total.toLocaleString('ru-RU') + ' ' + cur, 'var(--accent3)') +
            this._metricPill('Долг',
                m.debt > 0 ? m.debt.toLocaleString('ru-RU') + ' ' + cur : '—',
                m.debt > 0 ? 'var(--danger)' : 'var(--text-muted)') +
            this._metricPill('Ср. чек',
                m.avg > 0 ? m.avg.toLocaleString('ru-RU') + ' ' + cur : '—',
                'var(--accent4)') +
            (client.discount
                ? this._metricPill('Скидка', client.discount + '%', 'var(--accent)')
                : '') +
            '</div>' +

            // ─── Табы ────────────────────────────────────────────────
            '<div class="tabs" style="margin-top:0;overflow-x:auto;">' + tabsHtml + '</div>' +
            '</div>' +

            // ─── ОСНОВНОЕ СОДЕРЖИМОЕ (2 колонки) ────────────────────
            '<div style="flex:1;overflow:hidden;display:flex;min-height:0;">' +

            // Левая колонка — медиа/макеты (только на вкладке main)
            '<div id="cp_detail_left" style="' +
            (tab === 'main' ? 'width:300px;' : 'display:none;') +
            'flex-shrink:0;border-right:1px solid var(--border);overflow-y:auto;padding:14px;">' +
            this._renderLeftMedia(client) +
            '</div>' +

            // Правая колонка — контент таба
            '<div id="cp_detail_tab_content" style="flex:1;overflow-y:auto;padding:16px 20px;">' +
            this._renderTab(client, tab) +
            '</div>' +

            '</div>' +
            '</div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var modal = document.getElementById('cp_detail_modal');
        modal.classList.add('open');
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'cp_detail_modal') modal.remove();
        });

        if (tab === 'orders')  this._loadTabOrders(client);
        if (tab === 'vk')      this._loadTabVK(client);
        if (tab === 'notify')  this._loadTabArtemiy(client);
        if (tab === 'dadata')  this._loadTabDadata(client);
        if (tab === 'history') this._renderTabHistory(client);
    },

    // ── ЛЕВАЯ КОЛОНКА: макеты / медиа ────────────────────────
    _renderLeftMedia: function(client) {
        var self = this;
        var cid  = String(client.id);

        // Таб-переключатель медиа
        var mediaTabs = ['Макеты','Фото','Файлы'];
        var tabsHtml  = mediaTabs.map(function(t, i) {
            return '<button id="cp_media_tab_' + i + '" ' +
                'onclick="_cp(\'_switchMediaTab\',' + i + ',\'' + cid + '\')" ' +
                'style="flex:1;padding:5px;font-size:0.7rem;font-weight:600;cursor:pointer;border:none;' +
                'border-radius:6px;transition:all 0.2s;' +
                (i === 0 ? 'background:var(--accent);color:#fff;' : 'background:transparent;color:var(--text-muted);') + '">' +
                t + '</button>';
        }).join('');

        // Заглушка макетов — реальные файлы загружаются при переключении
        var mockupsHtml =
            '<div id="cp_media_content_0" style="display:block;">' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">' +
            // 6 слотов для макетов
            [0,1,2,3,4,5].map(function() {
                return '<div style="aspect-ratio:1;border-radius:8px;border:1px dashed var(--border);' +
                    'background:rgba(255,255,255,0.03);display:flex;align-items:center;' +
                    'justify-content:center;cursor:pointer;transition:all 0.2s;overflow:hidden;" ' +
                    'onclick="_cp(\'_triggerMockupUpload\',\'' + cid + '\')" ' +
                    'onmouseenter="this.style.borderColor=\'var(--accent)\'" ' +
                    'onmouseleave="this.style.borderColor=\'var(--border)\'">' +
                    '<span style="color:var(--text-muted);font-size:0.65rem;text-align:center;line-height:1.4;padding:8px;">' +
                    self._icon('plus', 16) + '<br>Добавить</span>' +
                    '</div>';
            }).join('') +
            '</div>' +
            '<input type="file" id="cp_mockup_input" accept="image/*" multiple style="display:none;" ' +
            'onchange="_cp(\'_uploadMockup\',this,\'' + cid + '\')">' +
            '<button class="btn btn-secondary btn-xs" style="width:100%;margin-top:8px;" ' +
            'onclick="_cp(\'_triggerMockupUpload\',\'' + cid + '\')">' +
            self._icon('upload', 11) + ' Загрузить макет</button>' +
            '</div>' +
            '<div id="cp_media_content_1" style="display:none;">' +
            '<div style="text-align:center;padding:20px 0;color:var(--text-muted);font-size:0.75rem;">' +
            self._icon('image', 24) + '<br>Фото из ВК появятся здесь</div>' +
            '</div>' +
            '<div id="cp_media_content_2" style="display:none;">' +
            '<div style="text-align:center;padding:20px 0;color:var(--text-muted);font-size:0.75rem;">' +
            self._icon('file', 24) + '<br>Файлы заказов</div>' +
            '</div>';

        return '<div>' +
            '<div style="font-size:0.7rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Медиатека</div>' +
            '<div style="display:flex;gap:2px;background:var(--bg-dark);border-radius:8px;' +
            'padding:3px;border:1px solid var(--border);margin-bottom:4px;">' +
            tabsHtml +
            '</div>' +
            mockupsHtml +
            '</div>';
    },

    _switchMediaTab: function(idx, clientId) {
        [0,1,2].forEach(function(i) {
            var btn     = document.getElementById('cp_media_tab_' + i);
            var content = document.getElementById('cp_media_content_' + i);
            if (btn) {
                btn.style.background = (i === idx) ? 'var(--accent)' : 'transparent';
                btn.style.color      = (i === idx) ? '#fff' : 'var(--text-muted)';
            }
            if (content) content.style.display = (i === idx) ? 'block' : 'none';
        });
        // Загрузка фото из ВК
        if (idx === 1) {
            var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
            if (client) this._loadVkPhotos(client);
        }
    },

    _loadVkPhotos: function(client) {
        var self    = this;
        var content = document.getElementById('cp_media_content_1');
        if (!content) return;
        if (!client.vk_user_id) {
            content.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted);font-size:0.75rem;">' +
                'ВКонтакте не привязан. Нет доступа к фото.</div>';
            return;
        }
        content.innerHTML = '<div style="text-align:center;padding:12px;">' +
            '<div class="cp-spinning" style="display:inline-block;">' + self._icon('spinner', 20) + '</div></div>';
        // Загружаем фото из чата ВК
        CRM.api('clients_pro', 'vk_chat', { vk_user_id: client.vk_user_id })
            .then(function(res) {
                var messages = res.data || [];
                var photos   = [];
                messages.forEach(function(msg) {
                    (msg.attachments || []).forEach(function(a) {
                        if (a.type === 'photo' && a.url) photos.push(a.url);
                    });
                });
                if (!photos.length) {
                    content.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted);font-size:0.75rem;">' +
                        'Фото в диалоге не найдено</div>';
                    return;
                }
                content.innerHTML =
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-top:6px;">' +
                    photos.slice(0, 20).map(function(url) {
                        return '<div style="aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--bg-dark);">' +
                            '<img src="' + url + '" class="cp-media-thumb" loading="lazy" decoding="async" ' +
                            'onclick="window.open(\'' + url + '\',\'_blank\')" style="cursor:zoom-in;">' +
                            '</div>';
                    }).join('') +
                    '</div>';
            });
    },

    _triggerMockupUpload: function(clientId) {
        var input = document.getElementById('cp_mockup_input');
        if (input) input.click();
    },

    _uploadMockup: function(input, clientId) {
        var self    = this;
        var files   = input.files;
        if (!files || !files.length) return;
        var client  = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        var grid    = document.querySelector('#cp_media_content_0 .mockup-grid') ||
                      document.querySelector('#cp_media_content_0 div[style*="grid"]');

        var apiUrl = window.API_URL || (typeof API_URL !== 'undefined' ? API_URL : '/api/api.php');
        var apiKey = window.API_KEY || (typeof API_KEY !== 'undefined' ? API_KEY : '12345');

        Array.from(files).forEach(function(file) {
            var fd = new FormData();
            fd.append('file', file, file.name);
            fetch(apiUrl + '?action=upload&key=' + apiKey, { method:'POST', body:fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var url = data.url || data.file_url || '';
                    if (!url) return;
                    notify('Макет загружен', 'success');
                    // Сохраняем в данные клиента
                    if (client) {
                        if (!client.mockups) client.mockups = [];
                        client.mockups.push({ url: url, name: file.name, date: new Date().toISOString() });
                        CRM.api('clients_pro', 'save', client);
                    }
                    // Обновляем превью
                    self._refreshMockupsGrid(clientId);
                });
        });
    },

    _refreshMockupsGrid: function(clientId) {
        var self    = this;
        var client  = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        var content = document.getElementById('cp_media_content_0');
        if (!content || !client) return;
        var mockups = client.mockups || [];
        var gridDiv = content.querySelector('div[style*="grid"]');
        if (!gridDiv) return;
        if (!mockups.length) return;
        gridDiv.innerHTML = mockups.map(function(m) {
            return '<div style="aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--bg-dark);position:relative;cursor:zoom-in;" ' +
                'onclick="window.open(\'' + m.url + '\',\'_blank\')">' +
                '<img src="' + m.url + '" class="cp-media-thumb" loading="lazy" decoding="async">' +
                '</div>';
        }).join('') +
        '<div style="aspect-ratio:1;border-radius:8px;border:1px dashed var(--border);' +
        'background:rgba(255,255,255,0.03);display:flex;align-items:center;' +
        'justify-content:center;cursor:pointer;" ' +
        'onclick="_cp(\'_triggerMockupUpload\',\'' + clientId + '\')">' +
        '<span style="color:var(--text-muted);">' + self._icon('plus', 16) + '</span>' +
        '</div>';
    },

    // ── ПЕРЕКЛЮЧАТЕЛЬ ТАБОВ ──────────────────────────────────
    _switchDetailTab: function(cid, tab) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(cid); });
        if (!client) return;

        document.querySelectorAll('[id^="cp_tab_btn_"]').forEach(function(b) { b.classList.remove('active'); });
        var btn = document.getElementById('cp_tab_btn_' + tab);
        if (btn) btn.classList.add('active');

        // Левая колонка — только на главном табе
        var leftCol = document.getElementById('cp_detail_left');
        if (leftCol) leftCol.style.display = tab === 'main' ? 'block' : 'none';

        var content = document.getElementById('cp_detail_tab_content');
        if (content) {
            content.innerHTML = self._renderTab(client, tab);
            if (tab === 'orders')  self._loadTabOrders(client);
            if (tab === 'vk')      self._loadTabVK(client);
            if (tab === 'notify')  self._loadTabArtemiy(client);
            if (tab === 'dadata')  self._loadTabDadata(client);
            if (tab === 'history') self._renderTabHistory(client);
        }
    },

    _renderTab: function(client, tab) {
        if (tab === 'main')    return this._tabMain(client);
        if (tab === 'orders')  return this._tabOrders();
        if (tab === 'vk')      return this._tabVK();
        if (tab === 'notify')  return this._tabArtemiy();
        if (tab === 'dadata')  return this._tabDadata(client);
                    if (tab === 'docs') {
            // Вместо старой заглушки — сразу запускаем загрузку через патч
            setTimeout(function() {
                var clientId = String(client.id);
                var content = document.getElementById('cp_detail_tab_content');
                if (content) {
                    content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">📄 Загрузка документов...</div>';
                    // Загружаем через API
                   CRM.api('documents', 'list', null, { client_id: clientId }).then(function(res) {
    var docs = res.data || [];
    if (!docs.length) {
        content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">У клиента нет привязанных документов</div>';
        return;
    }

    // ✅ СОХРАНЯЕМ ДОКУМЕНТЫ В КЕШ МОДУЛЯ ДОКУМЕНТОВ
    if (CRM.modules && CRM.modules.documents) {
        docs.forEach(function(d) {
            var existing = CRM.modules.documents._docs.findIndex(function(x) { return x.id === d.id; });
            if (existing === -1) {
                CRM.modules.documents._docs.push(d);
            } else {
                CRM.modules.documents._docs[existing] = d;
            }
        });
        console.log('✅ Документы сохранены в _docs. Всего:', CRM.modules.documents._docs.length);
    }

    var html = '<div style="padding:10px;display:flex;flex-direction:column;gap:8px;">';
    docs.forEach(function(d) {
        // ... твой код генерации карточки ...
        var color = {
            invoice:'#10b981', invoice_tax:'#10b981',
            waybill:'#3b82f6', waybill_upd:'#3b82f6',
            contract:'#8b5cf6', act:'#f59e0b', kp:'#ec4899', other:'#6b7280'
        }[d.type] || '#6b7280';
        var icon = {
            invoice:'🧾', invoice_tax:'🧾',
            waybill:'📦', waybill_upd:'📦',
            contract:'📝', act:'✅', kp:'💼', other:'📄'
        }[d.type] || '📄';
        var p = d.parsed || {};
        html += '<div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:10px;padding:12px;border-left:4px solid ' + color + ';cursor:pointer;transition:all 0.2s;" onmouseenter="this.style.borderColor=\'' + color + '55\'" onmouseleave="this.style.borderColor=\'var(--border)\'" onclick="CRM.modules.documents._viewDoc(\'' + d.id + '\')">';
        html += '<div style="display:flex;align-items:center;gap:10px;">';
        html += '<span style="font-size:1.2rem;">' + icon + '</span>';
        html += '<div style="flex:1;">';
        html += '<div style="font-weight:700;font-size:0.9rem;">' + d.name + '</div>';
        html += '<div style="font-size:0.72rem;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px;">';
        html += '<span>' + (d.typeName || d.type) + '</span>';
        if (p.number) html += '<span>· №' + p.number + '</span>';
        if (p.total) html += '<span>· 💰 ' + Number(p.total).toLocaleString('ru-RU') + ' ₽</span>';
        if (p.date) html += '<span>· 📅 ' + p.date + '</span>';
        html += '</div></div>';
        html += '<div style="display:flex;gap:8px;align-items:center;">';
        html += '<span style="font-size:0.7rem;padding:3px 8px;border-radius:6px;background:' + color + '15;color:' + color + ';">' + (d.kanban || 'new') + '</span>';
        if (d.filePath) {
            html += '<button class="btn btn-secondary btn-xs" onclick="event.stopPropagation();CRM.modules.documents._downloadById(\'' + d.id + '\')">⬇️ Скачать</button>';
        }
        html += '</div></div></div>';
    });
    html += '</div>';
    content.innerHTML = html;
}).catch(function(err) {
    content.innerHTML = '<div style="color:#f87171;padding:20px;">Ошибка загрузки документов</div>';
    console.error(err);
});
                }
            }, 100);
            return '<div id="cp_detail_tab_content" style="text-align:center;padding:40px;color:#64748b;">📄 Загрузка документов...</div>';
        }
        if (tab === 'history') return this._tabHistory();
        return '';
    },

    // ── ТАБ: ПРОФИЛЬ ────────────────────────────────────────
    _tabMain: function(client) {
        var self     = this;
        var cid      = String(client.id);
        var tagsHtml = (client.tags || []).map(function(t) {
            return '<span style="background:rgba(124,58,237,0.15);color:var(--accent);' +
                'border:1px solid rgba(124,58,237,0.25);font-size:0.7rem;font-weight:600;' +
                'padding:3px 10px;border-radius:20px;">' + _esc(t) + '</span>';
        }).join('');

        var infoRow = function(icon, label, value, copiable) {
            if (!value) return '';
            return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' +
                'border-bottom:1px solid rgba(255,255,255,0.05);">' +
                '<span style="color:var(--text-muted);flex-shrink:0;">' + icon + '</span>' +
                '<span style="font-size:0.72rem;color:var(--text-muted);min-width:80px;">' + label + '</span>' +
                '<span style="font-size:0.85rem;font-weight:600;flex:1;">' + _esc(value) + '</span>' +
                (copiable
                    ? '<button style="width:22px;height:22px;border-radius:5px;border:1px solid var(--border);' +
                      'background:var(--bg-dark);color:var(--text-muted);cursor:pointer;' +
                      'display:flex;align-items:center;justify-content:center;flex-shrink:0;" ' +
                      'onclick="navigator.clipboard.writeText(\'' + _esc(value) + '\').then(function(){notify(\'Скопировано\',\'success\')})">' +
                      self._icon('copy', 10) + '</button>'
                    : '') +
                '</div>';
        };

        var statusBtns = self.CRM_STATUSES.map(function(s) {
            var conf     = self.CRM_STATUS_COLORS[s];
            var lbl      = self.CRM_STATUS_LABELS[s];
            var isActive = (client.crm_status || 'new') === s;
            return '<button style="padding:5px 12px;border-radius:8px;font-size:0.72rem;font-weight:700;' +
                'cursor:pointer;transition:all 0.2s;border:1px solid ' +
                (isActive ? conf.border : 'var(--border)') + ';background:' +
                (isActive ? conf.bg : 'var(--bg-dark)') + ';color:' +
                (isActive ? conf.text : 'var(--text-muted)') + ';" ' +
                'onclick="_cp(\'_changeClientStatus\',\'' + cid + '\',\'' + s + '\')">' +
                lbl + '</button>';
        }).join('');

        // Правая: Заказы (мини-список)
        var rightOrdersHtml =
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">' +
            self._icon('order', 12) + ' Последние заказы</div>' +
            '<div id="cp_main_orders_mini" style="margin-bottom:14px;">' +
            '<div style="text-align:center;padding:10px;color:var(--text-muted);font-size:0.75rem;">' +
            '<div class="cp-spinning" style="display:inline-block;">' + self._icon('spinner', 16) + '</div></div>' +
            '</div>';

        // Правая: Заметка
        var noteBlock =
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Добавить заметку</div>' +
            '<div style="display:flex;gap:8px;">' +
            '<textarea class="form-input" id="cp_note_text" rows="2" ' +
            'placeholder="Заметка о клиенте..." style="flex:1;resize:none;font-family:inherit;font-size:0.82rem;"></textarea>' +
            '<button class="btn btn-primary btn-sm" onclick="_cp(\'_addNote\',\'' + cid + '\')">' +
            self._icon('send', 13) + '</button>' +
            '</div>';

        var result =
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
            // Левая: контакты
            '<div>' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Контакты</div>' +
            '<div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:12px;padding:12px;">' +
            infoRow(self._icon('phone', 13), 'Телефон',  client.phone,   true) +
            infoRow(self._icon('mail', 13),  'Email',    client.email,   true) +
            infoRow(self._icon('pin', 13),   'Адрес',    client.address, false) +
            (client.inn     ? infoRow(self._icon('building', 13), 'ИНН',     client.inn,     true) : '') +
            (client.ogrn    ? infoRow(self._icon('building', 13), 'ОГРН',    client.ogrn,    true) : '') +
            (client.kpp     ? infoRow(self._icon('building', 13), 'КПП',     client.kpp,     true) : '') +
            (client.director? infoRow(self._icon('user', 13),     'Рук-ль',  client.director,false) : '') +
            (client.discount? infoRow(self._icon('tag', 13),      'Скидка',  client.discount + '%', false) : '') +
            '</div>' +
            // Теги
            (tagsHtml
                ? '<div style="margin-top:12px;">' +
                  '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
                  'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Теги</div>' +
                  '<div style="display:flex;flex-wrap:wrap;gap:5px;">' + tagsHtml + '</div>' +
                  '</div>'
                : '') +
            // Заметки клиента
            (client.notes
                ? '<div style="margin-top:12px;background:rgba(245,158,11,0.06);' +
                  'border:1px solid rgba(245,158,11,0.2);border-radius:10px;padding:10px 12px;">' +
                  '<div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:4px;">Заметки</div>' +
                  '<div style="font-size:0.82rem;">' + _esc(client.notes) + '</div>' +
                  '</div>'
                : '') +
            '</div>' +
            // Правая: статус + заказы + заметка
            '<div>' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Статус CRM</div>' +
            '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">' + statusBtns + '</div>' +
            rightOrdersHtml +
            noteBlock +
            '</div>' +
            '</div>';

        // Подгружаем мини-список заказов
        setTimeout(function() {
            var miniOrders = document.getElementById('cp_main_orders_mini');
            if (!miniOrders) return;
            CRM.api('clients_pro', 'client_orders', { phone: client.phone || '', name: client.name || '' })
                .then(function(res) {
                    var orders = (res.data || []).slice(0, 4);
                    if (!orders.length) {
                        miniOrders.innerHTML = '<div style="color:var(--text-muted);font-size:0.75rem;padding:6px 0;">Заказов нет</div>';
                        return;
                    }
                    var cur2 = CRM.getSettings().currency || '₽';
                    miniOrders.innerHTML = orders.map(function(o) {
                        var statusColors2 = { new:'var(--accent)', work:'var(--accent4)', ready:'var(--accent2)', done:'var(--accent3)', cancel:'var(--danger)' };
                        return '<div style="display:flex;align-items:center;justify-content:space-between;' +
                            'padding:6px 8px;background:var(--bg-dark);border-radius:8px;margin-bottom:4px;">' +
                            '<div><div style="font-size:0.78rem;font-weight:600;">' + _esc(o.num || '') + '</div>' +
                            '<div style="font-size:0.65rem;color:var(--text-muted);">' +
                            (o.date ? new Date(o.date).toLocaleDateString('ru-RU') : '') + '</div></div>' +
                            '<span style="font-size:0.72rem;font-weight:700;color:' +
                            (statusColors2[o.status] || 'var(--text)') + ';">' +
                            (o.total ? Number(o.total).toLocaleString('ru-RU') + ' ' + cur2 : '—') + '</span>' +
                            '</div>';
                    }).join('') +
                    '<button class="btn btn-secondary btn-xs" style="width:100%;margin-top:4px;" ' +
                    'onclick="_cp(\'_switchDetailTab\',\'' + String(client.id) + '\',\'orders\')">' +
                    'Все заказы (' + res.data.length + ')</button>';
                });
        }, 100);

        return result;
    },

    // ── ТАБ: ЗАКАЗЫ ─────────────────────────────────────────
    _tabOrders: function() {
        return '<div>' +
            '<div style="display:flex;gap:8px;margin-bottom:12px;">' +
            '<div class="search-bar" style="flex:1;">' +
            '<span>' + this._icon('search', 13) + '</span>' +
            '<input type="text" id="cp_orders_search" placeholder="Поиск по заказам..." ' +
            'oninput="_cp(\'_filterOrdersList\')" style="background:transparent;border:none;outline:none;color:inherit;width:100%;">' +
            '</div></div>' +
            '<div id="cp_orders_content" style="text-align:center;padding:20px;"><div class="spinner"></div></div>' +
            '</div>';
    },
    _loadTabOrders: function(client) {
        var self = this;
        CRM.api('clients_pro', 'client_orders', { phone: client.phone || '', name: client.name || '' })
            .then(function(res) {
                var orders  = res.data || [];
                self._currentOrders = orders;
                self._renderOrdersList(orders);
            });
    },
    _filterOrdersList: function() {
        var q = (document.getElementById('cp_orders_search') || {}).value || '';
        q = q.toLowerCase();
        var filtered = (this._currentOrders || []).filter(function(o) {
            return !q ||
                (o.num    || '').toLowerCase().includes(q) ||
                (o.service || '').toLowerCase().includes(q) ||
                (o.serviceLabel || '').toLowerCase().includes(q);
        });
        this._renderOrdersList(filtered);
    },
    _renderOrdersList: function(orders) {
        var content = document.getElementById('cp_orders_content');
        if (!content) return;
        if (!orders.length) {
            content.innerHTML = '<div class="empty-state"><div class="icon">' +
                this._icon('order', 36) + '</div><div class="title">Заказов нет</div></div>';
            return;
        }
        var statusLabels = { new:'Новый', work:'В работе', ready:'Готов', done:'Выдан', cancel:'Отменён' };
        var statusColors = { new:'var(--accent)', work:'var(--accent4)', ready:'var(--accent2)', done:'var(--accent3)', cancel:'var(--danger)' };
        var cur = CRM.getSettings().currency || '₽';
        // Итого
        var totalSum = orders.reduce(function(s, o) { return s + (Number(o.total) || 0); }, 0);
        var totalDebt = orders.reduce(function(s, o) {
            return s + Math.max(0, (Number(o.total) || 0) - (Number(o.paid) || Number(o.prepay) || 0));
        }, 0);
        content.innerHTML =
            '<div style="display:flex;gap:12px;margin-bottom:12px;">' +
            '<div style="flex:1;background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);' +
            'border-radius:10px;padding:8px;text-align:center;">' +
            '<div style="font-size:0.65rem;color:var(--text-muted);">Всего заказов</div>' +
            '<div style="font-weight:800;color:var(--accent2);">' + orders.length + '</div></div>' +
            '<div style="flex:1;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);' +
            'border-radius:10px;padding:8px;text-align:center;">' +
            '<div style="font-size:0.65rem;color:var(--text-muted);">Сумма</div>' +
            '<div style="font-weight:800;color:var(--accent3);">' + totalSum.toLocaleString('ru-RU') + ' ' + cur + '</div></div>' +
            (totalDebt > 0
                ? '<div style="flex:1;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);' +
                  'border-radius:10px;padding:8px;text-align:center;">' +
                  '<div style="font-size:0.65rem;color:var(--text-muted);">Долг</div>' +
                  '<div style="font-weight:800;color:var(--danger);">' + totalDebt.toLocaleString('ru-RU') + ' ' + cur + '</div></div>'
                : '') +
            '</div>' +
            '<div style="display:flex;flex-direction:column;gap:8px;">' +
            orders.map(function(o) {
                var st   = o.status || 'new';
                var paid = (Number(o.paid) || Number(o.prepay) || 0);
                var debt = Math.max(0, (Number(o.total) || 0) - paid);
                return '<div style="background:var(--bg-dark);border:1px solid var(--border);' +
                    'border-radius:10px;padding:10px 12px;">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                    '<span style="font-weight:700;font-size:0.85rem;">' + _esc(o.num || '—') + '</span>' +
                    '<span style="font-size:0.72rem;padding:2px 8px;border-radius:8px;' +
                    'background:rgba(255,255,255,0.06);color:' + (statusColors[st] || 'var(--text)') + ';">' +
                    (statusLabels[st] || st) + '</span></div>' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;">' +
                    '<span style="font-size:0.78rem;color:var(--text-muted);">' + _esc(o.serviceLabel || o.service || '—') + '</span>' +
                    '<div style="display:flex;gap:10px;font-size:0.78rem;">' +
                    '<span style="color:var(--accent3);">' + (o.total ? Number(o.total).toLocaleString('ru-RU') + ' ' + cur : '') + '</span>' +
                    (debt > 0 ? '<span style="color:var(--danger);">долг ' + debt.toLocaleString('ru-RU') + ' ' + cur + '</span>' : '') +
                    '</div></div>' +
                    '<div style="font-size:0.65rem;color:var(--text-muted);margin-top:3px;">' +
                    (o.date ? new Date(o.date).toLocaleDateString('ru-RU') : '') + '</div>' +
                    '</div>';
            }).join('') + '</div>';
    },

    // ── ТАБ: ВК ─────────────────────────────────────────────
    _tabVK: function() {
        return '<div id="cp_vk_content" style="text-align:center;padding:20px;"><div class="spinner"></div></div>';
    },
    _loadTabVK: function(client) {
        var self    = this;
        var content = document.getElementById('cp_vk_content');
        if (!content) return;
        var cid = String(client.id);
        Promise.all([
            CRM.api('clients_pro', 'vk_profile', { phone: client.phone || '' }),
            CRM.api('clients_pro', 'vk_chat', { vk_user_id: client.vk_user_id || 0, phone: client.phone || '' }),
        ]).then(function(res) {
            var profile  = res[0].data;
            var messages = res[1].data || [];
            var vkId     = res[1].vk_user_id || 0;

            if (!profile && !messages.length) {
                content.innerHTML =
                    '<div class="empty-state">' +
                    '<div class="icon" style="opacity:0.3;">' + self._icon('vk', 40) + '</div>' +
                    '<div class="title">ВКонтакте не привязан</div>' +
                    '<div class="desc">Нажмите «Привязать ВК» в шапке карточки</div>' +
                    '<button class="btn btn-primary btn-sm" style="margin-top:10px;" ' +
                    'onclick="_cp(\'_openVkBindModal\',\'' + cid + '\')">' +
                    self._icon('vk', 13) + ' Привязать ВКонтакте</button>' +
                    '</div>';
                return;
            }

            // Автообновление данных клиента из профиля
            if (profile && vkId && !client.vk_user_id) {
                client.vk_user_id  = vkId;
                client.vk_avatar   = profile.avatar || profile.avatar_big || '';
                client.vk_url      = profile.vk_url || '';
                client.vk_city     = profile.city || '';
                client.vk_bdate    = profile.bdate || '';
                client.vk_status   = profile.status || '';
                client.vk_about    = profile.about || '';
                client.vk_followers= profile.followers_count || 0;
            }

            // Блок профиля ВК с расширенными данными
            var profileHtml = '';
            if (profile) {
                var extraFields = [
                    profile.city        ? '📍 ' + profile.city : '',
                    profile.bdate       ? '🎂 ' + profile.bdate : '',
                    profile.relation != null && profile.relation !== '' ? ['❓','💑','🤙','💍','💔','🎉','🥰','💛'][profile.relation] || '' : '',
                    profile.followers_count ? '👥 ' + Number(profile.followers_count).toLocaleString('ru-RU') + ' подписчиков' : '',
                    profile.last_seen   ? '🕐 ' + self._formatVkLastSeen(profile.last_seen) : '',
                    profile.site        ? '🌐 ' + profile.site : '',
                ].filter(Boolean).join('  ·  ');

                profileHtml =
                    '<div style="background:rgba(0,119,255,0.08);border:1px solid rgba(0,119,255,0.2);' +
                    'border-radius:12px;padding:12px;margin-bottom:14px;">' +
                    '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">' +
                    (profile.avatar
                        ? '<img src="' + profile.avatar + '" style="width:52px;height:52px;border-radius:12px;object-fit:cover;" loading="lazy" decoding="async">'
                        : '') +
                    '<div style="flex:1;">' +
                    '<div style="font-weight:700;font-size:1rem;">' + _esc(profile.name || '') + '</div>' +
                    (profile.status ? '<div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;font-style:italic;">"' + _esc(profile.status) + '"</div>' : '') +
                    (profile.vk_url ? '<a href="' + profile.vk_url + '" target="_blank" style="font-size:0.72rem;color:#6ab4ff;display:block;margin-top:2px;">' + profile.vk_url + '</a>' : '') +
                    '</div>' +
                    // Онлайн-индикатор
                    '<div style="display:flex;align-items:center;gap:4px;font-size:0.65rem;' +
                    'color:' + (profile.online ? 'var(--accent3)' : 'var(--text-muted)') + ';">' +
                    '<div style="width:6px;height:6px;border-radius:50%;background:' +
                    (profile.online ? 'var(--accent3)' : 'var(--text-muted)') + ';"></div>' +
                    (profile.online ? 'онлайн' : 'офлайн') + '</div>' +
                    '</div>' +
                    (extraFields
                        ? '<div style="font-size:0.72rem;color:var(--text-muted);line-height:1.8;padding-top:6px;border-top:1px solid rgba(255,255,255,0.06);">' + _esc(extraFields) + '</div>'
                        : '') +
                    (profile.about
                        ? '<div style="font-size:0.75rem;margin-top:6px;padding:8px;background:rgba(255,255,255,0.04);border-radius:8px;">' + _esc(profile.about) + '</div>'
                        : '') +
                    '</div>';
            }

            var chatHtml = messages.length
                ? '<div id="cp_vk_chat_wrap" style="display:flex;flex-direction:column;gap:6px;' +
                  'max-height:280px;overflow-y:auto;padding:2px;scroll-behavior:smooth;">' +
                  messages.slice(-40).map(function(msg) {
                      var isClient = msg.role === 'client';
                      var hasAtts  = msg.attachments && msg.attachments.length;
                      var attsHtml = hasAtts
                          ? '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">' +
                            msg.attachments.map(function(a) {
                                return a.type === 'photo' && a.url
                                    ? '<img src="' + a.url + '" loading="lazy" decoding="async" ' +
                                      'style="max-width:110px;max-height:110px;border-radius:8px;' +
                                      'object-fit:cover;cursor:zoom-in;image-rendering:auto;" ' +
                                      'onclick="window.open(\'' + a.url + '\',\'_blank\')">'
                                    : '<div style="font-size:0.7rem;padding:3px 8px;background:rgba(255,255,255,0.08);border-radius:6px;">' +
                                      _esc(a.title || a.type || 'файл') + '</div>';
                            }).join('') + '</div>'
                          : '';
                      return '<div style="display:flex;justify-content:' + (isClient ? 'flex-start' : 'flex-end') + ';">' +
                          '<div style="max-width:78%;padding:7px 10px;border-radius:' +
                          (isClient ? '4px 10px 10px 10px' : '10px 4px 10px 10px') + ';' +
                          'background:' + (isClient ? 'var(--bg-card2,var(--bg-dark))' : 'rgba(0,119,255,0.2)') + ';' +
                          'border:1px solid ' + (isClient ? 'var(--border)' : 'rgba(0,119,255,0.3)') + ';">' +
                          (msg.text ? '<div style="font-size:0.8rem;white-space:pre-wrap;">' + _esc(msg.text) + '</div>' : '') +
                          attsHtml +
                          '<div style="font-size:0.6rem;color:var(--text-muted);margin-top:3px;text-align:right;">' +
                          (msg.time ? new Date(msg.time * 1000 || msg.time).toLocaleTimeString('ru-RU', {hour:'2-digit',minute:'2-digit'}) : '') +
                          '</div></div></div>';
                  }).join('') + '</div>'
                : '<div style="color:var(--text-muted);font-size:0.82rem;text-align:center;padding:16px;">' +
                  self._icon('chat', 24) + '<br>История сообщений пуста</div>';

            // Поле отправки (только если есть vkId)
            var sendHtml = '';
            if (vkId || client.vk_user_id) {
                sendHtml =
                    '<div style="border-top:1px solid var(--border);padding-top:12px;margin-top:10px;">' +
                    '<div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:6px;">Отправить сообщение</div>' +
                    '<div style="display:flex;gap:8px;align-items:flex-end;">' +
                    '<textarea class="form-input" id="cp_vk_send_text" rows="2" placeholder="Введите сообщение..." ' +
                    'style="flex:1;resize:none;font-family:inherit;font-size:0.82rem;" ' +
                    'onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();_cp(\'_sendVkMessage\',\'' + cid + '\')}">' +
                    '</textarea>' +
                    '<div style="display:flex;flex-direction:column;gap:4px;">' +
                    '<button class="btn btn-primary btn-sm" id="cp_vk_send_btn" ' +
                    'onclick="_cp(\'_sendVkMessage\',\'' + cid + '\')">' +
                    self._icon('send', 13) + '</button>' +
                    '<button class="btn btn-secondary btn-xs" title="Шаблоны" ' +
                    'onclick="_cp(\'_showMsgTemplatesVK\')">' +
                    '📋</button>' +
                    '</div></div>' +
                    '<div id="cp_vk_templates" style="display:none;margin-top:6px;"></div>' +
                    '</div>';
            }

            content.innerHTML =
                profileHtml +
                '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
                'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">' +
                self._icon('chat', 12) + ' Сообщения (' + messages.length + ')</div>' +
                chatHtml +
                sendHtml;

            // Скролл вниз
            setTimeout(function() {
                var wrap = document.getElementById('cp_vk_chat_wrap');
                if (wrap) wrap.scrollTop = wrap.scrollHeight;
            }, 50);
        });
    },

    _formatVkLastSeen: function(lastSeen) {
        if (!lastSeen) return '';
        var ts   = typeof lastSeen === 'object' ? lastSeen.time : lastSeen;
        if (!ts) return '';
        var d    = new Date(ts * 1000);
        var now  = new Date();
        var diff = (now - d) / 1000;
        if (diff < 60)     return 'только что';
        if (diff < 3600)   return Math.floor(diff / 60) + ' мин. назад';
        if (diff < 86400)  return Math.floor(diff / 3600) + ' ч. назад';
        return d.toLocaleDateString('ru-RU');
    },

    _showMsgTemplatesVK: function() {
        var self = this;
        var wrap = document.getElementById('cp_vk_templates');
        if (!wrap) return;
        if (wrap.style.display === 'block') { wrap.style.display = 'none'; return; }
        wrap.style.display = 'block';
        wrap.innerHTML = this.MSG_TEMPLATES.map(function(t) {
            return '<button class="btn btn-secondary btn-xs" style="margin:2px;text-align:left;" ' +
                'onclick="var ta=document.getElementById(\'cp_vk_send_text\');if(ta)ta.value=' +
                JSON.stringify(t.text) + ';document.getElementById(\'cp_vk_templates\').style.display=\'none\'">' +
                t.label + '</button>';
        }).join('');
    },

    _sendVkMessage: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;
        var input = document.getElementById('cp_vk_send_text');
        var text  = input ? input.value.trim() : '';
        if (!text) return;

        var btn = document.getElementById('cp_vk_send_btn');
        if (btn) { btn.disabled = true; btn.innerHTML = self._icon('spinner', 13); }

        CRM.api('clients_pro', 'send_message', { phone: client.phone, text: text })
            .then(function(res) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = self._icon('send', 13);
                }
                if (res && res.ok) {
                    notify('Сообщение отправлено', 'success');
                    if (input) input.value = '';
                    // Добавляем в чат визуально
                    var chatWrap = document.getElementById('cp_vk_chat_wrap');
                    if (chatWrap) {
                        var nowStr = new Date().toLocaleTimeString('ru-RU', {hour:'2-digit',minute:'2-digit'});
                        chatWrap.insertAdjacentHTML('beforeend',
                            '<div style="display:flex;justify-content:flex-end;">' +
                            '<div style="max-width:78%;padding:7px 10px;border-radius:10px 4px 10px 10px;' +
                            'background:rgba(0,119,255,0.2);border:1px solid rgba(0,119,255,0.3);">' +
                            '<div style="font-size:0.8rem;white-space:pre-wrap;">' + _esc(text) + '</div>' +
                            '<div style="font-size:0.6rem;color:var(--text-muted);margin-top:3px;text-align:right;">' + nowStr + '</div>' +
                            '</div></div>'
                        );
                        chatWrap.scrollTop = chatWrap.scrollHeight;
                    }
                } else {
                    notify('Не удалось отправить — ВК не привязан или ошибка', 'error');
                }
            });
    },

    // ── ТАБ: АРТЕМИЙ ────────────────────────────────────────
    _tabArtemiy: function() {
        return '<div id="cp_artemiy_content" style="text-align:center;padding:20px;"><div class="spinner"></div></div>';
    },
    _loadTabArtemiy: function(client) {
        var self    = this;
        var content = document.getElementById('cp_artemiy_content');
        if (!content) return;

        CRM.api('clients_pro', 'artemiy_history', { phone: client.phone || '' })
            .then(function(res) {
                // Поддерживаем разные форматы: res.data, res.history, res.items, или сам массив
                var history = [];
                if (Array.isArray(res.data))    history = res.data;
                else if (Array.isArray(res.history)) history = res.history;
                else if (Array.isArray(res.items))   history = res.items;
                else if (Array.isArray(res))         history = res;

                if (!history.length) {
                    content.innerHTML =
                        '<div class="empty-state">' +
                        '<div class="icon" style="font-size:2.5rem;opacity:0.4;">' + self._icon('robot', 44) + '</div>' +
                        '<div class="title">Нет истории уведомлений</div>' +
                        '<div class="desc">Артемий ещё не отправлял уведомления этому клиенту</div>' +
                        '</div>';
                    return;
                }

                // Сводка
                var okCount  = history.filter(function(h) { return h.ok !== false && h.status !== 'error'; }).length;
                var errCount = history.length - okCount;

                content.innerHTML =
                    '<div style="display:flex;gap:10px;margin-bottom:12px;">' +
                    '<div style="flex:1;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);' +
                    'border-radius:10px;padding:8px;text-align:center;">' +
                    '<div style="font-size:0.65rem;color:var(--text-muted);">Доставлено</div>' +
                    '<div style="font-weight:800;color:var(--accent3);">' + okCount + '</div></div>' +
                    (errCount > 0
                        ? '<div style="flex:1;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);' +
                          'border-radius:10px;padding:8px;text-align:center;">' +
                          '<div style="font-size:0.65rem;color:var(--text-muted);">Ошибок</div>' +
                          '<div style="font-weight:800;color:var(--danger);">' + errCount + '</div></div>'
                        : '') +
                    '</div>' +
                    '<div style="display:flex;flex-direction:column;gap:6px;">' +
                    history.slice(0, 50).map(function(h) {
                        var ok = h.ok !== false && h.status !== 'error' && h.status !== 'failed';
                        // Дата — пробуем разные поля
                        var dateVal = h.date || h.time || h.created_at || h.sent_at || '';
                        var dateStr = '';
                        if (dateVal) {
                            try {
                                var d = new Date(typeof dateVal === 'number' && dateVal < 2e10
                                    ? dateVal * 1000 : dateVal);
                                if (!isNaN(d)) dateStr = d.toLocaleString('ru-RU');
                            } catch(e) {}
                        }
                        // Текст уведомления
                        var msgText = h.text || h.message || h.event || h.template || h.type || 'Уведомление';
                        // Канал
                        var channel = h.channel || h.method || '';
                        var channelEmoji = { sms:'📱', vk:'💬', email:'📧', push:'🔔', whatsapp:'💬' };

                        return '<div style="background:var(--bg-dark);border:1px solid var(--border);' +
                            'border-radius:10px;padding:10px 12px;display:flex;align-items:flex-start;gap:10px;">' +
                            '<div style="width:28px;height:28px;border-radius:8px;flex-shrink:0;' +
                            'display:flex;align-items:center;justify-content:center;' +
                            'background:' + (ok ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)') + ';' +
                            'color:' + (ok ? 'var(--accent3)' : 'var(--danger)') + ';">' +
                            (ok ? self._icon('check', 14) : '✕') + '</div>' +
                            '<div style="flex:1;min-width:0;">' +
                            '<div style="font-size:0.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;">' +
                            _esc(msgText) + '</div>' +
                            '<div style="font-size:0.65rem;color:var(--text-muted);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap;">' +
                            (dateStr ? '<span>' + dateStr + '</span>' : '') +
                            (channel ? '<span>' + (channelEmoji[channel] || '📤') + ' ' + _esc(channel) + '</span>' : '') +
                            (h.order_id || h.orderId ? '<span>Заказ: ' + _esc(String(h.order_id || h.orderId)) + '</span>' : '') +
                            '</div></div>' +
                            '<span style="font-size:0.65rem;padding:2px 8px;border-radius:6px;flex-shrink:0;white-space:nowrap;' +
                            'background:' + (ok ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)') + ';' +
                            'color:' + (ok ? 'var(--accent3)' : 'var(--danger)') + ';">' +
                            (ok ? 'OK' : (h.status || 'Ошибка')) + '</span>' +
                            '</div>';
                    }).join('') +
                    '</div>';
            });
    },

    // ── ТАБ: РЕКВИЗИТЫ (DaData) ─────────────────────────────
    _tabDadata: function(client) {
        var inn  = client.inn  || '';
        var ogrn = client.ogrn || '';
        return '<div id="cp_dadata_content">' +
            '<div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);' +
            'border-radius:12px;padding:14px;margin-bottom:16px;">' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">' +
            this._icon('building', 12) + ' Поиск по ИНН / ОГРН</div>' +
            '<div style="display:flex;gap:8px;">' +
            '<input class="form-input" id="cp_dadata_query" ' +
            'value="' + _esc(inn || ogrn) + '" ' +
            'placeholder="7707083893 или ОГРН..." style="flex:1;" ' +
            'onkeydown="if(event.key===\'Enter\')_cp(\'_searchDadata\',\'' + String(client.id) + '\')">' +
            '<button class="btn btn-primary" onclick="_cp(\'_searchDadata\',\'' + String(client.id) + '\')">' +
            this._icon('search', 13) + ' Найти</button>' +
            '</div>' +
            '<div style="font-size:0.68rem;color:var(--text-muted);margin-top:6px;">' +
            'Автозаполнение реквизитов из ЕГРЮЛ/ЕГРИП через DaData.ru</div>' +
            '</div>' +
            // Банковские реквизиты
            '<div style="background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.2);' +
            'border-radius:12px;padding:14px;margin-bottom:16px;">' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">' +
            '🏦 Банковские реквизиты</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            this._reqField('Банк',       'bank_name', client.bank_name || '') +
            this._reqField('БИК',        'bank_bik',  client.bank_bik  || '') +
            this._reqField('Р/С',        'bank_acc',  client.bank_acc  || '') +
            this._reqField('К/С',        'bank_ks',   client.bank_ks   || '') +
            '</div>' +
            '<button class="btn btn-primary btn-sm" style="margin-top:10px;" ' +
            'onclick="_cp(\'_saveBankReqs\',\'' + String(client.id) + '\')">' +
            this._icon('check', 12) + ' Сохранить</button>' +
            '<button class="btn btn-secondary btn-sm" style="margin-top:10px;margin-left:8px;" ' +
            'onclick="_cp(\'_copyAllReqs\',\'' + String(client.id) + '\')">' +
            this._icon('copy', 12) + ' Копировать всё</button>' +
            '</div>' +
            '<div id="cp_dadata_result">' +
            (client.dadata_data ? this._renderDadataResult(client.dadata_data, client.id) : '') +
            '</div>' +
            '</div>';
    },

    _reqField: function(label, field, value) {
        return '<div class="form-group" style="margin-bottom:0;">' +
            '<label class="form-label" style="font-size:0.7rem;">' + label + '</label>' +
            '<input class="form-input" id="cp_req_' + field + '" value="' + _esc(value) + '" ' +
            'placeholder="' + label + '..." style="font-size:0.82rem;">' +
            '</div>';
    },

    _saveBankReqs: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;
        var gv = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
        client.bank_name = gv('cp_req_bank_name');
        client.bank_bik  = gv('cp_req_bank_bik');
        client.bank_acc  = gv('cp_req_bank_acc');
        client.bank_ks   = gv('cp_req_bank_ks');
        CRM.api('clients_pro', 'save', client).then(function(res) {
            if (res && res.ok) {
                var idx = self._clients.findIndex(function(c) { return String(c.id) === String(clientId); });
                if (idx !== -1) self._clients[idx] = Object.assign(self._clients[idx], client);
                notify('Реквизиты сохранены', 'success');
            }
        });
    },

    _copyAllReqs: function(clientId) {
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;
        var lines = [
            client.name       ? 'Наименование: ' + client.name : '',
            client.inn        ? 'ИНН: '  + client.inn  : '',
            client.kpp        ? 'КПП: '  + client.kpp  : '',
            client.ogrn       ? 'ОГРН: ' + client.ogrn : '',
            client.address    ? 'Адрес: ' + client.address : '',
            client.director   ? 'Руководитель: ' + client.director : '',
            client.bank_name  ? 'Банк: ' + client.bank_name : '',
            client.bank_bik   ? 'БИК: '  + client.bank_bik  : '',
            client.bank_acc   ? 'Р/С: '  + client.bank_acc  : '',
            client.bank_ks    ? 'К/С: '  + client.bank_ks   : '',
        ].filter(Boolean).join('\n');
        navigator.clipboard.writeText(lines).then(function() {
            notify('Реквизиты скопированы', 'success');
        });
    },

    _loadTabDadata: function(client) {
        if (client.dadata_data) {
            var result = document.getElementById('cp_dadata_result');
            if (result) result.innerHTML = this._renderDadataResult(client.dadata_data, client.id);
        }
    },

    _searchDadata: function(clientId) {
        var self   = this;
        var input  = document.getElementById('cp_dadata_query');
        var query  = input ? input.value.trim() : '';
        var result = document.getElementById('cp_dadata_result');
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });

        if (!query) { notify('Введите ИНН или ОГРН', 'error'); return; }
        if (!result) return;

        result.innerHTML =
            '<div style="text-align:center;padding:20px;">' +
            '<div class="cp-spinning" style="display:inline-block;">' + this._icon('spinner', 24) + '</div>' +
            '<div style="color:var(--text-muted);font-size:0.8rem;margin-top:8px;">Поиск в DaData...</div>' +
            '</div>';

        CRM.api('clients_pro', 'dadata_find', { query: query }).then(function(res) {
            if (!res || !res.ok) {
                result.innerHTML =
                    '<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);' +
                    'border-radius:10px;padding:14px;color:var(--danger);font-size:0.85rem;">' +
                    '❌ ' + (res.error || 'Ничего не найдено') + '</div>';
                return;
            }
            var data = res.data;
            result.innerHTML = self._renderDadataResult(data, clientId);
            if (client) {
                self._dadataPendingData     = data;
                self._dadataPendingClientId = clientId;
            }
        });
    },

    _renderDadataResult: function(data, clientId) {
        var self = this;
        if (!data) return '';

        var statusColor = data.status_raw === 'ACTIVE'
            ? 'var(--accent3)'
            : (data.status_raw === 'LIQUIDATED' || data.status_raw === 'BANKRUPT')
              ? 'var(--danger)' : 'var(--accent4)';

        var row = function(label, value, mono, copiable) {
            if (!value) return '';
            return '<tr>' +
                '<td style="padding:6px 10px;font-size:0.72rem;color:var(--text-muted);white-space:nowrap;' +
                'border-bottom:1px solid rgba(255,255,255,0.04);">' + label + '</td>' +
                '<td style="padding:6px 10px;font-size:0.82rem;font-weight:600;' +
                'border-bottom:1px solid rgba(255,255,255,0.04);' +
                (mono ? 'font-family:monospace;' : '') + '">' + _esc(String(value)) +
                (copiable
                    ? ' <button style="width:18px;height:18px;border-radius:4px;border:1px solid var(--border);' +
                      'background:var(--bg-dark);color:var(--text-muted);cursor:pointer;vertical-align:middle;" ' +
                      'onclick="navigator.clipboard.writeText(\'' + _esc(String(value)) + '\').then(function(){notify(\'Скопировано\',\'success\')})">' +
                      self._icon('copy', 9) + '</button>'
                    : '') +
                '</td></tr>';
        };

        var financeBlock = '';
        if (data.finance && (data.finance.revenue || data.finance.income)) {
            var f = data.finance;
            financeBlock =
                '<div style="margin-top:12px;background:rgba(16,185,129,0.06);' +
                'border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:12px;">' +
                '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);margin-bottom:8px;">' +
                '💰 Финансы ' + (f.year ? f.year : '') + '</div>' +
                '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">' +
                (f.revenue != null ? '<div style="text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Выручка</div><div style="font-weight:700;color:var(--accent3);">' + Number(f.revenue).toLocaleString('ru-RU') + ' ₽</div></div>' : '') +
                (f.income  != null ? '<div style="text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Доходы</div><div style="font-weight:700;color:var(--accent2);">' + Number(f.income).toLocaleString('ru-RU') + ' ₽</div></div>' : '') +
                (f.expense != null ? '<div style="text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Расходы</div><div style="font-weight:700;color:var(--danger);">' + Number(f.expense).toLocaleString('ru-RU') + ' ₽</div></div>' : '') +
                '</div></div>';
        }

        return '<div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-top:8px;">' +
            '<div style="padding:12px 16px;border-bottom:1px solid var(--border);' +
            'display:flex;align-items:center;justify-content:space-between;">' +
            '<div>' +
            '<div style="font-weight:800;font-size:1rem;">' + _esc(data.name || '') + '</div>' +
            (data.full_name && data.full_name !== data.name ? '<div style="font-size:0.72rem;color:var(--text-muted);">' + _esc(data.full_name) + '</div>' : '') +
            '</div>' +
            '<div style="display:flex;gap:8px;align-items:center;">' +
            '<span style="padding:3px 10px;border-radius:8px;font-size:0.72rem;font-weight:700;' +
            'background:rgba(255,255,255,0.08);color:' + statusColor + ';">' + _esc(data.status || '') + '</span>' +
            '<button class="btn btn-primary btn-sm" onclick="_cp(\'_applyDadata\',\'' + String(clientId) + '\')">' +
            self._icon('refresh', 12) + ' Применить</button>' +
            '</div></div>' +
            '<div style="padding:8px;">' +
            '<table style="width:100%;border-collapse:collapse;">' +
            row('ИНН',          data.inn,      true,  true) +
            row('ОГРН',         data.ogrn,     true,  true) +
            row('КПП',          data.kpp,      true,  true) +
            row('ОКВЭД',        data.okved,    true,  false) +
            row('ОПФ',          data.opf,      false, false) +
            row('Руководитель', data.director, false, false) +
            row('Адрес',        data.address,  false, true)  +
            row('Телефон',      data.phone,    false, true)  +
            row('Email',        data.email,    false, true)  +
            row('Дата рег.',    data.reg_date, false, false) +
            (data.employee_count != null ? row('Сотрудников', data.employee_count, false, false) : '') +
            (data.branch_count  ? row('Филиалов', data.branch_count, false, false) : '') +
            '</table>' +
            financeBlock +
            '</div></div>';
    },

    _applyDadata: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;
        var data = this._dadataPendingData || client.dadata_data;
        if (!data) { notify('Нет данных для применения', 'error'); return; }
        if (data.name    && !client.name)    client.name    = data.name;
        if (data.inn)     client.inn      = data.inn;
        if (data.ogrn)    client.ogrn     = data.ogrn;
        if (data.kpp)     client.kpp      = data.kpp;
        if (data.okved)   client.okved    = data.okved;
        if (data.address) client.address  = data.address;
        if (data.director)client.director = data.director;
        if (data.phone && !client.phone)   client.phone   = data.phone;
        if (data.email && !client.email)   client.email   = data.email;
        if (data.type)    client.type     = data.type;
        client.dadata_data = data;
        if (data.type === 'ИП')           client.bizcat = 'Малый бизнес';
        else if (data.type === 'ООО / ЗАО') client.bizcat = 'Корпоративный';

        CRM.api('clients_pro', 'save', client).then(function(res) {
            if (!res || !res.ok) { notify('Ошибка сохранения', 'error'); return; }
            var idx = self._clients.findIndex(function(c) { return String(c.id) === String(clientId); });
            if (idx !== -1) self._clients[idx] = Object.assign(self._clients[idx], client);
            notify('✅ Реквизиты применены', 'success');
            document.getElementById('cp_detail_modal') && document.getElementById('cp_detail_modal').remove();
            setTimeout(function() { self.openDetail(clientId, 'dadata'); }, 100);
        });
    },

      // ── ТАБ: ДОКУМЕНТЫ (НОВАЯ ВЕРСИЯ — ЧЕРЕЗ API) ────────
    _tabDocs: function(client) {
        var self = this;
        var cid  = String(client.id);
        // Возвращаем заглушку, реальная загрузка происходит в патче
        return '<div id="cp_detail_tab_content">' +
            '<div style="text-align:center;padding:40px;color:#64748b;">📄 Загрузка документов...</div>' +
            '</div>';
    },

    // ── ТАБ: ИСТОРИЯ ────────────────────────────────────────
    _tabHistory: function() {
        return '<div id="cp_history_content"><div class="spinner"></div></div>';
    },
    _renderTabHistory: function(client) {
        var content = document.getElementById('cp_history_content');
        if (!content) return;

        var timeline = (client.timeline || []).slice().reverse();
        var typeIcons = {
            note:    '📝',
            order:   '📦',
            message: '💬',
            call:    '📞',
            status:  '🔄',
            payment: '💰',
        };
        var typeColors = {
            note:    '#6366f1',
            order:   '#10b981',
            message: '#06b6d4',
            call:    '#f59e0b',
            status:  '#a855f7',
            payment: '#10b981',
        };

        if (!timeline.length) {
            content.innerHTML =
                '<div class="empty-state"><div class="icon">' + this._icon('history', 36) + '</div>' +
                '<div class="title">История пуста</div>' +
                '<div class="desc">Все действия по клиенту будут отображаться здесь</div></div>';
            return;
        }

        content.innerHTML =
            '<div style="position:relative;padding-left:24px;">' +
            '<div style="position:absolute;left:10px;top:0;bottom:0;width:2px;' +
            'background:linear-gradient(to bottom,var(--accent),transparent);border-radius:2px;"></div>' +
            timeline.map(function(e) {
                var ico   = typeIcons[e.type]  || '📌';
                var color = typeColors[e.type] || '#94a3b8';
                var dateStr = '';
                if (e.date) {
                    try { dateStr = new Date(e.date).toLocaleString('ru-RU'); } catch(ex) {}
                }
                return '<div style="position:relative;margin-bottom:14px;">' +
                    '<div style="position:absolute;left:-20px;top:4px;width:18px;height:18px;' +
                    'border-radius:50%;background:' + color + '20;border:2px solid ' + color + ';' +
                    'display:flex;align-items:center;justify-content:center;font-size:0.6rem;">' +
                    ico + '</div>' +
                    '<div style="background:var(--bg-dark);border:1px solid var(--border);' +
                    'border-radius:10px;padding:10px 12px;">' +
                    '<div style="font-size:0.82rem;font-weight:600;">' + _esc(e.text || '') + '</div>' +
                    '<div style="font-size:0.65rem;color:var(--text-muted);margin-top:3px;">' +
                    (dateStr ? dateStr + ' · ' : '') + _esc(e.user || '') + '</div>' +
                    '</div></div>';
            }).join('') +
            '</div>';
    },

    // ── МОДАЛКА: ПРИВЯЗКА ВК ────────────────────────────────
    _openVkBindModal: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;

        document.getElementById('cp_vkbind_modal') && document.getElementById('cp_vkbind_modal').remove();

        var html =
            '<div class="modal-overlay" id="cp_vkbind_modal" style="z-index:120000;">' +
            '<div class="modal modal-sm" style="background:#0f172a;border:1px solid #1e293b;max-width:440px;">' +
            '<div class="modal-header">' +
            '<div class="modal-title">' + this._icon('vk', 16) + ' Привязать ВКонтакте</div>' +
            '<button class="modal-close" onclick="document.getElementById(\'cp_vkbind_modal\').remove()">✕</button>' +
            '</div>' +
            '<div style="padding:16px;">' +
            '<div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:12px;">' +
            'Вставьте ссылку на профиль клиента в VK — данные заполнятся автоматически</div>' +
            '<div style="display:flex;gap:8px;">' +
            '<input class="form-input" id="cp_vkbind_url" placeholder="https://vk.com/id123456 или vk.com/username" ' +
            'style="flex:1;" value="' + _esc(client.vk_url || '') + '">' +
            '<button class="btn btn-primary" id="cp_vkbind_btn" onclick="_cp(\'_doVkBind\',\'' + clientId + '\')">' +
            this._icon('search', 13) + ' Найти</button>' +
            '</div>' +
            '<div id="cp_vkbind_result" style="margin-top:12px;"></div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="btn btn-secondary" onclick="document.getElementById(\'cp_vkbind_modal\').remove()">Отмена</button>' +
            '</div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var m = document.getElementById('cp_vkbind_modal');
        m.classList.add('open');
        m.addEventListener('click', function(e) { if (e.target.id === 'cp_vkbind_modal') m.remove(); });
        document.getElementById('cp_vkbind_url').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') _cp('_doVkBind', clientId);
        });
    },

    _doVkBind: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;

        var input  = document.getElementById('cp_vkbind_url');
        var result = document.getElementById('cp_vkbind_result');
        var btn    = document.getElementById('cp_vkbind_btn');
        var url    = input ? input.value.trim() : '';

        if (!url) { notify('Введите ссылку', 'error'); return; }

        if (btn) { btn.disabled = true; btn.innerHTML = self._icon('spinner', 13); }
        if (result) result.innerHTML = '<div style="color:var(--text-muted);font-size:0.78rem;">Поиск...</div>';

        CRM.api('clients_pro', 'vk_lookup', { url: url }).then(function(res) {
            if (btn) { btn.disabled = false; btn.innerHTML = self._icon('search', 13) + ' Найти'; }

            if (!res || !res.ok || !res.data) {
                if (result) result.innerHTML =
                    '<div style="color:var(--danger);font-size:0.78rem;">❌ Пользователь не найден</div>';
                return;
            }

            var u = res.data;

            // Обновляем клиента всеми доступными данными
            client.vk_user_id  = u.vk_user_id || u.id || null;
            client.vk_url      = u.vk_url  || url;
            client.vk_avatar   = u.avatar_big || u.avatar || '';
            client.avatar_url  = client.vk_avatar;
            // Расширенные поля
            if (!client.name  || !client.name.trim())  client.name  = u.name  || client.name;
            if (!client.phone || !client.phone.trim())  client.phone = u.phone || client.phone;
            client.vk_city      = u.city             || '';
            client.vk_bdate     = u.bdate            || '';
            client.vk_sex       = u.sex != null ? u.sex : '';
            client.vk_about     = u.about            || '';
            client.vk_status    = u.status           || '';
            client.vk_followers = u.followers_count  || 0;
            client.vk_last_seen = u.last_seen        ? JSON.stringify(u.last_seen) : '';
            client.vk_site      = u.site             || '';
            client.vk_relation  = u.relation != null ? u.relation : '';

            // Показываем результат
            if (result) {
                result.innerHTML =
                    '<div style="background:rgba(0,119,255,0.1);border:1px solid rgba(0,119,255,0.25);' +
                    'border-radius:10px;padding:10px;display:flex;align-items:center;gap:10px;">' +
                    (u.avatar ? '<img src="' + u.avatar + '" style="width:44px;height:44px;border-radius:10px;object-fit:cover;">' : '') +
                    '<div><div style="font-weight:700;">' + _esc(u.name || '') + '</div>' +
                    '<div style="font-size:0.72rem;color:var(--text-muted);">' +
                    (u.city ? '📍 ' + _esc(u.city) : '') +
                    (u.followers_count ? ' · 👥 ' + Number(u.followers_count).toLocaleString('ru-RU') : '') +
                    '</div></div>' +
                    '<button class="btn btn-primary btn-sm" style="margin-left:auto;" ' +
                    'onclick="_cp(\'_saveVkBind\',\'' + clientId + '\')">' +
                    self._icon('check', 13) + ' Сохранить</button>' +
                    '</div>';
            }
        });
    },

    _saveVkBind: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;

        CRM.api('clients_pro', 'save', client).then(function(res) {
            if (!res || !res.ok) { notify('Ошибка сохранения', 'error'); return; }
            var idx = self._clients.findIndex(function(c) { return String(c.id) === String(clientId); });
            if (idx !== -1) self._clients[idx] = Object.assign(self._clients[idx], client);
            notify('✅ ВКонтакте привязан', 'success');
            document.getElementById('cp_vkbind_modal') && document.getElementById('cp_vkbind_modal').remove();
            // Перезагружаем детальную карточку
            document.getElementById('cp_detail_modal') && document.getElementById('cp_detail_modal').remove();
            setTimeout(function() { self.openDetail(clientId, 'vk'); }, 100);
        });
    },

    // ── МОДАЛКА: НАПИСАТЬ / ФАЙЛ ─────────────────────────────
    openMessageModal: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client) return;

        document.getElementById('cp_msg_modal') && document.getElementById('cp_msg_modal').remove();

        var av  = client.vk_avatar || client.avatar_url || '';
        var cid = String(clientId);

        // Шаблоны
        var templatesHtml = this.MSG_TEMPLATES.map(function(t) {
            return '<button class="btn btn-secondary btn-xs" style="text-align:left;white-space:normal;" ' +
                'onclick="var ta=document.getElementById(\'cp_msg_text\');if(ta)ta.value=' +
                JSON.stringify(t.text) + '">' + t.label + '</button>';
        }).join('');

        var html =
            '<div class="modal-overlay" id="cp_msg_modal" style="z-index:110000;">' +
            '<div class="modal modal-sm" style="background:#0f172a;border:1px solid #1e293b;max-width:500px;">' +
            '<div class="modal-header">' +
            '<div class="modal-title" style="display:flex;align-items:center;gap:10px;">' +
            (av
                ? '<img src="' + av + '" style="width:32px;height:32px;border-radius:8px;object-fit:cover;">'
                : '<div style="width:32px;height:32px;border-radius:8px;background:' + this._nameGradient(client.name) + ';' +
                  'display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;">' +
                  (client.name || '?').charAt(0).toUpperCase() + '</div>') +
            '<div><div style="font-size:0.9rem;">' + _esc(client.name || 'Клиент') + '</div>' +
            '<div style="font-size:0.7rem;color:var(--text-muted);">' + _esc(client.phone || '') + '</div></div>' +
            '</div>' +
            '<button class="modal-close" onclick="document.getElementById(\'cp_msg_modal\').remove()">✕</button>' +
            '</div>' +
            '<div style="padding:16px;">' +
            // Табы
            '<div style="display:flex;gap:4px;background:var(--bg-dark);border-radius:10px;' +
            'padding:4px;border:1px solid var(--border);margin-bottom:14px;">' +
            '<button id="cp_msg_tab_text" class="btn btn-primary btn-sm" style="flex:1;" ' +
            'onclick="_cp(\'_switchMsgTab\',\'text\')">' + this._icon('chat', 13) + ' Сообщение</button>' +
            '<button id="cp_msg_tab_file" class="btn btn-secondary btn-sm" style="flex:1;" ' +
            'onclick="_cp(\'_switchMsgTab\',\'file\')">' + this._icon('file', 13) + ' Файл</button>' +
            '</div>' +
            // Раздел текст
            '<div id="cp_msg_section_text">' +
            '<div class="form-group">' +
            '<label class="form-label">Текст сообщения</label>' +
            '<textarea class="form-textarea" id="cp_msg_text" rows="4" ' +
            'placeholder="Введите сообщение..." ' +
            'onkeydown="if(event.key===\'Enter\'&&event.ctrlKey)_cp(\'_doSendMessage\',\'' + cid + '\')"></textarea>' +
            '</div>' +
            '<div style="margin-bottom:10px;">' +
            '<div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:6px;">Быстрые шаблоны:</div>' +
            '<div style="display:flex;gap:4px;flex-wrap:wrap;">' + templatesHtml + '</div>' +
            '</div>' +
            '<div style="font-size:0.72rem;color:var(--text-muted);">📱 Ctrl+Enter — отправить</div>' +
            '</div>' +
            // Раздел файл
            '<div id="cp_msg_section_file" style="display:none;">' +
            '<div class="form-group">' +
            '<label class="form-label">Текст к файлу</label>' +
            '<input class="form-input" id="cp_msg_file_text" placeholder="Ваш заказ готов!">' +
            '</div>' +
            '<div id="cp_msg_file_drop" style="border:2px dashed var(--border);border-radius:12px;' +
            'padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:10px;" ' +
            'onclick="document.getElementById(\'cp_msg_file_input\').click()" ' +
            'ondragover="event.preventDefault();this.style.borderColor=\'var(--accent)\'" ' +
            'ondragleave="this.style.borderColor=\'var(--border)\'" ' +
            'ondrop="_cp(\'_handleFileDrop\',event,\'' + cid + '\')">' +
            '<div style="margin-bottom:8px;">' + this._icon('upload', 28) + '</div>' +
            '<div style="font-weight:600;">Перетащите файл или нажмите</div>' +
            '<input type="file" id="cp_msg_file_input" style="display:none;" ' +
            'onchange="_cp(\'_onMsgFileSelected\',this,\'' + cid + '\')">' +
            '</div>' +
            '<div id="cp_msg_file_preview" style="display:none;">' +
            '<div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:10px;' +
            'padding:10px;display:flex;align-items:center;gap:10px;margin-bottom:10px;">' +
            '<div style="font-size:1.4rem;">📎</div>' +
            '<div style="flex:1;"><div id="cp_msg_file_name" style="font-weight:600;font-size:0.85rem;"></div>' +
            '<div id="cp_msg_file_size" style="font-size:0.7rem;color:var(--text-muted);"></div></div>' +
            '<button style="width:24px;height:24px;border-radius:6px;border:none;' +
            'background:rgba(239,68,68,0.2);color:var(--danger);cursor:pointer;" ' +
            'onclick="_cp(\'_clearMsgFile\')">✕</button>' +
            '</div></div>' +
            '<div id="cp_msg_file_progress" style="display:none;">' +
            '<div style="background:var(--bg-dark);border-radius:4px;overflow:hidden;height:6px;">' +
            '<div id="cp_msg_file_bar" style="height:100%;background:var(--accent);width:0;transition:width 0.2s;border-radius:4px;"></div>' +
            '</div>' +
            '<div id="cp_msg_file_status" style="font-size:0.7rem;color:var(--text-muted);margin-top:4px;text-align:center;">Загрузка...</div>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="btn btn-secondary" onclick="document.getElementById(\'cp_msg_modal\').remove()">Отмена</button>' +
            '<button class="btn btn-primary" id="cp_msg_send_btn" ' +
            'onclick="_cp(\'_doSendMessage\',\'' + cid + '\')">' +
            this._icon('send', 13) + ' Отправить</button>' +
            '</div>' +
            '</div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var modal = document.getElementById('cp_msg_modal');
        modal.classList.add('open');
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'cp_msg_modal') modal.remove();
        });

        this._msgCurrentTab   = 'text';
        this._msgUploadedFile = null;
        this._msgClientId     = cid;

        // Автофокус
        setTimeout(function() {
            var ta = document.getElementById('cp_msg_text');
            if (ta) ta.focus();
        }, 50);
    },

    _switchMsgTab: function(tab) {
        this._msgCurrentTab = tab;
        var show = tab === 'text' ? 'cp_msg_section_text' : 'cp_msg_section_file';
        var hide = tab === 'text' ? 'cp_msg_section_file' : 'cp_msg_section_text';
        var activeBtn = tab === 'text' ? 'cp_msg_tab_text' : 'cp_msg_tab_file';
        var inactBtn  = tab === 'text' ? 'cp_msg_tab_file' : 'cp_msg_tab_text';
        var showEl = document.getElementById(show);
        var hideEl = document.getElementById(hide);
        var activeEl = document.getElementById(activeBtn);
        var inactEl  = document.getElementById(inactBtn);
        if (showEl) showEl.style.display = 'block';
        if (hideEl) hideEl.style.display = 'none';
        if (activeEl) { activeEl.className = 'btn btn-primary btn-sm'; activeEl.style.flex = '1'; }
        if (inactEl)  { inactEl.className  = 'btn btn-secondary btn-sm'; inactEl.style.flex = '1'; }
    },

    _handleFileDrop: function(event, clientId) {
        event.preventDefault();
        var drop = document.getElementById('cp_msg_file_drop');
        if (drop) drop.style.borderColor = 'var(--border)';
        var files = event.dataTransfer && event.dataTransfer.files;
        if (files && files[0]) this._uploadMsgFile(files[0], clientId);
    },

    _onMsgFileSelected: function(input, clientId) {
        var file = input.files && input.files[0];
        if (file) this._uploadMsgFile(file, clientId);
    },

    _uploadMsgFile: function(file, clientId) {
        var self     = this;
        var preview  = document.getElementById('cp_msg_file_preview');
        var progress = document.getElementById('cp_msg_file_progress');
        var bar      = document.getElementById('cp_msg_file_bar');
        var status   = document.getElementById('cp_msg_file_status');
        var nameEl   = document.getElementById('cp_msg_file_name');
        var sizeEl   = document.getElementById('cp_msg_file_size');
        var drop     = document.getElementById('cp_msg_file_drop');
        var sendBtn  = document.getElementById('cp_msg_send_btn');

        if (!file) return;
        if (file.size > 50 * 1024 * 1024) { notify('Файл > 50 МБ', 'error'); return; }

        var sizeKB = Math.round(file.size / 1024);
        if (drop)     drop.style.display     = 'none';
        if (progress) progress.style.display = 'block';
        if (preview)  preview.style.display  = 'none';
        if (sendBtn)  sendBtn.disabled        = true;

        var apiUrl = window.API_URL || (typeof API_URL !== 'undefined' ? API_URL : '/api/api.php');
        var apiKey = window.API_KEY || (typeof API_KEY !== 'undefined' ? API_KEY : '12345');
        var fd     = new FormData();
        fd.append('file', file, file.name);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl + '?action=upload&key=' + apiKey);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable && bar && status) {
                var pct = Math.round(e.loaded / e.total * 100);
                bar.style.width = pct + '%';
                status.textContent = 'Загрузка... ' + pct + '%';
            }
        };

        xhr.onload = function() {
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch(e) {}
            var url = data.url || data.file_url || '';
            if (url) {
                self._msgUploadedFile = { url: url, name: file.name, size: file.size };
                if (progress) progress.style.display = 'none';
                if (preview) {
                    preview.style.display = 'block';
                    if (nameEl) nameEl.textContent = file.name;
                    if (sizeEl) sizeEl.textContent = sizeKB + ' КБ';
                }
                if (sendBtn) sendBtn.disabled = false;
                notify('Файл загружен', 'success');
            } else {
                if (drop)     drop.style.display     = 'block';
                if (progress) progress.style.display = 'none';
                if (sendBtn)  sendBtn.disabled        = false;
                notify('Ошибка загрузки файла', 'error');
            }
        };

        xhr.onerror = function() {
            if (drop)     drop.style.display     = 'block';
            if (progress) progress.style.display = 'none';
            if (sendBtn)  sendBtn.disabled        = false;
            notify('Ошибка сети', 'error');
        };

        xhr.send(fd);
    },

    _clearMsgFile: function() {
        this._msgUploadedFile = null;
        var drop    = document.getElementById('cp_msg_file_drop');
        var preview = document.getElementById('cp_msg_file_preview');
        if (drop)    drop.style.display    = 'block';
        if (preview) preview.style.display = 'none';
        var input = document.getElementById('cp_msg_file_input');
        if (input) input.value = '';
    },

    // ── ОТПРАВИТЬ СООБЩЕНИЕ ────────────────────────────────────
    _doSendMessage: function(clientId) {
        var self   = this;
        var client = this._clients.find(function(c) { return String(c.id) === String(clientId); });
        if (!client || !client.phone) {
            notify('У клиента нет телефона', 'error');
            return;
        }

        var tab    = this._msgCurrentTab || 'text';
        var btn    = document.getElementById('cp_msg_send_btn');
        var iconSend = self._icon('send', 13);

        if (tab === 'text') {
            var textEl = document.getElementById('cp_msg_text');
            var text   = textEl ? textEl.value.trim() : '';
            if (!text) { notify('Введите текст сообщения', 'error'); return; }

            if (btn) {
                btn.disabled   = true;
                btn.textContent = 'Отправка...';
            }

            CRM.api('clients_pro', 'send_message', {
                phone: client.phone,
                text:  text,
            }).then(function(res) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = iconSend + ' Отправить';
                }
                if (res && res.ok) {
                    notify('✅ Сообщение отправлено', 'success');
                    var modal = document.getElementById('cp_msg_modal');
                    if (modal) modal.remove();
                } else {
                    notify('❌ ' + (res.error || 'Не удалось отправить'), 'error');
                }
            }).catch(function() {
                if (btn) { btn.disabled = false; btn.innerHTML = iconSend + ' Отправить'; }
                notify('❌ Ошибка сети', 'error');
            });

        } else {
            if (!this._msgUploadedFile) {
                notify('Сначала загрузите файл', 'error');
                return;
            }
            var fileTextEl = document.getElementById('cp_msg_file_text');
            var fileText   = fileTextEl ? fileTextEl.value.trim() : '';
            var fileName   = this._msgUploadedFile.name || '';

            if (btn) {
                btn.disabled   = true;
                btn.textContent = 'Отправка...';
            }

            CRM.api('clients_pro', 'send_file', {
                phone:     client.phone,
                text:      fileText,
                file_name: fileName,
            }).then(function(res) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = iconSend + ' Отправить';
                }
                if (res && res.ok) {
                    notify('✅ Файл отправлен', 'success');
                    var modal = document.getElementById('cp_msg_modal');
                    if (modal) modal.remove();
                } else {
                    notify('❌ ' + (res.error || 'Ошибка отправки'), 'error');
                }
            }).catch(function() {
                if (btn) { btn.disabled = false; btn.innerHTML = iconSend + ' Отправить'; }
                notify('❌ Ошибка сети', 'error');
            });
        }
    },

    // ── МОДАЛКА СОЗДАНИЯ / РЕДАКТИРОВАНИЯ ───────────────────
    openClientModal: function(id) {
        var self    = this;
        this._editId = id || null;
        var isEdit   = !!id;
        var client   = isEdit
            ? this._clients.find(function(c) { return String(c.id) === String(id); })
            : null;
        var cid      = client ? String(client.id) : '';

        document.getElementById('cp_client_modal') && document.getElementById('cp_client_modal').remove();

        var tagsHtml = this.TAGS.map(function(tag) {
            var sel = client && (client.tags || []).includes(tag);
            return '<label style="display:flex;align-items:center;gap:6px;' +
                'padding:5px 10px;background:' + (sel ? 'rgba(124,58,237,0.15)' : 'var(--bg-dark)') + ';' +
                'border-radius:8px;border:1px solid ' + (sel ? 'var(--accent)' : 'var(--border)') + ';' +
                'cursor:pointer;font-size:0.78rem;transition:all 0.2s;" ' +
                'onclick="var cb=this.querySelector(\'input\');cb.checked=!cb.checked;' +
                'this.style.borderColor=cb.checked?\'var(--accent)\':\'var(--border)\';' +
                'this.style.background=cb.checked?\'rgba(124,58,237,0.15)\':\'var(--bg-dark)\'">' +
                '<input type="checkbox" ' + (sel ? 'checked' : '') + ' ' +
                'name="cp_tag" value="' + _esc(tag) + '" style="display:none;">' +
                _esc(tag) + '</label>';
        }).join('');

        var statusOpts = this.CRM_STATUSES.map(function(s) {
            return '<option value="' + s + '" ' +
                ((client && client.crm_status === s) || (s === 'new' && !client) ? 'selected' : '') + '>' +
                self.CRM_STATUS_LABELS[s] + '</option>';
        }).join('');

        var html =
            '<div class="modal-overlay" id="cp_client_modal" style="z-index:100000;">' +
            '<div class="modal" style="background:#0f172a;border:1px solid #1e293b;max-width:660px;width:95vw;">' +
            '<div class="modal-header">' +
            '<div class="modal-title">' + this._icon('user', 16) + ' ' +
            (isEdit ? 'Редактировать клиента' : 'Новый клиент') + '</div>' +
            '<button class="modal-close" onclick="document.getElementById(\'cp_client_modal\').remove()">✕</button>' +
            '</div>' +
            '<div style="padding:16px;max-height:78vh;overflow-y:auto;">' +

            // ── ВК ────────────────────────────────────────────────────
            '<div style="background:rgba(0,119,255,0.06);border:1px solid rgba(0,119,255,0.2);' +
            'border-radius:12px;padding:14px;margin-bottom:14px;">' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">' +
            this._icon('vk', 12) + ' ВКонтакте — автозаполнение данных</div>' +
            '<div style="display:flex;gap:8px;margin-bottom:8px;">' +
            '<input class="form-input" id="cp_vk_sync_url" ' +
            'value="' + _esc((client && client.vk_url) || '') + '" ' +
            'placeholder="https://vk.com/username или vk.com/id123..." style="flex:1;">' +
            '<button class="btn btn-primary" id="cp_vk_sync_btn" onclick="_cp(\'_syncVkProfile\')">' +
            this._icon('refresh', 13) + ' Синхронизировать</button>' +
            '</div>' +
            '<div id="cp_vk_sync_result">' +
            (client && client.vk_user_id
                ? '<div style="display:flex;align-items:center;gap:8px;padding:8px;' +
                  'background:rgba(0,119,255,0.1);border-radius:8px;">' +
                  (client.vk_avatar ? '<img src="' + client.vk_avatar + '" style="width:32px;height:32px;border-radius:8px;object-fit:cover;">' : '') +
                  '<div style="flex:1;font-size:0.78rem;"><span style="color:var(--accent3);font-weight:700;">✓ ВК привязан</span>' +
                  (client.vk_city ? ' · 📍 ' + _esc(client.vk_city) : '') +
                  (client.vk_url ? '<br><a href="' + client.vk_url + '" target="_blank" style="color:#6ab4ff;font-size:0.7rem;">' + client.vk_url + '</a>' : '') +
                  '</div></div>'
                : '<div style="font-size:0.68rem;color:var(--text-muted);">Вставьте ссылку — имя, фото, город подтянутся автоматически</div>') +
            '</div>' +
            '</div>' +

            // ── DaData ────────────────────────────────────────────────
            '<div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);' +
            'border-radius:12px;padding:14px;margin-bottom:14px;">' +
            '<div style="font-size:0.72rem;font-weight:700;color:var(--text-muted);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">' +
            this._icon('building', 12) + ' Реквизиты по ИНН / ОГРН</div>' +
            '<div style="display:flex;gap:8px;">' +
            '<input class="form-input" id="cp_dadata_modal_query" ' +
            'value="' + _esc((client && (client.inn || client.ogrn)) || '') + '" ' +
            'placeholder="ИНН или ОГРН..." style="flex:1;" ' +
            'onkeydown="if(event.key===\'Enter\')_cp(\'_dadataFillModal\')">' +
            '<button class="btn btn-secondary" onclick="_cp(\'_dadataFillModal\')">' +
            this._icon('search', 13) + ' Найти</button>' +
            '</div>' +
            '<div id="cp_dadata_modal_result" style="margin-top:8px;"></div>' +
            '</div>' +

            // ── Аватар ────────────────────────────────────────────────
            '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">' +
            '<div style="width:60px;height:60px;border-radius:14px;overflow:hidden;' +
            'border:2px dashed var(--border);background:var(--bg-dark);' +
            'display:flex;align-items:center;justify-content:center;flex-shrink:0;" id="cp_avatar_preview">' +
            (client && (client.vk_avatar || client.avatar_url)
                ? '<img src="' + (client.vk_avatar || client.avatar_url) + '" style="width:100%;height:100%;object-fit:cover;">'
                : '<span style="color:var(--text-muted);">' + this._icon('user', 20) + '</span>') +
            '</div>' +
            '<div>' +
            '<div style="font-size:0.75rem;font-weight:700;color:var(--text-muted);margin-bottom:6px;">Фото</div>' +
            '<label class="btn btn-secondary btn-xs" style="cursor:pointer;">' +
            this._icon('upload', 12) + ' Загрузить' +
            '<input type="file" accept="image/*" style="display:none;" ' +
            'onchange="_cp(\'_uploadClientAvatar\',this,' + (isEdit ? '\'' + cid + '\'' : 'null') + ')">' +
            '</label>' +
            '<div style="font-size:0.65rem;color:var(--text-muted);margin-top:4px;">или подтянется из ВК</div>' +
            '</div>' +
            '<input type="hidden" id="cp_avatar_url" value="' + _esc((client && (client.vk_avatar || client.avatar_url)) || '') + '">' +
            '<input type="hidden" id="cp_vk_user_id_hidden" value="' + _esc(String((client && client.vk_user_id) || '')) + '">' +
            '<input type="hidden" id="cp_vk_url_hidden" value="' + _esc((client && client.vk_url) || '') + '">' +
            '</div>' +

            // ── Основные поля ─────────────────────────────────────────
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">Имя / Название *</label>' +
            '<input class="form-input" id="cp_name" value="' + _esc((client && client.name) || '') + '" placeholder="Иван Иванов"></div>' +
            '<div class="form-group"><label class="form-label">Тип</label>' +
            '<select class="form-select" id="cp_type">' +
            ['Физическое лицо','ИП','ООО / ЗАО','Государственная структура'].map(function(t) {
                return '<option ' + (client && client.type === t ? 'selected' : '') + '>' + t + '</option>';
            }).join('') + '</select></div></div>' +

            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">Телефон</label>' +
            '<input class="form-input" id="cp_phone" value="' + _esc((client && client.phone) || '') + '" placeholder="+7 (___) ___-__-__"></div>' +
            '<div class="form-group"><label class="form-label">Email</label>' +
            '<input class="form-input" id="cp_email" value="' + _esc((client && client.email) || '') + '" placeholder="email@mail.ru"></div>' +
            '</div>' +

            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">Категория</label>' +
            '<select class="form-select" id="cp_bizcat">' +
            ['Частный клиент','Малый бизнес','Корпоративный','Государственный','Образование','Медицина','Ивент','Строительство','Торговля','Другое'].map(function(b) {
                return '<option ' + (client && client.bizcat === b ? 'selected' : '') + '>' + b + '</option>';
            }).join('') + '</select></div>' +
            '<div class="form-group"><label class="form-label">Статус CRM</label>' +
            '<select class="form-select" id="cp_crm_status">' + statusOpts + '</select></div>' +
            '</div>' +

            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">Адрес</label>' +
            '<input class="form-input" id="cp_address" value="' + _esc((client && client.address) || '') + '" placeholder="г. Москва..."></div>' +
            '<div class="form-group"><label class="form-label">ИНН</label>' +
            '<input class="form-input" id="cp_inn" value="' + _esc((client && client.inn) || '') + '" placeholder="7700000000"></div>' +
            '</div>' +

            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">ОГРН</label>' +
            '<input class="form-input" id="cp_ogrn" value="' + _esc((client && client.ogrn) || '') + '" placeholder="1027700132195"></div>' +
            '<div class="form-group"><label class="form-label">КПП</label>' +
            '<input class="form-input" id="cp_kpp" value="' + _esc((client && client.kpp) || '') + '" placeholder="770901001"></div>' +
            '</div>' +

            '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;margin-bottom:12px;">' +
            '<div class="form-group"><label class="form-label">Руководитель / ФИО</label>' +
            '<input class="form-input" id="cp_director" value="' + _esc((client && client.director) || '') + '" placeholder="Иванов Иван Иванович"></div>' +
            '<div class="form-group"><label class="form-label">Скидка %</label>' +
            '<input class="form-input" type="number" id="cp_discount" value="' + ((client && client.discount) || 0) + '" min="0" max="100" style="width:80px;"></div>' +
            '</div>' +

            '<div class="form-group" style="margin-bottom:12px;">' +
            '<label class="form-label">' + this._icon('tag', 12) + ' Теги</label>' +
            '<div style="display:flex;flex-wrap:wrap;gap:6px;">' + tagsHtml + '</div>' +
            '</div>' +

            '<div class="form-group"><label class="form-label">Заметки</label>' +
            '<textarea class="form-textarea" id="cp_notes" rows="2" placeholder="Предпочтения, особенности...">' +
            _esc((client && client.notes) || '') + '</textarea></div>' +

            '</div>' +
            '<div class="modal-footer">' +
            '<button class="btn btn-secondary" onclick="document.getElementById(\'cp_client_modal\').remove()">Отмена</button>' +
            (isEdit ? '<button class="btn btn-danger btn-sm" onclick="_cp(\'_deleteClient\',\'' + cid + '\')">🗑️ Удалить</button>' : '') +
            '<button class="btn btn-primary" onclick="_cp(\'_saveClient\')">💾 Сохранить</button>' +
            '</div>' +
            '</div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var modal = document.getElementById('cp_client_modal');
        modal.classList.add('open');
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'cp_client_modal') modal.remove();
        });
    },

    // ── ВК СИНХРОНИЗАЦИЯ В МОДАЛКЕ ──────────────────────────
    _syncVkProfile: function() {
        var self   = this;
        var input  = document.getElementById('cp_vk_sync_url');
        var result = document.getElementById('cp_vk_sync_result');
        var url    = input ? input.value.trim() : '';
        if (!url) { notify('Введите ссылку ВК', 'error'); return; }

        var btn = document.getElementById('cp_vk_sync_btn');
        if (btn) { btn.disabled = true; btn.innerHTML = self._icon('spinner', 13) + ' Загрузка...'; }
        if (result) result.innerHTML = '<div style="color:var(--text-muted);font-size:0.78rem;">Поиск...</div>';

        CRM.api('clients_pro', 'vk_lookup', { url: url }).then(function(res) {
            if (btn) { btn.disabled = false; btn.innerHTML = self._icon('refresh', 13) + ' Синхронизировать'; }

            if (!res || !res.ok || !res.data) {
                if (result) result.innerHTML = '<div style="color:var(--danger);font-size:0.78rem;">❌ Не найден</div>';
                return;
            }

            var u = res.data;
            var sv = function(id, val) {
                var el = document.getElementById(id);
                if (el && val !== undefined && val !== null && String(val).trim()) el.value = val;
            };

            // Заполняем поля — НЕ затираем уже заполненные
            var nameEl  = document.getElementById('cp_name');
            var phoneEl = document.getElementById('cp_phone');
            if (nameEl  && !nameEl.value.trim())  nameEl.value  = u.name  || '';
            if (phoneEl && !phoneEl.value.trim())  phoneEl.value = u.phone || '';

            sv('cp_vk_user_id_hidden', String(u.vk_user_id || u.id || ''));
            sv('cp_vk_url_hidden',     u.vk_url || url);
            sv('cp_avatar_url',        u.avatar_big || u.avatar || '');

            // Превью аватара
            var preview = document.getElementById('cp_avatar_preview');
            if (preview && (u.avatar_big || u.avatar)) {
                preview.innerHTML = '<img src="' + (u.avatar_big || u.avatar) + '" style="width:100%;height:100%;object-fit:cover;">';
            }

            if (result) {
                result.innerHTML =
                    '<div style="display:flex;align-items:center;gap:8px;' +
                    'background:rgba(0,119,255,0.1);border:1px solid rgba(0,119,255,0.25);' +
                    'border-radius:8px;padding:8px;">' +
                    (u.avatar ? '<img src="' + u.avatar + '" style="width:36px;height:36px;border-radius:8px;object-fit:cover;">' : '') +
                    '<div style="flex:1;">' +
                    '<div style="font-weight:700;font-size:0.85rem;">✓ ' + _esc(u.name || '') + '</div>' +
                    '<div style="font-size:0.72rem;color:var(--text-muted);">' +
                    (u.city     ? '📍 ' + _esc(u.city) : '') +
                    (u.bdate    ? '  🎂 ' + _esc(u.bdate) : '') +
                    (u.followers_count ? '  👥 ' + Number(u.followers_count).toLocaleString('ru-RU') : '') +
                    '</div>' +
                    (u.vk_url ? '<a href="' + u.vk_url + '" target="_blank" style="font-size:0.68rem;color:#6ab4ff;">' + u.vk_url + '</a>' : '') +
                    '</div></div>';
            }
            notify('✅ Данные ВКонтакте загружены', 'success');
        });
    },

    // ── DaData в модалке ─────────────────────────────────────
    _dadataFillModal: function() {
        var self   = this;
        var input  = document.getElementById('cp_dadata_modal_query');
        var result = document.getElementById('cp_dadata_modal_result');
        var query  = input ? input.value.trim() : '';
        if (!query) { notify('Введите ИНН или ОГРН', 'error'); return; }
        if (result) result.innerHTML = '<div style="color:var(--text-muted);font-size:0.78rem;">Поиск...</div>';

        CRM.api('clients_pro', 'dadata_find', { query: query }).then(function(res) {
            if (!res || !res.ok) {
                if (result) result.innerHTML = '<div style="color:var(--danger);font-size:0.78rem;">❌ ' + (res.error || 'Не найдено') + '</div>';
                return;
            }
            var d  = res.data;
            var sv = function(id, val) { var el = document.getElementById(id); if (el && val) el.value = val; };
            sv('cp_name',     d.name);
            sv('cp_address',  d.address);
            sv('cp_inn',      d.inn);
            sv('cp_ogrn',     d.ogrn);
            sv('cp_kpp',      d.kpp);
            sv('cp_director', d.director);
            if (d.phone) { var ph = document.getElementById('cp_phone'); if (ph && !ph.value) ph.value = d.phone; }
            if (d.email) { var em = document.getElementById('cp_email'); if (em && !em.value) em.value = d.email; }
            var typeEl = document.getElementById('cp_type');
            if (typeEl && d.type) {
                for (var i = 0; i < typeEl.options.length; i++) {
                    if (typeEl.options[i].value === d.type) { typeEl.selectedIndex = i; break; }
                }
            }
            if (result) result.innerHTML =
                '<div style="background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);' +
                'border-radius:8px;padding:8px;font-size:0.78rem;">' +
                '<div style="font-weight:700;color:var(--accent2);">✓ Данные заполнены</div>' +
                '<div style="color:var(--text-muted);">' + _esc(d.name || '') + '</div>' +
                '<div style="color:var(--text-muted);font-size:0.7rem;">' +
                (d.inn ? 'ИНН: ' + d.inn : '') + (d.ogrn ? ' · ОГРН: ' + d.ogrn : '') +
                ' · ' + _esc(d.status || '') + '</div></div>';
            notify('✅ Реквизиты заполнены', 'success');
        });
    },

    // ── ЗАГРУЗКА АВАТАРА ────────────────────────────────────
    _uploadClientAvatar: function(input, clientId) {
        var file = input.files && input.files[0];
        if (!file) return;
        var preview = document.getElementById('cp_avatar_preview');
        if (preview) {
            var objUrl = URL.createObjectURL(file);
            preview.innerHTML = '<img src="' + objUrl + '" style="width:100%;height:100%;object-fit:cover;">';
        }
        var fd     = new FormData();
        var apiUrl = window.API_URL || (typeof API_URL !== 'undefined' ? API_URL : '/api/api.php');
        var apiKey = window.API_KEY || (typeof API_KEY !== 'undefined' ? API_KEY : '12345');
        fd.append('file', file, file.name);
        fetch(apiUrl + '?action=upload&key=' + apiKey, { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var url = data.url || data.file_url || '';
                if (url) {
                    var h = document.getElementById('cp_avatar_url');
                    if (h) h.value = url;
                    notify('Фото загружено', 'success');
                }
            });
    },

    // ── СОХРАНИТЬ КЛИЕНТА ────────────────────────────────────
    _saveClient: function() {
        var self = this;
        var name = (document.getElementById('cp_name') || {}).value;
        name = name ? name.trim() : '';
        if (!name) { notify('Введите имя клиента', 'error'); return; }

        var tags = [];
        document.querySelectorAll('input[name="cp_tag"]:checked').forEach(function(cb) {
            tags.push(cb.value);
        });

        var gv = function(id) {
            var el = document.getElementById(id);
            if (!el) return '';
            if (el.tagName === 'SELECT') return el.options[el.selectedIndex] ? (el.options[el.selectedIndex].value || el.value) : el.value;
            return el.value.trim();
        };

        var avatarUrl = gv('cp_avatar_url');
        var vkUserId  = gv('cp_vk_user_id_hidden');
        var vkUrl     = gv('cp_vk_url_hidden');

        var clientData = {
            name:       name,
            phone:      gv('cp_phone'),
            email:      gv('cp_email'),
            type:       gv('cp_type'),
            bizcat:     gv('cp_bizcat'),
            crm_status: gv('cp_crm_status'),
            address:    gv('cp_address'),
            inn:        gv('cp_inn'),
            ogrn:       gv('cp_ogrn'),
            kpp:        gv('cp_kpp'),
            director:   gv('cp_director'),
            discount:   parseInt(gv('cp_discount')) || 0,
            notes:      gv('cp_notes'),
            avatar_url: avatarUrl,
            vk_user_id: vkUserId || null,
            vk_url:     vkUrl,
            vk_avatar:  (avatarUrl && vkUserId) ? avatarUrl : '',
            tags:       tags,
        };

        if (this._editId) {
            var existing = this._clients.find(function(c) { return String(c.id) === String(self._editId); });
            if (existing) {
                // Сохраняем расширенные ВК-данные если они уже были
                clientData = Object.assign({}, existing, clientData, { id: existing.id });
            }
        }

        CRM.api('clients_pro', 'save', clientData).then(function(res) {
            if (!res || !res.ok) { notify('Ошибка сохранения', 'error'); return; }
            if (self._editId) {
                var idx = self._clients.findIndex(function(c) { return String(c.id) === String(self._editId); });
                if (idx !== -1) self._clients[idx] = res.data;
                else self._clients.unshift(res.data);
                notify('Клиент обновлён', 'success');
            } else {
                self._clients.unshift(res.data);
                notify('Клиент добавлен', 'success');
            }
            var cm = document.getElementById('cp_client_modal');
            var dm = document.getElementById('cp_detail_modal');
            if (cm) cm.remove();
            if (dm) dm.remove();
            self._initTagFilter();
            self._filterAndRender();
        });
    },

    // ── УДАЛЕНИЕ КЛИЕНТА (НОВАЯ ИСПРАВЛЕННАЯ ФУНКЦИЯ) ──────
    _deleteClient: function(id) {
        var self = this;
        if (!confirm('Вы уверены, что хотите удалить этого клиента? Это действие необратимо, и все его заказы также будут удалены из базы.')) return;

        CRM.api('clients_pro', 'delete', null, { id: id })
            .then(function(res) {
                if (res && res.ok) {
                    // Убираем клиента из памяти
                    self._clients = self._clients.filter(function(c) { return String(c.id) !== String(id); });
                    
                    // Закрываем открытые модалки
                    var cm = document.getElementById('cp_client_modal');
                    var dm = document.getElementById('cp_detail_modal');
                    if (cm) cm.remove();
                    if (dm) dm.remove();

                    // Перерисовываем список
                    self._filterAndRender();
                    
                    notify('Клиент и его заказы успешно удалены', 'success');
                } else {
                    notify('Ошибка при удалении: ' + (res.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(function(err) {
                notify('Ошибка сети при удалении', 'error');
                console.error(err);
            });
    },

    // ── СТАТУС ───────────────────────────────────────────────
    _changeClientStatus: function(id, newStatus) {
        var self = this;
        CRM.api('clients_pro', 'status', { id: id, status: newStatus }).then(function(res) {
            if (!res || !res.ok) { notify('Ошибка', 'error'); return; }
            var idx = self._clients.findIndex(function(c) { return String(c.id) === String(id); });
            if (idx !== -1) self._clients[idx].crm_status = newStatus;
            notify('Статус: ' + self.CRM_STATUS_LABELS[newStatus], 'success');
            // Обновляем статус-бейджи без перезакрытия модалки
            document.querySelectorAll('[id^="cp_tab_btn_"]').length || self._filterAndRender();
            self._filterAndRender();
        });
    },

    // ── ЗАМЕТКА ──────────────────────────────────────────────
    _addNote: function(clientId) {
        var self = this;
        var el   = document.getElementById('cp_note_text');
        var text = el ? el.value.trim() : '';
        if (!text) return;
        CRM.api('clients_pro', 'add_note', {
            client_id: clientId,
            text:      text,
            user:      (CRM.getSettings && CRM.getSettings().signatory) || 'Менеджер',
        }).then(function(res) {
            if (res && res.ok) {
                if (el) el.value = '';
                notify('Заметка добавлена', 'success');
                var tl = document.getElementById('cp_timeline');
                if (tl) {
                    tl.insertAdjacentHTML('afterbegin',
                        '<div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.05);">' +
                        '<div style="width:6px;height:6px;border-radius:50%;background:var(--accent2);flex-shrink:0;margin-top:6px;"></div>' +
                        '<div><div style="font-size:0.78rem;">' + _esc(text) + '</div>' +
                        '<div style="font-size:0.65rem;color:var(--text-muted);">' +
                        new Date().toLocaleString('ru-RU') + ' · Менеджер</div></div></div>'
                    );
                }
                var client = self._clients.find(function(c) { return String(c.id) === String(clientId); });
                if (client) {
                    if (!client.timeline) client.timeline = [];
                    client.timeline.push({ type:'note', text:text, date:new Date().toISOString(), user:'Менеджер' });
                }
            }
        });
    },

    // ── НАСТРОЙКИ ────────────────────────────────────────────
    showSettings: function() {
        var self = this;
        document.getElementById('cp_settings_modal') && document.getElementById('cp_settings_modal').remove();
        var s = this._settings;

        var html =
            '<div class="modal-overlay" id="cp_settings_modal" style="z-index:100000;">' +
            '<div class="modal modal-sm" style="background:#0f172a;border:1px solid #1e293b;">' +
            '<div class="modal-header">' +
            '<div class="modal-title">' + this._icon('settings', 16) + ' Настройки Клиенты PRO</div>' +
            '<button class="modal-close" onclick="document.getElementById(\'cp_settings_modal\').remove()">✕</button>' +
            '</div>' +
            '<div style="padding:16px;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;' +
            'padding:12px;background:var(--bg-dark);border-radius:10px;border:1px solid var(--border);margin-bottom:12px;">' +
            '<div><div style="font-weight:700;">Модуль включён</div></div>' +
            '<div class="toggle-switch ' + (s.enabled ? 'on' : '') + '" id="cp_set_enabled" ' +
            'onclick="this.classList.toggle(\'on\');this.querySelector(\'.toggle-thumb\').style.left=this.classList.contains(\'on\')?\'23px\':\'3px\'">' +
            '<div class="toggle-thumb" style="left:' + (s.enabled ? '23' : '3') + 'px;"></div>' +
            '</div></div>' +
            '<div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);' +
            'border-radius:10px;padding:12px;margin-bottom:12px;">' +
            '<div style="font-size:0.75rem;font-weight:700;color:var(--text-muted);margin-bottom:10px;">DaData API</div>' +
            '<div class="form-group" style="margin-bottom:8px;"><label class="form-label" style="font-size:0.72rem;">API-ключ</label>' +
            '<input class="form-input" id="cp_set_dadata_key" style="font-family:monospace;font-size:0.75rem;" value="' + _esc(s.dadata_api_key || '') + '"></div>' +
            '<div class="form-group"><label class="form-label" style="font-size:0.72rem;">Секретный ключ</label>' +
            '<input class="form-input" id="cp_set_dadata_secret" style="font-family:monospace;font-size:0.75rem;" value="' + _esc(s.dadata_secret || '') + '"></div></div>' +
            '<div style="padding:10px;background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);' +
            'border-radius:10px;font-size:0.78rem;color:var(--text-muted);">' +
            self._icon('vk', 12) + ' ВКонтакте → /bot/vk.php<br>' +
            '🤖 Артемий → /bot/artemiy.php<br>🏢 DaData → suggestions.dadata.ru</div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="btn btn-secondary" onclick="document.getElementById(\'cp_settings_modal\').remove()">Отмена</button>' +
            '<button class="btn btn-primary" onclick="_cp(\'_saveSettings\')">Сохранить</button>' +
            '</div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var m = document.getElementById('cp_settings_modal');
        m.classList.add('open');
        m.addEventListener('click', function(e) { if (e.target.id === 'cp_settings_modal') m.remove(); });
    },

    _saveSettings: function() {
        var self = this;
        var s = {
            enabled:        (document.getElementById('cp_set_enabled') || {}).classList
                            ? document.getElementById('cp_set_enabled').classList.contains('on')
                            : true,
            dadata_api_key: ((document.getElementById('cp_set_dadata_key')   || {}).value || '').trim(),
            dadata_secret:  ((document.getElementById('cp_set_dadata_secret')|| {}).value || '').trim(),
        };
        CRM.api('clients_pro', 'save_settings', s).then(function(res) {
            if (res && res.ok) {
                self._settings = s;
                var m = document.getElementById('cp_settings_modal');
                if (m) m.remove();
                notify('Настройки сохранены', 'success');
            }
        });
    },

    // ── УТИЛИТЫ ─────────────────────────────────────────────
    _metricPill: function(label, value, color) {
        return '<div style="text-align:center;">' +
            '<div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;' +
            'letter-spacing:0.5px;margin-bottom:2px;">' + label + '</div>' +
            '<div style="font-size:0.95rem;font-weight:800;color:' + color + ';">' + value + '</div>' +
            '</div>';
    },

    _nameGradient: function(name) {
        var grads = [
            'linear-gradient(135deg,#7c3aed,#06b6d4)',
            'linear-gradient(135deg,#0ea5e9,#6366f1)',
            'linear-gradient(135deg,#f59e0b,#ef4444)',
            'linear-gradient(135deg,#10b981,#0ea5e9)',
            'linear-gradient(135deg,#8b5cf6,#ec4899)',
            'linear-gradient(135deg,#14b8a6,#06b6d4)',
            'linear-gradient(135deg,#f97316,#f59e0b)',
        ];
        var idx = 0;
        if (name) {
            var code = 0;
            for (var i = 0; i < Math.min(name.length, 3); i++) code += name.charCodeAt(i);
            idx = code % grads.length;
        }
        return grads[idx];
    },

}); // END registerModule
</script>

<script>
// ================================================================
//  УНИВЕРСАЛЬНЫЙ ПАТЧ: ОТОБРАЖЕНИЕ СЧЕТОВ И ДОКУМЕНТОВ В ПРОФИЛЕ КЛИЕНТА (FIXED)
// ================================================================

(function() {
    'use strict';

    function injectTabs() {
        var tabs = document.querySelector('#cp_detail_modal .tabs');
        if (!tabs) return;

        // Если вкладки уже добавлены — не дублируем
        if (tabs.querySelector('[data-custom-tab="invoices"]')) return;
        if (tabs.querySelector('[data-custom-tab="documents"]')) return;

        // Получаем ID клиента
        var clientId = null;
        var detailModal = document.getElementById('cp_detail_modal');
        if (detailModal) {
            var idEl = detailModal.querySelector('[data-client-id]');
            if (idEl) clientId = idEl.dataset.clientId;
        }
        if (!clientId && window._cp_detailId) {
            clientId = window._cp_detailId;
        }

        // ─── ВКЛАДКА "СЧЕТА" ───────────────────────────────────────────
        var invoicesBtn = document.createElement('button');
        invoicesBtn.className = 'tab';
        invoicesBtn.dataset.customTab = 'invoices';
        invoicesBtn.textContent = '🧾 Счета';
        invoicesBtn.onclick = function() {
            tabs.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');

            var content = document.getElementById('cp_detail_tab_content');
            if (!content) return;

            content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">🧾 Загрузка счетов...</div>';

            if (clientId) {
                CRM.api('invoice_pro', 'client_invoices', { client_id: clientId })
                    .then(function(res) {
                        var invoices = res.data || [];
                        var stats = res.stats || {};

                        if (!invoices.length) {
                            content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">У клиента нет выставленных счетов</div>';
                            return;
                        }

                        var html = '<div style="padding:10px;">';
                        html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">';
                        html += '<div style="background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);border-radius:10px;padding:10px;text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Счетов</div><div style="font-weight:800;color:var(--accent2);">' + stats.count + '</div></div>';
                        html += '<div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:10px;text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Выставлено</div><div style="font-weight:800;color:var(--accent3);">' + Number(stats.total).toLocaleString('ru-RU') + ' ₽</div></div>';
                        html += '<div style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:10px;padding:10px;text-align:center;"><div style="font-size:0.65rem;color:var(--text-muted);">Оплачено</div><div style="font-weight:800;color:#4ade80;">' + Number(stats.paid).toLocaleString('ru-RU') + ' ₽</div></div>';
                        html += '</div>';

                        invoices.forEach(function(inv) {
                            var statusColor = {
                                draft: '#64748b',
                                sent: '#0ea5e9',
                                paid: '#22c55e',
                                overdue: '#ef4444',
                                cancelled: '#334155'
                            }[inv.status] || '#64748b';

                            html += '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg-dark);border:1px solid var(--border);border-radius:10px;margin-bottom:8px;border-left:4px solid ' + statusColor + ';">';
                            html += '<div style="flex:1;">';
                            html += '<div style="font-weight:700;font-size:0.9rem;">Счёт №' + (inv.number || '') + '</div>';
                            html += '<div style="font-size:0.75rem;color:var(--text-muted);">от ' + (inv.date || '') + '</div>';
                            html += '</div>';
                            html += '<div style="font-weight:800;font-size:1.1rem;color:' + statusColor + ';">' + Number(inv.total).toLocaleString('ru-RU') + ' ₽</div>';
                            html += '<button class="btn btn-primary btn-xs" onclick="window.CRM.showModulePage(\'invoice_pro\');setTimeout(function(){IP.view(\'' + inv.id + '\')},200);">Открыть</button>';
                            html += '</div>';
                        });
                        html += '</div>';
                        content.innerHTML = html;
                    })
                    .catch(function() {
                        content.innerHTML = '<div style="color:#f87171;padding:20px;">Ошибка загрузки счетов</div>';
                    });
            } else {
                content.innerHTML = '<div style="color:#f87171;padding:20px;">Клиент не привязан к счетам</div>';
            }
        };

        // ─── ВКЛАДКА "ДОКУМЕНТЫ" ──────────────────────────────────────
        var docsBtn = document.createElement('button');
        docsBtn.className = 'tab';
        docsBtn.dataset.customTab = 'documents';
        docsBtn.textContent = '📄 Документы';
        docsBtn.onclick = function() {
            tabs.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');

            var content = document.getElementById('cp_detail_tab_content');
            if (!content) return;

            content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">📄 Загрузка документов...</div>';

            if (clientId) {
                CRM.api('documents', 'list', null, { client_id: clientId })
                    .then(function(res) {
                        var docs = res.data || [];
                        var docsModule = CRM.modules && CRM.modules.documents;

                        if (!docs.length) {
                            content.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">У клиента нет привязанных документов</div>';
                            return;
                        }

                        var typeColor = docsModule ? docsModule._typeColor : {
                            invoice:'#10b981', invoice_tax:'#10b981',
                            waybill:'#3b82f6', waybill_upd:'#3b82f6',
                            contract:'#8b5cf6', act:'#f59e0b', kp:'#ec4899', other:'#6b7280'
                        };
                        var typeIcon = docsModule ? docsModule._typeIcon : {
                            invoice:'🧾', invoice_tax:'🧾',
                            waybill:'📦', waybill_upd:'📦',
                            contract:'📝', act:'✅', kp:'💼', other:'📄'
                        };
                        var kanbanCols = docsModule ? docsModule._kanbanCols : [
                            { id:'new', name:'🆕 Новые' },
                            { id:'review', name:'👀 На проверке' },
                            { id:'approved', name:'👍 Согласовано' },
                            { id:'signed', name:'✍️ Подписано' },
                            { id:'paid', name:'💳 Оплачено' },
                            { id:'archive', name:'🗄 Архив' },
                        ];

                        var html = '<div style="padding:10px;display:flex;flex-direction:column;gap:8px;">';

                        docs.forEach(function(d) {
                            var color = typeColor[d.type] || '#6b7280';
                            var icon = typeIcon[d.type] || '📄';
                            var p = d.parsed || {};
                            var kanban = kanbanCols.find(function(k) { return k.id === (d.kanban || 'new'); });
                            var kanbanName = kanban ? kanban.name : (d.kanban || 'новый');

                            html += '<div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:10px;padding:12px;border-left:4px solid ' + color + ';cursor:pointer;transition:all 0.2s;" onmouseenter="this.style.borderColor=\'' + color + '55\'" onmouseleave="this.style.borderColor=\'var(--border)\'" onclick="CRM.modules.documents._viewDoc(\'' + d.id + '\')">';
                            html += '<div style="display:flex;align-items:center;gap:10px;">';
                            html += '<span style="font-size:1.2rem;">' + icon + '</span>';
                            html += '<div style="flex:1;">';
                            html += '<div style="font-weight:700;font-size:0.9rem;">' + d.name + '</div>';
                            html += '<div style="font-size:0.72rem;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px;">';
                            html += '<span>' + (d.typeName || d.type) + '</span>';
                            if (p.number) html += '<span>· №' + p.number + '</span>';
                            if (p.total) html += '<span>· 💰 ' + Number(p.total).toLocaleString('ru-RU') + ' ₽</span>';
                            if (p.date) html += '<span>· 📅 ' + p.date + '</span>';
                            html += '</div></div>';
                            html += '<span style="font-size:0.7rem;padding:3px 8px;border-radius:6px;background:' + color + '15;color:' + color + ';">' + kanbanName + '</span>';
                            html += '</div></div>';
                        });
                        html += '</div>';
                        content.innerHTML = html;
                    })
                    .catch(function(err) {
                        console.error('Ошибка загрузки документов:', err);
                        content.innerHTML = '<div style="color:#f87171;padding:20px;">Ошибка загрузки документов: ' + (err.message || 'неизвестная ошибка') + '</div>';
                    });
            } else {
                content.innerHTML = '<div style="color:#f87171;padding:20px;">Клиент не найден</div>';
            }
        };

        // ─── ВСТАВЛЯЕМ ВКЛАДКИ ────────────────────────────────────────
        var existingTabs = tabs.querySelectorAll('.tab');
        var historyTab = null;

        existingTabs.forEach(function(t) {
            if (t.textContent && t.textContent.trim() === 'История') {
                historyTab = t;
            }
        });

        if (historyTab) {
            historyTab.parentNode.insertBefore(invoicesBtn, historyTab);
            historyTab.parentNode.insertBefore(docsBtn, historyTab);
        } else {
            tabs.appendChild(invoicesBtn);
            tabs.appendChild(docsBtn);
        }
    }

    var observer = new MutationObserver(function() {
        if (document.getElementById('cp_detail_modal')) {
            injectTabs();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    setTimeout(injectTabs, 500);

})();
</script>