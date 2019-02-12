<?php

if (!defined('ABSPATH')) {

    die('This file cannot be accessed directly');
}

if (!function_exists('init_zibal_gateway_pv_class')) {

    add_action('plugins_loaded', 'init_zibal_gateway_pv_class');

    function init_zibal_gateway_pv_class()
    {
        add_filter('pro_vip_currencies_list', 'currencies_check');

        function currencies_check($list)
        {
            if (!in_array('IRT', $list)) {

                $list['IRT'] = array(

                    'name'   => 'تومان ایران',
                    'symbol' => 'تومان',
                );
            }

            if (!in_array('IRR', $list)) {

                $list['IRR'] = array(

                    'name'   => 'ریال ایران',
                    'symbol' => 'ریال',
                );
            }

            return $list;
        }

        if (class_exists('Pro_VIP_Payment_Gateway') && !class_exists('Pro_VIP_Zibal_Gateway')) {

            class Pro_VIP_Zibal_Gateway extends Pro_VIP_Payment_Gateway
            {
                public

                    $id            = 'zibal',
                    $settings      = array(),
                    $frontendLabel = 'درگاه پرداخت زیبال',
                    $adminLabel    = 'درگاه پرداخت زیبال';

                public function __construct()
                {
                    parent::__construct();
                }

                public function beforePayment(Pro_VIP_Payment $payment)
                {
                    if (extension_loaded('curl')) {

                        $merchantId  = $this->settings['merchantId'];
                        $zibaldirect  = $this->settings['zibaldirect'];
                        $order_id = $payment->paymentId;
                        $callback = add_query_arg('order', $order_id, $this->getReturnUrl());
                        $amount   = intval($payment->price);

                        if (pvGetOption('currency') == 'IRT') {

                            $amount = $amount * 10;
                        }

                        $params = array(

                            'merchant'          => preg_replace('/\s+/', '', $merchantId),
                            'amount'       => $amount,
                            'callbackUrl'     => urlencode($callback),
                            'orderId' => $order_id
                        );

                        $result = $this->postToZibal('request', $params);

                        if ($result && isset($result->result) && $result->result == 100) {

                            $payment->key = $order_id;

                            $payment->user = get_current_user_id();
                            $payment->save();

                            $message     = 'شماره تراکنش ' . $result->trackId;
                            $gateway_url = 'https://gateway.zibal.ir/start/' . $result->trackId;

                            if(isset($zibaldirect) && $zibaldirect=='1')
                                $gateway_url.="/direct";

                            pvAddNotice($message, 'error');

                            wp_redirect($gateway_url);
                            exit;

                        } else {

                            $message = 'در ارتباط با وب سرویس زیبال خطایی رخ داده است';
                            $message = isset($result->message) ? $result->message : $message;

                            pvAddNotice($message, 'error');

                            $payment->status = 'trash';
                            $payment->save();

                            wp_die($message);
                            exit;
                        }

                    } else {

                        $message = 'تابع cURL در سرور فعال نمی باشد';

                        pvAddNotice($message, 'error');

                        $payment->status = 'trash';
                        $payment->save();

                        wp_die($message);
                        exit;
                    }
                }

                public function afterPayment()
                {
                    if (isset($_GET['order'])) {

                        $order_id = sanitize_text_field($_GET['order']);

                    } else {

                        $order_id = NULL;
                    }

                    if ($order_id) {

                        $payment = new Pro_VIP_Payment($order_id);

                        if (isset($_POST['success']) && isset($_POST['trackId']) && isset($_POST['orderId'])) {

                            $trackId        = sanitize_text_field($_POST['trackId']);
                            $orderId      = sanitize_text_field($_POST['orderId']);
                            $success = sanitize_text_field($_POST['success']);

                            if (isset($success) && $success == 1 && $orderId==$order_id) {

                                $merchantId = $this->settings['merchantId'];

                                $params = array (
                                    'merchant'     => preg_replace('/\s+/', '', $merchantId),
                                    'trackId' => $trackId
                                );

                                $result = $this->postToZibal('verify', $params);

                                if ($result && isset($result->result) && $result->result == 100) {

                                    $amount  = intval($payment->price);

                                    if (pvGetOption('currency') == 'IRT') {

                                        $amount = $amount * 10;
                                    }

                                    if ($amount == $result->amount) {

                                        $message = 'تراکنش شماره ' . $trackId . ' با موفقیت انجام شد';

                                        pvAddNotice($message, 'success');

                                        $payment->status = 'publish';
                                        $payment->save();

                                        $this->paymentComplete($payment);

                                    } else {

                                        $message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';

                                        pvAddNotice($message, 'error');

                                        $payment->status = 'trash';
                                        $payment->save();

                                        $this->paymentFailed($payment);
                                    }

                                } else {

                                    $message = 'در ارتباط با وب سرویس زیبال و بررسی تراکنش خطایی رخ داده است';
                                    $message = isset($result->message) ? $result->message : $message;

                                    pvAddNotice($message, 'error');

                                    $payment->status = 'trash';
                                    $payment->save();

                                    $this->paymentFailed($payment);
                                }

                            } else {

                                $message = 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';

                                pvAddNotice($message, 'error');

                                $payment->status = 'trash';
                                $payment->save();

                                $this->paymentFailed($payment);
                            }

                        } else {

                            $message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';

                            pvAddNotice($message, 'error');

                            $payment->status = 'trash';
                            $payment->save();

                            $this->paymentFailed($payment);
                        }

                    } else {

                        $message = 'شماره سفارش ارسال شده غیر معتبر است';

                        pvAddNotice($message, 'error');
                    }
                }

                public function adminSettings(PV_Framework_Form_Builder $form)
                {
                    $form->textfield('merchantId')->label('کلید Merchant');
                    $form->checkbox('zibaldirect')->label('زیبال دایرکت؟ (درگاه مستقیم)');
                }

                /**
                 * connects to zibal's rest api
                 * @param $path
                 * @param $parameters
                 * @return stdClass
                 */
                private function postToZibal($path, $parameters)
                {
                    $url = 'https://gateway.zibal.ir/'.$path;
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($parameters));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response  = curl_exec($ch);
                    curl_close($ch);
                    return json_decode($response);
                }
            }

            Pro_VIP_Payment_Gateway::registerGateway('Pro_VIP_Zibal_Gateway');
        }
    }
}
