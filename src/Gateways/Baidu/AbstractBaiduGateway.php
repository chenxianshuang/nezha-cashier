<?php
/**
 * @author: mozr
 * @date: 2019/1/7
 */

namespace Runner\NezhaCashier\Gateways\Baidu;

use Runner\NezhaCashier\Gateways\AbstractGateway;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Requests\Close;
use Runner\NezhaCashier\Requests\Query;
use Runner\NezhaCashier\Requests\Refund;

abstract class AbstractBaiduGateway extends AbstractGateway
{
    const QUERY_ORDER = 'https://dianshang.baidu.com/platform/entity/openapi/queryorderdetail';

    const APPLY_REFUND = 'https://nop.nuomi.com/nop/server/rest';

    /**
     * @param Charge $form
     *
     * @return array
     */
    public function charge(Charge $form): array
    {
        $payload = $this->createPayload(
            array_merge(
                [
                    'body'         => $form->get('subject'),
                    'tp_order_id'  => $form->get('order_id'),
                    'fee_type'     => $form->get('currency'),
                    'total_amount' => $form->get('amount'),
                    'notify_url'   => $this->config->get('notify_url'),
                    'deal_title'   => $form->get('description'),
                ],
                $this->prepareCharge($form)
            )
        );

        return $this->doCharge($payload);
    }

    /**
     * @param Refund $form
     *
     * @return array
     */
    public function refund(Refund $form): array
    {
        // TODO: Implement refund() method.
    }

    /**
     * @param Close $form
     *
     * @return array
     */
    public function close(Close $form): array
    {
        // TODO: Implement close() method.
    }

    /**
     * @param Query $form
     *
     * @return array
     */
    public function query(Query $form): array
    {
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function chargeNotify(array $receives): array
    {
        return [
            'order_id'              => $receives['tpOrderId'],
            'status'                => $this->formatTradeStatus($receives['status']),
            'trade_sn'              => $receives['orderId'],
            'amount'                => $receives['totalMoney'],
            'buyer_identifiable_id' => $receives['userId'] ?? '',
            'buyer_name'            => '',
            'paid_at'               => (isset($receives['payTime']) ? strtotime($receives['payTime']) : 0),
            'raw'                   => $receives,
        ];
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function refundNotify(array $receives): array
    {
        // TODO: Implement refundNotify() method.
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function closeNotify(array $receives): array
    {
        // TODO: Implement closeNotify() method.
    }

    /**
     * @return string
     */
    public function success(): string
    {
        return 'success';
    }

    /**
     * @return string
     */
    public function fail(): string
    {
        return 'fail';
    }

    public function receiveNotificationFromRequest(): array
    {
        return $_POST;
    }

    /**
     * @param $receives
     *
     * @return array
     */
    public function convertNotificationToArray($receives): array
    {
        return $receives;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected function sign(array $parameters): string
    {
        unset($parameters['sign']);
        ksort($parameters);
        $sign = '';

        $privateKey = '.pem' === substr($this->config->get('baidu_private_key'), -4)
            ? openssl_get_privatekey("file://{$this->config->get('baidu_private_key')}")
            : $this->config->get('baidu_private_key');

        openssl_sign(
            urldecode(
                http_build_query(
                    array_filter(
                        $parameters,
                        function ($value) {
                            return '' !== $value;
                        }
                    )
                )
            ),
            $sign,
            $privateKey
        );

        return base64_encode($sign);
    }

    /**
     * @param $receives
     *
     * @return bool
     */
    public function verify($receives): bool
    {
        $sign = $receives['rsaSign'];
        unset($receives['rsaSign']);
        ksort($receives);

        $publicKey = ('.pem' === substr($this->config->get('baidu_public_key'), -4)
            ? openssl_get_publickey("file://{$this->config->get('baidu_public_key')}")
            : $this->config->get('baidu_public_key'));

        $verify = openssl_verify(
            urldecode(
                http_build_query(
                    array_filter(
                        $receives,
                        function ($value) {
                            return '' !== $value;
                        }
                    )
                )
            ),
            base64_decode($sign),
            $publicKey
        );

        return 0 === $verify;
    }

    /**
     * @param array $payload
     *
     * @return array
     */
    protected function createPayload(array $payload = []): array
    {
        $parameters = [
            'appKey'      => $this->config->get('app_key'),
            'dealId'      => $this->config->get('deal_id'),
            'tpOrderId'   => $payload['tp_order_id'],
            'totalAmount' => $payload['total_amount']
        ];

        $parameters['rsaSign']         = $this->sign($parameters);
        $parameters['signFieldsRange'] = 1;
        $parameters['dealTitle']       = $payload['deal_title'];
        $parameters['bizInfo']         = json_encode($parameters, true);

        return $parameters;
    }

    /**
     * @param Charge $form
     *
     * @return array
     */
    protected function prepareCharge(Charge $form): array
    {
        return [];
    }

    /**
     * @param array $payload
     *
     * @return array
     */
    protected function doCharge(array $payload): array
    {
        return [
            'charge_url' => '',
            'parameters' => $payload,
        ];
    }

    /**
     * @param $status
     *
     * @return string
     */
    protected function formatTradeStatus($status): string
    {
        $map = [
            1  => 'created',
            2  => 'paid',
            -1 => 'closed',
        ];

        return $map[$status];
    }
}
