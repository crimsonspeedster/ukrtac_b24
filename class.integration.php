<?php

namespace App\Bitrix;

class B24Integration {
    public const BITRIX_URL = get_option('bitrix_rest_url');

    public function __construct () {
        add_action( 'woocommerce_order_status_changed', [$this, 'updateDealStatus'] );
        add_action( 'woocommerce_checkout_order_processed', [$this, 'createDeal'] );
        add_action( 'woocommerce_thankyou', [$this, 'createDeal'] );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [$this, 'add_custom_order_data']);
        add_filter('manage_product_posts_columns', [$this, 'add_custom_column_to_product_admin']);
        add_action('manage_product_posts_custom_column', [$this, 'display_custom_column_data'], 10, 2);
        add_action('wpcf7_mail_sent', [$this, 'sendDataFromCF7']);
    }

    private function addUTMToArray ($array) {
        if (!empty($_COOKIE['utm_source']))
            $array['FIELDS[UTM_SOURCE]'] = $_COOKIE['utm_source'];

        if (!empty($_COOKIE['utm_medium']))
            $array['FIELDS[UTM_MEDIUM]'] = $_COOKIE['utm_medium'];

        if (!empty($_COOKIE['utm_campaign']))
            $array['FIELDS[UTM_CAMPAIGN]'] = $_COOKIE['utm_campaign'];

        if (!empty($_COOKIE['utm_content']))
            $array['FIELDS[UTM_CONTENT]'] = $_COOKIE['utm_content'];

        if (!empty($_COOKIE['utm_term']))
            $array['FIELDS[UTM_TERM]'] = $_COOKIE['utm_term'];

        setcookie('utm_source', '', time() - 3600, '/');
        setcookie('utm_medium', '', time() - 3600, '/');
        setcookie('utm_campaign', '', time() - 3600, '/');
        setcookie('utm_content', '', time() - 3600, '/');
        setcookie('utm_term', '', time() - 3600, '/');

        return $array;
    }

    public function sendDataFromCF7 ($contact_form) {
        $form_id = $contact_form->id();

        $submission = \WPCF7_Submission::get_instance();

        if ($submission) {
            $form_data = $submission->get_posted_data();

            $user_name = $form_data['user-name'];
            $user_phone = $form_data['user-phone'];
            $user_product_id = $form_data['user_product'];

            switch ($form_id) {
                case get_field('fast_order_cf7', 'options'):
                    $address = array(
                        'first_name' => $user_name,
                        'last_name'  => '',
                        'phone'      => $user_phone,
                    );

                    $order = wc_create_order();

                    $order->add_product(wc_get_product(intval($user_product_id)), 1);
                    $order->set_address( $address, 'billing' );

                    $order->calculate_totals();
                    $order->update_status("processing", 'Fast Order', TRUE);

                    $order->save();

                    self::createDeal($order->get_id());

                    break;
                case get_field('preorder_cf7', 'options'):
                    $user_message = $form_data['user-message'];

                    $contactID = 0;
                    $phones_array = self::get_phones_array($user_phone);

                    foreach ($phones_array as $phone)
                    {
                        $params = [
                            'filter[PHONE]' => $phone,
                            'select[]' => 'ID'
                        ];

                        $response = self::getDataFromCurl($params, 'crm.contact.list/?');

                        if ($response['total'] > 0)
                        {
                            $contactID = $response['result'][0]['ID'];
                            break;
                        }
                    }

                    if ($contactID == 0)
                    {
                        $contactID = self::sendContactToCRM($user_phone, $user_name)['result'];
                    }

                    $params = [
                        'FIELDS[CONTACT_ID]' => $contactID,
                        'FIELDS[COMMENTS]' => $user_message,
                        'FIELDS[UF_CRM_1687456268421]' => 1,
                        'FIELDS[UF_CRM_1671540712021]' => 'Товар под заказ с сайта Ukrtak.com'
                    ];

                    $params = self::addUTMToArray($params);

                    $deal_ID = self::getDataFromCurl($params, 'crm.deal.add/?')['result'];

                    $paramsForProductsSet = self::getProductsArrayForCRMFromCF7($user_product_id, $deal_ID);
                    self::getDataFromCurl($paramsForProductsSet, 'crm.deal.productrows.set/?');

                    break;
                default:
                    break;
            }
        }
    }

    public function getProductsArrayForCRMFromCF7 ($productID, $dealID) {
        $orders_data = [];
        $final_data = [
            'id' => $dealID
        ];

        $product = wc_get_product($productID);

        $search_id = get_post_meta($productID, '1c_id', true) ? get_post_meta($productID, '1c_id', true) : $productID;

        $response = self::getDataFromCurl([
            'filter[XML_ID]' => $search_id,
            'select[]' => 'ID'
        ], 'crm.product.list/?');

        if ($response['total'] > 0 && count($response['result']) > 0)
        {
            $orders_data[] = [
                'PRODUCT_ID' => $response['result'][0]['ID'],
                'PRICE' => $product->get_price(),
                'QUANTITY' => 1
            ];
        }
        else {
            $orders_data[] = [
                'PRODUCT_ID' => self::createProduct($productID),
                'PRICE' => $product->get_price(),
                'QUANTITY' => 1
            ];
        }

        foreach ($orders_data as $index => $item)
        {
            foreach($item as $key => $value) {
                $final_data['rows['.$index.']['.$key.']'] = $value;
            }
        }


        return $final_data;
    }

    public function add_custom_column_to_product_admin ($columns) {
        $columns['1c_id'] = '1C ID';
        return $columns;
    }

    public function display_custom_column_data($column, $post_id) {
        if ($column == '1c_id') {
            $value = get_post_meta($post_id, '1c_id', true) ? get_post_meta($post_id, '1c_id', true) : $post_id;
            echo $value;
        }
    }

    public function str_replace_first ($search, $replace, $subject)
    {
        return preg_replace('/'.preg_quote($search, '/').'/', $replace, $subject, 1);
    }

    public function clearPhone ($phone) {
        return preg_replace('/[^A-Za-z0-9]/', '', $phone);
    }

    public function get_phones_array ($phone) {
        $phone = self::clearPhone($phone);

        $phones_arr = [];

        switch(substr($phone, 0, 1))
        {
            case '0':
                $phones_arr[] = '38'.$phone;
                break;
            case '8':
                $phones_arr[] = self::str_replace_first('8', '38', $phone);
                break;
            default:
                $phones_arr[] = $phone;
                break;
        }

        $phones_arr[] = self::str_replace_first('38', '%2B38', $phones_arr[0]);
        $phones_arr[] = self::str_replace_first('38', '8', $phones_arr[0]);
        $phones_arr[] = self::str_replace_first('38', '', $phones_arr[0]);

        return $phones_arr;
    }

    public function add_custom_order_data ($order) {
        $custom_data = $order->get_meta('b24_deal_id');
        $wayforpay_ID = $order->get_meta('wayforpay_merchant_id');
        $fondy_ID = $order->get_meta('fondy_merchant_id');

        echo '<p>CRM Deal ID: '.$custom_data.'</p>';

        if ($wayforpay_ID)
            echo '<p>Wayforpay Merchant ID: '.$wayforpay_ID.'</p>';

        if ($fondy_ID)
            echo '<p>Fondy Merchant ID: '.$fondy_ID.'</p>';
    }

    public function getDataFromCurl ($params, $method) {
        $params = http_build_query($params);

        $fullUrl = self::BITRIX_URL . $method . $params;

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result, true);
    }

    public function getContactID ($order) {
        $contactID = 0;
        $customer_email = $order->get_billing_email();
        $customer_phone  = $order->get_billing_phone();

        $phones_array = self::get_phones_array($customer_phone);

        foreach ($phones_array as $phone)
        {
            $params = [
                'filter[PHONE]' => $phone,
                'select[]' => 'ID'
            ];

            $response = self::getDataFromCurl($params, 'crm.contact.list/?');

            if ($response['total'] > 0)
            {
                $contactID = $response['result'][0]['ID'];
                break;
            }
        }

        if ($contactID == 0)
        {
            if ($customer_email)
            {
                $params = [
                    'filter[EMAIL]' => $customer_email,
                    'select[]' => 'ID'
                ];

                $response = self::getDataFromCurl($params, 'crm.contact.list/?');

                return $response['total'] == 0 ? self::createContactInCRM($order)['result'] : $response['result'][0]['ID'];
            }

            return self::createContactInCRM($order)['result'];
        }


        return $contactID;
    }

    public function createContactInCRM ($order) {
        $customer_email = $order->get_billing_email();
        $customer_phone  = self::clearPhone($order->get_billing_phone());
        $customer_name = $order->get_billing_first_name();
        $customer_last_name = $order->get_billing_last_name();

        return self::sendContactToCRM($customer_phone, $customer_name, $customer_email, $customer_last_name);
    }

    public function sendContactToCRM ($phone, $name, $email = '', $lastname = '') {
        $params = [
            'FIELDS[PHONE][0][VALUE]' => '+'.$phone,
            'FIELDS[EMAIL][0][VALUE]' => $email,
            'FIELDS[NAME]' => $name,
            'FIELDS[LAST_NAME]' => $lastname,
        ];

        return self::getDataFromCurl($params, 'crm.contact.add/?');
    }

    public function getProductsArrayForCRM ($order, $dealID) {
        $order_items = $order->get_items();
        $orders_data = [];
        $final_data = [
            'id' => $dealID
        ];

        foreach ( $order_items as $item ) {
            $product_id = $item->get_product_id();
            $product = $item->get_product();

            $search_id = get_post_meta($product_id, '1c_id', true) ? get_post_meta($product_id, '1c_id', true) : $product_id;

            $response = self::getDataFromCurl([
                'filter[XML_ID]' => $search_id,
                'select[]' => 'ID'
            ], 'crm.product.list/?');

            if ($response['total'] > 0 && count($response['result']) > 0)
            {
                $orders_data[] = [
                    'PRODUCT_ID' => $response['result'][0]['ID'],
                    'PRICE' => $product->get_price(),
                    'QUANTITY' => $item->get_quantity()
                ];
            }
            else {
                $orders_data[] = [
                    'PRODUCT_ID' => self::createProduct($product_id),
                    'PRICE' => $product->get_price(),
                    'QUANTITY' => $item->get_quantity()
                ];
            }
        }

        foreach ($orders_data as $index => $item)
        {
            foreach($item as $key => $value) {
                $final_data['rows['.$index.']['.$key.']'] = $value;
            }
        }


        return $final_data;
    }

    public function createProduct ($product_id) {
        $product = wc_get_product($product_id);
        $search_id = get_post_meta($product_id, '1c_id', true) ? get_post_meta($product_id, '1c_id', true) : $product_id;

        $params = [
            'FIELDS[XML_ID]' => $search_id,
            'FIELDS[DESCRIPTION]' => wp_trim_words($product->get_short_description(), 50),
            'FIELDS[NAME]' => $product->get_title(),
            'FIELDS[PRICE]' => $product->get_price(),
            'FIELDS[PROPERTY_102]' => $product->get_sku(),
            'FIELDS[PROPERTY_104]' => $product_id,
            'FIELDS[PREVIEW_PICTURE]' => wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'single-post-thumbnail' )
        ];

        $response = self::getDataFromCurl($params, 'crm.product.add/?');

        return $response['result'];
    }

    public function updateDealStatus ($orderID) {
        $order = wc_get_order($orderID);
        $dealID = $order->get_meta('b24_deal_id');

        if (!empty($dealID))
        {
            $params = [
                'id' => $dealID,
            ];

            $order_status = $order->get_status();

            if ($order->get_payment_method() === 'wayforpay' || $order->get_payment_method() === 'wayforpay_parts' || $order->get_payment_method() === 'fondy' || $order->get_payment_method() === 'fondy_bank')
            {
                switch ($order_status) {
                    case 'processing':
                    case 'completed':
                        $params['FIELDS[UF_CRM_1670709185825]'] = 60;

                        if ($order->get_meta('wayforpay_merchant_id'))
                            $params['FIELDS[UF_CRM_1670710770]'] = $order->get_meta('wayforpay_merchant_id');

                        if ($order->get_meta('fondy_merchant_id'))
                            $params['FIELDS[UF_CRM_1670710770]'] = $order->get_meta('fondy_merchant_id');

                        break;
                    default:
                        $params['FIELDS[UF_CRM_1670709185825]'] = 62;
                        break;
                }

                self::getDataFromCurl($params, 'crm.deal.update/?');
            }
        }
    }

    public function createDeal ($orderId) {
        $order = wc_get_order($orderId);
        $order_status = $order->get_status();

        if ($order->get_meta( 'b24_deal_id' ))
        {
            self::updateDealStatus($orderId);
            return;
        }

        $contact_ID = self::getContactID($order);

        $params = [
            'FIELDS[CONTACT_ID]' => $contact_ID,
            'FIELDS[UF_CRM_MPI__ORDER_ID]' => $orderId,
            'FIELDS[UF_CRM_MPI__MARKET_PLACE]' => 186,
            'FIELDS[UF_CRM_1671540712021]' => 'ukrtac - замовлення через кошик',
            'FIELDS[COMMENTS]' => $order->get_customer_note(),
        ];

        switch ($order->get_shipping_method())
        {
            case 'Нова пошта':
                $params['FIELDS[UF_CRM_1667840019560]'] = 48;
                $params['FIELDS[UF_CRM_1667839972375]'] = $order->get_address('billing')['address_1'];
                $params['FIELDS[UF_CRM_1667839942882]'] = $order->get_billing_city() ? $order->get_billing_city() : $order->get_shipping_city();
                break;
            default:
                $params['FIELDS[UF_CRM_1667840019560]'] = 44;
                break;
        }

        switch ($order->get_payment_method())
        {
            case 'wayforpay':
            case 'fondy':
                $params['FIELDS[UF_CRM_1667840101665]'] = 52;
                $params['FIELDS[UF_CRM_1670709185825]'] = $order_status === 'processing' ? 60 : 62;
                break;
            case 'wayforpay_parts':
                $params['FIELDS[UF_CRM_1667840101665]'] = 190;
                $params['FIELDS[UF_CRM_1670709185825]'] = $order_status === 'processing' ? 60 : 62;
                break;
            case 'fondy_bank':
                $params['FIELDS[UF_CRM_1667840101665]'] = 192;
                $params['FIELDS[UF_CRM_1670709185825]'] = $order_status === 'processing' ? 60 : 62;
                break;
            default:
                $params['FIELDS[UF_CRM_1667840101665]'] = 50;
                break;
        }

        $params = self::addUTMToArray($params);

        $response = self::getDataFromCurl($params, 'crm.deal.add/?');
        $deal_ID = $response['result'];

        $order->add_meta_data('b24_deal_id', $deal_ID);

        $paramsForProductsSet = self::getProductsArrayForCRM($order, $deal_ID);

        self::getDataFromCurl($paramsForProductsSet, 'crm.deal.productrows.set/?');

        $order->save();
    }
}
