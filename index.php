<?php
/**
 * Plugin Name: Dec APB Payment
 * Description: Плагин для оплаты через платежный шлюз Агропромбанка
 * Author: Jonathan Livingston. Telegram @akssenov
 * Version: 1.5
 */

if (!defined("ABSPATH")) {exit();}

add_action("plugins_loaded", "dec_apb_payment_plugin_init", 0);
add_action("init", "dec_apb_payment_plugin_register_endpoints");
add_filter("query_vars", "dec_apb_payment_plugin_query_vars", 0);
add_action("parse_request", "dec_apb_payment_plugin_parse_request", 0);

// Укажите ваши параметры ResultURL, SuccessURL и FailURL или оставьте без изменений.
$resultURL = "payment/result";
$successURL = "payment/success";
$failURL = "payment/failure";

// Регистрируем конечные точки.
function dec_apb_payment_plugin_register_endpoints()
{
    global $resultURL, $successURL, $failURL;
    
    add_rewrite_endpoint($resultURL, EP_ROOT);
    add_rewrite_endpoint($successURL, EP_ROOT);
    add_rewrite_endpoint($failURL, EP_ROOT);
}

// Обрабатываем запросы к конечным точкам
function dec_apb_payment_plugin_parse_request(&$wp)
{
    global $resultURL, $successURL, $failURL;

    if (array_key_exists($resultURL, $wp->query_vars)) {
        dec_apb_payment_plugin_handle_payment_result();
        exit();
    }

    if (array_key_exists($successURL, $wp->query_vars)) {
        dec_apb_payment_plugin_handle_payment_success();
        exit();
    }

    if (array_key_exists($failURL, $wp->query_vars)) {
        dec_apb_payment_plugin_handle_payment_fail();
        exit();
    }
    return;
}

function dec_apb_payment_plugin_query_vars($vars)
{
    global $resultURL, $successURL, $failURL;

    $vars[] = $resultURL;
    $vars[] = $successURL;
    $vars[] = $failURL;
    return $vars;
}

// Функция обработки обращений к ResultURL
function dec_apb_payment_plugin_handle_payment_result()
{
    $status = $_POST["status"] ?? "";
    $invoice_id = $_POST["invoiceid"] ?? "";
    $payment_sum = $_POST["paymentsum"] ?? "";
    $payment_currency = $_POST["paymentcurrency"] ?? "";
    $date = $_POST["date"] ?? "";
    $signature = $_POST["signature"] ?? "";

    $dec_apb_payment_plugin = new WC_Dec_APB_Payment_Plugin();
    $merchant_pass = $dec_apb_payment_plugin->get_merchant_pass();

    // Сверяем подписи.
    $md5_date = str_replace(".", "", $date);
    if ($status === "paid") {
        $expected_signature = strtoupper(md5("{$invoice_id}:{$status}:{$payment_sum}:{$payment_currency}:{$md5_date}:{$merchant_pass}"));
    } else if ($status === "fail") {
        $expected_signature = strtoupper(md5("{$invoice_id}:{$status}:{$md5_date}:{$merchant_pass}"));
    }

    if ($status === "paid" || $status === "fail") {
        if ($signature === $expected_signature) {
            $order = wc_get_order($invoice_id);
            if ($order) {
                if ($status === "paid") {
                    $order->payment_complete();
                    $order->add_order_note("Оплачено через DEC APB Payment");
                } else {
                    $order->update_status("failed", "Неудачный платеж через DEC APB Payment");
                }
            }
        } else {
            error_log("Проверка подписи не пройдена.");
        }
    } else {
        error_log( "Неизвестное значение статуса");
    }
    status_header(200);
    exit();
}

// Функция обработки обращений к SuccessURL
function dec_apb_payment_plugin_handle_payment_success()
{
    $order_id = $_POST["InvoiceId"] ?? "";

    // Проверяем, существует ли ID заказа
    if (!empty($order_id)) {
        $order = wc_get_order($order_id);
        if ($order) {
            // Добавляем к URL ключ заказа
            $redirect_url = wc_get_checkout_url() . "/order-received/" . $order_id . "/";

            // Добавляем к URL хеш заказа
            $redirect_url .= "?key=" . $order->get_order_key();

            // Перенаправляем пользователя на страницу "Заказ принят"
            wp_redirect($redirect_url);
            exit();
        } else {
            // Заказ не найден, здесь вы можете добавить свою логику обработки ошибок
        }
    } else {
        // ID заказа не найден, здесь вы можете добавить свою логику обработки ошибок
    }
}

// Функция обработки обращений к FailURL
function dec_apb_payment_plugin_handle_payment_fail()
{
    // Укажите ваш адрес страницы неудачной оплаты
    wp_redirect("/failed-pay'"); 
    exit();
}

// Инициализация плагина
function dec_apb_payment_plugin_init()
{
    if (!class_exists("WC_Payment_Gateway")) {
        return;
    }

    class WC_Dec_APB_Payment_Plugin extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = "dec_apb_payment_plugin";
            $this->method_title = __("Dec APB Payment", "dec_apb_payment_plugin");
            $this->method_description = __('Платежный шлюз Агропромбанка', 'dec_apb_payment_plugin');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option("enabled");
            $this->test_mode = $this->get_option("test_mode");
            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->merchant_login = $this->get_option("merchant_login");
            $this->merchant_pass = $this->get_option("merchant_pass");

            add_action(
                "woocommerce_update_options_payment_gateways_" . $this->id,
                [$this, "process_admin_options"]
            );

        }

        public function is_available()
        {
            return $this->enabled === 'yes';
        }

        public function get_merchant_pass()
        {
            return $this->merchant_pass;
        }


        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Включить/Выключить', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включить DEC Payment Gateway', 'woocommerce'),
                    'default' => 'yes',
                ],
                'test_mode' => [
                    'title' => __('Тестовый режим', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включить тестовый режим. (В тестовом режиме деньги при оплате не списываются).', 'woocommerce'),
                    'default' => 'yes',
                ],
                "title" => [
                    "title" => __("Название", "dec_apb_payment_plugin"),
                    "type" => "text",
                    "description" => __(
                        "Название, которое видит пользователь при выборе способа оплаты.",
                        "dec_apb_payment_plugin"
                    ),
                    "default" => __("Dec APB Payment Plugin", "dec_apb_payment_plugin"),
                ],
                "description" => [
                    "title" => __("Описание", "dec_apb_payment_plugin"),
                    "type" => "textarea",
                    "description" => __(
                        "Описание способа оплаты."
                    ),
                    "default" => __(
                        "Оплата через модуль Dec APB",
                        "dec_apb_payment_plugin"
                    ),
                ],
                "merchant_login" => [
                    "title" => __("Merchant Login", "dec_apb_payment_plugin"),
                    "type" => "text",
                    "description" => __(
                        "Персональный идентификатор интернет-магазина (Выдается банком)",
                        "my_payment_plugin"
                    ),
                    "default" => "",
                ],
                "merchant_pass" => [
                    "title" => __("Merchant Pass", "dec_apb_payment_plugin"),
                    "type" => "password",
                    "description" => __(
                        "Персональный пароль интернет-магазина (Выдается банком)",
                        "dec_apb_payment_plugin"
                    ),
                    "default" => "",
                ],
            ];
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $payment_url = "https://www.agroprombank.com/payments/PaymentStart";

            $payData = [
                "MerchantLogin" => $this->merchant_login,
                "nivid" => $order_id,
                "istest" => $this->test_mode === 'yes' ? 1 : 0, 
                "RequestSum" => $order->get_total() * 100, // Конвертируем в копейки
                "RequestCurrCode" => "000",
                "Desc" => "Покупка в интернет магазине",
            ];

            // Генерируем SignatureValue используя MD5 хэш метод
            $signature_string = "{$payData["MerchantLogin"]}:{$payData["nivid"]}:{$payData["istest"]}:{$payData["RequestSum"]}:{$payData["RequestCurrCode"]}:{$payData["Desc"]}:{$this->merchant_pass}";
            $payData["SignatureValue"] = md5($signature_string);

            $payment_url .= "?" . http_build_query($payData);

            return [
                "result" => "success",
                "redirect" => $payment_url,
            ];
        }
    }

    function add_dec_apb_payment_plugin($methods)
    {
        $methods[] = "WC_Dec_APB_Payment_Plugin";
        return $methods;
    }
    add_filter("woocommerce_payment_gateways", "add_dec_apb_payment_plugin");
}
