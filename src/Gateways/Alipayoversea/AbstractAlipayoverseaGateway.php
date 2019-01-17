<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2019/1/14
 * Time: 16:56
 */

namespace Runner\NezhaCashier\Gateways\Alipayoversea;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Runner\NezhaCashier\Exception\GatewayException;
use Runner\NezhaCashier\Exception\RequestGatewayException;
use Runner\NezhaCashier\Gateways\AbstractGateway;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Requests\Close;
use Runner\NezhaCashier\Requests\Query;
use Runner\NezhaCashier\Requests\Refund;
use Runner\NezhaCashier\Utils\HttpClient;
use Runner\NezhaCashier\Utils\Xml;

abstract class AbstractAlipayoverseaGateway extends AbstractGateway
{
    const PAY_API_HOST = 'https://gateway.wepayez.com/pay/gateway';

    const UNIFIED_ORDER_SERVICE = 'pay.alipay.wappay.intl';
    const UNIFIED_ORDER_QUERY = 'unified.trade.query';
    const UNIFIED_ORDER_REFUND = 'unified.trade.refund';

    /**
     * 支付.
     *
     * @param Charge $form
     *
     * @return array
     */
    public function charge(Charge $form): array
    {
        $payload = $this->createPayload(
            array_merge(
                [
                    'service' => self::UNIFIED_ORDER_SERVICE,
                    'body'             => $form->get('subject'),
                    'out_trade_no'     => $form->get('order_id'),
                    'total_fee'        => number_format($form->get('amount') * $this->config->get('rate', 1), 0, '.', ''),
                    'mch_create_ip' => $form->get('user_ip'),
                    'notify_url'       => $this->config->get('notify_url'),
                    'callback_url'    => $form->get('return_url')
                ],
                $this->prepareCharge($form)
            )
        );

        $response = $this->request(self::PAY_API_HOST, $payload);

        return $this->doCharge($response, $form);
    }

    /**
     * 退款-退款成功后，需要等待几分钟，略慢.
     *
     * @param Refund $form
     *
     * @return array
     */
    public function refund(Refund $form): array
    {
        $payload = $this->createPayload(
            array_merge(
                [
                    'service' => self::UNIFIED_ORDER_REFUND,
                    'out_trade_no'  => $form->get('order_id'),
                    'out_refund_no' => $form->get('refund_id'),
                    'total_fee'     => $form->get('total_amount'),
                    'refund_fee'    => $form->get('refund_amount'),
                    'op_user_id' => $this->config->get('mch_id'),
                ],
                $form->get('extras')
            )
        );

        $response = $this->request(
            self::PAY_API_HOST,
            $payload
        );

        return [
            'refund_sn'     => $response['refund_id'],
            'refund_amount' => $response['refund_fee'],
            'raw'           => $response,
        ];
    }

    /**
     * 关闭.
     *
     * @param Close $form
     *
     * @return array
     */
    public function close(Close $form): array
    {
        return [];
    }

    /**
     * 查询.
     *
     * @param Query $form
     *
     * @return array
     */
    public function query(Query $form): array
    {
        $parameters = [
            'service' => self::UNIFIED_ORDER_QUERY,
            'mch_id' => $this->config->get('mch_id'),
            'out_trade_no' => $form->get('order_id'),
            'nonce_str' => uniqid(),
            'sign_type' => 'MD5',
        ];
        $parameters['sign'] = $this->sign($parameters);

        $result = $this->request(self::PAY_API_HOST, $parameters);

        $amount = 0;
        $status = $this->formatTradeStatus($result['trade_state']);

        if ('paid' === $status) {
            $amount = $result['total_fee'];
        }

        return [
            'order_id' => $result['out_trade_no'] ?? '',
            'status' => $status,
            'trade_sn' => $result['out_transaction_id'] ?? '',
            'buyer_identifiable_id' => $result['openid'] ?? '',
            'buyer_is_subscribed' => 'no',
            'amount' => $amount,
            'buyer_name' => '',
            'paid_at' => $this->formatTradeTime($result['time_end'] ?? ''),
            'raw' => $result,
        ];
    }

    /**
     * 支付通知, 触发通知根据不同支付渠道, 可能包含:
     * 1. 交易关闭通知
     * 2. 其他通知
     *
     * @param $receives
     *
     * @return array
     */
    public function chargeNotify(array $receives): array
    {
        $amount = number_format($receives['total_fee'] / $this->config->get('rate', 1), 0, '.', '');

        return [
            'order_id'              => $receives['out_trade_no'],
            'status'                => $receives['pay_result'] == 0 ? 'paid' : 'created',
            'trade_sn'              => $receives['out_transaction_id'],
            'buyer_identifiable_id' => $receives['openid'] ?? '',
            'buyer_is_subscribed'   => 'no',
            'amount'                => $amount,
            'buyer_name'            => '',
            'paid_at'               => $this->formatTradeTime($receives['time_end'] ?? ''),
            'raw'                   => $receives,
        ];
    }

    /**
     * 退款通知, 并非所有支付渠道都支持
     *
     * @param $receives
     *
     * @return array
     */
    public function refundNotify(array $receives): array
    {
        // TODO
    }

    /**
     * 关闭通知, 并非所有支付渠道都支持
     *
     * @param $receives
     *
     * @return array
     */
    public function closeNotify(array $receives): array
    {
        // TODO
    }

    /**
     * 通知校验.
     *
     * @param $receives
     *
     * @return bool
     */
    public function verify($receives): bool
    {
        $receives = Xml::fromXml($receives);

        return $receives['sign'] === $this->sign($receives)
        && $receives['status'] == 0
        && $receives['result_code'] == 0
        && $receives['pay_result'] == 0;
    }

    /**
     * 通知成功处理响应.
     *
     * @return string
     */
    public function success(): string
    {
        return 'success';
    }

    /**
     * 通知处理失败响应.
     *
     * @return string
     */
    public function fail(): string
    {
        return 'fail';
    }

    /**
     * @param $receives
     *
     * @return array
     */
    public function convertNotificationToArray($receives): array
    {
        return Xml::fromXml($receives);
    }

    /**
     * @return string
     */
    public function receiveNotificationFromRequest(): string
    {
        return file_get_contents('php://input');
    }

    /**
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function prepareCharge(Charge $form): array;

    /**
     * @param array  $response
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function doCharge(array $response, Charge $form): array;

    /**
     * @param array $payload
     *
     * @return array
     */
    protected function createPayload(array $payload)
    {
        $payload = array_merge(
            [
                'mch_id'    => $this->config->get('mch_id'),
                'nonce_str' => uniqid(),
                'sign_type' => 'MD5',
            ],
            $payload
        );
        $payload['sign'] = $this->sign($payload);

        return $payload;
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
        $parameters['key'] = $this->config->get('mch_secret');

        return strtoupper(
            md5(
                urldecode(
                    http_build_query(
                        array_filter(
                            $parameters,
                            function ($value) {
                                return '' !== $value;
                            }
                        )
                    )
                )
            )
        );
    }

    /**
     * @param $url
     * @param array $payload
     * @param null  $cert
     * @param null  $sslKey
     *
     * @return array
     */
    protected function request($url, array $payload, $cert = null, $sslKey = null): array
    {
        $options = [
            'body' => Xml::toXml($payload),
        ];
        if (!is_null($cert)) {
            $options[RequestOptions::CERT] = $cert;
            $options[RequestOptions::SSL_KEY] = $sslKey;
        }

        return HttpClient::request(
            'POST',
            $url,
            $options,
            function (ResponseInterface $response) {
                $result = Xml::fromXml((string) $response->getBody());

                if (isset($result['err_code']) || 0 != $result['status']) {
                    throw new GatewayException(
                        sprintf(
                            'Alipay Gateway Error: %s, %s',
                            $result['err_code'] ?? '',
                            $result['err_msg'] ?? ''
                        ),
                        $result
                    );
                }

                return $result;
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Alipay Gateway Error.', $exception);
            }
        );
    }

    /**
     * @param $status
     *
     * @return string
     */
    protected function formatTradeStatus($status): string
    {
        switch ($status) {
            case 'NOTPAY':
            case 'REVERSE':
                return 'created';
            case 'CLOSED':
                return 'closed';
            case 'REFUND':
            case 'REVOK':
                return 'refund';
            default:
                return 'paid';
        }
    }

    protected function formatTradeTime($time) : int
    {
        if(!preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $time, $matches)) {
            return 0;
        }

        return (new \DateTime(
            sprintf(
                '%s-%s-%s %s:%s:%s',
                $matches[1],
                $matches[2],
                $matches[3],
                $matches[4],
                $matches[5],
                $matches[6]
            ),
            new \DateTimeZone('PRC')
        ))->getTimestamp();
    }
}

